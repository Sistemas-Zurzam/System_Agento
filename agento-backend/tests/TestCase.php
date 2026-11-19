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
     * Por defecto los tests parten en modo sistema declarado: muchos invocan servicios y
     * modelos directamente para preparar o verificar datos de varias empresas, sin una
     * petición HTTP. Las peticiones HTTP con JWT NO se ven afectadas: el middleware fija
     * la empresa del token durante la petición, así que el aislamiento sí se ejercita.
     */
    protected bool $aislamientoEstricto = false;

    protected function setUp(): void
    {
        parent::setUp();

        EmpresaContext::reiniciar();
        if (! $this->aislamientoEstricto) {
            EmpresaContext::entrarSistema('tests: preparan y verifican datos de varias empresas sin petición HTTP', registrar: false);
        }
    }

    protected function tearDown(): void
    {
        EmpresaContext::reiniciar();

        parent::tearDown();
    }
}
