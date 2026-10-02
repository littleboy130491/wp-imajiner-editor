<?php
/**
 * Child-theme file management for the Appearance screen.
 *
 * @package Imajiner_Editor
 */

defined( 'ABSPATH' ) || exit;

class Imajiner_Template_Manager {

	public static function init() {
		add_action( 'admin_post_imajiner_manage_template', array( __CLASS__, 'handle' ) );
	}

	public static function permission() {
		if ( ! current_user_can( 'edit_themes' ) || ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) ) {
			return new WP_Error( 'imajiner_forbidden', __( 'You cannot edit theme files.', 'imajiner-editor' ), array( 'status' => 403 ) );
		}
		if ( ! is_child_theme() || ! Imajiner_Editor::theme_ready() ) {
			return new WP_Error( 'imajiner_child_required', __( 'Activate an Imajiner child theme first.', 'imajiner-editor' ) );
		}
		return true;
	}

	public static function handle() {
		$permission = self::permission();
		if ( is_wp_error( $permission ) ) {
			wp_die( esc_html( $permission->get_error_message() ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'imajiner_manage_template' );
		$input = wp_unslash( $_POST );
		$result = self::manage(
			isset( $input['template'] ) && is_string( $input['template'] ) ? $input['template'] : '',
			isset( $input['hash'] ) && is_string( $input['hash'] ) ? $input['hash'] : '',
			isset( $input['operation'] ) && is_string( $input['operation'] ) ? $input['operation'] : '',
			isset( $input['slug'] ) && is_string( $input['slug'] ) ? $input['slug'] : '',
			isset( $input['confirm'] ) && '1' === $input['confirm']
		);
		set_transient( 'imajiner_builder_notice_' . get_current_user_id(), array( is_wp_error( $result ) ? 'error' : 'success', is_wp_error( $result ) ? $result->get_error_message() : __( 'Template files updated.', 'imajiner-editor' ) ), MINUTE_IN_SECONDS );
		wp_safe_redirect( Imajiner_Builder::url() );
		exit;
	}

	/** Validates every existing path component, including the paired stylesheet. */
	public static function path( $key ) {
		$permission = self::permission();
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		if ( ! is_string( $key ) || ! preg_match( '#^(?:parts/[a-z0-9-]+|[a-z0-9_-]+)$#D', $key ) ) {
			return new WP_Error( 'imajiner_path', __( 'Invalid template key.', 'imajiner-editor' ) );
		}
		$root = get_stylesheet_directory();
		if ( is_link( $root ) ) {
			return new WP_Error( 'imajiner_path', __( 'Linked files and folders cannot be managed.', 'imajiner-editor' ) );
		}
		$path = $root . '/' . Imajiner_Editor::TEMPLATE_DIR . '/' . $key . '.php';
		foreach ( array( $path, Imajiner_Template_Store::css_path( $path ) ) as $file ) {
			if ( is_dir( $file ) ) {
				return new WP_Error( 'imajiner_path', __( 'A template or stylesheet path is a folder.', 'imajiner-editor' ) );
			}
			$component = $file;
			while ( wp_normalize_path( $component ) !== wp_normalize_path( $root ) ) {
				if ( is_link( $component ) ) {
					return new WP_Error( 'imajiner_path', __( 'Linked files and folders cannot be managed.', 'imajiner-editor' ) );
				}
				$component = dirname( $component );
			}
		}
		return $path;
	}

	/**
	 * Mutations require a confirmed form and the PHP/CSS hash shown in it.
	 *
	 * @return true|WP_Error
	 */
	public static function manage( $key, $hash, $operation, $slug = '', $confirmed = false ) {
		$path = self::path( $key );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( ! $confirmed || ! in_array( $operation, array( 'rename', 'delete' ), true ) ) {
			return new WP_Error( 'imajiner_confirmation', __( 'Confirm the rename or deletion before continuing.', 'imajiner-editor' ) );
		}
		if ( ! class_exists( 'Imajiner_Filesystem' ) || ! method_exists( 'Imajiner_Template_Store', 'snapshot' ) ) {
			return new WP_Error( 'imajiner_management_unavailable', __( 'Template file management is not available. Check the plugin installation.', 'imajiner-editor' ) );
		}
		$ready = Imajiner_Filesystem::init();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$lock = 'imajiner_manage_' . md5( get_stylesheet_directory() );
		$started = (int) get_option( $lock );
		if ( $started && $started < time() - 5 * MINUTE_IN_SECONDS ) {
			delete_option( $lock );
		}
		if ( ! add_option( $lock, time(), '', false ) ) {
			return new WP_Error( 'imajiner_busy', __( 'This template is being managed by another request.', 'imajiner-editor' ) );
		}
		try {
			if ( ! Imajiner_Filesystem::exists( $path ) ) {
				return new WP_Error( 'imajiner_missing', __( 'Only existing child-theme templates can be managed.', 'imajiner-editor' ) );
			}
			$files = Imajiner_Template_Store::read( $path );
			if ( is_wp_error( $files ) ) {
				return $files;
			}
			if ( ! is_string( $hash ) || ! hash_equals( Imajiner_Template_Store::hash( $files ), $hash ) ) {
				return new WP_Error( 'imajiner_conflict', __( 'The template or stylesheet changed. Reload this screen.', 'imajiner-editor' ), array( 'status' => 409 ) );
			}
			$destination = '';
			$new_key     = '';
			$is_part     = 0 === strpos( $key, 'parts/' );
			if ( 'rename' === $operation ) {
				if ( ! preg_match( '/^[a-z0-9-]+$/D', $slug ) || $slug === basename( $key ) ) {
					return new WP_Error( 'imajiner_slug', __( 'Enter a different file name using lowercase letters, numbers and hyphens.', 'imajiner-editor' ) );
				}
				$new_key     = ( $is_part ? 'parts/' : '' ) . $slug;
				$destination = self::path( $new_key );
				if ( is_wp_error( $destination ) ) {
					return $destination;
				}
				$catalog = $is_part ? imajiner_get_parts() : imajiner_get_templates();
				if ( isset( $catalog[ $slug ] ) || Imajiner_Filesystem::exists( $destination ) || Imajiner_Filesystem::exists( Imajiner_Template_Store::css_path( $destination ) ) ) {
					return new WP_Error( 'imajiner_exists', __( 'That file name or stylesheet already exists.', 'imajiner-editor' ) );
				}
				if ( $is_part ) {
					foreach ( $catalog as $part ) {
						if ( in_array( $slug, $part['aliases'], true ) ) {
							return new WP_Error( 'imajiner_exists', __( 'That name is already a part alias.', 'imajiner-editor' ) );
						}
					}
				}
			}
			$revision = Imajiner_Template_Store::snapshot( $path, $hash, 'delete' === $operation ? __( 'Before deleting template files', 'imajiner-editor' ) : __( 'Before renaming template files', 'imajiner-editor' ) );
			if ( is_wp_error( $revision ) ) {
				return $revision;
			}
			$result = 'delete' === $operation ? self::remove_pair( $path, $files ) : self::rename_pair( $path, $destination, $files, $is_part );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			self::update_assignments( $key, $new_key );
			wp_clean_themes_cache( false );
			return true;
		} finally {
			delete_option( $lock );
		}
	}

	private static function remove_pair( $path, array $files ) {
		$css     = Imajiner_Template_Store::css_path( $path );
		$has_css = Imajiner_Filesystem::exists( $css );
		$result  = Imajiner_Filesystem::delete( $path );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $has_css ) {
			$result = Imajiner_Filesystem::delete( $css );
			if ( is_wp_error( $result ) ) {
				$rollback = Imajiner_Filesystem::write( $path, $files['php'] );
				return is_wp_error( $rollback ) ? $rollback : $result;
			}
		}
		return true;
	}

	private static function rename_pair( $path, $destination, array $files, $is_part ) {
		$old_slug = basename( $path, '.php' );
		$new_slug = basename( $destination, '.php' );
		$new      = $files;
		if ( $is_part ) {
			$headers    = get_file_data( $path, array( 'aliases' => 'Part Aliases' ) );
			$aliases    = array_filter( array_map( 'trim', explode( ',', $headers['aliases'] ) ) );
			$aliases[]  = $old_slug;
			$new['php'] = Imajiner_Builder::set_header( $files['php'], 'Part Aliases', implode( ', ', array_unique( $aliases ) ) );
			if ( is_wp_error( $new['php'] ) ) {
				return $new['php'];
			}
		}
		try {
			token_get_all( $new['php'], TOKEN_PARSE );
		} catch ( ParseError $error ) {
			return new WP_Error( 'imajiner_php_syntax', __( 'The PHP template has a syntax error.', 'imajiner-editor' ) );
		}
		$scope      = $is_part ? '.imj-part-' : '.imj-';
		$new['css'] = preg_replace( '/' . preg_quote( $scope . $old_slug, '/' ) . '(?![a-zA-Z0-9_-])/', $scope . $new_slug, $files['css'] );
		$css        = Imajiner_Template_Store::css_path( $destination );
		$result     = Imajiner_Filesystem::mkdir( dirname( $css ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result = Imajiner_Filesystem::write( $css, $new['css'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result = Imajiner_Filesystem::write( $destination, $new['php'] );
		if ( ! is_wp_error( $result ) ) {
			$result = self::remove_pair( $path, $files );
		}
		if ( is_wp_error( $result ) ) {
			foreach ( array( $destination, $css ) as $file ) {
				if ( Imajiner_Filesystem::exists( $file ) ) {
					$rollback = Imajiner_Filesystem::delete( $file );
					if ( is_wp_error( $rollback ) ) {
						return $rollback;
					}
				}
			}
		}
		return $result;
	}

	private static function update_assignments( $key, $new_key ) {
		global $wpdb;
		$old = Imajiner_Editor::TEMPLATE_DIR . '/' . $key . '.php';
		$new = $new_key ? Imajiner_Editor::TEMPLATE_DIR . '/' . $new_key . '.php' : 'default';
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_page_template' AND meta_value = %s", $old ) );
		foreach ( $ids as $id ) {
			update_post_meta( $id, '_wp_page_template', $new, $old );
		}
		if ( $new_key ) {
			$prefix = basename( get_stylesheet_directory() ) . '/' . Imajiner_Editor::TEMPLATE_DIR . '/';
			$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_imajiner_template' AND meta_value = %s", $prefix . $key . '.php' ) );
			foreach ( $ids as $id ) {
				update_post_meta( $id, '_imajiner_template', $prefix . $new_key . '.php' );
			}
		}
	}

	public static function render_form( $key, $path ) {
		$safe = self::path( $key );
		if ( is_wp_error( $safe ) || wp_normalize_path( $safe ) !== wp_normalize_path( $path ) ) {
			echo '<p>' . esc_html__( 'Parent-theme files are read-only here.', 'imajiner-editor' ) . '</p>';
			return;
		}
		$files = Imajiner_Template_Store::read( $path );
		if ( is_wp_error( $files ) ) {
			return;
		}
		?>
		<details>
			<summary><?php esc_html_e( 'Rename or delete', 'imajiner-editor' ); ?></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="imajiner_manage_template">
				<input type="hidden" name="template" value="<?php echo esc_attr( $key ); ?>">
				<input type="hidden" name="hash" value="<?php echo esc_attr( Imajiner_Template_Store::hash( $files ) ); ?>">
				<?php wp_nonce_field( 'imajiner_manage_template' ); ?>
				<p><label><?php esc_html_e( 'New file name (without .php)', 'imajiner-editor' ); ?> <input name="slug" pattern="[a-z0-9-]+" value="<?php echo esc_attr( basename( $key ) ); ?>"></label></p>
				<p><label><input type="checkbox" name="confirm" value="1" required> <?php esc_html_e( 'I confirm changing these PHP/CSS files and their assignments. Deletion uses the theme defaults; explicit calls to a deleted part will stop rendering it. A revision is kept.', 'imajiner-editor' ); ?></label></p>
				<button class="button" name="operation" value="rename"><?php esc_html_e( 'Rename', 'imajiner-editor' ); ?></button>
				<button class="button" name="operation" value="delete"><?php esc_html_e( 'Delete', 'imajiner-editor' ); ?></button>
			</form>
		</details>
		<?php
	}
}
