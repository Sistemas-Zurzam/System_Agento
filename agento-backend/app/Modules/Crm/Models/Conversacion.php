<?php

namespace App\Modules\Crm\Models;

use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conversación de WhatsApp con un cliente (wa_id = su número, solo dígitos) en un canal.
 * ultimo_texto_mensaje es una vista previa para el listado (se recorta a 255 caracteres).
 */
#[ScopedBy([EmpresaScope::class])]
#[Fillable([
    'empresa_id', 'canal_id', 'wa_id', 'nombre_contacto', 'ultimo_texto_mensaje', 'ultimo_mensaje_at',
    'ultimo_entrante_at', 'no_leidos',
])]
class Conversacion extends Model
{
    /** Meta solo permite mensajes libres hasta 24 h después del último mensaje del cliente. */
    public const VENTANA_HORAS = 24;

    protected $table = 'crm_conversaciones';

    protected function casts(): array
    {
        return [
            'ultimo_mensaje_at' => 'datetime',
            'ultimo_entrante_at' => 'datetime',
            'no_leidos' => 'integer',
        ];
    }

    public function ventanaAbierta(): bool
    {
        return $this->ultimo_entrante_at !== null
            && $this->ultimo_entrante_at->gt(now()->subHours(self::VENTANA_HORAS));
    }

    public function canal(): BelongsTo
    {
        return $this->belongsTo(CanalWhatsapp::class, 'canal_id');
    }

    public function mensajes(): HasMany
    {
        return $this->hasMany(Mensaje::class, 'conversacion_id');
    }
}
