<?php

namespace Tests\Feature\Modules\Nominas;

use App\Models\User;
use App\Modules\Configuracion\Models\Banco;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\EmpresaCuentaBancaria;
use App\Modules\Configuracion\Models\ParametroLaboralDefinicion;
use App\Modules\Configuracion\Models\ParametroLaboralValor;
use App\Modules\Nominas\Jobs\NotificarEstadoPagoSemanalZazuJob;
use App\Modules\Nominas\Models\PagoSemanalLote;
use App\Modules\Nominas\Services\BoletaService;
use App\Modules\Personas\Models\Colaborador;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Pagos semanales de motorizados enviados por plataforma_zazu: lote aparte del
 * ciclo mensual, identificado por DNI, con retención de 4ta, TXT BBVA y aviso
 * de estado de vuelta a Zazu.
 */
class PagoSemanalZazuTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    private Empresa $empresa;

    private Colaborador $motorizado;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->empresa = Empresa::firstOrFail();

        config([
            'services.zazu.empresa_id' => $this->empresa->id,
            'services.zazu.webhook_secret' => 'secreto',
            'services.zazu.planilla_webhook_url' => 'https://zazu.test/api/webhook/agento/planilla-estado',
        ]);

        $bbva = Banco::where('codigo', 'bbva')->firstOrFail();
        $this->motorizado = $this->crearColaborador($this->empresa, [
            'nombres' => 'Walter Ricardo', 'apellido_paterno' => 'Quispe', 'apellido_materno' => 'Rojas',
            'numero_documento' => '44889923', 'cargo' => 'Motorizado',
            'tipo_contrato' => 'locacion_servicios', 'regimen_laboral' => 'Locacion de Servicios', 'tipo_trabajador' => 'locador',
            'banco_id' => $bbva->id, 'numero_cuenta' => '001101030200698126', 'tipo_cuenta' => 'ahorros', 'moneda_cuenta' => 'PEN',
        ]);
        $this->motorizado->remuneraciones()->update(['salario' => 2000, 'periodicidad_pago' => 'semanal']);

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

    private function lote(array $lineas = []): array
    {
        return [
            'referencia' => 'ZAZU-MOTOS-2026-11-16',
            'semana_inicio' => '2026-11-16',
            'semana_fin' => '2026-11-22',
            'enviado_por' => 'Administrador',
            'lineas' => $lineas ?: [[
                'dni' => '44889923', 'nombre' => 'WALTER RICARDO', 'alias' => 'MOTO-12',
                'dias_trabajados' => 7, 'dias_base' => 6, 'pedidos_entregados' => 150,
                'monto_dias' => 583.33, 'monto_bonos' => 60, 'adicional' => 0, 'descuento' => 0, 'monto_total' => 643.33,
            ]],
        ];
    }

    private function enviar(array $payload, string $clave = 'secreto')
    {
        return $this->postJson('/api/integraciones/zazu/pagos-semanales', $payload, ['X-Api-Key' => $clave]);
    }

    private function headersNominas(): array
    {
        $usuario = User::where('username', 'test.user')->firstOrFail();
        $usuario->update(['empresa_id' => $this->empresa->id]);

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($usuario)];
    }

    public function test_rechaza_sin_clave_valida(): void
    {
        $this->enviar($this->lote(), 'otra')->assertUnauthorized();
        $this->assertSame(0, PagoSemanalLote::count());
    }

    public function test_recibe_el_lote_por_dni_y_lo_reemplaza_al_reenviar(): void
    {
        Queue::fake();

        $this->enviar($this->lote())->assertCreated()
            ->assertJsonPath('estado', 'recibido')
            ->assertJsonPath('lineas.0.dni', '44889923')
            ->assertJsonPath('lineas.0.estado', 'recibido');

        $lote = PagoSemanalLote::firstOrFail();
        $detalle = $lote->detalles()->firstOrFail();
        $this->assertSame($this->motorizado->id, $detalle->colaborador_id);
        // Semanal bajo el umbral de S/ 1500: sin retención.
        $this->assertSame('0.00', $detalle->retencion_4ta);
        $this->assertSame('643.33', $detalle->monto_neto);
        $this->assertNull($detalle->aviso);

        $otra = $this->lote();
        $otra['lineas'][0]['monto_total'] = 600;
        $this->enviar($otra)->assertOk();
        $this->assertSame(1, PagoSemanalLote::count());
        $this->assertSame('600.00', $lote->fresh()->total_bruto);
        $this->assertSame(2, $lote->fresh()->envios);
    }

    public function test_observa_dni_desconocido_y_avisa_nombre_distinto(): void
    {
        Queue::fake();
        $payload = $this->lote([
            ['dni' => '00000000', 'nombre' => 'NADIE', 'monto_total' => 100],
            ['dni' => '44889923', 'nombre' => 'WALTER', 'monto_total' => 500],
            ['dni' => '44889924', 'nombre' => 'X', 'monto_total' => 0],
        ]);
        $payload['lineas'][1]['nombre'] = 'WALTHER';

        $this->enviar($payload)->assertCreated()->assertJsonPath('estado', 'observado');

        $lote = PagoSemanalLote::firstOrFail();
        $this->assertSame('observado', $lote->detalles()->where('numero_documento', '00000000')->value('estado'));
        $walter = $lote->detalles()->where('numero_documento', '44889923')->first();
        $this->assertSame('recibido', $walter->estado);
        $this->assertStringContainsString('no coincide', $walter->aviso);
    }

    public function test_aprueba_genera_txt_paga_y_avisa_a_zazu(): void
    {
        Http::fake(['zazu.test/*' => Http::response(['status' => 'actualizado'])]);
        $this->enviar($this->lote())->assertCreated();
        $lote = PagoSemanalLote::firstOrFail();
        $headers = $this->headersNominas();

        $this->postJson("/api/pagos-semanales/{$lote->id}/aprobar", [], $headers)->assertOk()->assertJsonPath('data.estado', 'aprobado');
        $this->assertSame('001101030200698126', $lote->detalles()->value('numero_cuenta_snapshot'));

        // Ya aprobado: Zazu no puede reemplazarlo.
        $this->enviar($this->lote())->assertStatus(409);

        $txt = $this->post("/api/pagos-semanales/{$lote->id}/bbva-netcash/exportar", [], $headers);
        $txt->assertOk();
        $this->assertStringContainsString('BBVA_NETCASH_', $txt->headers->get('Content-Disposition'));
        $this->assertStringContainsString('_4TA_2026_S47.txt', $txt->headers->get('Content-Disposition'));
        $this->assertStringContainsString('44889923', $txt->getContent());

        $this->postJson("/api/pagos-semanales/{$lote->id}/pagar", [], $headers)->assertOk()->assertJsonPath('data.estado', 'pagado');

        Http::assertSent(fn ($request) => $request->url() === 'https://zazu.test/api/webhook/agento/planilla-estado'
            && $request->header('X-Api-Key')[0] === 'secreto'
            && $request['referencia'] === 'ZAZU-MOTOS-2026-11-16'
            && $request['estado'] === 'pagado'
            && $request['lineas'][0]['dni'] === '44889923'
            && $request['lineas'][0]['estado'] === 'pagado');
    }

    public function test_aprueba_solo_las_lineas_validas_y_el_txt_excluye_las_observadas(): void
    {
        Queue::fake();
        $this->enviar($this->lote([
            ['dni' => '44889923', 'nombre' => 'WALTER RICARDO', 'monto_total' => 500],
            ['dni' => '00000000', 'nombre' => 'NADIE', 'monto_total' => 100],
        ]))->assertCreated()->assertJsonPath('estado', 'observado');
        $lote = PagoSemanalLote::firstOrFail();
        $headers = $this->headersNominas();

        $this->getJson("/api/pagos-semanales/{$lote->id}", $headers)->assertOk()
            ->assertJsonPath('data.validos', 1)->assertJsonPath('data.neto_a_pagar', 500);

        $this->postJson("/api/pagos-semanales/{$lote->id}/aprobar", [], $headers)->assertOk()->assertJsonPath('data.estado', 'aprobado');
        $this->assertSame('aprobado', $lote->detalles()->where('numero_documento', '44889923')->value('estado'));
        $this->assertSame('observado', $lote->detalles()->where('numero_documento', '00000000')->value('estado'));

        $txt = $this->post("/api/pagos-semanales/{$lote->id}/bbva-netcash/exportar", [], $headers)->assertOk()->getContent();
        $this->assertStringContainsString('44889923', $txt);
        // Una sola línea de detalle (tipo 002): la observada no entra.
        $this->assertSame(1, substr_count($txt, "\n002"));

        $this->postJson("/api/pagos-semanales/{$lote->id}/pagar", [], $headers)->assertOk();
        $this->assertSame('observado', $lote->detalles()->where('numero_documento', '00000000')->value('estado'));
    }

    public function test_no_aprueba_si_todas_las_lineas_estan_observadas_y_observar_avisa_a_zazu(): void
    {
        Queue::fake();
        $this->enviar($this->lote([['dni' => '00000000', 'nombre' => 'NADIE', 'monto_total' => 100]]))->assertCreated();
        $lote = PagoSemanalLote::firstOrFail();
        $lote->update(['estado' => 'recibido']);
        $headers = $this->headersNominas();

        $this->postJson("/api/pagos-semanales/{$lote->id}/aprobar", [], $headers)->assertUnprocessable();
        $this->postJson("/api/pagos-semanales/{$lote->id}/observar", ['observacion' => 'DNI 00000000 no existe'], $headers)
            ->assertOk()->assertJsonPath('data.estado', 'observado');

        Queue::assertPushed(NotificarEstadoPagoSemanalZazuJob::class);
    }

    public function test_filtra_los_lotes_por_semana_y_estado(): void
    {
        Queue::fake();
        $this->enviar($this->lote())->assertCreated();
        $otra = [...$this->lote(), 'referencia' => 'ZAZU-MOTOS-2026-11-09', 'semana_inicio' => '2026-11-09', 'semana_fin' => '2026-11-15'];
        $this->enviar($otra)->assertCreated();
        $headers = $this->headersNominas();

        $this->getJson('/api/pagos-semanales', $headers)->assertOk()->assertJsonCount(2, 'data');
        // Cualquier día de la semana encuentra su lote (miércoles 18 → semana del 16).
        $this->getJson('/api/pagos-semanales?semana=2026-11-18', $headers)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.semana_inicio', '2026-11-16');
        $this->getJson('/api/pagos-semanales?estado=pagado', $headers)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_los_pagados_por_semana_no_entran_al_ciclo_mensual(): void
    {
        $mensual = $this->crearColaborador($this->empresa);

        $elegibles = app(BoletaService::class)->colaboradoresElegibles($this->empresa, '2026-11-01', '2026-11-30')->pluck('id');

        $this->assertContains($mensual->id, $elegibles);
        $this->assertNotContains($this->motorizado->id, $elegibles);
    }
}
