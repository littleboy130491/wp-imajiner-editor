<?php
/**
 * Fallback template: blog index, archives and search results.
 *
 * @package Imajiner
 */

get_header();
?>

<div class="container content-area">
	<?php if ( is_archive() || is_search() ) : ?>
		<header class="page-header">
			<?php if ( is_search() ) : ?>
				<h1 class="page-title">
					<?php
					/* translators: %s: search query. */
					printf( esc_html__( 'Search results for: %s', 'imajiner' ), esc_html( get_search_query() ) );
					?>
				</h1>
			<?php else : ?>
				<?php the_archive_title( '<h1 class="page-title">', '</h1>' ); ?>
				<?php the_archive_description( '<div class="archive-description">', '</div>' ); ?>
			<?php endif; ?>
		</header>
	<?php endif; ?>

	<?php if ( have_posts() ) : ?>
		<div class="post-list">
			<?php while ( have_posts() ) : ?>
				<?php the_post(); ?>
				<?php do_action( 'tha_entry_before' ); ?>
				<article id="post-<?php the_ID(); ?>" <?php post_class( 'post-card' ); ?>>
					<?php do_action( 'tha_entry_top' ); ?>
					<?php if ( has_post_thumbnail() ) : ?>
						<a class="post-card__thumb" href="<?php the_permalink(); ?>"><?php the_post_thumbnail( 'medium_large' ); ?></a>
					<?php endif; ?>
					<h2 class="post-card__title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
					<div class="post-card__excerpt"><?php the_excerpt(); ?></div>
					<?php do_action( 'tha_entry_bottom' ); ?>
				</article>
				<?php do_action( 'tha_entry_after' ); ?>
			<?php endwhile; ?>
		</div>

		<?php the_posts_pagination(); ?>
	<?php else : ?>
		<p><?php esc_html_e( 'Nothing found.', 'imajiner' ); ?></p>
	<?php endif; ?>
</div>

<?php
get_footer();
