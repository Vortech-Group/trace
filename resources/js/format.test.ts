import { describe, expect, it } from 'vitest';
import { chart, thousands } from './format';

describe('thousands', () => {
    it('groups by three digits with a space', () => {
        expect(thousands(0)).toBe('0');
        expect(thousands(999)).toBe('999');
        expect(thousands(1000)).toBe('1 000');
        expect(thousands(1234567)).toBe('1 234 567');
    });
});

describe('chart', () => {
    const hours = Array.from({ length: 24 }, (_, index) => ({ hour: `${String(index).padStart(2, '0')}:00`, count: index === 10 ? 63 : index }));

    it('scales the grid to the peak in steps of ten', () => {
        const geometry = chart(hours);

        expect(geometry.peak).toBe(63);
        expect(geometry.max).toBe(70);
        expect(geometry.ticks.map((tick) => tick.value)).toEqual([0, 35, 70]);
    });

    it('marks only the peak bar and keeps an empty hour visible', () => {
        const geometry = chart(hours);

        expect(geometry.bars.filter((bar) => bar.peak)).toHaveLength(1);
        expect(geometry.bars[10].peak).toBe(true);
        expect(geometry.bars[0].height).toBe(1);
    });

    it('has a scale of ten for an empty day and labels five hours', () => {
        const geometry = chart(hours.map((hour) => ({ ...hour, count: 0 })));

        expect(geometry.max).toBe(10);
        expect(geometry.bars.some((bar) => bar.peak)).toBe(false);
        expect(geometry.labels.map((label) => label.text)).toEqual(['00:00', '06:00', '12:00', '18:00', '23:00']);
    });
});
