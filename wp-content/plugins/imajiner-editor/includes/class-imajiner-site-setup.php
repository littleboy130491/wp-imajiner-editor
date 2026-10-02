<?php
/** Explicit, portable client child-theme creation. */
defined( 'ABSPATH' ) || exit;

class Imajiner_Site_Setup {
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_imajiner_create_child', array( __CLASS__, 'handle_create' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'imajiner child create', array( __CLASS__, 'cli_create' ) );
		}
	}

	public static function menu() {
		add_theme_page( __( 'Imajiner Site Setup', 'imajiner-editor' ), __( 'Imajiner Site Setup', 'imajiner-editor' ), 'install_themes', 'imajiner-site-setup', array( __CLASS__, 'render' ) );
	}

	public static function permission() {
		if ( ! current_user_can( 'install_themes' ) || ! current_user_can( 'edit_themes' ) ||
			! wp_is_file_mod_allowed( 'imajiner_site_setup' ) || ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) ) {
			return new WP_Error( 'imajiner_setup_forbidden', __( 'You are not allowed to create child themes.', 'imajiner-editor' ) );
		}
		if ( is_multisite() ) {
			return new WP_Error( 'imajiner_setup_multisite', __( 'Create client child themes on a single-site installation.', 'imajiner-editor' ) );
		}
		return true;
	}

	public static function directory_name( $slug ) {
		if ( ! is_string( $slug ) || ! preg_match( '/\A[a-z0-9][a-z0-9-]{0,47}\z/', $slug ) || '-' === substr( $slug, -1 ) ) {
			return new WP_Error( 'imajiner_setup_slug', __( 'Use a site slug of 1–48 lowercase letters, numbers and hyphens, starting and ending with a letter or number.', 'imajiner-editor' ) );
		}
		return 'imajiner-' . $slug;
	}

	public static function create( $slug, $name = '' ) {
		$allowed = self::permission();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$directory = self::directory_name( $slug );
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}
		$parent = wp_get_theme( 'imajiner' );
		if ( ! $parent->exists() || $parent->errors() ) {
			return new WP_Error( 'imajiner_setup_parent', __( 'Install the Imajiner parent theme first.', 'imajiner-editor' ) );
		}
		if ( ! class_exists( 'Imajiner_Filesystem' ) ) {
			return new WP_Error( 'imajiner_setup_filesystem', __( 'The Imajiner filesystem service is unavailable.', 'imajiner-editor' ) );
		}
		$ready = Imajiner_Filesystem::init();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$root = $parent->get_theme_root();
		$path = $root . '/' . $directory;
		$lock = 'imajiner_child_setup_' . md5( $path );
		$time = get_option( $lock );
		if ( $time && (int) $time < time() - 300 ) {
			delete_option( $lock );
		}
		if ( ! add_option( $lock, time(), '', false ) ) {
			return new WP_Error( 'imajiner_setup_busy', __( 'This child theme is already being created. Try again later.', 'imajiner-editor' ) );
		}
		try {
			if ( Imajiner_Filesystem::exists( $path ) ) {
				return new WP_Error( 'imajiner_setup_exists', __( 'That theme directory already exists. Choose a different site slug; existing themes are never overwritten.', 'imajiner-editor' ) );
			}
			$name = sanitize_text_field( str_replace( array( '/*', '*/' ), '', $name ) );
			$name = $name ?: sprintf( __( 'Imajiner — %s', 'imajiner-editor' ), $slug );
			$files = array(
				'style.css' => "/*\nTheme Name: " . $name . "\nTemplate: imajiner\nVersion: 0.1.0\nRequires at least: 6.7\nRequires PHP: 7.4\nText Domain: " . $directory . "\n*/\n",
				'assets/css/design-tokens.css' => ":root {\n\t/* Site design token overrides. */\n}\n",
				'imajiner/.gitkeep' => '',
				'imajiner/css/.gitkeep' => '',
				'imajiner/parts/.gitkeep' => '',
				'imajiner/parts/css/.gitkeep' => '',
			);
			foreach ( array( 'functions.php', 'editor.css', 'README.md' ) as $file ) {
				$content = Imajiner_Filesystem::read( $parent->get_stylesheet_directory() . '/child-scaffold/' . $file );
				if ( is_wp_error( $content ) ) {
					return $content;
				}
				$files[ $file ] = $content;
			}
			foreach ( array( 'theme.json', 'screenshot.png' ) as $file ) {
				$content = Imajiner_Filesystem::read( $parent->get_stylesheet_directory() . '/' . $file );
				if ( is_wp_error( $content ) ) {
					return $content;
				}
				$files[ $file ] = $content;
			}
			$created = array();
			foreach ( array( '', '/assets', '/assets/css', '/imajiner', '/imajiner/css', '/imajiner/parts', '/imajiner/parts/css' ) as $folder ) {
				$result = Imajiner_Filesystem::mkdir( $path . $folder );
				if ( is_wp_error( $result ) ) {
					return self::rollback( $created, $result, $path );
				}
			}
			foreach ( $files as $file => $content ) {
				$target = $path . '/' . $file;
				if ( Imajiner_Filesystem::exists( $target ) ) {
					return self::rollback( $created, new WP_Error( 'imajiner_setup_exists', __( 'A scaffold file already exists; setup stopped without overwriting it.', 'imajiner-editor' ) ), $path );
				}
				$created[] = $target;
				$result = Imajiner_Filesystem::write( $target, $content );
				if ( is_wp_error( $result ) ) {
					return self::rollback( $created, $result, $path );
				}
			}
			wp_clean_themes_cache();
			do_action( 'imajiner_child_theme_created', $directory, $path );
			return $directory;
		} finally {
			delete_option( $lock );
		}
	}

	private static function rollback( $created, $error, $directory ) {
		foreach ( array_reverse( $created ) as $path ) {
			$result = Imajiner_Filesystem::delete( $path );
			if ( is_wp_error( $result ) ) {
				$error->add( 'imajiner_setup_cleanup', __( 'An incomplete scaffold could not be removed. Check the theme directory before trying again.', 'imajiner-editor' ) );
			}
		}
		if ( Imajiner_Filesystem::exists( $directory ) ) {
			$error->add( 'imajiner_setup_incomplete', __( 'Setup left an incomplete child directory. Inspect and remove it manually before reusing this slug; directories are not deleted automatically.', 'imajiner-editor' ) );
		}
		return $error;
	}

	public static function handle_create() {
		check_admin_referer( 'imajiner_create_child' );
		$slug = isset( $_POST['site_slug'] ) && is_string( $_POST['site_slug'] ) ? wp_unslash( $_POST['site_slug'] ) : '';
		$name = isset( $_POST['theme_name'] ) && is_string( $_POST['theme_name'] ) ? wp_unslash( $_POST['theme_name'] ) : '';
		$result = self::create( $slug, $name );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( implode( ' ', $result->get_error_messages() ) ), esc_html__( 'Child theme setup failed', 'imajiner-editor' ), array( 'response' => 400, 'back_link' => true ) );
		}
		$url = add_query_arg( array( 'page' => 'imajiner-site-setup', 'created' => $result ), admin_url( 'themes.php' ) );
		$url = wp_nonce_url( $url, 'imajiner_child_created_' . $result, 'setup_nonce' );
		wp_safe_redirect( $url );
		exit;
	}

	public static function render() {
		$allowed = self::permission();
		if ( is_wp_error( $allowed ) ) {
			wp_die( esc_html( $allowed->get_error_message() ) );
		}
		$created = isset( $_GET['created'] ) && is_string( $_GET['created'] ) ? sanitize_key( wp_unslash( $_GET['created'] ) ) : '';
		$nonce = isset( $_GET['setup_nonce'] ) && is_string( $_GET['setup_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['setup_nonce'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Imajiner Site Setup', 'imajiner-editor' ); ?></h1>
			<?php if ( $created && wp_verify_nonce( $nonce, 'imajiner_child_created_' . $created ) && wp_get_theme( $created )->get_template() === 'imajiner' ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html__( 'Child theme created. Your active theme has not changed. Review the new theme, then activate it when you are ready.', 'imajiner-editor' ); ?></p>
				<?php if ( current_user_can( 'switch_themes' ) ) : ?>
					<p><a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'activate', 'stylesheet' => $created ), admin_url( 'themes.php' ) ), 'switch-theme_' . $created ) ); ?>"><?php echo esc_html__( 'Confirm and activate this child theme', 'imajiner-editor' ); ?></a></p>
				<?php endif; ?></div>
			<?php endif; ?>
			<p><?php echo esc_html__( 'Create an empty, independent child theme for a client site. Only the intended scaffold is copied; demo templates and other sites’ content are excluded. Existing theme directories are never overwritten.', 'imajiner-editor' ); ?></p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="imajiner_create_child">
				<?php wp_nonce_field( 'imajiner_create_child' ); ?>
				<p><label for="imajiner-site-slug"><?php echo esc_html__( 'Site slug', 'imajiner-editor' ); ?></label><br>
				<input id="imajiner-site-slug" name="site_slug" type="text" pattern="[a-z0-9][a-z0-9-]{0,47}" maxlength="48" required aria-describedby="imajiner-slug-help"></p>
				<p id="imajiner-slug-help"><?php echo esc_html__( 'Use lowercase letters, numbers and hyphens. The theme directory is prefixed with imajiner-.', 'imajiner-editor' ); ?></p>
				<p><label for="imajiner-theme-name"><?php echo esc_html__( 'Theme name', 'imajiner-editor' ); ?></label><br><input id="imajiner-theme-name" name="theme_name" type="text" maxlength="100"></p>
				<?php submit_button( __( 'Create child theme without activating', 'imajiner-editor' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Create an empty client child theme without activating it.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Portable site slug (lowercase letters, numbers and hyphens).
	 *
	 * [--name=<name>]
	 * : Display name for the child theme.
	 */
	public static function cli_create( $args, $assoc_args ) {
		$result = self::create( $args[0], isset( $assoc_args['name'] ) ? $assoc_args['name'] : '' );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( implode( ' ', $result->get_error_messages() ) );
		}
		WP_CLI::success( sprintf( __( 'Created %s. The active theme has not changed.', 'imajiner-editor' ), $result ) );
	}
}
