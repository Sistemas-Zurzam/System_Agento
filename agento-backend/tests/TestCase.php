<?php

namespace Tests;

use App\Modules\Configuracion\Support\EmpresaContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Aislamiento estricto: el test parte SIN contexto de empresa, igual que un job o un
     * comando en producción, y cualquier consulta sin empresa falla. Los tests de
     * aislamiento (tests/Feature/Seguridad/Aislamiento*) lo activan.
     *
     * Por defecto los tests conservan el comportamiento que tenían antes del fallo cerrado: muchos
     * invocan servicios y modelos directamente, sin petición HTTP ni usuario, para preparar o
     * verificar datos de varias empresas. Con un usuario autenticado el scope SÍ filtra por su empresa
     * (igual que antes), y las peticiones HTTP con JWT fijan la empresa del token.
     */
    protected bool $aislamientoEstricto = false;

    protected function setUp(): void
    {
        parent::setUp();

        EmpresaContext::reiniciar();
        if (! $this->aislamientoEstricto) {
            EmpresaContext::permitirSinContextoEnPruebas();
        }
    }

    protected function tearDown(): void
    {
        EmpresaContext::reiniciar();

        parent::tearDown();
    }
}
