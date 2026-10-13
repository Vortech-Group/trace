import type { JSX } from 'preact';
import { useState } from 'preact/hooks';
import { Icon } from '../components/Icon';
import { Empty, Failure, Head, Loading, Stats, Status } from '../components/parts';
import { thousands } from '../format';
import { useAction, useResource } from '../hooks';
import { href, Link, navigate } from '../router';
import type { RemoteResponse } from '../types';

export function RemotePage({ params }: { params: URLSearchParams }): JSX.Element {
    const { data, error, reload } = useResource<RemoteResponse>('remote');
    const { busy, run } = useAction(reload);
    const [inline, setInline] = useState(false);
    const [dry, setDry] = useState(false);

    const head = (
        <Head index="01" label="Remote" title="Every model in sync," dim="or exactly why not.">
            What this application receives from other installations, and where a sync needs attention.
        </Head>
    );

    if (data === null) {
        return (
            <>
                {head}
                {error === null ? <Loading /> : <Failure message={error} />}
            </>
        );
    }

    if (data.models.length === 0) {
        return (
            <>
                {head}
                <div class="card">
                    <Empty>
                        No model is synced here yet. Declare one with <code>#[Sync]</code> and run <code>php artisan trace:sync</code>.
                    </Empty>
                </div>
            </>
        );
    }

    const selected = data.models.find((model) => model.class === params.get('model')) ?? data.models[0];
    const start = (action: 'sync' | 'reconcile'): Promise<void> => run(action, `remote/${action}`, { model: selected.class, inline: inline || dry, dry });

    return (
        <>
            {head}
            {error !== null && <Failure message={error} />}
            <Stats
                items={[
                    { label: 'Models', value: data.stats.models },
                    { label: 'In sync', value: data.stats.synced },
                    { label: 'Need attention', value: data.stats.attention, hot: data.stats.attention > 0 },
                    { label: 'Records linked', value: thousands(data.stats.links) },
                ]}
            />
            <div class="panes">
                <div class="card">
                    <div class="card-head">
                        <h2>Synced models</h2>
                        <span class="eyebrow">Select one</span>
                    </div>
                    <div class="scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Model</th>
                                    <th>Status</th>
                                    <th class="num">Links</th>
                                    <th>Last success</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.models.map((model) => (
                                    <tr
                                        key={model.class}
                                        class="sel"
                                        aria-selected={model.class === selected.class}
                                        onClick={(event) => {
                                            if (!(event.target as HTMLElement).closest('a')) {
                                                navigate(href('remote', { model: model.class }));
                                            }
                                        }}
                                    >
                                        <td>
                                            <Link to={href('remote', { model: model.class })} class="nm">
                                                {model.name}
                                            </Link>
                                            <span class="slug">{model.feed}</span>
                                        </td>
                                        <td>
                                            <Status tone={model.tone}>{model.status}</Status>
                                        </td>
                                        <td class="num">{thousands(model.links)}</td>
                                        <td>{model.lastSuccess ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card detail">
                    <div class="detail-head">
                        <div>
                            <h2>{selected.name}</h2>
                            <span class="slug">{selected.feed}</span>
                        </div>
                        <Status tone={selected.tone}>{selected.status}</Status>
                    </div>
                    {selected.note !== null && <p class={`note ${selected.tone === 'bad' ? '' : 'warn'}`}>{selected.note}</p>}
                    <dl class="kvs">
                        <div>
                            <dt class="eyebrow">Cursor</dt>
                            <dd class="mono">{selected.cursor}</dd>
                        </div>
                        <div>
                            <dt class="eyebrow">Schema</dt>
                            <dd class="mono">{selected.schema}</dd>
                        </div>
                        <div>
                            <dt class="eyebrow">Instance</dt>
                            <dd class="mono">{selected.instance}</dd>
                        </div>
                    </dl>
                    {data.canManage && (
                        <div class="controls">
                            <div class="actions">
                                <button class="act primary" type="button" disabled={busy !== null} onClick={() => start('sync')}>
                                    <Icon name="refresh" />
                                    <span class="t">{busy === 'sync' ? 'Syncing…' : 'Sync now'}</span>
                                    <span class="ar" aria-hidden="true">
                                        ↗
                                    </span>
                                </button>
                                <span class="act-div" aria-hidden="true" />
                                <button class="act" type="button" disabled={busy !== null} onClick={() => start('reconcile')}>
                                    <Icon name="stack" />
                                    <span class="t">{busy === 'reconcile' ? 'Reconciling…' : 'Reconcile'}</span>
                                    <span class="ar" aria-hidden="true">
                                        →
                                    </span>
                                </button>
                            </div>
                            <label class="inline">
                                <input type="checkbox" checked={inline || dry} disabled={dry} onChange={(event) => setInline(event.currentTarget.checked)} />
                                <span>Run now, without the queue</span>
                            </label>
                            <label class="inline">
                                <input type="checkbox" checked={dry} onChange={(event) => setDry(event.currentTarget.checked)} />
                                <span>Dry run: show what would change, write nothing</span>
                            </label>
                        </div>
                    )}
                    <div>
                        <div class="detail-head" style="margin-bottom:12px">
                            <span class="eyebrow">Recent runs</span>
                            <Link class="eyebrow" to={href('runs', { model: selected.type })}>
                                All runs →
                            </Link>
                        </div>
                        {selected.runs.length === 0 ? (
                            <p class="slug" style="margin-top:0">
                                No run recorded yet.
                            </p>
                        ) : (
                            <ul class="runs">
                                {selected.runs.map((item, index) => (
                                    <li class="run" key={index}>
                                        <span class="a">{item.mode}</span>
                                        <Status tone={item.tone}>{item.status}</Status>
                                        <span class="m">{item.summary}</span>
                                        <span class="w">
                                            {item.when} · {item.duration}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
