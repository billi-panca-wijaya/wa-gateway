import { useState, useCallback, type ReactNode } from 'react';
import type { UserRole, RoleContextType } from '../types/role';
import { RoleContext } from '../hooks/useRole';

export function RoleProvider({ children }: { children: ReactNode }) {
  // Per tab, next to the API key it was validated for: a role outliving its key (or shared with
  // another tab signed in with a different key) would gate the UI for the wrong actor.
  // Embedded build: the role always comes from /auth/validate through the website proxy (no key in
  // the browser, so the legacy localStorage role migration of the standalone dashboard is dropped).
  const [role, setRoleState] = useState<UserRole | null>(() => {
    const saved = sessionStorage.getItem('openwa_user_role');
    return (saved as UserRole) || null;
  });

  const setRole = useCallback((newRole: UserRole | null) => {
    setRoleState(newRole);
    if (newRole) {
      sessionStorage.setItem('openwa_user_role', newRole);
    } else {
      sessionStorage.removeItem('openwa_user_role');
    }
  }, []);

  // Kept beside the role because GET /infra/engines/current is admin-only: the validate response is
  // the one place every role learns which engine it is talking to.
  const [engineType, setEngineTypeState] = useState<string | null>(() => sessionStorage.getItem('openwa_engine_type'));

  const setEngineType = useCallback((newEngineType: string | null) => {
    setEngineTypeState(newEngineType);
    if (newEngineType) {
      sessionStorage.setItem('openwa_engine_type', newEngineType);
    } else {
      sessionStorage.removeItem('openwa_engine_type');
    }
  }, []);

  // Session-scoped keys are refused on the cross-session routes (e.g. /stats/overview) whatever their
  // role, so the UI needs the scope beside the role to avoid sending them.
  const [scoped, setScopedState] = useState(() => sessionStorage.getItem('openwa_key_scoped') === 'true');

  const setScoped = useCallback((newScoped: boolean) => {
    setScopedState(newScoped);
    if (newScoped) {
      sessionStorage.setItem('openwa_key_scoped', 'true');
    } else {
      sessionStorage.removeItem('openwa_key_scoped');
    }
  }, []);

  const value: RoleContextType = {
    role,
    setRole,
    isAdmin: role === 'admin',
    isOperator: role === 'operator',
    isViewer: role === 'viewer',
    canWrite: role === 'admin' || role === 'operator',
    engineType,
    setEngineType,
    scoped,
    setScoped,
  };

  return <RoleContext.Provider value={value}>{children}</RoleContext.Provider>;
}
