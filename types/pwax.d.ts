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
        /** Structured data, one entry per `<script type="application/ld+json">`. */
        jsonLd?: Array<Record<string, unknown>>;
        /** `rel="alternate"` links to this page in other languages. */
        alternates?: Array<{ hreflang: string; href: string }>;
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
        /** The resolved `pwax.vue.*` extensions, keyed by the name they were configured under. */
        plugins: Record<string, RuntimeExtension>;
        directives: Record<string, RuntimeExtension>;
        middleware: Record<string, RuntimeExtension>;
        json: JsonConfig;
    }

    /** One prop of a catalog component, as `pwax.json.components` declares it. */
    interface JsonPropDeclaration {
        type?: 'string' | 'number' | 'boolean' | 'enum' | 'array' | 'object' | 'any';
        /** The permitted values, for `type: 'enum'`. */
        values?: string[];
        required?: boolean;
    }

    /**
     * One entry of the JSON catalog, resolved server-side.
     *
     * The component half is a `RuntimeExtension` — the same `module`/`global` shape the
     * `vue.*` groups use. The rest is the description a document is validated and a
     * generator is constrained against.
     */
    type JsonCatalogEntry = RuntimeExtension & {
        description?: string;
        slots?: string[];
        /**
         * Events the component raises that a document may bind with `on`.
         *
         * Rarely needed: a component's own `emits` is read once its module loads, and
         * that is where these normally come from. Declared only for a component whose
         * options the runtime cannot see, such as one resolved from a `window` global.
         */
        events?: string[];
        props?: Record<string, JsonPropDeclaration>;
    };

    /** `pwax.json`, resolved. Every field is present and empty when disabled. */
    interface JsonConfig {
        enabled: boolean;
        /** The renderer bundle's fingerprinted URL, or null when disabled. */
        runtime: string | null;
        components: Record<string, JsonCatalogEntry>;
        actions: Record<string, RuntimeExtension>;
    }

    /**
     * `window.pwax.json` — the JSON document renderer.
     *
     * Rendering is done with the global `<PwaxJson :json="…" />` component; this is the
     * surface for the things around it. `prompt()` and `jsonSchema()` load the renderer
     * if it is not already loaded, because the catalog they describe lives inside it —
     * and every component in that catalog, to read the events off each one's `emits`.
     * Rendering is unaffected: a page still fetches only what its document names.
     */
    interface Json {
        /**
         * Load the renderer now rather than on first render.
         *
         * Never required — `<PwaxJson>` loads it itself — and worth calling only to move
         * the fetch somewhere less noticeable than the render it would otherwise delay.
         */
        load(): Promise<unknown>;
        /** The system prompt that constrains a model to this application's catalog. */
        prompt(options?: Record<string, unknown>): Promise<string>;
        /**
         * The JSON Schema for a model that supports structured output.
         *
         * This and `prompt()` are what the catalog's prop declarations are for: nothing
         * checks a declared prop's *type* once a document has arrived. What is enforced
         * is the component list, and the props no document may set at all — anything
         * beginning with `on`, the names that write markup, and script URLs.
         */
        jsonSchema(options?: Record<string, unknown>): Promise<object>;
    }

    /**
     * One entry of `pwax.vue.plugins`, `.directives` or `.middleware`, resolved server-side.
     *
     * A `@pwaxImport('view')` or `module:view` value becomes a `module` entry with the
     * component's signed URL; anything else is a `global` entry naming a dotted path on
     * `window`. The runtime never evaluates a configured string — that is the whole reason
     * the server resolves these into a shape rather than passing them through.
     */
    type RuntimeExtension =
        | { type: 'module'; url: string; export: string }
        | { type: 'global'; path: string };

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
        readonly json: Json;
    }

    /** Events dispatched on `document`. */
    interface EventMap {
        'pwax:ready': CustomEvent<{ app: unknown; router: unknown }>;
        'pwax:navigating': CustomEvent<{ to: unknown; from: unknown }>;
        'pwax:navigated': CustomEvent<{ component: unknown; path: string }>;
        /**
         * A page failed to load. `status` is the HTTP status, or `null` when the request
         * never got an answer — a dropped connection, or a component that would not
         * compile. The runtime has already rendered its error screen by this point; the
         * event is for reporting, not for handling the failure.
         */
        'pwax:error': CustomEvent<{ error: unknown; status: number | null }>;
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
