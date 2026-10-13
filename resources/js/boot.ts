import type { Boot } from './types';

declare global {
    interface Window {
        __TRACE__: Boot;
    }
}

/** What the server hands over with the page: base path, CSRF token, environment. */
export const boot: Boot = (typeof window === 'undefined' ? undefined : window.__TRACE__) ?? { base: '/trace', api: '/trace/api', csrf: '', app: 'Laravel', env: 'local', home: 'remote' };
