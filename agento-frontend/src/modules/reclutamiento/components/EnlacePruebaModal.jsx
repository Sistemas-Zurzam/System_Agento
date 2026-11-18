import { CopyOutlined } from '@ant-design/icons';
import { Alert, App, Button, Input, Modal, Popconfirm, Skeleton, Space, Table, Tag } from 'antd';
import dayjs from 'dayjs';
import { useCallback, useState } from 'react';
import { mensajeDeError } from '../constants/seleccion';
import { useConsulta } from '../hooks/useConsulta';
import HojaResultadosPrueba from './HojaResultadosPrueba';

const ESTADOS = {
  pendiente: { label: 'Ingresó, sin iniciar', color: 'default' },
  en_curso: { label: 'En curso', color: 'blue' },
  finalizada: { label: 'Finalizada', color: 'green' },
  vencida: { label: 'Venció', color: 'orange' },
  anulada: { label: 'Anulada', color: 'red' },
};

const fecha = (valor) => (valor ? dayjs(valor).format('DD/MM/YYYY HH:mm') : '—');

/**
 * Enlace compartible de una prueba: quien lo abre inicia sesión con Google.
 * Aquí RR. HH. lo copia y ve quién lo rindió (correo de Google).
 */
export default function EnlacePruebaModal({ prueba, acciones, onCerrar }) {
  const { message } = App.useApp();
  const [trabajando, setTrabajando] = useState(false);
  const id = prueba?.id;

  // El enlace es estable: si aún no existe se crea al abrir el modal.
  const cargarEnlace = useCallback(async () => {
    if (!id) return null;
    const actual = await acciones.enlace(id);
    return actual.url ? actual : acciones.crearEnlace(id);
  }, [id, acciones]);
  const { datos, setDatos, cargando, error, recargar } = useConsulta(cargarEnlace, { inmediata: Boolean(id) });

  const copiar = async () => {
    try {
      await navigator.clipboard.writeText(datos.url);
      message.success('Enlace copiado.');
    } catch {
      message.error('No se pudo copiar. Selecciona el enlace y cópialo manualmente.');
    }
  };

  const cambiar = async (accion, exito) => {
    setTrabajando(true);
    try {
      setDatos(await accion(id));
      message.success(exito);
    } catch (e) {
      message.error(mensajeDeError(e));
    } finally {
      setTrabajando(false);
    }
  };

  return (
    <Modal
      open={Boolean(prueba)}
      title={prueba ? `Enlace para rendir: ${prueba.nombre}` : 'Enlace'}
      onCancel={onCerrar}
      footer={null}
      width={900}
      centered
      destroyOnHidden
    >
      {cargando && <Skeleton active />}
      {error && <Alert type="error" showIcon message={mensajeDeError(error, 'No se pudo obtener el enlace.')} action={<Button size="small" onClick={recargar}>Reintentar</Button>} />}
      {datos && !cargando && (
        <div className="space-y-4">
          <p className="text-sm text-gray-600">
            Comparte este enlace. Quien lo abra inicia sesión con su cuenta de Google y rinde la prueba; su correo queda registrado
            y cada cuenta puede rendirla una sola vez.
          </p>
          <Space.Compact className="w-full">
            <Input readOnly value={datos.url} onFocus={(e) => e.target.select()} aria-label="Enlace de la prueba" />
            <Button type="primary" icon={<CopyOutlined />} onClick={copiar} disabled={!datos.activo}>Copiar</Button>
          </Space.Compact>
          {!datos.activo && <Alert type="warning" showIcon message="El enlace está desactivado: nadie puede entrar con él." />}
          <div className="flex justify-end">
            {datos.activo ? (
              <Popconfirm
                title="¿Desactivar el enlace?"
                description="Quien lo abra ya no podrá entrar. Los resultados registrados se conservan."
                okText="Desactivar"
                cancelText="Cancelar"
                onConfirm={() => cambiar(acciones.desactivarEnlace, 'Enlace desactivado.')}
              >
                <Button size="small" danger loading={trabajando}>Desactivar enlace</Button>
              </Popconfirm>
            ) : (
              <Button size="small" loading={trabajando} onClick={() => cambiar(acciones.crearEnlace, 'Enlace activado.')}>Activar enlace</Button>
            )}
          </div>
          <div>
            <p className="mb-2 text-sm font-semibold">Quiénes la rindieron ({datos.participantes.length})</p>
            <p className="mb-2 text-xs text-gray-500">Despliega una fila terminada (＋) para ver su resultado por dimensión.</p>
            <Table
              size="small"
              rowKey="id"
              dataSource={datos.participantes}
              pagination={datos.participantes.length > 8 ? { pageSize: 8, showSizeChanger: false } : false}
              locale={{ emptyText: 'Nadie ha entrado todavía.' }}
              scroll={{ x: 640 }}
              expandable={{
                rowExpandable: (r) => r.estado === 'finalizada',
                expandedRowRender: (r) => <HojaResultadosPrueba asignacion={r.asignacion} puedeConcluir={false} />,
              }}
              columns={[
                { title: 'Correo de Google', dataIndex: 'email' },
                { title: 'Nombre', dataIndex: 'nombre', render: (v) => v ?? '—' },
                { title: 'Estado', dataIndex: 'estado', render: (e) => <Tag color={ESTADOS[e]?.color}>{ESTADOS[e]?.label ?? e}</Tag> },
                { title: 'Ingresó', dataIndex: 'ingreso_at', render: fecha },
                { title: 'Terminó', dataIndex: 'finalizado_at', render: fecha },
                { title: 'Puntaje', dataIndex: 'puntaje', align: 'right', render: (v) => (v === null || v === undefined ? '—' : Number(v).toFixed(1)) },
              ]}
            />
          </div>
        </div>
      )}
    </Modal>
  );
}
