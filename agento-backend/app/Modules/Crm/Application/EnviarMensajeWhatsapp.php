<?php

namespace App\Modules\Crm\Application;

use App\Models\User;
use App\Modules\Crm\Infrastructure\WhatsappCloudApi;
use App\Modules\Crm\Models\Conversacion;
use App\Modules\Crm\Models\Mensaje;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Respuesta manual de un usuario a una conversación. Solo texto libre dentro de la ventana de 24 h
 * (fuera de ella Meta exige una plantilla aprobada, que esta versión no envía).
 */
class EnviarMensajeWhatsapp
{
    public function __construct(private readonly WhatsappCloudApi $api) {}

    public function __invoke(Conversacion $conversacion, string $texto, User $usuario): Mensaje
    {
        $canal = $conversacion->canal;

        if (! $canal || ! $canal->activo) {
            throw ValidationException::withMessages(['texto_mensaje' => 'El canal de WhatsApp de esta conversación está desactivado.']);
        }

        if (! $conversacion->ventanaAbierta()) {
            throw ValidationException::withMessages(['texto_mensaje' => 'Pasaron más de 24 h desde el último mensaje del cliente: WhatsApp solo permite escribirle con una plantilla aprobada.']);
        }

        try {
            $waMessageId = $this->api->enviarTexto($canal, $conversacion->wa_id, $texto);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['texto_mensaje' => $e->getMessage()]);
        }

        $mensaje = Mensaje::query()->create([
            'empresa_id' => $conversacion->empresa_id,
            'conversacion_id' => $conversacion->id,
            'direccion' => Mensaje::SALIENTE,
            'tipo' => 'text',
            'texto_mensaje' => $texto,
            'wa_message_id' => $waMessageId,
            'estado' => Mensaje::ENVIADO,
            'enviado_por' => $usuario->id,
        ]);

        $conversacion->update([
            'ultimo_texto_mensaje' => Str::limit($texto, 250),
            'ultimo_mensaje_at' => now(),
            'no_leidos' => 0,
        ]);

        return $mensaje->setRelation('autor', $usuario);
    }
}
