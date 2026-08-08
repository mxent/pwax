# Pwax

**Write Vue components as Blade views. Ship them as a progressive web app.**

[![Tests](https://github.com/mxent/pwax/actions/workflows/tests.yml/badge.svg)](https://github.com/mxent/pwax/actions/workflows/tests.yml)
[![Code Quality](https://github.com/mxent/pwax/actions/workflows/code-quality.yml/badge.svg)](https://github.com/mxent/pwax/actions/workflows/code-quality.yml)
[![Latest Version](https://img.shields.io/packagist/v/mxent/pwax.svg)](https://packagist.org/packages/mxent/pwax)
[![PHP Version](https://img.shields.io/packagist/dependency-v/mxent/pwax/php.svg)](https://packagist.org/packages/mxent/pwax)
[![License](https://img.shields.io/packagist/l/mxent/pwax.svg)](LICENSE)

Pwax lets you write a Vue single-file component *as a Blade view* — `<template>`,
`<script>` and `<style>` in one file — and serves it to the browser, where Vue compiles
it at runtime. You get an SPA with client-side routing, a service worker and an
installable app manifest, and you never leave PHP or run a frontend build.

```blade
{{-- resources/views/pages/home.blade.php --}}
<template>
    <div class="home">
        <h1>@{{ greeting }}</h1>
        <button type="button" @click="count++">Clicked @{{ count }} times</button>
    </div>
</template>

<script>
    export default {
        data() {
            return { greeting: @json($greeting), count: 0 };
        },
    };
</script>

<style scoped>
    .home { padding: 2rem; }
</style>
```

```php
Route::get('/', fn () => pwaxRender('pages.home', [
    'greeting' => 'Hello, ' . auth()->user()?->name,
]))->name('index');
```

That is the whole application. No `npm run build`, no `.vue` files, no separate route
table in JavaScript.

---

## Contents

- [How it works](#how-it-works)
- [Is this the right tool?](#is-this-the-right-tool)
- [Requirements](#requirements)
- [Installation](#installation)
- [Writing components](#writing-components)
- [Blade and Vue together](#blade-and-vue-together)
- [Passing data](#passing-data)
- [Routing](#routing)
- [Redirects and errors](#redirects-and-errors)
- [Importing components](#importing-components)
- [Scoped styles](#scoped-styles)
- [Plugins, directives and middleware](#plugins-directives-and-middleware)
- [Progressive web app](#progressive-web-app)
- [Frontend assets](#frontend-assets)
- [Performance](#performance)
- [Security](#security)
- [Configuration reference](#configuration-reference)
- [Artisan commands](#artisan-commands)
- [JavaScript API](#javascript-api)
- [Upgrading from 1.x](#upgrading-from-1x)
- [Testing](#testing)
- [Contributing](#contributing)

---

## How it works

A component is a Blade view. Pwax renders it, splits it into its parts, and returns them
to a small client runtime that hands the template to Vue's in-browser compiler.

```
  Browser                         Laravel
     │                               │
     │  GET /profile                 │
     ├──────────────────────────────►│  Route → pwaxRender('pages.profile')
     │                               │    render Blade → split blocks → scope styles
     │  ◄── HTML shell ──────────────┤    → embed template + script + style in the shell
     │                               │
     │  (in parallel, static, cacheable, no component request at all)
     ├── vue.global.prod.js ────────►│
     ├── vue-router / pinia ────────►│
     ├── pwax.js ───────────────────►│
     │                               │
     │  first paint
     │                               │
     │  a @pwaxImport('components.modal') first renders
     ├── GET /__pwax__/c/{id}.js ───►│  the component, as a real ES module:
     │  ◄── module ──────────────────┤  template + script + styles + scope in one file
     │                               │
     │  click <RouterLink to="/settings">
     ├── GET /settings ─────────────►│  X-Pwax-Component: true
     │  ◄── JSON payload ────────────┤  → same route, JSON instead of the shell
     │                               │
```

The same Laravel route answers both a browser navigation and an SPA navigation. Which
one you get is decided by the `X-Pwax-Component` header, and every response says so in
`Vary`.

Two shapes of component, for one reason. A **page** is rendered with controller data, so
it cannot be re-derived from its view name alone — it travels inside the page response,
script and all. An **imported** component takes no controller data, so it is addressable
at a stable signed URL, which makes it HTTP-cacheable and importable as a real module.

## Is this the right tool?

| | Pwax | [Inertia](https://inertiajs.com) | [Livewire](https://livewire.laravel.com) |
| --- | --- | --- | --- |
| Frontend build step | none | Vite required | none |
| Component source | Blade views | `.vue` / `.jsx` files | Blade + PHP class |
| Where components render | browser (Vue) | browser (Vue) | server (round trip per interaction) |
| Client-side reactivity | full Vue | full Vue | server-driven |
| Ships a compiler to the browser | yes (~50 kB of Vue) | no | no |

Reach for Pwax when you want real client-side Vue but not a Node toolchain — internal
tools, admin panels, prototypes, or apps that must be installable and work offline.
Reach for **Inertia** if you are happy running Vite and want tree-shaking, TypeScript,
and `<script setup>`. Reach for **Livewire** if you would rather not write JavaScript.

The honest trade-off: Vue's in-browser compiler is about 50 kB gzipped more than the
runtime-only build, and compiling templates in the browser requires
`script-src 'unsafe-eval'` in your Content-Security-Policy. See [Security](#security).

## Requirements

- PHP 8.2 or higher (8.3+ if you are on Laravel 13)
- Laravel 12 or 13

Node is **not** required to use Pwax. It is only needed to contribute to the client
runtime.

## Installation

```bash
composer require mxent/pwax
php artisan pwax:install
```

`pwax:install` publishes `config/pwax.php` and copies Vue, Vue Router and Pinia into
`public/vendor/pwax`. Add `--views` to publish the Blade views as well.

Then point a route at a component and create it:

```bash
php artisan pwax:component pages.home
```

```php
// routes/web.php
Route::get('/', fn () => pwaxRender('pages.home'))->name('index');
```

Check your setup at any time:

```bash
php artisan pwax:doctor
```

> **After every `composer update`**, re-run
> `php artisan vendor:publish --tag=pwax-assets --force` so the published Vue build stays
> in step with the package. `pwax:doctor` will tell you if it drifts.

## Writing components

A component is a Blade view containing any of `<template>`, `<script>` and `<style>`.

```blade
<template>
    <article class="post">
        <h1>@{{ post.title }}</h1>
        <p>@{{ post.body }}</p>
    </article>
</template>

<script>
    export default {
        props: { post: { type: Object, required: true } },
    };
</script>

<style scoped>
    .post { max-width: 60ch; }
</style>
```

Rules worth knowing:

- **`<template>` is the root.** Nested `<template v-if>` and `<template #slot>` work;
  the parser matches closing tags by depth.
- **`<script>` is an ES module.** `export default` is your component; `import` statements
  resolve relative to the component's URL; named exports are importable by other
  components.
- **Multiple blocks are allowed.** Several `<script>` blocks are concatenated, several
  `<style>` blocks are merged.
- **`<script src>` and `<link rel="stylesheet">` are treated as external assets** and
  loaded before the component renders, rather than inlined.
- **One HTML rule applies:** a literal `</script>` inside a JavaScript string ends the
  block. Write `<\/script>`, exactly as in a plain HTML page.

## Blade and Vue together

Both languages use `{{ }}` and `@`. Two rules cover it:

**Escape Vue's interpolation with `@{{ }}`.** Blade renders `@{{ x }}` as `{{ x }}`, so
Vue receives it:

```blade
<h1>@{{ title }}</h1>       {{-- Vue renders this --}}
<h1>{{ $title }}</h1>       {{-- Blade renders this, once, on the server --}}
```

**Most `@` attributes are safe, but a few collide.** Blade only compiles `@name` when
`name` is a registered directive. `@click`, `@submit`, `@input` and friends pass
through untouched — but Laravel ships directives called `@error`, `@class`, `@style`,
`@checked`, `@disabled`, `@selected` and `@readonly`. Writing `@error="onError"` in a
Vue template invokes Blade's `@error` directive instead.

Use the `v-on:` longhand for those:

```blade
<video v-on:error="onError"></video>   {{-- not @error --}}
<input v-on:change="save">             {{-- @change is fine, but be consistent --}}
```

For a larger block, `@verbatim` disables Blade entirely:

```blade
@verbatim
<template>
    <p>{{ message }}</p>
    <video @error="onError"></video>
</template>
@endverbatim
```

Note that `@verbatim` also disables `@json()` and `{{ $phpVariable }}`, so keep
server-injected values outside it.

## Passing data

Pass an array as the second argument. It becomes ordinary Blade view data:

```php
Route::get('/posts/{post}', fn (Post $post) => pwaxRender('pages.post', [
    'post' => $post,
    'canEdit' => auth()->user()?->can('update', $post),
]))->name('posts.show');
```

```blade
<script>
    export default {
        data() {
            return {
                post: @json($post),
                canEdit: @json($canEdit),
            };
        },
    };
</script>
```

`@json()` escapes for a JavaScript context — always prefer it to `{!! json_encode(...) !!}`.

Because components render inside your middleware stack, `auth()`, `session()`,
`request()` and policies all work exactly as they do in any other Blade view.

## Routing

Routes stay in `routes/web.php`. There is no second route table to maintain. Vue Router
hands every path to Pwax, which asks the server what to render.

```php
Route::get('/', fn () => pwaxRender('pages.home'))->name('index');
Route::get('/about', fn () => pwaxRender('pages.about'))->name('about');

Route::middleware('auth')->group(function () {
    Route::get('/settings', fn () => pwaxRender('pages.settings'))->name('settings');
});
```

Link between pages with `<RouterLink>`, using `pwaxRoute()` to resolve named routes:

```blade
<template>
    <nav>
        <RouterLink to="{{ pwaxRoute('index') }}">Home</RouterLink>
        <RouterLink to="{{ pwaxRoute('posts.show', ['post' => 1]) }}">First post</RouterLink>
    </nav>
</template>
```

`pwaxRoute()` returns a path (`/posts/1`); pass `true` as the third argument for an
absolute URL. Unlike 1.x's `router()`, an unknown route name **throws** when
`APP_DEBUG` is on rather than silently sending the link to your home page.

### History mode

Pwax uses the History API by default, so URLs have no `#`. Your web server must send
unknown paths to `index.php` — Laravel's default `nginx`/Apache configuration already
does. If yours cannot, set `hash_route => true`.

## Redirects and errors

`fetch` follows redirects transparently, which would normally turn a `302` from your
`auth` middleware into an unparseable HTML body. Pwax's middleware translates them:

| Server response | What the client does |
| --- | --- |
| `return redirect('/somewhere')` | SPA navigation, no page reload |
| `return redirect()->away(...)` | full page navigation |
| `auth` middleware rejects the request | full page load of your login screen |
| `419` expired CSRF token | full page reload to pick up a fresh token |
| `404`, `403`, `401`, `5xx` | renders the error template |

The first two are translated by Pwax's middleware. The next two cannot be — `auth` and
`VerifyCsrfToken` *throw*, so their redirects are produced by the exception handler
outside the middleware pipeline — so the client handles them instead, by treating a
followed redirect that returns HTML as an instruction to reload.

So this just works:

```php
Route::middleware('auth')->get('/settings', fn () => pwaxRender('pages.settings'));
```

An unauthenticated visitor is taken to your login page by the SPA router.

Customise the error and loading markup by publishing the views, or by pointing
`pwax.blade.error` / `pwax.blade.loader` at your own:

```blade
{{-- resources/views/pwax/error.blade.php --}}
<div class="error" role="alert">
    <h1 v-text="error.statusText"></h1>
    <p v-text="error.message"></p>
    <button type="button" @click="retry">Try again</button>
</div>
```

`error` exposes `status`, `statusText` and `message`; `retry()` refetches the page.

> Use `v-text`, not `v-html`. Part of `error` derives from the HTTP response, and
> rendering that as HTML would make reflected content executable.

## Importing components

Use the `@pwax` directive to reference another component:

```blade
<template>
    <div>
        <Modal v-if="open" @close="open = false" />
    </div>
</template>

<script>
    export default {
        components: {
            Modal: @pwaxImport('components.modal'),
        },
        data() {
            return { open: false };
        },
    };
</script>
```

To pick a named export instead of the default one:

```blade
Backdrop: @pwaxImport('Backdrop from components.modal'),
```

`@pwax` returns a Vue async component, resolved the first time it renders. That means:

- **Circular imports work.** Two components can reference each other freely.
- **Nothing loads until it is needed.** A modal behind a `v-if` is never fetched until
  the modal opens.
- **Each component is fetched once per session**, and cached by the browser after that.

> **Do not wrap it in an arrow function.**
>
> ```blade
> Modal: @pwaxImport('components.modal'),        {{-- correct --}}
> Modal: () => @pwaxImport('components.modal'),  {{-- renders as [object Object] --}}
> ```
>
> `() => Component` is the Vue **2** idiom for lazy components and was dropped in Vue 3.
> Vue 3 sees a function and treats the entry as a *functional component*, calls it during
> render, and gets a component object back where it expected vnodes. `@pwax` is already
> lazy — the arrow adds nothing. If you do write it, Pwax renders an explanation on the
> page instead of `[object Object]`.

> The directive is `@pwax`, not `@import`. A Blade directive named `import` also matches
> the CSS at-rule `@import url(...)` inside `<style>` blocks — see
> [Upgrading from 1.x](#upgrading-from-1x). You can rename it with
> `pwax.components.directive`; the name `import` is rejected.

## Scoped styles

Add `scoped` to a `<style>` block and its rules only apply to that component:

```blade
<template>
    <div class="card"><p class="title">Hi</p></div>
</template>

<style scoped>
    .card { border: 1px solid #ddd; }
    .title { font-weight: 600; }
</style>
```

Pwax rewrites the selectors to `.card[data-pwax-a1b2c3d4]` and stamps the template's
elements with the matching attribute — the same approach Vue's SFC compiler takes at
build time, done here at render time.

Two escape hatches, named as in Vue:

```css
.wrapper :deep(.child-component-class) { color: red; }  /* reach into a child */
:global(.body-modifier) { overflow: hidden; }           /* opt out entirely */
```

`@keyframes`, `@font-face` and `@import` are left untouched. Turn the whole feature off
with `pwax.components.scoped_styles => false`.

## Plugins, directives and middleware

Register Vue plugins and directives in `config/pwax.php`. Each value is either a
component reference or a dotted path to a global:

```php
'plugins' => [
    // A Pwax component whose default export is a Vue plugin.
    'toast' => "@pwaxImport('plugins.toast')",

    // A UMD library already loaded by a <script> tag.
    'i18n'  => 'VueI18n.createI18n',
],

'directives' => [
    'focus' => "@pwaxImport('directives.focus')",
],
```

> These values are **never evaluated as code**. A component reference is imported; a
> dotted path is looked up on `window`. In 1.x they were interpolated straight into the
> page inside `{!! !!}`, so a stray quote broke the whole application and any path by
> which config could be influenced was remote code execution.

### Client middleware

`middleware_js` entries run before a page component mounts, and may redirect:

```php
'middleware_js' => [
    'confirmed' => "@pwaxImport('middleware.confirmed')",
],
```

```blade
{{-- resources/views/middleware/confirmed.blade.php --}}
<script>
    export default async function ({ component, meta, redirect }) {
        if (meta.requiresConfirmation && !window.localStorage.getItem('confirmed')) {
            redirect('/confirm');
        }
    };
</script>
```

Opt a page in from its own script:

```js
export default {
    middleware: ['confirmed'],
    meta: { requiresConfirmation: true },
};
```

> Client middleware is for user experience, not for access control. It runs in the
> browser and can be bypassed. Enforce authorisation with Laravel middleware and
> policies.

## Progressive web app

### Manifest

Served from `/manifest.json` and configured in `config/pwax.php`. Browsers require
a 192×192 and a 512×512 icon before offering to install:

```php
'manifest' => [
    // Set this once and never change it. Without it a browser identifies the installed
    // app by start_url, so changing start_url later orphans every existing install.
    'id' => '/',

    'name' => 'My Application',
    'short_name' => 'MyApp',
    'theme_color' => '#0c83ff',
    'icons' => [
        ['src' => '/images/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => '/images/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ['src' => '/images/maskable.png', 'sizes' => '512x512', 'type' => 'image/png',
         'purpose' => 'maskable'],
    ],
    'screenshots' => [
        ['src' => '/images/wide.png', 'sizes' => '1280x720', 'type' => 'image/png',
         'form_factor' => 'wide'],
    ],
],
```

Every member of the [Web App Manifest specification](https://www.w3.org/TR/appmanifest/)
is passed through, so `display_override`, `shortcuts`, `launch_handler`, `share_target`,
`protocol_handlers` and the rest work by adding them to the array. Empty values are
dropped — `false` and `0` are not, so `prefer_related_applications => false` survives.

Pwax also emits the tags iOS needs, which are not in the manifest at all:
`apple-touch-icon` (chosen from your non-maskable icons), `apple-mobile-web-app-capable`,
`apple-mobile-web-app-title` and `application-name`.

### Offline

Off by default. Turn it on:

```php
'service_worker' => ['enabled' => true],
```

That is the whole configuration for a fully offline-capable app. Pwax generates an asset
manifest at **`/sw.json`**, listing every URL the application is made of with a content
hash:

```json
{
  "configVersion": 2,
  "hash": "9c41f0be2a7d5581",
  "version": "v1",
  "shellUrl": "/__pwax__/shell",
  "navigationStrategy": "network-first",
  "pageHeaders": {
    "Accept": "application/json",
    "X-Requested-With": "XMLHttpRequest",
    "X-Pwax-Component": "true"
  },
  "assetGroups": [
    { "name": "app", "installMode": "prefetch", "kind": "asset", "urls": [
        "/vendor/pwax/vue.global.prod.js?v=3.5.41",
        "/vendor/pwax/vue-router.global.prod.js?v=5.2.0",
        "/__pwax__/pwax.js",
        "/manifest.json",
        "/favicon.ico",
        "/__pwax__/shell"
    ]},
    { "name": "components", "installMode": "prefetch", "kind": "asset", "urls": [
        "/__pwax__/c/Y29tcG9uZW50cy5tb2RhbA3f9a1c0d.js"
    ]},
    { "name": "assets", "installMode": "lazy", "kind": "asset",
      "urls": ["/images/logo.svg"], "patterns": ["^\\/images\\/.*$"] },
    { "name": "pages", "installMode": "prefetch", "kind": "page",
      "strategy": "freshness", "credentials": "omit", "urls": ["/", "/about"] }
  ],
  "dataGroups": [],
  "hashTable": { "/__pwax__/c/Y29tcG9uZW50cy5tb2RhbA3f9a1c0d.js": "a41c9b02f7de5163" },
  "critical": ["/__pwax__/pwax.js", "/__pwax__/shell"],
  "warnings": []
}
```

`pageHeaders` is the one entry worth understanding. Page responses vary on those three
headers, so the worker both fetches with them and keys its cache on them — when the two
sides disagreed, every page lookup missed silently, which is the defect this release
exists to fix.

`sw.js` fetches that on install and caches the lot in one pass. **Nothing has
to be visited first to be available**: a visitor who loaded one page can go offline and
still reach every route and every component in the application.

Components are discovered by scanning your view paths for Blade files with a `<template>`
block, or a `<script>` block that exports — which also picks up the script-only views used
for plugins, directives and client middleware. Include all of them, or pick:

```php
'service_worker' => [
    'enabled' => true,
    'components' => 'all',                    // every component (default)
    'components' => ['components.*', 'ui.*'], // only these
    'components' => false,                    // none; cached lazily as they load
    'exclude' => ['vendor.pwax.*', 'admin.*'],
],
```

Your own static files — images, fonts, stylesheets, build output — come from asset groups,
which take globs:

```php
'service_worker' => [
    'asset_groups' => [
        [
            'name' => 'app',
            'install_mode' => 'prefetch',                 // fetched at install
            'files' => ['/favicon.ico', '/css/**.css', '/js/**.js', '/build/**'],
        ],
        [
            'name' => 'assets',
            'install_mode' => 'lazy',                     // fetched on first use, then kept
            'files' => ['/images/**', '/fonts/**'],
        ],
    ],
],
```

`**` crosses directories, `*` does not, `{a,b}` and `(a|b)` alternate, and a leading `!`
excludes. `public/storage` is never walked — it is a symlink to user uploads — and `.php`
files, dotfiles and source maps are never matched whatever the patterns say. `max_files`
and `max_bytes` cap a runaway glob and report what they truncated.

Only your own view paths are scanned. Package view namespaces are not — every package
that calls `loadViewsFrom()` registers one, Laravel's own exception-page renderer
included, and none of those are components your application imports. Opt in by name if
you publish components from a package of your own:

```php
'service_worker' => ['namespaces' => ['acme-ui']],   // finds @pwaxImport('acme-ui::button')
```

See exactly what that resolves to:

```bash
php artisan pwax:precache            # what will be available offline
php artisan pwax:precache --verify   # …and which components actually render
php artisan pwax:precache --json     # the manifest itself
```

> **Developing against `php artisan serve`.** PHP's built-in server handles one request
> at a time. Pwax precaches at most six assets at once for that reason, but an install is
> still the largest burst of requests your app will ever make, and it will feel slow. Give
> the server some workers:
>
> ```bash
> PHP_CLI_SERVER_WORKERS=8 php artisan serve
> ```
>
> Or develop against Herd, Valet, Octane or nginx. Nothing breaks either way — a precache
> entry that fails is retried on the next install — but the difference is noticeable.

### Cache busting

Each entry is content-addressed and the digest of the whole table names the cache, so
**shipping a change is just deploying it**. A release that changed one component
re-downloads that one file and copies the rest from the previous cache; a client can never
end up with a mix of two builds. `version` is still there, but only for when you want to
discard everything deliberately:

```php
'service_worker' => ['version' => 'v2'],
```

The manifest hash is embedded in `sw.js` itself. That is what makes a deploy
reach an existing install: a browser only treats a worker as new if its bytes differ, so a
worker whose source never changed would leave clients on the build they first installed.

### The offline shell

Navigations always go to the network, and their responses are **never stored**. The Cache
API ignores HTTP cache directives, so a worker that cached what it fetched would persist
to disk exactly the documents the server marked `no-store, private` — a signed-in user's
rendered page, which the next person to use that device would be served offline.

Instead Pwax precaches `/__pwax__/shell`: the same SPA shell rendered with no session, no
CSRF token and no page component, identical for every visitor. When a navigation cannot
reach the network the worker serves that, the runtime boots, and client-side routing
carries on as normal.

The page *content* for a route is a separate question, and it is the one that decides
whether the application is genuinely usable offline or merely renders a shell. Two
mechanisms cover it.

**Precached pages.** Every route that renders a page is found automatically and fetched at
install, so the whole application works offline before any of it has been visited:

```php
Route::get('/settings', fn () => pwaxRender('pages.settings'));   // found
```

Each precached page is stored twice: the JSON payload for client-side routing, and the
rendered HTML for a direct navigation — the document with the component already inlined in
its `pwax-initial` island, so an offline navigation paints at once rather than showing a
spinner while the runtime fetches something it already has.

Discovery reads the route's action for a literal view name given to `pwaxRender()`,
`Pwax::render()` or `pwax()`. That view name is the point — `service_worker.components`
scopes pages the same way it scopes components, so `'all'` takes every page and
`['pages.*']` takes only the ones whose view matches. One setting, both halves.

What it cannot read statically — a computed view name, a render behind a service, or a
parameterised route like `/posts/{post}`, which is a template rather than a page — goes in
the list by hand:

```php
'service_worker' => ['pages' => ['urls' => ['/posts/hello-world']]],
```

`php artisan pwax:precache` prints exactly what was found. Turn discovery off with
`pages.discover => false`.

The worker asks for these without cookies, so what it stores is the guest rendering. A
route behind `auth` answers that request with a login screen rather than a payload, which
is refused and reported — listing one is harmless, it just will not be there before
sign-in.

`->cacheable()` is a separate matter: it relaxes the *HTTP* caching headers on a page
payload, letting the browser and any shared proxy hold it too. It is not needed for
offline.

```php
Route::get('/docs/{page}', fn ($page) => pwaxRender('pages.docs', [...])
    ->cacheable(86400, shared: true));
```

**Visited pages.** On by default, and what makes an authenticated application work
offline rather than only its public routes: every page a visitor opens is cached as they
go.

```php
'service_worker' => ['pages' => ['runtime' => true]],
```

Storing a signed-in user's page is only safe because of where it is stored. The Cache API
is scoped to the origin, not to a user, so these go into a cache named after an opaque
HMAC of the signed-in identity — one person's cached page is not merely cleared when
somebody else signs in, it was never reachable under their name. Call
`pwax.sw.forgetIdentity(window.pwax.config.identity)` on sign-out to drop it immediately,
and mark anything that must never reach disk at all:

```php
Route::get('/recovery-codes', fn () => pwaxRender('pages.codes')->offline(false));
```

Point `offline_url` at your own page to replace the fallback, publish the worker to change
its behaviour:

```bash
php artisan vendor:publish --tag=pwax-service-worker
```

### Update prompts

When a new version is waiting, Pwax fires an event instead of silently leaving the
visitor on the old build:

```js
document.addEventListener('pwax:update-available', (event) => {
    if (confirm('A new version is available. Reload now?')) {
        event.detail.activate(); // page reloads once the new worker takes over
    }
});
```

The page reloads **only** when it asked for the update. A worker that activated for any
other reason will not restart your users' tabs and discard what they were typing.

An open tab re-checks for a new build hourly and when it regains focus. `pwax.sw.update()`
checks on demand.

### Going offline and back

```js
document.addEventListener('pwax:offline', () => banner.hidden = false);
document.addEventListener('pwax:online', () => banner.hidden = true);
```

### On sign-out

Drop the signing-out user's pages, runtime entries and API responses, leaving the
precached framework and components in place so the application still works offline for
whoever comes next:

```js
await window.pwax.sw.forgetIdentity(window.pwax.config.identity);
```

`pwax.sw.clearCaches()` is the heavier hammer — it discards everything, including the
framework, so the next visitor downloads the application again. Reach for it when a
component renders differently for an administrator and you want no trace left.

## Frontend assets

Vue, Vue Router and Pinia are **self-hosted by default**, published to
`public/vendor/pwax`:

| Package | Version |
| --- | --- |
| [vue](https://www.npmjs.com/package/vue) | 3.5.41 |
| [vue-router](https://www.npmjs.com/package/vue-router) | 5.2.0 |
| [pinia](https://www.npmjs.com/package/pinia) | 4.0.2 |

Self-hosting is the default because a progressive web app that fetches its framework
from a third-party CDN cannot start offline — which defeats the purpose — and discloses
every visitor's IP address to that CDN.

To use a CDN anyway, with subresource integrity:

```php
'assets' => ['strategy' => 'cdn'],
```

Pinia can be dropped if you do not use a store:

```php
'assets' => ['pinia' => false],
```

> Pwax needs the **full** Vue build (`vue.global.prod.js`). The runtime-only build has no
> template compiler and cannot render a template string.

To update Vue, see [`resources/vendor/README.md`](resources/vendor/README.md).

## Performance

**First paint costs one round trip.** The shell arrives with the current component fully
embedded — template, styles and script — so there is no follow-up request for the page at
all. Only the framework and the runtime are fetched, in parallel, and both are static and
cacheable. In 1.x the browser made six sequential requests before it could render
anything.

**Repeat navigation compiles each component once.** Compiled modules are cached on the
content hash the server sends, so returning to a page reuses the module rather than
building another one.

**Everything is cached at the right layer:**

| Response | Caching |
| --- | --- |
| Page HTML | `no-store, private` — carries the CSRF token |
| Page JSON | `no-store, private`, unless the route calls `->cacheable()` (an HTTP-cache hint; the service worker stores it either way unless `->offline(false)`) |
| Component module (`/__pwax__/c/{id}.js`) | `private, max-age`, with an `ETag` → `304` |
| `pwax.js` | `public, max-age=31536000, immutable` |
| Web manifest | `public, max-age=86400`, with an `ETag` |
| Offline shell (`/__pwax__/shell`) | `public, must-revalidate`, with an `ETag` |
| Asset manifest (`/sw.json`) | `no-cache, must-revalidate` — how updates are discovered |

**Compilation is memoised** on a digest of the rendered output, so a component that has
not changed is not re-parsed, re-scoped or re-minified. Because the key is the output
itself, the cache can never go stale.

**Minification** runs in production only, and its results are cached by content. If your
web server already applies gzip or brotli, turn it off — you recover most of the bytes
with no CPU cost and no risk of a regex-based minifier mangling valid JavaScript:

```php
'minify' => ['enabled' => false],
```

## Security

### Component identifiers are signed

Component URLs carry an HMAC derived from your `APP_KEY`:

```
/__pwax__/c/cGFnZXMuaG9tZQ3f9a1c0d4b8e2a67.js
```

Only identifiers your application itself emitted will resolve, so these endpoints cannot
be used to render arbitrary Blade views. Signatures are compared with `hash_equals`.

For defence in depth, restrict which views may be served at all:

```php
'components' => ['allowed' => ['pages.*', 'components.*']],
```

> Rotating `APP_KEY` invalidates every previously emitted identifier. Clients recover on
> their next full page load.

### Content-Security-Policy

Vue compiles templates in the browser with the `Function` constructor, so
`script-src 'unsafe-eval'` is required. There is no way around it while templates are
compiled client-side — if that is unacceptable in your environment, use Inertia instead.

Imported components are fetched from real same-origin URLs. A **page** component cannot
be — it is rendered with controller data, so it ships its script inline and the runtime
compiles it from a `blob:` URL. That needs `blob:` in `script-src`. (`data:` is never
used: unlike `blob:`, a `data:` URL in `script-src` makes any injected string
executable.)

```
Content-Security-Policy:
    default-src 'self';
    script-src 'self' blob: 'unsafe-eval';
    style-src 'self' 'nonce-{NONCE}';
    connect-src 'self';
    img-src 'self' data:;
    base-uri 'self';
    form-action 'self';
    frame-ancestors 'none';
    object-src 'none'
```

Supply the nonce for Pwax's inline `<style>` and JSON blocks:

```php
'csp' => ['nonce' => fn () => request()->attributes->get('csp-nonce')],
```

### What the service worker will and will not store

The Cache Storage API ignores HTTP cache directives, so a worker that stores whatever it
fetches writes signed-in users' rendered pages to disk — where the next person to use that
device is served them offline. Assets carrying `Cache-Control: no-store` are refused
outright, and a navigation's HTML is never stored on any path: what is served offline is
the session-free shell.

Page payloads are the deliberate exception, because a shell with nothing to render in it
is not an offline app. Three things make storing them safe, and they are worth knowing:

- **They are partitioned by identity.** A visited page goes in a cache named after an
  opaque HMAC of the signed-in user, so another session on the same device cannot reach
  it — it is not cleared later, it was never addressable. Call
  `pwax.sw.forgetIdentity(window.pwax.config.identity)` on sign-out to drop it at once.
- **Precached pages are fetched without cookies**, so what installs is the guest
  rendering. A route behind `auth` answers with a login screen and is refused.
- **`->offline(false)` refuses outright**, for a page that must not reach disk under any
  circumstances — a one-time code, a recovery key. `service_worker.pages.runtime => false`
  turns the whole behaviour off.

One thing this does not cover: **a component can still vary by user** — an admin-only
branch, a localised string — and precached components are stored per browser profile, not
per identity. On a shared device, call `pwax.sw.clearCaches()` when someone signs out.

### Your responsibilities

- **Never put user input in `plugins`, `directives` or `middleware_js`.** They describe
  what the page loads.
- **Never interpolate unescaped user input into a `<template>`.** Blade's `{{ }}`
  escapes; `{!! !!}` and Vue's `v-html` do not.
- **Authorise on the server.** Client middleware is a UX affordance, not access control.
- **Keep `APP_KEY` secret and stable.**

CSRF tokens are read from the `<meta name="csrf-token">` tag and sent with every runtime
request automatically.

Report vulnerabilities privately — see [SECURITY.md](SECURITY.md).

## Configuration reference

| Key | Default | Purpose |
| --- | --- | --- |
| `hash_route` | `false` | Use `#/` URLs instead of the History API |
| `home` | `'index'` | Named route used as a fallback target |
| `route_prefix` | `'__pwax__'` | URL prefix for component endpoints |
| `shell` | `'pwax::layouts.shell'` | Blade view used as the SPA shell |
| `middleware` | `['web']` | Middleware for component routes |
| `routes.register` | `true` | Register package routes automatically |
| `routes.domain` | `null` | Restrict package routes to a domain |
| `routes.static_middleware` | `[]` | Middleware for runtime/manifest/worker |
| `components.directive` | `'pwaxImport'` | Blade directive name (`import` is rejected) |
| `components.allowed` | `[]` | Allowlist of servable view patterns |
| `components.scoped_styles` | `true` | Honour `<style scoped>` |
| `blade.*` | `null` | Override bundled partials |
| `assets.strategy` | `'local'` | `local` or `cdn` |
| `assets.local_path` | `'/vendor/pwax'` | Where published assets live |
| `assets.versions` | see config | Pinned Vue / Router / Pinia versions |
| `assets.pinia` | `true` | Load Pinia at all |
| `styles`, `scripts` | `[]` | Extra tags; string or attribute array |
| `plugins`, `directives`, `middleware_js` | `[]` | Vue extensions |
| `minify.enabled` | production only | Minify component sources |
| `minify.store`, `minify.ttl` | `null` | Cache for minified output |
| `cache.asset_ttl` | `3600` | `max-age` for component assets |
| `cache.components` | `true` | Memoise compiled components |
| `csp.nonce` | `null` | Nonce (or callable) for inline blocks |
| `customization.*` | see config | Preloader colours |
| `manifest_path`, `manifest` | see config | Web App Manifest (all spec members) |
| `head.title`, `.title_template` | `null` | Document title and its wrapper |
| `head.description`, `.icon` | `null` | Fall back to the manifest's |
| `head.base` | `null` | `<base href>`; off because it rewrites every relative URL |
| `head.color_scheme`, `.theme_color_dark` | `null` | Dark-mode head hints |

### Service worker

| Key | Default | Purpose |
| --- | --- | --- |
| `service_worker.enabled` | `false` | Register and serve the worker |
| `service_worker.path`, `.scope` | `/sw.js`, `/` | Where it lives and what it controls |
| `service_worker.version` | `'v1'` | Mixed into the manifest hash; bump to discard everything |
| `service_worker.strategy` | `network-first` | For requests not in the manifest |
| `service_worker.max_entries` | `60` | Cap on the **runtime** cache; precached entries are never evicted |
| `service_worker.asset_manifest.path` | `/sw.json` | Where the asset manifest is served |
| `service_worker.asset_manifest.ttl` | `60` | Seconds the built manifest is memoised |
| `service_worker.shell.enabled`, `.path` | `true`, `/__pwax__/shell` | The session-free offline shell |
| `service_worker.assets` | `true` | Precache Vue, the runtime and the web manifest |
| `service_worker.components` | `'all'` | `'all'`, `false`, or a list of view patterns |
| `service_worker.exclude` | `['vendor.pwax.*']` | Never precached, whatever `components` says |
| `service_worker.paths` | `[]` | Extra directories to scan for components |
| `service_worker.asset_groups` | see config | Glob-resolved static assets, prefetch or lazy |
| `service_worker.max_files`, `.max_bytes` | `2000`, `64 MB` | Ceilings on what a glob may pull in |
| `service_worker.source_maps` | `false` | Precache `.map` files |
| `service_worker.exclude_files` | `[]` | Globs never precached |
| `service_worker.pages.urls` | `[]` | Extra routes to precache, beyond the discovered ones |
| `service_worker.pages.discover` | `true` | Find every route that renders a page, scoped by `components` |
| `service_worker.pages.runtime` | `true` | Cache pages as they are visited |
| `service_worker.pages.strategy`, `.timeout` | `freshness`, `2000` | How a page payload is fetched |
| `service_worker.pages.credentials` | `'omit'` | Precache the guest rendering, not one visitor's |
| `service_worker.pages.as_components` | `false` | Also precache page views as importable modules |
| `service_worker.data_groups` | `[]` | API response caching |
| `service_worker.navigation_strategy` | `network-first` | Or `app-shell` for zero-round-trip navigation |
| `service_worker.navigation_urls` | see config | Which navigations the worker claims |
| `service_worker.identity_cache_limit` | `2` | Signed-in identities keeping caches on one device |
| `service_worker.offline_url` | `null` | Page shown offline; defaults to the shell |
| `service_worker.navigation_preload` | `true` | Start the network request before the worker boots |

## Artisan commands

| Command | Purpose |
| --- | --- |
| `pwax:install` | Publish config and frontend assets (`--views`, `--force`, `--no-assets`) |
| `pwax:component <name>` | Scaffold a component view (`--plain`, `--force`) |
| `pwax:precache` | List everything available offline (`--verify`, `--json`) |
| `pwax:doctor` | Check for common misconfigurations |
| `pwax:clear` | Flush compiled caches and the offline manifest |

## JavaScript API

The runtime publishes `window.pwax`:

| Member | Description |
| --- | --- |
| `pwax.component(url, export?)` | Vue async component for a component URL |
| `pwax.load(url, export?)` | Promise of the component's options |
| `pwax.http.json(url, options?)` | Fetch JSON with Pwax's headers and CSRF token |
| `pwax.styles` | The reference-counted style manager |
| `pwax.sw.update()` | Check for a new build now |
| `pwax.sw.clearCaches()` | Delete every Pwax cache, framework included |
| `pwax.sw.forgetIdentity(id)` | Drop one signed-in identity's pages and data — call on sign-out |
| `pwax.sw.unregister()` | Remove the service worker entirely |
| `pwax.app`, `pwax.router` | The Vue app and router instances |
| `pwax.config`, `pwax.version` | Runtime configuration and package version |

Events on `document`:

| Event | Fired when |
| --- | --- |
| `pwax:ready` | The app has mounted |
| `pwax:navigating` | A navigation has started |
| `pwax:navigated` | A page component has mounted |
| `pwax:error` | A page failed to load |
| `pwax:update-available` | A new service worker is waiting |
| `pwax:online`, `pwax:offline` | The connection came back or went away |

## Upgrading from 1.x

2.0 is a breaking release. See [UPGRADE.md](UPGRADE.md) for the full checklist.

The change to make first, even if you upgrade nothing else: **1.x registered a Blade
directive named `import`.** Blade matches a directive even with no arguments, so
`@import url("fonts.css")` inside *any* `<style>` block in your application — not just in
Pwax components — was replaced with JavaScript. If you have ever had a stylesheet
mysteriously stop working, that was why. The directive is now `@pwax`.

Headlines:

| 1.x | 2.0 |
| --- | --- |
| `@import('view')` | `@pwaxImport('view')` |
| `vue('view', $data)` | `pwaxRender('view', $data)` |
| `router('name')` | `pwaxRoute('name')` |
| `/__pwax__/{name}.json` | `/__pwax__/c/{signed-id}.js` |
| Component routes had no middleware | run through `web` |
| Vue 3.5.18 / Router 4 / Pinia 3 from unpkg | 3.5.41 / 5.2.0 / 4.0.2, self-hosted |

## Testing

```bash
composer install
composer check            # Pint, PHPStan and PHPUnit
composer test
```

For the client runtime:

```bash
npm ci
npm run lint
npm test
npm run build             # dist/pwax.js is committed
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Bug reports, questions and pull requests are all
welcome. Please report security issues privately — see [SECURITY.md](SECURITY.md).

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT — see [LICENSE](LICENSE).

Vue, Vue Router and Pinia are redistributed unchanged under their own MIT licenses; see
[`resources/vendor/README.md`](resources/vendor/README.md).
