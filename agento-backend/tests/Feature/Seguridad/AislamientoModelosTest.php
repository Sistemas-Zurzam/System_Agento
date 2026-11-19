<?php

namespace Tests\Feature\Seguridad;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Support\EmpresaContext;
use App\Modules\Configuracion\Support\EmpresaNoDefinidaException;
use App\Modules\Reclutamiento\Models\Candidato;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InsertaFilasDeEmpresa;
use Tests\TestCase;

/**
 * EmpresaScope falla cerrado y aÃ­sla cada modelo con aislamiento por empresa.
 */
class AislamientoModelosTest extends TestCase
{
    use InsertaFilasDeEmpresa, RefreshDatabase;

    protected bool $aislamientoEstricto = true;

    protected function tearDown(): void
    {
        $this->restaurarClavesForaneas();

        parent::tearDown();
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function modelos(): array
    {
        $casos = [];
        foreach (self::modelosConAislamiento() as $clase) {
            $casos[class_basename($clase)] = [$clase];
        }

        return $casos;
    }

    public function test_se_detectan_los_modelos_con_aislamiento(): void
    {
        // Si este nÃºmero baja de golpe, la detecciÃ³n (o el atributo ScopedBy) estÃ¡ rota.
        $this->assertGreaterThanOrEqual(30, count(self::modelosConAislamiento()));
    }

    #[DataProvider('modelos')]
    public function test_cada_modelo_solo_ve_los_registros_de_su_empresa(string $clase): void
    {
        $this->diferirClavesForaneas();
        $tabla = (new $clase)->getTable();
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();
        $idA = $this->insertarFilaDeEmpresa($tabla, $empresaA->id);
        $idB = $this->insertarFilaDeEmpresa($tabla, $empresaB->id);

        EmpresaContext::como($empresaA->id, function () use ($clase, $idA, $idB) {
            $this->assertSame([$idA], $clase::query()->pluck('id')->map(fn ($id) => (int) $id)->all(), 'La empresa A solo ve lo suyo');
            $this->assertNotNull($clase::query()->find($idA));
            $this->assertNull($clase::query()->find($idB), 'La empresa A no encuentra el registro de B por id');
            $this->assertFalse($clase::query()->whereKey($idB)->exists());
        });

        EmpresaContext::como($empresaB->id, function () use ($clase, $idA, $idB) {
            $this->assertSame([$idB], $clase::query()->pluck('id')->map(fn ($id) => (int) $id)->all());
            $this->assertNull($clase::query()->find($idA));
        });

        $this->assertSame(2, EmpresaContext::comoSistema(fn () => $clase::query()->count(), motivo: 'test: verificar que el modo sistema ve todas las empresas'));
    }

    #[DataProvider('modelos')]
    public function test_sin_contexto_de_empresa_la_consulta_falla_cerrada(string $clase): void
    {
        $this->expectException(EmpresaNoDefinidaException::class);

        $clase::query()->count();
    }

    public function test_en_produccion_sin_contexto_no_se_devuelven_filas(): void
    {
        $this->diferirClavesForaneas();
        $empresa = Empresa::factory()->create();
        $clase = Candidato::class;
        $this->insertarFilaDeEmpresa((new $clase)->getTable(), $empresa->id);
        $this->assertSame(1, EmpresaContext::comoSistema(fn () => $clase::query()->count(), motivo: 'test: comprobar que el dato existe'));

        $this->app['env'] = 'production';

        $this->assertSame(0, $clase::query()->count(), 'En producciÃ³n, sin contexto, la consulta no devuelve nada (en vez de todo).');
        $this->assertNull($clase::query()->first());
    }

    public function test_el_modo_sistema_exige_motivo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EmpresaContext::comoSistema(fn () => null, motivo: '  ');
    }

    public function test_los_contextos_anidados_se_restauran_incluso_con_excepciones(): void
    {
        $this->assertNull(EmpresaContext::actual());

        EmpresaContext::como(1, function () {
            EmpresaContext::comoSistema(function () {
                $this->assertTrue(EmpresaContext::esSistema());
                $this->assertNull(EmpresaContext::actual());
            }, motivo: 'test: anidado');

            $this->assertSame(1, EmpresaContext::actual());
            $this->assertFalse(EmpresaContext::esSistema());

            try {
                EmpresaContext::como(2, fn () => throw new \RuntimeException('x'));
            } catch (\RuntimeException) {
            }
            $this->assertSame(1, EmpresaContext::actual(), 'Tras una excepciÃ³n vuelve a la empresa anterior');
        });

        $this->assertNull(EmpresaContext::actual());
        $this->assertFalse(EmpresaContext::esSistema());
    }
}
