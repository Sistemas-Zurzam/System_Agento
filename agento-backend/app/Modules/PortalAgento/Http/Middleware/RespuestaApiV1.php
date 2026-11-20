<?php

namespace App\Modules\PortalAgento\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forma de las respuestas de /api/v1 según el contrato de Agento:
 *
 * - Errores como { code, message, details } (ApiError del openapi.yaml). Los demás endpoints de Agento
 *   conservan su formato { message, errors }: esto solo aplica a las rutas que usan este middleware.
 * - Lecturas (GET 200) con ETag y Cache-Control público; If-None-Match responde 304.
 *
 * Es el middleware más externo: las excepciones del resto de la pila (validación, 404, límite de peticiones)
 * ya llegan aquí convertidas en respuesta.
 */
class RespuestaApiV1
{
    private const CODIGOS = [
        400 => ['bad_request', 'La solicitud no es válida.'],
        401 => ['unauthorized', 'Necesitas iniciar sesión.'],
        403 => ['forbidden', 'No tienes permiso para esta acción.'],
        404 => ['not_found', 'No encontramos lo que buscas.'],
        405 => ['method_not_allowed', 'Método no permitido.'],
        409 => ['conflict', 'La información cambió. Intenta de nuevo.'],
        422 => ['invalid_request', 'Revisa los datos enviados.'],
        429 => ['rate_limited', 'Demasiadas solicitudes. Intenta de nuevo en un momento.'],
    ];

    /** Tipo de caché (config portal_agento.cache.*) según la ruta. */
    private function tipoDeCache(Request $request): string
    {
        $ruta = (string) $request->route()?->uri();

        return match (true) {
            str_contains($ruta, 'v1/catalog/{tipo}') => 'catalogos',
            str_contains($ruta, '{slug}') => 'detalle',
            default => 'listado',
        };
    }

    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        $respuesta = $next($request);

        if ($respuesta->getStatusCode() >= 400) {
            return $this->comoError($respuesta);
        }

        if ($request->isMethod('GET') && $respuesta->getStatusCode() === 200 && $respuesta instanceof JsonResponse) {
            $this->conCache($request, $respuesta, $this->tipoDeCache($request));
        }

        return $respuesta;
    }

    private function comoError(Response $respuesta): Response
    {
        $estado = $respuesta->getStatusCode();
        $original = $respuesta instanceof JsonResponse ? $respuesta->getData(true) : [];
        [$code, $mensaje] = self::CODIGOS[$estado] ?? ($estado >= 500 ? ['internal_error', 'Algo salió mal. Intenta de nuevo.'] : ['bad_request', 'La solicitud no es válida.']);

        $detalles = is_array($original['errors'] ?? null) ? $original['errors'] : null;
        if ($estado === 422 && $detalles) {
            $mensaje = (string) (collect($detalles)->flatten()->first() ?? $mensaje);
        } elseif (in_array($estado, [400, 409], true) && is_string($original['message'] ?? null) && ! str_contains((string) ($original['exception'] ?? ''), 'Exception')) {
            $mensaje = $original['message'];
        }

        $nueva = new JsonResponse(['code' => $code, 'message' => $mensaje, 'details' => $detalles], $estado);
        foreach (['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'] as $cabecera) {
            if ($respuesta->headers->has($cabecera)) {
                $nueva->headers->set($cabecera, $respuesta->headers->get($cabecera));
            }
        }
        $nueva->headers->set('Cache-Control', 'no-store');

        return $nueva;
    }

    private function conCache(Request $request, JsonResponse $respuesta, string $tipo): void
    {
        $segundos = (int) config("portal_agento.cache.{$tipo}", 60);
        $etag = '"'.md5((string) $respuesta->getContent()).'"';

        $respuesta->headers->set('ETag', $etag);
        $respuesta->headers->set('Cache-Control', "public, max-age={$segundos}, stale-while-revalidate=".($segundos * 2));
        $respuesta->headers->set('Vary', 'Accept-Encoding, Origin');

        $coincide = array_map(fn (string $e) => trim(str_replace('W/', '', $e)), explode(',', (string) $request->headers->get('If-None-Match')));
        if (in_array($etag, $coincide, true)) {
            $respuesta->setNotModified();
        }
    }
}
