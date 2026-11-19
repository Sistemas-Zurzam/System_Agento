<?php

namespace App\Modules\Reclutamiento\Domain;

/**
 * Resultado de escanear un archivo con el antivirus.
 */
final class ResultadoEscaneo
{
    public const LIMPIO = 'limpio';

    public const INFECTADO = 'infectado';

    /** El motor no respondió o no está configurado: NO significa que el archivo esté limpio. */
    public const NO_DISPONIBLE = 'no_disponible';

    private function __construct(
        public readonly string $estado,
        public readonly ?string $detalle = null,
    ) {}

    public static function limpio(): self
    {
        return new self(self::LIMPIO);
    }

    public static function infectado(string $firma): self
    {
        return new self(self::INFECTADO, $firma);
    }

    public static function noDisponible(string $motivo): self
    {
        return new self(self::NO_DISPONIBLE, $motivo);
    }
}
