import { useCallback, useState } from 'react';
import api from '../services/api';
import { guardarSesion, limpiarSesion } from '../services/sesion';

export function useAuth() {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const login = useCallback(async ({ login: identificador, password }) => {
    setLoading(true);
    setError(null);

    try {
      const { data } = await api.post('/login', { login: identificador, password });
      guardarSesion(localStorage, data);
      return true;
    } catch (err) {
      setError(err.response?.data?.message ?? 'No se pudo iniciar sesión');
      return false;
    } finally {
      setLoading(false);
    }
  }, []);

  const logout = useCallback(async () => {
    try {
      await api.post('/logout');
    } finally {
      limpiarSesion(localStorage);
    }
  }, []);

  return { login, logout, loading, error };
}
