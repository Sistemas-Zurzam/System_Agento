<?php

namespace App\Modules\Integraciones\Domain;

/**
 * Regla de pago por clase (pura: sin base de datos ni HTTP). Los importes se manejan como cadenas con
 * bcmath, nunca como float, para que el total de las clases coincida al centavo con lo que luego se abona.
 *
 *  - Una clase «dictada» se paga a QUIEN LA DICTÓ con la tarifa de la TITULAR vigente a la fecha de la clase
 *    (si la titular la dicta, su propia tarifa; si la suple otra, la de la titular).
 *  - «cancelada_estudio» y «no_dictada» no se pagan.
 *  - Una clase dictada cuya titular no tiene tarifa no se puede pagar: se reporta con motivo.
 */
final class CalculadoraPagoClases
{
    public const MOTIVO_SIN_TARIFA = 'La instructora titular no tiene tarifa vigente a la fecha de la clase.';

    /**
     * @param  list<array{clase_id: string, estado: string, ejecutor_id: ?int, tarifa_titular: ?string}>  $clases
     * @return array{
     *     clases: array<string, array{monto: string, tarifa: ?string, motivo: ?string}>,
     *     por_colaborador: array<int, array{clases: int, bruto: string}>
     * }
     */
    public function calcular(array $clases): array
    {
        $resultado = [];
        $porColaborador = [];

        foreach ($clases as $clase) {
            if ($clase['estado'] !== 'dictada') {
                $resultado[$clase['clase_id']] = ['monto' => '0.00', 'tarifa' => null, 'motivo' => null];

                continue;
            }

            if ($clase['ejecutor_id'] === null) {
                $resultado[$clase['clase_id']] = ['monto' => '0.00', 'tarifa' => null, 'motivo' => 'No se sabe quién dictó la clase.'];

                continue;
            }

            $tarifa = $clase['tarifa_titular'];
            if ($tarifa === null) {
                $resultado[$clase['clase_id']] = ['monto' => '0.00', 'tarifa' => null, 'motivo' => self::MOTIVO_SIN_TARIFA];

                continue;
            }

            $monto = bcadd($tarifa, '0', 2);
            $resultado[$clase['clase_id']] = ['monto' => $monto, 'tarifa' => $monto, 'motivo' => null];

            $acumulado = $porColaborador[$clase['ejecutor_id']] ?? ['clases' => 0, 'bruto' => '0.00'];
            $porColaborador[$clase['ejecutor_id']] = [
                'clases' => $acumulado['clases'] + 1,
                'bruto' => bcadd($acumulado['bruto'], $monto, 2),
            ];
        }

        return ['clases' => $resultado, 'por_colaborador' => $porColaborador];
    }
}
