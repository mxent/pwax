# Server-rendered pages for SEO, without giving up the SPA

**Status:** proposal / plan. Nothing here is implemented.
**Scope:** `mxent/pwax` 4.x → 5.x.
**Goal:** a Pwax route can return HTML that already contains its rendered
markup — indexable, readable with JavaScript off, fast to first paint — and
the same page then becomes a live Vue application in the browser, with every
subsequent navigation staying the JSON round trip it is today.

---

## 1. What the problem actually is

It is worth being precise, because "we need SSR for SEO" is usually half
wrong, and in Pwax's case it is *more* than half wrong.

### 1.1 What Pwax already server-renders

A full browser navigation to a Pwax route does **not** get an empty shell.
`ComponentResponse::shell()` renders `pwax::layouts.shell` with:

- `<title>`, `<meta name="description">`, `<link rel="canonical">` and the
  whole derived Open Graph / Twitter set, resolved by `Pwa\HeadMeta` from
  `->title()`, `->description()`, `->canonical()`, `->meta()`, `->property()`
  (`resources/views/components/includes/head.blade.php`).
- The page component itself — template, script, scoped style — inlined as
  JSON in `#pwax-initial`, so the first paint needs zero further requests.
- `modulepreload` hints for every `@pwaxImport`ed component
  (`Support\Shell::modulePreloads()`).

So the following already work today and need no SSR:

| Concern | Status |
| --- | --- |
| Google indexing (it executes JS) | Works, with a render-queue delay |
| `<title>` / description / canonical per route | Works |
| Facebook / Slack / LinkedIn / X unfurls | Works (they read `<head>` only) |
| Per-route metadata on client navigation | Works (`payload.head` → `applyHead()`) |

### 1.2 What is genuinely missing

1. **`<body>` is empty until Vue boots.** Everything inside `#pwax` is a
   spinner. Crawlers that do not execute JavaScript — Bing's cheaper tier,
   most LLM/answer-engine crawlers, `curl`, corporate link scanners, reader
   modes, many RSS/preview services — see no content at all.
2. **No-JS users get a wall.** The shell's `<noscript>` says "This app needs
   JavaScript", which is honest today and would be unnecessary for a
   read-only page under SSR.
3. **Core Web Vitals.** LCP waits for: document → Vue (60.8 kB gz full
   build) → runtime → compile the template → mount. On a slow connection
   that is a real number, and CWV is an actual ranking input.
4. **Content-in-body signals.** Some ranking and answer-extraction paths
   never reach the rendered DOM. Text present in the initial HTML is
   strictly safer.
5. **No sitemap / robots / structured-data surface.** Pwax has no
   `->jsonLd()`, no `->robots()`, no `pwax:sitemap`. This is cheap and
   completely independent of SSR, and for a lot of applications it moves the
   needle more than SSR would.

**Conclusion:** the work splits into a *cheap, high-yield SEO tier* that has
nothing to do with rendering, and a *rendering tier* that is expensive and
should be opt-in per route. Do them in that order. Do not let SSR block the
first tier.

---

## 2. Why SSR is hard here specifically

Pwax's premise is *no build step*: a component is a Blade view whose
`<template>` goes to the browser as a string and is compiled there by Vue.
Server rendering inverts three of those assumptions.

### 2.1 The template is a string, and SSR needs a render function

`renderToString()` needs a compiled render function, and specifically an
**SSR-optimised** one (`@vue/compiler-ssr`, not `@vue/compiler-dom` — the
SSR compiler emits `_push`-based code with static hoisting that the DOM
compiler does not).

There is precedent: `php artisan pwax:compile` already shells out to Node
(`bin/compile-templates.mjs`) with a batch of templates and gets render
functions back, stored in `storage/app/pwax/render-functions.php` keyed on a
hash of the *stamped* template (`Support\RenderFunctionStore`). The same
pattern extends to SSR functions cleanly — a second map in the same store,
or a second store.

### 2.2 The component's script is JavaScript, and it has request data baked in

This is the hard part. A page's `<script>` is compiled per request because
`@json($posts)` interpolates controller data into it — which is exactly why
`Pwax::payload()` sets `module => null` for pages and ships `script` inline.
There is no way to re-derive a page's options from its view name on the
server *in PHP*. Something has to **execute JavaScript** with this request's
payload.

Three ways to execute it:

| Driver | Verdict |
| --- | --- |
| **Node sidecar** (`node` process, HTTP or stdin) | Recommended. Same shape as `pwax:compile`, no new PHP extension, works everywhere Node works. |
| **V8Js / php-v8 extension** | Optional driver at most. Effectively unmaintained, needs a compiled extension, blocks most shared/managed hosting. Never a default, never a hard dependency (AGENTS.md §13). |
| **Reimplement Vue rendering in PHP** | No. This is a rendering engine, not a template. It would diverge the first time anyone writes a `setup()`. |

### 2.3 Hydration requires the client to mount *synchronously* against the SSR tree

Today `index.js` calls `Vue.createApp(...).mount(el)` and the routed page
component resolves its own payload asynchronously in `created()` →
`visit()` → `mount()` → `importInlineModule()`. The first paint is therefore
always a client render into an element the runtime owns outright.

`Vue.createSSRApp()` demands that the very first render produce a tree
matching the server HTML. That means the initial page's component options
must be **resolved before `app.mount()`**, not inside the component's
lifecycle. This is a real, contained change to `src/js/index.js` and
`src/js/page.js`, and it is the single most invasive part of the client work.

### 2.4 The SSR tree is the whole app, not just the page

Hydration matches from the mount element down. So the server must render the
same tree the client will build: the root component (whose template is
`pwax.blade.content`, default `<main><router-view></router-view></main>`),
the router resolved at this URL, Pinia if enabled, plus every configured
plugin and directive (`pwax.vue.plugins` / `.directives`), because a plugin
can inject a global component that appears in the output.

### 2.5 Everything else that bites

- **Browser globals at module top level.** `window`, `document`,
  `localStorage`, and `window.pwax.*` in a component's `<script>` body will
  throw in Node. `mounted()` is safe (never runs on the server); `setup()`
  and top-level code are not.
- **`@pwaxImport`** expands to `window.pwax.component(url, export)` at render
  time. The sidecar needs a shim for that plus the *sources* of the imported
  modules, resolved transitively.
- **Scoped styles.** Currently injected by `src/js/styles.js` after mount. On
  an SSR page that is a guaranteed flash of unstyled content; the page's CSS
  must go into the document `<head>`.
- **The service worker.** SSR'd HTML is per-URL and often per-visitor. The
  navigation caching rules and `X-Pwax-Cache: none` must keep holding.
- **CSP.** SSR adds no `unsafe-eval` requirement (good — the SSR render
  functions come from a build step, same as `pwax:compile`), but it does add
  an inline `<style>` for the page, which needs the existing nonce.
- **Personalisation and caching.** An SSR page rendered for a signed-in user
  must never reach a shared cache. `ComponentResponse` already draws exactly
  this line with `cacheable()`; SSR must inherit it, not invent a second one.
- **Timeouts.** A slow or dead sidecar must degrade to today's CSR shell, not
  to a 500.

---

## 3. Options considered

### Option A — SEO tier only (no rendering change)

`->jsonLd()`, `->robots()`, `pwax:sitemap`, `hreflang`, a configurable
`<noscript>` body, `<link rel="alternate">`. Days of work, zero
architectural risk, and for a marketing site or a docs site it captures most
of the realistic gain.

**Verdict:** do this regardless. It is Phase 1.

### Option B — Build-time prerender (SSG) for visitor-independent routes

Extend the existing `pwax:compile` shape into `pwax:prerender`: walk routes
already declared `->cacheable()`, render each through the sidecar once at
deploy time, write HTML to `storage/app/pwax/prerendered/{hash}.html`, and
serve it from `ComponentResponse::shell()` on a cache hit.

Cheap at request time (a file read), no runtime Node dependency in
production, and it covers the pages that actually need indexing on most
sites. Cannot do anything with a route whose output depends on the request.

**Verdict:** high value per unit of risk. Phase 3.

### Option C — Runtime SSR via a Node sidecar

A long-lived Node process rendering per request. Covers every route,
including personalised ones. Adds a process to operate, a per-request
latency budget, and a whole class of "works in browser, throws in Node"
bugs.

**Verdict:** the real answer for applications that need it, gated behind
opt-in. Phase 4.

### Option D — Crawler-only prerender (UA-sniffed proxy)

Serve rendered HTML to bots, the SPA to humans. Rejected: it is cloaking-
adjacent, it does nothing for CWV or no-JS users, and it needs a second
service that is a permanent source of drift.

### Option E — Progressive-enhancement HTML fallback (render the Blade twice)

Render a plain-HTML variant of the page from the same Blade view for the
initial paint, then let Vue replace it. Rejected: it means every component
is authored twice and the two silently diverge — the exact failure mode
Pwax exists to avoid.

---

## 4. Recommended architecture

**A rendering *driver* behind one contract, four phases, opt-in per route,
degrading to today's behaviour at every step.**

The core design decision, and the one that makes this tractable:

> **The SSR service is a pure function of the payload PHP already builds.**
> PHP sends the sidecar the same JSON it would have inlined into
> `#pwax-initial`, plus the root template, the resolved extension module
> sources and a slice of runtime config. The sidecar returns HTML. It never
> calls back into the application, never knows what a Blade view is, and
> never needs the database.

That keeps the two layers exactly as separated as AGENTS.md §2 requires, and
it means the sidecar can be tested with a fixture payload and no Laravel at
all.

### 4.1 Contract

```php
namespace Mxent\Pwax\Contracts;

interface ServerRenderer
{
    /**
     * @param  array<string, mixed>  $request  url, component payload, root template,
     *                                         modules, config slice
     * @return \Mxent\Pwax\Data\RenderedPage|null  null = could not render; caller falls back
     */
    public function render(array $request): ?RenderedPage;
}
```

```php
final class RenderedPage
{
    public function __construct(
        public readonly string $html,      // innerHTML for the mount element
        public readonly string $style,     // collected component CSS
        public readonly array $head = [],  // teleported <head> content, if any
        public readonly ?string $error = null,
    ) {}
}
```

Implementations:

- `Rendering\NullRenderer` — default. Returns `null`. Today's behaviour.
- `Rendering\PrerenderedRenderer` — reads a build-time snapshot from disk.
- `Rendering\NodeRenderer` — talks to the sidecar over stdin (a one-shot
  `node` process, correct and slow) or HTTP/unix socket (a long-lived
  server, the production path).
- `Rendering\V8Renderer` — optional, community-maintained, never wired by
  default.

### 4.2 Request payload PHP sends

```jsonc
{
  "url": "/posts/hello-world",
  "root": "<main><router-view></router-view></main>",   // pwax.blade.content
  "component": { /* exactly Pwax::payload($component, addressable: false) */ },
  "modules": {                                          // transitive @pwaxImport closure
    "/__pwax__/c/abc123.js": "…module source…"
  },
  "config": { "base": "/", "pinia": true, "mount": "pwax" },
  "extensions": {
    "plugins":    { "toast": "…source…" },
    "directives": { "tooltip": "…source…" }
  },
  "timeoutMs": 500
}
```

`modules` is built by generalising `Support\Shell::importedModules()` from
one level to a transitive walk with a visited set (it already scans compiled
scripts for `window.pwax.component("…")`), then compiling each view through
`Pwax::compile()` — which is cache-backed, so warm requests are cheap.

### 4.3 What the sidecar does

```js
// bin/ssr-server.mjs  (and bin/ssr-render.mjs for the one-shot driver)
import { createSSRApp, defineAsyncComponent } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { createRouter, createMemoryHistory } from 'vue-router';
```

1. Build a module registry from `request.modules`, evaluated in a
   `node:vm` context with a `window.pwax.component(url, export)` shim that
   returns `defineAsyncComponent(() => registry(url))` — `renderToString`
   awaits async components, so cycles and laziness both work.
2. Evaluate the page's inline `script` the same way, yielding the options.
3. `template` → SSR render function via `@vue/compiler-ssr`, cached in-process
   on the payload `hash` the server already computes (`Data\Component::hash()`).
4. `createSSRApp(root)`, install the memory-history router at `request.url`,
   Pinia, plugins, directives.
5. `renderToString(app, ctx)`; collect `ctx.modules`, the page style and any
   `<head>` teleports.
6. Return `{ ok, html, style, head }` as JSON. Diagnostics to stderr only —
   same discipline as `bin/compile-templates.mjs`.

Hard requirements on the sidecar: bounded concurrency, a per-render timeout,
a hard memory ceiling, no filesystem or network access to the application,
and a `--version` handshake so PHP can refuse a mismatched Vue.

### 4.4 What changes in the PHP shell

`ComponentResponse::shell()` gains one branch:

```php
$rendered = $this->ssr ? app(ServerRenderer::class)->render($this->ssrRequest($component, $request)) : null;
```

and passes `$pwaxRendered` into the shell view. The shell then:

- emits the page CSS as `<style data-pwax-page{nonce}>` in `<head>` (no FOUC),
- renders `$pwaxRendered->html` **inside** `#pwax` instead of the preloader,
- drops the `pwax-preloader` class and the spinner for that render,
- sets `data-pwax-ssr="1"` on the mount element so the runtime knows to
  hydrate rather than mount,
- keeps `<noscript>` but swaps the "needs JavaScript" screen for a short
  note only when SSR did *not* run.

Everything else — `#pwax-config`, `#pwax-initial`, the vendor scripts,
`Vary`, `Cache-Control` — is unchanged. `#pwax-initial` is still required:
hydration needs the same payload the server rendered from.

### 4.5 What changes in the client runtime

Three contained changes.

1. **`src/js/page.js`** — accept a pre-resolved `initialOptions` and skip the
   fetch/compile path for the first visit, so the component is available on
   the very first render pass rather than a tick later. `visit()` keeps its
   current shape for every subsequent navigation.
2. **`src/js/index.js`** — when `#pwax` carries `data-pwax-ssr`, resolve the
   initial payload to options *before* creating the app (`toOptions()` lifts
   out of the page component into a shared module), then
   `Vue.createSSRApp(...)` instead of `Vue.createApp(...)`, and await
   `router.isReady()` as today.
3. **`src/js/styles.js`** — `adopt(key, element)`, so the style manager takes
   ownership of the server-emitted `<style data-pwax-page>` instead of
   appending a duplicate and later releasing the wrong one.

Hydration mismatches are logged in dev and patched by Vue at runtime; the
runtime should additionally emit `pwax:hydration-mismatch` so an application
can report it. A **failed** hydration falls back to a full client mount —
never a blank page.

### 4.6 Public API

```php
Route::get('/posts/{post}', fn (Post $post) => pwaxRender('pages.post', ['post' => $post])
    ->title($post->title)
    ->description($post->excerpt)
    ->canonical(route('posts.show', $post))
    ->jsonLd(['@type' => 'Article', 'headline' => $post->title])   // Phase 1
    ->robots('index,follow')                                       // Phase 1
    ->ssr()                                                        // Phase 4
    ->cacheable(3600, shared: true));
```

`->ssr(bool $ssr = true)` overrides the config default in both directions, so
`ssr.mode => 'all'` with `->ssr(false)` on a dashboard route is expressible.

### 4.7 Configuration

```php
'ssr' => [
    // 'off' (default) | 'prerendered' | 'on'
    'mode' => env('PWAX_SSR', 'off'),

    // 'node' | 'prerendered' | 'null' | a class name
    'driver' => 'node',

    // Which routes render server-side when mode is 'on'.
    // 'cacheable' — only routes that declared ->cacheable(); 'all'; 'opt-in'.
    'routes' => 'opt-in',

    'node' => [
        'binary' => null,                  // falls back to pwax.assets.node, then `node`
        'server' => env('PWAX_SSR_URL'),   // http://127.0.0.1:13714 — omit for one-shot
        'timeout' => 500,                  // ms; exceeded → fall back to CSR
        'retries' => 0,
    ],

    // Cache the rendered HTML. Only ever consulted for pages that declared
    // ->cacheable() — the same claim, used a third time.
    'cache' => ['enabled' => true, 'store' => null, 'ttl' => 3600],

    'prerender' => [
        'path' => null,                    // storage/app/pwax/prerendered
        'routes' => [],                    // extra URLs to visit at build time
    ],

    // Fail loudly in local/CI, silently in production.
    'strict' => env('PWAX_SSR_STRICT', false),
],
```

Per AGENTS.md §9.1 and §13: none of these reach `runtimeConfig()` except the
one bit the client needs, and that one rides on the mount element's
`data-pwax-ssr` attribute rather than as a config key — the client must
believe the DOM it was given, not a flag that could disagree with it.

### 4.8 Caching layers, stated once

| Layer | Key | Applies to |
| --- | --- | --- |
| Compile cache (exists) | digest of rendered Blade output | all components |
| Render-function store (exists) | stamped template hash | DOM render fns |
| **SSR function cache** (new, in-process, sidecar) | `Component::hash()` | SSR render fns |
| **SSR HTML cache** (new) | `Component::hash()` + digest of the payload script | `->cacheable()` pages only |
| HTTP `Cache-Control` (exists) | — | `no-store, private` unless `->cacheable()` |
| Service worker (exists) | — | honours `X-Pwax-Cache: none` |

The SSR HTML cache is what makes runtime SSR affordable: a docs or catalogue
page renders once per deploy, not once per visitor.

---

## 5. Phasing

Each phase ships on its own and is useful without the next one.

### Phase 1 — SEO surface (no rendering change) · ~1 week

- `ComponentResponse::jsonLd(array|string)` → `<script type="application/ld+json">`
  in the shell head, and carried in the JSON payload so client navigations
  replace it (same `data-pwax-head` ownership rule as the meta tags).
- `->robots(string)` as sugar over `->meta('robots', …)`.
- `->image(string)` filling `og:image` / `twitter:image`, and
  `pwax.head.image` as the site-wide default. Right now `og:image` is the
  one important tag with no derivation at all.
- `pwax:sitemap` command, built on the existing `Pwa\PageRegistry`
  discovery, writing `public/sitemap.xml`; `--check` mode for CI.
- `pwax.head.noscript` — a Blade view rendered inside `<noscript>` instead
  of the "needs JavaScript" screen.
- `pwax:doctor` gains an **SEO** category: missing canonical on a
  `cacheable()` route, missing description, no sitemap, no `og:image`.
- Tests: `Feature/HeadMetaTest` extensions, `Feature/SitemapCommandTest`.
- Docs: a "SEO" section in README, above anything about SSR.

### Phase 2 — SSR-capable compile pipeline · ~1 week

Nothing renders yet; this makes rendering possible.

- `bin/compile-templates.mjs` gains `mode: 'ssr'`, emitting through
  `@vue/compiler-ssr`.
- `RenderFunctionStore` stores both maps (`functions` and `ssrFunctions`)
  under the same key. `bindings()` unchanged; a new `ssrBindings()`.
- `pwax:compile --ssr` (and `--ssr` implied when `pwax.ssr.mode !== 'off'`).
- `pwax:doctor` reports SSR store coverage alongside DOM coverage.
- Tests: fixture template → SSR function; store round-trip; mismatch guard.

### Phase 3 — Build-time prerender · ~1.5 weeks

- `Contracts\ServerRenderer`, `Data\RenderedPage`, `NullRenderer`.
- `bin/ssr-render.mjs` (one-shot, stdin/stdout — same protocol shape as
  `compile-templates.mjs`) and `Rendering\NodeRenderer` in one-shot mode.
- `pwax:prerender` — walks `cacheable()` routes plus `ssr.prerender.routes`,
  renders each, writes HTML + style to `storage/app/pwax/prerendered`.
- `Rendering\PrerenderedRenderer` reads those at request time.
- Shell changes (§4.4) and hydration changes (§4.5) land here — they are the
  same work whether the HTML came from a file or a live process.
- Tests: PHP feature test asserting rendered markup inside `#pwax`; JS test
  asserting `createSSRApp` is used when `data-pwax-ssr` is present and that a
  hydration failure falls back to a clean mount.

### Phase 4 — Runtime SSR · ~2 weeks

- `bin/ssr-server.mjs`: HTTP server, bounded concurrency, per-render timeout,
  in-process SSR-function cache, `--version` handshake, health endpoint.
- `NodeRenderer` HTTP mode, circuit breaker (N consecutive failures →
  disabled for M seconds → CSR fallback), timing logged.
- SSR HTML cache (§4.8).
- `pwax:ssr` (start/serve for local dev), `pwax:doctor` SSR category:
  sidecar reachable, Vue versions agree, store covers the discovered views,
  `ssr.mode` vs `assets.vue_build` consistency.
- Docs: an SSR chapter that opens with "you probably do not need this",
  the browser-globals rule, and deployment shapes (supervisor, systemd,
  container sidecar, Octane co-process).

### Phase 5 — Hardening · ongoing

Streaming (`renderToNodeStream`), `Suspense`-aware data fetching, teleport
collection for head content, per-route SSR timing surfaced to the doctor,
`ssr.strict` failing CI on any mismatch.

---

## 6. Risks and how each is contained

| Risk | Containment |
| --- | --- |
| Sidecar down or slow | Timeout + circuit breaker → today's CSR shell. Never a 500. `strict` turns it into a hard failure in local/CI only. |
| Hydration mismatch | Vue patches at runtime; emit `pwax:hydration-mismatch`; catastrophic failure falls back to a full mount. |
| `window` in a component script | Documented rule + a `pwax:doctor` heuristic scanning compiled page scripts for top-level browser globals. |
| Leaking a signed-in user's HTML into a shared cache | SSR HTML cache consults `->cacheable()` only. No SSR-specific cache flag exists to get wrong. |
| Vue version skew (PHP-vendored vs sidecar's npm) | Version handshake, refusing at boot — the same guard `compile-templates.mjs` already implements. |
| Operational burden pushed onto every consumer | `mode => 'off'` default; Phases 1–3 need no running process at all. |
| Scope creep into a build tool | The sidecar never reads a Blade view, never touches the database, never calls back into the app. If it needs to, the design is wrong. |
| Two divergent rendering paths | The sidecar consumes the *same* payload the browser does. Any divergence is a bug in one place, not two implementations. |

---

## 7. Explicit non-goals

- **No `.vue` files, no bundler, no `npm run build` in the default path.**
  Phases 1–2 add an optional CI step; Phases 3–4 add an optional process.
  An application that does nothing keeps exactly today's behaviour.
- **No UA sniffing, ever.** Same HTML for crawlers and people.
- **No SSR on client-side navigations.** After hydration a navigation is the
  JSON payload it is today. That is the "still works like a normal Vue app"
  requirement, and rendering HTML for a navigation would break it.
- **No PHP-side JavaScript engine as a hard dependency.**
- **No second route table.** SSR resolves the URL through the same memory
  router over the same catch-all route.

---

## 8. Decisions needed before Phase 3 starts

1. **Default for `ssr.routes`** — `opt-in` (safest, most typing) or
   `cacheable` (fewer surprises about personalised pages, and it reuses a
   claim the application has already made). Leaning `cacheable`.
2. **One-shot Node per request** — ship it at all, or force the long-lived
   server? A one-shot process is ~80 ms of startup per request, which is
   defensible for `pwax:prerender` and indefensible for live traffic. Suggest:
   support it, but have `pwax:doctor` warn when it is used with `mode => 'on'`.
3. **`@vue/server-renderer` as an optional peer dependency**, mirroring how
   `@vue/compiler-dom` is handled today. Almost certainly yes.
4. **Does Phase 4 warrant a major version?** Nothing here is breaking on its
   own; the client mount path change (§4.5) is the only load-bearing edit,
   and it is behind `data-pwax-ssr`. Probably a minor, with the SSR surface
   marked experimental for one cycle.
