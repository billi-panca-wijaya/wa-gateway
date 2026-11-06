import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import Axios from 'axios';
import axios from '../lib/axios';
import { useAuth } from '../contexts/AuthContext';
import { useWhatsAppChallenge } from '../hooks/useWhatsAppChallenge';
import OtpInput from './OtpInput';

type DeliveryStatus = 'queued' | 'sending' | 'submitted' | 'unknown' | 'failed';
interface Payload {
    challenge_id: string; masked_phone: string; delivery_status: DeliveryStatus;
    state: string; expires_in: number | null; resend_after: number;
}
interface Challenge extends Payload { expiresAtMs: number | null; resendAtMs: number }
const storageKey = 'whatsapp-login-challenge';
const newKey = () => crypto.randomUUID();
const csrf = () => axios.get('/sanctum/csrf-cookie', { timeout: 10000 });
function remember(id: string | null) {
    try { if (id) sessionStorage.setItem(storageKey, id); else sessionStorage.removeItem(storageKey); } catch { /* Storage may be disabled. */ }
}
function savedId(): string | null {
    try { return sessionStorage.getItem(storageKey); } catch { return null; }
}
function toChallenge(data: Payload): Challenge {
    return { ...data, expiresAtMs: data.expires_in === null ? null : Date.now() + data.expires_in * 1000, resendAtMs: Date.now() + data.resend_after * 1000 };
}
function failure(error: unknown): { message: string; retry?: number; code?: string } {
    if (Axios.isAxiosError(error)) {
        if (!error.response) return { message: 'Unable to connect. Check your connection and try again.' };
        if (error.response.status === 419) return { message: 'Session expired. Refresh the page and try again.' };
        const data = error.response.data as { message?: string; retry_after?: number; code?: string; errors?: Record<string, string[]> };
        return { message: (data.errors && Object.values(data.errors)[0]?.[0]) || data.message || 'Request failed. Please try again.', retry: data.retry_after, code: data.code };
    }
    return { message: error instanceof Error ? error.message : 'Request failed. Please try again.' };
}
function validPhone(value: string): boolean {
    if (!/^[+\d\s().-]+$/.test(value)) return false;
    const digits = value.replace(/\D/g, '');
    return digits.length >= 9 && digits.length <= 15;
}

export default function WhatsAppOtpForm({ onDone, onStageChange }: { onDone?: () => void; onStageChange?: (codeSent: boolean) => void }) {
    const { checkAuth } = useAuth();
    const navigate = useNavigate();
    const bot = useWhatsAppChallenge();
    const [phone, setPhone] = useState('');
    const [code, setCode] = useState('');
    const [challenge, setChallenge] = useState<Challenge | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [restoring, setRestoring] = useState(() => !!savedId());
    const [cooldownUntil, setCooldownUntil] = useState(0);
    const [now, setNow] = useState(Date.now());
    const operation = useRef(false);
    const idemKey = useRef(newKey());
    const lastSubmitted = useRef('');
    const alive = useRef(true);

    useEffect(() => {
        alive.current = true;
        const timer = setInterval(() => setNow(Date.now()), 500);
        return () => { alive.current = false; clearInterval(timer); };
    }, []);
    useEffect(() => {
        let cancelled = false;
        const id = savedId();
        if (!id) return;
        axios.get<Payload>(`/api/v1/auth/whatsapp/challenges/${id}`, { timeout: 10000 })
            .then(({ data }) => { if (!cancelled) setChallenge(toChallenge(data)); })
            .catch((err: unknown) => {
                if (cancelled) return;
                if (Axios.isAxiosError(err) && err.response?.status === 404) remember(null);
                else setError('Unable to restore your request. You can retry; the server cooldown still applies.');
            })
            .finally(() => { if (!cancelled) setRestoring(false); });
        return () => { cancelled = true; };
    }, []);
    const activeId = challenge?.challenge_id;
    const tracking = challenge?.delivery_status === 'queued' || challenge?.delivery_status === 'sending';
    useEffect(() => {
        if (!activeId || !tracking) return;
        let cancelled = false;
        let timer: ReturnType<typeof setTimeout>;
        const poll = async () => {
            try {
                const { data } = await axios.get<Payload>(`/api/v1/auth/whatsapp/challenges/${activeId}`, { timeout: 10000 });
                if (!cancelled) { setChallenge(toChallenge(data)); setError(''); }
            } catch {
                if (!cancelled) setError('Unable to refresh delivery status. Retrying automatically.');
            }
            if (!cancelled) timer = setTimeout(poll, 3000);
        };
        timer = setTimeout(poll, 1500);
        return () => { cancelled = true; clearTimeout(timer); };
    }, [activeId, tracking]);
    const hasChallenge = challenge !== null;
    useEffect(() => { onStageChange?.(hasChallenge); }, [hasChallenge, onStageChange]);

    const requestCode = async (resend: boolean) => {
        if (operation.current || restoring) return;
        if (!resend && !validPhone(phone)) { setError('Enter a valid WhatsApp number.'); return; }
        if (resend && !challenge) return;
        operation.current = true; setBusy(true); setError('');
        try {
            const token = await bot.execute(resend ? 'otp_resend' : 'otp_request');
            if (!alive.current) return;
            await csrf();
            const { data } = await axios.post<Payload>(`/api/v1/auth/whatsapp/${resend ? 'resend-otp' : 'request-otp'}`,
                { ...(resend ? { challenge_id: challenge!.challenge_id } : { phone }), challenge_token: token },
                { headers: { 'Idempotency-Key': idemKey.current }, timeout: 15000 });
            if (!alive.current) return;
            setChallenge(toChallenge(data)); remember(data.challenge_id); setCode(''); lastSubmitted.current = '';
            idemKey.current = newKey();
        } catch (err) {
            if (!alive.current) return;
            const result = failure(err); setError(result.message);
            if (result.retry) {
                const until = Date.now() + result.retry * 1000;
                setCooldownUntil(until);
                if (resend) setChallenge((old) => old ? { ...old, resendAtMs: until } : old);
            }
            // Preserve the key when the outcome may be uncertain (network error/timeout).
            if (Axios.isAxiosError(err) && err.response && err.response.status < 500) idemKey.current = newKey();
        } finally { operation.current = false; if (alive.current) setBusy(false); }
    };
    const completeLogin = async (next: string) => {
        await checkAuth(); remember(null); onDone?.(); navigate(next === '/onboarding' ? '/onboarding' : '/dashboard', { replace: true });
    };
    const usable = !!challenge && challenge.state === 'pending' && challenge.delivery_status !== 'failed' && challenge.expiresAtMs !== null && challenge.expiresAtMs > now;
    const verify = async (value: string) => {
        if (!challenge || operation.current || !usable || !/^\d{4}$/.test(value)) return;
        operation.current = true; lastSubmitted.current = value; setBusy(true); setError('');
        try {
            await csrf();
            const { data } = await axios.post<{ next: string }>('/api/v1/auth/whatsapp/verify-otp', { challenge_id: challenge.challenge_id, code: value }, { timeout: 15000 });
            await completeLogin(data.next);
        } catch (err) {
            if (Axios.isAxiosError(err) && !err.response) {
                try {
                    const { data } = await axios.get('/api/v1/auth/me', { timeout: 10000 });
                    if (data.data?.id) { await completeLogin(data.data.onboarding_required ? '/onboarding' : '/dashboard'); return; }
                } catch { /* Show the original recoverable error. */ }
            }
            const result = failure(err);
            const remaining = Axios.isAxiosError(err) ? err.response?.data?.attempts_remaining : undefined;
            setError(remaining !== undefined ? `${result.message} ${remaining} attempts remaining.` : result.message);
            setCode('');
            if (['OTP_ATTEMPTS_EXCEEDED', 'OTP_EXPIRED', 'CHALLENGE_SUPERSEDED'].includes(result.code ?? '')) {
                setChallenge((old) => old ? { ...old, state: 'locked' } : old);
            }
        } finally { operation.current = false; if (alive.current) setBusy(false); }
    };
    useEffect(() => {
        if (code.length < 4) lastSubmitted.current = '';
        if (/^\d{4}$/.test(code) && code !== lastSubmitted.current) void verify(code);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [code]);
    const changeNumber = () => {
        remember(null); setChallenge(null); setCode(''); setError(''); lastSubmitted.current = ''; idemKey.current = newKey();
    };
    const secs = (ms: number) => Math.max(0, Math.ceil((ms - now) / 1000));
    const fmt = (s: number) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
    const cooldown = secs(cooldownUntil);
    const message = challenge?.delivery_status === 'queued' ? 'Your code is queued for sending.'
        : challenge?.delivery_status === 'sending' ? 'Sending your code to WhatsApp…'
        : challenge?.delivery_status === 'failed' ? 'We couldn’t send your code. Please request a new code.'
        : challenge?.delivery_status === 'unknown' ? 'Delivery could not be confirmed. Check WhatsApp before requesting another code.'
        : 'Check WhatsApp for your code.';
    return <>
        {error && <div className="alert error" role="alert">{error}</div>}
        {restoring && <p role="status">Restoring your request…</p>}
        {!challenge ? <form onSubmit={(event: FormEvent) => { event.preventDefault(); void requestCode(false); }} className="auth-form" noValidate>
            <div className="form-group">
                <label htmlFor="whatsapp-phone">WhatsApp Phone Number</label>
                <input id="whatsapp-phone" type="tel" inputMode="tel" autoComplete="tel" placeholder="08xx or +62xx" value={phone}
                    disabled={busy || restoring} maxLength={32} required autoFocus
                    onChange={(event) => { setPhone(event.target.value); idemKey.current = newKey(); }} />
                <small className="hint">For international numbers, include + and the country code. Local numbers default to Indonesia (+62).</small>
            </div>
            <button type="submit" className="btn btn-primary btn-block" disabled={busy || restoring || !phone.trim() || cooldown > 0}>
                {bot.verifying ? 'Verifying…' : busy ? 'Requesting code…' : cooldown > 0 ? `Retry in ${fmt(cooldown)}` : 'Request OTP'}
            </button>
        </form> : <div className="auth-form">
            <p className="otp-target">WhatsApp: <strong>{challenge.masked_phone}</strong></p>
            <p className="hint" role="status">{message}</p>
            <p className="hint">Enter or paste your 4-digit code. Verification starts automatically.</p>
            <OtpInput value={code} onChange={setCode} length={4} disabled={busy || !usable} invalid={!!error} />
            <p className="otp-meta">{challenge.expiresAtMs !== null && (secs(challenge.expiresAtMs) > 0 ? `Code expires in ${fmt(secs(challenge.expiresAtMs))}` : 'Code has expired. Request a new code.')}</p>
            <button type="button" className="btn btn-primary btn-block" disabled={busy || !usable || !/^\d{4}$/.test(code)} onClick={() => void verify(code)}>{busy ? (bot.verifying ? 'Verifying…' : 'Processing…') : 'Verify & Sign In'}</button>
            <div className="otp-actions">
                <button type="button" className="text-link" onClick={() => void requestCode(true)} disabled={busy || secs(challenge.resendAtMs) > 0 || cooldown > 0}>{secs(challenge.resendAtMs) > 0 ? `Resend code in ${fmt(secs(challenge.resendAtMs))}` : 'Resend OTP'}</button>
                <button type="button" className="text-link" onClick={changeNumber} disabled={busy}>Change Number</button>
            </div>
        </div>}
        <div ref={bot.container} aria-label="Bot verification" style={{ marginTop: bot.verifying ? 16 : 0, minWidth: 0 }} />
    </>;
}
