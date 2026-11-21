import { CheckCircleFilled, CloseCircleFilled, ExportOutlined, InfoCircleFilled } from '@ant-design/icons';
import {
  Alert, App, Button, Card, DatePicker, Descriptions, Form, Input, Modal, Select, Space, Switch, Tag, Timeline,
} from 'antd';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { ESTADOS_AGENTO, EVENTOS_AGENTO, mensajeDeError } from '../constants/seleccion';
import { agentoPublicacionApi } from '../services/agentoPublicacionApi';
import { useCatalogosAgento, useHistorialAgento, usePublicacionAgento } from '../hooks/useSeleccion';
import EstadoSeccion from './EstadoSeccion';
import MotivoModal from './MotivoModal';

const ETIQUETA_ACCION = {
  publicar: 'Publicar en Agento',
  programar: 'Programar',
  pausar: 'Pausar',
  reanudar: 'Reanudar',
  retirar: 'Retirar de Agento',
  renovar: 'Renovar',
};

const EFECTO_ACCION = {
  publicar: 'La oferta aparecerá en Agento y el proceso quedará abierto para recibir postulaciones.',
  pausar: 'La oferta seguirá visible en Agento, pero el botón Postular quedará deshabilitado.',
  reanudar: 'Las personas podrán volver a postular desde Agento.',
  retirar: 'La oferta dejará de verse en Agento. Podrás renovarla más adelante.',
};

function ChecklistPublicacion({ checklist }) {
  return (
    <Card size="small" title="Lista de verificación" aria-label="Lista de verificación para publicar">
      <ul className="m-0 list-none space-y-1 p-0">
        {checklist.items.map((item) => (
          <li key={item.clave} className="flex items-start gap-2 text-sm">
            {item.ok
              ? <CheckCircleFilled className="mt-1 text-green-600" aria-label="Cumplido" />
              : item.obligatorio
                ? <CloseCircleFilled className="mt-1 text-red-600" aria-label="Falta" />
                : <InfoCircleFilled className="mt-1 text-amber-500" aria-label="Recomendado" />}
            <span className={item.ok ? 'text-gray-700' : 'text-gray-900'}>
              {item.etiqueta}
              {item.detalle && <span className="block text-xs text-gray-500">{item.detalle}</span>}
            </span>
          </li>
        ))}
      </ul>
      <p className="mt-3 text-xs text-gray-500">
        {checklist.listo ? 'Todo listo para publicar.' : 'Completa lo marcado en rojo para poder publicar.'}
      </p>
    </Card>
  );
}

function VistaPrevia({ procesoId, abierto, onCerrar }) {
  const [oferta, setOferta] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    if (!abierto) return undefined;
    let vigente = true;
    agentoPublicacionApi.vistaPrevia(procesoId).then(
      (d) => { if (vigente) { setOferta(d); setError(null); } },
      (e) => { if (vigente) setError(mensajeDeError(e, 'No se pudo cargar la vista previa.')); },
    );
    return () => { vigente = false; };
  }, [abierto, procesoId]);

  return (
    <Modal open={abierto} onCancel={onCerrar} footer={null} title="Vista previa en Agento" width={720} destroyOnHidden>
      {error && <Alert type="warning" showIcon message={error} />}
      {oferta && (
        <div className="space-y-3">
          <div>
            <h3 className="m-0 text-lg font-semibold text-gray-900">{oferta.title}</h3>
            <p className="m-0 text-sm text-gray-600">
              {oferta.company.name} · {oferta.location.distrito}, {oferta.location.departamento}
              {oferta.confidential && <Tag className="ml-2">Empresa confidencial</Tag>}
            </p>
          </div>
          <Descriptions size="small" column={2} bordered>
            <Descriptions.Item label="Área">{oferta.area}</Descriptions.Item>
            <Descriptions.Item label="Jornada">{oferta.schedule}</Descriptions.Item>
            <Descriptions.Item label="Modalidad">{oferta.mode}</Descriptions.Item>
            <Descriptions.Item label="Sueldo">{oferta.salary ? `S/ ${oferta.salary.min} – S/ ${oferta.salary.max}` : 'Sueldo no informado'}</Descriptions.Item>
          </Descriptions>
          <p className="text-sm text-gray-700">{oferta.summary}</p>
          {oferta.benefits.length > 0 && <div className="flex flex-wrap gap-1">{oferta.benefits.map((b) => <Tag key={b}>{b}</Tag>)}</div>}
          {oferta.requirements.mandatory.length > 0 && (
            <div>
              <p className="m-0 text-sm font-semibold">Requisitos</p>
              <ul className="m-0 pl-5 text-sm text-gray-700">{oferta.requirements.mandatory.map((r) => <li key={r}>{r}</li>)}</ul>
            </div>
          )}
        </div>
      )}
    </Modal>
  );
}

function Historial({ procesoId, version }) {
  const { datos, cargando, error, recargar } = useHistorialAgento(procesoId, version);

  return (
    <Card size="small" title="Historial en Agento">
      <EstadoSeccion cargando={cargando && !datos} error={error} onReintentar={recargar} vacio={(datos ?? []).length === 0} mensajeVacio="Aún no hay movimientos.">
        <Timeline
          items={(datos ?? []).map((e) => ({
            content: (
              <div className="text-sm">
                <p className="m-0 font-medium">{EVENTOS_AGENTO[e.evento] ?? e.evento}</p>
                <p className="m-0 text-xs text-gray-500">
                  {e.usuario ?? 'Sistema'} · {dayjs(e.fecha).format('DD/MM/YYYY HH:mm')}
                </p>
                {e.detalle && <p className="m-0 text-xs text-gray-600">{e.detalle}</p>}
              </div>
            ),
          }))}
        />
      </EstadoSeccion>
    </Card>
  );
}

/**
 * Pestaña «Publicación en Agento» del detalle de un proceso: datos públicos de la oferta, lista de
 * verificación, vista previa y acciones (guardar, publicar, programar, pausar, reanudar, retirar, renovar,
 * destacar). La empresa y los permisos los resuelve el backend.
 */
export default function PublicacionAgentoTab({ procesoId, proceso, editable, onCambio }) {
  const { message } = App.useApp();
  const [form] = Form.useForm();
  const { datos, cargando, error, recargar, acciones } = usePublicacionAgento(procesoId);
  const catalogos = useCatalogosAgento();
  const [version, setVersion] = useState(0);
  const [lugares, setLugares] = useState([]);
  const [modal, setModal] = useState(null);
  const [vistaPreviaAbierta, setVistaPreviaAbierta] = useState(false);
  const [guardando, setGuardando] = useState(false);
  const [fechaAccion, setFechaAccion] = useState(null);

  const publicacion = datos?.publicacion;
  const estado = publicacion?.estado_publicacion ?? 'borrador';
  const disponibles = datos?.acciones ?? [];
  const bloqueado = !editable || ['retirada', 'vencida'].includes(estado);

  const valoresIniciales = useMemo(() => ({
    titulo_publico: publicacion?.titulo_publico ?? undefined,
    resumen: publicacion?.resumen ?? undefined,
    area_id: publicacion?.area_id ?? undefined,
    nivel_id: publicacion?.nivel_id ?? undefined,
    jornada_id: publicacion?.jornada_id ?? undefined,
    ubigeo_codigo: publicacion?.ubigeo_codigo ?? undefined,
    sin_experiencia: publicacion?.sin_experiencia ?? false,
    inclusiva: publicacion?.inclusiva ?? false,
    beneficios: publicacion?.beneficios ?? [],
    vigente_hasta: publicacion?.vigente_hasta ? dayjs(publicacion.vigente_hasta) : undefined,
  }), [publicacion]);

  // Opciones de ubicación: la ya guardada más lo que devuelva la búsqueda.
  const opcionesLugar = useMemo(() => {
    const actual = publicacion?.ubigeo_codigo ? [{ codigo: publicacion.ubigeo_codigo, etiqueta: publicacion.ubicacion_texto }] : [];
    const unicas = new Map([...actual, ...lugares].map((l) => [l.codigo, l]));
    return [...unicas.values()].map((l) => ({ value: l.codigo, label: l.etiqueta }));
  }, [publicacion, lugares]);

  const buscarLugares = async (texto) => {
    try {
      setLugares(await agentoPublicacionApi.ubigeo(texto));
    } catch {
      setLugares([]);
    }
  };

  const cambio = () => {
    setVersion((v) => v + 1);
    recargar();
    // Publicar, retirar, etc. pueden cambiar el proceso (abrirlo, quitar su publicación externa).
    onCambio?.();
  };

  const ejecutar = async (accion, exito) => {
    try {
      await accion();
      message.success(exito);
      cambio();
    } catch (e) {
      message.error(mensajeDeError(e));
      throw e;
    }
  };

  const guardar = async () => {
    const v = await form.validateFields();
    setGuardando(true);
    try {
      await ejecutar(() => acciones.guardar(procesoId, {
        ...v,
        vigente_hasta: v.vigente_hasta ? v.vigente_hasta.format('YYYY-MM-DD') : null,
        version: publicacion?.version,
      }), 'Datos de la publicación guardados.');
    } catch {
      // El error ya se mostró.
    } finally {
      setGuardando(false);
    }
  };

  const lanzar = (accion) => {
    setFechaAccion(null);
    setModal(accion);
  };

  const confirmarAccion = async (valores = {}) => {
    const accion = modal;
    const cuerpo = { ...valores };
    if (accion === 'programar') cuerpo.programada_para = fechaAccion?.toISOString();
    if (accion === 'renovar' && fechaAccion) cuerpo.vigente_hasta = fechaAccion.format('YYYY-MM-DD');
    await ejecutar(() => acciones.accion(procesoId, accion, cuerpo), `${ETIQUETA_ACCION[accion]}: listo.`);
    setModal(null);
  };

  return (
    <EstadoSeccion cargando={cargando && !datos} error={error} onReintentar={recargar}>
      {datos && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
          <div className="space-y-4 lg:col-span-2">
            <Card
              size="small"
              title={(
                <Space wrap>
                  <span>Oferta en Agento</span>
                  <Tag color={ESTADOS_AGENTO[estado]?.color}>{ESTADOS_AGENTO[estado]?.label}</Tag>
                  {publicacion?.destacada && <Tag color="gold">Destacada</Tag>}
                </Space>
              )}
              extra={publicacion && ['publicada', 'pausada'].includes(estado) && publicacion.url_publica && (
                <Button type="link" icon={<ExportOutlined />} href={publicacion.url_publica} target="_blank" rel="noopener noreferrer">Ver en Agento</Button>
              )}
            >
              {estado === 'programada' && publicacion?.programada_para && (
                <Alert type="info" showIcon className="mb-3" message={`Se publicará el ${dayjs(publicacion.programada_para).format('DD/MM/YYYY HH:mm')}.`} />
              )}
              {estado === 'pausada' && (
                <Alert type="warning" showIcon className="mb-3" message={publicacion?.pausada_por === 'proceso' ? 'Pausada porque el proceso está en pausa. Se reanuda sola al reanudar el proceso.' : 'Pausada manualmente: la oferta se ve, pero no admite postulaciones.'} />
              )}
              {estado === 'retirada' && publicacion?.motivo_retiro && (
                <Alert type="error" showIcon className="mb-3" message={`Retirada: ${publicacion.motivo_retiro}`} />
              )}
              {proceso.empresa_confidencial && (
                <Alert type="info" showIcon className="mb-3" message="El proceso es confidencial: en Agento se mostrará como «Empresa confidencial», sin nombre ni logo." />
              )}

              {/* key: al guardar o cambiar de estado el formulario se reinicia con los valores del servidor. */}
              <Form key={publicacion?.version ?? 0} form={form} initialValues={valoresIniciales} layout="vertical" disabled={bloqueado} requiredMark={false}>
                <Form.Item name="titulo_publico" label="Título público" extra={`Si lo dejas vacío se usa el cargo: ${proceso.cargo}.`}>
                  <Input maxLength={150} showCount placeholder={proceso.cargo} />
                </Form.Item>
                <Form.Item name="resumen" label="Resumen" rules={[{ min: 20, message: 'Escribe al menos 20 caracteres.' }]} extra="Se muestra en el listado de Agento.">
                  <Input.TextArea rows={3} maxLength={300} showCount />
                </Form.Item>
                <div className="grid grid-cols-1 gap-x-4 md:grid-cols-3">
                  <Form.Item name="area_id" label="Área">
                    <Select placeholder="Selecciona" options={(catalogos.datos?.areas ?? []).map((a) => ({ value: a.id, label: a.etiqueta }))} />
                  </Form.Item>
                  <Form.Item name="nivel_id" label="Nivel">
                    <Select placeholder="Selecciona" options={(catalogos.datos?.niveles ?? []).map((a) => ({ value: a.id, label: a.etiqueta }))} />
                  </Form.Item>
                  <Form.Item name="jornada_id" label="Jornada">
                    <Select placeholder="Selecciona" options={(catalogos.datos?.jornadas ?? []).map((a) => ({ value: a.id, label: a.etiqueta }))} />
                  </Form.Item>
                </div>
                <Form.Item name="ubigeo_codigo" label="Ubicación (distrito)">
                  <Select
                    showSearch
                    filterOption={false}
                    placeholder="Escribe un distrito o departamento"
                    onSearch={buscarLugares}
                    onFocus={() => lugares.length === 0 && buscarLugares('')}
                    options={opcionesLugar}
                    notFoundContent="Sin resultados"
                  />
                </Form.Item>
                <Form.Item name="beneficios" label="Beneficios">
                  <Select mode="multiple" placeholder="Selecciona los beneficios" options={(catalogos.datos?.beneficios ?? []).map((b) => ({ value: b.id, label: b.etiqueta }))} />
                </Form.Item>
                <div className="grid grid-cols-1 gap-x-4 md:grid-cols-3">
                  <Form.Item name="sin_experiencia" label="No requiere experiencia" valuePropName="checked"><Switch /></Form.Item>
                  <Form.Item name="inclusiva" label="Oferta inclusiva" valuePropName="checked"><Switch /></Form.Item>
                  <Form.Item name="vigente_hasta" label="Vigente hasta" extra="Opcional. Vacío = hasta cerrar el proceso.">
                    <DatePicker format="DD/MM/YYYY" disabledDate={(d) => d && d.isBefore(dayjs().startOf('day'))} className="w-full" />
                  </Form.Item>
                </div>
              </Form>

              <Space wrap>
                {editable && !bloqueado && <Button type="primary" loading={guardando} onClick={guardar}>Guardar</Button>}
                {publicacion && <Button onClick={() => setVistaPreviaAbierta(true)}>Vista previa</Button>}
                {editable && disponibles.filter((a) => ETIQUETA_ACCION[a]).map((a) => (
                  <Button key={a} danger={a === 'retirar'} type={a === 'publicar' ? 'primary' : 'default'} disabled={a === 'publicar' && !datos.checklist.listo} onClick={() => lanzar(a)}>{ETIQUETA_ACCION[a]}</Button>
                ))}
                {editable && publicacion && ['publicada', 'pausada'].includes(estado) && (
                  <Button onClick={() => ejecutar(() => acciones.destacar(procesoId, { destacar: !publicacion.destacada }), publicacion.destacada ? 'Se quitó el destacado.' : 'Oferta destacada.').catch(() => {})}>
                    {publicacion.destacada ? 'Quitar destacado' : 'Destacar'}
                  </Button>
                )}
              </Space>
            </Card>
          </div>

          <div className="space-y-4">
            <ChecklistPublicacion checklist={datos.checklist} />
            {publicacion && <Historial procesoId={procesoId} version={version} />}
          </div>
        </div>
      )}

      <VistaPrevia procesoId={procesoId} abierto={vistaPreviaAbierta} onCerrar={() => setVistaPreviaAbierta(false)} />

      <MotivoModal
        abierto={modal === 'retirar'}
        titulo="Retirar de Agento"
        efecto={EFECTO_ACCION.retirar}
        peligroso
        textoConfirmar="Retirar"
        onConfirmar={confirmarAccion}
        onCerrar={() => setModal(null)}
      />

      <Modal
        open={['publicar', 'pausar', 'reanudar'].includes(modal)}
        title={ETIQUETA_ACCION[modal]}
        okText="Confirmar"
        cancelText="Cancelar"
        onOk={() => confirmarAccion().catch(() => {})}
        onCancel={() => setModal(null)}
        destroyOnHidden
      >
        <p>{EFECTO_ACCION[modal]}</p>
      </Modal>

      <Modal
        open={['programar', 'renovar'].includes(modal)}
        title={ETIQUETA_ACCION[modal]}
        okText="Confirmar"
        cancelText="Cancelar"
        okButtonProps={{ disabled: modal === 'programar' && !fechaAccion }}
        onOk={() => confirmarAccion().catch(() => {})}
        onCancel={() => setModal(null)}
        destroyOnHidden
      >
        <p className="text-sm text-gray-600">
          {modal === 'programar' ? 'Elige cuándo debe salir la oferta en Agento.' : 'Elige hasta cuándo seguirá vigente. Si no eliges fecha se usan 30 días.'}
        </p>
        <DatePicker
          showTime={modal === 'programar'}
          format={modal === 'programar' ? 'DD/MM/YYYY HH:mm' : 'DD/MM/YYYY'}
          value={fechaAccion}
          onChange={setFechaAccion}
          disabledDate={(d) => d && d.isBefore(dayjs().startOf('day'))}
          className="w-full"
          aria-label={modal === 'programar' ? 'Fecha y hora de publicación' : 'Vigente hasta'}
        />
      </Modal>
    </EstadoSeccion>
  );
}
