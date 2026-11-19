import axios from 'axios';
import { CLAVE_TOKEN, crearRenovador, limpiarSesion } from './sesion';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL
    ?? (import.meta.env.DEV ? 'http://127.0.0.1:8000/api' : '/api'),
});

// Renovación preventiva del token (ver services/sesion.js). La llamada a /refresh se marca para no
// pasar otra vez por el renovador.
const asegurarSesionVigente = crearRenovador({
  almacen: localStorage,
  llamarRefresh: (token) => api.post('/refresh', null, {
    sinRenovar: true,
    headers: { Authorization: `Bearer ${token}` },
  }).then((respuesta) => respuesta.data),
});

api.interceptors.request.use(async (config) => {
  if (!config.sinRenovar && !String(config.url).endsWith('/login')) {
    await asegurarSesionVigente();
  }
  const token = localStorage.getItem(CLAVE_TOKEN);
  if (token && !config.headers.Authorization) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      limpiarSesion(localStorage);
      window.dispatchEvent(new CustomEvent('auth:unauthorized', {
        detail: { message: error.response?.data?.message ?? 'La sesión expiró' },
      }));
    }
    return Promise.reject(error);
  },
);

// Al volver a una pestaña que estuvo en segundo plano, se renueva si estaba por vencer.
if (typeof document !== 'undefined') {
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') asegurarSesionVigente();
  });
}

export default api;
