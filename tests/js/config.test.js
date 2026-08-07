import { beforeEach, describe, expect, it, vi } from 'vitest';
import { loadConfig, loadInitialPayload, readJsonIsland } from '../../src/js/config.js';

function island(id, contents) {
    const el = document.createElement('script');
    el.type = 'application/json';
    el.id = id;
    el.textContent = contents;
    document.body.appendChild(el);
}

describe('config', () => {
    beforeEach(() => {
        document.head.innerHTML = '';
        document.body.innerHTML = '';
    });

    it('reads a json island', () => {
        island('pwax-config', '{"prefix":"custom"}');

        expect(readJsonIsland('pwax-config')).toEqual({ prefix: 'custom' });
    });

    it('returns the fallback when the island is absent', () => {
        expect(readJsonIsland('pwax-config', { a: 1 })).toEqual({ a: 1 });
    });

    it('returns the fallback and logs when the island is malformed', () => {
        const error = vi.spyOn(console, 'error').mockImplementation(() => {});
        island('pwax-config', '{not json');

        expect(readJsonIsland('pwax-config', null)).toBeNull();
        expect(error).toHaveBeenCalled();
    });

    it('applies defaults over a partial config', () => {
        island('pwax-config', '{"hashRouting":true}');

        const config = loadConfig();

        expect(config.hashRouting).toBe(true);
        expect(config.prefix).toBe('__pwax__');
    });

    it('falls back to the csrf meta tag', () => {
        document.head.innerHTML = '<meta name="csrf-token" content="tok123">';
        island('pwax-config', '{}');

        expect(loadConfig().csrf).toBe('tok123');
    });

    it('prefers an explicit csrf value over the meta tag', () => {
        document.head.innerHTML = '<meta name="csrf-token" content="meta">';
        island('pwax-config', '{"csrf":"explicit"}');

        expect(loadConfig().csrf).toBe('explicit');
    });

    it('returns null when there is no initial payload', () => {
        expect(loadInitialPayload()).toBeNull();
    });

    it('reads the embedded initial payload', () => {
        island('pwax-initial', '{"url":"/about","component":{"template":"<p>x</p>"}}');

        expect(loadInitialPayload().url).toBe('/about');
    });
});
