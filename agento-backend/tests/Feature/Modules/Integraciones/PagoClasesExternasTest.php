<?php

namespace Tests\Feature\Modules\Integraciones;

use App\Models\User;
use App\Modules\Configuracion\Models\Banco;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\EmpresaCuentaBancaria;
use App\Modules\Configuracion\Models\ParametroLaboralDefinicion;
use App\Modules\Configuracion\Models\ParametroLaboralValor;
use App\Modules\Configuracion\Models\Permission;
use App\Modules\Configuracion\Models\Role;
use App\Modules\Integraciones\Application\CredencialesIntegracion;
use App\Modules\Integraciones\Models\ClaseExterna;
use App\Modules\Integraciones\Models\CredencialIntegracion;
use App\Modules\Integraciones\Models\LoteClasesExternas;
use App\Modules\Integraciones\Models\PagoClasesExternas;
use App\Modules\Integraciones\Models\TarifaClase;
use App\Modules\Personas\Models\Colaborador;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Fases 2 a 5 de Firme Studio: tarifas con historial, cálculo por clase (suplencia a tarifa de la titular),
 * retención de 4ta, aprobación con cuentas congeladas, pago y TXT BBVA.
 */
class PagoClasesExternasTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    private Empresa $empresa;

    private string $token;

    private Colaborador $luchita;

    private Colaborador $pepita;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->empresa = Empresa::firstOrFail();
        $this->empresa->update(['pago_por_clases_habilitado' => true]);
        $this->token = app(CredencialesIntegracion::class)->crear($this->empresa->id, CredencialIntegracion::FIRME_STUDIO, 'Firme')['token'];

        $bbva = Banco::where('codigo', 'bbva')->firstOrFail();
        $this->luchita = $this->instructora('11111111', 'Luchita', '001101030200698126', $bbva);
        $this->pepita = $this->instructora('22222222', 'Pepita', '001101030200698127', $bbva);

        foreach (['renta_4ta_tasa' => 8, 'renta_4ta_umbral' => 1500] as $clave => $valor) {
            ParametroLaboralValor::create([
                'empresa_id' => $this->empresa->id,
                'definicion_id' => ParametroLaboralDefinicion::where('clave', $clave)->value('id'),
                'regimen_laboral' => 'Locacion de Servicios',
                'vigencia_desde' => '2026-01-01',
                'valor' => $valor,
                'motivo' => 'Configuración de prueba',
            ]);
        }

        EmpresaCuentaBancaria::create([
            'empresa_id' => $this->empresa->id, 'banco_id' => $bbva->id, 'tipo_cuenta' => 'corriente', 'moneda' => 'PEN',
            'numero_cuenta' => '0011057901000123', 'uso' => 'haberes', 'es_predeterminada' => true, 'activo' => true,
        ]);
    }

    private function instructora(string $dni, string $nombre, string $cuenta, Banco $banco): Colaborador
    {
        $c = $this->crearColaborador($this->empresa, [
            'nombres' => $nombre, 'apellido_paterno' => 'Prueba', 'apellido_materno' => 'Firme', 'numero_documento' => $dni,
            'cargo' => 'Instructora', 'tipo_contrato' => 'locacion_servicios', 'regimen_laboral' => 'Locacion de Servicios',
            'tipo_trabajador' => 'locador', 'banco_id' => $banco->id, 'numero_cuenta' => $cuenta, 'tipo_cuenta' => 'ahorros', 'moneda_cuenta' => 'PEN',
        ]);
        $c->remuneraciones()->update(['salario' => 0, 'periodicidad_pago' => 'semanal']);

        return $c;
    }

    private function headers(): array
    {
        $usuario = User::where('username', 'test.user')->firstOrFail();
        $usuario->update(['empresa_id' => $this->empresa->id]);

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($usuario)];
    }

    /** Usuario común de la empresa (NO administrador global) con todos los permisos: ejercita el aislamiento por empresa. */
    private function headersUsuarioComun(): array
    {
        $rol = Role::create(['clave' => 'prueba_clases', 'nombre' => 'Prueba clases']);
        $rol->permissions()->sync(Permission::query()->pluck('id'));
        $usuario = User::factory()->create(['empresa_id' => $this->empresa->id, 'activo' => true, 'token_version' => 0]);
        $usuario->empresas()->updateExistingPivot($this->empresa->id, ['role_id' => $rol->id]);
        $usuario->refresh();
        $this->assertFalse($usuario->esAdministradorGlobal());

        JWTAuth::unsetToken();
        app('tymon.jwt')->unsetToken();
        Auth::forgetGuards();

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($usuario)];
    }

    private function tarifa(Colaborador $c, string $monto, string $desde = '2026-01-01'): void
    {
        TarifaClase::create(['empresa_id' => $this->empresa->id, 'colaborador_id' => $c->id, 'monto' => $monto, 'vigencia_desde' => $desde]);
    }

    private function clase(string $id, string $titular, string $dicto, string $estado = 'dictada', string $fecha = '2026-11-10'): array
    {
        $dni = ['luchita' => '11111111', 'pepita' => '22222222'];
        $clase = [
            'clase_id' => $id, 'fecha' => $fecha, 'hora_inicio' => '07:00', 'hora_fin' => '08:00', 'estado' => $estado,
            'instructora_titular' => ['dni' => $dni[$titular], 'nombre' => ucfirst($titular)],
        ];
        if ($estado === 'dictada') {
            $clase['instructora_que_dicto'] = ['dni' => $dni[$dicto], 'nombre' => ucfirst($dicto)];
        }

        return $clase;
    }

    private function recibir(array $clases, string $referencia = 'FIRME-2026-11'): LoteClasesExternas
    {
        $this->withHeaders(['X-Api-Key' => $this->token])->postJson('/api/integraciones/firme-studio/clases', [
            'referencia' => $referencia, 'periodo_inicio' => '2026-11-01', 'periodo_fin' => '2026-11-30', 'clases' => $clases,
        ])->assertSuccessful();

        return LoteClasesExternas::where('referencia', $referencia)->firstOrFail();
    }

    private function loteBasico(): LoteClasesExternas
    {
        $this->tarifa($this->luchita, '80.00');
        $this->tarifa($this->pepita, '60.00');

        return $this->recibir([
            $this->clase('CL-1', 'luchita', 'pepita'),   // suplencia: Pepita cobra 80
            $this->clase('CL-2', 'pepita', 'pepita'),    // propia: 60
            $this->clase('CL-3', 'luchita', 'luchita', 'cancelada_estudio'),
        ]);
    }

    // ----- Fase 2: tarifas -----

    public function test_registra_una_tarifa_y_conserva_el_historial(): void
    {
        $h = $this->headers();

        $this->postJson('/api/clases-externas/tarifas', ['colaborador_id' => $this->luchita->id, 'monto' => 80, 'vigencia_desde' => '2026-01-01'], $h)->assertCreated();
        $this->postJson('/api/clases-externas/tarifas', ['colaborador_id' => $this->luchita->id, 'monto' => 90, 'vigencia_desde' => '2026-12-01'], $h)->assertCreated();

        $this->getJson('/api/clases-externas/tarifas?colaborador_id='.$this->luchita->id, $h)->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.monto', 90)
            ->assertJsonPath('data.1.monto', 80);
    }

    public function test_no_permite_dos_tarifas_con_la_misma_vigencia(): void
    {
        $h = $this->headers();
        $this->postJson('/api/clases-externas/tarifas', ['colaborador_id' => $this->luchita->id, 'monto' => 80, 'vigencia_desde' => '2026-01-01'], $h)->assertCreated();

        $this->postJson('/api/clases-externas/tarifas', ['colaborador_id' => $this->luchita->id, 'monto' => 99, 'vigencia_desde' => '2026-01-01'], $h)
            ->assertStatus(422)->assertJsonValidationErrors('vigencia_desde');
        $this->assertSame(1, TarifaClase::count());
    }

    public function test_no_registra_tarifas_si_la_empresa_no_habilito_el_pago_por_clases(): void
    {
        $this->empresa->update(['pago_por_clases_habilitado' => false]);

        $this->postJson('/api/clases-externas/tarifas', ['colaborador_id' => $this->luchita->id, 'monto' => 80, 'vigencia_desde' => '2026-01-01'], $this->headers())
            ->assertStatus(422);

        $this->assertSame(0, TarifaClase::count());
    }

    public function test_el_usuario_y_la_empresa_exponen_si_el_pago_por_clases_esta_habilitado(): void
    {
        $this->getJson('/api/me', $this->headers())->assertOk()->assertJsonPath('data.empresa.pago_por_clases_habilitado', true);
    }

    public function test_no_acepta_tarifas_invalidas(): void
    {
        $h = $this->headers();

        $this->postJson('/api/clases-externas/tarifas', ['colaborador_id' => $this->luchita->id, 'monto' => 0, 'vigencia_desde' => '2026-01-01'], $h)->assertStatus(422);
        $this->postJson('/api/clases-externas/tarifas', ['colaborador_id' => $this->luchita->id, 'monto' => 10, 'vigencia_desde' => 'ayer'], $h)->assertStatus(422);
    }

    public function test_no_se_registra_tarifa_de_un_colaborador_de_otra_empresa(): void
    {
        $ajena = $this->crearColaborador(Empresa::factory()->create());

        $this->postJson('/api/clases-externas/tarifas', ['colaborador_id' => $ajena->id, 'monto' => 80, 'vigencia_desde' => '2026-01-01'], $this->headersUsuarioComun())
            ->assertForbidden();

        $this->assertSame(0, TarifaClase::withoutGlobalScopes()->count());
    }

    public function test_exige_permiso_para_gestionar_tarifas(): void
    {
        $this->postJson('/api/clases-externas/tarifas', ['colaborador_id' => $this->luchita->id, 'monto' => 80, 'vigencia_desde' => '2026-01-01'])->assertStatus(401);
    }

    // ----- Fase 3: cálculo -----

    public function test_calcula_la_suplencia_con_la_tarifa_de_la_titular(): void
    {
        $lote = $this->loteBasico();

        $this->postJson("/api/clases-externas/lotes/{$lote->id}/calcular", [], $this->headers())->assertOk();

        $pago = PagoClasesExternas::where('lote_id', $lote->id)->where('colaborador_id', $this->pepita->id)->firstOrFail();
        $this->assertSame(2, $pago->clases_pagadas);
        $this->assertSame('140.00', $pago->monto_bruto);   // 80 (suplencia a tarifa de Luchita) + 60 (propia)
        $this->assertSame('0.00', $pago->retencion_4ta);
        $this->assertSame('140.00', $pago->monto_neto);
        $this->assertSame(PagoClasesExternas::RECIBIDO, $pago->estado);
        $this->assertSame(1, PagoClasesExternas::where('lote_id', $lote->id)->count(), 'Luchita no dictó ninguna clase pagable');

        $suplida = ClaseExterna::where('clase_id', 'CL-1')->firstOrFail();
        $this->assertSame('80.00', $suplida->tarifa_aplicada);
        $this->assertSame('0.00', ClaseExterna::where('clase_id', 'CL-3')->firstOrFail()->monto);
        $this->assertSame('140.00', $lote->fresh()->total_bruto);
    }

    public function test_usa_la_tarifa_vigente_a_la_fecha_de_la_clase(): void
    {
        $this->tarifa($this->luchita, '80.00', '2026-01-01');
        $this->tarifa($this->luchita, '95.00', '2026-11-15');
        $lote = $this->recibir([
            $this->clase('CL-1', 'luchita', 'luchita', 'dictada', '2026-11-10'),  // 80
            $this->clase('CL-2', 'luchita', 'luchita', 'dictada', '2026-11-20'),  // 95
        ]);

        $this->postJson("/api/clases-externas/lotes/{$lote->id}/calcular", [], $this->headers())->assertOk();

        $this->assertSame('175.00', PagoClasesExternas::where('lote_id', $lote->id)->firstOrFail()->monto_bruto);
    }

    public function test_una_clase_sin_tarifa_de_la_titular_queda_observada_y_se_corrige_al_cargarla(): void
    {
        $this->tarifa($this->pepita, '60.00');   // Luchita (titular) SIN tarifa
        $lote = $this->recibir([$this->clase('CL-1', 'luchita', 'pepita'), $this->clase('CL-2', 'pepita', 'pepita')]);

        $this->postJson("/api/clases-externas/lotes/{$lote->id}/calcular", [], $this->headers())->assertOk()
            ->assertJsonPath('data.estado', 'observado');
        $this->assertSame('60.00', PagoClasesExternas::firstOrFail()->monto_bruto);
        $this->assertStringContainsString('tarifa', ClaseExterna::where('clase_id', 'CL-1')->firstOrFail()->observacion);

        $this->tarifa($this->luchita, '80.00');
        $this->postJson("/api/clases-externas/lotes/{$lote->id}/calcular", [], $this->headers())->assertOk()
            ->assertJsonPath('data.estado', 'recibido');
        $this->assertSame('140.00', PagoClasesExternas::firstOrFail()->monto_bruto);
        $this->assertNull(ClaseExterna::where('clase_id', 'CL-1')->firstOrFail()->observacion);
    }

    public function test_una_instructora_con_periodicidad_mensual_queda_observada_para_no_cobrar_dos_veces(): void
    {
        $this->pepita->remuneraciones()->update(['periodicidad_pago' => 'mensual']);
        $lote = $this->loteBasico();

        $this->postJson("/api/clases-externas/lotes/{$lote->id}/calcular", [], $this->headers())->assertOk();

        $pago = PagoClasesExternas::firstOrFail();
        $this->assertSame(PagoClasesExternas::OBSERVADO, $pago->estado);
        $this->assertStringContainsString('semanal', $pago->observacion);
    }

    public function test_una_instructora_sin_cuenta_bancaria_queda_observada(): void
    {
        $this->pepita->update(['banco_id' => null, 'numero_cuenta' => null, 'cci' => null]);
        $lote = $this->loteBasico();

        $this->postJson("/api/clases-externas/lotes/{$lote->id}/calcular", [], $this->headers())->assertOk();

        $this->assertStringContainsString('cuenta bancaria', PagoClasesExternas::firstOrFail()->observacion);
    }

    // ----- Fase 5: retención de 4ta -----

    public function test_retiene_4ta_cuando_el_bruto_supera_el_umbral(): void
    {
        $this->tarifa($this->pepita, '800.00');
        $lote = $this->recibir([$this->clase('CL-1', 'pepita', 'pepita'), $this->clase('CL-2', 'pepita', 'pepita')]);

        $this->postJson("/api/clases-externas/lotes/{$lote->id}/calcular", [], $this->headers())->assertOk();

        $pago = PagoClasesExternas::firstOrFail();
        $this->assertSame('1600.00', $pago->monto_bruto);
        $this->assertSame('128.00', $pago->retencion_4ta);
        $this->assertSame('1472.00', $pago->monto_neto);
        $this->assertSame('128.00', $lote->fresh()->total_retencion);
    }

    public function test_no_retiene_con_suspension_de_retenciones(): void
    {
        $this->pepita->update(['tiene_suspension_renta_4ta' => true]);
        $this->tarifa($this->pepita, '800.00');
        $lote = $this->recibir([$this->clase('CL-1', 'pepita', 'pepita'), $this->clase('CL-2', 'pepita', 'pepita')]);

        $this->postJson("/api/clases-externas/lotes/{$lote->id}/calcular", [], $this->headers())->assertOk();

        $this->assertSame('0.00', PagoClasesExternas::firstOrFail()->retencion_4ta);
        $this->assertSame('1600.00', PagoClasesExternas::firstOrFail()->monto_neto);
    }

    // ----- Fase 4: aprobar, pagar, TXT -----

    public function test_aprueba_congela_la_cuenta_y_marca_el_pago(): void
    {
        $lote = $this->loteBasico();
        $h = $this->headers();

        $this->postJson("/api/clases-externas/lotes/{$lote->id}/aprobar", [], $h)->assertOk()->assertJsonPath('data.estado', 'aprobado');

        $pago = PagoClasesExternas::firstOrFail();
        $this->assertSame(PagoClasesExternas::APROBADO, $pago->estado);
        $this->assertSame('001101030200698127', $pago->numero_cuenta_snapshot);

        // Cambiar la cuenta después NO altera lo congelado.
        $this->pepita->update(['numero_cuenta' => '999999999999999999']);
        $this->assertSame('001101030200698127', $pago->fresh()->numero_cuenta_snapshot);

        $this->postJson("/api/clases-externas/lotes/{$lote->id}/pagar", ['referencia_pago' => 'OP-123'], $h)->assertOk()
            ->assertJsonPath('data.estado', 'pagado')->assertJsonPath('data.referencia_pago', 'OP-123');
        $this->assertSame(PagoClasesExternas::PAGADO, $pago->fresh()->estado);
    }

    public function test_no_aprueba_si_no_hay_pagos_validos(): void
    {
        $this->pepita->remuneraciones()->update(['periodicidad_pago' => 'mensual']);
        $lote = $this->loteBasico();

        $this->postJson("/api/clases-externas/lotes/{$lote->id}/aprobar", [], $this->headers())->assertStatus(422);
        $this->assertSame(LoteClasesExternas::OBSERVADO, $lote->fresh()->estado);
    }

    public function test_un_lote_aprobado_no_se_recalcula_ni_se_reemplaza(): void
    {
        $lote = $this->loteBasico();
        $h = $this->headers();
        $this->postJson("/api/clases-externas/lotes/{$lote->id}/aprobar", [], $h)->assertOk();

        $this->postJson("/api/clases-externas/lotes/{$lote->id}/calcular", [], $h)->assertStatus(422);
        $this->withHeaders(['X-Api-Key' => $this->token])->postJson('/api/integraciones/firme-studio/clases', [
            'referencia' => 'FIRME-2026-11', 'periodo_inicio' => '2026-11-01', 'periodo_fin' => '2026-11-30',
            'clases' => [$this->clase('CL-9', 'pepita', 'pepita')],
        ])->assertStatus(409);
    }

    public function test_no_se_paga_un_lote_sin_aprobar(): void
    {
        $lote = $this->loteBasico();

        $this->postJson("/api/clases-externas/lotes/{$lote->id}/pagar", [], $this->headers())->assertStatus(422);
    }

    public function test_anula_el_lote_con_motivo(): void
    {
        $lote = $this->loteBasico();
        $h = $this->headers();

        $this->postJson("/api/clases-externas/lotes/{$lote->id}/anular", [], $h)->assertStatus(422);
        $this->postJson("/api/clases-externas/lotes/{$lote->id}/anular", ['motivo' => 'Enviado por error'], $h)->assertOk()
            ->assertJsonPath('data.estado', 'anulado');
    }

    public function test_genera_el_txt_bbva_solo_de_lotes_aprobados(): void
    {
        $lote = $this->loteBasico();
        $h = $this->headers();

        $this->postJson("/api/clases-externas/lotes/{$lote->id}/bbva-netcash/exportar", [], $h)->assertStatus(422);

        $this->postJson("/api/clases-externas/lotes/{$lote->id}/aprobar", [], $h)->assertOk();
        $respuesta = $this->post("/api/clases-externas/lotes/{$lote->id}/bbva-netcash/exportar", [], $h);

        $respuesta->assertOk();
        $this->assertStringContainsString('.txt', (string) $respuesta->headers->get('Content-Disposition'));
        // Una sola línea de detalle: el DNI de Pepita y su neto (140.00 → 14000 céntimos). BBVA reformatea la cuenta.
        $contenido = $respuesta->getContent();
        $this->assertStringContainsString('22222222', $contenido);
        $this->assertStringContainsString('000000000014000', $contenido);
        $this->assertStringNotContainsString('11111111', $contenido, 'Luchita no tiene pago en este lote');
    }

    public function test_el_listado_y_el_detalle_muestran_pagos_y_clases(): void
    {
        $lote = $this->loteBasico();
        $h = $this->headers();
        $this->postJson("/api/clases-externas/lotes/{$lote->id}/calcular", [], $h)->assertOk();

        $this->getJson('/api/clases-externas/lotes', $h)->assertOk()->assertJsonPath('data.0.referencia', 'FIRME-2026-11');
        $this->getJson("/api/clases-externas/lotes/{$lote->id}", $h)->assertOk()
            ->assertJsonCount(1, 'data.pagos')
            ->assertJsonCount(3, 'data.clases')
            ->assertJsonPath('data.clases.0.suplencia', true);
    }

    public function test_un_lote_de_otra_empresa_no_es_accesible_ni_aparece_en_el_listado(): void
    {
        $otra = Empresa::factory()->create();
        $ajeno = LoteClasesExternas::withoutGlobalScopes()->create([
            'empresa_id' => $otra->id, 'proveedor' => 'firme_studio', 'referencia' => 'AJENO',
            'periodo_inicio' => '2026-11-01', 'periodo_fin' => '2026-11-30',
        ]);
        $propio = $this->loteBasico();
        $h = $this->headersUsuarioComun();

        foreach (['get' => '', 'post' => '/calcular'] as $metodo => $sufijo) {
            $estado = $this->json($metodo, "/api/clases-externas/lotes/{$ajeno->id}{$sufijo}", [], $h)->getStatusCode();
            $this->assertContains($estado, [403, 404], "{$metodo} lote ajeno → {$estado}");
        }
        foreach (['aprobar', 'pagar', 'anular', 'bbva-netcash/exportar'] as $accion) {
            $estado = $this->postJson("/api/clases-externas/lotes/{$ajeno->id}/{$accion}", ['motivo' => 'x'], $h)->getStatusCode();
            $this->assertContains($estado, [403, 404], "{$accion} lote ajeno → {$estado}");
        }

        $this->getJson('/api/clases-externas/lotes', $h)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $propio->id);
    }
}
