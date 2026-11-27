<?php

namespace App\Modules\Integraciones\Application;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Support\EmpresaContext;
use App\Modules\Integraciones\Domain\CalculadoraPagoClases;
use App\Modules\Integraciones\Domain\CalculadoraRetencion4ta;
use App\Modules\Integraciones\Models\LoteClasesExternas;
use App\Modules\Integraciones\Models\PagoClasesExternas;
use App\Modules\Integraciones\Models\TarifaClase;
use App\Modules\Nominas\Support\ParametrosVigentesResolver;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorRemuneracion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Calcula (o recalcula) lo que se paga por las clases de un lote: tarifa de la titular, bruto por instructora,
 * retención de 4ta y neto. Es re-ejecutable mientras el lote no esté aprobado: borra y rehace los pagos con las
 * tarifas y datos vigentes. Las reglas viven en Domain (CalculadoraPagoClases y CalculadoraRetencion4ta).
 *
 * Una instructora queda «observada» (no entra al abono hasta corregirse y recalcular) si no es locadora de 4ta,
 * si en Agento su periodicidad de pago no es «semanal» (única forma de que NO entre además a la planilla
 * mensual de Nóminas y cobre dos veces), si no tiene cuenta bancaria completa o si faltan los parámetros de
 * 4ta de la empresa. Los parámetros de 4ta se leen de Nóminas (solo lectura) para no duplicar la regla.
 */
class CalcularPagoClases
{
    private const REGIMEN_LOCADOR = 'Locacion de Servicios';

    public function __construct(
        private readonly CalculadoraPagoClases $calculadora,
        private readonly CalculadoraRetencion4ta $retencion,
    ) {}

    /** @throws ValidationException si el lote ya no admite recálculo */
    public function ejecutar(LoteClasesExternas $lote): LoteClasesExternas
    {
        if (! in_array($lote->estado, LoteClasesExternas::REEMPLAZABLE, true)) {
            throw ValidationException::withMessages(['estado' => "El lote está {$lote->estado}; no se puede recalcular."]);
        }

        EmpresaContext::como($lote->empresa_id, fn () => DB::transaction(fn () => $this->calcular($lote)));

        return $lote->fresh(['clases', 'pagos']);
    }

    private function calcular(LoteClasesExternas $lote): void
    {
        $empresa = Empresa::findOrFail($lote->empresa_id);
        $clases = $lote->clases()->get();

        $tarifas = TarifaClase::where('empresa_id', $lote->empresa_id)
            ->whereIn('colaborador_id', $clases->pluck('titular_colaborador_id')->filter()->unique()->all())
            ->orderByDesc('vigencia_desde')->get()->groupBy('colaborador_id');

        $entrada = [];
        foreach ($clases as $clase) {
            // Una clase observada por otro motivo (DNI desconocido, fuera de periodo, duplicada) no se paga.
            $bloqueada = $clase->observacion !== null && $clase->observacion !== CalculadoraPagoClases::MOTIVO_SIN_TARIFA;
            $entrada[] = [
                'clase_id' => $clase->clase_id,
                'estado' => $bloqueada ? 'no_dictada' : $clase->estado,
                'ejecutor_id' => $clase->ejecutor_colaborador_id,
                'tarifa_titular' => $this->tarifaVigente($tarifas->get($clase->titular_colaborador_id), $clase->fecha),
            ];
        }
        $calculo = $this->calculadora->calcular($entrada);

        foreach ($clases as $clase) {
            $fila = $calculo['clases'][$clase->clase_id];
            $observacion = $clase->observacion;
            if ($fila['motivo'] === CalculadoraPagoClases::MOTIVO_SIN_TARIFA && $observacion === null) {
                $observacion = CalculadoraPagoClases::MOTIVO_SIN_TARIFA;
            } elseif ($fila['motivo'] === null && $observacion === CalculadoraPagoClases::MOTIVO_SIN_TARIFA) {
                $observacion = null;
            }
            $clase->update(['monto' => $fila['monto'], 'tarifa_aplicada' => $fila['tarifa'], 'observacion' => $observacion]);
        }

        $lote->pagos()->delete();
        $parametros = $this->parametrosRetencion($empresa, $lote->periodo_fin->toDateString());
        $colaboradores = Colaborador::where('empresa_id', $lote->empresa_id)
            ->whereIn('id', array_keys($calculo['por_colaborador']))->get()->keyBy('id');

        foreach ($calculo['por_colaborador'] as $colaboradorId => $acumulado) {
            $lote->pagos()->create($this->construirPago($lote, (int) $colaboradorId, $colaboradores->get($colaboradorId), $acumulado, $parametros));
        }

        $this->actualizarLote($lote);
    }

    /** @param Collection<int, TarifaClase>|null $tarifasDeLaTitular de la más reciente a la más antigua */
    private function tarifaVigente(?Collection $tarifasDeLaTitular, $fecha): ?string
    {
        $tarifa = $tarifasDeLaTitular?->first(fn (TarifaClase $t) => $t->vigencia_desde->lte($fecha));

        return $tarifa ? (string) $tarifa->monto : null;
    }

    /** @return array<string, mixed> */
    private function construirPago(LoteClasesExternas $lote, int $colaboradorId, ?Colaborador $colaborador, array $acumulado, ?array $parametros): array
    {
        $observaciones = [];
        $retencion = '0.00';

        if (! $colaborador) {
            $observaciones[] = 'La instructora ya no existe en la empresa.';
        } else {
            if ($colaborador->regimen_laboral !== self::REGIMEN_LOCADOR) {
                $observaciones[] = 'No es locadora (4ta): el pago por clase es solo para recibos por honorarios.';
            }
            $remuneracion = ColaboradorRemuneracion::where('colaborador_id', $colaborador->id)
                ->whereDate('vigencia_desde', '<=', $lote->periodo_fin)
                ->orderByDesc('vigencia_desde')->orderByDesc('id')->first();
            if ($remuneracion?->periodicidad_pago !== 'semanal') {
                // Nóminas excluye de su planilla mensual solo a quienes están en «semanal»: sin esto, cobraría dos veces.
                $observaciones[] = 'Su periodicidad de pago en Agento debe ser "semanal" para que no entre también a la planilla mensual.';
            }
            if (! $colaborador->banco_id || (! $colaborador->numero_cuenta && ! $colaborador->cci)) {
                $observaciones[] = 'Sin cuenta bancaria completa (banco y cuenta o CCI).';
            }
            if ($parametros === null) {
                $observaciones[] = 'Faltan los parámetros de renta de 4ta vigentes en Agento.';
            } else {
                $retencion = $this->retencion->calcular(
                    $acumulado['bruto'], $parametros['umbral'], $parametros['tasa'], (bool) $colaborador->tiene_suspension_renta_4ta,
                );
            }
        }

        return [
            'empresa_id' => $lote->empresa_id,
            'colaborador_id' => $colaboradorId,
            'clases_pagadas' => $acumulado['clases'],
            'monto_bruto' => $acumulado['bruto'],
            'retencion_4ta' => $retencion,
            'monto_neto' => bcsub($acumulado['bruto'], $retencion, 2),
            'estado' => $observaciones ? PagoClasesExternas::OBSERVADO : PagoClasesExternas::RECIBIDO,
            'observacion' => $observaciones ? mb_substr(implode(' ', $observaciones), 0, 500) : null,
        ];
    }

    /** @return array{umbral: string, tasa: string}|null */
    private function parametrosRetencion(Empresa $empresa, string $fecha): ?array
    {
        try {
            $p = ParametrosVigentesResolver::paraHonorarios($empresa, $fecha);

            return [
                'umbral' => number_format((float) $p['umbral_retencion_4ta'], 2, '.', ''),
                'tasa' => number_format((float) $p['tasa_retencion_4ta'], 6, '.', ''),
            ];
        } catch (Throwable $e) {
            Log::warning('integraciones.clases_externas.sin_parametros_4ta', ['empresa_id' => $empresa->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function actualizarLote(LoteClasesExternas $lote): void
    {
        $pagos = $lote->pagos()->get();
        $observadas = $lote->clases()->whereNotNull('observacion')->count();

        $lote->update([
            'total_bruto' => $pagos->reduce(fn (string $a, PagoClasesExternas $p) => bcadd($a, (string) $p->monto_bruto, 2), '0.00'),
            'total_retencion' => $pagos->reduce(fn (string $a, PagoClasesExternas $p) => bcadd($a, (string) $p->retencion_4ta, 2), '0.00'),
            'total_neto' => $pagos->reduce(fn (string $a, PagoClasesExternas $p) => bcadd($a, (string) $p->monto_neto, 2), '0.00'),
            'total_observadas' => $observadas,
            'estado' => ($observadas > 0 || $pagos->contains('estado', PagoClasesExternas::OBSERVADO))
                ? LoteClasesExternas::OBSERVADO
                : LoteClasesExternas::RECIBIDO,
        ]);
    }
}
