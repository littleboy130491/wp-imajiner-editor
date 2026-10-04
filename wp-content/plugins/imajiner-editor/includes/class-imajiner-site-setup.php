<?php
/** Explicit per-client child-theme setup; never copies an active site's content. */
defined( 'ABSPATH' ) || exit;

class Imajiner_Site_Setup {
	const PAGE = 'imajiner-site-setup';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_post_imajiner_create_child_theme', array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_imajiner_activate_child_theme', array( __CLASS__, 'handle_activate' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'imajiner child-theme create', array( __CLASS__, 'cli_create' ) );
		}
	}

	public static function add_page() {
		add_theme_page( __( 'Imajiner Site Setup', 'imajiner-editor' ), __( 'Imajiner Site Setup', 'imajiner-editor' ), 'install_themes', self::PAGE, array( __CLASS__, 'render' ) );
	}

	/** @return true|WP_Error */
	private static function permission() {
		if ( ! current_user_can( 'install_themes' ) || ! current_user_can( 'edit_themes' ) || ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) || ! wp_is_file_mod_allowed( 'imajiner_child_theme' ) ) {
			return new WP_Error( 'imajiner_setup_forbidden', __( 'You are not allowed to create child themes on this site.', 'imajiner-editor' ) );
		}
		if ( is_multisite() ) {
			return new WP_Error( 'imajiner_setup_multisite', __( 'Create and network-enable client child themes through your network deployment workflow.', 'imajiner-editor' ) );
		}
		return true;
	}

	/** @return true|WP_Error A strict, portable folder name; never silently rewrites input. */
	public static function validate_slug( $slug ) {
		if ( ! is_string( $slug ) || strlen( $slug ) > 64 || ! preg_match( '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $slug ) || in_array( $slug, array( 'imajiner', 'imajiner-child', 'con', 'prn', 'aux', 'nul' ), true ) || preg_match( '/^(com|lpt)[0-9]$/D', $slug ) ) {
			return new WP_Error( 'imajiner_setup_slug', __( 'Use a unique lowercase site slug starting with a letter, using only letters, numbers and single hyphens (maximum 64 characters).', 'imajiner-editor' ) );
		}
		return true;
	}

	/** @return array|WP_Error Created theme information. Does not activate the theme. */
	public static function create( $slug, $name ) {
		$allowed = self::permission();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$valid = self::validate_slug( $slug );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$path = wp_get_theme( 'imajiner' )->get_theme_root() . '/' . $slug;
		if ( file_exists( $path ) || is_link( $path ) || wp_get_theme( $slug )->exists() ) {
			return new WP_Error( 'imajiner_setup_exists', __( 'That theme already exists. Setup never overwrites an existing theme.', 'imajiner-editor' ) );
		}
		if ( ! class_exists( 'Imajiner_Filesystem' ) ) {
			return new WP_Error( 'imajiner_setup_filesystem', __( 'The Imajiner filesystem service is unavailable.', 'imajiner-editor' ) );
		}
		return Imajiner_Filesystem::scaffold( $slug, function () use ( $slug, $name ) {
			return self::create_scoped( $slug, $name );
		} );
	}

	private static function create_scoped( $slug, $name ) {
		$allowed = self::permission();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$valid = self::validate_slug( $slug );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( ! is_string( $name ) ) {
			return new WP_Error( 'imajiner_setup_name', __( 'Enter a child-theme name.', 'imajiner-editor' ) );
		}
		$name = str_replace( array( '/*', '*/', "\r", "\n" ), '', sanitize_text_field( $name ) );
		if ( '' === trim( $name ) || strlen( $name ) > 150 ) {
			return new WP_Error( 'imajiner_setup_name', __( 'Enter a child-theme name of at most 150 characters.', 'imajiner-editor' ) );
		}
		$parent = wp_get_theme( 'imajiner' );
		if ( $parent->errors() || ! $parent->exists() ) {
			return new WP_Error( 'imajiner_setup_parent', __( 'Install the Imajiner parent theme before creating a client theme.', 'imajiner-editor' ) );
		}
		if ( ! class_exists( 'Imajiner_Filesystem' ) ) {
			return new WP_Error( 'imajiner_setup_filesystem', __( 'The Imajiner filesystem service is unavailable.', 'imajiner-editor' ) );
		}
		$ready = Imajiner_Filesystem::init();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$root = $parent->get_theme_root();
		$path = $root . '/' . $slug;
		$lock = 'imajiner_child_setup_' . md5( $path );
		if ( ! add_option( $lock, time(), '', false ) ) {
			return new WP_Error( 'imajiner_setup_busy', __( 'Setup for this slug is already in progress. Choose another slug or ask an administrator to check the setup lock.', 'imajiner-editor' ) );
		}
		$created_files = array();
		$created_dirs  = array();
		$success       = false;
		try {
			if ( Imajiner_Filesystem::exists( $path ) || wp_get_theme( $slug )->exists() ) {
				return new WP_Error( 'imajiner_setup_exists', __( 'That theme already exists. Setup never overwrites an existing theme.', 'imajiner-editor' ) );
			}
			$source = $parent->get_stylesheet_directory() . '/child-scaffold/';
			$files  = array();
			foreach ( array( 'functions.php', 'theme.json', 'editor.css', 'screenshot.png', 'README.md' ) as $file ) {
				$content = Imajiner_Filesystem::read( $source . $file );
				if ( is_wp_error( $content ) ) {
					return $content;
				}
				if ( '.php' === substr( $file, -4 ) ) {
					try {
						token_get_all( $content, TOKEN_PARSE );
					} catch ( ParseError $error ) {
						return new WP_Error( 'imajiner_setup_syntax', __( 'The bundled child scaffold failed PHP syntax validation.', 'imajiner-editor' ) );
					}
				}
				$files[ $file ] = $content;
			}
			$files['style.css'] = "/*\nTheme Name: {$name}\nTemplate: imajiner\nDescription: Client-specific Imajiner child theme.\nVersion: 0.1.0\nRequires at least: 6.7\nRequires PHP: 7.4\nText Domain: {$slug}\n*/\n\n/* Site overrides; accepted AI tokens live in assets/css/design-tokens.css. */\n";
			foreach ( array( '', '/assets', '/assets/css', '/imajiner', '/imajiner/css', '/imajiner/parts', '/imajiner/parts/css' ) as $directory ) {
				$result = Imajiner_Filesystem::mkdir( $path . $directory );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$created_dirs[] = $path . $directory;
			}
			foreach ( $files as $file => $content ) {
				$target = $path . '/' . $file;
				$created_files[] = $target;
				$result = Imajiner_Filesystem::write( $target, $content );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
			$success = true;
			wp_clean_themes_cache();
			do_action( 'imajiner_child_theme_created', $slug, $path );
			return array( 'slug' => $slug, 'path' => $path, 'name' => $name );
		} finally {
			if ( ! $success ) {
				foreach ( array_reverse( $created_files ) as $file ) {
					Imajiner_Filesystem::delete( $file );
				}
				foreach ( array_reverse( $created_dirs ) as $directory ) {
					Imajiner_Filesystem::delete( $directory );
				}
			}
			delete_option( $lock );
		}
	}

	/** Activation is a separate explicit decision after creation. @return true|WP_Error */
	public static function activate( $slug, $confirmed = false ) {
		if ( true !== $confirmed || ! current_user_can( 'switch_themes' ) || ! wp_is_file_mod_allowed( 'imajiner_child_theme' ) || is_multisite() ) {
			return new WP_Error( 'imajiner_setup_activation', __( 'Confirm activation with permission to switch themes.', 'imajiner-editor' ) );
		}
		$valid = self::validate_slug( $slug );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() || $theme->errors() || 'imajiner' !== $theme->get_template() || ! $theme->is_allowed() ) {
			return new WP_Error( 'imajiner_setup_theme', __( 'Choose an installed, available Imajiner client child theme.', 'imajiner-editor' ) );
		}
		switch_theme( $slug );
		return true;
	}

	public static function handle_create() {
		check_admin_referer( 'imajiner_create_child_theme' );
		$result = self::create( isset( $_POST['slug'] ) ? wp_unslash( $_POST['slug'] ) : '', isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '' );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'created' => $result['slug'] ), admin_url( 'themes.php' ) ) );
		exit;
	}

	public static function handle_activate() {
		check_admin_referer( 'imajiner_activate_child_theme' );
		$result = self::activate( isset( $_POST['slug'] ) ? wp_unslash( $_POST['slug'] ) : '', isset( $_POST['confirm'] ) && '1' === $_POST['confirm'] );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		wp_safe_redirect( admin_url( 'themes.php' ) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'install_themes' ) ) {
			wp_die( esc_html__( 'You are not allowed to set up client themes.', 'imajiner-editor' ) );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Imajiner Site Setup', 'imajiner-editor' ); ?></h1>
			<p><?php echo esc_html__( 'Create a clean, independently versionable child theme. No templates, images or design tokens are copied from another site. Your current theme stays active until you confirm a switch.', 'imajiner-editor' ); ?></p>
			<?php
			$permission = self::permission();
			if ( is_wp_error( $permission ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $permission->get_error_message() ) . '</p></div></div>';
				return;
			}
			$created = isset( $_GET['created'] ) && is_string( $_GET['created'] ) ? wp_unslash( $_GET['created'] ) : '';
			if ( $created && ! is_wp_error( self::validate_slug( $created ) ) && wp_get_theme( $created )->exists() && 'imajiner' === wp_get_theme( $created )->get_template() ) :
				?>
				<div class="notice notice-success"><p><?php echo esc_html__( 'The child theme is installed. Review it in Appearance → Themes before activation.', 'imajiner-editor' ); ?></p></div>
				<?php if ( current_user_can( 'switch_themes' ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'imajiner_activate_child_theme' ); ?>
					<input type="hidden" name="action" value="imajiner_activate_child_theme">
					<input type="hidden" name="slug" value="<?php echo esc_attr( $created ); ?>">
					<p><label><input type="checkbox" name="confirm" value="1" required> <?php echo esc_html__( 'I confirm switching this site to the new child theme.', 'imajiner-editor' ); ?></label></p>
					<?php submit_button( __( 'Activate child theme', 'imajiner-editor' ), 'secondary' ); ?>
				</form>
				<?php endif; ?>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'imajiner_create_child_theme' ); ?>
				<input type="hidden" name="action" value="imajiner_create_child_theme">
				<p><label for="imajiner-site-name"><?php echo esc_html__( 'Child-theme name', 'imajiner-editor' ); ?></label><br><input id="imajiner-site-name" name="name" class="regular-text" maxlength="150" required></p>
				<p><label for="imajiner-site-slug"><?php echo esc_html__( 'Site slug / theme folder', 'imajiner-editor' ); ?></label><br><input id="imajiner-site-slug" name="slug" class="regular-text" pattern="[a-z][a-z0-9]*(-[a-z0-9]+)*" maxlength="64" required></p>
				<p class="description"><?php echo esc_html__( 'Use a unique lowercase slug, for example client-site. Existing theme folders are never overwritten.', 'imajiner-editor' ); ?></p>
				<?php submit_button( __( 'Create child theme (without activating)', 'imajiner-editor' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Creates an empty client child theme; does not activate it.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : A unique lowercase portable site slug.
	 *
	 * --name=<name>
	 * : The display name of the child theme.
	 *
	 * ## EXAMPLES
	 *
	 *     wp imajiner child-theme create client-site --name="Client Site" --user=administrator
	 */
	public static function cli_create( $args, $assoc_args ) {
		$result = self::create( isset( $args[0] ) ? $args[0] : '', isset( $assoc_args['name'] ) ? $assoc_args['name'] : '' );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}
		/* translators: %s is the new child-theme folder. */
		WP_CLI::success( sprintf( __( 'Created %s. The active theme was not changed. Review it, then explicitly activate it with wp theme activate.', 'imajiner-editor' ), $result['slug'] ) );
	}
}
