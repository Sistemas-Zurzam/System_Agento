# ADR: almacenamiento y ciclo de vida de los tokens de sesión del panel

- **Estado:** PROPUESTO. Recomendación al final; **no se implementa el cambio de almacenamiento sin aprobación**.
- **Fecha:** 2026-11-19
- **Contexto:** hallazgo "JWT de 720 min en `localStorage`" (`docs/analisis-integracion-agento.md`, §8.1).

## 1. Situación

- El panel interno guarda el JWT de acceso en `localStorage` (`access_token`) y lo envía en `Authorization: Bearer` (`agento-frontend/src/services/api.js`).
- Cualquier script que corra en la página (una dependencia comprometida, un XSS) puede **leer el token y llevárselo**: `localStorage` no tiene ninguna protección contra JavaScript. Es el riesgo central de este ADR.
- Antes duraba 720 min (12 h) y el frontend **no tenía renovación**, así que un token robado servía hasta medio día y la sesión se cortaba a las 12 h aunque el usuario estuviera trabajando.
- El backend ya soporta renovación (`POST /api/refresh`, ventana `JWT_REFRESH_TTL` de 14 días), invalidación al renovar (lista negra) y revocación inmediata de sesiones (`token_version`, `JwtMiddleware`).
- La API y el frontend se sirven bajo **el mismo dominio** (Apache hace proxy de `/api/`, `agento-frontend/apache.conf`), lo que hace viable el uso de cookies.

## 2. Qué se hizo ya (este PR, sin cambiar el almacenamiento)

1. `JWT_TTL` por defecto baja de 720 a **60 min**.
2. El frontend **renueva el token de forma preventiva** (`services/sesion.js`): cuando le quedan menos de 10 min (o un cuarto de su vida) y antes de la siguiente petición. Una sola renovación a la vez aunque haya muchas peticiones; si otra pestaña renueva primero no se pierde la sesión; un fallo de red no cierra la sesión.
3. Un usuario activo no ve nunca un cierre de sesión. Tras más de 60 min sin actividad la sesión vence (el backend solo acepta renovar un token vigente).

Efecto sobre el riesgo: un token robado deja de servir como mucho a los 60 min y, si el usuario sigue activo, el token robado queda **invalidado** cuando el navegador renueva (lista negra). **No elimina el robo**: dentro de esos 60 min el atacante tiene acceso.

## 3. Opciones

### A. Refresh token en cookie `httpOnly` + access token solo en memoria
- El access token vive **solo en una variable de JavaScript** (se pierde al recargar la página; se recupera pidiendo uno nuevo con la cookie).
- El refresh token vive en una cookie `httpOnly; Secure; SameSite=Strict`, que JavaScript **no puede leer**.
- **Pros:** un XSS ya no puede robar una sesión duradera; solo puede usar la sesión mientras la página esté abierta (no puede exfiltrar un token reutilizable fuera). Es la práctica recomendada para SPAs con mismo dominio.
- **Contras / impacto:**
  - Backend: hoy no existe un refresh token separado; hay que emitirlo, rotarlo en cada uso, detectar reutilización y guardarlo hasheado (nueva tabla). Endpoints `/auth/refresh` y `/auth/logout` basados en cookie.
  - Pasa a existir **CSRF** sobre los endpoints que usan la cookie: se mitiga con `SameSite=Strict` y exigiendo una cabecera personalizada; hay que probarlo.
  - CORS: si algún día el frontend se sirve desde otro origen, hay que activar `supports_credentials` y fijar orígenes (hoy es `false`).
  - Frontend: al recargar, hay un instante sin token (hay que "restaurar" la sesión antes de pintar); varias pestañas comparten la cookie pero no la memoria.
  - Desarrollo local: el frontend en `:5173` y la API en `:8000` son orígenes distintos: requiere proxy de Vite o cookies con dominio compartido.
- **Esfuerzo estimado:** 8 a 12 días-persona (backend 4–5, frontend 2–3, pruebas y despliegue 2–4).

### B. Mantener `localStorage` con TTL corto y CSP estricta (lo que ya está hecho + endurecer)
- TTL de 60 min con renovación (ya hecho) y **CSP en modo bloqueo** (bloque 3: hoy en `report-only`; ver `docs/seguridad/cabeceras-y-cors.md`) para reducir la probabilidad de que un script ajeno se ejecute.
- **Pros:** sin cambios de arquitectura; ya está operativo.
- **Contras:** la CSP actual necesita `style-src 'unsafe-inline'` (por antd) y, aun estricta, no cubre dependencias comprometidas que ya están en el bundle propio. El token sigue siendo legible por JS.
- **Esfuerzo:** 1 a 3 días (revisar violaciones de CSP, pasar a bloqueo, quitar lo innecesario).

### C. Cookie `httpOnly` con el JWT completo (sin separar refresh)
- Más simple que A, pero una cookie de 60 min con el access token tendría `SameSite` y CSRF igual, y sin refresh separado habría que renovar por la misma cookie. Combina los contras de A con menos beneficios. **No recomendada.**

## 4. Comparación

| | Robo del token por XSS | Esfuerzo | Riesgo de regresión | Dependencias |
|---|---|---|---|---|
| Hoy (TTL 60 + renovación) | Sirve ≤ 60 min | hecho | bajo | — |
| B (+ CSP en bloqueo) | Menos probable; si ocurre, ≤ 60 min | 1–3 d | bajo | bloque 3 (CSP) |
| A (cookie `httpOnly`) | No hay token reutilizable que robar | 8–12 d | medio (login, multi-pestaña, local) | tabla de refresh tokens, pruebas CSRF |

## 5. Recomendación

1. **Ahora:** quedarse con lo implementado (TTL 60 + renovación) y **cerrar la opción B**: mantener la CSP en `report-only` una semana de uso real, corregir violaciones y pasarla a bloqueo (procedimiento en `docs/seguridad/cabeceras-y-cors.md`).
2. **Después de abrir el portal a postulantes externos:** implementar **A**. Es la única opción que elimina el robo de tokens por XSS, y el despliegue bajo un mismo dominio la hace viable. Conviene planificarla antes de que el volumen de superficie pública crezca, y después de tener la CSP en bloqueo (defensa en profundidad).
3. Descartar C.

**Decisión pendiente (a confirmar por quien aprueba):** ¿se aprueba la opción A para implementarla en un PR aparte? Hasta entonces no se toca el almacenamiento del token.

## 6. Notas de operación
- El `.env` del servidor puede definir `JWT_TTL` y anular el nuevo valor por defecto: **verifica que no siga en 720** (`grep JWT_TTL .env`) y fija `JWT_TTL=60`.
- Las sesiones abiertas antes del cambio conservan su token de 12 h hasta que venza; las nuevas ya salen con 60 min.
- Si algún día el frontend se sirve desde otro dominio, revisar `docs/seguridad/cabeceras-y-cors.md` (CORS) antes de tocar cookies.
