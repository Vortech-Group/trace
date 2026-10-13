import type { JSX } from 'preact';
import { Icon } from '../components/Icon';
import { CopyCommand, Empty, Failure, Head, Loading, Stats } from '../components/parts';
import { chart, thousands } from '../format';
import { useAction, useResource } from '../hooks';
import { href, Link } from '../router';
import type { SourceResponse } from '../types';

export function SourcePage({ params }: { params: URLSearchParams }): JSX.Element {
    const { data, error, reload } = useResource<SourceResponse>('source', { feed: params.get('feed') });
    const { busy, run } = useAction(reload);

    const head = (
        <Head index="03" label="Source" title="Feeds you publish," dim="and how they are captured.">
            Capture mode, log size and trigger state of every feed other installations can pull from.
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

    if (data.feeds.length === 0) {
        return (
            <>
                {head}
                <div class="card">
                    <Empty>
                        No feed is published here yet. Register one with <code>Route::source()</code>.
                    </Empty>
                </div>
            </>
        );
    }

    const selected = data.feeds.find((feed) => feed.feed === data.selected) ?? data.feeds[0];
    const geometry = chart(data.hours);

    return (
        <>
            {head}
            {error !== null && <Failure message={error} />}
            <Stats
                items={[
                    { label: 'Feeds', value: data.stats.feeds },
                    { label: 'Log rows', value: thousands(data.stats.logRows) },
                    { label: 'Changes today', value: thousands(data.stats.today) },
                    { label: 'Need attention', value: data.stats.attention, hot: data.stats.attention > 0 },
                ]}
            />

            {data.feeds
                .filter((feed) => feed.issue !== null)
                .map((feed) => (
                    <div class="callout" key={feed.feed}>
                        <Icon name="warn" />
                        <span>
                            <b style="color:var(--ink)">{feed.name}:</b> {feed.issue}
                        </span>
                    </div>
                ))}

            <div class="oss-grid">
                {data.feeds.map((feed, index) => (
                    <article class="card fcard" key={feed.feed} aria-current={feed.feed === selected.feed ? 'true' : undefined}>
                        <Link class="feed-link" to={href('source', { feed: feed.feed })} aria-label={`Show ${feed.name}`} />
                        <div class="fcard-head">
                            <div>
                                <h3>{feed.name}</h3>
                                <span class="slug">{feed.feed}</span>
                            </div>
                            <div class="fcard-top">
                                <span class="cat">{feed.capture}</span>
                                <span class="n">{String(index + 1).padStart(2, '0')}</span>
                            </div>
                        </div>
                        <p>{feed.info}</p>
                        <div class={`status ${feed.tone}`}>{feed.status}</div>
                        <CopyCommand command={feed.command} />
                    </article>
                ))}
            </div>

            <div class="panes">
                <div class="card">
                    <div class="card-head">
                        <h2>Changes per hour</h2>
                        <span class="eyebrow">{selected.name} · last 24 h</span>
                    </div>
                    <div class="chart">
                        <svg viewBox={`0 0 ${geometry.width} ${geometry.height}`} role="img" aria-label={`Changes per hour over the last 24 hours, peaking at ${geometry.peak}`}>
                            {geometry.ticks.map((tick) => (
                                <g key={tick.value}>
                                    <line x1={geometry.left} x2={geometry.width} y1={tick.y} y2={tick.y} stroke="rgba(255,255,255,.06)" />
                                    <text x="0" y={tick.y + 3}>
                                        {tick.value}
                                    </text>
                                </g>
                            ))}
                            {geometry.bars.map((bar) => (
                                <rect key={bar.x} x={bar.x} y={bar.y} width={bar.width} height={bar.height} rx="2" fill={bar.peak ? '#CF000F' : 'rgba(244,244,242,.22)'}>
                                    <title>{bar.title}</title>
                                </rect>
                            ))}
                            {geometry.labels.map((label) => (
                                <text key={label.x} x={label.x} y={geometry.base + 18}>
                                    {label.text}
                                </text>
                            ))}
                        </svg>
                    </div>
                </div>
                <div class="card">
                    <div class="card-head">
                        <h2>Retention</h2>
                        <span class="eyebrow">Pruning</span>
                    </div>
                    <dl class="ret">
                        <div>
                            <dt>Keeping</dt>
                            <dd>{data.retention} days</dd>
                        </div>
                        <div>
                            <dt>Last prune</dt>
                            <dd>{data.prune === null ? 'never run' : `${data.prune.at}, ${thousands(data.prune.changes)} rows`}</dd>
                        </div>
                        <div>
                            <dt>Oldest cursor served</dt>
                            <dd class="mono">version {thousands(selected.prunedVersion)}</dd>
                        </div>
                        <div>
                            <dt>Instance</dt>
                            <dd class="mono">{data.instance}</dd>
                        </div>
                    </dl>
                </div>
            </div>

            <div class="card detail">
                <div class="eyebrow">Maintenance</div>
                <div class="actions">
                    {data.canManage && (
                        <>
                            <button class="act primary" type="button" disabled={busy !== null} onClick={() => run('prune', 'source/prune')}>
                                <Icon name="stack" />
                                <span class="t">{busy === 'prune' ? 'Pruning…' : 'Prune now'}</span>
                                <span class="ar" aria-hidden="true">
                                    ↗
                                </span>
                            </button>
                            {data.hasTriggers && (
                                <>
                                    <span class="act-div" aria-hidden="true" />
                                    <button class="act" type="button" disabled={busy !== null} onClick={() => run('triggers', 'source/triggers')}>
                                        <Icon name="bolt" />
                                        <span class="t">{busy === 'triggers' ? 'Installing…' : 'Install triggers'}</span>
                                        <span class="ar" aria-hidden="true">
                                            →
                                        </span>
                                    </button>
                                </>
                            )}
                            <span class="act-div" aria-hidden="true" />
                        </>
                    )}
                    <Link class={`act ${data.canManage ? '' : 'primary'}`} to={href('health', { live: 1 })}>
                        <Icon name="check" />
                        <span class="t">Run doctor</span>
                        <span class="ar" aria-hidden="true">
                            →
                        </span>
                    </Link>
                </div>
            </div>
        </>
    );
}
