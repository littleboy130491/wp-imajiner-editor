<?php
/** Literal section starters with matching scoped, token-based CSS. */
defined( 'ABSPATH' ) || exit;

class Imajiner_Section_Library {
	public static function init() {
		return true;
	}

	public static function entries() {
		return array( 'hero' => __( 'Hero', 'imajiner-editor' ), 'features' => __( 'Features', 'imajiner-editor' ), 'cta' => __( 'Call to action', 'imajiner-editor' ) );
	}

	public static function get( $name, $scope ) {
		if ( ! is_string( $name ) || ! isset( self::entries()[ $name ] ) ) {
			return new WP_Error( 'imajiner_library', __( 'Unknown library section.', 'imajiner-editor' ) );
		}
		$class = 'imj-library-' . $name;
		$title = array( 'hero' => __( 'Welcome to your next chapter', 'imajiner-editor' ), 'features' => __( 'Built around your needs', 'imajiner-editor' ), 'cta' => __( 'Ready to get started?', 'imajiner-editor' ) );
		$markup = '<section class="' . $class . '"><div class="container"><h2>' . esc_html( $title[ $name ] ) . '</h2><p>' . esc_html__( 'Add your content here.', 'imajiner-editor' ) . '</p>';
		if ( 'features' === $name ) {
			$markup .= '<div class="' . $class . '__grid">';
			foreach ( array( __( 'Quality', 'imajiner-editor' ), __( 'Support', 'imajiner-editor' ), __( 'Simplicity', 'imajiner-editor' ) ) as $feature ) {
				$markup .= '<article><h3>' . esc_html( $feature ) . '</h3><p>' . esc_html__( 'Describe this benefit.', 'imajiner-editor' ) . '</p></article>';
			}
			$markup .= '</div>';
		} else {
			$markup .= '<a class="' . $class . '__button" href="#">' . esc_html__( 'Learn more', 'imajiner-editor' ) . '</a>';
		}
		$markup .= '</div></section>';
		$css = "\n$scope .$class { padding: var(--imj-space-7); background: var(--imj-color-surface); color: var(--imj-color-text); }\n";
		if ( 'features' === $name ) {
			$css .= "$scope .{$class}__grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--imj-space-5); }\n";
		} else {
			$css .= "$scope .{$class}__button { display: inline-block; padding: var(--imj-space-3) var(--imj-space-5); background: var(--imj-color-primary); border-radius: var(--imj-radius); }\n";
		}
		return array( 'php' => '<!-- imj:section name="' . $name . '" -->' . "\n" . $markup . "\n<!-- /imj:section -->", 'css' => $css );
	}
}
