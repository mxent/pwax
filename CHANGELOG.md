# Changelog

All notable changes to `mxent/pwax` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **`<PwaxJson :json="…" />`**, a globally registered component that renders a JSON
  document — a tree of components and props — against a catalog declared in
  `pwax.json.components`. A document can only name components the catalog lists, and
  cannot introduce markup, scripts or components of its own, which is what makes a
  structure assembled on the server, stored in a database or produced by a language model
  safe to render. Nothing about `pwaxRender()` or the payload format changes: the document
  is controller data like any other, and the page around it is an ordinary Pwax component.

- **The catalog is a boundary, not a suggestion.** Vue passes a prop a component did not
  declare through to that component's root element, where a few names stop being data, so
  a document's props are filtered before they are rendered: anything beginning with `on`,
  the markup sinks (`innerHTML`, `outerHTML`, `textContent`, `innerText`, `srcdoc`) and any
  value whose scheme is `javascript:`, `vbscript:` or `data:text/html` is dropped with a
  console line naming the element and the prop. Vue's own `^prop` and `.prop` prefixes are
  undone before the name is checked, the scheme is read the way the URL parser reads one,
  and a value is looked for at every level of a prop — a menu's URL lives in
  `items[n].href`, not in the prop itself — with the whole prop dropped when one turns up.
  The `submit` and `navigate` built-ins refuse a URL on another origin for the same reason
  — one carries the session's CSRF token, the other drives the application's router. What
  a component then does with the data it is given is the component's own to validate, as it
  already was.

- **A catalog component is an ordinary Pwax component.** Children arrive through one
  default `<slot />`, and whatever the component declares in `emits` is what a document
  may bind with `on` — configuration never repeats it. Scoped styles, lazy loading and
  offline precaching all work as they already did.

- **Actions.** A binding carries an action name, `params` that may read state,
  `onSuccess` / `onError`, and an optional `confirm`. Most need no handler: the renderer
  supplies `setState`, `pushState`, `removeState` and `validateForm`, and Pwax adds
  `navigate` (through the SPA router), `submit` (with the CSRF token, queued through
  `window.pwax.sync` when the connection is gone) and `reload`. Applications add their own
  under `pwax.json.actions`, resolved exactly like `pwax.vue.middleware`, or pass
  `:handlers` for one instance — where a name is defined twice, the page wins over
  configuration, and configuration over a built-in.

- **The whole document vocabulary.** All eight prop expressions (`$state`, `$bindState`,
  `$template`, `$cond`, `$item`, `$bindItem`, `$index`, `$computed`) and every element key
  (`children`, `visible`, `repeat`, `on`, `watch`), including nested `repeat` and the full
  set of `visible` comparisons. `workbench/resources/views/pages/vocabulary.blade.php`
  demonstrates every one of them on a single page, alongside a catalog component reached
  by dotted path on `window` and an `onError` that surfaces the thrown message.

  `validateForm` is the one renderer action that does nothing here. It reports on fields
  registered through a composable a component calls in its own `setup()`, which a catalog
  component — loaded as a separate module from the server — cannot reach.

- **`:functions`**, for `$computed` props. A prop rather than configuration, because it
  holds JavaScript and `config/pwax.php` carries data the runtime reads, never code it
  runs. Catalog prop declarations shape `prompt()` and `jsonSchema()` — they constrain the
  model that writes a document, and are not a runtime gate; the boundary that is enforced
  is the component list.

- **`window.pwax.json.{load,prompt,jsonSchema}`.** `prompt()` and `jsonSchema()`
  describe the configured catalog for a model that is generating documents — each
  component with its description, its declared props and the events it emits, so a model
  binding an `on` key writes an event the component actually has instead of guessing.
  Event names come from the component's own `emits`, so describing the catalog loads
  every component in it; rendering is unaffected and still fetches only what a document
  names.

- **`dist/pwax-json.js`**, a second prebuilt bundle carrying
  [json-render](https://github.com/vercel-labs/json-render) and its dependencies. It is
  about 82 kB gzipped against the runtime's 9.7 kB, so it is served, precached and
  fetched only when `pwax.json.enabled` is on and a `<PwaxJson>` actually renders — and
  hinted at with a `<link rel="preload">` on the pages whose templates use one. Like
  every other bundle it ships built, so there is still nothing to install and nothing to
  compile.

- `php artisan pwax:doctor` checks the catalog: a reference that names no component, one
  pointing at a view that does not exist, an unknown prop type, an `enum` with no values,
  and a prop name the renderer drops — declaring one is futile rather than dangerous, since
  it puts a prop in `prompt()` and `jsonSchema()` that no document can ever deliver.

## [1.0.0]

First release.

### Added

- **Vue single-file components as Blade views.** `<template>`, `<script>` and `<style>`
  in one `.blade.php` file, compiled to an ES module and served from a signed,
  same-origin URL. Vue compiles the template in the browser, so there is no `npm run
  build`, no `.vue` files, and no separate route table in JavaScript.

- **`pwaxRender($view, $data)`**, which answers a browser navigation with the SPA shell —
  component embedded, so the first paint costs one request — and a request from the
  client runtime with the JSON payload alone. Both carry `Vary`.

- **`@pwaxImport('components.modal')`**, resolved when the view runs rather than when
  Blade compiles it, so `view:cache` and a changed `route_prefix` cannot leave a baked
  URL behind. The call it emits is synchronous, which is what lets two components import
  each other without deadlocking at module top level.

- **`<style scoped>`**, honoured by rewriting each selector and stamping the template's
  elements to match. `:deep()` and `:global()` work as Vue authors expect.

- **Client-side routing** over the application's own named routes: `pwaxRoute()` resolves
  a Laravel route to a path Vue Router can consume, and an unknown name throws in debug
  rather than quietly sending every link home.

- **A service worker driven by an asset manifest.** The server enumerates every URL the
  application is made of — vendor bundles, the runtime, the offline shell, every page and
  every component — each with a content hash, and the worker installs the lot in one
  pass. No "visit the page once to cache it", and no manual version bump to ship a change.
  Pages are precached as both payload and document.

- **One caching vocabulary**: `network-only`, `network-first`, `cache-first`,
  `stale-while-revalidate`, used by every strategy key in the config. An unrecognised
  name falls back to the default and `pwax:doctor` fails on it.

- **A web app manifest**, an offline document, an install prompt, app badging, storage
  persistence, Web Push with VAPID, background-sync for writes, file and URL launch
  handling, and the platform share sheet — all reachable from `window.pwax`.

- **Security headers on every response Pwax serves**, not only its own endpoints, decided
  once in `Support\SecurityHeaders` so that a URL is hardened identically whether the
  server or the service worker answers it.

- **Middleware that translates framework responses** the client runtime can act on: an
  in-application redirect becomes `{"redirect": "/path"}` for the SPA router, and an
  off-site redirect becomes `409` plus `X-Pwax-Location` for a full navigation.

- **Content-keyed compile and minification caches**, so a component pays for parsing and
  minification once and edited components fall out of the cache on their own.

- **Optional template precompilation** (`pwax:compile`) for applications that would
  rather ship Vue's runtime-only build and drop `script-src 'unsafe-eval'`.

- **Artisan commands**: `pwax:install`, `pwax:component`, `pwax:precache`, `pwax:compile`,
  `pwax:vapid`, `pwax:push-endpoint`, `pwax:routes`, `pwax:skill`, `pwax:doctor` and
  `pwax:clear`.

- **A skill file for AI assistants**, published to `.ai/skills/pwax/SKILL.md` by
  `pwax:skill` or `pwax:install --ai`.

[Unreleased]: https://github.com/mxent/pwax/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/mxent/pwax/releases/tag/v1.0.0
