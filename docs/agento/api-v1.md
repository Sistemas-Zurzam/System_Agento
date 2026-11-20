# API pública de Agento: `/api/v1`

Módulo `agento-backend/app/Modules/PortalAgento`. Es la API de **solo lectura** que consume el portal Agento (React) para mostrar las ofertas que el equipo publica desde el panel de Reclutamiento. Cumple el contrato `openapi.yaml` del portal (OpenAPI 3.1); lo que el contrato pide y la agencia no puede dar está en [brechas-contrato.md](brechas-contrato.md).

## Reglas comunes

| Tema | Regla |
|---|---|
| Autenticación | Ninguna: son datos públicos. La empresa **nunca** viene del request: sale de cada publicación (`contexto.sistema`). |
| Visibilidad | Se decide en un único lugar: `Domain/VisibilidadPublica`. Visible = publicación `publicada` o `pausada`, dentro de su vigencia, con el proceso `abierto` o `en_pausa`, publicado hacia afuera (`publicacion_externa`), sin `retirada_at`, con fecha de cierre no vencida y de una empresa activa con el servicio de reclutamiento. |
| Empresa confidencial | La oferta se ve, **la empresa no**: `company` = «Empresa confidencial» (`id` y `slug` = `confidencial`), sin logo ni enlace. No aparece en `/companies`, ni en el sitemap de empresas, ni en el texto buscable, ni en el JSON-LD. |
| Errores | `{ "code", "message", "details" }` (`ApiError`). Códigos: `bad_request`, `unauthorized`, `forbidden`, `not_found`, `conflict`, `invalid_request` (422, con `details` por campo), `rate_limited` (429, con `Retry-After`), `internal_error`. Solo en `/api/v1`: el resto de la API conserva `{ message, errors }`. |
| Caché | `ETag` y `Cache-Control: public` en los GET 200. `If-None-Match` coincidente → `304`. Segundos configurables en `config/portal_agento.php` (`AGENTO_CACHE_LISTADO`, `_DETALLE`, `_CATALOGOS`). Las lecturas pesadas (áreas, empresas, sitemap, landings) se guardan en caché del servidor con invalidación por versión: cualquier cambio en una publicación la invalida. |
| Rate limiting | `agento-publico`: 120/min por IP en todo `/api/v1`. `agento-busqueda`: 60/min por IP en `GET /jobs`. Configurable (`AGENTO_LIMITE_POR_MINUTO`, `AGENTO_LIMITE_BUSQUEDA_POR_MINUTO`). |
| CORS | Solo los orígenes de `CORS_ALLOWED_ORIGINS` (local: `http://localhost:5173`). `ETag` se expone a JavaScript. Sin comodines ni credenciales. |
| Lista blanca | Los `Resource` devuelven solo campos explícitos. Un test falla si aparece cualquiera interno (umbral ATS, pesos, reclutador, ids de empresa o de área interna). |

## Endpoints

### `GET /api/v1/jobs`: listado

Filtros (mismos nombres que la URL del portal): `q`, `lugar`, `area` (repetible), `nivel` (repetible), `jornada` (repetible), `modalidad` (repetible), `min`, `max` (PEN mensual), `fecha` (`24h`, `7d`, `30d`), `sinexp`, `inclusiva`, `verificadas`, `consueldo`, `orden` (`relevance`, `recent`, `salary`), `pag`, `tam` (1 a 50, por defecto 10).

Respuesta `200`: `{ items: Job[], page, pageSize, total, correctedQuery }`.

- `q`: sin tildes ni mayúsculas, todas las palabras deben aparecer. En MySQL usa `FULLTEXT` (`MATCH … AGAINST … IN BOOLEAN MODE`) con las palabras de 3 letras o más; en SQLite (tests) y con palabras cortas usa `LIKE`. **No** hay sinónimos ni corrección ortográfica: `correctedQuery` es siempre `null`.
- `min`/`max`: excluyen las ofertas sin sueldo visible o en otra moneda. Si `min > max` se intercambian.
- `orden=relevance` (defecto): destacadas primero, luego relevancia de texto (MySQL) y más recientes. `recent`: solo cronológico. `salary`: mayor sueldo visible primero, las demás al final.
- Si `pag` supera la última página, devuelve la última y `page` lo indica.
- `verificadas=1` devuelve vacío: hoy ninguna empresa tiene estado «verificada».
- Valor inválido en un filtro → `422 invalid_request`.

### `GET /api/v1/jobs/{slug}`: detalle · `404 not_found` si no existe o dejó de ser visible

`Job` del contrato:

| Campo | Origen |
|---|---|
| `id`, `slug` | id y slug de la publicación |
| `title` | `titulo_publico` o el cargo del proceso |
| `company` | ver «Empresa confidencial» arriba |
| `location` | catálogo `agento_ubigeo` (departamento, provincia, distrito) |
| `area`, `level`, `schedule` | catálogos propios (valores fijos del contrato) |
| `mode` | modalidad del proceso (`presencial`, `remoto`, `hibrido`) |
| `salary` | `{min, max}` solo si `mostrar_salario`, en PEN y con monto; si no, `null` («Sueldo no informado»). Con un solo monto, mínimo y máximo coinciden |
| `postedAt`, `expiresAt` | `publicada_at`; el menor entre la fecha de cierre y `vigente_hasta` |
| `noExperience`, `inclusive`, `benefits` | datos de la publicación |
| `requirements` | requisitos del perfil: excluyentes → `mandatory`, deseables → `desirable` |
| `summary`, `description[]`, `responsibilities[]` | resumen; descripción del proceso por párrafos; actividades de la solicitud por líneas |
| `questions` | siempre `[]` |

Extensiones (no están en el contrato; el cliente zod las ignora): `status` (`activa` o `pausada`), `acceptsApplications`, `publicMessage` (mensaje del proceso cuando no admite postulaciones), `jobCode` (código del proceso, para postular en la etapa siguiente) y `confidential`.

### Otros

| Endpoint | Descripción |
|---|---|
| `GET /jobs/{slug}/similar` | Hasta 4 ofertas del mismo área o cargo; prioriza el mismo departamento. `404` si la base no es visible |
| `GET /jobs/areas` | `[{area, count}]` con las 11 áreas (también las de 0 ofertas) |
| `GET /job-landings/{slug}` | Página SEO «cargo en lugar» (`cajero-en-lima`). `404` si no hay ofertas visibles para esa combinación |
| `GET /companies` | `{items, sectors}`. Filtros `q`, `limit`, `sector`, `verified`. Solo empresas con una oferta visible **no confidencial** |
| `GET /companies/{slug}` | `CompanyProfile` |
| `GET /companies/{slug}/jobs` | Ofertas visibles de la empresa (nunca las confidenciales) |
| `GET /catalog/cargos?q=` | Autocompletado de cargos publicados (mínimo 2 letras, máximo 8) |
| `GET /catalog/places?q=` | Autocompletado de ubigeo (mínimo 2 letras, máximo 8) |
| `GET /catalog/{areas,niveles,jornadas,beneficios,ubigeo}` | **Extensión**: catálogos completos. `{ kind, items }` |
| `GET /seo/sitemap` | **Extensión**: `{ jobs, companies, landings }` con slugs y `lastmod`. Sin datos de empresas confidenciales |
| `GET /seo/jobs/{slug}` | **Extensión**: datos `schema.org/JobPosting`. Empresa confidencial: nombre genérico y sin logo |

## API interna del panel (JWT)

Prefijo `/api/seleccion`, servicio de reclutamiento y permisos `seleccion.*` existentes (lectura: seguimiento, vacantes, candidatos o evaluaciones; escritura: `seleccion.vacantes.gestionar`). El proceso se resuelve con `EmpresaScope`: uno de otra empresa responde `404`.

| Método y ruta | Acción |
|---|---|
| `GET /procesos/{p}/agento` | Publicación (o `null`), lista de verificación y acciones disponibles |
| `PUT /procesos/{p}/agento` | Guardar. Con `version` aplica bloqueo optimista (`409` si cambió) |
| `POST /procesos/{p}/agento/acciones/{accion}` | `publicar`, `programar`, `pausar`, `reanudar`, `retirar` (motivo obligatorio), `renovar` |
| `POST /procesos/{p}/agento/destacar` | `{destacar, hasta?}` |
| `GET /procesos/{p}/agento/historial` | Eventos de la publicación |
| `GET /procesos/{p}/agento/vista-previa` | La oferta tal como la vería el público (mismo `Resource`) |
| `GET /agento/catalogos`, `/agento/ubigeo?q=` | Catálogos con ids para el formulario |
| `GET /agento/estados?ids=1,2` | Estado en Agento de varios procesos (columna de la lista) |

## Configuración

`config/portal_agento.php` y variables `AGENTO_URL_PORTAL`, `AGENTO_CONTACTO_EMAIL`, `AGENTO_VIGENCIA_DIAS`, `AGENTO_CACHE_*`, `AGENTO_LIMITE_*`. Comando programado cada 5 minutos: `agento:sincronizar-publicaciones` (publica lo programado y vence lo cumplido, recorriendo cada empresa con su contexto).

## Pruebas

`tests/Feature/Modules/PortalAgento` (`PublicacionAgentoTest`, `ApiV1Test`, `ContratoOpenApiTest`) y `tests/Unit/PortalAgento`. El test de contrato valida las respuestas reales contra los esquemas de Agento con `opis/json-schema`: por defecto contra el extracto versionado `docs/agento/contrato/agento-openapi.extracto.yaml`; con `AGENTO_OPENAPI_PATH=<ruta al openapi.yaml completo>` contra el contrato completo del portal.
