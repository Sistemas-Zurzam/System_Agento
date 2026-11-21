<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Application\EnviarMensajeWhatsapp;
use App\Modules\Crm\Http\Requests\EnviarMensajeRequest;
use App\Modules\Crm\Http\Resources\ConversacionResource;
use App\Modules\Crm\Http\Resources\MensajeResource;
use App\Modules\Crm\Models\Conversacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Bandeja de conversaciones de WhatsApp de la empresa activa. EmpresaScope hace que una
 * {conversacion} de otra empresa responda 404.
 */
class ConversacionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filtros = $request->validate([
            'canal_id' => ['nullable', 'integer'],
            'buscar' => ['nullable', 'string', 'max:100'],
            'no_leidas' => ['nullable', 'boolean'],
        ]);

        $consulta = Conversacion::query()->with('canal')
            ->when($filtros['canal_id'] ?? null, fn ($q, $canalId) => $q->where('canal_id', $canalId))
            ->when($request->boolean('no_leidas'), fn ($q) => $q->where('no_leidos', '>', 0))
            ->when(trim((string) ($filtros['buscar'] ?? '')), function ($q, $buscar) {
                $digitos = preg_replace('/\D/', '', $buscar);
                $q->where(function ($q) use ($buscar, $digitos) {
                    $q->where('nombre_contacto', 'like', '%'.$buscar.'%');
                    if ($digitos !== '') {
                        $q->orWhere('wa_id', 'like', '%'.$digitos.'%');
                    }
                });
            })
            ->orderByDesc('ultimo_mensaje_at')->orderByDesc('id');

        return ConversacionResource::collection($consulta->paginate(30));
    }

    /** Conversación y sus mensajes más recientes; con `despues_de` solo los nuevos (para refrescar). */
    public function mensajes(Request $request, Conversacion $conversacion): JsonResponse
    {
        $despuesDe = (int) ($request->validate(['despues_de' => ['nullable', 'integer', 'min:0']])['despues_de'] ?? 0);

        $mensajes = $conversacion->mensajes()->with('autor:id,name')
            ->when($despuesDe > 0, fn ($q) => $q->where('id', '>', $despuesDe))
            ->orderByDesc('id')->limit((int) config('crm.mensajes_por_conversacion'))->get()->reverse()->values();

        return response()->json([
            'conversacion' => new ConversacionResource($conversacion->load('canal')),
            'data' => MensajeResource::collection($mensajes),
        ]);
    }

    public function marcarLeida(Conversacion $conversacion): JsonResponse
    {
        if ($conversacion->no_leidos > 0) {
            $conversacion->update(['no_leidos' => 0]);
        }

        return response()->json(null, 204);
    }

    public function enviar(EnviarMensajeRequest $request, Conversacion $conversacion, EnviarMensajeWhatsapp $enviar): JsonResponse
    {
        $mensaje = $enviar($conversacion, (string) $request->validated('texto_mensaje'), $request->user('api'));

        return (new MensajeResource($mensaje))->response()->setStatusCode(201);
    }
}
