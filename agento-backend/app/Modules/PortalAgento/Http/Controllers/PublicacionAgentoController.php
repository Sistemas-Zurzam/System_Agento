<?php

namespace App\Modules\PortalAgento\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PortalAgento\Application\PublicacionService;
use App\Modules\PortalAgento\Http\Requests\AccionPublicacionRequest;
use App\Modules\PortalAgento\Http\Requests\DestacarPublicacionRequest;
use App\Modules\PortalAgento\Http\Requests\GuardarPublicacionRequest;
use App\Modules\PortalAgento\Http\Resources\OfertaResource;
use App\Modules\PortalAgento\Http\Resources\PublicacionAgentoResource;
use App\Modules\PortalAgento\Models\AreaAgento;
use App\Modules\PortalAgento\Models\BeneficioAgento;
use App\Modules\PortalAgento\Models\JornadaAgento;
use App\Modules\PortalAgento\Models\NivelAgento;
use App\Modules\PortalAgento\Models\Publicacion;
use App\Modules\PortalAgento\Models\Ubigeo;
use App\Modules\PortalAgento\Support\Texto;
use App\Modules\Reclutamiento\Application\AlcanceSeleccion;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pestaña «Publicación en Agento» del panel de Reclutamiento (JWT + servicio de reclutamiento).
 * La empresa sale del usuario autenticado; el proceso se resuelve con EmpresaScope (otra empresa → 404).
 */
class PublicacionAgentoController extends Controller
{
    public function __construct(private readonly PublicacionService $publicaciones) {}

    public function show(Request $request, ProcesoSeleccion $proceso): JsonResponse
    {
        $vista = $this->publicaciones->ver($this->alcance($request), $proceso);

        return $this->respuesta($vista['publicacion'], $vista['checklist'], $vista['acciones'], $proceso);
    }

    public function update(GuardarPublicacionRequest $request, ProcesoSeleccion $proceso): JsonResponse
    {
        $this->publicaciones->guardar($this->alcance($request), $proceso, $request->validated());

        return $this->show($request, $proceso);
    }

    public function accion(AccionPublicacionRequest $request, ProcesoSeleccion $proceso, string $accion): JsonResponse
    {
        $this->publicaciones->ejecutar($this->alcance($request), $proceso, $accion, $request->validated());

        return $this->show($request, $proceso->refresh());
    }

    public function destacar(DestacarPublicacionRequest $request, ProcesoSeleccion $proceso): JsonResponse
    {
        $this->publicaciones->destacar($this->alcance($request), $proceso, $request->boolean('destacar'), $request->validated('hasta'));

        return $this->show($request, $proceso->refresh());
    }

    public function historial(Request $request, ProcesoSeleccion $proceso): JsonResponse
    {
        $eventos = $this->publicaciones->historial($this->alcance($request), $proceso);

        return response()->json(['data' => $eventos->map(fn ($e) => [
            'id' => $e->id,
            'evento' => $e->evento,
            'estado_desde' => $e->estado_desde,
            'estado_hasta' => $e->estado_hasta,
            'usuario' => $e->usuario?->name,
            'detalle' => $e->detalle,
            'fecha' => $e->created_at?->toIso8601String(),
        ])->values()->all()]);
    }

    /**
     * La oferta tal como la vería el público (mismo Resource que /api/v1/jobs/{slug}), aunque no esté publicada.
     */
    public function vistaPrevia(Request $request, ProcesoSeleccion $proceso): JsonResponse
    {
        $publicacion = $this->publicaciones->paraVistaPrevia($this->alcance($request), $proceso)
            ->load(\App\Modules\PortalAgento\Application\ConsultaOfertas::RELACIONES);

        return response()->json(['data' => (new OfertaResource($publicacion))->resolve()]);
    }

    /**
     * Catálogos para el formulario del panel (con los ids que acepta el guardado).
     */
    public function catalogos(): JsonResponse
    {
        return response()->json(['data' => [
            'areas' => AreaAgento::query()->orderBy('orden')->get(['id', 'etiqueta']),
            'niveles' => NivelAgento::query()->orderBy('orden')->get(['id', 'clave', 'etiqueta']),
            'jornadas' => JornadaAgento::query()->orderBy('orden')->get(['id', 'clave', 'etiqueta']),
            'beneficios' => BeneficioAgento::query()->orderBy('orden')->get(['id', 'etiqueta']),
        ]]);
    }

    /**
     * Estado en Agento de varios procesos a la vez (columna de la lista de procesos). Solo de la empresa
     * del usuario: EmpresaScope descarta los ids ajenos.
     */
    public function estados(Request $request): JsonResponse
    {
        $ids = collect(explode(',', (string) $request->query('ids', '')))
            ->map(fn ($id) => (int) $id)->filter()->unique()->take(100)->values();

        return response()->json(['data' => Publicacion::query()->whereIn('proceso_id', $ids)
            ->pluck('estado_publicacion', 'proceso_id')]);
    }

    public function ubigeo(Request $request): JsonResponse
    {
        $q = Texto::normalizar((string) $request->query('q', ''));

        return response()->json(['data' => Ubigeo::query()
            ->when($q !== '', fn ($c) => $c->where('etiqueta_normalizada', 'like', "%{$q}%"))
            ->orderBy('etiqueta')->limit(15)->get(['codigo', 'etiqueta'])]);
    }

    private function alcance(Request $request): AlcanceSeleccion
    {
        return AlcanceSeleccion::de($request->user('api'));
    }

    /**
     * @param  array<string, mixed>  $checklist
     * @param  list<string>  $acciones
     */
    private function respuesta(?\App\Modules\PortalAgento\Models\Publicacion $publicacion, array $checklist, array $acciones, ProcesoSeleccion $proceso): JsonResponse
    {
        return response()->json(['data' => [
            'publicacion' => $publicacion ? (new PublicacionAgentoResource($publicacion))->resolve() : null,
            'checklist' => $checklist,
            'acciones' => $acciones,
            'proceso' => ['id' => $proceso->id, 'codigo' => $proceso->codigo, 'estado' => $proceso->estado, 'cargo' => $proceso->cargo],
        ]]);
    }
}
