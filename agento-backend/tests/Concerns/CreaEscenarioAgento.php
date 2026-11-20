<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Modules\Configuracion\Models\Area;
use App\Modules\PortalAgento\Models\AreaAgento;
use App\Modules\PortalAgento\Models\BeneficioAgento;
use App\Modules\PortalAgento\Models\JornadaAgento;
use App\Modules\PortalAgento\Models\NivelAgento;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use Illuminate\Testing\TestResponse;

/**
 * Escenarios de publicación en Agento: un proceso de selección listo para publicar en el portal.
 * Se usa junto con CreaEscenarioSeleccion.
 */
trait CreaEscenarioAgento
{
    /**
     * Datos mínimos válidos de la pestaña «Publicación en Agento».
     *
     * @return array<string, mixed>
     */
    protected function datosAgento(array $extra = []): array
    {
        return [
            'resumen' => 'Operario de almacén para recepción y despacho de mercadería en Lima.',
            'area_id' => AreaAgento::query()->where('etiqueta', 'Logística y almacén')->value('id'),
            'nivel_id' => NivelAgento::query()->where('clave', 'operativo')->value('id'),
            'jornada_id' => JornadaAgento::query()->where('clave', 'completa')->value('id'),
            'ubigeo_codigo' => '150122',
            'sin_experiencia' => true,
            'beneficios' => [BeneficioAgento::query()->where('etiqueta', 'Seguro de salud')->value('id')],
            ...$extra,
        ];
    }

    /**
     * Proceso abierto (publicación externa del flujo actual) en la empresa del usuario, con salario visible.
     */
    protected function procesoParaAgento(User $rys, Area $area, array $atributosProceso = []): ProcesoSeleccion
    {
        $proceso = $this->procesoPublicado($rys, $area);
        if ($atributosProceso !== []) {
            $proceso->update($atributosProceso);
        }

        return $proceso->fresh();
    }

    protected function guardarAgento(User $usuario, ProcesoSeleccion $proceso, array $extra = []): TestResponse
    {
        return $this->putJson("/api/seleccion/procesos/{$proceso->id}/agento", $this->datosAgento($extra), $this->cabeceras($usuario));
    }

    protected function accionAgento(User $usuario, ProcesoSeleccion $proceso, string $accion, array $datos = []): TestResponse
    {
        return $this->postJson("/api/seleccion/procesos/{$proceso->id}/agento/acciones/{$accion}", $datos, $this->cabeceras($usuario));
    }

    /**
     * Guarda y publica en Agento; devuelve el slug de la publicación.
     */
    protected function publicarEnAgento(User $usuario, ProcesoSeleccion $proceso, array $extra = []): string
    {
        $slug = $this->guardarAgento($usuario, $proceso, $extra)->assertOk()->json('data.publicacion.slug');
        $this->accionAgento($usuario, $proceso, 'publicar')->assertOk()->assertJsonPath('data.publicacion.estado_publicacion', 'publicada');

        return $slug;
    }

    /**
     * GET público a /api/v1 (sin credenciales aunque antes se hayan usado en el test).
     */
    protected function v1(string $ruta, array $cabeceras = []): TestResponse
    {
        $this->olvidarSesionDePersonal();
        unset($this->defaultHeaders['Authorization']);

        return $this->getJson('/api/v1'.$ruta, $cabeceras);
    }
}
