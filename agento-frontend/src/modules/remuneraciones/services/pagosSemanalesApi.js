import api from '../../../services/api';

/**
 * Pagos semanales de motorizados enviados por Zazu (lote aparte del ciclo
 * mensual). Zazu calcula los montos brutos; aquí se revisan por DNI, se
 * aprueban (congela cuentas), se genera el TXT BBVA de 4ta y se marca el pago.
 */
const datos = (respuesta) => respuesta.data.data;

export const pagosSemanalesApi = {
  listar: (params = {}) => api.get('/pagos-semanales', { params }).then((r) => r.data),
  obtener: (id) => api.get(`/pagos-semanales/${id}`).then(datos),
  revalidar: (id) => api.post(`/pagos-semanales/${id}/revalidar`).then(datos),
  aprobar: (id) => api.post(`/pagos-semanales/${id}/aprobar`).then(datos),
  observar: (id, observacion) => api.post(`/pagos-semanales/${id}/observar`, { observacion }).then(datos),
  anular: (id, motivo) => api.post(`/pagos-semanales/${id}/anular`, { motivo }).then(datos),
  pagar: (id, referenciaPago) => api.post(`/pagos-semanales/${id}/pagar`, { referencia_pago: referenciaPago || null }).then(datos),
  descargarBbva: async (lote) => {
    const respuesta = await api.post(`/pagos-semanales/${lote.id}/bbva-netcash/exportar`, {}, { responseType: 'blob' });
    const disposicion = respuesta.headers['content-disposition'] || '';
    const nombre = /filename="([^"]+)"/.exec(disposicion)?.[1] || `BBVA_NETCASH_4TA_${lote.semana_iso}.txt`;
    const enlace = document.createElement('a');
    const objeto = URL.createObjectURL(respuesta.data);
    enlace.href = objeto;
    enlace.download = nombre;
    document.body.appendChild(enlace);
    enlace.click();
    enlace.remove();
    URL.revokeObjectURL(objeto);
  },
};
