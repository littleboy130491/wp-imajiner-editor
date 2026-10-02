<?php
/**
 * Admin integration: theme check, entry points and the editor screen.
 *
 * @package Imajiner_Editor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Imajiner Editor.
 */
class Imajiner_Editor {

	/**
	 * Theme folder the plugin requires as the parent theme.
	 */
	const THEME = 'imajiner';

	/**
	 * Folder, relative to the theme, that holds Imajiner templates.
	 */
	const TEMPLATE_DIR = 'imajiner';

	/**
	 * Hooks everything up once the active theme is known.
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'theme_notice' ) );

		if ( ! self::theme_ready() ) {
			return;
		}

		Imajiner_Preview::init();
		Imajiner_Rest::init();

		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_block_editor_assets' ) );
		add_filter( 'page_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		// post.php?post=ID&action=imajiner.
		add_action( 'post_action_imajiner', array( __CLASS__, 'render_editor' ) );
	}

	/**
	 * Whether the Imajiner theme, or a child of it, is active.
	 *
	 * @return bool
	 */
	public static function theme_ready() {
		return self::THEME === get_template();
	}

	/**
	 * Path of the post's Imajiner template.
	 *
	 * @param WP_Post $post Post.
	 * @return string Absolute path, or '' when the post doesn't use an Imajiner template.
	 */
	public static function get_template_path( WP_Post $post ) {
		$slug = get_page_template_slug( $post );
		if ( ! $slug || ! preg_match( '#^' . self::TEMPLATE_DIR . '/[a-z0-9_-]+\.php$#i', $slug ) ) {
			return '';
		}
		return locate_template( $slug );
	}

	/**
	 * Whether the current user may edit this post's template.
	 *
	 * Editing templates writes PHP, so it needs edit_themes, which WordPress
	 * also denies when DISALLOW_FILE_EDIT is set.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function user_can_edit( WP_Post $post ) {
		return current_user_can( 'edit_themes' ) && current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Editor screen URL for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function editor_url( $post_id ) {
		return admin_url( 'post.php?post=' . (int) $post_id . '&action=imajiner' );
	}

	/**
	 * Scheme, host and port of a URL, as used for postMessage origins.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function origin( $url ) {
		$parts = wp_parse_url( $url );
		return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}

	/**
	 * Warns admins when the Imajiner theme isn't active.
	 */
	public static function theme_notice() {
		$screen = get_current_screen();
		if ( self::theme_ready() || ! current_user_can( 'switch_themes' ) || ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins', 'themes' ), true ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Imajiner Editor needs the Imajiner theme (or a child theme of it) to be active.', 'imajiner-editor' )
		);
	}

	/**
	 * Adds an "Imajiner Editor" link to posts that use an Imajiner template.
	 *
	 * @param string[] $actions Row actions.
	 * @param WP_Post  $post    Post.
	 * @return string[]
	 */
	public static function row_actions( $actions, $post ) {
		if ( self::get_template_path( $post ) && self::user_can_edit( $post ) ) {
			$actions['imajiner'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::editor_url( $post->ID ) ),
				esc_html__( 'Imajiner Editor', 'imajiner-editor' )
			);
		}
		return $actions;
	}

	/**
	 * Adds the "Open Imajiner Editor" panel to the block editor sidebar.
	 */
	public static function enqueue_block_editor_assets() {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base || ! current_user_can( 'edit_themes' ) ) {
			return;
		}

		wp_enqueue_script(
			'imajiner-block-editor',
			IMAJINER_EDITOR_URL . 'assets/js/block-editor.js',
			array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n' ),
			IMAJINER_EDITOR_VERSION,
			true
		);
		wp_localize_script(
			'imajiner-block-editor',
			'imajinerBlockEditor',
			array(
				'editorUrl'   => admin_url( 'post.php?action=imajiner&post=' ),
				'templateDir' => self::TEMPLATE_DIR . '/',
			)
		);
	}

	/**
	 * Renders the full-screen editor and exits.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function render_editor( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || ! self::user_can_edit( $post ) ) {
			wp_die( esc_html__( 'You are not allowed to edit this template.', 'imajiner-editor' ), 403 );
		}

		$path = self::get_template_path( $post );
		if ( ! $path ) {
			wp_die( esc_html__( 'This page does not use an Imajiner template. Choose one under Template in the page settings, then save the page.', 'imajiner-editor' ) );
		}

		$files = Imajiner_Template_Store::read( $path );
		if ( is_wp_error( $files ) ) {
			wp_die( esc_html( $files->get_error_message() ) );
		}

		$template = get_file_data( $path, array( 'name' => 'Template Name' ) );

		$data = array_merge(
			array(
				'post'          => array(
					'id'      => $post->ID,
					'title'   => get_the_title( $post ),
					'editUrl' => get_edit_post_link( $post, 'raw' ),
					'viewUrl' => get_permalink( $post ),
				),
				'template'      => array(
					'file' => get_page_template_slug( $post ),
					'name' => $template['name'],
				),
				'cssScope'      => self::css_scope( $path ),
				'breakpoints'   => array_map(
					function ( $name, $breakpoint ) {
						return array_merge( array( 'name' => $name ), $breakpoint );
					},
					array_keys( self::breakpoints() ),
					self::breakpoints()
				),
				'tokens'        => self::design_tokens(),
				'previewUrl'    => Imajiner_Preview::url( $post ),
				'previewOrigin' => self::origin( home_url() ),
				'restUrl'       => rest_url( Imajiner_Rest::NAMESPACE_V1 . '/templates/' . $post->ID ),
				'restNonce'     => wp_create_nonce( 'wp_rest' ),
			),
			self::template_payload( $path, $files )
		);

		// The editor page is standalone, so it prints its own assets (see views/editor.php).
		wp_enqueue_media();
		wp_enqueue_style( 'imajiner-editor', IMAJINER_EDITOR_URL . 'assets/css/editor.css', array(), IMAJINER_EDITOR_VERSION );
		wp_enqueue_script( 'imajiner-editor', IMAJINER_EDITOR_URL . 'assets/js/editor.js', array( 'media-editor' ), IMAJINER_EDITOR_VERSION, true );
		wp_add_inline_script( 'imajiner-editor', 'window.imajinerEditor = ' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );

		require IMAJINER_EDITOR_DIR . 'views/editor.php';
		exit;
	}

	/**
	 * What the editor needs to know about a template's current files.
	 *
	 * @param string $path  Template path.
	 * @param array  $files php and css source.
	 * @return array {
	 *     @type string $hash      Version hash for conflict checks.
	 *     @type array  $structure Template structure from the scanner.
	 *     @type array  $styles    Breakpoint => class name => property => value, from the template stylesheet.
	 * }
	 */
	public static function template_payload( $path, array $files ) {
		$scanner = new Imajiner_Template_Scanner( $files['php'] );
		$css     = new Imajiner_Css_Editor( $files['css'] );
		$styles  = $css->get_class_styles( self::css_scope( $path ), wp_list_pluck( self::breakpoints(), 'media' ) );

		return array(
			'hash'      => Imajiner_Template_Store::hash( $files ),
			'structure' => $scanner->get_structure(),
			// An empty array would encode as [] instead of {}.
			'styles'    => (object) array_map(
				function ( $classes ) {
					return (object) $classes;
				},
				$styles
			),
		);
	}

	/**
	 * Breakpoints the Style tab edits, widest first. The first one is the base styles (no @media).
	 *
	 * Desktop-first: each narrower breakpoint's block comes later in the stylesheet
	 * and overrides the wider ones.
	 *
	 * @return array Name => label, @media condition and preview width in pixels. The base
	 *               breakpoint's width is a minimum: its preview fills the canvas when wider.
	 */
	public static function breakpoints() {
		$breakpoints = array(
			'desktop' => array(
				'label' => __( 'Desktop', 'imajiner-editor' ),
				'media' => '',
				// Wider than every breakpoint, so no @media rule applies in the desktop preview.
				'width' => 1280,
			),
			'tablet'  => array(
				'label' => __( 'Tablet', 'imajiner-editor' ),
				'media' => '(max-width: 1024px)',
				'width' => 768,
			),
			'mobile'  => array(
				'label' => __( 'Mobile', 'imajiner-editor' ),
				'media' => '(max-width: 767px)',
				'width' => 375,
			),
		);

		/**
		 * Filters the breakpoints offered by the Style tab.
		 *
		 * @param array $breakpoints Name => label, media and width. Keep them ordered widest first.
		 */
		return apply_filters( 'imajiner_editor_breakpoints', $breakpoints );
	}

	/**
	 * Selector that scopes a template's CSS: the body class the theme adds, e.g. ".imj-page-home".
	 *
	 * @param string $path Template path.
	 * @return string
	 */
	public static function css_scope( $path ) {
		return '.imj-' . basename( $path, '.php' );
	}

	/**
	 * Design tokens (--imj-* custom properties) from the parent theme, with child theme overrides.
	 *
	 * @return array Token name => value.
	 */
	public static function design_tokens() {
		$files = array( get_template_directory() . '/assets/css/base.css' );
		if ( is_child_theme() ) {
			$files[] = get_stylesheet_directory() . '/style.css';
		}

		$tokens = array();
		foreach ( $files as $file ) {
			if ( ! is_readable( $file ) ) {
				continue;
			}
			// Commented-out examples in the child stylesheet must not count.
			$css = preg_replace( '#/\*.*?\*/#s', '', file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			preg_match_all( '/(--imj-[a-z0-9-]+)\s*:\s*([^;}]+)/', $css, $matches, PREG_SET_ORDER );
			foreach ( $matches as $match ) {
				$tokens[ $match[1] ] = trim( $match[2] );
			}
		}

		return (object) $tokens;
	}
}
