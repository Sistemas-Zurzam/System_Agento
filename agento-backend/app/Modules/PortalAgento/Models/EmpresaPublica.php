<?php

namespace App\Modules\PortalAgento\Models;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Perfil público mínimo de la empresa en Agento: slug estable, sector, tamaño, descripción y sitio web.
 */
#[ScopedBy([EmpresaScope::class])]
#[Fillable(['empresa_id', 'slug', 'sector', 'tamano', 'descripcion', 'sitio_web'])]
class EmpresaPublica extends Model
{
    protected $table = 'agento_empresas_publicas';

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
