<?php

namespace Tests\Feature\Modules\PortalAgento;

use App\Modules\PortalAgento\Models\Publicacion;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaEscenarioAgento;
use Tests\Concerns\CreaEscenarioSeleccion;
use Tests\TestCase;

/**
 * Pestaña «Publicación en Agento» del panel: guardar, checklist, acciones, sincronización con el proceso.
 */
class PublicacionAgentoTest extends TestCase
{
    use CreaEscenarioAgento, CreaEscenarioSeleccion, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeleccion();
    }

    public function test_guardar_crea_la_publicacion_en_borrador_y_el_checklist_pide_lo_que_falta(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoParaAgento($rys, $this->crearArea($empresa));

        // Antes de guardar no hay publicación y el checklist bloquea.
        $this->getJson("/api/seleccion/procesos/{$proceso->id}/agento", $this->cabeceras($rys))
            ->assertOk()->assertJsonPath('data.publicacion', null)->assertJsonPath('data.checklist.listo', false);

        $this->guardarAgento($rys, $proceso)->assertOk()
            ->assertJsonPath('data.publicacion.estado_publicacion', 'borrador')
            ->assertJsonPath('data.publicacion.ubicacion_texto', 'Miraflores, Lima')
            ->assertJsonPath('data.checklist.listo', true);

        $this->assertSame(1, Publicacion::count());
    }

    public function test_no_se_publica_con_el_checklist_incompleto(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoParaAgento($rys, $this->crearArea($empresa));

        $this->guardarAgento($rys, $proceso, ['resumen' => 'corto', 'ubigeo_codigo' => null])->assertOk();

        $this->accionAgento($rys, $proceso, 'publicar')->assertStatus(422)->assertJsonValidationErrors('checklist');
        $this->assertSame('borrador', Publicacion::firstOrFail()->estado_publicacion);
    }

    public function test_publicar_expone_la_oferta_en_la_api_v1_y_registra_el_historial(): void
    {
        $empresa = $this->crearEmpresa('Logística Andina');
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoParaAgento($rys, $this->crearArea($empresa), ['mostrar_salario' => true]);

        $slug = $this->publicarEnAgento($rys, $proceso);

        $this->v1("/jobs/{$slug}")->assertOk()
            ->assertJsonPath('slug', $slug)
            ->assertJsonPath('title', 'Operario de almacén')
            ->assertJsonPath('company.name', $empresa->nombre_comercial)
            ->assertJsonPath('location.distrito', 'Miraflores')
            ->assertJsonPath('area', 'Logística y almacén')
            ->assertJsonPath('level', 'operativo')
            ->assertJsonPath('schedule', 'completa')
            ->assertJsonPath('mode', 'presencial')
            ->assertJsonPath('salary.min', 1200)
            ->assertJsonPath('salary.max', 1500)
            ->assertJsonPath('noExperience', true)
            ->assertJsonPath('benefits.0', 'Seguro de salud')
            ->assertJsonPath('requirements.mandatory.0', 'Secundaria completa')
            ->assertJsonPath('acceptsApplications', true)
            ->assertJsonPath('status', 'activa');

        $this->getJson("/api/seleccion/procesos/{$proceso->id}/agento/historial", $this->cabeceras($rys))
            ->assertOk()->assertJsonPath('data.0.evento', 'publicar')->assertJsonPath('data.0.estado_hasta', 'publicada');

        $this->assertTrue((bool) ProcesoSeleccion::find($proceso->id)->publicacion_externa);
    }

    public function test_pausar_y_reanudar_desde_agento_no_cambia_el_proceso(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoParaAgento($rys, $this->crearArea($empresa));
        $slug = $this->publicarEnAgento($rys, $proceso);

        $this->accionAgento($rys, $proceso, 'pausar')->assertOk()->assertJsonPath('data.publicacion.estado_publicacion', 'pausada');
        $this->v1("/jobs/{$slug}")->assertOk()->assertJsonPath('status', 'pausada')->assertJsonPath('acceptsApplications', false);
        $this->assertSame(ProcesoSeleccion::ABIERTO, $proceso->fresh()->estado);

        $this->accionAgento($rys, $proceso, 'reanudar')->assertOk()->assertJsonPath('data.publicacion.estado_publicacion', 'publicada');
        $this->v1("/jobs/{$slug}")->assertJsonPath('acceptsApplications', true);
    }

    public function test_pausar_el_proceso_pausa_la_publicacion_y_reanudarlo_la_reanuda(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoParaAgento($rys, $this->crearArea($empresa));
        $slug = $this->publicarEnAgento($rys, $proceso);

        $this->postJson("/api/seleccion/procesos/{$proceso->id}/estado", ['accion' => 'pausar', 'motivo' => 'Revisión de presupuesto'], $this->cabeceras($rys))->assertOk();

        $this->assertSame('pausada', Publicacion::firstOrFail()->estado_publicacion);
        $this->v1("/jobs/{$slug}")->assertOk()->assertJsonPath('status', 'pausada')->assertJsonPath('acceptsApplications', false)
            ->assertJsonPath('publicMessage', fn ($mensaje) => filled($mensaje));

        $this->postJson("/api/seleccion/procesos/{$proceso->id}/estado", ['accion' => 'reanudar'], $this->cabeceras($rys))->assertOk();
        $this->assertSame('publicada', Publicacion::firstOrFail()->estado_publicacion);
        $this->v1("/jobs/{$slug}")->assertJsonPath('status', 'activa');
    }

    public function test_una_pausa_manual_en_agento_no_la_levanta_el_proceso(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoParaAgento($rys, $this->crearArea($empresa));
        $this->publicarEnAgento($rys, $proceso);
        $this->accionAgento($rys, $proceso, 'pausar')->assertOk();

        $this->postJson("/api/seleccion/procesos/{$proceso->id}/estado", ['accion' => 'pausar', 'motivo' => 'x'], $this->cabeceras($rys))->assertOk();
        $this->postJson("/api/seleccion/procesos/{$proceso->id}/estado", ['accion' => 'reanudar'], $this->cabeceras($rys))->assertOk();

        $this->assertSame('pausada', Publicacion::firstOrFail()->estado_publicacion, 'La pausa manual la levanta una persona en Agento.');
    }

    public function test_cerrar_el_proceso_retira_la_publicacion_y_la_oferta_deja_de_existir(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoParaAgento($rys, $this->crearArea($empresa));
        $slug = $this->publicarEnAgento($rys, $proceso);

        $this->postJson("/api/seleccion/procesos/{$proceso->id}/estado", ['accion' => 'cerrar', 'motivo' => 'Plazas cubiertas por otro canal'], $this->cabeceras($rys))->assertOk();

        $publicacion = Publicacion::firstOrFail();
        $this->assertSame('retirada', $publicacion->estado_publicacion);
        $this->assertSame('proceso_cerrado', $publicacion->motivo_retiro);
        $this->v1("/jobs/{$slug}")->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->assertSame(0, $this->v1('/jobs')->json('total'));
    }

    public function test_retirar_desde_agento_exige_motivo_y_quita_la_publicacion_externa(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoParaAgento($rys, $this->crearArea($empresa));
        $slug = $this->publicarEnAgento($rys, $proceso);

        $this->accionAgento($rys, $proceso, 'retirar')->assertStatus(422)->assertJsonValidationErrors('motivo');
        $this->accionAgento($rys, $proceso, 'retirar', ['motivo' => 'Oferta duplicada'])->assertOk()
            ->assertJsonPath('data.publicacion.estado_publicacion', 'retirada')->assertJsonPath('data.publicacion.motivo_retiro', 'Oferta duplicada');

        $this->v1("/jobs/{$slug}")->assertNotFound();
        $this->assertFalse((bool) $proceso->fresh()->publicacion_externa);
    }

    public function test_renovar_una_publicacion_retirada_la_vuelve_a_publicar(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoParaAgento($rys, $this->crearArea($empresa));
        $slug = $this->publicarEnAgento($rys, $proceso);
        $this->accionAgento($rys, $proceso, 'retirar', ['motivo' => 'Pausa comercial'])->assertOk();

        $this->accionAgento($rys, $proceso, 'renovar', ['vigente_hasta' => now()->addDays(15)->toDateString()])->assertOk()
            ->assertJsonPath('data.publicacion.estado_publicacion', 'publicada');

        $this->v1("/jobs/{$slug}")->assertOk();
        $this->assertTrue((bool) $proceso->fresh()->publicacion_externa);
    }

    public function test_acciones_invalidas_segun_el_estado_responden_422(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoParaAgento($rys, $this->crearArea($empresa));
        $this->guardarAgento($rys, $proceso)->assertOk();

        $this->accionAgento($rys, $proceso, 'pausar')->assertStatus(422);
        $this->accionAgento($rys, $proceso, 'reanudar')->assertStatus(422);
        $this->accionAgento($rys, $proceso, 'retirar', ['motivo' => 'x'])->assertStatus(422);
        $this->accionAgento($rys, $proceso, 'inexistente')->assertForbidden();
    }

    public function test_programar_exige_fecha_futura_y_el_comando_la_publica_a_su_hora(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoParaAgento($rys, $this->crearArea($empresa));
        $slug = $this->guardarAgento($rys, $proceso)->assertOk()->json('data.publicacion.slug');

        $this->accionAgento($rys, $proceso, 'programar', ['programada_para' => now()->subHour()->toIso8601String()])->assertStatus(422);
        $this->accionAgento($rys, $proceso, 'programar', ['programada_para' => now()->addHours(2)->toIso8601String()])->assertOk()
            ->assertJsonPath('data.publicacion.estado_publicacion', 'programada');
        $this->v1("/jobs/{$slug}")->assertNotFound();

        $this->artisan('agento:sincronizar-publicaciones')->assertSuccessful();
        $this->assertSame('programada', Publicacion::firstOrFail()->estado_publicacion, 'Todavía no es la hora.');

        $this->travel(3)->hours();
        $this->artisan('agento:sincronizar-publicaciones')->assertSuccessful();

        $this->assertSame('publicada', Publicacion::firstOrFail()->estado_publicacion);
        $this->v1("/jobs/{$slug}")->assertOk();
    }

    public function test_el_comando_vence_las_publicaciones_cuya_vigencia_se_cumplio(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoParaAgento($rys, $this->crearArea($empresa));
        $slug = $this->publicarEnAgento($rys, $proceso, ['vigente_hasta' => now()->addDays(5)->toDateString()]);

        $this->travel(6)->days();
        // Aun antes del comando la oferta vencida no se muestra (la visibilidad mira la vigencia).
        $this->v1("/jobs/{$slug}")->assertNotFound();

        $this->artisan('agento:sincronizar-publicaciones')->assertSuccessful();
        $this->assertSame('vencida', Publicacion::firstOrFail()->estado_publicacion);
    }

    public function test_destacar_sube_la_oferta_en_el_orden_por_relevancia(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $area = $this->crearArea($empresa);
        $primera = $this->procesoParaAgento($rys, $area);
        $slugPrimera = $this->publicarEnAgento($rys, $primera);
        $this->travel(1)->minute();
        $segunda = $this->procesoParaAgento($rys, $area);
        $slugSegunda = $this->publicarEnAgento($rys, $segunda);

        $this->assertSame([$slugSegunda, $slugPrimera], collect($this->v1('/jobs')->json('items'))->pluck('slug')->all());

        $this->postJson("/api/seleccion/procesos/{$primera->id}/agento/destacar", ['destacar' => true], $this->cabeceras($rys))->assertOk()
            ->assertJsonPath('data.publicacion.destacada', true);

        $this->assertSame([$slugPrimera, $slugSegunda], collect($this->v1('/jobs')->json('items'))->pluck('slug')->all());
        $this->assertSame([$slugSegunda, $slugPrimera], collect($this->v1('/jobs?orden=recent')->json('items'))->pluck('slug')->all());
    }

    public function test_bloqueo_optimista_por_version(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoParaAgento($rys, $this->crearArea($empresa));
        $this->guardarAgento($rys, $proceso)->assertOk();
        $this->guardarAgento($rys, $proceso, ['version' => 1])->assertOk()->assertJsonPath('data.publicacion.version', 2);

        $this->guardarAgento($rys, $proceso, ['version' => 1])->assertStatus(409);
    }

    public function test_vista_previa_muestra_la_oferta_aunque_sea_borrador(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoParaAgento($rys, $this->crearArea($empresa));

        $this->getJson("/api/seleccion/procesos/{$proceso->id}/agento/vista-previa", $this->cabeceras($rys))->assertStatus(422);

        $this->guardarAgento($rys, $proceso)->assertOk();
        $this->getJson("/api/seleccion/procesos/{$proceso->id}/agento/vista-previa", $this->cabeceras($rys))
            ->assertOk()->assertJsonPath('data.title', 'Operario de almacén');
    }

    public function test_aislamiento_otra_empresa_no_ve_ni_modifica_la_publicacion(): void
    {
        $a = $this->crearEmpresa('A');
        $b = $this->crearEmpresa('B');
        $rysA = $this->usuarioCon($a, 'talento_cultura');
        $rysB = $this->usuarioCon($b, 'talento_cultura');
        $proceso = $this->procesoParaAgento($rysA, $this->crearArea($a));
        $this->publicarEnAgento($rysA, $proceso);

        $this->getJson("/api/seleccion/procesos/{$proceso->id}/agento", $this->cabeceras($rysB))->assertNotFound();
        $this->getJson("/api/seleccion/procesos/{$proceso->id}/agento/historial", $this->cabeceras($rysB))->assertNotFound();
        $this->putJson("/api/seleccion/procesos/{$proceso->id}/agento", $this->datosAgento(), $this->cabeceras($rysB))->assertNotFound();
        $this->postJson("/api/seleccion/procesos/{$proceso->id}/agento/acciones/retirar", ['motivo' => 'x'], $this->cabeceras($rysB))->assertNotFound();
        $this->postJson("/api/seleccion/procesos/{$proceso->id}/agento/destacar", ['destacar' => true], $this->cabeceras($rysB))->assertNotFound();
    }

    public function test_un_usuario_sin_permiso_de_gestion_no_puede_modificar(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoParaAgento($rys, $this->crearArea($empresa));
        $sinPermiso = $this->usuarioCon($empresa, 'vigilancia');

        $this->putJson("/api/seleccion/procesos/{$proceso->id}/agento", $this->datosAgento(), $this->cabeceras($sinPermiso))->assertForbidden();
        $this->getJson("/api/seleccion/procesos/{$proceso->id}/agento", $this->cabeceras($sinPermiso))->assertForbidden();
    }

    public function test_estados_en_agento_para_la_lista_de_procesos_solo_de_la_propia_empresa(): void
    {
        $a = $this->crearEmpresa('A');
        $b = $this->crearEmpresa('B');
        $rysA = $this->usuarioCon($a, 'talento_cultura');
        $rysB = $this->usuarioCon($b, 'talento_cultura');
        $publicado = $this->procesoParaAgento($rysA, $this->crearArea($a));
        $this->publicarEnAgento($rysA, $publicado);
        $sinPublicar = $this->procesoParaAgento($rysA, $this->crearArea($a));

        $this->getJson("/api/seleccion/agento/estados?ids={$publicado->id},{$sinPublicar->id}", $this->cabeceras($rysA))
            ->assertOk()->assertExactJson(['data' => [(string) $publicado->id => 'publicada']]);
        // Otra empresa no ve el estado de procesos ajenos.
        $this->getJson("/api/seleccion/agento/estados?ids={$publicado->id}", $this->cabeceras($rysB))
            ->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_el_flujo_actual_del_portal_de_empleos_no_cambia(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoParaAgento($rys, $this->crearArea($empresa));

        // Sin publicación en Agento, /api/publico/empleos sigue mostrando el proceso como siempre.
        $this->olvidarSesionDePersonal();
        unset($this->defaultHeaders['Authorization']);
        $this->getJson('/api/publico/empleos')->assertOk()->assertJsonPath('data.0.codigo', $proceso->codigo);
        $this->assertSame(0, Publicacion::count());
        $this->assertSame(0, $this->v1('/jobs')->json('total'), 'Un proceso sin publicación en Agento no aparece en /api/v1.');
    }
}
