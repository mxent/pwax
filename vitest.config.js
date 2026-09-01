import { readFileSync } from 'node:fs';
import { defineConfig } from 'vitest/config';

const pkg = JSON.parse(readFileSync(new URL('./package.json', import.meta.url), 'utf8'));

export default defineConfig({
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.js'],
        globals: false,
        restoreMocks: true,
        // Builds the service worker and the JSON renderer into memory once, so the
        // harnesses run the real thing rather than a stale copy from `dist/`.
        globalSetup: ['tests/js/helpers/buildWorker.js', 'tests/js/helpers/buildJson.js'],
        setupFiles: ['tests/js/helpers/setup.js'],
    },
    define: {
        // Matches esbuild's define in build.js — the runtime reads this at the top of
        // index.js, and without it any test that imports the runtime entry crashes with
        // `__PWAX_VERSION__ is not defined`.
        __PWAX_VERSION__: JSON.stringify(pkg.version),
    },
});
