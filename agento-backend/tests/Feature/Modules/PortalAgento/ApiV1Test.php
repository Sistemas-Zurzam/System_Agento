<?php

namespace Tests\Feature\Modules\PortalAgento;

use App\Modules\PortalAgento\Models\AreaAgento;
use App\Modules\PortalAgento\Models\JornadaAgento;
use App\Modules\PortalAgento\Models\NivelAgento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaEscenarioAgento;
use Tests\Concerns\CreaEscenarioSeleccion;
use Tests\TestCase;

/**
 * API pública /api/v1 de Agento: filtros, orden, paginación, empresas, catálogos, SEO, errores y caché.
 */
class ApiV1Test extends TestCase
{
    use CreaEscenarioAgento, CreaEscenarioSeleccion, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeleccion();
    }

    private function esMysql(): bool
    {
        return \Illuminate\Support\Facades\DB::getDriverName() === 'mysql';
    }

    /**
     * @return array{0: \App\Models\User, 1: \App\Modules\Configuracion\Models\Empresa}
     */
    private function rysDeEmpresa(string $nombre = 'Empresa'): array
    {
        $empresa = $this->crearEmpresa($nombre);

        return [$this->usuarioCon($empresa, 'talento_cultura'), $empresa];
    }

    public function test_listado_con_la_forma_del_contrato_y_solo_campos_de_la_lista_blanca(): void
    {
        [$rys, $empresa] = $this->rysDeEmpresa();
        $proceso = $this->procesoParaAgento($rys, $this->crearArea($empresa), ['mostrar_salario' => true]);
        $this->publicarEnAgento($rys, $proceso);

        $respuesta = $this->v1('/jobs')->assertOk()
            ->assertJsonPath('page', 1)->assertJsonPath('pageSize', 10)->assertJsonPath('total', 1)->assertJsonPath('correctedQuery', null);

        $oferta = $respuesta->json('items.0');
        $this->assertEqualsCanonicalizing([
            'id', 'slug', 'title', 'company', 'location', 'area', 'level', 'schedule', 'mode', 'salary', 'postedAt', 'expiresAt',
            'noExperience', 'inclusive', 'benefits', 'requirements', 'summary', 'description', 'responsibilities', 'questions',
            'status', 'acceptsApplications', 'publicMessage', 'jobCode', 'confidential',
        ], array_keys($oferta));
        $this->assertEqualsCanonicalizing(['id', 'slug', 'name', 'verified', 'rating', 'reviewsCount', 'respondsFast', 'sector'], array_keys($oferta['company']));

        // Nada interno: ni ATS, ni reclutador, ni áreas internas, ni ids de la empresa.
        $texto = json_encode($oferta);
        foreach (['umbral', 'peso', 'reclutador', 'empresa_id', 'area_id', 'solicitud', 'competencia'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $texto);
        }
    }

    public function test_las_funciones_no_se_repiten_en_la_descripcion(): void
    {
        [$rys, $empresa] = $this->rysDeEmpresa();
        $proceso = $this->procesoParaAgento($rys, $this->crearArea($empresa));
        $proceso->update(['descripcion' => "Operario para el almacén central.

Funciones principales
Recepcionar mercadería
Despachar pedidos

Horario laboral
Lunes a viernes"]);
        $proceso->solicitud->update(['actividades' => "Recepcionar mercadería
Despachar pedidos"]);
        $slug = $this->publicarEnAgento($rys, $proceso);

        $oferta = $this->v1("/jobs/{$slug}")->assertOk()->json();

        $this->assertSame(['Recepcionar mercadería', 'Despachar pedidos'], $oferta['responsibilities']);
        $this->assertSame(['Operario para el almacén central.', 'Horario laboral', 'Lunes a viernes'], $oferta['description']);
    }

    public function test_el_sueldo_solo_se_muestra_en_soles_y_si_el_proceso_lo_permite(): void
    {
        [$rys, $empresa] = $this->rysDeEmpresa();
        $area = $this->crearArea($empresa);
        $oculto = $this->procesoParaAgento($rys, $area, ['mostrar_salario' => false]);
        $dolares = $this->procesoParaAgento($rys, $area, ['mostrar_salario' => true, 'moneda' => 'USD']);
        $visible = $this->procesoParaAgento($rys, $area, ['mostrar_salario' => true]);
        $slugOculto = $this->publicarEnAgento($rys, $oculto);
        $slugDolares = $this->publicarEnAgento($rys, $dolares);
        $slugVisible = $this->publicarEnAgento($rys, $visible);

        $this->v1("/jobs/{$slugOculto}")->assertJsonPath('salary', null);
        $this->v1("/jobs/{$slugDolares}")->assertJsonPath('salary', null);
        $this->v1("/jobs/{$slugVisible}")->assertJsonPath('salary.min', 1200);

        // consueldo y el rango salarial excluyen las ofertas sin sueldo informado.
        $this->assertSame(1, $this->v1('/jobs?consueldo=1')->json('total'));
        $this->assertSame(1, $this->v1('/jobs?min=1000&max=1300')->json('total'));
        $this->assertSame(0, $this->v1('/jobs?min=3000')->json('total'));
        // min > max se intercambian.
        $this->assertSame(1, $this->v1('/jobs?min=1300&max=1000')->json('total'));
    }

    public function test_filtros_de_area_nivel_jornada_lugar_y_texto(): void
    {
        [$rys, $empresa] = $this->rysDeEmpresa();
        $area = $this->crearArea($empresa);
        $almacen = $this->procesoParaAgento($rys, $area);
        $this->publicarEnAgento($rys, $almacen);

        $ventas = $this->procesoParaAgento($rys, $area, ['cargo' => 'Asesor de ventas', 'modalidad' => 'remoto']);
        $this->publicarEnAgento($rys, $ventas, [
            'area_id' => AreaAgento::where('etiqueta', 'Ventas y comercial')->value('id'),
            'nivel_id' => NivelAgento::where('clave', 'asistente')->value('id'),
            'jornada_id' => JornadaAgento::where('clave', 'nocturna')->value('id'),
            'ubigeo_codigo' => '040101',
            'resumen' => 'Asesor comercial para atención de clientes en Arequipa.',
            'sin_experiencia' => false,
        ]);

        $this->assertSame(2, $this->v1('/jobs')->json('total'));
        $this->assertSame(1, $this->v1('/jobs?area=Ventas%20y%20comercial')->json('total'));
        $this->assertSame(2, $this->v1('/jobs?area=Ventas%20y%20comercial&area=Log%C3%ADstica%20y%20almac%C3%A9n')->json('total'), 'area es repetible');
        $this->assertSame(1, $this->v1('/jobs?nivel=asistente')->json('total'));
        $this->assertSame(1, $this->v1('/jobs?jornada=nocturna')->json('total'));
        $this->assertSame(1, $this->v1('/jobs?modalidad=remoto')->json('total'));
        $this->assertSame(1, $this->v1('/jobs?lugar=Arequipa')->json('total'));
        $this->assertSame(1, $this->v1('/jobs?lugar='.rawurlencode('Miraflores, Lima'))->json('total'));
        $this->assertSame(1, $this->v1('/jobs?sinexp=1')->json('total'), 'solo la de almacén se publicó con sin_experiencia');
        // Texto: sin tildes y sin distinguir mayúsculas.
        // En MySQL el FULLTEXT de InnoDB no ve filas sin confirmar (cada test corre en una transacción):
        // allí lo cubre BusquedaFulltextMysqlTest con datos confirmados.
        if (! $this->esMysql()) {
            $this->assertSame(1, $this->v1('/jobs?q=ALMACEN')->json('total'));
            $this->assertSame(1, $this->v1('/jobs?q=asesor%20ventas')->json('total'));
            $this->assertSame(0, $this->v1('/jobs?q=gerente')->json('total'));
        }
        // Ninguna empresa está verificada en modo agencia.
        $this->assertSame(0, $this->v1('/jobs?verificadas=1')->json('total'));
    }

    public function test_paginacion_y_pagina_fuera_de_rango(): void
    {
        [$rys, $empresa] = $this->rysDeEmpresa();
        $area = $this->crearArea($empresa);
        foreach (range(1, 3) as $_) {
            $this->publicarEnAgento($rys, $this->procesoParaAgento($rys, $area));
        }

        $this->v1('/jobs?tam=2&pag=1')->assertJsonPath('page', 1)->assertJsonPath('pageSize', 2)->assertJsonPath('total', 3)->assertJsonCount(2, 'items');
        $this->v1('/jobs?tam=2&pag=2')->assertJsonPath('page', 2)->assertJsonCount(1, 'items');
        // Si pag supera la última página, devuelve la última y page lo indica.
        $this->v1('/jobs?tam=2&pag=99')->assertJsonPath('page', 2)->assertJsonCount(1, 'items');
        $this->v1('/jobs?tam=51')->assertStatus(422)->assertJsonPath('code', 'invalid_request');
    }

    public function test_el_orden_por_sueldo_pone_primero_el_mayor_y_al_final_los_sin_sueldo(): void
    {
        [$rys, $empresa] = $this->rysDeEmpresa();
        $area = $this->crearArea($empresa);
        $sin = $this->publicarEnAgento($rys, $this->procesoParaAgento($rys, $area));
        $bajo = $this->publicarEnAgento($rys, $this->procesoParaAgento($rys, $area, ['mostrar_salario' => true, 'salario_min' => 1000, 'salario_max' => 1100]));
        $alto = $this->publicarEnAgento($rys, $this->procesoParaAgento($rys, $area, ['mostrar_salario' => true, 'salario_min' => 2000, 'salario_max' => 2500]));

        $this->assertSame([$alto, $bajo, $sin], collect($this->v1('/jobs?orden=salary')->json('items'))->pluck('slug')->all());
    }

    public function test_empresa_confidencial_no_se_identifica_en_ningun_dato_publico(): void
    {
        [$rys, $empresa] = $this->rysDeEmpresa('Minera Secreta');
        $area = $this->crearArea($empresa);
        $confidencial = $this->procesoParaAgento($rys, $area, ['empresa_confidencial' => true, 'cargo' => 'Supervisor de campo']);
        $slug = $this->publicarEnAgento($rys, $confidencial);

        $nombre = $empresa->nombre_comercial;
        $slugEmpresa = \Illuminate\Support\Str::slug($nombre);

        foreach (["/jobs/{$slug}", '/jobs', "/jobs/{$slug}/similar", "/seo/jobs/{$slug}", '/seo/sitemap', '/companies', '/catalog/cargos?q=supervisor', '/jobs?q='.rawurlencode($nombre)] as $ruta) {
            $json = $this->v1($ruta)->assertOk()->getContent();
            $this->assertStringNotContainsString($nombre, $json, "La ruta {$ruta} no puede revelar el nombre de la empresa confidencial");
            $this->assertStringNotContainsStringIgnoringCase($slugEmpresa, $json, "La ruta {$ruta} no puede revelar el slug de la empresa confidencial");
        }

        $this->v1("/jobs/{$slug}")->assertJsonPath('company.name', 'Empresa confidencial')->assertJsonPath('company.slug', 'confidencial')
            ->assertJsonPath('confidential', true);
        // Buscar por el nombre de la empresa confidencial no encuentra su oferta.
        $this->assertSame(0, $this->v1('/jobs?q='.rawurlencode($nombre))->json('total'));
        // No hay perfil público de una empresa confidencial.
        $this->assertSame(0, count($this->v1('/companies')->json('items')));
        $this->v1("/companies/{$slugEmpresa}")->assertNotFound();
        $this->v1('/companies/confidencial')->assertNotFound();

        $jsonLd = $this->v1("/seo/jobs/{$slug}")->json();
        $this->assertSame('Empresa confidencial', $jsonLd['hiringOrganization']['name']);
        $this->assertArrayNotHasKey('logo', $jsonLd['hiringOrganization']);
    }

    public function test_si_cambia_a_confidencial_el_texto_de_busqueda_deja_de_incluir_la_empresa(): void
    {
        [$rys, $empresa] = $this->rysDeEmpresa('Constructora Pacífico');
        $proceso = $this->procesoParaAgento($rys, $this->crearArea($empresa));
        $this->publicarEnAgento($rys, $proceso);
        $this->assertStringContainsString('constructora', \App\Modules\PortalAgento\Models\Publicacion::firstOrFail()->texto_busqueda);
        $this->esMysql() || $this->assertSame(1, $this->v1('/jobs?q=constructora')->json('total'));

        $proceso->update(['empresa_confidencial' => true]);

        $this->assertStringNotContainsString('constructora', \App\Modules\PortalAgento\Models\Publicacion::firstOrFail()->texto_busqueda, 'El observer del proceso refresca el texto buscable.');
        $this->assertSame(0, $this->v1('/jobs?q=constructora')->json('total'));
    }

    public function test_directorio_y_perfil_de_empresa_con_sus_ofertas(): void
    {
        [$rys, $empresa] = $this->rysDeEmpresa('Logística Andina');
        $area = $this->crearArea($empresa);
        $slugOferta = $this->publicarEnAgento($rys, $this->procesoParaAgento($rys, $area));
        $this->publicarEnAgento($rys, $this->procesoParaAgento($rys, $area));

        $item = $this->v1('/companies')->assertOk()->json('items.0');
        $this->assertSame($empresa->nombre_comercial, $item['name']);
        $this->assertSame(2, $item['openJobs']);
        $this->assertSame('Miraflores, Lima', $item['location']);
        $this->assertFalse($item['verified']);

        $slug = $item['slug'];
        $this->v1("/companies/{$slug}")->assertOk()->assertJsonPath('name', $empresa->nombre_comercial)
            ->assertJsonPath('size', '1-10')->assertJsonStructure(['id', 'slug', 'name', 'verified', 'rating', 'reviewsCount', 'respondsFast', 'sector', 'ruc', 'size', 'email']);
        $this->v1("/companies/{$slug}/jobs")->assertOk()->assertJsonCount(2);
        $this->assertContains($slugOferta, collect($this->v1("/companies/{$slug}/jobs")->json())->pluck('slug')->all());
        $this->v1('/companies/no-existe/jobs')->assertNotFound();
        $this->assertSame([], $this->v1('/companies?verified=true')->json('items'));
        $this->assertSame(1, count($this->v1('/companies?q=andina')->json('items')));
    }

    public function test_areas_con_su_contador_catalogos_y_autocompletados(): void
    {
        [$rys, $empresa] = $this->rysDeEmpresa();
        $this->publicarEnAgento($rys, $this->procesoParaAgento($rys, $this->crearArea($empresa)));

        $areas = collect($this->v1('/jobs/areas')->assertOk()->json());
        $this->assertCount(11, $areas);
        $this->assertSame(1, $areas->firstWhere('area', 'Logística y almacén')['count']);
        $this->assertSame(0, $areas->firstWhere('area', 'Salud')['count']);

        $this->assertCount(6, $this->v1('/catalog/niveles')->json('items'));
        $this->assertCount(7, $this->v1('/catalog/jornadas')->json('items'));
        $this->assertContains('Seguro de salud', $this->v1('/catalog/beneficios')->json('items'));
        $this->assertCount(11, $this->v1('/catalog/areas')->json('items'));
        $this->v1('/catalog/desconocido')->assertNotFound();

        $this->assertSame('Miraflores, Lima', $this->v1('/catalog/places?q=mirafl')->assertOk()->json('0.label'));
        $this->assertSame('Operario de almacén', $this->v1('/catalog/cargos?q=almac')->json('0.label'));
        $this->v1('/catalog/places?q=a')->assertStatus(422)->assertJsonPath('code', 'invalid_request');
    }

    public function test_detalle_similares_y_landing_seo(): void
    {
        [$rys, $empresa] = $this->rysDeEmpresa();
        $area = $this->crearArea($empresa);
        $slug = $this->publicarEnAgento($rys, $this->procesoParaAgento($rys, $area));
        $otra = $this->publicarEnAgento($rys, $this->procesoParaAgento($rys, $area));

        $this->assertSame([$otra], collect($this->v1("/jobs/{$slug}/similar")->assertOk()->json())->pluck('slug')->all());
        $this->v1('/jobs/no-existe/similar')->assertNotFound();

        $sitemap = $this->v1('/seo/sitemap')->assertOk()->json();
        $this->assertCount(2, $sitemap['jobs']);
        $this->assertCount(1, $sitemap['companies']);
        $landing = $sitemap['landings'][0]['slug'];
        $this->assertStringContainsString('-en-', $landing);

        $this->v1("/job-landings/{$landing}")->assertOk()->assertJsonStructure(['cargo', 'lugar', 'intro', 'relatedCargos', 'relatedPlaces']);
        $this->v1('/job-landings/cajero-en-tumbes')->assertNotFound();

        $jsonLd = $this->v1("/seo/jobs/{$slug}")->assertOk()->json();
        $this->assertSame('JobPosting', $jsonLd['@type']);
        $this->assertSame('PE', $jsonLd['jobLocation']['address']['addressCountry']);
        $this->assertSame($empresa->nombre_comercial, $jsonLd['hiringOrganization']['name']);
    }

    public function test_errores_con_el_formato_del_contrato(): void
    {
        $this->v1('/jobs/no-existe')->assertNotFound()->assertExactJson([
            'code' => 'not_found', 'message' => 'No encontramos lo que buscas.', 'details' => null,
        ]);
        $this->v1('/jobs?orden=cualquiera')->assertStatus(422)->assertJsonPath('code', 'invalid_request')->assertJsonStructure(['code', 'message', 'details' => ['orden']]);
        $this->v1('/ruta-que-no-existe')->assertNotFound();
    }

    public function test_etag_y_cache_control_en_las_lecturas(): void
    {
        [$rys, $empresa] = $this->rysDeEmpresa();
        $slug = $this->publicarEnAgento($rys, $this->procesoParaAgento($rys, $this->crearArea($empresa)));

        $primera = $this->v1("/jobs/{$slug}")->assertOk();
        $etag = $primera->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->assertStringContainsString('public', $primera->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=', $primera->headers->get('Cache-Control'));

        $this->v1("/jobs/{$slug}", ['If-None-Match' => $etag])->assertStatus(304);

        // Un cambio en la publicación cambia el ETag.
        $this->accionAgento($rys, \App\Modules\Reclutamiento\Models\ProcesoSeleccion::firstOrFail(), 'pausar')->assertOk();
        $this->assertNotSame($etag, $this->v1("/jobs/{$slug}")->headers->get('ETag'));

        // Los catálogos se cachean más tiempo.
        $this->assertStringContainsString('max-age=3600', $this->v1('/catalog/areas')->headers->get('Cache-Control'));
    }

    public function test_la_cache_de_areas_se_invalida_al_publicar(): void
    {
        [$rys, $empresa] = $this->rysDeEmpresa();
        $area = $this->crearArea($empresa);
        $this->assertSame(0, collect($this->v1('/jobs/areas')->json())->sum('count'));

        $this->publicarEnAgento($rys, $this->procesoParaAgento($rys, $area));

        $this->assertSame(1, collect($this->v1('/jobs/areas')->json())->sum('count'));
    }

    public function test_rate_limiting_de_la_api_publica(): void
    {
        config(['portal_agento.limite_busqueda_por_minuto' => 3]);

        foreach (range(1, 3) as $_) {
            $this->v1('/jobs')->assertOk();
        }
        $this->v1('/jobs')->assertStatus(429)->assertJsonPath('code', 'rate_limited')->assertHeader('Retry-After');
    }

    public function test_cors_permite_solo_el_origen_de_agento(): void
    {
        config(['cors.allowed_origins_patterns' => ['#^http://localhost:5173$#']]);

        $this->v1('/catalog/areas', ['Origin' => 'http://localhost:5173'])->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
        $this->assertNull($this->v1('/catalog/areas', ['Origin' => 'https://sitio-ajeno.test'])->headers->get('Access-Control-Allow-Origin'));
    }
}
