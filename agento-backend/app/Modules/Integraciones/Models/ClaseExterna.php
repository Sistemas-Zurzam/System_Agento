<?php

namespace App\Modules\Integraciones\Models;

use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Clase dictada informada por un sistema externo. Guarda quién era la titular y quién la dictó: la
 * suplencia es que sean distintas (la tarifa que se pagará es la de la titular; eso se calcula en una fase
 * posterior, aquí solo se recibe y se identifica a las personas por DNI).
 */
#[ScopedBy([EmpresaScope::class])]
#[Fillable([
    'lote_id', 'empresa_id', 'clase_id', 'fecha', 'hora_inicio', 'hora_fin', 'tipo_clase', 'sede', 'estado',
    'motivo_suplencia', 'titular_dni', 'titular_nombre', 'titular_colaborador_id',
    'ejecutor_dni', 'ejecutor_nombre', 'ejecutor_colaborador_id', 'observacion', 'tarifa_aplicada', 'monto',
])]
class ClaseExterna extends Model
{
    public const DICTADA = 'dictada';

    public const CANCELADA_ESTUDIO = 'cancelada_estudio';

    public const NO_DICTADA = 'no_dictada';

    public const ESTADOS = [self::DICTADA, self::CANCELADA_ESTUDIO, self::NO_DICTADA];

    protected $table = 'clases_externas';

    protected function casts(): array
    {
        return ['fecha' => 'date', 'tarifa_aplicada' => 'decimal:2', 'monto' => 'decimal:2'];
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(LoteClasesExternas::class, 'lote_id');
    }

    public function esSuplencia(): bool
    {
        return $this->estado === self::DICTADA
            && $this->ejecutor_dni !== null
            && $this->ejecutor_dni !== $this->titular_dni;
    }
}
