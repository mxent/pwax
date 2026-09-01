import globals from 'globals';

export default [
    {
        files: ['src/js/**/*.js', 'build.js'],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'module',
            globals: {
                ...globals.browser,
                Vue: 'readonly',
                VueRouter: 'readonly',
                Pinia: 'readonly',
                __PWAX_VERSION__: 'readonly',
            },
        },
        rules: {
            'no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
            'no-console': ['warn', { allow: ['warn', 'error'] }],
            'no-var': 'error',
            'prefer-const': 'error',
            eqeqeq: ['error', 'smart'],
            // A free variable in browser code does not throw where you would expect it
            // to. The DOM defines a great many globals — `Comment`, `Option`, `Event`,
            // `name`, `status` — so a mistyped or unimported identifier often resolves
            // to one of them and the code quietly does the wrong thing instead of
            // failing. That is not hypothetical here: `src/js/json/index.js` compares a
            // vnode against Vue's `Comment`, and an import that never landed left the
            // comparison running against the DOM interface, silently.
            'no-undef': 'error',
        },
    },
    {
        // The worker reports two things at `info`: how many URLs an install deliberately
        // did not store, and that a deploy landed mid-install. Neither is a problem, and
        // logging them as warnings would make a correct install look like a broken one.
        files: ['src/js/sw/**/*.js'],
        rules: { 'no-console': ['warn', { allow: ['info', 'warn', 'error'] }] },
    },
    {
        // Node scripts, not browser code: the build, and the optional template compiler
        // `pwax:compile` shells out to. Both write to stdout on purpose.
        files: ['build.js', 'bin/**/*.mjs'],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'module',
            globals: { ...globals.node },
        },
        rules: {
            'no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
            'no-console': 'off',
            'no-var': 'error',
            'prefer-const': 'error',
            eqeqeq: ['error', 'smart'],
        },
    },
    {
        // The suites run under Vitest in jsdom, and the service-worker harness reaches for
        // node's own APIs to read and sandbox the worker — so both sets of globals apply.
        // Linted deliberately: the harness is real code, and the worker it exercises is the
        // largest body of JavaScript in the package.
        files: ['tests/js/**/*.js'],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'module',
            // The same globals the runtime is linted against: a test stubs `Vue` and
            // reads it back, and the runtime under test resolves it off `window`.
            globals: {
                ...globals.browser,
                ...globals.node,
                Vue: 'readonly',
                VueRouter: 'readonly',
                Pinia: 'readonly',
            },
        },
        rules: {
            'no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
            'no-var': 'error',
            'prefer-const': 'error',
            eqeqeq: ['error', 'smart'],
            'no-undef': 'error',
        },
    },
];
