<?php

namespace App\Modules\PortalAgento\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PortalAgento\Application\ConsultaOfertas;
use App\Modules\PortalAgento\Http\Requests\BuscarOfertasRequest;
use App\Modules\PortalAgento\Http\Resources\OfertaResource;
use App\Modules\PortalAgento\Infrastructure\CacheAgento;
use App\Modules\PortalAgento\Models\AreaAgento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /api/v1/jobs, /jobs/areas, /jobs/{slug}, /jobs/{slug}/similar (API pública de Agento).
 */
class OfertasController extends Controller
{
    public function __construct(private readonly ConsultaOfertas $ofertas) {}

    public function index(BuscarOfertasRequest $request): JsonResponse
    {
        $resultado = $this->ofertas->buscar($request->validated());

        return response()->json([
            'items' => OfertaResource::collection($resultado['items'])->resolve($request),
            'page' => $resultado['page'],
            'pageSize' => $resultado['pageSize'],
            'total' => $resultado['total'],
            // Corrección ortográfica de la búsqueda: no implementada (docs/agento/brechas-contrato.md).
            'correctedQuery' => null,
        ]);
    }

    public function areas(): JsonResponse
    {
        $datos = CacheAgento::recordar('areas', 300, function () {
            $conteos = $this->ofertas->visibles()->select('agento_publicaciones.area_id', DB::raw('count(*) as total'))
                ->groupBy('agento_publicaciones.area_id')->pluck('total', 'area_id');

            return AreaAgento::query()->orderBy('orden')->get()
                ->map(fn (AreaAgento $area) => ['area' => $area->etiqueta, 'count' => (int) ($conteos[$area->id] ?? 0)])
                ->all();
        });

        return response()->json($datos);
    }

    public function show(string $slug): JsonResponse
    {
        $oferta = $this->ofertas->visibles()->where('agento_publicaciones.slug', $slug)->with(ConsultaOfertas::RELACIONES)->first()
            ?? throw new NotFoundHttpException;

        return response()->json((new OfertaResource($oferta))->resolve());
    }

    public function similares(string $slug): JsonResponse
    {
        $base = $this->ofertas->visibles()->where('agento_publicaciones.slug', $slug)->with('ubigeo')->first()
            ?? throw new NotFoundHttpException;

        $departamento = $base->ubigeo?->departamento;

        $similares = $this->ofertas->visibles()
            ->where('agento_publicaciones.id', '!=', $base->id)
            ->where(fn (Builder $q) => $q->where('agento_publicaciones.area_id', $base->area_id)
                ->when($base->proceso?->cargo, fn (Builder $s, $cargo) => $s->orWhere('agento_publicaciones.texto_busqueda', 'like', '%'.\App\Modules\PortalAgento\Support\Texto::normalizar($cargo).'%')))
            ->with(ConsultaOfertas::RELACIONES)
            ->orderByDesc('agento_publicaciones.publicada_at')
            ->limit(30)
            ->get()
            // Misma zona primero (el contrato pide priorizar la zona), luego las más recientes.
            ->sortBy(fn ($o) => $o->ubigeo?->departamento === $departamento ? 0 : 1)
            ->take(4)
            ->values();

        return response()->json(OfertaResource::collection($similares)->resolve());
    }
}
