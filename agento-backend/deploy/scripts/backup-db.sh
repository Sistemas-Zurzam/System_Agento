#!/usr/bin/env bash
#
# Respaldo de la base de datos MySQL previo a un despliegue.
#
# Se ejecuta EN EL SERVIDOR (lo invoca deploy.sh). Lee las credenciales del
# .env de agento-backend sin imprimirlas y pasa la clave por MYSQL_PWD, para
# que no aparezca en la lista de procesos.
#
# Variables (todas opcionales):
#   BACKEND_DIR            ruta de agento-backend            (default: carpeta padre de deploy/)
#   BACKUP_DIR             destino de los respaldos          (default: /var/backups/agento)
#   BACKUP_ETIQUETA        prefijo del archivo, p. ej. el entorno (default: agento)
#   BACKUP_RETENTION_DAYS  días que se conservan             (default: 14)
#
# Salida: imprime en stdout SOLO la ruta del archivo generado.
# Código de salida distinto de 0 si el respaldo no se pudo generar o no es válido.

set -Eeuo pipefail

main() {
    local backend_dir="${BACKEND_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
    local backup_dir="${BACKUP_DIR:-/var/backups/agento}"
    local etiqueta="${BACKUP_ETIQUETA:-agento}"
    local retencion="${BACKUP_RETENTION_DAYS:-14}"
    local env_file="${backend_dir}/.env"

    [[ "$retencion" =~ ^[0-9]+$ ]] || { echo "BACKUP_RETENTION_DAYS debe ser un entero" >&2; return 1; }
    [[ -r "$env_file" ]] || { echo "No se puede leer ${env_file}" >&2; return 1; }

    leer_env() {
        # Toma el último valor de la clave y le quita comillas y espacios finales.
        # `|| true`: una clave ausente (p. ej. DB_PORT) no debe abortar el script.
        { grep -E "^$1=" "$env_file" || true; } | tail -n1 | cut -d= -f2- | sed -e 's/^["'\'']//' -e 's/["'\'']\s*$//' -e 's/\s*$//'
    }

    local conexion host port db user pass
    conexion="$(leer_env DB_CONNECTION)"
    host="$(leer_env DB_HOST)"; host="${host:-127.0.0.1}"
    port="$(leer_env DB_PORT)"; port="${port:-3306}"
    db="$(leer_env DB_DATABASE)"
    user="$(leer_env DB_USERNAME)"
    pass="$(leer_env DB_PASSWORD)"

    case "$conexion" in
        mysql|mariadb) ;;
        *) echo "DB_CONNECTION='${conexion}' no es mysql/mariadb: no hay nada que respaldar con mysqldump" >&2; return 1 ;;
    esac
    [[ -n "$db" && -n "$user" ]] || { echo "Faltan DB_DATABASE o DB_USERNAME en el .env" >&2; return 1; }

    command -v mysqldump >/dev/null || { echo "mysqldump no está instalado" >&2; return 1; }

    mkdir -p "$backup_dir"
    chmod 700 "$backup_dir"

    local archivo parcial
    archivo="${backup_dir}/${etiqueta}-${db}-$(date +%Y%m%d-%H%M%S).sql.gz"
    parcial="${archivo}.parcial"

    # Si algo falla se borra el archivo incompleto para que nadie lo confunda con un respaldo válido.
    # (comillas dobles: se expande ahora; $parcial es local y no existiría al salir de main)
    trap "rm -f '$parcial'" ERR EXIT

    MYSQL_PWD="$pass" mysqldump \
        --host="$host" --port="$port" --user="$user" \
        --single-transaction --quick --routines --triggers --events \
        --no-tablespaces --default-character-set=utf8mb4 \
        "$db" | gzip -9 > "$parcial"

    # Verificación: el comprimido es íntegro, no está vacío y contiene un volcado completo.
    [[ -s "$parcial" ]] || { echo "El respaldo quedó vacío" >&2; return 1; }
    [[ -n "$(zcat "$parcial" | head -c 1)" ]] || { echo "El volcado no contiene datos" >&2; return 1; }
    gzip -t "$parcial" || { echo "El respaldo está corrupto (gzip -t falló)" >&2; return 1; }
    zcat "$parcial" | tail -n 5 | grep -q "Dump completed" || { echo "El volcado no terminó correctamente (falta 'Dump completed')" >&2; return 1; }

    mv "$parcial" "$archivo"
    chmod 600 "$archivo"
    trap - ERR EXIT

    # Retención: solo borra respaldos de esta etiqueta con más de N días.
    find "$backup_dir" -maxdepth 1 -type f -name "${etiqueta}-*.sql.gz" -mtime "+${retencion}" -delete

    echo "$archivo"
}

main "$@"
