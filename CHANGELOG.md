# Changelog

All notable changes to `mxent/pwax` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- True PWA support: dynamically served Web App Manifest (`/manifest.webmanifest`)
  and configurable service worker (`/service-worker.js`), with automatic
  registration from the bundled bootstrap script.
- Configurable cache (`pwax.cache.asset_ttl`) and ETag/304 support for served
  `.js` and `.css` component assets.
- `pwax:install` Artisan command for one-step publishing of config and views.
- New publish tag `pwax-service-worker` to publish just the worker template.
- Helper utilities `pwaxExtractBlocks`, `pwaxMinifyJs`, `pwaxMinifyCss`,
  `pwaxValidateViewName` for safer/reusable parsing.
- Feature + unit tests now run on Orchestra Testbench (20 tests, all passing).
- CSRF token automatically forwarded with PWA requests when a
  `<meta name="csrf-token">` tag is present.
- `theme-color` and `<link rel="manifest">` automatically rendered in the
  bundled head include.

### Changed
- `vue()` and the controller now extract multiple `<script>` / `<style>`
  blocks and tolerate arbitrary attributes (e.g. `lang="js"`).
- `router()` now defaults to relative paths and only returns absolute URLs
  when explicitly requested via the third argument.
- `import()` helper now JSON-encodes arguments for safer JS emission.
- Service provider now merges configuration in `register()` and only
  registers publishing hooks when running in the console.
- `<script src="...">` and `<link href="...">` references are now reliably
  separated from inline blocks during parsing.

### Fixed
- Minification failures no longer break component delivery; the raw source
  is returned with a warning logged.
- Aborted fetches are correctly distinguished from network errors in the
  client-side loader; the loading spinner now always clears on error.
- The bundled `main.blade.php` script handles bootstrap errors and a missing
  `#pwax` mount element gracefully.

### Security
- Centralised view-name validation prevents path traversal and rejects
  unexpected characters before any view rendering.
- Error responses no longer leak internal exception messages to clients.

## [1.0.0] - Initial Release

### Added
- Vue 3 integration with Laravel.
- Vue Router support for SPA navigation.
- Pinia state management integration.
- Dynamic component loading via AJAX.
- Automatic JS/CSS minification.
- Customizable configuration.
- Hot module injection.
- Template parsing from Blade views.
- Support for custom plugins, directives, and middleware.

[Unreleased]: https://github.com/mxent/pwax/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/mxent/pwax/releases/tag/v1.0.0
