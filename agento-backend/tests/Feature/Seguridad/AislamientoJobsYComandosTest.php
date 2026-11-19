<?php

namespace Tests\Feature\Seguridad;

use App\Modules\Configuracion\Support\EmpresaContext;
use App\Modules\Configuracion\Support\EmpresaNoDefinidaException;
use App\Modules\Reclutamiento\Application\AplicacionPruebaService;
use App\Modules\Reclutamiento\Models\Candidato;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaEscenarioSeleccion;
use Tests\Fixtures\JobQueLeeContexto;
use Tests\TestCase;

/**
 * Jobs y comandos no tienen usuario autenticado: el contexto de empresa debe viajar con ellos o
 * declararse. Sin él, el scope falla cerrado.
 */
class AislamientoJobsYComandosTest extends TestCase
{
    use CreaEscenarioSeleccion, RefreshDatabase;

    protected bool $aislamientoEstricto = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeleccion();
        JobQueLeeContexto::reiniciar();
    }

    protected function tearDown(): void
    {
        $this->tearDownCreaEscenarioSeleccion();
        parent::tearDown();
    }

    private function candidato(int $empresaId, string $email, ?string $ultimaPostulacion = null): void
    {
        Candidato::withoutGlobalScopes()->create([
            'empresa_id' => $empresaId, 'email' => $email, 'nombres' => 'Ana', 'apellido_paterno' => 'Pérez',
            'ultima_postulacion_at' => $ultimaPostulacion,
        ]);
    }

    /** Despacho inmediato (dispatch() devuelve un PendingDispatch que se ejecuta al destruirse). */
    private function despachar(): void
    {
        app(Dispatcher::class)->dispatch(new JobQueLeeContexto);
    }

    // ---- Jobs ----

    public function test_un_job_hereda_la_empresa_con_la_que_fue_despachado(): void
    {
        $a = $this->crearEmpresa('A');
        $b = $this->crearEmpresa('B');
        $this->candidato($a->id, 'a1@correo.test');
        $this->candidato($a->id, 'a2@correo.test');
        $this->candidato($b->id, 'b1@correo.test');

        EmpresaContext::como($a->id, fn () => $this->despachar());
        $this->assertSame($a->id, JobQueLeeContexto::$empresaVista);
        $this->assertSame(2, JobQueLeeContexto::$candidatosVistos, 'El job de A solo ve los candidatos de A');

        EmpresaContext::como($b->id, fn () => $this->despachar());
        $this->assertSame($b->id, JobQueLeeContexto::$empresaVista);
        $this->assertSame(1, JobQueLeeContexto::$candidatosVistos);
    }

    public function test_el_contexto_anterior_se_restaura_despues_de_ejecutar_el_job(): void
    {
        $a = $this->crearEmpresa('A');
        $b = $this->crearEmpresa('B');

        EmpresaContext::como($a->id, function () use ($a, $b) {
            EmpresaContext::como($b->id, fn () => $this->despachar());

            $this->assertSame($b->id, JobQueLeeContexto::$empresaVista);
            $this->assertSame($a->id, EmpresaContext::actual(), 'Tras el job se vuelve a la empresa de quien lo despachó');
        });

        $this->assertNull(EmpresaContext::actual());
    }

    public function test_un_job_despachado_sin_contexto_no_hereda_empresa_y_falla_cerrado(): void
    {
        $a = $this->crearEmpresa('A');
        $this->candidato($a->id, 'a1@correo.test');

        $this->expectException(EmpresaNoDefinidaException::class);

        $this->despachar();
    }

    public function test_un_job_despachado_en_modo_sistema_no_hereda_empresa(): void
    {
        $a = $this->crearEmpresa('A');
        $b = $this->crearEmpresa('B');
        $this->candidato($a->id, 'a1@correo.test');
        $this->candidato($b->id, 'b1@correo.test');

        EmpresaContext::comoSistema(fn () => $this->despachar(), motivo: 'test: despacho en modo sistema');

        // Se ejecutó dentro del modo sistema de quien lo despachó (cola sincrónica), sin empresa propia.
        $this->assertNull(JobQueLeeContexto::$empresaVista);
        $this->assertSame(2, JobQueLeeContexto::$candidatosVistos);
    }

    // ---- Jobs de Nóminas: conservan el comportamiento histórico (regla permanente) ----

    private function jobFalso(string $clase, ?int $empresaEnPayload): \Illuminate\Contracts\Queue\Job
    {
        $job = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('resolveName')->andReturn($clase);
        $job->shouldReceive('payload')->andReturn($empresaEnPayload ? ['empresa_contexto' => $empresaEnPayload] : []);

        return $job;
    }

    public function test_un_job_de_nominas_en_el_worker_corre_sin_filtro_y_no_hereda_la_empresa_del_payload(): void
    {
        $job = $this->jobFalso('App\\Modules\\Nominas\\Jobs\\CalcularPlanillaJob', 5);

        event(new \Illuminate\Queue\Events\JobProcessing('database', $job));

        // Igual que en main: en el worker, sin filtro de empresa (ni la del payload ni fallo cerrado).
        $this->assertTrue(EmpresaContext::esSistema());
        $this->assertNull(EmpresaContext::actual());

        event(new \Illuminate\Queue\Events\JobProcessed('database', $job));
        $this->assertFalse(EmpresaContext::esSistema(), 'Al terminar vuelve al estado anterior');
    }

    public function test_un_job_de_nominas_dentro_de_una_peticion_conserva_el_contexto_ambiente(): void
    {
        $job = $this->jobFalso('App\\Modules\\Nominas\\Jobs\\EnviarBoletaPorCorreoJob', 5);

        EmpresaContext::como(7, function () use ($job) {
            event(new \Illuminate\Queue\Events\JobProcessing('sync', $job));

            $this->assertSame(7, EmpresaContext::actual(), 'Cola sync dentro de la petición: el contexto no cambia (como en main)');
            $this->assertFalse(EmpresaContext::esSistema());

            event(new \Illuminate\Queue\Events\JobProcessed('sync', $job));
        });
    }

    public function test_un_job_que_no_es_de_nominas_si_hereda_la_empresa_del_payload(): void
    {
        $job = $this->jobFalso('App\\Modules\\Reclutamiento\\Jobs\\EvaluarPostulacionJob', 5);

        event(new \Illuminate\Queue\Events\JobProcessing('database', $job));
        $this->assertSame(5, EmpresaContext::actual());
        $this->assertFalse(EmpresaContext::esSistema());

        event(new \Illuminate\Queue\Events\JobProcessed('database', $job));
        $this->assertNull(EmpresaContext::actual());
    }

    // ---- Comandos ----

    public function test_cerrar_intentos_recorre_cada_empresa_con_su_propio_contexto(): void
    {
        $a = $this->crearEmpresa('A');
        $b = $this->crearEmpresa('B');
        $vistas = [];
        $this->mock(AplicacionPruebaService::class, function ($mock) use (&$vistas) {
            $mock->shouldReceive('cerrarVencidos')->andReturnUsing(function () use (&$vistas) {
                $vistas[] = EmpresaContext::actual();

                return 0;
            });
        });

        $this->artisan('reclutamiento:cerrar-intentos-vencidos')->assertSuccessful();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_values(array_filter($vistas)), 'Cada empresa se procesa con su contexto');
        $this->assertNull(EmpresaContext::actual(), 'El comando no deja contexto pegado');
    }

    public function test_cerrar_intentos_con_opcion_empresa_solo_procesa_esa_empresa(): void
    {
        $a = $this->crearEmpresa('A');
        $this->crearEmpresa('B');
        $vistas = [];
        $this->mock(AplicacionPruebaService::class, function ($mock) use (&$vistas) {
            $mock->shouldReceive('cerrarVencidos')->andReturnUsing(function () use (&$vistas) {
                $vistas[] = EmpresaContext::actual();

                return 0;
            });
        });

        $this->artisan('reclutamiento:cerrar-intentos-vencidos', ['--empresa' => $a->id])->assertSuccessful();

        $this->assertSame([$a->id], $vistas);
    }

    public function test_vencer_candidatos_cuenta_por_empresa_y_respeta_la_opcion_empresa(): void
    {
        $a = $this->crearEmpresa('A');
        $b = $this->crearEmpresa('B');
        $viejo = now()->subYears(3)->toDateTimeString();
        $this->candidato($a->id, 'a1@correo.test', $viejo);
        $this->candidato($a->id, 'a2@correo.test', $viejo);
        $this->candidato($b->id, 'b1@correo.test', $viejo);
        $this->candidato($b->id, 'b2@correo.test', now()->toDateTimeString());

        $this->artisan('reclutamiento:vencer-candidatos')->expectsOutputToContain('desde la última postulación): 3')->assertSuccessful();
        $this->artisan('reclutamiento:vencer-candidatos', ['--empresa' => $a->id])->expectsOutputToContain('desde la última postulación): 2')->assertSuccessful();
        $this->artisan('reclutamiento:vencer-candidatos', ['--empresa' => $b->id])->expectsOutputToContain('desde la última postulación): 1')->assertSuccessful();
    }

    public function test_el_comando_de_retencion_anonimiza_solo_a_la_empresa_indicada(): void
    {
        config(['reclutamiento.retencion.eliminacion_habilitada' => true]);
        $a = $this->crearEmpresa('A');
        $b = $this->crearEmpresa('B');
        $viejo = now()->subYears(3)->toDateTimeString();
        $this->candidato($a->id, 'a1@correo.test', $viejo);
        $this->candidato($b->id, 'b1@correo.test', $viejo);

        $this->artisan('reclutamiento:vencer-candidatos', ['--ejecutar' => true, '--empresa' => $a->id])->assertSuccessful();

        $this->assertNotNull(Candidato::withoutGlobalScopes()->where('empresa_id', $a->id)->first()->anonimizado_at);
        $this->assertNull(Candidato::withoutGlobalScopes()->where('empresa_id', $b->id)->first()->anonimizado_at, 'La empresa B no se toca');
    }
}
