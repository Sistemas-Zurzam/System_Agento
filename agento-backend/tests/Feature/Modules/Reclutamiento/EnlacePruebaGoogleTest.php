<?php

namespace Tests\Feature\Modules\Reclutamiento;

use App\Models\User;
use App\Modules\Reclutamiento\Models\PruebaAsignacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreaEscenarioSeleccion;
use Tests\TestCase;

/**
 * Enlace compartido de una prueba: quien lo abre inicia sesión con Google y
 * queda registrado su correo; cada correo tiene un único intento.
 */
class EnlacePruebaGoogleTest extends TestCase
{
    use CreaEscenarioSeleccion, RefreshDatabase;

    private const CLIENT_ID = 'cliente-123.apps.googleusercontent.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeleccion();
        config(['services.google.client_id' => self::CLIENT_ID]);
    }

    /**
     * @return array{rys: User, headers: array, prueba: array}
     */
    private function pruebaPublicada(): array
    {
        $rys = $this->usuarioCon($this->crearEmpresa('Cliente'), 'talento_cultura');
        $headers = $this->cabeceras($rys);
        $archivo = UploadedFile::fake()->createWithContent('banco.json', json_encode([
            'nombre' => 'Conocimientos de caja',
            'tipo' => 'conocimientos',
            'escala' => ['min' => 1, 'max' => 5, 'etiquetas' => ['1' => 'Nada', '2' => 'Poco', '3' => 'Regular', '4' => 'Bastante', '5' => 'Mucho']],
            'dimensiones' => [['codigo' => 'CAJ', 'nombre' => 'Caja']],
            'preguntas' => [
                ['id' => 1, 'dimension' => 'CAJ', 'texto' => 'Sé cuadrar una caja.', 'invertida' => false],
                ['id' => 2, 'dimension' => 'CAJ', 'texto' => 'Sé emitir comprobantes.', 'invertida' => false],
            ],
        ]));
        $vista = $this->withHeaders($headers)->post('/api/seleccion/pruebas/importar', ['archivo' => $archivo], ['Accept' => 'application/json'])->assertOk()->json('data');
        $prueba = $this->withHeaders($headers)->postJson('/api/seleccion/pruebas', $vista['contenido'])->assertCreated()->json('data');

        return ['rys' => $rys, 'headers' => $headers, 'prueba' => $prueba];
    }

    private function publicar(array $headers, array $prueba): void
    {
        $this->withHeaders($headers)->postJson("/api/seleccion/pruebas/{$prueba['id']}/publicar")->assertOk();
    }

    private function fingirGoogle(string $email = 'ana@gmail.com', array $extra = []): void
    {
        // Un fake nuevo no reemplaza al anterior: se parte de un cliente limpio.
        Http::swap(new HttpFactory);
        Http::fake(['oauth2.googleapis.com/*' => Http::response([
            'aud' => self::CLIENT_ID, 'iss' => 'https://accounts.google.com', 'sub' => '1122',
            'email' => $email, 'email_verified' => 'true', 'name' => 'Ana Torres', ...$extra,
        ])]);
    }

    private function tokenDelEnlace(string $url): string
    {
        return basename($url);
    }

    public function test_solo_se_comparte_el_enlace_de_una_prueba_publicada(): void
    {
        ['headers' => $headers, 'prueba' => $prueba] = $this->pruebaPublicada();

        $this->withHeaders($headers)->postJson("/api/seleccion/pruebas/{$prueba['id']}/enlace")->assertUnprocessable();

        $this->publicar($headers, $prueba);
        $url = $this->withHeaders($headers)->postJson("/api/seleccion/pruebas/{$prueba['id']}/enlace")
            ->assertCreated()->assertJsonPath('data.activo', true)->json('data.url');
        $this->assertStringContainsString('/empleos/evaluacion/', $url);

        // El enlace es estable: pedirlo de nuevo devuelve el mismo.
        $this->assertSame($url, $this->withHeaders($headers)->postJson("/api/seleccion/pruebas/{$prueba['id']}/enlace")->json('data.url'));
    }

    public function test_con_google_se_registra_quien_rinde_y_retoma_su_unico_intento(): void
    {
        ['headers' => $headers, 'prueba' => $prueba] = $this->pruebaPublicada();
        $this->publicar($headers, $prueba);
        $token = $this->tokenDelEnlace($this->withHeaders($headers)->postJson("/api/seleccion/pruebas/{$prueba['id']}/enlace")->json('data.url'));

        $this->getJson("/api/publico/evaluaciones/{$token}")->assertOk()->assertJsonPath('data.nombre', 'Conocimientos de caja');

        $this->fingirGoogle();
        $acceso = $this->postJson("/api/publico/evaluaciones/{$token}/acceso", ['credencial' => 'jwt'])
            ->assertOk()->assertJsonPath('data.email', 'ana@gmail.com')->json('data');

        $this->getJson("/api/publico/pruebas/{$acceso['token']}")->assertOk();
        $this->postJson("/api/publico/pruebas/{$acceso['token']}/iniciar")->assertOk();

        // Volver a entrar con la misma cuenta reutiliza la misma asignación.
        $otro = $this->postJson("/api/publico/evaluaciones/{$token}/acceso", ['credencial' => 'jwt'])->assertOk()->json('data.token');
        $this->assertSame(1, PruebaAsignacion::withoutGlobalScopes()->count());
        $this->getJson("/api/publico/pruebas/{$acceso['token']}")->assertNotFound();
        $this->getJson("/api/publico/pruebas/{$otro}")->assertOk()->assertJsonPath('data.intento.finalizado', false);

        $this->postJson("/api/publico/pruebas/{$otro}/finalizar")->assertOk();
        $this->postJson("/api/publico/evaluaciones/{$token}/acceso", ['credencial' => 'jwt'])->assertOk();
        $this->assertSame(PruebaAsignacion::FINALIZADA, PruebaAsignacion::withoutGlobalScopes()->first()->estado);

        // RR. HH. ve quién la rindió.
        $participantes = $this->withHeaders($headers)->getJson("/api/seleccion/pruebas/{$prueba['id']}/enlace")->assertOk()->json('data.participantes');
        $this->assertCount(1, $participantes);
        $this->assertSame('ana@gmail.com', $participantes[0]['email']);
        $this->assertSame('finalizada', $participantes[0]['estado']);
        $this->assertSame('Conocimientos de caja', $participantes[0]['asignacion']['prueba']['nombre']);
        $this->assertNotEmpty($participantes[0]['asignacion']['resultado']['dimensiones'], 'RR. HH. ve el resultado por dimensión');
    }

    public function test_dos_cuentas_distintas_tienen_cada_una_su_intento(): void
    {
        ['headers' => $headers, 'prueba' => $prueba] = $this->pruebaPublicada();
        $this->publicar($headers, $prueba);
        $token = $this->tokenDelEnlace($this->withHeaders($headers)->postJson("/api/seleccion/pruebas/{$prueba['id']}/enlace")->json('data.url'));

        $this->fingirGoogle('ana@gmail.com');
        $this->postJson("/api/publico/evaluaciones/{$token}/acceso", ['credencial' => 'jwt'])->assertOk();
        $this->fingirGoogle('luis@gmail.com');
        $this->postJson("/api/publico/evaluaciones/{$token}/acceso", ['credencial' => 'jwt'])->assertOk();

        $this->assertSame(2, PruebaAsignacion::withoutGlobalScopes()->count());
    }

    public function test_se_rechaza_una_credencial_que_no_es_de_esta_aplicacion_o_sin_correo_verificado(): void
    {
        ['headers' => $headers, 'prueba' => $prueba] = $this->pruebaPublicada();
        $this->publicar($headers, $prueba);
        $token = $this->tokenDelEnlace($this->withHeaders($headers)->postJson("/api/seleccion/pruebas/{$prueba['id']}/enlace")->json('data.url'));

        $this->fingirGoogle(extra: ['aud' => 'otra-app.apps.googleusercontent.com']);
        $this->postJson("/api/publico/evaluaciones/{$token}/acceso", ['credencial' => 'jwt'])->assertUnprocessable();

        $this->fingirGoogle(extra: ['email_verified' => 'false']);
        $this->postJson("/api/publico/evaluaciones/{$token}/acceso", ['credencial' => 'jwt'])->assertUnprocessable();

        Http::swap(new HttpFactory);
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_token'], 400)]);
        $this->postJson("/api/publico/evaluaciones/{$token}/acceso", ['credencial' => 'falso'])->assertUnprocessable();

        $this->assertSame(0, PruebaAsignacion::withoutGlobalScopes()->count());
    }

    public function test_un_enlace_desactivado_o_inexistente_no_da_acceso(): void
    {
        ['headers' => $headers, 'prueba' => $prueba] = $this->pruebaPublicada();
        $this->publicar($headers, $prueba);
        $token = $this->tokenDelEnlace($this->withHeaders($headers)->postJson("/api/seleccion/pruebas/{$prueba['id']}/enlace")->json('data.url'));

        $this->getJson('/api/publico/evaluaciones/noexiste')->assertNotFound();

        $this->withHeaders($headers)->deleteJson("/api/seleccion/pruebas/{$prueba['id']}/enlace")->assertOk()->assertJsonPath('data.activo', false);
        $this->getJson("/api/publico/evaluaciones/{$token}")->assertNotFound();
        $this->fingirGoogle();
        $this->postJson("/api/publico/evaluaciones/{$token}/acceso", ['credencial' => 'jwt'])->assertNotFound();
    }
}
