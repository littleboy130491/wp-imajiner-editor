<?php
/** @package Imajiner_Editor */
defined( 'ABSPATH' ) || exit;
?>
<div class="wrap imj-design" id="imj-design-system">
	<h1><?php esc_html_e( 'Imajiner Design System', 'imajiner-editor' ); ?></h1>
	<p><?php esc_html_e( 'Describe a design, choose a screenshot, or supply a reference URL. Combine them if useful. AI proposals are reviewed here before saving any child theme CSS.', 'imajiner-editor' ); ?></p>
	<form id="imj-design-form">
		<p><label for="imj-design-prompt"><strong><?php esc_html_e( 'Design brief', 'imajiner-editor' ); ?></strong></label></p>
		<textarea id="imj-design-prompt" rows="5" maxlength="10000" class="large-text"></textarea>
		<p><label for="imj-design-url"><strong><?php esc_html_e( 'HTTPS reference URL', 'imajiner-editor' ); ?></strong></label></p>
		<input id="imj-design-url" type="url" class="large-text" maxlength="2048" placeholder="https://" />
		<p class="description"><?php esc_html_e( 'Public HTML pages only. Redirects, private addresses and external stylesheets are not followed; up to three stylesheets from the same origin are sampled.', 'imajiner-editor' ); ?></p>
		<p>
			<button type="button" id="imj-design-media" class="button"><?php esc_html_e( 'Choose screenshot', 'imajiner-editor' ); ?></button>
			<button type="button" id="imj-design-remove" class="button" hidden><?php esc_html_e( 'Remove screenshot', 'imajiner-editor' ); ?></button>
			<span id="imj-design-image-name"></span>
		</p>
		<img id="imj-design-image" class="imj-design-image" alt="<?php esc_attr_e( 'Selected screenshot', 'imajiner-editor' ); ?>" hidden />
		<p class="description"><?php esc_html_e( 'JPEG, PNG, WebP or GIF, up to 5 MB. The AI provider receives the selected media image URL. Use a publicly accessible HTTPS media URL.', 'imajiner-editor' ); ?></p>
		<p><button type="submit" id="imj-design-extract" class="button button-primary"><?php esc_html_e( 'Extract design system', 'imajiner-editor' ); ?></button></p>
	</form>
	<div id="imj-design-status" role="status" aria-live="polite"></div>
	<section id="imj-design-review" hidden aria-labelledby="imj-design-review-title">
		<h2 id="imj-design-review-title"><?php esc_html_e( 'Review proposed tokens', 'imajiner-editor' ); ?></h2>
		<p id="imj-design-summary"></p>
		<ul id="imj-design-warnings"></ul>
		<div class="imj-design-comparison">
			<div><h3><?php esc_html_e( 'Before', 'imajiner-editor' ); ?></h3><div id="imj-design-before"></div></div>
			<div><h3><?php esc_html_e( 'After', 'imajiner-editor' ); ?></h3><div id="imj-design-after"></div></div>
		</div>
		<h3><?php esc_html_e( 'Raw token diff', 'imajiner-editor' ); ?></h3>
		<pre id="imj-design-diff" tabindex="0"></pre>
		<details><summary><?php esc_html_e( 'CSS to save', 'imajiner-editor' ); ?></summary><pre id="imj-design-css"></pre></details>
		<p><label><input type="checkbox" id="imj-design-confirm" /> <?php esc_html_e( 'I reviewed the changes and want to save these tokens to the active child theme.', 'imajiner-editor' ); ?></label></p>
		<p><button type="button" id="imj-design-save" class="button button-primary" disabled><?php esc_html_e( 'Confirm Save', 'imajiner-editor' ); ?></button> <button type="button" id="imj-design-discard" class="button"><?php esc_html_e( 'Discard proposal', 'imajiner-editor' ); ?></button></p>
	</section>
	<section aria-labelledby="imj-design-current-title">
		<h2 id="imj-design-current-title"><?php esc_html_e( 'Accepted design system', 'imajiner-editor' ); ?></h2>
		<p><?php esc_html_e( 'Saved token overrides remain in the child theme CSS across sessions. Future AI requests and editor suggestions use them.', 'imajiner-editor' ); ?></p>
		<div id="imj-design-current"></div>
		<h3><?php esc_html_e( 'Private revision history', 'imajiner-editor' ); ?></h3>
		<div id="imj-design-revisions"></div>
	</section>
</div>
