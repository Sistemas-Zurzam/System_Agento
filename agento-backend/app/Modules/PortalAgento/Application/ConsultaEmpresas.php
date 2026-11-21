<?php

namespace App\Modules\PortalAgento\Application;

use App\Modules\PortalAgento\Models\EmpresaPublica;
use App\Modules\PortalAgento\Models\Publicacion;
use App\Modules\PortalAgento\Support\Texto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Empresas visibles en Agento = las que tienen al menos una oferta visible NO confidencial.
 * Una empresa confidencial nunca aparece, ni siquiera por sus otras ofertas confidenciales.
 */
class ConsultaEmpresas
{
    public function __construct(private readonly ConsultaOfertas $ofertas) {}

    /**
     * Ofertas visibles y NO confidenciales (base de todo lo que identifica a una empresa).
     *
     * @return Builder<Publicacion>
     */
    public function ofertasIdentificables(): Builder
    {
        return $this->ofertas->visibles()->whereHas('proceso', fn (Builder $q) => $q->where('empresa_confidencial', false));
    }

    /**
     * @return Collection<int, array{perfil: EmpresaPublica, abiertas: int, ubicacion: string}>
     */
    public function todas(): Collection
    {
        $ofertas = $this->ofertasIdentificables()->orderByDesc('publicada_at')->get(['id', 'empresa_id', 'ubicacion_texto']);
        $porEmpresa = $ofertas->groupBy('empresa_id');

        return EmpresaPublica::query()->with('empresa')->whereIn('empresa_id', $porEmpresa->keys())->get()
            ->map(fn (EmpresaPublica $perfil) => [
                'perfil' => $perfil,
                'abiertas' => $porEmpresa[$perfil->empresa_id]->count(),
                'ubicacion' => (string) ($porEmpresa[$perfil->empresa_id]->first()->ubicacion_texto ?? ''),
            ])
            ->sortByDesc('abiertas')->values();
    }

    /**
     * @param  array<string, mixed>  $f  verified, q, limit, sector
     * @return array{items: list<array<string, mixed>>, sectors: list<string>}
     */
    public function directorio(array $f): array
    {
        $todas = $this->todas();
        $sectores = $todas->map(fn (array $e) => (string) $e['perfil']->sector)->filter()->unique()->sort()->values()->all();

        $lista = $todas
            ->when(! empty($f['verified']) && filter_var($f['verified'], FILTER_VALIDATE_BOOLEAN), fn (Collection $c) => $c->take(0))
            ->when(filled($f['sector'] ?? null), fn (Collection $c) => $c->filter(fn (array $e) => $e['perfil']->sector === $f['sector']))
            ->when(filled($f['q'] ?? null), function (Collection $c) use ($f) {
                $buscado = Texto::normalizar($f['q']);

                return $c->filter(fn (array $e) => str_contains(Texto::normalizar($e['perfil']->empresa?->nombre_comercial), $buscado));
            })
            ->when(isset($f['limit']), fn (Collection $c) => $c->take(max(1, min(50, (int) $f['limit']))));

        return [
            'items' => $lista->map(fn (array $e) => [
                ...$this->resumen($e['perfil']),
                'openJobs' => $e['abiertas'],
                'location' => $e['ubicacion'],
            ])->values()->all(),
            'sectors' => $sectores,
        ];
    }

    /**
     * @return array{perfil: EmpresaPublica, abiertas: int, ubicacion: string}|null
     */
    public function porSlug(string $slug): ?array
    {
        return $this->todas()->first(fn (array $e) => $e['perfil']->slug === $slug);
    }

    /**
     * CompanySummary. verified/rating/reviewsCount/respondsFast: sin dato en modo agencia (ver brechas).
     *
     * @return array<string, mixed>
     */
    public function resumen(EmpresaPublica $perfil): array
    {
        return [
            'id' => $perfil->slug,
            'slug' => $perfil->slug,
            'name' => (string) $perfil->empresa?->nombre_comercial,
            'verified' => false,
            'rating' => 0,
            'reviewsCount' => 0,
            'respondsFast' => false,
            'sector' => (string) ($perfil->sector ?? ''),
        ];
    }
}
