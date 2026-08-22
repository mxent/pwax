# Changelog

All notable changes to `mxent/pwax` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Server-side rendering for SEO.** The first-paint response of an eligible route is
  prerendered to real HTML through a Node SSR bridge (`bin/ssr.mjs`), using the same
  compiled `Component` the browser would receive run through `@vue/server-renderer`. The
  client runtime then hydrates the existing DOM via `createSSRApp` rather than replacing
  it. The developer's authoring model does not change: components stay Blade views with
  `<template>/<script>/<style>`, controllers still call `pwaxRender('pages.home', $data)`.
  - New `ssr.*` config block (`pwax.ssr.enabled`, `routes`, `exclude`, `node`, `script`,
    `cache.store`, `cache.ttl`, `timeout`, `fallback`). Off by default.
  - New `ComponentResponse::prerenderable()` and `ComponentResponse::spaOnly()` fluent
    methods for per-route opt-in/opt-out. `prerenderable()` overrides the eligibility
    inference: it is the same visitor-independence claim `cacheable()` makes, without the
    payload caching, for a page that is the same for everyone but has no reason to be
    HTTP-cacheable.
  - New optional peer dependencies: `vue`, `@vue/server-renderer` and `@vue/compiler-dom`,
    all pinned to `assets.versions.vue`. `vue` is among them because the bridge renders a
    real Vue application in Node — Pwax vendors Vue for the browser, not for npm — and the
    bridge reports any of the three missing or mismatched as a readable message rather than
    dying with an unhandled module error.
  - New `bin/ssr.mjs` Node bridge, modelled on `bin/compile-templates.mjs`. It renders the
    page inside the same `PwaxPage` wrapper the client builds, from the shared
    `src/js/pageTemplate.mjs`, so the fragment anchors and branch placeholders Vue's
    hydration compares are present in the server's HTML. It is handed the same payload the
    browser receives — `Pwax::payload()`, not the bare `Component` — so a precompiled
    application renders through the same `__pwaxRender` on both sides instead of the server
    recompiling the template and agreeing only by coincidence. The Vue runtime is published
    as `globalThis.Vue` before any component module is evaluated, because that is the global
    such a module dereferences in the browser.
  - New `X-Pwax-SSR` response header (`1` prerendered, `0` SPA fallback) — informational,
    not part of `Vary`. With `APP_DEBUG` on, a `0` is accompanied by a log line naming the
    rule that skipped the route.
  - New `pwax-state` JSON island carrying the prerendered page's resolved state for
    hydration; new `stateIslandId` runtime config key. The server's values take precedence
    over the component's own `data()` for the initial render, which is what makes the
    island able to reconcile a `data()` that is not a pure function of the document.
  - New `window.pwax.ssrState` — the server's prerendered state, for a page component to
    read in `data()`/`setup()`. Documented behind a `typeof window` check, since the same
    `data()` runs in Node during the prerender and optional chaining does not protect the
    identifier on the left of the first dot.
  - A prerendered response inlines the page's compiled `<style>` in the head (keyed
    `data-pwax-style="pwax:page:<hash>"`, adopted rather than duplicated by the runtime's
    style manager) and links its external stylesheets rather than merely preloading them, so the
    markup is styled before any JavaScript runs. The mount element carries no
    `pwax-preloader` class, whose `::before` overlay would otherwise cover the content.
  - `pwax:doctor` checks the SSR bridge's dependencies and version alignment when enabled,
    against the script `ssr.script` actually names rather than the package's own — and
    reports the line of Node's stderr that says what went wrong when the bridge cannot start
    at all, rather than the file-and-line header an uncaught throw prints first.
  - `pwax:clear` flushes the prerender cache along with the other Pwax stores.
  - Per-visitor pages (rendered with data, declared neither `cacheable()` nor
    `prerenderable()`) are excluded by default — same boundary as the compile cache and
    payload addressability.
  - Requires `symfony/process`, now declared in `composer.json` rather than relied on
    transitively through `laravel/framework`.
- **`MAINTAINING.md`.** The maintainer's counterpart to `CONTRIBUTING.md`: the release
  procedure and why `dist/` has to be rebuilt *before* the tag, how to update the vendored
  Vue/Vue Router/Pinia builds so their versions and SRI hashes stay in step, how to widen
  the Laravel support window without the CI matrix silently testing the wrong Testbench,
  when the skill file has gone stale, how to audit the published config surface for dead
  keys, and which parts of the package a security report is most likely to concern.
- **`tests/Unit/VendoredAssetsTest.php`.** Asserts that the `sha384` hashes in
  `assets.cdn.integrity` match the builds in `resources/vendor/`, that every vendored file
  is pinned in `assets.versions`, and that `resources/vendor/README.md` documents the same
  versions. Nothing checked this at runtime — the two halves are never used together, since
  `assets.source => 'local'` serves the files and ignores the hashes — so a stale hash
  surfaced as a browser refusing to run Vue in an application running in CDN mode, a
  release after the mistake.
- **`pwax:error` in `types/pwax.d.ts`.** It is dispatched by the runtime and listed in the
  README's event table, but was missing from the typed `EventMap` — so the one event that
  carries the failure payload was the one a TypeScript listener got no types for.
- **The skill documents `window.pwax.sync`.** The offline write queue was a headline
  capability with no entry in `resources/ai/pwax-skill.md` at all, so an assistant asked to
  make a form work offline had nothing to go on. The new section covers the API, the
  requirement that queued requests be safe to repeat, the replay outcome table, and the 419
  behaviour above.

- **`window.pwax.start()` — reboot the runtime.** Unmounts the current Vue app and
  re-initialises; returns a Promise. Rarely needed, but it is the supported way to recover
  from a hot-reload in development or to apply a configuration change without a full page
  reload.
- **`pwax.push.private_key` config key.** The VAPID private key, mapped to
  `VAPID_PRIVATE_KEY`. Pwax does not use it (it is the browser half only), but
  `pwax:doctor` validates its shape so a malformed key is caught before it reaches the
  push service as a 401.


- **`php artisan pwax:skill` and `pwax:install --ai`.** A Pwax-using project is the
  one place an AI assistant is most likely to invent package conventions from
  scratch, and the one place it is least likely to land. The package now ships a
  skill file — `resources/ai/pwax-skill.md` — that describes the contract: where
  pages live, what a component looks like, which config keys cross the runtime
  boundary, which Blade directives collide with Vue, what the doctor warnings
  mean. `pwax:skill` publishes it to `.ai/skills/pwax/SKILL.md` (the path Copilot,
  Claude Code and Codex look at); `--path` overrides the target directory,
  `--force` overwrites. `pwax:install --ai` publishes the same file as part of
  install. `--all` is unnecessary: the publish tag is `pwax-ai` and is always
  available.


- **`AGENTS.md` at the package root.** The companion file for an AI assistant
  that is *modifying* the package itself — every section is a decision the
  maintainers have already had to make, from "the runtime config is data, not
  code" to "a renamed config key goes through one major cycle of fallback and a
  doctor warning". Reading it is the difference between an AI assistant that
  produces work that needs one review and one that produces work that needs
  five.


- **`php artisan pwax:vapid`.** Web Push needed a VAPID key pair, and every guide to
  generating one reaches for a Node tool — which is the one thing an application built on
  this package is entitled not to have. This needs only `ext-openssl`, and prints both keys
  ready to paste into `.env`.


- **The manifest members that hand your app to the operating system now work.**
  `share_target`, `file_handlers` and `protocol_handlers` passed straight through to the
  manifest and nothing else happened: the browser registered the app for them, and the
  launch that followed was discarded. A file the user double-clicked never arrived, and
  following a `web+thing:` link brought the window forward showing whatever page it had been
  left on.

  The runtime now consumes the launch queue — before anything is awaited, because a launch
  lands before the document finishes loading and the queue holds it only until a consumer is
  set. `pwax.launch.consume()` receives the `FileSystemFileHandle`s, including any buffered
  before the app booted, and a launch carrying a URL and no files is routed to. Return
  `false`, or cancel the `pwax:launch` event, to route it yourself.

  Nothing reads or uploads a file: those handles are a capability the user granted this
  application, and handing them anywhere else is the application's decision.

- **`pwax:doctor` resolves every declared manifest target against the real route table**,
  with the method the browser will actually use. A target that matches no route, refuses
  that method, or sits outside `scope` is now a failed check rather than a user who shared a
  photo to an installed app and got a 404 hours after the deploy that caused it. It also
  checks the shapes the route table cannot speak to: a share target accepting files must
  POST as `multipart/form-data`, a file handler needs a non-empty `accept` map, and a custom
  protocol must begin with `web+`.

- **`pwax.share()`**, the other direction — the platform share sheet, resolving
  `'shared' | 'dismissed' | 'unavailable'` so a caller can fall back to copying a link
  without feature-detecting, and separating a cancelled sheet from a broken one.


- **`pwax:doctor` now checks the four things that fail silently today.** Every
  `service_worker.extend` entry resolves to a view or a file the worker can read; the
  VAPID public key is a 65-byte uncompressed P-256 point and the private key a 32-byte
  scalar; the configured cache store round-trips a value; the worker URL is served as
  JavaScript rather than an HTML login page. The first three were features whose
  misconfigurations shipped, and the fourth is the "nothing happens when I reload offline"
  failure mode. A bad key, a missing controller endpoint, or a typo in an extend list
  is now a failed check rather than a user who enabled push and got nothing.


- **`pwax:component` now scaffolds plugins, directives and middleware.** The three
  additions are mutually exclusive flags, each emits the correct export shape with a
  comment block listing the API, and the command names the `pwax.plugins` /
  `pwax.directives` / `pwax.middleware_js` key the new view belongs under. A developer
  reading the codebase no longer has to copy the export shape from somewhere else — and
  somewhere else is the server-side renderer inside the package, which is not the place
  they would have looked.


- **`pwax:precache --verify` covers pages.** Rendering each component was the first half
  of "everything in the manifest will load". `pages.urls` and `pages.discover` are the
  other half, and they were the half that 5xx's offline for a first-time visitor — a
  route that throws here throws the same way with no connection. `--verify` now issues a
  guest GET to every page the manifest will precache, with the same `X-Pwax-Component`
  header the worker uses, and reports any 5xx as a failed row.


- **`php artisan pwax:push-endpoint`.** A push-subscription controller scaffold. Every
  application reaches for the same shape — validate the subscription that arrived, store
  it keyed on the endpoint, on `DELETE` remove it — and putting the boilerplate on disk
  means a developer can read it next to the README example rather than rewriting it
  from the prose. The command writes the controller only when missing, and `--force`
  overwrites.


- **`php artisan pwax:routes`.** Every Pwax-served route, with its method, name and
  URL. `--all` includes the application's own routes. The package's routes are the ones
  a developer reaches for when a 404 lands on `/__pwax__/manifest.json`, and a
  `pwax:routes` style answer belongs next to `route:list`.


- **`pwax:install --push` and `--service-worker` flags.** `--push` publishes the
  annotated push-endpoint Blade view (`pwax-push` tag); `--service-worker` publishes
  the offline document the worker serves. Tags already existed; the flags are the
  way a developer reaches them without remembering the tag name.


- **`pwax:doctor` covers the rest of the silent failures.** `service_worker.scope` is
  `/` or `/<segment>` and contains no fragment; `manifest.id` does not contain a
  fragment or query string; `manifest.display` is one of the installable values
  browsers actually offer for; `service_worker.source_maps` is off in production;
  the `push_subscriptions` table exists when VAPID is configured. Each is a check
  that previously failed silently or in a place the developer was not looking.

- **The rest of the page-metadata surface.** `title()`, `description()`, `canonical()`,
  `meta()` and `property()` were the whole of what a page could say about itself, which
  leaves out most of what a page has to say. `ComponentResponse` gains four methods, each
  with an application-wide default under `pwax.head`:
  - `image($url)` — sets `og:image` and `twitter:image` together, because a page that has
    one and not the other is a page whose link preview depends on which service is
    unfurling it. Default: `head.image`.
  - `robots($directives)` — `<meta name="robots">`, with `head.robots` as the place a
    staging deployment says `noindex, nofollow` once rather than on every route. It is
    applied whether or not Open Graph derivation is on, so turning derivation off cannot
    silently start indexing that deployment.
  - `jsonLd($schema)` — a `<script type="application/ld+json">` block, repeatable for a page
    that makes several claims. Default: `head.json_ld`. A page that calls it *replaces* the
    default rather than adding to it: an `Article` and an `Organization` are two claims
    about two different things, and emitting both against one URL says the page is both.
  - `alternate($hreflang, $href)` — `<link rel="alternate" hreflang>`, so a localised page
    is not competing with its own translations in the index. Default: `head.alternates`,
    which takes the map spelling (`['fr' => '/fr']`).

  All four are rendered into the document — so a prerendered page carries them for the
  crawler that does not run JavaScript — and all four travel in the payload, so a
  client-side navigation replaces them. That last part is why they belong here rather than
  in `@stack('pwax-head')`: a hand-written `ld+json` block in the stack is rendered once and
  never replaced, so from the second page onwards it describes a page the visitor has left.
  Stale structured data is not a missing rich result, it is a wrong one.

- **`og:locale`, derived from the application locale.** In Open Graph's underscored form,
  so a localised application declares its locale once rather than in three places that can
  disagree. Overridable with `head.locale`.

- **URLs in Open Graph tags are made absolute.** `og:image`, `og:url`, `twitter:image` and
  the rest are resolved against `app.url` when given a site-relative path. A scraper reading
  the tag does not necessarily have the document to resolve one against, and the failure is
  a link preview with no image rather than an error — so nobody finds out until someone
  shares a link.

- **`pwax:doctor` checks the document head.** No sharing image configured; a `head.robots`
  that would `noindex` the whole site; `head.json_ld` with no `@context`; an alternate with
  no URL. Every one of them concerns a tag nobody on the team ever looks at.

### Fixed

- **`sync.enqueue()` accepted a cross-origin URL and queued the CSRF token for it.** The
  headers stored with a queued write are the ones the runtime sends, this session's CSRF
  token among them, and the worker replays them verbatim from a context the page cannot
  see — so a URL on another origin, whether a typo or a third-party API somebody meant to
  call, handed that token away. `push.js` refuses exactly this for `pwax.push.endpoint`,
  in the same words; the queue had no such guard. It now returns `false` and says why.

- **A queued write that met a 419 could never succeed.** `RETRYABLE` keeps 419 out of the
  set of statuses that count as the server answering, precisely so a write that sat offline
  past `session.lifetime` is not deleted — but the replay re-sent the *stored* headers, so
  the retry presented the same dead token and got the same 419, for ever. The entry was
  immortal, the write never landed, and the "3 changes will send when you are back online"
  counter never moved. The worker now asks an open page for the session's current token
  before it drains the queue (once per drain, not once per entry) and swaps it into each
  entry's headers. With no page open — a genuine Background Sync wake — the stored token is
  still used, which is where this started, so nothing is worse than before.

- **Resource hints for configured plugins and directives were never emitted.**
  `Shell::modulePreloads()` read `pwax.plugins` and `pwax.directives`; the group moved under
  `pwax.vue.*` in 5.0, so those keys no longer exist and the reader was handed an empty
  array. The entries lost were the most load-bearing on the list — the runtime `await`s
  plugins and directives *before* it mounts, so each was a serial round trip on the critical
  path with no hint at all. Nothing failed: a missing resource hint costs a round trip and
  never an error, and the test that should have caught it set the old key too. It now
  asserts that the hints and the runtime config read the same place, whatever it is called
  next.

- **The `<noscript>` rule that hides the preloader carried no CSP nonce.** Under a strict
  `style-src 'nonce-…'` it was refused — and that one rule lifts an opaque, full-viewport
  cover off the "enable JavaScript" message beneath it, so the only visitor who ever reaches
  that markup was left looking at a spinner that would never stop. `Shell::nonce()` also
  resolved the value afresh for each of its callers, so a `csp.nonce` callable minting one
  per call gave the head, the foot, the shell and the runtime config four different nonces
  where the header names exactly one; it is memoised per shell now.

- **The SSR prerender memo grew for the life of the worker.** `Prerenderer` is a singleton
  and its memo was documented as per-request. It is per *worker*: under `php artisan serve`
  the container is torn down after every request and the growth is invisible, but under
  Octane, FrankenPHP or Swoole it accumulated a rendered HTML document plus its serialized
  state per distinct page for as long as the process lived — which is the deployment SSR is
  most likely to be running in. It is bounded now, evicting oldest-first.

- **A prerendered page whose mount element holds anything else is repaired, and says so.**
  Vue hydrates from `container.firstChild`, and its recovery from a mismatch there is to
  build the application afresh *before* that node and leave the server's copy where it is —
  so the visitor sees the whole page twice. The shipped shell emits the markup as the only
  child and the runtime trims edge whitespace, but a published shell that indents it, wraps
  it in a `@yield`, or leaves a comment beside it can still put something there. The runtime
  now removes the orphaned copy and names the cause in the console, rather than leaving a
  doubled page to be diagnosed from the screen. A leading comment is still not trimmed
  automatically: an application that overrides `pwax.blade.content` with a multi-root
  template makes `<!--[-->` a node hydration genuinely expects.

- **A template's HTML comments no longer break hydration.** `comments` defaults to whether
  the compiler is a development build, and the bridge deliberately runs the development one
  so that Vue's resolution warnings exist. Left to that default the server kept every
  `<!-- … -->` in a template while the browser — compiling the same template with the
  production runtime Pwax ships — dropped them, so any component containing a comment
  hydrated with a mismatch. Both `bin/ssr.mjs` and `bin/compile-templates.mjs` now pin it to
  what the browser does.

- **A changed content template is not served from the prerender cache.** The bridge wraps the
  page in `pwax.blade.content` and builds the page component from the loader and error
  markup, so what it renders depends on all three — and changing that layout is the ordinary
  reason to publish the view. Keyed without them, the prerender kept being built from the
  previous layout.

- **A page that uses `@pwaxImport` can be prerendered.** The directive compiles to
  `window.pwax.component("/__pwax__/c/….js")`, evaluated as the page's module loads — and
  Node has no `window`, so the bridge died with `ReferenceError: window is not defined`
  before rendering anything. Since the fallback is the SPA shell, every page with a
  sub-component silently opted itself out of SSR while looking configured for it. The PHP
  side now walks the component graph and sends each imported component's source with the
  payload (transitively, cycles included — the browser fetches those URLs over HTTP and Node
  cannot, since they are routes on the application serving the request; their digests join
  the prerender cache key, because an `@pwaxImport` URL carries the imported view's *name*
  and an edited sub-component would otherwise be served from cache indefinitely), and the
  bridge
  rewrites that one emitted expression rather than declaring a `window` global. Declaring
  one would be worse than the bug: `typeof window === 'undefined'` is *the* server check,
  and a component using it correctly would start taking the browser branch and reading
  `window.innerWidth` as `undefined` — no error, a plausible value, and markup that
  disagrees with the browser's. An imported component's stylesheet is inlined in the head
  alongside the page's own, for the same reason the page's is.

- **A prerender that cannot be trusted fails instead of shipping.** An unresolved component
  rendered as a literal `<MysteryThing></MysteryThing>` element, an unresolved directive was
  silently dropped, an async component that could not load became an empty comment, and a
  `<Teleport>`'s children were left out of the markup altogether — in every case `ok: true`
  and HTML that merely *differs* from what the browser builds, which a crawler then indexes
  and the visitor's browser throws away. All four now fail the prerender, so the SPA shell is served instead, which is only slower. The bridge pins Vue's
  development build to keep the checks working: `warn()` is stripped from the production
  build, so on a server with `NODE_ENV=production` the two resolution failures had no
  symptom at all. The rendered HTML is byte-identical between the builds.

- **The bridge says what a developer can change.** `document is not defined` names a Node
  fact rather than a Vue one and arrives with a stack trace through a `data:` URL pointing
  at no file anybody wrote. Browser globals reached during render, and unresolved components
  and directives, are now reported in terms of the cause and the remedies — `mounted()`, a
  `typeof` guard, `@pwaxImport`, or `->spaOnly()`.

- **A prerendered page is hydrated rather than rendered a second time underneath itself.**
  Vue hydrates from `container.firstChild`, and the shell indented the prerendered markup
  inside `<div id="pwax">` — so that first child was a whitespace text node where the
  virtual DOM expected an element. A mismatch on the container's very first node is not one
  Vue recovers gracefully from: it drops the text node, renders the whole application from
  scratch *before* the next sibling, and leaves the server's markup exactly where it was.
  The visitor saw every prerendered page twice. The shell now emits the markup as the mount
  element's only child, on one line, with the `@if` outside the element rather than inside
  it; and the runtime strips whitespace-only text nodes from the element's edges before
  hydrating, so a shell an application has published and reformatted — or published before
  SSR existed — cannot reintroduce it. A leading comment is deliberately left alone, since
  an application that overrides `pwax.blade.content` with a multi-root template makes
  `<!--[-->` a node hydration genuinely expects.

- **A page's stylesheet is replaced on navigation instead of the first page's being kept.**
  The style manager counts references per key, and every page acquired its stylesheet under
  the same constant key `pwax:page` — which reads like an identity and is not one. `mount()`
  acquires the incoming stylesheet *before* releasing the outgoing one, deliberately, so the
  swap never leaves a frame with neither applied; under one shared key that overlap meant
  the second acquire found the existing entry, incremented its count and returned. From the
  second page onward, every visitor got the first page's rules and none of their own. The
  key now carries the component's digest (`pwax:page:<hash>`), so each page's stylesheet has
  its own identity and the acquire/release pair does what it says. Scoped styles hid the
  symptom — their selectors do not match another page's elements — so it showed up only on
  components with an unscoped `<style>` block.

### Changed

- **`head.twitter_card` defaults to `null` and follows the image.** A card declaring
  `summary_large_image` with no image renders as a bare summary anyway, and a `summary`
  beside a 1200x630 image throws most of that artwork away — so left null the card follows
  the one fact that decides which of the two is right. A `config/pwax.php` published before
  this carries a literal `'summary'` and keeps exactly the tag it had; set the key
  explicitly to pin one spelling for every page.

- **`plugins`, `directives` and the client-side middleware now live under `pwax.vue.*`.**
  The two configs that shared the word "middleware" — `pwax.middleware` (the Laravel
  groups a page route runs through, server-side) and `pwax.middleware_js` (the Vue
  route middleware, client-side) — were always going to be confused. Putting the
  three Vue extensions under one group lets the client-side one keep its natural
  name (`vue.middleware`), groups every piece of client-side Vue configuration in
  one place, and means no rename ever has to disambiguate from the server side
  again.

  ```diff
  - 'plugins' => [...],
  - 'directives' => [...],
  - 'middleware_js' => [...],
  + 'vue' => [
  +     'plugins' => [...],
  +     'directives' => [...],
  +     'middleware' => [...],
  + ],
  ```

  The rename is straightforward: a published `config/pwax.php` is the application's
  file, not the package's, so an upgrade cannot rewrite it. The migration is one
  `grep -rn 'middleware_js' config/` and a copy.


- **Page transitions use the browser's View Transitions API.** The Vue
  `<transition mode="out-in">` wrapper was the source of the empty router-view
  flicker: even with `transition.duration: 0`, two-phase mount/unmount left a
  frame where the outgoing page was gone and the incoming page had not yet
  rendered. The runtime now wraps the swap in
  `document.startViewTransition`, so the browser snapshots the outgoing page,
  commits the new one, and cross-fades between them in a single frame. Browsers
  without the API fall back to a synchronous swap, which is the previous
  behaviour preserved. `transition.duration` is the cross-fade length rather
  than a Vue transition class; `0` is now an instant swap, not a transition
  with empty classes. `prefers-reduced-motion` is honoured by the browser
  itself.


- **A navigation that fails for a reason other than an HTTP status now says so in the
  console.** The visitor is still shown "this page needs an internet connection", which is
  what they can act on and is true nine times out of ten. The tenth is a bug or a
  misconfiguration — a component that will not compile, a middleware that threw — and it was
  reported to the developer as a network problem, which is the wrong place to start looking.
- **The service worker is built, not templated.** It was 1,611 lines of JavaScript inside a
  Blade file — never linted, never formatted, never minified, and testable only through a
  hand-written Blade emulator that its own docblock admitted was crude. It is now
  `src/js/sw/index.js`, built by esbuild to `dist/pwax-sw.js` and served behind a small
  generated preamble carrying the four values the server actually decides. Served bytes
  drop from ~55 kB of commented source to ~13 kB, on a file refetched on every update
  check.

  Linting it for the first time immediately found a dead parameter.

  `service_worker.extend` is the supported way to add a `push` or `sync` handler now —
  views or files appended after the worker, sharing its scope — instead of forking the
  whole thing to add ten lines and never receiving a fix again.
  `service_worker.blade` still replaces the worker outright and always will, so a fork
  made against 4.0 keeps working.
- **The offline document is a Blade view**, `pwax::js.offline`, rather than forty lines of
  HTML in a JavaScript string. It picks up the application's `lang` and `dir` — it was
  hardcoded to `lang="en"` — and publishes on its own with
  `vendor:publish --tag=pwax-service-worker`.
- **One vocabulary for every strategy.** Four config keys answered "when do we go to the
  network?" in three different languages — `runtime_strategy` said `network-first` where
  `pages.strategy` said `freshness` for the same behaviour, and `navigation_strategy` said
  `app-shell` where both meant `cache-first`. They now share one set of names, the ones the
  rest of the web uses. `freshness` is `network-first`, `performance` is `cache-first`, and
  `app-shell` is `cache-first`.

  Every old spelling still resolves and will for this major cycle; `pwax:doctor` names the
  ones still in use. Normalising happens server-side, so the manifest only ever carries the
  new vocabulary and the worker knows one set of words.
- **`pwax.assets.strategy` is `pwax.assets.source`.** It chooses where the framework is
  served from — `local` or `cdn` — and nothing about caching, so it was the fifth key called
  "strategy" in a config where the other four mean something else. The old key still works.


- **One set of caches, named for the build.** `pwax-precache-<build>`,
  `pwax-pages-<build>`, `pwax-documents-<build>`, `pwax-runtime`, `pwax-lazy` and
  `pwax-data-<group>-v<n>`, where `<build>` is the manifest's content hash. No per-visitor
  names, no per-visitor sets: one cost a fresh copy of the application per person and an
  empty set minted on every sign-in, and it never delivered the isolation it was named
  for. `service_worker.identity_cache_limit` is gone with the per-person sets it bounded;
  `pwax:doctor` names it.
- **`data_groups[].version` now names the group's cache**, which is the only thing it was
  ever for. It reached the manifest and was read by nothing: bumping it changed the
  manifest hash, so it re-precached the entire application on every client, and left the
  one cache it was meant to discard exactly as it was. Cache names carry the version, and
  `activate()` sweeps the versions no group claims any more. Upgrading orphans each
  existing data cache once; they repopulate on the next request.


- **A stored copy is used when the origin cannot be reached through, not only when the
  network throws.** A proxy that cannot get an answer out of the application, or an
  application mid-deploy, produces a *reply* — so the fallback never ran and the visitor
  saw an error with a usable copy on the device. The rule applies everywhere there is
  something to fall back to: page payloads, data groups, full navigations — which answer
  from the stored document, so a reload during a deploy still gets the installed
  application — and the runtime cache.

  Exactly `502`, `503` and `504`, none of which is distinguishable from a bad connection
  from the device: a proxy that could not reach the application, one refusing for now
  (`php artisan down` answers `503`), and one that waited and gave up. **A `500` is shown,
  like a `404`** — the application ran and threw, and answering that from cache hides the
  bug twice: the visitor sees a page that works and reports nothing, and whoever deployed
  it does not learn the route is broken. When a stored copy does stand in, the worker says
  so on the console with the status and the URL.
- `service_worker.strategy` is `service_worker.runtime_strategy`, and defaults to
  `network-only`. The old default kept a copy of every same-origin GET it passed through,
  including URLs the application never declared.
- Data groups are written flat, and `max_size` is `max_entries` — the same quantity that
  the pages block and the runtime cache already spelled that way.
- `pwax.sw.registration` is `pwax.sw.controller`; `pwax.sw.registration()` returns the
  registration.
- Navigation preload is consumed rather than discarded, so a navigation the worker declines
  to handle no longer costs the server two requests.
- The view-tree walk and the route walk happen once per manifest build rather than two and
  four times.
- Middleware modules no longer delay the first paint. Plugins and directives still do,
  because Vue offers no way to register either after mount.


- **BREAKING:** `pwax_component()` is now `pwaxRender()`, `pwax_route()` is `pwaxRoute()`,
  and the `@pwax` directive is `@pwaxImport`. `Pwax::importExpression()` is `Pwax::import()`.
  Each helper is now named after the facade method it wraps. See `UPGRADE.md`.
- **BREAKING:** the 1.x `vue()` and `router()` helpers and the `pwax.helpers.global` config
  key are removed. They were deprecated in 2.0.
- **BREAKING:** the service worker moves from `/service-worker.js` to `/sw.js` and the web
  manifest from `/manifest.webmanifest` to `/manifest.json`. No redirect or shim is shipped
  for either — a worker script response cannot be a redirect, so a worker already registered
  at the old path has to be unregistered once in the browser. See `UPGRADE.md`. Installs
  survive the manifest move: its `id` defaults to `start_url`.
- **BREAKING:** `pwax.components.directive` now replaces the default name rather than
  registering a second directive alongside it, so an application has exactly one spelling.
  The 1.x `@import('…')` form is no longer special-cased in config values.
- **BREAKING:** `service_worker.precache` becomes `service_worker.pages.urls` and
  `service_worker.files` becomes `service_worker.asset_groups`.
- Page payloads may now be stored by the service worker when a route opts in, where
  previously `no-store` kept every one of them off disk. The three controls that make that
  safe — the opt-in itself, anonymous install-time fetches, and identity-partitioned caches
  — are described in the published config.

### Removed

- **The retired strategy spellings `freshness`, `performance` and `app-shell`.** They were
  3.x names kept working as aliases through 4.x so that merging four vocabularies into one
  was a readability change rather than a breaking one. That cycle is over. A config that
  still uses one is now an unrecognised value that falls back to the key's default, and
  `pwax:doctor` **fails** on it — as it does on any value outside `network-only`,
  `network-first`, `cache-first` and `stale-while-revalidate`, a plain typo included.
  `Strategy::ALIASES` and `Strategy::isDeprecated()` are gone; `Strategy::isUnknown()`
  replaces the latter.
- **The `pwax.assets.strategy` fallback.** `assets.source` replaced it in 4.1 and is now
  the only key read. Worth checking even if you never set it deliberately: an application
  that published its config before 4.1 has `strategy` and no `source` at all, so upgrading
  without renaming moves the framework from a CDN to the local origin — which works, and is
  not what was configured. `pwax:doctor` fails on a leftover key for that reason.

  See [UPGRADE.md](UPGRADE.md) for the copy-pasteable diffs.

### Fixed

- **Stale documentation for two removed keys.** The README documented
  `transition.name` as "kept for back-compat" — it was removed along with its plumbing when
  page transitions moved to the View Transitions API, and nothing reads it, so anyone
  setting it got silence. The `transition` block's own doc comment in `config/pwax.php` had
  also drifted away from the array it describes and sat above `prefetch` instead.

- **A page that declared no metadata inherited the previous page's.** `title` and `head`
  were sent in the payload only when the route had declared something of its own, so
  navigating to an ordinary page left the tab showing the title of the page before it,
  along with that page's canonical URL and Open Graph tags — for the rest of the session. A
  reload of the same page showed the resolved fallback instead, so the SPA and the document
  disagreed about what the page was, which is the exact drift `Head` exists to prevent.

  The title is the visible half: `document.title` is a browser tab, a bookmark and a
  history entry, and it is also the string the runtime reads into its live region after
  every navigation — so a screen-reader user was being told the name of the page they had
  just left. Both fields are now sent on every page.

  The omission was deliberate, on the reasoning that an empty head would wipe the
  application-wide description. It does not: `applyHead()` returns early on an empty
  description rather than clearing it, so the only thing the omission achieved was the
  drift. Two runtime tests now pin that down, and a page that declares no canonical URL
  correctly has the previous one removed.

- **Offline writes were silently deleted when the session expired.** An entry in
  `window.pwax.sync`'s queue carries the CSRF token that was current when it was queued,
  so anything that sat offline longer than `session.lifetime` came back `419` on its first
  replay — every time, by construction. The worker read any 4xx as a real answer from the
  server and dropped the entry, which meant the queue reliably destroyed exactly the writes
  it exists to protect: the ones queued for a long time. Silently, with no way for the
  application to find out.

  `419`, `408`, `425` and `429` are now kept and retried; every other 4xx is still treated
  as an answer and dropped. The retry succeeds on the next replay from a page that has
  since refreshed the session.

- **One unreadable queue entry blocked every write behind it, forever.** `replay()` walks
  the queue in order and parsed each entry without a guard, so a truncated write — or one
  left by an older build — threw out of the replay on every sync and nothing after it ever
  sent. Unreadable entries are now dropped and the walk continues.

- **A failed push subscription report said nothing.** The runtime posted the subscription
  to `pwax.push.endpoint` and ignored the result, so an endpoint that 500'd, or that could
  not be reached, left the browser subscribed and the server unaware it existed. Every push
  the application believed it sent went nowhere, and the symptom — notifications that never
  arrive — is indistinguishable from a bad VAPID key. Both cases now log an error naming
  the status and what it means.

- **`window.pwax.start()` was missing from the one situation it exists for.** It was
  assigned at the end of `boot()`, so a boot that *threw* — Vue loaded after `pwax.js`, a
  plugin module that 404'd, no mount element — never reached the assignment, and the
  documented way to retry was absent precisely when the application had failed to start.
  It is now armed on the failure path too.

- **`pwax.push.endpoint` is now required to be same-origin.** The subscription is posted
  with `http.headers()`, which carries the session's CSRF token, so a cross-origin value —
  a typo, a copied example — would have handed that token to another origin. A cross-origin
  endpoint is refused and logged rather than sent.

- **A page's HTML was only ever cached by visiting it.** A page answers two ways — a JSON
  payload to the client runtime, an HTML document to a browser navigation — and the install
  fetched only the payload. Every route the visitor had not personally opened therefore had
  no document at all: offline, or on a cold start, they got the shell and a spinner while
  the runtime fetched a payload that was already sitting on disk, instead of the HTML the
  server had already rendered.

  The install now fetches both halves. `service_worker.pages.documents => false` opts out,
  and `pages.runtime => false` still overrides everything — it is documented as keeping
  rendered markup off disk, so a deploy must not start writing documents when it is set.
  `->offline(false)` is honoured on this path as on every other.

  A test named "answers an offline navigation with the page's own document" had been passing
  for two releases: the fake shell and the fake page document both contained the string it
  asserted on, so it could not tell which one came back — and it was the shell. It asserts on
  the URL inside the document's own island now, and fails against the old behaviour.

- **`pwax:doctor` now warns when more pages are precached than `pages.max_entries` allows.**
  The page caches are bounded and evicted oldest-first, and the install fills them before any
  browsing does — so with the default of 60 an application with more routes than that had the
  install's own work thrown away by the visitor's first few navigations.


- **`/manifest.json` had no `Vary: Accept-Language`.** Its `lang`, `name` and `description`
  all follow the application locale and it is served `public, max-age=86400`. The route sits
  outside the `web` group, so today nothing sets a locale before it renders — but that is a
  property of `routes.static_middleware`, which is a config key, and one header is cheaper
  than the shared-cache bug that adding locale middleware there would otherwise create.

- **The "Vue is not loaded" error still said the runtime-only build could never work.** It
  has been serviceable since `pwax:compile` landed.


- **A CDN subresource-integrity map keyed only on the package name.** Vue publishes two
  builds and Pwax can serve either, so `assets.cdn.integrity['vue']` would have sent the
  full build's digest with `vue.runtime.global.prod.js` — and a browser refuses a script
  whose digest does not match, which is the whole application failing to start. The map now
  accepts a filename key, which wins over the package name, and ships the runtime build's
  hash.
- **The client runtime bundle could never update in a browser that had cached it.**
  `/__pwax__/pwax.js` carries no version in its URL and is served `immutable`, which tells
  a browser not to revalidate for a year — not even conditionally, so its ETag was never
  consulted. Upgrading the package left returning visitors on the runtime they first
  downloaded. Invisible with the service worker on, since that precaches by content hash;
  entirely visible with it off, which is the default. The URL is now fingerprinted by the
  bundle's contents, and the source map is revalidated rather than cached hard so it cannot
  be paired with a newer bundle.
- **Every lazily-cached asset was discarded on every deploy.** A lazy asset group is where
  the big files go — `/images/**`, `/fonts/**` and `/media/**` are the shipped defaults —
  and the point of declaring one is that what it fetches is then kept. It was kept in the
  precache, which is named for the build and deleted wholesale by the next one, and lazy
  entries never enter the install set so nothing carried them across. So a release that
  changed one component re-downloaded every image and font on the device: precisely the
  churn the delta install exists to prevent, applied to the largest files in the
  application. They now live in `pwax-lazy`, which survives a deploy the way the runtime
  cache does, and only entries whose own content hash changed are acted on.
- **`asset_groups[].update_mode` did nothing at all.** Documented as choosing what happens
  to a changed entry on the next deploy, it was emitted into `sw.json` and never read —
  while still forming part of the manifest hash, so editing it forced every client to
  re-precache the whole application for no change in behaviour. It now selects what the
  install does with a changed file the device already holds: `prefetch` brings it up to
  date there and then, `lazy` drops it for the next request to fetch. Files the device
  never asked for are still not fetched — that is `install_mode`'s decision, not this one.
- **The compiled-component cache grew without bound on a personalised page.** The cache
  key is a digest of the rendered output, which is what makes an entry impossible to serve
  stalely — a changed component simply produces a new key. For a page rendered with
  controller data it inverts: the output is particular to the request, so every visitor
  minted an entry, written with `forever()`, that no later request could ever hit. On a
  busy application that is unbounded growth driven by traffic, on a store whose only purge
  is to flush everything. Renders given no data are stored as before; renders given data
  are not, unless the route calls `->cacheable()` — which already declares the page renders
  the same for everyone.
- **A component compiled twice in one request reached the cache store twice.** Two
  `@pwaxImport`s of the same component, or a caller reading `toArray()` before returning
  the response, each paid a full round trip to Redis for an answer already in hand. A
  bounded in-process memo answers the second one.
- **A page waited for its own cache write before it was delivered.** `page()` opened a
  cache, wrote to it and walked its keys to trim, all before returning the response the
  runtime was waiting on; a data group's response paid three storage round-trips the same
  way. None of that work is for the current visit. It moves to `event.waitUntil`, where
  `navigate()` already put it.
- **`trim()` walked every key in the cache on every single write.** `cache.keys()`
  materialises a `Request` per entry, so a sixty-entry page cache built sixty objects per
  navigation to discover that nothing needed deleting. An advisory counter, seeded from
  one real walk, gates the check; nothing is deleted without the real walk still running.
- **The install probed each previous build in turn for every unchanged asset.** With three
  builds retained that is up to three serial storage round-trips per file, inside a
  six-way limiter, on the one path a deploy is supposed to make cheap. The manifest scan
  that already reads those caches now records which one holds each URL.
- **A new page component type was minted on every navigation.**
  `defineAsyncComponent(() => Promise.resolve(options))` wrapped an object that was
  already resolved, costing a microtask and a render pass in which the page was truthy but
  drew nothing — and, because Vue compares component types by identity, making a return to
  an already-visited path unmount and rebuild from scratch.
- **The whole application was announced as "Loading".** The mount element carried
  `role="status"`, `aria-live="polite"` and `aria-label="Loading"` for the spinner, and the
  runtime removed only the class on mount — so for the rest of the session every reactive
  text change anywhere in the app was read aloud by a screen reader.
- **A route change told a screen reader nothing.** The shell now carries a live region and
  the runtime announces each navigation's title into it.
- Function default exports are returned as themselves, so client middleware and Vue
  functional components work. Spreading a function into an object produced `{}`.
- `pwax.sw.registration` returned the controlling `ServiceWorker`, not the
  `ServiceWorkerRegistration` — see **Changed**.
- `dist/pwax.js.map` is served. The bundle has always ended with a `sourceMappingURL`
  comment pointing at a route that did not exist.
- A `navigation_urls` pattern that will not compile is skipped with a warning instead of
  thrown, where it previously turned every navigation in the application into the offline
  page.
- **An expired CSRF token could reload the page forever.** A `419` is answered by reloading
  to pick up a fresh token, which assumes the reload reaches the server — and under
  `navigation_strategy => 'app-shell'` it does not, because the worker answers navigations
  from disk and returns the same expired token. One reload per tab now, re-armed whenever a
  page loads successfully; a second `419` renders the error template.
- `pwax.sw.applyUpdate()` no longer depends on `this`, so `const { applyUpdate } =
  window.pwax.sw` works.


- **Client middleware was always "unknown".** A middleware is written as
  `export default async function (…) {}`, exactly as the README shows, but the module
  loader spread every default export into a fresh object to merge in the Blade template.
  A function has no own enumerable properties, so the spread produced `{}` and
  `runMiddleware` reported `pwax: unknown middleware "name"` for a middleware that had
  loaded perfectly. A function default export is now returned as itself, which also fixes
  Vue functional components silently rendering nothing.
- **`service_worker.precache` never worked.** The worker fetched each listed route without
  the `X-Pwax-Component` header, so the server answered with the HTML shell; that shell is
  `no-store`, which the worker correctly refused to store. Every entry was skipped in
  silence while the documentation described the feature as working.
- **Cached page entries could never be matched.** Responses carry
  `Vary: X-Pwax-Component, X-Requested-With, Accept`, and entries were stored under a bare
  URL with none of those headers set.
- **The offline shell's hash ignored the shell.** It was computed from a list of config
  values and `Pwax::shell()` — which returns the shell's *view name*, a constant. Editing
  the shell layout, either `includes/` partial or any Blade override left it unchanged, so
  the worker copied a stale shell forward across every deploy.
- **Configured `pwax.styles` sheets were never precached**, so an application with them set
  went offline unstyled.
- The service worker fetched precache URLs with `credentials: 'same-origin'`, so an install
  triggered while signed in would have stored that user's private renderings once page
  caching worked. Pages are now fetched anonymously.
- `resources/views/layouts/shell.blade.php` discarded the `Shell` passed to it and resolved
  a new one from the container, making the parameter dead and blocking a request-free
  render.
- `AssetManifest` compared against a hardcoded `/manifest.webmanifest` default that differed
  from the one it emitted, so a configured manifest path went unhashed.
- The `Pwax` facade documented `payload()`'s second argument as `$includeScript`; it has
  been `$addressable` since 2.0.
- **`window.pwax.start()` was documented but did not exist.** AGENTS.md listed it as part
  of the public JavaScript API, but `start()` in `index.js` was local and never assigned to
  `window.pwax`. A consumer calling it got `undefined is not a function`. The runtime now
  exposes `start()` — which unmounts the current Vue app, resets the module cache, and
  re-runs the full boot sequence, returning a Promise that resolves when the reboot
  completes. The type declaration in `types/pwax.d.ts` was updated to match.
- **`pwax.push.private_key` was checked by the doctor but never defined in config.**
  `DoctorCommand::checkPush()` validated the private key's shape, but `config/pwax.php`
  had no `private_key` entry — so the check always silently skipped. The config key now
  exists (mapped to `VAPID_PRIVATE_KEY`), and `pwax:vapid` names it alongside `public_key`
  and `endpoint` in its guidance. Pwax itself does not use the private key (it is the
  browser half only); the config key exists so the doctor can validate it and the
  application's own push-sending code can read it.

### Deprecated

- **`$pwaxTitle` in the shell view.** The published `ComponentResponse` passes both
  `pwaxHead` and `pwaxTitle` to the shell; the second is always equal to
  `head->title` and exists only so a shell published before `pwaxHead` was added
  still has a variable to read. It will be removed in the next major version. The
  published shell already reads `pwaxHead->title`; an application that has
  customised `resources/views/vendor/pwax/layouts/shell.blade.php` should do the
  same now.

### Documentation

- The JavaScript API table now lists `pwax.start()` (the reboot API added above) and
  `pwax.import(url)`, and adds `pwax.sw.controller` and `pwax.sw.registration()` which
  were already on the runtime object but missing from the table.
- The push config example in the README now includes `private_key`, matching the config
  key added above. The AI skill file (`resources/ai/pwax-skill.md`) mentions
  `pwax.push.private_key` and explains why Pwax validates a key it does not use.
- The README's note on `pwax.import` / `@pwaxImport` / `pwax.importModule` now clarifies
  that `importModule` is the internal function name and `pwax.import` is the public API,
  so a developer looking for `pwax.importModule` on `window.pwax` is not confused.
- **How to use push, end to end**, which was previously one line in a code block: generating
  keys, the config, the exact JSON your endpoint receives, a worked controller, what the
  worker does with a payload, and why sending is left to
  `laravel-notification-channels/webpush`.
- **How to extend the service worker**, which stopped being obvious when it stopped being a
  Blade view. `service_worker.extend` appends your own handlers into the worker's scope and
  was documented only in a comment in the published config; `service_worker.blade` still
  replaces the worker outright. Both are in the README now, with what each one's view
  receives.


- The README documented none of `pwax.install`, `pwax.badge`, `pwax.storage`, `pwax.push` or
  `pwax.sync` — they shipped with an entry in this file and nothing a reader would find.
  The JavaScript API table now lists the whole surface, and there is a section on what an
  installed app can do and on being opened by the operating system.

- **`php artisan pwax:compile` — an opt-in precompile mode.** Templates are compiled to Vue
  render functions at deploy time instead of in the browser, which buys back both of the
  costs the README lists for the no-build model: `assets.vue_build => 'runtime'` then serves
  `vue.runtime.global.prod.js` (40.6 kB gzipped against 60.7) and
  `script-src 'unsafe-eval'` can go.

  The render function is emitted **into the component module as source**, not handed over as
  a string — the module loader evaluates it, so nothing calls the `Function` constructor,
  which is the entire point. A page ships its script inline rather than at a URL, so its
  render function travels inside that script.

  Strictly opt-in, never added to `pwax:install`, and the zero-build path is untouched.
  Never having compiled is not an outage: the store is empty, `Shell` serves the full build,
  and the application behaves as though you had not opted in. Compiling and then editing a
  component *is* one, so `pwax:doctor` checks for exactly that and names the components
  whose templates no longer have a render function. It also warns when render functions are
  compiled but unused, and errors when the runtime build is asked for with nothing compiled.

  Needs Node and `@vue/compiler-dom` at the pinned Vue version — declared as an optional
  peer dependency, refused by the compiler script when the versions disagree, because a
  mismatched compiler emits code that fails at render time in the browser naming neither.
  One constraint: a template must be the same for every visitor, so keep controller data in
  `<script>` and out of `<template>`. `pwax:compile` names any view that breaks it.

- **Prefetch on intent.** A visitor says where they are going before they go: the pointer
  lands on a link a few hundred milliseconds before the click, and a keyboard user focuses
  it first. That time now goes on the request, so the navigation feels instant — the same
  request, sent earlier. On by default, `prefetch.mode => false` to turn it off, and
  `data-pwax-prefetch="off"` on a link that should not be.

  Bounded rather than cached: payloads stay in memory, capped at eight, dropped after
  thirty seconds and given up when taken. A page payload can carry a signed-in visitor's
  data, and storing pages is the service worker's job — with rules about it.
- **TypeScript definitions**, hand-written at `types/pwax.d.ts` and shipped in the Composer
  package, covering the whole `window.pwax` surface and every `pwax:` event. Checked by
  `npm run types` in CI, because shipping types nobody verifies is how they go wrong.
- **Install prompt, badge and storage.** `pwax.install.prompt()` shows the browser's
  install prompt at a moment the application chooses; `beforeinstallprompt` is captured
  before the runtime does anything else, because it fires once, early, and is never
  replayed. `pwax.install.standalone` answers whether this window is an installed app —
  `display-mode` where it exists, `navigator.standalone` for iOS, which has neither the
  media query nor a programmatic install. Events: `pwax:installable`, `pwax:installed`.

  `pwax.badge.set()/clear()` for the app-icon badge, a no-op where the platform has none.
  `pwax.storage.estimate()/persisted()/persist()` — worth knowing about here specifically,
  because a precache evicted under quota pressure is what users report as "it stopped
  working offline". Persistence is never requested for you: on some platforms it is a real
  prompt, and spending it is the application's decision.
- **Web Push.** `pwax.push.subscribe()/unsubscribe()`, the VAPID key conversion, and
  `push` / `notificationclick` handlers in the worker — a click focuses a window already on
  the target rather than opening a second one. Configure `push.public_key` and an endpoint
  that persists what the browser posts.

  Deliberately only the browser half: storing subscriptions and sending to them is what
  `laravel-notification-channels/webpush` does, and a second implementation inside a PWA
  package would be a worse one.
- **Background Sync.** `pwax.sync.enqueue()` stores a write when there is no network and
  the worker replays it when there is — falling back to an immediate replay on browsers
  without Background Sync, which is both Safari and Firefox. Nothing is queued
  automatically: intercepting failed writes would replay a payment as readily as a draft,
  and only the application knows which of its requests repeat safely. `pwax.sync.pending()`
  is there to build "3 changes will send when you're back online".
- **Per-page document metadata.** `->description()`, `->canonical()`, `->meta()` and
  `->property()` on the response, alongside the existing `->title()`. Open Graph and
  Twitter card tags are derived from the title, description and canonical URL — nothing is
  invented, a tag is emitted only where a value already exists, and a page that set one by
  hand keeps its own. Turn derivation off with `head.open_graph => false`.

  All of it travels in the payload as well as the document, so a client-side navigation
  updates it. A browser replaces the head on a real navigation and a router does not: a
  title that moves with the route and a description that stays behind is worse than
  setting neither, because the wrong answer outlives the missing one. Only tags Pwax
  emitted are replaced — they carry `data-pwax-head`, so anything in
  `@stack('pwax-head')` is left alone.

  This does not make an application crawlable. Page content is still compiled in the
  browser from a JSON island; these tags are for the crawlers that run JavaScript, and for
  link unfurling.
- **`<html dir>`**, from `pwax.manifest.dir` and defaulting to `auto`. The language was
  declared and the layout still ran left-to-right, which is half of what a right-to-left
  locale needs.
- **A skip link, and focus moves on navigation.** The announcer restored the screen-reader
  signal a router loses; this restores the other half. Focus moves to the application root
  on each navigation — unless the new page claimed it in `mounted()`, which is a page
  saying it meant to — so Tab order and the skip link behave the way they do on a
  server-rendered site.
- **A `<noscript>` that says what is wrong.** The application renders in the browser, so
  there is nothing to progressively enhance; saying so beats a spinner that never stops.
  The preloader is hidden alongside it.
- **`php artisan about` reports Pwax**: version, whether the worker is on, where assets are
  served from, which Vue build is being served, the component cache store and the minifier.
- **Events.** `ComponentCompiled` fires on a real compile — never on a cache hit, so it
  counts what the compile cache is actually missing. `ManifestBuilt` fires on a real
  manifest build, roughly once per deploy, and carries the hash and the warning list.


- **A page's HTML is cached as it is visited, not only at install.** A page answers two
  ways — JSON to the runtime, HTML with the component inlined to a navigation — and only
  the JSON was stored after install. A route the build never precached, a dynamic one or
  anything route discovery could not reach, had no document at all, so reloading it offline
  fell back to the shell and a spinner. Documents are now kept as they are visited, in a
  cache shared across visitors like every other — so a page whose signed-in and signed-out
  renderings differ should say `->offline(false)`.
- **The modules the first render needs are named in the head.** A component imported with
  `@pwaxImport` is compiled into a `window.pwax.component('/__pwax__/c/….js')` call inside
  the page's own script, so nothing asks for it until Vue has downloaded, parsed, compiled
  this page's template and rendered it — a serial round trip after the framework is
  already up, for a URL the server knew while it was writing the document. Those now ship
  as `<link rel="modulepreload">`, along with the configured `plugins` and `directives`
  the runtime awaits *before* mounting, and any external `<script src>` or
  `<link rel="stylesheet">` the component declares.
- `pwax.cache.ttl` bounds how long a compiled component is stored. `null` keeps the
  previous behaviour of storing it forever.


- **A navigation no longer unmounts the page you are on.** The current page stays rendered
  while the next one is fetched, compiled and has its styles applied; only then do the two
  swap, with a fade. Previously the loader replaced the component the moment a navigation
  began, so every click threw away what the visitor was reading and collapsed the layout to
  a single line, twice. A failed navigation now leaves you where you were.
- **A navigation progress bar**, which is the only thing that moves while you wait. It
  waits 250 ms before appearing, eases towards a ceiling it never reaches, and completes
  before the page swaps rather than alongside it. Navigations only — a document arriving is
  the browser's own wait, and the shell's spinner already covers it. `pwax.progress`;
  `window.pwax.progress` exposes `start()` and `done()` for an application's own slow work.
- `customization.init_spinner` turns the first-load spinner off, for an application that
  renders its own skeleton into the mount element.
- **The screens have a design.** The page that would not load, the runtime that would not
  start and the worker's offline document were unstyled text on a white page; they now
  share one centred layout that works in light and dark, and each offers the way out that
  applies. Restyle them with the `--pwax-screen-*` custom properties without publishing a
  view. The offline document carries its own copy of the styles, since it answers a
  navigation to a page whose stylesheet never loaded.
- `pwax.transition` names the page transition and its duration. The bundled one fades with
  opacity alone; both it and the progress bar defer to `prefers-reduced-motion`.
- `pwax.sw.applyUpdate()` takes a waiting build immediately, and the runtime logs one line
  when one is waiting. A new worker installs and then waits for every tab to close — the
  right default, and indistinguishable from a deploy that did nothing if an application
  does not listen for `pwax:update-available`.
- `service_worker.max_entry_bytes`, bounding a single runtime-cache entry. `max_entries`
  counts entries, which bounds nothing on its own.
- **Pages work offline.** An application could precache its framework, its components and
  its shell, install as a PWA, boot offline — and still show "This page needs an internet
  connection to load", because the one thing never cached was the page. Page payloads are
  now fetched the way the client runtime fetches them and stored under that same request,
  so the Cache API's `Vary` check succeeds instead of missing every time.
- **Page discovery.** Every GET route whose action hands a literal view name to
  `pwaxRender()` is found and precached, so the application works offline before any of it
  has been visited — installing from the home page and then opening Settings offline no
  longer fails on the page nobody had opened. Scoped by `service_worker.components`, so one
  setting governs pages and components alike. `service_worker.pages.discover`.
- **Runtime page caching**, which covers what discovery cannot — a parameterised route
  someone actually opened. `service_worker.pages.runtime`.
- Precached pages store their rendered **document** as well as their payload, so an offline
  navigation paints immediately from the inlined `pwax-initial` island instead of showing
  the shell's spinner while the runtime fetches a payload it already has.
- **Asset groups** (`service_worker.asset_groups`) with `install_mode`, `update_mode` and
  glob patterns resolved against `public/`. Images, fonts, stylesheets and build output are
  precached without listing each one.
- **Data groups** (`service_worker.data_groups`) with `freshness` and `performance`
  strategies, `max_age` and `max_entries`. An offline page used to render and then fail every
  fetch it made.
- **`navigation_strategy`** with an `app-shell` option for zero-round-trip navigation, and
  **`navigation_urls`** so a path the application does not own bypasses the worker.
- `ComponentResponse::title()` for a per-page document title, applied on first paint and on
  every client-side navigation.
- `ComponentResponse::offline(false)` for a page that must never reach disk — stronger than
  omitting `->cacheable()`, which only declines to precache.
- `<title>`, `<meta name="description">`, `<link rel="icon">` and an opt-in `<base href>` in
  the head, in a fixed order.
- `pwax:doctor` and `pwax:precache` report manifest `warnings`, including a glob truncated
  by `max_files` or `max_bytes`.

### Security

- **Caches are shared across visitors, and the package now says so plainly.** 2.x named
  each cache after the signed-in visitor and documented that as making a cross-user read
  impossible. It was not: the offline fallback used a lookup that names no cache, and by
  specification such a lookup searches *every* cache on the origin — so two people sharing
  a device, the second one offline, and the worker served the first one's responses. The
  naming is gone rather than patched, because a partition that holds for writes and not
  for reads is worse than none: it is the same exposure with a guarantee written over it.
  What ships is one set of caches per build, shared by whoever uses the device, stated as
  such in the README and in `config/pwax.php`. `->offline(false)` and
  `X-Pwax-Cache: none` are how a page stays off disk entirely, and `pwax:doctor` says so
  when runtime page caching is on.
- **An empty cache was created by reading.** `caches.open()` creates, and the page and data
  paths opened theirs before knowing whether anything would be stored — so every visitor
  left behind an empty cache per group, on a device that had stored nothing. Reads go
  through `caches.has()` first throughout.
- **Catastrophic backtracking in the glob compiler.** Consecutive `**` segments compiled to
  adjacent optional greedy groups; twelve of them took five seconds to reject a
  sixty-character path, and these patterns are matched against the request URL on every
  fetch the worker handles. Runs of `**` are now folded into one, which changes no
  pattern's meaning.
- `/sw.json` builds under a lock and `routes.static_middleware` ships with a throttle.
  Those routes sit outside `web` deliberately, which also put them outside its rate
  limiting, while each manifest build walks `public/`, every view root and every route.
- Every Pwax endpoint now sends `X-Content-Type-Options: nosniff`, and the deny list for
  `public/` covers hidden *directories* rather than only hidden files.
- Configured preloader colours are validated before being interpolated into the shell's
  inline `<style>`, where Blade's HTML escaping does not apply.
- A lazily-cached asset is now bounded by `service_worker.max_entry_bytes` like every
  other stored response. It was the one write path that checked neither the size nor the
  cap, so a single large declared file went to disk whatever it weighed.

### Removed

- **`pwax.transition.name` config key.** Page transitions are now driven by the browser's
  View Transitions API (`document.startViewTransition`); the name of a Vue `<transition>`
  is no longer meaningful and the field was already ignored by the runtime. The
  `transition.duration` key stays, because it is the cross-fade length the CSS reads
  directly. A published `config/pwax.php` that still names a transition will not error —
  Laravel's config repository ignores keys it is not asked for.

### Internal

- The test harness modelled the Cache API keyed on URL alone and ignored `Vary` entirely,
  which is why none of the page-caching defects were visible to the suite. It now stores
  each key request's headers and honours `Vary`, with tests of its own pinning that
  behaviour.
- New `Mxent\Pwax\Pwa\Glob` compiles glob patterns to regular expressions, used both
  server-side and — serialised into `sw.json` — by the worker.
- New `Mxent\Pwax\Pwa\PublicAssets` walks `public/` once per manifest build, refusing
  symlinks, `.php` files, dotfiles and source maps.

## [2.1.x]

### Added

- **The whole application is now available offline after one visit.** Pwax generates an
  asset manifest at `/sw.json`, listing every URL the application is made of with a
  content hash, and the service worker installs the
  lot in one pass. Previously the worker precached one URL (`/`) and everything else was
  cached lazily, after having been fetched online at least once: a visitor who installed
  the app and then lost their connection had no framework, no runtime and no components.
- Components are discovered by scanning the view paths for Blade files with a `<template>`
  block, or a `<script>` block that exports — which also finds the script-only views used
  for plugins, directives and client middleware. Nothing in an application declares its
  components, because `@pwax` resolves at request time, so there was previously no list
  for a worker to precache. Select them with `service_worker.components`: `'all'`,
  `false`, or a list of view-name patterns, narrowed further by `service_worker.exclude`.
- `pwax:precache` prints exactly what will be available offline. `--verify` renders every
  selected component so a view that cannot be served without controller data is found
  before a user reaches it with no connection; `--json` prints the manifest itself.
- A session-free offline shell at `/__pwax__/shell`: the SPA shell with no CSRF token and
  no page component, precached and served for any navigation that cannot reach the
  network. The runtime boots from it and routes client-side as usual.
- `ComponentResponse::cacheable()` opts a page's JSON payload out of `no-store` so the
  route works offline, for pages that render the same for every visitor. The HTML shell
  stays `no-store` regardless — it carries the CSRF token.
- `window.pwax.sw` with `update()`, `clearCaches()` and `unregister()`. An open tab now
  re-checks for a new build hourly and on regaining focus.
- `pwax:offline` and `pwax:online` events on `document`.
- The Web App Manifest supports every member of the specification, including `id`, `lang`,
  `dir`, `display_override`, `categories`, `screenshots`, `shortcuts`, `launch_handler`,
  `share_target`, `protocol_handlers` and `scope_extensions`. `id` defaults to `start_url`
  and `lang` to the application locale.
- The shell emits the tags iOS needs and the manifest cannot supply: `apple-touch-icon`
  (chosen from the non-maskable icons), `apple-mobile-web-app-capable`,
  `apple-mobile-web-app-title` and `application-name`. Without them an iPhone installs the
  app with a screenshot of the page as its icon.
- `<link rel="preload">` for the vendor scripts, so the browser starts all of them from
  the head rather than discovering each in turn.
- `pwax:doctor` checks maskable icons, a missing manifest `id`, `start_url` inside
  `scope`, missing screenshots, the offline shell, precache coverage and cross-origin
  assets.

### Fixed

- **The service worker called `self.skipWaiting()` during install, which defeated the
  entire update mechanism.** The new worker activated immediately, `controllerchange`
  fired, and the client reloaded every open tab on every deploy — discarding whatever the
  user was typing. It also made `registration.waiting` unobservable, so the
  `pwax:update-available` prompt the package documents could essentially never fire. The
  worker now waits, and the page reloads only when it asked to activate the update.
- **The worker cached authenticated HTML.** Cache Storage ignores HTTP cache directives,
  so navigations marked `no-store, private` by the server were persisted to disk anyway
  and served to the next user of a shared device. Navigations are no longer cached at all,
  and no response carrying `no-store` is stored.
- Precached entries lived in the same cache as everything else and were trimmed to
  `max_entries` in insertion order, so ordinary browsing could evict the app shell and
  silently take offline capability away. Precache and runtime cache are now separate, and
  the precache is never trimmed.
- `precache` used `cache.addAll`, which is atomic: one 404 anywhere in the list rejected
  the whole install, and the surrounding `catch` then activated a worker with an empty
  cache while reporting success. Entries are now fetched individually, failures are
  reported, and only a missing shell or runtime aborts the install.
- A deploy did not reach existing installs unless `version` was bumped by hand. A browser
  only installs a worker whose bytes differ from the one it has, and the worker's source
  never changed. The manifest hash is now embedded in it.
- `registerServiceWorker` threw on an insecure origin. `'serviceWorker' in navigator` is
  true over plain HTTP while the value is `undefined`, so the guard passed and the next
  line failed.
- `Support\Shell` was a singleton that read the request, so under Octane or FrankenPHP it
  held the first request it ever saw for the life of the process.
- A failed request was rethrown into `respondWith`, which fails the request *and* reports
  `Uncaught (in promise) TypeError: Failed to fetch` against the worker. Any SPA produces
  those routinely — navigating away aborts the in-flight page request — so the console
  filled with errors attributed to the service worker rather than to the caller. The
  worker returns `Response.error()` instead: the page's own `fetch` still rejects at its
  own call site, which the runtime already reads as "no connection".
- Stale-while-revalidate resolved to `undefined` when nothing was cached and the network
  was unreachable, so `respondWith` failed with "the promise was resolved with an object
  that is not a Response" in place of an ordinary offline error.
- Precaching ran every request in parallel. An application with a hundred components
  produced a hundred simultaneous requests, which `php artisan serve` — single-threaded —
  answers by queueing until connections are refused. At most six now run at once.
- Component discovery scanned every registered view namespace, so a real installation
  found `laravel-exceptions-renderer::components.query` — part of Laravel's debug error
  page — and put it in the offline manifest. Every package that calls `loadViewsFrom()`
  registered one, and none of those views are components an application imports:
  precaching them fills the manifest with URLs that cannot render offline and mints a
  signed, publicly addressable URL for each. Only the application's own view paths are
  scanned now; namespaces are opt-in through `service_worker.namespaces`.
- A precache entry the server refused with `Cache-Control: no-store` was counted and
  reported as a failed asset, so an install behaving exactly as designed logged
  "3 of 10 assets could not be precached" and sent people looking for a network fault.
  Refusals are now reported separately, and say what to do about them.

### Changed

- `service_worker.precache` now defaults to `[]` rather than `['/']`. Precaching `/`
  stored one signed-in user's rendered HTML for the next user of the device to be served,
  and only covered that one route; the offline shell covers every route and has nothing in
  it to leak. Routes listed here are still precached, but a response the server marked
  `no-store` — which is every page Pwax renders — is not stored, and `pwax:doctor` says so.
- `service_worker.max_entries` now bounds only the runtime cache. Precached entries are
  never evicted.
- A component now has exactly one representation: `/__pwax__/c/{id}.js`, an ES module
  carrying its template, script, styles and scope together. The `.css` and `.json`
  endpoints were removed. Nothing consumed either — the runtime imports the module and
  reads its exports — and both rendered the view with **no controller data**, so their
  output was misleading in precisely the case someone would have reached for them.
  `Pwax::url()` accordingly drops its `$format` argument.

### Fixed

- Components returned by `@pwax` are memoised on their URL and export name. Vue treats
  every `defineAsyncComponent` result as a distinct component type, so minting a new one
  per call remounted the subtree whenever a parent re-rendered and gave `<KeepAlive>` a
  different identity to cache each time.
- Wrapping the directive in an arrow function — `components: { Modal: () => @pwax('…') }`,
  the Vue 2 lazy-component idiom that Vue 3 dropped — rendered `[object Object]` with no
  warning anywhere. Vue sees a function, treats the entry as a functional component, calls
  it during render, and falls back to `String(child)` when it gets a component object
  instead of vnodes. The component now carries a `toString()` that names the mistake and
  its fix, so the page says what to change. Documented in the README and upgrade guide.

## [2.0.0]

A rewrite of the internals. See [UPGRADE.md](UPGRADE.md) for the migration checklist.

### Security

- **Component endpoints no longer render arbitrary views.** `/__pwax__/{name}.json`
  accepted any view name, so an unauthenticated caller could render any Blade template in
  the application — `GET /__pwax__/admin_x_users.json` returned
  `resources/views/admin/users.blade.php`. Identifiers are now signed with `APP_KEY` and
  verified with `hash_equals`, so only URLs the application itself emitted resolve.
- **Component routes run through a middleware stack** (`web` by default). They previously
  had none, so components rendered with no session and `auth()` was always a guest.
- **Plugin, directive and middleware config values are no longer executed.** They were
  interpolated into the page inside `{!! !!}`. Each is now either a component reference or
  a dotted path looked up on `window`.
- Added `components.allowed`, an optional allowlist of servable view patterns.
- Added `csp.nonce` for the inline `<style>` and JSON blocks, and dropped the need for
  `blob:` and `data:` in `script-src` by serving components from real URLs.
- The bundled error template uses `v-text` rather than `v-html` for response-derived text.
- Added [SECURITY.md](SECURITY.md) with a disclosure policy and the security model.

### Fixed

- **The `@import` Blade directive corrupted CSS across the whole application.** Blade
  matches a directive even with no arguments, so a directive named `import` also captured
  the CSS at-rule `@import url("…")` inside every `<style>` block in every Blade view —
  not only in Pwax components — and replaced it with JavaScript. The directive is now
  `@pwax`, configurable, and the name `import` is rejected.
- **The directive resolved URLs at Blade compile time**, freezing them into
  `storage/framework/views`. Changing `route_prefix`, or running `view:cache` in a build
  step with no routes loaded, produced permanently broken imports. It now emits PHP that
  runs per request.
- **Circular imports deadlocked.** `@import` expanded to `await window.pwaxImport(...)`, so
  two components referencing each other each waited at module top level for the other — a
  deadlock in native ES modules. The placeholder-mutation workaround that hid it (and
  which could render a component with a template but no methods) is gone: `@pwax` returns a
  Vue async component resolved at render time.
- **Navigating away removed the styles of mounted components.** Every injected tag was
  marked `pwax-attached` and all of them were removed on each navigation, including those
  belonging to imported components still on screen. Styles are now reference-counted.
- **Every navigation leaked an ES module.** Component scripts were imported from a fresh
  `blob:` URL each time; module records are keyed by URL and never collected. Components
  are now imported once from a stable URL.
- **Import cache keys collided.** Keys came from `Str::studly()` of the view name, so
  `a::foo.bar` and `a.foo.bar` both keyed to `AFooBar` and returned the wrong component.
  Keys are now signed identifiers.
- **`Vary` was missing on negotiated responses**, letting a shared cache serve the JSON
  payload to a browser navigation or the HTML shell to the client runtime.
- **Server redirects never reached the client.** The client handled a `redirect` key that
  nothing produced, so an `auth` middleware redirect surfaced as "Network Error". A
  middleware now translates 302s and expired CSRF tokens.
- Only `<link rel="stylesheet">` is collected as a component stylesheet; the manifest
  link, icons and preloads were previously swept in too.
- Template extraction matches closing tags by depth and tolerates attributes, instead of
  taking the first `<template>` and the last `</template>`.
- `hash_route` defaulted to `false` in config and `true` in the router template.
- The preloader used `100vh`/`100vw`, which mis-centres on mobile and forces a horizontal
  scrollbar on Windows; it now uses `100dvh` and respects `prefers-reduced-motion`.
- A missing component view returns `404` rather than `500`.
- The manifest and service worker no longer run through `web`, so they stop setting a
  session cookie on every fetch.
- A cache store that is missing or misconfigured no longer breaks page rendering. Laravel
  defaults `CACHE_STORE` to `database`, so an application that had not run the cache
  migration would otherwise see every component fail with `no such table: cache`;
  compilation and minification now fall back to running uncached and warn once.
- Redirects produced by an *exception* rather than a response — `auth` and
  `VerifyCsrfToken` both throw — are recovered on the client, which treats a followed
  redirect returning HTML as an instruction to reload. Middleware cannot see these: the
  exception unwinds past the pipeline and the redirect is built by the exception handler.
- Each vendored asset is cache-busted on its own version, rather than all three being
  tagged with Vue's.

### Added

- **The SPA shell embeds the current component in full** — template, styles and script —
  so first paint makes no follow-up request for the page at all, against six sequential
  requests in 1.x.
- **Imported components are served as real ES modules** from `/__pwax__/c/{id}.js`,
  carrying the template, styles and scope alongside the author's script: one request,
  HTTP-cacheable with an ETag, and no `blob:` or `data:` URL. A *page* component cannot
  use this — it is rendered with controller data and so cannot be re-derived from its
  view name — and ships its script inline instead, which the runtime compiles once per
  content hash.
- **`<style scoped>`**, with selector rewriting and template stamping, plus `:deep()` and
  `:global()` escape hatches.
- **Self-hosted frontend assets.** Vue, Vue Router and Pinia ship with the package and
  publish to `public/vendor/pwax`, so the app can start offline and no visitor IP reaches
  a third-party CDN. CDN mode remains available with subresource integrity.
- **A compiled client runtime** (`dist/pwax.js`, 10 kB) replacing the inline JavaScript in
  `main.blade.php`, with its own lint, format and test suite. CI fails if the committed
  bundle drifts from source.
- ETag/`304` support on every component endpoint, the runtime bundle and the manifest.
- Content-addressed caching of compiled components and minified sources.
- `Pwax` facade and container service; `pwax()`, `pwax_component()`, `pwax_route()` helpers.
- Artisan `pwax:doctor`, `pwax:component` and `pwax:clear`; `pwax:install` gained
  `--no-assets`.
- Service worker rewrite: navigation preload, navigation-always-network-first,
  stale-while-revalidate for assets, bounded cache, versioned invalidation, update
  notification via `pwax:update-available`, and refusal to cache opaque or partial
  responses.
- Document events `pwax:ready`, `pwax:navigating`, `pwax:navigated`, `pwax:error` and
  `pwax:update-available`; `window.pwax` public API.
- `routes.register`, `routes.domain` and `routes.static_middleware` for applications that
  want to own their routing.
- Accessibility: `role="status"`/`aria-live` on the loader, `role="alert"` on errors, a
  retry control, and reduced-motion support.

### Changed

- **Upgraded the frontend stack**: Vue 3.5.18 → 3.5.41, Vue Router 4.5.1 → 5.2.0,
  Pinia 3.0.3 → 4.0.2. Vue Router 5 is a no-op migration for applications that did not use
  `unplugin-vue-router`; Pinia 4 is ESM-only but its IIFE build is self-contained.
- `vue()` → `pwax_component()`, `router()` → `pwax_route()`. The 1.x names are available
  behind `helpers.global` and deprecated.
- `pwax_route()` throws on an unknown route name when `APP_DEBUG` is on instead of silently
  falling back to the home page.
- Minification defaults to production only, is cached by content, and can be disabled.
- `config.middleware` now means the *server* middleware stack; client middleware moved to
  `middleware_js`.
- Views reorganised; `layouts/app` became `layouts/shell` and the `components/vue/*`
  templates were replaced by the runtime bundle.
- `composer.json`: `minimum-stability` is `stable` (it was `dev`, which forced dev
  resolution on every consumer), `php` and `matthiasmullie/minify` constraints are bounded,
  and the full `laravel/framework` requirement was replaced with the `illuminate/*`
  components actually used.

### Removed

- The `@import` Blade directive, `vue()` and `router()` globals (see `helpers.global`),
  the `_x_` / `__x__` view-name encoding, and `resources/views/js/main.blade.php`.

### Internal

- CI now runs Pint and PHPStan for real. Both jobs previously skipped when the tools were
  absent — and neither was in `require-dev`, so the badge was always green.
- Test matrix covers PHP 8.2–8.4 × Laravel 12–13, plus `prefer-lowest`.
- Test suite grew from 20 assertions to 179 PHP tests and 52 JavaScript tests.
- Added Pint, PHPStan/Larastan, ESLint, Prettier, Vitest, esbuild, Dependabot,
  `.editorconfig`, `.gitattributes` and issue/PR templates.

## [1.0.0]

### Added

- Vue 3, Vue Router and Pinia integration for Laravel.
- Dynamic component loading from Blade views over AJAX.
- JS/CSS minification and template parsing.
- Configurable plugins, directives and middleware.

[Unreleased]: https://github.com/mxent/pwax/compare/v2.1.4...HEAD
[2.1.x]: https://github.com/mxent/pwax/compare/v2.0.0...v2.1.4
[2.0.0]: https://github.com/mxent/pwax/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/mxent/pwax/releases/tag/v1.0.0
