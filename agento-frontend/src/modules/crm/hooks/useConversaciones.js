import { useCallback, useEffect, useState } from 'react';
import { REFRESCO_BANDEJA_MS } from '../constants/crm';
import { crmApi } from '../services/crmApi';
import { useRefresco } from './useRefresco';

const sinVacios = (objeto) => Object.fromEntries(Object.entries(objeto)
  .filter(([, valor]) => valor !== undefined && valor !== null && valor !== '' && valor !== false));

/**
 * Bandeja de conversaciones. `filtros` debe ser estable (useState/useMemo). «cargando» se deriva de si
 * la última respuesta corresponde a la consulta vigente (mismo patrón que useConsulta); el refresco
 * periódico es silencioso y se descarta si mientras tanto cambiaron los filtros o la página.
 */
export function useConversaciones(filtros) {
  const [pagina, setPagina] = useState(1);
  const [version, setVersion] = useState(0);
  const [estado, setEstado] = useState({ clave: null, datos: [], meta: null, error: null });
  const clave = JSON.stringify([filtros, pagina, version]);

  const pedir = useCallback(
    () => crmApi.conversaciones.listar(sinVacios({ ...filtros, page: pagina })),
    [filtros, pagina],
  );

  useEffect(() => {
    let vigente = true;
    pedir().then(
      (respuesta) => {
        if (vigente) setEstado({ clave, datos: respuesta.data, meta: respuesta.meta, error: null });
      },
      (error) => {
        if (vigente) setEstado((previo) => ({ ...previo, clave, error }));
      },
    );
    return () => {
      vigente = false;
    };
  }, [pedir, clave]);

  useRefresco(() => {
    const claveDelPedido = clave;
    pedir().then((respuesta) => setEstado((previo) => (previo.clave === claveDelPedido
      ? { ...previo, datos: respuesta.data, meta: respuesta.meta }
      : previo))).catch(() => {});
  }, REFRESCO_BANDEJA_MS);

  const recargar = useCallback(() => setVersion((v) => v + 1), []);

  /** Actualiza una conversación de la lista sin recargar (al leerla o responderla). */
  const actualizarLocal = useCallback((id, cambios) => {
    setEstado((previo) => ({ ...previo, datos: previo.datos.map((c) => (c.id === id ? { ...c, ...cambios } : c)) }));
  }, []);

  const cargando = estado.clave !== clave;

  return {
    conversaciones: estado.datos,
    total: estado.meta?.total ?? 0,
    pagina,
    ultimaPagina: estado.meta?.last_page ?? 1,
    setPagina,
    cargando,
    error: cargando ? null : estado.error,
    recargar,
    actualizarLocal,
  };
}
