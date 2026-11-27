<?php

namespace Tests\Feature\Modules\Integraciones;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Integraciones\Application\CredencialesIntegracion;
use App\Modules\Integraciones\Models\ClaseExterna;
use App\Modules\Integraciones\Models\CredencialIntegracion;
use App\Modules\Integraciones\Models\LoteClasesExternas;
use App\Modules\Personas\Models\Colaborador;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

/**
 * Recepción de clases dictadas de Firme Studio: la empresa sale del token, el lote es idempotente, la
 * suplencia se deduce de titular ≠ quien dictó y lo que no se puede identificar queda observado.
 */
class ClasesExternasFirmeStudioTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    private const URL = '/api/integraciones/firme-studio/clases';

    private Empresa $empresa;

    private string $token;

    private Colaborador $luchita;

    private Colaborador $pepita;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->empresa = Empresa::firstOrFail();
        $this->empresa->update(['ruc' => '20123456789', 'pago_por_clases_habilitado' => true]);

        $this->token = app(CredencialesIntegracion::class)
            ->crear($this->empresa->id, CredencialIntegracion::FIRME_STUDIO, 'Firme Studio')['token'];

        $this->luchita = $this->crearColaborador($this->empresa, ['nombres' => 'Luchita', 'numero_documento' => '11111111']);
        $this->pepita = $this->crearColaborador($this->empresa, ['nombres' => 'Pepita', 'numero_documento' => '22222222']);
    }

    private function clase(array $cambios = []): array
    {
        return array_replace_recursive([
            'clase_id' => 'CL-1',
            'fecha' => '2026-11-10',
            'hora_inicio' => '07:00',
            'hora_fin' => '08:00',
            'tipo_clase' => 'reformer',
            'sede' => 'Miraflores',
            'estado' => 'dictada',
            'instructora_titular' => ['dni' => '11111111', 'nombre' => 'Luchita Gómez'],
            'instructora_que_dicto' => ['dni' => '11111111', 'nombre' => 'Luchita Gómez'],
        ], $cambios);
    }

    private function envio(array $clases, array $cambios = []): array
    {
        return array_merge([
            'ruc' => '20123456789',
            'referencia' => 'FIRME-2026-11',
            'periodo_inicio' => '2026-11-01',
            'periodo_fin' => '2026-11-30',
            'clases' => $clases,
        ], $cambios);
    }

    private function enviar(array $cuerpo, ?string $token = null)
    {
        return $this->withHeaders(['X-Api-Key' => $token ?? $this->token])->postJson(self::URL, $cuerpo);
    }

    public function test_sin_token_o_con_token_invalido_responde_401(): void
    {
        $this->postJson(self::URL, $this->envio([$this->clase()]))->assertStatus(401);
        $this->enviar($this->envio([$this->clase()]), 'agt_inventado')->assertStatus(401);
        $this->assertSame(0, LoteClasesExternas::withoutGlobalScopes()->count());
    }

    public function test_una_empresa_sin_pago_por_clases_habilitado_no_recibe_clases(): void
    {
        $this->empresa->update(['pago_por_clases_habilitado' => false]);

        $this->enviar($this->envio([$this->clase()]))
            ->assertStatus(403)
            ->assertJsonPath('message', 'La empresa no tiene habilitado el pago por clases en Agento.');

        $this->assertSame(0, LoteClasesExternas::withoutGlobalScopes()->count());
    }

    public function test_una_credencial_revocada_o_vencida_responde_401(): void
    {
        CredencialIntegracion::query()->update(['activo' => false]);
        $this->enviar($this->envio([$this->clase()]))->assertStatus(401);

        CredencialIntegracion::query()->update(['activo' => true, 'expira_at' => now()->subDay()]);
        $this->enviar($this->envio([$this->clase()]))->assertStatus(401);
    }

    public function test_el_token_de_otro_proveedor_no_sirve(): void
    {
        $otro = app(CredencialesIntegracion::class)->crear($this->empresa->id, 'otro_proveedor', 'Otro')['token'];

        $this->enviar($this->envio([$this->clase()]), $otro)->assertStatus(401);
    }

    public function test_el_token_se_guarda_solo_como_hash(): void
    {
        $this->assertNotSame($this->token, CredencialIntegracion::firstOrFail()->token_hash);
        $this->assertSame(hash('sha256', $this->token), CredencialIntegracion::firstOrFail()->token_hash);
    }

    public function test_recibe_el_lote_con_la_empresa_del_token_y_marca_la_suplencia(): void
    {
        $respuesta = $this->enviar($this->envio([
            $this->clase(['clase_id' => 'CL-1']),
            $this->clase(['clase_id' => 'CL-2', 'instructora_que_dicto' => ['dni' => '22222222', 'nombre' => 'Pepita Ríos'], 'motivo_suplencia' => 'enfermedad']),
        ]))->assertStatus(201);

        $respuesta->assertJsonPath('estado', 'recibido')
            ->assertJsonPath('total_clases', 2)
            ->assertJsonPath('total_observadas', 0)
            ->assertJsonPath('clases.0.suplencia', false)
            ->assertJsonPath('clases.1.suplencia', true);

        $lote = LoteClasesExternas::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->empresa->id, $lote->empresa_id);

        $suplida = ClaseExterna::withoutGlobalScopes()->where('clase_id', 'CL-2')->firstOrFail();
        $this->assertSame($this->luchita->id, $suplida->titular_colaborador_id);
        $this->assertSame($this->pepita->id, $suplida->ejecutor_colaborador_id);
    }

    public function test_un_empresa_id_en_el_cuerpo_no_cambia_la_empresa(): void
    {
        $otra = Empresa::factory()->create();

        $this->enviar($this->envio([$this->clase()], ['empresa_id' => $otra->id]))->assertStatus(201);

        $this->assertSame($this->empresa->id, LoteClasesExternas::withoutGlobalScopes()->firstOrFail()->empresa_id);
    }

    public function test_un_ruc_que_no_corresponde_a_la_credencial_se_rechaza(): void
    {
        $this->enviar($this->envio([$this->clase()], ['ruc' => '20999999999']))->assertStatus(422);

        $this->assertSame(0, LoteClasesExternas::withoutGlobalScopes()->count());
    }

    public function test_reenviar_la_misma_referencia_reemplaza_el_lote_sin_duplicar(): void
    {
        $this->enviar($this->envio([$this->clase(['clase_id' => 'CL-1'])]))->assertStatus(201);

        $this->enviar($this->envio([$this->clase(['clase_id' => 'CL-1']), $this->clase(['clase_id' => 'CL-2'])]))
            ->assertStatus(200)
            ->assertJsonPath('total_clases', 2);

        $this->assertSame(1, LoteClasesExternas::withoutGlobalScopes()->count());
        $this->assertSame(2, ClaseExterna::withoutGlobalScopes()->count());
        $this->assertSame(2, LoteClasesExternas::withoutGlobalScopes()->firstOrFail()->envios);
    }

    public function test_un_lote_aprobado_no_se_puede_reemplazar(): void
    {
        $this->enviar($this->envio([$this->clase()]))->assertStatus(201);
        LoteClasesExternas::withoutGlobalScopes()->update(['estado' => LoteClasesExternas::APROBADO]);

        $this->enviar($this->envio([$this->clase(['clase_id' => 'CL-9'])]))->assertStatus(409);

        $this->assertSame(1, ClaseExterna::withoutGlobalScopes()->count());
    }

    public function test_dni_desconocido_deja_la_clase_observada_sin_bloquear_las_demas(): void
    {
        $respuesta = $this->enviar($this->envio([
            $this->clase(['clase_id' => 'CL-1']),
            $this->clase(['clase_id' => 'CL-2', 'instructora_que_dicto' => ['dni' => '99999999', 'nombre' => 'Desconocida']]),
        ]))->assertStatus(201);

        $respuesta->assertJsonPath('estado', 'observado')
            ->assertJsonPath('total_observadas', 1)
            ->assertJsonPath('clases.0.estado', 'ok')
            ->assertJsonPath('clases.1.estado', 'observada');
    }

    public function test_colaboradores_de_otra_empresa_no_se_identifican(): void
    {
        $otra = Empresa::factory()->create();
        $this->crearColaborador($otra, ['numero_documento' => '33333333']);

        $this->enviar($this->envio([$this->clase(['instructora_titular' => ['dni' => '33333333']])]))
            ->assertJsonPath('clases.0.estado', 'observada');
    }

    public function test_clase_fuera_del_periodo_o_repetida_en_otro_lote_queda_observada(): void
    {
        $this->enviar($this->envio([$this->clase(['clase_id' => 'CL-1'])]))->assertStatus(201);

        $this->enviar($this->envio([
            $this->clase(['clase_id' => 'CL-1']),
            $this->clase(['clase_id' => 'CL-7', 'fecha' => '2026-12-05']),
        ], ['referencia' => 'FIRME-2026-12', 'periodo_inicio' => '2026-12-01', 'periodo_fin' => '2026-12-31']))
            ->assertJsonPath('clases.0.estado', 'observada')
            ->assertJsonPath('clases.1.estado', 'ok');
    }

    public function test_una_clase_dictada_exige_quien_la_dicto(): void
    {
        $clase = $this->clase();
        unset($clase['instructora_que_dicto']);

        $this->enviar($this->envio([$clase]))->assertJsonPath('clases.0.estado', 'observada');
    }

    public function test_una_clase_cancelada_por_el_estudio_no_necesita_ejecutora(): void
    {
        $clase = $this->clase(['estado' => 'cancelada_estudio']);
        unset($clase['instructora_que_dicto']);

        $this->enviar($this->envio([$clase]))->assertStatus(201)->assertJsonPath('clases.0.estado', 'ok');
    }

    public function test_valida_el_formato_del_cuerpo(): void
    {
        $this->enviar($this->envio([$this->clase(['estado' => 'inventado'])]))->assertStatus(422)->assertJsonValidationErrors('clases.0.estado');
        $this->enviar($this->envio([$this->clase(), $this->clase()]))->assertStatus(422)->assertJsonValidationErrors('clases.0.clase_id');
        $this->enviar($this->envio([], ['periodo_fin' => '2026-10-01']))->assertStatus(422);
    }

    public function test_el_uso_de_la_credencial_queda_registrado(): void
    {
        $this->assertNull(CredencialIntegracion::firstOrFail()->ultimo_uso_at);

        $this->enviar($this->envio([$this->clase()]))->assertStatus(201);

        $this->assertNotNull(CredencialIntegracion::firstOrFail()->ultimo_uso_at);
    }
}
