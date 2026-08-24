# Changelog

All notable changes to `mxent/pwax` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Nothing yet.

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
