import { ArrowLeftOutlined, SendOutlined, WhatsAppOutlined } from '@ant-design/icons';
import { Alert, App, Button, Empty, Input, Spin, Tag, Tooltip, Typography, theme } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useRef, useState } from 'react';
import { ESTADOS_MENSAJE, mensajeDeError } from '../constants/crm';
import { useChat } from '../hooks/useChat';

const { Text } = Typography;

function Burbuja({ mensaje }) {
  const { token } = theme.useToken();
  const saliente = mensaje.direccion === 'saliente';
  const estado = ESTADOS_MENSAJE[mensaje.estado];

  return (
    <div className={`flex ${saliente ? 'justify-end' : 'justify-start'}`}>
      <div
        className="max-w-[80%] rounded-lg px-3 py-2 shadow-sm"
        style={{ background: saliente ? token.colorPrimaryBg : token.colorBgContainer }}
      >
        <div className="whitespace-pre-wrap break-words text-sm">{mensaje.texto}</div>
        <div className="mt-1 flex items-center justify-end gap-1.5">
          {saliente && mensaje.autor && <Text type="secondary" className="text-xs">{mensaje.autor}</Text>}
          <Text type="secondary" className="text-xs">{dayjs(mensaje.created_at).format('DD/MM HH:mm')}</Text>
          {saliente && estado && (
            <Tooltip title={mensaje.error || estado.label}>
              <Text type={mensaje.estado === 'fallido' ? 'danger' : mensaje.estado === 'leido' ? undefined : 'secondary'}
                className="text-xs" style={mensaje.estado === 'leido' ? { color: token.colorPrimary } : undefined}
              >
                {estado.icono}
              </Text>
            </Tooltip>
          )}
        </div>
      </div>
    </div>
  );
}

/** Columna derecha de la bandeja: mensajes de la conversación abierta y caja de respuesta. */
export default function ChatConversacion({ conversacionId, puedeResponder, onLeida, onEnviado, onVolver }) {
  const { message } = App.useApp();
  const { conversacion, mensajes, cargando, error, enviando, enviar } = useChat(conversacionId, { onLeida });
  const [texto, setTexto] = useState('');
  const fin = useRef(null);

  useEffect(() => {
    fin.current?.scrollIntoView({ block: 'end' });
  }, [mensajes.length, conversacionId]);

  if (!conversacionId) {
    return (
      <div className="flex h-full items-center justify-center p-6">
        <Empty image={<WhatsAppOutlined className="text-5xl text-gray-300" />} description="Elige una conversación para ver los mensajes." />
      </div>
    );
  }

  const responder = async () => {
    const limpio = texto.trim();
    if (!limpio || enviando) return;
    try {
      await enviar(limpio);
      setTexto('');
      onEnviado?.(conversacionId, limpio);
    } catch (e) {
      message.error(mensajeDeError(e, 'No se pudo enviar el mensaje.'));
    }
  };

  const ventanaCerrada = conversacion && !conversacion.ventana_abierta;

  return (
    <div className="flex h-full min-h-0 flex-col">
      <div className="flex items-center gap-3 border-b border-gray-100 px-4 py-3">
        {onVolver && <Button type="text" icon={<ArrowLeftOutlined />} aria-label="Volver a la lista" onClick={onVolver} className="lg:hidden!" />}
        <div className="min-w-0 flex-1">
          <Text strong ellipsis className="block">{conversacion?.nombre_contacto || conversacion?.telefono || '…'}</Text>
          <Text type="secondary" className="text-xs">
            {conversacion?.telefono}{conversacion?.canal ? ` · ${conversacion.canal.nombre}` : ''}
          </Text>
        </div>
        {conversacion && (
          <Tooltip title={conversacion.ventana_abierta
            ? `Puedes responder hasta ${dayjs(conversacion.ventana_cierra_at).format('DD/MM HH:mm')}`
            : 'Pasaron más de 24 h desde el último mensaje del cliente'}
          >
            <Tag color={conversacion.ventana_abierta ? 'green' : 'default'}>
              {conversacion.ventana_abierta ? 'Ventana abierta' : 'Ventana cerrada'}
            </Tag>
          </Tooltip>
        )}
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto bg-gray-50 px-4 py-3">
        {cargando && <div className="flex justify-center p-8"><Spin /></div>}
        {error && <Alert type="error" showIcon message={mensajeDeError(error, 'No se pudo cargar la conversación.')} />}
        <div className="flex flex-col gap-2">
          {mensajes.map((m) => <Burbuja key={m.id} mensaje={m} />)}
        </div>
        <div ref={fin} />
      </div>

      <div className="border-t border-gray-100 p-3">
        {ventanaCerrada && (
          <Alert
            className="mb-2"
            type="info"
            showIcon
            message="WhatsApp solo permite responder durante las 24 h siguientes al último mensaje del cliente. Cuando vuelva a escribir, podrás contestarle."
          />
        )}
        {!puedeResponder && <Text type="secondary" className="text-sm">No tienes permiso para responder conversaciones.</Text>}
        {puedeResponder && (
          <div className="flex items-end gap-2">
            <Input.TextArea
              value={texto}
              onChange={(e) => setTexto(e.target.value)}
              onPressEnter={(e) => {
                if (!e.shiftKey) {
                  e.preventDefault();
                  responder();
                }
              }}
              autoSize={{ minRows: 1, maxRows: 5 }}
              maxLength={4096}
              placeholder={ventanaCerrada ? 'Ventana de 24 h cerrada' : 'Escribe un mensaje (Enter envía, Shift+Enter salta de línea)'}
              aria-label="Mensaje"
              disabled={ventanaCerrada || cargando}
            />
            <Button type="primary" icon={<SendOutlined />} loading={enviando} disabled={!texto.trim() || ventanaCerrada || cargando} onClick={responder}>
              Enviar
            </Button>
          </div>
        )}
      </div>
    </div>
  );
}
