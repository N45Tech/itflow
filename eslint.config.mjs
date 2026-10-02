import js from '@eslint/js';
import globals from 'globals';

// These functions are supplied by classic scripts and called by PHP views or peers.
const publicHelpers = {
    'js/app.js': ['itflowReady', 'itflowTinyMceSkin', 'itflowNormalizeModalControls'],
    'js/http.js': ['itflowGet', 'itflowPost', 'itflowPostForm'],
    'js/autocomplete.js': ['itflowAutocomplete', 'itflowEscapeHtml'],
    'js/tom_select.js': ['initTomSelect', 'setTomSelectValue'],
    'js/phone_inputs.js': ['initPhoneInputs'],
};

export default [{
    name: 'n45/browser-scripts',
    files: ['js/*.js'],
    languageOptions: {
        ecmaVersion: 2022,
        sourceType: 'script',
        globals: {
            ...globals.browser,
            bootstrap: 'readonly',
            Chart: 'readonly',
            tinymce: 'readonly',
            TomSelect: 'readonly',
            Stripe: 'readonly',
            DataTable: 'readonly',
            flatpickr: 'readonly',
            IMask: 'readonly',
            ClipboardJS: 'readonly',
            Swal: 'readonly',
            ...Object.fromEntries(Object.values(publicHelpers).flat().map(name => [name, 'readonly'])),
        },
    },
    linterOptions: { noInlineConfig: true },
    rules: {
        ...js.configs.recommended.rules,
        // Classic scripts expose top-level helpers to other scripts and PHP views.
        'no-unused-vars': ['error', { vars: 'local', args: 'after-used', caughtErrors: 'none' }],
    },
}, ...Object.entries(publicHelpers).map(([file, names]) => ({
    name: 'n45/provided-helpers/' + file,
    files: [file],
    languageOptions: { globals: Object.fromEntries(names.map(name => [name, 'off'])) },
}))];
