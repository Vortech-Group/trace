import { useCallback, useEffect, useRef, useState } from 'preact/hooks';
import { get, post } from './api';
import { useToast } from './toast';

type Params = Record<string, string | number | boolean | undefined | null>;

/**
 * Loads a JSON resource and keeps it fresh: it reloads when the parameters change and, while the tab
 * is visible, every `interval` milliseconds. The previous data stays on screen during a reload.
 */
export function useResource<T>(path: string, params: Params = {}, interval = 10_000): { data: T | null; error: string | null; reload: () => void } {
    const [data, setData] = useState<T | null>(null);
    const [error, setError] = useState<string | null>(null);
    const latest = useRef(0);
    const key = path + JSON.stringify(params);

    const load = useCallback(async (): Promise<void> => {
        const id = ++latest.current;

        try {
            const result = await get<T>(path, params);

            if (id === latest.current) {
                setData(result);
                setError(null);
            }
        } catch (exception) {
            if (id === latest.current) {
                setError(exception instanceof Error ? exception.message : 'The request failed.');
            }
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key]);

    useEffect(() => {
        void load();

        if (interval <= 0) {
            return undefined;
        }

        const timer = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                void load();
            }
        }, interval);

        return () => window.clearInterval(timer);
    }, [load, interval]);

    return { data, error, reload: () => void load() };
}

/** Starts an action on the server, reports its outcome as a toast and refreshes the page data. */
export function useAction(reload: () => void): { busy: string | null; run: (name: string, path: string, body?: Record<string, string | boolean>) => Promise<void> } {
    const [busy, setBusy] = useState<string | null>(null);
    const toast = useToast();

    const run = useCallback(
        async (name: string, path: string, body: Record<string, string | boolean> = {}): Promise<void> => {
            setBusy(name);

            try {
                const result = await post(path, body);

                toast(result.message, result.ok ? '' : 'error');
            } catch (exception) {
                toast(exception instanceof Error ? exception.message : 'The request failed.', 'error');
            } finally {
                setBusy(null);
                reload();
            }
        },
        [reload, toast],
    );

    return { busy, run };
}
