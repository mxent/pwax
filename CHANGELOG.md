# Changelog

All notable changes to `mxent/pwax` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Pages work offline.** An application could precache its framework, its components and
  its shell, install as a PWA, boot offline — and still show "This page needs an internet
  connection to load", because the one thing never cached was the page. Page payloads are
  now fetched the way the client runtime fetches them and stored under that same request,
  so the Cache API's `Vary` check succeeds instead of missing every time.
- **Runtime page caching**, so everywhere a visitor has been works offline rather than only
  the routes listed in config. Configure with `service_worker.pages.runtime`.
- **Per-identity cache partitioning.** Pages, runtime entries and API responses are stored
  in caches named after an opaque HMAC of the signed-in user. One person's cached page is
  not merely cleared when another signs in — it was never reachable under their name.
  `pwax.sw.forgetIdentity()` drops one identity's caches on sign-out without discarding the
  precache.
- **Angular-style asset groups** (`service_worker.asset_groups`) with `install_mode`,
  `update_mode` and glob patterns resolved against `public/`. Images, fonts, stylesheets
  and build output are precached without listing each one.
- **Data groups** (`service_worker.data_groups`) with `freshness` and `performance`
  strategies, `max_age` and `max_size`. An offline page used to render and then fail every
  fetch it made.
- **`navigation_strategy`** with an `app-shell` option for zero-round-trip navigation, and
  **`navigation_urls`** so a path the application does not own bypasses the worker.
- `ComponentResponse::title()` for a per-page document title, applied on first paint and on
  every client-side navigation.
- `ComponentResponse::offline(false)` for a page that must never reach disk — stronger than
  omitting `->cacheable()`, which only declines to precache.
- `<title>`, `<meta name="description">`, `<link rel="icon">` and an opt-in `<base href>` in
  the head, in a fixed order modelled on Angular's `index.html`.
- `pwax:doctor` and `pwax:precache` report manifest `warnings`, including a glob truncated
  by `max_files` or `max_bytes`.

### Fixed

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

### Changed

- **BREAKING:** `pwax_component()` is now `pwaxRender()`, `pwax_route()` is `pwaxRoute()`,
  and the `@pwax` directive is `@pwaxImport`. `Pwax::importExpression()` is `Pwax::import()`.
  Each helper is now named after the facade method it wraps. See `UPGRADE.md`.
- **BREAKING:** the 1.x `vue()` and `router()` helpers and the `pwax.helpers.global` config
  key are removed. They were deprecated in 2.0.
- **BREAKING:** the service worker moves from `/service-worker.js` to `/sw.js`. The runtime
  unregisters a worker left at the old path; `service_worker.legacy_paths` serves a
  self-unregistering worker for installs that may never load a fresh page. A worker script
  response cannot be a redirect, so the path cannot 301.
- **BREAKING:** the web manifest moves from `/manifest.webmanifest` to `/manifest.json`,
  with a 301 from the old path via `manifest_aliases`. Installs survive: the manifest's `id`
  defaults to `start_url`.
- **BREAKING:** `service_worker.precache` becomes `service_worker.pages.urls` and
  `service_worker.files` becomes `service_worker.asset_groups`.
- Page payloads may now be stored by the service worker when a route opts in, where
  previously `no-store` kept every one of them off disk. The three controls that make that
  safe — the opt-in itself, anonymous install-time fetches, and identity-partitioned caches
  — are described in the published config.

### Internal

- The test harness modelled the Cache API keyed on URL alone and ignored `Vary` entirely,
  which is why none of the page-caching defects were visible to the suite. It now stores
  each key request's headers and honours `Vary`, with tests of its own pinning that
  behaviour.
- New `Mxent\Pwax\Pwa\Glob` compiles Angular's glob syntax to regular expressions, used
  both server-side and — serialised into `sw.json` — by the worker.
- New `Mxent\Pwax\Pwa\PublicAssets` walks `public/` once per manifest build, refusing
  symlinks, `.php` files, dotfiles and source maps.

## [2.1.x]

### Added

- **The whole application is now available offline after one visit.** Pwax generates an
  asset manifest at `/sw.json` — the equivalent of Angular's `ngsw.json` — listing every
  URL the application is made of with a content hash, and the service worker installs the
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
