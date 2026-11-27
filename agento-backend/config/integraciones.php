<?php

/*
| Módulo Integraciones: sistemas externos que envían datos a Agento por API (máquina a máquina, sin JWT).
|
| Cada empresa recibe su propia credencial (token). La empresa SIEMPRE sale de la credencial, nunca del
| cuerpo de la petición.
*/
return [
    // Peticiones por minuto y por IP al endpoint de recepción (limitador «integraciones»).
    'limite_por_minuto' => (int) env('INTEGRACIONES_POR_MINUTO', 60),

    // Tope de clases por envío (un lote mensual de un estudio pequeño queda muy por debajo).
    'max_clases_por_envio' => (int) env('INTEGRACIONES_MAX_CLASES', 1000),
];
