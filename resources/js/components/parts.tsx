import type { ComponentChildren, JSX } from 'preact';
import { useState } from 'preact/hooks';
import type { Tone } from '../types';
import { Icon } from './Icon';

export function Status({ tone = '', children }: { tone?: Tone; children: ComponentChildren }): JSX.Element {
    return <span class={`status ${tone}`}>{children}</span>;
}

export function Head({ index, label, title, dim, children }: { index: string; label: string; title: string; dim: string; children: ComponentChildren }): JSX.Element {
    return (
        <div class="head">
            <div class="kicker">
                <span class="dot" />
                <span class="eyebrow">
                    {index} — {label}
                </span>
            </div>
            <h1>
                {title} <span class="dim">{dim}</span>
            </h1>
            <p>{children}</p>
        </div>
    );
}

export function Stats({ items }: { items: { label: string; value: string | number; hot?: boolean }[] }): JSX.Element {
    return (
        <>
            <dl class="stats">
                {items.map((item) => (
                    <div key={item.label}>
                        <dt>{item.label}</dt>
                        <dd class={item.hot ? 'hot' : undefined}>{item.value}</dd>
                    </div>
                ))}
            </dl>
            <div class="rule" />
        </>
    );
}

export function Empty({ children }: { children: ComponentChildren }): JSX.Element {
    return <p class="empty">{children}</p>;
}

export function Failure({ message }: { message: string }): JSX.Element {
    return (
        <div class="callout">
            <Icon name="warn" />
            <span>{message}</span>
        </div>
    );
}

export function Loading(): JSX.Element {
    return <p class="empty">Loading…</p>;
}

/** Copies text where the async clipboard API is missing too: it only exists on https and localhost. */
export async function copyText(text: string): Promise<boolean> {
    if (window.isSecureContext && navigator.clipboard) {
        try {
            await navigator.clipboard.writeText(text);

            return true;
        } catch {
            // fall through to the textarea
        }
    }

    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.cssText = 'position:fixed;top:0;left:0;opacity:0';
    document.body.appendChild(area);
    area.select();

    try {
        return document.execCommand('copy');
    } catch {
        return false;
    } finally {
        area.remove();
    }
}

export function CopyCommand({ command }: { command: string }): JSX.Element {
    const [copied, setCopied] = useState(false);

    const copy = async (event: MouseEvent): Promise<void> => {
        event.stopPropagation();

        if (await copyText(command)) {
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1600);
        }
    };

    return (
        <button class={`cmd ${copied ? 'copied' : ''}`} type="button" aria-label="Copy command" onClick={copy}>
            <code>
                <span class="pr">$</span> {command}
            </code>
            <Icon name="copy" />
        </button>
    );
}
