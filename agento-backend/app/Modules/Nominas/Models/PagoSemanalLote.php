<?php

namespace App\Modules\Nominas\Models;

use App\Modules\Configuracion\Models\Empresa;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lote de pago semanal de motorizados recibido de plataforma_zazu. Aparte del
 * ciclo remunerativo mensual — ver la migración crear_pagos_semanales_zazu.
 */
#[Fillable([
    'empresa_id', 'sistema_origen', 'referencia', 'semana_inicio', 'semana_fin', 'estado',
    'total_bruto', 'total_retencion', 'total_neto', 'enviado_por', 'envios', 'recibido_at', 'observacion',
    'aprobado_por', 'aprobado_at', 'pagado_por', 'pagado_at', 'referencia_pago',
])]
class PagoSemanalLote extends Model
{
    public const RECIBIDO = 'recibido';

    public const OBSERVADO = 'observado';

    public const APROBADO = 'aprobado';

    public const PAGADO = 'pagado';

    public const ANULADO = 'anulado';

    /** Estados en los que Zazu todavía puede reemplazar el lote reenviándolo. */
    public const REEMPLAZABLE = [self::RECIBIDO, self::OBSERVADO, self::ANULADO];

    protected $table = 'pagos_semanales_lotes';

    protected function casts(): array
    {
        return [
            'semana_inicio' => 'date',
            'semana_fin' => 'date',
            'total_bruto' => 'decimal:2',
            'total_retencion' => 'decimal:2',
            'total_neto' => 'decimal:2',
            'recibido_at' => 'datetime',
            'aprobado_at' => 'datetime',
            'pagado_at' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(PagoSemanalLoteDetalle::class, 'lote_id');
    }

    /** Semana ISO, para nombrar el TXT y la referencia del abono (ej. "2026_S47"). */
    public function semanaIso(): string
    {
        return $this->semana_inicio->format('o').'_S'.$this->semana_inicio->format('W');
    }
}
