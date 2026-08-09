import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    importModule,
    moduleCacheSize,
    resetModuleCache,
    setImporter,
    styleMetadata,
    toComponentOptions,
} from '../../src/js/modules.js';

describe('module cache', () => {
    beforeEach(() => {
        resetModuleCache();
    });

    it('imports a url once and reuses the result', async () => {
        const module = { default: { name: 'A' } };
        const spy = vi.fn().mockResolvedValue(module);
        setImporter(spy);

        const first = await importModule('/c/a.js');
        const second = await importModule('/c/a.js');

        expect(spy).toHaveBeenCalledTimes(1);
        expect(first).toBe(second);
        expect(moduleCacheSize()).toBe(1);
    });

    it('shares one request between concurrent callers', async () => {
        const spy = vi.fn().mockResolvedValue({ default: {} });
        setImporter(spy);

        await Promise.all([
            importModule('/c/a.js'),
            importModule('/c/a.js'),
            importModule('/c/a.js'),
        ]);

        expect(spy).toHaveBeenCalledTimes(1);
    });

    it('keeps distinct urls separate', async () => {
        setImporter(vi.fn().mockResolvedValue({ default: {} }));

        await importModule('/c/a.js');
        await importModule('/c/b.js');

        expect(moduleCacheSize()).toBe(2);
    });

    // A transient failure must not poison the component for the rest of the session.
    it('does not cache a failed import', async () => {
        const spy = vi
            .fn()
            .mockRejectedValueOnce(new Error('offline'))
            .mockResolvedValue({ default: { name: 'A' } });
        setImporter(spy);

        await expect(importModule('/c/a.js')).rejects.toThrow('offline');
        expect(moduleCacheSize()).toBe(0);

        const module = await importModule('/c/a.js');

        expect(module.default.name).toBe('A');
        expect(spy).toHaveBeenCalledTimes(2);
    });
});

describe('toComponentOptions', () => {
    it('merges the server template into the default export', () => {
        const options = toComponentOptions({
            default: { data: () => ({}) },
            __pwaxTemplate: '<div>hi</div>',
        });

        expect(options.template).toBe('<div>hi</div>');
        expect(typeof options.data).toBe('function');
    });

    it('lets an author-declared template win', () => {
        const options = toComponentOptions({
            default: { template: '<p>mine</p>' },
            __pwaxTemplate: '<div>blade</div>',
        });

        expect(options.template).toBe('<p>mine</p>');
    });

    it('returns a function default export unchanged', () => {
        // Client middleware is a function. Spreading it into an object produced `{}`,
        // so `runMiddleware` reported the name as unknown.
        const middleware = async () => {};

        expect(toComponentOptions({ default: middleware })).toBe(middleware);
    });

    it('does not attach a template to a functional component', () => {
        const functional = () => null;

        expect(toComponentOptions({ default: functional, __pwaxTemplate: '<i></i>' })).toBe(
            functional
        );
    });

    it('handles a module with no default export', () => {
        expect(toComponentOptions({ __pwaxTemplate: '<i></i>' }).template).toBe('<i></i>');
    });

    it('selects a named export', () => {
        const Modal = { name: 'Modal' };

        expect(toComponentOptions({ Modal, default: {} }, 'Modal')).toBe(Modal);
    });

    it('throws for a missing named export', () => {
        expect(() => toComponentOptions({ default: {} }, 'Nope')).toThrow(/no export named "Nope"/);
    });

    it('does not mutate the module default export', () => {
        const module = { default: { a: 1 }, __pwaxTemplate: '<b></b>' };
        toComponentOptions(module);

        expect(module.default.template).toBeUndefined();
    });
});

describe('styleMetadata', () => {
    it('reads the attached metadata', () => {
        expect(
            styleMetadata({
                __pwaxStyle: '.a{}',
                __pwaxScope: 'abc',
                __pwaxStyles: ['/a.css'],
                __pwaxScripts: ['/a.js'],
            })
        ).toEqual({ style: '.a{}', scope: 'abc', styles: ['/a.css'], scripts: ['/a.js'] });
    });

    it('defaults everything for a bare module', () => {
        expect(styleMetadata({})).toEqual({ style: '', scope: null, styles: [], scripts: [] });
    });
});
