<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad en todas las respuestas de Laravel.
 * Valores en config/cabeceras.php.
 */
class CabecerasSeguridad
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $cabeceras = $response->headers;

        $cabeceras->set('X-Content-Type-Options', 'nosniff');
        $cabeceras->set('X-Frame-Options', (string) config('cabeceras.x_frame_options'));
        $cabeceras->set('Referrer-Policy', (string) config('cabeceras.referrer_policy'));
        $cabeceras->set('Permissions-Policy', (string) config('cabeceras.permissions_policy'));

        if (($request->isSecure() || config('cabeceras.hsts_forzar')) && config('cabeceras.hsts')) {
            $cabeceras->set('Strict-Transport-Security', (string) config('cabeceras.hsts'));
        }

        $politica = (string) config('cabeceras.csp');
        $modo = config('cabeceras.csp_modo');
        if ($politica !== '' && $modo === 'enforce') {
            $cabeceras->set('Content-Security-Policy', $politica);
        } elseif ($politica !== '' && $modo === 'report-only') {
            $cabeceras->set('Content-Security-Policy-Report-Only', $politica);
        }

        return $response;
    }
}
