/**
 * Installing the app, and the two small APIs that come with being installed.
 *
 * A browser will not show its own install button on most platforms any more, so the
 * affordance has to be the application's — which means catching `beforeinstallprompt`
 * before the browser gives up on it and keeping the event to call later.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    createBadgeApi,
    createInstallApi,
    createStorageApi,
    standalone,
    watchInstall,
} from '../../src/js/install.js';

/** The event a browser fires when it is willing to offer installation. */
function beforeInstallPrompt(outcome = 'accepted') {
    const event = new Event('beforeinstallprompt');

    event.prompt = vi.fn(async () => {});
    event.userChoice = Promise.resolve({ outcome });

    return event;
}

describe('the install prompt', () => {
    beforeEach(() => {
        watchInstall();
    });

    afterEach(() => {
        delete window.navigator.standalone;
    });

    it('is unavailable until the browser offers one', async () => {
        const install = createInstallApi();

        expect(install.available).toBe(false);
        await expect(install.prompt()).resolves.toBe('unavailable');
    });

    it('captures the event and announces it', () => {
        const heard = vi.fn();
        document.addEventListener('pwax:installable', heard, { once: true });

        window.dispatchEvent(beforeInstallPrompt());

        expect(heard).toHaveBeenCalled();
        expect(createInstallApi().available).toBe(true);
    });

    it('prevents the browser showing its own prompt', () => {
        const event = beforeInstallPrompt();
        const prevented = vi.spyOn(event, 'preventDefault');

        window.dispatchEvent(event);

        // Without this the browser decides when to ask, and the application never does.
        expect(prevented).toHaveBeenCalled();
    });

    it('reports what the visitor chose', async () => {
        window.dispatchEvent(beforeInstallPrompt('accepted'));

        await expect(createInstallApi().prompt()).resolves.toBe('accepted');
    });

    it('reports a dismissal as a dismissal', async () => {
        window.dispatchEvent(beforeInstallPrompt('dismissed'));

        await expect(createInstallApi().prompt()).resolves.toBe('dismissed');
    });

    it('spends the captured event exactly once', async () => {
        window.dispatchEvent(beforeInstallPrompt());

        const install = createInstallApi();

        await install.prompt();

        // The browser decides when to offer another. Pretending we still have one would
        // give an application a button that silently does nothing.
        expect(install.available).toBe(false);
        await expect(install.prompt()).resolves.toBe('unavailable');
    });

    it('notices the app being installed', () => {
        const heard = vi.fn();
        document.addEventListener('pwax:installed', heard, { once: true });

        window.dispatchEvent(beforeInstallPrompt());
        window.dispatchEvent(new Event('appinstalled'));

        expect(heard).toHaveBeenCalled();
        expect(createInstallApi().installed).toBe(true);
        expect(createInstallApi().available).toBe(false);
    });
});

describe('detecting an installed window', () => {
    // jsdom ships no `matchMedia` at all, which is why the source feature-detects it
    // rather than calling it — and why this assigns rather than spies.
    const withMedia = (matching) => {
        window.matchMedia = (query) => ({ matches: query === matching, media: query });
    };

    afterEach(() => {
        delete window.navigator.standalone;
        delete window.matchMedia;
    });

    it('survives a browser with no matchMedia', () => {
        expect(standalone()).toBe(false);
    });

    it('reads display-mode', () => {
        withMedia('(display-mode: standalone)');

        expect(standalone()).toBe(true);
    });

    it('counts the other installed display modes too', () => {
        withMedia('(display-mode: window-controls-overlay)');

        expect(standalone()).toBe(true);
    });

    it('falls back to navigator.standalone for iOS', () => {
        withMedia('(display-mode: nothing)');

        expect(standalone()).toBe(false);

        // iOS has never implemented the media query and still reports only this.
        window.navigator.standalone = true;

        expect(standalone()).toBe(true);
    });
});

describe('the badge', () => {
    afterEach(() => {
        delete navigator.setAppBadge;
        delete navigator.clearAppBadge;
    });

    it('reports honestly when the platform has no badge', async () => {
        const badge = createBadgeApi();

        expect(badge.supported).toBe(false);

        // A no-op rather than a throw: an application should not have to feature-detect
        // to show an unread count.
        await expect(badge.set(3)).resolves.toBe(false);
        await expect(badge.clear()).resolves.toBe(false);
    });

    it('sets and clears where it exists', async () => {
        navigator.setAppBadge = vi.fn(async () => {});
        navigator.clearAppBadge = vi.fn(async () => {});

        const badge = createBadgeApi();

        await expect(badge.set(5)).resolves.toBe(true);
        await expect(badge.clear()).resolves.toBe(true);

        expect(navigator.setAppBadge).toHaveBeenCalledWith(5);
    });

    it('swallows a rejection from the platform', async () => {
        navigator.setAppBadge = vi.fn(async () => {
            throw new Error('nope');
        });

        await expect(createBadgeApi().set(1)).resolves.toBe(false);
    });
});

describe('storage', () => {
    afterEach(() => {
        delete navigator.storage;
    });

    it('reports null where the browser will not say', async () => {
        await expect(createStorageApi().estimate()).resolves.toBeNull();
    });

    it('reports usage against quota', async () => {
        navigator.storage = { estimate: async () => ({ usage: 250, quota: 1000 }) };

        await expect(createStorageApi().estimate()).resolves.toEqual({
            usage: 250,
            quota: 1000,
            ratio: 0.25,
        });
    });

    it('never asks for persistence on its own', async () => {
        const persist = vi.fn(async () => true);
        navigator.storage = { persist, persisted: async () => false };

        const storage = createStorageApi();

        await storage.persisted();

        // On some platforms this is a real permission prompt. Spending it is the
        // application's decision, not the package's.
        expect(persist).not.toHaveBeenCalled();

        await expect(storage.persist()).resolves.toBe(true);
        expect(persist).toHaveBeenCalled();
    });
});
