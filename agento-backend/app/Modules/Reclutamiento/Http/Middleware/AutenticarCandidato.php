<?php

namespace App\Modules\Reclutamiento\Http\Middleware;

use App\Modules\Configuracion\Support\EmpresaContext;
use App\Modules\Reclutamiento\Application\AccesoCandidatoService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sesión del candidato en el portal público: token opaco emitido tras
 * verificar el correo (cabecera X-Candidato-Token). No usa JWT ni da acceso
 * a ninguna ruta administrativa.
 */
class AutenticarCandidato
{
    public function __construct(private readonly AccesoCandidatoService $accesos) {}

    public function handle(Request $request, Closure $next): Response
    {
        $acceso = $this->accesos->resolver($request->header('X-Candidato-Token'));

        if (! $acceso) {
            return response()->json(['message' => 'Verifica tu correo para continuar.'], 401);
        }

        $request->attributes->set('acceso_candidato', $acceso);

        // El candidato se identifica por correo verificado y no pertenece a una empresa: sus
        // consultas cruzan empresas por diseño y se filtran explícitamente por ese correo.
        // Al postular, el servicio fija la empresa del proceso (EmpresaContext::como).
        $previo = EmpresaContext::entrarSistema('sesión de candidato verificado: datos filtrados por su correo');

        try {
            return $next($request);
        } finally {
            EmpresaContext::restaurar($previo);
        }
    }
}
