import { useCallback, useEffect, useState } from 'react';
import { REFRESCO_CHAT_MS } from '../constants/crm';
import { crmApi } from '../services/crmApi';
import { useRefresco } from './useRefresco';

const VACIO = { id: null, conversacion: null, mensajes: [], error: null };

const unir = (actuales, nuevos) => {
  const porId = new Map(actuales.map((m) => [m.id, m]));
  nuevos.forEach((m) => porId.set(m.id, m));
  return [...porId.values()].sort((a, b) => a.id - b.id);
};

/**
 * Mensajes de la conversación abierta: carga inicial, refresco incremental (los nuevos y el estado de
 * los salientes aún «enviados») y envío. Las respuestas de otra conversación se descartan.
 */
export function useChat(conversacionId, { onLeida } = {}) {
  const [estado, setEstado] = useState(VACIO);
  const [enviando, setEnviando] = useState(false);

  const marcarLeida = useCallback((id, conversacion) => {
    if (conversacion?.no_leidos > 0) {
      crmApi.conversaciones.marcarLeida(id).then(() => onLeida?.(id)).catch(() => {});
    }
  }, [onLeida]);

  useEffect(() => {
    if (!conversacionId) return undefined;
    let vigente = true;
    crmApi.conversaciones.mensajes(conversacionId).then(
      (respuesta) => {
        if (!vigente) return;
        setEstado({ id: conversacionId, conversacion: respuesta.conversacion, mensajes: respuesta.data, error: null });
        marcarLeida(conversacionId, respuesta.conversacion);
      },
      (error) => {
        if (vigente) setEstado({ ...VACIO, id: conversacionId, error });
      },
    );
    return () => {
      vigente = false;
    };
  }, [conversacionId, marcarLeida]);

  const actual = estado.id === conversacionId ? estado : VACIO;

  const refrescar = useCallback(() => {
    if (!conversacionId || estado.id !== conversacionId) return;
    const pendiente = estado.mensajes.find((m) => m.direccion === 'saliente' && m.estado === 'enviado');
    const ultimo = estado.mensajes[estado.mensajes.length - 1];
    const desde = pendiente ? pendiente.id - 1 : ultimo?.id;

    crmApi.conversaciones.mensajes(conversacionId, desde).then((respuesta) => {
      setEstado((previo) => (previo.id === conversacionId
        ? { ...previo, conversacion: respuesta.conversacion, mensajes: unir(previo.mensajes, respuesta.data) }
        : previo));
      marcarLeida(conversacionId, respuesta.conversacion);
    }).catch(() => {});
  }, [conversacionId, estado, marcarLeida]);

  useRefresco(refrescar, REFRESCO_CHAT_MS, Boolean(conversacionId));

  const enviar = useCallback(async (texto) => {
    setEnviando(true);
    try {
      const mensaje = await crmApi.conversaciones.enviar(conversacionId, texto);
      setEstado((previo) => (previo.id === conversacionId ? { ...previo, mensajes: unir(previo.mensajes, [mensaje]) } : previo));
      return mensaje;
    } finally {
      setEnviando(false);
    }
  }, [conversacionId]);

  return {
    conversacion: actual.conversacion,
    mensajes: actual.mensajes,
    error: actual.error,
    cargando: Boolean(conversacionId) && estado.id !== conversacionId,
    enviando,
    enviar,
  };
}
