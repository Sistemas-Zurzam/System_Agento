import { Alert, App, Form, Input, Modal, Switch } from 'antd';
import { useState } from 'react';
import { mensajeDeError } from '../constants/crm';
import { crmApi } from '../services/crmApi';

const tokenAleatorio = () => Array.from(crypto.getRandomValues(new Uint8Array(18)), (b) => b.toString(16).padStart(2, '0')).join('');

/**
 * Alta o edición de un canal de WhatsApp Cloud API. Al editar, el token de acceso y el App secret
 * se dejan vacíos para conservar los guardados (el backend nunca los devuelve).
 */
export default function CanalWhatsappModal({ canal, abierto, onCerrar, onGuardado }) {
  const { message } = App.useApp();
  const [form] = Form.useForm();
  const [guardando, setGuardando] = useState(false);
  const editando = Boolean(canal);

  const guardar = async (valores) => {
    setGuardando(true);
    try {
      const guardado = editando
        ? await crmApi.canales.actualizar(canal.id, valores)
        : await crmApi.canales.crear(valores);
      message.success(editando ? 'Canal actualizado.' : 'Canal creado. Copia la URL del webhook en Meta.');
      onGuardado(guardado);
    } catch (e) {
      const errores = e?.response?.data?.errors;
      if (errores) {
        form.setFields(Object.entries(errores).map(([name, mensajes]) => ({ name, errors: mensajes })));
      }
      message.error(mensajeDeError(e, 'No se pudo guardar el canal.'));
    } finally {
      setGuardando(false);
    }
  };

  return (
    <Modal
      open={abierto}
      title={editando ? `Editar canal: ${canal.nombre}` : 'Nuevo canal de WhatsApp'}
      okText="Guardar"
      cancelText="Cancelar"
      confirmLoading={guardando}
      onOk={() => form.submit()}
      onCancel={onCerrar}
      destroyOnHidden
      width={640}
    >
      <Alert
        className="mb-4"
        type="info"
        showIcon
        message="Los datos salen de Meta for Developers → tu app → WhatsApp → Configuración de la API. El token debe ser permanente (usuario del sistema), no el temporal de 24 h."
      />
      <Form
        form={form}
        layout="vertical"
        requiredMark="optional"
        onFinish={guardar}
        initialValues={canal
          ? { ...canal, access_token: '', app_secret: '' }
          : { activo: true, verify_token: tokenAleatorio() }}
      >
        <Form.Item name="nombre" label="Nombre del canal" rules={[{ required: true, message: 'Ponle un nombre (p. ej. la marca o campaña).' }]}>
          <Input maxLength={120} placeholder="Bravos Urban" />
        </Form.Item>
        <div className="grid gap-x-4 sm:grid-cols-2">
          <Form.Item name="phone_number_id" label="Phone number ID" rules={[{ required: true, message: 'Es obligatorio.' }, { pattern: /^\d{5,40}$/, message: 'Solo números.' }]}>
            <Input inputMode="numeric" placeholder="1434888073042064" />
          </Form.Item>
          <Form.Item name="numero_visible" label="Número visible">
            <Input maxLength={30} placeholder="+51 907 753 748" />
          </Form.Item>
          <Form.Item name="waba_id" label="ID de la cuenta de WhatsApp Business" rules={[{ pattern: /^\d{5,40}$/, message: 'Solo números.' }]}>
            <Input inputMode="numeric" placeholder="1817993963552044" />
          </Form.Item>
          <Form.Item name="verify_token" label="Token de verificación (lo eliges tú)" rules={[{ required: true, min: 8, message: 'Mínimo 8 caracteres.' }]}>
            <Input maxLength={120} />
          </Form.Item>
        </div>
        <Form.Item
          name="access_token"
          label="Token de acceso permanente"
          extra={editando && canal.access_token_final ? `Guardado (${canal.access_token_final}). Déjalo vacío para conservarlo.` : null}
          rules={editando ? [] : [{ required: true, message: 'Es obligatorio.' }]}
        >
          <Input.Password autoComplete="off" placeholder="EAA…" />
        </Form.Item>
        <Form.Item
          name="app_secret"
          label="App secret (valida la firma de Meta)"
          extra={editando && canal.app_secret_final
            ? `Guardado (${canal.app_secret_final}). Déjalo vacío para conservarlo.`
            : 'Meta → Configuración de la app → Básica → Clave secreta de la app.'}
          rules={editando ? [] : [{ required: true, message: 'Es obligatorio.' }]}
        >
          <Input.Password autoComplete="off" />
        </Form.Item>
        <Form.Item name="activo" label="Canal activo" valuePropName="checked">
          <Switch />
        </Form.Item>
      </Form>
    </Modal>
  );
}
