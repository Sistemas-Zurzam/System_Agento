import { CheckOutlined, DownloadOutlined, DollarOutlined, ExclamationCircleOutlined, LeftOutlined, ReloadOutlined, RightOutlined, RollbackOutlined, StopOutlined } from '@ant-design/icons';
import { Alert, App, Button, DatePicker, Empty, Input, Select, Skeleton, Space, Table, Tag, Tooltip } from 'antd';
import dayjs from 'dayjs';
import { useCallback, useEffect, useState } from 'react';
import { pagosSemanalesApi } from '../services/pagosSemanalesApi';

const ESTADOS = {
  recibido: { color: 'blue', label: 'Por revisar' },
  observado: { color: 'orange', label: 'Observado' },
  aprobado: { color: 'cyan', label: 'Aprobado' },
  pagado: { color: 'green', label: 'Pagado' },
  anulado: { color: 'default', label: 'Anulado' },
};

const soles = (monto) => `S/ ${Number(monto || 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const semana = (l) => `${dayjs(l.semana_inicio).format('DD/MM')} – ${dayjs(l.semana_fin).format('DD/MM/YYYY')}`;

// Lunes de la semana (lunes a domingo, igual que en Zazu).
const lunesDe = (fecha) => {
  const d = dayjs(fecha);
  return d.subtract((d.day() + 6) % 7, 'day').startOf('day');
};

const TagEstado = ({ estado }) => {
  const e = ESTADOS[estado] ?? { color: 'default', label: estado };
  return <Tag color={e.color} bordered={false}>{e.label}</Tag>;
};

/**
 * Nóminas → Pagos semanales (Zazu). Zazu envía cada semana cerrada de sus
 * motorizados (locadores, 4ta). Sin semana elegida se listan los envíos; al
 * elegir una se ven sus motorizados, igual que en Zazu, y desde ahí se
 * aprueba, se genera el TXT BBVA y se marca el pago. Las líneas observadas
 * quedan fuera del TXT y del pago.
 */
export default function PagosSemanalesZazuTab({ puedeAprobar, puedePagar, puedeCalcular, puedeExportarBbva }) {
  const { message } = App.useApp();
  const [lotes, setLotes] = useState([]);
  const [cargando, setCargando] = useState(true);
  // null = todas las semanas (lista de envíos).
  const [lunes, setLunes] = useState(null);
  const [estado, setEstado] = useState(null);

  const cargar = useCallback(async () => {
    setCargando(true);
    try {
      const params = { per_page: 50 };
      if (lunes) params.semana = lunes.format('YYYY-MM-DD');
      if (estado) params.estado = estado;
      setLotes((await pagosSemanalesApi.listar(params)).data ?? []);
    } catch {
      message.error('No se pudieron cargar los pagos semanales.');
    } finally {
      setCargando(false);
    }
  }, [message, lunes, estado]);

  useEffect(() => {
    const timer = window.setTimeout(cargar, 0);
    return () => window.clearTimeout(timer);
  }, [cargar]);

  const moverSemana = (dias) => setLunes((actual) => (actual ?? lunesDe(dayjs())).add(dias, 'day'));
  const loteDeLaSemana = lunes ? lotes[0] : null;

  return (
    <div className="flex flex-col gap-3">
      <Alert
        type="info"
        showIcon
        message="Motorizados de Zazu: pago semanal (lunes a domingo)"
        description="Zazu calcula días, entregas y bonos y envía el monto bruto. Aquí se cruza por DNI y se aplica la retención de 4ta; al aprobar se congelan las cuentas y se genera el TXT BBVA. Los observados (DNI no encontrado, sin cuenta o con periodicidad distinta de «semanal») quedan fuera del TXT hasta corregirlos."
      />

      <div className="flex flex-wrap items-center gap-2">
        <Button icon={<LeftOutlined />} onClick={() => moverSemana(-7)} aria-label="Semana anterior" />
        <DatePicker
          picker="week"
          value={lunes}
          placeholder="Todas las semanas"
          onChange={(valor) => setLunes(valor ? lunesDe(valor) : null)}
          format={(valor) => `${lunesDe(valor).format('DD/MM')} – ${lunesDe(valor).add(6, 'day').format('DD/MM/YYYY')}`}
          style={{ width: 210 }}
        />
        <Button icon={<RightOutlined />} onClick={() => moverSemana(7)} aria-label="Semana siguiente" />
        <Button onClick={() => setLunes(lunesDe(dayjs().subtract(7, 'day')))}>Semana pasada</Button>
        {!lunes && (
          <Select
            allowClear
            placeholder="Todos los estados"
            value={estado}
            onChange={(valor) => setEstado(valor ?? null)}
            style={{ width: 180 }}
            options={Object.entries(ESTADOS).map(([value, e]) => ({ value, label: e.label }))}
          />
        )}
        {(lunes || estado) && <Button type="link" onClick={() => { setLunes(null); setEstado(null); }}>Ver todas las semanas</Button>}
      </div>

      {lunes ? (
        cargando ? <Skeleton active paragraph={{ rows: 6 }} /> : loteDeLaSemana ? (
          <LotePanel
            key={loteDeLaSemana.id}
            loteId={loteDeLaSemana.id}
            onCambio={cargar}
            permisos={{ puedeAprobar, puedePagar, puedeCalcular, puedeExportarBbva }}
          />
        ) : (
          <Empty description={`Zazu no envió la semana del ${lunes.format('DD/MM')} al ${lunes.add(6, 'day').format('DD/MM/YYYY')}.`} />
        )
      ) : (
        <Table
          rowKey="id"
          size="small"
          loading={cargando}
          dataSource={lotes}
          pagination={false}
          onRow={(l) => ({ onClick: () => setLunes(lunesDe(l.semana_inicio)), style: { cursor: 'pointer' } })}
          locale={{ emptyText: <Empty description={estado ? 'No hay pagos semanales con ese estado.' : 'Zazu todavía no envió ninguna semana.'} /> }}
          columns={[
            { title: 'Semana', key: 'semana', render: (_, l) => <span className="font-semibold">{semana(l)} <span className="text-gray-400 font-normal">· {l.semana_iso.replace('_', ' ')}</span></span> },
            { title: 'Empresa', dataIndex: 'empresa' },
            { title: 'Motorizados', dataIndex: 'lineas', align: 'center', render: (v, l) => <span>{v}{l.observados > 0 && <Tag color="orange" bordered={false} className="ml-2">{l.observados} obs.</Tag>}</span> },
            { title: 'Bruto', dataIndex: 'total_bruto', align: 'right', render: soles },
            { title: 'Retención 4ta', dataIndex: 'total_retencion', align: 'right', render: soles },
            { title: 'Neto a pagar', dataIndex: 'neto_a_pagar', align: 'right', render: (v) => <b>{soles(v)}</b> },
            { title: 'Estado', dataIndex: 'estado', align: 'center', render: (v) => <TagEstado estado={v} /> },
            { title: 'Recibido', dataIndex: 'recibido_at', render: (v, l) => (v ? `${dayjs(v).format('DD/MM HH:mm')}${l.envios > 1 ? ` (envío ${l.envios})` : ''}` : '—') },
          ]}
        />
      )}
    </div>
  );
}

function LotePanel({ loteId, onCambio, permisos }) {
  const { message, modal } = App.useApp();
  const [lote, setLote] = useState(null);
  const [accion, setAccion] = useState(null);

  const cargar = useCallback(async () => {
    try {
      setLote(await pagosSemanalesApi.obtener(loteId));
    } catch {
      message.error('No se pudo cargar el detalle de la semana.');
    }
  }, [loteId, message]);

  useEffect(() => {
    const timer = window.setTimeout(cargar, 0);
    return () => window.clearTimeout(timer);
  }, [cargar]);

  const ejecutar = async (clave, fn, ok) => {
    setAccion(clave);
    try {
      await fn();
      if (ok) message.success(ok);
      await cargar();
      onCambio();
      return true;
    } catch (error) {
      const errores = error?.response?.data?.errors;
      message.error((errores && Object.values(errores).flat()[0]) || error?.response?.data?.message || 'No se pudo completar la acción.', 6);
      return false;
    } finally {
      setAccion(null);
    }
  };

  const pedirTexto = (titulo, placeholder, alConfirmar) => {
    let texto = '';
    modal.confirm({
      title: titulo,
      icon: <ExclamationCircleOutlined />,
      content: <Input.TextArea rows={3} maxLength={500} placeholder={placeholder} onChange={(e) => { texto = e.target.value; }} />,
      okText: 'Confirmar',
      cancelText: 'Cancelar',
      onOk: () => {
        if (!texto.trim()) {
          message.warning('Escribe el motivo.');
          return Promise.reject();
        }
        return alConfirmar(texto.trim());
      },
    });
  };

  if (!lote) return <Skeleton active paragraph={{ rows: 6 }} />;

  const estado = lote.estado;
  const porAprobar = ['recibido', 'observado'].includes(estado);
  const yaAprobado = ['aprobado', 'pagado'].includes(estado);

  // TXT: si aún no está aprobado, se aprueba primero (congela cuentas) y luego se descarga.
  const generarTxt = () => {
    if (yaAprobado) {
      ejecutar('txt', () => pagosSemanalesApi.descargarBbva(lote));
      return;
    }
    modal.confirm({
      title: 'Aprobar y generar TXT BBVA (4ta)',
      icon: <ExclamationCircleOutlined />,
      content: (
        <div className="text-sm">
          Se aprobarán <b>{lote.validos}</b> motorizado(s) por <b>{soles(lote.neto_a_pagar)}</b> y se descargará el TXT.
          {lote.observados > 0 && <div className="mt-2 text-amber-600">{lote.observados} observado(s) quedan fuera del TXT hasta corregirlos.</div>}
        </div>
      ),
      okText: 'Aprobar y descargar',
      cancelText: 'Cancelar',
      onOk: async () => {
        const aprobado = await ejecutar('txt', () => pagosSemanalesApi.aprobar(lote.id), 'Semana aprobada: cuentas congeladas.');
        if (aprobado) await ejecutar('txt', () => pagosSemanalesApi.descargarBbva(lote));
      },
    });
  };

  const columnas = [
    {
      title: 'Motorizado', key: 'nombre', fixed: 'left', width: 250,
      render: (_, d) => (
        <div className="leading-tight">
          <div className="font-semibold">{d.colaborador || <span className="text-red-500">No encontrado en Agento</span>}</div>
          <div className="text-xs text-gray-500">DNI {d.dni} · Zazu: {d.nombre_zazu}{d.alias ? ` (${d.alias})` : ''}</div>
          {d.aviso && <div className="text-xs text-amber-600">{d.aviso}</div>}
        </div>
      ),
    },
    { title: 'Días', key: 'dias', align: 'center', width: 64, render: (_, d) => `${d.dias_trabajados}/${d.dias_base}` },
    { title: 'Entregados', dataIndex: 'pedidos_entregados', align: 'center', width: 90 },
    { title: 'Por días', dataIndex: 'monto_dias', align: 'right', render: soles },
    { title: 'Bonos', dataIndex: 'monto_bonos', align: 'right', render: (v) => (v > 0 ? soles(v) : '—') },
    { title: 'Ajustes', key: 'ajustes', align: 'right', render: (_, d) => (d.adicional || d.descuento ? `+${d.adicional.toFixed(2)} / -${d.descuento.toFixed(2)}` : '—') },
    { title: 'Bruto', dataIndex: 'monto_bruto', align: 'right', render: soles },
    { title: 'Ret. 4ta', dataIndex: 'retencion_4ta', align: 'right', render: (v) => (v > 0 ? `-${soles(v)}` : '—') },
    { title: 'Neto', dataIndex: 'monto_neto', align: 'right', render: (v, d) => <b className={d.estado === 'observado' ? 'text-gray-400 line-through' : ''}>{soles(v)}</b> },
    { title: 'Cuenta', key: 'cuenta', render: (_, d) => (d.tiene_cuenta ? d.banco : <Tag color="red" bordered={false}>Sin cuenta</Tag>) },
    {
      title: 'Estado', key: 'estado', width: 230,
      render: (_, d) => (
        <div className="leading-tight">
          <TagEstado estado={d.estado} />
          {d.observacion && <div className="mt-1 text-xs text-amber-700">{d.observacion}</div>}
        </div>
      ),
    },
  ];

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-wrap items-center gap-2 text-sm">
          <span className="font-semibold">{lote.empresa} · Semana {semana(lote)}</span>
          <TagEstado estado={lote.estado} />
          <span className="text-gray-500">Enviado por {lote.enviado_por || '—'}{lote.envios > 1 ? ` · envío ${lote.envios}` : ''}</span>
        </div>
        <Space wrap>
          {permisos.puedeCalcular && porAprobar && (
            <Tooltip title="Vuelve a cruzar DNI, periodicidad, cuentas y retención con los datos actuales de los colaboradores">
              <Button icon={<ReloadOutlined />} loading={accion === 'revalidar'} onClick={() => ejecutar('revalidar', () => pagosSemanalesApi.revalidar(lote.id), 'Revalidado.')}>Revalidar</Button>
            </Tooltip>
          )}
          {permisos.puedeAprobar && ['recibido', 'aprobado'].includes(estado) && (
            <Button icon={<RollbackOutlined />} onClick={() => pedirTexto('Devolver a Zazu con observación', 'Qué debe corregir Zazu…', (t) => ejecutar('observar', () => pagosSemanalesApi.observar(lote.id, t), 'Devuelto a Zazu.'))}>Devolver a Zazu</Button>
          )}
          {permisos.puedeAprobar && porAprobar && (
            <Button icon={<CheckOutlined />} disabled={!lote.validos} loading={accion === 'aprobar'} onClick={() => ejecutar('aprobar', () => pagosSemanalesApi.aprobar(lote.id), 'Semana aprobada: cuentas congeladas.')}>
              Aprobar {lote.validos}
            </Button>
          )}
          {permisos.puedeExportarBbva && (yaAprobado || (porAprobar && permisos.puedeAprobar)) && (
            <Tooltip title={!lote.validos ? 'No hay motorizados válidos: corrige los observados y pulsa Revalidar.' : undefined}>
              <Button type="primary" icon={<DownloadOutlined />} disabled={!lote.validos} loading={accion === 'txt'} onClick={generarTxt}>
                {yaAprobado ? 'Descargar TXT BBVA (4ta)' : 'Aprobar y generar TXT'}
              </Button>
            </Tooltip>
          )}
          {permisos.puedePagar && estado === 'aprobado' && (
            <Button icon={<DollarOutlined />} loading={accion === 'pagar'}
              onClick={() => modal.confirm({
                title: '¿Marcar como pagado?',
                content: `Neto ${soles(lote.neto_a_pagar)} a ${lote.validos} motorizado(s). Zazu lo verá como pagado.`,
                okText: 'Marcar pagado', cancelText: 'Cancelar',
                onOk: () => ejecutar('pagar', () => pagosSemanalesApi.pagar(lote.id), 'Semana pagada.'),
              })}>Marcar pagado</Button>
          )}
          {permisos.puedeAprobar && ['recibido', 'observado', 'aprobado'].includes(estado) && (
            <Button danger icon={<StopOutlined />} onClick={() => pedirTexto('Anular la semana', 'Motivo de la anulación…', (t) => ejecutar('anular', () => pagosSemanalesApi.anular(lote.id, t), 'Semana anulada.'))}>Anular</Button>
          )}
        </Space>
      </div>

      {lote.observacion && <Alert type="warning" showIcon message={lote.observacion} />}
      {lote.observados > 0 && (
        <Alert type="warning" showIcon message={`${lote.observados} motorizado(s) observado(s): no entran al TXT`}
          description="Corrige al colaborador en Agento (DNI, régimen locador, periodicidad «semanal» o cuenta bancaria) y pulsa Revalidar. También puedes devolver la semana a Zazu." />
      )}

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        {[
          ['Motorizados', `${lote.lineas}${lote.observados ? ` (${lote.observados} obs.)` : ''}`],
          ['Bruto', soles(lote.total_bruto)],
          ['Retención 4ta', soles(lote.total_retencion)],
          ['Neto a pagar (TXT)', soles(lote.neto_a_pagar)],
        ].map(([label, valor]) => (
          <div key={label} className="rounded-lg border border-gray-200 bg-white px-4 py-3">
            <div className="text-xs uppercase tracking-wide text-gray-500">{label}</div>
            <div className="text-lg font-bold">{valor}</div>
          </div>
        ))}
      </div>

      <Table rowKey="id" size="small" dataSource={lote.detalles} columns={columnas} pagination={false} scroll={{ x: 1300 }} />
      {lote.referencia_pago && <div className="text-xs text-gray-500">Referencia de pago: {lote.referencia_pago}</div>}
    </div>
  );
}
