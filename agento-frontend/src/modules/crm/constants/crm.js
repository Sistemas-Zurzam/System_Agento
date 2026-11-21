/** Vistas del módulo CRM (/crm/<vista>). */
export const VISTAS_CRM = ['conversaciones', 'canales'];

/** Cada cuánto se refresca la bandeja y el chat abierto (solo con la pestaña visible). */
export const REFRESCO_BANDEJA_MS = 10000;
export const REFRESCO_CHAT_MS = 5000;

export const ESTADOS_MENSAJE = {
  enviado: { label: 'Enviado', icono: '✓' },
  entregado: { label: 'Entregado', icono: '✓✓' },
  leido: { label: 'Leído', icono: '✓✓' },
  fallido: { label: 'No entregado', icono: '!' },
};

export function tienePermiso(user, ...permisos) {
  return user?.role === 'administrador' || permisos.some((p) => user?.permisos?.includes(p));
}

/** Mensaje legible de un error de Axios: primero el error de validación, luego el mensaje general. */
export function mensajeDeError(error, porDefecto = 'No se pudo completar la acción.') {
  const datos = error?.response?.data;
  const primerError = datos?.errors ? Object.values(datos.errors).flat()[0] : null;
  return primerError ?? datos?.message ?? porDefecto;
}
