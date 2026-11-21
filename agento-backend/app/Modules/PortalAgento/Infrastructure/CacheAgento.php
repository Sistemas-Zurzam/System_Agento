<?php

namespace App\Modules\PortalAgento\Infrastructure;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Caché de lecturas públicas con invalidación por versión: cada cambio en una publicación (o en lo que
 * se publica) sube la versión y las entradas anteriores dejan de leerse (expiran solas por TTL).
 * Funciona con cualquier almacén de caché (no requiere tags).
 */
final class CacheAgento
{
    private const CLAVE_VERSION = 'agento:v1:version';

    public static function version(): int
    {
        return (int) Cache::get(self::CLAVE_VERSION, 1);
    }

    public static function invalidar(): void
    {
        Cache::forever(self::CLAVE_VERSION, self::version() + 1);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $calcular
     * @return T
     */
    public static function recordar(string $clave, int $segundos, Closure $calcular): mixed
    {
        return Cache::remember('agento:v1:'.self::version().':'.$clave, $segundos, $calcular);
    }
}
