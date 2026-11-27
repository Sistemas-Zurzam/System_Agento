# Integración Firme Studio: envío de clases dictadas

Contrato para el equipo de Firme Studio y guía de operación para Agento. Estado: recepción, tarifas, cálculo, aprobación, pago y TXT BBVA implementados en el backend, con pestaña en el panel (Nóminas → Gestión de remuneraciones → Pago por clases).

**Es una característica por empresa.** Cada empresa define cómo paga: el pago por clases solo existe para las empresas que activan «Pago por clases habilitado» en Configuraciones → Empresas (columna `empresas.pago_por_clases_habilitado`, apagada por defecto, igual que el bono de asistencia). Sin ella, la pestaña no se muestra, la API rechaza el envío de clases (403) y no se pueden registrar tarifas (422). Las demás empresas no cambian en nada.

## Qué hace cada sistema

| Dato | Lo envía / lo conoce |
|---|---|
| Qué clases se dictaron, cuándo, quién era la titular y quién la dictó | **Firme Studio** (este envío) |
| Tarifa de cada instructora y monto a pagar | **Agento** (no se envían montos) |
| Periodicidad del pago | **Agento** (mensual) |

Suplencia: no hay un campo «es suplencia». Agento la deduce cuando la titular y quien dictó son personas distintas. La tarifa que se pagará (fase posterior) es la de la **titular**, acreditada a quien dictó.

## Petición

```
POST https://<dominio-agento>/api/integraciones/firme-studio/clases
Content-Type: application/json
Accept: application/json
X-Api-Key: <token entregado por Agento>
```

Un envío por periodo (mensual). Reenviar la misma `referencia` **reemplaza** el lote mientras Agento no lo haya aprobado.

```json
{
  "ruc": "20123456789",
  "referencia": "FIRME-2026-11",
  "periodo_inicio": "2026-11-01",
  "periodo_fin": "2026-11-30",
  "enviado_por": "sistema-firme-studio",
  "clases": [
    {
      "clase_id": "CL-100234",
      "fecha": "2026-11-24",
      "hora_inicio": "07:00",
      "hora_fin": "08:00",
      "tipo_clase": "reformer",
      "sede": "Miraflores",
      "estado": "dictada",
      "motivo_suplencia": "enfermedad",
      "instructora_titular": { "dni": "11111111", "nombre": "Luchita Gómez" },
      "instructora_que_dicto": { "dni": "22222222", "nombre": "Pepita Ríos" }
    }
  ]
}
```

| Campo | Obligatorio | Notas |
|---|---|---|
| `ruc` | No | Verificación cruzada con la empresa de la credencial. **No identifica** a la empresa. Si no coincide: 422 |
| `referencia` | Sí | Única por empresa; letras, números, `.`, `_`, `-` (máx. 60) |
| `periodo_inicio`, `periodo_fin` | Sí | `YYYY-MM-DD` |
| `clases` | Sí | De 1 a 1000 clases por envío |
| `clase_id` | Sí | Identificador único de la clase en Firme Studio (no repetido en el envío ni en otros lotes) |
| `fecha`, `hora_inicio`, `hora_fin` | Sí | `YYYY-MM-DD` y `HH:MM` |
| `tipo_clase`, `sede` | No | Informativos |
| `estado` | Sí | `dictada`, `cancelada_estudio` o `no_dictada` |
| `motivo_suplencia` | No | Informativo |
| `instructora_titular` | Sí | `dni` y `nombre`: quien estaba asignada a la clase |
| `instructora_que_dicto` | Obligatoria si `estado = dictada` | `dni` y `nombre`: quien realmente la dictó (igual a la titular si no hubo suplencia) |

## Respuestas

| Código | Significado |
|---|---|
| 201 | Lote nuevo recibido |
| 200 | Lote existente reemplazado |
| 401 | Credencial inválida, revocada o vencida |
| 409 | El lote ya fue aprobado en Agento; no se puede reemplazar |
| 422 | Formato inválido (`errors` indica el campo) o RUC que no corresponde |
| 429 | Demasiadas peticiones |

El cuerpo de 200/201 trae `lote_id`, `estado` (`recibido` u `observado`), `total_clases`, `total_observadas` y, por clase, `estado` (`ok` u `observada`), `suplencia` y `observacion`.

Una clase queda **observada** (sin bloquear al resto) si: el DNI de la titular o de quien dictó no corresponde a un colaborador de la empresa; una clase `dictada` no indica quién la dictó; la fecha cae fuera del periodo; o el `clase_id` ya llegó en otro lote. Se corrige en Agento o reenviando el lote.

## Credenciales (operación en Agento)

La empresa se determina **solo por el token**. Una credencial por empresa y proveedor.

```powershell
php artisan integraciones:crear-credencial <empresa_id> firme_studio --nombre="Firme Studio"
```

El token se muestra una única vez; en la base solo queda su hash SHA-256. Entregarlo por un canal seguro (nunca por correo en texto plano). Para revocar: `activo = false` en `integracion_credenciales`. Para rotar: crear otra credencial y revocar la anterior.

Límite: `INTEGRACIONES_POR_MINUTO` (60 por IP y por token). Tope de clases por envío: `INTEGRACIONES_MAX_CLASES` (1000).

## Seguridad y datos personales

- La empresa nunca se toma del cuerpo; un `empresa_id` enviado se ignora.
- Los logs registran solo identificadores y conteos, sin DNI ni nombres.
- El endpoint no devuelve información del contrato ni de otras empresas ante una credencial inválida.

## Cómo se calcula el pago (en Agento)

Firme Studio **no envía montos**. Agento calcula con sus tarifas:

- La tarifa es **por clase y por instructora** (tabla `tarifas_clase`, con vigencia; una tarifa nueva es una fila nueva y nunca pisa la anterior, para no alterar periodos ya pagados).
- Una clase `dictada` se paga a **quien la dictó** con la tarifa de la **titular** vigente a la **fecha de la clase**. Ejemplo: Luchita (S/ 80) está enferma y la suple Pepita (S/ 60): Pepita cobra S/ 80 por esa clase y S/ 60 por las suyas.
- `cancelada_estudio` y `no_dictada` no se pagan.
- Si la titular no tiene tarifa vigente, la clase queda **observada** y no se paga hasta cargar la tarifa y recalcular.
- Importes con `bcmath` (cadenas), nunca `float`.

**Retención de 4ta:** sobre el bruto de cada instructora en el lote, con la tasa y el umbral de los parámetros laborales de la empresa; sin retención si tiene suspensión. La regla definitiva la valida el contador (`docs/legal/pendientes.md` §3).

### Instructoras observadas (no entran al abono hasta corregirse y recalcular)
- No son locadoras (4ta).
- Su **periodicidad de pago en Agento no es «semanal»**. Es deliberado: la planilla mensual de Nóminas excluye únicamente a quienes están en «semanal»; con «mensual» la instructora cobraría **dos veces** (planilla mensual y pago por clase). Hay que configurarlo una vez por instructora.
- Sin cuenta bancaria completa (banco y cuenta o CCI).
- Faltan los parámetros de renta de 4ta de la empresa.

## Flujo en el panel (Gestión de remuneraciones → Pago por clases)

1. **Tarifas:** registrar la tarifa por clase de cada instructora con su vigencia.
2. **Lotes recibidos:** revisar el lote (instructoras y clases, con suplencias y observaciones) y **Recalcular** si se corrigió algo.
3. **Aprobar:** recalcula y **congela la cuenta bancaria** de cada instructora. Lo observado queda fuera.
4. **TXT BBVA:** abono con el exportador BBVA Net Cash de Nóminas (solo lectura, sin editarlo). Requiere una cuenta BBVA de haberes activa en la empresa.
5. **Marcar pagado**, o **Anular** con motivo.

Endpoints del panel (JWT): `GET/POST /api/clases-externas/tarifas`, `GET /api/clases-externas/lotes`, `GET /api/clases-externas/lotes/{id}`, y `POST` a `.../calcular`, `.../aprobar`, `.../pagar`, `.../anular`, `.../bbva-netcash/exportar`. Permisos: los de Nóminas (`nominas.ver`, `.gestionar_ciclos`, `.calcular`, `.aprobar`, `.pagar`, `.bbva_netcash_exportar`); no se crean permisos nuevos.

## Limitaciones conocidas
- El abono solo genera el TXT de **BBVA** (como los pagos semanales de Zazu); no hay TXT de BCP para este flujo.
- La retención se calcula por lote. Si una instructora tiene varios lotes en el mismo mes, no se acumula entre ellos (validar con el contador).
- Confirmar con Firme Studio que su sistema guarda, por clase, quién estaba asignada y quién la dictó: sin la titular no se puede aplicar la regla de suplencia.
