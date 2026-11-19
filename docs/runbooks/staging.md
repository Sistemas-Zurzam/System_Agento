# Runbook: preparar el entorno de staging

Esto **no se puede crear desde el código**: lo prepara el administrador del servidor. Mientras no exista, `deploy-staging.yml` fallará al llegar al paso SSH (los tests sí correrán).

## 1. Servidor o vhost
- Un servidor distinto de producción, o un vhost separado en el mismo servidor (preferible lo primero: aísla carga y datos).
- Mismo stack que producción: PHP 8.3, Composer, Node, MySQL, Apache (ver `agento-frontend/apache.conf` como base) y Supervisor para el worker de cola (`agento-backend/deploy/supervisor/agento-worker.conf`, con otro nombre de programa).
- Dominio propio, p. ej. `staging.agento.com.pe`, con HTTPS (certbot).
- Clonar el repositorio en el directorio de despliegue (por defecto `/var/www/Plataforma-Agento`, o definir la variable `APP_DIR`) y hacer `git checkout dev`.
- Usuario de despliegue con permisos descritos en [despliegue.md](despliegue.md#requisitos-en-el-servidor).

## 2. Base de datos separada
- Esquema y usuario **propios** de staging (nunca la base de producción).
- Si se cargan datos de producción para pruebas: **anonimizarlos** (candidatos, colaboradores, nóminas) antes de importarlos. Son datos personales (Ley 29733).

## 3. `.env` de staging (en el servidor; no se versiona)
Copiar `agento-backend/.env.example` y definir como mínimo:

| Clave | Valor para staging |
|---|---|
| `APP_ENV` | `staging` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://staging.<dominio>` |
| `APP_KEY`, `JWT_SECRET` | **distintos** de producción (`php artisan key:generate`, `php artisan jwt:secret`) |
| `DB_*` | credenciales de la base de staging |
| `MAIL_MAILER` | `log` (o un buzón de pruebas): **nunca** enviar correo real a candidatos desde staging. Nota: con `log` el OTP del portal no se envía fuera de `local`; usa un mailer de pruebas (p. ej. Mailpit/Resend en sandbox) si necesitas probar el flujo de candidato |
| `QUEUE_CONNECTION` | `database` + worker activo |
| `RECLUTAMIENTO_URL_PORTAL` | URL del portal de staging |
| `RECLUTAMIENTO_RETENCION_ELIMINAR` | `false` |

El frontend compila con `VITE_API_URL` (ver `agento-frontend/.env.example`).

## 4. GitHub
1. *Settings → Environments → New environment* `staging` (sin revisores: es automático).
2. Secretos del Environment `staging`: `SSH_HOST`, `SSH_USERNAME`, `SSH_PRIVATE_KEY`, `SSH_PORT`, y opcional `MAINTENANCE_SECRET`.
3. Variables del Environment `staging`: `HEALTHCHECK_URL` (p. ej. `https://staging.<dominio>/up`, **obligatoria**), y opcionales `APP_DIR`, `BACKUP_DIR`, `BACKUP_RETENTION_DAYS`.
4. Usar una llave SSH **distinta** a la de producción.
5. (Recomendado) proteger las ramas `main` y `dev`: PR obligatorio y check `laravel-tests` requerido.

## 5. Flujo de trabajo propuesto
`feature/*` → PR a `dev` (corre `ci.yml`) → merge → despliegue automático a staging → validar → PR de `dev` a `main` → merge → aprobación manual → producción.

## 6. Comprobación inicial
1. Merge a `dev` de un cambio trivial.
2. En *Actions*, el workflow *Deploy staging* debe terminar en verde, incluido el smoke test.
3. `curl -fsS https://staging.<dominio>/up` responde 200.
4. En el servidor: `ls -l /var/backups/agento/` muestra un respaldo `staging-<db>-…sql.gz`.
