import { boot } from './boot';
import type { ActionResponse } from './types';

export class ApiError extends Error {
    constructor(
        message: string,
        readonly status: number,
    ) {
        super(message);
    }
}

type Params = Record<string, string | number | boolean | undefined | null>;

function query(params: Params): string {
    const search = new URLSearchParams();

    for (const [key, value] of Object.entries(params)) {
        if (value !== undefined && value !== null && value !== '' && value !== false) {
            search.set(key, value === true ? '1' : String(value));
        }
    }

    const text = search.toString();

    return text === '' ? '' : `?${text}`;
}

async function request<T>(path: string, init: RequestInit): Promise<T> {
    const response = await fetch(`${boot.api}/${path}`, {
        credentials: 'same-origin',
        ...init,
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': boot.csrf, ...init.headers },
    });

    if (response.status === 419) {
        throw new ApiError('The session expired: reload the page.', 419);
    }

    if (!response.ok) {
        throw new ApiError(response.status === 404 ? 'Not found, or not allowed.' : `The request failed (${response.status}).`, response.status);
    }

    return (await response.json()) as T;
}

export function get<T>(path: string, params: Params = {}, signal?: AbortSignal): Promise<T> {
    return request<T>(`${path}${query(params)}`, { method: 'GET', signal });
}

export function post(path: string, body: Record<string, string | boolean> = {}): Promise<ActionResponse> {
    return request<ActionResponse>(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
}
