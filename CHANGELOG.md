# Changelog

All notable changes to `mxent/pwax` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- A component now has exactly one representation: `/__pwax__/c/{id}.js`, an ES module
  carrying its template, script, styles and scope together. The `.css` and `.json`
  endpoints were removed. Nothing consumed either — the runtime imports the module and
  reads its exports — and both rendered the view with **no controller data**, so their
  output was misleading in precisely the case someone would have reached for them.
  `Pwax::url()` accordingly drops its `$format` argument.

### Fixed

- Components returned by `@pwax` are memoised on their URL and export name. Vue treats
  every `defineAsyncComponent` result as a distinct component type, so minting a new one
  per call remounted the subtree whenever a parent re-rendered and gave `<KeepAlive>` a
  different identity to cache each time.
- Wrapping the directive in an arrow function — `components: { Modal: () => @pwax('…') }`,
  the Vue 2 lazy-component idiom that Vue 3 dropped — rendered `[object Object]` with no
  warning anywhere. Vue sees a function, treats the entry as a functional component, calls
  it during render, and falls back to `String(child)` when it gets a component object
  instead of vnodes. The component now carries a `toString()` that names the mistake and
  its fix, so the page says what to change. Documented in the README and upgrade guide.

## [2.0.0]

A rewrite of the internals. See [UPGRADE.md](UPGRADE.md) for the migration checklist.

### Security

- **Component endpoints no longer render arbitrary views.** `/__pwax__/{name}.json`
  accepted any view name, so an unauthenticated caller could render any Blade template in
  the application — `GET /__pwax__/admin_x_users.json` returned
  `resources/views/admin/users.blade.php`. Identifiers are now signed with `APP_KEY` and
  verified with `hash_equals`, so only URLs the application itself emitted resolve.
- **Component routes run through a middleware stack** (`web` by default). They previously
  had none, so components rendered with no session and `auth()` was always a guest.
- **Plugin, directive and middleware config values are no longer executed.** They were
  interpolated into the page inside `{!! !!}`. Each is now either a component reference or
  a dotted path looked up on `window`.
- Added `components.allowed`, an optional allowlist of servable view patterns.
- Added `csp.nonce` for the inline `<style>` and JSON blocks, and dropped the need for
  `blob:` and `data:` in `script-src` by serving components from real URLs.
- The bundled error template uses `v-text` rather than `v-html` for response-derived text.
- Added [SECURITY.md](SECURITY.md) with a disclosure policy and the security model.

### Fixed

- **The `@import` Blade directive corrupted CSS across the whole application.** Blade
  matches a directive even with no arguments, so a directive named `import` also captured
  the CSS at-rule `@import url("…")` inside every `<style>` block in every Blade view —
  not only in Pwax components — and replaced it with JavaScript. The directive is now
  `@pwax`, configurable, and the name `import` is rejected.
- **The directive resolved URLs at Blade compile time**, freezing them into
  `storage/framework/views`. Changing `route_prefix`, or running `view:cache` in a build
  step with no routes loaded, produced permanently broken imports. It now emits PHP that
  runs per request.
- **Circular imports deadlocked.** `@import` expanded to `await window.pwaxImport(...)`, so
  two components referencing each other each waited at module top level for the other — a
  deadlock in native ES modules. The placeholder-mutation workaround that hid it (and
  which could render a component with a template but no methods) is gone: `@pwax` returns a
  Vue async component resolved at render time.
- **Navigating away removed the styles of mounted components.** Every injected tag was
  marked `pwax-attached` and all of them were removed on each navigation, including those
  belonging to imported components still on screen. Styles are now reference-counted.
- **Every navigation leaked an ES module.** Component scripts were imported from a fresh
  `blob:` URL each time; module records are keyed by URL and never collected. Components
  are now imported once from a stable URL.
- **Import cache keys collided.** Keys came from `Str::studly()` of the view name, so
  `a::foo.bar` and `a.foo.bar` both keyed to `AFooBar` and returned the wrong component.
  Keys are now signed identifiers.
- **`Vary` was missing on negotiated responses**, letting a shared cache serve the JSON
  payload to a browser navigation or the HTML shell to the client runtime.
- **Server redirects never reached the client.** The client handled a `redirect` key that
  nothing produced, so an `auth` middleware redirect surfaced as "Network Error". A
  middleware now translates 302s and expired CSRF tokens.
- Only `<link rel="stylesheet">` is collected as a component stylesheet; the manifest
  link, icons and preloads were previously swept in too.
- Template extraction matches closing tags by depth and tolerates attributes, instead of
  taking the first `<template>` and the last `</template>`.
- `hash_route` defaulted to `false` in config and `true` in the router template.
- The preloader used `100vh`/`100vw`, which mis-centres on mobile and forces a horizontal
  scrollbar on Windows; it now uses `100dvh` and respects `prefers-reduced-motion`.
- A missing component view returns `404` rather than `500`.
- The manifest and service worker no longer run through `web`, so they stop setting a
  session cookie on every fetch.
- A cache store that is missing or misconfigured no longer breaks page rendering. Laravel
  defaults `CACHE_STORE` to `database`, so an application that had not run the cache
  migration would otherwise see every component fail with `no such table: cache`;
  compilation and minification now fall back to running uncached and warn once.
- Redirects produced by an *exception* rather than a response — `auth` and
  `VerifyCsrfToken` both throw — are recovered on the client, which treats a followed
  redirect returning HTML as an instruction to reload. Middleware cannot see these: the
  exception unwinds past the pipeline and the redirect is built by the exception handler.
- Each vendored asset is cache-busted on its own version, rather than all three being
  tagged with Vue's.

### Added

- **The SPA shell embeds the current component in full** — template, styles and script —
  so first paint makes no follow-up request for the page at all, against six sequential
  requests in 1.x.
- **Imported components are served as real ES modules** from `/__pwax__/c/{id}.js`,
  carrying the template, styles and scope alongside the author's script: one request,
  HTTP-cacheable with an ETag, and no `blob:` or `data:` URL. A *page* component cannot
  use this — it is rendered with controller data and so cannot be re-derived from its
  view name — and ships its script inline instead, which the runtime compiles once per
  content hash.
- **`<style scoped>`**, with selector rewriting and template stamping, plus `:deep()` and
  `:global()` escape hatches.
- **Self-hosted frontend assets.** Vue, Vue Router and Pinia ship with the package and
  publish to `public/vendor/pwax`, so the app can start offline and no visitor IP reaches
  a third-party CDN. CDN mode remains available with subresource integrity.
- **A compiled client runtime** (`dist/pwax.js`, 10 kB) replacing the inline JavaScript in
  `main.blade.php`, with its own lint, format and test suite. CI fails if the committed
  bundle drifts from source.
- ETag/`304` support on every component endpoint, the runtime bundle and the manifest.
- Content-addressed caching of compiled components and minified sources.
- `Pwax` facade and container service; `pwax()`, `pwax_component()`, `pwax_route()` helpers.
- Artisan `pwax:doctor`, `pwax:component` and `pwax:clear`; `pwax:install` gained
  `--no-assets`.
- Service worker rewrite: navigation preload, navigation-always-network-first,
  stale-while-revalidate for assets, bounded cache, versioned invalidation, update
  notification via `pwax:update-available`, and refusal to cache opaque or partial
  responses.
- Document events `pwax:ready`, `pwax:navigating`, `pwax:navigated`, `pwax:error` and
  `pwax:update-available`; `window.pwax` public API.
- `routes.register`, `routes.domain` and `routes.static_middleware` for applications that
  want to own their routing.
- Accessibility: `role="status"`/`aria-live` on the loader, `role="alert"` on errors, a
  retry control, and reduced-motion support.

### Changed

- **Upgraded the frontend stack**: Vue 3.5.18 → 3.5.41, Vue Router 4.5.1 → 5.2.0,
  Pinia 3.0.3 → 4.0.2. Vue Router 5 is a no-op migration for applications that did not use
  `unplugin-vue-router`; Pinia 4 is ESM-only but its IIFE build is self-contained.
- `vue()` → `pwax_component()`, `router()` → `pwax_route()`. The 1.x names are available
  behind `helpers.global` and deprecated.
- `pwax_route()` throws on an unknown route name when `APP_DEBUG` is on instead of silently
  falling back to the home page.
- Minification defaults to production only, is cached by content, and can be disabled.
- `config.middleware` now means the *server* middleware stack; client middleware moved to
  `middleware_js`.
- Views reorganised; `layouts/app` became `layouts/shell` and the `components/vue/*`
  templates were replaced by the runtime bundle.
- `composer.json`: `minimum-stability` is `stable` (it was `dev`, which forced dev
  resolution on every consumer), `php` and `matthiasmullie/minify` constraints are bounded,
  and the full `laravel/framework` requirement was replaced with the `illuminate/*`
  components actually used.

### Removed

- The `@import` Blade directive, `vue()` and `router()` globals (see `helpers.global`),
  the `_x_` / `__x__` view-name encoding, and `resources/views/js/main.blade.php`.

### Internal

- CI now runs Pint and PHPStan for real. Both jobs previously skipped when the tools were
  absent — and neither was in `require-dev`, so the badge was always green.
- Test matrix covers PHP 8.2–8.4 × Laravel 12–13, plus `prefer-lowest`.
- Test suite grew from 20 assertions to 179 PHP tests and 52 JavaScript tests.
- Added Pint, PHPStan/Larastan, ESLint, Prettier, Vitest, esbuild, Dependabot,
  `.editorconfig`, `.gitattributes` and issue/PR templates.

## [1.0.0]

### Added

- Vue 3, Vue Router and Pinia integration for Laravel.
- Dynamic component loading from Blade views over AJAX.
- JS/CSS minification and template parsing.
- Configurable plugins, directives and middleware.

[Unreleased]: https://github.com/mxent/pwax/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/mxent/pwax/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/mxent/pwax/releases/tag/v1.0.0
