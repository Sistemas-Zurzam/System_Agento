<?php

namespace App\Modules\PortalAgento\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo público de Agento (sin empresa: es común a todas). Valores fijos del contrato.
 */
#[Fillable(['clave', 'etiqueta', 'orden'])]
class JornadaAgento extends Model
{
    public $timestamps = false;

    protected $table = 'agento_jornadas';
}
