import api from '../../../services/api';

/**
 * Cliente de la pestaña «Publicación en Agento» (/api/seleccion/.../agento). Nunca envía empresa_id:
 * la empresa la resuelve el backend desde el JWT.
 */
const datos = (respuesta) => respuesta.data.data;

export const agentoPublicacionApi = {
  catalogos: () => api.get('/seleccion/agento/catalogos').then(datos),
  ubigeo: (q) => api.get('/seleccion/agento/ubigeo', { params: { q } }).then(datos),
  estados: (ids) => api.get('/seleccion/agento/estados', { params: { ids: ids.join(',') } }).then(datos),

  // Pantalla «Publicar en Agento»: perfil de la empresa activa y alta directa de un puesto.
  empresa: () => api.get('/seleccion/agento/empresa').then(datos),
  guardarEmpresa: (valores) => api.put('/seleccion/agento/empresa', valores).then(datos),
  puestos: () => api.get('/seleccion/agento/puestos').then(datos),
  crearPuesto: (valores) => api.post('/seleccion/agento/puestos', valores).then(datos),

  obtener: (procesoId) => api.get(`/seleccion/procesos/${procesoId}/agento`).then(datos),
  guardar: (procesoId, valores) => api.put(`/seleccion/procesos/${procesoId}/agento`, valores).then(datos),
  accion: (procesoId, accion, valores = {}) => api.post(`/seleccion/procesos/${procesoId}/agento/acciones/${accion}`, valores).then(datos),
  destacar: (procesoId, valores) => api.post(`/seleccion/procesos/${procesoId}/agento/destacar`, valores).then(datos),
  historial: (procesoId) => api.get(`/seleccion/procesos/${procesoId}/agento/historial`).then(datos),
  vistaPrevia: (procesoId) => api.get(`/seleccion/procesos/${procesoId}/agento/vista-previa`).then(datos),
};
