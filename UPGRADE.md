# Upgrading

## 3.x → 4.0

4.0 is a consolidation. Nothing about how you write a component or a route changes; what
changes is a handful of config keys that had grown two names for one idea, and one default
that was storing more than it should have been.

**Two of these are security fixes.** If you use `service_worker.pages.runtime` — it is on
by default — upgrade rather than cherry-picking the renames.

```bash
composer require mxent/pwax:^4.0
php artisan pwax:doctor
```

`pwax:doctor` reads your published config and names every key below that no longer does
anything, so it is the checklist. There are no code changes to make unless you call
`pwax.sw.registration`.

---

### 1. Cached pages could be read across identities

Pages, API responses and runtime entries were stored in caches named after the signed-in
visitor, so one person's pages were unreachable from another's session. That held for
writes and not for reads: the offline fallback used a lookup that searches *every* cache on
the origin, so two people sharing a device — the second one offline — could be served the
first one's cached responses.

Nothing to change. Upgrading is the fix. See §10 for the scheme that replaced the naming.

### 2. `forgetIdentity()` and `window.pwax.config.identity` are gone

Both belonged to the per-identity cache naming that §10 removes. There are no per-person
caches left to forget, so the call has nothing to do — delete it.

```diff
- pwax.sw.forgetIdentity(window.pwax.config.identity);
```

To clear stored responses on sign-out — on a shared terminal, say — use
`pwax.sw.clearCaches()`. It is the heavier hammer: it discards the framework too, so the
next visitor downloads the application again. For a page that must never reach disk in the
first place, `->offline(false)` on the route is the better answer.

### 3. `service_worker.strategy` is now `runtime_strategy`, defaulting to `network-only`

It sat next to `navigation_strategy` meaning something entirely different — one governs
documents, the other governs every same-origin GET nothing in the manifest claims.

```diff
- 'strategy' => 'network-first',
+ 'runtime_strategy' => 'network-only',
```

The default changed too. `network-first` kept a copy of everything it passed through: a
one-off PDF, a CSV export, a file under `/storage`. If you were relying on that, set
`'runtime_strategy' => 'network-first'` explicitly — but consider whether those URLs belong
in an `asset_group` or a `data_group`, where they are listed, hashed and bounded.

### 4. Data groups are flat, and `max_size` is `max_entries`

```diff
  [
      'name' => 'posts',
      'urls' => ['/api/posts', '/api/posts/**'],
-     'cache_config' => [
-         'strategy' => 'freshness',
-         'max_size' => 50,
-         'max_age' => 3600,
-         'timeout' => 3000,
-     ],
+     'strategy' => 'freshness',
+     'max_entries' => 50,
+     'max_age' => 3600,
+     'timeout' => 3000,
  ],
```

`pages` and `asset_groups` were already flat; data groups were the odd one out, and
`max_size` was a third spelling of a quantity already called `max_entries` twice. A group
left in the old shape still works — on defaults, with none of the bounds you wrote — which
is why `pwax:doctor` calls it out by name.

### 5. New: `service_worker.max_entry_bytes`

Defaults to 5 MB. `max_entries` counts entries, which bounds nothing on its own: sixty JSON
payloads and sixty videos are very different amounts of a visitor's disk, and one large
response can push the origin past its quota and have the browser evict the precache.

### 6. `routes.static_middleware` ships with a throttle

```diff
- 'static_middleware' => [],
+ 'static_middleware' => ['throttle:300,1'],
```

These routes are outside `web` on purpose, which also puts them outside its rate limiting,
and `/sw.json` walks `public/`, every view root and every route on each build. Adjust the
rate to suit; leave it empty only if something in front of the app already limits it.

### 7. `pwax.sw.registration` returned the wrong object

It returned the controlling `ServiceWorker`, not the `ServiceWorkerRegistration` — so
`.waiting`, `.scope` and `.update()` on it were all `undefined`.

```diff
- const worker = pwax.sw.registration;
+ const worker = pwax.sw.controller;
+ const registration = await pwax.sw.registration();
```

### 8. If you published the shell view

`vendor:publish --tag=pwax-views` copies `layouts/shell.blade.php` into your application,
so an upgrade cannot change it. Two edits are worth making by hand:

```diff
- <div id="pwax" class="pwax-preloader" role="status" aria-live="polite" aria-label="Loading">
+ <div id="pwax" class="pwax-preloader">
+     <span class="pwax-sr-only" role="status">Loading</span>
      @yield('content')
  </div>
+
+ <div id="pwax-announcer" class="pwax-sr-only" role="status" aria-live="polite"></div>
```

**The mount element's attributes.** They belong to the spinner, and the runtime removes the
preloader class on mount but cannot remove semantics you own — so the application root
stayed a live region labelled "Loading" for the whole session, and every reactive text
change in the app was announced. (The runtime now strips them defensively, so this is belt
and braces.)

**The announcer** is where a route change is read out. Without it, navigation is silent to
a screen reader.

### 9. A page's HTML is cached as it is visited

A page answers two ways — JSON to the runtime, HTML to a navigation — and only the JSON
was kept after install. A route the build never precached, a `/posts/{post}` for instance,
had no document at all, so reloading it offline fell back to the shell and a spinner. Now
the HTML is kept too.

Nothing to change, but one thing to decide.

**These documents are shared, like every other cache.** Whatever HTML the server returned
for a URL is what the next visitor on that device gets offline. For a page that renders
the same for everyone that is the point. For one whose signed-in and signed-out renderings
differ — `/dashboard`, `/account` — mark the route `->offline(false)` and it is refused
outright.

`service_worker.pages.runtime => false` turns off both halves, as before.

### 10. Cache names no longer carry the signed-in identity

Names were `pwax-pages-v1-<build>-<identity>`, one set per person who signed in on the
device. That made a cross-user read impossible by construction — and meant a fresh empty
cache on every sign-in, a set left behind per person, and everything re-fetched under the
new name each time it changed.

The names are fixed now, one set per build: `pwax-precache-<build>`,
`pwax-pages-<build>`, `pwax-documents-<build>`, plus `pwax-runtime`, `pwax-lazy` and
`pwax-data-<group>-v<n>`, which are not keyed by the build so a deploy does not discard
them.

**`service_worker.identity_cache_limit` is gone.** It bounded how many per-person cache sets
a device kept, and there are none. Remove it; `pwax:doctor` names it if you forget.

```diff
  'service_worker' => [
-     'identity_cache_limit' => 2,
  ],
```

**The consequence, stated plainly: caches are shared across visitors.** Whatever the server
returned for a URL is what the next person using that device is served offline. The naming
scheme it replaces did not actually prevent that — a `caches.match()` with no cache named
searches every cache on the origin, which is exactly what the offline fallback did — so
this is the same exposure with the guarantee removed rather than a new one.

Decide per route. `->offline(false)` refuses to store a page at all, and is the right
answer for anything whose signed-in and signed-out renderings differ. Data groups are
responses and can hold one person's data; do not add an authenticated endpoint to one
without meaning it.

Existing caches from 4.0.x are swept on the first activate of the new worker, so there is
nothing to clear by hand.

### 11. One vocabulary for every strategy

Four keys chose a caching behaviour in three different languages, and a fifth called
`strategy` chose a hostname. They now share one set of names, taken from the ones the rest
of the web uses:

| Was | Now |
| --- | --- |
| `freshness` | `network-first` |
| `performance` | `cache-first` |
| `app-shell` (navigations) | `cache-first` |
| `pwax.assets.strategy` | `pwax.assets.source` |

**Nothing breaks.** Every old spelling still resolves, and `pwax:doctor` names the ones you
are still using. The manifest only ever carries the new vocabulary, so the service worker
knows one set of words regardless of what your config says.

`assets.source` is the odd one out and the reason the rename was worth doing: it chooses
where Vue is served from — `local` or `cdn` — and has nothing to do with caching, so
calling it `strategy` put a fifth key by that name in a config where the other four mean
something else entirely.

### 12. New, and optional: `pwax:compile`

Nothing to do unless you want it. `php artisan pwax:compile` compiles every template to a
Vue render function at deploy time, so `assets.vue_build => 'runtime'` can serve the
smaller Vue build (40.6 kB gzipped against 60.7) and your CSP can drop
`script-src 'unsafe-eval'`.

```bash
npm install --save-dev @vue/compiler-dom@3.5.41
php artisan pwax:compile
```

```php
'assets' => ['vue_build' => 'runtime'],
```

If you turn it on, **`php artisan pwax:compile` becomes a required deploy step** — put it
beside `config:cache`. Forgetting it once is harmless (the store is empty, the full build is
served, nothing changes); forgetting it after a component edit is not, because the store is
then non-empty and the edited component has no render function. `pwax:doctor` reports both,
the second as an error naming the components affected.

It also requires that a template be the same for every visitor, since it is compiled with no
request in flight. Keep controller data in `<script>` (`@json($user)`) and out of
`<template>` — `pwax:compile` names any view that breaks the rule and exits non-zero.

Also new: `assets.cdn.integrity` accepts a filename key alongside the package name, and
ships one for `vue.runtime.global.prod.js`. If you have hand-written that map and use a CDN
with the runtime build, add the filename entry or the browser will reject the script.

### 13. If you declare share_target, file_handlers or protocol_handlers

These used to pass through to the manifest and do nothing else. They now work — the runtime
consumes the launch queue, so a file handler actually receives its files — and
`pwax:doctor` now checks them, which may fail a build that was previously green.

It fails for a reason: a declared target whose route does not exist, refuses the method the
browser uses, or sits outside `scope` is a capability your app advertises and cannot honour.
Point it at a real route, or remove the member.

### Checklist

- [ ] `service_worker.strategy` → `service_worker.runtime_strategy`, and decided on its value
- [ ] `service_worker.identity_cache_limit` removed
- [ ] Data groups flattened; `max_size` → `max_entries`
- [ ] `forgetIdentity()` and `window.pwax.config.identity` removed from your code
- [ ] `pwax.sw.registration` → `.controller` or `.registration()`
- [ ] Published shell view updated, if you have one — announcer, mount attributes
- [ ] Decided which routes need `->offline(false)`, now that caches are shared
- [ ] Strategy names updated, or the doctor's warnings about them accepted
- [ ] Decided about `pwax:compile` — and if you turned it on, it is in the deploy script
- [ ] Any declared `share_target` / `file_handlers` / `protocol_handlers` point at real routes
- [ ] `php artisan pwax:doctor` is clean
- [ ] Tested offline, signed in as two different users on one browser profile

---

## 2.x → 3.0

3.0 makes an installed application actually work offline, and settles the naming so the
API is predictable. The renames are mechanical — a few `grep`s and you are done. Budget
half an hour for a small app.

```bash
composer require mxent/pwax:^3.0
php artisan pwax:install --force
php artisan view:clear && php artisan config:clear
php artisan pwax:doctor
```

`view:clear` is not optional. Compiled views in `storage/framework/views` contain the old
directive's output, and the function it called no longer exists.

---

### 1. `pwax_component()` is now `pwaxRender()`

**Why:** three related things had three unrelated spellings. Each helper is now named
after the facade method it wraps, so knowing one gives you the others.

```diff
- Route::get('/', fn () => pwax_component('pages.home'));
+ Route::get('/', fn () => pwaxRender('pages.home'));
```

```bash
grep -rn "pwax_component(" app routes
```

`Pwax::render()` is unchanged, and is the spelling to prefer in a controller.

### 2. `pwax_route()` is now `pwaxRoute()`

```diff
- <a :href="'{{ pwax_route('posts.show', $post) }}'">
+ <a :href="'{{ pwaxRoute('posts.show', $post) }}'">
```

```bash
grep -rn "pwax_route(" app routes resources/views
```

### 3. `@pwax()` is now `@pwaxImport()`

```diff
  <script>
      export default {
          components: {
-             Modal: @pwax('components.modal'),
+             Modal: @pwaxImport('components.modal'),
          },
      };
  </script>
```

```bash
grep -rn "@pwax(" resources/views
```

Named exports keep the same spelling: `@pwaxImport('Backdrop from components.modal')`.

`pwax.components.directive` renames it if you want a different spelling — but it replaces
the default rather than joining it, so there is exactly one name in an application.
`plugins`, `directives` and `client_middleware` config values follow whatever that name is.

`Pwax::importExpression()` is now `Pwax::import()`. You are unlikely to have called it
directly — it is what the directive compiles to.

### 4. The 1.x `vue()` and `router()` helpers are gone

`pwax.helpers.global` has been removed with them. They were deprecated in 2.0.

```diff
- vue('pages.home', ['post' => $post])
+ pwaxRender('pages.home', ['post' => $post])
```

### 5. The service worker moved to `/sw.js`, the manifest to `/manifest.json`

**Why:** `/sw.js`, `/sw.json` and `/manifest.json` read as one set. The old paths did not.

Update any reverse-proxy or CDN rule that names the worker by path.

3.0 ships no redirect or shim for either. A worker script response is not allowed to be a
redirect — the browser fails the registration outright — so a worker already registered at
`/service-worker.js` keeps running until it is removed. Clear it once, in DevTools →
Application → Service Workers → **Unregister**, or from the console:

```js
navigator.serviceWorker.getRegistrations().then((rs) => rs.forEach((r) => r.unregister()));
```

Installs survive the manifest move: the manifest's `id` defaults to `start_url`, not to
the manifest's own URL.

### 6. `service_worker.precache` is now `service_worker.pages` — and it finally works

**Why:** it never worked. The worker fetched each listed route without the header that
asks for a component payload, so the server answered with the HTML shell; that shell is
`no-store`, which the worker correctly refused to store. Every entry was skipped, silently.

```diff
  'service_worker' => [
-     'precache' => ['/', '/about'],
+     'pages' => [
+         'urls' => ['/', '/about'],
+         'runtime' => true,
+     ],
  ],
```

In most applications the list can stay empty: every GET route whose action hands a literal
view name to `pwaxRender()` is discovered and precached automatically, scoped by
`service_worker.components`. `urls` is for what cannot be read statically — a computed view
name, or a parameterised route like `/posts/{post}`.

No per-route opt-in is needed. Pages are fetched without cookies, so what is stored is the
guest rendering; a route behind `auth` answers with a login screen instead of a payload and
is refused, which `php artisan pwax:precache --verify` reports.

**`runtime` is new, defaults to true, and is worth a decision.** With it on, every page a
visitor opens is cached so that everywhere they have been works offline. The worker empties
that cache when the signed-in visitor changes, so one person's cached page is not served to
another on a shared device, and `->offline(false)` marks a page that must never reach disk
at all. Set `runtime` to false if that trade is not one you want.

### 7. `service_worker.files` is now `service_worker.asset_groups`

**Why:** listing every image and font by hand meant most applications went offline with
missing artwork and, if `pwax.styles` was set, no stylesheet at all — that one was a plain
bug: the sheets were rendered into every page and never precached.

```diff
- 'files' => ['/css/app.css', '/fonts/inter.woff2'],
+ 'asset_groups' => [
+     [
+         'name' => 'app',
+         'install_mode' => 'prefetch',
+         'files' => ['/favicon.ico', '/css/**.css', '/js/**.js', '/build/**'],
+     ],
+     [
+         'name' => 'assets',
+         'install_mode' => 'lazy',
+         'files' => ['/images/**', '/fonts/**'],
+     ],
+ ],
```

The globs: `**` crosses directories, `*` does not,
`{a,b}` and `(a|b)` alternate, a leading `!` excludes. `public/storage` is never walked,
and `.php` files, dotfiles and source maps are never matched.

Publishing the config with `--force` gives you working defaults. Check the result with
`php artisan pwax:precache` before deploying — `max_files` and `max_bytes` cap a runaway
glob, and anything they truncate is reported.

### 8. Data groups, if your components call an API

New, and off unless configured. An offline page used to render and then fail every fetch
it made. See `service_worker.data_groups` in the published config; the security note there
is worth reading before you add an authenticated endpoint.

### 9. The head changed

`<title>`, `<meta name="description">` and `<link rel="icon">` are emitted now, and the
tags are in a fixed order. If you were adding a title through `@push('pwax-head')`, remove
it and use `pwax.head.title` or `pwaxRender(...)->title('…')` instead, or you will have two.

`<base href>` is available through `pwax.head.base` and is off by default — it changes how
every relative URL in the document resolves, and routing does not need it.

### 11. One vocabulary for every strategy

Four keys chose a caching behaviour in three different languages, and a fifth called
`strategy` chose a hostname. They now share one set of names, taken from the ones the rest
of the web uses:

| Was | Now |
| --- | --- |
| `freshness` | `network-first` |
| `performance` | `cache-first` |
| `app-shell` (navigations) | `cache-first` |
| `pwax.assets.strategy` | `pwax.assets.source` |

**Nothing breaks.** Every old spelling still resolves, and `pwax:doctor` names the ones you
are still using. The manifest only ever carries the new vocabulary, so the service worker
knows one set of words regardless of what your config says.

`assets.source` is the odd one out and the reason the rename was worth doing: it chooses
where Vue is served from — `local` or `cdn` — and has nothing to do with caching, so
calling it `strategy` put a fifth key by that name in a config where the other four mean
something else entirely.

### Checklist

- [ ] `pwax_component(` → `pwaxRender(`
- [ ] `pwax_route(` → `pwaxRoute(`
- [ ] `@pwax(` → `@pwaxImport(`
- [ ] `vue()` / `router()` replaced; `pwax.helpers.global` removed from config
- [ ] `service_worker.precache` → `service_worker.pages.urls`
- [ ] `service_worker.files` → `service_worker.asset_groups`
- [ ] Decided on `service_worker.pages.runtime`
- [ ] Proxy/CDN rules updated for `/sw.js` and `/manifest.json`
- [ ] Old worker at `/service-worker.js` unregistered once in the browser
- [ ] `php artisan view:clear && php artisan config:clear`
- [ ] `php artisan pwax:doctor` and `php artisan pwax:precache --verify` are clean
- [ ] Tested offline: install, go offline, navigate

---
