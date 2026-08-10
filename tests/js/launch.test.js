/**
 * Being opened by the operating system.
 *
 * `file_handlers`, `protocol_handlers` and a GET `share_target` are three manifest members
 * that all arrive as one thing: a launch. Before this existed the manifest could declare
 * all three and the runtime consumed none of them — the file the user double-clicked was
 * discarded, and following a `web+thing:` link brought the app forward showing whatever
 * page it had been left on.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    createLaunchApi,
    createShareApi,
    resetLaunches,
    watchLaunch,
} from '../../src/js/launch.js';

/** A stand-in launch queue that hands back the consumer the runtime registered. */
function stubQueue() {
    let consumer = null;

    window.launchQueue = {
        setConsumer: (fn) => (consumer = fn),
    };

    return {
        /** Deliver a launch, as the browser would. */
        launch: (params) => consumer(params),
        get registered() {
            return consumer !== null;
        },
    };
}

function stubRouter() {
    const push = vi.fn();

    window.pwax = { router: { push } };

    return push;
}

describe('launch queue', () => {
    let queue;

    beforeEach(() => {
        resetLaunches();
        queue = stubQueue();
        watchLaunch();
    });

    afterEach(() => {
        delete window.launchQueue;
        delete window.pwax;
        resetLaunches();
    });

    it('registers a consumer as soon as the runtime boots', () => {
        expect(queue.registered).toBe(true);
    });

    it('does nothing when there is no launch queue', () => {
        delete window.launchQueue;

        expect(() => watchLaunch()).not.toThrow();
        expect(createLaunchApi().supported).toBe(false);
    });

    it('hands files to a consumer', () => {
        const seen = vi.fn();
        createLaunchApi().consume(seen);

        queue.launch({ files: ['handle-a', 'handle-b'], targetURL: '/open' });

        expect(seen).toHaveBeenCalledWith({
            files: ['handle-a', 'handle-b'],
            targetURL: '/open',
            navigated: false,
        });
    });

    /**
     * The reason the queue exists at all: a launch happens before the document has
     * finished loading, so it lands before any application code can ask for it.
     */
    it('buffers a launch that arrives before anyone asks', () => {
        queue.launch({ files: ['early'] });

        const api = createLaunchApi();

        expect(api.pending).toHaveLength(1);

        const seen = vi.fn();
        api.consume(seen);

        expect(seen).toHaveBeenCalledWith({
            files: ['early'],
            targetURL: null,
            navigated: false,
        });
        expect(api.pending).toHaveLength(0);
    });

    it('dispatches pwax:launch', () => {
        const seen = vi.fn();
        document.addEventListener('pwax:launch', seen, { once: true });

        queue.launch({ files: ['a'] });

        expect(seen).toHaveBeenCalled();
        expect(seen.mock.calls[0][0].detail.files).toEqual(['a']);
    });

    /**
     * Every ordinary cold start of an installed app produces a launch with neither files
     * nor a target, and treating that as an event would fire on every single open.
     */
    it('ignores a launch carrying nothing', () => {
        const seen = vi.fn();
        document.addEventListener('pwax:launch', seen);

        queue.launch({});
        queue.launch({ files: [] });

        expect(seen).not.toHaveBeenCalled();
        document.removeEventListener('pwax:launch', seen);
    });

    it('unregisters a consumer', () => {
        const seen = vi.fn();
        const stop = createLaunchApi().consume(seen);

        stop();
        queue.launch({ files: ['a'] });

        expect(seen).not.toHaveBeenCalled();
    });
});

/**
 * Under `launch_handler: focus-existing` the browser only brings the window forward — it
 * does not navigate. Doing nothing there means following a `web+thing:` link opens the app
 * on yesterday's page, which is wrong every time.
 */
describe('routing to a launch target', () => {
    let queue;

    beforeEach(() => {
        resetLaunches();
        queue = stubQueue();
        watchLaunch();
    });

    afterEach(() => {
        delete window.launchQueue;
        delete window.pwax;
        resetLaunches();
    });

    it('routes to a target url carrying no files', () => {
        const push = stubRouter();

        queue.launch({ targetURL: `${window.location.origin}/invoices/7?from=protocol` });

        expect(push).toHaveBeenCalledWith('/invoices/7?from=protocol');
    });

    /**
     * With files the target URL is the handler page the app is already on, and the files
     * are the payload. Navigating would throw away the launch it was handling.
     */
    it('does not route when the launch carries files', () => {
        const push = stubRouter();

        queue.launch({ files: ['a'], targetURL: `${window.location.origin}/open` });

        expect(push).not.toHaveBeenCalled();
    });

    it('does not route to where it already is', () => {
        const push = stubRouter();

        queue.launch({ targetURL: window.location.href });

        expect(push).not.toHaveBeenCalled();
    });

    /**
     * `targetURL` comes from the browser and should always be in scope, but it is the one
     * value here that decides where the application goes.
     */
    it('refuses a cross-origin target', () => {
        const push = stubRouter();

        queue.launch({ targetURL: 'https://elsewhere.example/steal' });

        expect(push).not.toHaveBeenCalled();
    });

    it('is stopped by preventDefault on the event', () => {
        const push = stubRouter();
        const block = (event) => event.preventDefault();

        document.addEventListener('pwax:launch', block);
        queue.launch({ targetURL: `${window.location.origin}/elsewhere` });
        document.removeEventListener('pwax:launch', block);

        expect(push).not.toHaveBeenCalled();
    });

    it('is stopped by a consumer returning false', () => {
        const push = stubRouter();
        createLaunchApi().consume(() => false);

        queue.launch({ targetURL: `${window.location.origin}/elsewhere` });

        expect(push).not.toHaveBeenCalled();
    });

    it('still routes when a consumer returns nothing', () => {
        const push = stubRouter();
        createLaunchApi().consume(() => {});

        queue.launch({ targetURL: `${window.location.origin}/elsewhere` });

        expect(push).toHaveBeenCalledWith('/elsewhere');
    });

    it('routes a launch that nobody was listening for', () => {
        const push = stubRouter();

        queue.launch({ targetURL: `${window.location.origin}/late` });

        expect(push).toHaveBeenCalledWith('/late');
    });

    /**
     * A launch with no consumer is routed on arrival *and* buffered, so a consumer
     * registering afterwards used to replay it and route a second time. Harmless-looking,
     * and not: the first push may still be in flight, so the app navigates twice for one
     * link and the visitor gets an extra history entry to back out of.
     */
    it('does not route a buffered launch it has already routed', () => {
        const push = stubRouter();

        queue.launch({ targetURL: `${window.location.origin}/late` });
        expect(push).toHaveBeenCalledTimes(1);

        const seen = vi.fn();
        createLaunchApi().consume(seen);

        // The consumer is still told about it — it simply is not acted on twice.
        expect(seen).toHaveBeenCalledTimes(1);
        expect(seen.mock.calls[0][0].navigated).toBe(true);
        expect(push).toHaveBeenCalledTimes(1);
    });

    it('routes a buffered launch that was never routed', () => {
        const push = stubRouter();
        const block = (event) => event.preventDefault();

        document.addEventListener('pwax:launch', block);
        queue.launch({ targetURL: `${window.location.origin}/blocked` });
        document.removeEventListener('pwax:launch', block);

        expect(push).not.toHaveBeenCalled();

        createLaunchApi().consume(() => {});

        expect(push).toHaveBeenCalledWith('/blocked');
    });
});

describe('sharing out', () => {
    afterEach(() => {
        delete navigator.share;
        delete navigator.canShare;
    });

    it('reports unavailable where there is no share sheet', async () => {
        await expect(createShareApi().share({ title: 'x' })).resolves.toBe('unavailable');
    });

    it('shares', async () => {
        navigator.share = vi.fn().mockResolvedValue(undefined);

        await expect(createShareApi().share({ title: 'x' })).resolves.toBe('shared');
        expect(navigator.share).toHaveBeenCalledWith({ title: 'x' });
    });

    /**
     * A cancelled share sheet is an AbortError, which is the user saying no — not a
     * failure, and a caller that showed "sharing failed" for it would be lying.
     */
    it('separates a cancelled share from a broken one', async () => {
        navigator.share = vi
            .fn()
            .mockRejectedValue(Object.assign(new Error('no'), { name: 'AbortError' }));

        await expect(createShareApi().share({})).resolves.toBe('dismissed');

        navigator.share = vi.fn().mockRejectedValue(new Error('nope'));

        await expect(createShareApi().share({})).resolves.toBe('unavailable');
    });

    it('asks canShare before offering to share files', async () => {
        navigator.share = vi.fn().mockResolvedValue(undefined);
        navigator.canShare = vi.fn().mockReturnValue(false);

        await expect(createShareApi().share({ files: ['a'] })).resolves.toBe('unavailable');
        expect(navigator.share).not.toHaveBeenCalled();
    });
});
