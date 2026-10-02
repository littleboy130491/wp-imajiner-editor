<?php
/**
 * Editor preview page for a template part.
 *
 * Renders the site's header and footer. A part placed at a site-wide location
 * (header, footer, before footer, …) appears there by itself; a part without a
 * location is shown in the page content.
 *
 * @package Imajiner_Editor
 */

defined( 'ABSPATH' ) || exit;

$imj_part = Imajiner_Preview::current();

get_header();

if ( $imj_part && '' === $imj_part['location'] ) :
	imajiner_part( $imj_part['slug'] );
else :
	?>
	<div class="container content-area">
		<p style="padding:3rem;border:2px dashed #c3c4c7;border-radius:8px;color:#646970;text-align:center"><?php esc_html_e( 'Page content', 'imajiner-editor' ); ?></p>
	</div>
	<?php
endif;

get_footer();
