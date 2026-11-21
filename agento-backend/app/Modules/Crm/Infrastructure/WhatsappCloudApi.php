<?php

namespace App\Modules\Crm\Infrastructure;

use App\Modules\Crm\Models\CanalWhatsapp;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Cliente de la Graph API de Meta para WhatsApp Cloud API. Nunca registra el token ni el contenido.
 */
class WhatsappCloudApi
{
    /**
     * Envía un mensaje de texto. Devuelve el id del mensaje en Meta (wamid).
     *
     * @throws RuntimeException con un mensaje apto para el usuario si Meta lo rechaza.
     */
    public function enviarTexto(CanalWhatsapp $canal, string $para, string $texto): string
    {
        $url = rtrim((string) config('crm.graph_url'), '/').'/'.config('crm.graph_version').'/'.$canal->phone_number_id.'/messages';

        try {
            $respuesta = Http::withToken($canal->access_token)
                ->acceptJson()
                ->timeout((int) config('crm.timeout'))
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $para,
                    'type' => 'text',
                    'text' => ['preview_url' => false, 'body' => $texto],
                ]);
        } catch (Throwable) {
            throw new RuntimeException('No se pudo conectar con WhatsApp. Inténtalo de nuevo.');
        }

        $id = $respuesta->json('messages.0.id');
        if ($respuesta->successful() && is_string($id) && $id !== '') {
            return $id;
        }

        throw new RuntimeException(self::mensajeDeError($respuesta->json('error') ?? []));
    }

    /**
     * @param  array<string, mixed>  $error  objeto `error` de la Graph API
     */
    private static function mensajeDeError(array $error): string
    {
        return match ((int) ($error['code'] ?? 0)) {
            190 => 'El token de acceso del canal no es válido o venció. Actualízalo en Canales de WhatsApp.',
            10, 200 => 'El token del canal no tiene permiso sobre este número de WhatsApp.',
            131047 => 'Pasaron más de 24 h desde el último mensaje del cliente: solo se le puede escribir con una plantilla aprobada.',
            131026 => 'El número del cliente no puede recibir mensajes de WhatsApp.',
            default => 'WhatsApp rechazó el mensaje'.(isset($error['code']) ? ' (código '.$error['code'].')' : '').'.',
        };
    }
}
