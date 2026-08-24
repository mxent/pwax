import { describe, expect, it } from 'vitest';
import { DEFAULT_ERROR, DEFAULT_LOADER, pageTemplate } from '../../src/js/pageTemplate.mjs';

describe('pageTemplate', () => {
    it('falls back to the bundled loader and error markup', () => {
        const template = pageTemplate();

        expect(template).toContain(DEFAULT_LOADER);
        expect(template).toContain(DEFAULT_ERROR.trim());
    });

    it('uses the server-supplied fragments when it has them', () => {
        const template = pageTemplate({ loader: '<p>Wait</p>', error: '<p>Broken</p>' });

        expect(template).toContain('<p>Wait</p>');
        expect(template).toContain('<p>Broken</p>');
        expect(template).not.toContain(DEFAULT_LOADER);
        expect(template).not.toContain(DEFAULT_ERROR.trim());
    });

    /*
     * The loader, the error screen and the page share one slot, and exactly one of them
     * must be able to render. Two root-level `<template>` blocks on `v-if`/`v-else` are
     * what enforces that: flatten them and a page that errors mid-navigation renders the
     * error screen *and* the stale page underneath it.
     */
    it('keeps the error branch and the page branch mutually exclusive', () => {
        const template = pageTemplate();

        expect(template).toMatch(/<template v-if="error">/);
        expect(template).toMatch(/<template v-else>/);
        expect(template).toMatch(/<template v-if="!component">/);
        expect(template).toMatch(
            /<component v-if="component" :is="component" :key="renderedPath">/
        );
    });
});
