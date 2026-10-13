export interface Boot {
    base: string;
    api: string;
    csrf: string;
    app: string;
    env: string;
    home: 'remote' | 'source';
}

export type Tone = '' | 'warn' | 'bad';

export interface Run {
    mode: string;
    tone: Tone;
    status: string;
    when: string;
    duration: string;
    summary: string;
    model: string;
    feed: string;
    at: string;
}

export interface RemoteModel {
    class: string;
    type: string;
    name: string;
    feed: string;
    tone: Tone;
    status: string;
    needsAttention: boolean;
    synced: boolean;
    links: number;
    lastSuccess: string | null;
    cursor: string;
    schema: string;
    instance: string;
    note: string | null;
    runs: Run[];
}

export interface RemoteResponse {
    models: RemoteModel[];
    stats: { models: number; synced: number; attention: number; links: number };
    canManage: boolean;
}

export interface RunsResponse {
    available: boolean;
    rows?: Run[];
    total?: number;
    pages?: number;
    page?: number;
    models?: Record<string, string>;
    statuses?: string[];
    modes?: string[];
    filters?: { model: string; status: string; mode: string };
    stats?: { all: number; today: number; failed: number };
}

export interface Feed {
    feed: string;
    model: string;
    name: string;
    capture: string;
    info: string;
    tone: Tone;
    status: string;
    issue: string | null;
    command: string;
    logRows: number;
    today: number;
    prunedVersion: number;
    database: boolean;
}

export interface SourceResponse {
    feeds: Feed[];
    selected: string | null;
    hours: { hour: string; count: number }[];
    stats: { feeds: number; logRows: number; today: number; attention: number };
    prune: { at: string; changes: number; runs: number } | null;
    instance: string;
    retention: number;
    canManage: boolean;
    hasTriggers: boolean;
}

export interface HealthCheck {
    label: string;
    status: 'passed' | 'failed' | 'skipped';
    detail: string;
}

export interface HealthResponse {
    checks: HealthCheck[];
    failed: number;
    live: boolean;
}

export interface ActionResponse {
    ok: boolean;
    message: string;
}
