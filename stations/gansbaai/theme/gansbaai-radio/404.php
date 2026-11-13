<?php
/** A page that isn't there. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();
?>
<main id="main">
	<section class="page-head">
		<div class="wrap">
			<span class="eyebrow">Lost at sea</span>
			<h1>We can't find that page</h1>
			<p class="lead">It may have moved. The music is still playing, though.</p>
			<div class="btn-row" style="justify-content:flex-start;margin-top:24px">
				<a class="btn" href="<?php echo esc_url( home_url( '/' ) ); ?>">Go to the home page</a>
				<a class="btn ghost" href="#listen" data-listen><span class="live-dot"></span>Listen live</a>
			</div>
		</div>
	</section>
</main>
<?php
get_footer();
