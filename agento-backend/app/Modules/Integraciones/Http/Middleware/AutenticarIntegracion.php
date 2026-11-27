<?php

namespace App\Modules\Integraciones\Http\Middleware;

use App\Modules\Integraciones\Application\CredencialesIntegracion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica una integración máquina a máquina por el token de X-Api-Key y deja la credencial (y con ella la
 * empresa autorizada) en $request->attributes['credencial_integracion']. Se ejecuta antes de validar el
 * cuerpo: sin credencial válida no se revela nada del contrato. El mensaje no distingue entre token
 * desconocido, revocado o vencido.
 *
 * Uso: AutenticarIntegracion::class.':firme_studio'
 */
class AutenticarIntegracion
{
    public function __construct(private readonly CredencialesIntegracion $credenciales) {}

    public function handle(Request $request, Closure $next, string $proveedor): Response
    {
        $credencial = $this->credenciales->verificar($request->header('X-Api-Key'), $proveedor);
        if (! $credencial) {
            return response()->json(['message' => 'Credencial inválida.'], 401);
        }

        $request->attributes->set('credencial_integracion', $credencial);

        return $next($request);
    }
}
