<?php

namespace App\Modules\Reclutamiento\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Modules\Reclutamiento\Application\ArcoService;
use App\Modules\Reclutamiento\Application\TextosLegalesService;
use App\Modules\Reclutamiento\Models\SolicitudArco;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Derechos ARCO y revocación del consentimiento del candidato (sesión verificada por correo).
 * El texto legal vigente es público.
 */
class ArcoCandidatoController extends Controller
{
    public function __construct(
        private readonly ArcoService $arco,
        private readonly TextosLegalesService $textos,
    ) {}

    public function textoLegal(string $tipo): JsonResponse
    {
        $texto = $this->textos->vigenteOFalla($tipo);

        return response()->json(['data' => [
            'tipo' => $texto->tipo,
            'version' => $texto->version,
            'texto' => $texto->texto,
            'hash' => $texto->hash,
            'vigente_desde' => $texto->vigente_desde->toIso8601String(),
        ]]);
    }

    public function exportacion(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->arco->exportar($request->attributes->get('acceso_candidato'), $request)]);
    }

    public function documento(Request $request, int $documento): StreamedResponse
    {
        return $this->arco->descargarDocumento($request->attributes->get('acceso_candidato'), $documento, $request);
    }

    public function solicitudes(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->presentar($this->arco->solicitudesDe($request->attributes->get('acceso_candidato')))]);
    }

    public function rectificacion(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'cambios' => ['required', 'array', 'min:1'],
            'cambios.tipo_documento' => ['sometimes', 'string', 'in:dni,ce,pasaporte'],
            'cambios.numero_documento' => ['sometimes', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]+$/'],
            'cambios.nombres' => ['sometimes', 'string', 'max:120'],
            'cambios.apellido_paterno' => ['sometimes', 'string', 'max:100'],
            'cambios.apellido_materno' => ['sometimes', 'nullable', 'string', 'max:100'],
            'cambios.celular' => ['sometimes', 'string', 'max:20'],
            'cambios.ubicacion' => ['sometimes', 'nullable', 'string', 'max:150'],
            'cambios.resumen' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'motivo' => ['nullable', 'string', 'max:1000'],
        ]);

        $solicitudes = $this->arco->solicitar($request->attributes->get('acceso_candidato'), SolicitudArco::RECTIFICACION, [
            'cambios' => $datos['cambios'], 'motivo' => $datos['motivo'] ?? null,
        ], $request);

        return response()->json(['data' => $this->presentar($solicitudes)], 201);
    }

    public function cancelacion(Request $request): JsonResponse
    {
        $datos = $request->validate(['motivo' => ['nullable', 'string', 'max:1000']]);

        $solicitudes = $this->arco->solicitar($request->attributes->get('acceso_candidato'), SolicitudArco::CANCELACION, [
            'motivo' => $datos['motivo'] ?? null,
        ], $request);

        return response()->json(['data' => $this->presentar($solicitudes)], 201);
    }

    public function revocarConsentimiento(Request $request): JsonResponse
    {
        $resultado = $this->arco->revocarConsentimiento($request->attributes->get('acceso_candidato'), $request);

        return response()->json(['data' => $resultado, 'message' => 'Revocaste tu consentimiento. Retiramos tus postulaciones en curso y registramos la solicitud de eliminación de tus datos.']);
    }

    /**
     * @param  Collection<int, SolicitudArco>  $solicitudes
     * @return list<array<string, mixed>>
     */
    private function presentar(Collection $solicitudes): array
    {
        return $solicitudes->map(fn (SolicitudArco $s) => [
            'id' => $s->id,
            'tipo' => $s->tipo,
            'estado' => $s->estado,
            'solicitada_at' => $s->solicitada_at->toIso8601String(),
            'vence_at' => $s->vence_at->toIso8601String(),
            'atendida_at' => $s->atendida_at?->toIso8601String(),
            'respuesta' => $s->respuesta,
        ])->values()->all();
    }
}
