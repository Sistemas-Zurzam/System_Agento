<?php

namespace App\Modules\Integraciones\Domain;

/**
 * Retención de renta de cuarta categoría de un recibo por honorarios (pura, con bcmath).
 *
 * Regla aplicada, igual a la de los pagos de locadores que ya hace Agento: se retiene `tasa` sobre el bruto
 * cuando el bruto SUPERA el umbral y el locador no tiene suspensión de retenciones. Tasa y umbral vienen de
 * los parámetros laborales de la empresa (nunca fijos en el código). La regla tributaria definitiva la debe
 * validar el contador del cliente (docs/legal/pendientes.md).
 */
final class CalculadoraRetencion4ta
{
    /**
     * @param  string  $bruto  importe con 2 decimales
     * @param  string  $umbral  importe a partir del cual (exclusive) se retiene
     * @param  string  $tasa  fracción (0.08 = 8 %)
     */
    public function calcular(string $bruto, string $umbral, string $tasa, bool $suspensionVigente): string
    {
        if ($suspensionVigente || bccomp($bruto, $umbral, 2) <= 0) {
            return '0.00';
        }

        // +0.005 antes de truncar a 2 decimales equivale a redondear medio hacia arriba.
        return bcadd(bcmul($bruto, $tasa, 6), '0.005', 2);
    }
}
