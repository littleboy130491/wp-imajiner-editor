<?php
/**
 * Site header: document head, skip link and the site header.
 *
 * A template part placed at the "header" location replaces the default header.
 * Fires the Theme Hook Alliance hooks so plugins and parts can add content.
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
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<?php do_action( 'tha_body_top' ); ?>
<a class="skip-link screen-reader-text" href="#content"><?php esc_html_e( 'Skip to content', 'imajiner' ); ?></a>

<?php do_action( 'tha_header_before' ); ?>

<?php if ( ! imajiner_render_location( 'header' ) ) : ?>
	<header class="site-header">
		<?php do_action( 'tha_header_top' ); ?>
		<div class="container site-header__inner">
			<div class="site-branding">
				<?php if ( has_custom_logo() ) : ?>
					<?php the_custom_logo(); ?>
				<?php else : ?>
					<a class="site-title" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home"><?php bloginfo( 'name' ); ?></a>
				<?php endif; ?>
			</div>

			<?php if ( has_nav_menu( 'primary' ) ) : ?>
				<nav class="site-nav" data-imajiner-navigation aria-label="<?php esc_attr_e( 'Primary', 'imajiner' ); ?>">
					<button class="site-nav__toggle" type="button" aria-expanded="false" aria-controls="imajiner-primary-menu" hidden>
						<span aria-hidden="true">☰</span>
						<?php echo esc_html__( 'Menu', 'imajiner-editor' ); ?>
					</button>
					<?php
					wp_nav_menu(
						array(
							'theme_location' => 'primary',
							'container'      => false,
							'menu_class'     => 'site-nav__menu',
							'menu_id'        => 'imajiner-primary-menu',
							'fallback_cb'    => false,
							'depth'          => 2,
						)
					);
					?>
				</nav>
			<?php endif; ?>
		</div>
		<?php do_action( 'tha_header_bottom' ); ?>
	</header>
<?php endif; ?>

<?php do_action( 'tha_header_after' ); ?>
<?php do_action( 'tha_content_before' ); ?>

<main id="content" class="site-main">
<?php do_action( 'tha_content_top' ); ?>
