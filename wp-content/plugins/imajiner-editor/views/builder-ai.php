<?php
/**
 * AI creation and normalization review panel.
 *
 * @package Imajiner_Editor
 */

defined( 'ABSPATH' ) || exit;
?>
<section id="imj-ai" class="card" style="max-width:960px">
	<h2><?php esc_html_e( 'Build with AI', 'imajiner-editor' ); ?></h2>
	<p><?php esc_html_e( 'Review the generated PHP and CSS before saving. New pages are saved as drafts. Normalize keeps the current assignments and a revision of the original files.', 'imajiner-editor' ); ?></p>
	<p><a href="<?php echo esc_url( admin_url( 'options-general.php?page=imajiner-editor' ) ); ?>"><?php esc_html_e( 'Configure AI models and keys', 'imajiner-editor' ); ?></a></p>
	<form id="imj-ai-form">
		<p><label for="imj-ai-key"><?php esc_html_e( 'Action', 'imajiner-editor' ); ?></label><br>
			<select id="imj-ai-key">
				<option value=""><?php esc_html_e( 'New page with AI', 'imajiner-editor' ); ?></option>
				<?php foreach ( $templates as $key => $template ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( sprintf( __( 'Normalize: %s', 'imajiner-editor' ), $template['name'] ) ); ?></option>
				<?php endforeach; ?>
				<?php foreach ( $parts as $slug => $part ) : ?>
					<option value="<?php echo esc_attr( 'parts/' . $slug ); ?>"><?php echo esc_html( sprintf( __( 'Normalize part: %s', 'imajiner-editor' ), $part['name'] ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p id="imj-ai-name-field"><label for="imj-ai-name"><?php esc_html_e( 'Page / template name', 'imajiner-editor' ); ?></label><br>
			<input id="imj-ai-name" class="regular-text" maxlength="200" required>
		</p>
		<p><label for="imj-ai-prompt"><?php esc_html_e( 'Describe the page, or add instructions for Normalize', 'imajiner-editor' ); ?></label><br>
			<textarea id="imj-ai-prompt" rows="5" maxlength="20000" style="width:100%" required></textarea>
		</p>
		<button class="button button-primary" id="imj-ai-generate" type="submit"><?php esc_html_e( 'Generate proposal', 'imajiner-editor' ); ?></button>
	</form>
	<p id="imj-ai-status" role="status" aria-live="polite"></p>
	<ul id="imj-ai-errors"></ul>
	<div id="imj-ai-review" hidden>
		<h3><?php esc_html_e( 'Review before saving', 'imajiner-editor' ); ?></h3>
		<p><?php esc_html_e( 'Static preview only: PHP, scripts, the site header and footer do not run here. Review the source for dynamic behavior. Proposals expire after one hour.', 'imajiner-editor' ); ?></p>
		<div id="imj-ai-comparison" style="display:flex;flex-wrap:wrap;gap:16px"></div>
		<h4><?php esc_html_e( 'Contract warnings after generation', 'imajiner-editor' ); ?></h4>
		<ul id="imj-ai-warnings"></ul>
		<p><button id="imj-ai-accept" class="button button-primary" type="button"><?php esc_html_e( 'Confirm and save', 'imajiner-editor' ); ?></button>
			<button id="imj-ai-cancel" class="button" type="button"><?php esc_html_e( 'Discard proposal', 'imajiner-editor' ); ?></button></p>
	</div>
</section>
