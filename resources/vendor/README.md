# Vendored frontend runtime

Unmodified production builds, published to `public/vendor/pwax` by
`php artisan vendor:publish --tag=pwax-assets`.

| File | Package | Version | sha384 |
| --- | --- | --- | --- |
| `vue.global.prod.js` | [vue](https://www.npmjs.com/package/vue) | 3.5.41 | `arPHRzOKPl8g3Rbe/cQBWYPnq4HcxfPFSFWD3qvI/hc2XQf+4GkVqkOlWgjN5mD3` |
| `vue-router.global.prod.js` | [vue-router](https://www.npmjs.com/package/vue-router) | 5.2.0 | `bPPzCqx4xLwbRx+Dz7Wg1pyZ2CoP5XkRxCR5yfuA/U/QNsKJ0G7zkbuqzLyQLDSR` |
| `pinia.iife.prod.js` | [pinia](https://www.npmjs.com/package/pinia) | 4.0.2 | `wg8sN8T2ZcZIv5vtyNApjm6zSpZ61ZgJEm5w3TXD7cGzWOhnNcNQkwvK39KIH5tp` |

All three are MIT licensed and are redistributed unchanged.

## Why these are vendored rather than loaded from a CDN

A progressive web app that fetches its framework from a third-party CDN cannot start
offline, which defeats the purpose of the package. It also discloses every visitor's IP
address to that CDN on every cold load, and makes availability depend on a host outside
your control. `pwax.assets.strategy` defaults to `local` for those reasons; CDN mode
remains available and ships with the SRI hashes above.

## Why `vue.global.prod.js` and not `vue.runtime.global.prod.js`

Pwax sends templates to the browser as strings and Vue compiles them there, so the full
build — which includes the template compiler — is required. The runtime-only build is
smaller but cannot compile a template and will fail at mount.

## Load order

`vue-router` and `pinia` are IIFE bundles that read the global `Vue` when they evaluate,
so `vue.global.prod.js` must come first. `Shell::vendorScripts()` emits them in order.

## Updating

```bash
V=3.5.42
curl -sSfL "https://cdn.jsdelivr.net/npm/vue@$V/dist/vue.global.prod.js" -o vue.global.prod.js
openssl dgst -sha384 -binary vue.global.prod.js | openssl base64 -A
```

Then update `assets.versions` and `assets.cdn.integrity` in `config/pwax.php`, and this
table. Check Vue Router's `peerDependencies` before bumping Vue across a minor.
