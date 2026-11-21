<?php

namespace Tests\Feature\Modules\Crm;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Permission;
use App\Modules\Configuracion\Models\Role;
use App\Modules\Configuracion\Support\EmpresaContext;
use App\Modules\Crm\Models\CanalWhatsapp;
use App\Modules\Crm\Models\Conversacion;
use App\Modules\Crm\Models\Mensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreaEscenarioSeleccion;
use Tests\TestCase;

/**
 * CRM de WhatsApp: configuración de canales, webhook de Meta (verificación, firma, mensajes y estados),
 * bandeja por empresa y respuesta manual dentro de la ventana de 24 h.
 */
class CrmWhatsappTest extends TestCase
{
    use CreaEscenarioSeleccion, RefreshDatabase;

    private const SECRET = 'app-secret-de-prueba';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeleccion();

        $rol = Role::create(['clave' => 'asesor_crm', 'nombre' => 'Asesor CRM']);
        $rol->permissions()->sync(Permission::query()->where('clave', 'like', 'crm.%')->pluck('id'));
    }

    protected function tearDown(): void
    {
        $this->tearDownCreaEscenarioSeleccion();
        parent::tearDown();
    }

    private function canal(Empresa $empresa, array $atributos = []): CanalWhatsapp
    {
        return EmpresaContext::como($empresa->id, fn () => CanalWhatsapp::query()->create([
            'empresa_id' => $empresa->id,
            'nombre' => 'Ventas',
            'phone_number_id' => (string) random_int(10_000_000, 99_999_999),
            'numero_visible' => '+51 900 000 000',
            'access_token' => 'EAAtoken',
            'app_secret' => self::SECRET,
            'verify_token' => 'verificar-123',
            'webhook_token' => CanalWhatsapp::nuevoWebhookToken(),
            ...$atributos,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function avisoMensaje(CanalWhatsapp $canal, string $id = 'wamid.1', string $texto = 'Hola', string $de = '51987654321'): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '1', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['phone_number_id' => $canal->phone_number_id],
            'contacts' => [['wa_id' => $de, 'profile' => ['name' => 'Ana Torres']]],
            'messages' => [['from' => $de, 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $texto]]],
        ]]]]]];
    }

    private function enviarAviso(CanalWhatsapp $canal, array $payload, ?string $secreto = self::SECRET)
    {
        $cuerpo = json_encode($payload);
        $firma = 'sha256='.hash_hmac('sha256', $cuerpo, (string) $secreto);

        return $this->call('POST', '/api/publico/crm/whatsapp/'.$canal->webhook_token, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $firma,
        ], $cuerpo);
    }

    private function conversacionDe(Empresa $empresa): Conversacion
    {
        return EmpresaContext::como($empresa->id, fn () => Conversacion::query()->firstOrFail());
    }

    private function asesor(Empresa $empresa): User
    {
        return $this->usuarioCon($empresa, 'asesor_crm');
    }

    public function test_la_verificacion_del_webhook_responde_el_challenge_solo_con_el_token_correcto(): void
    {
        $canal = $this->canal($this->crearEmpresa('A'));
        $url = '/api/publico/crm/whatsapp/'.$canal->webhook_token;

        $this->get($url.'?hub.mode=subscribe&hub.verify_token=verificar-123&hub.challenge=xyz')
            ->assertOk()->assertSee('xyz', false);
        $this->get($url.'?hub.mode=subscribe&hub.verify_token=otro&hub.challenge=xyz')->assertForbidden();
        $this->get('/api/publico/crm/whatsapp/'.str_repeat('a', 40).'?hub.mode=subscribe&hub.verify_token=verificar-123&hub.challenge=xyz')->assertForbidden();
    }

    public function test_un_mensaje_entrante_crea_la_conversacion_sin_duplicar_si_meta_reintenta(): void
    {
        $empresa = $this->crearEmpresa('A');
        $canal = $this->canal($empresa);

        $this->enviarAviso($canal, $this->avisoMensaje($canal))->assertOk()->assertJson(['ok' => true]);
        $this->enviarAviso($canal, $this->avisoMensaje($canal))->assertOk();

        $conversacion = $this->conversacionDe($empresa);
        $this->assertSame('51987654321', $conversacion->wa_id);
        $this->assertSame('Ana Torres', $conversacion->nombre_contacto);
        $this->assertSame(1, $conversacion->no_leidos);
        $this->assertTrue($conversacion->ventanaAbierta());
        $this->assertSame(1, EmpresaContext::como($empresa->id, fn () => Mensaje::query()->count()));
        $this->assertSame('Hola', EmpresaContext::como($empresa->id, fn () => Mensaje::query()->value('texto_mensaje')));
    }

    public function test_el_webhook_rechaza_firmas_invalidas_y_avisos_de_otro_numero(): void
    {
        $empresa = $this->crearEmpresa('A');
        $canal = $this->canal($empresa);

        $this->enviarAviso($canal, $this->avisoMensaje($canal), 'otro-secreto')->assertUnauthorized();

        $otroNumero = $this->avisoMensaje($canal);
        $otroNumero['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] = '111111';
        $this->enviarAviso($canal, $otroNumero)->assertOk();

        $this->assertSame(0, EmpresaContext::como($empresa->id, fn () => Conversacion::query()->count()));
    }

    public function test_un_canal_desactivado_no_guarda_mensajes(): void
    {
        $empresa = $this->crearEmpresa('A');
        $canal = $this->canal($empresa, ['activo' => false]);

        $this->enviarAviso($canal, $this->avisoMensaje($canal))->assertOk();

        $this->assertSame(0, EmpresaContext::como($empresa->id, fn () => Conversacion::query()->count()));
    }

    public function test_la_bandeja_solo_muestra_conversaciones_de_la_empresa_del_usuario(): void
    {
        $empresaA = $this->crearEmpresa('A');
        $empresaB = $this->crearEmpresa('B');
        $canalA = $this->canal($empresaA);
        $canalB = $this->canal($empresaB);
        $this->enviarAviso($canalA, $this->avisoMensaje($canalA, 'wamid.a', 'Hola A', '51911111111'))->assertOk();
        $this->enviarAviso($canalB, $this->avisoMensaje($canalB, 'wamid.b', 'Hola B', '51922222222'))->assertOk();

        $headers = $this->cabeceras($this->asesor($empresaA));
        $this->withHeaders($headers)->getJson('/api/crm/conversaciones')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.telefono', '+51911111111');

        $conversacionB = $this->conversacionDe($empresaB);
        $this->withHeaders($headers)->getJson("/api/crm/conversaciones/{$conversacionB->id}/mensajes")->assertNotFound();
        $this->withHeaders($headers)->postJson("/api/crm/conversaciones/{$conversacionB->id}/mensajes", ['texto_mensaje' => 'x'])->assertNotFound();
    }

    public function test_responder_envia_a_meta_y_guarda_el_mensaje_y_su_estado(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.salida']]])]);
        $empresa = $this->crearEmpresa('A');
        $canal = $this->canal($empresa);
        $this->enviarAviso($canal, $this->avisoMensaje($canal))->assertOk();
        $conversacion = $this->conversacionDe($empresa);

        $this->withHeaders($this->cabeceras($this->asesor($empresa)))
            ->postJson("/api/crm/conversaciones/{$conversacion->id}/mensajes", ['texto_mensaje' => 'Hola, ¿en qué te ayudo?'])
            ->assertCreated()->assertJsonPath('data.direccion', 'saliente')->assertJsonPath('data.estado', 'enviado');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/'.$canal->phone_number_id.'/messages')
            && $request['to'] === '51987654321' && $request['text']['body'] === 'Hola, ¿en qué te ayudo?'
            && $request->hasHeader('Authorization', 'Bearer EAAtoken'));

        // Meta avisa la lectura: el estado avanza y no retrocede con un «delivered» tardío.
        foreach (['read', 'delivered'] as $estado) {
            $this->enviarAviso($canal, ['entry' => [['changes' => [['value' => [
                'metadata' => ['phone_number_id' => $canal->phone_number_id],
                'statuses' => [['id' => 'wamid.salida', 'status' => $estado]],
            ]]]]]])->assertOk();
        }

        $this->assertSame('leido', EmpresaContext::como($empresa->id, fn () => Mensaje::query()->where('wa_message_id', 'wamid.salida')->value('estado')));
        $this->assertSame(0, EmpresaContext::como($empresa->id, fn () => $conversacion->fresh()->no_leidos));
    }

    public function test_no_se_responde_fuera_de_la_ventana_de_24_horas(): void
    {
        Http::fake();
        $empresa = $this->crearEmpresa('A');
        $canal = $this->canal($empresa);
        $this->enviarAviso($canal, $this->avisoMensaje($canal))->assertOk();
        $conversacion = $this->conversacionDe($empresa);
        EmpresaContext::como($empresa->id, fn () => $conversacion->update(['ultimo_entrante_at' => now()->subHours(25)]));

        $this->withHeaders($this->cabeceras($this->asesor($empresa)))
            ->postJson("/api/crm/conversaciones/{$conversacion->id}/mensajes", ['texto_mensaje' => 'Hola'])
            ->assertUnprocessable()->assertJsonValidationErrors('texto_mensaje');

        Http::assertNothingSent();
    }

    public function test_configurar_canales_exige_permiso_y_nunca_devuelve_los_secretos(): void
    {
        $empresa = $this->crearEmpresa('A');
        $datos = [
            'nombre' => 'Bravos Urban',
            'phone_number_id' => '1434888073042064',
            'access_token' => 'EAAsecreto1234',
            'app_secret' => 'afab0b55',
            'verify_token' => 'verificar-123',
        ];

        $sinPermiso = $this->usuarioCon($empresa, 'jefe_area');
        $this->withHeaders($this->cabeceras($sinPermiso))->postJson('/api/crm/canales', $datos)->assertForbidden();

        $respuesta = $this->withHeaders($this->cabeceras($this->asesor($empresa)))->postJson('/api/crm/canales', $datos)
            ->assertCreated()
            ->assertJsonPath('data.access_token_final', '…1234')
            ->assertJsonPath('data.verify_token', 'verificar-123');

        $this->assertStringContainsString('/api/publico/crm/whatsapp/', $respuesta->json('data.url_webhook'));
        $this->assertStringNotContainsString('EAAsecreto1234', $respuesta->getContent());
        $this->assertStringNotContainsString('afab0b55', $respuesta->getContent());

        // Editar sin enviar los secretos conserva los guardados.
        $id = $respuesta->json('data.id');
        $this->withHeaders($this->cabeceras($this->asesor($empresa)))
            ->putJson("/api/crm/canales/{$id}", [...$datos, 'access_token' => '', 'app_secret' => null, 'nombre' => 'Bravos'])
            ->assertOk()->assertJsonPath('data.nombre', 'Bravos');
        $canal = EmpresaContext::como($empresa->id, fn () => CanalWhatsapp::query()->findOrFail($id));
        $this->assertSame('EAAsecreto1234', $canal->access_token);
        $this->assertSame((int) $empresa->id, (int) $canal->empresa_id);
    }
}
