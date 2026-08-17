# Maintaining Pwax

For maintainers. [CONTRIBUTING.md](CONTRIBUTING.md) covers making a change; this covers
keeping the package correct across releases, dependency updates and security reports.

Everything here exists because the package has an unusual property: **it ships built
JavaScript, a vendored frontend and a documented config surface inside a Composer
package.** A Laravel developer installing `mxent/pwax` never runs Node, never runs a
bundler, and never reads `src/js/`. That is the whole point, and it is also the reason
several things have to be kept in agreement by hand. The invariants below are what "in
agreement" means; where one can be checked automatically, it is, and the test is named.

---

## 1. The invariants

| Invariant | Enforced by |
| --- | --- |
| `dist/` matches `src/js/` | `tests.yml` → `npm run build && git diff --exit-code -- dist/` |
| SRI hashes match the vendored builds | `tests/Unit/VendoredAssetsTest.php` |
| `resources/vendor/README.md` versions match `config/pwax.php` | `tests/Unit/VendoredAssetsTest.php` |
| JS header constants match `Pwax::HEADER` / `LOCATION_HEADER` | `tests/Feature/HeaderConstantsTest.php` |
| Every config key is read by something | Not automated — see §6 |
| `resources/ai/pwax-skill.md` describes the current API | Not automated — see §5 |

The unautomated two are the ones that rot. Check them at release time.

---

## 2. Releasing

Pwax follows semantic versioning. The client runtime's version is stamped into the bundle
at build time from `package.json`, and reported at runtime as `window.pwax.version`.

```bash
# 1. Bump the runtime version and rebuild, so the banner and __PWAX_VERSION__ agree.
npm version <major|minor|patch> --no-git-tag-version
npm run build

# 2. Full check, both halves.
composer check          # pint --test, phpstan, phpunit
npm run lint && npm run format:check && npm run types && npm test

# 3. Changelog and, for a breaking change, the upgrade guide.
$EDITOR CHANGELOG.md UPGRADE.md

# 4. Commit, tag, push.
git commit -am "Release vX.Y.Z"
git tag vX.Y.Z
git push origin main --tags
```

Notes:

- **`package.json` is `"private": true` and is never published to npm.** The version there
  exists only to stamp the bundle. The Composer package is the release; the git tag is
  what Packagist reads.
- **The tag must point at a commit whose `dist/` is current.** A tag with a stale bundle
  ships a runtime that does not match its own source, and because consumers never build,
  nothing downstream will ever correct it. Rebuild *before* tagging, not after.
- The PHP package version is not written anywhere in the repository — `composer.json` has
  no `version` key, deliberately, so the tag is the single source of truth.
  `PwaxServiceProvider::bootAbout()` reads it back through `Composer\InstalledVersions`.

---

## 3. Updating the vendored frontend

`resources/vendor/` holds unmodified production builds of Vue, Vue Router and Pinia. The
procedure is in [resources/vendor/README.md](resources/vendor/README.md); what matters
here is the order, because three files have to move together:

1. Replace the build(s) in `resources/vendor/`.
2. Update `assets.versions` in `config/pwax.php`.
3. Update `assets.cdn.integrity` in `config/pwax.php` with fresh sha384 hashes.
4. Update the table in `resources/vendor/README.md`.
5. `vendor/bin/phpunit --filter VendoredAssetsTest`

Step 5 fails if you missed any of 2–4. It cannot tell you that you forgot step 1, so do
that one first.

**Both Vue builds move together, always.** `assets.vue_build => 'runtime'` serves the
compiler-less build against render functions produced by `pwax:compile`, and a render
function compiled by one version's compiler is not guaranteed to run on another version's
runtime. `pwax:compile` refuses to run when the installed `@vue/compiler-dom` does not
match `assets.versions.vue`, which is the guard — keep `package.json`'s
`@vue/compiler-dom` in step with the vendored Vue or that guard starts firing on a
correct setup.

Check Vue Router's `peerDependencies` before bumping Vue across a minor.

---

## 4. Widening the Laravel support window

When a new Laravel major is released:

1. Add it to every `illuminate/*` constraint in `composer.json`.
2. Add the matching `orchestra/testbench` major to `require-dev`.
3. Add the version to the matrix in `.github/workflows/tests.yml`, **including** the
   `Constrain Laravel version` step, which maps a Laravel major to a Testbench major and
   is easy to miss — the matrix will otherwise test the new Laravel against the old
   Testbench and pass for the wrong reason.
4. Note the minimum PHP the new Laravel requires and add the matching `exclude:` entry.

Dropping a Laravel major is a breaking change: it needs a major version, an `UPGRADE.md`
entry and a note in the changelog.

---

## 5. Keeping the skill honest

`resources/ai/pwax-skill.md` is published into applications by `php artisan pwax:skill`,
where an AI assistant reads it before touching a Pwax project. A stale skill is worse than
no skill: it produces confident, wrong code that looks like it came from the docs.

Re-read it against the diff whenever a release changes any of:

- a config key's name, default or meaning;
- the shape of a component (`<template>` / `<script>` / `<style>` and what is allowed in
  each);
- a Blade directive or a helper's signature;
- the `window.pwax.*` surface, which is also `types/pwax.d.ts` and must move with it;
- an artisan command's name, arguments or output;
- a `pwax:doctor` check, since the skill tells assistants how to act on its warnings.

The skill's front-matter `description` is what decides whether an assistant loads it at
all. If a release adds a capability someone would ask for by name — a new directive, a new
command — add the trigger word there too, or the skill will be right and unread.

---

## 6. Config surface

`config/pwax.php` is published into applications, so a key that is removed or renamed
breaks applications that set it, and a key that is added but never read is a promise the
package does not keep.

Before a release, confirm every key is still read by something:

```bash
php -r 'function env($k,$d=null){return $d;}
$walk=function($a,$p)use(&$walk,&$out){foreach($a as $k=>$v){if(is_int($k))continue;
$key=$p===""?$k:"$p.$k";$out[]=$key;if(is_array($v))$walk($v,$key);}};
$out=[];$walk(require "config/pwax.php","");echo implode("\n",$out);' \
  | while read k; do
      grep -rqF "$k" src routes resources || echo "unreferenced: $k";
    done
```

Two families report as unreferenced and are expected to:

- `manifest.*` — the Web App Manifest is emitted wholesale by `WebManifest::toArray()`, so
  a member the specification gains needs no code change. Adding one here is correct.
- `assets.versions.*` and `assets.cdn.integrity.*` — read by key at runtime in
  `Shell::frameworkScripts()` and `Shell::cdnScript()`.

Anything else in that output is a real dead key: either wire it up or delete it, and if
you delete it, say so in `UPGRADE.md`.

Removing or renaming a key is a breaking change. Prefer reading the old key as a fallback
for one major version and warning through `pwax:doctor`.

---

## 7. Security reports

Reports arrive at `opensource@mxent.com` — see [SECURITY.md](SECURITY.md). They must never
be triaged in the public tracker.

The parts of this package where a report is most likely to be real, and what each one
protects:

- **`Support/ComponentId`** — the HMAC over view names. This is the single control that
  stops `/__pwax__/c/{id}.js` from rendering arbitrary Blade templates in the host
  application. Treat any bypass as critical.
- **`Pwa/PublicAssets::DENY`** — a precache entry is a URL the browser is *told to go and
  fetch*, so anything that gets a `.env`, a `.git/` file or a `.php` source past this list
  is a disclosure, not a caching bug.
- **`Pwa/Glob`** — the compiled expressions run in the service worker against paths taken
  from the URL. Catastrophic backtracking here is a denial of service on the worker's only
  thread; see the note on `collapse()`.
- **`Http/Controllers/PwaxController::harden()`** — the response headers every endpoint
  carries.
- **Anywhere the CSRF token travels.** It rides in `http.headers()`, which means it
  reaches the push endpoint (`src/js/push.js`, guarded to same-origin) and is persisted
  into Cache Storage by the offline queue (`src/js/sync.js`). A change that widens where
  those headers are sent needs the same-origin check widened deliberately, not silently.

Fix on a private branch, release the patch, then publish the advisory. Backport to the
previous major if it is still inside its support window.

---

## 8. Things that look like bugs and are not

Worth knowing before you "fix" one of them:

- **`dist/` is committed.** It has to be; consumers have no build step.
- **`pwax.components.directive` may not be `import`.** Blade matches a directive with no
  arguments, so `@import` would swallow the CSS at-rule in every `<style>` block in the
  application. `PwaxServiceProvider::assertDirectiveName()` rejects it.
- **`routes.static_middleware` deliberately excludes `web`.** The runtime, manifest and
  worker are identical for every visitor; adding `web` would start a session and set a
  cookie on requests that have no use for either.
- **The offline shell is a route, not `precache => ['/']`.** Precaching a real application
  URL stores one signed-in user's HTML on a shared device.
- **The service worker embeds the manifest hash.** A worker whose bytes never change is a
  worker the browser never replaces, so a deploy would not reach existing installs.
- **A page payload ships `script` inline and has no `module` URL.** A view rendered with
  controller data cannot be re-derived from its name alone.
