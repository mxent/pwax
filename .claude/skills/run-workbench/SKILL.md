---
name: run-workbench
description: Launch and drive the pwax workbench in a real browser. Use when asked to run, serve or screenshot the demo app, or to confirm a runtime change works in a browser rather than only in Vitest — page rendering, navigation, scoped styles, the service worker and offline, or a `<PwaxJson>` document. Covers `testbench serve`, the Chromium/Playwright setup this container needs, and the checks worth making.
---

# Running the pwax workbench

`orchestra/testbench` serves a real Laravel application against the package.
`workbench/` is the demo it serves. Vitest runs in jsdom and the PHP suite asserts
markup, so anything about what a browser actually builds — first paint, stylesheet
lifecycle, the service worker, a JSON document rendering — can only be checked here.

## 1. Build first, if you touched `src/js/`

The server reads the committed bundles from `dist/`. A change to `src/js/` that has
not been built is invisible to the browser, and the page you are looking at is the
last build.

```bash
npm run build
```

## 2. Set up once per container

```bash
composer install --no-interaction --prefer-source     # see the note below
php vendor/bin/testbench vendor:publish --tag=pwax-assets --force
php vendor/bin/testbench workbench:sync-skeleton
```

**Composer needs help in this container.** GitHub zipballs return 403 through the
egress proxy, and `GITHUB_TOKEN` is the literal string `proxy-injected`, which
Composer rejects as an invalid OAuth token. Git itself works. So:

```bash
env -u GITHUB_TOKEN -u GH_TOKEN COMPOSER_ALLOW_SUPERUSER=1 \
    composer install --no-interaction --no-progress --prefer-source
```

`phpstan/phpstan`, `laravel/pint` and `larastan/larastan` are distributed only as
zipballs and will still fail. Drop them from `require-dev` to get a working
`vendor/`, restore `composer.json` afterwards, and run those three from a shallow
clone of their repos instead — each ships its phar or extension in-tree.

## 3. Serve

**Use port 8000.** `pwax:doctor` probes `config('app.url')`, which testbench
defaults to `http://localhost:8000`; serve anywhere else and its service-worker
check fails for no reason.

```bash
php vendor/bin/testbench serve --host=127.0.0.1 --port=8000
```

Run it in the background and wait on the socket rather than sleeping:

```bash
until curl -sf -o /dev/null --max-time 2 http://127.0.0.1:8000/; do sleep 2; done
```

**Do not stop it with `pkill -f "testbench serve"`.** The pattern matches the shell
running your own command, so the tool call dies with exit 144 and anything chained
after it — a commit, say — never runs. Kill the listener instead:

```bash
kill "$(lsof -ti tcp:8000)" 2>/dev/null || true
```

## 4. Drive it

Playwright is not a dependency of this repo; install it into the scratchpad. The
container ships Chromium already, so **never run `playwright install`** — the npm
package expects a newer build than the image has and will try to download one.
Point at the installed binary:

```bash
cd "$SCRATCH" && npm install --no-audit --no-fund playwright
```

```js
import { chromium } from '<scratch>/node_modules/playwright/index.mjs';

const browser = await chromium.launch({
    // The image ships build 1194; the npm package wants a newer one. Without this
    // it fails with "Executable doesn't exist at .../chromium_headless_shell-1234".
    executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
});
const page = await browser.newPage({ viewport: { width: 900, height: 1000 } });

// Always collect these. A blank screenshot with a silent console is a lie.
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));

await page.goto('http://127.0.0.1:8000/sample', { waitUntil: 'networkidle' });
await page.screenshot({ path: `${SHOT}/1.png`, fullPage: true });
```

Import jsdom, if a script needs it, from the repo: `/home/user/pwax/node_modules/jsdom/lib/api.js`.
The scratchpad has no copy.

**Look at the screenshot.** Read the PNG back; an assertion that passes against an
empty page is worth nothing.

## 5. What the demo has, and what to check

| Route | Covers |
| --- | --- |
| `/` | cold load with the component inlined — no component fetch before first paint |
| `/about` | A → B → A navigation, one `<style data-pwax-style>` per live component |
| `/elsewhere` | a redirect travelling through the SPA router |
| `/items` | a list fetched after mount, and still there offline |
| `/sample` | half Blade template, half JSON document |
| `/vocabulary` | every prop expression and element key in one document |

Offline is the claim most worth the trouble, and needs the worker warmed first:

```js
await page.goto(`${BASE}/sample`, { waitUntil: 'networkidle' });
await page.waitForFunction(() => navigator.serviceWorker.controller !== null, { timeout: 20000 });
await page.waitForTimeout(3000);           // let the precache settle
await context.setOffline(true);
await page.reload({ waitUntil: 'load' });
```

For `/vocabulary`, every control should do something: the toggle reveals an
AND-gated element, editing the first field moves both the heading (`$template`) and
the initials (`$computed`), the row fields edit in place (`$bindItem`), add and
remove change the list, and Save asks first then writes a status through
`onSuccess`. Select the dialog's buttons by their label — `Confirm` and `Cancel` —
because it injects them above the document's own and any index shifts.

Cancelling a confirmed action logs an unhandled rejection. That is the renderer's,
it is known, and it is not a failure of the page.

## 6. Finally

```bash
php vendor/bin/testbench pwax:doctor
```

Two warnings are deliberate against this demo (no manifest screenshots, pages
cached as visited). Anything else is yours.
