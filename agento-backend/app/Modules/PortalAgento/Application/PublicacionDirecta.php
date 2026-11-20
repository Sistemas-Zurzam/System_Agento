<?php

namespace App\Modules\PortalAgento\Application;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\PortalAgento\Infrastructure\CacheAgento;
use App\Modules\PortalAgento\Models\EmpresaPublica;
use App\Modules\PortalAgento\Models\Publicacion;
use App\Modules\Reclutamiento\Application\AlcanceSeleccion;
use App\Modules\Reclutamiento\Application\SolicitudService;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use Illuminate\Support\Facades\DB;

/**
 * «Publicar un puesto directamente»: un solo formulario crea la solicitud, la acepta (con su proceso y plazas),
 * guarda la publicación en Agento y, si se pide, la publica. Reutiliza los casos de uso existentes
 * (SolicitudService, PublicacionService): no duplica reglas del ATS ni de la publicación.
 *
 * Todo ocurre en UNA transacción: si falta algo para publicar, no queda una solicitud a medias.
 * La empresa sale del usuario autenticado (AlcanceSeleccion), nunca del request.
 */
class PublicacionDirecta
{
    public function __construct(
        private readonly SolicitudService $solicitudes,
        private readonly PublicacionService $publicaciones,
        private readonly PerfilPublicoEmpresa $perfiles,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(AlcanceSeleccion $alcance, array $datos): ProcesoSeleccion
    {
        $alcance->exigir('seleccion.solicitudes.crear');
        $alcance->exigir('seleccion.solicitudes.decidir');
        $alcance->exigir('seleccion.vacantes.gestionar');

        return DB::transaction(function () use ($alcance, $datos) {
            $solicitud = $this->solicitudes->crear($alcance, [
                'area_id' => $datos['area_interna_id'],
                'cargo' => $datos['cargo'],
                'motivo' => 'nuevo_puesto',
                'plazas' => $datos['plazas'],
                'salario_min' => $datos['salario_min'],
                'salario_max' => $datos['salario_max'],
                'moneda' => $datos['moneda'] ?? 'PEN',
                'tipo_contrato' => $datos['tipo_contrato'],
                'modalidad' => $datos['modalidad'],
                'fecha_requerida' => $datos['fecha_requerida'],
                'descripcion' => $datos['descripcion'],
                'actividades' => $datos['actividades'] ?? null,
                'requisitos' => $datos['requisitos'] ?? null,
                'urgente' => false,
                'habilidades' => $datos['habilidades'],
                'enviar' => true,
            ]);

            $solicitud = $this->solicitudes->decidir($alcance, $solicitud, 'aceptar', null, null);
            /** @var ProcesoSeleccion $proceso */
            $proceso = $solicitud->proceso()->firstOrFail();

            $this->publicaciones->guardar($alcance, $proceso, [
                'titulo_publico' => $datos['titulo_publico'] ?? null,
                'resumen' => $datos['resumen'] ?? null,
                'area_id' => $datos['area_id'] ?? null,
                'nivel_id' => $datos['nivel_id'] ?? null,
                'jornada_id' => $datos['jornada_id'] ?? null,
                'ubigeo_codigo' => $datos['ubigeo_codigo'] ?? null,
                'sin_experiencia' => (bool) ($datos['sin_experiencia'] ?? false),
                'inclusiva' => (bool) ($datos['inclusiva'] ?? false),
                'vigente_hasta' => $datos['vigente_hasta'] ?? null,
                'beneficios' => $datos['beneficios'] ?? [],
            ]);

            if (! empty($datos['publicar'])) {
                $this->publicaciones->ejecutar($alcance, $proceso->refresh(), 'publicar');
            }

            return $proceso->refresh();
        });
    }

    /**
     * Perfil público de la empresa activa (se crea con su slug la primera vez que se consulta o se guarda).
     */
    public function perfil(AlcanceSeleccion $alcance): EmpresaPublica
    {
        return $this->perfiles->asegurar(Empresa::findOrFail($alcance->empresaId));
    }

    /**
     * @param  array<string, mixed>  $datos  sector, tamano, descripcion, sitio_web
     */
    public function guardarPerfil(AlcanceSeleccion $alcance, array $datos): EmpresaPublica
    {
        $alcance->exigir('seleccion.vacantes.gestionar');
        $perfil = $this->perfil($alcance);
        $perfil->update([
            'sector' => $datos['sector'] ?? null,
            'tamano' => $datos['tamano'] ?? null,
            'descripcion' => $datos['descripcion'] ?? null,
            'sitio_web' => $datos['sitio_web'] ?? null,
        ]);

        // Los cambios del perfil se ven en /companies: se invalida la caché pública.
        CacheAgento::invalidar();

        return $perfil->refresh();
    }

    /**
     * Puestos publicados (o preparados) por el panel para esta empresa: lista de apoyo de la pantalla.
     *
     * @return list<array<string, mixed>>
     */
    public function puestos(AlcanceSeleccion $alcance): array
    {
        return Publicacion::query()
            ->where('empresa_id', $alcance->empresaId)
            ->with(['proceso:id,codigo,cargo,estado'])
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (Publicacion $p) => [
                'proceso_id' => $p->proceso_id,
                'codigo' => $p->proceso?->codigo,
                'cargo' => $p->titulo_publico ?: $p->proceso?->cargo,
                'estado_publicacion' => $p->estado_publicacion,
                'estado_proceso' => $p->proceso?->estado,
                'slug' => $p->slug,
            ])->all();
    }
}
