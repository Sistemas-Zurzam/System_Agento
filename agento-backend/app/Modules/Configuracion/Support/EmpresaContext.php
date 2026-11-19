<?php

namespace App\Modules\Configuracion\Support;

use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Contexto de empresa (tenant) de la ejecución actual.
 *
 * `EmpresaScope` filtra por `empresa_id` usando este contexto. Hay tres estados:
 *
 *  - Empresa: se filtra por esa empresa. Lo establecen el middleware JWT (empresa del
 *    token), los jobs (se serializa al encolar), los comandos y `como()`.
 *  - Sistema: sin filtro. Es EXPLÍCITO y exige un motivo, que queda registrado en el
 *    canal de log `tenant`. Solo para tareas que por diseño cruzan empresas (portal
 *    público, cron de retención). Ver docs/seguridad/auditoria-scopes.md.
 *  - Sin definir: el scope falla CERRADO (excepción en local/testing; sin filas en
 *    producción).
 *
 * El estado es estático: vive lo que dura la petición, el job o el comando. Los
 * puntos de entrada lo restauran con `restaurar()` (nunca lo dejan "pegado").
 */
final class EmpresaContext
{
    private static ?int $empresaId = null;

    private static bool $sistema = false;

    private static ?string $motivo = null;

    public static function actual(): ?int
    {
        return self::$empresaId;
    }

    public static function esSistema(): bool
    {
        return self::$sistema;
    }

    public static function motivo(): ?string
    {
        return self::$motivo;
    }

    /**
     * Ejecuta $callback con la empresa indicada y restaura el contexto anterior.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function como(int $empresaId, Closure $callback): mixed
    {
        $previo = self::entrarEmpresa($empresaId);

        try {
            return $callback();
        } finally {
            self::restaurar($previo);
        }
    }

    /**
     * Ejecuta $callback SIN filtro de empresa. El motivo es obligatorio y se registra.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function comoSistema(Closure $callback, string $motivo): mixed
    {
        $previo = self::entrarSistema($motivo);

        try {
            return $callback();
        } finally {
            self::restaurar($previo);
        }
    }

    /**
     * Establece la empresa hasta que se llame a restaurar() con el valor devuelto.
     *
     * @return array{0: ?int, 1: bool, 2: ?string}
     */
    public static function entrarEmpresa(int $empresaId): array
    {
        $previo = self::instantanea();
        self::$empresaId = $empresaId;
        self::$sistema = false;
        self::$motivo = null;

        return $previo;
    }

    /**
     * Activa el modo sistema (sin filtro) con motivo registrado.
     *
     * @return array{0: ?int, 1: bool, 2: ?string}
     */
    public static function entrarSistema(string $motivo, bool $registrar = true): array
    {
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw new \InvalidArgumentException('El modo sistema exige un motivo.');
        }

        $previo = self::instantanea();
        self::$empresaId = null;
        self::$sistema = true;
        self::$motivo = $motivo;

        if ($registrar) {
            Log::channel('tenant')->info('Modo sistema (sin filtro de empresa)', [
                'motivo' => $motivo,
                'contexto' => app()->runningInConsole() ? 'consola' : 'http',
                'ruta' => app()->runningInConsole() ? null : request()->path(),
            ]);
        }

        return $previo;
    }

    /**
     * @param  array{0: ?int, 1: bool, 2: ?string}  $previo
     */
    public static function restaurar(array $previo): void
    {
        [self::$empresaId, self::$sistema, self::$motivo] = $previo;
    }

    /**
     * @return array{0: ?int, 1: bool, 2: ?string}
     */
    public static function instantanea(): array
    {
        return [self::$empresaId, self::$sistema, self::$motivo];
    }

    /** Deja el contexto sin definir (inicio de cada test; fin de un proceso de larga vida). */
    public static function reiniciar(): void
    {
        self::$empresaId = null;
        self::$sistema = false;
        self::$motivo = null;
    }
}
