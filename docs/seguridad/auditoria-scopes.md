# Auditoría de scopes de empresa (`withoutGlobalScope`) y contexto de empresa

Rama `fix/04-aislamiento-multiempresa`. Complementa la sección 8.1 de `docs/analisis-integracion-agento.md`.

## 1. Cómo funciona ahora el aislamiento

`EmpresaScope` (`Modules/Configuracion/Models/Scopes/EmpresaScope.php`) **falla cerrado**. Orden de decisión:

1. **Modo sistema** declarado (`EmpresaContext::comoSistema(fn, motivo: '…')`): sin filtro. Exige motivo y queda registrado en el canal de log `tenant`.
2. **Empresa del contexto** (`EmpresaContext::como($id, fn)`): filtra por ella.
3. **Usuario autenticado** por el guard `api`: filtra por su empresa activa (cubre respuestas en streaming).
4. Nada de lo anterior: en `local`/`testing` lanza `EmpresaNoDefinidaException`; en producción la consulta **no devuelve filas**.

Dónde se establece el contexto:

| Punto de entrada | Contexto |
|---|---|
| `JwtMiddleware` | Empresa del token, restaurada al terminar. Los bindings de modelo (`{proceso}`, `{ciclo}`…) se siguen resolviendo **antes** de este middleware, como en `main`; ahí filtra el respaldo por usuario autenticado (paso 3 del scope). No se cambió la prioridad del middleware para no alterar el orden 401/404 de las rutas |
| `AutenticarCandidato` | Modo sistema con motivo (el candidato es global por correo; las consultas se filtran por ese correo) |
| `/api/publico/*` | `contexto.sistema:<motivo>`: la empresa sale del proceso/token resuelto por el servidor, nunca del request. `PortalEmpleosService::postular` fija la empresa del proceso (`EmpresaContext::como`) |
| Jobs | `TenantServiceProvider`: la empresa del contexto viaja en el payload (`empresa_contexto`) y el worker la restaura y la devuelve al terminar. **Excepción: los jobs de Nóminas** conservan el comportamiento de `main` (en el worker, sin filtro de empresa; no heredan el contexto) por la regla permanente |
| Comandos | `reclutamiento:cerrar-intentos-vencidos` y `reclutamiento:vencer-candidatos` recorren cada empresa con su contexto; ambos aceptan `--empresa=ID` |
| Seeders | `DatabaseSeeder` en modo sistema con motivo |

**Reglas que hace cumplir `ArquitecturaScopesTest`:** ningún `withoutGlobalScope(s)` nuevo fuera de la lista permitida (ni uno más en un archivo ya permitido); `comoSistema` siempre con `motivo:`; solo tres sitios pueden usar `entrarSistema`.

## 2. Hallazgos corregidos

| Hallazgo | Corrección |
|---|---|
| **`Horario` sin scope y sin comprobación de pertenencia** en `update`, `duplicar` y `cambiarEstado`: un usuario con `horarios.editar/crear` de la empresa A podía alterar o duplicar horarios de la B (IDOR). Lo detectó `AislamientoRutasTest` | `HorarioController::exigirDeLaEmpresaActiva` (404 si el horario no es de la empresa activa) |
| `AplicacionPruebaService::cerrarVencidos` ignoraba el scope | Ahora usa el scope y se ejecuta por empresa desde el comando |

## 2b. Riesgos detectados en Nóminas (NO corregidos: regla permanente)

La regla permanente prohíbe modificar Nóminas, incluso para corregir seguridad. Estos dos usos reciben un id de la ruta y no filtran por la empresa autorizada; **quedan documentados, pendientes de decisión del responsable de Nóminas** (detalle en `docs/seguridad/estado-hardening.md`, N1 y N2):

| Archivo | Uso | Corrección propuesta (no aplicada) |
|---|---|---|
| `Nominas/Http/Controllers/BoletaController.php:340` | `Colaborador::withoutGlobalScopes()->findOrFail($colaborador)` | `->where('empresa_id', $empresa->id)` |
| `Nominas/Http/Controllers/CicloRemunerativoController.php:409` | `AsistenciaIncidencia::withoutGlobalScope(EmpresaScope::class)->findOrFail($incidencia)` | `->where('empresa_id', $empresa->id)` o confirmar que `resolverIncidencia()` valida la pertenencia |

## 3. Auditoría de los usos de `withoutGlobalScope(s)`

Inicialmente 81 coincidencias en 28 archivos: 80 llamadas y 1 comentario. Hoy hay **79 llamadas**; una se eliminó. Las líneas son las del análisis inicial (pueden haberse movido unas pocas).

Criterios: **Legítimo** = filtra explícitamente por `empresa_id` (o por un id obtenido de un registro ya autorizado) y existe una razón técnica; **Corregido** = se añadió filtro; **Por diseño** = cruza empresas a propósito.

### 3.1 `Area` con `withoutGlobalScope(EmpresaScope)` y `where empresa_id` explícito (Legítimo)
Un administrador global opera sobre una empresa distinta de su empresa activa; el scope sumaría dos `empresa_id` contradictorios y ocultaría todo. Todos llevan `where('empresa_id', …)` explícito.

| Archivo:línea | Uso |
|---|---|
| `Configuracion/Http/Controllers/AreaController.php:31` | Lista áreas de la empresa de la ruta, tras `tieneAccesoA` |
| `Configuracion/Http/Requests/StoreAreaRequest.php:28` | Nombre duplicado dentro de la empresa de la ruta |
| `Configuracion/Http/Requests/StoreUsuarioRequest.php:55`, `UpdateUsuarioRequest.php:84` | El área pertenece a la empresa principal indicada |
| `Personas/Services/ColaboradorService.php:361, 1055` | El área pertenece a la empresa del colaborador (`->where('empresa_id', $empresa->id)`) |
| `PortalCliente/Application/AlcancePortal.php:55, 152, 164` | Áreas de la empresa del usuario/portal |
| `Reclutamiento/Application/AlcanceSeleccion.php:50, 75` | Áreas del alcance de la empresa del usuario |
| `Reclutamiento/Application/SolicitudService.php:77, 117, 334` | Áreas/colaboradores de la empresa del alcance (`empresa_id` explícito) |
| `Nominas/Services/ReporteBonoAsistenciaService.php:21` | Áreas de `$empresa->id` |

### 3.2 Nóminas ↔ Asistencia: consultas entre módulos con `empresa_id` explícito (Legítimo)
La empresa del cálculo ya fue autorizada por el caso de uso y puede no ser la activa del usuario.

| Archivo:línea | Uso |
|---|---|
| `Nominas/Services/BoletaService.php:94, 427` | Horas extra y permisos con `where empresa_id` |
| `Nominas/Services/BoletaService.php:268` | Eager load de `colaborador.area` desde una boleta autorizada |
| `Nominas/Services/BonoAsistenciaService.php:150, 398` | Resultados diarios con `where empresa_id` |
| `Nominas/Services/BonoAsistenciaService.php:408` | `update` por ids obtenidos en la consulta anterior (con `empresa_id`) |
| `Nominas/Services/DescansoSemanalComplementariaService.php:46, 50, 56` | Con `where empresa_id` |
| `Nominas/Services/IncidenciasPendientesNominaService.php:29` | Con `where empresa_id` |
| `Nominas/Services/PlanillaComplementariaService.php:226, 392` | Con `where empresa_id` |
| `Nominas/Services/ReporteBonoAsistenciaService.php:24, 29, 31, 33` | Con `where empresa_id` |
| `Nominas/Http/Controllers/CicloRemunerativoController.php:107, 108, 252` | Eager load de sede/área de colaboradores de un ciclo autorizado |
| `Nominas/Support/ParametrosVigentesResolver.php:78, 188, 290` | `where parametro_laboral_valores.empresa_id = $empresa->id` (comentado en el código) |

### 3.3 Bloqueos y lecturas por id derivado de un registro ya autorizado (Legítimo, riesgo bajo)
`Colaborador` **no tiene `EmpresaScope`**, así que estas consultas ya no filtrarían aunque se quitara el bypass; el id proviene de una boleta, liquidación o complementaria autorizada (`tieneAccesoA`).

| Archivo:línea | Uso |
|---|---|
| `Nominas/Http/Controllers/LiquidacionCeseController.php:122` | `lockForUpdate` del colaborador de la liquidación |
| `Nominas/Services/DescansoSemanalComplementariaService.php:88` | Bloqueo de colaboradores de boletas autorizadas |
| `Nominas/Services/PlanillaComplementariaService.php:761, 1512, 1676, 1751` | Bloqueo/lectura de colaboradores de la complementaria |
| `Nominas/Support/ValorHoraOrdinaria.php:37` | Promedio por `colaborador_id` de un colaborador autorizado. **Mejora pendiente:** pasar `empresa_id` |

### 3.4 Corregidos (fuera de Nóminas)
| Archivo | Cambio |
|---|---|
| `Reclutamiento/Application/AplicacionPruebaService.php:277` | **Eliminado** el bypass (usa el scope dentro del contexto de empresa) |

(`BoletaController:340` y `CicloRemunerativoController:409` figuran en §2b: sin corregir.)

### 3.5 Reclutamiento: flujos públicos por token y tareas de sistema
| Archivo:línea | Uso | Veredicto |
|---|---|---|
| `AplicacionPruebaService.php:78, 100, 303, 304, 332` | Se parte del token de la asignación (secreto de 64 caracteres, hash en BD): el resto se deriva de esa asignación | Legítimo (acceso por token, sin usuario) |
| `EnlacePruebaService.php:69, 101, 108, 131, 133` | `resumen` ya filtra por `empresa_id` del enlace; el resto parte del token del enlace | Legítimo |
| `EnvioAutomaticoPruebas.php:37, 59, 71, 73` | Parte de una postulación ya cargada; `where` por familia/prueba | Legítimo |
| `EtiquetadoAutomatico.php:21` | `firstOrCreate` con `empresa_id` en la clave | Legítimo |
| `PortalEmpleosService.php:309` | Unicidad **global** del código de seguimiento | Por diseño |
| `PruebaService.php:39, 49, 303, 330, 351, 409` y `ProcesoSeleccion.php:97` | El banco incluye pruebas compartidas de Agento (`empresa_id` NULL) además de las propias: `whereNull('empresa_id') orWhere empresa_id = X` | Por diseño (banco único) |
| `RetencionCandidatosService.php:42–72` | Retención programada (cron); ahora se invoca **por empresa** con su contexto | Por diseño (cron) |
| `Jobs/EvaluarPostulacionJob.php:35, 50` | Recibe `empresa_id` y filtra por él | Legítimo |

## 4. Casos que cruzan empresas por diseño

- **`PortalEmpleosService::misPostulaciones` y `perfilPrevio`**: un correo verificado ve **sus propias** postulaciones en todas las empresas (es la misma persona). Corren en el modo sistema declarado por `AutenticarCandidato` y filtran por el correo del acceso verificado. No exponen datos internos (notas, puntajes ni etapa interna).
- **Webhook de WhatsApp del CRM** (`Crm\Application\RecibirWebhookWhatsapp::canal`): Meta no envía la empresa, así que el canal se busca en modo sistema **solo por el token secreto de 40 caracteres de la URL** (`webhook_token`, único). Encontrado el canal, todo lo demás (conversaciones, mensajes, estados) corre en `EmpresaContext::como(empresa del canal)` y cada aviso se valida con la firma `X-Hub-Signature-256` del App secret del canal.
- **Administrador global** (`User::esAdministradorGlobal`): un `administrador` de cualquier empresa opera en todas, a propósito. Está fijado por `test_el_administrador_global_opera_en_todas_las_empresas_por_diseno`. Las rutas que reciben `{empresa}` lo validan con `tieneAccesoA`.

## 5. Pruebas

- `AislamientoModelosTest`: para **cada** modelo con scope (30), con dos empresas, una no ve ni encuentra lo de la otra, el modo sistema ve todo y sin contexto falla cerrado (y en producción devuelve 0 filas).
- `AislamientoRutasTest`: un usuario de A (rol amplio no administrador) llama a todas las rutas autenticadas que reciben un modelo con `empresa_id` usando un registro de B. Debe dar 403/404.
- `AislamientoJobsYComandosTest`: el job hereda la empresa y la restaura; sin contexto falla cerrado; los comandos recorren cada empresa y respetan `--empresa`.
- `ArquitecturaScopesTest`: reglas del punto 1.

## 6. Pendientes y limitaciones (honestas)

1. **35 modelos con `empresa_id` no tienen `EmpresaScope`**: 20 de Nóminas (solo documentados, no se tocan) y 15 fuera de Nóminas (`Colaborador`, `Horario`, `Sede`, `User`, `EmpresaCuentaBancaria`, `EmpresaUsuario`, `ColaboradorPeriodoPrueba` y 8 de Selección; issue de seguimiento). Su aislamiento depende de comprobaciones explícitas por controlador o servicio (`tieneAccesoA`, `autorizarAccion`, `empresaAutorizadaDelCiclo`…). Hoy las rutas están cubiertas por el test, pero **no hay defensa en profundidad**. Añadir el scope exige revisar las consultas multiempresa del administrador global: se propone como trabajo aparte.
2. **46 rutas responden 422 en la sonda** (32 de Nóminas, solo documentadas, y 14 fuera de Nóminas) porque validan el cuerpo antes de autorizar; con cuerpo vacío la prueba no es concluyente. Se revisó **por lectura de código** que cada una autoriza (servicio o controlador). Están enumeradas en `AislamientoRutasTest::VALIDAN_ANTES_DE_AUTORIZAR`: una ruta nueva con 422 obliga a revisarla. **No se probó con cuerpos válidos.**
3. Los tests existentes parten en un modo sistema de base declarado (`TestCase`), porque muchos invocan servicios y modelos sin petición HTTP. Las peticiones HTTP con JWT no se ven afectadas (el middleware fija la empresa) y los tests de aislamiento activan el modo estricto (`$aislamientoEstricto = true`).
4. Las rutas públicas corren en modo sistema por grupo (no por método): la acotación por empresa la hace cada servicio con la empresa del proceso o token. Es más grueso que un contexto por consulta y se documenta como deuda.
5. `EmpresaScope` seguirá usando el usuario del guard `api` como respaldo (3er paso) para respuestas en streaming; debe quitarse cuando todas las rutas estén bajo el contexto.
