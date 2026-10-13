import { describe, expect, it } from 'vitest';
import { parse } from './router';

describe('parse', () => {
    it('finds the page below the base path', () => {
        expect(parse('/trace/runs?status=failed', '/trace', 'remote').page).toBe('runs');
        expect(parse('/trace/runs?status=failed', '/trace', 'remote').params.get('status')).toBe('failed');
    });

    it('falls back to the home page for the bare base path and unknown pages', () => {
        expect(parse('/trace', '/trace', 'source').page).toBe('source');
        expect(parse('/trace/', '/trace', 'source').page).toBe('source');
        expect(parse('/trace/nothing', '/trace', 'remote').page).toBe('remote');
    });

    it('works with a custom base path', () => {
        expect(parse('/admin/trace/health', '/admin/trace', 'remote').page).toBe('health');
    });
});
