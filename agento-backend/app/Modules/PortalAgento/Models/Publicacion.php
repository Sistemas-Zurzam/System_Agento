<?php

namespace App\Modules\PortalAgento\Models;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use App\Modules\PortalAgento\Infrastructure\CacheAgento;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Publicación de un proceso de selección en Agento (1:1 con seleccion_procesos).
 * La visibilidad pública NO se decide aquí: vive en Domain\VisibilidadPublica.
 */
#[ScopedBy([EmpresaScope::class])]
#[Fillable([
    'empresa_id', 'proceso_id', 'slug', 'titulo_publico', 'resumen', 'area_id', 'nivel_id', 'jornada_id',
    'ubigeo_codigo', 'ubicacion_texto', 'sin_experiencia', 'inclusiva', 'destacada', 'destacada_hasta',
    'estado_publicacion', 'pausada_por', 'programada_para', 'publicada_at', 'vigente_hasta', 'retirada_at',
    'motivo_retiro', 'version', 'texto_busqueda', 'creada_por', 'actualizada_por',
])]
class Publicacion extends Model
{
    public const BORRADOR = 'borrador';

    public const PROGRAMADA = 'programada';

    public const PUBLICADA = 'publicada';

    public const PAUSADA = 'pausada';

    public const RETIRADA = 'retirada';

    public const VENCIDA = 'vencida';

    public const ESTADOS = [self::BORRADOR, self::PROGRAMADA, self::PUBLICADA, self::PAUSADA, self::RETIRADA, self::VENCIDA];

    protected $table = 'agento_publicaciones';

    protected static function booted(): void
    {
        // Cualquier cambio invalida las lecturas públicas en caché (listados, áreas, empresas, sitemap).
        static::saved(fn () => CacheAgento::invalidar());
        static::deleted(fn () => CacheAgento::invalidar());
    }

    protected function casts(): array
    {
        return [
            'sin_experiencia' => 'boolean',
            'inclusiva' => 'boolean',
            'destacada' => 'boolean',
            'destacada_hasta' => 'datetime',
            'programada_para' => 'datetime',
            'publicada_at' => 'datetime',
            'vigente_hasta' => 'datetime',
            'retirada_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function proceso(): BelongsTo
    {
        return $this->belongsTo(ProcesoSeleccion::class, 'proceso_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(AreaAgento::class, 'area_id');
    }

    public function nivel(): BelongsTo
    {
        return $this->belongsTo(NivelAgento::class, 'nivel_id');
    }

    public function jornada(): BelongsTo
    {
        return $this->belongsTo(JornadaAgento::class, 'jornada_id');
    }

    public function ubigeo(): BelongsTo
    {
        return $this->belongsTo(Ubigeo::class, 'ubigeo_codigo', 'codigo');
    }

    public function beneficios(): BelongsToMany
    {
        return $this->belongsToMany(BeneficioAgento::class, 'agento_publicacion_beneficio', 'publicacion_id', 'beneficio_id')->orderBy('orden');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(PublicacionEvento::class, 'publicacion_id')->orderByDesc('id');
    }

    public function creadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creada_por');
    }

    public function empresaPublica(): BelongsTo
    {
        return $this->belongsTo(EmpresaPublica::class, 'empresa_id', 'empresa_id');
    }
}
