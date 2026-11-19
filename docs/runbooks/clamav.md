# Runbook: antivirus ClamAV para CV y documentos de candidatos

Lo instala y mantiene el **administrador del servidor**; el código ya está preparado. Mientras ClamAV no esté instalado y configurado, en producción **los CV y documentos nuevos quedan bloqueados** (no se pueden descargar): es a propósito (falla cerrado).

## 1. Cómo funciona

1. Al postular, el CV (y DNI/CUL) se guarda con estado `pendiente_escaneo`. **No se puede descargar** (HTTP 423).
2. Un job en cola (`EscanearDocumentoJob`) envía el archivo a `clamd` (protocolo INSTREAM) y lo marca:
   - `limpio`: queda disponible.
   - `infectado`: se mueve a `cuarentena/<empresa>/…` (mismo disco privado), **nunca se descarga**, se registra el evento `archivo.infectado` en Auditoría y se crea una **tarea** para RR. HH. (permiso `seleccion.candidatos.gestionar`) en el dashboard de Selección.
3. Si `clamd` no responde, el job se reintenta (30 s, 2 min, 10 min, 30 min). Agotados los intentos queda `error_escaneo` (sigue bloqueado). El comando programado `reclutamiento:escanear-documentos` (cada 10 min) lo vuelve a encolar, así que al volver ClamAV se liberan solos.
4. Los documentos anteriores a este cambio quedaron como `legado` (descargables, sin escanear). Para escanearlos: `php artisan reclutamiento:escanear-documentos --legado`.

Estados: `pendiente_escaneo`, `limpio`, `infectado`, `error_escaneo`, `legado`. Solo `limpio` y `legado` se descargan.

## 2. Instalación en el servidor (Ubuntu/Debian)

```bash
sudo apt update
sudo apt install -y clamav clamav-daemon
sudo systemctl stop clamav-freshclam
sudo freshclam                      # primera descarga de firmas (puede tardar)
sudo systemctl start clamav-freshclam
sudo systemctl enable --now clamav-daemon
sudo systemctl status clamav-daemon # debe estar "active (running)"
```

La primera vez, `clamd` tarda 1 o 2 minutos en cargar las firmas y consume ~1 GB de RAM. Verifica que el servidor tiene memoria suficiente.

### Socket Unix (recomendado: no expone puertos)
Por defecto `clamd` escucha en `/var/run/clamav/clamd.ctl`. El usuario que ejecuta el worker de la cola (`www-data` en `deploy/supervisor/agento-worker.conf`) debe poder usarlo:

```bash
sudo usermod -aG clamav www-data
sudo systemctl restart clamav-daemon
sudo supervisorctl restart all       # el worker debe reiniciarse para tomar el grupo
```

Variables del `.env` del servidor:

```
ANTIVIRUS_DRIVER=clamav
ANTIVIRUS_SI_NO_DISPONIBLE=rechazar
CLAMAV_SOCKET=unix:///var/run/clamav/clamd.ctl
CLAMAV_TIMEOUT=20
```

### Alternativa TCP (solo si clamd corre en otro servidor)
En `/etc/clamav/clamd.conf`: `TCPSocket 3310` y `TCPAddr 127.0.0.1` (o la IP privada). **No lo expongas a internet**: clamd no tiene autenticación. Restringe con firewall a la IP del servidor de la aplicación. En el `.env`: `CLAMAV_SOCKET=tcp://IP:3310`.

### Tamaño máximo
`clamd` rechaza flujos mayores a `StreamMaxLength` (25 MB por defecto). Los CV están limitados a 5 MB (`RECLUTAMIENTO_CV_MAX_KB`), así que no hace falta cambiarlo. Si subes ese límite, sube también `StreamMaxLength` y `MaxFileSize` en `clamd.conf`.

## 3. Qué hacer si ClamAV no está disponible

`ANTIVIRUS_SI_NO_DISPONIBLE` decide el comportamiento:

| Valor | Efecto | Cuándo |
|---|---|---|
| `rechazar` | El archivo **sigue bloqueado** y se reintenta | **Producción** (por defecto con `APP_ENV=production`) |
| `permitir` | Lo da por limpio y deja constancia en el log | Local / desarrollo (por defecto fuera de producción) |

Con `ANTIVIRUS_DRIVER=nulo` (por defecto fuera de producción) no hay motor: solo se reconoce la cadena de prueba EICAR. **En producción el escáner nulo responde "no disponible"**: un servidor sin ClamAV nunca marca un archivo como limpio.

Impacto operativo: mientras `clamd` esté caído, los CV nuevos no se pueden abrir desde el panel (mensaje "en revisión de seguridad"). Los candidatos pueden seguir postulando.

## 4. Verificación tras instalar

```bash
# 1) clamd responde
echo PING | sudo -u www-data nc -U /var/run/clamav/clamd.ctl     # debe responder PONG
# 2) Prueba con la cadena EICAR (inofensiva): guárdala en un archivo y escanéala
printf '%s' 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*' > /tmp/eicar.txt
clamdscan /tmp/eicar.txt                                          # debe decir "Eicar-Signature FOUND"
rm /tmp/eicar.txt
# 3) Encolar el escaneo de lo pendiente y ver el resultado
cd /var/www/Plataforma-Agento/agento-backend
php artisan reclutamiento:escanear-documentos
```

Para una prueba de extremo a extremo, sube desde el portal un PDF que contenga la cadena EICAR: debe quedar `infectado`, aparecer la tarea "Archivo bloqueado por el antivirus" y no poder descargarse.

## 5. Operación

- **Firmas:** `clamav-freshclam` las actualiza solo. Si está detenido, las firmas envejecen: monitorea `systemctl status clamav-freshclam`.
- **Logs:** `/var/log/clamav/clamav.log` y el log de Laravel (busca "documento infectado" y "antivirus no disponible").
- **Cola:** el worker debe estar activo (`supervisorctl status`); sin worker los archivos se quedan `pendiente_escaneo`.
- **Cuarentena:** los infectados quedan en el disco privado bajo `cuarentena/`. No se borran solos: decide la política de depuración con RR. HH. (el candidato puede subir otro archivo desde una nueva postulación).
- **Falsos positivos:** un archivo legítimo marcado `infectado` no se libera desde el panel (no hay botón a propósito). Pide al candidato un archivo nuevo. Si hace falta liberar uno, debe hacerlo un administrador con acceso a la base, tras revisarlo.

## 6. Plan de reversa
Para volver al comportamiento anterior sin antivirus (no recomendado en producción): `ANTIVIRUS_DRIVER=nulo` + `ANTIVIRUS_SI_NO_DISPONIBLE=permitir`, luego `php artisan config:cache` y `supervisorctl restart all`. Los archivos pendientes se liberarán en el siguiente ciclo del comando programado.
