import { DeleteOutlined, EditOutlined, PlusOutlined, ReloadOutlined, WhatsAppOutlined } from '@ant-design/icons';
import { Alert, App, Button, Card, Empty, Space, Spin, Tag, Typography } from 'antd';
import { useCallback, useEffect, useState } from 'react';
import CanalWhatsappModal from '../components/CanalWhatsappModal';
import { mensajeDeError } from '../constants/crm';
import { crmApi } from '../services/crmApi';

const { Paragraph, Text, Title } = Typography;

function TarjetaCanal({ canal, onEditar, onRegenerar, onEliminar }) {
  return (
    <Card size="small">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <Space size={8} wrap>
            <WhatsAppOutlined className="text-lg" />
            <Text strong>{canal.nombre}</Text>
            <Tag color={canal.activo ? 'green' : 'default'}>{canal.activo ? 'Activo' : 'Inactivo'}</Tag>
          </Space>
          <div>
            <Text type="secondary" className="text-sm">
              {canal.numero_visible || 'Sin número visible'} · Phone number ID {canal.phone_number_id}
              {typeof canal.conversaciones_count === 'number' && ` · ${canal.conversaciones_count} conversaciones`}
            </Text>
          </div>
        </div>
        <Space wrap>
          <Button icon={<EditOutlined />} onClick={() => onEditar(canal)}>Editar</Button>
          <Button icon={<ReloadOutlined />} onClick={() => onRegenerar(canal)}>Nueva URL</Button>
          <Button danger icon={<DeleteOutlined />} aria-label={`Eliminar ${canal.nombre}`} onClick={() => onEliminar(canal)} />
        </Space>
      </div>

      <div className="mt-3 grid gap-3 md:grid-cols-[2fr_1fr]">
        <div>
          <Text type="secondary" className="text-xs">URL del webhook (pégala en Meta)</Text>
          <Paragraph copyable={{ text: canal.url_webhook }} className="mb-0! rounded bg-gray-50 px-2 py-1 font-mono text-xs break-all">
            {canal.url_webhook}
          </Paragraph>
        </div>
        <div>
          <Text type="secondary" className="text-xs">Token de verificación</Text>
          <Paragraph copyable className="mb-0! rounded bg-gray-50 px-2 py-1 font-mono text-xs break-all">{canal.verify_token}</Paragraph>
        </div>
      </div>
      <Text type="secondary" className="mt-2 block text-xs">
        Token de acceso {canal.access_token_final ?? '—'} · App secret {canal.app_secret_final ?? '—'}
      </Text>
    </Card>
  );
}

/** Configuración de los números de WhatsApp Cloud API (Meta) de la empresa activa. */
export default function CanalesWhatsappPage() {
  const { message, modal } = App.useApp();
  const [canales, setCanales] = useState(null);
  const [error, setError] = useState(null);
  const [editando, setEditando] = useState(undefined); // undefined: cerrado · null: nuevo · objeto: edición

  const cargar = useCallback(() => crmApi.canales.listar().then(
    (datos) => { setCanales(datos); setError(null); },
    (e) => setError(e),
  ), []);

  useEffect(() => {
    cargar();
  }, [cargar]);

  const regenerar = (canal) => modal.confirm({
    title: '¿Generar una nueva URL de webhook?',
    content: 'La URL actual dejará de funcionar: tendrás que pegar la nueva en Meta (WhatsApp → Configuración → Webhook).',
    okText: 'Generar',
    cancelText: 'Cancelar',
    onOk: () => crmApi.canales.regenerarWebhook(canal.id)
      .then(() => { message.success('Nueva URL generada. Actualízala en Meta.'); return cargar(); })
      .catch((e) => message.error(mensajeDeError(e))),
  });

  const eliminar = (canal) => modal.confirm({
    title: `¿Eliminar el canal ${canal.nombre}?`,
    content: 'Solo se puede eliminar un canal sin conversaciones. Si tiene historial, desactívalo.',
    okText: 'Eliminar',
    okButtonProps: { danger: true },
    cancelText: 'Cancelar',
    onOk: () => crmApi.canales.eliminar(canal.id)
      .then(() => { message.success('Canal eliminado.'); return cargar(); })
      .catch((e) => message.error(mensajeDeError(e))),
  });

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <Title level={4} className="mb-1!">Canales de WhatsApp</Title>
          <Text type="secondary">
            En Meta for Developers → WhatsApp → Configuración, usa la URL del webhook y el token de verificación de cada canal y suscribe el campo <Text code>messages</Text>.
          </Text>
        </div>
        <Button type="primary" icon={<PlusOutlined />} onClick={() => setEditando(null)}>Nuevo canal</Button>
      </div>

      {error && <Alert type="error" showIcon message={mensajeDeError(error, 'No se pudieron cargar los canales.')} />}
      {!canales && !error && <div className="flex justify-center p-8"><Spin /></div>}
      {canales?.length === 0 && (
        <Card><Empty description="Aún no hay canales. Agrega tu número de WhatsApp Cloud API para recibir conversaciones." /></Card>
      )}
      {canales?.map((canal) => (
        <TarjetaCanal key={canal.id} canal={canal} onEditar={setEditando} onRegenerar={regenerar} onEliminar={eliminar} />
      ))}

      <CanalWhatsappModal
        key={editando?.id ?? 'nuevo'}
        abierto={editando !== undefined}
        canal={editando ?? null}
        onCerrar={() => setEditando(undefined)}
        onGuardado={() => { setEditando(undefined); cargar(); }}
      />
    </div>
  );
}
