import {
  Alert, App, Button, Card, DatePicker, Form, Input, InputNumber, Select, Switch, Table, Tag,
} from 'antd';
import { useEffect, useState } from 'react';
import EstadoSeccion from '../components/EstadoSeccion';
import {
  ESTADOS_AGENTO, MODALIDADES, TIPOS_CONTRATO, mensajeDeError, tiene,
} from '../constants/seleccion';
import {
  useCatalogosAgento, usePerfilEmpresaAgento, usePuestosAgento, useReferenciasSolicitud,
} from '../hooks/useSeleccion';
import { agentoPublicacionApi } from '../services/agentoPublicacionApi';

const TAMANOS = ['1-10 empleados', '11-50 empleados', '51-200 empleados', '201-500 empleados', 'Más de 500 empleados'];

/** Perfil público de la empresa activa: lo que Agento muestra en «Empresas». */
function PerfilEmpresa({ editable }) {
  const { message } = App.useApp();
  const { datos, cargando, error, recargar } = usePerfilEmpresaAgento();
  const [guardando, setGuardando] = useState(false);

  const guardar = async (valores) => {
    setGuardando(true);
    try {
      await agentoPublicacionApi.guardarEmpresa(valores);
      message.success('Perfil de la empresa actualizado.');
      recargar();
    } catch (e) {
      message.error(mensajeDeError(e, 'No se pudo guardar el perfil.'));
    } finally {
      setGuardando(false);
    }
  };

  return (
    <Card size="small" title="Perfil de la empresa en Agento">
      <EstadoSeccion cargando={cargando && !datos} error={error} onReintentar={recargar}>
        {datos && (
          <Form
            key={datos.slug}
            layout="vertical"
            requiredMark={false}
            disabled={!editable}
            initialValues={{
              sector: datos.sector ?? undefined,
              tamano: datos.tamano ?? undefined,
              descripcion: datos.descripcion ?? undefined,
              sitio_web: datos.sitio_web ?? undefined,
            }}
            onFinish={guardar}
          >
            <p className="mt-0 text-sm text-gray-600">
              <strong>{datos.nombre}</strong>
              {' · '}
              <span className="text-gray-500">/empresas/{datos.slug}</span>
            </p>
            <div className="grid gap-x-4 md:grid-cols-2">
              <Form.Item name="sector" label="Sector" rules={[{ max: 80 }]}><Input placeholder="Ej. Retail, Logística, Tecnología" /></Form.Item>
              <Form.Item name="tamano" label="Tamaño">
                <Select allowClear placeholder="Selecciona" options={TAMANOS.map((t) => ({ value: t, label: t }))} />
              </Form.Item>
            </div>
            <Form.Item name="sitio_web" label="Sitio web" rules={[{ type: 'url', message: 'Incluye http:// o https://' }]}>
              <Input placeholder="https://" />
            </Form.Item>
            <Form.Item name="descripcion" label="Descripción" rules={[{ max: 2000 }]}>
              <Input.TextArea rows={3} showCount maxLength={2000} />
            </Form.Item>
            {editable && <Button type="primary" htmlType="submit" loading={guardando}>Guardar perfil</Button>}
          </Form>
        )}
      </EstadoSeccion>
    </Card>
  );
}

/** Formulario único: crea la solicitud, el proceso y la publicación, y opcionalmente publica. */
function NuevoPuesto({ editable, onCreado, onAbrirProceso }) {
  const { message } = App.useApp();
  const [form] = Form.useForm();
  const catalogos = useCatalogosAgento();
  const referencias = useReferenciasSolicitud(true);
  const [lugares, setLugares] = useState([]);
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState(null);

  const buscarLugares = async (texto) => {
    try {
      setLugares(await agentoPublicacionApi.ubigeo(texto));
    } catch {
      setLugares([]);
    }
  };

  useEffect(() => { buscarLugares(''); }, []);

  const enviar = async (publicar) => {
    let valores;
    try {
      valores = await form.validateFields();
    } catch {
      return;
    }
    setEnviando(true);
    setError(null);
    try {
      const { vigente_hasta: vigenteHasta, habilidades, ...resto } = valores;
      const respuesta = await agentoPublicacionApi.crearPuesto({
        ...resto,
        moneda: 'PEN',
        habilidades: (habilidades ?? []).map((nombre) => ({ nombre, nivel: 3 })),
        vigente_hasta: vigenteHasta ? vigenteHasta.format('YYYY-MM-DD') : null,
        publicar,
      });
      message.success(publicar ? `Puesto publicado en Agento (${respuesta.codigo}).` : `Puesto guardado como borrador (${respuesta.codigo}).`);
      form.resetFields();
      onCreado();
      if (!publicar) onAbrirProceso(respuesta.proceso_id);
    } catch (e) {
      setError(mensajeDeError(e, 'No se pudo crear el puesto.'));
    } finally {
      setEnviando(false);
    }
  };

  const cat = catalogos.datos;
  const ref = referencias.datos;

  return (
    <Card size="small" title="Publicar un puesto">
      <EstadoSeccion cargando={(catalogos.cargando && !cat) || (referencias.cargando && !ref)} error={catalogos.error ?? referencias.error} onReintentar={() => { catalogos.recargar(); referencias.recargar(); }}>
        {cat && ref && (
          <Form
            form={form}
            layout="vertical"
            requiredMark={false}
            disabled={!editable || enviando}
            initialValues={{ plazas: 1, modalidad: 'presencial', tipo_contrato: 'plazo_fijo', sin_experiencia: false, inclusiva: false }}
          >
            {error && <Alert type="error" showIcon className="mb-4" message={error} />}

            <p className="m-0 text-sm font-semibold text-gray-900">El puesto</p>
            <div className="grid gap-x-4 md:grid-cols-2">
              <Form.Item name="cargo" label="Cargo" rules={[{ required: true, message: 'Indica el cargo' }, { min: 3 }]}><Input maxLength={150} /></Form.Item>
              <Form.Item name="area_interna_id" label="Área de la empresa" rules={[{ required: true, message: 'Selecciona el área' }]}>
                <Select placeholder="Selecciona" options={ref.areas.map((a) => ({ value: a.id, label: a.nombre }))} />
              </Form.Item>
              <Form.Item name="plazas" label="Plazas" rules={[{ required: true }]}><InputNumber min={1} max={50} className="w-full" /></Form.Item>
              <Form.Item name="tipo_contrato" label="Tipo de contrato" rules={[{ required: true }]}><Select options={TIPOS_CONTRATO} /></Form.Item>
              <Form.Item name="modalidad" label="Modalidad" rules={[{ required: true }]}><Select options={MODALIDADES} /></Form.Item>
              <Form.Item label="Sueldo mensual (S/)" required>
                <div className="flex gap-2">
                  <Form.Item name="salario_min" noStyle rules={[{ required: true, message: 'Mínimo' }]}><InputNumber min={0} placeholder="Mínimo" className="w-full" /></Form.Item>
                  <Form.Item
                    name="salario_max"
                    noStyle
                    dependencies={['salario_min']}
                    rules={[
                      { required: true, message: 'Máximo' },
                      ({ getFieldValue }) => ({
                        validator: (_, v) => (v == null || getFieldValue('salario_min') == null || v >= getFieldValue('salario_min') ? Promise.resolve() : Promise.reject(new Error('El máximo no puede ser menor'))),
                      }),
                    ]}
                  >
                    <InputNumber min={0} placeholder="Máximo" className="w-full" />
                  </Form.Item>
                </div>
              </Form.Item>
            </div>
            <Form.Item name="descripcion" label="Descripción del puesto" rules={[{ required: true, message: 'Describe el puesto' }, { min: 30, message: 'Mínimo 30 caracteres' }]}>
              <Input.TextArea rows={3} showCount maxLength={2000} />
            </Form.Item>
            <div className="grid gap-x-4 md:grid-cols-2">
              <Form.Item name="actividades" label="Funciones (una por línea)"><Input.TextArea rows={4} maxLength={4000} /></Form.Item>
              <Form.Item name="requisitos" label="Requisitos (uno por línea)"><Input.TextArea rows={4} maxLength={4000} /></Form.Item>
            </div>
            <Form.Item name="habilidades" label="Competencias clave" rules={[{ required: true, type: 'array', min: 1, message: 'Agrega al menos una competencia' }]}>
              <Select mode="tags" tokenSeparators={[',']} placeholder="Escribe y presiona Enter" maxTagTextLength={100} open={false} />
            </Form.Item>

            <p className="mb-2 mt-2 text-sm font-semibold text-gray-900">Cómo se verá en Agento</p>
            <Form.Item name="resumen" label="Resumen" rules={[{ max: 300 }]}><Input.TextArea rows={2} showCount maxLength={300} /></Form.Item>
            <div className="grid gap-x-4 md:grid-cols-2">
              <Form.Item name="area_id" label="Área en Agento"><Select allowClear placeholder="Selecciona" options={cat.areas.map((a) => ({ value: a.id, label: a.etiqueta }))} /></Form.Item>
              <Form.Item name="nivel_id" label="Nivel"><Select allowClear placeholder="Selecciona" options={cat.niveles.map((a) => ({ value: a.id, label: a.etiqueta }))} /></Form.Item>
              <Form.Item name="jornada_id" label="Jornada"><Select allowClear placeholder="Selecciona" options={cat.jornadas.map((a) => ({ value: a.id, label: a.etiqueta }))} /></Form.Item>
              <Form.Item name="ubigeo_codigo" label="Ubicación (distrito)">
                <Select
                  showSearch
                  allowClear
                  filterOption={false}
                  placeholder="Escribe un distrito"
                  onSearch={buscarLugares}
                  options={lugares.map((l) => ({ value: l.codigo, label: l.etiqueta }))}
                />
              </Form.Item>
            </div>
            <Form.Item name="beneficios" label="Beneficios">
              <Select mode="multiple" allowClear placeholder="Selecciona" options={cat.beneficios.map((b) => ({ value: b.id, label: b.etiqueta }))} />
            </Form.Item>
            <div className="flex flex-wrap gap-x-8">
              <Form.Item name="sin_experiencia" label="Sin experiencia" valuePropName="checked"><Switch /></Form.Item>
              <Form.Item name="inclusiva" label="Oferta inclusiva" valuePropName="checked"><Switch /></Form.Item>
              <Form.Item name="vigente_hasta" label="Vigente hasta"><DatePicker format="DD/MM/YYYY" /></Form.Item>
            </div>

            {editable ? (
              <div className="flex flex-wrap gap-2">
                <Button type="primary" loading={enviando} onClick={() => enviar(true)}>Publicar en Agento</Button>
                <Button loading={enviando} onClick={() => enviar(false)}>Guardar como borrador</Button>
              </div>
            ) : (
              <Alert type="info" showIcon message="No tienes permiso para publicar puestos." />
            )}
          </Form>
        )}
      </EstadoSeccion>
    </Card>
  );
}

function ListaPuestos({ version, onAbrirProceso }) {
  const { datos, cargando, error, recargar } = usePuestosAgento(version);

  return (
    <Card size="small" title="Puestos de la empresa en Agento">
      <EstadoSeccion cargando={cargando && !datos} error={error} onReintentar={recargar} vacio={(datos ?? []).length === 0} mensajeVacio="Aún no hay puestos.">
        <Table
          size="small"
          rowKey="proceso_id"
          pagination={false}
          dataSource={datos ?? []}
          columns={[
            { title: 'Código', dataIndex: 'codigo' },
            { title: 'Cargo', dataIndex: 'cargo' },
            {
              title: 'En Agento',
              dataIndex: 'estado_publicacion',
              render: (estado) => <Tag color={ESTADOS_AGENTO[estado]?.color}>{ESTADOS_AGENTO[estado]?.label ?? estado}</Tag>,
            },
            { title: '', render: (_, fila) => <Button type="link" size="small" onClick={() => onAbrirProceso(fila.proceso_id)}>Abrir proceso</Button> },
          ]}
        />
      </EstadoSeccion>
    </Card>
  );
}

/**
 * «Publicar en Agento»: el perfil público de la empresa y el alta de puestos en un solo lugar, sin pasar por
 * solicitud → proceso → pestaña. Por debajo se crean la solicitud y el proceso (el ATS sigue igual).
 */
export default function PublicarAgentoPage({ user, onAbrirProceso }) {
  const [version, setVersion] = useState(0);
  const editable = tiene(user, 'seleccion.vacantes.gestionar')
    && tiene(user, 'seleccion.solicitudes.crear')
    && tiene(user, 'seleccion.solicitudes.decidir');

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold">Publicar en Agento</h1>
        <p className="m-0 text-sm text-gray-600">
          Completa el perfil de {user?.empresa?.nombre_comercial ?? 'la empresa'} y publica sus puestos en el portal de empleo.
        </p>
      </div>
      <PerfilEmpresa editable={tiene(user, 'seleccion.vacantes.gestionar')} />
      <NuevoPuesto editable={editable} onCreado={() => setVersion((v) => v + 1)} onAbrirProceso={onAbrirProceso} />
      <ListaPuestos version={version} onAbrirProceso={onAbrirProceso} />
    </div>
  );
}
