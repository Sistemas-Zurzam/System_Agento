<?php

namespace App\Modules\PortalAgento\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PortalAgento\Application\ConsultaEmpresas;
use App\Modules\PortalAgento\Application\ConsultaOfertas;
use App\Modules\PortalAgento\Http\Resources\OfertaResource;
use App\Modules\PortalAgento\Infrastructure\CacheAgento;
use App\Modules\PortalAgento\Models\Publicacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Datos de SEO (no HTML): sitemap, JSON-LD JobPosting y páginas /trabajo/:cargoLugar.
 * Una oferta de empresa confidencial se publica SIN identificar a la empresa en ningún dato.
 */
class SeoController extends Controller
{
    public function __construct(
        private readonly ConsultaOfertas $ofertas,
        private readonly ConsultaEmpresas $empresas,
    ) {}

    /**
     * Slugs y fecha de última modificación de lo indexable.
     */
    public function sitemap(): JsonResponse
    {
        return response()->json(CacheAgento::recordar('sitemap', 600, function () {
            $jobs = $this->ofertas->visibles()->orderByDesc('agento_publicaciones.updated_at')
                ->get(['agento_publicaciones.slug', 'agento_publicaciones.updated_at'])
                ->map(fn (Publicacion $p) => ['slug' => $p->slug, 'lastmod' => $p->updated_at?->toIso8601String()])->all();

            $companies = $this->empresas->todas()
                ->map(fn (array $e) => ['slug' => $e['perfil']->slug, 'lastmod' => $e['perfil']->updated_at?->toIso8601String()])->all();

            $landings = $this->landings()->map(fn (array $l) => ['slug' => $l['slug']])->values()->all();

            return ['jobs' => $jobs, 'companies' => $companies, 'landings' => $landings];
        }));
    }

    /**
     * Datos de schema.org/JobPosting de una oferta visible. Empresa confidencial: sin nombre ni logo ni enlaces.
     */
    public function jsonLd(string $slug): JsonResponse
    {
        $oferta = $this->ofertas->visibles()->where('agento_publicaciones.slug', $slug)->with(ConsultaOfertas::RELACIONES)->first()
            ?? throw new NotFoundHttpException;

        $job = (new OfertaResource($oferta))->resolve();
        $confidencial = (bool) $oferta->proceso->empresa_confidencial;

        $organizacion = ['@type' => 'Organization', 'name' => $job['company']['name']];
        if (! $confidencial) {
            $logo = $oferta->empresa?->logo_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($oferta->empresa->logo_path) : null;
            $logo && $organizacion['logo'] = $logo;
        }

        return response()->json(array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'JobPosting',
            'title' => $job['title'],
            'description' => implode("\n", $job['description']) ?: $job['summary'],
            'datePosted' => $job['postedAt'],
            'validThrough' => $job['expiresAt'],
            'employmentType' => $this->tipoDeEmpleo((string) $job['schedule']),
            'hiringOrganization' => $organizacion,
            'jobLocation' => [
                '@type' => 'Place',
                'address' => [
                    '@type' => 'PostalAddress',
                    'addressLocality' => $job['location']['distrito'],
                    'addressRegion' => $job['location']['departamento'],
                    'addressCountry' => 'PE',
                ],
            ],
            'jobLocationType' => $job['mode'] === 'remoto' ? 'TELECOMMUTE' : null,
            'baseSalary' => $job['salary'] ? [
                '@type' => 'MonetaryAmount',
                'currency' => 'PEN',
                'value' => ['@type' => 'QuantitativeValue', 'minValue' => $job['salary']['min'], 'maxValue' => $job['salary']['max'], 'unitText' => 'MONTH'],
            ] : null,
        ], fn ($v) => $v !== null && $v !== ''));
    }

    public function landing(string $slug): JsonResponse
    {
        $landings = $this->landings();
        $actual = $landings->firstWhere('slug', $slug) ?? throw new NotFoundHttpException;

        $mismoLugar = $landings->where('lugar', $actual['lugar'])->where('cargo', '!=', $actual['cargo'])->take(5);
        $mismoCargo = $landings->where('cargo', $actual['cargo'])->where('lugar', '!=', $actual['lugar'])->take(5);
        $enlace = fn (array $l, string $campo) => ['label' => $l[$campo], 'href' => '/trabajo/'.$l['slug']];

        return response()->json([
            'cargo' => $actual['cargo'],
            'lugar' => $actual['lugar'],
            'intro' => 'Encuentra empleos de '.mb_strtolower($actual['cargo']).' en '.$actual['lugar'].'. Actualizamos esta lista todos los días con ofertas vigentes.',
            'relatedCargos' => $mismoLugar->map(fn (array $l) => $enlace($l, 'cargo'))->values()->all(),
            'relatedPlaces' => $mismoCargo->map(fn (array $l) => $enlace($l, 'lugar'))->values()->all(),
        ]);
    }

    /**
     * Combinaciones «cargo en lugar» que tienen al menos una oferta visible (distrito y departamento).
     *
     * @return Collection<int, array{slug: string, cargo: string, lugar: string}>
     */
    private function landings(): Collection
    {
        return collect(CacheAgento::recordar('landings', 600, function () {
            $combos = [];
            foreach ($this->ofertas->visibles()->with(['proceso:id,cargo', 'ubigeo'])->get() as $oferta) {
                $cargo = (string) ($oferta->titulo_publico ?: $oferta->proceso?->cargo);
                foreach (array_unique(array_filter([$oferta->ubigeo?->distrito, $oferta->ubigeo?->departamento])) as $lugar) {
                    $slug = Str::slug($cargo).'-en-'.Str::slug($lugar);
                    $combos[$slug] = ['slug' => $slug, 'cargo' => $cargo, 'lugar' => $lugar];
                }
            }

            return array_values($combos);
        }));
    }

    private function tipoDeEmpleo(string $jornada): string
    {
        return match ($jornada) {
            'medio_tiempo' => 'PART_TIME',
            'por_horas', 'por_campana', 'fines_de_semana' => 'TEMPORARY',
            default => 'FULL_TIME',
        };
    }
}
