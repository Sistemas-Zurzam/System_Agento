<?php

namespace App\Modules\Integraciones\Models;

use App\Modules\Configuracion\Models\Empresa;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Credencial (token) de un sistema externo para una empresa. Sin EmpresaScope a propósito: se consulta
 * ANTES de conocer la empresa, justamente para averiguarla a partir del token. Solo existe el hash.
 */
#[Fillable([
    'empresa_id', 'proveedor', 'nombre', 'token_hash', 'token_prefijo', 'activo', 'ultimo_uso_at', 'expira_at', 'creado_por',
])]
#[Hidden(['token_hash'])]
class CredencialIntegracion extends Model
{
    public const FIRME_STUDIO = 'firme_studio';

    protected $table = 'integracion_credenciales';

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'ultimo_uso_at' => 'datetime',
            'expira_at' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function estaVigente(): bool
    {
        return $this->activo && ($this->expira_at === null || $this->expira_at->isFuture());
    }
}
