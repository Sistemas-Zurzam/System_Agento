<?php

namespace App\Modules\Configuracion\Support;

use LogicException;

/**
 * Se consultó un modelo con aislamiento por empresa sin contexto de empresa ni modo
 * sistema declarado. Solo se lanza en local/testing, para detectar el problema
 * pronto; en producción la consulta no devuelve filas.
 */
class EmpresaNoDefinidaException extends LogicException
{
    public static function para(string $tabla): self
    {
        return new self(
            "Consulta a «{$tabla}» sin contexto de empresa. Establece la empresa (EmpresaContext::como) "
            .'o, si es una tarea que cruza empresas por diseño, declara el modo sistema con motivo '
            .'(EmpresaContext::comoSistema(fn () => ..., motivo: \'...\')).'
        );
    }
}
