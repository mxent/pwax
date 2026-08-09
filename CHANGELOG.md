# Changelog

All notable changes to `mxent/pwax` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

- **Cached responses could be read across signed-in identities.** Pages, API responses and
  runtime entries were stored in caches named after the signed-in visitor, and the package
  documented that as making a cross-user read impossible rather than merely unlikely. It
  held for writes and not for reads: the offline fallback used a lookup that names no
  cache, and by specification such a lookup searches *every* cache on the origin. Two
  people sharing a device — the second one offline — and the worker served the first one's
  responses. Reads are confined to one cache now, which is emptied whenever the visitor
  changes; see **Changed**.
- **An empty cache was created by reading.** `caches.open()` creates, and the page and data
  paths opened theirs before knowing whether anything would be stored — so every visitor
  left behind an empty cache per group, on a device that had stored nothing. Reads go
  through `caches.has()` first throughout.
- **The client identity could be a whole session out of date.** It was read once from the
  document, and Pwax turns a post-login `redirect()` into a client-side navigation on
  purpose — so a visitor who signed in through the runtime kept sending the guest label,
  and the first pages of their authenticated session were filed in the partition every
  signed-out visitor can read. The documented sign-out call read the same stale value and
  cleared nothing. Payloads now carry the identity they were rendered for and responses
  carry it as a header, so the worker files by who was actually served rather than by who
  asked.
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
- **A stored document is withheld once anyone has signed in on the device.** Every document
  the worker holds is a signed-out rendering, and one handed to a signed-in visitor tells
  them they are logged out when they are not — permanently, since the document carries its
  own inlined payload and the runtime has no reason to refetch it. Those visitors are given
  the shell, and the runtime's own request, which carries an identity, decides what to
  render. `pwax.sw.forgetIdentity()` on sign-out restores the fast path.

### Changed

- **One set of caches, kept to one visitor at a time.** The signed-in identity used to be
  part of every cache *name*, which made a cross-user read impossible by construction — and
  cost a fresh set of caches per person, an empty one minted on every sign-in, and
  everything re-fetched under the new name each time the name changed. Names are now fixed:
  `pwax-pages-v1-<build>`, `pwax-runtime-v1`, `pwax-data-<group>-<v>`. The separation is
  kept by *emptying* the visitor caches the moment the worker learns it is serving somebody
  else — from the identity a response declares, and from the identity a request claims,
  which is what covers a visitor offline from their first request. The precache, the
  build's own guest page payloads and the documents cache are never emptied that way, so a
  sign-in never re-downloads the application.

  This is weaker than being unaddressable, and it loses the previous person's offline pages
  rather than parking them. `service_worker.identity_cache_limit` is gone with the per-person
  sets it bounded; `pwax:doctor` names it.
- Every request the runtime makes now carries `X-Pwax-Identity`, `anon` included. It was
  omitted for a signed-out visitor, which made an absent header mean both "a guest is
  asking" and "this is not a Pwax request" — and the worker has to tell those apart, since
  one is a signal to empty the caches and the other must never be.

### Added

- **A page's HTML is cached as it is visited, not only at install.** A page answers two
  ways — JSON to the runtime, HTML with the component inlined to a navigation — and only
  the JSON was stored after install. A route the build never precached, a dynamic one or
  anything route discovery could not reach, had no document at all, so reloading it offline
  fell back to the shell and a spinner. Documents are now kept as they are visited, and only
  when the response declares `X-Pwax-Identity: anon`: a missing header is treated as
  somebody's, because a navigation is the one request whose sender a worker cannot identify.
  `ComponentResponse`'s HTML representation sends that header alongside the payload's.

### Fixed

- **The client runtime bundle could never update in a browser that had cached it.**
  `/__pwax__/pwax.js` carries no version in its URL and is served `immutable`, which tells
  a browser not to revalidate for a year — not even conditionally, so its ETag was never
  consulted. Upgrading the package left returning visitors on the runtime they first
  downloaded. Invisible with the service worker on, since that precaches by content hash;
  entirely visible with it off, which is the default. The URL is now fingerprinted by the
  bundle's contents, and the source map is revalidated rather than cached hard so it cannot
  be paired with a newer bundle.
- **A signed-in visitor could not open a precached page offline.** Go offline mid-session
  and click a link to a page you have not already opened, and it failed — while *reloading*
  that same URL worked. A navigation is answered from the shared precache; the payload a
  link needs was precached under `anon`, and a signed-in visitor's requests name their own
  cache. So the one thing precaching exists to provide was the one thing they could not
  reach. Install-time payloads now live in a bucket every identity reads and none writes
  to, with the visitor's own copy still preferred when they have one.
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

### Changed

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
- `forgetIdentity()` defaults to the current identity.
- Navigation preload is consumed rather than discarded, so a navigation the worker declines
  to handle no longer costs the server two requests.
- The view-tree walk and the route walk happen once per manifest build rather than two and
  four times.
- Middleware modules no longer delay the first paint. Plugins and directives still do,
  because Vue offers no way to register either after mount.

### Added

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
- **Per-identity cache partitioning.** Pages, runtime entries and API responses are stored
  in caches named after an opaque HMAC of the signed-in user. One person's cached page is
  not merely cleared when another signs in — it was never reachable under their name.
  `pwax.sw.forgetIdentity()` drops one identity's caches on sign-out without discarding the
  precache.
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

### Fixed

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

### Changed

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
