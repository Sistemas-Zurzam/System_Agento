# Estado del hardening previo a abrir Agento a postulantes externos

Fecha: 2026-11-19. Origen de los hallazgos: `docs/analisis-integracion-agento.md` (secciones 2, 8 y 10).

**Importante:** nada de esto se ha subido a GitHub ni desplegado. Todo existe en ramas locales; la columna "PR" indica la rama (no hay PR todavía). "Corregido" significa *corregido en el código y probado*; lo que además exige una acción en el servidor o en GitHub se indica en Notas y en "Pasos manuales".

| # | Hallazgo | Estado | Rama / PR | Notas |
|---|---|---|---|---|
| 1 | Despliegue directo a producción sin staging, sin backup y con `SKIP_TESTS` | **Corregido** (código y runbooks). Falta configurar GitHub y el servidor | `fix/01-despliegue-seguro` — sin PR | `SKIP_TESTS` eliminado; staging (`dev`) y producción (`main`, Environment con aprobación); mantenimiento + respaldo verificado + migrate + smoke test. Los scripts se probaron con `mysqldump`, `php`, `composer` y `npm` simulados; **los workflows no se han ejecutado** (no hay `act`). Sin Environment `production` con revisores obligatorios **no hay aprobación manual** |
| 2 | `POST /api/login` sin rate limiting | **Corregido** | `fix/02-rate-limiting-auth` — sin PR | 5/min por login+IP, 20/min por IP, bloqueo de cuenta tras 5 fallos (15 min) con evento de Auditoría, 429 con `Retry-After`, mismos mensajes exista o no el usuario |
| 2b | Sin límites en `/refresh`, `/me` y listado público | **Corregido** | `fix/02-…` | Limitadores propios por usuario (refresh, me) y por IP (listado, detalle). Los existentes de Selección siguen funcionando |
| 2c | Correo OTP síncrono | **Corregido** | `fix/02-…` | Mailable en cola y cifrado; requiere el worker de la cola activo. El endpoint no cambia |
| 3 | Sin `config/cors.php` (origen `*`) | **Corregido** (código). Falta definir `CORS_ALLOWED_ORIGINS` en cada servidor | `fix/03-cors-cabeceras` — sin PR | Lista por `.env`; un origen no listado no recibe `Access-Control-Allow-Origin`. En producción (mismo dominio) la lista va vacía |
| 4a | Sin cabeceras de seguridad | **Parcial** | `fix/03-…` | Middleware en la API (nosniff, frame, referrer, permissions, HSTS solo en HTTPS). **El frontend estático lo sirve Apache: las mismas cabeceras deben ponerse allí** (fragmento en `docs/seguridad/cabeceras-y-cors.md`); no se tocó `apache.conf` |
| 4b | CSP | **Parcial** | `fix/03-…` | En modo `report-only` con endpoint de reportes (sin tokens ni consultas en el log). Falta revisar violaciones una semana y pasarla a bloqueo |
| 4c | JWT de 720 min | **Corregido** (TTL por defecto 60) | `fix/07-sesion-y-limpieza` — sin PR | Renovación preventiva en el frontend. **Si el `.env` del servidor define `JWT_TTL`, ese valor manda: hay que ponerlo en 60** |
| 4d | JWT en `localStorage` | **Parcial (decidido: opción B ahora; opción A obligatoria antes del lanzamiento público)** | `fix/07-…` | ADR aceptado (`docs/decisiones/ADR-sesion-tokens.md`). Hoy: TTL 60 + renovación + CSP en `report-only`. El paso a bloqueo de la CSP tras 1 semana está en `docs/runbooks/despliegue.md` §5. La opción A (cookie `httpOnly`) tiene issue P0 en `docs/seguridad/issues-pendientes.md` |
| 5a | `EmpresaScope` falla abierto | **Corregido** | `fix/04-aislamiento-multiempresa` — sin PR | Falla cerrado (excepción en local/testing; 0 filas en producción). Contexto de empresa en JWT, candidato, jobs y comandos; modo sistema con motivo registrado |
| 5b | ~28 archivos con `withoutGlobalScope(s)` | **Corregido / auditado** | `fix/04-…` | 79 llamadas auditadas en `docs/seguridad/auditoria-scopes.md`; 2 búsquedas sin empresa corregidas, 1 bypass eliminado; test de arquitectura impide nuevos |
| 5c | Fugas entre empresas en endpoints | **Corregido (1 hallazgo real)** | `fix/04-…` | Hallazgo y corrección: `Horario` (`update`, `duplicar`, `estado`) permitía a un usuario de la empresa A tocar horarios de la B. Se corrigió y hay test que recorre las rutas |
| 5d | Defensa en profundidad en modelos sin scope | **Pendiente** | `fix/04-…` | 35 modelos con `empresa_id` no tienen `EmpresaScope` (`Colaborador`, `Boleta`, `CicloRemunerativo`…): dependen de comprobaciones explícitas. Hoy los cubre el test de rutas; añadir el scope exige revisar las consultas multiempresa del administrador |
| 5e | Aislamiento verificado en todas las rutas | **Parcial** | `fix/04-…` | 46 rutas responden 422 en la sonda (validan antes de autorizar): su autorización se revisó **leyendo código**, no con cuerpos válidos. Las rutas públicas corren en modo sistema por grupo (más grueso que por consulta) |
| 6a | Texto de consentimiento solo en el frontend, sin versión ni hash verificados | **Corregido** | `fix/05-consentimiento-arco` — sin PR | Tabla de textos legales versionados, texto servido por la API, 422 si la versión o el hash no coinciden con el vigente |
| 6b | `revocado_at` sin endpoint | **Corregido** | `fix/05-…` | Revocar retira postulaciones y registra la solicitud de cancelación por empresa |
| 6c | Sin derechos ARCO | **Corregido (backend y bandeja)**. Falta UI del candidato | `fix/05-…` | Acceso (JSON y archivos), rectificación y cancelación con estado y plazo; bandeja en *Selección → Derechos ARCO*; todo auditado. El portal de empleos **no tiene pantalla** para que el candidato los ejerza (hay API y métodos en `portalEmpleosApi.arco`) |
| 6d | Textos legales definitivos | **Pendiente (abogado)** | `fix/05-…` | `docs/legal/pendientes.md`. El texto sembrado es el provisional del frontend; la política de privacidad **no existe**; los plazos ARCO (20/10/10 días hábiles) son valores iniciales a validar; la oposición no está implementada |
| 7 | Sin antivirus en CV y documentos | **Corregido** (código). Falta instalar ClamAV | `fix/06-antivirus-archivos` — sin PR | **Advertencia operativa:** en producción, mientras ClamAV no esté instalado, los CV nuevos quedan **bloqueados** (HTTP 423) por diseño. Instalar y probar antes de desplegar (`docs/runbooks/clamav.md`). Los documentos anteriores quedan como `legado` (descargables) hasta ejecutar `reclutamiento:escanear-documentos --legado` |
| 8a | Listado público sin límite propio | **Corregido** | `fix/02-…` | Ver 2b |
| 8b | `CLAUDE.md` desactualizado | **Corregido** | `fix/07-…` | Estado real, comandos, convenciones y reglas de seguridad |

## Verificación

| Rama | Tests | Resultado |
|---|---|---|
| `fix/01` | 631 | verde |
| `fix/02` | 642 | verde |
| `fix/03` | 645 | verde |
| `fix/04` | 707 | verde |
| `fix/05` | 654 | verde |
| `fix/06` | 647 | verde |
| `fix/07` | 634 | verde |
| **`integracion/hardening`** (las siete fusionadas) | **788** | **verde** |

Los números de cada rama parten de `main` por separado; la fila de integración es la que cuenta como estado final. En integración hubo que resolver conflictos de fusión (`.env.example`, `routes/api.php`, `routes/seleccion.php`, `config/logging.php`, `config/reclutamiento.php`, `RegistradorAuditoria`) y adaptar el job y el comando de antivirus para no saltarse el scope (la regla de arquitectura del bloque 4 lo detectó). Ese ajuste solo existe en `integracion/hardening`.

## CI sobre MySQL (añadido)

`ci.yml` ahora tiene un segundo trabajo, `laravel-tests-mysql`: servicio MySQL de Actions (versión por la variable `MYSQL_VERSION`, por defecto `8.0`, **a confirmar contra producción**), migraciones up, down/up de las que añade el PR y la suite completa. Los helpers de test se adaptaron a MySQL por revisión de código (`InsertaFilasDeEmpresa`: enum, longitud de `char`, FK diferidas; `CreaEscenarioSeleccion`: ya no fuerza un `migrate:fresh` tras cada test fuera de SQLite). **Ese trabajo todavía no se ha ejecutado**: no hay MySQL local ni acceso a GitHub desde la sesión, así que no se sabe si la suite pasa sobre MySQL. Lo que falle por diferencias con SQLite se corrige al ver el primer resultado.

Pruebas que **no** se pudieron hacer:
- Los workflows de GitHub Actions (no se ejecutaron) ni el despliegue real.
- `mysqldump` contra una base MySQL real y ClamAV real (se probó el protocolo de `clamd` con un servidor simulado y el flujo con EICAR mediante el escáner nulo).
- Migraciones y suite en MySQL: se agregó el trabajo de CI, pero no se ha ejecutado (ver arriba).
- El frontend no tiene tests automatizados: la renovación de sesión se verificó con un script de Node sobre la lógica pura (9 casos) y con lint y build; no con un navegador.
- ESLint reporta un error en `src/hooks/useCurrentUser.js` (`set-state-in-effect`) que **ya existía** y no se tocó.

## Cómo fusionar

Las siete ramas parten de `main` y tocan archivos comunes. Opciones:
1. **Un solo PR** desde `integracion/hardening` (ya resuelto y probado). Es lo más seguro.
2. **Siete PR** en este orden: 01 → 02 → 03 → 04 → 05 → 06 → 07. Tras fusionar cada uno, rebasar el siguiente sobre `main`. Conflictos esperados: `.env.example` (todos), `routes/api.php` (02, 03), `config/logging.php` (03, 04), `routes/seleccion.php` (02, 04, 05), `RegistradorAuditoria` (02, 05), `config/reclutamiento.php` (05, 06). **El PR 06 debe adaptarse al fusionarlo después del 04** (el job y el comando de antivirus deben usar `EmpresaContext`, como se hizo en integración).

## Pasos manuales pendientes (los hace una persona)

**GitHub**
1. Crear los Environments `staging` y `production`; en `production`, **Required reviewers**.
2. Mover ahí los secretos SSH y definir `HEALTHCHECK_URL` (obligatoria), y opcionales `APP_DIR`, `BACKUP_DIR`, `BACKUP_RETENTION_DAYS`, `MAINTENANCE_SECRET`.
3. Proteger `main` y `dev` (PR obligatorio y check `laravel-tests`).

**Servidor de producción**
1. `mysqldump`, `openssl` y permisos de escritura en `/var/backups/agento`.
2. `.env`: `JWT_TTL=60`, `CORS_ALLOWED_ORIGINS` (vacío si es mismo dominio), `ANTIVIRUS_DRIVER=clamav`, `ANTIVIRUS_SI_NO_DISPONIBLE=rechazar`, `CLAMAV_SOCKET`.
3. **Instalar y probar ClamAV antes de desplegar** (de lo contrario los CV nuevos quedan bloqueados).
4. Cabeceras de seguridad en Apache (HSTS, CSP report-only, etc.).
5. Worker de la cola activo (OTP, antivirus, correos) y `schedule:run` en cron (escaneo cada 10 min, cierre de intentos, retención).
6. Un respaldo manual de la base antes del primer despliegue con el flujo nuevo.

**Servidor de staging**: preparar según `docs/runbooks/staging.md`.

**Negocio / legal**
1. Abogado: textos y plazos de `docs/legal/pendientes.md`.
2. Decidir sobre el ADR de sesión (opción A: cookie `httpOnly`).
3. Decidir si el candidato necesita pantalla de ARCO en el portal.

**Seguimiento técnico**
1. Revisar CSP una semana y pasarla a bloqueo.
2. Añadir `EmpresaScope` a los modelos sensibles sin scope (`Colaborador`, `Boleta`, `CicloRemunerativo`) y quitar el respaldo por usuario autenticado del scope.
3. Cuerpos válidos en el test de rutas para las 46 que validan antes de autorizar.

## Herramientas
El plugin `github` no conectó (error 400 de cabecera de autorización) y `context7` pidió autorización OAuth que esta sesión no podía completar; por eso no se crearon PR y la documentación de Laravel, jwt-auth y GitHub Actions se consultó en el código del propio `vendor/` y en los conocimientos del modelo, no con `context7`.
