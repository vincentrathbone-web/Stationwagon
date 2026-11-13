<?php
/** Any other page (the privacy policy, for example): its title in the page head and its text below. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();
?>
<main id="main">
	<?php while ( have_posts() ) : the_post(); ?>
		<section class="page-head">
			<div class="wrap"><h1><?php the_title(); ?></h1></div>
		</section>
		<section class="section" style="padding-top:48px">
			<div class="wrap"><div class="content"><?php the_content(); ?></div></div>
		</section>
	<?php endwhile; ?>
</main>
<?php
get_footer();
