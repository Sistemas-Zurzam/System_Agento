import { Alert, Button, Card, Result, Spin } from 'antd';
import { useCallback, useEffect, useRef, useState } from 'react';
import { portalEmpleosApi } from '../services/portalEmpleosApi';
import RendirPrueba from './RendirPrueba';

const GOOGLE_CLIENT_ID = import.meta.env.VITE_GOOGLE_CLIENT_ID;
const SCRIPT_GOOGLE = 'https://accounts.google.com/gsi/client';

let cargaScript;
function cargarGoogle() {
  if (window.google?.accounts?.id) return Promise.resolve();
  cargaScript ??= new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = SCRIPT_GOOGLE;
    script.async = true;
    script.onload = resolve;
    script.onerror = () => {
      cargaScript = undefined;
      reject(new Error('No se pudo cargar Google'));
    };
    document.head.appendChild(script);
  });
  return cargaScript;
}

function BotonGoogle({ onCredencial, deshabilitado }) {
  const contenedor = useRef(null);
  const [fallo, setFallo] = useState(false);

  useEffect(() => {
    let activo = true;
    cargarGoogle()
      .then(() => {
        if (!activo || !contenedor.current) return;
        window.google.accounts.id.initialize({
          client_id: GOOGLE_CLIENT_ID,
          callback: (respuesta) => onCredencial(respuesta.credential),
        });
        window.google.accounts.id.renderButton(contenedor.current, {
          theme: 'outline', size: 'large', text: 'signin_with', shape: 'rectangular', locale: 'es',
        });
      })
      .catch(() => activo && setFallo(true));
    return () => { activo = false; };
  }, [onCredencial]);

  if (fallo) return <Alert type="error" showIcon message="No se pudo cargar el inicio de sesión de Google. Revisa tu conexión y recarga la página." />;

  return <div ref={contenedor} className={deshabilitado ? 'pointer-events-none opacity-50' : undefined} aria-busy={deshabilitado} />;
}

/**
 * Enlace compartido de una prueba: el participante inicia sesión con Google
 * (queda registrado su correo) y rinde la prueba con su asignación personal.
 * El token de la asignación se guarda solo en sessionStorage para sobrevivir
 * a una recarga; si no está disponible, vuelve a pedir el inicio de sesión.
 */
export default function EvaluacionPorEnlace({ token }) {
  const clave = `agento_evaluacion_${token}`;
  const [info, setInfo] = useState(null);
  const [errorInfo, setErrorInfo] = useState(false);
  const [error, setError] = useState(null);
  const [validando, setValidando] = useState(false);
  const [participante, setParticipante] = useState(() => {
    try {
      const guardado = sessionStorage.getItem(clave);
      return guardado ? JSON.parse(guardado) : null;
    } catch {
      return null;
    }
  });

  useEffect(() => {
    portalEmpleosApi.evaluacion(token).then(setInfo).catch(() => setErrorInfo(true));
  }, [token]);

  const alCredencial = useCallback(async (credencial) => {
    setValidando(true);
    setError(null);
    try {
      const datos = await portalEmpleosApi.accesoEvaluacion(token, credencial);
      try {
        sessionStorage.setItem(clave, JSON.stringify(datos));
      } catch {
        // Sin almacenamiento: la sesión vive solo mientras no se recargue.
      }
      setParticipante(datos);
    } catch (e) {
      setError(e?.response?.data?.message ?? e?.response?.data?.errors?.credencial?.[0] ?? 'No se pudo verificar tu cuenta de Google. Inténtalo de nuevo.');
    } finally {
      setValidando(false);
    }
  }, [token, clave]);

  const cambiarCuenta = () => {
    try {
      sessionStorage.removeItem(clave);
    } catch {
      // Nada que limpiar.
    }
    window.google?.accounts?.id?.disableAutoSelect();
    setParticipante(null);
  };

  if (errorInfo) {
    return <Result status="404" title="Enlace no disponible" subTitle="El enlace no existe, fue desactivado o la evaluación ya no está publicada." />;
  }
  if (!info) return <div className="flex justify-center py-10"><Spin /></div>;

  if (participante) {
    return (
      <div className="space-y-3">
        <div className="mx-auto flex max-w-2xl flex-wrap items-center justify-between gap-2 text-sm text-gray-500">
          <span>Sesión: <strong className="text-gray-700">{participante.email}</strong></span>
          <Button type="link" size="small" onClick={cambiarCuenta}>No soy yo</Button>
        </div>
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
        message={`Duración: ${info.tiempo_limite_minutos} minutos desde que inicies. Inicia sesión con tu cuenta de Google: tu correo quedará registrado y solo puedes rendirla una vez.`}
      />
      {!GOOGLE_CLIENT_ID
        ? <Alert type="error" showIcon message="El inicio de sesión con Google no está configurado. Avisa al equipo de selección." />
        : <BotonGoogle onCredencial={alCredencial} deshabilitado={validando} />}
      {error && <Alert className="mt-3" type="error" showIcon message={error} />}
    </Card>
  );
}
