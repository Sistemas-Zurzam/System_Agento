# Brechas entre el contrato de Agento y lo que el backend puede dar

Contrato: `openapi.yaml` del portal Agento (OpenAPI 3.1). **No se modificó.** Donde el contrato exige un dato que el modo agencia no tiene, el backend devuelve un valor neutro y documentado, y se propone un cambio de contrato (a decidir por el equipo del portal). El portal debe **ocultar** lo que no tenga dato real.

| # | Brecha | Qué devuelve `/api/v1` | Propuesta |
|---|---|---|---|
| 1 | `CompanySummary.rating`, `reviewsCount`, `respondsFast` son obligatorios. En modo agencia no hay reseñas ni medición de respuesta | `0`, `0`, `false` | Hacerlos opcionales; mientras tanto, el portal oculta estrellas, reseñas y «Responde rápido» cuando `reviewsCount = 0` |
| 2 | `CompanySummary.verified`: no existe el estado «empresa verificada» | `false` | Decisión de producto: ¿la agencia verifica? Hasta entonces el filtro `verificadas=1` devuelve vacío |
| 3 | `CompanySummary.sector` es obligatorio y no hay sector en la empresa | `""` (se completa en `agento_empresas_publicas.sector`, hoy sin pantalla en el panel) | Pantalla del panel para el perfil público de la empresa |
| 4 | `Job.company` es obligatorio, pero una empresa **confidencial** no puede identificarse | `{id:"confidencial", slug:"confidencial", name:"Empresa confidencial", …}` | El portal debe tratar `confidential = true` (extensión) o el slug `confidencial` como «sin perfil»: sin enlace ni logo |
| 5 | `CompanyProfile.size` (enum) y `email` (formato correo) son obligatorios | `size` = `agento_empresas_publicas.tamano` o `1-10` por defecto; `email` = correo de contacto del portal (`AGENTO_CONTACTO_EMAIL`), porque en modo agencia las postulaciones las gestiona Agento | Hacer `size` y `email` opcionales; el `1-10` por defecto es un valor de relleno, no un dato real |
| 6 | `CompanyProfile`: sedes, cultura, galería, portada, teléfono | `[]`, `""`, `[]`, `null`, `""` | Perfil público de empresa con esos campos (tabla ya creada: `agento_empresas_publicas`; faltan columnas y pantalla) |
| 7 | `CompanyProfile.ruc` | RUC de la empresa (dato registral público) | Confirmar con el responsable de privacidad si se publica |
| 8 | Sueldo: el contrato solo admite `{min, max}` en soles | `null` si el proceso no lo muestra o está en otra moneda; con un solo monto, `min = max` | Agregar `currency` y `period` al esquema `salary` |
| 9 | Corrección ortográfica y sinónimos en `q` (`correctedQuery`) | No implementado: `correctedQuery = null`; la búsqueda ignora tildes y mayúsculas | Servicio de sinónimos / búsqueda externa en una etapa posterior |
| 10 | `/catalog/cargos` espera «cargos y sinónimos» | Solo los títulos de las ofertas visibles que coinciden | Catálogo de cargos propio |
| 11 | `Job.applied` y `Job.saved` requieren sesión de candidata con Bearer. Hoy el candidato entra por correo + código (OTP) con `X-Candidato-Token` | Se omiten (el contrato les da `default: false`) | Decidir en la etapa de postulación si el OTP se adapta a Bearer o se extiende el contrato |
| 12 | `Job.questions` (preguntas de filtro) | `[]` | No hay equivalente en S&R |
| 13 | Formato de errores | `{code, message, details}` solo bajo `/api/v1` | Ninguna: es el contrato |
| 14 | `JobStatus` (`borrador, activa, en_revision, pausada, cerrada`) vs estados de la publicación (`borrador, programada, publicada, pausada, retirada, vencida`) | El contrato no expone `status` en `Job`; se agrega la extensión `status` (`activa` o `pausada`). Lo cerrado, retirado o vencido no se publica (`404`) | Si el portal necesita mostrar «oferta cerrada», se puede agregar un estado en una etapa posterior |
| 15 | Ubigeo: el contrato exige departamento, provincia y distrito | Catálogo **inicial** (capitales departamentales y los distritos principales de Lima y Callao); no es el catálogo completo del INEI | Sembrar el catálogo completo del INEI con un seeder de solo inserción (pendiente: confirmar la fuente) |
| 16 | `/jobs/{slug}/report`, `/alerts`, `/applications`, `/companies/{slug}/reviews`, administración de catálogos y el resto de las 123 operaciones | **No implementadas** en esta etapa | Etapas siguientes |

## Extensiones que agrega el backend (no están en el contrato)

- `Job`: `status`, `acceptsApplications`, `publicMessage`, `jobCode`, `confidential`.
- Endpoints: `/catalog/{areas,niveles,jornadas,beneficios,ubigeo}`, `/seo/sitemap`, `/seo/jobs/{slug}`.
- Los esquemas zod del portal ignoran los campos desconocidos, así que no rompen la validación. Si se quieren formalizar, hay que agregarlos al `openapi.yaml` (cambio que **no** se hizo aquí).
