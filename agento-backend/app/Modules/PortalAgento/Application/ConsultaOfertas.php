<?php

namespace App\Modules\PortalAgento\Application;

use App\Modules\PortalAgento\Domain\VisibilidadPublica;
use App\Modules\PortalAgento\Models\AreaAgento;
use App\Modules\PortalAgento\Models\Publicacion;
use App\Modules\PortalAgento\Models\Ubigeo;
use App\Modules\PortalAgento\Support\Texto;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Consultas públicas de ofertas (GET /jobs y derivados). Parte SIEMPRE de VisibilidadPublica.
 * Se ejecuta en contexto de sistema (ruta pública): la empresa sale de cada publicación.
 */
class ConsultaOfertas
{
    public const RELACIONES = [
        'proceso.perfilVigente.requisitos', 'proceso.solicitud', 'empresa:id,nombre_comercial', 'empresaPublica',
        'area', 'nivel', 'jornada', 'ubigeo', 'beneficios',
    ];

    private const PALABRAS_VACIAS = ['de', 'del', 'la', 'las', 'los', 'el', 'en', 'y', 'o', 'para', 'con', 'por', 'una', 'un', 'que', 'al'];

    /**
     * @return Builder<Publicacion>
     */
    public function visibles(): Builder
    {
        return VisibilidadPublica::aplicar(Publicacion::query());
    }

    /**
     * @param  array<string, mixed>  $f  filtros ya validados (ver BuscarOfertasRequest)
     * @return array{items: Collection<int, Publicacion>, page: int, pageSize: int, total: int}
     */
    public function buscar(array $f): array
    {
        $consulta = $this->visibles();
        $this->filtrar($consulta, $f);

        $tam = max(1, min(50, (int) ($f['tam'] ?? 10)));
        $total = (clone $consulta)->count();
        $ultima = max(1, (int) ceil($total / $tam));
        // Si piden una página que no existe se devuelve la última (y page indica cuál).
        $pagina = min(max(1, (int) ($f['pag'] ?? 1)), $ultima);

        $this->ordenar($consulta, $f);

        $items = $consulta->with(self::RELACIONES)->forPage($pagina, $tam)->get();

        return ['items' => $items, 'page' => $pagina, 'pageSize' => $tam, 'total' => $total];
    }

    /**
     * @param  Builder<Publicacion>  $consulta
     * @param  array<string, mixed>  $f
     */
    public function filtrar(Builder $consulta, array $f): void
    {
        if (! empty($f['verificadas'])) {
            // Ninguna empresa tiene hoy el estado «verificada» (docs/agento/brechas-contrato.md).
            $consulta->whereRaw('1 = 0');
        }

        $this->buscarTexto($consulta, (string) ($f['q'] ?? ''));

        if (filled($f['lugar'] ?? null)) {
            $lugar = Texto::normalizar($f['lugar']);
            $consulta->where(fn (Builder $q) => $q
                ->whereIn('agento_publicaciones.ubigeo_codigo', Ubigeo::query()->where('etiqueta_normalizada', 'like', "%{$lugar}%")->select('codigo'))
                ->orWhere('agento_publicaciones.texto_busqueda', 'like', "%{$lugar}%"));
        }

        if ($areas = $this->lista($f['area'] ?? null)) {
            $consulta->whereIn('agento_publicaciones.area_id', AreaAgento::query()->whereIn('etiqueta', $areas)->select('id'));
        }
        if ($niveles = $this->lista($f['nivel'] ?? null)) {
            $consulta->whereHas('nivel', fn (Builder $q) => $q->whereIn('clave', $niveles));
        }
        if ($jornadas = $this->lista($f['jornada'] ?? null)) {
            $consulta->whereHas('jornada', fn (Builder $q) => $q->whereIn('clave', $jornadas));
        }
        if ($modalidades = $this->lista($f['modalidad'] ?? null)) {
            $consulta->whereHas('proceso', fn (Builder $q) => $q->whereIn('modalidad', $modalidades));
        }

        $min = isset($f['min']) ? (int) $f['min'] : null;
        $max = isset($f['max']) ? (int) $f['max'] : null;
        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }
        if ($min !== null || $max !== null) {
            // Rango salarial mensual en soles: excluye las ofertas sin sueldo informado.
            $consulta->whereHas('proceso', fn (Builder $q) => $this->conSueldo($q)
                ->when($min !== null, fn (Builder $s) => $s->whereRaw('COALESCE(salario_max, salario_min) >= ?', [$min]))
                ->when($max !== null, fn (Builder $s) => $s->where('salario_min', '<=', $max)));
        }
        if (! empty($f['consueldo'])) {
            $consulta->whereHas('proceso', fn (Builder $q) => $this->conSueldo($q));
        }

        if (filled($f['fecha'] ?? null)) {
            $desde = match ($f['fecha']) {
                '24h' => now()->subDay(),
                '7d' => now()->subDays(7),
                '30d' => now()->subDays(30),
                default => null,
            };
            $desde && $consulta->where('agento_publicaciones.publicada_at', '>=', $desde);
        }
        if (! empty($f['sinexp'])) {
            $consulta->where('agento_publicaciones.sin_experiencia', true);
        }
        if (! empty($f['inclusiva'])) {
            $consulta->where('agento_publicaciones.inclusiva', true);
        }
    }

    /**
     * @param  Builder<Publicacion>  $consulta
     * @param  array<string, mixed>  $f
     */
    private function ordenar(Builder $consulta, array $f): void
    {
        $orden = $f['orden'] ?? 'relevance';
        $destacada = 'CASE WHEN agento_publicaciones.destacada = 1 AND (agento_publicaciones.destacada_hasta IS NULL OR agento_publicaciones.destacada_hasta > ?) THEN 1 ELSE 0 END';

        if ($orden === 'salary') {
            // Solo cuenta el sueldo que se muestra (visible y en soles); los demás van al final.
            $sueldo = "(SELECT CASE WHEN p.mostrar_salario = 1 AND p.moneda = 'PEN' THEN COALESCE(p.salario_max, p.salario_min) END FROM seleccion_procesos p WHERE p.id = agento_publicaciones.proceso_id)";
            $consulta->orderByRaw("{$sueldo} IS NULL")->orderByRaw("{$sueldo} DESC");
        } elseif ($orden === 'recent') {
            // sin destacadas: orden cronológico puro
        } else {
            $consulta->orderByRaw("{$destacada} DESC", [now()]);
            if ($this->usaFulltext((string) ($f['q'] ?? ''))) {
                $consulta->orderByRaw('MATCH(agento_publicaciones.texto_busqueda) AGAINST (? IN BOOLEAN MODE) DESC', [$this->booleano((string) $f['q'])]);
            }
        }

        $consulta->orderByDesc('agento_publicaciones.publicada_at')->orderByDesc('agento_publicaciones.id');
    }

    /**
     * Palabras sin tildes; todas deben aparecer. MySQL usa FULLTEXT; SQLite (tests) y las palabras
     * muy cortas usan LIKE.
     *
     * @param  Builder<Publicacion>  $consulta
     */
    private function buscarTexto(Builder $consulta, string $q): void
    {
        $palabras = Texto::palabras($q);
        if ($palabras === []) {
            return;
        }

        if ($this->usaFulltext($q)) {
            $consulta->whereRaw('MATCH(agento_publicaciones.texto_busqueda) AGAINST (? IN BOOLEAN MODE)', [$this->booleano($q)]);

            return;
        }

        foreach ($palabras as $palabra) {
            $consulta->where('agento_publicaciones.texto_busqueda', 'like', "%{$palabra}%");
        }
    }

    private function usaFulltext(string $q): bool
    {
        return DB::connection()->getDriverName() === 'mysql' && $this->palabrasLargas($q) !== [];
    }

    /**
     * @return list<string>
     */
    private function palabrasLargas(string $q): array
    {
        return array_values(array_filter(
            Texto::palabras($q),
            fn (string $p) => mb_strlen($p) >= 3 && ! in_array($p, self::PALABRAS_VACIAS, true),
        ));
    }

    private function booleano(string $q): string
    {
        // Solo letras y números (Texto::normalizar): no se cuela ningún operador del modo booleano.
        return implode(' ', array_map(fn (string $p) => "+{$p}*", $this->palabrasLargas($q)));
    }

    /**
     * @param  Builder<ProcesoSeleccion>  $q
     * @return Builder<ProcesoSeleccion>
     */
    private function conSueldo(Builder $q): Builder
    {
        return $q->where('mostrar_salario', true)->where('moneda', 'PEN')->whereNotNull('salario_min');
    }

    /**
     * @return list<string>
     */
    private function lista(mixed $valor): array
    {
        return array_values(array_filter(array_map('strval', (array) $valor), fn (string $v) => $v !== ''));
    }
}
