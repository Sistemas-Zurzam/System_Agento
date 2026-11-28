<?php

namespace Tests\Feature\Modules\Reclutamiento;

use App\Models\User;
use App\Modules\Auditoria\Models\AuditoriaEvento;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Reclutamiento\Models\Candidato;
use App\Modules\Reclutamiento\Models\Consentimiento;
use App\Modules\Reclutamiento\Models\Postulacion;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use App\Modules\Reclutamiento\Models\SolicitudArco;
use App\Modules\Reclutamiento\Models\TextoLegal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreaEscenarioSeleccion;
use Tests\TestCase;

class ConsentimientoYArcoTest extends TestCase
{
    use CreaEscenarioSeleccion, RefreshDatabase;

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

    private function vigente(): TextoLegal
    {
        return TextoLegal::where('tipo', TextoLegal::CONSENTIMIENTO_POSTULACION)->firstOrFail();
    }

    /**
     * @return array{0: Empresa, 1: User, 2: ProcesoSeleccion}
     */
    private function escenario(string $nombre = 'A'): array
    {
        $empresa = $this->crearEmpresa($nombre);
        $rrhh = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoPublicado($rrhh, $this->crearArea($empresa));

        return [$empresa, $rrhh, $proceso];
    }

    private function cand(string $email): array
    {
        return ['X-Candidato-Token' => $this->tokenCandidato($email)];
    }

    // ---- Textos legales ----

    public function test_el_texto_vigente_se_siembra_y_es_publico(): void
    {
        $respuesta = $this->getJson('/api/publico/textos-legales/consentimiento_postulacion')->assertOk();

        $this->assertSame($this->vigente()->version, $respuesta->json('data.version'));
        $this->assertSame(hash('sha256', $respuesta->json('data.texto')), $respuesta->json('data.hash'));
        $this->assertStringContainsString('tratamiento de mis datos personales', $respuesta->json('data.texto'));
    }

    public function test_tipo_de_texto_desconocido_o_sin_version_vigente_da_404(): void
    {
        $this->getJson('/api/publico/textos-legales/otro_tipo')->assertNotFound();
        // La política de privacidad aún no tiene texto cargado (lo redacta el abogado).
        $this->getJson('/api/publico/textos-legales/politica_privacidad')->assertNotFound();
    }

    public function test_solo_se_considera_vigente_la_version_mas_reciente_sin_vencer(): void
    {
        $anterior = $this->vigente();
        $anterior->update(['vigente_hasta' => now()->subDay()]);
        TextoLegal::create(['tipo' => 'consentimiento_postulacion', 'version' => '2027-01-v2', 'texto' => 'Texto nuevo', 'hash' => hash('sha256', 'Texto nuevo'), 'vigente_desde' => now()->subHour()]);

        $this->getJson('/api/publico/textos-legales/consentimiento_postulacion')->assertOk()->assertJsonPath('data.version', '2027-01-v2');
    }

    // ---- Verificación al postular ----

    public function test_postular_con_el_texto_vigente_registra_version_hash_y_texto_legal(): void
    {
        [, , $proceso] = $this->escenario();
        $vigente = $this->vigente();

        $this->postular($proceso, 'ok@correo.test', ['consentimiento_version' => $vigente->version, 'consentimiento_hash' => $vigente->hash])->assertCreated();

        $consentimiento = Consentimiento::query()->firstOrFail();
        $this->assertSame($vigente->version, $consentimiento->version);
        $this->assertSame($vigente->hash, $consentimiento->texto_hash);
        $this->assertSame($vigente->id, $consentimiento->texto_legal_id);
    }

    public function test_postular_sin_enviar_version_ni_hash_sigue_funcionando_si_el_texto_coincide(): void
    {
        [, , $proceso] = $this->escenario();

        $this->postular($proceso, 'compat@correo.test')->assertCreated();
    }

    public function test_postular_con_una_version_distinta_a_la_vigente_da_422(): void
    {
        [, , $proceso] = $this->escenario();

        $this->postular($proceso, 'v@correo.test', ['consentimiento_version' => '1999-v0'])->assertUnprocessable()->assertJsonValidationErrors('consentimiento_version');
        $this->assertSame(0, Postulacion::withoutGlobalScopes()->count());
    }

    public function test_postular_con_un_hash_distinto_al_vigente_da_422(): void
    {
        [, , $proceso] = $this->escenario();

        $this->postular($proceso, 'h@correo.test', ['consentimiento_hash' => str_repeat('a', 64)])->assertUnprocessable()->assertJsonValidationErrors('consentimiento_version');
    }

    public function test_postular_con_un_texto_modificado_da_422(): void
    {
        [, , $proceso] = $this->escenario();

        $this->postular($proceso, 't@correo.test', ['consentimiento_texto' => 'Acepto cualquier cosa'])->assertUnprocessable()->assertJsonValidationErrors('consentimiento_version');
    }

    public function test_si_cambia_el_texto_vigente_el_texto_viejo_deja_de_valer(): void
    {
        [, , $proceso] = $this->escenario();
        $viejo = $this->vigente();
        $viejo->update(['vigente_hasta' => now()->subMinute()]);
        $nuevo = TextoLegal::create(['tipo' => 'consentimiento_postulacion', 'version' => '2027-v2', 'texto' => 'Nuevo texto legal', 'hash' => hash('sha256', 'Nuevo texto legal'), 'vigente_desde' => now()->subSecond()]);

        $this->postular($proceso, 'viejo@correo.test', ['consentimiento_texto' => $viejo->texto, 'consentimiento_version' => $viejo->version])->assertUnprocessable();
        $this->postular($proceso, 'nuevo@correo.test', ['consentimiento_texto' => $nuevo->texto, 'consentimiento_version' => $nuevo->version, 'consentimiento_hash' => $nuevo->hash])->assertCreated();
    }

    // ---- Revocación ----

    public function test_revocar_retira_postulaciones_marca_el_consentimiento_y_crea_la_solicitud_de_cancelacion(): void
    {
        [$empresa, , $proceso] = $this->escenario();
        $this->postular($proceso, 'rev@correo.test')->assertCreated();

        $this->withHeaders($this->cand('rev@correo.test'))->postJson('/api/publico/candidatos/consentimiento/revocar')
            ->assertOk()->assertJsonPath('data.empresas', 1)->assertJsonPath('data.postulaciones_retiradas', 1);

        $this->assertNotNull(Consentimiento::query()->firstOrFail()->revocado_at);
        $this->assertSame('retirada', Postulacion::withoutGlobalScopes()->firstOrFail()->estado);
        $arco = SolicitudArco::withoutGlobalScopes()->firstOrFail();
        $this->assertSame([$empresa->id, 'cancelacion', 'pendiente'], [$arco->empresa_id, $arco->tipo, $arco->estado]);
        $this->assertTrue(AuditoriaEvento::where('accion', 'consentimiento.revocado')->exists());
    }

    public function test_revocar_es_idempotente_y_no_duplica_solicitudes(): void
    {
        [, , $proceso] = $this->escenario();
        $this->postular($proceso, 'dos@correo.test')->assertCreated();

        $cabeceras = $this->cand('dos@correo.test');
        $this->withHeaders($cabeceras)->postJson('/api/publico/candidatos/consentimiento/revocar')->assertOk();
        $this->withHeaders($cabeceras)->postJson('/api/publico/candidatos/consentimiento/revocar')->assertOk()->assertJsonPath('data.postulaciones_retiradas', 0);

        $this->assertSame(1, SolicitudArco::withoutGlobalScopes()->count());
    }

    public function test_revocar_requiere_sesion_de_candidato(): void
    {
        $this->postJson('/api/publico/candidatos/consentimiento/revocar')->assertUnauthorized();
        $this->getJson('/api/publico/candidatos/arco/exportacion')->assertUnauthorized();
    }

    // ---- ARCO: acceso ----

    public function test_el_acceso_exporta_solo_los_datos_del_propio_candidato_y_queda_registrado(): void
    {
        [, , $proceso] = $this->escenario();
        $this->postular($proceso, 'yo@correo.test', ['nombres' => 'Rosa'])->assertCreated();
        $this->postular($proceso, 'otro@correo.test', ['nombres' => 'Ajeno'])->assertCreated();

        $respuesta = $this->withHeaders($this->cand('yo@correo.test'))->getJson('/api/publico/candidatos/arco/exportacion')->assertOk();

        $this->assertCount(1, $respuesta->json('data.empresas'));
        $this->assertSame('Rosa', $respuesta->json('data.empresas.0.perfil.nombres'));
        $this->assertSame('yo@correo.test', $respuesta->json('data.correo'));
        $this->assertStringNotContainsString('otro@correo.test', $respuesta->getContent());
        $this->assertStringNotContainsString('Ajeno', $respuesta->getContent());
        $this->assertStringNotContainsString('motivo_interno', $respuesta->getContent());
        $this->assertTrue(AuditoriaEvento::where('accion', 'arco.acceso')->exists());
        $this->assertSame('atendida', SolicitudArco::withoutGlobalScopes()->where('tipo', 'acceso')->firstOrFail()->estado);
    }

    public function test_el_candidato_descarga_sus_archivos_pero_no_los_de_otro(): void
    {
        [, , $proceso] = $this->escenario();
        $this->postular($proceso, 'mio@correo.test', ['cv' => UploadedFile::fake()->createWithContent('cv.pdf', "%PDF-1.4\n%mío\n%%EOF")])->assertCreated();
        $this->postular($proceso, 'ajeno@correo.test')->assertCreated();
        $exportacion = $this->withHeaders($this->cand('mio@correo.test'))->getJson('/api/publico/candidatos/arco/exportacion')->json('data.empresas.0.documentos');
        $propio = $exportacion[0]['id'];
        $ajeno = Postulacion::withoutGlobalScopes()->whereHas('candidato', fn ($q) => $q->withoutGlobalScopes()->where('email', 'ajeno@correo.test'))->firstOrFail()->cv_documento_id;

        $this->withHeaders($this->cand('mio@correo.test'))->get("/api/publico/candidatos/arco/documentos/{$propio}")->assertOk();
        $this->withHeaders($this->cand('mio@correo.test'))->get("/api/publico/candidatos/arco/documentos/{$ajeno}")->assertNotFound();
    }

    // ---- ARCO: rectificación y cancelación ----

    public function test_la_rectificacion_se_registra_con_estado_y_plazo(): void
    {
        [$empresa, , $proceso] = $this->escenario();
        $this->postular($proceso, 'rect@correo.test', ['celular' => '911111111'])->assertCreated();

        $respuesta = $this->withHeaders($this->cand('rect@correo.test'))->postJson('/api/publico/candidatos/arco/rectificacion', [
            'cambios' => ['celular' => '922222222', 'nombres' => 'Rosa María'], 'motivo' => 'Cambié de número',
        ])->assertCreated();

        $this->assertSame('pendiente', $respuesta->json('data.0.estado'));
        $solicitud = SolicitudArco::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($empresa->id, $solicitud->empresa_id);
        $this->assertEquals(['celular' => '922222222', 'nombres' => 'Rosa María'], $solicitud->detalle['cambios']);
        $this->assertSame($solicitud->solicitada_at->copy()->addWeekdays(10)->toDateString(), $solicitud->vence_at->toDateString());
        $this->assertTrue(AuditoriaEvento::where('accion', 'arco.rectificacion_solicitada')->exists());
    }

    public function test_la_rectificacion_valida_los_campos_y_exige_al_menos_uno(): void
    {
        [, , $proceso] = $this->escenario();
        $this->postular($proceso, 'v@correo.test')->assertCreated();
        $cabeceras = $this->cand('v@correo.test');

        $this->withHeaders($cabeceras)->postJson('/api/publico/candidatos/arco/rectificacion', [])->assertUnprocessable();
        $this->withHeaders($cabeceras)->postJson('/api/publico/candidatos/arco/rectificacion', ['cambios' => ['numero_documento' => 'x y']])->assertUnprocessable();
    }

    public function test_la_rectificacion_ignora_campos_no_permitidos(): void
    {
        [$empresa, $rrhh, $proceso] = $this->escenario();
        $this->postular($proceso, 'camp@correo.test')->assertCreated();
        $this->withHeaders($this->cand('camp@correo.test'))->postJson('/api/publico/candidatos/arco/rectificacion', [
            'cambios' => ['celular' => '933333333', 'empresa_id' => 999, 'email' => 'otro@x.test'],
        ])->assertCreated();
        $solicitud = SolicitudArco::withoutGlobalScopes()->firstOrFail();

        $this->withHeaders($this->cabeceras($rrhh))->postJson("/api/seleccion/arco/{$solicitud->id}/atender", ['estado' => 'atendida'])->assertOk();

        $candidato = Candidato::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('933333333', $candidato->celular);
        $this->assertSame($empresa->id, $candidato->empresa_id);
        $this->assertSame('camp@correo.test', $candidato->email);
    }

    public function test_una_solicitud_abierta_del_mismo_tipo_no_se_duplica(): void
    {
        [, , $proceso] = $this->escenario();
        $this->postular($proceso, 'dup@correo.test')->assertCreated();
        $cabeceras = $this->cand('dup@correo.test');

        $this->withHeaders($cabeceras)->postJson('/api/publico/candidatos/arco/cancelacion', ['motivo' => 'Ya no quiero'])->assertCreated();
        $this->withHeaders($cabeceras)->postJson('/api/publico/candidatos/arco/cancelacion')->assertCreated();

        $this->assertSame(1, SolicitudArco::withoutGlobalScopes()->where('tipo', 'cancelacion')->count());
        $this->assertCount(1, $this->withHeaders($cabeceras)->getJson('/api/publico/candidatos/arco/solicitudes')->json('data'));
    }

    public function test_una_solicitud_se_registra_por_empresa_que_conserva_datos(): void
    {
        [$empresaA, , $procesoA] = $this->escenario('A');
        [$empresaB, , $procesoB] = $this->escenario('B');
        $this->postular($procesoA, 'dos@correo.test')->assertCreated();
        $this->postular($procesoB, 'dos@correo.test')->assertCreated();

        $this->withHeaders($this->cand('dos@correo.test'))->postJson('/api/publico/candidatos/arco/cancelacion')->assertCreated()->assertJsonCount(2, 'data');

        $this->assertEqualsCanonicalizing([$empresaA->id, $empresaB->id], SolicitudArco::withoutGlobalScopes()->pluck('empresa_id')->all());
    }

    // ---- Bandeja de RR. HH. ----

    public function test_rrhh_solo_ve_las_solicitudes_de_su_empresa(): void
    {
        [, $rrhhA, $procesoA] = $this->escenario('A');
        [, $rrhhB, $procesoB] = $this->escenario('B');
        $this->postular($procesoA, 'a@correo.test')->assertCreated();
        $this->postular($procesoB, 'b@correo.test')->assertCreated();
        $this->withHeaders($this->cand('a@correo.test'))->postJson('/api/publico/candidatos/arco/cancelacion')->assertCreated();
        $this->withHeaders($this->cand('b@correo.test'))->postJson('/api/publico/candidatos/arco/cancelacion')->assertCreated();
        $deB = SolicitudArco::withoutGlobalScopes()->where('empresa_id', $rrhhB->empresa_id)->firstOrFail();

        $lista = $this->withHeaders($this->cabeceras($rrhhA))->getJson('/api/seleccion/arco')->assertOk();

        $this->assertCount(1, $lista->json('data'));
        $this->assertSame('a@correo.test', $lista->json('data.0.candidato.email'));
        $this->withHeaders($this->cabeceras($rrhhA))->postJson("/api/seleccion/arco/{$deB->id}/atender", ['estado' => 'atendida'])->assertNotFound();
    }

    public function test_la_bandeja_exige_permiso_de_gestion_de_candidatos(): void
    {
        $empresa = $this->crearEmpresa('A');
        $solicitante = $this->usuarioCon($empresa, 'solicitante');

        $this->withHeaders($this->cabeceras($solicitante))->getJson('/api/seleccion/arco')->assertForbidden();
    }

    public function test_atender_una_cancelacion_anonimiza_al_candidato_y_audita(): void
    {
        [, $rrhh, $proceso] = $this->escenario();
        $this->postular($proceso, 'borrar@correo.test')->assertCreated();
        $this->withHeaders($this->cand('borrar@correo.test'))->postJson('/api/publico/candidatos/consentimiento/revocar')->assertOk();
        $solicitud = SolicitudArco::withoutGlobalScopes()->firstOrFail();

        $this->withHeaders($this->cabeceras($rrhh))->postJson("/api/seleccion/arco/{$solicitud->id}/atender", ['estado' => 'atendida', 'respuesta' => 'Datos eliminados.'])
            ->assertOk()->assertJsonPath('data.estado', 'atendida');

        $candidato = Candidato::withoutGlobalScopes()->firstOrFail();
        $this->assertNotNull($candidato->anonimizado_at);
        $this->assertStringNotContainsString('borrar@correo.test', (string) $candidato->email);
        $this->assertSame($rrhh->id, $solicitud->fresh()->atendida_por);
        $this->assertTrue(AuditoriaEvento::where('accion', 'arco.cancelacion_atendida')->where('user_id', $rrhh->id)->exists());
    }

    public function test_no_se_eliminan_los_datos_de_un_contratado(): void
    {
        [, $rrhh, $proceso] = $this->escenario();
        $this->postular($proceso, 'contratado@correo.test')->assertCreated();
        Postulacion::withoutGlobalScopes()->firstOrFail()->update(['estado' => 'contratada']);
        $this->withHeaders($this->cand('contratado@correo.test'))->postJson('/api/publico/candidatos/arco/cancelacion')->assertCreated();
        $solicitud = SolicitudArco::withoutGlobalScopes()->firstOrFail();

        $this->withHeaders($this->cabeceras($rrhh))->postJson("/api/seleccion/arco/{$solicitud->id}/atender", ['estado' => 'atendida'])->assertUnprocessable();

        $this->assertNull(Candidato::withoutGlobalScopes()->firstOrFail()->anonimizado_at);
        $this->assertSame('pendiente', $solicitud->fresh()->estado);
    }

    public function test_rechazar_exige_motivo_y_una_solicitud_cerrada_no_se_reabre(): void
    {
        [, $rrhh, $proceso] = $this->escenario();
        $this->postular($proceso, 'r@correo.test')->assertCreated();
        $this->withHeaders($this->cand('r@correo.test'))->postJson('/api/publico/candidatos/arco/cancelacion')->assertCreated();
        $solicitud = SolicitudArco::withoutGlobalScopes()->firstOrFail();
        $cabeceras = $this->cabeceras($rrhh);

        $this->withHeaders($cabeceras)->postJson("/api/seleccion/arco/{$solicitud->id}/atender", ['estado' => 'rechazada'])->assertUnprocessable()->assertJsonValidationErrors('respuesta');
        $this->withHeaders($cabeceras)->postJson("/api/seleccion/arco/{$solicitud->id}/atender", ['estado' => 'rechazada', 'respuesta' => 'Existe obligación legal de conservar los datos.'])->assertOk();
        $this->withHeaders($cabeceras)->postJson("/api/seleccion/arco/{$solicitud->id}/atender", ['estado' => 'atendida'])->assertUnprocessable();
    }

    public function test_el_filtro_de_vencidas_devuelve_solo_las_abiertas_fuera_de_plazo(): void
    {
        [, $rrhh, $proceso] = $this->escenario();
        $this->postular($proceso, 'venc@correo.test')->assertCreated();
        $this->withHeaders($this->cand('venc@correo.test'))->postJson('/api/publico/candidatos/arco/cancelacion')->assertCreated();
        $solicitud = SolicitudArco::withoutGlobalScopes()->firstOrFail();
        $cabeceras = $this->cabeceras($rrhh);

        $this->withHeaders($cabeceras)->getJson('/api/seleccion/arco?vencidas=1')->assertOk()->assertJsonCount(0, 'data');

        $solicitud->update(['vence_at' => now()->subDay()]);
        $this->withHeaders($cabeceras)->getJson('/api/seleccion/arco?vencidas=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.vencida', true);
    }
}
