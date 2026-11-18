<?php

namespace App\Modules\Reclutamiento\Models;

use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy([EmpresaScope::class])]
#[Fillable(['empresa_id', 'prueba_id', 'token', 'activo', 'creado_por'])]
class PruebaEnlace extends Model
{
    protected $table = 'seleccion_prueba_enlaces';

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function prueba(): BelongsTo
    {
        return $this->belongsTo(Prueba::class, 'prueba_id');
    }

    public function asignaciones(): HasMany
    {
        return $this->hasMany(PruebaAsignacion::class, 'enlace_id');
    }
}
