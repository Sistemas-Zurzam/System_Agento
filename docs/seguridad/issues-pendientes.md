# Issues pendientes del hardening (borradores listos para publicar)

Estos issues **no se han creado en GitHub** (la sesión no tenía `gh`, token ni plugin de GitHub). Cada uno trae título, prioridad, contexto y criterio de aceptación. Con `gh` instalado y autenticado se publican con el script del final.

Prioridades: **P0** = bloquea el lanzamiento público; **P1** = alta, siguiente iteración; **P2** = media.

---

## 1. [P0] Sesión del panel: refresh token en cookie `httpOnly` (ADR-sesion-tokens, opción A)

**Requisito obligatorio antes del lanzamiento público de Agento.**

**Contexto.** Hoy el JWT del personal vive en `localStorage` y lo puede leer cualquier script de la página (XSS, dependencia comprometida). Se mitigó con TTL de 60 min, renovación y CSP (opción B), pero no se elimina el robo del token. `docs/decisiones/ADR-sesion-tokens.md` (§3 opción A) describe el diseño.

**Alcance.**
- Backend: refresh token propio (opaco, hasheado en BD, con rotación en cada uso y detección de reutilización), endpoints de refresh y logout por cookie, revocación coherente con `token_version`.
- Cookie `httpOnly; Secure; SameSite=Strict`; defensa CSRF (cabecera personalizada + `SameSite`).
- Frontend: access token solo en memoria; restaurar la sesión al recargar; varias pestañas.
- Desarrollo local (frontend `:5173` y API `:8000`): proxy de Vite o configuración equivalente.
- CORS: si el frontend se sirve desde otro origen, `supports_credentials` con orígenes explícitos.

**Criterios de aceptación.**
- [ ] Tras iniciar sesión no existe ningún token reutilizable en `localStorage`, `sessionStorage` ni accesible por JavaScript.
- [ ] Recargar la página mantiene la sesión sin pedir credenciales; cerrar sesión invalida el refresh token en servidor.
- [ ] Reutilizar un refresh token ya rotado invalida toda la familia de tokens y fuerza nuevo login.
- [ ] Un POST cross-site al endpoint de refresh es rechazado (test de CSRF).
- [ ] Revocar la sesión de un usuario (`token_version`) la corta también por esta vía.
- [ ] Tests de backend (rotación, reutilización, expiración, CSRF) y de la renovación en el frontend; CI en verde en SQLite y MySQL.
- [ ] El ADR se actualiza a "implementado".

---

## 2. [P1] `EmpresaScope` en los modelos con `empresa_id` que no lo tienen (fuera de Nóminas)

**Alcance:** **fuera de Nóminas.** Los 20 modelos de Nóminas con `empresa_id` sin scope **solo se documentan** (`docs/seguridad/estado-hardening.md`, N3) y quedan a decisión del responsable de Nóminas; este issue no los toca.

**Contexto.** Hay modelos con `empresa_id` sin `EmpresaScope`: su aislamiento depende de comprobaciones en cada controlador o servicio (`tieneAccesoA`, `autorizarAccion`…). Hoy `AislamientoRutasTest` los cubre, pero no hay defensa en profundidad: una ruta nueva olvidada filtraría datos. Detalle en `docs/seguridad/auditoria-scopes.md` §6.

**Modelos (15):**
- Configuración: `Sede`, `EmpresaCuentaBancaria`, `EmpresaUsuario`, `User`.
- Personas: `Colaborador`, `ColaboradorPeriodoPrueba`.
- Asistencia: `Horario`.
- Selección: `Consentimiento`, `EntrevistaEvaluacion`, `EvaluacionAts`, `PostulacionMovimiento`, `PuntajeFinal`, `PruebaIntento`, `PruebaImagen`, `Prueba` (tiene `empresa_id` nulo para el banco compartido de Agento y su propio scope de visibilidad: valorar caso aparte).

**Dificultad conocida.** Un `administrador` es global por diseño (`User::esAdministradorGlobal`) y hay servicios que operan sobre una empresa distinta de la activa del usuario; cada modelo con scope obliga a revisar esos usos y pasarlos a `EmpresaContext::como($empresaId, …)`. **`Colaborador` es consultado por Nóminas** (que no se puede modificar): antes de ponerle scope hay que demostrar, con los tests de Nóminas y con el responsable de Nóminas, que no cambia su comportamiento.

**Criterios de aceptación.**
- [ ] Cada modelo de la lista lleva `#[ScopedBy([EmpresaScope::class])]` (o queda documentado por qué no).
- [ ] `AislamientoModelosTest` los incluye automáticamente y pasa.
- [ ] **No se modifica ningún archivo de Nóminas** (`git diff main...<rama> --stat -- app/Modules/Nominas` vacío) y los tests de Nóminas dan el mismo resultado que en `main`.
- [ ] Los `withoutGlobalScope(s)` fuera de Nóminas auditados se reemplazan por `EmpresaContext::como` o se justifican; `ArquitecturaScopesTest` no necesita ampliar su lista.
- [ ] Se quita el respaldo por usuario autenticado del `EmpresaScope` (paso 3 de su decisión) solo cuando todas las rutas fuera de Nóminas estén bajo el contexto y Nóminas siga idéntico.
- [ ] Sin migraciones de datos: solo cambios de código. La suite completa pasa en SQLite y MySQL. Se entrega por PRs pequeños.

---

## 3. [P1] Rutas que validan antes de autorizar (fuera de Nóminas): invertir el orden y probarlas con cuerpos válidos

**Alcance:** **fuera de Nóminas.** Las 32 rutas de Nóminas (`ciclos-remunerativos/*`, `planillas-complementarias/*`, `nominas/importaciones-historicas/*`) **solo se documentan** (N4) y no se tocan.

**Contexto.** Hay rutas que responden 422 ante un cuerpo vacío porque el `FormRequest` o `$request->validate()` corre **antes** de comprobar que el recurso pertenece a la empresa del usuario. No filtran datos, pero (a) revelan la existencia de recursos ajenos (422 en vez de 404/403) y (b) la prueba dinámica de aislamiento no es concluyente para ellas. Su autorización se revisó solo leyendo código.

**Rutas (14):** `PUT empresas/{empresa}`, `PATCH empresas/{empresa}/estado`, `POST empresas/{empresa}/logo`, `POST empresas/{empresa}/sedes`, `PUT empresas/{empresa}/sedes/{sede}`, `POST empresas/{empresa}/areas`, `POST empresas/{empresa}/reglas-tardanza`, `POST empresas/{empresa}/cuentas-bancarias`, `PUT empresas/{empresa}/cuentas-bancarias/{cuenta}`, `PATCH empresas/{empresa}/cuentas-bancarias/{cuenta}/estado`, `PUT usuarios/{usuario}`, `PATCH usuarios/{usuario}/rol`, `PATCH usuarios/{usuario}/estado`, `PUT horarios/{horario}`. (Lista completa, incluidas las de Nóminas, en `AislamientoRutasTest::VALIDAN_ANTES_DE_AUTORIZAR`.)

**Criterios de aceptación.**
- [ ] En cada una, la comprobación de pertenencia/permiso ocurre antes de la validación del cuerpo (en `authorize()` del `FormRequest` o antes de `validate()`), de modo que un recurso ajeno responde 403/404 también con cuerpo inválido.
- [ ] `AislamientoRutasTest` envía un **cuerpo válido** para cada una y confirma 403/404 sin modificar nada del recurso ajeno.
- [ ] Esas 14 salen de `VALIDAN_ANTES_DE_AUTORIZAR`; las de Nóminas permanecen en la lista como excepción documentada.
- [ ] No cambian rutas, campos ni respuestas exitosas; **no se modifica nada de Nóminas**.

---

## 4. [P1] Cabeceras de seguridad en Apache para el frontend estático

**Contexto.** Laravel solo pone cabeceras en las respuestas de `/api`. El HTML y los JS del panel y del portal de empleos los sirve Apache, que hoy no envía HSTS, CSP, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` ni `Permissions-Policy`. El fragmento está en `docs/seguridad/cabeceras-y-cors.md` §3 y **no se aplicó** (es configuración del servidor).

**Criterios de aceptación.**
- [ ] `agento-frontend/apache.conf` (y la plantilla de nginx si se usa) incluyen las cabeceras del documento, en el VirtualHost HTTPS.
- [ ] `curl -sI https://<dominio>/` muestra las seis cabeceras; HSTS solo en HTTPS.
- [ ] `Permissions-Policy` permite la cámara solo para el propio origen (control de acceso por carnet) y el resto bloqueado.
- [ ] La CSP se publica en `Content-Security-Policy-Report-Only` con `report-uri /api/seguridad/csp-report`; el paso a bloqueo sigue `docs/runbooks/despliegue.md` §5.
- [ ] Verificado en staging antes de producción, sin romper el panel, el portal de empleos ni las descargas.

---

## 5. [P2] Pantalla ARCO del candidato (en el frontend de Agento)

**Contexto.** El backend ya expone los derechos ARCO y la revocación del consentimiento al candidato autenticado (`/api/publico/candidatos/arco/*` y `/consentimiento/revocar`) y existen los métodos en `portalEmpleosApi.arco`, pero **ningún frontend los usa**. Se hará en el frontend nuevo de Agento (portal de empleo), no en el panel interno.

**Criterios de aceptación.**
- [ ] El candidato verificado puede: descargar sus datos (JSON) y sus archivos, pedir rectificación (campos permitidos), pedir cancelación y revocar el consentimiento, con confirmación previa y explicación de los efectos (`docs/legal/pendientes.md` §3).
- [ ] Ve el listado de sus solicitudes con estado y fecha límite.
- [ ] Muestra el texto legal vigente servido por `GET /api/publico/textos-legales/{tipo}`.
- [ ] Errores 401/422/423 con mensajes claros; accesible y responsive según el Design System.
- [ ] Probado contra el contrato del backend (sin cambiar sus rutas).

---

## 6. [P1] Revisión legal de la política de privacidad y de los plazos ARCO

**Bloquea el lanzamiento público.**

**Contexto.** El texto de consentimiento sembrado es provisional (copiado del frontend), la política de privacidad **no existe** y los plazos ARCO (acceso 20, rectificación 10, cancelación 10 días hábiles) son valores iniciales. `docs/legal/pendientes.md` lista todo lo que debe validar un abogado (Ley 29733).

**Criterios de aceptación.**
- [ ] Un abogado redacta y aprueba el texto de consentimiento y la política de privacidad (finalidades, responsable del banco de datos, encargados y transferencias incluida la futura IA, plazos de conservación, derechos y canal).
- [ ] Se publican como nuevas versiones en `seleccion_textos_legales` y el portal enlaza la política desde el formulario.
- [ ] Se confirman o corrigen los plazos de `config/reclutamiento.php` (`arco.plazo_dias_habiles`) y el cómputo de días hábiles (feriados).
- [ ] Se decide el tratamiento de contratados en cancelación, el derecho de oposición (hoy no implementado) y la información sobre decisiones automatizadas (puntaje ATS).
- [ ] `docs/legal/pendientes.md` se actualiza con lo resuelto.

---

## 7. [P2] Tests del frontend interno (al menos la renovación de sesión)

**Contexto.** El frontend no tiene framework de pruebas. La renovación preventiva del token (`agento-frontend/src/services/sesion.js`) solo se verificó con un script de Node puntual (9 casos) que no está en el repositorio.

**Criterios de aceptación.**
- [ ] Se añade un runner de pruebas (por ejemplo Vitest, ya compatible con Vite) y `npm test` en `package.json`.
- [ ] Los 9 casos de `sesion.js` pasan como tests permanentes: no renueva con tiempo de sobra; renueva en el margen; una sola renovación en paralelo; otra pestaña que gana la renovación; 401 real; fallo de red; sesión previa sin vencimiento; limpieza; almacenamiento que lanza.
- [ ] Un test del interceptor de `api.js` comprueba que renueva antes de la petición y que un 401 cierra la sesión.
- [ ] El job de CI ejecuta `npm ci`, `npm run lint` y `npm test` para el frontend.
- [ ] Se corrige o se documenta el error de lint preexistente en `src/hooks/useCurrentUser.js` (`set-state-in-effect`) para que el lint pueda ser obligatorio.

---

## Documentado, no se toca: riesgos en Nóminas

No es un issue de trabajo: son los riesgos N1 a N5 de `docs/seguridad/estado-hardening.md` (dos búsquedas por id sin filtro de empresa, 20 modelos sin scope, 32 rutas que validan antes de autorizar, 32 `withoutGlobalScope`), **pendientes de decisión del responsable de Nóminas**. Si ese responsable decide actuar, se abrirá un issue propio bajo su dirección.

---

## Publicarlos con `gh`

```bash
gh issue create --title "[P0] Sesión del panel: refresh token en cookie httpOnly (ADR-sesion-tokens, opción A)" --body-file <(sed -n '/^## 1\./,/^---/p' docs/seguridad/issues-pendientes.md)
# …repetir con las secciones 2 a 7 (cambia el número de "## N." en el sed)
```

Si prefieres etiquetas, crea antes `prioridad:P0`, `prioridad:P1` y `prioridad:P2` (`gh label create`).
