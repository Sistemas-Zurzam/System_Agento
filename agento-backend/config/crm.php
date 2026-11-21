<?php

/*
| Módulo CRM: conversaciones de WhatsApp Cloud API (Meta).
|
| La URL del webhook de cada canal se arma con APP_URL (debe ser HTTPS y accesible desde Internet para
| que Meta la verifique).
*/
return [
    // Versión de la Graph API de Meta.
    'graph_version' => env('CRM_WHATSAPP_GRAPH_VERSION', 'v25.0'),

    'graph_url' => env('CRM_WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),

    // Segundos de espera al enviar un mensaje a Meta.
    'timeout' => (int) env('CRM_WHATSAPP_TIMEOUT', 15),

    // Avisos de Meta por minuto y por canal (limitador «crm-webhook»).
    'webhook_por_minuto' => (int) env('CRM_WEBHOOK_POR_MINUTO', 600),

    // Mensajes que se devuelven al abrir una conversación (los más recientes).
    'mensajes_por_conversacion' => 200,
];
