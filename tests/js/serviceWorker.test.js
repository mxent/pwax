import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createServiceWorkerApi, registerServiceWorker } from '../../src/js/serviceWorker.js';

/**
 * A minimal stand-in for a ServiceWorkerRegistration, complete enough to drive the update
 * lifecycle: a worker that installs, becomes `installed`, and then waits.
 */
function createRegistration({ waiting = null } = {}) {
    const listeners = {};

    return {
        waiting,
        installing: null,
        update: vi.fn(() => Promise.resolve()),
        unregister: vi.fn(() => Promise.resolve(true)),
        addEventListener: (name, fn) => {
            (listeners[name] ||= []).push(fn);
        },
        emit: (name) => (listeners[name] || []).forEach((fn) => fn()),
    };
}

function createWorker() {
    const listeners = {};

    return {
        state: 'installing',
        postMessage: vi.fn(),
        addEventListener: (name, fn) => {
            (listeners[name] ||= []).push(fn);
        },
        emit: (name) => (listeners[name] || []).forEach((fn) => fn()),
    };
}

function installContainer({ controller = {} } = {}) {
    const listeners = {};
    const registration = createRegistration();

    const container = {
        controller,
        register: vi.fn(() => Promise.resolve(registration)),
        getRegistration: vi.fn(() => Promise.resolve(registration)),
        addEventListener: (name, fn) => {
            (listeners[name] ||= []).push(fn);
        },
        emit: (name) => (listeners[name] || []).forEach((fn) => fn()),
    };

    Object.defineProperty(window.navigator, 'serviceWorker', {
        value: container,
        configurable: true,
        writable: true,
    });

    return { container, registration };
}

describe('registerServiceWorker', () => {
    let reload;

    beforeEach(() => {
        reload = vi.fn();
        // jsdom's location.reload is not writable; replacing the object is the usual way.
        delete window.location;
        window.location = { reload, href: 'http://localhost/' };
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('resolves to null when the browser has no service worker support', async () => {
        Object.defineProperty(window.navigator, 'serviceWorker', {
            value: undefined,
            configurable: true,
        });

        await expect(registerServiceWorker('/service-worker.js')).resolves.toBeNull();
    });

    it('resolves to null when no path is configured', async () => {
        await expect(registerServiceWorker(null)).resolves.toBeNull();
    });

    it('announces an update that arrives while the page is open', async () => {
        const { registration } = installContainer();
        await registerServiceWorker('/service-worker.js');

        const worker = createWorker();
        registration.installing = worker;
        registration.emit('updatefound');

        const events = [];
        document.addEventListener('pwax:update-available', (event) => events.push(event));

        worker.state = 'installed';
        worker.emit('statechange');

        expect(events).toHaveLength(1);
        expect(typeof events[0].detail.activate).toBe('function');
    });

    it('does not announce a first install, only an update', async () => {
        const { registration } = installContainer({ controller: null });
        await registerServiceWorker('/service-worker.js');

        const worker = createWorker();
        registration.installing = worker;
        registration.emit('updatefound');

        const events = [];
        document.addEventListener('pwax:update-available', (event) => events.push(event));

        worker.state = 'installed';
        worker.emit('statechange');

        expect(events).toHaveLength(0);
    });

    it('asks the waiting worker to take over only when the page activates it', async () => {
        const { registration } = installContainer();
        await registerServiceWorker('/service-worker.js');

        const worker = createWorker();
        registration.installing = worker;
        registration.emit('updatefound');

        let detail;
        document.addEventListener('pwax:update-available', (event) => (detail = event.detail));

        worker.state = 'installed';
        worker.emit('statechange');

        expect(worker.postMessage).not.toHaveBeenCalled();

        detail.activate();

        expect(worker.postMessage).toHaveBeenCalledWith({ type: 'PWAX_SKIP_WAITING' });
    });

    it('does not reload when a worker takes control on its own', async () => {
        const { container } = installContainer();
        await registerServiceWorker('/service-worker.js');

        // A worker that skipped waiting by itself must not restart every open tab and
        // throw away whatever the user was typing.
        container.emit('controllerchange');

        expect(reload).not.toHaveBeenCalled();
    });

    it('reloads once after the page asked for the update', async () => {
        const { container, registration } = installContainer();
        await registerServiceWorker('/service-worker.js');

        const worker = createWorker();
        registration.installing = worker;
        registration.emit('updatefound');

        let detail;
        document.addEventListener('pwax:update-available', (event) => (detail = event.detail));
        worker.state = 'installed';
        worker.emit('statechange');

        detail.activate();
        container.emit('controllerchange');
        container.emit('controllerchange');

        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('checks for a new build periodically', async () => {
        const { registration } = installContainer();
        await registerServiceWorker('/service-worker.js');

        expect(registration.update).not.toHaveBeenCalled();

        vi.advanceTimersByTime(60 * 60 * 1000);

        expect(registration.update).toHaveBeenCalled();
    });

    it('reports connectivity changes to the application', async () => {
        installContainer();
        await registerServiceWorker('/service-worker.js');

        const seen = [];
        document.addEventListener('pwax:offline', () => seen.push('offline'));
        document.addEventListener('pwax:online', () => seen.push('online'));

        window.dispatchEvent(new Event('offline'));
        window.dispatchEvent(new Event('online'));

        expect(seen).toEqual(['offline', 'online']);
    });
});

describe('the service worker api', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('resolves false when nothing controls the page', async () => {
        Object.defineProperty(window.navigator, 'serviceWorker', {
            value: { controller: null, getRegistration: () => Promise.resolve(null) },
            configurable: true,
        });

        await expect(createServiceWorkerApi().clearCaches()).resolves.toBe(false);
    });

    it('clears caches through the controlling worker', async () => {
        const controller = {
            postMessage: vi.fn((message, transfer) => {
                // The worker replies on the transferred port, which is how the page knows
                // the caches are actually gone rather than merely asked to go.
                transfer[0].postMessage({ type: 'PWAX_CACHES_CLEARED' });
            }),
        };

        Object.defineProperty(window.navigator, 'serviceWorker', {
            value: { controller },
            configurable: true,
        });

        await expect(createServiceWorkerApi().clearCaches()).resolves.toBe(true);
        expect(controller.postMessage).toHaveBeenCalledWith(
            { type: 'PWAX_CLEAR_CACHES' },
            expect.any(Array)
        );
    });

    it('triggers an update check', async () => {
        const registration = createRegistration();

        Object.defineProperty(window.navigator, 'serviceWorker', {
            value: { getRegistration: () => Promise.resolve(registration) },
            configurable: true,
        });

        await createServiceWorkerApi().update();

        expect(registration.update).toHaveBeenCalled();
    });

    it('unregisters the worker', async () => {
        const registration = createRegistration();

        Object.defineProperty(window.navigator, 'serviceWorker', {
            value: { getRegistration: () => Promise.resolve(registration) },
            configurable: true,
        });

        await expect(createServiceWorkerApi().unregister()).resolves.toBe(true);
    });
});

describe('taking a waiting update', () => {
    it('says so on the console, because nothing else will', async () => {
        const { registration } = installContainer();
        await registerServiceWorker('/service-worker.js');

        const notices = [];
        const info = console.info;
        console.info = (m) => notices.push(m);

        try {
            const worker = createWorker();
            registration.installing = worker;
            registration.emit('updatefound');
            worker.state = 'installed';
            worker.emit('statechange');
        } finally {
            console.info = info;
        }

        // A new build waits rather than taking over, and an application that does not
        // listen for the event gives no sign at all — which reads as a deploy that did
        // not deploy. This line is what explains it.
        expect(notices).toHaveLength(1);
        expect(notices[0]).toContain('applyUpdate');
    });

    it('lets the update through on request', async () => {
        const { registration } = installContainer();
        await registerServiceWorker('/service-worker.js');

        const worker = createWorker();
        registration.installing = worker;
        registration.emit('updatefound');
        worker.state = 'installed';
        worker.emit('statechange');

        await expect(createServiceWorkerApi().applyUpdate()).resolves.toBe(true);
        expect(worker.postMessage).toHaveBeenCalledWith({ type: 'PWAX_SKIP_WAITING' });
    });

    it('reports when there is nothing waiting', async () => {
        installContainer();

        await expect(createServiceWorkerApi().applyUpdate()).resolves.toBe(false);
    });
});
