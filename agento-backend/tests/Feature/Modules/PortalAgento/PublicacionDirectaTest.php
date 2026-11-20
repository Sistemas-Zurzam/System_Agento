<?php

namespace Tests\Feature\Modules\PortalAgento;

use App\Modules\PortalAgento\Models\Publicacion;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use App\Modules\Reclutamiento\Models\SolicitudPersonal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaEscenarioAgento;
use Tests\Concerns\CreaEscenarioSeleccion;
use Tests\TestCase;

/**
 * Pantalla «Publicar en Agento» del panel: alta directa de un puesto (solicitud + proceso + publicación en
 * una transacción) y perfil público de la empresa activa.
 */
class PublicacionDirectaTest extends TestCase
{
    use CreaEscenarioAgento, CreaEscenarioSeleccion, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeleccion();
    }

    /**
     * @return array<string, mixed>
     */
    private function datosPuesto(int $areaId, array $extra = []): array
    {
        return [
            'cargo' => 'Operario de almacén',
            'area_interna_id' => $areaId,
            'plazas' => 2,
            'salario_min' => 1200,
            'salario_max' => 1500,
            'tipo_contrato' => 'plazo_fijo',
            'modalidad' => 'presencial',
            'descripcion' => 'Recepción, almacenamiento y despacho de mercadería en el almacén central.',
            'actividades' => "Recibir mercadería\nDespachar pedidos",
            'requisitos' => 'Secundaria completa',
            'habilidades' => [['nombre' => 'Trabajo en equipo', 'nivel' => 3]],
            ...$this->datosAgento(),
            ...$extra,
        ];
    }

    public function test_crea_solicitud_proceso_y_publica_en_un_solo_paso(): void
    {
        $empresa = $this->crearEmpresa('Logística Andina');
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $area = $this->crearArea($empresa);

        $respuesta = $this->postJson('/api/seleccion/agento/puestos', $this->datosPuesto($area->id, ['publicar' => true]), $this->cabeceras($rys))
            ->assertCreated();

        $proceso = ProcesoSeleccion::findOrFail($respuesta->json('data.proceso_id'));
        $this->assertSame($empresa->id, $proceso->empresa_id);
        $this->assertSame(1, SolicitudPersonal::where('empresa_id', $empresa->id)->count());
        $this->assertSame('publicada', Publicacion::where('proceso_id', $proceso->id)->value('estado_publicacion'));

        $this->v1('/jobs')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_sin_publicar_queda_en_borrador_y_no_aparece_en_la_api_publica(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');

        $this->postJson('/api/seleccion/agento/puestos', $this->datosPuesto($this->crearArea($empresa)->id), $this->cabeceras($rys))
            ->assertCreated();

        $this->assertSame('borrador', Publicacion::firstOrFail()->estado_publicacion);
        $this->v1('/jobs')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_si_falla_la_publicacion_no_queda_una_solicitud_a_medias(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');

        // Resumen demasiado corto: el checklist impide publicar.
        $this->postJson('/api/seleccion/agento/puestos', $this->datosPuesto($this->crearArea($empresa)->id, ['resumen' => 'corto', 'publicar' => true]), $this->cabeceras($rys))
            ->assertStatus(422);

        $this->assertSame(0, SolicitudPersonal::count());
        $this->assertSame(0, ProcesoSeleccion::count());
        $this->assertSame(0, Publicacion::count());
    }

    public function test_el_area_de_otra_empresa_se_rechaza(): void
    {
        $rys = $this->usuarioCon($this->crearEmpresa('Propia'), 'talento_cultura');
        $ajena = $this->crearArea($this->crearEmpresa('Ajena'));

        $this->postJson('/api/seleccion/agento/puestos', $this->datosPuesto($ajena->id), $this->cabeceras($rys))
            ->assertStatus(422);

        $this->assertSame(0, SolicitudPersonal::count());
    }

    public function test_el_perfil_de_la_empresa_se_guarda_y_se_ve_en_la_api_publica(): void
    {
        $empresa = $this->crearEmpresa('Logística Andina');
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $area = $this->crearArea($empresa);

        $this->putJson('/api/seleccion/agento/empresa', [
            'sector' => 'Logística', 'tamano' => '51-200 empleados', 'descripcion' => 'Operador logístico.', 'sitio_web' => 'https://ejemplo.pe',
        ], $this->cabeceras($rys))->assertOk()->assertJsonPath('data.sector', 'Logística');

        $this->postJson('/api/seleccion/agento/puestos', $this->datosPuesto($area->id, ['publicar' => true]), $this->cabeceras($rys))->assertCreated();

        $slug = $this->getJson('/api/seleccion/agento/empresa', $this->cabeceras($rys))->assertOk()->json('data.slug');
        $this->v1("/companies/{$slug}")->assertOk()->assertJsonPath('sector', 'Logística');
    }

    public function test_sin_el_permiso_de_publicar_no_se_crea_el_puesto(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->usuarioCon($empresa, 'vigilancia');

        $this->postJson('/api/seleccion/agento/puestos', $this->datosPuesto($this->crearArea($empresa)->id), $this->cabeceras($usuario))
            ->assertForbidden();
    }
}
