<?php

namespace App\Modules\Reclutamiento\Infrastructure\Antivirus;

use App\Modules\Reclutamiento\Domain\Contracts\EscanerArchivos;
use App\Modules\Reclutamiento\Domain\ResultadoEscaneo;
use Closure;
use Throwable;

/**
 * Cliente de clamd (protocolo INSTREAM) por socket Unix o TCP.
 *
 *   CLAMAV_SOCKET=unix:///var/run/clamav/clamd.ctl   (socket Unix)
 *   CLAMAV_SOCKET=tcp://127.0.0.1:3310               (TCP)
 *
 * Protocolo: "zINSTREAM\0", luego bloques [longitud 4 bytes big-endian][datos], y un bloque de
 * longitud 0 para terminar. clamd responde "stream: OK", "stream: <firma> FOUND" o "... ERROR".
 * Cualquier fallo de conexión, tiempo o respuesta inesperada es "no disponible": jamás "limpio".
 */
class EscanerClamAv implements EscanerArchivos
{
    private const TAMANO_BLOQUE = 8192;

    /**
     * @param  (Closure(string): resource|false)|null  $abrirConexion  inyectable para pruebas
     */
    public function __construct(
        private readonly string $direccion,
        private readonly int $timeoutSegundos = 20,
        private readonly ?Closure $abrirConexion = null,
    ) {}

    public function escanear($contenido): ResultadoEscaneo
    {
        $conexion = null;

        try {
            $conexion = $this->abrir();
            if (! $conexion) {
                return ResultadoEscaneo::noDisponible("No se pudo conectar con clamd en {$this->direccion}.");
            }
            stream_set_timeout($conexion, $this->timeoutSegundos);

            if (fwrite($conexion, "zINSTREAM\0") === false) {
                return ResultadoEscaneo::noDisponible('No se pudo escribir en clamd.');
            }

            while (! feof($contenido)) {
                $bloque = fread($contenido, self::TAMANO_BLOQUE);
                if ($bloque === false) {
                    return ResultadoEscaneo::noDisponible('No se pudo leer el archivo a escanear.');
                }
                if ($bloque === '') {
                    continue;
                }
                if (! $this->escribir($conexion, pack('N', strlen($bloque)).$bloque)) {
                    // clamd puede cortar si el archivo supera StreamMaxLength: puede haber dejado la respuesta.
                    break;
                }
            }
            $this->escribir($conexion, pack('N', 0));

            $respuesta = trim((string) stream_get_contents($conexion), "\0 \n\r\t");

            return $this->interpretar($respuesta);
        } catch (Throwable $e) {
            return ResultadoEscaneo::noDisponible('Error al hablar con clamd: '.$e->getMessage());
        } finally {
            if (is_resource($conexion)) {
                fclose($conexion);
            }
        }
    }

    private function interpretar(string $respuesta): ResultadoEscaneo
    {
        if (preg_match('/^stream:\s*(.+?)\s+FOUND$/', $respuesta, $m)) {
            return ResultadoEscaneo::infectado($m[1]);
        }
        if (preg_match('/^stream:\s*OK$/', $respuesta)) {
            return ResultadoEscaneo::limpio();
        }

        return ResultadoEscaneo::noDisponible('Respuesta inesperada de clamd: '.($respuesta === '' ? '(vacía)' : substr($respuesta, 0, 120)));
    }

    /**
     * @param  resource  $conexion
     */
    private function escribir($conexion, string $datos): bool
    {
        $pendiente = $datos;
        while ($pendiente !== '') {
            $escrito = @fwrite($conexion, $pendiente);
            if ($escrito === false || $escrito === 0) {
                return false;
            }
            $pendiente = substr($pendiente, $escrito);
        }

        return true;
    }

    /**
     * @return resource|false
     */
    private function abrir()
    {
        if ($this->abrirConexion) {
            return ($this->abrirConexion)($this->direccion);
        }

        return @stream_socket_client($this->direccion, $codigo, $mensaje, $this->timeoutSegundos);
    }
}
