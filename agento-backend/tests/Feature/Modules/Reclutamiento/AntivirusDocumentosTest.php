<?php

namespace Tests\Feature\Modules\Reclutamiento;

use App\Models\User;
use App\Modules\Auditoria\Models\AuditoriaEvento;
use App\Modules\Reclutamiento\Application\EscaneoDocumentos;
use App\Modules\Reclutamiento\Domain\AntivirusNoDisponibleException;
use App\Modules\Reclutamiento\Domain\Contracts\EscanerArchivos;
use App\Modules\Reclutamiento\Domain\ResultadoEscaneo;
use App\Modules\Reclutamiento\Infrastructure\Antivirus\EscanerNulo;
use App\Modules\Reclutamiento\Jobs\EscanearDocumentoJob;
use App\Modules\Reclutamiento\Models\DocumentoCandidato;
use App\Modules\Reclutamiento\Models\Postulacion;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use App\Modules\Reclutamiento\Models\Tarea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreaEscenarioSeleccion;
use Tests\TestCase;

/**
 * Antivirus de CV y documentos: nacen pendientes, se escanean por cola, los infectados quedan en
 * cuarentena (no se descargan) y, si el antivirus cae, el archivo sigue bloqueado (falla cerrado).
 */
class AntivirusDocumentosTest extends TestCase
{
    use CreaEscenarioSeleccion, RefreshDatabase;

    private const EICAR = EscanerNulo::EICAR;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeleccion();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        $this->tearDownCreaEscenarioSeleccion();
        parent::tearDown();
    }

    private function pdf(string $contenido = "%PDF-1.4\n%limpio\n%%EOF"): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('cv.pdf', $contenido);
    }

    /**
     * @return array{0: User, 1: ProcesoSeleccion}
     */
    private function escenario(): array
    {
        $empresa = $this->crearEmpresa('A');
        $rrhh = $this->usuarioCon($empresa, 'talento_cultura');

        return [$rrhh, $this->procesoPublicado($rrhh, $this->crearArea($empresa))];
    }

    /** Fija un escáner de prueba que devuelve siempre este resultado. */
    private function escanerQueDevuelve(ResultadoEscaneo $resultado): void
    {
        $this->app->bind(EscanerArchivos::class, fn () => new class($resultado) implements EscanerArchivos
        {
            public function __construct(private readonly ResultadoEscaneo $resultado) {}

            public function escanear($contenido): ResultadoEscaneo
            {
                return $this->resultado;
            }
        });
    }

    private function documentoDeCv(): DocumentoCandidato
    {
        return DocumentoCandidato::withoutGlobalScopes()->where('tipo', 'cv')->firstOrFail();
    }

    // ---- Flujo normal ----

    public function test_un_cv_limpio_se_escanea_y_se_puede_descargar(): void
    {
        [$rrhh, $proceso] = $this->escenario();

        $this->postular($proceso, 'ok@correo.test', ['cv' => $this->pdf()])->assertCreated();

        $documento = $this->documentoDeCv();
        $this->assertSame('limpio', $documento->estado_escaneo);
        $this->assertNotNull($documento->escaneado_at);
        $postulacion = Postulacion::withoutGlobalScopes()->firstOrFail();
        $this->withHeaders($this->cabeceras($rrhh))->get("/api/seleccion/postulaciones/{$postulacion->id}/cv")->assertOk();
    }

    public function test_el_documento_nace_pendiente_y_pendiente_no_se_descarga(): void
    {
        Queue::fake();
        [$rrhh, $proceso] = $this->escenario();

        $this->postular($proceso, 'pend@correo.test', ['cv' => $this->pdf()])->assertCreated();

        $documento = $this->documentoDeCv();
        $this->assertSame('pendiente_escaneo', $documento->estado_escaneo);
        Queue::assertPushed(EscanearDocumentoJob::class, fn ($job) => true);
        $postulacion = Postulacion::withoutGlobalScopes()->firstOrFail();
        $this->withHeaders($this->cabeceras($rrhh))->get("/api/seleccion/postulaciones/{$postulacion->id}/cv")
            ->assertStatus(423)->assertJsonFragment(['message' => 'El archivo está en revisión de seguridad. Inténtalo de nuevo en unos minutos.']);
    }

    // ---- EICAR ----

    public function test_un_archivo_eicar_se_pone_en_cuarentena_y_nunca_se_puede_descargar(): void
    {
        [$rrhh, $proceso] = $this->escenario();

        $this->postular($proceso, 'virus@correo.test', ['cv' => $this->pdf("%PDF-1.4\n".self::EICAR."\n%%EOF")])->assertCreated();

        $documento = $this->documentoDeCv();
        $this->assertSame('infectado', $documento->estado_escaneo);
        $this->assertSame('Eicar-Test-Signature', $documento->resultado_escaneo);
        $this->assertStringStartsWith("cuarentena/{$documento->empresa_id}/", $documento->ruta);
        Storage::disk('local')->assertExists($documento->ruta);

        $postulacion = Postulacion::withoutGlobalScopes()->firstOrFail();
        $this->withHeaders($this->cabeceras($rrhh))->get("/api/seleccion/postulaciones/{$postulacion->id}/cv")
            ->assertStatus(423)->assertJsonFragment(['message' => 'El archivo fue bloqueado por el antivirus y no se puede descargar.']);
    }

    public function test_un_documento_complementario_eicar_tambien_queda_bloqueado(): void
    {
        [$rrhh, $proceso] = $this->escenario();

        $this->postular($proceso, 'dni@correo.test', ['dni' => UploadedFile::fake()->createWithContent('dni.pdf', "%PDF-1.4\n".self::EICAR."\n%%EOF")])->assertCreated();

        $dni = DocumentoCandidato::withoutGlobalScopes()->where('tipo', 'dni')->firstOrFail();
        $this->assertSame('infectado', $dni->estado_escaneo);
        $this->withHeaders($this->cabeceras($rrhh))
            ->get("/api/seleccion/banco-talentos/{$dni->candidato_id}/documentos/{$dni->id}")->assertStatus(423);
    }

    public function test_un_infectado_se_audita_y_genera_una_tarea_para_rrhh(): void
    {
        [, $proceso] = $this->escenario();

        $this->postular($proceso, 'virus@correo.test', ['cv' => $this->pdf("%PDF-1.4\n".self::EICAR."\n%%EOF")])->assertCreated();

        $documento = $this->documentoDeCv();
        $evento = AuditoriaEvento::where('accion', 'archivo.infectado')->firstOrFail();
        $this->assertSame($documento->empresa_id, $evento->empresa_id);
        $this->assertSame('Eicar-Test-Signature', $evento->payload['firma']);
        $tarea = Tarea::withoutGlobalScopes()->where('tipo', Tarea::ARCHIVO_INFECTADO)->firstOrFail();
        $this->assertSame('seleccion.candidatos.gestionar', $tarea->permiso_responsable);
        $this->assertSame($documento->empresa_id, $tarea->empresa_id);
    }

    public function test_un_infectado_no_se_vuelve_a_escanear_ni_se_libera(): void
    {
        [$rrhh, $proceso] = $this->escenario();
        $this->postular($proceso, 'virus@correo.test', ['cv' => $this->pdf("%PDF-1.4\n".self::EICAR."\n%%EOF")])->assertCreated();
        $documento = $this->documentoDeCv();

        $this->escanerQueDevuelve(ResultadoEscaneo::limpio());
        EscanearDocumentoJob::dispatchSync($documento->empresa_id, $documento->id);

        $this->assertSame('infectado', $documento->fresh()->estado_escaneo);
    }

    public function test_el_job_solo_actua_sobre_documentos_de_su_empresa(): void
    {
        Queue::fake();
        [, $proceso] = $this->escenario();
        $this->postular($proceso, 'a@correo.test', ['cv' => $this->pdf()])->assertCreated();
        $documento = $this->documentoDeCv();
        $otraEmpresa = $this->crearEmpresa('B');

        EscanearDocumentoJob::dispatchSync($otraEmpresa->id, $documento->id);

        $this->assertSame('pendiente_escaneo', $documento->fresh()->estado_escaneo);
    }

    // ---- Antivirus no disponible ----

    public function test_si_el_antivirus_no_responde_en_produccion_el_archivo_sigue_bloqueado(): void
    {
        config(['reclutamiento.antivirus.si_no_disponible' => 'rechazar']);
        Queue::fake();
        [$rrhh, $proceso] = $this->escenario();
        $this->postular($proceso, 'caido@correo.test', ['cv' => $this->pdf()])->assertCreated();
        $documento = $this->documentoDeCv();
        $this->escanerQueDevuelve(ResultadoEscaneo::noDisponible('clamd caído'));

        try {
            app(EscaneoDocumentos::class)->procesar($documento);
            $this->fail('Debe lanzar para reintentar');
        } catch (AntivirusNoDisponibleException) {
        }

        $this->assertSame('pendiente_escaneo', $documento->fresh()->estado_escaneo);
        $postulacion = Postulacion::withoutGlobalScopes()->firstOrFail();
        $this->withHeaders($this->cabeceras($rrhh))->get("/api/seleccion/postulaciones/{$postulacion->id}/cv")->assertStatus(423);
    }

    public function test_agotados_los_reintentos_queda_en_error_de_escaneo_y_sigue_bloqueado(): void
    {
        config(['reclutamiento.antivirus.si_no_disponible' => 'rechazar']);
        Queue::fake();
        [$rrhh, $proceso] = $this->escenario();
        $this->postular($proceso, 'caido@correo.test', ['cv' => $this->pdf()])->assertCreated();
        $documento = $this->documentoDeCv();

        (new EscanearDocumentoJob($documento->empresa_id, $documento->id))->failed(new \RuntimeException('clamd no responde'));

        $this->assertSame('error_escaneo', $documento->fresh()->estado_escaneo);
        $postulacion = Postulacion::withoutGlobalScopes()->firstOrFail();
        $this->withHeaders($this->cabeceras($rrhh))->get("/api/seleccion/postulaciones/{$postulacion->id}/cv")->assertStatus(423);
    }

    public function test_el_comando_reencola_pendientes_y_con_error_pero_no_los_legados_salvo_opcion(): void
    {
        Queue::fake();
        [, $proceso] = $this->escenario();
        $this->postular($proceso, 'a@correo.test', ['cv' => $this->pdf()])->assertCreated();
        $this->postular($proceso, 'b@correo.test', ['cv' => $this->pdf()])->assertCreated();
        $this->postular($proceso, 'c@correo.test', ['cv' => $this->pdf()])->assertCreated();
        [$a, $b, $c] = DocumentoCandidato::withoutGlobalScopes()->orderBy('id')->get()->all();
        $b->update(['estado_escaneo' => 'error_escaneo']);
        $c->update(['estado_escaneo' => 'legado']);
        Queue::fake();

        $this->artisan('reclutamiento:escanear-documentos')->expectsOutputToContain('encolados para escaneo: 2')->assertSuccessful();
        Queue::assertPushed(EscanearDocumentoJob::class, 2);

        Queue::fake();
        $this->artisan('reclutamiento:escanear-documentos', ['--legado' => true])->expectsOutputToContain('encolados para escaneo: 3')->assertSuccessful();
        $this->assertTrue($a->exists);
    }

    public function test_un_documento_legado_se_puede_descargar_hasta_escanearlo(): void
    {
        [$rrhh, $proceso] = $this->escenario();
        $this->postular($proceso, 'legado@correo.test', ['cv' => $this->pdf()])->assertCreated();
        $this->documentoDeCv()->update(['estado_escaneo' => 'legado']);
        $postulacion = Postulacion::withoutGlobalScopes()->firstOrFail();

        $this->withHeaders($this->cabeceras($rrhh))->get("/api/seleccion/postulaciones/{$postulacion->id}/cv")->assertOk();
    }

    // ---- Escáner nulo ----

    public function test_el_escaner_nulo_fuera_de_local_y_testing_no_da_nada_por_limpio(): void
    {
        $this->app['env'] = 'production';
        $flujo = fopen('php://memory', 'r+');
        fwrite($flujo, 'contenido inocente');
        rewind($flujo);

        $resultado = (new EscanerNulo)->escanear($flujo);

        $this->assertSame(ResultadoEscaneo::NO_DISPONIBLE, $resultado->estado);
    }
}
