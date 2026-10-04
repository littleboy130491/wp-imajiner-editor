<?php
/**
 * Full-screen editor page.
 *
 * Standalone document: it prints the styles and scripts enqueued by
 * Imajiner_Editor::render_editor() itself, including the media library.
 *
 * @package Imajiner_Editor
 *
 * @var array $data Editor data from Imajiner_Editor::render_editor().
 */

defined( 'ABSPATH' ) || exit;

?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>
		<?php
		/* translators: %s: page title. */
		echo esc_html( sprintf( __( 'Imajiner Editor: %s', 'imajiner-editor' ), $data['post']['title'] ) );
		?>
	</title>
	<?php wp_print_styles(); ?>
</head>
<body class="imj-editor">

<header class="imj-topbar">
	<a class="imj-topbar__back" href="<?php echo esc_url( $data['post']['editUrl'] ); ?>">&larr; <?php echo esc_html( $data['post']['back'] ); ?></a>
	<div class="imj-topbar__title">
		<strong><?php echo esc_html( $data['post']['title'] ); ?></strong>
		<span class="imj-topbar__type"><?php echo esc_html( $data['template']['type'] ); ?></span>
		<code><?php echo esc_html( $data['template']['file'] ); ?></code>
	</div>
	<div id="imj-devices" class="imj-devices" role="group" aria-label="<?php esc_attr_e( 'Preview device', 'imajiner-editor' ); ?>"></div>
	<div class="imj-topbar__actions">
		<button type="button" id="imj-breakpoints" class="imj-button"><?php esc_html_e( 'Breakpoints', 'imajiner-editor' ); ?></button>
		<button type="button" id="imj-undo" class="imj-button" disabled><?php esc_html_e( 'Undo', 'imajiner-editor' ); ?></button>
		<button type="button" id="imj-redo" class="imj-button" disabled><?php esc_html_e( 'Redo', 'imajiner-editor' ); ?></button>
		<span id="imj-status" class="imj-status" role="status" aria-live="polite"></span>
		<div class="imj-history">
			<button type="button" id="imj-history-toggle" class="imj-button imj-button--ghost" aria-expanded="false" aria-controls="imj-history-panel"><?php esc_html_e( 'History', 'imajiner-editor' ); ?></button>
			<div id="imj-history-panel" class="imj-history__panel" hidden></div>
		</div>
		<a class="imj-button imj-button--ghost" href="<?php echo esc_url( $data['post']['viewUrl'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View page', 'imajiner-editor' ); ?></a>
		<button type="button" id="imj-discard" class="imj-button imj-button--ghost" disabled><?php esc_html_e( 'Discard', 'imajiner-editor' ); ?></button>
		<button type="button" id="imj-save" class="imj-button imj-button--primary" disabled><?php esc_html_e( 'Save', 'imajiner-editor' ); ?></button>
	</div>
</header>

<div class="imj-layout">
	<aside class="imj-panel">
		<h2 class="imj-panel__title"><?php esc_html_e( 'Layers', 'imajiner-editor' ); ?></h2>
		<button type="button" id="imj-add-section" class="imj-button imj-add-section"><?php esc_html_e( 'Add blank section', 'imajiner-editor' ); ?></button>
		<label class="imj-field">
			<span><?php esc_html_e( 'Section library', 'imajiner-editor' ); ?></span>
			<select id="imj-library" class="imj-input">
				<option value=""><?php esc_html_e( 'Choose a section…', 'imajiner-editor' ); ?></option>
				<?php foreach ( Imajiner_Section_Library::entries() as $name => $label ) : ?>
					<option value="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<div id="imj-warnings"></div>
		<div id="imj-tree" class="imj-tree"></div>
	</aside>

	<main class="imj-canvas">
		<div id="imj-frame" class="imj-frame">
			<iframe id="imj-preview" title="<?php esc_attr_e( 'Page preview', 'imajiner-editor' ); ?>" src="<?php echo esc_url( $data['previewUrl'] ); ?>"></iframe>
		</div>
	</main>

	<aside class="imj-panel">
		<h2 class="imj-panel__title"><?php esc_html_e( 'Properties', 'imajiner-editor' ); ?></h2>
		<div id="imj-props" class="imj-props"></div>
	</aside>
</div>

<?php
wp_print_media_templates();
wp_print_scripts();
?>
</body>
</html>
