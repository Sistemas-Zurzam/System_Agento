import api from '../../../services/api';

/**
 * Cliente del módulo CRM (/api/crm). Nunca envía empresa_id: la empresa la resuelve el backend
 * desde el JWT.
 */
const datos = (respuesta) => respuesta.data.data;

export const crmApi = {
  canales: {
    listar: () => api.get('/crm/canales').then(datos),
    resumen: () => api.get('/crm/canales/resumen').then(datos),
    crear: (valores) => api.post('/crm/canales', valores).then(datos),
    actualizar: (id, valores) => api.put(`/crm/canales/${id}`, valores).then(datos),
    eliminar: (id) => api.delete(`/crm/canales/${id}`),
    regenerarWebhook: (id) => api.post(`/crm/canales/${id}/regenerar-webhook`).then(datos),
  },
  conversaciones: {
    listar: (params) => api.get('/crm/conversaciones', { params }).then((r) => r.data),
    mensajes: (id, despuesDe) => api.get(`/crm/conversaciones/${id}/mensajes`, {
      params: despuesDe ? { despues_de: despuesDe } : undefined,
    }).then((r) => r.data),
    marcarLeida: (id) => api.post(`/crm/conversaciones/${id}/leida`),
    enviar: (id, texto) => api.post(`/crm/conversaciones/${id}/mensajes`, { texto_mensaje: texto }).then(datos),
  },
};
