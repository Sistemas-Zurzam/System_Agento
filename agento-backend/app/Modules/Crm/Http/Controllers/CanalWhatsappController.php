<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Http\Requests\GuardarCanalWhatsappRequest;
use App\Modules\Crm\Http\Resources\CanalWhatsappResource;
use App\Modules\Crm\Models\CanalWhatsapp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Canales de WhatsApp de la empresa activa (CRUD simple). La empresa sale del JWT, nunca del request;
 * EmpresaScope hace que {canal} de otra empresa responda 404.
 */
class CanalWhatsappController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CanalWhatsappResource::collection(
            CanalWhatsapp::query()->withCount('conversaciones')->orderBy('nombre')->get(),
        );
    }

    /** Lista corta para el filtro de la bandeja (no requiere permiso de configuración). */
    public function resumen(): JsonResponse
    {
        return response()->json([
            'data' => CanalWhatsapp::query()->orderBy('nombre')->get(['id', 'nombre', 'numero_visible', 'activo']),
        ]);
    }

    public function store(GuardarCanalWhatsappRequest $request): JsonResponse
    {
        $canal = CanalWhatsapp::query()->create([
            ...$request->safe()->except('activo'),
            'activo' => $request->boolean('activo', true),
            'empresa_id' => $request->user('api')->empresa_id,
            'webhook_token' => CanalWhatsapp::nuevoWebhookToken(),
            'creado_por' => $request->user('api')->id,
        ]);

        return (new CanalWhatsappResource($canal))->response()->setStatusCode(201);
    }

    public function update(GuardarCanalWhatsappRequest $request, CanalWhatsapp $canal): CanalWhatsappResource
    {
        $datos = $request->safe()->except(['access_token', 'app_secret']);
        foreach (['access_token', 'app_secret'] as $secreto) {
            if (filled($request->input($secreto))) {
                $datos[$secreto] = $request->input($secreto);
            }
        }

        $canal->update($datos);

        return new CanalWhatsappResource($canal->loadCount('conversaciones'));
    }

    public function destroy(CanalWhatsapp $canal): JsonResponse
    {
        if ($canal->conversaciones()->exists()) {
            return response()->json([
                'message' => 'El canal tiene conversaciones: desactívalo en lugar de eliminarlo para no perder el historial.',
            ], 422);
        }

        $canal->delete();

        return response()->json(null, 204);
    }

    /** Genera otra URL de webhook (invalida la anterior: hay que actualizarla en Meta). */
    public function regenerarWebhook(CanalWhatsapp $canal): CanalWhatsappResource
    {
        $canal->update(['webhook_token' => CanalWhatsapp::nuevoWebhookToken()]);

        return new CanalWhatsappResource($canal->loadCount('conversaciones'));
    }
}
