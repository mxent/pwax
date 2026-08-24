# Contributing to Pwax

Thanks for taking the time. Bug reports, questions and pull requests are all welcome.

**Security issues do not belong in the tracker.** Email `opensource@mxent.com` — see
[SECURITY.md](SECURITY.md).

## Development setup

PHP is enough to work on the server side. Node is only needed if you touch the client
runtime under `src/js/`.

```bash
git clone https://github.com/mxent/pwax.git
cd pwax

composer install
composer check          # Pint, PHPStan, PHPUnit

npm ci                  # only for src/js work
npm run lint && npm test
```

## Project layout

```
src/
  Compiler/        Blade output → Component: block extraction, style scoping, stamping
  Console/         Artisan commands
  Contracts/       Minifier interface
  Data/            Component and Head value objects
  Events/          ComponentCompiled, ManifestBuilt
  Exceptions/
  Facades/
  Http/            Controller, middleware, ComponentResponse
  Minification/    matthiasmullie, null and caching decorators
  Pwa/             Manifests, registries, service worker, glob matching
  Support/         ComponentId (signing), Shell (asset and config assembly)
  js/              Client runtime source → dist/pwax.js
  js/sw/           Service worker source → dist/pwax-sw.js
  Pwax.php         Public API
  helpers.php      pwax(), pwax_component(), pwax_route()
bin/               compile-templates.mjs, driven by `pwax:compile`
config/            Published configuration
types/             Hand-written pwax.d.ts for the runtime's public surface
resources/
  ai/              pwax-skill.md — published by `pwax:skill`
  views/           Shell, partials, offline document, push example
  vendor/          Vendored Vue, Vue Router, Pinia (see its README)
routes/
dist/              Built client runtime and worker — COMMITTED, see below
tests/
  Unit/            No framework boot
  Feature/         Orchestra Testbench
  fixtures/views/  Component fixtures
  js/              Vitest
```

Maintainers: see [MAINTAINING.md](MAINTAINING.md) for releasing, updating the vendored
frontend builds, widening the Laravel support window and handling security reports.

## The `dist/` directory is committed

`dist/pwax.js` ships inside the Composer package, so a Laravel developer never needs
Node. That means **if you change anything in `src/js/`, you must rebuild and commit**:

```bash
npm run build
git add dist/
```

CI runs `npm run build && git diff --exit-code -- dist/` and fails if the committed
bundle does not match the source.

## Standards

- **PHP:** PSR-12 via Laravel Pint. Run `composer lint:fix`.
- **Static analysis:** PHPStan (with Larastan) at level 8, must pass clean. Run
  `composer analyse`.
- **JavaScript:** ESLint and Prettier. Run `npm run lint:fix && npm run format`.
- **Tests:** `snake_case` method names in PHP, `it('does something')` in Vitest.

### Comments

Comment the *why*, not the *what*. The reader can see that a loop iterates; what they
cannot see is which failure it exists to prevent. Much of this codebase carries notes
naming the alternative that was rejected and what goes wrong with it — that context is
the point, so please keep it accurate when you change the surrounding code.

## Tests

Every behaviour change needs a test. When fixing a bug, write the test that fails first
and say in a comment what the failure was.

```bash
composer test
vendor/bin/phpunit --filter=ComponentId
vendor/bin/phpunit tests/Feature/ComponentRoutesTest.php

npm test
npx vitest --watch
```

Unit tests must not boot Laravel. Feature tests extend `Mxent\Pwax\Tests\TestCase`, which
adds `tests/fixtures/views` to the view finder — put new component fixtures there.

## Manual verification

`orchestra/testbench` serves a real application against the package:

```bash
php vendor/bin/testbench vendor:publish --tag=pwax-assets --force   # once
php vendor/bin/testbench workbench:sync-skeleton                    # once, for the icons
php vendor/bin/testbench serve
```

`testbench.yaml` registers the provider and `workbench/` holds the demo it serves: three
pages with scoped styles, a component pulled in with `@pwaxImport` and held in `data()`,
a route that redirects, a page whose list arrives from a `fetch` after mount, and the
service worker on. Between them they cover the checks below.
`php vendor/bin/testbench pwax:doctor` reports a handful of warnings against it and no
problems; the warnings are deliberate.

Worth doing by hand for anything touching the runtime or the shell, because the test
suites cannot see any of it — Vitest runs in jsdom, and the PHP suite asserts markup
rather than what a browser builds from it:

1. A cold load renders with **no** component fetch before first paint.
2. Navigating A → B → A leaves exactly one `<style data-pwax-style>` per live component.
3. `/elsewhere` redirects through the SPA router rather than throwing.
4. DevTools offline mode still boots the app, and still navigates between pages.
5. `/items` still shows its list with DevTools offline, on a second visit.

The last two are the ones worth the trouble. Offline is the claim this package makes that
is hardest to check by reading code, and the failure is silent: everything works on the
machine that has the network.

## Pull requests

1. Branch from `main`.
2. Keep the change focused; unrelated refactors belong in their own PR.
3. Update `README.md` and `CHANGELOG.md`.
4. Make sure `composer check` and `npm test` pass.
5. Rebuild `dist/` if `src/js/` changed.

### Commit messages

Imperative mood, present tense, first line under 72 characters:

```
Reference-count injected component styles

Navigating away removed every element marked `pwax-attached`, including
styles belonging to imported components that were still mounted.

Fixes #123
```

## Breaking changes

Pwax follows semantic versioning. A breaking change needs a clear justification, a
`CHANGELOG.md` entry under a new major, and — where it is possible — a deprecation path.
If a compatible fix exists, prefer it.
