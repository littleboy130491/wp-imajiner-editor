<?php
/**
 * Not found page.
 *
 * @package Imajiner
 */

get_header();
?>

<div class="container content-area">
	<h1 class="page-title"><?php esc_html_e( 'Page not found', 'imajiner' ); ?></h1>
	<p><?php esc_html_e( 'The page you are looking for does not exist.', 'imajiner' ); ?></p>
	<?php get_search_form(); ?>
</div>

<?php
get_footer();
