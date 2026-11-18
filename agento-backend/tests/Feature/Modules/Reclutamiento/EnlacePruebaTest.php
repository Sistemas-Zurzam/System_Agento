<?php

namespace Tests\Feature\Modules\Reclutamiento;

use App\Models\User;
use App\Modules\Reclutamiento\Models\PruebaAsignacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreaEscenarioSeleccion;
use Tests\TestCase;

/**
 * Enlace compartido de una prueba: quien lo abre declara nombre, correo y
 * documento, rinde la prueba y RR. HH. recibe el resultado; un intento por correo.
 */
class EnlacePruebaTest extends TestCase
{
    use CreaEscenarioSeleccion, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeleccion();
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

    private function registro(string $email = 'ana@gmail.com', array $extra = []): array
    {
        return ['nombre' => 'Ana Torres', 'email' => $email, 'documento' => '45678912', ...$extra];
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

    public function test_el_postulante_se_registra_rinde_y_rrhh_recibe_el_resultado(): void
    {
        ['headers' => $headers, 'prueba' => $prueba] = $this->pruebaPublicada();
        $this->publicar($headers, $prueba);
        $token = $this->tokenDelEnlace($this->withHeaders($headers)->postJson("/api/seleccion/pruebas/{$prueba['id']}/enlace")->json('data.url'));

        $this->getJson("/api/publico/evaluaciones/{$token}")->assertOk()->assertJsonPath('data.nombre', 'Conocimientos de caja');

        $acceso = $this->postJson("/api/publico/evaluaciones/{$token}/acceso", $this->registro('Ana@Gmail.com'))
            ->assertOk()->assertJsonPath('data.email', 'ana@gmail.com')->json('data');

        $this->getJson("/api/publico/pruebas/{$acceso['token']}")->assertOk();
        $this->postJson("/api/publico/pruebas/{$acceso['token']}/iniciar")->assertOk();
        $this->postJson("/api/publico/pruebas/{$acceso['token']}/finalizar")->assertOk();
        $this->assertSame(PruebaAsignacion::FINALIZADA, PruebaAsignacion::withoutGlobalScopes()->first()->estado);

        $participantes = $this->withHeaders($headers)->getJson("/api/seleccion/pruebas/{$prueba['id']}/enlace")->assertOk()->json('data.participantes');
        $this->assertCount(1, $participantes);
        $this->assertSame('ana@gmail.com', $participantes[0]['email']);
        $this->assertSame('45678912', $participantes[0]['documento']);
        $this->assertSame('finalizada', $participantes[0]['estado']);
        $this->assertSame('Conocimientos de caja', $participantes[0]['asignacion']['prueba']['nombre']);
        $this->assertNotEmpty($participantes[0]['asignacion']['resultado']['dimensiones'], 'RR. HH. ve el resultado por dimensión');
    }

    public function test_un_correo_ya_registrado_no_recupera_el_acceso_de_otra_persona(): void
    {
        ['headers' => $headers, 'prueba' => $prueba] = $this->pruebaPublicada();
        $this->publicar($headers, $prueba);
        $token = $this->tokenDelEnlace($this->withHeaders($headers)->postJson("/api/seleccion/pruebas/{$prueba['id']}/enlace")->json('data.url'));

        $this->postJson("/api/publico/evaluaciones/{$token}/acceso", $this->registro())->assertOk();
        $this->postJson("/api/publico/evaluaciones/{$token}/acceso", $this->registro('ANA@gmail.com'))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame(1, PruebaAsignacion::withoutGlobalScopes()->count());

        $this->postJson("/api/publico/evaluaciones/{$token}/acceso", $this->registro('luis@gmail.com', ['nombre' => 'Luis Pérez']))->assertOk();
        $this->assertSame(2, PruebaAsignacion::withoutGlobalScopes()->count());
    }

    public function test_se_exigen_nombre_correo_y_documento_validos(): void
    {
        ['headers' => $headers, 'prueba' => $prueba] = $this->pruebaPublicada();
        $this->publicar($headers, $prueba);
        $token = $this->tokenDelEnlace($this->withHeaders($headers)->postJson("/api/seleccion/pruebas/{$prueba['id']}/enlace")->json('data.url'));

        $this->postJson("/api/publico/evaluaciones/{$token}/acceso", [])->assertUnprocessable()->assertJsonValidationErrors(['nombre', 'email', 'documento']);
        $this->postJson("/api/publico/evaluaciones/{$token}/acceso", $this->registro('no-es-correo'))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson("/api/publico/evaluaciones/{$token}/acceso", $this->registro(extra: ['documento' => '12 34;']))->assertUnprocessable()->assertJsonValidationErrors('documento');

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
        $this->postJson("/api/publico/evaluaciones/{$token}/acceso", $this->registro())->assertNotFound();
    }
}
