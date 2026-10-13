import type { ComponentChildren, JSX } from 'preact';
import { useEffect, useState } from 'preact/hooks';
import { boot } from './boot';

export type Page = 'remote' | 'runs' | 'source' | 'health';

export interface Location {
    page: Page;
    params: URLSearchParams;
}

const pages: Page[] = ['remote', 'runs', 'source', 'health'];

/** The page and the query of a URL, relative to the dashboard's base path. */
export function parse(url: string, base: string, home: Page): Location {
    const parsed = new URL(url, 'http://trace.local');
    const rest = parsed.pathname.startsWith(base) ? parsed.pathname.slice(base.length).replace(/^\/+|\/+$/g, '') : '';
    const page = pages.find((candidate) => candidate === rest) ?? home;

    return { page, params: parsed.searchParams };
}

export function href(page: Page, params: Record<string, string | number | undefined | null> = {}): string {
    const query = new URLSearchParams();

    for (const [key, value] of Object.entries(params)) {
        if (value !== undefined && value !== null && value !== '' && value !== 0) {
            query.set(key, String(value));
        }
    }

    const search = query.toString();

    return `${boot.base}/${page}${search === '' ? '' : `?${search}`}`;
}

const listeners = new Set<() => void>();

export function navigate(to: string, replace = false): void {
    if (to === window.location.pathname + window.location.search) {
        return;
    }

    window.history[replace ? 'replaceState' : 'pushState']({}, '', to);
    listeners.forEach((listener) => listener());
}

export function useLocation(): Location {
    const read = (): Location => parse(window.location.pathname + window.location.search, boot.base, boot.home);
    const [location, setLocation] = useState<Location>(read);

    useEffect(() => {
        const update = (): void => setLocation(read());

        listeners.add(update);
        window.addEventListener('popstate', update);

        // the bare base path opens the side the application uses
        if (window.location.pathname.replace(/\/+$/, '') === boot.base) {
            navigate(href(boot.home), true);
        }

        return () => {
            listeners.delete(update);
            window.removeEventListener('popstate', update);
        };
    }, []);

    return location;
}

interface LinkProps extends Omit<JSX.HTMLAttributes<HTMLAnchorElement>, 'href'> {
    to: string;
    children?: ComponentChildren;
}

/** An ordinary link that the application handles itself, unless the user wants a new tab. */
export function Link({ to, children, onClick, ...rest }: LinkProps): JSX.Element {
    const handle = (event: JSX.TargetedMouseEvent<HTMLAnchorElement>): void => {
        onClick?.(event);

        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        event.preventDefault();
        navigate(to);
    };

    return (
        <a href={to} onClick={handle} {...rest}>
            {children}
        </a>
    );
}
