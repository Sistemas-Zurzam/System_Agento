<?php

namespace Tests\Unit\PortalAgento;

use App\Modules\PortalAgento\Domain\FlujoPublicacion;
use App\Modules\PortalAgento\Models\Publicacion;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FlujoPublicacionTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function transiciones(): array
    {
        return [
            'borrador publica' => ['borrador', 'publicar', 'publicada'],
            'borrador programa' => ['borrador', 'programar', 'programada'],
            'programada publica' => ['programada', 'publicar', 'publicada'],
            'publicada se pausa' => ['publicada', 'pausar', 'pausada'],
            'pausada se reanuda' => ['pausada', 'reanudar', 'publicada'],
            'publicada se retira' => ['publicada', 'retirar', 'retirada'],
            'vencida se renueva' => ['vencida', 'renovar', 'publicada'],
            'retirada se renueva' => ['retirada', 'renovar', 'publicada'],
        ];
    }

    #[DataProvider('transiciones')]
    public function test_transiciones_permitidas(string $desde, string $accion, string $hasta): void
    {
        $this->assertSame($hasta, FlujoPublicacion::aplicar($desde, $accion));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function prohibidas(): array
    {
        return [
            'borrador no se pausa' => ['borrador', 'pausar'],
            'publicada no se publica otra vez' => ['publicada', 'publicar'],
            'retirada no se pausa' => ['retirada', 'pausar'],
            'vencida no se reanuda' => ['vencida', 'reanudar'],
            'borrador no se retira' => ['borrador', 'retirar'],
        ];
    }

    #[DataProvider('prohibidas')]
    public function test_transiciones_prohibidas(string $desde, string $accion): void
    {
        $this->expectException(ValidationException::class);

        FlujoPublicacion::aplicar($desde, $accion);
    }

    public function test_acciones_disponibles_por_estado(): void
    {
        $this->assertSame(['publicar', 'programar'], FlujoPublicacion::accionesDisponibles('borrador'));
        $this->assertSame(['pausar', 'retirar', 'renovar'], FlujoPublicacion::accionesDisponibles('publicada'));
        $this->assertSame(['renovar'], FlujoPublicacion::accionesDisponibles('vencida'));
    }

    public function test_el_estado_segun_el_proceso(): void
    {
        $ahora = now();
        $publicacion = fn (string $estado, array $extra = []) => new Publicacion(['estado_publicacion' => $estado, ...$extra]);
        $proceso = fn (string $estado, array $extra = []) => (new ProcesoSeleccion(['estado' => $estado, 'publicacion_externa' => true, ...$extra]));

        $this->assertSame('retirada', FlujoPublicacion::estadoSegunProceso($publicacion('publicada'), $proceso('cerrado'), $ahora)['estado']);
        $this->assertSame('retirada', FlujoPublicacion::estadoSegunProceso($publicacion('pausada'), $proceso('cubierto'), $ahora)['estado']);
        $this->assertSame('retirada', FlujoPublicacion::estadoSegunProceso($publicacion('publicada'), $proceso('abierto', ['publicacion_externa' => false]), $ahora)['estado']);
        $this->assertSame('pausada', FlujoPublicacion::estadoSegunProceso($publicacion('publicada'), $proceso('en_pausa'), $ahora)['estado']);
        $this->assertSame('vencida', FlujoPublicacion::estadoSegunProceso($publicacion('publicada', ['vigente_hasta' => $ahora->copy()->subMinute()]), $proceso('abierto'), $ahora)['estado']);

        // Reanuda solo la pausa que provocó el proceso.
        $this->assertSame('publicada', FlujoPublicacion::estadoSegunProceso($publicacion('pausada', ['pausada_por' => 'proceso']), $proceso('abierto'), $ahora)['estado']);
        $this->assertNull(FlujoPublicacion::estadoSegunProceso($publicacion('pausada', ['pausada_por' => 'agento']), $proceso('abierto'), $ahora));

        // Sin cambios: nada que hacer.
        $this->assertNull(FlujoPublicacion::estadoSegunProceso($publicacion('publicada'), $proceso('abierto'), $ahora));
        $this->assertNull(FlujoPublicacion::estadoSegunProceso($publicacion('borrador'), $proceso('cerrado'), $ahora));
        $this->assertNull(FlujoPublicacion::estadoSegunProceso($publicacion('retirada'), $proceso('abierto'), $ahora));
    }
}
