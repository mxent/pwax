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

Everything renders in the browser. The server's job is to answer with a component and its
data; the page is built on the device, which is what lets the whole application be
precached and keep working with no network at all.

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
- [Navigating](#navigating)
- [Redirects and errors](#redirects-and-errors)
- [Importing components](#importing-components)
- [Scoped styles](#scoped-styles)
- [Plugins, directives and middleware](#plugins-directives-and-middleware)
- [Progressive web app](#progressive-web-app)
- [Being opened by the operating system](#being-opened-by-the-operating-system)
- [Push notifications](#push-notifications)
- [Extending the service worker](#extending-the-service-worker)
- [Frontend assets](#frontend-assets)
- [Performance](#performance)
- [Precompiling templates](#precompiling-templates)
- [SEO and page metadata](#seo-and-page-metadata)
- [Security](#security)
- [Configuration reference](#configuration-reference)
- [Artisan commands](#artisan-commands)
- [JavaScript API](#javascript-api)
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

Pwax makes one trade, and everything else follows from it: **templates are compiled in
the browser instead of at build time.**

What you get for it:

- **No build step.** No `npm install`, no Vite config, no `dist/` to deploy, no build
  that can be out of date. Editing a Blade view is deploying a component.
- **One language for the whole page.** A component is a Blade view, so `@if`, `@json`,
  `auth()`, policies, translations and route helpers all work inside it — server-rendered
  where it makes sense, reactive where it does not.
- **One list of routes.** The Laravel route table is the application's route table; there
  is no second one in JavaScript to keep in step with it.
- **Installable and offline by default.** A service worker, an app manifest and a
  precached shell come with the package rather than being assembled afterwards. Because
  the page is built on the device, a route works offline once its component and payload
  are on disk — there is no server render to be unable to reach.

What it costs:

- **20 kB gzipped**, over and above the runtime-only build, for Vue's compiler.
- **`script-src 'unsafe-eval'`** in your Content-Security-Policy, because compiling a
  template in the browser means calling the `Function` constructor. See
  [Security](#security).
- **No `<script setup>`, no single-file-component TypeScript, no tree-shaking of your own
  components** — all three are build-time features, and there is no build.
- **No server-rendered HTML.** A crawler that does not run JavaScript sees the shell. See
  [SEO and page metadata](#seo-and-page-metadata) for what the package does put in the
  document and where the line is.

That trade is a good one for internal tools, admin panels, dashboards, prototypes, field
apps, and anything that has to be installable and work offline without a frontend
pipeline. It is the wrong one for a public marketing site whose ranking depends on
crawlers that do not execute JavaScript, and the wrong one if your team already runs a
frontend toolchain happily and wants what a build step buys.

If you have CI and the first two costs are the ones that bite — a policy that forbids
`unsafe-eval`, or a first paint over a slow connection — you can buy them back without
giving up the model. [`pwax:compile`](#precompiling-templates) compiles the templates
ahead of time and is entirely opt-in; the default path stays exactly as described above.

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

#### Flags

| Flag | Purpose |
| --- | --- |
| `--force` | Overwrite anything that already exists in the publish targets |
| `--views` | Also publish the Blade views (the SPA shell, the loader and error templates) |
| `--ai` | Also publish the Pwax skill for AI assistants |
| `--no-assets` | Skip the framework copy; the application serves Vue itself |

#### Publish tags

`pwax:install` is a thin wrapper around `vendor:publish`, and the same tags are
available at any time:

| Tag | Publishes |
| --- | --- |
| `pwax-config` | `config/pwax.php` |
| `pwax-assets` | Vue, Vue Router, Pinia into `public/vendor/pwax` |
| `pwax-views` | The shell, loader, error and offline Blade views |
| `pwax-service-worker` | The offline document the worker serves |
| `pwax-ai` | The Pwax skill into `.ai/skills/pwax/SKILL.md` |
| `pwax-push` | The annotated push-endpoint view `pwax:push-endpoint` emits |

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
absolute URL. An unknown route name **throws** when `APP_DEBUG` is on rather than
silently sending the link to your home page. With debug off, `pwaxRoute()` logs and
falls back to the route named in `pwax.home`,
which itself falls back to `/` if it is missing — so a typo never breaks a build,
it just sends everyone home until you notice in the log.

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
| `419` expired CSRF token | one full page reload to pick up a fresh token |
| `404`, `403`, `401`, `5xx` | renders the error template |

The first two are translated by Pwax's middleware. The next two cannot be — `auth` and
`VerifyCsrfToken` *throw*, so their redirects are produced by the exception handler
outside the middleware pipeline — so the client handles them instead, by treating a
followed redirect that returns HTML as an instruction to reload.

**One** reload for a `419`, per tab. The reload only helps if it returns a different
document, and under `navigation_strategy => 'cache-first'` it does not: the worker answers
that navigation from disk, so the same expired token comes back and the page would reload
forever. A second `419` renders the error template instead.

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

Three screens share one design — the page that would not load, the runtime that would not
start, and the service worker's offline document — because to a visitor they are the same
event, and three different-looking apologies read as three different bugs. Each is
centred, legible in light and dark, and offers the way out that applies.

**They take your colours, not their own.** The text inherits whatever your layout sets,
and the quieter parts are the same colour at lower opacity. That is deliberate: these
screens paint inside your page, on your background, so a rule that switched on
`prefers-color-scheme` would put near-white text on the light page of an app that has no
dark mode, the moment a visitor's system had one. They cannot know your background, so
they do not guess at it.

Override any of it in your own stylesheet, which loads after the shell's:

```css
:root {
    --pwax-screen-accent: #0c83ff;      /* buttons and focus rings; defaults to your spinner colour */
    --pwax-screen-accent-fg: #fff;
    --pwax-screen-fg: #111;             /* only if inheriting is not what you want */
    --pwax-screen-muted: #666;
    --pwax-screen-muted-opacity: 1;     /* set alongside --pwax-screen-muted */
    --pwax-screen-line: #e5e5e5;
    --pwax-screen-bg: #fafafa;
}
```

The markup hooks are `.pwax-screen`, `.pwax-screen__code`, `.pwax-screen__title`,
`.pwax-screen__message`, `.pwax-screen__actions` and `.pwax-button` (plus
`.pwax-button--quiet`). The worker's offline document carries its own copy of the styles,
because it answers a navigation to a page that never loaded — there is no shell stylesheet
to inherit. Publish it with `--tag=pwax-service-worker` to change it.

## Navigating

A navigation does not unmount the page you are on. The current page stays rendered while
the next one is fetched, compiled and has its styles applied; only then do the two swap,
with a fade. Nothing collapses to a spinner in between, and a navigation that fails leaves
you where you were.

The one thing that moves while you wait is a progress bar across the top of the window.
It waits 250 ms before appearing — most navigations finish well inside that, and a bar
that flashes on and off for each of them reads as jitter — then eases towards a ceiling it
never reaches, because the payload has no length the browser can know in advance. Claiming
to be finished and then waiting is what makes a progress bar feel like a lie.

There are two waits and they get different answers, because they are different waits:

```
first load    document arrives ─► spinner ─► app mounts ─► page appears
navigation    bar starts       ─► payload ─► bar completes ─► page fades in
```

A document arriving is the browser's own wait — the address bar moves, the tab spins — and
the shell's centred spinner covers the gap between the HTML landing and the runtime
mounting. A navigation has none of that, which is what the bar is for. It is not rendered
by the shell: the runtime creates it on the first navigation slow enough to need one, so an
application whose navigations are all fast never puts it in the document.

`customization.init_spinner => false` turns the spinner off, for an application that
renders its own skeleton into the mount element instead.

```php
'progress' => [
    'enabled' => true,
    'color'   => null,   // defaults to customization.init_spinner_color
    'height'  => 3,
    'delay'   => 250,
    'trickle' => true,
],

'transition' => [
    'duration' => 150,           // cross-fade length in milliseconds
],
```

The page swap is wrapped in the browser's [View Transitions API](https://developer.mozilla.org/en-US/docs/Web/API/View_Transitions_API):
the outgoing page is snapshotted, the new page is committed in the same frame, and the
browser cross-fades between them. The bundled transition fades with opacity alone —
anything that changes an element's size or position is a second kind of movement to
follow. `transition.duration` is the cross-fade length; `duration: 0` is an instant swap
and means the new page is in the DOM before the screen has a chance to draw the empty
router-view in between. Browsers without the API fall back to a synchronous swap, which
is the previous behaviour preserved. `prefers-reduced-motion` is honoured by the browser
itself: the transition runs but the cross-fade is replaced with a synchronous swap.

Wrap your own slow work in the same indicator:

```js
window.pwax.progress.start();
await fetch('/api/report');
window.pwax.progress.done();
```

The loader view is now only for the case with nothing to keep — the first paint of an
application whose landing page was not inlined. It ships silent, speaking only to screen
readers, which have no progress bar to look at. Point `pwax.blade.loader` at a skeleton if
you would rather show one.

> Use `v-text`, not `v-html`. Part of `error` derives from the HTTP response, and
> rendering that as HTML would make reflected content executable.

### Going back

A router turns the back button into an ordinary navigation: the URL changes, the page is
fetched again, and you wait for something you were looking at a moment ago. A
server-rendered site does not do this — the browser keeps its own
[back/forward cache](https://web.dev/articles/bfcache) and restores the previous document
without a request — so moving an application to a router is what *introduces* that wait,
on the one navigation where a visitor is most certain of what they are about to see.

Pwax puts it back. Every page that renders is kept, and a navigation the browser started —
back, forward, `router.go()` — is answered from memory with no request at all:

```
link click    bar starts ─► payload ─► bar completes ─► page fades in
back button   page fades in
```

Only those. Clicking a link to a page you have seen before still fetches. The two are
different questions: going back asks for *the page you were on*, while clicking a link asks
for *the page as it is now*. Turbo draws the same line and calls them restoration visits and
application visits; Inertia arrives at it by keeping page props in `history.state`.

So going back shows the page as it was. Comment on a post, press back, and the list you
return to is the list you left — the same bargain the browser's own back/forward cache
makes for a server-rendered site. When your application has just made one of those pages
wrong, say so:

```js
await fetch('/posts/1/comments', { method: 'POST', body });

window.pwax.restore.forget('/posts/1');   // next visit back to it fetches
window.pwax.restore.clear();              // drop everything, e.g. on sign-out
```

A page that should never be restored says so once, in its own script, next to
`middleware`:

```vue
<script>
export default {
    // A one-time token, a checkout step, a confirmation only correct when it was served.
    restore: false,
};
</script>
```

Such a page is not held at all: its payload is never stored, and its component instance is
destroyed when you navigate away rather than parked in memory. Nothing the visitor typed
into it outlives the visit.

### The page comes back as you left it

Removing the round trip is only half of it. A page that is fetched again is also *mounted*
again, so a half-filled form comes back empty however fast the navigation was. Retained
pages keep their component instance alive in a Vue
[`<KeepAlive>`](https://vuejs.org/guide/built-ins/keep-alive.html), so what comes back is
the page you left — the text you had typed, the list scrolled where you scrolled it, the
panel you had open.

That changes one thing about lifecycle, and it is the thing to know:

```js
export default {
    mounted()   { this.load(); },   // runs once, and NOT again on the way back
    activated() { this.load(); },   // runs on first render and on every return
};
```

A page that must be current every time it is shown does that work in `activated()`.
`deactivated()` is its pair, for stopping a timer or a subscription while the page is off
screen. Both fire only for retained pages; on a page that is not retained, `mounted()`
still runs on every visit as before.

```php
'restore' => [
    'enabled' => true,
    'entries' => 12,     // pages kept; the least recently used is dropped
    'state'   => true,   // keep the component instance too, not just the payload
],
```

`state => false` keeps the round-trip saving and drops the instance retention — the
setting for an application whose pages assume `mounted()` runs on every visit. `entries`
caps both stores.

It is a cap worth thinking about, because a retained page is a live component instance and
its DOM, not just a payload: twelve of them is twelve rendered pages held in memory. Lower
it for an application with heavy pages; raise it for one with light pages and deep
navigation.

Pages are held **in memory only** and never written to disk, so a reload, a new tab or a
closed browser leaves nothing behind — a payload can carry a signed-in visitor's data, and
that is the same rule prefetching follows. `sessionStorage` would survive a reload, which
is exactly why it is not used. Entries do not expire: a prefetch is a guess about where
somebody is going and is stale within seconds, but "the page I was just looking at" does
not become wrong because a minute passed. The cap is what bounds it.

Two kinds of scroll, restored by two different mechanisms. The **window's** position comes
from the router's own saved position, as it always has — though it lands more accurately on
a retained page, whose content is back at full height before the browser is asked to scroll
within it. Scrolling **inside** the page — a list pane, a chat log, anything with
`overflow: auto` — is restored by Pwax, because `<KeepAlive>` does not do it: deactivating
detaches the nodes, and a scrollable element that leaves the document has its `scrollTop`
reset to zero by the browser. Pwax reads those offsets just before the swap and puts them
back as part of it.

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

> The directive is `@pwaxImport`, and it can never be `@import`. Blade matches a directive
> even with no arguments, so one named `import` would also match the CSS at-rule
> `@import url(...)` inside every `<style>` block in the application and replace it with
> JavaScript. You can rename the directive with `pwax.components.directive`; the name
> `import` is rejected at boot.

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
elements with the matching attribute, so a rule can only ever reach the component it was
written in. The scope id is derived from the view's contents, so it is stable between
requests and changes when the component does.

Two escape hatches, keeping the names Vue authors already type:

```css
.wrapper :deep(.child-component-class) { color: red; }  /* reach into a child */
:global(.body-modifier) { overflow: hidden; }           /* opt out entirely */
```

`@keyframes`, `@font-face` and `@import` are left untouched. Turn the whole feature off
with `pwax.components.scoped_styles => false`.

## Plugins, directives and middleware

Register Vue plugins, directives and route middleware in `config/pwax.php`. Each value
is either a component reference or a dotted path to a global:

```php
'vue' => [
    'plugins' => [
        // A Pwax component whose default export is a Vue plugin.
        'toast' => "@pwaxImport('plugins.toast')",

        // A UMD library already loaded by a <script> tag.
        'i18n'  => 'VueI18n.createI18n',
    ],

    'directives' => [
        'focus' => "@pwaxImport('directives.focus')",
    ],

    'middleware' => [
        'confirmed' => "@pwaxImport('middleware.confirmed')",
    ],
],
```

> These values are **never evaluated as code**. A component reference is imported; a
> dotted path is looked up on `window`. Interpolating them into the page inside `{!! !!}`
> would make a stray quote break the whole application, and any path by which config can
> be influenced a remote code execution.

### Why the `vue` group

The package has two configs that share the word "middleware": `pwax.middleware` (the
Laravel middleware groups a page route runs through, set by `HandlePwaxRequests`)
and the client's route middleware. The two were always going to be confused.
Putting the three Vue extensions under `pwax.vue.*` lets the client-side one
keep its natural name — `vue.middleware` — and groups all client-side Vue
configuration in one place.

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

> **`pwax.import`, `@pwaxImport`, and `pwax.importModule` are the same thing.** The
> Blade directive `@pwaxImport('foo.bar')` is the only way to write a component
> reference in a config value — you cannot reach for an `import('foo')` expression
> instead. At runtime it resolves to the client-side `pwax.component(url)` (sync)
> or `pwax.load(url)` (async with options). The public runtime exposes this as
> `pwax.import(url)`; `importModule` is the internal function name in the bundle,
> which the service worker also calls. One operation, three spellings for three
> places you might meet it.

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

### Installed-app capabilities

An installed app can do things a tab cannot. Each of these is exposed and none of them
happens on its own — when to prompt, when to badge, when to ask for storage are all
decisions the application makes.

```js
// Your own install button, shown when the browser says it is possible.
document.addEventListener('pwax:installable', () => showMyInstallButton());
await window.pwax.install.prompt();          // 'accepted' | 'dismissed' | 'unavailable'
window.pwax.install.standalone;              // running as an installed app?

await window.pwax.badge.set(3);              // the count on the app icon
await window.pwax.storage.persist();         // ask to be exempt from eviction

await window.pwax.push.subscribe();          // Web Push — see Push notifications
await window.pwax.sync.enqueue('/notes', { method: 'POST', body: note });

await window.pwax.share({ title, url });     // the platform share sheet
```

`storage.persist()` is worth a thought in this package specifically. Pwax precaches the
whole application, and a browser under storage pressure evicts whole origins — so the
failure mode is not a slow page, it is "it stopped working offline" with nothing in the app
to explain it. Persistence is never requested for you: on some platforms it is a real
prompt, and spending it is the application's decision.

`sync.enqueue()` queues nothing automatically. Intercepting failed writes would replay a
payment as readily as a draft, and only the application knows which of its requests repeat
safely.

It takes URLs on your own origin only, and returns `false` for anything else. What is
stored alongside the request is the headers the runtime sends, this session's CSRF token
among them, and the worker replays them from a context the page cannot see — so a
cross-origin URL there would hand that token to somebody else. Call a third-party API from
the page, where you control the headers.

The token itself is refreshed at replay time from whatever page is open, not sent as it was
when the write was queued. That is what makes a write that sat offline past
`session.lifetime` succeed on its retry rather than meeting the same 419 for ever.

### Being opened by the operating system

Three manifest members hand your application an entry point from outside the browser, and
all three arrive as one thing — a launch:

```php
'manifest' => [
    // Opened when the user picks your app from the share sheet.
    'share_target' => [
        'action' => '/share',
        'method' => 'POST',
        'enctype' => 'multipart/form-data',
        'params' => ['title' => 'title', 'text' => 'text', 'url' => 'url'],
    ],

    // Opened when the user opens a file your app claims.
    'file_handlers' => [
        ['action' => '/import', 'accept' => ['text/csv' => ['.csv']]],
    ],

    // Opened when something follows a web+invoice: link.
    'protocol_handlers' => [
        ['protocol' => 'web+invoice', 'url' => '/invoices/open?ref=%s'],
    ],
],
```

**The routes are yours to write.** The manifest tells the operating system to send the app
to those URLs; nothing in the package answers them. `php artisan pwax:doctor` resolves every
declared target against your real route table, with the method the browser will use, and
fails if one matches no route, refuses that method, or sits outside `scope` — because
otherwise the first you hear of it is a user who shared a photo to your app and got a 404.

A file handler and a protocol handler deliver through the launch queue, which the runtime
consumes for you:

```js
window.pwax.launch.consume(({ files, targetURL }) => {
    for (const handle of files) {
        readAndImport(handle);          // FileSystemFileHandle
    }
});
```

A launch carrying a URL and no files — a protocol handler, a shared link — is routed to for
you. Under `launch_handler: focus-existing` the browser only brings the window forward, so
without that, following a `web+invoice:` link would open the app on whatever page it was
left on. Return `false` from the consumer, or call `preventDefault()` on the cancelable
`pwax:launch` event, to route it yourself.

A **POST** share target is not a launch — it is a real form POST from outside your
application, so its route needs CSRF exemption and its own validation. The service worker
leaves every non-GET request to the network, which means a share received offline fails
rather than being queued. That is deliberate: replaying an arbitrary POST is not something
a package can decide on your behalf. `pwax.sync.enqueue()` is there if your application
decides it.

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
        "/__pwax__/pwax.js?v=8f2a41c0d5e7",
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
      "strategy": "network-first", "credentials": "omit", "urls": ["/", "/about"] }
  ],
  "dataGroups": [],
  "hashTable": {
    "/__pwax__/c/Y29tcG9uZW50cy5tb2RhbA3f9a1c0d.js": "a41c9b02f7de5163",
    "/__pwax__/pwax.js?v=8f2a41c0d5e7": "8f2a41c0d5e7b193"
  },
  "critical": ["/__pwax__/pwax.js?v=8f2a41c0d5e7", "/__pwax__/shell"],
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
files, source maps and anything hidden are never matched whatever the patterns say
(including files inside a hidden directory, so a stray `.git` under `public/` cannot be
precached). `max_files` and `max_bytes` cap a runaway glob and report what they truncated.

Requests to an API get their own groups, which are about responses rather than files:

```php
'service_worker' => [
    'data_groups' => [
        [
            'name' => 'posts',
            'urls' => ['/api/posts', '/api/posts/**'],
            'strategy' => 'network-first',   // or 'cache-first' to serve the cache first
            'max_entries' => 50,
            'max_age' => 3600,           // seconds, for 'cache-first'
            'timeout' => 3000,           // ms before 'network-first' falls back to the cache
        ],
    ],
],
```

These are responses, not files, and they can hold one person's data. They are cached
normally, and caches are shared across visitors — anyone with the device sees the same
API responses as the last user. Do not add an authenticated endpoint here without deciding
that is acceptable, or guarding the response with `X-Pwax-Cache: none` server-side.

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
end up with a mix of two builds. `version` exists only for when you want to discard
everything deliberately:

```php
'service_worker' => ['version' => 'v2'],
```

The manifest hash is embedded in `sw.js` itself. That is what makes a deploy
reach an existing install: a browser only treats a worker as new if its bytes differ, so a
worker whose source never changed would leave clients on the build they first installed.

### The offline shell

**Caches are shared across visitors, so a page that stores nothing user-specific ends up
in the same place for whoever comes next.** The Cache API ignores HTTP cache directives,
so a worker that kept what it fetched would persist to disk whatever document the server
returned — which is exactly the document the server marked `Cache-Control: no-store,
private` for, a signed-in user's rendered page. A page that must not reach disk at all
opts out with `->offline(false)`; the server says so on the response, and the worker
honours it.

The documents that install with the build are anonymous by construction: they are fetched
at install time with cookies passed through, but a route behind `auth` answers that
request with a login screen rather than a page, and the worker detects that and refuses
to store it. Pages whose guest and signed-in renderings are the same — the typical
application page — end up cached; pages whose aren't don't, and fall back to the shell
when offline.

The shell is `/__pwax__/shell`: the same SPA shell rendered with no session, no CSRF token
and no page component, identical for every visitor. When a navigation cannot reach the
network and there is no document for that route, the worker serves the shell, the runtime
boots, and client-side routing carries on as normal.

`service_worker.pages.runtime => false` turns off both halves of runtime page caching, the
payload and the document alike. The precached shell and documents are unaffected — they
are what the build installed, not what a visitor left behind.

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
go. The cache is shared across visitors, so the document the server returned for a URL
is what the next visitor gets offline — which is fine for a page that renders the same
for everyone, and a deliberate decision otherwise.

```php
'service_worker' => ['pages' => ['runtime' => true]],
```

Mark anything that must never reach disk at all:

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

> **A new build does not take over on its own.** It installs, then waits until every tab
> of the application is closed. That is the point — taking over immediately would reload
> those tabs and discard whatever was being typed — but it does mean an application that
> ignores the event above can look as though a deploy did nothing: the old worker is still
> answering every request, out of the old caches.
>
> The runtime logs one line to the console when a build is waiting. `pwax.sw.applyUpdate()`
> lets it through immediately and reloads, which is what to call from a prompt of your own,
> and what to paste into the console when you are wondering why a fix has not appeared.

### Going offline and back

```js
document.addEventListener('pwax:offline', () => banner.hidden = false);
document.addEventListener('pwax:online', () => banner.hidden = true);
```

These fire whether or not the service worker is on. They are `window`'s own `online` and
`offline` events, relayed onto `document` alongside every other `pwax:` event so there is
one place to listen — and an application with no worker is exactly the one whose failed
request has nothing else to explain it.

`navigator.onLine` is the only signal a browser offers, and it is honest about very little:
it means "this device has a network interface", not "your server is reachable". Treat
`pwax:offline` as a reason to show a banner, never as a reason to skip a request.

### Push notifications

Five steps, and the first one is the one every other guide assumes you already have.

**0. Scaffold the endpoint.** `pwax:push-endpoint` writes the controller — the
`subscribe` and `unsubscribe` actions, the validation, the upsert by endpoint. It
prints the routes to add to `routes/web.php` and a starter migration. Reading the
file next to the shape below is the difference between "I think I understand" and
"I have something concrete to read."

```bash
php artisan pwax:push-endpoint
```

**1. Generate a VAPID key pair.** No Node needed — this uses `ext-openssl`, which Laravel
already requires.

```bash
php artisan pwax:vapid
```

```dotenv
VAPID_PUBLIC_KEY=BE1cBl7BxUtZ…
VAPID_PRIVATE_KEY=BBD3vKF0zkf…
```

The public key goes to the browser at subscribe time, so it is safe in a page. The private
one never leaves the server. Rotating them invalidates every existing subscription, so
generate once and keep them.

**2. Point the config at the key and at an endpoint of yours.**

```php
'push' => [
    'public_key'  => env('VAPID_PUBLIC_KEY'),
    'private_key' => env('VAPID_PRIVATE_KEY'),
    'endpoint'    => '/push/subscriptions',
    'title'       => config('app.name'),   // used when a message sends no title
    'icon'        => '/images/icons/icon-192.png',
    'badge'       => '/images/icons/badge-72.png',
],
```

**3. Write the endpoint.** Pwax posts the browser's subscription there and deletes it on
unsubscribe. It never invents the shape — the body is `PushSubscription.toJSON()` verbatim:

```json
{
  "endpoint": "https://fcm.googleapis.com/fcm/send/…",
  "expirationTime": null,
  "keys": { "p256dh": "BN…", "auth": "k9…" }
}
```

```php
Route::post('/push/subscriptions', function (Request $request) {
    $data = $request->validate([
        'endpoint'   => ['required', 'url'],
        'keys.p256dh' => ['required', 'string'],
        'keys.auth'   => ['required', 'string'],
    ]);

    $request->user()->pushSubscriptions()->updateOrCreate(
        ['endpoint' => $data['endpoint']],
        ['p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth']],
    );

    return response()->noContent();
})->middleware('auth');

Route::delete('/push/subscriptions', function (Request $request) {
    $request->user()->pushSubscriptions()
        ->where('endpoint', $request->input('endpoint'))
        ->delete();

    return response()->noContent();
})->middleware('auth');
```

Both requests carry the session cookie and Pwax's CSRF token, so `auth` and `web` work
normally.

**4. Subscribe, from a user gesture.**

```js
if (window.pwax.push.supported && window.pwax.push.permission === 'default') {
    await window.pwax.push.subscribe();       // asks permission, then posts to your endpoint
}
```

`subscribe()` resolves to the subscription or `null` if permission was refused.
`unsubscribe()` tells your endpoint first and drops the local subscription second, so a
failure leaves you with a subscription that still exists on both sides rather than one the
server will keep pushing to forever.

#### Sending

Deliberately not part of this package. Storing subscriptions and signing VAPID requests is
what [`laravel-notification-channels/webpush`](https://github.com/laravel-notification-channels/webpush)
already does well, and a second implementation inside a PWA package would be a worse one.
Install it, point it at the same keys, and Pwax's worker will render what it sends.

#### What the worker does with a message

The payload shape is the Notification API's own, so anything that library sends works
untranslated:

```json
{
  "title": "Invoice paid",
  "body": "#1043 — £240.00",
  "icon": "/images/icons/icon-192.png",
  "tag": "invoice-1043",
  "data": { "url": "/invoices/1043" }
}
```

`title` falls back to `push.title`, and `icon`/`badge` to their config values. A push that
arrives with no payload at all still shows something: every browser requires
`userVisibleOnly`, and showing nothing is how an origin loses its push permission.

A click closes the notification and goes to `data.url`. If a window is already open on that
URL it is focused; if a window is open elsewhere it is navigated; only if neither is true is
a new one opened. Opening a second tab on a page the user already has open is the thing
they notice and dislike.

### Extending the service worker

The worker is a built bundle, not a Blade view, so it is not edited by publishing it.
Two ways in, and the first is almost always the right one.

**Append your own handlers.** Each entry is a view name or an absolute path. The contents
are appended after the worker and share its scope, so `CONFIG`, `PREFIX` and the cache
helpers are all in reach:

```php
'service_worker' => [
    'extend' => ['js.analytics-sync'],
],
```

```blade
{{-- resources/views/js/analytics-sync.blade.php --}}
self.addEventListener('periodicsync', (event) => {
    if (event.tag === 'refresh-feed') {
        event.waitUntil(fetch('/feed/refresh'));
    }
});
```

It is a Blade view rendered with no variables, so reach for `config()` and `@json()` —
the value is resolved at request time rather than baked into a published file. Everything
the worker itself was given is on `self.__PWAX_SW__`. The worker is served `no-cache`, so a
change reaches clients on their next update check.

This is the supported seam and the reason to prefer it: you get every future fix to the
1,600 lines you did not fork.

**Replace it outright.** Still supported, and always will be:

```php
'service_worker' => [
    'blade' => 'js.my-worker',
],
```

Your view receives `$manifest` — the whole asset manifest, the same array `/sw.json`
serves. Nothing else in the package is involved after that, including the fixes.

Either way `php artisan pwax:precache` shows what the manifest will tell your worker to
install, and `/sw.js` in a browser shows exactly what is being served.

#### Scope

A service worker can only control paths at or below its own URL. `pwax:doctor` checks
the obvious misconfigurations — a worker served at `/static/sw.js` cannot control
`/`, and the browser will quietly leave the worker uninstalled — but the design
principle is the same: the worker's URL sets the upper bound of what it can claim,
and `service_worker.scope` cannot extend that.

The default (`/`) covers everything. Set it to `/admin` only when the worker is
itself served at `/admin/sw.js`; otherwise the browser truncates the scope to the
worker's path and the symptoms are the same as if `scope` were wrong. Run
`php artisan pwax:routes` to see which Pwax routes the worker can claim.

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
'assets' => ['source' => 'cdn'],
```

Pinia can be dropped if you do not use a store:

```php
'assets' => ['pinia' => false],
```

Pwax serves the **full** Vue build (`vue.global.prod.js`) by default, because it is the
one that can compile a template string. The runtime-only build is also published, and is
served instead when you opt into [precompiling](#precompiling-templates).

To update Vue, see [`resources/vendor/README.md`](resources/vendor/README.md).

## Performance

**First paint costs one round trip.** The shell arrives with the current component fully
embedded — template, styles and script — so there is no follow-up request for the page at
all. Only the framework and the runtime are fetched, in parallel, and both are static and
cacheable — rather than a chain of requests the browser can only discover one at a time.

Anything the first render still has to import is named in the head with
`<link rel="modulepreload">`: the components this page imports with `@pwaxImport`, the
configured `plugins` and `directives` the runtime awaits before mounting, and any external
script or stylesheet the component declares. All of those are known to the server while it
is writing the document, and none are discoverable by the browser until Vue has loaded and
rendered — a serial round trip that the hints remove.

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
itself, the cache can never go stale. What is memoised is the *parse* — splitting the
blocks, scoping the styles, stamping the template, minifying — never the Blade render,
which has to run every request because rendering is where a page's data enters.

For the same reason, only renders that take no data are stored. A page rendered with
controller data produces different output for every visitor, so an entry would be written
once and read never; `->cacheable()` opts a page back in, since a page whose payload may
sit in a shared HTTP cache has already been declared visitor-independent. `cache.ttl`
bounds how long an entry lives — `null` keeps it forever.

**Minification** runs in production only, and its results are cached by content. If your
web server already applies gzip or brotli, turn it off — you recover most of the bytes
with no CPU cost and no risk of a regex-based minifier mangling valid JavaScript:

```php
'minify' => ['enabled' => false],
```

### Precompiling templates

Optional, off by default, and the only part of Pwax that needs Node. It exists for teams
who already run CI and would rather spend a build minute than the bytes and the CSP
allowance.

```bash
npm install --save-dev @vue/compiler-dom@3.5.41   # the version Pwax vendors
php artisan pwax:compile
```

```php
'assets' => ['vue_build' => 'runtime'],
```

Every template is compiled to a Vue render function server-side and stored in
`storage/app/pwax/render-functions.php` — a plain PHP array literal, so OPcache holds the
parsed form and a lookup costs a hash-table hit. The functions are emitted **into the
component module**, as source rather than as a string, so the module loader evaluates
them and nothing calls the `Function` constructor.

Three things follow:

| | Default | `vue_build => 'runtime'` |
| --- | --- | --- |
| Vue | 60.7 kB gzipped | 40.6 kB gzipped |
| `script-src` | `'self' blob: 'unsafe-eval'` | `'self' blob:` |
| Per navigation | Template compiled in the browser | Render function already compiled |

**Run it on every deploy that changes a component.** This is a real deploy step, not a
nice-to-have — put it in the same script as `php artisan config:cache`:

```bash
php artisan pwax:compile
```

Never having compiled is not an outage: the store is empty, Pwax serves the full build,
and the application behaves exactly as if you had not opted in. Compiling and then
changing a component *is* one — the store is non-empty, so the runtime-only build is
served, and the changed component has no render function under its new key. Both states
are reported by `php artisan pwax:doctor`, the second as an error naming the components
affected.

**One constraint comes with it.** A template has to be the same for every visitor, because
it is compiled once, at deploy time, with no request in flight. Keep controller data in
`<script>` and out of `<template>`:

```blade
<template>
    <h1>@{{ user.name }}</h1>          {{-- fine: Vue renders this in the browser --}}
    <h1>{{ $user->name }}</h1>         {{-- not precompilable: differs per visitor --}}
</template>

<script>
    export default {
        data: () => ({ user: @json($user) }),
    };
</script>
```

That is the idiomatic split anyway, and `pwax:compile` names any view that breaks it —
such a view raises on the undefined variable when rendered with no data, and the command
reports it and exits non-zero rather than writing a store that looks complete.

`php artisan pwax:compile --clear` removes the store and goes back to compiling in the
browser. `assets.node` sets the Node binary if it is not on `PATH`;
`assets.render_functions` moves the store, which is worth doing if you build the artifact
in CI and ship it with the release.

## SEO and page metadata

Everything a page says about itself — its title, its description, its social card, its
structured data — is declared on the response and travels twice: as tags in the document a
full page load receives, and inside the JSON payload the runtime applies on a client-side
navigation.

Both halves matter. A browser replaces the whole head on a real navigation; a router does
not. A title that moves with the route and a canonical URL that stays behind is worse than
setting neither, because the wrong answer outlives the missing one — and nothing on screen
shows it. It surfaces in a link preview or a crawler, weeks later.

```php
Route::get('/posts/{post}', fn (Post $post) => pwaxRender('pages.post', compact('post'))
    ->title($post->title)
    ->description($post->excerpt)
    ->canonical(route('posts.show', $post))
    ->image($post->cover_url)
    ->robots($post->draft ? 'noindex' : 'index, follow')
    ->alternate('fr', route('posts.show', [$post, 'locale' => 'fr']))
    ->jsonLd([
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $post->title,
        'datePublished' => $post->published_at->toIso8601String(),
    ]));
```

| Method | Emits |
| --- | --- |
| `title($text)` | `<title>`, `og:title`, `twitter:title` |
| `description($text)` | `<meta name="description">`, `og:description`, `twitter:description` |
| `canonical($url)` | `<link rel="canonical">`, `og:url` |
| `image($url)` | `og:image`, `twitter:image`, and the Twitter card that suits it |
| `robots($directives)` | `<meta name="robots">` |
| `alternate($hreflang, $url)` | `<link rel="alternate" hreflang="…">` |
| `jsonLd($schema)` | `<script type="application/ld+json">` |
| `meta($name, $content)` | `<meta name="…">` |
| `property($property, $content)` | `<meta property="…">` |

`meta()` and `property()` also take an array, so several tags can be set in one call:

```php
->meta(['author' => 'Ada Lovelace', 'rating' => 'general'])
```

### Defaults for every page

Each of these has an application-wide fallback in `config/pwax.php`, which is where a
value that is the same on every route belongs:

```php
'head' => [
    'title' => null,               // falls back to manifest.name
    'title_template' => ':title · Acme',
    'description' => null,         // falls back to manifest.description
    'image' => '/img/og.png',      // the sharing card for pages that name none
    'robots' => null,              // 'noindex, nofollow' on staging
    'locale' => null,              // og:locale; defaults to the app locale
    'alternates' => [],            // ['en' => '/', 'fr' => '/fr']
    'json_ld' => null,             // usually the site's own Organization
    'open_graph' => true,
    'open_graph_type' => 'website',
    'twitter_card' => null,        // follows the image
],
```

The title template is applied only to a page's own title: `':title · Acme'` against a
fallback of `'Acme'` would otherwise render `Acme · Acme`.

### What is derived, and what is not

Open Graph and Twitter tags are derived from the values above, and derivation never
overwrites — a page that sets `og:title` by hand keeps it, and nothing is invented from a
value that does not exist. Turn the whole of it off with `'open_graph' => false`.

Two derivations are worth knowing about:

- **`twitter:card` follows the image.** Left null it is `summary_large_image` when a page
  has an image and `summary` when it does not, because a large card with no image renders
  as a bare summary anyway and a small one beside a 1200×630 image throws the artwork away.
  Set it explicitly to pin one spelling for every page.
- **URLs in Open Graph tags are made absolute.** A scraper reading `og:image` does not
  necessarily have the document to resolve a relative path against, so `->image('/img/og.png')`
  is emitted against your `app.url`. Anything already carrying a scheme is left as written.

`robots` is deliberately not part of that: it is applied whether or not Open Graph
derivation is on, so an application that turns derivation off does not silently start
indexing a staging deployment.

### Structured data

`jsonLd()` is what a search engine reads to show a rich result — a recipe's rating, an
article's author and date, a product's price, the breadcrumb trail above a result. Call it
more than once for a page that makes several claims and each becomes its own block, which
is what Google's documentation asks for:

```php
->jsonLd(['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $post->title])
->jsonLd(['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $crumbs])
```

A page that calls `jsonLd()` **replaces** `head.json_ld` rather than adding to it. An
`Article` and an `Organization` are two claims about two different things, and emitting
both against one URL says the page is both. Put the site's own identity in
`@stack('pwax-head')` if you want it on every page alongside the page's own — that is a
document-level concern and outlives the navigation.

The block is written with the same escaping as the runtime's JSON islands, so a value from
your database cannot close it, and it carries your CSP nonce: a browser applies
`script-src` to a `<script>` element by its tag rather than by its `type`, so an un-nonced
`ld+json` block is refused under a strict policy.

### Translations

`alternate()` is how a search engine is told that two URLs are the same page in different
languages rather than duplicates competing with each other. The reciprocity rule means
every locale must name every other one, including itself:

```php
->alternate('en', route('posts.show', $post))
->alternate('fr', route('posts.show', [$post, 'locale' => 'fr']))
->alternate('x-default', route('posts.show', $post))
```

For a site whose translations differ only by a prefix, `head.alternates` sets the same
links for every page.

### What this does and does not do

These tags describe the document. They do not put the page's *content* in it: the markup
is compiled in the browser from a JSON island, so a crawler that does not run JavaScript
sees the shell.

That is enough for more than people expect. Link unfurling — Slack, iMessage, WhatsApp,
Discord, Open Graph everywhere — reads the tags and never the body, so a shared link
previews correctly. Googlebot and Bingbot both render JavaScript and index the result.

It is not enough for crawlers that do not: most social scrapers beyond the ones above, and
whatever an AI crawler or an aggregator happens to run. If ranking on those matters for a
page, that page's content belongs somewhere a crawler can read it without a browser —
a Blade-rendered route outside Pwax, a feed, or a `<noscript>` summary you write yourself.
This package renders in the browser, by design; it does not pretend otherwise.

### Anything else in the head

`@stack('pwax-head')` takes arbitrary content and is left alone on a navigation:

```blade
@push('pwax-head')
    <meta name="google-site-verification" content="…">
@endpush
```

Only tags the package emitted carry `data-pwax-head`, and only those are replaced when the
route changes.

There is a matching `@stack('pwax-foot')`, rendered after the vendor scripts at the end of
`<body>` — the place for a script that needs Vue to have evaluated. Those are the two
stacks the shell wires up; a push to any other name is silently dropped.

Push from a Blade view that renders as part of the page — one of the `blade.*` overrides,
or your own published shell. Blade discards its stacks when the outermost render finishes,
so a `View::startPush()` from a controller is gone before the shell asks for it.

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

By default Vue compiles templates in the browser with the `Function` constructor, so
`script-src 'unsafe-eval'` is required. That is inherent to compiling templates on the
client rather than at build time — but it is not inherent to the package:
[`pwax:compile`](#precompiling-templates) moves the compilation to deploy time and lets
you drop `'unsafe-eval'` entirely. If your policy forbids it, that is the configuration
to use.

Imported components are fetched from real same-origin URLs. A **page** component cannot
be — it is rendered with controller data, so it ships its script inline and the runtime
imports it from a `blob:` URL. That needs `blob:` in `script-src`, with or without
precompiling. (`data:` is never used: unlike `blob:`, a `data:` URL in `script-src` makes
any injected string executable.)

```
Content-Security-Policy:
    default-src 'self';
    script-src 'self' blob: 'unsafe-eval';   /* drop 'unsafe-eval' with pwax:compile */
    worker-src 'self';                       /* the service worker is registered same-origin */
    style-src 'self' 'nonce-{NONCE}';
    connect-src 'self';
    img-src 'self' data:;
    base-uri 'self';
    form-action 'self';
    frame-ancestors 'none';
    object-src 'none'
```

A `worker-src 'self'` is the line most policies forget to add. Without it, a strict
policy refuses to register the service worker at all and the application is offline
*as far as the worker is concerned* without anything in the dev console to point at
the policy.

Supply the nonce for Pwax's inline `<style>` and JSON blocks:

```php
'csp' => ['nonce' => fn () => request()->attributes->get('csp-nonce')],
```

### Security headers

Pwax applies its own set of response headers, so its hardening does not depend on the
application's own middleware and cannot be silently lost.

Every response it serves gets them: the page a visitor loads, the JSON payload a
client-side navigation fetches, the runtime bundle, the manifests, the offline shell, each
component module. Documents get the full set; assets get the two that mean anything on a
`fetch()` response.

That matters more than it sounds. The service worker precaches the offline shell and
answers navigations with it, so a document served from the network and the same document
served from the cache have to agree — otherwise the application's security posture
changes the moment the network does.

| Header | Asset | Document | Configurable |
| --- | --- | --- | --- |
| `X-Content-Type-Options: nosniff` | yes | yes | no |
| `Referrer-Policy: no-referrer` | yes | yes | `pwax.security.referrer_policy` |
| `X-Frame-Options: SAMEORIGIN` | no | yes | `pwax.security.frame_options` |
| `Permissions-Policy` | no | yes | `pwax.security.permissions_policy` |
| `Cross-Origin-Opener-Policy: same-origin-allow-popups` | no | yes | `pwax.security.cross_origin_opener_policy` |
| `Cross-Origin-Embedder-Policy` | no | off | `pwax.security.cross_origin_embedder_policy` |

Every value is overridable; setting any to `null` (or `''`) drops the corresponding
header.

**`Permissions-Policy`** denies everything that reaches hardware, a sensor or another
origin's data, and allows the document its own use of what a progressive web app is
actually built on — Web Share, the screen wake lock, fullscreen, the clipboard, passkeys.
A blanket deny would switch off `window.pwax.share()`, `window.pwax.badge`, and
`publickey-credentials-get`, which is how a Laravel application signs someone in with a
passkey. Add a feature back with `feature=(self)`, or open it to any origin with
`feature=*`.

**`Cross-Origin-Opener-Policy: same-origin-allow-popups`** severs the `window.opener`
reference a cross-origin page would otherwise hold, while leaving popups this document
opens able to talk back — which is how an OAuth flow returns its result. `same-origin`
severs both.

**`Cross-Origin-Embedder-Policy` is off.** `require-corp` refuses every cross-origin
subresource that does not carry `Cross-Origin-Resource-Policy` — an avatar from a bucket, a
font from a CDN, an embedded video. Paired with `cross_origin_opener_policy =>
'same-origin'` it makes the document cross-origin isolated, which is what
`SharedArrayBuffer` and high-resolution timers need. Turn it on when you want those:

```php
'security' => [
    'cross_origin_opener_policy' => 'same-origin',
    'cross_origin_embedder_policy' => 'require-corp',
],
```

`pwax:doctor` then flags any `pwax.scripts` or `pwax.styles` entry pointing off-site
without a `crossorigin` attribute. The framework scripts always carry one when loaded from
the CDN, so they are fine either way.

### What the service worker will and will not store

The Cache Storage API ignores HTTP cache directives, so a worker that stores whatever it
fetches writes signed-in users' rendered pages to disk — where the next person to use that
device is served them offline. Assets carrying `Cache-Control: no-store` are refused
outright.

A page has two representations and both are stored, under different rules. The runtime
asks for the payload and gets JSON; a reload, a bookmark or a link from outside the app is
a navigation and gets HTML with the component already inlined. Caches are shared across
visitors: the payload or document the server returned for a URL is what the next visitor
gets on the same device.

For most pages this is fine — a page that renders the same for everyone ends up cached
the same way, and a small staleness between deploys is unnoticeable. For pages whose
guest and signed-in renderings differ, that sharing is a deliberate decision. `->offline(false)`
refuses outright for content that must never reach disk at all — a one-time code, a
recovery key, a record on a shared terminal.

### When a stored page is used

By default a page goes to the network first and falls back to what is stored — not only
when the network is gone, but when the origin cannot be reached through. A proxy that
cannot get an answer out of the application, or an application mid-deploy, *replies*, so
waiting for `fetch` to throw would show an error while a copy of the page sat on the device
unread.

Three statuses, and not one more:

| | |
| --- | --- |
| `502` | a proxy could not get an answer out of the application at all |
| `503` | the application or the proxy is refusing for now — `php artisan down` answers this |
| `504` | a proxy waited and gave up |

From the device none of those is distinguishable from a bad connection, which is the case
the fallback exists for.

**A `500` is shown, like a `404`.** It is not the origin being unreachable — it is the
application running and throwing, in a real route, and answering it from cache hides the
bug twice over: the visitor sees a page that works and reports nothing, and whoever
deployed it has no idea that route is broken until somebody eventually notices. A stale
page is worse than an error page when the error is the thing you needed to know.

The same rule holds for a full page load, not only for a navigation inside the app: a
reload while the origin is unreachable is answered from the stored document for that route
— the one visited earlier, or the one installed with the build. It holds for data groups
and for the runtime cache too.

Whenever a stored copy does stand in for a failing origin, the worker says so on the
console with the status and the URL. Silence there is how an outage goes unnoticed: the
application looks healthy because everybody is being served yesterday's copy of it.

To prefer the stored copy even when the network is fine, make pages cache-first:

```php
'service_worker' => ['pages' => ['strategy' => 'cache-first']],
```

That is faster and can be a version behind. `'network-first'` — the default — goes to the
network but gives up on it after `pages.timeout` (2000 ms) and uses the copy.

Two things this does not cover, and both are worth deciding about rather than discovering:

- **A page that varies by visitor is shared.** Caches are not partitioned, so the document
  one visitor sees offline is the document the server last returned for that URL. For a
  page whose guest and signed-in renderings differ — `/dashboard`, `/account` — this is
  wrong by design, and `->offline(false)` is the answer.
- **A component can still vary by user** — an admin-only branch, a localised string. The
  precache is shared too. For content that must not reach a stranger's device, the
  component payload carries `Cache-Control: no-store, private`, and the worker respects it.

`pwax.sw.clearCaches()` is the heavier hammer — it discards everything, including the
framework, so the next visitor downloads the application again. Reach for it when a
component renders differently for an administrator and you want no trace left.

One thing to avoid entirely: **never sign a view name that came from a request.**
`pwaxRender($request->input('view'))` would mint a valid signed identifier for whatever it
was handed, and signing is what the component endpoints trust. View names belong in your
code.

### Your responsibilities

- **Never put user input in `pwax.vue.*`.** They describe what the page loads.
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
| `routes.static_middleware` | `['throttle:300,1']` | Middleware for runtime/manifest/worker — outside `web`, so outside its rate limiting too |
| `components.directive` | `'pwaxImport'` | Blade directive name (`import` is rejected) |
| `components.allowed` | `[]` | Allowlist of servable view patterns |
| `components.scoped_styles` | `true` | Honour `<style scoped>` |
| `blade.*` | `null` | Override bundled partials |
| `assets.source` | `'local'` | `local` or `cdn` |
| `assets.vue_build` | `'full'` | `'runtime'` drops ~20 kB and `unsafe-eval`, once `pwax:compile` has run |
| `assets.render_functions`, `.node` | `null` | Where `pwax:compile` writes, and the Node binary it runs |
| `assets.local_path` | `'/vendor/pwax'` | Where published assets live |
| `assets.versions` | see config | Pinned Vue / Router / Pinia versions |
| `assets.pinia` | `true` | Load Pinia at all |
| `styles`, `scripts` | `[]` | Extra tags; string or attribute array |
| `scripts[].head` | `false` | Render this script in `<head>` rather than at the end of `<body>` |
| `vue.plugins`, `vue.directives`, `vue.middleware` | `[]` | Vue extensions |
| `minify.enabled` | production only | Minify component sources |
| `minify.store`, `minify.ttl` | `null` | Cache for minified output |
| `cache.asset_ttl` | `3600` | `max-age` for component assets |
| `cache.components` | `true` | Memoise compiled components |
| `cache.ttl` | `604800` | How long a compiled component is stored; `null` for forever |
| `cache.store` | `null` | Cache store for compiled components; `null` is the app default |
| `csp.nonce` | `null` | Nonce (or callable) for inline blocks |
| `security.referrer_policy` | `'no-referrer'` | On every response; `null` drops the header |
| `security.frame_options` | `'SAMEORIGIN'` | Documents only |
| `security.permissions_policy` | see config | Documents only; denies hardware and sensors, `(self)` for what a PWA uses |
| `security.cross_origin_opener_policy` | `'same-origin-allow-popups'` | Documents only; `'same-origin'` for cross-origin isolation |
| `security.cross_origin_embedder_policy` | `null` | Off. `'require-corp'` completes cross-origin isolation |
| `prefetch.mode`, `.delay` | `'hover'`, `65` | Fetch a page before it is asked for; `false` turns it off |
| `restore.enabled`, `.entries`, `.state` | `true`, `12`, `true` | Render back/forward from memory instead of refetching, keeping each page's component instance; `false` turns it off |
| `push.public_key`, `.private_key` | `null` | VAPID pair; `pwax:vapid` generates one |
| `push.endpoint` | `null` | Your route that stores a subscription |
| `push.title`, `.icon`, `.badge` | `null` | Fallbacks for a push payload that omits them |
| `customization.init_spinner` | `true` | The centred spinner covering the first load |
| `customization.*` | see config | Preloader colours |
| `progress.enabled` | `true` | Navigation progress bar |
| `progress.color`, `.height` | `null`, `3` | Colour falls back to the spinner's; height in px |
| `progress.delay`, `.trickle` | `250`, `true` | Silence before it appears, and whether it eases while waiting |
| `transition.duration` | `150` | Cross-fade length in ms; `0` swaps instantly |
| `manifest_path`, `manifest` | see config | Web App Manifest (all spec members) |
| `head.title`, `.title_template` | `null` | Document title and its wrapper |
| `head.description`, `.icon` | `null` | Fall back to the manifest's |
| `head.base` | `null` | `<base href>`; off because it rewrites every relative URL |
| `head.color_scheme`, `.theme_color_dark` | `null` | Dark-mode head hints |
| `head.image` | `null` | `og:image` / `twitter:image` for pages that name none |
| `head.robots` | `null` | Default `robots` directive for every page |
| `head.locale` | `null` | `og:locale`; falls back to the application locale |
| `head.alternates` | `[]` | `hreflang` links for every page, as `['fr' => '/fr']` |
| `head.json_ld` | `null` | Structured data for pages that declare none |
| `head.open_graph`, `.open_graph_type` | `true`, `'website'` | Derive Open Graph tags, and `og:type` |
| `head.twitter_card` | `null` | `twitter:card`; follows the image when left null |

### Service worker

| Key | Default | Purpose |
| --- | --- | --- |
| `service_worker.enabled` | `false` | Register and serve the worker |
| `service_worker.path`, `.scope` | `/sw.js`, `/` | Where it lives and what it controls |
| `service_worker.version` | `'v1'` | Mixed into the manifest hash; bump to discard everything |
| `service_worker.cache_name` | `'pwax'` | Prefix for every cache the worker owns |
| `service_worker.extend` | `[]` | Views or paths appended to the worker — the supported way to add a handler |
| `service_worker.blade` | `null` | Replace the worker outright with a view of your own |
| `service_worker.offline_view` | `null` | The document a navigation gets with no network and nothing stored |
| `service_worker.navigation_strategy` | `network-first` | How a full page navigation is answered |
| `service_worker.navigation_urls` | see config | Which paths belong to the app; anything else bypasses the worker |
| `service_worker.namespaces` | `[]` | Package view namespaces to scan for components |
| `service_worker.pages.max_entries` | `60` | Cap on stored pages, payloads and documents alike |
| `service_worker.runtime_strategy` | `network-only` | What happens to a same-origin GET nothing in the manifest claims |
| `service_worker.max_entries` | `60` | Cap on the **runtime** cache; precached entries are never evicted |
| `service_worker.max_entry_bytes` | `5 MB` | Largest single response the runtime cache will keep |
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
| `service_worker.pages.runtime` | `true` | Cache pages as they are visited — payload, and HTML when anonymous |
| `service_worker.pages.documents` | `true` | Precache each page's HTML alongside its JSON payload at install |
| `service_worker.pages.strategy`, `.timeout` | `network-first`, `2000` | How a page payload is fetched |
| `service_worker.pages.credentials` | `'include'` | Cookies are sent when precaching a page; caches are shared, so what is stored is whatever the server returned |
| `service_worker.pages.as_components` | `false` | Also precache page views as importable modules |
| `service_worker.data_groups` | `[]` | API response caching |
| `service_worker.navigation_strategy` | `network-first` | Or `cache-first` for zero-round-trip navigation |
| `service_worker.navigation_urls` | see config | Which navigations the worker claims |
| `service_worker.offline_url` | `null` | Page shown offline; defaults to the shell |
| `service_worker.navigation_preload` | `true` | Start the network request before the worker boots |

## Artisan commands

| Command | Purpose |
| --- | --- |
| `pwax:install` | Publish config and frontend assets (`--views`, `--push`, `--service-worker`, `--ai`, `--force`, `--no-assets`) |
| `pwax:component <name>` | Scaffold a component view (`--plain`, `--force`, `--plugin`, `--directive`, `--middleware`) |
| `pwax:precache` | List everything available offline (`--verify`, `--json`) — `--verify` renders every component and probes every page |
| `pwax:compile` | Precompile templates to render functions — optional, needs Node (`--clear`, `--json`) |
| `pwax:vapid` | Generate a VAPID key pair for Web Push (`--json`) |
| `pwax:push-endpoint` | Scaffold the push-subscription controller (`--force`) |
| `pwax:routes` | List every Pwax-served route (`--all` includes application routes) |
| `pwax:skill` | Publish the Pwax skill for AI assistants (`--path`, `--force`) |
| `pwax:doctor` | Check for common misconfigurations |
| `pwax:clear` | Flush compiled caches and the offline manifest |

## JavaScript API

The runtime publishes `window.pwax`:

| Member | Description |
| --- | --- |
| `pwax.component(url, export?)` | Vue async component for a component URL |
| `pwax.load(url, export?)` | Promise of the component's options |
| `pwax.import(url)` | Import a component module by URL (the runtime-side `@pwaxImport`) |
| `pwax.start()` | Reboot the runtime — unmount and re-initialise; returns a Promise |
| `pwax.http.json(url, options?)` | Fetch JSON with Pwax's headers and CSRF token |
| `pwax.styles` | The reference-counted style manager |
| `pwax.sw.controller` | The `ServiceWorker` controlling this page, or null |
| `pwax.sw.registration()` | The `ServiceWorkerRegistration`, or null |
| `pwax.sw.update()` | Check for a new build now |
| `pwax.sw.applyUpdate()` | Let a waiting build take over now, and reload |
| `pwax.sw.clearCaches()` | Delete every Pwax cache, framework included |
| `pwax.sw.unregister()` | Remove the service worker entirely |
| `pwax.install.*` | `available`, `installed`, `standalone`, `prompt()` |
| `pwax.badge.set(n)` / `.clear()` | The count on the installed app's icon |
| `pwax.storage.*` | `estimate()`, `persisted()`, `persist()` |
| `pwax.push.*` | `subscribe()`, `unsubscribe()`, `subscription()`, `permission` |
| `pwax.sync.*` | `enqueue(url, options)`, `pending()`, `flush()` |
| `pwax.launch.consume(fn)` | Files and URLs the operating system launched the app with |
| `pwax.share(data)` | The platform share sheet |
| `pwax.prefetch(path)` | Fetch a page's payload before it is asked for |
| `pwax.restore.forget(path)` | Drop one page, so the next visit back to it fetches |
| `pwax.restore.clear()` | Drop every page held for the back button |
| `pwax.progress` | The navigation progress bar, for your own long tasks |
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
| `pwax:installable`, `pwax:installed` | The browser will offer installation, or it happened |
| `pwax:push-subscribed`, `pwax:push-unsubscribed` | A push subscription changed |
| `pwax:queued` | A write went to the background-sync queue |
| `pwax:launch` | The OS opened the app with files or a URL (cancelable) |

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
