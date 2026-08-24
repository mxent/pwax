# Vendored frontend runtime

Unmodified production builds, published to `public/vendor/pwax` by
`php artisan vendor:publish --tag=pwax-assets`.

| File | Package | Version | sha384 |
| --- | --- | --- | --- |
| `vue.global.prod.js` | [vue](https://www.npmjs.com/package/vue) | 3.5.41 | `arPHRzOKPl8g3Rbe/cQBWYPnq4HcxfPFSFWD3qvI/hc2XQf+4GkVqkOlWgjN5mD3` |
| `vue.runtime.global.prod.js` | [vue](https://www.npmjs.com/package/vue) | 3.5.41 | `RFxxAeahncPwNwUDUMprS/CVNUxKm7t0wLbqf3HZ+i5rvu2/QS+xB4Lo+eDZ75Fb` |
| `vue-router.global.prod.js` | [vue-router](https://www.npmjs.com/package/vue-router) | 5.2.0 | `bPPzCqx4xLwbRx+Dz7Wg1pyZ2CoP5XkRxCR5yfuA/U/QNsKJ0G7zkbuqzLyQLDSR` |
| `pinia.iife.prod.js` | [pinia](https://www.npmjs.com/package/pinia) | 4.0.2 | `wg8sN8T2ZcZIv5vtyNApjm6zSpZ61ZgJEm5w3TXD7cGzWOhnNcNQkwvK39KIH5tp` |

All are MIT licensed and are redistributed unchanged.

## Why these are vendored rather than loaded from a CDN

A progressive web app that fetches its framework from a third-party CDN cannot start
offline, which defeats the purpose of the package. It also discloses every visitor's IP
address to that CDN on every cold load, and makes availability depend on a host outside
your control. `pwax.assets.source` defaults to `local` for those reasons; CDN mode
remains available and ships with the SRI hashes above.

## Both Vue builds, and which one is served

`vue.global.prod.js` is the default and includes the template compiler, because Pwax
sends templates to the browser as strings and Vue compiles them there. That is what
lets the package have no build step.

`vue.runtime.global.prod.js` has no compiler — 40.6 kB gzipped against 60.7 — and is
served only when `assets.vue_build` is `runtime` **and** `php artisan pwax:compile` has
written render functions for the application's templates. `Shell::vueBuild()` makes that
decision per render, and falls back to the full build whenever the store is empty, so an
application that opts in and forgets to compile is slow rather than broken.

Both are published, because the fallback needs the full build present.

## Load order

`vue-router` and `pinia` are IIFE bundles that read the global `Vue` when they evaluate,
so the Vue build must come first. `Shell::vendorScripts()` emits them in order.

## Updating

```bash
V=3.5.42
for f in vue.global.prod.js vue.runtime.global.prod.js; do
    curl -sSfL "https://cdn.jsdelivr.net/npm/vue@$V/dist/$f" -o "$f"
    printf '%s sha384-%s\n' "$f" "$(openssl dgst -sha384 -binary "$f" | openssl base64 -A)"
done
```

Both Vue builds must move together: a render function compiled by one version's compiler
is not guaranteed to run on another's runtime, which is why `pwax:compile` refuses to run
when the installed `@vue/compiler-dom` does not match `assets.versions.vue`.

Then update `assets.versions` and `assets.cdn.integrity` in `config/pwax.php`, and this
table. Check Vue Router's `peerDependencies` before bumping Vue across a minor.
