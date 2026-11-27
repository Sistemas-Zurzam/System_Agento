<?php

namespace App\Modules\Integraciones\Models;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Envío (p. ej. un mes) de clases dictadas que manda un sistema externo. */
#[ScopedBy([EmpresaScope::class])]
#[Fillable([
    'empresa_id', 'credencial_id', 'proveedor', 'referencia', 'periodo_inicio', 'periodo_fin', 'estado',
    'enviado_por', 'envios', 'total_clases', 'total_observadas', 'recibido_at',
    'total_bruto', 'total_retencion', 'total_neto', 'aprobado_por', 'aprobado_at', 'pagado_por', 'pagado_at',
    'referencia_pago', 'observacion',
])]
class LoteClasesExternas extends Model
{
    public const RECIBIDO = 'recibido';

    public const OBSERVADO = 'observado';

    public const APROBADO = 'aprobado';

    public const PAGADO = 'pagado';

    public const ANULADO = 'anulado';

    /** Estados en los que el proveedor todavía puede reemplazar el lote reenviándolo. */
    public const REEMPLAZABLE = [self::RECIBIDO, self::OBSERVADO, self::ANULADO];

    protected $table = 'clases_externas_lotes';

    protected function casts(): array
    {
        return [
            'periodo_inicio' => 'date',
            'periodo_fin' => 'date',
            'recibido_at' => 'datetime',
            'aprobado_at' => 'datetime',
            'pagado_at' => 'datetime',
            'total_bruto' => 'decimal:2',
            'total_retencion' => 'decimal:2',
            'total_neto' => 'decimal:2',
        ];
    }

    public function clases(): HasMany
    {
        return $this->hasMany(ClaseExterna::class, 'lote_id');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(PagoClasesExternas::class, 'lote_id');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
