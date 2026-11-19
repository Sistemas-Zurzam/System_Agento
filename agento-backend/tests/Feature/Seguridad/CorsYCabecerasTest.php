<?php

namespace Tests\Feature\Seguridad;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CorsYCabecerasTest extends TestCase
{
    use RefreshDatabase;

    private const PERMITIDO = 'https://staging.agento.test';

    private const AJENO = 'https://sitio-malicioso.test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['cors.allowed_origins' => [], 'cors.allowed_origins_patterns' => ['#^'.preg_quote(self::PERMITIDO, '#').'$#']]);
    }

    // ---- CORS ----

    public function test_un_origen_permitido_recibe_access_control_allow_origin(): void
    {
        $this->getJson('/api/publico/empleos', ['Origin' => self::PERMITIDO])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', self::PERMITIDO);
    }

    public function test_un_origen_no_permitido_no_recibe_access_control_allow_origin(): void
    {
        $respuesta = $this->getJson('/api/publico/empleos', ['Origin' => self::AJENO]);

        $this->assertNull($respuesta->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_el_preflight_de_un_origen_no_permitido_no_autoriza_nada(): void
    {
        $respuesta = $this->call('OPTIONS', '/api/login', server: [
            'HTTP_ORIGIN' => self::AJENO,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);

        $this->assertNull($respuesta->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($respuesta->headers->get('Access-Control-Allow-Methods'));
    }

    public function test_el_preflight_de_un_origen_permitido_declara_metodos_y_cabeceras_explicitos(): void
    {
        $respuesta = $this->call('OPTIONS', '/api/login', server: [
            'HTTP_ORIGIN' => self::PERMITIDO,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);

        $respuesta->assertSuccessful()->assertHeader('Access-Control-Allow-Origin', self::PERMITIDO);
        $this->assertStringNotContainsString('*', (string) $respuesta->headers->get('Access-Control-Allow-Headers'));
        $this->assertStringNotContainsString('*', (string) $respuesta->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($respuesta->headers->get('Access-Control-Allow-Credentials'));
    }

    public function test_sin_origenes_configurados_ningun_origen_externo_es_permitido(): void
    {
        config(['cors.allowed_origins' => [], 'cors.allowed_origins_patterns' => []]);

        foreach ([self::PERMITIDO, self::AJENO, 'null'] as $origen) {
            $respuesta = $this->getJson('/api/publico/empleos', ['Origin' => $origen]);
            $this->assertNull($respuesta->headers->get('Access-Control-Allow-Origin'), "Origen {$origen}");
        }
    }

    public function test_la_configuracion_por_defecto_no_es_comodin(): void
    {
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertSame(['api/*'], config('cors.paths'));
        $this->assertNotContains('*', config('cors.allowed_headers'));
        $this->assertNotContains('*', config('cors.allowed_methods'));
        $this->assertFalse(config('cors.supports_credentials'));
    }

    // ---- Cabeceras de seguridad ----

    public function test_las_respuestas_incluyen_las_cabeceras_de_seguridad(): void
    {
        foreach (['/api/publico/empleos', '/up'] as $ruta) {
            $this->get($ruta)
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
                ->assertHeader('Permissions-Policy');
        }
    }

    public function test_tambien_las_respuestas_de_error_llevan_las_cabeceras(): void
    {
        $this->getJson('/api/me')->assertUnauthorized()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_hsts_solo_se_envia_por_https(): void
    {
        $this->get('/up')->assertHeaderMissing('Strict-Transport-Security');

        $this->withServerVariables(['HTTPS' => 'on'])->get('https://localhost/up')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_hsts_puede_forzarse_detras_de_un_proxy(): void
    {
        config(['cabeceras.hsts_forzar' => true]);

        $this->get('/up')->assertHeader('Strict-Transport-Security');
    }

    public function test_csp_va_en_modo_report_only_por_defecto(): void
    {
        $respuesta = $this->get('/up');

        $respuesta->assertHeader('Content-Security-Policy-Report-Only');
        $respuesta->assertHeaderMissing('Content-Security-Policy');
        $this->assertStringContainsString('report-uri /api/seguridad/csp-report', $respuesta->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertStringContainsString("default-src 'self'", $respuesta->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_csp_puede_pasar_a_bloqueo_o_apagarse(): void
    {
        config(['cabeceras.csp_modo' => 'enforce']);
        $this->get('/up')->assertHeader('Content-Security-Policy')->assertHeaderMissing('Content-Security-Policy-Report-Only');

        config(['cabeceras.csp_modo' => 'off']);
        $this->get('/up')->assertHeaderMissing('Content-Security-Policy')->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    // ---- Reportes CSP ----

    public function test_el_endpoint_de_reportes_csp_registra_y_responde_204_sin_autenticacion(): void
    {
        Log::shouldReceive('channel')->with('csp')->once()->andReturnSelf();
        Log::shouldReceive('warning')->once()->with('Violación de CSP', \Mockery::on(
            fn (array $contexto) => $contexto['violated-directive'] === "script-src 'self'" && $contexto['blocked-uri'] === 'https://evil.test/x.js'
        ));

        $this->call('POST', '/api/seguridad/csp-report', content: json_encode(['csp-report' => [
            'document-uri' => 'https://agento.test/empleos',
            'violated-directive' => "script-src 'self'",
            'blocked-uri' => 'https://evil.test/x.js?clave=1',
            'campo-ajeno' => 'se descarta',
        ]]), server: ['CONTENT_TYPE' => 'application/csp-report'])->assertNoContent();
    }

    public function test_el_reporte_csp_no_registra_tokens_de_las_urls_ni_consultas(): void
    {
        $capturado = null;
        Log::shouldReceive('channel')->with('csp')->once()->andReturnSelf();
        Log::shouldReceive('warning')->once()->andReturnUsing(function ($mensaje, $contexto) use (&$capturado) {
            $capturado = $contexto;
        });

        $token = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789';
        $this->call('POST', '/api/seguridad/csp-report', content: json_encode(['csp-report' => [
            'document-uri' => "https://agento.test/empleos/prueba/{$token}?x=secreto#frag",
            'violated-directive' => 'img-src',
        ]]), server: ['CONTENT_TYPE' => 'application/csp-report'])->assertNoContent();

        $this->assertSame('https://agento.test/empleos/prueba/[token]', $capturado['document-uri']);
        $this->assertStringNotContainsString($token, json_encode($capturado));
        $this->assertStringNotContainsString('secreto', json_encode($capturado));
    }

    public function test_un_reporte_malformado_tambien_responde_204_sin_registrar_nada(): void
    {
        Log::shouldReceive('channel')->never();

        $this->call('POST', '/api/seguridad/csp-report', content: 'no es json', server: ['CONTENT_TYPE' => 'application/csp-report'])->assertNoContent();
    }

    public function test_el_endpoint_de_reportes_tiene_limite_de_tasa(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->call('POST', '/api/seguridad/csp-report', content: '{}')->assertNoContent();
        }

        $this->call('POST', '/api/seguridad/csp-report', content: '{}')->assertStatus(429);
    }
}
