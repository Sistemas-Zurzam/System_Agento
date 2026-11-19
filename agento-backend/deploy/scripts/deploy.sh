#!/usr/bin/env bash
#
# Despliegue en el servidor (staging o producción). Lo invoca el workflow por SSH.
#
# Orden: modo mantenimiento → respaldo de BD → código → composer → migrate →
# cachés → cola → frontend → salir de mantenimiento.
#
# Garantías:
#   - Si el respaldo falla se aborta SIN tocar código ni base y se levanta el sitio.
#   - Si falla algo entre el cambio de código y la migración, se restaura el código
#     anterior y se levanta el sitio (la base no se ha modificado).
#   - Si falla durante o después de migrate, el sitio PERMANECE en mantenimiento:
#     el esquema puede ya no coincidir con el código anterior. Decide una persona
#     con docs/runbooks/despliegue.md (restaurar respaldo o corregir y re-desplegar).
#
# Variables obligatorias: APP_DIR, DEPLOY_BRANCH
# Opcionales: ENTORNO (staging|production, default production), MAINTENANCE_SECRET,
#             BACKUP_DIR, BACKUP_RETENTION_DAYS, SKIP_FRONTEND (=1 para omitirlo)
#
# Todo el cuerpo está dentro de main() para que bash lea el archivo completo antes
# de ejecutarlo: el script puede reemplazarse durante `git reset` sin romperse.

set -Eeuo pipefail

main() {
    : "${APP_DIR:?Falta APP_DIR}" "${DEPLOY_BRANCH:?Falta DEPLOY_BRANCH}"
    local entorno="${ENTORNO:-production}"
    local backend="${APP_DIR}/agento-backend"
    local frontend="${APP_DIR}/agento-frontend"
    local fase="inicio"
    local previo nuevo respaldo backup_tmp secreto

    secreto="${MAINTENANCE_SECRET:-$(openssl rand -hex 16)}"

    log() { printf '\n[deploy:%s] %s\n' "$entorno" "$*"; }

    cd "$APP_DIR"
    git fetch --prune origin "$DEPLOY_BRANCH"
    previo="$(git rev-parse HEAD)"
    nuevo="$(git rev-parse "origin/${DEPLOY_BRANCH}")"
    log "Código actual ${previo:0:9} → nuevo ${nuevo:0:9}"

    # El script de respaldo se toma de la versión que se va a desplegar (sirve también
    # en el primer despliegue, cuando el servidor aún no lo tiene).
    backup_tmp="$(mktemp)"
    git show "origin/${DEPLOY_BRANCH}:agento-backend/deploy/scripts/backup-db.sh" > "$backup_tmp"

    al_fallar() {
        local codigo=$?
        trap - ERR
        log "FALLÓ en la fase '${fase}' (código ${codigo})"
        case "$fase" in
            inicio|mantenimiento|respaldo)
                log "La base y el código no se modificaron. Se levanta el sitio."
                (cd "$backend" && php artisan up) || true
                ;;
            codigo|composer)
                log "Se restaura el código anterior ${previo:0:9}. La base no se migró."
                git reset --hard "$previo" || true
                (cd "$backend" && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader) || true
                (cd "$backend" && php artisan up) || true
                ;;
            *)
                log "El sitio QUEDA EN MANTENIMIENTO. Sigue docs/runbooks/despliegue.md (restaurar respaldo: ${respaldo:-sin respaldo})."
                ;;
        esac
        rm -f "$backup_tmp"
        exit "$codigo"
    }
    trap al_fallar ERR

    fase="mantenimiento"
    log "Modo mantenimiento (bypass: /${secreto:0:4}… — el secreto completo no se imprime)"
    (cd "$backend" && php artisan down --retry=60 --secret="$secreto")

    fase="respaldo"
    log "Respaldo de la base de datos"
    respaldo="$(BACKEND_DIR="$backend" BACKUP_ETIQUETA="$entorno" bash "$backup_tmp")"
    log "Respaldo verificado: ${respaldo}"
    rm -f "$backup_tmp"

    fase="codigo"
    git reset --hard "origin/${DEPLOY_BRANCH}"

    fase="composer"
    cd "$backend"
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

    fase="migrate"
    php artisan migrate --force

    fase="cache"
    php artisan optimize:clear
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache

    fase="cola"
    php artisan queue:restart

    if [[ "${SKIP_FRONTEND:-0}" != "1" ]]; then
        fase="frontend"
        cd "$frontend"
        npm ci
        npm run build
        sudo systemctl reload apache2
        cd "$backend"
    fi

    fase="levantar"
    php artisan up
    trap - ERR
    log "Despliegue terminado: ${nuevo:0:9}. Respaldo previo: ${respaldo}"
}

main "$@"
