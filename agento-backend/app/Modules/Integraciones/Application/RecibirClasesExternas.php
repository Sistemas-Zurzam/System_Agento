<?php

namespace App\Modules\Integraciones\Application;

use App\Modules\Configuracion\Support\EmpresaContext;
use App\Modules\Integraciones\Models\ClaseExterna;
use App\Modules\Integraciones\Models\CredencialIntegracion;
use App\Modules\Integraciones\Models\LoteClasesExternas;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recibe el lote de clases dictadas que envía un sistema externo (Firme Studio).
 *
 * Solo RECIBE e IDENTIFICA: el sistema externo informa hechos (quién era la titular, quién dictó, estado);
 * el monto lo calculará Agento en una fase posterior con sus tarifas. Cada clase queda «observada» (sin
 * bloquear al resto) si no se puede identificar a alguna de las personas por DNI, si cae fuera del periodo
 * o si ya vino en otro lote de la empresa. El lote es idempotente por (empresa, proveedor, referencia).
 *
 * Corre dentro de EmpresaContext::como(empresa de la credencial): la empresa nunca sale del cuerpo.
 */
class RecibirClasesExternas
{
    /**
     * @param  array<string, mixed>  $datos  ya validados por RecibirClasesRequest
     * @return array{lote: LoteClasesExternas, creado: bool}
     *
     * @throws LoteYaAprobadoException
     */
    public function ejecutar(CredencialIntegracion $credencial, array $datos): array
    {
        return EmpresaContext::como($credencial->empresa_id, fn () => $this->recibir($credencial, $datos));
    }

    private function recibir(CredencialIntegracion $credencial, array $datos): array
    {
        $empresaId = $credencial->empresa_id;

        $existente = LoteClasesExternas::where('empresa_id', $empresaId)
            ->where('proveedor', $credencial->proveedor)
            ->where('referencia', $datos['referencia'])
            ->first();

        if ($existente && ! in_array($existente->estado, LoteClasesExternas::REEMPLAZABLE, true)) {
            throw new LoteYaAprobadoException("El lote {$datos['referencia']} ya está {$existente->estado}; no se puede reemplazar.");
        }

        $lote = DB::transaction(function () use ($credencial, $datos, $existente, $empresaId): LoteClasesExternas {
            $lote = $existente ?? new LoteClasesExternas([
                'empresa_id' => $empresaId,
                'proveedor' => $credencial->proveedor,
                'referencia' => $datos['referencia'],
                'envios' => 0,
            ]);
            $lote->fill([
                'credencial_id' => $credencial->id,
                'periodo_inicio' => $datos['periodo_inicio'],
                'periodo_fin' => $datos['periodo_fin'],
                'enviado_por' => $datos['enviado_por'] ?? null,
                'envios' => $lote->envios + 1,
                'recibido_at' => now(),
            ])->save();

            $lote->clases()->delete();

            $clases = collect($datos['clases']);
            $colaboradores = $this->colaboradoresPorDni($empresaId, $clases);
            $enOtrosLotes = ClaseExterna::where('lote_id', '!=', $lote->id)
                ->whereIn('clase_id', $clases->pluck('clase_id')->all())
                ->pluck('clase_id')
                ->flip();

            $observadas = 0;
            foreach ($clases as $clase) {
                $fila = $this->construirFila($lote, $clase, $colaboradores, $enOtrosLotes);
                $observadas += $fila['observacion'] === null ? 0 : 1;
                $lote->clases()->create($fila);
            }

            $lote->update([
                'total_clases' => $clases->count(),
                'total_observadas' => $observadas,
                'estado' => $observadas > 0 ? LoteClasesExternas::OBSERVADO : LoteClasesExternas::RECIBIDO,
            ]);

            return $lote;
        });

        // Solo identificadores y conteos: nada de nombres ni DNI en logs.
        Log::info('integraciones.clases_externas.recibido', [
            'lote_id' => $lote->id, 'empresa_id' => $empresaId, 'proveedor' => $lote->proveedor,
            'referencia' => $lote->referencia, 'clases' => $lote->total_clases, 'observadas' => $lote->total_observadas,
        ]);

        return ['lote' => $lote->fresh('clases'), 'creado' => $existente === null];
    }

    /** Colaboradores de la empresa por DNI. Colaborador no tiene EmpresaScope: el filtro por empresa es explícito. */
    private function colaboradoresPorDni(int $empresaId, $clases)
    {
        $dnis = $clases->flatMap(fn (array $c) => [$c['instructora_titular']['dni'], $c['instructora_que_dicto']['dni'] ?? null])
            ->filter()->map(fn ($d) => trim((string) $d))->unique()->values()->all();

        return Colaborador::where('empresa_id', $empresaId)
            ->whereIn('numero_documento', $dnis)
            ->get(['id', 'numero_documento'])
            ->keyBy('numero_documento');
    }

    private function construirFila(LoteClasesExternas $lote, array $clase, $colaboradores, $enOtrosLotes): array
    {
        $titularDni = trim((string) $clase['instructora_titular']['dni']);
        $ejecutorDni = isset($clase['instructora_que_dicto']['dni']) ? trim((string) $clase['instructora_que_dicto']['dni']) : null;
        $titular = $colaboradores->get($titularDni);
        $ejecutor = $ejecutorDni !== null ? $colaboradores->get($ejecutorDni) : null;

        return [
            'empresa_id' => $lote->empresa_id,
            'clase_id' => $clase['clase_id'],
            'fecha' => $clase['fecha'],
            'hora_inicio' => $clase['hora_inicio'],
            'hora_fin' => $clase['hora_fin'],
            'tipo_clase' => $clase['tipo_clase'] ?? null,
            'sede' => $clase['sede'] ?? null,
            'estado' => $clase['estado'],
            'motivo_suplencia' => $clase['motivo_suplencia'] ?? null,
            'titular_dni' => $titularDni,
            'titular_nombre' => $clase['instructora_titular']['nombre'],
            'titular_colaborador_id' => $titular?->id,
            'ejecutor_dni' => $ejecutorDni,
            'ejecutor_nombre' => $clase['instructora_que_dicto']['nombre'] ?? null,
            'ejecutor_colaborador_id' => $ejecutor?->id,
            'observacion' => $this->observacion($lote, $clase, $titular, $ejecutorDni, $ejecutor, $enOtrosLotes),
        ];
    }

    /** El primer motivo por el que la clase requiere revisión, o null si está lista. */
    private function observacion(LoteClasesExternas $lote, array $clase, ?Colaborador $titular, ?string $ejecutorDni, ?Colaborador $ejecutor, $enOtrosLotes): ?string
    {
        if ($enOtrosLotes->has($clase['clase_id'])) {
            return 'La clase ya fue enviada en otro lote.';
        }
        if ($clase['fecha'] < $lote->periodo_inicio->toDateString() || $clase['fecha'] > $lote->periodo_fin->toDateString()) {
            return 'La fecha de la clase está fuera del periodo del lote.';
        }
        if (! $titular) {
            return 'El DNI de la instructora titular no corresponde a ningún colaborador.';
        }
        if ($clase['estado'] === ClaseExterna::DICTADA) {
            if ($ejecutorDni === null) {
                return 'Una clase dictada debe indicar quién la dictó.';
            }
            if (! $ejecutor) {
                return 'El DNI de quien dictó la clase no corresponde a ningún colaborador.';
            }
        }

        return null;
    }
}
