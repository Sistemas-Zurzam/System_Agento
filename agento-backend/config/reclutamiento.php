<?php

/*
| Selección y Reclutamiento — reglas de negocio configurables.
|
| Ninguno de estos valores es una constante legal: son decisiones del
| requerimiento funcional que cada despliegue puede ajustar por entorno.
*/

return [

    // Portal público: verificación de correo y acceso del candidato.
    'acceso' => [
        'codigo_minutos_vigencia' => (int) env('RECLUTAMIENTO_CODIGO_MINUTOS', 10),
        'codigo_max_intentos' => (int) env('RECLUTAMIENTO_CODIGO_INTENTOS', 5),
        'token_horas_vigencia' => (int) env('RECLUTAMIENTO_TOKEN_HORAS', 12),
        // Peticiones por minuto (por IP y por correo) para solicitar códigos.
        'limite_codigos_por_minuto' => (int) env('RECLUTAMIENTO_LIMITE_CODIGOS', 3),
        'limite_publico_por_minuto' => (int) env('RECLUTAMIENTO_LIMITE_PUBLICO', 60),
    ],

    'cv' => [
        'max_kb' => (int) env('RECLUTAMIENTO_CV_MAX_KB', 5120),
        'disco' => env('RECLUTAMIENTO_DISCO', 'local'),
    ],

    // Versión vigente del texto de consentimiento mostrado en el portal. Al
    // cambiar el texto en el frontend, incrementar esta versión: cada
    // consentimiento registrado guarda la versión y el hash del texto aceptado.
    'consentimiento' => [
        'version' => env('RECLUTAMIENTO_CONSENTIMIENTO_VERSION', '2026-10-v1'),
    ],

    'ats' => [
        'umbral_por_defecto' => 60,
        // Proveedor de extracción de CV / compatibilidad. "ninguno" deja la
        // captura manual y el cálculo por reglas sobre datos declarados.
        'proveedor' => env('RECLUTAMIENTO_ATS_PROVEEDOR', 'ninguno'),
        'timeout_segundos' => (int) env('RECLUTAMIENTO_ATS_TIMEOUT', 20),
    ],

    'pruebas' => [
        // Días que el candidato tiene para iniciar una prueba asignada.
        'dias_para_iniciar' => (int) env('RECLUTAMIENTO_PRUEBA_DIAS', 5),
    ],

    'invitaciones' => [
        'dias_vigencia' => (int) env('RECLUTAMIENTO_INVITACION_DIAS', 15),
    ],

    // Banco de talentos: retención desde la última postulación (regla de
    // negocio del requerimiento, no una constante legal).
    'retencion' => [
        'meses' => (int) env('RECLUTAMIENTO_RETENCION_MESES', 24),
        // La eliminación irreversible solo ocurre con esta bandera activa Y
        // el comando ejecutado con --ejecutar. Sin ambas, es ejecución de prueba.
        'eliminacion_habilitada' => (bool) env('RECLUTAMIENTO_RETENCION_ELIMINAR', false),
        // Excepciones configuradas: candidatos contratados conservan su
        // expediente de candidatura (los datos laborales viven en Personas y
        // nunca se tocan desde aquí).
        'excluir_contratados' => (bool) env('RECLUTAMIENTO_RETENCION_EXCLUIR_CONTRATADOS', true),
    ],

    // Derechos ARCO (Ley 29733). Plazos en DÍAS HÁBILES (lunes a viernes, sin descontar feriados).
    // Son valores iniciales y DEBEN ser validados por un abogado (docs/legal/pendientes.md).
    'arco' => [
        'plazo_dias_habiles' => [
            'acceso' => (int) env('RECLUTAMIENTO_ARCO_PLAZO_ACCESO', 20),
            'rectificacion' => (int) env('RECLUTAMIENTO_ARCO_PLAZO_RECTIFICACION', 10),
            'cancelacion' => (int) env('RECLUTAMIENTO_ARCO_PLAZO_CANCELACION', 10),
        ],
    ],

    // Antivirus de CV y documentos de candidatos (ver docs/runbooks/clamav.md).
    'antivirus' => [
        // clamav | nulo. El nulo solo da por bueno lo que no es EICAR en local/testing; fuera de ahí NO sirve.
        'driver' => env('ANTIVIRUS_DRIVER', env('APP_ENV') === 'production' ? 'clamav' : 'nulo'),
        // Qué hacer si el antivirus no responde: rechazar (el archivo sigue bloqueado y se reintenta,
        // falla CERRADO) | permitir (lo da por limpio y lo registra). Por defecto: rechazar en producción.
        'si_no_disponible' => env('ANTIVIRUS_SI_NO_DISPONIBLE', env('APP_ENV') === 'production' ? 'rechazar' : 'permitir'),
        'clamav' => [
            // unix:///var/run/clamav/clamd.ctl | tcp://127.0.0.1:3310
            'direccion' => env('CLAMAV_SOCKET', 'tcp://127.0.0.1:3310'),
            'timeout' => (int) env('CLAMAV_TIMEOUT', 20),
        ],
    ],

    // URL pública del frontend usada en correos (portal /empleos).
    'url_portal' => env('RECLUTAMIENTO_URL_PORTAL', env('FRONTEND_URL', env('APP_URL', 'http://localhost'))),
];
