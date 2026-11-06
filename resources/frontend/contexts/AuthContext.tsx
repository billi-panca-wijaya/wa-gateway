import { createContext, useContext } from 'react';
import type { ReactNode } from 'react';

type AuthBridge = { checkAuth: () => Promise<void> };
const AuthContext = createContext<AuthBridge | null>(null);

/** Connect the add-on to the host application's session refresh function. */
export function WhatsAppAuthProvider({ checkAuth, children }: AuthBridge & { children: ReactNode }) {
    return <AuthContext.Provider value={{ checkAuth }}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthBridge {
    const context = useContext(AuthContext);
    if (!context) throw new Error('Wrap WhatsAppOtpForm in WhatsAppAuthProvider.');
    return context;
}
