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
        },
    },
    {
        files: ['build.js'],
        languageOptions: { globals: { ...globals.node } },
        rules: { 'no-console': 'off' },
    },
];
