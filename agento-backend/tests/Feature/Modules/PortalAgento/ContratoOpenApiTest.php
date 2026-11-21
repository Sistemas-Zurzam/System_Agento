<?php

namespace Tests\Feature\Modules\PortalAgento;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\CreaEscenarioAgento;
use Tests\Concerns\CreaEscenarioSeleccion;
use Tests\TestCase;

/**
 * Las respuestas reales de /api/v1 cumplen los esquemas del contrato de Agento (openapi.yaml, OpenAPI 3.1).
 *
 * Contrato: por defecto el extracto versionado en docs/agento/contrato/; con AGENTO_OPENAPI_PATH se prueba
 * contra el openapi.yaml completo del portal (esquemas Job, JobsPage, CompanyProfile...).
 */
class ContratoOpenApiTest extends TestCase
{
    use CreaEscenarioAgento, CreaEscenarioSeleccion, RefreshDatabase;

    private Validator $validador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeleccion();

        $ruta = (string) (getenv('AGENTO_OPENAPI_PATH') ?: base_path('../docs/agento/contrato/agento-openapi.extracto.yaml'));
        $this->assertFileExists($ruta, 'No se encontró el contrato de Agento.');

        $this->validador = new Validator;
        $this->validador->resolver()->registerRaw(Yaml::parseFile($ruta, Yaml::PARSE_OBJECT_FOR_MAP), 'agento://contrato');
    }

    private function cumple(string $esquema, mixed $datos, string $contexto): void
    {
        $resultado = $this->validador->validate($datos, "agento://contrato#/components/schemas/{$esquema}");
        $detalle = $resultado->error() ? json_encode((new \Opis\JsonSchema\Errors\ErrorFormatter)->format($resultado->error()), JSON_UNESCAPED_UNICODE) : '';
        $this->assertTrue($resultado->isValid(), "{$contexto} no cumple el esquema {$esquema}: {$detalle}");
    }

    /** Cuerpo JSON decodificado como objetos (lo que espera el validador). */
    private function cuerpo(string $ruta): mixed
    {
        return json_decode($this->v1($ruta)->assertOk()->getContent());
    }

    public function test_las_respuestas_de_ofertas_empresas_y_catalogos_cumplen_el_contrato(): void
    {
        $empresa = $this->crearEmpresa('Logística Andina');
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $area = $this->crearArea($empresa);
        $conSueldo = $this->publicarEnAgento($rys, $this->procesoParaAgento($rys, $area, ['mostrar_salario' => true]));
        $confidencial = $this->publicarEnAgento($rys, $this->procesoParaAgento($rys, $area, ['empresa_confidencial' => true]));
        $pausada = $this->procesoParaAgento($rys, $area);
        $this->publicarEnAgento($rys, $pausada);
        $this->accionAgento($rys, $pausada, 'pausar')->assertOk();

        $this->cumple('JobsPage', $this->cuerpo('/jobs'), 'GET /jobs');
        $this->cumple('JobsPage', $this->cuerpo('/jobs?q=almacen&orden=salary&tam=2&pag=1'), 'GET /jobs con filtros');
        $this->cumple('Job', $this->cuerpo("/jobs/{$conSueldo}"), 'GET /jobs/{slug} con sueldo');
        $this->cumple('Job', $this->cuerpo("/jobs/{$confidencial}"), 'GET /jobs/{slug} confidencial');
        $this->cumple('Job', $this->cuerpo('/jobs/'.\App\Modules\PortalAgento\Models\Publicacion::where('estado_publicacion', 'pausada')->value('slug')), 'GET /jobs/{slug} pausada');
        $this->cumple('JobsPage', $this->cuerpo("/jobs/{$conSueldo}/similar") === [] ? (object) ['items' => [], 'page' => 1, 'pageSize' => 10, 'total' => 0] : (object) ['items' => $this->cuerpo("/jobs/{$conSueldo}/similar"), 'page' => 1, 'pageSize' => 4, 'total' => 1], 'GET /jobs/{slug}/similar (ofertas)');
        $this->cumple('AreaCount', $this->cuerpo('/jobs/areas'), 'GET /jobs/areas');

        $directorio = $this->cuerpo('/companies');
        $this->cumple('CompanyDirectoryResponse', $directorio, 'GET /companies');
        $slug = $directorio->items[0]->slug;
        $this->cumple('CompanyProfile', $this->cuerpo("/companies/{$slug}"), 'GET /companies/{slug}');
        $this->cumple('GetCompaniesSlugJobsResponse', $this->cuerpo("/companies/{$slug}/jobs"), 'GET /companies/{slug}/jobs');

        $this->cumple('Suggestion', $this->cuerpo('/catalog/cargos?q=almacen'), 'GET /catalog/cargos');
        $this->cumple('Suggestion', $this->cuerpo('/catalog/places?q=lima'), 'GET /catalog/places');

        $landing = $this->cuerpo('/seo/sitemap')->landings[0]->slug;
        $this->cumple('JobLanding', $this->cuerpo("/job-landings/{$landing}"), 'GET /job-landings/{slug}');
    }

    public function test_el_validador_detecta_incumplimientos_del_contrato(): void
    {
        $oferta = json_decode(json_encode([
            'id' => '1', 'slug' => 'x', 'title' => 'T', 'location' => ['departamento' => 'Lima', 'provincia' => 'Lima', 'distrito' => 'Lima'],
            'area' => 'Área inventada', 'level' => 'operativo', 'schedule' => 'completa', 'mode' => 'presencial', 'salary' => null,
            'postedAt' => 'x', 'noExperience' => false, 'inclusive' => false, 'benefits' => [], 'requirements' => ['mandatory' => [], 'desirable' => []],
            'summary' => 's', 'description' => [], 'responsibilities' => [],
        ]));

        $resultado = $this->validador->validate($oferta, 'agento://contrato#/components/schemas/Job');

        $this->assertFalse($resultado->isValid(), 'Un Job sin company y con un área fuera del enum debe incumplir el contrato.');
    }

    public function test_los_errores_cumplen_apierror(): void
    {
        $this->cumple('ApiError', json_decode($this->v1('/jobs/no-existe')->assertNotFound()->getContent()), '404');
        $this->cumple('ApiError', json_decode($this->v1('/jobs?tam=99')->assertStatus(422)->getContent()), '422');
    }
}
