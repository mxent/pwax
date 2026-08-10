---
name: pwax
description: Working with `mxent/pwax` — the Laravel package that ships Vue components written as Blade views as a progressive web app. Use this skill whenever the project uses `pwaxRender`, `@pwaxImport`, `@{{ }}`, `pwax:doctor`, or `pwax:component`. TRIGGER when the user asks to add a page, change a manifest setting, configure the service worker, scaffold a component/plugin/directive/middleware, debug a doctor warning, or understand why a runtime setting lives where it does.
---

# Pwax — what every change in this project needs to know

This project uses `mxent/pwax`. The package has two halves: a **PHP layer**
that renders components and serves the runtime, and a **JavaScript layer**
that is one static bundle reading one JSON config block. The two halves
never meet on the wire — only in `config/pwax.php`.

Read this skill before adding a page, changing a manifest setting,
configuring the service worker, scaffolding anything with
`pwax:component`, debugging a `pwax:doctor` warning, or before reaching
for a config key whose purpose you are not certain of.

---

## 1. Where things live

- `routes/web.php` — every page is a route returning `pwaxRender('pages.X')`.
- `resources/views/pages/` — the Blade views that become Vue components.
  Convention is one view per route, named to match (`pages.home` for the
  `home` route).
- `resources/views/components/` — reusable building blocks used by pages
  (`<x-pwax:modal>` from `components/modal.blade.php`).
- `resources/views/plugins/`, `resources/views/directives/`,
  `resources/views/middleware/` — Vue extensions, registered in
  `config/pwax.php` under the matching key.
- `config/pwax.php` — the package's settings. Read it before changing
  anything that crosses the runtime boundary.
- `app/Http/Controllers/` — the `pwax:push-endpoint` controller lives here
  when push is enabled.

The PWA surface (manifest, service worker, runtime, push) is served under
the URL prefix `__pwax__` and is part of the package's contract — its
shape is documented in the README and not a place to improvise.

---

## 2. The shape of a Pwax component

Every Pwax component is a Blade view with three top-level tags:

```blade
<template>
    <div class="...">{{ title }}</div>
</template>

<script>
export default {
    data() { return { title: 'Hello' }; },
    middleware: ['auth'], // optional
};
</script>

<style scoped>
/* CSS is scoped automatically */
</style>
```

Notes:

- **`@{{ }}` escapes Blade** so Vue receives `{{ }}` intact. Inside a Pwax
  component, every Vue interpolation must use `@{{ }}`. Forgetting this is
  the single most common bug.
- **The `<template>` is the Vue template.** It may contain Blade
  interpolations that resolve at compile time; once compiled, the result
  is shipped as Vue.
- **The default export is the Vue component.** A plugin exports a `default`
  of an object with `install`; a directive exports `bind`/`update`; a
  middleware exports an async function — see the comment block the
  scaffolder emits.
- **`<style scoped>` becomes a Vue scoped style.** Omit `scoped` for
  global styles. `--plain` on `pwax:component` skips the `<style>` block.

The scaffolder (`pwax:component`) writes the canonical shape; reading an
existing component is the fastest way to confirm the convention for the
specific feature you are adding.

---

## 3. The `@pwaxImport` directive

Components reference each other with `@pwaxImport('view.name')`:

```blade
<x-pwax:card>
    @pwaxImport('components.modal')
</x-pwax:card>
```

The directive resolves at compile time to a signed id and a module URL. It
**must not be confused with CSS `@import`** — they share a prefix and a
naming collision produces a silent broken module. `pwax:doctor` rejects
`pwax.components.directive = 'import'`.

The directive name can be changed (`pwax.components.directive`) but the
default is `pwaxImport`. Config values for plugins, directives and client
middleware use the same spelling:

```php
'plugins' => [
    'toast' => "@pwaxImport('plugins.toast')",
],
```

A reference that is not `@pwaxImport(...)` is treated as a dotted path
to look up on `window` — it is **never** evaluated as code.

---

## 4. The two response shapes

A page route returns one of two things, and the choice changes what the
runtime does with it:

- **`pwaxRender('pages.home')`** — render the Blade view as a component
  and return its template, script and styles. The runtime imports the
  compiled module by id.
- **`pwaxRender('pages.home', ['post' => $post])`** — same, plus the data
  is sent as the component's initial state. Use this when the page depends
  on controller data.

The first shape is **addressable** (one URL per view, cacheable by the
service worker). The second is **stateful** (the URL stays the same, the
data does not — the worker cannot pre-render it for offline).

Anything you can make addressable, make addressable: the manifest,
pre-cache and service worker all assume it. A page that must be stateful
is a deliberate choice with a real reason — comment why.

---

## 5. Config keys that cross the runtime boundary

The three Vue extensions are emitted into the page as JavaScript; they all
live under `pwax.vue.*`:

```php
'vue' => [
    'plugins'    => ['toast' => "@pwaxImport('plugins.toast')"],
    'directives' => ['focus' => "@pwaxImport('directives.focus')"],
    'middleware' => ['admin' => "@pwaxImport('middleware.admin')"],
],
```

Each value is either a `@pwaxImport('view.name')` reference or a dotted
path to a global on `window`. Values are configuration, **never** a place
for request input. They are emitted verbatim into a `<script
type="application/json">` block.

The `pwax.middleware` config is a **different** key — it lists Laravel
middleware groups to inject the package's HTTP middleware into. The two
have never been the same; putting the client-side one under `vue.*` lets
it keep its natural name (`vue.middleware`) and groups every piece of
client-side Vue configuration in one place.

---

## 6. Vue directives that conflict with Blade

A `@something` inside a Blade template is a directive. The package's own
directive is `@pwaxImport`; the default Vue directives (`@click`,
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

---

## 7. The service worker is built, not templated

The service worker is `dist/pwax-sw.js`, produced by esbuild from
`src/js/sw/index.js` inside the package. **Do not try to publish or
modify the worker.** Two supported extensions exist:

- `pwax.service_worker.extend` — list of additional views or files the
  worker handles (`push`, `sync`, custom routes).
- `pwax.service_worker.blade` — a full replacement view, kept for
  applications that need to fork the worker. Use it knowingly; you stop
  receiving fixes.

The runtime settings (`pwax.service_worker.enabled`, `*.strategy`,
`*.cache_name`, `*.precache`, `*.assets`, `*.shell.enabled`) drive the
manifest and the worker's behaviour at boot. Change them in
`config/pwax.php`, run `pwax:doctor`, and read the warnings.

---

## 8. Push notifications

Push needs four things in place, in this order:

1. `php artisan pwax:vapid` — generates the key pair, prints both.
2. `php artisan pwax:push-endpoint` — scaffolds the controller.
3. The VAPID keys and the endpoint URL go into `.env`
   (`PWA_VAPID_PUBLIC_KEY`, `PWA_VAPID_PRIVATE_KEY`, `PWA_PUSH_ENDPOINT`).
4. The `push_subscriptions` table exists (the migration ships with the
   package).

`pwax:doctor` verifies all four. A missing migration, a malformed key, or
a controller that 500's is reported as a failed check, not a silent
nothing-happens.

---

## 9. Debugging a `pwax:doctor` warning

The doctor names the problem and the fix. Read the warning in full:

- **"manifest target does not match a route"** — a `share_target`,
  `file_handlers` or `protocol_handlers` entry points at a path the router
  does not know. Either add the route or remove the entry.
- **"runtime strategy is unknown"** — the strategy name in
  `pwax.service_worker.runtime_strategy` (or `navigation_strategy`,
  `pages.strategy`, `data_groups[].strategy`) is not in the vocabulary.
  The accepted names are listed in the README; aliases from earlier
  releases are recognised and warned about, not failed.
- **"Component routes have middleware"** —
  `pwax.middleware` is empty. Set it to `['web']`.
- **"no application key"** — `APP_KEY` is not set. `php artisan key:generate`.
- **"manifest has no icons"** — a PWA without icons is not installable.

The full list lives in `src/Console/Commands/DoctorCommand.php`. When the
doctor says "no problems, N warnings", every warning is a thing that
**still works** but should change.

---

## 10. The precompiled-templates workflow

`pwax:compile` reads every configured component and stores the
`{template, script, style}` triple by content hash. **Production must run
this command during deployment.** Without it, the first request to each
component pays the compile cost on the hot path; under load, that is the
difference between "fast" and "the server is on fire".

The deployment recipe is: `composer install`, `php artisan migrate`,
`php artisan pwax:compile`, `php artisan pwax:doctor`. Re-run
`pwax:compile` after a deploy that changes views. The doctor checks that
the published config has `pwax.precompile.enabled = true` and that
compiled entries exist.

---

## 11. Two pitfalls worth their own section

### The `@{{ }}` escape

Vue interpolation is `{{ }}`. Blade interpolation is `{{ }}`. Inside a
Pwax component, every Vue interpolation **must be** `@{{ }}` so Blade
passes it through. Forgetting the `@` makes Blade consume it and ship an
empty template — the symptom is a runtime warning and a blank page.

### The stateful vs addressable choice

If `pwaxRender('pages.X', [...])` sends data the component cannot derive
from its view name, the page is stateful. The service worker cannot
pre-render it for offline. This is fine for "edit post 42", wrong for
"homepage" — make the choice deliberately.

---

## 12. When you are stuck

1. `php artisan pwax:doctor` — most warnings name the fix.
2. The README has the user-facing manual; the UPGRADE guide has every
   breaking change between versions.
3. `src/Support/Shell.php` is the source of truth for what the runtime
   receives. If you change a setting on the PHP side, it has to land in
   `runtimeConfig()`.
4. `src/js/config.js` is what reads those settings on the client.
5. If none of those answer the question, the issue is probably
   application-shaped, not package-shaped — read your own code.

---

This file is regenerated by `php artisan pwax:skill`. Keep it next to your
AI assistant's other skills; an assistant that knows Pwax will produce
work that needs fewer corrections.