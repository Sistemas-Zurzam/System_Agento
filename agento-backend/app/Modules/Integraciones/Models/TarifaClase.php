<?php

namespace App\Modules\Integraciones\Models;

use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tarifa por clase de una instructora, con vigencia. Historial: nunca se edita, se agrega una fila nueva.
 * La vigente a una fecha es la de mayor vigencia_desde menor o igual a esa fecha.
 */
#[ScopedBy([EmpresaScope::class])]
#[Fillable(['empresa_id', 'colaborador_id', 'monto', 'vigencia_desde', 'creado_por'])]
class TarifaClase extends Model
{
    protected $table = 'tarifas_clase';

    protected function casts(): array
    {
        return ['monto' => 'decimal:2', 'vigencia_desde' => 'date'];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }
}
