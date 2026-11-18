import { Alert, Button, Card, Form, Input, Result, Spin } from 'antd';
import { useEffect, useState } from 'react';
import { portalEmpleosApi } from '../services/portalEmpleosApi';
import RendirPrueba from './RendirPrueba';

/**
 * Enlace compartido de una prueba: el postulante declara nombre, correo y
 * documento y rinde la prueba; el resultado lo recibe Agento. El token de su
 * asignación se guarda en este dispositivo (localStorage) para poder retomar
 * la prueba si recarga o cierra la pestaña: el servidor no lo vuelve a
 * entregar, así nadie retoma el intento de otra persona con solo su correo.
 */
export default function EvaluacionPorEnlace({ token }) {
  const clave = `agento_evaluacion_${token}`;
  const [info, setInfo] = useState(null);
  const [errorInfo, setErrorInfo] = useState(false);
  const [error, setError] = useState(null);
  const [enviando, setEnviando] = useState(false);
  const [participante, setParticipante] = useState(() => {
    try {
      const guardado = localStorage.getItem(clave);
      return guardado ? JSON.parse(guardado) : null;
    } catch {
      return null;
    }
  });

  useEffect(() => {
    portalEmpleosApi.evaluacion(token).then(setInfo).catch(() => setErrorInfo(true));
  }, [token]);

  const registrar = async (valores) => {
    setEnviando(true);
    setError(null);
    try {
      const datos = await portalEmpleosApi.accesoEvaluacion(token, valores);
      try {
        localStorage.setItem(clave, JSON.stringify(datos));
      } catch {
        // Sin almacenamiento: no podrá retomar si recarga la página.
      }
      setParticipante(datos);
    } catch (e) {
      const errores = e?.response?.data?.errors;
      setError(errores ? Object.values(errores).flat()[0] : (e?.response?.data?.message ?? 'No se pudo registrar tu ingreso. Inténtalo de nuevo.'));
    } finally {
      setEnviando(false);
    }
  };

  if (errorInfo) {
    return <Result status="404" title="Enlace no disponible" subTitle="El enlace no existe, fue desactivado o la evaluación ya no está publicada." />;
  }
  if (!info) return <div className="flex justify-center py-10"><Spin /></div>;

  if (participante) {
    return (
      <div className="space-y-3">
        <p className="mx-auto max-w-2xl text-sm text-gray-500">Participante: <strong className="text-gray-700">{participante.nombre}</strong> ({participante.email})</p>
        <RendirPrueba token={participante.token} />
      </div>
    );
  }

  return (
    <Card className="mx-auto max-w-xl">
      <h1 className="text-lg font-semibold">{info.nombre}</h1>
      {info.descripcion && <p className="mt-2 whitespace-pre-line text-sm text-gray-600">{info.descripcion}</p>}
      <Alert
        className="my-4"
        type="info"
        showIcon
        message={`Duración: ${info.tiempo_limite_minutos} minutos desde que inicies. Completa tus datos para comenzar; solo puedes rendirla una vez.`}
      />
      <Form layout="vertical" onFinish={registrar} requiredMark={false} disabled={enviando}>
        <Form.Item name="nombre" label="Nombres y apellidos" rules={[{ required: true, min: 3, message: 'Escribe tu nombre completo.' }]}>
          <Input maxLength={120} autoComplete="name" />
        </Form.Item>
        <Form.Item name="email" label="Correo electrónico" rules={[{ required: true, type: 'email', message: 'Escribe un correo válido.' }]}>
          <Input maxLength={150} autoComplete="email" inputMode="email" />
        </Form.Item>
        <Form.Item name="documento" label="Documento de identidad (DNI o CE)" rules={[{ required: true, pattern: /^[A-Za-z0-9-]{6,20}$/, message: 'Escribe tu documento sin espacios (6 a 20 caracteres).' }]}>
          <Input maxLength={20} autoComplete="off" />
        </Form.Item>
        {error && <Alert className="mb-3" type="error" showIcon message={error} />}
        <Button type="primary" htmlType="submit" loading={enviando} block>Comenzar evaluación</Button>
      </Form>
    </Card>
  );
}
