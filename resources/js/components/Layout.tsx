import type { ComponentChildren, JSX } from 'preact';
import { boot } from '../boot';
import { href, Link, type Page } from '../router';

const tabs: { page: Page; label: string }[] = [
    { page: 'remote', label: 'Remote' },
    { page: 'runs', label: 'Runs' },
    { page: 'source', label: 'Source' },
    { page: 'health', label: 'Health' },
];

export function Layout({ page, children }: { page: Page; children: ComponentChildren }): JSX.Element {
    return (
        <>
            <header>
                <div class="bar">
                    <div class="brand">
                        <span class="logo" role="img" aria-label="Vortech" />
                        <span class="nm">
                            Vortech<i>.</i>
                        </span>
                        <span class="eyebrow">
                            Laravel <b>Trace</b>
                        </span>
                    </div>
                    <nav aria-label="Trace">
                        {tabs.map((tab) => (
                            <Link key={tab.page} to={href(tab.page)} aria-current={tab.page === page ? 'page' : undefined}>
                                {tab.label}
                            </Link>
                        ))}
                    </nav>
                    <div class="env">
                        <span class="kicker">
                            <span class="dot" />
                        </span>
                        <span class="eyebrow">
                            {boot.app} · {boot.env}
                        </span>
                    </div>
                </div>
            </header>
            <div class="shell">
                <section class="page">{children}</section>
                <footer>
                    <span>Vortech © {new Date().getFullYear()} · Laravel Trace</span>
                    <a
                        href="#top"
                        onClick={(event) => {
                            event.preventDefault();
                            window.scrollTo({ top: 0, behavior: 'smooth' });
                        }}
                    >
                        Back to top ↑
                    </a>
                </footer>
            </div>
        </>
    );
}
