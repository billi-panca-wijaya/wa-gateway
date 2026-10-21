import { useRef } from 'react';
import type { ClipboardEvent, KeyboardEvent } from 'react';

interface OtpInputProps {
    value: string;
    onChange: (value: string) => void;
    length?: number;
    disabled?: boolean;
    invalid?: boolean;
}

/**
 * Six visual boxes. The value is always a string so leading zeros are preserved.
 * Supports typing, paste of the full code, backspace, arrow keys and the
 * one-time-code autocomplete hint (manual entry always works).
 */
export default function OtpInput({ value, onChange, length = 4, disabled, invalid }: OtpInputProps) {
    const refs = useRef<Array<HTMLInputElement | null>>([]);
    const digits = Array.from({ length }, (_, i) => (value[i] ?? '').trim());
    const emit = (next: string[]) => onChange(next.map((c) => c || ' ').join('').trimEnd());

    const focus = (i: number) => refs.current[Math.max(0, Math.min(length - 1, i))]?.focus();

    const setFrom = (start: number, input: string) => {
        const clean = input.replace(/\D/g, '');
        if (!clean) return;
        const next = digits.slice();
        for (let k = 0; k < clean.length && start + k < length; k++) next[start + k] = clean[k];
        emit(next);
        focus(start + clean.length);
    };

    const onKeyDown = (i: number, e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Backspace') {
            e.preventDefault();
            const next = digits.slice();
            if (next[i]) {
                next[i] = '';
            } else if (i > 0) {
                next[i - 1] = '';
                focus(i - 1);
            }
            emit(next);
        } else if (e.key === 'ArrowLeft') {
            e.preventDefault();
            focus(i - 1);
        } else if (e.key === 'ArrowRight') {
            e.preventDefault();
            focus(i + 1);
        }
    };

    const onPaste = (i: number, e: ClipboardEvent<HTMLInputElement>) => {
        e.preventDefault();
        setFrom(i === 0 || e.clipboardData.getData('text').replace(/\D/g, '').length >= length ? 0 : i, e.clipboardData.getData('text'));
    };

    return (
        <fieldset className="otp-boxes" disabled={disabled}>
            <legend className="sr-only">Kode OTP {length} digit</legend>
            {digits.map((d, i) => (
                <input
                    key={i}
                    ref={(el) => { refs.current[i] = el; }}
                    className={`otp-box${invalid ? ' error' : ''}`}
                    type="text"
                    inputMode="numeric"
                    pattern="[0-9]*"
                    autoComplete={i === 0 ? 'one-time-code' : 'off'}
                    maxLength={i === 0 ? length : 1}
                    aria-label={`Digit ${i + 1} dari ${length}`}
                    aria-invalid={invalid || undefined}
                    value={d}
                    autoFocus={i === 0}
                    onChange={(e) => {
                        const v = e.target.value;
                        if (v === '') {
                            const next = digits.slice();
                            next[i] = '';
                            emit(next);
                            return;
                        }
                        setFrom(i, d && v.length === 2 && v.startsWith(d) ? v.slice(1) : v);
                    }}
                    onKeyDown={(e) => onKeyDown(i, e)}
                    onPaste={(e) => onPaste(i, e)}
                    onFocus={(e) => e.target.select()}
                />
            ))}
        </fieldset>
    );
}
