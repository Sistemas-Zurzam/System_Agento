<?php

namespace App\Modules\Reclutamiento\Models;

use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Archivo inmutable en almacenamiento privado. Un CV nuevo crea otra fila;
 * la postulación conserva la referencia al CV con el que se postuló.
 */
#[ScopedBy([EmpresaScope::class])]
#[Hidden(['ruta', 'disco', 'sha256'])]
#[Fillable(['empresa_id', 'candidato_id', 'tipo', 'disco', 'ruta', 'nombre_original', 'mime', 'tamano', 'sha256', 'eliminado_at', 'estado_escaneo', 'resultado_escaneo', 'escaneado_at'])]
class DocumentoCandidato extends Model
{
    public const PENDIENTE_ESCANEO = 'pendiente_escaneo';

    public const LIMPIO = 'limpio';

    public const INFECTADO = 'infectado';

    /** El antivirus no respondió tras varios intentos: sigue bloqueado y se reintenta. */
    public const ERROR_ESCANEO = 'error_escaneo';

    /** Subido antes de existir el antivirus: se mantiene descargable hasta escanearlo (--legado). */
    public const LEGADO = 'legado';

    /** Estados que descargan. El resto (pendiente, infectado, error) NO se descarga. */
    public const DESCARGABLES = [self::LIMPIO, self::LEGADO];

    /** Estados que el job debe escanear. */
    public const POR_ESCANEAR = [self::PENDIENTE_ESCANEO, self::ERROR_ESCANEO, self::LEGADO];

    protected $table = 'seleccion_documentos';

    /** Los registros NUEVOS nacen pendientes de escaneo (el DEFAULT de la columna es 'legado', solo para los ya existentes). */
    protected $attributes = ['estado_escaneo' => self::PENDIENTE_ESCANEO];

    public function descargable(): bool
    {
        return in_array($this->estado_escaneo, self::DESCARGABLES, true);
    }

    protected function casts(): array
    {
        return ['eliminado_at' => 'datetime', 'tamano' => 'integer'];
    }

    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidato::class);
    }
}
