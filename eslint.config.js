import js from '@eslint/js';
import prettier from 'eslint-config-prettier';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import tseslint from 'typescript-eslint';

export default tseslint.config(
    {
        ignores: [
            'public/**',
            'vendor/**',
            'node_modules/**',
            'bootstrap/**',
            'storage/**',
        ],
    },
    js.configs.recommended,
    ...tseslint.configs.recommended,
    {
        files: ['resources/js/**/*.{ts,tsx}'],
        plugins: { 'react-hooks': reactHooks },
        languageOptions: {
            globals: {
                ...globals.browser,
                // Ziggy stellt route() global bereit.
                route: 'readonly',
            },
        },
        rules: {
            ...reactHooks.configs.recommended.rules,
            // Pragmatischer Einstieg: stilistisch unkritische Punkte als Warnung,
            // damit CI gruen bleibt; schrittweise verschaerfen.
            '@typescript-eslint/no-explicit-any': 'warn',
            '@typescript-eslint/no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
        },
    },
    prettier,
);
