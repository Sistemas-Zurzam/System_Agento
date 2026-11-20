<?php

namespace App\Modules\PortalAgento\Support;

use Illuminate\Support\Str;

/**
 * Normalización de texto para búsqueda y slugs (sin tildes, en minúsculas).
 */
final class Texto
{
    public static function normalizar(?string $texto): string
    {
        $texto = Str::lower(Str::ascii((string) $texto));

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9 ]+/', ' ', $texto)));
    }

    /**
     * @return list<string>
     */
    public static function palabras(?string $texto): array
    {
        $normal = self::normalizar($texto);

        return $normal === '' ? [] : array_values(array_unique(explode(' ', $normal)));
    }
}
