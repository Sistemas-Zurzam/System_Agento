# Hardening previo a abrir Agento a postulantes externos

> Descripción del PR (borrador). **Fusionar con *merge commit*, no con squash**: la rama conserva un merge por bloque y el historial de cada uno.
> Estado detallado de cada hallazgo: `docs/seguridad/estado-hardening.md`. Issues de seguimiento: `docs/seguridad/issues-pendientes.md`.

## Resumen

Siete bloques que corrigen los hallazgos de `docs/analisis-integracion-agento.md` antes de exponer el sistema a postulantes externos. Nada de esto se ha desplegado.

## Bloque 1 · Despliegue seguro

- **Problema.** Despliegue directo a producción sin staging ni respaldo, con `SKIP_TESTS`.
- **Solución.** Workflows separados: `ci.yml` (obligatorio), `deploy-staging.yml` (rama `dev`) y `deploy-production.yml` (rama `main`, Environment `production` con aprobación manual). `deploy.sh`: mantenimiento → respaldo verificado (aborta si falla) → código → migrate → cachés → cola → `up`; smoke test contra `/up`. Runbooks de despliegue, restauración, rollback y staging.
- **Pruebas.** `HealthCheckTest`; scripts probados con `mysqldump`/`php`/`composer`/`npm` simulados (éxito, respaldo que falla, `migrate` que falla, volcado vacío/truncado).

## Bloque 2 · Límites de acceso

- **Problema.** `/api/login` sin límite; sin límites en refresh, me y listado público; OTP síncrono.
- **Solución.** Limitadores por login+IP (5/min) y por IP (20/min), bloqueo de cuenta tras 5 fallos (15 min) con evento de Auditoría, 429 con `Retry-After`, mensajes que no revelan si el usuario existe; límites en refresh/me/listado/detalle; OTP en cola (Mailable cifrado).
- **Pruebas.** `LimitesDeAccesoTest` (13): límite, 429, desbloqueo por tiempo, bloqueo desde varias IP, login correcto, mensajes idénticos, cola.

## Bloque 3 · CORS y cabeceras

- **Problema.** CORS abierto a cualquier origen; sin cabeceras de seguridad ni CSP.
- **Solución.** `config/cors.php` con orígenes por `.env` (patrones exactos: un origen no listado no recibe ninguna cabecera CORS); middleware de cabeceras (HSTS solo en HTTPS, nosniff, frame, referrer, permissions); CSP en `report-only` con endpoint de reportes que no registra tokens ni consultas.
- **Pruebas.** `CorsYCabecerasTest` (16).

## Bloque 4 · Aislamiento multiempresa

- **Problema.** `EmpresaScope` fallaba abierto (sin usuario no filtraba); 79 `withoutGlobalScope` sin auditar.
- **Solución.** `EmpresaContext` (empresa / modo sistema con motivo registrado / sin definir) y scope que **falla cerrado**. Contexto en JWT (que ahora corre antes de resolver los bindings), candidato, rutas públicas, jobs (viaja en el payload) y comandos (por empresa). Auditoría de los 79 usos con 2 correcciones y 1 bypass eliminado; test de arquitectura que impide nuevos.
- **Hallazgo real corregido.** `Horario` (`update`, `duplicar`, `estado`) permitía a un usuario de la empresa A tocar horarios de la B.
- **Pruebas.** Por modelo (30), recorrido de rutas con un usuario de otra empresa, jobs, comandos y arquitectura.

## Bloque 5 · Consentimiento y derechos ARCO

- **Problema.** Texto de consentimiento solo en el frontend, sin verificar versión ni hash; `revocado_at` sin uso; sin ARCO.
- **Solución.** Textos legales versionados servidos por la API; `422` si la versión o el hash no son los vigentes; revocación del consentimiento; acceso (JSON y archivos), rectificación y cancelación con estado y plazo; bandeja en *Selección → Derechos ARCO*; todo auditado. Migración aditiva y reversible.
- **Pruebas.** `ConsentimientoYArcoTest` (25).
- **Pendiente legal.** `docs/legal/pendientes.md` (textos y plazos provisionales).

## Bloque 6 · Antivirus de archivos

- **Problema.** CV y documentos sin escanear.
- **Solución.** `EscanerArchivos` con ClamAV (socket/TCP, INSTREAM) y escáner nulo (local/testing). Los documentos nacen `pendiente_escaneo` y no se descargan (423) hasta ser `limpio`; infectados a cuarentena + Auditoría + tarea para RR. HH.; sin antivirus en producción falla cerrado y se reintenta. Comando programado y `--legado`.
- **Pruebas.** `AntivirusDocumentosTest` (12) con EICAR y `EscanerClamAvTest` (6) con un `clamd` simulado.

## Bloque 7 · Sesión y limpieza

- **Problema.** JWT de 12 h en `localStorage` y sin renovación en el frontend; `CLAUDE.md` desactualizado.
- **Solución.** `JWT_TTL` por defecto de 60 min con renovación preventiva (`services/sesion.js`). **ADR de tokens: opción B ahora** (TTL corto, renovación, CSP); **la opción A es obligatoria antes del lanzamiento público** (issue). `CLAUDE.md` con el estado real y las reglas de seguridad.
- **Pruebas.** `SesionJwtTest` (5) y verificación de la lógica de renovación (9 casos, script de Node; ver issue de tests de frontend).

## CI sobre MySQL

El CI ahora corre la suite completa **también sobre MySQL** (servicio de Actions; versión por la variable `MYSQL_VERSION`, por defecto `8.0`) y prueba las migraciones: up, down de las que añade el PR y up otra vez. Los tests auxiliares se adaptaron a MySQL (enum, longitud de `char`, claves foráneas diferidas, y se evitó el `migrate:fresh` tras cada test). El despliegue exige ambos trabajos en verde.

## Pasos manuales antes de desplegar

**GitHub**
1. Crear los Environments `staging` y `production`; en `production`, **Required reviewers**.
2. Mover ahí los secretos SSH y definir `HEALTHCHECK_URL` (obligatoria) y, opcionales, `APP_DIR`, `BACKUP_DIR`, `BACKUP_RETENTION_DAYS`, `MAINTENANCE_SECRET`.
3. Definir la variable de repositorio **`MYSQL_VERSION`** con la versión de producción (`SELECT VERSION();`).
4. Proteger `main` y `dev` (PR obligatorio y checks `laravel-tests` y `laravel-tests-mysql`).

**Servidor de producción**
1. `mysqldump`, `openssl` y permisos de escritura en `/var/backups/agento`.
2. `.env`: `JWT_TTL=60` (si ya define 720, cámbialo), `CORS_ALLOWED_ORIGINS` (vacío si es mismo dominio), `ANTIVIRUS_DRIVER=clamav`, `ANTIVIRUS_SI_NO_DISPONIBLE=rechazar`, `CLAMAV_SOCKET`.
3. **Instalar y probar ClamAV antes de desplegar**; si no, los CV nuevos quedan bloqueados (423). Ver `docs/runbooks/clamav.md`.
4. Cabeceras de seguridad en Apache (issue de seguimiento).
5. Worker de la cola activo (OTP, antivirus, correos) y `schedule:run` en cron.
6. Un respaldo manual de la base antes del primer despliegue con el flujo nuevo.

**Staging**: preparar según `docs/runbooks/staging.md` y desplegar ahí primero, siguiendo `docs/runbooks/despliegue.md`.

**Después de 1 semana**: pasar la CSP a bloqueo (`docs/runbooks/despliegue.md` §5).

## Riesgos y plan de rollback

- El cambio más sensible es el **aislamiento por empresa** (falla cerrado): una consulta sin contexto devuelve vacío en producción. Los flujos sin usuario (portal público, jobs, comandos) se declararon explícitamente y están cubiertos por tests, pero **conviene probarlos en staging** (portal de empleos, postulación, prueba por enlace, envío de boletas, cierre de intentos).
- Los CV nuevos quedan bloqueados hasta que ClamAV funcione (por diseño).
- Rollback del código: `docs/runbooks/despliegue.md` §4. Las migraciones nuevas son aditivas y reversibles (`down`).

## Qué NO incluye (issues de seguimiento)

Opción A de sesión (requisito antes del lanzamiento público), `EmpresaScope` en los 35 modelos restantes, rutas que validan antes de autorizar, cabeceras en Apache, pantalla ARCO del candidato, revisión legal y tests del frontend.
