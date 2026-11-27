<?php

namespace Tests\Unit\Modules\Integraciones;

use App\Modules\Integraciones\Domain\CalculadoraPagoClases;
use App\Modules\Integraciones\Domain\CalculadoraRetencion4ta;
use PHPUnit\Framework\TestCase;

class CalculadoraPagoClasesTest extends TestCase
{
    private const LUCHITA = 1;

    private const PEPITA = 2;

    private function clase(string $id, string $estado, ?int $ejecutor, ?string $tarifaTitular): array
    {
        return ['clase_id' => $id, 'estado' => $estado, 'ejecutor_id' => $ejecutor, 'tarifa_titular' => $tarifaTitular];
    }

    public function test_la_titular_cobra_su_propia_tarifa(): void
    {
        $r = (new CalculadoraPagoClases)->calcular([$this->clase('A', 'dictada', self::LUCHITA, '80.00')]);

        $this->assertSame('80.00', $r['clases']['A']['monto']);
        $this->assertSame(['clases' => 1, 'bruto' => '80.00'], $r['por_colaborador'][self::LUCHITA]);
    }

    public function test_la_suplente_cobra_la_tarifa_de_la_titular(): void
    {
        // Luchita (80) está enferma y la suple Pepita (60): Pepita cobra 80, no 60.
        $r = (new CalculadoraPagoClases)->calcular([$this->clase('A', 'dictada', self::PEPITA, '80.00')]);

        $this->assertSame('80.00', $r['clases']['A']['monto']);
        $this->assertArrayNotHasKey(self::LUCHITA, $r['por_colaborador']);
        $this->assertSame(['clases' => 1, 'bruto' => '80.00'], $r['por_colaborador'][self::PEPITA]);
    }

    public function test_acumula_clases_propias_y_suplencias_de_la_misma_instructora(): void
    {
        $r = (new CalculadoraPagoClases)->calcular([
            $this->clase('A', 'dictada', self::PEPITA, '80.00'),
            $this->clase('B', 'dictada', self::PEPITA, '60.00'),
        ]);

        $this->assertSame(['clases' => 2, 'bruto' => '140.00'], $r['por_colaborador'][self::PEPITA]);
    }

    public function test_las_clases_no_dictadas_o_canceladas_no_se_pagan(): void
    {
        $r = (new CalculadoraPagoClases)->calcular([
            $this->clase('A', 'cancelada_estudio', null, '80.00'),
            $this->clase('B', 'no_dictada', self::LUCHITA, '80.00'),
        ]);

        $this->assertSame('0.00', $r['clases']['A']['monto']);
        $this->assertSame('0.00', $r['clases']['B']['monto']);
        $this->assertSame([], $r['por_colaborador']);
    }

    public function test_sin_tarifa_de_la_titular_la_clase_no_se_paga_y_se_explica(): void
    {
        $r = (new CalculadoraPagoClases)->calcular([$this->clase('A', 'dictada', self::PEPITA, null)]);

        $this->assertSame('0.00', $r['clases']['A']['monto']);
        $this->assertSame(CalculadoraPagoClases::MOTIVO_SIN_TARIFA, $r['clases']['A']['motivo']);
        $this->assertSame([], $r['por_colaborador']);
    }

    public function test_una_clase_dictada_sin_ejecutora_no_se_paga(): void
    {
        $r = (new CalculadoraPagoClases)->calcular([$this->clase('A', 'dictada', null, '80.00')]);

        $this->assertSame('0.00', $r['clases']['A']['monto']);
        $this->assertNotNull($r['clases']['A']['motivo']);
    }

    public function test_los_centavos_suman_exactos_sin_error_de_decimales(): void
    {
        $clases = [];
        for ($i = 0; $i < 10; $i++) {
            $clases[] = $this->clase("C$i", 'dictada', self::PEPITA, '0.10');
        }

        $this->assertSame('1.00', (new CalculadoraPagoClases)->calcular($clases)['por_colaborador'][self::PEPITA]['bruto']);
    }

    public function test_retencion_bajo_o_igual_al_umbral_es_cero(): void
    {
        $c = new CalculadoraRetencion4ta;

        $this->assertSame('0.00', $c->calcular('1500.00', '1500.00', '0.080000', false));
        $this->assertSame('0.00', $c->calcular('140.00', '1500.00', '0.080000', false));
    }

    public function test_retencion_sobre_el_umbral_aplica_la_tasa(): void
    {
        $this->assertSame('128.00', (new CalculadoraRetencion4ta)->calcular('1600.00', '1500.00', '0.080000', false));
    }

    public function test_retencion_redondea_medio_hacia_arriba(): void
    {
        // 1500.07 × 8 % = 120.0056 → 120.01
        $this->assertSame('120.01', (new CalculadoraRetencion4ta)->calcular('1500.07', '1500.00', '0.080000', false));
        // 1500.06 × 8 % = 120.0048 → 120.00
        $this->assertSame('120.00', (new CalculadoraRetencion4ta)->calcular('1500.06', '1500.00', '0.080000', false));
    }

    public function test_con_suspension_de_retenciones_no_se_retiene(): void
    {
        $this->assertSame('0.00', (new CalculadoraRetencion4ta)->calcular('5000.00', '1500.00', '0.080000', true));
    }
}
