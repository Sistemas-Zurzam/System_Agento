<?php

namespace App\Modules\PortalAgento\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PortalAgento\Application\ConsultaEmpresas;
use App\Modules\PortalAgento\Application\ConsultaOfertas;
use App\Modules\PortalAgento\Http\Resources\OfertaResource;
use App\Modules\PortalAgento\Infrastructure\CacheAgento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /api/v1/companies, /companies/{slug}, /companies/{slug}/jobs. Solo empresas con ofertas visibles
 * que NO son confidenciales.
 */
class EmpresasController extends Controller
{
    public function __construct(private readonly ConsultaEmpresas $empresas, private readonly ConsultaOfertas $ofertas) {}

    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'verified' => ['nullable', 'in:true,false,1,0'],
            'q' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'sector' => ['nullable', 'string', 'max:80'],
        ]);

        $clave = 'empresas:'.md5(json_encode($datos));

        return response()->json(CacheAgento::recordar($clave, 300, fn () => $this->empresas->directorio($datos)));
    }

    public function show(string $slug): JsonResponse
    {
        $entrada = $this->empresas->porSlug($slug) ?? throw new NotFoundHttpException;
        $perfil = $entrada['perfil'];
        $empresa = $perfil->empresa;

        return response()->json([
            ...$this->empresas->resumen($perfil),
            'ruc' => (string) ($empresa?->ruc ?? ''),
            'size' => $perfil->tamano ?: config('portal_agento.tamano_por_defecto'),
            // En modo agencia las postulaciones las gestiona Agento: el contacto es el del portal.
            'email' => config('portal_agento.contacto_email'),
            'phone' => '',
            'website' => (string) ($perfil->sitio_web ?? ''),
            'logoUrl' => $empresa?->logo_path ? Storage::disk('public')->url($empresa->logo_path) : null,
            'coverUrl' => null,
            'description' => (string) ($perfil->descripcion ?? ''),
            'culture' => '',
            'benefits' => [],
            'sedes' => [],
            'gallery' => [],
        ]);
    }

    public function ofertas(string $slug): JsonResponse
    {
        $entrada = $this->empresas->porSlug($slug) ?? throw new NotFoundHttpException;

        $ofertas = $this->empresas->ofertasIdentificables()
            ->where('agento_publicaciones.empresa_id', $entrada['perfil']->empresa_id)
            ->with(ConsultaOfertas::RELACIONES)
            ->orderByDesc('agento_publicaciones.publicada_at')
            ->get();

        return response()->json(OfertaResource::collection($ofertas)->resolve());
    }
}
