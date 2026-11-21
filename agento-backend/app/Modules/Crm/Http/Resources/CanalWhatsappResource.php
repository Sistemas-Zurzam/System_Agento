<?php

namespace App\Modules\Crm\Http\Resources;

use App\Modules\Crm\Models\CanalWhatsapp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Canal para la pantalla de configuración. El token de acceso y el App secret nunca salen: solo se
 * indica si están cargados y sus últimos 4 caracteres. El token de verificación y la URL del webhook
 * sí, porque hay que copiarlos en Meta.
 *
 * @mixin CanalWhatsapp
 */
class CanalWhatsappResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'phone_number_id' => $this->phone_number_id,
            'waba_id' => $this->waba_id,
            'numero_visible' => $this->numero_visible,
            'activo' => $this->activo,
            'verify_token' => $this->verify_token,
            'url_webhook' => $this->urlWebhook(),
            'access_token_final' => self::final($this->access_token),
            'app_secret_final' => self::final($this->app_secret),
            'conversaciones_count' => $this->whenCounted('conversaciones'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private static function final(?string $secreto): ?string
    {
        return $secreto ? '…'.substr($secreto, -4) : null;
    }
}
