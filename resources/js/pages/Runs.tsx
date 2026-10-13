import type { JSX } from 'preact';
import { Empty, Failure, Head, Loading, Stats, Status } from '../components/parts';
import { Select } from '../components/Select';
import { thousands } from '../format';
import { useResource } from '../hooks';
import { href, Link, navigate } from '../router';
import type { RunsResponse } from '../types';

const filterNames = ['model', 'status', 'mode'] as const;

export function RunsPage({ params }: { params: URLSearchParams }): JSX.Element {
    const query = { model: params.get('model') ?? '', status: params.get('status') ?? '', mode: params.get('mode') ?? '', page: Number(params.get('page') ?? 1) };
    const { data, error } = useResource<RunsResponse>('runs', query, 15_000);

    const head = (
        <Head index="02" label="Runs" title="Every sync," dim="in order.">
            The history of the syncs this application ran, newest first. Old runs are removed by <code class="mono">trace:prune</code>.
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

    if (!data.available || data.rows === undefined || data.stats === undefined || data.filters === undefined) {
        return (
            <>
                {head}
                <div class="card">
                    <Empty>The remote tables do not exist yet. Run the remote migration.</Empty>
                </div>
            </>
        );
    }

    const { filters, rows, stats } = data;
    const page = data.page ?? 1;
    const pages = data.pages ?? 1;
    const filter = (name: (typeof filterNames)[number], value: string): void => navigate(href('runs', { ...filters, [name]: value, page: undefined }));
    const pageLink = (target: number): string => href('runs', { ...filters, page: target });

    const select = (name: (typeof filterNames)[number], label: string, options: [string, string][]): JSX.Element => (
        <Select label={label} value={filters[name]} options={options} onChange={(value) => filter(name, value)} />
    );

    return (
        <>
            {head}
            {error !== null && <Failure message={error} />}
            <Stats
                items={[
                    { label: 'Runs', value: thousands(stats.all) },
                    { label: 'Today', value: thousands(stats.today) },
                    { label: 'Failed', value: thousands(stats.failed), hot: stats.failed > 0 },
                ]}
            />
            <div class="card">
                <div class="card-head">
                    <h2>Runs</h2>
                    <div class="filters">
                        {select('model', 'Model', [['', 'All models'], ...Object.entries(data.models ?? {})])}
                        {select('status', 'Status', [['', 'Any status'], ...(data.statuses ?? []).map((status): [string, string] => [status, status.charAt(0).toUpperCase() + status.slice(1)])])}
                        {select('mode', 'Mode', [['', 'Any mode'], ...(data.modes ?? []).map((mode): [string, string] => [mode, mode.charAt(0).toUpperCase() + mode.slice(1)])])}
                    </div>
                </div>
                {rows.length === 0 ? (
                    <Empty>No run matches.</Empty>
                ) : (
                    <>
                        <div class="scroll">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Started</th>
                                        <th>Model</th>
                                        <th>Mode</th>
                                        <th>Status</th>
                                        <th class="num">Duration</th>
                                        <th>Result</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((run, index) => (
                                        <tr key={`${run.at}-${index}`}>
                                            <td title={run.at}>{run.when}</td>
                                            <td>
                                                <span class="nm sm">{run.model}</span>
                                                <span class="slug">{run.feed}</span>
                                            </td>
                                            <td>{run.mode}</td>
                                            <td>
                                                <Status tone={run.tone}>{run.status}</Status>
                                            </td>
                                            <td class="num">{run.duration}</td>
                                            <td class={`result ${run.tone === 'bad' ? 'bad' : ''}`}>{run.summary}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div class="pager">
                            <span class="eyebrow">
                                {thousands(data.total ?? 0)} runs · page {page} / {pages}
                            </span>
                            <span class="pages">
                                {page > 1 && (
                                    <Link class="eyebrow" to={pageLink(page - 1)}>
                                        ← Newer
                                    </Link>
                                )}
                                {page < pages && (
                                    <Link class="eyebrow" to={pageLink(page + 1)}>
                                        Older →
                                    </Link>
                                )}
                            </span>
                        </div>
                    </>
                )}
            </div>
        </>
    );
}
