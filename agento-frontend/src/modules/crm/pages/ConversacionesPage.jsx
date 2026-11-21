import { Alert, Card } from 'antd';
import { useCallback, useEffect, useState } from 'react';
import ChatConversacion from '../components/ChatConversacion';
import ListaConversaciones from '../components/ListaConversaciones';
import { mensajeDeError, tienePermiso } from '../constants/crm';
import { useConversaciones } from '../hooks/useConversaciones';
import { crmApi } from '../services/crmApi';

/**
 * Bandeja de conversaciones de WhatsApp: lista a la izquierda y chat a la derecha. En móvil se ve
 * una de las dos columnas a la vez.
 */
export default function ConversacionesPage({ user }) {
  const [filtros, setFiltros] = useState({ buscar: '', canal_id: undefined, no_leidas: false });
  const [seleccionada, setSeleccionada] = useState(null);
  const [canales, setCanales] = useState(null);
  const {
    conversaciones, cargando, error, pagina, ultimaPagina, setPagina, actualizarLocal,
  } = useConversaciones(filtros);

  useEffect(() => {
    crmApi.canales.resumen().then(setCanales, () => {});
  }, []);

  const cambiarFiltros = (cambios) => {
    setFiltros((actuales) => ({ ...actuales, ...cambios }));
    setPagina(1);
  };

  const alLeer = useCallback((id) => actualizarLocal(id, { no_leidos: 0 }), [actualizarLocal]);
  const alEnviar = useCallback((id, texto) => actualizarLocal(id, {
    no_leidos: 0, ultimo_texto_mensaje: texto, ultimo_mensaje_at: new Date().toISOString(),
  }), [actualizarLocal]);

  return (
    <div className="flex flex-col gap-3">
      {canales?.length === 0 && (
        <Alert
          type="info"
          showIcon
          message="Aún no hay un canal de WhatsApp configurado"
          description={tienePermiso(user, 'crm.canales.gestionar')
            ? 'Ve a CRM → Canales de WhatsApp para conectar tu número de WhatsApp Cloud API (Meta).'
            : 'Pide a un administrador que conecte el número de WhatsApp en CRM → Canales de WhatsApp.'}
        />
      )}
      {error && <Alert type="error" showIcon message={mensajeDeError(error, 'No se pudieron cargar las conversaciones.')} />}

      <Card styles={{ body: { padding: 0 } }} className="overflow-hidden">
        <div className="grid h-[calc(100svh-210px)] min-h-[480px] lg:grid-cols-[minmax(280px,360px)_1fr]">
          <div className={`min-h-0 border-r border-gray-100 ${seleccionada ? 'hidden lg:block' : 'block'}`}>
            <ListaConversaciones
              conversaciones={conversaciones}
              cargando={cargando}
              canales={canales ?? []}
              filtros={filtros}
              onFiltros={cambiarFiltros}
              seleccionada={seleccionada}
              onSeleccionar={(c) => setSeleccionada(c.id)}
              pagina={pagina}
              ultimaPagina={ultimaPagina}
              onPagina={setPagina}
            />
          </div>
          <div className={`min-h-0 ${seleccionada ? 'block' : 'hidden lg:block'}`}>
            <ChatConversacion
              conversacionId={seleccionada}
              puedeResponder={tienePermiso(user, 'crm.conversaciones.responder')}
              onLeida={alLeer}
              onEnviado={alEnviar}
              onVolver={() => setSeleccionada(null)}
            />
          </div>
        </div>
      </Card>
    </div>
  );
}
