import './defaults';
import { Suspense, useEffect, useState } from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Loader2, Monitor, Moon, Sun } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import '@fontsource-variable/plus-jakarta-sans';
import '@fontsource-variable/plus-jakarta-sans/wght-italic.css';
import '@fontsource-variable/jetbrains-mono';
import '@fontsource-variable/heebo';
import '@fontsource-variable/noto-sans-arabic';
import './index.css';
import './App.css';
import './embed.css';
import { i18nReady, languageOptions, resolveSupportedLanguage, type SupportedLanguage } from './i18n';
import { lazyWithRetry as lazy } from './utils/lazyWithRetry';
import { ToastProvider } from './components/Toast';
import { RoleProvider } from './components/RoleProvider';
import { ErrorBoundary } from './components/ErrorBoundary';
import { useRole } from './hooks/useRole';
import { useTheme } from './hooks/useTheme';
import { API_BASE_URL, healthApi } from './services/api';
import { resolveStartupValidation } from './utils/authLifecycle';
import { GATEWAY_PAGES } from './pages';

const Dashboard = lazy(() => import('./pages/Dashboard').then(m => ({ default: m.Dashboard })));
const Sessions = lazy(() => import('./pages/Sessions').then(m => ({ default: m.Sessions })));
const Chats = lazy(() => import('./pages/Chats').then(m => ({ default: m.Chats })));
const Webhooks = lazy(() => import('./pages/Webhooks').then(m => ({ default: m.Webhooks })));
const Templates = lazy(() => import('./pages/Templates').then(m => ({ default: m.Templates })));
const Logs = lazy(() => import('./pages/Logs').then(m => ({ default: m.Logs })));
const ApiKeys = lazy(() => import('./pages/ApiKeys').then(m => ({ default: m.ApiKeys })));
const MessageTester = lazy(() => import('./pages/MessageTester').then(m => ({ default: m.MessageTester })));
const Infrastructure = lazy(() => import('./pages/Infrastructure').then(m => ({ default: m.Infrastructure })));
const Plugins = lazy(() => import('./pages/Plugins'));

const ELEMENTS: Record<string, React.ReactNode> = {
  dashboard: <Dashboard />,
  sessions: <Sessions />,
  chats: <Chats />,
  webhooks: <Webhooks />,
  templates: <Templates />,
  apiKeys: <ApiKeys />,
  messageTester: <MessageTester />,
  infrastructure: <Infrastructure />,
  plugins: <Plugins />,
  logs: <Logs />,
};

const queryClient = new QueryClient({
  defaultOptions: { queries: { staleTime: 30_000, retry: 1, refetchOnWindowFocus: true } },
});

const themeIcons = { light: Sun, dark: Moon, system: Monitor };

const loader = (
  <div className="openwa-loading">
    <Loader2 className="animate-spin" size={32} />
  </div>
);

type AuthState = 'checking' | 'ready' | 'rejected' | 'offline';

function GatewayContent() {
  const { t, i18n } = useTranslation();
  const { theme, toggleTheme } = useTheme();
  const ThemeIcon = themeIcons[theme];
  const { role, scoped, setRole, setScoped, setEngineType } = useRole();
  const [auth, setAuth] = useState<AuthState>('checking');
  const [version, setVersion] = useState(__APP_VERSION__);
  const [attempt, setAttempt] = useState(0);

  // The proxy injects the gateway key; validating it tells the pages which role they run as.
  useEffect(() => {
    let active = true;
    fetch(`${API_BASE_URL}/auth/validate`, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(async res => {
        if (!active) return;
        // A non-JSON 401/403 comes from the proxy itself: the website admin session is gone.
        if ((res.status === 401 || res.status === 403) && !res.headers.get('content-type')?.includes('json')) {
          window.location.assign('/');
          return;
        }
        const decision = resolveStartupValidation(res.status, await res.json().catch(() => null));
        if (decision.action === 'role') {
          setRole(decision.role);
          setScoped(decision.scoped);
          if (decision.engineType) setEngineType(decision.engineType);
          setAuth('ready');
        } else {
          setAuth(decision.action === 'logout' ? 'rejected' : 'offline');
        }
      })
      .catch(() => active && setAuth('offline'));
    healthApi
      .check()
      .then(info => active && info?.version && setVersion(info.version))
      .catch(() => {});
    return () => {
      active = false;
    };
  }, [attempt, setRole, setScoped, setEngineType]);

  const currentLang = resolveSupportedLanguage(i18n.resolvedLanguage || i18n.language);

  return (
    <div className="openwa-root">
      <div className="openwa-toolbar">
        <div className="openwa-brand">
          <strong>{t('common.appName')}</strong>
          <span>v{version}</span>
        </div>
        <div className="openwa-toolbar-actions">
          <label className="sr-only" htmlFor="openwa-lang">{t('common.language')}</label>
          <select
            id="openwa-lang"
            className="openwa-lang"
            value={currentLang}
            onChange={e => void i18n.changeLanguage(e.target.value as SupportedLanguage)}
          >
            {languageOptions.map(o => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </select>
          <button type="button" className="theme-toggle-btn openwa-theme" onClick={toggleTheme} title={t(`theme.${theme}`)}>
            <ThemeIcon size={16} />
            <span>{t(`theme.${theme}`)}</span>
          </button>
        </div>
      </div>

      <div className="main-content openwa-main">
        {auth === 'checking' && loader}
        {auth === 'rejected' && (
          <div className="error-banner">
            <span className="error-banner-text">
              Gateway menolak kunci dashboard. Isi OPENWA_DASHBOARD_API_KEY (kunci role admin bernama
              &quot;website-dashboard&quot;) di deploy/.env lalu jalankan ulang container web.
            </span>
          </div>
        )}
        {auth === 'offline' && (
          <div className="error-banner">
            <span className="error-banner-text">Gateway WhatsApp tidak dapat dihubungi.</span>
            <button type="button" className="btn-secondary" onClick={() => { setAuth('checking'); setAttempt(a => a + 1); }}>
              {t('common.retry', 'Coba lagi')}
            </button>
          </div>
        )}
        {auth === 'ready' && (
          <ToastProvider>
            <Suspense fallback={loader}>
              <Routes>
                <Route path="gateway">
                  {GATEWAY_PAGES.filter(p => (!p.adminOnly || role === 'admin') && (!p.unscopedOnly || !scoped)).map(p =>
                    p.path === ''
                      ? <Route key={p.key} index element={ELEMENTS[p.key]} />
                      : <Route key={p.key} path={p.path} element={ELEMENTS[p.key]} />,
                  )}
                  <Route path="*" element={<Navigate to="/dashboard/gateway" replace />} />
                </Route>
              </Routes>
            </Suspense>
          </ToastProvider>
        )}
      </div>

      <footer className="openwa-footer">{t('login.footer')}</footer>
    </div>
  );
}

/** The OpenWA dashboard ("OPENWA PenjagaBumi") rendered inside the website admin layout. */
export default function OpenWaApp() {
  const [ready, setReady] = useState(false);
  useEffect(() => {
    void i18nReady.then(() => setReady(true), () => setReady(true));
  }, []);

  if (!ready) return loader;
  return (
    <ErrorBoundary>
      <QueryClientProvider client={queryClient}>
        <RoleProvider>
          <GatewayContent />
        </RoleProvider>
      </QueryClientProvider>
    </ErrorBoundary>
  );
}
