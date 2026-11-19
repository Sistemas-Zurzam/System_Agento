# CORS y cabeceras de seguridad

## 1. CORS (`agento-backend/config/cors.php`)

Solo aplica a `api/*`. Los orígenes salen de `CORS_ALLOWED_ORIGINS` (lista separada por comas, con esquema y **sin barra final**). Se compilan a patrones exactos: un origen no listado no recibe ninguna cabecera `Access-Control-Allow-Origin`.

| Entorno | `CORS_ALLOWED_ORIGINS` |
|---|---|
| Local | `http://localhost:5173,http://127.0.0.1:5173` |
| Staging | `https://staging.agento.com.pe` |
| Producción (frontend y API en el mismo dominio, vía proxy de Apache) | *(vacío)*: no hay CORS, el navegador no lo necesita |
| Portal Agento (futuro dominio) | agregar `https://<dominio-del-portal>` cuando exista |

- **Métodos:** GET, POST, PUT, PATCH, DELETE, OPTIONS. **Cabeceras permitidas:** `Authorization`, `Content-Type`, `Accept`, `X-Requested-With`, `X-Candidato-Token`. **Expuestas:** `Retry-After`, `Content-Disposition`.
- **`supports_credentials = false`:** el JWT va en `Authorization` (no en cookies), así que no hace falta y un sitio ajeno no puede usar la sesión del navegador. Si algún día se pasa a cookies httpOnly (ver ADR de sesión), habrá que activarlo y fijar orígenes sin comodines.
- **Si aparece un error CORS tras desplegar:** el origen desde el que se llama no está en la lista. Agrégalo en el `.env` del servidor y ejecuta `php artisan config:cache`.
- **Riesgo del cambio:** antes se aceptaba cualquier origen. Si algún cliente externo (distinto del panel y del portal, que son same-origin en producción) llamaba a la API desde un navegador, dejará de funcionar hasta que se agregue. Las llamadas servidor a servidor (p. ej. webhook de Zazu) no se ven afectadas.

## 2. Cabeceras de la API (`app/Http/Middleware/CabecerasSeguridad.php`, valores en `config/cabeceras.php`)

| Cabecera | Valor | Nota |
|---|---|---|
| `X-Content-Type-Options` | `nosniff` | |
| `X-Frame-Options` | `SAMEORIGIN` | El panel muestra documentos en un `iframe` del mismo origen |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | |
| `Permissions-Policy` | `camera=(self), microphone=(), geolocation=(), payment=(), usb=()` | El control de acceso usa la cámara |
| `Strict-Transport-Security` | `max-age=31536000` | Solo en HTTPS; ver abajo |
| `Content-Security-Policy-Report-Only` | política en `config/cabeceras.php` | Modo por defecto |

**HSTS:** Laravel recibe las peticiones por HTTP desde el proxy de Apache, por lo que no ve HTTPS. Lo recomendable es ponerlo en Apache (§3). Si se prefiere desde Laravel: `CABECERAS_HSTS_FORZAR=true`. No se usa `includeSubDomains` ni `preload`: activarlos es una decisión de negocio (afecta a todos los subdominios y es difícil de revertir).

## 3. Cabeceras del frontend estático (Apache)

Laravel solo cubre las respuestas de `/api`. El HTML y los JS los sirve Apache, así que las mismas cabeceras deben ponerse allí (requiere `a2enmod headers`). Esto lo aplica el administrador; es un cambio de `agento.conf`, no de código:

```apache
<VirtualHost *:443>
    # ... configuración actual (certbot) ...
    Header always set Strict-Transport-Security "max-age=31536000"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Permissions-Policy "camera=(self), microphone=(), geolocation=(), payment=(), usb=()"
    # Mismo valor que config/cabeceras.php. Empezar en Report-Only.
    Header always set Content-Security-Policy-Report-Only "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-src 'self' blob:; frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; report-uri /api/seguridad/csp-report"
</VirtualHost>
```

Probar con `apachectl configtest && sudo systemctl reload apache2`. Para una prueba rápida de las cabeceras: `curl -sI https://<dominio>/ | grep -iE 'strict|x-frame|x-content|referrer|permissions|content-security'`.

## 4. CSP: de Report-Only a bloqueo

1. Mantener `Report-Only` y revisar `storage/logs/csp-AAAA-MM-DD.log` (canal `csp`; las URLs se registran sin consulta y con los tramos largos enmascarados como `[token]`).
2. Navegar por los flujos reales (panel, portal de empleos, pruebas, control de acceso con cámara, descargas) y anotar cada violación.
3. Ajustar la política: lo legítimo (p. ej. una imagen de otro host, fuentes externas) se agrega a la directiva correspondiente; lo inesperado se investiga. El `style-src 'unsafe-inline'` existe porque antd inyecta estilos; sustituirlo por nonces es una mejora posterior.
4. Cuando lleve **al menos una semana de uso real sin violaciones** (o solo con ruido de extensiones del navegador), cambiar a bloqueo:
   - API: `CSP_MODO=enforce` en el `.env` y `php artisan config:cache`.
   - Frontend: en Apache, cambiar `Content-Security-Policy-Report-Only` por `Content-Security-Policy` y recargar.
5. Plan de reversa: volver a `report-only` (o `off`) con la misma variable y recargar; no requiere despliegue.

## 5. Pruebas

`tests/Feature/Seguridad/CorsYCabecerasTest.php`: origen permitido recibe la cabecera, origen no permitido y `null` no la reciben (también en preflight), configuración sin comodines, cabeceras presentes (incluso en errores), HSTS solo con HTTPS, modos de CSP, y el endpoint de reportes (204 sin autenticación, no registra tokens ni consultas, límite de tasa).
