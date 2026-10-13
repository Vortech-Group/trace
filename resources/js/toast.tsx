import type { ComponentChildren, JSX } from 'preact';
import { createContext } from 'preact';
import { useCallback, useContext, useEffect, useRef, useState } from 'preact/hooks';
import { Icon } from './components/Icon';

type Show = (message: string, tone?: '' | 'error') => void;

const Context = createContext<Show>(() => undefined);

export function useToast(): Show {
    return useContext(Context);
}

export function ToastProvider({ children }: { children: ComponentChildren }): JSX.Element {
    const [toast, setToast] = useState<{ message: string; tone: '' | 'error'; id: number } | null>(null);
    const timer = useRef<number>();

    const show = useCallback<Show>((message, tone = '') => {
        window.clearTimeout(timer.current);
        setToast({ message, tone, id: Date.now() });
        // a long message needs time to be read
        timer.current = window.setTimeout(() => setToast(null), Math.max(3600, message.length * 70));
    }, []);

    useEffect(() => () => window.clearTimeout(timer.current), []);

    return (
        <Context.Provider value={show}>
            {children}
            {toast && (
                <div class={`toast ${toast.tone === 'error' ? 'err' : ''}`} role="status" key={toast.id}>
                    <Icon name="check" />
                    {toast.message}
                </div>
            )}
        </Context.Provider>
    );
}
