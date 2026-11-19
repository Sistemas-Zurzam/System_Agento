<?php

namespace App\Modules\Reclutamiento\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Texto legal versionado (no pertenece a una empresa: es el texto que acepta el candidato).
 */
#[Fillable(['tipo', 'version', 'texto', 'hash', 'vigente_desde', 'vigente_hasta'])]
class TextoLegal extends Model
{
    public const CONSENTIMIENTO_POSTULACION = 'consentimiento_postulacion';

    public const POLITICA_PRIVACIDAD = 'politica_privacidad';

    public const TIPOS = [self::CONSENTIMIENTO_POSTULACION, self::POLITICA_PRIVACIDAD];

    protected $table = 'seleccion_textos_legales';

    protected function casts(): array
    {
        return ['vigente_desde' => 'datetime', 'vigente_hasta' => 'datetime'];
    }
}
