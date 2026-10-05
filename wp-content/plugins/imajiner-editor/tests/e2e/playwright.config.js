const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: '.',
	testMatch: '*.spec.js',
	fullyParallel: false,
	workers: 1,
	retries: 0,
	timeout: 90000,
	expect: { timeout: 20000 },
	globalSetup: './global-setup.js',
	use: {
		baseURL: process.env.IMAJINER_E2E_URL,
		browserName: 'chromium',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
	},
	reporter: [ [ 'list' ], [ 'html', { open: 'never' } ] ],
} );
