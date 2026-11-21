<?php

/*
| Publicación de ofertas en Agento (módulo PortalAgento, API pública /api/v1).
|
| El navegador de Agento solo puede llamar a la API si su origen está en CORS_ALLOWED_ORIGINS
| (config/cors.php): local http://localhost:5173; staging y producción, el dominio del portal.
*/
return [
    // Vigencia por defecto al renovar una publicación (días).
    'vigencia_dias' => (int) env('AGENTO_VIGENCIA_DIAS', 30),

    // URL pública del portal Agento (para el botón «Ver en Agento» del panel).
    'url_portal' => env('AGENTO_URL_PORTAL', 'http://localhost:5173'),

    // Correo de contacto del portal que se muestra en el perfil de la empresa (modo agencia: las
    // postulaciones las gestiona Agento, no la empresa).
    'contacto_email' => env('AGENTO_CONTACTO_EMAIL', 'empleos@agento.pe'),

    // Tamaño de empresa que se informa cuando no se conoce (el contrato exige el campo).
    'tamano_por_defecto' => '1-10',

    'cache' => [
        // Segundos de Cache-Control (público) por tipo de respuesta.
        'listado' => (int) env('AGENTO_CACHE_LISTADO', 60),
        'detalle' => (int) env('AGENTO_CACHE_DETALLE', 60),
        'catalogos' => (int) env('AGENTO_CACHE_CATALOGOS', 3600),
    ],

    // Peticiones por minuto y por IP a /api/v1 (limitador nombrado «agento-publico»).
    'limite_por_minuto' => (int) env('AGENTO_LIMITE_POR_MINUTO', 120),
    'limite_busqueda_por_minuto' => (int) env('AGENTO_LIMITE_BUSQUEDA_POR_MINUTO', 60),
];
