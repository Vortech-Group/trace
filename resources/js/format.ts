/** 12345 -> "12 345", the grouping the dashboard uses everywhere. */
export function thousands(value: number): string {
    return String(Math.trunc(value)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}

export interface Bar {
    x: number;
    y: number;
    width: number;
    height: number;
    peak: boolean;
    title: string;
}

export interface Chart {
    width: number;
    height: number;
    left: number;
    base: number;
    peak: number;
    max: number;
    ticks: { value: number; y: number }[];
    bars: Bar[];
    labels: { x: number; text: string }[];
}

/** The geometry of the changes-per-hour chart: bars, grid and labels share one scale. */
export function chart(hours: { hour: string; count: number }[]): Chart {
    const width = 560;
    const height = 190;
    const top = 8;
    const base = 160;
    const left = 28;
    const slot = (width - left) / 24;
    const peak = Math.max(0, ...hours.map((hour) => hour.count));
    const max = Math.max(10, Math.ceil(peak / 10) * 10);
    const y = (value: number): number => base - (value / max) * (base - top);

    return {
        width,
        height,
        left,
        base,
        peak,
        max,
        ticks: [0, max / 2, max].map((value) => ({ value: Math.round(value), y: y(value) })),
        bars: hours.map((hour, index) => {
            const barHeight = Math.max((hour.count / max) * (base - top), 1);

            return {
                x: left + index * slot + 2,
                y: base - barHeight,
                width: slot - 4,
                height: barHeight,
                peak: hour.count > 0 && hour.count === peak,
                title: `${hour.hour} · ${hour.count}`,
            };
        }),
        labels: [0, 6, 12, 18, 23].map((index) => ({ x: left + index * slot + 2, text: hours[index]?.hour ?? '' })),
    };
}
