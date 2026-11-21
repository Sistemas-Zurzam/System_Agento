import { SearchOutlined, UserOutlined } from '@ant-design/icons';
import { Avatar, Badge, Button, Checkbox, Empty, Input, Select, Spin, Typography, theme } from 'antd';
import dayjs from 'dayjs';

const { Text } = Typography;

const hora = (valor) => {
  if (!valor) return '';
  const fecha = dayjs(valor);
  return fecha.isSame(dayjs(), 'day') ? fecha.format('HH:mm') : fecha.format('DD/MM');
};

/** Columna izquierda de la bandeja: filtros y lista de conversaciones. */
export default function ListaConversaciones({
  conversaciones, cargando, canales, filtros, onFiltros, seleccionada, onSeleccionar,
  pagina, ultimaPagina, onPagina,
}) {
  const { token } = theme.useToken();

  return (
    <div className="flex h-full min-h-0 flex-col">
      <div className="flex flex-col gap-2 border-b border-gray-100 p-3">
        <Input
          allowClear
          prefix={<SearchOutlined />}
          placeholder="Buscar por nombre o teléfono"
          aria-label="Buscar conversación"
          defaultValue={filtros.buscar}
          onChange={(e) => !e.target.value && onFiltros({ buscar: '' })}
          onPressEnter={(e) => onFiltros({ buscar: e.target.value.trim() })}
        />
        <div className="flex items-center gap-2">
          {canales.length > 1 && (
            <Select
              allowClear
              size="small"
              className="min-w-0 flex-1"
              placeholder="Todos los canales"
              aria-label="Filtrar por canal"
              value={filtros.canal_id}
              onChange={(canal) => onFiltros({ canal_id: canal })}
              options={canales.map((c) => ({ value: c.id, label: c.nombre }))}
            />
          )}
          <Checkbox checked={filtros.no_leidas} onChange={(e) => onFiltros({ no_leidas: e.target.checked })}>
            No leídas
          </Checkbox>
        </div>
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto">
        {cargando && conversaciones.length === 0 && <div className="flex justify-center p-8"><Spin /></div>}
        {!cargando && conversaciones.length === 0 && (
          <Empty className="py-10" description="Todavía no hay conversaciones. Llegarán cuando un cliente escriba a tu WhatsApp." />
        )}
        <ul className="m-0 list-none p-0">
          {conversaciones.map((c) => {
            const activa = c.id === seleccionada;
            return (
              <li key={c.id}>
                <button
                  type="button"
                  onClick={() => onSeleccionar(c)}
                  aria-current={activa ? 'true' : undefined}
                  className="flex w-full cursor-pointer items-start gap-3 border-0 border-b border-solid border-gray-100 px-3 py-3 text-left transition-colors hover:bg-gray-50"
                  style={{ background: activa ? token.colorPrimaryBg : 'transparent' }}
                >
                  <Avatar icon={<UserOutlined />} className="shrink-0" />
                  <span className="min-w-0 flex-1">
                    <span className="flex items-baseline justify-between gap-2">
                      <Text strong ellipsis>{c.nombre_contacto || c.telefono}</Text>
                      <Text type="secondary" className="shrink-0 text-xs">{hora(c.ultimo_mensaje_at)}</Text>
                    </span>
                    <span className="flex items-center justify-between gap-2">
                      <Text type="secondary" ellipsis className="text-sm">{c.ultimo_texto_mensaje || c.telefono}</Text>
                      {c.no_leidos > 0 && <Badge count={c.no_leidos} />}
                    </span>
                    {canales.length > 1 && c.canal && <Text type="secondary" className="text-xs">{c.canal.nombre}</Text>}
                  </span>
                </button>
              </li>
            );
          })}
        </ul>
      </div>

      {ultimaPagina > 1 && (
        <div className="flex items-center justify-between border-t border-gray-100 p-2">
          <Button size="small" disabled={pagina <= 1} onClick={() => onPagina(pagina - 1)}>Anterior</Button>
          <Text type="secondary" className="text-xs">Página {pagina} de {ultimaPagina}</Text>
          <Button size="small" disabled={pagina >= ultimaPagina} onClick={() => onPagina(pagina + 1)}>Siguiente</Button>
        </div>
      )}
    </div>
  );
}
