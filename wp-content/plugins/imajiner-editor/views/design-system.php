<?php
/** AI design-system review screen. @package Imajiner_Editor */
defined( 'ABSPATH' ) || exit;
?>
<div class="wrap imj-design" id="imj-design-system">
	<h1><?php esc_html_e( 'AI Design System', 'imajiner-editor' ); ?></h1>
	<p><?php esc_html_e( 'Extract colors, typography and spacing from a prompt, screenshot or reference page. Review the proposal before saving it to the active child theme.', 'imajiner-editor' ); ?></p>
	<p><?php esc_html_e( 'Accepted tokens live in assets/css/design-tokens.css and can be reused by AI in later sessions. Existing accepted tokens are preserved unless the proposal changes them.', 'imajiner-editor' ); ?></p>
	<div id="imj-design-status" role="status" aria-live="polite"></div>
	<form id="imj-design-form">
		<label for="imj-design-prompt"><?php esc_html_e( 'Design instructions (optional)', 'imajiner-editor' ); ?></label>
		<textarea id="imj-design-prompt" rows="5" maxlength="8000" class="large-text"></textarea>
		<label for="imj-design-url"><?php esc_html_e( 'Public HTTPS reference URL (optional)', 'imajiner-editor' ); ?></label>
		<input id="imj-design-url" type="url" maxlength="2048" placeholder="https://" class="large-text">
		<p><?php esc_html_e( 'Only a bounded HTML page and up to three same-origin stylesheets are read. Scripts are never executed and redirects are not followed.', 'imajiner-editor' ); ?></p>
		<button type="button" class="button" id="imj-design-media"><?php esc_html_e( 'Choose or upload screenshot', 'imajiner-editor' ); ?></button>
		<button type="button" class="button" id="imj-design-remove" hidden><?php esc_html_e( 'Remove screenshot', 'imajiner-editor' ); ?></button>
		<span id="imj-design-image-name"></span>
		<p><?php esc_html_e( 'PNG, JPEG or WebP, maximum 5 MB. The actual image URL and extracted reference styles will be sent to your configured AI provider. The provider must support images and be able to access the screenshot URL.', 'imajiner-editor' ); ?></p>
		<p><button class="button button-primary" type="submit" id="imj-design-extract" disabled><?php esc_html_e( 'Extract for review', 'imajiner-editor' ); ?></button></p>
	</form>
	<section id="imj-design-review" hidden aria-labelledby="imj-design-review-title">
		<h2 id="imj-design-review-title"><?php esc_html_e( 'Review proposal — nothing has been saved', 'imajiner-editor' ); ?></h2>
		<p id="imj-design-summary"></p>
		<ul id="imj-design-warnings"></ul>
		<div id="imj-design-diff"></div>
		<div class="imj-design-columns">
			<div><h3><?php esc_html_e( 'Current token CSS', 'imajiner-editor' ); ?></h3><pre id="imj-design-before"></pre></div>
			<div><h3><?php esc_html_e( 'Proposed token CSS', 'imajiner-editor' ); ?></h3><pre id="imj-design-after"></pre></div>
		</div>
		<label><input type="checkbox" id="imj-design-confirm"> <?php esc_html_e( 'I reviewed the token differences and confirm saving them to this child theme.', 'imajiner-editor' ); ?></label>
		<p><button type="button" class="button button-primary" id="imj-design-save" disabled><?php esc_html_e( 'Confirm and save tokens', 'imajiner-editor' ); ?></button> <button type="button" class="button" id="imj-design-discard"><?php esc_html_e( 'Discard proposal', 'imajiner-editor' ); ?></button></p>
	</section>
	<section aria-labelledby="imj-design-history-title">
		<h2 id="imj-design-history-title"><?php esc_html_e( 'Private revision history', 'imajiner-editor' ); ?></h2>
		<button type="button" class="button" id="imj-design-history"><?php esc_html_e( 'Load revisions', 'imajiner-editor' ); ?></button>
		<div id="imj-design-revisions"></div>
		<div id="imj-design-restore-review" hidden>
			<h3><?php esc_html_e( 'Review revision before restoring', 'imajiner-editor' ); ?></h3>
			<div class="imj-design-columns"><pre id="imj-design-restore-current"></pre><pre id="imj-design-restore-css"></pre></div>
			<label><input type="checkbox" id="imj-design-restore-confirm"> <?php esc_html_e( 'I confirm restoring this revision over the current design tokens.', 'imajiner-editor' ); ?></label>
			<p><button type="button" class="button" id="imj-design-restore" disabled><?php esc_html_e( 'Confirm restore', 'imajiner-editor' ); ?></button></p>
		</div>
	</section>
</div>
