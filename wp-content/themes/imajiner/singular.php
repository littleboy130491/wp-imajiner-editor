<?php
/**
 * Default template for single posts and pages without an Imajiner template.
 *
 * @package Imajiner
 */

get_header();
?>

<div class="container content-area">
	<?php while ( have_posts() ) : ?>
		<?php the_post(); ?>
		<?php do_action( 'tha_entry_before' ); ?>
		<article id="post-<?php the_ID(); ?>" <?php post_class( 'entry' ); ?>>
			<?php do_action( 'tha_entry_top' ); ?>
			<header class="entry__header">
				<?php the_title( '<h1 class="entry__title">', '</h1>' ); ?>
				<?php if ( 'post' === get_post_type() ) : ?>
					<p class="entry__meta"><time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time></p>
				<?php endif; ?>
			</header>

			<?php if ( has_post_thumbnail() ) : ?>
				<div class="entry__thumb"><?php the_post_thumbnail( 'large' ); ?></div>
			<?php endif; ?>

			<div class="entry__content">
				<?php do_action( 'tha_entry_content_before' ); ?>
				<?php the_content(); ?>
				<?php wp_link_pages(); ?>
				<?php do_action( 'tha_entry_content_after' ); ?>
			</div>
			<?php do_action( 'tha_entry_bottom' ); ?>
		</article>
		<?php do_action( 'tha_entry_after' ); ?>

		<?php if ( comments_open() || get_comments_number() ) : ?>
			<?php do_action( 'tha_comments_before' ); ?>
			<?php comments_template(); ?>
			<?php do_action( 'tha_comments_after' ); ?>
		<?php endif; ?>
	<?php endwhile; ?>
</div>

<?php
get_footer();
