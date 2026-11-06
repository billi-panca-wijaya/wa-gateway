// The OpenWA dashboard pages embedded under /dashboard/gateway/* (order = original sidebar order).
// Professional English navigation labels.
export interface GatewayPage {
  key: string;
  path: string;
  label: string;
  adminOnly?: boolean;
  unscopedOnly?: boolean;
}

const showInfra = import.meta.env.VITE_SHOW_INFRA_PAGE === 'true';

export const GATEWAY_PAGES: GatewayPage[] = [
  { key: 'dashboard', path: '', label: 'Dashboard' },
  { key: 'chats', path: 'chats', label: 'Chats' },
  { key: 'webhooks', path: 'webhooks', label: 'Webhooks' },
  { key: 'templates', path: 'templates', label: 'Templates' },
  { key: 'messageTester', path: 'message-tester', label: 'Message Tester' },
  ...(showInfra ? [{ key: 'infrastructure', path: 'infrastructure', label: 'Infrastructure', adminOnly: true, unscopedOnly: true }] : []),
  { key: 'plugins', path: 'plugins', label: 'Plugins', adminOnly: true, unscopedOnly: true },
  { key: 'logs', path: 'logs', label: 'Logs', adminOnly: true },
];

export const GATEWAY_BASE = '/dashboard/gateway';
