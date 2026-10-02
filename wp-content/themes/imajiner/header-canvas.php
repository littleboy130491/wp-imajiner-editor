<?php
/**
 * Canvas header: document head only, no site header or menu.
 *
 * Used by templates that design the whole page themselves: get_header( 'canvas' ).
 *
 * @package Imajiner
 */

?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'imj-canvas' ); ?>>
<?php wp_body_open(); ?>
<a class="skip-link screen-reader-text" href="#content"><?php esc_html_e( 'Skip to content', 'imajiner' ); ?></a>

<main id="content" class="site-main">
