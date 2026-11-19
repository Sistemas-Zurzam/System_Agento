<?php

namespace App\Modules\Configuracion\Http\Middleware;

use App\Modules\Configuracion\Support\EmpresaContext;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Declara el modo sistema (sin filtro de empresa) para rutas que por diseño no tienen
 * un usuario con empresa: el portal público de empleos. El motivo es obligatorio:
 *
 *     ->middleware('contexto.sistema:portal público de empleos')
 *
 * En esas rutas la empresa nunca se toma de la petición: sale del recurso resuelto por
 * el servidor (el proceso publicado, el token de la asignación o del enlace).
 */
class ContextoSistema
{
    public function handle(Request $request, Closure $next, string $motivo = ''): Response
    {
        if (trim($motivo) === '') {
            throw new InvalidArgumentException('contexto.sistema exige un motivo: contexto.sistema:<motivo>');
        }

        $previo = EmpresaContext::entrarSistema($motivo);

        try {
            return $next($request);
        } finally {
            EmpresaContext::restaurar($previo);
        }
    }
}
