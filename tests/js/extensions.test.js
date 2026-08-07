import { describe, expect, it, vi } from 'vitest';
import { resolveExtension, resolveExtensions, resolveGlobal } from '../../src/js/extensions.js';

const loader = {
    load: vi.fn((url, name) => Promise.resolve({ url, name })),
};

describe('resolveGlobal', () => {
    it('walks a dotted path', () => {
        expect(resolveGlobal('a.b.c', { a: { b: { c: 42 } } })).toBe(42);
    });

    it('returns undefined for a missing path instead of throwing', () => {
        expect(resolveGlobal('a.b.c', { a: {} })).toBeUndefined();
        expect(resolveGlobal('nope', {})).toBeUndefined();
    });

    it('handles a single segment', () => {
        expect(resolveGlobal('a', { a: 1 })).toBe(1);
    });
});

describe('resolveExtension', () => {
    it('loads a module entry', async () => {
        const result = await resolveExtension(
            { type: 'module', url: '/c/x.js', export: 'Plugin' },
            loader
        );

        expect(result).toEqual({ url: '/c/x.js', name: 'Plugin' });
    });

    it('looks up a global entry', async () => {
        window.MyLib = { plugin: { install() {} } };

        const result = await resolveExtension({ type: 'global', path: 'MyLib.plugin' }, loader);

        expect(result).toBe(window.MyLib.plugin);
    });

    it('warns rather than throwing for a missing global', async () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        expect(
            await resolveExtension({ type: 'global', path: 'NotThere.x' }, loader)
        ).toBeUndefined();
        expect(warn).toHaveBeenCalled();
    });

    it('ignores malformed entries', async () => {
        expect(await resolveExtension(null, loader)).toBeUndefined();
        expect(await resolveExtension('a string', loader)).toBeUndefined();
        expect(await resolveExtension({ type: 'module' }, loader)).toBeUndefined();
    });
});

describe('resolveExtensions', () => {
    it('keeps keys and drops unresolvable entries', async () => {
        window.Lib = { d: 'directive' };
        vi.spyOn(console, 'warn').mockImplementation(() => {});

        const result = await resolveExtensions(
            {
                toast: { type: 'module', url: '/c/toast.js' },
                focus: { type: 'global', path: 'Lib.d' },
                broken: { type: 'global', path: 'Missing.thing' },
            },
            loader
        );

        expect(Object.keys(result).sort()).toEqual(['focus', 'toast']);
        expect(result.focus).toBe('directive');
    });

    // One failing plugin must not stop the application from booting.
    it('isolates a throwing entry', async () => {
        const error = vi.spyOn(console, 'error').mockImplementation(() => {});
        const failing = { load: vi.fn().mockRejectedValue(new Error('boom')) };

        const result = await resolveExtensions(
            { bad: { type: 'module', url: '/c/bad.js' } },
            failing
        );

        expect(result).toEqual({});
        expect(error).toHaveBeenCalled();
    });

    it('handles an empty or missing map', async () => {
        expect(await resolveExtensions({}, loader)).toEqual({});
        expect(await resolveExtensions(undefined, loader)).toEqual({});
    });
});
