import { Alert, App, Button, Descriptions, Input, Modal, Radio, Select, Space, Table, Tag } from 'antd';
import dayjs from 'dayjs';
import { useCallback, useState } from 'react';
import { mensajeDeError } from '../constants/seleccion';
import { useConsulta } from '../hooks/useConsulta';
import { seleccionApi } from '../services/seleccionApi';

const TIPOS = { acceso: 'Acceso', rectificacion: 'Rectificación', cancelacion: 'Cancelación / eliminación' };
const ESTADOS = {
  pendiente: { label: 'Pendiente', color: 'gold' },
  en_proceso: { label: 'En proceso', color: 'processing' },
  atendida: { label: 'Atendida', color: 'green' },
  rechazada: { label: 'Rechazada', color: 'default' },
};
const ETIQUETA_CAMPO = {
  tipo_documento: 'Tipo de documento', numero_documento: 'Documento', nombres: 'Nombres', apellido_paterno: 'Apellido paterno',
  apellido_materno: 'Apellido materno', celular: 'Celular', ubicacion: 'Ubicación', resumen: 'Resumen',
};

const fecha = (valor) => (valor ? dayjs(valor).format('DD/MM/YYYY') : '—');

/**
 * Bandeja de solicitudes de derechos ARCO (Ley 29733) de los candidatos de la empresa activa.
 * Atender una rectificación aplica los cambios pedidos; atender una cancelación anonimiza al candidato.
 */
export default function ArcoPage() {
  const { message } = App.useApp();
  const [filtros, setFiltros] = useState({ estado: undefined, tipo: undefined, vencidas: false });
  const cargar = useCallback(() => seleccionApi.arco.listar({
    estado: filtros.estado, tipo: filtros.tipo, vencidas: filtros.vencidas ? 1 : undefined, per_page: 50,
  }), [filtros]);
  const { datos, cargando, error, recargar } = useConsulta(cargar);
  const [atendiendo, setAtendiendo] = useState(null);
  const [estado, setEstado] = useState('atendida');
  const [respuesta, setRespuesta] = useState('');
  const [guardando, setGuardando] = useState(false);

  const abrir = (solicitud) => {
    setAtendiendo(solicitud);
    setEstado('atendida');
    setRespuesta('');
  };

  const confirmar = async () => {
    setGuardando(true);
    try {
      await seleccionApi.arco.atender(atendiendo.id, { estado, respuesta: respuesta || null });
      message.success('Solicitud actualizada.');
      setAtendiendo(null);
      recargar();
    } catch (e) {
      message.error(mensajeDeError(e));
    } finally {
      setGuardando(false);
    }
  };

  const solicitudes = datos?.data ?? [];

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold">Derechos ARCO de candidatos</h1>
        <p className="text-sm text-gray-500">
          Solicitudes de acceso, rectificación y cancelación. Cada una tiene un plazo legal: atiéndelas antes de que venzan.
        </p>
      </div>

      <Space wrap>
        <Select allowClear placeholder="Estado" style={{ width: 160 }} value={filtros.estado} onChange={(estadoNuevo) => setFiltros({ ...filtros, estado: estadoNuevo })}
          options={Object.entries(ESTADOS).map(([value, { label }]) => ({ value, label }))} />
        <Select allowClear placeholder="Tipo" style={{ width: 220 }} value={filtros.tipo} onChange={(tipo) => setFiltros({ ...filtros, tipo })}
          options={Object.entries(TIPOS).map(([value, label]) => ({ value, label }))} />
        <Button type={filtros.vencidas ? 'primary' : 'default'} danger={filtros.vencidas} onClick={() => setFiltros({ ...filtros, vencidas: !filtros.vencidas })}>
          Solo vencidas
        </Button>
      </Space>

      {error && <Alert type="error" showIcon message={mensajeDeError(error, 'No se pudo cargar la bandeja.')} action={<Button size="small" onClick={recargar}>Reintentar</Button>} />}

      <Table
        size="small"
        rowKey="id"
        loading={cargando}
        dataSource={solicitudes}
        locale={{ emptyText: 'No hay solicitudes.' }}
        scroll={{ x: 760 }}
        pagination={false}
        columns={[
          { title: 'Candidato', render: (_, s) => (s.candidato ? <div><div>{s.candidato.nombre}</div><div className="text-xs text-gray-500">{s.candidato.email}</div></div> : '—') },
          { title: 'Tipo', dataIndex: 'tipo', render: (t) => TIPOS[t] ?? t },
          { title: 'Estado', dataIndex: 'estado', render: (e, s) => <Space><Tag color={ESTADOS[e]?.color}>{ESTADOS[e]?.label ?? e}</Tag>{s.vencida && <Tag color="red">Vencida</Tag>}</Space> },
          { title: 'Solicitada', dataIndex: 'solicitada_at', render: fecha },
          { title: 'Vence', dataIndex: 'vence_at', render: fecha },
          { title: '', align: 'right', render: (_, s) => ((s.estado === 'pendiente' || s.estado === 'en_proceso') ? <Button size="small" type="primary" onClick={() => abrir(s)}>Atender</Button> : null) },
        ]}
      />

      <Modal
        open={Boolean(atendiendo)}
        title={atendiendo ? `${TIPOS[atendiendo.tipo]}: ${atendiendo.candidato?.nombre ?? ''}` : ''}
        onCancel={() => setAtendiendo(null)}
        onOk={confirmar}
        okText="Guardar"
        cancelText="Cancelar"
        confirmLoading={guardando}
        destroyOnHidden
      >
        {atendiendo && (
          <div className="space-y-3">
            {atendiendo.tipo === 'rectificacion' && (
              <Descriptions size="small" column={1} bordered title="Cambios solicitados">
                {Object.entries(atendiendo.detalle?.cambios ?? {}).map(([campo, valor]) => (
                  <Descriptions.Item key={campo} label={ETIQUETA_CAMPO[campo] ?? campo}>{String(valor ?? '—')}</Descriptions.Item>
                ))}
              </Descriptions>
            )}
            {atendiendo.tipo === 'cancelacion' && (
              <Alert type="warning" showIcon message="Marcarla como atendida anonimiza los datos del candidato de forma irreversible (no procede si tiene una contratación)." />
            )}
            {atendiendo.detalle?.motivo && <p className="text-sm text-gray-600">Motivo del candidato: {atendiendo.detalle.motivo}</p>}
            <Radio.Group value={estado} onChange={(e) => setEstado(e.target.value)}>
              <Radio.Button value="en_proceso">En proceso</Radio.Button>
              <Radio.Button value="atendida">Atendida</Radio.Button>
              <Radio.Button value="rechazada">Rechazar</Radio.Button>
            </Radio.Group>
            <Input.TextArea rows={3} maxLength={2000} value={respuesta} onChange={(e) => setRespuesta(e.target.value)}
              placeholder={estado === 'rechazada' ? 'Motivo del rechazo (obligatorio)' : 'Respuesta al candidato (opcional)'} />
          </div>
        )}
      </Modal>
    </div>
  );
}
