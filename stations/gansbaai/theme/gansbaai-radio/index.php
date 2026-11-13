<?php
/** Everything without its own template (posts, lists): titles and text in the station's look. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();
?>
<main id="main">
	<section class="page-head">
		<div class="wrap"><h1><?php echo esc_html( is_singular() ? get_the_title() : ( is_home() ? 'News' : wp_strip_all_tags( get_the_archive_title() ) ) ); ?></h1></div>
	</section>
	<section class="section" style="padding-top:48px">
		<div class="wrap">
			<?php if ( have_posts() ) : ?>
				<?php while ( have_posts() ) : the_post(); ?>
					<article class="content" style="margin-bottom:48px">
						<?php if ( ! is_singular() ) : ?>
							<h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
							<p class="note"><?php echo esc_html( get_the_date() ); ?></p>
							<?php the_excerpt(); ?>
						<?php else : ?>
							<p class="note"><?php echo esc_html( get_the_date() ); ?></p>
							<?php the_content(); ?>
						<?php endif; ?>
					</article>
				<?php endwhile; ?>
				<?php the_posts_pagination(); ?>
			<?php else : ?>
				<p class="lead">Nothing here yet.</p>
			<?php endif; ?>
		</div>
	</section>
</main>
<?php
get_footer();
