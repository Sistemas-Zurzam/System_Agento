<?php

namespace App\Modules\PortalAgento\Models;

use App\Models\User;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historial de una publicación. Solo identificadores y estados: nunca datos personales.
 */
#[ScopedBy([EmpresaScope::class])]
#[Fillable(['empresa_id', 'publicacion_id', 'evento', 'estado_desde', 'estado_hasta', 'usuario_id', 'detalle'])]
class PublicacionEvento extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'agento_publicacion_eventos';

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
