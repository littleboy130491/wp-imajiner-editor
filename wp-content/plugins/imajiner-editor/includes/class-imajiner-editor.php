<?php
/**
 * Admin integration: theme check, template lookup, entry points and the editor screen.
 *
 * Templates are addressed by a key: the file name inside the child theme's
 * imajiner/ folder without .php ("page-home", "single-product"), or
 * "parts/<slug>" for template parts. The theme (inc/locations.php and
 * inc/parts.php) is the source of truth for which templates and parts exist.
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
	 * Valid template keys.
	 */
	const KEY_PATTERN = '#^(parts/)?[a-z0-9_-]+$#';

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
		Imajiner_Builder::init();

		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_block_editor_assets' ) );
		add_filter( 'page_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		// post.php?post=ID&action=imajiner: the template used by a post.
		add_action( 'post_action_imajiner', array( __CLASS__, 'render_post_editor' ) );
		// admin.php?action=imajiner_template&template=KEY: any template or part.
		add_action( 'admin_action_imajiner_template', array( __CLASS__, 'render_template_editor' ) );
	}

	/**
	 * Whether the Imajiner theme, or a child of it, is active.
	 *
	 * @return bool
	 */
	public static function theme_ready() {
		return self::THEME === get_template() && function_exists( 'imajiner_get_templates' );
	}

	/**
	 * Looks up a template or part by key.
	 *
	 * @param string $key Template key.
	 * @return array|null {
	 *     @type string   $key       Key.
	 *     @type string   $type      'page' (chosen per page), 'location' (single/archive) or 'part'.
	 *     @type string   $slug      File name without .php.
	 *     @type string   $name      Readable name.
	 *     @type string   $file      Absolute path.
	 *     @type string[] $locations Locations a single/archive template is assigned to.
	 *     @type string   $location  Location of a part, or ''.
	 * }
	 */
	public static function get_template( $key ) {
		if ( ! is_string( $key ) || ! preg_match( self::KEY_PATTERN, $key ) ) {
			return null;
		}

		if ( 0 === strpos( $key, 'parts/' ) ) {
			$slug  = substr( $key, 6 );
			$parts = imajiner_get_parts();
			if ( ! isset( $parts[ $slug ] ) ) {
				return null;
			}
			return array(
				'key'       => $key,
				'type'      => 'part',
				'slug'      => $slug,
				'name'      => $parts[ $slug ]['name'],
				'file'      => $parts[ $slug ]['file'],
				'locations' => array(),
				'location'  => $parts[ $slug ]['location'],
			);
		}

		$templates = imajiner_get_templates();
		if ( ! isset( $templates[ $key ] ) ) {
			return null;
		}
		$template = $templates[ $key ];

		return array(
			'key'       => $key,
			'type'      => $template['is_page'] ? 'page' : 'location',
			'slug'      => $key,
			'name'      => $template['name'],
			'file'      => $template['file'],
			'locations' => $template['locations'],
			'location'  => '',
		);
	}

	/**
	 * Key of the template that renders a post: the one chosen for it, or the one assigned to its post type.
	 *
	 * @param WP_Post $post Post.
	 * @return string Key, or ''.
	 */
	public static function template_key_for_post( WP_Post $post ) {
		$chosen = get_page_template_slug( $post );
		if ( $chosen ) {
			$key = preg_match( '#^' . self::TEMPLATE_DIR . '/([a-z0-9_-]+)\.php$#', $chosen, $match ) ? $match[1] : '';
			return $key && self::get_template( $key ) ? $key : '';
		}

		$assignments = imajiner_location_assignments();
		foreach ( array( 'single:' . $post->post_type, 'single' ) as $location ) {
			if ( isset( $assignments[ $location ] ) ) {
				return $assignments[ $location ];
			}
		}
		return '';
	}

	/**
	 * Selector that scopes a template's CSS: the body class for templates, the wrapper class for parts.
	 *
	 * @param array $template Template from get_template().
	 * @return string
	 */
	public static function css_scope( array $template ) {
		return 'part' === $template['type'] ? '.imj-part-' . $template['slug'] : '.imj-' . $template['slug'];
	}

	/**
	 * Whether the current user may edit templates. Editing writes PHP, so it
	 * needs edit_themes, which WordPress also denies when DISALLOW_FILE_EDIT is set.
	 *
	 * @return bool
	 */
	public static function user_can_edit_templates() {
		return current_user_can( 'edit_themes' );
	}

	/**
	 * Editor URL for a template or part.
	 *
	 * @param string $key Template key.
	 * @return string
	 */
	public static function editor_url( $key ) {
		return add_query_arg(
			array(
				'action'   => 'imajiner_template',
				'template' => $key,
			),
			admin_url( 'admin.php' )
		);
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
	 * Adds an "Imajiner Editor" link to posts rendered by an Imajiner template.
	 *
	 * @param string[] $actions Row actions.
	 * @param WP_Post  $post    Post.
	 * @return string[]
	 */
	public static function row_actions( $actions, $post ) {
		if ( self::user_can_edit_templates() && current_user_can( 'edit_post', $post->ID ) && self::template_key_for_post( $post ) ) {
			$actions['imajiner'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'post.php?post=' . $post->ID . '&action=imajiner' ) ),
				esc_html__( 'Imajiner Editor', 'imajiner-editor' )
			);
		}
		return $actions;
	}

	/**
	 * Adds the "Imajiner Editor" panel to the block editor sidebar.
	 */
	public static function enqueue_block_editor_assets() {
		$screen = get_current_screen();
		$post   = get_post();
		if ( ! $screen || 'post' !== $screen->base || ! $post || ! self::user_can_edit_templates() ) {
			return;
		}

		// Template that renders this post as saved, so the panel can tell a chosen template from an assigned one.
		$key      = self::template_key_for_post( $post );
		$template = $key ? self::get_template( $key ) : null;

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
				'editorUrl'    => admin_url( 'post.php?action=imajiner&post=' ),
				'templateDir'  => self::TEMPLATE_DIR . '/',
				'templateName' => $template ? $template['name'] : '',
				// A single template assigned to this post type applies when no template is chosen.
				'assigned'     => $template && 'location' === $template['type'] ? $template['name'] : '',
				'builderUrl'   => Imajiner_Builder::url(),
			)
		);
	}

	/**
	 * Opens the editor for the template that renders a post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function render_post_editor( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || ! self::user_can_edit_templates() || ! current_user_can( 'edit_post', $post->ID ) ) {
			wp_die( esc_html__( 'You are not allowed to edit this template.', 'imajiner-editor' ), 403 );
		}

		$key = self::template_key_for_post( $post );
		if ( ! $key ) {
			wp_die( esc_html__( 'This page does not use an Imajiner template. Choose one under Template in the page settings and save the page, or assign a single template under Appearance → Imajiner Templates.', 'imajiner-editor' ) );
		}

		self::render_editor( self::get_template( $key ), $post );
	}

	/**
	 * Opens the editor for a template or part by key.
	 */
	public static function render_template_editor() {
		if ( ! self::user_can_edit_templates() ) {
			wp_die( esc_html__( 'You are not allowed to edit this template.', 'imajiner-editor' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Opening the editor changes nothing.
		$key      = isset( $_GET['template'] ) ? sanitize_text_field( wp_unslash( $_GET['template'] ) ) : '';
		$template = self::get_template( $key );
		if ( ! $template ) {
			wp_die( esc_html__( 'Template not found.', 'imajiner-editor' ), 404 );
		}

		self::render_editor( $template, null );
	}

	/**
	 * Renders the full-screen editor and exits.
	 *
	 * @param array        $template Template from get_template().
	 * @param WP_Post|null $post     Post to preview the template with, if opened from a post.
	 */
	private static function render_editor( array $template, $post ) {
		$files = Imajiner_Template_Store::read( $template['file'] );
		if ( is_wp_error( $files ) ) {
			wp_die( esc_html( $files->get_error_message() ) );
		}

		$preview_url = Imajiner_Preview::url( $template, $post );
		if ( is_wp_error( $preview_url ) ) {
			wp_die( esc_html( $preview_url->get_error_message() ) );
		}

		$type_labels = array(
			'page'     => __( 'Page template', 'imajiner-editor' ),
			'location' => __( 'Single / archive template', 'imajiner-editor' ),
			'part'     => __( 'Template part', 'imajiner-editor' ),
		);

		$data = array_merge(
			array(
				'post'          => array(
					'title'   => $post ? get_the_title( $post ) : $template['name'],
					'editUrl' => $post ? get_edit_post_link( $post, 'raw' ) : Imajiner_Builder::url(),
					'viewUrl' => $post ? get_permalink( $post ) : remove_query_arg( array( Imajiner_Preview::QUERY_VAR, Imajiner_Preview::TEMPLATE_VAR ), $preview_url ),
					'back'    => $post ? __( 'Back to page', 'imajiner-editor' ) : __( 'Back to templates', 'imajiner-editor' ),
				),
				'template'      => array(
					'key'  => $template['key'],
					'file' => self::TEMPLATE_DIR . '/' . $template['key'] . '.php',
					'name' => $template['name'],
					'type' => $type_labels[ $template['type'] ],
				),
				'cssScope'      => self::css_scope( $template ),
				'breakpoints'   => array_map(
					function ( $name, $breakpoint ) {
						return array_merge( array( 'name' => $name ), $breakpoint );
					},
					array_keys( self::breakpoints() ),
					self::breakpoints()
				),
				'tokens'        => self::design_tokens(),
				'previewUrl'    => $preview_url,
				'previewOrigin' => self::origin( home_url() ),
				'restUrl'       => rest_url( Imajiner_Rest::NAMESPACE_V1 . '/templates/' . $template['key'] ),
				'restNonce'     => wp_create_nonce( 'wp_rest' ),
			),
			self::template_payload( $template, $files )
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
	 * @param array $template Template from get_template().
	 * @param array $files    php and css source.
	 * @return array {
	 *     @type string $hash      Version hash for conflict checks.
	 *     @type array  $structure Template structure from the scanner.
	 *     @type array  $styles    Breakpoint => class name => property => value, from the template stylesheet.
	 * }
	 */
	public static function template_payload( array $template, array $files ) {
		// Parts are small pieces; only full templates are expected to have section markers.
		$scanner = new Imajiner_Template_Scanner( $files['php'], array( 'require_sections' => 'part' !== $template['type'] ) );
		$css     = new Imajiner_Css_Editor( $files['css'] );
		$styles  = $css->get_class_styles( self::css_scope( $template ), wp_list_pluck( self::breakpoints(), 'media' ) );

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
