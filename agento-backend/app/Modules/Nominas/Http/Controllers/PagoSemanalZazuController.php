<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\EmpresaCuentaBancaria;
use App\Modules\Nominas\Models\PagoSemanalLote;
use App\Modules\Nominas\Models\PagoSemanalLoteDetalle;
use App\Modules\Nominas\Services\PagoSemanalZazuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Nóminas → Pagos semanales (Zazu): revisión, aprobación, TXT BBVA y pago de
 * los lotes que envía plataforma_zazu. Ver PagoSemanalZazuService.
 */
class PagoSemanalZazuController extends Controller
{
    public function __construct(private readonly PagoSemanalZazuService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            // Cualquier día de la semana buscada: se normaliza a su lunes.
            'semana' => ['nullable', 'date'],
            'estado' => ['nullable', 'in:recibido,observado,aprobado,pagado,anulado'],
        ]);
        $usuario = $request->user('api');
        $empresaIds = Empresa::query()->get()->filter(fn ($e) => $usuario->tieneAccesoA($e))->pluck('id')->all();
        $lunes = isset($filtros['semana'])
            ? \Illuminate\Support\Carbon::parse($filtros['semana'])->startOfWeek(\Illuminate\Support\Carbon::MONDAY)->toDateString()
            : null;

        $lotes = PagoSemanalLote::with('empresa:id,nombre_comercial,razon_social')
            ->withCount(['detalles', 'detalles as observados_count' => fn ($q) => $q->where('estado', PagoSemanalLote::OBSERVADO)])
            ->whereIn('empresa_id', $empresaIds)
            ->when($lunes, fn ($q, $lunes) => $q->whereDate('semana_inicio', $lunes))
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->orderByDesc('semana_inicio')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 20))));

        return response()->json([
            'data' => collect($lotes->items())->map(fn (PagoSemanalLote $l) => $this->lote($l))->values(),
            'meta' => ['total' => $lotes->total(), 'current_page' => $lotes->currentPage(), 'last_page' => $lotes->lastPage()],
        ]);
    }

    public function show(Request $request, PagoSemanalLote $lote): JsonResponse
    {
        $this->autorizar($request, $lote);
        $lote->load([
            'empresa:id,nombre_comercial,razon_social',
            'detalles.colaborador:id,nombres,apellido_paterno,apellido_materno,banco_id,numero_cuenta,cci',
            'detalles.colaborador.banco:id,nombre,codigo',
        ]);

        return response()->json(['data' => [
            ...$this->lote($lote),
            'detalles' => $lote->detalles->map(fn (PagoSemanalLoteDetalle $d) => $this->detalle($d))->values(),
        ]]);
    }

    public function aprobar(Request $request, PagoSemanalLote $lote): JsonResponse
    {
        $this->autorizar($request, $lote);

        return $this->respuesta($this->service->aprobar($lote, $request->user('api')->id), 'Lote aprobado.');
    }

    public function observar(Request $request, PagoSemanalLote $lote): JsonResponse
    {
        $this->autorizar($request, $lote);
        $datos = $request->validate(['observacion' => ['required', 'string', 'max:500']]);

        return $this->respuesta($this->service->observar($lote, $datos['observacion']), 'Lote devuelto a Zazu con observación.');
    }

    public function revalidar(Request $request, PagoSemanalLote $lote): JsonResponse
    {
        $this->autorizar($request, $lote);

        return $this->respuesta($this->service->revalidar($lote), 'Lote revalidado con los datos actuales de los colaboradores.');
    }

    public function pagar(Request $request, PagoSemanalLote $lote): JsonResponse
    {
        $this->autorizar($request, $lote);
        $datos = $request->validate(['referencia_pago' => ['nullable', 'string', 'max:120']]);

        return $this->respuesta(
            $this->service->marcarPagado($lote, $request->user('api')->id, $datos['referencia_pago'] ?? null),
            'Lote marcado como pagado.',
        );
    }

    public function anular(Request $request, PagoSemanalLote $lote): JsonResponse
    {
        $this->autorizar($request, $lote);
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        return $this->respuesta($this->service->anular($lote, $datos['motivo']), 'Lote anulado.');
    }

    public function exportarBbva(Request $request, PagoSemanalLote $lote): Response
    {
        $empresa = $this->autorizar($request, $lote);
        $cuenta = EmpresaCuentaBancaria::with('banco')->where('empresa_id', $empresa->id)->where('activo', true)
            ->where('uso', 'haberes')->whereHas('banco', fn ($q) => $q->where('codigo', 'bbva'))
            ->orderByDesc('es_predeterminada')->first();
        abort_unless($cuenta, 422, 'La empresa no tiene una cuenta BBVA activa para haberes.');

        $contenido = $this->service->exportarBbva($lote, $cuenta);
        $archivo = $this->service->nombreArchivoBbva($lote).'.txt';

        Log::info('bbva_netcash.export', [
            'origen' => 'pagos_semanales_zazu', 'lote_id' => $lote->id, 'user_id' => $request->user('api')->id,
            'empresa_id' => $empresa->id, 'total_neto' => (string) $lote->total_neto,
        ]);

        return response($contenido, 200, [
            'Content-Type' => 'text/plain; charset=Windows-1252',
            'Content-Disposition' => 'attachment; filename="'.$archivo.'"',
        ]);
    }

    private function respuesta(PagoSemanalLote $lote, string $mensaje): JsonResponse
    {
        return response()->json(['message' => $mensaje, 'data' => $this->lote($lote)]);
    }

    private function autorizar(Request $request, PagoSemanalLote $lote): Empresa
    {
        abort_unless($request->user('api')->tieneAccesoA($lote->empresa), 403);

        return $lote->empresa;
    }

    private function lote(PagoSemanalLote $l): array
    {
        return [
            'id' => $l->id,
            'empresa' => $l->empresa?->nombre_comercial ?? $l->empresa?->razon_social,
            'referencia' => $l->referencia,
            'semana_inicio' => $l->semana_inicio->toDateString(),
            'semana_fin' => $l->semana_fin->toDateString(),
            'semana_iso' => $l->semanaIso(),
            'estado' => $l->estado,
            'total_bruto' => (float) $l->total_bruto,
            'total_retencion' => (float) $l->total_retencion,
            'total_neto' => (float) $l->total_neto,
            // Lo que realmente entra al TXT: las líneas no observadas.
            'neto_a_pagar' => (float) $l->detalles()->where('estado', '!=', PagoSemanalLote::OBSERVADO)->sum('monto_neto'),
            'validos' => $l->detalles()->where('estado', '!=', PagoSemanalLote::OBSERVADO)->count(),
            'lineas' => $l->detalles_count ?? $l->detalles()->count(),
            'observados' => $l->observados_count ?? $l->detalles()->where('estado', PagoSemanalLote::OBSERVADO)->count(),
            'enviado_por' => $l->enviado_por,
            'envios' => $l->envios,
            'recibido_at' => $l->recibido_at?->toIso8601String(),
            'observacion' => $l->observacion,
            'aprobado_at' => $l->aprobado_at?->toIso8601String(),
            'pagado_at' => $l->pagado_at?->toIso8601String(),
            'referencia_pago' => $l->referencia_pago,
        ];
    }

    private function detalle(PagoSemanalLoteDetalle $d): array
    {
        $c = $d->colaborador;

        return [
            'id' => $d->id,
            'dni' => $d->numero_documento,
            'nombre_zazu' => $d->nombre_origen,
            'alias' => $d->alias_origen,
            'colaborador' => $c ? trim("{$c->apellido_paterno} {$c->apellido_materno} {$c->nombres}") : null,
            'banco' => $c?->banco?->nombre,
            'tiene_cuenta' => (bool) ($c && $c->banco_id && ($c->numero_cuenta || $c->cci)),
            'dias_trabajados' => $d->dias_trabajados,
            'dias_base' => $d->dias_base,
            'pedidos_entregados' => $d->pedidos_entregados,
            'monto_dias' => (float) $d->monto_dias,
            'monto_bonos' => (float) $d->monto_bonos,
            'adicional' => (float) $d->adicional,
            'descuento' => (float) $d->descuento,
            'monto_bruto' => (float) $d->monto_bruto,
            'retencion_4ta' => (float) $d->retencion_4ta,
            'monto_neto' => (float) $d->monto_neto,
            'estado' => $d->estado,
            'observacion' => $d->observacion,
            'aviso' => $d->aviso,
        ];
    }
}
