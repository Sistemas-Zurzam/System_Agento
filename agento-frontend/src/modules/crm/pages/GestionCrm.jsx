import { Result } from 'antd';
import { tienePermiso } from '../constants/crm';
import CanalesWhatsappPage from './CanalesWhatsappPage';
import ConversacionesPage from './ConversacionesPage';

/** Módulo CRM: /crm/conversaciones (bandeja) y /crm/canales (configuración de WhatsApp Meta). */
export default function GestionCrm({ user, vista }) {
  if (vista === 'canales') {
    return tienePermiso(user, 'crm.canales.gestionar')
      ? <CanalesWhatsappPage />
      : <Result status="403" title="Sin acceso" subTitle="No tienes permiso para configurar los canales de WhatsApp." />;
  }

  return tienePermiso(user, 'crm.conversaciones.ver', 'crm.conversaciones.responder')
    ? <ConversacionesPage user={user} />
    : <Result status="403" title="Sin acceso" subTitle="No tienes permiso para ver las conversaciones del CRM." />;
}
