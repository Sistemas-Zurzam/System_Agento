<?php

namespace Tests\Feature\Seguridad;

use App\Models\User;
use App\Modules\Auditoria\Models\AuditoriaEvento;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Reclutamiento\Infrastructure\MensajePlano;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LimitesDeAccesoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    }

    private function usuario(string $username = 'maria'): User
    {
        return User::factory()->for(Empresa::factory())->create(['username' => $username, 'email' => $username.'@empresa.test', 'activo' => true]);
    }

    private function intentar(string $login, string $password = 'incorrecta', string $ip = '10.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/login', ['login' => $login, 'password' => $password]);
    }

    public function test_login_correcto_sigue_funcionando(): void
    {
        $this->usuario();

        $this->intentar('maria', 'password')->assertOk()->assertJsonStructure(['access_token', 'token_type', 'expires_in']);
    }

    public function test_limite_por_login_e_ip_devuelve_429_con_retry_after(): void
    {
        config(['seguridad.login.max_fallos' => 1000]);
        $this->usuario();

        for ($i = 0; $i < 5; $i++) {
            $this->intentar('maria')->assertUnauthorized();
        }

        $respuesta = $this->intentar('maria')->assertStatus(429);
        $this->assertNotEmpty($respuesta->headers->get('Retry-After'));
        $this->assertGreaterThan(0, (int) $respuesta->headers->get('Retry-After'));
    }

    public function test_el_limite_por_login_e_ip_no_afecta_a_otra_ip(): void
    {
        config(['seguridad.login.max_fallos' => 1000]);
        $this->usuario();

        for ($i = 0; $i < 5; $i++) {
            $this->intentar('maria', 'incorrecta', '10.0.0.1');
        }
        $this->intentar('maria', 'incorrecta', '10.0.0.1')->assertStatus(429);

        $this->intentar('maria', 'password', '10.0.0.2')->assertOk();
    }

    public function test_limite_por_ip_con_logins_distintos(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->intentar("inexistente{$i}")->assertUnauthorized();
        }

        $this->intentar('inexistente21')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_el_limite_se_libera_pasado_el_tiempo(): void
    {
        config(['seguridad.login.max_fallos' => 1000]);
        $this->usuario();
        for ($i = 0; $i < 6; $i++) {
            $this->intentar('maria');
        }
        $this->intentar('maria', 'password')->assertStatus(429);

        $this->travel(61)->seconds();

        $this->intentar('maria', 'password')->assertOk();
    }

    public function test_los_mensajes_no_revelan_si_el_usuario_existe(): void
    {
        $this->usuario();

        $existente = $this->intentar('maria', 'incorrecta');
        $inexistente = $this->intentar('fantasma', 'incorrecta');

        $existente->assertUnauthorized();
        $inexistente->assertUnauthorized();
        $this->assertSame($existente->json(), $inexistente->json());
    }

    public function test_bloqueo_de_cuenta_tras_fallos_consecutivos_desde_distintas_ips(): void
    {
        $usuario = $this->usuario();

        for ($i = 1; $i <= 5; $i++) {
            $this->intentar('maria', 'incorrecta', "10.0.1.{$i}")->assertUnauthorized();
        }

        // Aun con la clave correcta y otra IP, la cuenta está bloqueada.
        $bloqueada = $this->intentar('maria', 'password', '10.0.9.9')->assertStatus(429);
        $this->assertGreaterThan(0, (int) $bloqueada->headers->get('Retry-After'));

        $evento = AuditoriaEvento::where('accion', 'auth.cuenta_bloqueada')->first();
        $this->assertNotNull($evento, 'El bloqueo debe quedar registrado en Auditoría.');
        $this->assertSame($usuario->id, $evento->user_id);
        $this->assertSame($usuario->empresa_id, $evento->empresa_id);

        // Pasado el tiempo de bloqueo, vuelve a funcionar.
        $this->travel(16)->minutes();
        $this->intentar('maria', 'password', '10.0.9.9')->assertOk();
    }

    public function test_el_bloqueo_responde_igual_para_cuentas_inexistentes(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->intentar('fantasma', 'x', "10.0.2.{$i}")->assertUnauthorized();
        }

        $this->intentar('fantasma', 'x', '10.0.2.99')->assertStatus(429)->assertJson(['message' => 'Demasiados intentos. Espera un momento antes de volver a intentarlo.']);
    }

    public function test_un_login_correcto_reinicia_el_contador_de_fallos(): void
    {
        $this->usuario();

        for ($i = 1; $i <= 4; $i++) {
            $this->intentar('maria', 'incorrecta', "10.0.3.{$i}")->assertUnauthorized();
        }
        $this->intentar('maria', 'password', '10.0.3.50')->assertOk();
        for ($i = 1; $i <= 4; $i++) {
            $this->intentar('maria', 'incorrecta', "10.0.4.{$i}")->assertUnauthorized();
        }

        $this->intentar('maria', 'password', '10.0.4.50')->assertOk();
    }

    public function test_me_y_refresh_tienen_limite_por_usuario(): void
    {
        config(['seguridad.sesion.me_por_minuto' => 2, 'seguridad.sesion.refresh_por_minuto' => 2]);
        $this->usuario();
        $token = $this->intentar('maria', 'password')->json('access_token');

        $this->withToken($token)->getJson('/api/me')->assertOk();
        $this->withToken($token)->getJson('/api/me')->assertOk();
        $this->withToken($token)->getJson('/api/me')->assertStatus(429)->assertHeader('Retry-After');

        $this->travel(61)->seconds();
        // refresh entrega un token nuevo cada vez: el límite es por usuario, no por token.
        $token = $this->withToken($token)->postJson('/api/refresh')->assertOk()->json('access_token');
        $token = $this->withToken($token)->postJson('/api/refresh')->assertOk()->json('access_token');
        $this->withToken($token)->postJson('/api/refresh')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_listado_y_detalle_publicos_tienen_limite_propio(): void
    {
        config(['seguridad.portal.listado_por_minuto' => 3, 'seguridad.portal.detalle_por_minuto' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/publico/empleos')->assertOk();
            $this->getJson('/api/publico/empleos/SEL-2026-00001')->assertNotFound();
        }

        $this->getJson('/api/publico/empleos')->assertStatus(429)->assertHeader('Retry-After');
        $this->getJson('/api/publico/empleos/SEL-2026-00001')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_el_codigo_otp_se_envia_por_cola_sin_cambiar_la_respuesta(): void
    {
        Mail::fake();

        $respuesta = $this->postJson('/api/publico/candidatos/codigo', ['email' => 'rosa@correo.test'])->assertOk();

        Mail::assertQueued(MensajePlano::class, fn (MensajePlano $m) => $m->hasTo('rosa@correo.test') && $m->asunto === 'Tu código de verificación');
        Mail::assertNothingSent();
        $this->assertSame(['message' => $respuesta->json('message')], $respuesta->json());
    }

    public function test_el_mensaje_encolado_va_cifrado_y_en_cola(): void
    {
        $mensaje = new MensajePlano('Asunto', 'Cuerpo con código 123456');

        $this->assertInstanceOf(ShouldQueue::class, $mensaje);
        $this->assertInstanceOf(ShouldBeEncrypted::class, $mensaje);
    }
}
