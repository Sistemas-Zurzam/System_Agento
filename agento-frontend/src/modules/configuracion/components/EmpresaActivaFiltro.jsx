import { App, Select } from 'antd';
import { useEffect, useState } from 'react';
import { useEmpresas } from '../hooks/useEmpresas';

/**
 * Selector de empresa activa. Mismo mecanismo de cambio de empresa que
 * Header.jsx (PUT /empresas/{id}/activar y refresco del usuario).
 *
 * Props de presentación (todas opcionales):
 *  - className: ancho del selector (por defecto w-56, para áreas de filtros).
 *  - etiqueta: texto pequeño sobre el selector (p. ej. en el panel lateral).
 *  - ocultarSiUnica: no se dibuja si el usuario solo puede elegir una empresa.
 */
export default function EmpresaActivaFiltro({ user, onUserRefresh, className = 'w-56', etiqueta, ocultarSiUnica = false }) {
  const { message } = App.useApp();
  const { empresas, fetchEmpresas, activarEmpresa } = useEmpresas();
  const [switching, setSwitching] = useState(false);

  useEffect(() => {
    fetchEmpresas();
  }, [fetchEmpresas]);

  const handleChange = async (empresaId) => {
    // Bloquea selecciones mientras la anterior sigue en vuelo — dos cambios
    // de empresa disparados casi juntos podían resolverse en cualquier
    // orden (activar B, activar C, pero la respuesta de B llega última) y
    // dejaban al usuario viendo la empresa equivocada o el selector vacío.
    if (switching || empresaId === user?.empresa?.id) return;

    setSwitching(true);
    try {
      await activarEmpresa(empresaId);
      await Promise.all([fetchEmpresas(), onUserRefresh?.()]);
    } catch {
      message.error('No se pudo cambiar de empresa');
    } finally {
      setSwitching(false);
    }
  };

  const opciones = empresas
    .filter((empresa) => empresa.activa)
    .map((empresa) => ({ value: empresa.id, label: empresa.nombre_comercial }));

  if (ocultarSiUnica && opciones.length <= 1) return null;

  const selector = (
    <Select
      value={user?.empresa?.id}
      onChange={handleChange}
      loading={switching}
      disabled={switching}
      className={className}
      showSearch
      optionFilterProp="label"
      options={opciones}
    />
  );

  if (!etiqueta) return selector;

  return (
    <div>
      <p className="mb-1.5 text-xs text-white/50">{etiqueta}</p>
      {selector}
    </div>
  );
}
