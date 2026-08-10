import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.js'],
        globals: false,
        restoreMocks: true,
        // Builds the service worker into memory once, so the harness runs the real thing
        // rather than a stale copy from `dist/`.
        globalSetup: ['tests/js/helpers/buildWorker.js'],
        setupFiles: ['tests/js/helpers/setup.js'],
    },
});
