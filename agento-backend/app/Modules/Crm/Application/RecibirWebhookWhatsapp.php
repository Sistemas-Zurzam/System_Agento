<?php

namespace App\Modules\Crm\Application;

use App\Modules\Configuracion\Support\EmpresaContext;
use App\Modules\Crm\Models\CanalWhatsapp;
use App\Modules\Crm\Models\Conversacion;
use App\Modules\Crm\Models\Mensaje;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Webhook de WhatsApp Cloud API. Meta no envía la empresa: el canal (y con él la empresa) sale del
 * token secreto de la URL, buscado en modo sistema. Todo lo demás corre dentro de la empresa del canal.
 *
 * - GET (verificación): responde el challenge si el token de verificación coincide.
 * - POST (avisos): exige la firma X-Hub-Signature-256 con el App secret del canal; guarda los mensajes
 *   entrantes (sin duplicar si Meta reintenta) y actualiza el estado de los salientes.
 */
class RecibirWebhookWhatsapp
{
    public function canal(string $webhookToken): ?CanalWhatsapp
    {
        if (strlen($webhookToken) !== 40) {
            return null;
        }

        return EmpresaContext::comoSistema(
            fn () => CanalWhatsapp::query()->where('webhook_token', $webhookToken)->first(),
            motivo: 'Webhook de WhatsApp: Meta no envia la empresa, se resuelve por el token secreto del canal',
        );
    }

    public function verificacionValida(CanalWhatsapp $canal, ?string $modo, ?string $verifyToken): bool
    {
        return $canal->activo && $modo === 'subscribe' && hash_equals($canal->verify_token, (string) $verifyToken);
    }

    public function firmaValida(CanalWhatsapp $canal, string $cuerpo, ?string $firma): bool
    {
        $secreto = (string) $canal->app_secret;

        return $secreto !== '' && hash_equals('sha256='.hash_hmac('sha256', $cuerpo, $secreto), (string) $firma);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function procesar(CanalWhatsapp $canal, array $payload): void
    {
        if (! $canal->activo) {
            return;
        }

        EmpresaContext::como((int) $canal->empresa_id, function () use ($canal, $payload) {
            foreach ((array) ($payload['entry'] ?? []) as $entrada) {
                foreach ((array) ($entrada['changes'] ?? []) as $cambio) {
                    $valor = (array) ($cambio['value'] ?? []);
                    // Solo lo dirigido al número de este canal.
                    if ((string) ($valor['metadata']['phone_number_id'] ?? '') !== $canal->phone_number_id) {
                        continue;
                    }

                    $nombres = [];
                    foreach ((array) ($valor['contacts'] ?? []) as $contacto) {
                        $nombres[(string) ($contacto['wa_id'] ?? '')] = $contacto['profile']['name'] ?? null;
                    }

                    foreach ((array) ($valor['messages'] ?? []) as $mensaje) {
                        $this->guardarEntrante($canal, (array) $mensaje, $nombres);
                    }

                    foreach ((array) ($valor['statuses'] ?? []) as $estado) {
                        $this->actualizarEstado((array) $estado);
                    }
                }
            }
        });
    }

    /**
     * @param  array<string, mixed>  $mensaje
     * @param  array<string, string|null>  $nombres
     */
    private function guardarEntrante(CanalWhatsapp $canal, array $mensaje, array $nombres): void
    {
        $waId = preg_replace('/\D/', '', (string) ($mensaje['from'] ?? ''));
        $waMessageId = (string) ($mensaje['id'] ?? '');
        if ($waId === '' || $waMessageId === '' || Mensaje::query()->where('wa_message_id', $waMessageId)->exists()) {
            return;
        }

        $texto = TextoWhatsapp::de($mensaje);
        $momento = isset($mensaje['timestamp']) && is_numeric($mensaje['timestamp'])
            ? now()->setTimestamp((int) $mensaje['timestamp'])
            : now();

        DB::transaction(function () use ($canal, $mensaje, $nombres, $waId, $waMessageId, $texto, $momento) {
            $conversacion = Conversacion::query()->firstOrCreate(
                ['canal_id' => $canal->id, 'wa_id' => $waId],
                ['empresa_id' => $canal->empresa_id],
            );

            Mensaje::query()->create([
                'empresa_id' => $canal->empresa_id,
                'conversacion_id' => $conversacion->id,
                'direccion' => Mensaje::ENTRANTE,
                'tipo' => Str::limit((string) ($mensaje['type'] ?? 'text'), 20, ''),
                'texto_mensaje' => $texto,
                'wa_message_id' => $waMessageId,
                'estado' => Mensaje::RECIBIDO,
            ]);

            $nombre = $nombres[$waId] ?? null;
            $conversacion->fill([
                'nombre_contacto' => $nombre ? Str::limit($nombre, 150, '') : $conversacion->nombre_contacto,
                'ultimo_texto_mensaje' => Str::limit($texto, 250),
                'ultimo_mensaje_at' => $momento,
                'ultimo_entrante_at' => $momento,
                'no_leidos' => $conversacion->no_leidos + 1,
            ])->save();
        });
    }

    /**
     * @param  array<string, mixed>  $estado
     */
    private function actualizarEstado(array $estado): void
    {
        $nuevo = Mensaje::ESTADOS_META[(string) ($estado['status'] ?? '')] ?? null;
        $mensaje = $nuevo ? Mensaje::query()->where('wa_message_id', (string) ($estado['id'] ?? ''))->first() : null;
        if (! $mensaje || $mensaje->direccion !== Mensaje::SALIENTE) {
            return;
        }

        if ($nuevo === Mensaje::FALLIDO) {
            $error = (array) ($estado['errors'][0] ?? []);
            $mensaje->update([
                'estado' => Mensaje::FALLIDO,
                'error' => Str::limit('WhatsApp no entregó el mensaje'.(isset($error['code']) ? ' (código '.$error['code'].')' : ''), 250, ''),
            ]);

            return;
        }

        // No retroceder: «delivered» puede llegar después de «read».
        if ((Mensaje::ORDEN_ESTADOS[$nuevo] ?? 0) > (Mensaje::ORDEN_ESTADOS[$mensaje->estado] ?? 0)) {
            $mensaje->update(['estado' => $nuevo]);
        }
    }
}
