<?php

namespace Tests\Concerns;

use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;

/**
 * Inserta una fila mínima válida en cualquier tabla con `empresa_id`, rellenando las columnas
 * obligatorias según su tipo. Sirve para sembrar "un registro de la empresa A y otro de la B"
 * en cada modelo con aislamiento sin conocer sus reglas de negocio (las FK se difieren: la
 * transacción del test nunca se confirma).
 */
trait InsertaFilasDeEmpresa
{
    private static int $contadorFilas = 0;

    /**
     * Modelos Eloquent con aislamiento por empresa (atributo #[ScopedBy([EmpresaScope::class])]).
     *
     * @return list<class-string<Model>>
     */
    public static function modelosConAislamiento(): array
    {
        $base = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'app';
        $modelos = [];

        foreach (self::archivosPhp($base) as $archivo) {
            $clase = 'App\\'.str_replace(['/', '\\', '.php'], ['\\', '\\', ''], substr($archivo, strlen($base) + 1));
            if (! class_exists($clase) || ! is_subclass_of($clase, Model::class)) {
                continue;
            }
            foreach ((new ReflectionClass($clase))->getAttributes(ScopedBy::class) as $atributo) {
                if (in_array(EmpresaScope::class, (array) $atributo->getArguments()[0], true)) {
                    $modelos[] = $clase;
                }
            }
            // Registrado a mano en boot() (p. ej. Area).
            $codigo = (string) file_get_contents($archivo);
            if (str_contains($codigo, 'addGlobalScope') && str_contains($codigo, 'EmpresaScope')) {
                $modelos[] = $clase;
            }
        }

        $modelos = array_values(array_unique($modelos));
        sort($modelos);

        return $modelos;
    }

    /**
     * @return list<string>
     */
    private static function archivosPhp(string $directorio): array
    {
        $archivos = [];
        $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directorio, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterador as $archivo) {
            if ($archivo->isFile() && $archivo->getExtension() === 'php' && str_contains($archivo->getPathname(), 'Models')) {
                $archivos[] = $archivo->getPathname();
            }
        }

        return $archivos;
    }

    protected function insertarFilaDeEmpresa(string $tabla, int $empresaId): int
    {
        $fila = [];
        $checks = $this->valoresPermitidos($tabla);

        foreach (Schema::getColumns($tabla) as $columna) {
            $nombre = $columna['name'];
            if ($columna['auto_increment']) {
                continue;
            }
            if ($nombre === 'empresa_id') {
                $fila[$nombre] = $empresaId;

                continue;
            }
            if ($columna['nullable'] || $columna['default'] !== null) {
                continue;
            }
            $fila[$nombre] = $checks[$nombre] ?? $this->valorPara((string) $columna['type_name']);
        }

        return (int) DB::table($tabla)->insertGetId($fila);
    }

    private function valorPara(string $tipo): mixed
    {
        $n = ++self::$contadorFilas;
        $tipo = strtolower($tipo);

        return match (true) {
            str_contains($tipo, 'int'), $tipo === 'boolean' => $n,
            in_array($tipo, ['decimal', 'float', 'double', 'numeric', 'real'], true) => $n,
            $tipo === 'date' => '2026-01-01',
            in_array($tipo, ['datetime', 'timestamp'], true) => '2026-01-01 00:00:00',
            $tipo === 'time' => '00:00:00',
            $tipo === 'json' => '{}',
            default => 'x'.$n,
        };
    }

    /**
     * Valores admitidos por columnas enum (en SQLite son CHECK ... IN (...)).
     *
     * @return array<string, string>
     */
    private function valoresPermitidos(string $tabla): array
    {
        $sql = (string) DB::table('sqlite_master')->where('name', $tabla)->value('sql');
        $permitidos = [];
        if (preg_match_all('/"(\w+)"\s+in\s*\(\s*\'([^\']*)\'/i', $sql, $coincidencias, PREG_SET_ORDER)) {
            foreach ($coincidencias as $c) {
                $permitidos[$c[1]] = $c[2];
            }
        }

        return $permitidos;
    }

    protected function diferirClavesForaneas(): void
    {
        // Dentro de la transacción del test: las FK se comprueban al confirmar, y nunca se confirma.
        DB::statement('PRAGMA defer_foreign_keys = ON');
    }
}
