<?php

namespace Tests\Unit;

use App\Modules\Reclutamiento\Domain\ResultadoEscaneo;
use App\Modules\Reclutamiento\Infrastructure\Antivirus\EscanerClamAv;
use PHPUnit\Framework\TestCase;

/**
 * Protocolo INSTREAM de clamd, contra un clamd simulado (stream wrapper): sin red ni ClamAV real.
 */
class EscanerClamAvTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('fakeclamd', stream_get_wrappers(), true)) {
            stream_wrapper_register('fakeclamd', ClamdSimulado::class);
        }
        ClamdSimulado::$respuesta = '';
        ClamdSimulado::$escrito = '';
    }

    private function escaner(?\Closure $abrir = null): EscanerClamAv
    {
        return new EscanerClamAv('tcp://clamd.simulado:3310', 5, $abrir ?? fn () => fopen('fakeclamd://conexion', 'r+'));
    }

    /**
     * @return resource
     */
    private function archivo(string $contenido)
    {
        $flujo = fopen('php://memory', 'r+');
        fwrite($flujo, $contenido);
        rewind($flujo);

        return $flujo;
    }

    public function test_respuesta_ok_es_limpio(): void
    {
        ClamdSimulado::$respuesta = "stream: OK\0";

        $this->assertSame(ResultadoEscaneo::LIMPIO, $this->escaner()->escanear($this->archivo('hola'))->estado);
    }

    public function test_respuesta_found_es_infectado_con_la_firma(): void
    {
        ClamdSimulado::$respuesta = "stream: Eicar-Test-Signature FOUND\0";

        $resultado = $this->escaner()->escanear($this->archivo('x'));

        $this->assertSame(ResultadoEscaneo::INFECTADO, $resultado->estado);
        $this->assertSame('Eicar-Test-Signature', $resultado->detalle);
    }

    public function test_respuesta_error_o_vacia_nunca_es_limpio(): void
    {
        foreach (["INSTREAM size limit exceeded. ERROR\0", '', 'cualquier cosa'] as $respuesta) {
            ClamdSimulado::$respuesta = $respuesta;

            $this->assertSame(ResultadoEscaneo::NO_DISPONIBLE, $this->escaner()->escanear($this->archivo('x'))->estado, "Respuesta: {$respuesta}");
        }
    }

    public function test_si_no_se_puede_conectar_es_no_disponible_y_no_lanza(): void
    {
        $resultado = $this->escaner(fn () => false)->escanear($this->archivo('x'));

        $this->assertSame(ResultadoEscaneo::NO_DISPONIBLE, $resultado->estado);
    }

    public function test_si_la_conexion_lanza_excepcion_es_no_disponible(): void
    {
        $resultado = $this->escaner(fn () => throw new \RuntimeException('socket roto'))->escanear($this->archivo('x'));

        $this->assertSame(ResultadoEscaneo::NO_DISPONIBLE, $resultado->estado);
        $this->assertStringContainsString('socket roto', (string) $resultado->detalle);
    }

    public function test_envia_el_protocolo_instream_con_bloques_y_terminador(): void
    {
        ClamdSimulado::$respuesta = "stream: OK\0";
        $contenido = str_repeat('A', 20000);

        $this->escaner()->escanear($this->archivo($contenido));

        $enviado = ClamdSimulado::$escrito;
        $this->assertStringStartsWith("zINSTREAM\0", $enviado);
        $resto = substr($enviado, strlen("zINSTREAM\0"));
        $reconstruido = '';
        while (true) {
            $longitud = unpack('N', substr($resto, 0, 4))[1];
            $resto = substr($resto, 4);
            if ($longitud === 0) {
                break;
            }
            $reconstruido .= substr($resto, 0, $longitud);
            $resto = substr($resto, $longitud);
        }
        $this->assertSame($contenido, $reconstruido, 'El archivo viaja completo en bloques [longitud][datos]');
        $this->assertSame('', $resto, 'Tras el bloque de longitud 0 no se envía nada más');
    }
}

/**
 * clamd de mentira: registra lo que se escribe y sirve una respuesta fija.
 */
class ClamdSimulado
{
    public static string $respuesta = '';

    public static string $escrito = '';

    /** @var resource|null */
    public $context;

    private int $posicion = 0;

    public function stream_open(string $ruta, string $modo, int $opciones, ?string &$rutaAbierta): bool
    {
        self::$escrito = '';
        $this->posicion = 0;

        return true;
    }

    public function stream_write(string $datos): int
    {
        self::$escrito .= $datos;

        return strlen($datos);
    }

    public function stream_read(int $cantidad): string|false
    {
        $trozo = substr(self::$respuesta, $this->posicion, $cantidad);
        $this->posicion += strlen($trozo);

        return $trozo;
    }

    public function stream_eof(): bool
    {
        return $this->posicion >= strlen(self::$respuesta);
    }

    public function stream_set_option(int $opcion, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }
}
