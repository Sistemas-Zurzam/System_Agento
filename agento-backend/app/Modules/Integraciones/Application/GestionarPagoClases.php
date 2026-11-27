<?php

namespace App\Modules\Integraciones\Application;

use App\Modules\Configuracion\Models\Banco;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\EmpresaCuentaBancaria;
use App\Modules\Configuracion\Support\EmpresaContext;
use App\Modules\Integraciones\Models\LoteClasesExternas;
use App\Modules\Integraciones\Models\PagoClasesExternas;
use App\Modules\Nominas\Domain\BbvaNetCash\BbvaNetCashExportException;
use App\Modules\Nominas\Infrastructure\BbvaNetCash\Export\BbvaNetCashTxtExporter;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaDatosPago;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo de vida del pago de un lote de clases: aprobar (recalcula y congela las cuentas), pagar, anular y
 * generar el TXT BBVA Net Cash del abono. Solo se abona lo que no está observado; lo observado queda fuera
 * hasta corregirlo (en Agento) y volver a calcular.
 *
 * El TXT reutiliza BbvaNetCashTxtExporter de Nóminas (solo lectura, sin editarlo) con el mismo patrón que los
 * pagos semanales: el exportador trabaja sobre «boletas» con colaborador y datos de pago.
 */
class GestionarPagoClases
{
    public function __construct(private readonly CalcularPagoClases $calcular) {}

    public function aprobar(LoteClasesExternas $lote, int $usuarioId): LoteClasesExternas
    {
        $this->exigirEstado($lote, [LoteClasesExternas::RECIBIDO, LoteClasesExternas::OBSERVADO]);

        // Aprobar siempre sobre el cálculo más reciente (tarifas y datos de las instructoras pueden haber cambiado).
        $lote = $this->calcular->ejecutar($lote);

        EmpresaContext::como($lote->empresa_id, function () use ($lote, $usuarioId): void {
            $validos = $lote->pagos()->where('estado', '!=', PagoClasesExternas::OBSERVADO)->where('monto_neto', '>', 0)->with('colaborador')->get();
            if ($validos->isEmpty()) {
                throw ValidationException::withMessages(['lote' => 'No hay pagos válidos para aprobar: corrige las observaciones y vuelve a calcular.']);
            }

            DB::transaction(function () use ($lote, $usuarioId, $validos): void {
                // Congela la cuenta a la fecha de aprobación: el TXT sale de aquí.
                foreach ($validos as $pago) {
                    $c = $pago->colaborador;
                    $pago->update([
                        'estado' => PagoClasesExternas::APROBADO,
                        'banco_id' => $c->banco_id,
                        'tipo_cuenta_snapshot' => $c->tipo_cuenta,
                        'moneda_snapshot' => $c->moneda_cuenta,
                        'numero_cuenta_snapshot' => $c->numero_cuenta,
                        'cci_snapshot' => $c->cci,
                    ]);
                }
                $lote->update(['estado' => LoteClasesExternas::APROBADO, 'aprobado_por' => $usuarioId, 'aprobado_at' => now(), 'observacion' => null]);
            });
        });

        Log::info('integraciones.clases_externas.aprobado', ['lote_id' => $lote->id, 'user_id' => $usuarioId, 'total_neto' => (string) $lote->fresh()->total_neto]);

        return $lote->fresh(['pagos']);
    }

    public function pagar(LoteClasesExternas $lote, int $usuarioId, ?string $referenciaPago): LoteClasesExternas
    {
        $this->exigirEstado($lote, [LoteClasesExternas::APROBADO]);

        EmpresaContext::como($lote->empresa_id, fn () => DB::transaction(function () use ($lote, $usuarioId, $referenciaPago): void {
            $lote->update([
                'estado' => LoteClasesExternas::PAGADO,
                'pagado_por' => $usuarioId,
                'pagado_at' => now(),
                'referencia_pago' => $referenciaPago ?: $this->nombreArchivo($lote),
            ]);
            // Solo lo aprobado: las instructoras observadas no se pagaron.
            $lote->pagos()->where('estado', PagoClasesExternas::APROBADO)->update(['estado' => PagoClasesExternas::PAGADO]);
        }));

        Log::info('integraciones.clases_externas.pagado', ['lote_id' => $lote->id, 'user_id' => $usuarioId]);

        return $lote->fresh(['pagos']);
    }

    public function anular(LoteClasesExternas $lote, string $motivo): LoteClasesExternas
    {
        $this->exigirEstado($lote, [LoteClasesExternas::RECIBIDO, LoteClasesExternas::OBSERVADO, LoteClasesExternas::APROBADO]);
        $lote->update(['estado' => LoteClasesExternas::ANULADO, 'observacion' => $motivo]);

        return $lote->fresh(['pagos']);
    }

    /** TXT BBVA Net Cash de 4ta con las cuentas congeladas al aprobar. No cambia ningún estado. */
    public function exportarBbva(LoteClasesExternas $lote, EmpresaCuentaBancaria $cuentaCargo): string
    {
        $this->exigirEstado($lote, [LoteClasesExternas::APROBADO, LoteClasesExternas::PAGADO]);

        $boletas = EmpresaContext::como($lote->empresa_id, fn () => $lote->pagos()->with('colaborador')
            ->whereIn('estado', [PagoClasesExternas::APROBADO, PagoClasesExternas::PAGADO])->where('monto_neto', '>', 0)->get()
            ->map(function (PagoClasesExternas $p): Boleta {
                $boleta = new Boleta(['colaborador_id' => $p->colaborador_id, 'neto_a_pagar' => $p->monto_neto]);
                $boleta->setRelation('colaborador', $p->colaborador);
                $datosPago = new BoletaDatosPago([
                    'banco_id' => $p->banco_id,
                    'tipo_cuenta_snapshot' => $p->tipo_cuenta_snapshot,
                    'moneda_snapshot' => $p->moneda_snapshot,
                    'numero_cuenta_snapshot' => $p->numero_cuenta_snapshot,
                    'cci_snapshot' => $p->cci_snapshot,
                    'fecha_snapshot' => $p->updated_at,
                ]);
                $datosPago->setRelation('banco', $p->banco_id ? Banco::find($p->banco_id) : null);
                $boleta->setRelation('datosPago', $datosPago);

                return $boleta;
            }));

        if ($boletas->isEmpty()) {
            throw ValidationException::withMessages(['lote' => 'El lote no tiene montos a pagar.']);
        }

        try {
            return BbvaNetCashTxtExporter::generar($cuentaCargo, '4', 'RH CLASES '.$lote->periodo_inicio->format('Ym'), $boletas);
        } catch (BbvaNetCashExportException $e) {
            throw ValidationException::withMessages(['bbva_netcash' => $e->getMessage()]);
        }
    }

    public function nombreArchivo(LoteClasesExternas $lote): string
    {
        $empresa = Empresa::find($lote->empresa_id);
        $nombre = Str::upper(Str::slug($empresa?->nombre_comercial ?? $empresa?->razon_social ?? 'EMPRESA', '_'));

        return "BBVA_NETCASH_{$nombre}_4TA_CLASES_{$lote->periodo_inicio->format('Ym')}";
    }

    private function exigirEstado(LoteClasesExternas $lote, array $permitidos): void
    {
        if (! in_array($lote->estado, $permitidos, true)) {
            throw ValidationException::withMessages(['estado' => "El lote está {$lote->estado}; esta acción no aplica."]);
        }
    }
}
