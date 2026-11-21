import { useEffect, useRef } from 'react';

/**
 * Ejecuta `accion` cada `intervaloMs` mientras la pestaña esté visible (y una vez al volver a ella).
 * Con `activo` en false no hace nada.
 */
export function useRefresco(accion, intervaloMs, activo = true) {
  const ultima = useRef(accion);

  useEffect(() => {
    ultima.current = accion;
  }, [accion]);

  useEffect(() => {
    if (!activo) return undefined;

    const ejecutar = () => {
      if (document.visibilityState === 'visible') ultima.current();
    };
    const id = window.setInterval(ejecutar, intervaloMs);
    document.addEventListener('visibilitychange', ejecutar);

    return () => {
      window.clearInterval(id);
      document.removeEventListener('visibilitychange', ejecutar);
    };
  }, [intervaloMs, activo]);
}
