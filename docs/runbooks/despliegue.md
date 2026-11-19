# Runbook: despliegue, restauración y rollback

Entornos: **staging** (rama `dev`) y **producción** (rama `main`). Ver [staging.md](staging.md) para preparar staging.

## 1. Cómo se despliega

| Entorno | Disparador | Aprobación | Workflow |
|---|---|---|---|
| Staging | push a `dev` | automática | `.github/workflows/deploy-staging.yml` |
| Producción | push a `main` | **manual** (Environment `production` con revisores obligatorios) | `.github/workflows/deploy-production.yml` |

Ambos workflows hacen lo mismo:

1. `ci.yml` ejecuta la suite completa **dos veces**: sobre SQLite y sobre **MySQL** (servicio de GitHub Actions, que además prueba las migraciones: up, down de las nuevas y up otra vez). **No hay forma de omitirla**: si algo falla, el despliegue no empieza. (Se eliminó la variable `SKIP_TESTS`.) La versión de MySQL del CI sale de la variable `MYSQL_VERSION` del repositorio (por defecto `8.0`): **debe ser la de producción** (`SELECT VERSION();` en el servidor).
2. El job `deploy` espera la aprobación del Environment (solo producción).
3. Por SSH ejecuta en el servidor `agento-backend/deploy/scripts/deploy.sh`, que:
   1. `php artisan down --secret=…` (modo mantenimiento con bypass).
   2. **Respaldo de la base** con `backup-db.sh` (`mysqldump --single-transaction`, comprimido, verificado: no vacío, `gzip -t` y marca `Dump completed`). **Si falla, se aborta sin tocar código ni base** y se levanta el sitio.
   3. `git reset --hard origin/<rama>`, `composer install --no-dev`.
   4. `php artisan migrate --force`.
   5. `optimize:clear` + `config:cache`, `route:cache`, `view:cache`, `event:cache`.
   6. `queue:restart`.
   7. `npm ci` + `npm run build` + recarga de Apache.
   8. `php artisan up`.
4. **Smoke test:** el job hace `curl` a `HEALTHCHECK_URL` (`/up`) con reintentos; si no responde 200, **el job falla**.

### Qué pasa si algo falla

| Fase | Resultado |
|---|---|
| Mantenimiento / respaldo | No se modificó nada. Se levanta el sitio. |
| Cambio de código / composer | Se restaura el código anterior y se levanta el sitio. La base no se migró. |
| `migrate` o posterior | **El sitio queda en mantenimiento** (el esquema puede no coincidir con el código anterior). Decide una persona: ver §3 y §4. |
| Smoke test | El sitio ya está arriba pero el job queda en rojo. Revisar logs; si es grave, rollback (§4). |

### Aprobación manual de producción (una vez, lo hace un administrador del repositorio)
GitHub → *Settings → Environments → New environment* `production` → **Required reviewers** (al menos una persona distinta de quien despliega) y, opcional, *Deployment branches: only `main`*.

### Secretos y variables (por Environment: `staging` y `production`)

| Nombre | Tipo | Descripción |
|---|---|---|
| `SSH_HOST`, `SSH_USERNAME`, `SSH_PRIVATE_KEY`, `SSH_PORT` | secret | Acceso SSH del usuario de despliegue |
| `MAINTENANCE_SECRET` | secret (opcional) | Ruta de bypass del modo mantenimiento (si falta, se genera uno aleatorio por despliegue) |
| `HEALTHCHECK_URL` | variable | p. ej. `https://agento.com.pe/up`. **Obligatoria**: sin ella el smoke test falla |
| `APP_DIR` | variable (opcional) | default `/var/www/Plataforma-Agento` |
| `BACKUP_DIR` | variable (opcional) | default `/var/backups/agento` |
| `BACKUP_RETENTION_DAYS` | variable (opcional) | default `14` |

> Al crear el Environment `production`, mueve ahí los secretos SSH actuales (hoy son secretos del repositorio) para que solo el job aprobado pueda usarlos.

### Requisitos en el servidor
- Usuario de despliegue con `git`, `php`, `composer`, `node`/`npm`, `mysqldump`, `gzip`, `openssl` y `sudo systemctl reload apache2` sin contraseña.
- Escritura en `BACKUP_DIR` (el script lo crea con permisos 700).
- El `.env` del servidor con `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (el script lo lee sin imprimirlo y pasa la clave por `MYSQL_PWD`).
- Recomendado: copiar los respaldos fuera del servidor (los de `BACKUP_DIR` están en el mismo disco).

## 2. Verificar un respaldo
```bash
ls -lh /var/backups/agento/                        # el más reciente: production-<db>-AAAAMMDD-HHMMSS.sql.gz
gzip -t /var/backups/agento/<archivo>.sql.gz && echo íntegro
zcat /var/backups/agento/<archivo>.sql.gz | tail -n 3   # debe terminar con "Dump completed"
```

## 3. Restaurar un respaldo (paso a paso)
Hazlo solo con el sitio en mantenimiento. **Destruye los cambios hechos en la base después del respaldo.**

1. Confirmar el modo mantenimiento: `cd /var/www/Plataforma-Agento/agento-backend && php artisan down --secret=<secreto>` (si ya está abajo, no pasa nada).
2. Detener la cola: `sudo supervisorctl stop all` (o el nombre del worker `agento-worker`).
3. **Respaldar el estado actual** (aunque esté dañado): `BACKUP_ETIQUETA=pre-restauracion bash deploy/scripts/backup-db.sh`.
4. Restaurar:
   ```bash
   cd /var/www/Plataforma-Agento/agento-backend
   DB_NAME=$(grep -E '^DB_DATABASE=' .env | cut -d= -f2-)
   DB_USER=$(grep -E '^DB_USERNAME=' .env | cut -d= -f2-)
   read -rs MYSQL_PWD && export MYSQL_PWD           # escribe la clave; no queda en el historial
   zcat /var/backups/agento/<archivo>.sql.gz | mysql --user="$DB_USER" "$DB_NAME"
   unset MYSQL_PWD
   ```
5. Poner el **código** en la versión que coincide con ese respaldo (§4).
6. `php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache`.
7. `sudo supervisorctl start all` y `php artisan queue:restart`.
8. `php artisan up`; comprobar `curl -fsS https://<dominio>/up` y un flujo real (login, listado).

## 4. Rollback del código
El workflow imprime `Código actual <sha> → nuevo <sha>`. Con ese `sha` previo:

```bash
cd /var/www/Plataforma-Agento
git fetch origin
git reset --hard <sha-previo>
cd agento-backend && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan queue:restart
cd ../agento-frontend && npm ci && npm run build && sudo systemctl reload apache2
cd ../agento-backend && php artisan up
```

**Si el despliegue ya migró la base**, revertir solo el código puede fallar si las migraciones eran incompatibles. Opciones, de menor a mayor impacto:
1. `php artisan migrate:rollback --step=<n>` (solo si las migraciones nuevas tienen `down()` y no perdieron datos).
2. Restaurar el respaldo (§3) **y** el código previo.
3. Corregir hacia adelante (nuevo commit) y volver a desplegar.

Alternativa vía Git (sin acceso al servidor): `git revert <commit>` en `main` y volver a desplegar con aprobación; sigue el flujo normal y genera un respaldo nuevo.

## 5. Pasar la CSP de `report-only` a bloqueo (después de 1 semana)

La CSP se despliega en modo `report-only` (`CSP_MODO=report-only`): el navegador **avisa** de lo que bloquearía, pero no bloquea nada. Pasarla a bloqueo es un paso posterior, a decisión de una persona:

1. **Esperar 1 semana de uso real** en producción desde que se activó el reporte (cubre los flujos semanales: planilla, asistencia, portal de empleos, pruebas, descargas).
2. **Revisar los reportes**: en el servidor, `storage/logs/csp-AAAA-MM-DD.log` (una línea por violación, sin tokens ni consultas). Resumen rápido por directiva y recurso bloqueado:
   `grep -h "Violación de CSP" storage/logs/csp-*.log | grep -o '"violated-directive":"[^"]*","blocked-uri":"[^"]*"' | sort | uniq -c | sort -rn | head -30`
3. **Clasificar**. *No relevantes* (se ignoran): `blocked-uri` de extensiones del navegador (`chrome-extension`, `moz-extension`, `safari-extension`), `about`, `data` aislado de un solo usuario o recursos que ya no existen. *Relevantes* (hay que corregirlos antes de bloquear): cualquier violación repetida de `script-src`, `connect-src`, `img-src`, `font-src`, `frame-src` o `style-src` desde el propio sitio o desde un host que la aplicación usa de verdad.
4. **Corregir** lo relevante: añadir el host legítimo a la directiva en `config/cabeceras.php` (`CSP_POLITICA`) y en Apache (`docs/seguridad/cabeceras-y-cors.md` §3), y volver a esperar unos días si el cambio fue grande.
5. **Pasar a bloqueo** cuando no queden violaciones relevantes: `CSP_MODO=enforce` en el `.env` + `php artisan config:cache`, y en Apache cambiar `Content-Security-Policy-Report-Only` por `Content-Security-Policy` y `sudo systemctl reload apache2`.
6. **Vigilar las primeras 48 h**: si algo deja de funcionar, **reversa inmediata** con `CSP_MODO=report-only` (o `off`) y `config:cache`; no requiere despliegue de código.

Este paso es parte de la **opción B** del ADR de sesión (`docs/decisiones/ADR-sesion-tokens.md`).

## 6. Probar los scripts sin servidor
Los scripts se verificaron con `mysqldump`, `php`, `composer` y `npm` simulados (repositorio git temporal): éxito, respaldo que falla (no cambia código, levanta el sitio), `migrate` que falla (el sitio queda en mantenimiento), volcado vacío, truncado y con error. Para repetir la prueba del respaldo: poner un `mysqldump` falso en el `PATH`, un `.env` de prueba y `BACKEND_DIR`/`BACKUP_DIR` apuntando a una carpeta temporal.
