<?php

/*
| CORS: solo para la API y solo para los orígenes declarados en .env.
|
| CORS_ALLOWED_ORIGINS = lista separada por comas, sin barra final, con esquema:
|   local:       http://localhost:5173,http://127.0.0.1:5173
|   staging:     https://staging.agento.com.pe
|   producción:  (vacío si el frontend se sirve bajo el mismo dominio que la API,
|                 que es el caso de agento.com.pe: Apache proxea /api/, no hay CORS)
|   Agento (portal futuro): https://<dominio-del-portal>   ← agregar cuando exista
|
| Vacío = ningún origen externo. El JWT viaja en la cabecera Authorization (no en
| cookies), por eso supports_credentials es false: un sitio ajeno no puede
| aprovechar la sesión del navegador del usuario.
*/
return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // Se declaran como patrones exactos (no como lista): con un único origen en la lista la
    // librería lo devolvería también a los orígenes no permitidos; con patrones, un origen
    // no listado no recibe ninguna cabecera Access-Control-Allow-Origin.
    'allowed_origins' => [],

    'allowed_origins_patterns' => array_map(
        fn (string $origen) => '#^'.preg_quote($origen, '#').'$#',
        array_values(array_filter(array_map(
            fn (string $origen) => rtrim(trim($origen), '/'),
            explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
        ))),
    ),

    // Solo las cabeceras que usan los clientes (axios del panel y del portal de empleos).
    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Requested-With', 'X-Candidato-Token'],

    // Cabeceras legibles desde JavaScript en peticiones cross-origin.
    'exposed_headers' => ['Retry-After', 'Content-Disposition'],

    'max_age' => 600,

    'supports_credentials' => false,
];
