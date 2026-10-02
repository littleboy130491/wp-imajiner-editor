<?php
/**
 * Appearance → Imajiner Templates: create and manage templates, template parts and their locations.
 *
 * Assignments live in the files' headers ("Imajiner Location:" for templates,
 * "Part Location:" for parts), so they travel with the child theme in git.
 * Changing them here rewrites that header line through the template store,
 * which keeps a revision of the previous version.
 *
 * @package Imajiner_Editor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Template manager screen.
 */
class Imajiner_Builder {

	const PAGE = 'imajiner-templates';

	/**
	 * Hooks the screen and its form handlers.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_post_imajiner_create_template', array( __CLASS__, 'create_template' ) );
		add_action( 'admin_post_imajiner_create_part', array( __CLASS__, 'create_part' ) );
		add_action( 'admin_post_imajiner_save_locations', array( __CLASS__, 'save_locations' ) );
	}

	/**
	 * Screen URL.
	 *
	 * @return string
	 */
	public static function url() {
		return admin_url( 'themes.php?page=' . self::PAGE );
	}

	/**
	 * Adds the screen under Appearance.
	 */
	public static function add_page() {
		add_theme_page(
			__( 'Imajiner Templates', 'imajiner-editor' ),
			__( 'Imajiner Templates', 'imajiner-editor' ),
			'edit_themes',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Creates a page or single/archive template from a starter, then opens it in the editor.
	 */
	public static function create_template() {
		self::check_request( 'imajiner_create_template' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in check_request().
		$name     = isset( $_POST['name'] ) ? self::header_value( wp_unslash( $_POST['name'] ) ) : '';
		$kind     = isset( $_POST['kind'] ) && 'location' === $_POST['kind'] ? 'location' : 'page';
		$location = isset( $_POST['location'] ) ? sanitize_text_field( wp_unslash( $_POST['location'] ) ) : '';
		// phpcs:enable

		$slug = sanitize_title( $name );
		if ( '' === $name || ! preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
			self::redirect_with( 'error', __( 'Enter a template name using letters and numbers.', 'imajiner-editor' ) );
		}
		if ( 'location' === $kind && '' !== $location && ! isset( imajiner_template_locations()[ $location ] ) ) {
			self::redirect_with( 'error', __( 'Unknown location.', 'imajiner-editor' ) );
		}

		$path   = get_stylesheet_directory() . '/' . Imajiner_Editor::TEMPLATE_DIR . '/' . $slug . '.php';
		$result = Imajiner_Template_Store::create(
			$path,
			array(
				'php' => self::starter_template( $name, $kind, $location ),
				'css' => '/* Styles for ' . Imajiner_Editor::TEMPLATE_DIR . '/' . $slug . ".php, scoped to its body class .imj-{$slug}. */\n",
			)
		);
		if ( is_wp_error( $result ) ) {
			self::redirect_with( 'error', $result->get_error_message() );
		}

		// Another template may already claim the location; the new one takes it over.
		if ( 'location' === $kind && '' !== $location ) {
			self::assign_locations( array( $location => $slug ), array( $location ) );
		}

		wp_safe_redirect( Imajiner_Editor::editor_url( $slug ) );
		exit;
	}

	/**
	 * Creates a template part from a starter, then opens it in the editor.
	 */
	public static function create_part() {
		self::check_request( 'imajiner_create_part' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in check_request().
		$name     = isset( $_POST['name'] ) ? self::header_value( wp_unslash( $_POST['name'] ) ) : '';
		$location = isset( $_POST['location'] ) ? sanitize_key( wp_unslash( $_POST['location'] ) ) : '';
		$starter  = isset( $_POST['starter'] ) ? sanitize_key( wp_unslash( $_POST['starter'] ) ) : 'blank';
		// phpcs:enable

		$slug = sanitize_title( $name );
		if ( '' === $name || ! preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
			self::redirect_with( 'error', __( 'Enter a part name using letters and numbers.', 'imajiner-editor' ) );
		}
		if ( '' !== $location && ! isset( imajiner_part_locations()[ $location ] ) ) {
			self::redirect_with( 'error', __( 'Unknown location.', 'imajiner-editor' ) );
		}

		$path   = get_stylesheet_directory() . '/' . IMAJINER_PARTS_DIR . '/' . $slug . '.php';
		$result = Imajiner_Template_Store::create(
			$path,
			array(
				'php' => self::starter_part( $name, $slug, $location, $starter ),
				'css' => '/* Styles for the "' . $name . "\" part, scoped to its wrapper .imj-part-{$slug}. */\n",
			)
		);
		if ( is_wp_error( $result ) ) {
			self::redirect_with( 'error', $result->get_error_message() );
		}

		wp_safe_redirect( Imajiner_Editor::editor_url( 'parts/' . $slug ) );
		exit;
	}

	/**
	 * Saves template locations and part locations.
	 */
	public static function save_locations() {
		self::check_request( 'imajiner_save_locations' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in check_request().
		$templates = imajiner_get_templates();
		$wanted    = array();
		$posted    = isset( $_POST['locations'] ) && is_array( $_POST['locations'] ) ? wp_unslash( $_POST['locations'] ) : array();
		foreach ( imajiner_template_locations() as $location => $label ) {
			$key = isset( $posted[ $location ] ) ? sanitize_text_field( $posted[ $location ] ) : '';
			if ( '' !== $key && isset( $templates[ $key ] ) ) {
				$wanted[ $location ] = $key;
			}
		}
		$changed = self::assign_locations( $wanted, array_keys( imajiner_template_locations() ) );

		$parts       = imajiner_get_parts();
		$part_posted = isset( $_POST['parts'] ) && is_array( $_POST['parts'] ) ? wp_unslash( $_POST['parts'] ) : array();
		// phpcs:enable
		foreach ( $parts as $slug => $part ) {
			if ( ! isset( $part_posted[ $slug ] ) ) {
				continue;
			}
			$location = sanitize_key( $part_posted[ $slug ] );
			$location = isset( imajiner_part_locations()[ $location ] ) ? $location : '';
			if ( $location !== $part['location'] ) {
				$result = self::update_header( $part['file'], 'Part Location', $location, __( 'Before changing the part location', 'imajiner-editor' ) );
				if ( is_wp_error( $result ) ) {
					self::redirect_with( 'error', $result->get_error_message() );
				}
				++$changed;
			}
		}

		self::redirect_with(
			'success',
			$changed
				/* translators: %d: number of files changed. */
				? sprintf( _n( 'Locations saved (%d file updated).', 'Locations saved (%d files updated).', $changed, 'imajiner-editor' ), $changed )
				: __( 'No changes.', 'imajiner-editor' )
		);
	}

	/**
	 * Rewrites templates' "Imajiner Location" headers so each managed location belongs to the wanted template.
	 *
	 * @param array    $wanted  Location => template key.
	 * @param string[] $managed Locations being set; others in the headers are kept as they are.
	 * @return int Number of files changed.
	 */
	private static function assign_locations( array $wanted, array $managed ) {
		$changed = 0;
		foreach ( imajiner_get_templates() as $key => $template ) {
			$locations = array_values( array_diff( $template['locations'], $managed ) );
			foreach ( $wanted as $location => $owner ) {
				if ( $owner === $key ) {
					$locations[] = $location;
				}
			}

			if ( $locations === $template['locations'] ) {
				continue;
			}
			$result = self::update_header( $template['file'], 'Imajiner Location', implode( ', ', $locations ), __( 'Before changing template locations', 'imajiner-editor' ) );
			if ( is_wp_error( $result ) ) {
				self::redirect_with( 'error', $result->get_error_message() );
			}
			++$changed;
		}
		return $changed;
	}

	/**
	 * Sets one header line in a template file, keeping a revision.
	 *
	 * @param string $path   File.
	 * @param string $header Header name, e.g. "Imajiner Location".
	 * @param string $value  New value; '' removes the line.
	 * @param string $note   Revision note.
	 * @return true|WP_Error
	 */
	private static function update_header( $path, $header, $value, $note ) {
		$files = Imajiner_Template_Store::read( $path );
		if ( is_wp_error( $files ) ) {
			return $files;
		}

		$php = self::set_header( $files['php'], $header, $value );
		if ( is_wp_error( $php ) ) {
			return $php;
		}

		return Imajiner_Template_Store::write( $path, Imajiner_Template_Store::hash( $files ), array_merge( $files, array( 'php' => $php ) ), $note );
	}

	/**
	 * Sets a header line in a file's first comment block, the way WordPress reads headers.
	 *
	 * @param string $source PHP source.
	 * @param string $header Header name.
	 * @param string $value  New value; '' removes the line.
	 * @return string|WP_Error
	 */
	public static function set_header( $source, $header, $value ) {
		// Same line format get_file_data() reads, limited to the first 8 KB like WordPress.
		$pattern = '/^([ \t\/*#@]*' . preg_quote( $header, '/' ) . ':)(.*)$/mi';
		if ( preg_match( $pattern, $source, $match, PREG_OFFSET_CAPTURE ) && $match[0][1] < 8192 ) {
			if ( '' === $value ) {
				// Remove the whole line, including exactly one line break.
				$end = $match[0][1] + strlen( $match[0][0] );
				$end = $end + ( "\r\n" === substr( $source, $end, 2 ) ? 2 : ( "\n" === substr( $source, $end, 1 ) ? 1 : 0 ) );
				return substr( $source, 0, $match[0][1] ) . substr( $source, $end );
			}
			return substr_replace( $source, ' ' . $value, $match[2][1], strlen( $match[2][0] ) );
		}

		if ( '' === $value ) {
			return $source;
		}

		// Add it after the name header, or after the opening of the first doc comment.
		if ( preg_match( '/^([ \t\/*#@]*)(Template Name|Part Name):.*$/mi', $source, $anchor, PREG_OFFSET_CAPTURE ) && $anchor[0][1] < 8192 ) {
			$position = $anchor[0][1] + strlen( $anchor[0][0] );
			return substr_replace( $source, "\n" . $anchor[1][0] . $header . ': ' . $value, $position, 0 );
		}
		$position = strpos( $source, '/**' );
		if ( false !== $position && $position < 8192 ) {
			return substr_replace( $source, "\n * " . $header . ': ' . $value, $position + 3, 0 );
		}

		return new WP_Error( 'imajiner_no_header', __( 'The file has no header comment to add the location to.', 'imajiner-editor' ) );
	}

	/**
	 * Starter source for a new template.
	 *
	 * @param string $name     Template name.
	 * @param string $kind     'page' or 'location'.
	 * @param string $location Location, for single/archive templates.
	 * @return string
	 */
	private static function starter_template( $name, $kind, $location ) {
		$headers = array( ' * Template Name: ' . $name );
		list( $loc_kind, $loc_name ) = array_pad( explode( ':', $location, 2 ), 2, '' );

		if ( 'location' === $kind && 'single' === $loc_kind && $loc_name ) {
			// Also selectable per post in that post type's Template dropdown.
			$headers[] = ' * Template Post Type: ' . $loc_name;
		}
		if ( 'location' === $kind && '' !== $location ) {
			$headers[] = ' * Imajiner Location: ' . $location;
		}

		if ( 'page' === $kind ) {
			$body = <<<'HTML'
<!-- imj:section name="intro" -->
<section class="intro">
	<div class="container">
		<h1 class="intro__title">{NAME}</h1>
		<p class="intro__text">Start editing this section in the Imajiner Editor.</p>
	</div>
</section>
<!-- /imj:section -->
HTML;
		} elseif ( 'single' === $loc_kind ) {
			$body = <<<'HTML'
<!-- imj:section name="entry" -->
<article class="entry">
	<div class="container">
		<?php while ( have_posts() ) : ?>
			<?php the_post(); ?>
			<h1 class="entry__title"><?php the_title(); ?></h1>
			<div class="entry__content"><?php the_content(); ?></div>
		<?php endwhile; ?>
	</div>
</article>
<!-- /imj:section -->
HTML;
		} elseif ( '404' === $loc_kind ) {
			$body = <<<'HTML'
<!-- imj:section name="not-found" -->
<section class="not-found">
	<div class="container">
		<h1 class="not-found__title">Page not found</h1>
		<p class="not-found__text">The page you are looking for does not exist.</p>
		<a class="button" href="<?php echo esc_url( home_url( '/' ) ); ?>">Back to the home page</a>
	</div>
</section>
<!-- /imj:section -->
HTML;
		} else {
			$title = 'search' === $loc_kind
				? '<?php printf( esc_html__( \'Search results for: %s\', \'imajiner\' ), esc_html( get_search_query() ) ); ?>'
				: '<?php the_archive_title(); ?>';
			$body  = <<<'HTML'
<!-- imj:section name="archive-header" -->
<section class="archive-header">
	<div class="container">
		<h1 class="archive-header__title">{TITLE}</h1>
	</div>
</section>
<!-- /imj:section -->

<!-- imj:section name="posts" -->
<section class="posts">
	<div class="container">
		<?php if ( have_posts() ) : ?>
			<div class="posts__grid">
				<?php while ( have_posts() ) : ?>
					<?php the_post(); ?>
					<article class="posts__card">
						<h2 class="posts__title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
						<p class="posts__excerpt"><?php echo esc_html( get_the_excerpt() ); ?></p>
					</article>
				<?php endwhile; ?>
			</div>
			<?php the_posts_pagination(); ?>
		<?php else : ?>
			<p class="posts__empty">Nothing found.</p>
		<?php endif; ?>
	</div>
</section>
<!-- /imj:section -->
HTML;
			$body  = str_replace( '{TITLE}', $title, $body );
		}

		$body = str_replace( '{NAME}', esc_html( $name ), $body );

		return "<?php\n/**\n" . implode( "\n", $headers ) . "\n *\n * @package Imajiner\n */\n\nget_header();\n?>\n\n" . $body . "\n\n<?php\nget_footer();\n";
	}

	/**
	 * Starter source for a new template part.
	 *
	 * @param string $name     Part name.
	 * @param string $slug     Part slug.
	 * @param string $location Location, or ''.
	 * @param string $starter  'blank', 'header' or 'footer'.
	 * @return string
	 */
	private static function starter_part( $name, $slug, $location, $starter ) {
		$descriptions = array(
			'header' => 'Site header with logo and primary menu.',
			'footer' => 'Site footer with footer menu and copyright.',
		);

		$headers = array( ' * Part Name: ' . $name );
		if ( '' !== $location ) {
			$headers[] = ' * Part Location: ' . $location;
		}
		$headers[] = ' * Part Description: ' . ( isset( $descriptions[ $starter ] ) ? $descriptions[ $starter ] : $name . '.' );

		if ( 'header' === $starter ) {
			$body = <<<'HTML'
<header class="site-header">
	<div class="container site-header__inner">
		<div class="site-branding">
			<?php if ( has_custom_logo() ) : ?>
				<?php the_custom_logo(); ?>
			<?php else : ?>
				<a class="site-title" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home"><?php bloginfo( 'name' ); ?></a>
			<?php endif; ?>
		</div>
		<?php if ( has_nav_menu( 'primary' ) ) : ?>
			<nav class="site-nav" aria-label="Primary">
				<?php wp_nav_menu( array( 'theme_location' => 'primary', 'container' => false, 'menu_class' => 'site-nav__menu', 'depth' => 2 ) ); ?>
			</nav>
		<?php endif; ?>
	</div>
</header>
HTML;
		} elseif ( 'footer' === $starter ) {
			$body = <<<'HTML'
<footer class="site-footer">
	<div class="container site-footer__inner">
		<?php if ( has_nav_menu( 'footer' ) ) : ?>
			<nav class="site-footer__nav" aria-label="Footer">
				<?php wp_nav_menu( array( 'theme_location' => 'footer', 'container' => false, 'menu_class' => 'site-footer__menu', 'depth' => 1 ) ); ?>
			</nav>
		<?php endif; ?>
		<p class="site-footer__copy">&copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?></p>
	</div>
</footer>
HTML;
		} else {
			$body = <<<'HTML'
<section class="{SLUG}">
	<div class="container">
		<h2 class="{SLUG}__title">{NAME}</h2>
		<p class="{SLUG}__text">Edit this part in the Imajiner Editor.</p>
	</div>
</section>
HTML;
			$body = str_replace( array( '{SLUG}', '{NAME}' ), array( $slug, esc_html( $name ) ), $body );
		}

		return "<?php\n/**\n" . implode( "\n", $headers ) . "\n *\n * @package Imajiner\n */\n\n?>\n" . $body . "\n";
	}

	/**
	 * Renders the screen.
	 */
	public static function render() {
		$templates      = imajiner_get_templates();
		$parts          = imajiner_get_parts();
		$locations      = imajiner_template_locations();
		$assignments    = imajiner_location_assignments();
		$part_locations = imajiner_part_locations();
		$notice         = get_transient( 'imajiner_builder_notice_' . get_current_user_id() );
		delete_transient( 'imajiner_builder_notice_' . get_current_user_id() );

		// Templates claiming the same location: only the first is used.
		$claims = array();
		foreach ( $templates as $template ) {
			foreach ( $template['locations'] as $location ) {
				$claims[ $location ][] = $template['name'];
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Imajiner Templates', 'imajiner-editor' ); ?></h1>
			<p style="max-width:860px"><?php esc_html_e( 'Template parts are reusable pieces like the header and footer. Templates design whole pages: page templates are chosen per page, single and archive templates apply to the locations you assign below. Everything is saved as files in the child theme.', 'imajiner-editor' ); ?></p>

			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="imajiner_save_locations">
				<?php wp_nonce_field( 'imajiner_save_locations' ); ?>

				<h2><?php esc_html_e( 'Template parts', 'imajiner-editor' ); ?></h2>
				<table class="widefat striped" style="max-width:960px">
					<thead><tr><th><?php esc_html_e( 'Part', 'imajiner-editor' ); ?></th><th><?php esc_html_e( 'Location', 'imajiner-editor' ); ?></th><th><?php esc_html_e( 'Use in a template', 'imajiner-editor' ); ?></th><th></th></tr></thead>
					<tbody>
						<?php if ( ! $parts ) : ?>
							<tr><td colspan="4"><?php esc_html_e( 'No template parts yet.', 'imajiner-editor' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $parts as $slug => $part ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $part['name'] ); ?></strong><br><span class="description"><?php echo esc_html( $part['description'] ); ?></span></td>
								<td>
									<select name="parts[<?php echo esc_attr( $slug ); ?>]">
										<option value=""><?php esc_html_e( 'None (only where a template includes it)', 'imajiner-editor' ); ?></option>
										<?php foreach ( $part_locations as $value => $label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $part['location'], $value ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td><code>&lt;?php imajiner_part( '<?php echo esc_html( $slug ); ?>' ); ?&gt;</code></td>
								<td><a class="button" href="<?php echo esc_url( Imajiner_Editor::editor_url( 'parts/' . $slug ) ); ?>"><?php esc_html_e( 'Edit', 'imajiner-editor' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Locations', 'imajiner-editor' ); ?></h2>
				<p class="description" style="max-width:860px"><?php esc_html_e( 'Choose the template for each kind of page. Post types and taxonomies are detected from the site. The most specific match wins: a template for "Single Product" is used before one for "All single posts and pages". A template chosen on an individual page or post overrides its location.', 'imajiner-editor' ); ?></p>
				<table class="widefat striped" style="max-width:960px">
					<thead><tr><th><?php esc_html_e( 'Location', 'imajiner-editor' ); ?></th><th><?php esc_html_e( 'Template', 'imajiner-editor' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $locations as $location => $label ) : ?>
							<tr>
								<td><?php echo esc_html( $label ); ?> <code><?php echo esc_html( $location ); ?></code></td>
								<td>
									<select name="locations[<?php echo esc_attr( $location ); ?>]">
										<option value=""><?php esc_html_e( 'Theme default', 'imajiner-editor' ); ?></option>
										<?php foreach ( $templates as $key => $template ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>" <?php selected( isset( $assignments[ $location ] ) ? $assignments[ $location ] : '', $key ); ?>><?php echo esc_html( $template['name'] ); ?></option>
										<?php endforeach; ?>
									</select>
									<?php if ( isset( $claims[ $location ] ) && count( $claims[ $location ] ) > 1 ) : ?>
										<p class="description" style="color:#b32d2e">
											<?php
											/* translators: %s: template names. */
											printf( esc_html__( 'Claimed by several templates (%s); the first is used. Saving keeps only the selected one.', 'imajiner-editor' ), esc_html( implode( ', ', $claims[ $location ] ) ) );
											?>
										</p>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button( __( 'Save locations', 'imajiner-editor' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Templates', 'imajiner-editor' ); ?></h2>
			<table class="widefat striped" style="max-width:960px">
				<thead><tr><th><?php esc_html_e( 'Template', 'imajiner-editor' ); ?></th><th><?php esc_html_e( 'Used for', 'imajiner-editor' ); ?></th><th></th></tr></thead>
				<tbody>
					<?php foreach ( $templates as $key => $template ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $template['name'] ); ?></strong><br><code><?php echo esc_html( Imajiner_Editor::TEMPLATE_DIR . '/' . $key . '.php' ); ?></code></td>
							<td>
								<?php
								$used = array();
								foreach ( $template['locations'] as $location ) {
									$used[] = isset( $locations[ $location ] ) ? $locations[ $location ] : $location;
								}
								if ( $template['is_page'] || $template['post_types'] ) {
									/* translators: %s: post types. */
									$used[] = sprintf( __( 'Pages that choose it (%s)', 'imajiner-editor' ), implode( ', ', $template['post_types'] ? $template['post_types'] : array( 'page' ) ) );
								}
								echo esc_html( $used ? implode( '; ', $used ) : __( 'Not assigned', 'imajiner-editor' ) );
								?>
							</td>
							<td><a class="button" href="<?php echo esc_url( Imajiner_Editor::editor_url( $key ) ); ?>"><?php esc_html_e( 'Edit', 'imajiner-editor' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div style="display:flex;flex-wrap:wrap;gap:24px;margin-top:24px;max-width:960px">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="card" style="flex:1;min-width:300px;margin:0">
					<h2><?php esc_html_e( 'New template part', 'imajiner-editor' ); ?></h2>
					<input type="hidden" name="action" value="imajiner_create_part">
					<?php wp_nonce_field( 'imajiner_create_part' ); ?>
					<p><label><?php esc_html_e( 'Name', 'imajiner-editor' ); ?><br><input type="text" name="name" class="regular-text" required placeholder="<?php esc_attr_e( 'e.g. Before footer CTA', 'imajiner-editor' ); ?>"></label></p>
					<p><label><?php esc_html_e( 'Location', 'imajiner-editor' ); ?><br>
						<select name="location">
							<option value=""><?php esc_html_e( 'None (only where a template includes it)', 'imajiner-editor' ); ?></option>
							<?php foreach ( $part_locations as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label></p>
					<p><label><?php esc_html_e( 'Start from', 'imajiner-editor' ); ?><br>
						<select name="starter">
							<option value="blank"><?php esc_html_e( 'A blank section', 'imajiner-editor' ); ?></option>
							<option value="header"><?php esc_html_e( 'A copy of the default header', 'imajiner-editor' ); ?></option>
							<option value="footer"><?php esc_html_e( 'A copy of the default footer', 'imajiner-editor' ); ?></option>
						</select>
					</label></p>
					<?php submit_button( __( 'Create part', 'imajiner-editor' ), 'secondary', 'submit', false ); ?>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="card" style="flex:1;min-width:300px;margin:0">
					<h2><?php esc_html_e( 'New template', 'imajiner-editor' ); ?></h2>
					<input type="hidden" name="action" value="imajiner_create_template">
					<?php wp_nonce_field( 'imajiner_create_template' ); ?>
					<p><label><?php esc_html_e( 'Name', 'imajiner-editor' ); ?><br><input type="text" name="name" class="regular-text" required placeholder="<?php esc_attr_e( 'e.g. Product page', 'imajiner-editor' ); ?>"></label></p>
					<p><label><?php esc_html_e( 'Type', 'imajiner-editor' ); ?><br>
						<select name="kind">
							<option value="page"><?php esc_html_e( 'Page template (chosen per page)', 'imajiner-editor' ); ?></option>
							<option value="location"><?php esc_html_e( 'Single or archive template', 'imajiner-editor' ); ?></option>
						</select>
					</label></p>
					<p><label><?php esc_html_e( 'Location (single or archive templates)', 'imajiner-editor' ); ?><br>
						<select name="location">
							<option value=""><?php esc_html_e( 'Not assigned yet', 'imajiner-editor' ); ?></option>
							<?php foreach ( $locations as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label></p>
					<?php submit_button( __( 'Create template', 'imajiner-editor' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Checks capability and nonce for a form handler.
	 *
	 * @param string $action Nonce action.
	 */
	private static function check_request( $action ) {
		if ( ! Imajiner_Editor::user_can_edit_templates() ) {
			wp_die( esc_html__( 'You are not allowed to manage templates.', 'imajiner-editor' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Cleans a value for a file header line: one line, no comment terminators.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function header_value( $value ) {
		return trim( str_replace( array( '*/', '/*' ), '', sanitize_text_field( $value ) ) );
	}

	/**
	 * Shows a notice on the screen after a redirect, and exits.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message Message.
	 */
	private static function redirect_with( $type, $message ) {
		set_transient( 'imajiner_builder_notice_' . get_current_user_id(), array( $type, $message ), MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url() );
		exit;
	}
}
