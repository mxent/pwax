# AGENTS.md — operating manual for AI assistants working in `mxent/pwax`

This file is read by an AI coding assistant before it touches anything in this
repository. It is the consensus the maintainers have reached about how the
package is shaped, why it is shaped that way, and what counts as the wrong kind
of change. Read it the way you would read a senior engineer's onboarding
document: every section is a decision someone has already had to make.

If a section says "always do X" and a task asks you to do not-X, the answer
is almost always "raise it with the maintainer", not "do the task anyway".

---

## 1. What this package is

`mxent/pwax` lets a Laravel developer write a Vue component as a Blade view,
and ships the resulting application as a progressive web app. The package is
two things:

- a **PHP layer** (`src/`) — service provider, console commands, HTTP
  middleware, the component compiler, the manifest builder, the page-discovery
  service worker integration, the head metadata resolver, the response object;
- a **JavaScript layer** (`src/js/`) — the client runtime that consumes
  compiled components and the service worker source that pre-caches them.

The PHP layer renders templates and serves endpoints. The JS layer is a single
static bundle reading one JSON block (`runtimeConfig`) — nothing on the server
is interpolated into JavaScript, so a stray quote in `config/pwax.php` can no
longer take the whole page down.

A consumer installs the package, publishes `config/pwax.php`, scaffolds pages
with `pwax:component`, and the application is a PWA. The package owns the
contract: what the runtime receives, what the runtime emits, what the
service worker caches, and what the head includes.

---

## 2. The two layers and how they talk

The boundary is one JSON block (`Shell::runtimeConfig()` → `window.pwax.config`
→ the bundle's `config.js`). Everything crossing that boundary is data:

- **PHP → JS at boot:** a JSON blob with all settings. Read by
  `src/js/config.js` on the client. Anything that ends up there must be
  serialisable. Current keys are: `prefix`, `hashRouting`, `base`, `mount`,
  `nonce`, `pinia`, `serviceWorker`, `serviceWorkerScope`, `cachePrefix`,
  `push`, `csrf`, `home`, `progress`, `prefetch`, `plugins`, `directives`,
  `middleware`, `templates`.
- **JS → PHP at request time:** the three request headers
  (`X-Pwax-Component`, `X-Requested-With`, `Accept`). Read by
  `HandlePwaxRequests`. The page response varies on them — `Pwax::VARY` is the
  canonical list.
- **PHP → JS at compile time:** the service worker is built by esbuild from
  `src/js/sw/index.js` into `dist/pwax-sw.js`. The PHP layer serves the built
  file behind a generated preamble carrying the four values the server
  actually decides.

If you need to add a setting, follow the recipe in §9.1 — and add it to the
`Config` interface in `types/pwax.d.ts`, which `RuntimeContractTest` asserts
matches `Shell::runtimeConfig()` key for key. If you need to add a header,
add it to `Pwax::VARY` and to `PAGE_HEADERS` in `AssetManifest`: they must
agree, or the service worker keys its page cache on a field the server does
not vary on, and every offline page lookup misses. `OfflinePagesTest`
enforces that one.

### Component compilation pipeline

A Blade view becomes a `Mxent\Pwax\Data\Component` value object through:

```
Blade view
  → ComponentCompiler::compile()        (reads view once, splits blocks)
    → BlockExtractor                      (separates <template>/<script>/<style>)
    → TemplateStamper                     (rewrites template; scopes @ directives)
    → StyleScoper                         (scoped selector rewriting + element stamping)
  → Data\Component { template, script, style, hash, … }
```

`pwax:compile` runs this once and stores the result by content hash; the live
runtime runs it on first request and caches by hash too (see §16). Either way
the Blade render itself is **not** part of the cache — it runs on every
request, because that is where a page's controller data enters.

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

- **No `declare(strict_types=1);`.** This is a library: strict types apply
  at the *caller's* boundary, so turning them on changes what an
  application is allowed to pass to `ComponentResponse::status()` or
  `cacheable()` — a `"3600"` out of a config file stops working. Laravel's
  own first-party packages omit it for the same reason. Type coercion is
  handled explicitly instead: every value read out of configuration is cast
  at the point of use, which is why `(int)` and `(string)` are everywhere.
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
existing tests still pass. The whole suite is green; a failure in
`tests/js/renderFunction.test.js` means `npm ci` has not been run or the
`@vue/compiler-dom` peer dependency is missing, not a known flake.

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
interpolated into JavaScript. Assemble the runtime inside a Blade file with
`{!! !!}` and a stray quote in `config/pwax.php` becomes a syntax error that
takes down the whole page. **Do not move to that shape** for any reason. If
you find yourself wanting to inject a string of JavaScript into a Blade file,
add a key to `runtimeConfig()` and read it from the bundle.

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
`pages.urls`-only mode that requires manual enumeration: a list nobody
remembers to update is a page that silently stops working offline.

### Middleware is registered via Laravel middleware groups, not aliases

`HandlePwaxRequests` is appended to the groups named in
`pwax.middleware` (default: `['web']`). The package never aliases itself
under a route's middleware list — the developer chooses the group. The
server-side `pwax.middleware` config (Laravel middleware groups) and the
client-side `pwax.vue.middleware` config (Vue route middleware) live in
distinct namespaces by design; the two were once confused for each other,
and grouping all client-side Vue extensions under `pwax.vue.*` was the
fix.

### A JSON catalog entry is a component, not a render function

`src/js/json/index.js` gives every `pwax.json.components` entry its own
generated Vue component rather than the arrow function
`@json-render/vue`'s README shows. This looks like ceremony and is not.
A registry entry is handed `{props, children, emit, on, bindings,
loading}` — no element, no slot map, no instance — so a plain function
cannot discover which events the document bound (it reads them off the
loaded component's own `emits`) and cannot reach `useStateStore()` to
honour a `$bindState` (a composable needs a `setup()`).

Simplify it back to a function and every document renders perfectly and
does nothing: no `on:` binding fires, no two-way binding writes.

Two more things about actions are worth knowing before changing any of
this. The renderer handles `setState`, `pushState`, `removeState`,
`validateForm`, `push` and `pop` itself and returns before consulting the
handler map — so `@action` cannot report them, and "fixing" that is not
possible from our side. And `Root` renders its own confirmation dialog
host: the library's `ConfirmationDialogManager` destructures a
getter-backed context in `setup()`, capturing `null` for good, so a
`confirm` binding otherwise hangs its action forever with no dialog and no
error.
`tests/js/jsonRender.test.js` runs the real bundle against the real Vue
for exactly this reason, and pins three more behaviours of version
0.20.0 that are not documented contracts — `children` rather than named
slots, `repeat` on the container, and the patch-shaped `onStateChange`.

### Components are Blade views, but their compile output is JavaScript

`src/Compiler/ComponentCompiler` (and its helpers `BlockExtractor`,
`StyleScoper`, `TemplateStamper`) produces a `Mxent\Pwax\Data\Component`
value object with `template`, `script`, `style` strings. The blade view is
parsed once and cached by content hash. A developer who edits the view
invalidates the cache; a developer who edits config does not. This
separation is what lets `pwax:compile` be background-safe.

---

## 6. Public API surface

The following are part of the contract with consumers. Adding to them is
fine; removing from them is a major version.

### PHP API

- All commands under `php artisan pwax:*` (`pwax:install`, `pwax:component`,
  `pwax:doctor`, `pwax:precache`, `pwax:compile`, `pwax:vapid`,
  `pwax:push-endpoint`, `pwax:routes`, `pwax:clear`, `pwax:skill`).
- The `Pwax` facade and the underlying `Mxent\Pwax\Pwax` class.
- The global helpers: `pwax()`, `pwaxRender()`, `pwaxRoute()`. Knowing one
  spelling gives you the others.
- `Mxent\Pwax\Http\Responses\ComponentResponse` and its fluent API:
  `title()`, `description()`, `canonical()`, `image()`, `robots()`,
  `alternate()`, `jsonLd()`, `meta()`, `property()`, `cacheable()`,
  `offline()`, `status()`, `asJson()`, `withHeaders()`, `view()`, `data()`.
  Implements `Responsable`, so a controller can `return $response;`.
- The `Mxent\Pwax\Pwa\HeadMeta` resolver and its `Data\Head` value object —
  page metadata flows through this, and the runtime carries the resolved
  head in the payload so client-side navigations update `<title>`, the
  meta tags, the `hreflang` links and the JSON-LD blocks too. Everything
  the package emits into `<head>` carries `data-pwax-head`; that marker
  is the contract, and `src/js/head.js` sweeps by it rather than by tag
  name so a new kind of managed element needs no change there.
- The `Mxent\Pwax\Pwa\Strategy` constants: `NETWORK_ONLY`,
  `NETWORK_FIRST`, `CACHE_FIRST`, `STALE_WHILE_REVALIDATE`. These are the
  only accepted spellings; anything else falls back to a default and
  `pwax:doctor` fails on it.
- The configuration keys in `config/pwax.php`. A rename is a breaking
  change unless the old key continues to work; if it doesn't, document the
  migration in `CHANGELOG.md` under `### Changed` with a copy-pasteable
  diff.
- The HTTP routes registered by `routes/web.php` (the `__pwax__/*` prefix).
- The HTTP headers `X-Pwax-Component` and `X-Pwax-Location`.
- The JSON island ids `pwax-config` and `pwax-initial`, and the mount
  element id `pwax`.
- The response headers set by `pwax.security.*` (`COOP`, `COEP`,
  `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`). Applied to
  every response the package serves — pages, payloads and its own
  endpoints alike — through `Support\SecurityHeaders`.

### JavaScript API

The `window.pwax` namespace, populated by `src/js/index.js`. Anything
reachable from this object is part of the contract:

- `window.pwax.version` — the bundle's version.
- `window.pwax.config` — the runtime config the server supplied
  (`prefix`, `mount`, `csrf`, `serviceWorker`, …). Its exact shape is
  `Shell::runtimeConfig()` on one side and the `Config` interface in
  `types/pwax.d.ts` on the other; adding a key means adding it to both.
- `window.pwax.app` — the Vue application instance.
- `window.pwax.router` — the Vue Router instance; the runtime reads this
  for `push`, `replace`, and back/forward navigation.
- `window.pwax.start()` — reboot the runtime (rarely needed). Unmounts the
  current app and re-initialises; returns a Promise.
- `window.pwax.http.{headers,json}` — the runtime HTTP helper.
- `window.pwax.styles.{acquire,release,link,script}` — the style manager.
- `window.pwax.component(url, export?)` — a Vue async component for a component
  URL; `window.pwax.load(url, export?)` is the promise of its options.
- `window.pwax.import(url)` — runtime-side equivalent of `@pwaxImport`.
- `window.pwax.sw.{controller,registration,update,applyUpdate,clearCaches,unregister}` —
  service-worker API.
- `window.pwax.install.{available,installed,standalone,prompt}` — install prompt.
- `window.pwax.badge.{supported,set,clear}` — app-badge API.
- `window.pwax.storage.{estimate,persisted,persist}` — the origin's storage quota
  and whether the browser will keep it. Not a key/value store.
- `window.pwax.push.{supported,permission,subscription,subscribe,unsubscribe}` —
  Web Push subscription.
- `window.pwax.sync.{supported,enqueue,pending,flush}` — the offline write queue.
- `window.pwax.launch.{supported,pending,consume}` — consume manifest launch
  events (file_handlers, protocol_handlers, share_target).
- `window.pwax.share(data)` — Web Share integration.
- `window.pwax.prefetch(path)` — explicit prefetch.
- `window.pwax.progress.{start,done,reset}` — the progress bar.
- `window.pwax.json.{load,prompt,jsonSchema}` — the JSON document renderer.
  `load()` fetches `dist/pwax-json.js` early; `prompt()` and `jsonSchema()`
  describe the configured catalog for a model. Rendering itself is the
  globally registered `<PwaxJson :json="…" />` component, which is also part
  of the contract — see §5.

`types/pwax.d.ts` is the authority for every shape above and is type-checked in
CI (`npm run types`). When this list and that file disagree, the file is right —
and the disagreement is a bug in one of them, not a style difference.
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
  then need an explicit `crossorigin` attribute; the doctor catches this.
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
   copy-pasteable diff showing the move, and the migration recipe
   (`grep -rn 'old_key' config/`).
4. README mentions the new name in the configuration reference table.

Do not skip the doctor check — a published `config/pwax.php` is the
application's file, not the package's, so an upgrade cannot rewrite it.

---

## 9. Recipes for adding to the package

These are the most common tasks. Each one names the files to touch and the
ones to leave alone.

### 9.1 Adding a new runtime config key

If a setting needs to reach the client runtime:

1. Add it to `Shell::runtimeConfig()` (`src/Support/Shell.php`). Pick a
   descriptive name; the existing keys are short, not abbreviated.
2. Read it in `src/js/config.js` (extend `DEFAULTS` if the runtime should
   tolerate a missing value).
3. If the new value affects the cache identity — and most settings don't,
   but `service_worker.cache_name`, `assets.versions`, `manifest.*` and
   anything serialised into `sw.json` do — add it to
   `AssetManifest::configurationHash()`'s input list
   (`src/Pwa/AssetManifest.php`). Otherwise the worker will not bust
   cached entries when the value changes.
4. If the new value is part of the public JS API surface, add it to
   `types/pwax.d.ts` under the `Pwax.Config` interface.
5. CHANGELOG entry.

### 9.2 Adding a new Artisan command

1. `src/Console/Commands/{Name}Command.php` extending
   `Illuminate\Console\Command`. Use constructor property promotion for
   dependencies.
2. Register it in `PwaxServiceProvider::register()`'s `$this->commands([…])`
   list (the block immediately after `$this->commands([` in `register()`).
   The list is the only place consumers see it.
3. Set the `$description` property — it appears in `php artisan list`.
4. If the command publishes files, follow the existing pattern (see
   `InstallCommand` for `vendor:publish` calls) and add the new tag to the
   list of publishable tags.

### 9.3 Adding a new cache strategy

1. Add the name to `Mxent\Pwax\Pwa\Strategy` as a class constant.
2. Update `Strategy::resolve()` if the name has an alias.
3. Read the constant in the matching `*_strategy` config reader
   (`src/Support/Shell.php` for `runtime_strategy`, `AssetManifest.php` for
   `navigation_strategy`, etc.).
4. Add the case to the service worker's `fetch` handler
   (`src/js/sw/index.js`).
5. The doctor names unknown strategies; add a friendly check if it deserves
   one.
6. Document in CHANGELOG and update `Strategy`'s class docblock.

### 9.4 Adding a new `window.pwax.*` namespace

1. Add the implementation in `src/js/` (a new file unless the namespace
   naturally lives in an existing one).
2. Wire it into the `window.pwax = { … }` object in `src/js/index.js`.
3. Add the type to `types/pwax.d.ts` under the `Pwax.*` namespace.
4. Update §6 above with the new namespace and a one-line description of
   its purpose.
5. CHANGELOG entry under `### Added` (not `### Internal`).

### 9.5 Adding a component to the JSON catalog

1. Write it as an ordinary component. Children come through **one default
   `<slot />`** — a document cannot address a named one — and whatever it
   declares in `emits` is what a document may bind with `on`.
2. Add it to `pwax.json.components` in `config/pwax.php`. A bare
   reference is enough; the array form adds `description` and `props`,
   which is what constrains a generated document.
3. Nothing else. It is precached by `ComponentRegistry` like any other
   component, and `Shell::json()` resolves the reference with the same
   `moduleEntry()` the `vue.*` groups use.
4. `php artisan pwax:doctor` names a reference that points at no view,
   and a prop `type` the schema builder does not know.

### 9.6 Adding a new prefab shell partial or extension point

The shell ships several partials (`layouts/shell`, `components/includes/head`,
`components/includes/foot`, `components/content`, `components/loader`,
`components/error`) and one named extension stack (`@stack('pwax-head')`).
Adding a new partial:

1. Place the file in `resources/views/` under the appropriate subdir.
2. If it should be auto-included for every page, edit `layouts/shell.blade.php`
   to add the `@include(...)` (read what is there first; the convention is
   to let the consumer add conditional `@includeWhen(View::exists(...))`
   after they have published the shell with `--views`).
3. Document it in `resources/ai/pwax-skill.md` and the README so consumers
   know it exists.

### 9.7 Adding a new runner config key (CLI)

`pwax.assets.{render_functions,node,vue_build}` controls the `pwax:compile`
runner. Adding another option:

1. Document it in `config/pwax.php` with the same prose style as the
   surrounding options.
2. Read it in `CompileCommand` (`src/Console/Commands/CompileCommand.php`).
3. If it changes what the worker caches, add it to the runtime config
   (see §9.1) — but most runner settings do not.

---

## 10. The shell extension surface

The shell is the one place an application can extend the package without
forking it. Three mechanisms, in order of preference:

- **`@stack('pwax-head')`** — push arbitrary content to `<head>` from any
  view or partial. The runtime replaces `data-pwax-head`-marked tags on
  client-side navigations but keeps `@stack` content as-is, so any
  non-`<title>`/`<meta>`/`<link>` head content belongs here.
- **`pwax.blade.{content, head, foot, error, loader}`** — point any of
  these at one of your own Blade views to replace a bundled partial
  without publishing the whole view directory.
- **`php artisan pwax:install --views`** publishes the views into
  `resources/views/vendor/pwax/` so the application can edit them.
  Published views take priority over the package's bundled view of the
  same name; there is no upstream merge. Fork knowingly.

The package's bundled shell does **not** `@includeWhen(View::exists(...))`
anything — that pattern is one a consumer adds once they have published
their own shell.

---

## 11. The doctor categories

`Mxent\Pwax\Console\Commands\DoctorCommand` reports in three bands:

- **`failed`** — something does not work. Manifest target points at a route
  the router does not know, `pwax.middleware` is empty (`pwax:component`
  endpoints would be public), `APP_KEY` is missing so component ids cannot
  be signed. Fix before shipping.
- **`warned`** — something works but should change. Unknown strategy names,
  missing manifest icons (PWA is not installable), CDN assets without SRI
  hashes (the browser will refuse to run them).
- **`info`** — environment-specific. Local asset versions drift from the
  pinned Vue/Vue Router/Pinia versions.

When the doctor says "no problems, N warnings", every warning is a thing
that **still works** but should change. The full check list lives in
`DoctorCommand.php`; do not duplicate it elsewhere.

---

## 12. What NOT to add (the consumer-confusion list)

These show up in application code and PRs against the package. They are
**not** Pwax features, and adding them as if they were is the bug behind
half of the issues that arrive on the maintainer queue:

- **`View::share('pwaxMeta', [...])`** — no view in the package reads
  `$pwaxMeta`. The meta flow goes through
  `ComponentResponse::title/description/canonical/meta/property` and
  `Pwa\HeadMeta::resolve()`. A consumer setting `pwaxMeta` is dead code.
- **Manual `<script src="/__pwax__/pwax.js">` in a published shell** — the
  package adds the runtime itself.
- **Custom registration of `pwax/sw.js` from the application** — let the
  package register it via `serviceWorker.enabled`.
- **`<x-pwax::head>` from inside a page component** — pages do not render
  the shell. Use the fluent API on the response.
- **A new `window.pwax.*` namespace named after the application** — the
  namespace is the package's, not the consumer's. Applications attach
  their own data to `window`, not to `window.pwax`.
- **Custom `@stack('pwax-body')`** — the shell wires up two stacks and
  that is not one of them. `@stack('pwax-head')` renders inside `<head>`
  and `@stack('pwax-foot')` after the vendor scripts at the end of
  `<body>`; content pushed to any other name is silently dropped. For a
  whole partial rather than a push, use the `pwax.blade.foot` override.
- **A `pwax_component()` global helper** — it does not exist (was
  removed). The function is `pwaxImport()` (camelCase), and it is called
  via `@pwaxImport(...)` inside Blade, not as a free helper.

When a contributor reaches for one of these, the answer is **not**
"make it work" — it is "stop and use the existing surface". Most of these
mistakes come from a maintainer or AI assistant who did not read AGENTS.md
before answering.

---

## 13. What the maintainers will push back on

- **Anything that re-templatizes the service worker.** The worker is
  JavaScript in `src/js/`. Blade is for views.
- **Anything that interpolates PHP into JavaScript at runtime.** Add a key
  to `runtimeConfig()`.
- **Anything that adds a global helper without a deprecation cycle.** A
  function in composer's `files` autoload is defined for every application
  that installs the package, whether or not anything references it. Helpers
  ship deprecated first and are removed in the next major.
- **Anything that breaks a public-API test silently.** The test suite is
  the contract. If a change requires changing a test, the change is a
  breaking change and the version bumps.
- **Committing `dist/` unchanged when `src/js/` changed.** CI catches it,
  but it is a signal you forgot the build step.
- **Letting the precompile tests skip in CI.** They need Node and
  `@vue/compiler-dom`, and a suite that skips every test of a feature
  reports the same green tick as one that runs them. The PHP workflow
  installs the dependency and sets `PWAX_REQUIRE_NODE=1`, which turns the
  skip into a failure.
- **Leaving a compiler option to its default in `bin/*.mjs`.** `comments`
  follows whether the compiler is a development build, and `hoistStatic`
  brings `stringifyStatic` with it in the Node build. Precompiling is per
  component and an incomplete store falls back to compiling in the browser,
  so a render function that builds a different DOM from the browser's own
  compiler is a page that changes shape depending on whether `pwax:compile`
  reached it. Every option that affects emitted markup is pinned explicitly.
- **Changing the page component's template without going through
  `src/js/pageTemplate.mjs`.** `PwaxPage` is a fragment: two root-level
  `<template>` blocks on `v-if`/`v-else`, which is what keeps the loader,
  the error screen and the page mutually exclusive. Flatten them and a page
  that errors mid-navigation renders the error screen *and* the stale page
  underneath it. `tests/js/pageTemplate.test.js` asserts the structure.
- **Leaving a published artifact in the testbench skeleton.** `config_path()`
  in a test points inside `vendor/`, and that directory outlives the process:
  a published `config/pwax.php` shadows the package's own defaults for every
  later run. `TestCase::tearDown()` cleans up after every test; add anything
  new that gets published to its `PUBLISHED` list.
- **Adding a bare `import` to `bin/*.mjs` for a package the application may
  not have.** `package.json` is `export-ignore`d from the Composer package,
  so these scripts run inside someone else's `node_modules`. Every optional
  dependency goes through a `try`/`fail()` guard that names the install
  command; an unguarded top-level `await import()` dies before the script
  can report anything, and the PHP side sees only a non-zero exit. Relative
  imports of `src/js/*.mjs` are fine — and must be `.mjs`, or Node resolves
  them against the host application's `package.json` and loads them as
  CommonJS.
- **Adding a new dependency without checking the consumer.** A Laravel
  package is consumed by hundreds of apps; a new hard dependency is a
  surprise to all of them. Talk to the maintainers first.
- **Adding config that is dead code** (`runtimeConfig()` keys with no JS
  reader, PHP config with no consumer, fluent methods with no caller).
  Audit `runtimeConfig()` against `config.<key>` references in `src/js/`
  before merging.
- **Skipping the doctor update when adding a setting.** The doctor is the
  one place a published `config/pwax.php` learns about new keys; if you
  add a config without telling the doctor, an upgrade cannot warn about
  it.

---

## 14. Where to read more

- `.claude/skills/run-workbench/SKILL.md` — how to serve the demo and drive
  it in a real browser. Read it before trying to reproduce anything visual:
  it carries the Composer, Chromium and shutdown details this container
  needs, each of which costs an afternoon to rediscover.
- `README.md` — the user-facing manual; its structure is the user's
  mental model of the package.
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