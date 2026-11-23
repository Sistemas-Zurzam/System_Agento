<?php

namespace App\Modules\Nominas\Services;

use App\Modules\Configuracion\Models\Banco;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\EmpresaCuentaBancaria;
use App\Modules\Configuracion\Support\EmpresaContext;
use App\Modules\Nominas\Domain\BbvaNetCash\BbvaNetCashExportException;
use App\Modules\Nominas\Infrastructure\BbvaNetCash\Export\BbvaNetCashTxtExporter;
use App\Modules\Nominas\Jobs\NotificarEstadoPagoSemanalZazuJob;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaDatosPago;
use App\Modules\Nominas\Models\PagoSemanalLote;
use App\Modules\Nominas\Models\PagoSemanalLoteDetalle;
use App\Modules\Nominas\Support\ParametrosVigentesResolver;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorRemuneracion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pagos semanales de motorizados enviados por plataforma_zazu.
 *
 * Zazu es dueña del CÁLCULO (días, entregas, bonos, ajustes → monto bruto);
 * Agento es dueña del PAGO: identifica por DNI, aplica la retención de 4ta,
 * congela las cuentas al aprobar, genera el TXT BBVA y marca el pago. Cada
 * cambio de estado se avisa a Zazu (NotificarEstadoPagoSemanalZazuJob).
 *
 * Aislamiento multiempresa: recibir() llega sin JWT (máquina a máquina) y
 * revalidar() puede tocar un lote de otra empresa a la que el usuario tiene
 * acceso; ambos corren dentro de EmpresaContext::como(empresa del lote) para
 * que EmpresaScope (colaboradores, parámetros de 4ta) filtre por esa empresa.
 */
class PagoSemanalZazuService
{
    private const REGIMEN_LOCADOR = 'Locacion de Servicios';

    /**
     * Recibe (o reemplaza) el lote de una semana.
     *
     * @return array{lote: PagoSemanalLote, creado: bool}
     *
     * @throws ValidationException 409 lógico: el lote ya fue aprobado/pagado
     */
    public function recibir(Empresa $empresa, array $datos): array
    {
        return EmpresaContext::como($empresa->id, fn () => $this->recibirEnEmpresa($empresa, $datos));
    }

    private function recibirEnEmpresa(Empresa $empresa, array $datos): array
    {
        $existente = PagoSemanalLote::where('empresa_id', $empresa->id)
            ->where('sistema_origen', 'zazu')
            ->where('referencia', $datos['referencia'])
            ->first();

        if ($existente && ! in_array($existente->estado, PagoSemanalLote::REEMPLAZABLE, true)) {
            throw new PagoSemanalYaAprobadoException("El lote {$datos['referencia']} ya está {$existente->estado}; no se puede reemplazar.");
        }

        $lote = DB::transaction(function () use ($empresa, $datos, $existente): PagoSemanalLote {
            $lote = $existente ?? new PagoSemanalLote([
                'empresa_id' => $empresa->id,
                'sistema_origen' => 'zazu',
                'referencia' => $datos['referencia'],
                'envios' => 0,
            ]);
            $lote->fill([
                'semana_inicio' => $datos['semana_inicio'],
                'semana_fin' => $datos['semana_fin'],
                'enviado_por' => $datos['enviado_por'] ?? null,
                'envios' => $lote->envios + 1,
                'recibido_at' => now(),
                'observacion' => null,
            ])->save();

            $lote->detalles()->delete();

            $colaboradores = Colaborador::where('empresa_id', $empresa->id)
                ->whereIn('numero_documento', collect($datos['lineas'])->pluck('dni')->map(fn ($d) => trim((string) $d))->all())
                ->get()
                ->keyBy('numero_documento');

            $retencion = $this->parametrosRetencion($empresa, $datos['semana_fin']);

            foreach ($datos['lineas'] as $linea) {
                $lote->detalles()->create($this->construirDetalle($linea, $colaboradores, $retencion, $datos['semana_fin']));
            }

            $this->recalcularTotales($lote);

            return $lote;
        });

        Log::info('pagos_semanales_zazu.recibido', [
            'lote_id' => $lote->id, 'referencia' => $lote->referencia, 'estado' => $lote->estado,
            'lineas' => $lote->detalles()->count(), 'total_bruto' => (string) $lote->total_bruto,
        ]);

        return ['lote' => $lote->fresh('detalles'), 'creado' => $existente === null];
    }

    /**
     * Aprueba las líneas válidas. Las observadas (DNI desconocido, sin cuenta,
     * periodicidad no semanal…) quedan fuera: no entran al TXT ni al pago, y
     * siguen observadas para que se corrijan y se paguen en otro envío.
     */
    public function aprobar(PagoSemanalLote $lote, int $usuarioId): PagoSemanalLote
    {
        $this->exigirEstado($lote, [PagoSemanalLote::RECIBIDO, PagoSemanalLote::OBSERVADO]);

        $validas = $lote->detalles()->where('estado', '!=', PagoSemanalLote::OBSERVADO)->with('colaborador')->get();
        if ($validas->isEmpty()) {
            throw ValidationException::withMessages(['lote' => 'Todas las líneas están observadas: corrige los colaboradores en Agento y pulsa Revalidar, u observa el lote para que Zazu lo corrija.']);
        }

        DB::transaction(function () use ($lote, $usuarioId, $validas): void {
            // Congela las cuentas a la fecha de aprobación (mismo criterio que
            // boleta_datos_pago al cerrar un ciclo): el TXT sale de aquí.
            foreach ($validas as $detalle) {
                $c = $detalle->colaborador;
                $detalle->update([
                    'estado' => PagoSemanalLote::APROBADO,
                    'banco_id' => $c->banco_id,
                    'tipo_cuenta_snapshot' => $c->tipo_cuenta,
                    'moneda_snapshot' => $c->moneda_cuenta,
                    'numero_cuenta_snapshot' => $c->numero_cuenta,
                    'cci_snapshot' => $c->cci,
                ]);
            }
            $lote->update(['estado' => PagoSemanalLote::APROBADO, 'aprobado_por' => $usuarioId, 'aprobado_at' => now(), 'observacion' => null]);
        });

        $this->notificar($lote);

        return $lote->fresh('detalles');
    }

    /** Devuelve el lote a Zazu para que corrija y reenvíe. */
    public function observar(PagoSemanalLote $lote, string $observacion): PagoSemanalLote
    {
        $this->exigirEstado($lote, [PagoSemanalLote::RECIBIDO, PagoSemanalLote::APROBADO]);

        $lote->update(['estado' => PagoSemanalLote::OBSERVADO, 'observacion' => $observacion, 'aprobado_por' => null, 'aprobado_at' => null]);
        $lote->detalles()->where('estado', PagoSemanalLote::APROBADO)->update(['estado' => PagoSemanalLote::RECIBIDO]);
        $this->notificar($lote);

        return $lote->fresh('detalles');
    }

    /** Re-evalúa DNI, cuentas y retención con los datos actuales del colaborador (tras corregirlos en Agento). */
    public function revalidar(PagoSemanalLote $lote): PagoSemanalLote
    {
        $this->exigirEstado($lote, [PagoSemanalLote::RECIBIDO, PagoSemanalLote::OBSERVADO]);

        EmpresaContext::como($lote->empresa_id, fn () => DB::transaction(function () use ($lote): void {
            $empresa = $lote->empresa;
            $semanaFin = $lote->semana_fin->toDateString();
            $detalles = $lote->detalles()->get();
            $colaboradores = Colaborador::where('empresa_id', $empresa->id)
                ->whereIn('numero_documento', $detalles->pluck('numero_documento'))
                ->get()->keyBy('numero_documento');
            $retencion = $this->parametrosRetencion($empresa, $semanaFin);

            foreach ($detalles as $detalle) {
                $linea = [...($detalle->detalle_origen ?? []), 'dni' => $detalle->numero_documento];
                $detalle->update($this->construirDetalle($linea, $colaboradores, $retencion, $semanaFin));
            }
            $lote->update(['estado' => PagoSemanalLote::RECIBIDO, 'observacion' => null]);
            $this->recalcularTotales($lote);
        }));

        $this->notificar($lote);

        return $lote->fresh('detalles');
    }

    public function marcarPagado(PagoSemanalLote $lote, int $usuarioId, ?string $referenciaPago): PagoSemanalLote
    {
        $this->exigirEstado($lote, [PagoSemanalLote::APROBADO]);

        DB::transaction(function () use ($lote, $usuarioId, $referenciaPago): void {
            $lote->update([
                'estado' => PagoSemanalLote::PAGADO,
                'pagado_por' => $usuarioId,
                'pagado_at' => now(),
                'referencia_pago' => $referenciaPago ?: $this->nombreArchivoBbva($lote),
            ]);
            // Solo lo aprobado: las líneas observadas no se pagaron.
            $lote->detalles()->where('estado', PagoSemanalLote::APROBADO)->update(['estado' => PagoSemanalLote::PAGADO]);
        });

        Log::info('pagos_semanales_zazu.pagado', ['lote_id' => $lote->id, 'user_id' => $usuarioId, 'total_neto' => (string) $lote->total_neto]);
        $this->notificar($lote);

        return $lote->fresh('detalles');
    }

    public function anular(PagoSemanalLote $lote, string $motivo): PagoSemanalLote
    {
        $this->exigirEstado($lote, [PagoSemanalLote::RECIBIDO, PagoSemanalLote::OBSERVADO, PagoSemanalLote::APROBADO]);
        $lote->update(['estado' => PagoSemanalLote::ANULADO, 'observacion' => $motivo]);
        $this->notificar($lote);

        return $lote->fresh('detalles');
    }

    /** TXT BBVA Net Cash de 4ta con las cuentas congeladas al aprobar. No cambia ningún estado. */
    public function exportarBbva(PagoSemanalLote $lote, EmpresaCuentaBancaria $cuentaCargo): string
    {
        $this->exigirEstado($lote, [PagoSemanalLote::APROBADO, PagoSemanalLote::PAGADO]);

        $boletas = $lote->detalles()->with('colaborador')->where('monto_neto', '>', 0)
            ->whereIn('estado', [PagoSemanalLote::APROBADO, PagoSemanalLote::PAGADO])->get()
            ->map(function (PagoSemanalLoteDetalle $d): Boleta {
                // Misma adaptación que PlanillaComplementariaService::detallesParaPago():
                // el exportador trabaja sobre "boletas" con colaborador + datosPago.
                $boleta = new Boleta(['colaborador_id' => $d->colaborador_id, 'neto_a_pagar' => $d->monto_neto]);
                $boleta->setRelation('colaborador', $d->colaborador);
                $datosPago = new BoletaDatosPago([
                    'banco_id' => $d->banco_id,
                    'tipo_cuenta_snapshot' => $d->tipo_cuenta_snapshot,
                    'moneda_snapshot' => $d->moneda_snapshot,
                    'numero_cuenta_snapshot' => $d->numero_cuenta_snapshot,
                    'cci_snapshot' => $d->cci_snapshot,
                    'fecha_snapshot' => $d->updated_at,
                ]);
                $datosPago->setRelation('banco', $d->banco_id ? Banco::find($d->banco_id) : null);
                $boleta->setRelation('datosPago', $datosPago);

                return $boleta;
            });

        if ($boletas->isEmpty()) {
            throw ValidationException::withMessages(['lote' => 'El lote no tiene montos a pagar.']);
        }

        try {
            return BbvaNetCashTxtExporter::generar($cuentaCargo, '4', 'RH SEM '.$lote->semana_inicio->format('W'), $boletas);
        } catch (BbvaNetCashExportException $e) {
            throw ValidationException::withMessages(['bbva_netcash' => $e->getMessage()]);
        }
    }

    public function nombreArchivoBbva(PagoSemanalLote $lote): string
    {
        $empresa = Str::upper(Str::slug($lote->empresa?->nombre_comercial ?? $lote->empresa?->razon_social ?? 'ZAZU', '_'));

        return "BBVA_NETCASH_{$empresa}_4TA_{$lote->semanaIso()}";
    }

    /** Payload del aviso a Zazu (contrato docs/INTEGRACION-AGENTO-PLANILLA.md en plataforma_zazu). */
    public function payloadNotificacion(PagoSemanalLote $lote): array
    {
        return [
            'referencia' => $lote->referencia,
            'estado' => $lote->estado,
            'observacion' => $lote->observacion,
            'lineas' => $lote->detalles()->get()->map(fn (PagoSemanalLoteDetalle $d) => [
                'dni' => $d->numero_documento,
                'estado' => $d->estado,
                'observacion' => $d->observacion,
                'monto_neto' => (float) $d->monto_neto,
                'referencia_pago' => $lote->estado === PagoSemanalLote::PAGADO ? $lote->referencia_pago : null,
                'pagado_at' => $lote->estado === PagoSemanalLote::PAGADO ? $lote->pagado_at?->toIso8601String() : null,
            ])->values()->all(),
        ];
    }

    /** @param Collection<string, Colaborador> $colaboradores */
    private function construirDetalle(array $linea, Collection $colaboradores, ?array $retencion, string $semanaFin): array
    {
        $dni = trim((string) $linea['dni']);
        $bruto = round((float) ($linea['monto_total'] ?? 0), 2);
        $colaborador = $colaboradores->get($dni);

        $observaciones = [];
        $avisos = [];

        if (! $colaborador) {
            $observaciones[] = 'DNI no registrado como colaborador de la empresa en Agento.';
        } else {
            if ($colaborador->regimen_laboral !== self::REGIMEN_LOCADOR) {
                $observaciones[] = 'No es locador (4ta); los pagos semanales son solo para locación de servicios.';
            }
            $remuneracion = ColaboradorRemuneracion::where('colaborador_id', $colaborador->id)
                ->whereDate('vigencia_desde', '<=', $semanaFin)
                ->orderByDesc('vigencia_desde')->orderByDesc('id')->first();
            if ($remuneracion?->periodicidad_pago !== 'semanal') {
                // Sin esto también entraría al ciclo mensual y cobraría dos veces.
                $observaciones[] = 'Su periodicidad de pago en Agento no es "semanal": cámbiala para que no entre también a la planilla mensual.';
            }
            if (! $colaborador->banco_id || (! $colaborador->numero_cuenta && ! $colaborador->cci)) {
                $observaciones[] = 'Sin cuenta bancaria completa (banco y cuenta o CCI).';
            }
            if (! $this->nombreCoincide((string) ($linea['nombre'] ?? ''), $colaborador)) {
                $avisos[] = 'El nombre en Zazu ("'.($linea['nombre'] ?? '').'") no coincide con el de Agento.';
            }
        }
        if ($retencion === null) {
            $observaciones[] = 'Faltan los parámetros de renta de 4ta vigentes en Agento.';
        }

        $montoRetencion = 0.0;
        if ($colaborador && $retencion && ! $colaborador->tiene_suspension_renta_4ta && $bruto > $retencion['umbral']) {
            $montoRetencion = round($bruto * $retencion['tasa'], 2);
        }

        return [
            'colaborador_id' => $colaborador?->id,
            'numero_documento' => $dni,
            'nombre_origen' => Str::limit((string) ($linea['nombre'] ?? ''), 150, ''),
            'alias_origen' => $linea['alias'] ?? null,
            'dias_trabajados' => (int) ($linea['dias_trabajados'] ?? 0),
            'dias_base' => (int) ($linea['dias_base'] ?? 6),
            'pedidos_entregados' => (int) ($linea['pedidos_entregados'] ?? 0),
            'monto_dias' => (float) ($linea['monto_dias'] ?? 0),
            'monto_bonos' => (float) ($linea['monto_bonos'] ?? 0),
            'adicional' => (float) ($linea['adicional'] ?? 0),
            'descuento' => (float) ($linea['descuento'] ?? 0),
            'monto_bruto' => $bruto,
            'retencion_4ta' => $montoRetencion,
            'monto_neto' => round($bruto - $montoRetencion, 2),
            'estado' => $observaciones ? PagoSemanalLote::OBSERVADO : PagoSemanalLote::RECIBIDO,
            'observacion' => $observaciones ? Str::limit(implode(' ', $observaciones), 500, '') : null,
            'aviso' => $avisos ? Str::limit(implode(' ', $avisos), 255, '') : null,
            'detalle_origen' => $linea,
        ];
    }

    /** @return array{umbral: float, tasa: float}|null */
    private function parametrosRetencion(Empresa $empresa, string $fecha): ?array
    {
        try {
            $p = ParametrosVigentesResolver::paraHonorarios($empresa, $fecha);

            return ['umbral' => (float) $p['umbral_retencion_4ta'], 'tasa' => (float) $p['tasa_retencion_4ta']];
        } catch (Throwable $e) {
            Log::warning('pagos_semanales_zazu.sin_parametros_4ta', ['empresa_id' => $empresa->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Cada palabra del nombre que manda Zazu (ej. "WALTER RICARDO") debe estar en el nombre completo de Agento. */
    private function nombreCoincide(string $nombreZazu, Colaborador $colaborador): bool
    {
        $normalizar = fn (string $t) => collect(preg_split('/\s+/', Str::upper(Str::ascii(trim($t)))))->filter()->values();
        $zazu = $normalizar(preg_replace('/\(.*?\)/', '', $nombreZazu));
        $agento = $normalizar("{$colaborador->nombres} {$colaborador->apellido_paterno} {$colaborador->apellido_materno}");

        return $zazu->isNotEmpty() && $zazu->every(fn ($palabra) => $agento->contains($palabra));
    }

    private function recalcularTotales(PagoSemanalLote $lote): void
    {
        $detalles = $lote->detalles()->get();
        $lote->update([
            'total_bruto' => round($detalles->sum('monto_bruto'), 2),
            'total_retencion' => round($detalles->sum('retencion_4ta'), 2),
            'total_neto' => round($detalles->sum('monto_neto'), 2),
            'estado' => $detalles->contains('estado', PagoSemanalLote::OBSERVADO) ? PagoSemanalLote::OBSERVADO : PagoSemanalLote::RECIBIDO,
        ]);
    }

    private function exigirEstado(PagoSemanalLote $lote, array $permitidos): void
    {
        if (! in_array($lote->estado, $permitidos, true)) {
            throw ValidationException::withMessages(['estado' => "El lote está {$lote->estado}; esta acción no aplica."]);
        }
    }

    private function notificar(PagoSemanalLote $lote): void
    {
        NotificarEstadoPagoSemanalZazuJob::dispatch($lote->id);
    }
}
