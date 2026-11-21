# CRM: conversaciones de WhatsApp (Meta Cloud API)

Módulo `app/Modules/Crm` (backend) y `src/modules/crm` (frontend). Primera versión: bandeja de
conversaciones y respuesta manual. Sin agente IA, plantillas ni descarga de archivos (por ahora).

## Pantallas

| Menú | Ruta | Permiso |
|---|---|---|
| CRM → Conversaciones | `/crm/conversaciones` | `crm.conversaciones.ver` (ver) · `crm.conversaciones.responder` (responder) |
| CRM → Canales de WhatsApp | `/crm/canales` | `crm.canales.gestionar` |

El rol administrador ve todo. Los permisos se asignan a otros roles en Configuraciones → Permisos.

## Conectar un número (por empresa)

1. En Meta for Developers: app con el caso de uso de WhatsApp, número registrado y un **token
   permanente** de un usuario del sistema con acceso a la app y a la cuenta de WhatsApp.
2. En Agento, **CRM → Canales de WhatsApp → Nuevo canal**: Phone number ID, token permanente,
   App secret (Configuración de la app → Básica) y un token de verificación (se sugiere uno).
3. Copiar la **URL del webhook** y el **token de verificación** del canal en Meta → WhatsApp →
   Configuración → Webhook → *Verificar y guardar*; suscribir el campo `messages`.
4. Activar *Suscribir webhooks* en la tarjeta de la cuenta de WhatsApp de ese número y **publicar la
   app** (en modo desarrollo Meta solo envía avisos de prueba).

La URL del webhook se arma con `APP_URL`: debe ser el dominio HTTPS público del backend.

## Cómo funciona

- Webhook público `GET|POST /api/publico/crm/whatsapp/{token}` (throttle `crm-webhook`). Meta no envía
  la empresa: el canal se busca en modo sistema por el token secreto de 40 caracteres de la URL; lo demás
  corre en la empresa del canal (ver docs/seguridad/auditoria-scopes.md §4).
- Cada aviso se valida con `X-Hub-Signature-256` (HMAC con el App secret del canal). Firma inválida → 401.
- Mensajes entrantes: se guardan una vez (`wa_message_id` único). Los no textuales se describen
  (`[Imagen] pie`, `[Ubicación] …`). Estados de los salientes: enviado → entregado → leído (no retroceden) o
  fallido.
- Responder solo se permite dentro de la ventana de 24 h desde el último mensaje del cliente.
- El token de acceso y el App secret se guardan cifrados (`APP_KEY`) y nunca se devuelven al frontend.
- El texto de los mensajes va en la clave `texto_mensaje`, que la bitácora de auditoría oculta.
- La bandeja se refresca cada 10 s y el chat abierto cada 5 s (solo con la pestaña visible).

## Variables opcionales (`config/crm.php`)

`CRM_WHATSAPP_GRAPH_VERSION` (v25.0), `CRM_WHATSAPP_TIMEOUT` (15 s), `CRM_WEBHOOK_POR_MINUTO` (600).
