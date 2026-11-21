<?php

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mensaje de una conversación. El texto va en `texto_mensaje`: la auditoría oculta esa clave.
 */
#[ScopedBy([EmpresaScope::class])]
#[Fillable([
    'empresa_id', 'conversacion_id', 'direccion', 'tipo', 'texto_mensaje', 'wa_message_id', 'estado', 'error',
    'enviado_por',
])]
class Mensaje extends Model
{
    public const ENTRANTE = 'entrante';

    public const SALIENTE = 'saliente';

    public const RECIBIDO = 'recibido';

    public const ENVIADO = 'enviado';

    public const ENTREGADO = 'entregado';

    public const LEIDO = 'leido';

    public const FALLIDO = 'fallido';

    /** Estados que informa Meta (statuses[].status) → estado del mensaje. */
    public const ESTADOS_META = [
        'sent' => self::ENVIADO,
        'delivered' => self::ENTREGADO,
        'read' => self::LEIDO,
        'failed' => self::FALLIDO,
    ];

    /** Orden para no retroceder (Meta puede avisar «delivered» después de «read»). */
    public const ORDEN_ESTADOS = [self::ENVIADO => 1, self::ENTREGADO => 2, self::LEIDO => 3];

    protected $table = 'crm_mensajes';

    public function conversacion(): BelongsTo
    {
        return $this->belongsTo(Conversacion::class, 'conversacion_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }
}
