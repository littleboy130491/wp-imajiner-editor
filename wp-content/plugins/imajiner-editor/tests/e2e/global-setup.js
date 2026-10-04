const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );

module.exports = async function () {
	for ( const name of [ 'IMAJINER_E2E_URL', 'IMAJINER_E2E_USERNAME', 'IMAJINER_E2E_PASSWORD', 'IMAJINER_E2E_WP_ROOT' ] ) {
		if ( ! process.env[ name ] ) throw new Error( `Missing environment variable: ${ name }` );
	}
	const url = new URL( process.env.IMAJINER_E2E_URL );
	if ( ! [ 'localhost', '127.0.0.1', '[::1]' ].includes( url.hostname ) ) throw new Error( 'Use a disposable loopback WordPress site.' );
	process.env.IMAJINER_E2E_RUN_ID = 'e2e-' + Date.now();
	const cli = process.env.IMAJINER_E2E_WP_CLI || 'wp';
	const args = [ '--path=' + path.resolve( process.env.IMAJINER_E2E_WP_ROOT ), 'eval-file', path.join( __dirname, 'harness.php' ) ];
	execFileSync( cli, [ ...args, 'setup', process.env.IMAJINER_E2E_RUN_ID ], { stdio: 'inherit' } );
	return async () => { execFileSync( cli, [ ...args, 'cleanup', process.env.IMAJINER_E2E_RUN_ID ], { stdio: 'inherit' } ); };
};
