<?php

namespace App\Modules\Integraciones\Models;

use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lo que se le paga a una instructora por las clases de un lote (recibo por honorarios, 4ta categoría). */
#[ScopedBy([EmpresaScope::class])]
#[Fillable([
    'lote_id', 'empresa_id', 'colaborador_id', 'clases_pagadas', 'monto_bruto', 'retencion_4ta', 'monto_neto',
    'estado', 'observacion', 'banco_id', 'tipo_cuenta_snapshot', 'moneda_snapshot', 'numero_cuenta_snapshot', 'cci_snapshot',
])]
class PagoClasesExternas extends Model
{
    public const RECIBIDO = 'recibido';

    public const OBSERVADO = 'observado';

    public const APROBADO = 'aprobado';

    public const PAGADO = 'pagado';

    protected $table = 'clases_externas_pagos';

    protected function casts(): array
    {
        return ['monto_bruto' => 'decimal:2', 'retencion_4ta' => 'decimal:2', 'monto_neto' => 'decimal:2'];
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(LoteClasesExternas::class, 'lote_id');
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }
}
