# AGENTS.md — operating manual for AI assistants working in `mxent/pwax`

This file is read by an AI coding assistant before it touches anything in this
repository. It is the consensus the maintainers have reached about how the
package is shaped, why it is shaped that way, and what counts as the wrong kind
of change. Read it the way you would read a senior engineer's onboarding
document: every section is a decision someone has already had to make.

If a section says "always do X" and a task asks you to do not-X, the answer is
almost always "raise it with the maintainer", not "do the task anyway".

---

## 1. What this package is

`mxent/pwax` lets a Laravel developer write a Vue component as a Blade view,
and ships the resulting application as a progressive web app. The package is
two things:

- a **PHP layer** (`src/`) — service provider, console commands, HTTP
  middleware, the component renderer, the manifest builder, the page discovery
  service worker integration;
- a **JavaScript layer** (`src/js/`) — the client runtime that consumes
  compiled components and the service worker source that pre-caches them.

The PHP layer renders templates and serves endpoints. The JS layer is a single
static bundle that reads one JSON block (`runtimeConfig`) — nothing on the
server is interpolated into JavaScript, so a stray quote in `config/pwax.php`
can no longer take the whole page down.

A consumer installs the package, publishes `config/pwax.php`, scaffolds pages
with `pwax:component`, and the application is a PWA. The package owns the
contract: what the runtime receives, what the runtime emits, what the
service worker caches.

---

## 2. The two layers and how they talk

The boundary is one JSON block (`Shell::runtimeConfig()` → `window.pwax.config`
→ the bundle's `config.js`). Everything crossing that boundary is data:

- **PHP → JS at boot:** a JSON blob with all settings. Read by `src/js/config.js`
  on the client. Anything that ends up there must be serialisable.
- **JS → PHP at request time:** the three request headers
  (`X-Pwax-Component`, `X-Requested-With`, `Accept`). Read by
  `HandlePwaxRequests`. The page response varies on them — `Pwax::VARY` is the
  canonical list.
- **PHP → JS at compile time:** the service worker is built by esbuild from
  `src/js/sw/index.js` into `dist/pwax-sw.js`. The PHP layer serves the built
  file behind a generated preamble (the four values the server actually
  decides: cache name, precache entries, scope, version).

If you need to add a setting, add it to `runtimeConfig()` and read it from
`config.js`. If you need to add a header, add it to `Pwax::VARY` and to
`PAGE_HEADERS` in `AssetManifest` — they must agree, and the test
`HeaderConstantsTest` enforces that.

---

## 3. `dist/` is committed and verified

`dist/pwax.js` and `dist/pwax-sw.js` are produced by `npm run build` and
checked into the repository. CI runs `git diff --exit-code dist/` to catch a
source change that forgot to rebuild. This is by design:

- the bundle is a runtime dependency, not a build artefact;
- a fresh clone must work without `npm install`;
- the package is meant to be readable from the published artefact too, so a
  developer can grep `dist/pwax.js` for the runtime behaviour they are seeing.

When you change anything in `src/js/`, you must run `npm run build` and
commit the result. The CI check is the safety net, not an excuse to skip the
build locally.

---

## 4. Coding conventions

These are non-negotiable; the maintainers reject PRs that deviate.

### PHP

- `declare(strict_types=1);` in every file. No exceptions.
- Single quotes for strings, double quotes only for interpolation.
- Curly braces on every control structure, including single-line bodies.
- PHP 8 constructor property promotion: `public function __construct(public X $x) {}`.
  Do not leave an empty zero-parameter `__construct()` unless the constructor
  is private.
- Explicit return types and parameter type hints on every method.
- PHPDoc blocks for non-trivial logic. Prefer array-shape annotations
  (`@return array{name: string, version: int}`) over loose `array`.
- TitleCase for `Enum` keys (`FavoritePerson`, not `favorite_person`).
- Never use `(bool)` casts to silence static analysis. Fix the type.
- Never use `assert()` to override PHPStan's inference.

Run `vendor/bin/pint --dirty --format agent` after every PHP edit and
`vendor/bin/phpstan analyse --memory-limit=1G` before considering a task
done. Both must be clean.

### JavaScript

- ES modules, no CommonJS in `src/`.
- Single quotes for strings; template literals only for interpolation.
- No `any` in JSDoc. The TypeScript declarations in `types/pwax.d.ts` describe
  the public surface; if you need an internal type, declare it locally.
- The runtime is browser-only: no Node imports, no `fs`, no `path`. The
  service worker is browser-only by definition.
- The runtime must work with `script-src 'self'` and no eval: no `new Function`,
  no `eval`, no dynamic `import()` whose argument is constructed from
  user input. The module URL is a literal that the runtime builds from a
  signed id.

Run `npm run build` after every JS edit and `npx vitest run` to confirm the
existing tests still pass. The four pre-existing failures in
`tests/js/renderFunction.test.js` are a known flake from a peer-dep issue
with `@vue/compiler-dom`; do not try to fix them as part of unrelated work.

### Tests

- PHPUnit for PHP, vitest for JS.
- Feature tests for everything that crosses the HTTP boundary or
  `Artisan` boundary. Unit tests for pure logic.
- One assertion idea per test; multiple assertions on the same idea are
  fine, multiple ideas in one test are not.
- The assertion message tells the reader what would have happened if the
  test had failed for a different reason — see `test_redirects_are_untouched_for_ordinary_browser_requests`
  for the kind of message that earns its keep.
- Run only the affected tests during development (`vendor/bin/phpunit --filter=…`).
  Run the whole suite before declaring done.

---

## 5. Architecture rules

These are decisions the maintainers have already had to make. Reversing any
of them is a breaking change.

### The runtime config is data, not code

`Shell::runtimeConfig()` returns an array. Nothing in the PHP layer is
interpolated into JavaScript. In 1.x the runtime was assembled inside a Blade
file with `{!! !!}`, which meant a stray quote in `config/pwax.php` produced
a syntax error that took down the whole page. **Do not regress to that
shape** for any reason. If you find yourself wanting to inject a string of
JavaScript into a Blade file, add a key to `runtimeConfig()` and read it
from the bundle.

### The service worker is built, not templated

The worker lives in `src/js/sw/index.js` and is compiled by esbuild to
`dist/pwax-sw.js`. The Blade preamble that wraps it carries the four values
the server actually decides. **Do not put runtime logic back inside
`resources/views/js/worker.blade.php`.** If you need to change worker
behaviour, edit `src/js/sw/index.js` and rebuild.

The `service_worker.blade` config key still exists and always will — it is
the supported escape hatch for an application that needs to replace the
worker outright. Use it knowingly.

### The service worker is one file per cache strategy

Strategies are configured server-side in `pwax.service_worker.*_strategy` and
serialised into `sw.json`. The worker reads them on activation and routes
requests by category (page, navigation, runtime, data group). Adding a new
strategy means: add a name to `Strategy`, document its semantics, teach
`Strategy::resolve()` to recognise it, and add the case in the worker's
`fetch` handler. There is no place where the worker makes a strategy
decision on its own.

### Pages are discovered, not enumerated

Routes are discovered by walking `Illuminate\Routing\Router`. The manifest
carries the resolved list. A page that exists in `routes/web.php` and is
rendered with `pwaxRender()` is automatically precached; a developer who
adds a route does not need to remember to add it to a list. Do not add a
`pages.urls`-only mode that requires manual enumeration — that was the 1.x
behaviour, it was wrong, and `pwax:doctor` flags configs that still rely on
it.

### Middleware is registered via Laravel middleware groups, not aliases

`HandlePwaxRequests` is appended to the groups named in
`pwax.middleware` (default: `['web']`). The package never aliases itself
under a route's middleware list — the developer chooses the group. The
server-side `pwax.middleware` config (Laravel middleware groups) and the
client-side `pwax.vue.middleware` config (Vue route middleware) live in
distinct namespaces by design; the two were once confused for each other,
and grouping all client-side Vue extensions under `pwax.vue.*` was the
fix.

### Components are Blade views, but their compile output is JavaScript

`src/Pwa/ComponentRenderer` (and its helpers) produces a `Component` value
object with `template`, `script`, `style` strings. The blade view is parsed
once and cached by content hash. A developer who edits the view invalidates
the cache; a developer who edits config does not. This separation is what
lets `pwax:compile` be background-safe.

---

## 6. Public API surface

The following are part of the contract with consumers. Adding to them is
fine; removing from them is a major version.

### PHP API

- All commands under `php artisan pwax:*`.
- The `Pwax` facade and the underlying `Mxent\Pwax\Pwax` class.
- `pwaxRender()`, `pwax_route()`, `pwax_component()` global helpers.
- The configuration keys in `config/pwax.php`. A rename is a breaking change
  unless the old key continues to work; if it doesn't, document the
  migration in `CHANGELOG.md` under `### Changed` with a copy-pasteable
  diff (see the `plugins` / `directives` / `middleware_js` →
  `vue.*` move for the canonical recipe).
- The HTTP routes registered by `routes/web.php` (the `__pwax__/*` prefix).
- The HTTP headers `X-Pwax-Component` and `X-Pwax-Location`.

### JavaScript API

- The `window.pwax` namespace and everything reachable from it
  (`pwax.config`, `pwax.start()`, `pwax.share()`, `pwax.launch.consume()`).
- The service worker registration at `/sw.js`.
- The web manifest at `/manifest.json` and `/manifest.webmanifest`.
- The component route prefix `__pwax__` and the URL shape `__pwax__/c/{id}.js`.
- The push subscription shape sent to the user's `pwax.push.endpoint`.

Anything not on this list is internal and can change without notice. If a
consumer is reaching into it, the right answer is to add the missing key to
the public API and document it, not to keep the internal shape stable.

---

## 7. Security posture

- **Never interpolate user input into a Blade template without escaping.**
  `{{ }}` escapes; `{!! !!}` and Vue's `v-html` do not. The runtime config
  embeds in a `<script type="application/json">` block, so it is parsed as
  data, not executed.
- **Never put user input in `pwax.vue.*`.** They are emitted into the
  page as JavaScript. They are configuration, never a place for a request
  parameter.
- **The component endpoints are protected by Laravel middleware.** The
  default is `['web']`. A developer who removes that gets a doctor warning.
  CSRF tokens are sent with every runtime request automatically.
- **Cross-origin isolation is on by default.** The shell emits the
  `Cross-Origin-Opener-Policy: same-origin` and
  `Cross-Origin-Embedder-Policy: require-corp` headers. Cross-origin assets
  must be loaded with `crossorigin`; the doctor catches this.
- **Authorisation belongs on the server.** Client middleware is a UX
  affordance, not access control. Document this in any user-facing note
  about `pwax.vue.middleware`.

---

## 8. Configuration key renames

A renamed config key is a breaking change for published
`config/pwax.php` files — the package cannot rewrite them. The recipe:

1. Pick a new name that does not collide with anything still in use. If the
   old name was a forced workaround (e.g. `middleware_js` because
   `middleware` was already taken by the server side), the right fix is
   usually to give the whole group its own namespace (`vue.*`) so neither
   side needs a suffix again.
2. Update the runtime, the manifest hash, the scaffolder, and the doctor in
   one commit. There is no "old key still works" half-measure: the user said
   *change it*, so change it.
3. CHANGELOG entry under `### Changed` (not `### Deprecated`) with a
   copy-pasteable diff showing the move, and the upgrade recipe
   (`grep -rn 'old_key' config/`).
4. README mentions the new name in the configuration reference table.

Do not skip the doctor check — a published `config/pwax.php` is the
application's file, not the package's, so an upgrade cannot rewrite it.

---

## 9. What the maintainers will push back on

- **Anything that re-templatizes the service worker.** The worker is
  JavaScript in `src/js/`. Blade is for views.
- **Anything that interpolates PHP into JavaScript at runtime.** Add a key
  to `runtimeConfig()`.
- **Anything that adds a global helper without a deprecation cycle.** The
  1.x helpers (`vue()`, `router()`) were removed in 4.0 after a 2.0
  deprecation. New helpers follow the same shape: ship deprecated first,
  remove in the next major.
- **Anything that breaks a public-API test silently.** The test suite is
  the contract. If a change requires changing a test, the change is a
  breaking change and the version bumps.
- **Committing `dist/` unchanged when `src/js/` changed.** CI catches it,
  but it is a signal you forgot the build step.
- **Adding a new dependency without checking the consumer.** A Laravel
  package is consumed by hundreds of apps; a new hard dependency is a
  surprise to all of them. Talk to the maintainers first.

---

## 10. Where to read more

- `README.md` — the user-facing manual; its structure is the user's
  mental model of the package.
- `UPGRADE.md` — what changed between versions, with code diffs.
- `CHANGELOG.md` — every release, grouped Added / Changed / Fixed /
  Deprecated / Removed / Internal.
- `SECURITY.md` — how to report a vulnerability privately.
- `CONTRIBUTING.md` — pull-request workflow and review checklist.
- `resources/ai/pwax-skill.md` — the template for the AI skill
  `pwax:skill` publishes; it is what an application developer points their
  AI assistant at.

---

If something here contradicts the user's request, the user's request almost
certainly reveals a misunderstanding of how the package works. Ask before
acting.