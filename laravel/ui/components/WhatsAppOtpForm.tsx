import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import Axios from 'axios';
import axios from '../lib/axios';
import { useAuth } from '../contexts/AuthContext';
import OtpInput from './OtpInput';

interface Challenge {
    challenge_id: string;
    masked_phone: string;
    delivery_status: 'submitted' | 'unknown' | 'failed';
    expiresAtMs: number;
    resendAtMs: number;
}

const newKey = () =>
    typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(36).slice(2)}`;

function errorMessage(err: unknown): { message: string; retryAfter?: number; code?: string } {
    if (Axios.isAxiosError(err)) {
        const data = err.response?.data as { message?: string; code?: string; retry_after?: number; errors?: Record<string, string[]> } | undefined;
        if (!err.response) return { message: 'Tidak dapat terhubung ke server. Periksa koneksi Anda.' };
        if (err.response.status === 419) return { message: 'Sesi halaman kedaluwarsa. Muat ulang halaman.' };
        const firstField = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
        return { message: firstField ?? data?.message ?? 'Terjadi kesalahan. Coba lagi.', retryAfter: data?.retry_after, code: data?.code };
    }
    return { message: 'Terjadi kesalahan. Coba lagi.' };
}

/** Converts server timestamps to local deadlines relative to receipt, avoiding client clock skew. */
function toChallenge(data: any): Challenge {
    const now = Date.now();
    const resendAtMs = now + data.resend_after * 1000;
    const ttlFromResend = Date.parse(data.expires_at) - Date.parse(data.resend_available_at);
    return {
        challenge_id: data.challenge_id,
        masked_phone: data.masked_phone,
        delivery_status: data.delivery_status,
        resendAtMs,
        expiresAtMs: resendAtMs + ttlFromResend,
    };
}


/** WhatsApp OTP login form (phone -> 6-digit code). Used by the login popup and /login/whatsapp. */
export default function WhatsAppOtpForm({ onDone, onStageChange }: { onDone?: () => void; onStageChange?: (codeSent: boolean) => void }) {
    const { checkAuth } = useAuth();
    const navigate = useNavigate();
    const [phone, setPhone] = useState('');
    const [code, setCode] = useState('');
    const [challenge, setChallenge] = useState<Challenge | null>(null);
    const [error, setError] = useState('');
    const [info, setInfo] = useState('');
    const [busy, setBusy] = useState(false);
    const [cooldownUntil, setCooldownUntil] = useState(0);
    const [now, setNow] = useState(Date.now());
    const idemKey = useRef(newKey());
    const lastSubmitted = useRef('');

    useEffect(() => {
        const t = setInterval(() => setNow(Date.now()), 500);
        return () => clearInterval(t);
    }, []);

    const csrf = () => axios.get('/sanctum/csrf-cookie');

    const requestOtp = async (e: FormEvent) => {
        e.preventDefault();
        if (busy) return;
        setBusy(true);
        setError('');
        setInfo('');
        try {
            await csrf();
            const res = await axios.post('/api/v1/auth/whatsapp/request-otp', { phone }, { headers: { 'Idempotency-Key': idemKey.current } });
            setChallenge(toChallenge(res.data));
            setCode('');
            idemKey.current = newKey();
        } catch (err) {
            const m = errorMessage(err);
            setError(m.message);
            if (m.retryAfter) setCooldownUntil(Date.now() + m.retryAfter * 1000);
            if (Axios.isAxiosError(err) && err.response) idemKey.current = newKey();
        } finally {
            setBusy(false);
        }
    };

    const resend = async () => {
        if (!challenge || busy) return;
        setBusy(true);
        setError('');
        setInfo('');
        try {
            await csrf();
            const res = await axios.post('/api/v1/auth/whatsapp/resend-otp', { challenge_id: challenge.challenge_id }, { headers: { 'Idempotency-Key': idemKey.current } });
            setChallenge(toChallenge(res.data));
            setCode('');
            setInfo('Kode baru telah dikirim. Hanya kode terbaru yang berlaku.');
            idemKey.current = newKey();
        } catch (err) {
            const m = errorMessage(err);
            setError(m.message);
            if (m.retryAfter) setChallenge({ ...challenge, resendAtMs: Date.now() + m.retryAfter * 1000 });
            if (Axios.isAxiosError(err) && err.response) idemKey.current = newKey();
        } finally {
            setBusy(false);
        }
    };

    const verify = async (value: string) => {
        if (!challenge || busy || !/^\d{4}$/.test(value)) return;
        lastSubmitted.current = value;
        setBusy(true);
        setError('');
        try {
            await csrf();
            const res = await axios.post('/api/v1/auth/whatsapp/verify-otp', { challenge_id: challenge.challenge_id, code: value });
            await checkAuth();
            onDone?.();
            navigate(res.data.next === '/onboarding' ? '/onboarding' : '/dashboard', { replace: true });
        } catch (err) {
            const m = errorMessage(err);
            const remaining = Axios.isAxiosError(err) ? (err.response?.data as any)?.attempts_remaining : undefined;
            setError(remaining !== undefined ? `${m.message} Sisa percobaan: ${remaining}.` : m.message);
            setCode('');
        } finally {
            setBusy(false);
        }
    };

    useEffect(() => {
        if (/^\d{4}$/.test(code) && code !== lastSubmitted.current) verify(code);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [code]);


    useEffect(() => {
        onStageChange?.(challenge !== null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [challenge !== null]);

    const changeNumber = () => {
        setChallenge(null);
        setCode('');
        setError('');
        setInfo('');
        lastSubmitted.current = '';
        idemKey.current = newKey();
    };

    const secs = (ms: number) => Math.max(0, Math.ceil((ms - now) / 1000));
    const fmt = (s: number) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
    const cooldown = secs(cooldownUntil);


    return (
        <>
        {error && <div className="alert error" role="alert">{error}</div>}
        {info && <div className="alert success" role="status">{info}</div>}

        {!challenge ? (
            <form onSubmit={requestOtp} className="auth-form" noValidate>
                <div className="form-group">
                    <label htmlFor="phone">Nomor WhatsApp</label>
                    <input
                        id="phone"
                        type="tel"
                        inputMode="tel"
                        autoComplete="tel"
                        placeholder="08xx atau +62xx"
                        value={phone}
                        onChange={(e) => { setPhone(e.target.value); idemKey.current = newKey(); }}
                        required
                        autoFocus
                    />
                    <small className="hint">Nomor tanpa kode negara dianggap nomor Indonesia. Untuk negara lain awali dengan +kode negara.</small>
                </div>
                <button type="submit" className="btn btn-primary btn-block" disabled={busy || !phone.trim() || cooldown > 0}>
                    {busy ? <span className="spinner"></span> : cooldown > 0 ? `Coba lagi dalam ${fmt(cooldown)}` : 'Kirim kode OTP'}
                </button>
            </form>
        ) : (
            <div className="auth-form">
                <p className="otp-target">
                    Kode dikirim ke <strong>{challenge.masked_phone}</strong>
                    {challenge.delivery_status === 'unknown' && (
                        <><br /><small>Status pengiriman belum pasti. Jika kode tidak masuk, kirim ulang setelah hitungan selesai.</small></>
                    )}
                </p>
                <p className="hint">Buka aplikasi WhatsApp di ponsel Anda, baca pesan berisi kode, lalu ketik atau tempel di sini.</p>
                <OtpInput value={code} onChange={setCode} length={4} disabled={busy} invalid={!!error} />
                <p className="otp-meta" aria-live="polite">
                    {secs(challenge.expiresAtMs) > 0 ? `Kode berlaku ${fmt(secs(challenge.expiresAtMs))}` : 'Kode sudah kedaluwarsa. Minta kode baru.'}
                </p>
                <button type="button" className="btn btn-primary btn-block" disabled={busy || !/^\d{4}$/.test(code)} onClick={() => verify(code)}>
                    {busy ? <span className="spinner"></span> : 'Verifikasi'}
                </button>
                <div className="otp-actions">
                    <button type="button" className="text-link" onClick={resend} disabled={busy || secs(challenge.resendAtMs) > 0}>
                        {secs(challenge.resendAtMs) > 0 ? `Kirim ulang dalam ${fmt(secs(challenge.resendAtMs))}` : 'Kirim ulang kode'}
                    </button>
                    <button type="button" className="text-link" onClick={changeNumber} disabled={busy}>Ubah nomor</button>
                </div>
            </div>
        )}
        </>
    );
}
