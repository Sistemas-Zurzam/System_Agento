<?php

namespace App\Modules\Nominas\Models;

use App\Modules\Configuracion\Models\Banco;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'lote_id', 'colaborador_id', 'numero_documento', 'nombre_origen', 'alias_origen',
    'dias_trabajados', 'dias_base', 'pedidos_entregados', 'monto_dias', 'monto_bonos', 'adicional', 'descuento',
    'monto_bruto', 'retencion_4ta', 'monto_neto', 'estado', 'observacion', 'aviso', 'detalle_origen',
    'banco_id', 'tipo_cuenta_snapshot', 'moneda_snapshot', 'numero_cuenta_snapshot', 'cci_snapshot',
])]
class PagoSemanalLoteDetalle extends Model
{
    protected $table = 'pagos_semanales_lote_detalles';

    protected function casts(): array
    {
        return [
            'monto_dias' => 'decimal:2',
            'monto_bonos' => 'decimal:2',
            'adicional' => 'decimal:2',
            'descuento' => 'decimal:2',
            'monto_bruto' => 'decimal:2',
            'retencion_4ta' => 'decimal:2',
            'monto_neto' => 'decimal:2',
            'detalle_origen' => 'array',
        ];
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(PagoSemanalLote::class, 'lote_id');
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    public function banco(): BelongsTo
    {
        return $this->belongsTo(Banco::class);
    }
}
