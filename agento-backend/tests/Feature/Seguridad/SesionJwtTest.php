<?php

namespace Tests\Feature\Seguridad;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El token de acceso dura poco y se renueva con /refresh sin cerrar la sesión.
 */
class SesionJwtTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    }

    private function usuario(): User
    {
        return User::factory()->for(Empresa::factory())->create(['username' => 'sesion', 'activo' => true, 'token_version' => 0]);
    }

    private function login(): array
    {
        return $this->postJson('/api/login', ['login' => 'sesion', 'password' => 'password'])->assertOk()->json();
    }

    public function test_el_token_de_acceso_dura_como_maximo_una_hora_por_defecto(): void
    {
        $this->assertLessThanOrEqual(60, (int) config('jwt.ttl'), 'JWT_TTL por defecto debe ser corto (el frontend renueva).');
        $this->usuario();

        $this->assertSame((int) config('jwt.ttl') * 60, $this->login()['expires_in']);
    }

    public function test_refresh_entrega_un_token_nuevo_con_su_vigencia_y_la_sesion_sigue(): void
    {
        $this->usuario();
        $viejo = $this->login()['access_token'];

        $nuevo = $this->withToken($viejo)->postJson('/api/refresh')->assertOk()->assertJsonStructure(['access_token', 'expires_in']);

        $this->assertNotSame($viejo, $nuevo->json('access_token'));
        $this->assertSame((int) config('jwt.ttl') * 60, $nuevo->json('expires_in'));
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        $this->app->forgetInstance('tymon.jwt.auth');
        $this->withToken($nuevo->json('access_token'))->getJson('/api/me')->assertOk();
    }

    public function test_el_token_anterior_deja_de_valer_tras_renovar(): void
    {
        $this->usuario();
        $viejo = $this->login()['access_token'];
        $this->withToken($viejo)->postJson('/api/refresh')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        $this->app->forgetInstance('tymon.jwt.auth');

        $this->withToken($viejo)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_un_token_vencido_no_se_puede_renovar_y_exige_volver_a_entrar(): void
    {
        $usuario = $this->usuario();
        $token = $this->login()['access_token'];
        $this->travel((int) config('jwt.ttl') + 1)->minutes();
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        $this->app->forgetInstance('tymon.jwt.auth');

        // Por eso el frontend renueva ANTES de que venza (services/sesion.js).
        $this->withToken($token)->postJson('/api/refresh')->assertUnauthorized();
        $this->assertNotNull($usuario);
    }

    public function test_una_sesion_revocada_no_se_puede_renovar(): void
    {
        $usuario = $this->usuario();
        $token = $this->login()['access_token'];
        $usuario->increment('token_version');
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        $this->app->forgetInstance('tymon.jwt.auth');

        $this->withToken($token)->postJson('/api/refresh')->assertUnauthorized();
    }
}
