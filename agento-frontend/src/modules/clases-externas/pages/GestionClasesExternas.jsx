import { CheckOutlined, DollarOutlined, DownloadOutlined, ReloadOutlined, StopOutlined } from '@ant-design/icons';
import { Alert, App, Button, DatePicker, Descriptions, Drawer, Empty, Form, Input, InputNumber, Popconfirm, Select, Skeleton, Space, Table, Tabs, Tag } from 'antd';
import dayjs from 'dayjs';
import { useCallback, useEffect, useState } from 'react';
import { clasesExternasApi } from '../services/clasesExternasApi';

const ESTADOS = {
  recibido: { color: 'blue', label: 'Por revisar' },
  observado: { color: 'orange', label: 'Observado' },
  aprobado: { color: 'cyan', label: 'Aprobado' },
  pagado: { color: 'green', label: 'Pagado' },
  anulado: { color: 'default', label: 'Anulado' },
};

const soles = (monto) => `S/ ${Number(monto || 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const periodo = (l) => `${dayjs(l.periodo_inicio).format('DD/MM')} – ${dayjs(l.periodo_fin).format('DD/MM/YYYY')}`;
const mensajeDe = (error, porDefecto) => error?.response?.data?.message || porDefecto;

const TagEstado = ({ estado }) => {
  const e = ESTADOS[estado] ?? { color: 'default', label: estado };
  return <Tag color={e.color} bordered={false}>{e.label}</Tag>;
};

function DetalleLote({ loteId, abierto, onCerrar, onCambio, permisos }) {
  const { message, modal } = App.useApp();
  const [lote, setLote] = useState(null);
  const [cargando, setCargando] = useState(false);
  const [trabajando, setTrabajando] = useState(false);

  const cargar = useCallback(async () => {
    if (!loteId) return;
    setCargando(true);
    try {
      setLote(await clasesExternasApi.obtenerLote(loteId));
    } catch (error) {
      message.error(mensajeDe(error, 'No se pudo cargar el lote.'));
    } finally {
      setCargando(false);
    }
  }, [loteId, message]);

  useEffect(() => {
    if (!abierto) return undefined;
    const timer = window.setTimeout(cargar, 0);
    return () => window.clearTimeout(timer);
  }, [abierto, cargar]);

  const ejecutar = async (accion, exito) => {
    setTrabajando(true);
    try {
      setLote({ ...lote, ...(await accion()) });
      message.success(exito);
      onCambio();
      await cargar();
    } catch (error) {
      message.error(mensajeDe(error, 'No se pudo completar la acción.'));
    } finally {
      setTrabajando(false);
    }
  };

  const anular = () => {
    let motivo = '';
    modal.confirm({
      title: 'Anular lote',
      content: <Input.TextArea rows={3} placeholder="Motivo de la anulación" onChange={(e) => { motivo = e.target.value; }} />,
      okText: 'Anular',
      okButtonProps: { danger: true },
      cancelText: 'Cancelar',
      onOk: () => {
        if (!motivo.trim()) {
          message.warning('Indica el motivo.');
          return Promise.reject(new Error('motivo'));
        }
        return ejecutar(() => clasesExternasApi.anular(lote.id, motivo.trim()), 'Lote anulado.');
      },
    });
  };

  const editable = lote && ['recibido', 'observado'].includes(lote.estado);
  const descargable = lote && ['aprobado', 'pagado'].includes(lote.estado);

  return (
    <Drawer title={lote ? `Lote ${lote.referencia}` : 'Lote'} open={abierto} onClose={onCerrar} width={920} destroyOnHidden>
      {cargando && !lote ? <Skeleton active /> : !lote ? <Empty /> : (
        <Space direction="vertical" size="middle" className="w-full">
          <Space wrap>
            {editable && permisos.calcular && (
              <Button icon={<ReloadOutlined />} loading={trabajando} onClick={() => ejecutar(() => clasesExternasApi.calcular(lote.id), 'Lote recalculado.')}>Recalcular</Button>
            )}
            {editable && permisos.aprobar && (
              <Popconfirm title="¿Aprobar el lote?" description="Se recalcula y se congelan las cuentas. Lo observado queda fuera del pago." okText="Aprobar" cancelText="Cancelar" onConfirm={() => ejecutar(() => clasesExternasApi.aprobar(lote.id), 'Lote aprobado.')}>
                <Button type="primary" icon={<CheckOutlined />} loading={trabajando}>Aprobar</Button>
              </Popconfirm>
            )}
            {descargable && permisos.exportar && (
              <Button icon={<DownloadOutlined />} onClick={() => clasesExternasApi.descargarBbva(lote).catch((e) => message.error(mensajeDe(e, 'No se pudo generar el TXT.')))}>TXT BBVA</Button>
            )}
            {lote.estado === 'aprobado' && permisos.pagar && (
              <Popconfirm title="¿Marcar el lote como pagado?" okText="Marcar pagado" cancelText="Cancelar" onConfirm={() => ejecutar(() => clasesExternasApi.pagar(lote.id), 'Lote marcado como pagado.')}>
                <Button icon={<DollarOutlined />} loading={trabajando}>Marcar pagado</Button>
              </Popconfirm>
            )}
            {['recibido', 'observado', 'aprobado'].includes(lote.estado) && permisos.aprobar && (
              <Button danger icon={<StopOutlined />} onClick={anular}>Anular</Button>
            )}
          </Space>

          {lote.total_observadas > 0 && <Alert type="warning" showIcon message={`${lote.total_observadas} clase(s) observada(s): no se pagan hasta corregirlas.`} />}

          <Descriptions size="small" column={3} bordered>
            <Descriptions.Item label="Periodo">{periodo(lote)}</Descriptions.Item>
            <Descriptions.Item label="Estado"><TagEstado estado={lote.estado} /></Descriptions.Item>
            <Descriptions.Item label="Envíos">{lote.envios}</Descriptions.Item>
            <Descriptions.Item label="Bruto">{soles(lote.total_bruto)}</Descriptions.Item>
            <Descriptions.Item label="Retención 4ta">{soles(lote.total_retencion)}</Descriptions.Item>
            <Descriptions.Item label="Neto a pagar">{soles(lote.neto_a_pagar)}</Descriptions.Item>
          </Descriptions>

          <Tabs items={[
            {
              key: 'pagos',
              label: `Instructoras (${lote.pagos.length})`,
              children: (
                <Table
                  size="small" rowKey="id" pagination={false} dataSource={lote.pagos}
                  columns={[
                    { title: 'Instructora', dataIndex: 'instructora', render: (v, p) => <div>{v}<div className="text-xs text-gray-500">{p.dni}</div></div> },
                    { title: 'Clases', dataIndex: 'clases_pagadas', align: 'right' },
                    { title: 'Bruto', dataIndex: 'monto_bruto', align: 'right', render: soles },
                    { title: 'Ret. 4ta', dataIndex: 'retencion_4ta', align: 'right', render: soles },
                    { title: 'Neto', dataIndex: 'monto_neto', align: 'right', render: soles },
                    { title: 'Estado', dataIndex: 'estado', render: (e) => <TagEstado estado={e} /> },
                    { title: 'Observación', dataIndex: 'observacion', render: (v) => v ?? '—' },
                  ]}
                />
              ),
            },
            {
              key: 'clases',
              label: `Clases (${lote.clases.length})`,
              children: (
                <Table
                  size="small" rowKey="id" pagination={{ pageSize: 15 }} dataSource={lote.clases} scroll={{ x: 'max-content' }}
                  columns={[
                    { title: 'Fecha', dataIndex: 'fecha', render: (f, c) => `${dayjs(f).format('DD/MM')} ${c.hora_inicio}` },
                    { title: 'Clase', dataIndex: 'tipo_clase', render: (v, c) => `${v ?? '—'}${c.sede ? ` · ${c.sede}` : ''}` },
                    { title: 'Titular', dataIndex: 'titular' },
                    { title: 'Dictó', dataIndex: 'dicto', render: (v, c) => (v ? <>{v}{c.suplencia && <Tag color="purple" className="ml-1">Suplencia</Tag>}</> : '—') },
                    { title: 'Estado', dataIndex: 'estado' },
                    { title: 'Tarifa', dataIndex: 'tarifa_aplicada', align: 'right', render: (v) => (v === null ? '—' : soles(v)) },
                    { title: 'Monto', dataIndex: 'monto', align: 'right', render: soles },
                    { title: 'Observación', dataIndex: 'observacion', render: (v) => (v ? <Tag color="orange" bordered={false}>{v}</Tag> : '—') },
                  ]}
                />
              ),
            },
          ]}
          />
        </Space>
      )}
    </Drawer>
  );
}

function LotesTab({ permisos }) {
  const { message } = App.useApp();
  const [lotes, setLotes] = useState([]);
  const [cargando, setCargando] = useState(true);
  const [estado, setEstado] = useState(null);
  const [abierto, setAbierto] = useState(null);

  const cargar = useCallback(async () => {
    setCargando(true);
    try {
      setLotes((await clasesExternasApi.listarLotes({ per_page: 50, ...(estado ? { estado } : {}) })).data ?? []);
    } catch (error) {
      message.error(mensajeDe(error, 'No se pudieron cargar los lotes.'));
    } finally {
      setCargando(false);
    }
  }, [estado, message]);

  useEffect(() => {
    const timer = window.setTimeout(cargar, 0);
    return () => window.clearTimeout(timer);
  }, [cargar]);

  return (
    <Space direction="vertical" className="w-full" size="middle">
      <Space>
        <Select
          allowClear placeholder="Estado" className="w-44" value={estado} onChange={setEstado}
          options={Object.entries(ESTADOS).map(([value, e]) => ({ value, label: e.label }))}
        />
        <Button icon={<ReloadOutlined />} onClick={cargar}>Actualizar</Button>
      </Space>
      <Table
        size="middle" rowKey="id" loading={cargando} dataSource={lotes} pagination={false}
        locale={{ emptyText: <Empty description="Aún no se recibió ningún lote" /> }}
        columns={[
          { title: 'Periodo', render: (_, l) => periodo(l) },
          { title: 'Referencia', dataIndex: 'referencia' },
          { title: 'Estado', dataIndex: 'estado', render: (e) => <TagEstado estado={e} /> },
          { title: 'Clases', dataIndex: 'total_clases', align: 'right' },
          { title: 'Observadas', dataIndex: 'total_observadas', align: 'right', render: (v) => (v > 0 ? <Tag color="orange">{v}</Tag> : 0) },
          { title: 'Bruto', dataIndex: 'total_bruto', align: 'right', render: soles },
          { title: 'Neto', dataIndex: 'total_neto', align: 'right', render: soles },
          { title: '', render: (_, l) => <Button size="small" onClick={() => setAbierto(l.id)}>Revisar</Button> },
        ]}
      />
      <DetalleLote loteId={abierto} abierto={abierto !== null} onCerrar={() => setAbierto(null)} onCambio={cargar} permisos={permisos} />
    </Space>
  );
}

function TarifasTab({ puedeGestionar }) {
  const { message } = App.useApp();
  const [form] = Form.useForm();
  const [tarifas, setTarifas] = useState([]);
  const [cargando, setCargando] = useState(true);
  const [opciones, setOpciones] = useState([]);
  const [guardando, setGuardando] = useState(false);

  const cargar = useCallback(async () => {
    setCargando(true);
    try {
      setTarifas(await clasesExternasApi.listarTarifas());
    } catch (error) {
      message.error(mensajeDe(error, 'No se pudieron cargar las tarifas.'));
    } finally {
      setCargando(false);
    }
  }, [message]);

  useEffect(() => {
    const timer = window.setTimeout(cargar, 0);
    return () => window.clearTimeout(timer);
  }, [cargar]);

  const buscar = async (texto) => {
    if (texto.trim().length < 2) return;
    try {
      const lista = await clasesExternasApi.buscarColaboradores(texto.trim());
      setOpciones(lista.map((c) => ({
        value: c.id,
        label: `${c.nombre_completo ?? [c.apellido_paterno, c.apellido_materno, c.nombres].filter(Boolean).join(' ')} · ${c.numero_documento ?? ''}`,
      })));
    } catch {
      setOpciones([]);
    }
  };

  const guardar = async (valores) => {
    setGuardando(true);
    try {
      await clasesExternasApi.crearTarifa({
        colaborador_id: valores.colaborador_id,
        monto: valores.monto,
        vigencia_desde: valores.vigencia_desde.format('YYYY-MM-DD'),
      });
      message.success('Tarifa registrada.');
      form.resetFields();
      await cargar();
    } catch (error) {
      message.error(error?.response?.data?.errors?.vigencia_desde?.[0] || mensajeDe(error, 'No se pudo registrar la tarifa.'));
    } finally {
      setGuardando(false);
    }
  };

  return (
    <Space direction="vertical" className="w-full" size="middle">
      <Alert
        type="info" showIcon
        message="La tarifa es por clase y se paga a quien la dicta según la tarifa de la titular. Una tarifa nueva no pisa la anterior: queda con su vigencia."
      />
      {puedeGestionar && (
        <Form form={form} layout="inline" onFinish={guardar}>
          <Form.Item name="colaborador_id" rules={[{ required: true, message: 'Elige la instructora' }]}>
            <Select showSearch filterOption={false} onSearch={buscar} options={opciones} placeholder="Buscar instructora (nombre o DNI)" className="w-80" />
          </Form.Item>
          <Form.Item name="monto" rules={[{ required: true, message: 'Indica el monto' }]}>
            <InputNumber min={0.01} step={1} precision={2} prefix="S/" placeholder="Tarifa por clase" className="w-44" />
          </Form.Item>
          <Form.Item name="vigencia_desde" rules={[{ required: true, message: 'Indica la vigencia' }]}>
            <DatePicker format="DD/MM/YYYY" placeholder="Vigente desde" />
          </Form.Item>
          <Button type="primary" htmlType="submit" loading={guardando}>Registrar tarifa</Button>
        </Form>
      )}
      <Table
        size="middle" rowKey="id" loading={cargando} dataSource={tarifas} pagination={{ pageSize: 15 }}
        locale={{ emptyText: <Empty description="Aún no hay tarifas registradas" /> }}
        columns={[
          { title: 'Instructora', dataIndex: 'instructora', render: (v, t) => <div>{v}<div className="text-xs text-gray-500">{t.dni}</div></div> },
          { title: 'Tarifa por clase', dataIndex: 'monto', align: 'right', render: soles },
          { title: 'Vigente desde', dataIndex: 'vigencia_desde', render: (f) => dayjs(f).format('DD/MM/YYYY') },
        ]}
      />
    </Space>
  );
}

/**
 * Pago por clases (Firme Studio y otros sistemas externos): lotes recibidos por
 * API y tarifas por instructora. Los permisos son los de Nóminas; el backend
 * vuelve a validarlos en cada acción.
 */
export default function GestionClasesExternas({ user }) {
  const tiene = (permiso) => user?.role === 'administrador' || user?.permisos?.includes(permiso);
  const permisos = {
    calcular: tiene('nominas.calcular'),
    aprobar: tiene('nominas.aprobar'),
    pagar: tiene('nominas.pagar'),
    exportar: tiene('nominas.bbva_netcash_exportar'),
  };

  return (
    <Tabs
      items={[
        { key: 'lotes', label: 'Lotes recibidos', children: <LotesTab permisos={permisos} /> },
        { key: 'tarifas', label: 'Tarifas', children: <TarifasTab puedeGestionar={tiene('nominas.gestionar_ciclos')} /> },
      ]}
    />
  );
}
