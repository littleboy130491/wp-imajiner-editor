<?php
/** Test-only MU plugin, installed by harness.php on explicitly opted-in local sites. */
defined( 'ABSPATH' ) || exit;
if ( ! defined( 'IMAJINER_E2E_TEST_SITE' ) || true !== IMAJINER_E2E_TEST_SITE || 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true ) ) {
	return;
}
$imajiner_e2e = get_option( 'imajiner_e2e_fixture' );
if ( ! is_array( $imajiner_e2e ) ) { return; }
if ( defined( 'IMAJINER_OPENAI_API_KEY' ) ) {
	wp_die( 'Remove the real OpenAI key constant from this disposable E2E site.' );
}
define( 'IMAJINER_OPENAI_API_KEY', 'intercepted-e2e-test-key' );

add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
	$host = wp_parse_url( $url, PHP_URL_HOST );
	if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true ) ) { return $preempt; }
	if ( 'https://api.openai.com/v1/models' === $url ) {
		$data = array( 'data' => array( array( 'id' => 'imajiner-e2e-mock' ) ) );
	} elseif ( 'https://api.openai.com/v1/chat/completions' === $url ) {
		$body = json_decode( $args['body'], true );
		$prompt = '';
		foreach ( $body['messages'] as $message ) {
			if ( 'user' === $message['role'] ) { $prompt = $message['content']; break; }
		}
		if ( is_array( $prompt ) ) { $prompt = $prompt[0]['text']; }
		if ( 0 === strpos( $prompt, 'Extract a useful design system' ) ) {
			$reply = array( 'tokens' => array( '--imj-color-primary' => '#13579b', '--imj-space-4' => '1.25rem', '--imj-font-body' => 'Arial, sans-serif' ), 'summary' => 'Deterministic E2E tokens.' );
		} elseif ( preg_match( '/Use this exact metadata: (\{[^\n]+\})/', $prompt, $match ) ) {
			$context = json_decode( $match[1], true );
			$php = "<?php\n/**\n * Template Name: " . $context['name'] . "\n */\nget_header();\n?>\n<!-- imj:section name=\"intro\" -->\n<section class=\"e2e-intro\"><div class=\"container\"><h1>E2E generated heading</h1></div></section>\n<!-- /imj:section -->\n<?php get_footer(); ?>\n";
			$reply = array( 'slug' => $context['slug'], 'name' => $context['name'], 'php' => $php, 'css' => $context['scope'] . " .e2e-intro { color: var(--imj-color-primary); padding: var(--imj-space-4); }\n" );
		} else {
			return new WP_Error( 'imajiner_e2e_unsupported', 'The deterministic harness received an unexpected AI request.' );
		}
		$data = array( 'model' => 'imajiner-e2e-mock', 'choices' => array( array( 'message' => array( 'content' => wp_json_encode( $reply ) ) ) ), 'usage' => array( 'prompt_tokens' => 1, 'completion_tokens' => 1 ) );
	} else {
		return new WP_Error( 'imajiner_e2e_external_http', 'External HTTP is disabled while the local E2E harness is active.' );
	}
	return array( 'headers' => array(), 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $data ) );
}, PHP_INT_MAX, 3 );

add_action( 'wp_insert_post', function ( $id, $post ) {
	$fixture = get_option( 'imajiner_e2e_fixture' );
	if ( in_array( $post->post_type, array( 'imajiner_ai_job', 'imajiner_ai_usage', 'imajiner_revision', 'imajiner_design_rev' ), true ) || 0 === strpos( sanitize_title( $post->post_title ), $fixture['run'] ) ) {
		$fixture['posts'][] = $id;
		update_option( 'imajiner_e2e_fixture', $fixture, false );
	}
}, 10, 2 );
