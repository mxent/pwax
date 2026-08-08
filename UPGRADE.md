# Upgrading

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

No per-route opt-in is needed. Listed routes are fetched without cookies, so what is
stored is the guest rendering; a route behind `auth` answers with a login screen instead
of a payload and is refused, which `php artisan pwax:precache --verify` reports.

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
