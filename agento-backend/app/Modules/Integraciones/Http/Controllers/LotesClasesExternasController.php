<?php

namespace App\Modules\Integraciones\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\EmpresaCuentaBancaria;
use App\Modules\Integraciones\Application\CalcularPagoClases;
use App\Modules\Integraciones\Application\GestionarPagoClases;
use App\Modules\Integraciones\Models\ClaseExterna;
use App\Modules\Integraciones\Models\LoteClasesExternas;
use App\Modules\Integraciones\Models\PagoClasesExternas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Panel: revisión, cálculo, aprobación, pago y TXT BBVA de los lotes de clases que envían los sistemas
 * externos. La lógica está en CalcularPagoClases y GestionarPagoClases.
 */
class LotesClasesExternasController extends Controller
{
    public function __construct(
        private readonly CalcularPagoClases $calcular,
        private readonly GestionarPagoClases $gestion,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate(['estado' => ['nullable', 'in:recibido,observado,aprobado,pagado,anulado']]);

        $lotes = LoteClasesExternas::with('empresa:id,nombre_comercial,razon_social')
            ->whereIn('empresa_id', $request->user('api')->empresaIdsAutorizadas())
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->orderByDesc('periodo_inicio')->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 20))));

        return response()->json([
            'data' => collect($lotes->items())->map(fn (LoteClasesExternas $l) => $this->lote($l))->values(),
            'meta' => ['total' => $lotes->total(), 'current_page' => $lotes->currentPage(), 'last_page' => $lotes->lastPage()],
        ]);
    }

    public function show(Request $request, LoteClasesExternas $lote): JsonResponse
    {
        $this->autorizar($request, $lote);
        $lote->load([
            'empresa:id,nombre_comercial,razon_social',
            'pagos.colaborador:id,nombres,apellido_paterno,apellido_materno,numero_documento,banco_id,numero_cuenta,cci',
            'clases',
        ]);

        return response()->json(['data' => [
            ...$this->lote($lote),
            'pagos' => $lote->pagos->map(fn (PagoClasesExternas $p) => $this->pago($p))->values(),
            'clases' => $lote->clases->map(fn (ClaseExterna $c) => $this->clase($c))->values(),
        ]]);
    }

    public function calcular(Request $request, LoteClasesExternas $lote): JsonResponse
    {
        $this->autorizar($request, $lote);

        return $this->respuesta($this->calcular->ejecutar($lote), 'Lote calculado con las tarifas y datos actuales.');
    }

    public function aprobar(Request $request, LoteClasesExternas $lote): JsonResponse
    {
        $this->autorizar($request, $lote);

        return $this->respuesta($this->gestion->aprobar($lote, $request->user('api')->id), 'Lote aprobado.');
    }

    public function pagar(Request $request, LoteClasesExternas $lote): JsonResponse
    {
        $this->autorizar($request, $lote);
        $datos = $request->validate(['referencia_pago' => ['nullable', 'string', 'max:120']]);

        return $this->respuesta(
            $this->gestion->pagar($lote, $request->user('api')->id, $datos['referencia_pago'] ?? null),
            'Lote marcado como pagado.',
        );
    }

    public function anular(Request $request, LoteClasesExternas $lote): JsonResponse
    {
        $this->autorizar($request, $lote);
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        return $this->respuesta($this->gestion->anular($lote, $datos['motivo']), 'Lote anulado.');
    }

    public function exportarBbva(Request $request, LoteClasesExternas $lote): Response
    {
        $empresa = $this->autorizar($request, $lote);
        $cuenta = EmpresaCuentaBancaria::with('banco')->where('empresa_id', $empresa->id)->where('activo', true)
            ->where('uso', 'haberes')->whereHas('banco', fn ($q) => $q->where('codigo', 'bbva'))
            ->orderByDesc('es_predeterminada')->first();
        abort_unless($cuenta, 422, 'La empresa no tiene una cuenta BBVA activa para haberes.');

        $contenido = $this->gestion->exportarBbva($lote, $cuenta);

        Log::info('bbva_netcash.export', [
            'origen' => 'clases_externas', 'lote_id' => $lote->id, 'user_id' => $request->user('api')->id,
            'empresa_id' => $empresa->id, 'total_neto' => (string) $lote->total_neto,
        ]);

        return response($contenido, 200, [
            'Content-Type' => 'text/plain; charset=Windows-1252',
            'Content-Disposition' => 'attachment; filename="'.$this->gestion->nombreArchivo($lote).'.txt"',
        ]);
    }

    private function respuesta(LoteClasesExternas $lote, string $mensaje): JsonResponse
    {
        return response()->json(['message' => $mensaje, 'data' => $this->lote($lote)]);
    }

    private function autorizar(Request $request, LoteClasesExternas $lote): Empresa
    {
        $empresa = Empresa::findOrFail($lote->empresa_id);
        abort_unless($request->user('api')->tieneAccesoA($empresa), 403);

        return $empresa;
    }

    private function lote(LoteClasesExternas $l): array
    {
        return [
            'id' => $l->id,
            'empresa' => $l->empresa?->nombre_comercial ?? $l->empresa?->razon_social,
            'proveedor' => $l->proveedor,
            'referencia' => $l->referencia,
            'periodo_inicio' => $l->periodo_inicio->toDateString(),
            'periodo_fin' => $l->periodo_fin->toDateString(),
            'estado' => $l->estado,
            'total_clases' => $l->total_clases,
            'total_observadas' => $l->total_observadas,
            'total_bruto' => (float) $l->total_bruto,
            'total_retencion' => (float) $l->total_retencion,
            'total_neto' => (float) $l->total_neto,
            // Lo que realmente entra al TXT: pagos no observados.
            'neto_a_pagar' => (float) $l->pagos()->where('estado', '!=', PagoClasesExternas::OBSERVADO)->sum('monto_neto'),
            'enviado_por' => $l->enviado_por,
            'envios' => $l->envios,
            'recibido_at' => $l->recibido_at?->toIso8601String(),
            'observacion' => $l->observacion,
            'aprobado_at' => $l->aprobado_at?->toIso8601String(),
            'pagado_at' => $l->pagado_at?->toIso8601String(),
            'referencia_pago' => $l->referencia_pago,
        ];
    }

    private function pago(PagoClasesExternas $p): array
    {
        $c = $p->colaborador;

        return [
            'id' => $p->id,
            'colaborador_id' => $p->colaborador_id,
            'dni' => $c?->numero_documento,
            'instructora' => $c ? trim("{$c->apellido_paterno} {$c->apellido_materno} {$c->nombres}") : null,
            'clases_pagadas' => $p->clases_pagadas,
            'monto_bruto' => (float) $p->monto_bruto,
            'retencion_4ta' => (float) $p->retencion_4ta,
            'monto_neto' => (float) $p->monto_neto,
            'estado' => $p->estado,
            'observacion' => $p->observacion,
        ];
    }

    private function clase(ClaseExterna $c): array
    {
        return [
            'id' => $c->id,
            'clase_id' => $c->clase_id,
            'fecha' => $c->fecha->toDateString(),
            'hora_inicio' => substr((string) $c->hora_inicio, 0, 5),
            'hora_fin' => substr((string) $c->hora_fin, 0, 5),
            'tipo_clase' => $c->tipo_clase,
            'sede' => $c->sede,
            'estado' => $c->estado,
            'titular' => $c->titular_nombre,
            'dicto' => $c->ejecutor_nombre,
            'suplencia' => $c->esSuplencia(),
            'tarifa_aplicada' => $c->tarifa_aplicada !== null ? (float) $c->tarifa_aplicada : null,
            'monto' => (float) $c->monto,
            'observacion' => $c->observacion,
        ];
    }
}
