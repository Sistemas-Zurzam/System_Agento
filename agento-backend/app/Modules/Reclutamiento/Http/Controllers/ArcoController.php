<?php

namespace App\Modules\Reclutamiento\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reclutamiento\Application\ArcoService;
use App\Modules\Reclutamiento\Models\SolicitudArco;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bandeja de solicitudes ARCO para RR. HH. (de la empresa del usuario: el binding de {solicitud}
 * aplica el scope de empresa).
 */
class ArcoController extends Controller
{
    public function __construct(private readonly ArcoService $arco) {}

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'estado' => ['nullable', 'in:'.implode(',', SolicitudArco::ESTADOS)],
            'tipo' => ['nullable', 'in:'.implode(',', SolicitudArco::TIPOS)],
            'vencidas' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $pagina = $this->arco->listar($filtros, (int) ($filtros['per_page'] ?? 20));

        return response()->json([
            'data' => $pagina->getCollection()->map(fn (SolicitudArco $s) => $this->presentar($s))->values(),
            'meta' => ['total' => $pagina->total(), 'per_page' => $pagina->perPage(), 'current_page' => $pagina->currentPage(), 'last_page' => $pagina->lastPage()],
        ]);
    }

    public function atender(Request $request, SolicitudArco $solicitud): JsonResponse
    {
        $datos = $request->validate([
            'estado' => ['required', 'in:'.implode(',', [SolicitudArco::EN_PROCESO, SolicitudArco::ATENDIDA, SolicitudArco::RECHAZADA])],
            'respuesta' => ['nullable', 'string', 'max:2000'],
        ]);

        $solicitud = $this->arco->atender($solicitud, $datos['estado'], $datos['respuesta'] ?? null, $request->user('api'), $request);

        return response()->json(['data' => $this->presentar($solicitud->load('candidato:id,nombres,apellido_paterno,apellido_materno,email,numero_documento'))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(SolicitudArco $s): array
    {
        return [
            'id' => $s->id,
            'tipo' => $s->tipo,
            'estado' => $s->estado,
            'detalle' => $s->detalle,
            'respuesta' => $s->respuesta,
            'solicitada_at' => $s->solicitada_at->toIso8601String(),
            'vence_at' => $s->vence_at->toIso8601String(),
            'vencida' => $s->vencida(),
            'atendida_at' => $s->atendida_at?->toIso8601String(),
            'candidato' => $s->candidato ? [
                'id' => $s->candidato->id,
                'nombre' => $s->candidato->nombreCompleto(),
                'email' => $s->candidato->email,
                'documento' => $s->candidato->numero_documento,
            ] : null,
        ];
    }
}
