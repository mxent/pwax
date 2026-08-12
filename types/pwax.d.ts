/**
 * Type definitions for the Pwax client runtime.
 *
 * Hand-written rather than generated. The runtime is plain JavaScript with no build step
 * for consumers, and the public surface — one global object — is small enough that a
 * generated file would be larger, less readable, and no more accurate.
 *
 * Point an editor at these from an application's `jsconfig.json` or `tsconfig.json`:
 *
 *     { "compilerOptions": { "types": ["./vendor/mxent/pwax/types/pwax"] } }
 */

declare namespace Pwax {
    /** A compiled component, as the server sends it. */
    interface ComponentPayload {
        id: string | null;
        hash: string;
        view?: string;
        template: string;
        script: string;
        style: string;
        scope: string | null;
        styles: string[];
        scripts: string[];
        /** The module URL, for a component addressable by name alone. */
        module: string | null;
    }

    /** A page's document metadata, applied on every client-side navigation. */
    interface Head {
        title?: string;
        description?: string;
        canonical?: string;
        meta?: Array<{ attribute: 'name' | 'property'; key: string; content: string }>;
    }

    interface Config {
        prefix: string;
        cachePrefix: string;
        hashRouting: boolean;
        base: string;
        mount: string;
        nonce: string | null;
        pinia: boolean;
        serviceWorker: string | null;
        serviceWorkerScope: string;
        csrf: string | null;
        home: string;
        push: { publicKey: string | null; endpoint: string | null };
        prefetch: { mode: string; delay: number } | false;
        progress: { delay: number; trickle: boolean } | false;
        templates: Record<string, string>;
    }

    interface Http {
        /** The headers every runtime request carries, plus anything you add. */
        headers(extra?: Record<string, string>): Record<string, string>;
        /** Fetch a page payload, following the server's redirect conventions. */
        json(url: string, options?: RequestInit): Promise<any>;
    }

    interface StyleManager {
        acquire(key: string, css: string, options?: { nonce?: string | null }): void;
        release(key: string): void;
        link(href: string): Promise<void>;
        script(src: string): Promise<void>;
    }

    interface Progress {
        start(): void;
        done(): void;
        reset(): void;
    }

    interface ServiceWorkerApi {
        /** The worker controlling this page, if any. */
        readonly controller: ServiceWorker | null;
        registration(): Promise<ServiceWorkerRegistration | null>;
        /** Check for a new build now. */
        update(): Promise<void>;
        /**
         * Let a waiting build take over and reload once it has.
         *
         * A new worker installs and then waits until every tab is closed, so that a
         * deploy does not reload tabs and discard what someone was typing. This is how an
         * application's own "a new version is available" prompt applies it.
         */
        applyUpdate(): void;
        /** Discard every cache, including the framework. The heavy hammer. */
        clearCaches(): Promise<void>;
        unregister(): Promise<boolean>;
    }

    interface InstallApi {
        /** Can `prompt()` do anything right now? */
        readonly available: boolean;
        /** Installed, in this session or before it. */
        readonly installed: boolean;
        /** Is this window an installed app rather than a browser tab? */
        readonly standalone: boolean;
        /**
         * Show the browser's install prompt.
         *
         * `'unavailable'` when there was no captured event — already installed, not
         * eligible, or iOS, which has no programmatic install at all.
         */
        prompt(): Promise<'accepted' | 'dismissed' | 'unavailable'>;
    }

    interface BadgeApi {
        readonly supported: boolean;
        set(count?: number): Promise<boolean>;
        clear(): Promise<boolean>;
    }

    interface StorageApi {
        estimate(): Promise<{ usage: number; quota: number; ratio: number } | null>;
        persisted(): Promise<boolean>;
        /** Ask for eviction-proof storage. Never called for you. */
        persist(): Promise<boolean>;
    }

    interface PushApi {
        readonly supported: boolean;
        readonly permission: NotificationPermission | 'unsupported';
        subscription(): Promise<PushSubscription | null>;
        /** Must be called from a user gesture. */
        subscribe(): Promise<PushSubscription | null>;
        unsubscribe(): Promise<boolean>;
    }

    interface SyncApi {
        readonly supported: boolean;
        /** Queue a write for when the network allows. Returns false if it could not be stored. */
        enqueue(
            url: string,
            options?: { method?: string; headers?: Record<string, string>; body?: unknown }
        ): Promise<boolean>;
        /** How many writes are waiting. */
        pending(): Promise<number>;
        /** Try the queue now. */
        flush(): void;
    }

    /**
     * A launch delivered by the operating system: a file opened, a `web+…` link followed,
     * or a page shared to a GET share target.
     */
    interface Launch {
        /** `FileSystemFileHandle`s the user granted, for a `file_handlers` launch. */
        readonly files: FileSystemFileHandle[];
        readonly targetURL: string | null;
        /** Has the runtime already routed to `targetURL`? True for a replayed launch. */
        readonly navigated: boolean;
    }

    interface LaunchApi {
        readonly supported: boolean;
        /** Launches that arrived before a consumer was registered. */
        readonly pending: Launch[];
        /**
         * Receive launches, including any buffered before this was called. Return `false`
         * to stop the runtime routing to the launch's target URL. Returns an unsubscriber.
         */
        consume(fn: (launch: Launch) => unknown): () => void;
    }

    interface Runtime {
        readonly version: string;
        readonly config: Config;
        readonly http: Http;
        readonly styles: StyleManager;
        /** The Vue application, once mounted. */
        app?: unknown;
        /** The Vue Router instance, once created. */
        router?: unknown;
        /** Resolve an imported component. What `@pwaxImport` compiles to. */
        component(url: string, exportName?: string): unknown;
        load(url: string, exportName?: string): Promise<unknown>;
        import(url: string): Promise<unknown>;
        /**
         * Reboot the runtime. Rarely needed; unmounts the current app and
         * re-initialises. Returns a Promise that resolves when the reboot is complete.
         */
        start(): Promise<void>;
        /** Fetch a page's payload before it is asked for. */
        prefetch(path: string): Promise<unknown>;
        readonly sw: ServiceWorkerApi;
        readonly install: InstallApi;
        readonly badge: BadgeApi;
        readonly storage: StorageApi;
        readonly push: PushApi;
        readonly sync: SyncApi;
        readonly launch: LaunchApi;
        /**
         * Open the platform share sheet. Must be called from a user gesture.
         *
         * `'unavailable'` where there is no share sheet, so a caller can fall back to
         * copying a link without feature-detecting first.
         */
        share(data: ShareData): Promise<'shared' | 'dismissed' | 'unavailable'>;
        readonly progress: Progress | null;
    }

    /** Events dispatched on `document`. */
    interface EventMap {
        'pwax:ready': CustomEvent<{ app: unknown; router: unknown }>;
        'pwax:navigating': CustomEvent<{ to: unknown; from: unknown }>;
        'pwax:navigated': CustomEvent<{ component: unknown; path: string }>;
        'pwax:update-available': CustomEvent<{ activate: () => void }>;
        'pwax:online': CustomEvent<void>;
        'pwax:offline': CustomEvent<void>;
        'pwax:installable': CustomEvent<void>;
        'pwax:installed': CustomEvent<void>;
        'pwax:push-subscribed': CustomEvent<{ subscription: PushSubscription }>;
        'pwax:push-unsubscribed': CustomEvent<void>;
        'pwax:queued': CustomEvent<{ url: string }>;
        /** Cancelable: `preventDefault()` keeps the runtime from routing to `targetURL`. */
        'pwax:launch': CustomEvent<Launch>;
    }
}

interface Window {
    pwax: Pwax.Runtime;
}

interface Document {
    addEventListener<K extends keyof Pwax.EventMap>(
        type: K,
        listener: (this: Document, event: Pwax.EventMap[K]) => void,
        options?: boolean | AddEventListenerOptions
    ): void;
    removeEventListener<K extends keyof Pwax.EventMap>(
        type: K,
        listener: (this: Document, event: Pwax.EventMap[K]) => void,
        options?: boolean | EventListenerOptions
    ): void;
}

declare const pwax: Pwax.Runtime;
