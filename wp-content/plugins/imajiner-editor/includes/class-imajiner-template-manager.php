<?php
/**
 * Child-theme template lifecycle operations.
 *
 * @package Imajiner_Editor
 */

defined( 'ABSPATH' ) || exit;

class Imajiner_Template_Manager {

	public static function init() {
		add_action( 'admin_post_imajiner_manage_template', array( __CLASS__, 'handle' ) );
	}

	public static function handle() {
		$result = self::manage( wp_unslash( $_POST ) ); // Nonce verified by manage().
		set_transient(
			'imajiner_builder_notice_' . get_current_user_id(),
			array( is_wp_error( $result ) ? 'error' : 'success', is_wp_error( $result ) ? $result->get_error_message() : __( 'Template files and assignments updated. A revision was kept.', 'imajiner-editor' ) ),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( Imajiner_Builder::url() );
		exit;
	}

	/** Validates every form value before any filesystem operation. */
	public static function manage( array $request ) {
		if ( ! Imajiner_Editor::user_can_edit_templates() || ! Imajiner_Editor::theme_ready() || ! is_child_theme() ) {
			return new WP_Error( 'imajiner_forbidden', __( 'You are not allowed to manage child-theme templates.', 'imajiner-editor' ), array( 'status' => 403 ) );
		}
		$key = isset( $request['template'] ) && is_string( $request['template'] ) ? $request['template'] : '';
		if ( empty( $request['_wpnonce'] ) || ! is_string( $request['_wpnonce'] ) || ! wp_verify_nonce( $request['_wpnonce'], 'imajiner_manage_template_' . $key ) ) {
			return new WP_Error( 'imajiner_nonce', __( 'The confirmation expired. Reload and try again.', 'imajiner-editor' ), array( 'status' => 403 ) );
		}
		if ( ! isset( $request['confirm'] ) || 'yes' !== $request['confirm'] ) {
			return new WP_Error( 'imajiner_confirmation', __( 'Confirm this file operation first.', 'imajiner-editor' ) );
		}
		$operation = isset( $request['operation'] ) ? $request['operation'] : '';
		if ( ! in_array( $operation, array( 'rename', 'delete' ), true ) ) {
			return new WP_Error( 'imajiner_operation', __( 'Unknown file operation.', 'imajiner-editor' ) );
		}
		$path = self::path( $key );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( ! class_exists( 'Imajiner_Filesystem' ) || ! method_exists( 'Imajiner_Template_Store', 'snapshot' ) ) {
			return new WP_Error( 'imajiner_storage_unavailable', __( 'Template lifecycle storage is unavailable.', 'imajiner-editor' ) );
		}
		$ready = Imajiner_Filesystem::init();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		if ( ! Imajiner_Filesystem::exists( $path ) ) {
			return new WP_Error( 'imajiner_missing', __( 'The child-theme template no longer exists.', 'imajiner-editor' ) );
		}
		$files = Imajiner_Template_Store::read( $path );
		if ( is_wp_error( $files ) ) {
			return $files;
		}
		$hash = isset( $request['hash'] ) && is_string( $request['hash'] ) ? $request['hash'] : '';
		if ( ! hash_equals( Imajiner_Template_Store::hash( $files ), $hash ) ) {
			return new WP_Error( 'imajiner_conflict', __( 'The template or stylesheet changed. Reload before continuing.', 'imajiner-editor' ), array( 'status' => 409 ) );
		}
		$target = '';
		if ( 'rename' === $operation ) {
			$slug = isset( $request['slug'] ) && is_string( $request['slug'] ) ? $request['slug'] : '';
			$new_key = ( 0 === strpos( $key, 'parts/' ) ? 'parts/' : '' ) . $slug;
			$target = self::path( $new_key );
			if ( is_wp_error( $target ) ) {
				return $target;
			}
			if ( Imajiner_Editor::get_template( $new_key ) || Imajiner_Filesystem::exists( $target ) || Imajiner_Filesystem::exists( Imajiner_Template_Store::css_path( $target ) ) ) {
				return new WP_Error( 'imajiner_exists', __( 'That template name or stylesheet already exists.', 'imajiner-editor' ) );
			}
		}
		$revision = Imajiner_Template_Store::snapshot( $path, $hash, 'rename' === $operation ? __( 'Before renaming the template', 'imajiner-editor' ) : __( 'Before deleting the template', 'imajiner-editor' ) );
		if ( is_wp_error( $revision ) ) {
			return $revision;
		}
		$css = Imajiner_Template_Store::css_path( $path );
		$has_css = Imajiner_Filesystem::exists( $css );
		if ( 'delete' === $operation ) {
			$result = $has_css ? Imajiner_Filesystem::delete( $css ) : true;
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$result = Imajiner_Filesystem::delete( $path );
			if ( is_wp_error( $result ) ) {
				if ( $has_css ) {
					$rollback = self::restore_css( $css, $files['css'] );
					if ( is_wp_error( $rollback ) ) {
						return $rollback;
					}
				}
				return $result;
			}
		} else {
			$new_css = Imajiner_Template_Store::css_path( $target );
			if ( $has_css ) {
				$result = Imajiner_Filesystem::move( $css, $new_css );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$scope = 0 === strpos( $key, 'parts/' ) ? '.imj-part-' : '.imj-';
				$source = preg_replace( '/' . preg_quote( $scope . basename( $key ), '/' ) . '(?![a-zA-Z0-9_-])/', $scope . basename( $new_key ), $files['css'] );
				$result = Imajiner_Filesystem::write( $new_css, $source );
				if ( is_wp_error( $result ) ) {
					$rollback = self::restore_css( $css, $files['css'], $new_css );
					if ( is_wp_error( $rollback ) ) {
						return $rollback;
					}
					return $result;
				}
			}
			$result = Imajiner_Filesystem::move( $path, $target );
			if ( is_wp_error( $result ) ) {
				if ( $has_css ) {
					$rollback = self::restore_css( $css, $files['css'], $new_css );
					if ( is_wp_error( $rollback ) ) {
						return $rollback;
					}
				}
				return $result;
			}
			self::move_revisions( $path, $target );
		}
		self::update_assignments( $key, $target ? $new_key : '' );
		foreach ( array_filter( array( $path, $target ) ) as $file ) {
			if ( function_exists( 'opcache_invalidate' ) ) {
				opcache_invalidate( $file, true );
			}
		}
		wp_clean_themes_cache( false );
		return true;
	}

	/** Only literal child-template paths, including safe CSS ancestors. */
	public static function path( $key ) {
		if ( ! is_child_theme() || ! is_string( $key ) || ! preg_match( '/^(?:[a-z0-9_-]+|parts\/[a-z0-9-]+)$/D', $key ) ) {
			return new WP_Error( 'imajiner_path', __( 'Choose a valid child-theme template file name.', 'imajiner-editor' ) );
		}
		$root = get_stylesheet_directory();
		$path = $root . '/' . Imajiner_Editor::TEMPLATE_DIR . '/' . $key . '.php';
		foreach ( array( $path, Imajiner_Template_Store::css_path( $path ) ) as $file ) {
			if ( file_exists( $file ) && ! is_file( $file ) ) {
				return new WP_Error( 'imajiner_path', __( 'Only regular template files can be changed.', 'imajiner-editor' ) );
			}
			for ( $check = $file; strlen( $check ) >= strlen( $root ); $check = dirname( $check ) ) {
				if ( is_link( $check ) || ( file_exists( $check ) && 0 !== strpos( wp_normalize_path( realpath( $check ) ), wp_normalize_path( realpath( $root ) ) . '/' ) && $check !== $root ) ) {
					return new WP_Error( 'imajiner_path', __( 'Linked or out-of-scope theme paths cannot be changed.', 'imajiner-editor' ) );
				}
			}
		}
		return $path;
	}

	private static function restore_css( $path, $source, $renamed = '' ) {
		$result = $renamed ? Imajiner_Filesystem::move( $renamed, $path ) : true;
		if ( ! is_wp_error( $result ) ) {
			$result = Imajiner_Filesystem::write( $path, $source );
		}
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'imajiner_rollback', __( 'The operation failed and its stylesheet could not be restored. Recover the saved revision before continuing.', 'imajiner-editor' ) );
		}
		return true;
	}

	private static function update_assignments( $old_key, $new_key ) {
		if ( 0 === strpos( $old_key, 'parts/' ) ) {
			return;
		}
		global $wpdb;
		$old = Imajiner_Editor::TEMPLATE_DIR . '/' . $old_key . '.php';
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_page_template' AND meta_value = %s", $old ) );
		foreach ( $ids as $id ) {
			update_post_meta( $id, '_wp_page_template', $new_key ? Imajiner_Editor::TEMPLATE_DIR . '/' . $new_key . '.php' : 'default', $old );
		}
	}

	private static function move_revisions( $old_path, $new_path ) {
		$root = wp_normalize_path( trailingslashit( get_theme_root() ) );
		$old = ltrim( str_replace( $root, '', wp_normalize_path( $old_path ) ), '/' );
		$new = ltrim( str_replace( $root, '', wp_normalize_path( $new_path ) ), '/' );
		$ids = get_posts( array( 'post_type' => Imajiner_Template_Store::REVISION_POST_TYPE, 'post_status' => 'private', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_imajiner_template', 'meta_value' => $old ) );
		foreach ( $ids as $id ) {
			update_post_meta( $id, '_imajiner_template', $new );
			$scope = false !== strpos( $old_path, '/parts/' ) ? '.imj-part-' : '.imj-';
			$css = (string) get_post_meta( $id, '_imajiner_css', true );
			$css = preg_replace( '/' . preg_quote( $scope . basename( $old_path, '.php' ), '/' ) . '(?![a-zA-Z0-9_-])/', $scope . basename( $new_path, '.php' ), $css );
			update_post_meta( $id, '_imajiner_css', wp_slash( $css ) );
		}
	}

	public static function render_controls( $key ) {
		$path = self::path( $key );
		if ( is_wp_error( $path ) || ! is_file( $path ) ) {
			return;
		}
		$files = Imajiner_Template_Store::read( $path );
		if ( is_wp_error( $files ) ) {
			return;
		}
		?>
		<details><summary><?php esc_html_e( 'Rename or delete', 'imajiner-editor' ); ?></summary>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="imajiner_manage_template">
			<input type="hidden" name="template" value="<?php echo esc_attr( $key ); ?>">
			<input type="hidden" name="hash" value="<?php echo esc_attr( Imajiner_Template_Store::hash( $files ) ); ?>">
			<?php wp_nonce_field( 'imajiner_manage_template_' . $key ); ?>
			<p><label><?php esc_html_e( 'New file name (without .php)', 'imajiner-editor' ); ?> <input name="slug" value="<?php echo esc_attr( basename( $key ) ); ?>" pattern="[a-z0-9_-]+"></label></p>
			<p class="description"><?php esc_html_e( 'PHP and paired CSS move together; page assignments and revisions follow the new name. Deleting resets page assignments to default. Existing parent-theme files may become visible again. Explicit PHP calls to a renamed part must be updated manually.', 'imajiner-editor' ); ?></p>
			<p><label><input type="checkbox" name="confirm" value="yes" required> <?php esc_html_e( 'I confirm this file operation and have checked explicit part references.', 'imajiner-editor' ); ?></label></p>
			<button class="button" name="operation" value="rename"><?php esc_html_e( 'Rename', 'imajiner-editor' ); ?></button>
			<button class="button" name="operation" value="delete"><?php esc_html_e( 'Delete PHP and CSS', 'imajiner-editor' ); ?></button>
		</form>
		</details>
		<?php
	}
}
