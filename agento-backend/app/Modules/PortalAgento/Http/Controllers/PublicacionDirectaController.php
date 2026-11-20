<?php

namespace App\Modules\PortalAgento\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PortalAgento\Application\PublicacionDirecta;
use App\Modules\PortalAgento\Http\Requests\PerfilEmpresaRequest;
use App\Modules\PortalAgento\Http\Requests\PublicacionDirectaRequest;
use App\Modules\PortalAgento\Models\EmpresaPublica;
use App\Modules\Reclutamiento\Application\AlcanceSeleccion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pantalla «Publicar en Agento» del panel: perfil público de la empresa activa + alta directa de un puesto.
 * La empresa sale del usuario autenticado; nunca del request.
 */
class PublicacionDirectaController extends Controller
{
    public function __construct(private readonly PublicacionDirecta $directa) {}

    public function store(PublicacionDirectaRequest $request): JsonResponse
    {
        $proceso = $this->directa->crear($this->alcance($request), $request->validated());

        return response()->json(['data' => [
            'proceso_id' => $proceso->id,
            'codigo' => $proceso->codigo,
            'estado_proceso' => $proceso->estado,
        ]], 201);
    }

    public function puestos(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->directa->puestos($this->alcance($request))]);
    }

    public function empresa(Request $request): JsonResponse
    {
        $alcance = $this->alcance($request);

        return $this->respuestaEmpresa($this->directa->perfil($alcance), $alcance);
    }

    public function guardarEmpresa(PerfilEmpresaRequest $request): JsonResponse
    {
        $alcance = $this->alcance($request);

        return $this->respuestaEmpresa($this->directa->guardarPerfil($alcance, $request->validated()), $alcance);
    }

    private function respuestaEmpresa(EmpresaPublica $perfil, AlcanceSeleccion $alcance): JsonResponse
    {
        return response()->json(['data' => [
            'nombre' => $perfil->empresa()->value('nombre_comercial'),
            'slug' => $perfil->slug,
            'sector' => $perfil->sector,
            'tamano' => $perfil->tamano,
            'descripcion' => $perfil->descripcion,
            'sitio_web' => $perfil->sitio_web,
        ]]);
    }

    private function alcance(Request $request): AlcanceSeleccion
    {
        return AlcanceSeleccion::de($request->user('api'));
    }
}
