import { useEffect, useRef, useState } from 'react';
import axios from '../lib/axios';

type Turnstile = {
    render: (container: HTMLElement, options: Record<string, unknown>) => string;
    execute: (id: string) => void;
    remove: (id: string) => void;
};
declare global { interface Window { turnstile?: Turnstile } }
let loading: Promise<void> | undefined;

function loadScript(): Promise<void> {
    if (window.turnstile) return Promise.resolve();
    if (!loading) {
        loading = new Promise<void>((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
            script.async = true;
            const timer = window.setTimeout(() => fail(), 10000);
            const fail = () => {
                clearTimeout(timer);
                script.remove();
                reject(new Error('Verification could not load. Check your connection and try again.'));
            };
            script.onload = () => { clearTimeout(timer); resolve(); };
            script.onerror = fail;
            document.head.appendChild(script);
        }).catch((error: unknown) => { loading = undefined; throw error; });
    }
    return loading;
}

/** Load and execute only after Request/Resend is clicked. */
export function useWhatsAppChallenge() {
    const container = useRef<HTMLDivElement>(null);
    const widget = useRef<string | null>(null);
    const cancel = useRef<(() => void) | null>(null);
    const mounted = useRef(true);
    const [verifying, setVerifying] = useState(false);
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
            cancel.current?.();
            if (widget.current !== null) window.turnstile?.remove(widget.current);
        };
    }, []);

    const execute = async (action: 'otp_request' | 'otp_resend'): Promise<string | undefined> => {
        setVerifying(true);
        try {
            const { data } = await axios.get<{ enabled: boolean; site_key: string | null }>('/api/v1/auth/whatsapp/challenge-config', { timeout: 10000 });
            if (!data.enabled) return undefined; // Backend permits this only in local/testing.
            if (!data.site_key) throw new Error('Verification is temporarily unavailable. Please try again.');
            await loadScript();
            if (!mounted.current || !container.current || !window.turnstile) throw new Error('Verification was interrupted. Please try again.');
            if (widget.current !== null) window.turnstile.remove(widget.current);
            return await new Promise<string>((resolve, reject) => {
                let settled = false;
                const finish = (token?: string) => {
                    if (settled) return;
                    settled = true;
                    clearTimeout(timer);
                    cancel.current = null;
                    if (token) resolve(token);
                    else reject(new Error('Verification failed or expired. Please try again.'));
                };
                const timer = window.setTimeout(() => finish(), 90000);
                cancel.current = () => finish();
                widget.current = window.turnstile!.render(container.current!, {
                    sitekey: data.site_key, action, execution: 'execute', appearance: 'execute',
                    size: container.current!.clientWidth < 300 ? 'compact' : 'flexible', theme: 'auto', retry: 'never', 'refresh-expired': 'manual',
                    callback: (token: string) => finish(token),
                    'error-callback': () => { finish(); return true; },
                    'expired-callback': () => finish(),
                    'timeout-callback': () => finish(),
                });
                window.turnstile!.execute(widget.current);
            });
        } finally {
            cancel.current?.();
            if (widget.current !== null) {
                window.turnstile?.remove(widget.current);
                widget.current = null;
            }
            if (mounted.current) setVerifying(false);
        }
    };
    return { container, execute, verifying };
}
