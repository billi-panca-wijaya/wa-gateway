// The OpenWA dashboard pages embedded under /dashboard/gateway/* (order = original sidebar order).
// Labels are the dashboard's own Indonesian nav strings (i18n/locales/id.json "nav").
export interface GatewayPage {
  key: string;
  path: string;
  label: string;
  adminOnly?: boolean;
  unscopedOnly?: boolean;
}

const showInfra = import.meta.env.VITE_SHOW_INFRA_PAGE === 'true';

export const GATEWAY_PAGES: GatewayPage[] = [
  { key: 'dashboard', path: '', label: 'Dasbor' },
  { key: 'chats', path: 'chats', label: 'Obrolan' },
  { key: 'webhooks', path: 'webhooks', label: 'Webhook' },
  { key: 'templates', path: 'templates', label: 'Templat' },
  { key: 'messageTester', path: 'message-tester', label: 'Penguji Pesan' },
  ...(showInfra ? [{ key: 'infrastructure', path: 'infrastructure', label: 'Infrastruktur', adminOnly: true, unscopedOnly: true }] : []),
  { key: 'plugins', path: 'plugins', label: 'Plugin', adminOnly: true, unscopedOnly: true },
  { key: 'logs', path: 'logs', label: 'Log', adminOnly: true },
];

export const GATEWAY_BASE = '/dashboard/gateway';
