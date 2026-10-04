<?php
/** Opt-in standalone provider smoke runner. No keys are accepted as arguments or printed. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'Imajiner_AI' ) ) {
	exit( 1 );
}
$result = Imajiner_AI::smoke_from_environment();
if ( is_wp_error( $result ) ) {
	WP_CLI::error( $result->get_error_message() );
}
WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
