<?php
/** WordPress integration tests must run against a disposable local site. */
if ( '1' !== getenv( 'IMAJINER_TEST_SITE' ) ) {
	throw new RuntimeException( 'Set IMAJINER_TEST_SITE=1 only on a disposable WordPress installation.' );
}

define( 'IMAJINER_OPENAI_API_KEY', 'fake-key-for-intercepted-test-requests' );
define( 'DISABLE_WP_CRON', true );
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';
require ( getenv( 'WP_TEST_ROOT' ) ?: dirname( __DIR__, 4 ) ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

if ( ! class_exists( 'Imajiner_Generation' ) || ! is_child_theme() || ! Imajiner_Editor::theme_ready() ) {
	throw new RuntimeException( 'Activate imajiner-child and the imajiner-editor plugin before running tests.' );
}
