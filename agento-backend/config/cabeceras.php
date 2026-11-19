<?php

/*
| Cabeceras de seguridad HTTP de las respuestas servidas por Laravel (la API).
| Las del frontend estático las pone Apache: ver docs/seguridad/cabeceras-y-cors.md.
*/
return [
    // Strict-Transport-Security: solo se envía en HTTPS (o si se fuerza). Detrás del proxy
    // Apache la petición llega a Laravel por HTTP, así que lo recomendable es ponerlo en
    // Apache; CABECERAS_HSTS_FORZAR=true lo emite también desde Laravel.
    'hsts' => env('CABECERAS_HSTS', 'max-age=31536000'),
    'hsts_forzar' => (bool) env('CABECERAS_HSTS_FORZAR', false),

    'x_frame_options' => 'SAMEORIGIN',
    'referrer_policy' => 'strict-origin-when-cross-origin',
    // El panel usa la cámara (lector de carnets en Asistencia → Control de acceso): self.
    'permissions_policy' => 'camera=(self), microphone=(), geolocation=(), payment=(), usb=()',

    // Content-Security-Policy. Modo: report-only (por defecto) | enforce | off.
    // Pasar a enforce: ver docs/seguridad/cabeceras-y-cors.md.
    'csp_modo' => env('CSP_MODO', 'report-only'),
    'csp' => env('CSP_POLITICA', implode('; ', [
        "default-src 'self'",
        "script-src 'self'",
        "style-src 'self' 'unsafe-inline'",
        "img-src 'self' data: blob:",
        "font-src 'self' data:",
        "connect-src 'self'",
        "frame-src 'self' blob:",
        "frame-ancestors 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        'report-uri /api/seguridad/csp-report',
    ])),
];
