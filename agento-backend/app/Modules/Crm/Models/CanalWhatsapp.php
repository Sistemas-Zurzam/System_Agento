<?php

namespace App\Modules\Crm\Models;

use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Número de WhatsApp Cloud API (Meta) de una empresa. El token de acceso y el App secret se guardan
 * cifrados y nunca salen en las respuestas de la API (ver CanalWhatsappResource).
 */
#[ScopedBy([EmpresaScope::class])]
#[Fillable([
    'empresa_id', 'nombre', 'phone_number_id', 'waba_id', 'numero_visible', 'access_token', 'app_secret',
    'verify_token', 'webhook_token', 'activo', 'creado_por',
])]
#[Hidden(['access_token', 'app_secret', 'verify_token', 'webhook_token'])]
class CanalWhatsapp extends Model
{
    protected $table = 'crm_canales_whatsapp';

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'app_secret' => 'encrypted',
            'activo' => 'boolean',
        ];
    }

    public static function nuevoWebhookToken(): string
    {
        return Str::random(40);
    }

    /**
     * Se arma con APP_URL y no con la petición actual: detrás de Apache/proxy la petición puede llegar
     * como http y Meta exige https (no sigue la redirección al verificar el webhook).
     */
    public function urlWebhook(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/publico/crm/whatsapp/'.$this->webhook_token;
    }

    public function conversaciones(): HasMany
    {
        return $this->hasMany(Conversacion::class, 'canal_id');
    }
}
