# Upgrading

## 1.x → 2.0

2.0 fixes defects that could not be fixed compatibly. Budget an hour for a small app.
Work through the checklist below, then run `php artisan pwax:doctor`.

```bash
composer require mxent/pwax:^2.0
php artisan pwax:install --force
php artisan view:clear && php artisan config:clear
php artisan pwax:doctor
```

---

### 1. `@import` is now `@pwax` — do this one first

**Why:** Blade matches a directive even when it has no arguments. A directive named
`import` therefore also matched the CSS at-rule `@import url("…")` inside *every*
`<style>` block in the application — not only in Pwax components — and replaced it with
JavaScript. Installing 1.x silently corrupted unrelated stylesheets.

```diff
- const Modal = @import('components.modal')
+ components: {
+     Modal: @pwax('components.modal'),
+ }
```

Find every use:

```bash
grep -rn "@import(" resources/views
```

**The call is no longer awaited.** `@pwax` returns a Vue async component synchronously.
The old `await` form deadlocked when two components imported each other, which is why
1.x needed a placeholder-mutation workaround; that is gone.

```diff
  <script>
      export default {
-         async mounted() {
-             const Modal = @import('components.modal');
-         },
+         components: {
+             Modal: @pwax('components.modal'),
+         },
      };
  </script>
```

Named exports keep the same spelling:

```blade
Backdrop: @pwax('Backdrop from components.modal'),
```

**Do not add an arrow function.** `components: { Modal: () => @pwax('…') }` is the Vue 2
lazy-component idiom, which Vue 3 dropped — Vue treats the entry as a functional
component and renders `[object Object]`. `@pwax` is already lazy, so the arrow only
breaks it. Pwax renders an explanation in place of `[object Object]` if you hit this.

Rename the directive if `@pwax` clashes with something in your app:

```php
'components' => ['directive' => 'vueComponent'],
```

The name `import` is rejected outright.

---

### 2. Helper functions are prefixed

`vue()` and `router()` occupied very common names in the global namespace.

```diff
- return vue('pages.home', ['user' => $user]);
+ return pwax_component('pages.home', ['user' => $user]);
```

```diff
- <RouterLink to="{{ router('about') }}">About</RouterLink>
+ <RouterLink to="{{ pwax_route('about') }}">About</RouterLink>
```

To defer this, re-enable the old names:

```php
'helpers' => ['global' => true],
```

They are deprecated and will be removed in 3.0.

**`vue()`'s third argument is gone.** `['arr' => true]` becomes `->toArray()`, and
`['bypass' => true]` becomes `->asJson()`:

```diff
- $data = vue('components.modal', null, ['arr' => true]);
+ $data = pwax_component('components.modal')->toArray();
```

**`pwax_route()` throws on an unknown route name when `APP_DEBUG` is on.** 1.x silently
returned the home page, which hid typos. If a page starts throwing after upgrading, the
route name was already wrong.

---

### 3. Component URLs are signed

1.x served `/__pwax__/{name}.json`, where `{name}` was an encoded view name that anyone
could construct. That let an unauthenticated caller render **any** Blade view in the
application — `GET /__pwax__/admin_x_users.json` rendered
`resources/views/admin/users.blade.php`.

2.0 signs identifiers with your `APP_KEY`, so only URLs your application emitted resolve.
Nothing to do unless you hardcoded a component URL; use `Pwax::url('view.name')` instead.

A component now has exactly **one** representation — `/__pwax__/c/{signed-id}.js`, an ES
module carrying its template, script, styles and scope together. The `.json` and `.css`
endpoints are gone. Nothing consumed them, and both rendered the view with no controller
data, so their output was misleading in exactly the case someone would have reached for
them. `Pwax::url()` accordingly no longer takes a format argument.

`APP_KEY` must be set. Rotating it invalidates outstanding identifiers; clients recover
on their next full page load.

Optionally restrict which views may be served at all:

```php
'components' => ['allowed' => ['pages.*', 'components.*']],
```

---

### 4. Component routes now run middleware

1.x registered them with no middleware, so components rendered with no session and
`auth()` was always a guest. They now run through `pwax.middleware`, default `['web']`.

If a component starts behaving differently after upgrading, it is because it can finally
see the authenticated user.

---

### 5. Config keys changed

| 1.x | 2.0 |
| --- | --- |
| `scripts` (Vue CDN URLs) | `assets.strategy` / `assets.versions` — remove the Vue, Router and Pinia entries |
| `middleware` (client middleware) | `middleware_js` — `middleware` is now the **server** middleware stack |
| `service_worker.cache_name` (versioned) | `service_worker.cache_name` + `service_worker.version` |
| `service_worker.network_first` (bool) | `service_worker.strategy` (`'network-first'` \| `'stale-while-revalidate'`) |
| `service_worker.precache => ['/']` | `[]` — the offline shell replaces it, see below |
| — | `shell`, `components.*`, `assets.*`, `minify.*`, `csp.nonce`, `routes.*` |
| — | `service_worker.components`, `.exclude`, `.files`, `.shell.*`, `.asset_manifest.*` |
| — | `manifest.id`, `.lang`, `.display_override`, `.screenshots`, `.shortcuts`, and the rest of the spec |

Republishing the config is easiest:

```bash
php artisan vendor:publish --tag=pwax-config --force
```

**If you published the service worker, republish it.** The worker is now driven by the
asset manifest at `/sw.json` rather than by a list of URLs baked into its source:

```bash
php artisan vendor:publish --tag=pwax-service-worker --force
```

Keeping a 1.x-era worker is not fatal — it will carry on caching lazily as before — but
none of the offline behaviour applies to it, and it still calls `skipWaiting()` during
install, which reloads every open tab on each deploy.

Then check what you actually get offline:

```bash
php artisan pwax:precache
php artisan pwax:doctor
```

**`precache => ['/']` should be removed.** Precaching your home page stored one signed-in
user's HTML for the next user of that device to be served, and only covered that one
route. The offline shell at `/__pwax__/shell` replaces it: it is precached automatically,
covers every route, and has nothing in it to leak. If you keep application routes in
`precache`, note that a response the server marked `no-store` is no longer stored — which
is every page rendered by `pwax_component()` unless the route calls `->cacheable()`.

**Plugin, directive and middleware values are no longer evaluated as JavaScript.** Each
is now either a component reference or a dotted path looked up on `window`:

```diff
  'plugins' => [
-     'toast' => 'ToastPlugin.install({ position: "top" })',
+     'toast' => "@pwax('plugins.toast')",   // a component exporting a Vue plugin
+     'i18n'  => 'VueI18n.createI18n',       // a global from a <script> tag
  ],
```

An expression that must run with arguments belongs in a component:

```blade
{{-- resources/views/plugins/toast.blade.php --}}
<script>
    export default {
        install(app) {
            app.use(ToastPlugin, { position: 'top' });
        },
    };
</script>
```

---

### 6. Vue, Vue Router and Pinia are self-hosted and upgraded

| | 1.x | 2.0 |
| --- | --- | --- |
| vue | 3.5.18 (unpkg) | 3.5.41 (local) |
| vue-router | 4.5.1 (unpkg) | 5.2.0 (local) |
| pinia | 3.0.3 (unpkg) | 4.0.2 (local) |

Vue Router 5 has no breaking changes for applications that did not use
`unplugin-vue-router`. Pinia 4 is ESM-only, but the bundled IIFE build is self-contained.

Remove the CDN URLs from `pwax.scripts` and publish the local copies:

```bash
php artisan vendor:publish --tag=pwax-assets
```

Add `public/vendor/pwax` to your deployment, and re-publish with `--force` after every
`composer update`.

---

### 7. Views were reorganised

If you published views in 1.x, republish and re-apply your changes:

```bash
php artisan vendor:publish --tag=pwax-views --force
```

| 1.x | 2.0 |
| --- | --- |
| `layouts/app.blade.php` | `layouts/shell.blade.php` |
| `components/vue/page.blade.php` | merged into the shell |
| `components/vue/app.blade.php` | removed — the runtime provides it |
| `components/vue/router.blade.php` | removed — the runtime provides it |
| `components/vue/loader.blade.php` | removed — the runtime provides it |
| `components/vue/content.blade.php` | `components/content.blade.php` |
| `js/main.blade.php` | removed — replaced by the built `dist/pwax.js` |
| `components/loader.blade.php` | unchanged in purpose |
| `components/error.blade.php` | unchanged in purpose; now uses `v-text` |

The client runtime is a compiled bundle served from `/__pwax__/pwax.js`, not a Blade
file. If you edited `js/main.blade.php`, the equivalent extension points are the
`pwax:*` document events and `window.pwax`.

---

### 8. Payload shape

`Vary` is now set on every response. If you cache pages at a CDN, verify it honours
`Vary: X-Pwax-Component` — without that, 1.x could already serve raw JSON to a browser.

The JSON payload keeps its 1.x keys and adds three:

```jsonc
{
  "id": "...",          // new: signed component identifier
  "hash": "...",        // new: content digest, used for ETags
  "scope": "a1b2c3d4",  // new: scoped-style id, or null
  "module": "/__pwax__/c/{id}.js",  // new: the component as an ES module
  "template": "...",
  "script": "...",
  "style": "...",
  "styles": [],
  "scripts": []
}
```

Client middleware receives `to` in addition to `component`, `meta` and `redirect`.

---

### 9. Minification is off outside production

Minified sources while developing cost more than they save. To restore 1.x behaviour:

```php
'minify' => ['enabled' => true],
```

If your web server applies gzip or brotli, consider leaving it off everywhere.

---

### Checklist

- [ ] `@import(` replaced with `@pwax(`, and the `await` removed
- [ ] `vue()` → `pwax_component()`, `router()` → `pwax_route()`
- [ ] Config republished; `middleware` → `middleware_js`; CDN script URLs removed
- [ ] Plugin/directive values converted to component references or global paths
- [ ] `php artisan vendor:publish --tag=pwax-assets` run, and `public/vendor/pwax` deployed
- [ ] Published views re-applied against the new layout
- [ ] `APP_KEY` set in every environment
- [ ] `php artisan pwax:doctor` passes
- [ ] CDN honours `Vary`, if you cache pages at the edge
