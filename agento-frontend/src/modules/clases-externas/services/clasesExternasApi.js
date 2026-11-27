import api from '../../../services/api';

/**
 * Pago por clase dictada (integración con sistemas externos como Firme Studio).
 * El sistema externo informa las clases; aquí se fijan las tarifas, se revisa
 * el lote, se aprueba (congela cuentas), se genera el TXT BBVA y se marca el pago.
 */
const datos = (respuesta) => respuesta.data.data;

export const clasesExternasApi = {
  listarLotes: (params = {}) => api.get('/clases-externas/lotes', { params }).then((r) => r.data),
  obtenerLote: (id) => api.get(`/clases-externas/lotes/${id}`).then(datos),
  calcular: (id) => api.post(`/clases-externas/lotes/${id}/calcular`).then(datos),
  aprobar: (id) => api.post(`/clases-externas/lotes/${id}/aprobar`).then(datos),
  pagar: (id, referenciaPago) => api.post(`/clases-externas/lotes/${id}/pagar`, { referencia_pago: referenciaPago || null }).then(datos),
  anular: (id, motivo) => api.post(`/clases-externas/lotes/${id}/anular`, { motivo }).then(datos),
  descargarBbva: async (lote) => {
    const respuesta = await api.post(`/clases-externas/lotes/${lote.id}/bbva-netcash/exportar`, {}, { responseType: 'blob' });
    const disposicion = respuesta.headers['content-disposition'] || '';
    const nombre = /filename="([^"]+)"/.exec(disposicion)?.[1] || `BBVA_NETCASH_4TA_CLASES_${lote.referencia}.txt`;
    const enlace = document.createElement('a');
    const objeto = URL.createObjectURL(respuesta.data);
    enlace.href = objeto;
    enlace.download = nombre;
    document.body.appendChild(enlace);
    enlace.click();
    enlace.remove();
    URL.revokeObjectURL(objeto);
  },
  listarTarifas: (params = {}) => api.get('/clases-externas/tarifas', { params }).then((r) => r.data.data),
  crearTarifa: (cuerpo) => api.post('/clases-externas/tarifas', cuerpo).then((r) => r.data),
  buscarColaboradores: (busqueda) => api.get('/colaboradores', { params: { busqueda, per_page: 20 } }).then((r) => r.data.data ?? []),
};
