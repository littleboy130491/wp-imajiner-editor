<?php
defined( 'ABSPATH' ) || exit;

class Imajiner_Section_Library {
	public static function init() {}

	public static function sections() {
		$wrap = function ( $name, $html ) {
			return '<!-- imj:section name="' . $name . '" -->' . "\n" . $html . "\n<!-- /imj:section -->";
		};
		return array(
			'hero' => array( 'label' => __( 'Hero', 'imajiner-editor' ), 'php' => $wrap( 'hero', '<section class="imj-library-hero"><div class="container"><h1>' . esc_html__( 'Your next chapter starts here', 'imajiner-editor' ) . '</h1><p>' . esc_html__( 'Introduce your offer and the difference it makes.', 'imajiner-editor' ) . '</p><a class="imj-library-button" href="#">' . esc_html__( 'Get started', 'imajiner-editor' ) . '</a></div></section>' ), 'css' => '%scope% .imj-library-hero { padding: var(--imj-space-8) 0; background: var(--imj-color-surface); }' ),
			'features' => array( 'label' => __( 'Features', 'imajiner-editor' ), 'php' => $wrap( 'features', '<section class="imj-library-features"><div class="container"><h2>' . esc_html__( 'What you can do', 'imajiner-editor' ) . '</h2><div class="imj-library-grid"><article><h3>' . esc_html__( 'Simple', 'imajiner-editor' ) . '</h3><p>' . esc_html__( 'Describe a benefit.', 'imajiner-editor' ) . '</p></article><article><h3>' . esc_html__( 'Reliable', 'imajiner-editor' ) . '</h3><p>' . esc_html__( 'Describe a benefit.', 'imajiner-editor' ) . '</p></article><article><h3>' . esc_html__( 'Flexible', 'imajiner-editor' ) . '</h3><p>' . esc_html__( 'Describe a benefit.', 'imajiner-editor' ) . '</p></article></div></div></section>' ), 'css' => '%scope% .imj-library-features { padding: var(--imj-space-8) 0; }' . "\n" . '%scope% .imj-library-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 16rem), 1fr)); gap: var(--imj-space-5); }' ),
			'cta' => array( 'label' => __( 'Call to action', 'imajiner-editor' ), 'php' => $wrap( 'cta', '<section class="imj-library-cta"><div class="container"><h2>' . esc_html__( 'Ready to take the next step?', 'imajiner-editor' ) . '</h2><p>' . esc_html__( 'Tell visitors how to get in touch.', 'imajiner-editor' ) . '</p><a class="imj-library-button" href="#">' . esc_html__( 'Contact us', 'imajiner-editor' ) . '</a></div></section>' ), 'css' => '%scope% .imj-library-cta { padding: var(--imj-space-8) 0; text-align: center; background: var(--imj-color-surface); }' ),
		);
	}

	public static function get( $name, $scope ) {
		$sections = self::sections();
		if ( ! is_string( $name ) || ! isset( $sections[ $name ] ) ) {
			return new WP_Error( 'imajiner_library', __( 'Unknown section.', 'imajiner-editor' ) );
		}
		$section = $sections[ $name ];
		$section['css'] = str_replace( '%scope%', $scope, $section['css'] . "\n" . '%scope% .imj-library-button { display: inline-block; padding: var(--imj-space-3) var(--imj-space-5); color: var(--imj-color-primary-contrast); background: var(--imj-color-primary); border-radius: var(--imj-radius); }' );
		return $section;
	}
}
