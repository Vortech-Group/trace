import type { JSX } from 'preact';
import { useEffect, useId, useRef, useState } from 'preact/hooks';

interface Props {
    label: string;
    value: string;
    options: [value: string, text: string][];
    onChange: (value: string) => void;
}

/** A listbox in the style of the dashboard instead of the operating system's popup. */
export function Select({ label, value, options, onChange }: Props): JSX.Element {
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const root = useRef<HTMLDivElement>(null);
    const id = useId();
    const selected = Math.max(
        0,
        options.findIndex(([candidate]) => candidate === value),
    );

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const close = (event: MouseEvent): void => {
            if (!root.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', close);

        return () => document.removeEventListener('mousedown', close);
    }, [open]);

    const show = (): void => {
        setActive(selected);
        setOpen(true);
    };

    const choose = (index: number): void => {
        setOpen(false);

        if (options[index][0] !== value) {
            onChange(options[index][0]);
        }
    };

    const onKeyDown = (event: JSX.TargetedKeyboardEvent<HTMLButtonElement>): void => {
        const last = options.length - 1;

        switch (event.key) {
            case 'ArrowDown':
            case 'ArrowUp':
                event.preventDefault();

                if (!open) {
                    show();
                } else {
                    setActive((index) => Math.min(last, Math.max(0, index + (event.key === 'ArrowDown' ? 1 : -1))));
                }

                break;
            case 'Home':
            case 'End':
                if (open) {
                    event.preventDefault();
                    setActive(event.key === 'Home' ? 0 : last);
                }

                break;
            case 'Enter':
            case ' ':
                event.preventDefault();

                if (open) {
                    choose(active);
                } else {
                    show();
                }

                break;
            case 'Escape':
            case 'Tab':
                setOpen(false);

                break;
        }
    };

    return (
        <div class="select" ref={root}>
            <button
                type="button"
                class={`select-btn ${value === '' ? '' : 'set'}`}
                aria-label={label}
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-controls={`${id}-list`}
                aria-activedescendant={open ? `${id}-${active}` : undefined}
                onClick={() => (open ? setOpen(false) : show())}
                onKeyDown={onKeyDown}
            >
                <span>{options[selected][1]}</span>
                <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                </svg>
            </button>
            {open && (
                <ul class="select-list" id={`${id}-list`} role="listbox" aria-label={label}>
                    {options.map(([optionValue, text], index) => (
                        <li
                            key={optionValue}
                            id={`${id}-${index}`}
                            role="option"
                            aria-selected={optionValue === value}
                            class={index === active ? 'active' : undefined}
                            onMouseEnter={() => setActive(index)}
                            onMouseDown={(event) => {
                                event.preventDefault();
                                choose(index);
                            }}
                        >
                            {text}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
