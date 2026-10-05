const browser = Object.fromEntries( [ 'window', 'document', 'fetch', 'console', 'URL', 'URLSearchParams', 'DOMParser', 'setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'requestAnimationFrame', 'cancelAnimationFrame', 'CustomEvent', 'Event', 'MouseEvent', 'MutationObserver', 'ResizeObserver', 'getComputedStyle', 'navigator', 'location', 'HTMLElement', 'Node', 'CSS', 'performance', 'structuredClone', 'AbortController', 'wp', 'imajinerEditorData', 'imajinerPreview', 'imajinerBuilderAI', 'imajinerEditorAI', 'imajinerDesignSystem', 'imajinerSettings', 'imajinerBlockEditor', 'imajinerEditor' ].map( ( name ) => [ name, 'readonly' ] ) );
const node = Object.fromEntries( [ 'require', 'module', '__dirname', 'process', 'Buffer', 'console', 'setTimeout', 'clearTimeout' ].map( ( name ) => [ name, 'readonly' ] ) );

export default [
	{ ignores: [ '**/node_modules/**', '**/test-results/**', '**/playwright-report/**' ] },
	{ files: [ '**/*.js', '**/*.mjs' ], languageOptions: { ecmaVersion: 2022, sourceType: 'script', globals: browser }, rules: { 'no-undef': 'error' } },
	{ files: [ '**/tests/*.js', '**/tests/e2e/*.js' ], languageOptions: { globals: node } },
];
