<?php
/** wp eval-file harness.php setup|cleanup e2e-<timestamp>; CLI-only disposable fixture. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'IMAJINER_E2E_TEST_SITE' ) || true !== IMAJINER_E2E_TEST_SITE || 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true ) ) {
	WP_CLI::error( 'Requires an explicitly opted-in, disposable local WordPress site.' );
}
if ( 'imajiner' !== get_template() || ! is_child_theme() || ! class_exists( 'Imajiner_Generation' ) ) {
	WP_CLI::error( 'Activate the Imajiner child theme and editor plugin first.' );
}
$operation = $args[0] ?? '';
$run = $args[1] ?? '';
if ( ! preg_match( '/^e2e-[0-9]{10,20}$/D', $run ) ) { WP_CLI::error( 'Invalid disposable run ID.' ); }
$file = imajiner_design_tokens_file();
$mock = WPMU_PLUGIN_DIR . '/imajiner-e2e-provider.php';
$fixture = get_option( 'imajiner_e2e_fixture' );
if ( 'setup' === $operation ) {
	if ( $fixture || file_exists( $mock ) ) { WP_CLI::error( 'An existing E2E fixture needs cleanup first; never overwrite it.' ); }
	$fixture = array( 'run' => $run, 'theme' => get_stylesheet(), 'settings' => get_option( Imajiner_AI::OPTION, null ), 'tokens' => is_file( $file ) ? file_get_contents( $file ) : null, 'posts' => array() );
	if ( ! wp_mkdir_p( WPMU_PLUGIN_DIR ) || ! copy( __DIR__ . '/provider-mock.php', $mock ) ) { WP_CLI::error( 'Cannot install the local provider mock.' ); }
	update_option( 'imajiner_e2e_fixture', $fixture, false );
	update_option( Imajiner_AI::OPTION, array( 'primary' => array( 'provider' => 'openai', 'model' => 'imajiner-e2e-mock' ), 'fallback' => array( 'provider' => '', 'model' => '' ) ), false );
	WP_CLI::success( 'Local deterministic provider enabled for ' . $run );
} elseif ( 'cleanup' === $operation ) {
	if ( ! is_array( $fixture ) || $fixture['run'] !== $run || $fixture['theme'] !== get_stylesheet() ) { WP_CLI::error( 'Fixture owner/theme mismatch. Nothing was deleted.' ); }
	foreach ( array_unique( $fixture['posts'] ) as $id ) {
		wp_clear_scheduled_hook( 'imajiner_ai_run_job', array( $id ) );
		wp_clear_scheduled_hook( 'imajiner_ai_expire_job', array( $id ) );
		if ( 'imajiner_ai_job' === get_post_type( $id ) ) { Imajiner_AI_Jobs::expire( $id ); }
		wp_delete_post( $id, true );
	}
	foreach ( glob( get_stylesheet_directory() . '/imajiner/' . $run . '*.php' ) as $path ) {
		unlink( $path );
		$css = Imajiner_Template_Store::css_path( $path );
		if ( is_file( $css ) ) { unlink( $css ); }
	}
	if ( null === $fixture['tokens'] ) { if ( is_file( $file ) ) { unlink( $file ); } } else { file_put_contents( $file, $fixture['tokens'] ); }
	if ( null === $fixture['settings'] ) { delete_option( Imajiner_AI::OPTION ); } else { update_option( Imajiner_AI::OPTION, $fixture['settings'], false ); }
	delete_option( 'imajiner_e2e_fixture' );
	unlink( $mock );
	wp_clean_themes_cache();
	WP_CLI::success( 'Disposable fixtures removed and prior token/settings state restored.' );
} else { WP_CLI::error( 'Use setup or cleanup.' ); }
