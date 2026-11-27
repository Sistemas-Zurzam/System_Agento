# Pendientes legales (Ley 29733) — para el abogado

> Este documento lista lo que **debe redactar o validar un abogado**. El código solo aporta la estructura técnica (textos versionados, solicitudes ARCO, registro de aceptación). **Ningún texto del sistema es definitivo.**

## 1. Textos que hay que redactar o validar

Se cargan en la tabla `seleccion_textos_legales` (tipo + versión + texto + vigencia). Al publicar una versión nueva, la anterior deja de ser válida para postular: quien envíe el texto viejo recibe un 422.

| Tipo | Estado actual | Qué falta |
|---|---|---|
| `consentimiento_postulacion` | Existe un texto provisional, copiado del frontend (versión `2026-10-v1`, sembrado por la migración `2026_11_19_000001`) | Redactar el texto definitivo y publicarlo como nueva versión. Hoy no detalla las finalidades ni los puntos de abajo |
| `politica_privacidad` | **No existe**. El endpoint responde 404 | Redactar la política y cargarla. El portal debería enlazarla desde el formulario de postulación |

### Contenido que los textos deben cubrir (checklist, no redacción)
1. **Finalidades** concretas y separadas: participar en el proceso al que postula; ser considerado en futuros procesos (banco de talentos); evaluaciones (ATS, pruebas psicométricas y de conocimientos); contacto y notificaciones. Definir cuáles requieren consentimiento independiente.
2. **Responsable del banco de datos personales**: ¿cada empresa cliente, Agento, o ambos (encargado vs. responsable)? Esto cambia quién atiende los derechos ARCO y quién inscribe el banco de datos en el registro de la Autoridad.
3. **Transferencias y encargados de tratamiento**: proveedor de correo (Resend), hosting/servidores, y la **futura IA** (proveedor, país, qué datos recibe). Si hay flujo transfronterizo, nivel de protección adecuado.
4. **Plazos de conservación**: hoy 24 meses desde la última postulación (`RECLUTAMIENTO_RETENCION_MESES`), con anonimización inerte mientras `RECLUTAMIENTO_RETENCION_ELIMINAR=false`. Confirmar el plazo y el tratamiento de los **respaldos**, que no se purgan al anonimizar.
5. **Datos de documentos**: DNI, CUL (certificado único laboral, que puede contener antecedentes) y CV. Confirmar si corresponde tratarlos como datos sensibles o con garantías reforzadas, y la base de legitimación para pedirlos.
6. **Datos sensibles** (discapacidad, salud, etc.): hoy el formulario no los pide, pero el CV o el campo libre `resumen` pueden contenerlos. Definir la política y, si se agregan campos, consentimiento expreso y separado.
7. **Decisiones automatizadas**: el puntaje ATS y el puntaje final ordenan candidatos. Determinar si hay que informar al titular y ofrecer revisión humana.
8. **Derechos del titular y canal**: cómo ejercer acceso, rectificación, cancelación y oposición, y a quién. **La oposición no está implementada** (solo A, R y C); decidir si se atiende por el mismo flujo.

## 2. Valores técnicos que el abogado debe validar

| Valor | Dónde | Estado |
|---|---|---|
| Plazo de atención de **acceso**: 20 días hábiles | `config/reclutamiento.php` → `arco.plazo_dias_habiles.acceso` | Valor inicial, **a validar** |
| Plazo de **rectificación**: 10 días hábiles | idem `.rectificacion` | Valor inicial, **a validar** |
| Plazo de **cancelación**: 10 días hábiles | idem `.cancelacion` | Valor inicial, **a validar** |
| Cómputo de días hábiles | `addWeekdays` (lunes a viernes) | **No descuenta feriados**; decidir si hace falta calendario de feriados |
| Cancelación de un candidato **contratado** | `ArcoService::ejecutarCancelacion` | Se rechaza: no se eliminan datos mientras exista relación laboral. Validar el criterio y el texto de respuesta |
| Efectos de **revocar el consentimiento** | `ArcoService::revocarConsentimiento` | Ver §3. Validar que sean los efectos que corresponden |

## 3. Qué hace el sistema hoy (para que el abogado lo revise)

**Revocar el consentimiento** (`POST /api/publico/candidatos/consentimiento/revocar`, sesión verificada por correo):
1. Marca como revocados (`revocado_at`) todos los consentimientos de ese correo en todas las empresas.
2. **Retira** las postulaciones en curso (activas o seleccionadas), con motivo "Revocación del consentimiento por el candidato".
3. Crea **una solicitud de cancelación por empresa** para que RR. HH. elimine los datos. La eliminación **no es automática**: la ejecuta RR. HH. desde la bandeja (anonimización irreversible). Quien tenga una contratación no se elimina.
4. Registra el evento en Auditoría (`consentimiento.revocado`).

**Acceso / exportación** (`GET .../candidatos/arco/exportacion` y descarga de archivos propios): se entrega al instante por empresa (perfil, experiencias, estudios, habilidades, documentos, postulaciones con su estado y consentimientos). **No incluye** notas internas, puntajes, motivos de descarte internos ni resultados de evaluaciones, por no ser datos aportados por el titular. Validar si el titular tiene derecho a estos últimos.

**Rectificación y cancelación**: quedan como solicitudes con estado (`pendiente`, `en_proceso`, `atendida`, `rechazada`) y fecha límite, visibles en *Selección → Derechos ARCO*. Un rechazo exige motivo.

**Auditoría**: se registran `arco.acceso`, `arco.acceso_documento`, `arco.rectificacion_solicitada`, `arco.cancelacion_solicitada`, `arco.<tipo>_<estado>` y `consentimiento.revocado`, con solo identificadores (sin datos personales en el registro).

## 4. Pendientes técnicos relacionados (no son legales, pero dependen de la decisión legal)
- Interfaz del candidato para ejercer ARCO y revocar (hoy hay API y métodos en `portalEmpleosApi.arco`, **sin pantalla** en el portal de empleos).
- Notificar por correo al candidato cuando su solicitud cambie de estado.
- Alertas de solicitudes por vencer para RR. HH.
- Anonimizar también los datos del candidato en la bitácora de Auditoría y en los respaldos (hoy no se alcanzan).

## 3. Tributario — pago por clases (integración Firme Studio)

> No es protección de datos, pero también **debe validarlo un profesional** (contador del cliente), no el código.

| Tema | Qué hace hoy el sistema | Qué debe validarse |
|---|---|---|
| **Retención de renta de 4ta categoría** de los recibos por honorarios de las instructoras | Retiene la tasa configurada (`renta_4ta_tasa`) sobre el bruto del mes cuando **supera** el umbral configurado (`renta_4ta_umbral`) y la instructora no tiene suspensión de retenciones. Redondeo medio hacia arriba a 2 decimales. Es la misma regla que usan los pagos de locadores que ya hace Agento (pagos semanales de Zazu) | Que la tasa, el umbral y su forma de aplicar (bruto del lote vs. acumulado del mes entre varios lotes o varios pagos) sean los correctos para el cliente. Que la suspensión de retenciones (`tiene_suspension_renta_4ta`) esté bien registrada |
| Instructoras que **no** sean locadoras (planilla) | Quedan **observadas**: no entran al abono por clase | Definir si alguna instructora está en planilla y cómo se le paga |
| Calificación de la relación (locación vs. laboral) | No se evalúa | Revisión del contador/abogado del cliente |
