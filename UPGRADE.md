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

Pages, API responses and runtime entries are stored in caches named after the signed-in
visitor, so one person's pages are unreachable from another's session. That held for
writes and not for reads: the offline fallback used a lookup that searches *every* cache on
the origin, so two people sharing a device — the second one offline — could be served the
first one's cached responses.

Nothing to change. Upgrading is the fix.

### 2. The identity could be a session out of date

`window.pwax.config.identity` was read once, when the document loaded, and Pwax turns a
post-login `redirect()` into a client-side navigation on purpose. So someone who signed in
through the runtime kept sending the guest identity, and the first pages of their
authenticated session were filed in the partition every signed-out visitor can read.

Page payloads now report the identity they were rendered for, and the runtime follows it.

**If you call `forgetIdentity()` on sign-out, drop the argument:**

```diff
- pwax.sw.forgetIdentity(window.pwax.config.identity);
+ pwax.sw.forgetIdentity();
```

Passing it by hand was the documented pattern and the easiest way to pass a stale value.

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

Nothing to change, but two things to know.

**It rides on a response header.** Every page response carries `X-Pwax-Identity`, and a
document is stored only when it says `anon`. If a reverse proxy or CDN in front of the
application strips response headers it does not recognise, nothing is stored and you get
the previous behaviour — correct, just slower. Allow the header through.

**`forgetIdentity()` on sign-out now buys speed as well as privacy.** Stored documents are
all signed-out renderings, so they are withheld entirely once any identity has a cache on
the device — otherwise a signed-in visitor reloading offline would be told they are logged
out, and the document carries its own payload so nothing corrects it. Clearing the bucket
on sign-out restores the fast path for the next visitor.

`service_worker.pages.runtime => false` turns off both halves, as before.

### Checklist

- [ ] `service_worker.strategy` → `service_worker.runtime_strategy`, and decided on its value
- [ ] Data groups flattened; `max_size` → `max_entries`
- [ ] `forgetIdentity()` called with no argument
- [ ] `pwax.sw.registration` → `.controller` or `.registration()`
- [ ] Published shell view updated, if you have one — announcer, mount attributes
- [ ] `X-Pwax-Identity` survives your proxy or CDN, if you have one
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
`plugins`, `directives` and `middleware_js` config values follow whatever that name is.

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
visitor opens is cached so that everywhere they have been works offline. Those pages are
stored in a cache named after the signed-in identity, so one person's cached page cannot
be served to another on a shared device, and `->offline(false)` marks a page that must
never reach disk at all. Set `runtime` to false if that trade is not one you want.

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
