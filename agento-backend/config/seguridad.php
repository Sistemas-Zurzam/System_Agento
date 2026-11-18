<?php

/*
| Límites de seguridad de acceso. Todos configurables por .env para poder
| ajustarlos por entorno sin tocar código.
*/
return [
    'login' => [
        // POST /api/login: intentos por minuto por combinación login + IP.
        'por_minuto_login_ip' => (int) env('SEGURIDAD_LOGIN_POR_MINUTO_LOGIN_IP', 5),
        // POST /api/login: intentos por minuto por IP (cualquier login).
        'por_minuto_ip' => (int) env('SEGURIDAD_LOGIN_POR_MINUTO_IP', 20),
        // Bloqueo temporal de la cuenta tras N fallos consecutivos (desde cualquier IP).
        'max_fallos' => (int) env('SEGURIDAD_LOGIN_MAX_FALLOS', 5),
        'bloqueo_minutos' => (int) env('SEGURIDAD_LOGIN_BLOQUEO_MINUTOS', 15),
    ],

    'sesion' => [
        // POST /api/refresh por token (o IP si no hay token).
        'refresh_por_minuto' => (int) env('SEGURIDAD_REFRESH_POR_MINUTO', 10),
        // GET /api/me por token (o IP).
        'me_por_minuto' => (int) env('SEGURIDAD_ME_POR_MINUTO', 120),
    ],

    'portal' => [
        // Portal público de empleos, por IP (además de seleccion-publico).
        'listado_por_minuto' => (int) env('SEGURIDAD_PORTAL_LISTADO_POR_MINUTO', 30),
        'detalle_por_minuto' => (int) env('SEGURIDAD_PORTAL_DETALLE_POR_MINUTO', 60),
    ],
];
