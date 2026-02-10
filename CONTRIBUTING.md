# Contributing to PWax

First off, thank you for considering contributing to PWax! It's people like you that make PWax such a great tool.

## Code of Conduct

This project and everyone participating in it is governed by our commitment to maintain a welcoming and inclusive environment. By participating, you are expected to uphold this code.

## How Can I Contribute?

### Reporting Bugs

Before creating bug reports, please check the existing issues to avoid duplicates. When you create a bug report, include as many details as possible:

- **Use a clear and descriptive title**
- **Describe the exact steps to reproduce the problem**
- **Provide specific examples** to demonstrate the steps
- **Describe the behavior you observed** and what you expected to see
- **Include screenshots or code snippets** if relevant
- **Specify your environment**: PHP version, Laravel version, operating system

### Suggesting Enhancements

Enhancement suggestions are tracked as GitHub issues. When creating an enhancement suggestion, include:

- **Use a clear and descriptive title**
- **Provide a detailed description** of the suggested enhancement
- **Explain why this enhancement would be useful**
- **List any alternatives you've considered**

### Pull Requests

1. **Fork the repository** and create your branch from `main`
2. **Follow the coding standards** used throughout the project
3. **Add tests** if you're adding functionality
4. **Update documentation** if you're changing functionality
5. **Ensure the test suite passes** before submitting
6. **Write a good commit message** describing your changes

#### Coding Standards

- Follow PSR-12 coding standards for PHP
- Use meaningful variable and function names
- Add comments for complex logic
- Keep functions focused and concise
- Write clear commit messages

#### Testing

Before submitting your pull request:

```bash
# Run tests
composer test

# Check code style
composer cs-check

# Fix code style automatically
composer cs-fix
```

#### Commit Messages

- Use the present tense ("Add feature" not "Added feature")
- Use the imperative mood ("Move cursor to..." not "Moves cursor to...")
- Limit the first line to 72 characters or less
- Reference issues and pull requests liberally after the first line

Example:
```
Add validation for view names

- Prevents path traversal attacks
- Validates against whitelist pattern
- Adds proper error handling

Fixes #123
```

## Security Vulnerabilities

**DO NOT** open public issues for security vulnerabilities. Instead, email security concerns to `opensource@mxent.com`. We will address them promptly.

## Development Setup

1. Clone the repository:
```bash
git clone https://github.com/mxent/pwax.git
cd pwax
```

2. Install dependencies:
```bash
composer install
```

3. Run tests:
```bash
composer test
```

## Project Structure

```
pwax/
├── src/               # Source code
│   └── Http/
│       └── Controllers/
├── config/            # Configuration files
├── resources/         # Views and assets
│   └── views/
├── routes/            # Route definitions
├── tests/             # Test suite
└── helpers.php        # Helper functions
```

## Questions?

Feel free to open an issue with your question, or reach out to the maintainers at `opensource@mxent.com`.

## Attribution

This Contributing guide is adapted from the open-source contribution guidelines template.
