<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * /up es el health check que usan los workflows de despliegue (smoke test).
 * Debe responder 200 sin autenticación y sin exponer información interna.
 */
class HealthCheckTest extends TestCase
{
    public function test_up_responde_200_sin_autenticacion(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_up_no_expone_informacion_sensible(): void
    {
        $contenido = $this->get('/up')->getContent();

        foreach (['APP_KEY', 'DB_PASSWORD', 'JWT_SECRET', 'mysql', 'vendor/', 'Stack trace'] as $texto) {
            $this->assertStringNotContainsString($texto, $contenido);
        }
    }
}
