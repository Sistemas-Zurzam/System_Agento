<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Application\RecibirWebhookWhatsapp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Webhook público de WhatsApp Cloud API (/api/publico/crm/whatsapp/{token}). Sin JWT: el canal se
 * identifica por el token secreto de la URL y cada aviso se valida con la firma de Meta.
 */
class WebhookWhatsappController extends Controller
{
    public function __construct(private readonly RecibirWebhookWhatsapp $webhook) {}

    public function verificar(Request $request, string $token): Response
    {
        $canal = $this->webhook->canal($token);

        // PHP convierte «hub.mode» en «hub_mode» al leer la query.
        if ($canal && $this->webhook->verificacionValida($canal, $request->query('hub_mode'), $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
        }

        return response('Forbidden', 403, ['Content-Type' => 'text/plain']);
    }

    public function recibir(Request $request, string $token): JsonResponse
    {
        $canal = $this->webhook->canal($token);
        if (! $canal) {
            return response()->json(['message' => 'Canal no encontrado.'], 404);
        }

        if (! $this->webhook->firmaValida($canal, $request->getContent(), $request->header('X-Hub-Signature-256'))) {
            return response()->json(['message' => 'Firma inválida.'], 401);
        }

        $this->webhook->procesar($canal, (array) $request->json()->all());

        return response()->json(['ok' => true]);
    }
}
