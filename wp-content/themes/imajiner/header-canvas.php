<?php
/**
 * Canvas header: document head only, no site header or menu.
 *
 * Used by templates that design the whole page themselves: get_header( 'canvas' ).
 * Only the document-level hooks fire, so header/content parts don't appear.
 *
 * @package Imajiner
 */

do_action( 'tha_html_before' );
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<?php do_action( 'tha_head_top' ); ?>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php do_action( 'tha_head_bottom' ); ?>
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'imj-canvas' ); ?>>
<?php wp_body_open(); ?>
<?php do_action( 'tha_body_top' ); ?>
<a class="skip-link screen-reader-text" href="#content"><?php esc_html_e( 'Skip to content', 'imajiner' ); ?></a>

<main id="content" class="site-main">
