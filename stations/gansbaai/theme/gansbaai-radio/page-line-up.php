<?php
/**
 * The line-up (the page with the slug "line-up"): what is on now and next, and a tab for each day.
 * The shows come from the station (site.js reads its public schedule); text typed into the page in
 * WordPress shows under the heading.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();
the_post();
?>
<main id="main">
	<section class="page-head">
		<div class="wrap">
			<span class="eyebrow">Weekly line-up</span>
			<h1>What's on</h1>
			<p class="lead">Live shows from Gansbaai through the week, and non-stop music in between.</p>
			<?php if ( '' !== trim( (string) get_the_content() ) ) : ?>
				<div class="content page-intro"><?php the_content(); ?></div>
			<?php endif; ?>
		</div>
	</section>

	<section class="section" style="padding-top:48px">
		<div class="wrap">
			<p class="sample-note" hidden><?php echo gbcr_icon( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>Sample shows: the station's own line-up shows here as soon as it is set up.</p>
			<div class="now-grid">
				<div class="now-card" id="now-show" aria-live="polite">
					<div class="label"><span class="live-dot"></span>On now</div>
					<p class="title">…</p>
					<p class="meta"></p>
					<p class="song"><?php echo gbcr_icon( 'music' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span data-nowplaying></span></p>
					<a class="btn small" href="#listen" data-listen style="margin-top:16px">Listen live</a>
				</div>
				<div class="now-card next" id="next-show">
					<div class="label"><?php echo gbcr_icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>Up next</div>
					<p class="title">…</p>
					<p class="meta"></p>
				</div>
			</div>

			<h2 id="day-name">This week</h2>
			<div class="days" role="tablist" aria-label="Day of the week"></div>
			<ul class="slots" id="week-slots" role="tabpanel" aria-labelledby="day-name"><li class="slot gap"><time></time><div class="name">Loading the line-up…</div><span></span></li></ul>
			<p class="note">All times are South African time (SAST). Want your club, church or school on air? <a href="<?php echo esc_url( add_query_arg( 'topic', 'Join the team', gbcr_page_url( 'contact' ) ) ); ?>">Tell us</a>.</p>
		</div>
	</section>
</main>
<?php
get_footer();
