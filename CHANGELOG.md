# Changelog

All notable changes to `mxent/pwax` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Comprehensive error handling for view rendering and file operations
- Input validation to prevent path traversal attacks
- Cache headers for JS/CSS responses (1 hour cache)
- Enhanced README with detailed usage examples and security best practices
- CHANGELOG.md for tracking version history
- CONTRIBUTING.md with contribution guidelines
- Improved .gitignore with common IDE and build artifact patterns

### Security
- Fixed path traversal vulnerability in PwaxController
- Added validation for view names to prevent arbitrary file access
- Enhanced error messages that don't expose sensitive information

### Changed
- Improved error handling with try-catch blocks throughout the codebase
- Better validation of regex matches before array access

## [1.0.0] - Initial Release

### Added
- Vue 3 integration with Laravel
- Vue Router support for SPA navigation
- Pinia state management integration
- Dynamic component loading via AJAX
- Automatic JS/CSS minification
- Customizable configuration
- Hot module injection
- Template parsing from Blade views
- Support for custom plugins, directives, and middleware

[Unreleased]: https://github.com/mxent/pwax/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/mxent/pwax/releases/tag/v1.0.0
