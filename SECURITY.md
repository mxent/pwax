# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |

## Reporting a Vulnerability

**Do not open a public issue for security vulnerabilities.**

Email `opensource@mxent.com` with:

- a description of the issue and its impact,
- the affected version(s),
- reproduction steps or a proof of concept,
- any suggested remediation.

You will receive an acknowledgement within 3 business days and a substantive
response within 10 business days. We ask that you allow 90 days for a fix to ship
before public disclosure. Credit is given in the changelog unless you prefer
otherwise.

## Security model

Pwax renders Blade views and returns their `<template>`, `<script>` and `<style>`
blocks to the browser, where Vue compiles them at runtime. That places a few
responsibilities on both the package and the application using it.

### What the package guarantees

- **Component identifiers are signed.** Every component URL carries an HMAC derived
  from `APP_KEY`. Only view names the server itself emitted can be requested, so an
  attacker cannot enumerate or dump arbitrary Blade views through `/__pwax__/*`.
  Identifiers are verified with `hash_equals` before any view is resolved.
- **View names are validated** against a strict character allowlist and rejected if
  they contain path traversal sequences.
- **Errors are not leaked.** Exception messages are logged server-side; clients
  receive a generic message.
- **Component routes run through your configured middleware stack** (`web` by
  default), so authentication and authorisation apply exactly as they do to any
  other route.

### What your application is responsible for

- **Never interpolate user input into `pwax.vue.plugins`, `pwax.vue.directives` or
  `pwax.vue.middleware`.** Nothing there is evaluated as code — an entry is either a
  component module URL or a dotted path walked on `window` — but a path that reaches an
  attacker-controlled object is still an attacker-supplied plugin. They are configuration,
  not data.
- **Never interpolate unescaped user input into a component's `<template>`.** Blade's
  `{{ }}` escapes; `{!! !!}` does not. Vue's `v-html` does not either.
- **Deploy a Content-Security-Policy.** See the CSP section of the README — Vue's
  in-browser template compiler requires `script-src 'unsafe-eval'`, and the package
  supports a nonce for its inline `<style>` block.
- **Keep `APP_KEY` secret and stable.** Rotating it invalidates all previously
  emitted component identifiers (clients recover on their next full page load).
