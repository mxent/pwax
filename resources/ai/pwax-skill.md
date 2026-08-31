---
name: pwax
description: Working with `mxent/pwax` — the Laravel package that ships Vue components written as Blade views as a progressive web app. Use this skill whenever the project uses `pwaxRender`, `@pwaxImport`, `@{{ }}`, `pwax:doctor`, or `pwax:component`. TRIGGER when the user asks to add a page, change a manifest setting, configure the service worker, scaffold a component/plugin/directive/middleware, debug a doctor warning, change SEO meta tags, set up Web Push, queue a form submission or any other write for when the connection returns, integrate Vite or Tailwind with Pwax, customise the shell or the offline document, or understand why a runtime setting lives where it does.
---

# Pwax — what every change in this project needs to know

This project uses `mxent/pwax`. The package has two halves: a **PHP layer**
that renders components, builds the manifest and serves the runtime, and a
**JavaScript layer** that is one static bundle reading one JSON config
block. The two halves never meet on the wire — only in `config/pwax.php`.

Everything renders **in the browser**. There is no server-side rendering and
none is planned: the server answers with a component and its data, and the
page is built on the device. That is what lets the whole application be
precached and keep working with no network. A crawler that does not execute
JavaScript sees the shell; the `<head>` metadata is still fully rendered, so
link unfurling works.

Read this skill before adding a page, wiring a new component together,
setting per-page meta, configuring the service worker, scaffolding
anything with `pwax:component`, debugging a `pwax:doctor` warning, or
before reaching for a config key whose purpose you are not certain of.

Re-run `php artisan pwax:skill --force` after every package update so
this file reflects the current conventions. The source of truth is
`resources/ai/pwax-skill.md` inside the package — what you are reading
is a published copy.

---

## 1. Where things live

- `routes/web.php` (or a controller) — every page is a route returning
  `pwaxRender('pages.X')`. Closures for simple routes, controller
  methods when the route has other concerns (auth, layout, caching).
- `resources/views/pages/` — the Blade views that become Vue components.
  Convention is one view per route, named to match (`pages.home` for
  the `home` route).
- `resources/views/components/` — reusable building blocks imported by
  pages with `@pwaxImport(...)` and registered in the `components:`
  block of the default export.
- `resources/views/plugins/`, `resources/views/directives/`,
  `resources/views/middleware/` — Vue extensions, registered in
  `config/pwax.php` under the matching `pwax.vue.*` key.
- `config/pwax.php` — the package's settings. Read it before changing
  anything that crosses the runtime boundary.
- `app/Http/Controllers/` — page controllers live here. Use
  `\pwaxRender('pages.X')` (with leading backslash) inside namespaced
  controllers; PHP otherwise tries to resolve `App\Http\Controllers\pwaxRender`.

The PWA surface (manifest, service worker, runtime, push) is served
under the URL prefix `__pwax__` and is part of the package's contract —
its shape is documented in the README and not a place to improvise.

---

## 2. The shape of a Pwax component

Every Pwax component is a Blade view with three top-level tags:

```blade
<template>
    <div class="card">@{{ title }}</div>
</template>

<script>
export default {
    name: 'MyCard',                 // shows up in Vue DevTools
    props: { title: String },       // standard Vue, no Pwax magic
    data() { return { count: 0 }; },
    components: {
        // See §3 — this is how Pwax components wire up to each other
        OtherThing: @pwaxImport('components.other-thing'),
    },
};
</script>

<style scoped>
/* CSS is scoped automatically — rules only match this component */
</style>
```

Notes:

- **`@{{ }}` escapes Blade** so Vue receives `{{ }}` intact. Inside a
  Pwax component, every Vue interpolation must use `@{{ }}`. Forgetting
  this is the single most common bug — the symptom is a runtime warning
  and an empty value where one was expected.
- **The `<template>` is the Vue template.** It may contain Blade
  interpolations that resolve at compile time; once compiled, the
  result is shipped as Vue. Do not put controller data in
  `<template>` (it makes the page stateful instead of addressable —
  see §6).
- **`name:` in the default export** lights up Vue DevTools and shows
  up in the runtime's `$options.name`. Always set it.
- **`<slot />` works normally.** Pwax does not intercept it.
- **The default export is the Vue component.** A plugin exports a
  `default` object with an `install` method; a directive exports
  `bind`/`update`; a client middleware exports an async function. Run
  `pwax:component --plugin`, `--directive` or `--middleware` and read
  the comment block it emits; that is the canonical shape for each.
- **`<style scoped>` becomes a Vue scoped style.** Omit `scoped` for
  global styles; pass `--plain` to `pwax:component` to skip the block.

The scaffolder (`pwax:component`) writes the canonical shape; reading
an existing component is the fastest way to confirm the convention for
the specific feature you are adding.

---

## 3. The `components:` block — how Pwax components wire up

This is the dominant Pwax pattern: a page imports another component,
then registers it so the template can use it as a tag.

```blade
<template>
    <div>
        <SiteHeader />
        <main><slot /></main>
        <SiteFooter />
        <CookieBanner />
    </div>
</template>

<script>
export default {
    name: 'Layout',
    components: {
        SiteHeader:   @pwaxImport('components.site-header'),
        SiteFooter:   @pwaxImport('components.site-footer'),
        CookieBanner: @pwaxImport('components.cookie-banner'),
    },
};
</script>
```

Two rules:

- **`components:` is registered on the default export**, not on the
  template. The runtime compiles the `<script>` once when the page is
  requested, then reuses the same resolved module for every visit.
- **`@pwaxImport(...)` is the value, not a string.** It is a Blade
  directive that emits a JavaScript expression, so it must sit where an
  expression goes — unquoted. Quoting it (`'@pwaxImport(...)'`) leaves a
  literal string, and Vue then looks for a registered component by that
  name and finds none.

The key in `components:` is the tag the template uses (`<SiteHeader />`,
not `<site-header />`). Local registration (PascalCase) and global
registration (`app.component(...)`) both work; Pwax does not register
anything globally, so two pages using `<SiteHeader />` each import it.

The dotted-path-on-`window` rule is a **config** rule, not a script one:
a `pwax.vue.plugins` value that is not `@pwaxImport(...)` is looked up as
a path on `window`. Inside a `<script>` block, a string is just a string.

---

## 3b. `<PwaxJson>` — the other way to wire components up

A page whose shape is not known when you write it — a dashboard assembled
from what a user enabled, a form driven by a schema, a screen a model
produced — renders a **JSON document** instead of a template:

```blade
<template>
    <div class="page">
        <h1>Your report</h1>
        <PwaxJson :json="doc" />
    </div>
</template>

<script>
export default {
    data() {
        return { doc: @json($doc) };
    },
};
</script>
```

The route is unchanged — `pwaxRender('pages.report', ['doc' => $doc])`.
`<PwaxJson>` is registered globally, so there is nothing to import, and it
works anywhere a component works.

**What a document may contain is `pwax.json.components`**, and only that:

```php
'json' => [
    'components' => [
        'Card' => "@pwaxImport('components.card')",
        'Button' => [
            'component' => "@pwaxImport('components.button')",
            'description' => 'A clickable button that emits a "press" event.',
            'props' => ['label' => ['type' => 'string', 'required' => true]],
        ],
    ],
],
```

Same reference vocabulary as `pwax.vue.*`: `@pwaxImport(...)`,
`module:view.name`, or a dotted path on `window`. Never evaluated.

**A catalog component is an ordinary Pwax component.** Two rules:

- Children arrive through **one default `<slot />`**. A document lists
  child keys under `children` and cannot address a named slot.
- **`emits` is the contract.** Whatever the component declares there is
  what a document can bind with `on`. Configuration never repeats it.

A document is a flat map, not a nested tree:

```php
[
    'root' => 'card',
    'state' => ['user' => ['name' => 'Ada']],
    'elements' => [
        'card' => [
            'type' => 'Card',
            'props' => ['title' => ['$template' => 'Hello, ${/user/name}!']],
            'children' => ['go'],
        ],
        'go' => [
            'type' => 'Button',
            'props' => ['label' => 'Settings'],
            'on' => ['press' => ['action' => 'navigate', 'params' => ['to' => '/settings']]],
        ],
    ],
]
```

Eight prop expressions: `$state`, `$bindState`, `$template`, `$cond`
(with `$then`/`$else`), `$item`, `$bindItem`, `$index`, `$computed`
(with `args`). Elements may carry `children`, `visible`, `repeat`, `on`
and `watch`. `visible` takes `eq`/`neq`/`gt`/`gte`/`lt`/`lte` plus `not`,
a list (meaning and), or `$and`/`$or`.

**Actions divide into three, and most need nothing from you:**

| Action | From | Handler? |
| --- | --- | --- |
| `setState`, `pushState`, `removeState` | the renderer | no |
| `navigate`, `submit`, `reload` | Pwax | no |
| anything else | you | yes — `pwax.json.actions` or `:handlers` |

`validateForm` exists but is inert here: it reports on fields registered
through a composable a component calls in its own `setup()`, which a
catalog component cannot reach. Validate in a handler or on the server.

`setState` is the one to reach for first: a document can show and hide its
own panels with no PHP and no handler at all.

A binding may also carry `params` (which may read state), `onSuccess` /
`onError` (`{navigate}`, `{set}` or `{action}`), and `confirm`. An event may
name a list of bindings and all of them run.

Precedence where a name is defined twice: `:handlers` > `pwax.json.actions` >
Pwax built-in.

`@action` fires for every action **that reaches a handler** — not for the
renderer's own state actions, which are handled before any handler is
consulted. Use `@state-change` to see those; it reports the pointers written.

`:functions` is a prop, not config, because it holds JavaScript — config
carries data the runtime reads, never code it runs.

Prop types in the catalog are **prompt material, not a runtime gate**: they
shape `prompt()` and `jsonSchema()`, and nothing checks them once a document
arrives. An undeclared prop still reaches the component.

**What a document may never set**, whatever the catalog says — these are
dropped with a console line, because Vue passes an undeclared prop through
to the component's root element where a few names stop being data:

| Dropped | Why |
| --- | --- |
| any prop beginning with `on` | `onclick` becomes an inline handler and runs |
| `innerHTML`, `outerHTML`, `textContent`, `innerText`, `srcdoc` | Vue sets each as a DOM property, so a string is parsed as HTML |
| a value whose scheme is `javascript:`, `vbscript:` or `data:text/html`, **at any depth** | a component rendering a prop as a URL would run it |

Vue's `^prop` / `.prop` prefixes are undone first. The name rule is blunt on
purpose, so an innocent `online` prop is dropped too — rename it, and
`pwax:doctor` names one declared in the catalog. The value rule walks nested
arrays and objects, because a menu's URL lives in `items[n].href`, and drops
the whole prop when it finds one. Behaviour belongs under the element's `on`
key. `submit` and `navigate` refuse a cross-origin URL for the same reason:
one carries the CSRF token, the other drives the router.

None of this validates data. A component that renders a prop with `v-html`,
or renders one as a URL, still gets whatever ordinary value the document
wrote — that is the component's own decision to validate, exactly as with a
controller's data.

The renderer is a second bundle, `dist/pwax-json.js`, ~82 kB gzipped. It
is fetched by the first `<PwaxJson>` that renders and never on a page that
has none. It is precached, so a `->cacheable()` page works offline.

---

## 4. The `@pwaxImport` directive

One component reaches another with `@pwaxImport('view.name')`, **inside the
`<script>` block** — it is a JavaScript expression, not a Blade tag:

```blade
<script>
    const modal = @pwaxImport('components.modal');

    export default {
        data() {
            return { modal };
        },
    };
</script>

<template>
    <component :is="modal" />
</template>
```

It expands to `window.pwax.component('/__pwax__/c/<signed-id>.js', '')`,
which returns a Vue async component — the same thing
`defineAsyncComponent` returns. So either shape works: register it in
`components:` and use it as a tag (§3, and the more common one), or hold
it in `data()` and render it with `<component :is>`, which is what you
need when the component to render is chosen at runtime. Add a named
export with `@pwaxImport('Modal from components.modal')`.

**It resolves at request time, not when Blade compiles the view.** The
directive emits PHP that calls `Pwax::import()`, so the URL is built when
the view runs. That matters: resolving at compile time froze the URL into
`storage/framework/views`, so changing `route_prefix` — or running
`view:cache` in a build step with no routes loaded — produced imports
pointing nowhere.

**Never name the directive `import`.** Blade matches a directive even with
no arguments, so an `@import` directive also swallows the CSS at-rule
`@import url("…")` inside every `<style>` block in the application and
replaces it with JavaScript. `PwaxServiceProvider::assertDirectiveName()`
throws at boot on that name, so an application configured that way does
not start — `pwax:doctor` does not check it, because it could never run.

The name is configurable through `pwax.components.directive` and defaults
to `pwaxImport`. Config values for plugins, directives and client
middleware use the same spelling, under `pwax.vue`:

```php
'vue' => [
    'plugins' => [
        'toast' => "@pwaxImport('plugins.toast')",
    ],
],
```

A reference that is not `@pwaxImport(...)` is treated as a dotted path
to look up on `window` — it is **never** evaluated as code.

---

## 5. `pwaxRoute()` inside Vue

A Vue route target needs to be a path Vue Router can navigate to, so
the named-route helper is what every component uses instead of
hand-writing URLs:

```blade
<script>
export default {
    computed: {
        home()      { return '{{ pwaxRoute('index') }}'; },
        blog()      { return '{{ pwaxRoute('blog') }}'; },
        postUrl(s)  { return '{{ pwaxRoute('blog.show', ['slug' => '']) }}' + s; },
    },
};
</script>

<template>
    <nav>
        <RouterLink :to="home">Home</RouterLink>
        <RouterLink :to="blog">Blog</RouterLink>
    </nav>
</template>
```

The `'{{ pwaxRoute('about') }}'` interpolation is resolved by Blade at
request time and ends up as a plain string in the `<script>` that the
runtime sees. It is the right idiom for any link whose target is a
known named route.

`pwaxRoute('blog.show', ['slug' => $post->slug])` works inside
controllers and Blade too; the in-Vue form is just the same call,
run by Blade during compilation.

---

## 6. The two response shapes

A page route returns one of two things, and the choice changes what
the runtime does with it:

- **`pwaxRender('pages.home')`** — render the Blade view as a component
  and return its template, script and styles. The runtime imports the
  compiled module by id.
- **`pwaxRender('pages.home', ['post' => $post])`** — same, plus the
  data is sent as the component's initial state. Use this when the
  page depends on controller data.

The first shape is **addressable** (one URL per view, cacheable by the
service worker). The second is **stateful** (the URL stays the same,
the data does not — the worker cannot pre-render it for offline).

Anything you can make addressable, make addressable: the manifest,
pre-cache and service worker all assume it. A page that must be
stateful is a deliberate choice with a real reason — comment why.

### The fluent API on the response

`pwaxRender()` returns a `Mxent\Pwax\Http\Responses\ComponentResponse`
that you can chain methods on before returning it from a route or
controller:

```php
return pwaxRender('pages.post.show', ['post' => $post])
    ->title($post->meta_title ?: $post->title)
    ->description($post->excerpt)
    ->canonical(route('posts.show', $post))
    ->image($post->image_url)
    ->robots($post->draft ? 'noindex' : 'index, follow')
    ->offline(false); // refuse service-worker caching for this page
```

Methods available: `title()`, `description()`, `canonical()`,
`image()`, `robots()`, `alternate()`, `jsonLd()`, `meta()`,
`property()`, `cacheable()`, `offline()`, `status()`, `asJson()`,
`withHeaders()`.

`->image()` sets `og:image` and `twitter:image` together and makes a
site-relative path absolute; `->robots()` is `->meta('robots', …)` with
an application-wide default under `pwax.head.robots`. Use `->status(404)` instead of
converting the response to a Symfony `Response` and calling
`setStatusCode()` on it — that bypasses the runtime's signal that the
URL produced a real page. The 404 fallback should look like:

```php
Route::fallback(function () {
    $response = pwaxRender('pages.404');
    return $response->status(404);
});
```

---

## 7. Per-page metadata — the actual flow

The skill earlier said `pwaxRender(...)->title(...)` for meta. There is
**no** `View::share('pwaxMeta', ...)` mechanism in Pwax. If your
project has leftover `View::share('pwaxMeta', [...])` calls, **they
do nothing** — no view in the package reads `$pwaxMeta`. Use the
fluent API on the response (§6) instead.

The flow is:

1. The route calls `pwaxRender(...)` and optionally chains
   `->title()`, `->description()`, `->canonical()`, `->image()`,
   `->robots()`, `->alternate()`, `->jsonLd()`, `->meta()`,
   `->property()` on the response.
2. `Mxent\Pwax\Pwa\HeadMeta::resolve()` fills in defaults from
   `pwax.head.*` (title, title_template, description, image, robots,
   locale, alternates, json_ld, open_graph, twitter_card) and derives
   Open Graph / Twitter card tags from the values that are set.
3. The shell renders `<title>`, `<meta>`, `<link rel="canonical">`
   from the resolved `Head`, with `data-pwax-head` so the runtime can
   replace them on a client-side navigation.

`pwax.head.title` falls back to `pwax.manifest.name`. `description`
falls back to `pwax.head.description` then `pwax.manifest.description`.
The `title_template` is only applied when a page supplied its own
title — `':title · Acme'` against a fallback of `'Acme'` would render
`'Acme · Acme'`, which is not what the template is for.

4. The payload carries `title` and `head` on **every** page, including
   one that declared neither, so the runtime overwrites what the
   previous page set instead of inheriting it. This is why you do not
   need to set a title on every route to stop the wrong one sticking:
   a page with none resolves to the fallback and the runtime applies
   that. A page that wants no canonical URL simply omits it and the
   previous page's is removed.

### JSON-LD

Structured data belongs on the response, not in a stack:

```php
pwaxRender('pages.post', compact('post'))
    ->title($post->title)
    ->image($post->cover_url)
    ->jsonLd([
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $post->title,
        'datePublished' => $post->published_at->toIso8601String(),
    ]);
```

Call `->jsonLd()` more than once for a page that makes several claims
(an `Article` and the `BreadcrumbList` above it) and each becomes its
own block. The package escapes the JSON so a value from the database
cannot close the block, and stamps the CSP nonce on it.

A page that calls `->jsonLd()` **replaces** `pwax.head.json_ld` rather
than adding to it: an `Article` and an `Organization` are two claims
about two different things, and emitting both against one URL says the
page is both.

Do **not** reach for `@push('pwax-head')` with a hand-written
`<script type="application/ld+json">` for per-page data. That block is
rendered once into the document and is never replaced on a client-side
navigation, so from the second page onwards it describes a page the
visitor has left. Stale structured data is not a missing rich result,
it is a wrong one. The stack is for document-level content that is the
same on every page.

### Other <head> extensions

Use `@stack('pwax-head')` for content that belongs to the document
rather than to the page — a verification tag, the site's own
`Organization` graph:

```blade
@once
    @push('pwax-head')
        <meta name="google-site-verification" content="…">
    @endpush
@endonce
```

The shell renders `data-pwax-head` markers on its own tags so the
runtime knows which to replace on a client-side navigation; anything
inside `@stack('pwax-head')` is left alone.

---

## 8. Extending the shell

Three ways to add application behaviour to the shell itself:

- **`php artisan pwax:install --views`** publishes the Blade views
  into `resources/views/vendor/pwax/`. Edit `layouts/shell.blade.php`
  to add global markup; edit `components/includes/head.blade.php` or
  `components/includes/foot.blade.php` to add things that always go in
  `<head>` or just before `</body>`.
- **`pwax.blade.{head,foot,content,error,loader}`** — point any of
  these at one of your own Blade views to replace a bundled partial
  without publishing the whole view directory.
- **Conditional partials from a published shell** — once you have
  published the shell, you can add `@includeWhen(View::exists(...),
  'partials.X')` lines yourself. The package's bundled shell does
  not auto-include anything; the published shell is yours.

After publishing the views, **your `vendor/pwax` directory takes
priority** over the package's bundled view of the same name. Re-run
`php artisan pwax:install --views --force` to roll back to the
package version (which is rarely what you want — fork it knowingly).

---

## 9. Config keys that cross the runtime boundary

The three Vue extensions are emitted into the page as JavaScript; they
all live under `pwax.vue.*`:

```php
'vue' => [
    'plugins'    => ['toast' => "@pwaxImport('plugins.toast')"],
    'directives' => ['focus' => "@pwaxImport('directives.focus')"],
    'middleware' => ['admin' => "@pwaxImport('middleware.admin')"],
],
```

Each value is either a `@pwaxImport('view.name')` reference or a dotted
path to a global on `window`. Values are configuration, **never** a
place for request input. They are emitted verbatim into a `<script
type="application/json">` block.

The `pwax.middleware` config is a **different** key — it lists Laravel
middleware groups to inject the package's HTTP middleware into. The
two have never been the same; putting the client-side one under
`vue.*` lets it keep its natural name (`vue.middleware`) and groups
every piece of client-side Vue configuration in one place.

Other settings that cross the runtime boundary live in `pwax.head.*`
(title, title_template, description, canonical, og:*, twitter_card) and
in `pwax.push.*` (public_key, endpoint, title, icon, badge). All of
these are configuration, never request data.

---

## 10. Vue directives that conflict with Blade

A `@something` inside a Blade template is a directive. The package's
own directive is `@pwaxImport`; the default Vue directives (`@click`,
`@submit`, `@keyup`) work because Blade ignores names it does not
recognise. There are a handful of common ones that **do** collide:

- `@class` — Blade's own stack directive. Vue users reach for it on
  `<svg>` for `v-bind:class`-style bindings.
- `@error` — Blade's error block. Vue uses it for input validation.
- `@production`, `@env`, `@auth`, `@guest` — Blade conditionals.
- `@if`, `@foreach`, `@for`, `@while`, `@switch`, `@isset`, `@empty`,
  `@continue`, `@break`, `@php`, `@use` — all Blade.

When you need one inside a Pwax component, switch to the `v-` form
(`v-if`, `v-for`) and the conflict disappears. `pwax:doctor` does **not**
flag this — it is a class of bug that only the runtime catches.

Also watch for `@`-prefixed keys inside JSON-LD embedded in Blade —
prefix `@` with another `@` (`'@@context'`, `'@@type'`) so Blade does
not consume them as directives.

---

## 11. The service worker

The service worker is `dist/pwax-sw.js`, produced by esbuild from
`src/js/sw/index.js` inside the package. **Do not try to publish or
modify the worker.** Two supported extensions exist:

- `pwax.service_worker.extend` — list of additional views or files
  whose contents the worker appends to itself. Each entry shares the
  worker's scope, so `CONFIG`, `PREFIX` and the cache helpers are all
  in reach. This is where a `push` handler, a `sync` handler, or
  anything else the package does not ship belongs.
- `pwax.service_worker.blade` — a full replacement view, kept for
  applications that need to fork the worker. Use it knowingly; you
  stop receiving fixes.

The runtime settings (`pwax.service_worker.enabled`, `*.strategy`,
`*.cache_name`, `*.precache`, `*.assets`, `*.shell.enabled`) drive the
manifest and the worker's behaviour at boot. Change them in
`config/pwax.php`, run `pwax:doctor`, and read the warnings.

### Strategy vocabulary

Four strategy names are used in every `*.strategy` config key and in
every data group:

| Name | Behaviour |
|---|---|
| `network-only` | never stored, always fetched |
| `network-first` | fetch, fall back to what is stored |
| `cache-first` | serve what is stored, fetch only when there is nothing |
| `stale-while-revalidate` | serve what is stored and refresh it in the background |

Those four are the only spellings. Anything else is not resolving to
anything — it is silently falling back to the default — and
`pwax:doctor` **fails** on it rather than warning. Fix the config; do
not add an alias.

`assets.source` is not one of these. It takes `local` or `cdn` and
chooses where the framework is served from, not how anything is cached.

### Navigation URLs

`pwax.service_worker.navigation_urls` lists which paths the worker
claims as the SPA. The default `['/**', '!/**/*.*', '!/**/*__*',
'!/**/*__*/**']` covers everything except paths with a file extension
or a double underscore. A navigation matched by none of these (or
explicitly excluded by a leading `!`) bypasses the worker and goes
straight to the network — so `/admin/__debug` keeps working on a
shared domain with Nova, Telescope or a Filament panel.

---

## 12. Prefetching

`pwax.prefetch.{mode,delay}` controls whether the runtime fetches the
next page before the visitor clicks:

- `mode: 'hover'` — fetch when the pointer lands on a link (or focus
  arrives via keyboard) and `delay` ms has passed.
- `mode: 'visible'` — fetch when the link scrolls into view.
- `mode: 'load'` — fetch as soon as the page mounts.
- `false` — turn it off.

Payloads are held in memory only, capped at eight and dropped after
thirty seconds. A page payload can carry a signed-in visitor's data,
so a prefetch is a head start rather than a cache — the service
worker is what stores pages.

Costs a request for a link someone hovers and does not click. Turn it
off for an application with expensive pages or metered users.

---

## 13. The PWA manifest

`pwax.manifest.*` populates the Web App Manifest served at
`pwax.manifest_path` (default `/manifest.json`). Every key is emitted
verbatim, so any member the specification gains can simply be added
here. Empty values (`null`, `''`, `[]`) are dropped; `false` and `0`
are kept because `prefer_related_applications => false` is meaningful.

The three members that hand your app to the operating system require
**matching routes**:

- `protocol_handlers` — `web+thing:` links open the app at the
  configured URL. Run `pwax:doctor` to verify every entry resolves
  against the real route table.
- `file_handlers` — file associations. Same verification applies.
- `share_target` — a POST endpoint with CSRF exemption and its own
  validation.

A launch delivered through the launch queue is consumed by
`window.pwax.launch.consume(({ files, targetURL }) => { ... })`. The
queue holds launches until a consumer is set, so register the
consumer before the document finishes loading.

`pwax.manifest.id` is the installation's stable identity; defaulting
to `start_url` orphans every existing install when `start_url` changes.

---

## 14. Web Push

Push needs four things in place, in this order:

1. `php artisan pwax:vapid` — generates the key pair, prints both.
2. `php artisan pwax:push-endpoint` — scaffolds the controller.
3. The VAPID keys and the endpoint URL go into `.env`
   (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, the endpoint URL).
   Both keys are mapped in `config/pwax.php` under `pwax.push.public_key`
   and `pwax.push.private_key`. Pwax itself uses only the public key
   (it is the browser half); the private key is the application's, but
   `pwax:doctor` validates its shape so a typo is caught here rather
   than as a 401 from the push service.
4. The `push_subscriptions` table exists (the migration ships with
   the package).

`pwax:doctor` verifies all four. A missing migration, a malformed
key, or a controller that 500's is reported as a failed check, not a
silent nothing-happens.

`pwax.push.subscribe()` must be called from a user gesture; browsers
reject permission requests that are not. A page that asks on load is
the reason they do.

**`pwax.push.endpoint` must be on your own origin.** The runtime posts
the subscription there with the session's CSRF token attached, so a
cross-origin URL is refused outright and logged rather than sent — a
subscription is not secret, but the token is. Use a path (`/push`),
not an absolute URL to somewhere else.

If the endpoint answers non-2xx, or cannot be reached, the runtime
logs an error naming the status. Do not ignore it: the browser is
subscribed and the server does not know the subscription exists, so
every push the application believes it sent goes nowhere, and the
symptom is indistinguishable from a bad VAPID key.

---

## 15. Offline writes — `window.pwax.sync`

Reading offline is half an app. The other half is letting someone
submit a form with no connection and have it send later.

Nothing is queued for you. That is deliberate: intercepting every
failed write would replay a payment as readily as a draft, and only
the application knows which of its requests are safe to repeat. So
the application decides, per request:

```js
try {
    await fetch('/notes', { method: 'POST', body });
} catch {
    // No network. Store it; the worker sends it when one returns.
    const queued = await window.pwax.sync.enqueue('/notes', {
        method: 'POST',
        body: { text: 'draft' },   // object or string
    });

    if (!queued) {
        // Nothing to store it in. Fail loudly rather than pretend.
    }
}
```

- `enqueue(url, {method, headers, body})` → `Promise<boolean>`.
  Resolves false when there is no Cache Storage to write to.
- `pending()` → `Promise<number>`, readable before a worker controls
  the page. This is what a "3 changes will send when you are back
  online" indicator reads.
- `flush()` asks the worker to try the queue now.
- `supported` is false where Service Workers or Cache Storage are not.
- A successful `enqueue` fires `pwax:queued` on `document`.

**Only queue requests that are safe to repeat.** A replay can happen
after the original eventually succeeded — the device came back online
between the two — so the endpoint should be idempotent, or the payload
should carry a key the server can deduplicate on.

What the worker does with a queued entry when it replays it:

| Outcome | Queue entry |
| --- | --- |
| 2xx | deleted — it sent |
| 4xx, except those below | deleted — the server gave a real answer |
| 419, 408, 425, 429 | **kept** and retried |
| 5xx | kept and retried |
| network failure | kept, retried on the next sync |

419 is the one to understand. An entry carries the CSRF token that was
current when it was queued, so anything that sat offline longer than
`session.lifetime` comes back 419 on its first replay, every time. It
is kept, and the next replay — from a page that has since refreshed the
session — is the one that succeeds. If your endpoint is stateless and
you would rather skip this entirely, exempt it in
`VerifyCsrfToken::$except` and queue it without the token.

A consequence worth knowing: **a queued entry holds that CSRF token in
Cache Storage until it sends.** Do not put anything else secret in a
queued body that you would not want at rest on the device.

---

## 16. Security & CSP

`pwax.security.*` sets the response headers Pwax emits on **every**
response it serves — the page a visitor loads, the JSON payload a
client-side navigation fetches, the runtime bundle, the manifests, the
offline shell, each component module. Documents get the full set;
assets get `nosniff` and `Referrer-Policy`.

- `Referrer-Policy: no-referrer` — nothing leaks to third parties the
  page happens to load assets from.
- `X-Frame-Options: SAMEORIGIN` lets the application frame itself;
  set to `DENY` for a stricter policy.
- `Permissions-Policy` denies what reaches hardware, a sensor or
  another origin's data, and allows the document `(self)` for what a
  PWA is built on — Web Share, the wake lock, fullscreen, the
  clipboard, passkeys. Do not replace it with a blanket deny:
  `web-share=()` breaks `window.pwax.share()` and
  `publickey-credentials-get=()` breaks passkey sign-in.
- `Cross-Origin-Opener-Policy: same-origin-allow-popups`. Do not set
  it to `same-origin` unless you want cross-origin isolation — it
  severs `window.opener` for popups the document itself opens, which
  is how an OAuth flow returns its result.
- `Cross-Origin-Embedder-Policy` is off. `require-corp` refuses every
  cross-origin subresource without `Cross-Origin-Resource-Policy` —
  most avatars, most CDN fonts. Set it, with
  `cross_origin_opener_policy => 'same-origin'`, only when you need
  `SharedArrayBuffer`; the doctor then checks `crossorigin` on
  off-site scripts and styles.

Any of them set to `null` or `''` drops that header, which is what to
do when the application's own middleware is the authority.

`pwax.csp.nonce` is a nonce (or a callable returning one) applied to
the inline `<style>` and JSON blocks Pwax emits. Integrate it with
your CSP middleware of choice.

When `pwax.assets.vue_build` is `full` (the default), Vue compiles
templates in the browser using the `Function` constructor and the
runtime needs `script-src 'unsafe-eval'`. Switching to `runtime` (via
`pwax:compile`) drops that requirement.

---

## 17. The precompiled-templates workflow

`pwax:compile` reads every configured component and stores the
`{template, script, style}` triple by content hash. **Production must
run this command during deployment.** Without it, the first request
to each component pays the compile cost on the hot path; under load,
that is the difference between "fast" and "the server is on fire".

The deployment recipe is: `composer install`, `php artisan migrate`,
`php artisan pwax:compile`, `php artisan pwax:doctor`. Re-run
`pwax:compile` after a deploy that changes views.

The trade is in `pwax.assets.vue_build`:

- `full` (default) ships Vue's template compiler. About 20 kB
  gzipped larger than `runtime`, but no compile step is required —
  `pwax:compile` becomes optional.
- `runtime` is the opt-in trade in the other direction: run
  `pwax:compile` after each deploy, ship ~40.7 kB gzipped instead of
  ~60.8, and drop `'unsafe-eval'`. It needs Node in your build, and
  `@vue/compiler-dom` as a dev dependency.

`pwax.assets.render_functions` is where the compiled output is
written (defaults to `storage/app/pwax/render-functions.php`).
`pwax.assets.node` is the Node binary it runs (defaults to whatever
`node` resolves to).

One constraint comes with the `runtime` build: a template must be the
same for every visitor. Keep controller data in `<script>` (`@json($user)`)
and out of `<template>`, which is the idiomatic split anyway.
`pwax:compile` names any view that breaks it.

---

## 18. Bundling your own JS / CSS alongside Pwax

Pwax owns the runtime, but you can still ship your own JS / CSS:

- `pwax.styles` — list of stylesheet URLs or tag-attribute arrays,
  emitted in `<head>` after the shell's own styles.
- `pwax.scripts` — list of script URLs or tag-attribute arrays,
  emitted before the runtime.
- `pwax.assets.source` — `'local'` (serve Vue/Vue Router/Pinia from
  your own origin via `vendor/pwax/`) or `'cdn'` (load them from a
  configured CDN with subresource integrity).

A PWA that fetches its framework from a third-party CDN cannot work
offline — which is the entire point of a PWA — and discloses every
visitor's IP address to that CDN. `'local'` is the default for both
reasons.

The typical consumer architecture uses Vite (or a similar bundler) for
its own application JS / CSS, Pwax for the runtime and pages, and
`pwax.styles` / `pwax.scripts` for whatever the bundler emits.
`pwax:doctor` verifies the local and CDN configurations.

---

## 19. Debugging a `pwax:doctor` warning

The doctor names the problem and the fix. Read the warning in full:

- **"manifest target does not match a route"** — a `share_target`,
  `file_handlers` or `protocol_handlers` entry points at a path the
  router does not know. Either add the route or remove the entry.
- **"runtime strategy is unknown"** — the strategy name in
  `pwax.service_worker.runtime_strategy` (or `navigation_strategy`,
  `pages.strategy`, `data_groups[].strategy`) is not in the
  vocabulary above. Aliases from earlier releases are recognised and
  warned about, not failed.
- **"Component routes have middleware"** — `pwax.middleware` is
  empty. Set it to `['web']`.
- **"no application key"** — `APP_KEY` is not set.
  `php artisan key:generate`.
- **"manifest has no icons"** — a PWA without icons is not
  installable.
- **"CDN assets have no subresource integrity hashes configured"** —
  every entry in `pwax.assets.cdn.integrity` needs an `sha384-…`
  hash.
- **"has an unknown strategy"** — a caching key in the published
  `config/pwax.php` names something that is not one of the four
  spellings above. Nothing fails at runtime; the default silently
  applies instead. The message names the four.
- **"builds its stylesheet by reading the DOM"** — `pwax.scripts` loads
  a browser CSS engine (the Tailwind Play CDN, UnoCSS runtime, Twind).
  Every visitor downloads a compiler and waits for a DOM scan before
  anything is styled, on every cold load. Build your CSS to a file and
  list it in `pwax.styles`.

The full list lives in `src/Console/Commands/DoctorCommand.php`. When
the doctor says "no problems, N warnings", every warning is a thing
that **still works** but should change.

Companion commands: `pwax:routes` lists the endpoints Pwax owns;
`pwax:precache [--verify]` shows what the worker will install and
checks whether precached entries are reachable.

---

## 20. Pitfalls worth their own section

### The `@{{ }}` escape

Vue interpolation is `{{ }}`. Blade interpolation is `{{ }}`. Inside
a Pwax component, every Vue interpolation **must be** `@{{ }}` so
Blade passes it through. Forgetting the `@` makes Blade consume it and
ship an empty template — the symptom is a runtime warning and a blank
value where one was expected.

### A JSON document that renders nothing

Three shapes look right and draw an empty box. Pwax warns about the first
two in the console; the third is a catalog problem.

- **`slots` on an element.** The renderer reads `children` and never
  `slots`, so content placed under `slots` never renders. List child keys
  under `children`, and give the component one default `<slot />`.
- **`repeat` on the row.** `repeat` repeats an element's *children*, so it
  goes on the container. On an element with no `children` it produces one
  empty row.
- **A `type` that is not in the catalog.** Renders nothing and names
  itself in the console. Add it to `pwax.json.components`.
- **`confirm` on `setState`/`pushState`/`removeState`.** The renderer
  handles its own actions and returns before the confirmation, so the
  action runs without asking. Confirm an action of your own instead.

Two more worth knowing: `@state-change` emits the *changed pointers*, not
a state snapshot; and props are camelCase in a document because they are
Vue props (`modelValue`, not `model-value`).

### The `@` literal in JSON-LD

Inside JSON-LD embedded in Blade, every `@`-prefixed key (`@context`,
`@type`, `@graph`) needs an extra `@` to escape Blade's directive
parsing. The fix is mechanical: `'@@context'`, `'@@type'`.

### `components: { X: () => @pwaxImport(...) }`

The Vue 2 idiom for an async component, and the single commonest thing to
get wrong here — Vue 3 dropped it. Vue sees a function, treats the entry
as a *functional component*, calls it, gets an object back instead of
vnodes and renders `String(...)` — `[object Object]`, with no warning.

`@pwaxImport(...)` already returns an async component. Pass it directly:

```php
components: {
    Modal: @pwaxImport('components.modal'),   // right
    Modal: () => @pwaxImport('components.modal'),   // renders [object Object]
}
```

Pwax makes the mistake explain itself on the page rather than leaving
`[object Object]` on screen, but the fix is to drop the arrow.

### The stateful vs addressable choice

If `pwaxRender('pages.X', [...])` sends data the component cannot
derive from its view name, the page is stateful. The service worker
cannot pre-render it for offline. This is fine for "edit post 42",
wrong for "homepage" — make the choice deliberately.

### `pwaxRender()` in a namespaced controller

Inside `App\Http\Controllers\...`, call `\pwaxRender(...)` with a
leading backslash. Without it, PHP tries to resolve
`App\Http\Controllers\pwaxRender` which does not exist.

### Blade directives that look like Vue directives

See §10. If Blade's parser sees `@if`, `@foreach`, etc. inside a Pwax
component, it generates the Blade equivalent instead of the Vue one.

### `route_prefix: '__pwax__'` collisions

`pwax.route_prefix` is the URL prefix for the internal component
endpoints. A consumer using `__pwax__` in its own URL space needs to
change one of them — the runtime and the application sharing a prefix
is a recipe for routing surprises.

### `pwax:install --views` is a fork

Editing the published shell takes priority over the package's bundled
view of the same name. There is no upstream merge; `pwax:install
--views --force` overwrites your edits with the package version.

---

## 21. When you are stuck

1. `php artisan pwax:doctor` — most warnings name the fix.
2. `php artisan pwax:routes` — every endpoint Pwax owns.
3. `php artisan pwax:precache --verify` — what the worker will cache
   and whether it can reach everything.
4. The README has the user-facing manual, and `CHANGELOG.md` records
   what changed in each release.
5. `src/Support/Shell.php` is the source of truth for what the runtime
   receives. If you change a setting on the PHP side, it has to land
   in `runtimeConfig()`.
6. `src/js/config.js` is what reads those settings on the client.
7. If none of those answer the question, the issue is probably
   application-shaped, not package-shaped — read your own code.

---

This file is regenerated by `php artisan pwax:skill --force`. Keep it
next to your AI assistant's other skills; an assistant that knows
Pwax will produce work that needs fewer corrections.