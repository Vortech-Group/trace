import type { JSX } from 'preact';
import { Failure, Head, Loading, Stats } from '../components/parts';
import { useResource } from '../hooks';
import { href, Link } from '../router';
import type { HealthResponse } from '../types';

export function HealthPage({ params }: { params: URLSearchParams }): JSX.Element {
    const live = params.get('live') === '1';
    // the checks (and the request to the source) run on demand, not on a timer
    const { data, error } = useResource<HealthResponse>('health', { live }, 0);

    const head = (
        <Head index="04" label="Health" title="Everything checked," dim="nothing assumed.">
            The same checks as <code class="mono">php artisan trace:doctor</code>. The source is only contacted when you ask for it.
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

    return (
        <>
            {head}
            <Stats
                items={[
                    { label: 'Checks', value: data.checks.length },
                    { label: 'Failed', value: data.failed, hot: data.failed > 0 },
                ]}
            />
            <div class="card">
                <div class="card-head">
                    <h2>Checks</h2>
                    {live ? (
                        <Link class="eyebrow" to={href('health')}>
                            Without contacting the source
                        </Link>
                    ) : (
                        <Link class="eyebrow" to={href('health', { live: 1 })}>
                            Also contact the source →
                        </Link>
                    )}
                </div>
                <div class="scroll">
                    <table class="checks">
                        <tbody>
                            {data.checks.map((check, index) => (
                                <tr key={index}>
                                    <td>
                                        <span class={`status ${check.status === 'failed' ? 'bad' : check.status === 'skipped' ? 'idle' : ''}`}>{check.label}</span>
                                    </td>
                                    <td class="d">{check.detail}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
