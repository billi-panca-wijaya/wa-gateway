import { useEffect } from 'react';

/** Locks scrolling for an open overlay and closes it with Escape. */
export function useOverlay(open: boolean, close: () => void) {
    useEffect(() => {
        if (!open) return;
        document.body.classList.add('no-scroll');
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') close();
        };
        window.addEventListener('keydown', onKey);
        return () => {
            document.body.classList.remove('no-scroll');
            window.removeEventListener('keydown', onKey);
        };
    }, [open, close]);
}
