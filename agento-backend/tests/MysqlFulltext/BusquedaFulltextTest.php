<?php

namespace Tests\MysqlFulltext;

use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreaEscenarioAgento;
use Tests\Concerns\CreaEscenarioSeleccion;
use Tests\TestCase;

/**
 * Búsqueda de texto (`q`) con el FULLTEXT real de MySQL.
 *
 * El FULLTEXT de InnoDB no ve filas sin confirmar, y todas las demás pruebas corren dentro de una transacción
 * que se revierte. Por eso esta prueba vive en su PROPIA suite (`MysqlFulltext`, definida la última en
 * phpunit.xml): confirma sus datos sobre una base recién migrada y no limpia nada. Al correr después de
 * Unit y Feature no puede afectarlas. En SQLite se omite (no hay FULLTEXT).
 */
class BusquedaFulltextTest extends TestCase
{
    use CreaEscenarioAgento, CreaEscenarioSeleccion;

    private static bool $preparada = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('El FULLTEXT solo existe en MySQL.');
        }

        // Base limpia y migrada una sola vez (con los catálogos y textos que siembran las migraciones)
        if (! self::$preparada) {
            $this->artisan('migrate:fresh', ['--force' => true]);
            $this->prepararSeleccion();
            self::$preparada = true;
        }
    }

    public function test_q_usa_fulltext_sin_tildes_con_todas_las_palabras_y_prefijos(): void
    {
        $this->assertNotEmpty(DB::select("SHOW INDEX FROM agento_publicaciones WHERE Index_type = 'FULLTEXT'"), 'Falta el índice FULLTEXT.');

        $empresa = $this->crearEmpresa('Logística Andina');
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $area = $this->crearArea($empresa);
        $almacen = $this->procesoParaAgento($rys, $area);
        $ventas = $this->procesoParaAgento($rys, $area, ['cargo' => 'Asesor de ventas']);
        $this->publicarEnAgento($rys, $almacen);
        // Otra área: el nombre del área también es texto buscable («Logística y almacén» contiene «almacen»)
        $this->publicarEnAgento($rys, $ventas, [
            'resumen' => 'Asesor comercial para atención de clientes en la ciudad.',
            'area_id' => \App\Modules\PortalAgento\Models\AreaAgento::where('etiqueta', 'Ventas y comercial')->value('id'),
        ]);

        $this->assertSame(1, $this->v1('/jobs?q=ALMACEN')->json('total'), 'sin tildes ni mayúsculas');
        $this->assertSame(1, $this->v1('/jobs?q=almac')->json('total'), 'prefijo');
        $this->assertSame(1, $this->v1('/jobs?q=asesor%20ventas')->json('total'), 'todas las palabras');
        $this->assertSame(0, $this->v1('/jobs?q=asesor%20almacen')->json('total'), 'ninguna oferta tiene ambas');
        $this->assertSame(0, $this->v1('/jobs?q=gerente')->json('total'));
        $this->assertSame(1, $this->v1('/jobs?q=de%20ventas')->json('total'), 'las palabras vacías («de») no cuentan');
        // Palabras de 2 letras: se usa LIKE (FULLTEXT las ignora).
        $this->assertSame(2, $this->v1('/jobs?q=ci')->json('total'));
        // Sin romper el modo booleano con operadores del usuario.
        $this->assertSame(1, $this->v1('/jobs?q='.rawurlencode('+almacen*("'))->json('total'));
        // Relevancia: no falla al ordenar por texto.
        $this->v1('/jobs?q=operario&orden=relevance')->assertOk();
    }

    public function test_cambiar_a_confidencial_saca_a_la_empresa_del_texto_buscable(): void
    {
        $empresa = $this->crearEmpresa('Constructora Pacífico');
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoParaAgento($rys, $this->crearArea($empresa));
        $this->publicarEnAgento($rys, $proceso);
        $this->assertSame(1, $this->v1('/jobs?q=constructora')->json('total'));

        $proceso->update(['empresa_confidencial' => true]);

        $this->assertSame(0, $this->v1('/jobs?q=constructora')->json('total'));
    }
}
