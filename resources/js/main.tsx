import type { JSX } from 'preact';
import { render } from 'preact';
import './style.css';
import { Layout } from './components/Layout';
import { HealthPage } from './pages/Health';
import { RemotePage } from './pages/Remote';
import { RunsPage } from './pages/Runs';
import { SourcePage } from './pages/Source';
import { useLocation } from './router';
import { ToastProvider } from './toast';

function App(): JSX.Element {
    const { page, params } = useLocation();

    return (
        <ToastProvider>
            <Layout page={page}>
                {page === 'remote' && <RemotePage params={params} />}
                {page === 'runs' && <RunsPage params={params} />}
                {page === 'source' && <SourcePage params={params} />}
                {page === 'health' && <HealthPage params={params} />}
            </Layout>
        </ToastProvider>
    );
}

render(<App />, document.getElementById('app') as HTMLElement);
