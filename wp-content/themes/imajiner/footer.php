<?php
/**
 * Site footer: closes the main element and renders the site footer.
 *
 * A template part placed at the "footer" location replaces the default footer.
 * Fires the Theme Hook Alliance hooks so plugins and parts can add content.
 *
 * @package Imajiner
 */

do_action( 'tha_content_bottom' );
?>
</main>

<?php do_action( 'tha_content_after' ); ?>
<?php do_action( 'tha_footer_before' ); ?>

<?php if ( ! imajiner_render_location( 'footer' ) ) : ?>
	<footer class="site-footer">
		<?php do_action( 'tha_footer_top' ); ?>
		<div class="container site-footer__inner">
			<?php if ( has_nav_menu( 'footer' ) ) : ?>
				<nav class="site-footer__nav" aria-label="<?php esc_attr_e( 'Footer', 'imajiner' ); ?>">
					<?php
					wp_nav_menu(
						array(
							'theme_location' => 'footer',
							'container'      => false,
							'menu_class'     => 'site-footer__menu',
							'depth'          => 1,
						)
					);
					?>
				</nav>
			<?php endif; ?>

			<p class="site-footer__copy">&copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?></p>
		</div>
		<?php do_action( 'tha_footer_bottom' ); ?>
	</footer>
<?php endif; ?>

<?php do_action( 'tha_footer_after' ); ?>
<?php do_action( 'tha_body_bottom' ); ?>
<?php wp_footer(); ?>
</body>
</html>
