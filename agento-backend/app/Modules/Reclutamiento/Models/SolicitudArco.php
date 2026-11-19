<?php

namespace App\Modules\Reclutamiento\Models;

use App\Models\User;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Solicitud de derechos ARCO de un candidato (acceso, rectificación, cancelación) ante una empresa.
 */
#[ScopedBy([EmpresaScope::class])]
#[Fillable(['empresa_id', 'candidato_id', 'tipo', 'estado', 'detalle', 'respuesta', 'solicitada_at', 'vence_at', 'atendida_at', 'atendida_por'])]
class SolicitudArco extends Model
{
    public const ACCESO = 'acceso';

    public const RECTIFICACION = 'rectificacion';

    public const CANCELACION = 'cancelacion';

    public const TIPOS = [self::ACCESO, self::RECTIFICACION, self::CANCELACION];

    public const PENDIENTE = 'pendiente';

    public const EN_PROCESO = 'en_proceso';

    public const ATENDIDA = 'atendida';

    public const RECHAZADA = 'rechazada';

    public const ESTADOS = [self::PENDIENTE, self::EN_PROCESO, self::ATENDIDA, self::RECHAZADA];

    protected $table = 'seleccion_solicitudes_arco';

    protected function casts(): array
    {
        return [
            'detalle' => 'array',
            'solicitada_at' => 'datetime',
            'vence_at' => 'datetime',
            'atendida_at' => 'datetime',
        ];
    }

    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidato::class);
    }

    public function atendidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atendida_por');
    }

    public function estaAbierta(): bool
    {
        return in_array($this->estado, [self::PENDIENTE, self::EN_PROCESO], true);
    }

    public function vencida(): bool
    {
        return $this->estaAbierta() && $this->vence_at->isPast();
    }
}
