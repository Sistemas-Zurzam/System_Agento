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
| 5b | ~28 archivos con `withoutGlobalScope(s)` | **Auditado** (Nóminas: documentado, sin corregir) | `integracion/hardening` | 79 llamadas auditadas en `docs/seguridad/auditoria-scopes.md`; 1 bypass eliminado (Reclutamiento); test de arquitectura impide nuevos. **2 búsquedas sin empresa en Nóminas: riesgo detectado, pendiente de decisión del responsable de Nóminas (ver sección propia); no se tocaron por la regla permanente** |
| 5c | Fugas entre empresas en endpoints | **Corregido (1 hallazgo real)** | `fix/04-…` | Hallazgo y corrección: `Horario` (`update`, `duplicar`, `estado`) permitía a un usuario de la empresa A tocar horarios de la B. Se corrigió y hay test que recorre las rutas |
| 5d | Defensa en profundidad en modelos sin scope | **Pendiente** (15 modelos fuera de Nóminas en issue; 20 de Nóminas solo documentados) | `integracion/hardening` | 35 modelos con `empresa_id` no tienen `EmpresaScope`. **Nóminas (20, no se tocan):** `Boleta`, `BoletaEnvio`, `CicloRemunerativo`, `PlanillaComplementaria`, `LiquidacionCese`, `QuintaAjusteCese`, `BonoAsistenciaLote`, `BeneficioSocialEnvio`, `BeneficioSocialHistorico`, `SaldoLaboralPendiente`, `SaldoVacacionalHistorico`, `NominaImportacionHistorica`, `NominaImportacionHistoricaDetalle`, `PropuestaConcepto`, `ConsumoComidaAplicacion`, `ConsumoComidaDetalle`, `ConsumoComidaEvento`, `ConsumoComidaImportacion`, `ColaboradorConceptoPeriodo`, `VacacionMovimiento`. **Fuera de Nóminas (15, issue):** `User`, `Sede`, `EmpresaCuentaBancaria`, `EmpresaUsuario`, `Colaborador`, `ColaboradorPeriodoPrueba`, `Horario` y 8 de Selección. Hoy los cubre el test de rutas |
| 5e | Aislamiento verificado en todas las rutas | **Parcial** | `integracion/hardening` | 46 rutas responden 422 en la sonda (validan antes de autorizar): su autorización se revisó **leyendo código**, no con cuerpos válidos. 14 están fuera de Nóminas (issue) y 32 son de Nóminas (solo documentadas). Las rutas públicas corren en modo sistema por grupo (más grueso que por consulta) |
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
| **`integracion/hardening`** (las siete fusionadas, con la regla permanente aplicada) | **793** | **verde** |

Los números de cada rama parten de `main` por separado; la fila de integración es la que cuenta como estado final. En integración hubo que resolver conflictos de fusión (`.env.example`, `routes/api.php`, `routes/seleccion.php`, `config/logging.php`, `config/reclutamiento.php`, `RegistradorAuditoria`) y adaptar el job y el comando de antivirus para no saltarse el scope (la regla de arquitectura del bloque 4 lo detectó). Ese ajuste solo existe en `integracion/hardening`.

## Nóminas: riesgos detectados (pendientes de decisión del responsable de Nóminas)

**Regla permanente:** no se modifica nada de Nóminas. Lo siguiente se detectó y **no se corrigió**; queda a decisión del responsable de Nóminas. Las líneas son aproximadas.

| # | Ubicación | Riesgo | Corrección propuesta (NO aplicada) |
|---|---|---|---|
| N1 | `app/Modules/Nominas/Http/Controllers/BoletaController.php`, `registrarHorasExtraManual` (≈ línea 340) | `Colaborador::withoutGlobalScopes()->findOrFail($colaborador)` con el id de la ruta, **sin filtrar por la empresa autorizada del ciclo**. `Colaborador` no tiene scope: se puede cargar un colaborador de otra empresa. El servicio rechaza después si no hay boleta de ese colaborador en el ciclo, pero la lectura ocurre | `->where('empresa_id', $empresa->id)` antes del `findOrFail` |
| N2 | `app/Modules/Nominas/Http/Controllers/CicloRemunerativoController.php`, `resolverIncidenciaPendienteCierre` (≈ línea 409) | `AsistenciaIncidencia::withoutGlobalScope(EmpresaScope::class)->findOrFail($incidencia)` con el id de la ruta. El comentario del código dice que la pertenencia se valida dentro de `resolverIncidencia()`; **no se verificó** | `->where('empresa_id', $empresa->id)` o confirmar que `resolverIncidencia()` valida la pertenencia |
| N3 | 20 modelos de Nóminas con `empresa_id` sin `EmpresaScope` (lista en la fila 5d) | Su aislamiento depende de comprobaciones explícitas en cada controlador (`tieneAccesoA`, `empresaAutorizadaDelCiclo`). No hay defensa en profundidad | Valorar `EmpresaScope` (los modelos `Boleta` y `CicloRemunerativo` declaran a propósito no usarlo: ver su comentario) |
| N4 | 32 rutas de Nóminas (`ciclos-remunerativos/*`, `planillas-complementarias/*`, `nominas/importaciones-historicas/*`) | Validan el cuerpo **antes** de autorizar: ante un recurso ajeno responden 422 en vez de 403/404. Se revisó por lectura de código que autorizan, no con cuerpos válidos | Invertir el orden y probarlas con cuerpos válidos |
| N5 | 32 `withoutGlobalScope(s)` en servicios y controladores de Nóminas | Casi todos filtran por `empresa_id` explícito (auditados en `docs/seguridad/auditoria-scopes.md` §3.2 y §3.3); N1 y N2 son las excepciones | Sin acción salvo N1 y N2 |

## Impacto en Nóminas: ninguno

Los cambios de esta rama que son transversales se revisaron uno por uno. Donde habrían alterado a Nóminas, se conserva el comportamiento de `main` **sin editar archivos de Nóminas**.

| Cambio | ¿Alcanza a Nóminas? | Cómo se conserva el comportamiento de `main` |
|---|---|---|
| `EmpresaScope` falla cerrado | Sí en potencia: Nóminas tiene un modelo con scope (`BeneficioSocial`) y consulta modelos con scope de otros módulos (Asistencia, `Area`) | En HTTP el filtro es el mismo (empresa del usuario). En el worker, los **jobs de Nóminas** (`CalcularPlanillaJob`, `EnviarBoletaPorCorreoJob`, `EnviarBoletaCtsPorCorreoJob`) corrían sin filtro de empresa: `TenantServiceProvider` los excluye del nuevo comportamiento (no heredan el contexto del payload y, sin contexto ni usuario, entran en modo sistema declarado). Dentro de una petición con cola `sync` no se altera el contexto |
| `EmpresaContext` | No: ningún archivo de Nóminas lo usa | n/a |
| Prioridad de `jwt` sobre los bindings | **Revertida.** Cambiaba el orden 401/404 de todas las rutas, también las de Nóminas, y no era necesaria en producción | `bootstrap/app.php` queda sin esa línea |
| Comandos | No: Nóminas no tiene comandos ni tareas programadas | n/a |
| Seeders | `DatabaseSeeder` ejecuta seeders de Nóminas (catálogos); solo se envolvió la llamada en modo sistema declarado, con el mismo efecto que antes | Sin cambios en los seeders de Nóminas |
| Tests heredados | `tests/TestCase.php` ya no fuerza modo sistema: conserva el comportamiento anterior (sin contexto ni usuario, sin filtro; con usuario, filtra) solo con `APP_ENV=testing` | Comparación de tests más abajo |

**Efectos globales que sí alcanzan a las respuestas de Nóminas** (infraestructura, no código de Nóminas; se declaran para que no sorprendan): cabeceras de seguridad HTTP en todas las respuestas, incluidas las descargas; CORS restringido a los orígenes listados (solo importa en peticiones entre orígenes distintos); duración del token de sesión de 60 min con renovación automática del panel; y los limitadores de `/login`, `/refresh` y `/me`. Ninguno cambia datos, rutas ni contratos de Nóminas.

### Evidencia

1. `git diff main...integracion/hardening --stat -- app/Modules/Nominas tests/Feature/Modules/Nominas` → **vacío**. Tampoco hay archivos con "omina" en el nombre entre los cambiados ni líneas de Nóminas tocadas en `routes/api.php`.
2. Tests de Nóminas (52 archivos: `tests/Feature/Modules/Nominas` y los que referencian Nóminas en `tests/Feature` y `tests/Unit`), ejecutados en `main` (`5b937df`) y en la rama (`817e431`):

| | `main` | `integracion/hardening` |
|---|---|---|
| Tests | 320 | 320 |
| Aprobados | 320 | 320 |
| Aserciones | 1258 | 1258 |
| Solo en uno de los dos / resultado distinto | 0 / 0 | 0 / 0 |

Comparados caso por caso con el registro JUnit de cada ejecución: **iguales**.

## Migraciones y seeders de la rama (regla de datos existentes)

Cambios en `database/` frente a `main`: dos migraciones nuevas y el `DatabaseSeeder`. Ninguna migración ni seeder modifica ni borra registros existentes.

| Archivo | Operaciones | Tipo | `down()` |
|---|---|---|---|
| `2026_11_19_000001_crear_textos_legales_y_solicitudes_arco.php` | Crea `seleccion_textos_legales` (+ índices); crea `seleccion_solicitudes_arco` (+ índices); añade la columna `seleccion_consentimientos.texto_legal_id` (nullable, FK); **inserta 1 fila solo en la tabla nueva** `seleccion_textos_legales` (el texto vigente, si no existe) | tabla nueva ×2, columna nueva, índices nuevos, siembra en tabla nueva | Sí: quita la columna y borra las dos tablas nuevas |
| `2026_11_19_000002_agregar_escaneo_a_documentos_candidato.php` | Añade 3 columnas a `seleccion_documentos` (`estado_escaneo`, `resultado_escaneo`, `escaneado_at`) y el índice `sel_doc_estado_escaneo`. **Sin UPDATE**: las filas existentes reciben el `DEFAULT 'legado'` de la propia columna nueva | columna nueva ×3, índice nuevo | Sí: quita el índice y las columnas |
| `database/seeders/DatabaseSeeder.php` | Solo envuelve las llamadas existentes en `EmpresaContext::comoSistema` (modo sistema declarado). No añade ni cambia ninguna operación de datos. **No se ejecuta en producción** | modificado | n/a |

**Corrección de esta revisión:** la versión anterior de la migración `…000002` ejecutaba `UPDATE seleccion_documentos SET estado_escaneo='legado'` sobre las filas existentes. Era una violación de la regla; se eliminó y se sustituyó por el `DEFAULT`. Un test (`AntivirusDocumentosTest`) comprueba que la migración no contiene UPDATE ni DELETE.

**Lo que ya estaba subido:** antes de esta revisión se habían subido al remoto las ramas `integracion/hardening` y `dev` con las dos violaciones (cambios en Nóminas y el UPDATE). Se corrigen con un commit posterior en ambas ramas (sin force-push). Las ramas locales `fix/01` a `fix/07` conservan el contenido anterior y **no se actualizaron**: la fuente de verdad es `integracion/hardening`.

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
2. Añadir `EmpresaScope` a los modelos sensibles sin scope **fuera de Nóminas** (`Colaborador`, `User`, `Horario`, `Sede`…) y quitar el respaldo por usuario autenticado del scope. Los de Nóminas solo están documentados.
3. Cuerpos válidos en el test de rutas para las 14 que validan antes de autorizar **fuera de Nóminas** (las 32 de Nóminas solo están documentadas).
4. Decisión del responsable de Nóminas sobre N1 a N5 (sección "Nóminas: riesgos detectados").

## Herramientas
El plugin `github` no conectó (error 400 de cabecera de autorización) y `context7` pidió autorización OAuth que esta sesión no podía completar; por eso no se crearon PR y la documentación de Laravel, jwt-auth y GitHub Actions se consultó en el código del propio `vendor/` y en los conocimientos del modelo, no con `context7`.
