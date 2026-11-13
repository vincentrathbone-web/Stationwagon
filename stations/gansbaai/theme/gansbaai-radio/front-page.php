<?php
/**
 * The home page: the sea video behind the logo, the song on air, what the town gets from its station,
 * the rest of today's line-up and ways to get involved. Text typed into the Home page in WordPress shows
 * in its own section under the three cards.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();
$gbcr_contact = gbcr_page_url( 'contact' );
$gbcr_lineup  = gbcr_page_url( 'line-up' );
$gbcr_text    = '';
if ( is_page() && have_posts() ) { // the Home page's own text (not a post when the front page lists posts)
	the_post();
	$gbcr_text = trim( (string) get_the_content() );
}
?>
<main id="main">
	<section class="hero" aria-label="Welcome">
		<video muted loop playsinline autoplay poster="<?php echo esc_url( gbcr_asset( 'hero/gbcr-hero-poster.jpg' ) ); ?>" data-src-large="<?php echo esc_url( gbcr_asset( 'hero/gbcr-hero-1280.mp4' ) ); ?>" data-src-small="<?php echo esc_url( gbcr_asset( 'hero/gbcr-hero-854.mp4' ) ); ?>" aria-hidden="true"></video>
		<div class="wrap">
			<h1 style="margin:0"><img class="logo-big" src="<?php echo esc_url( gbcr_asset( 'assets/img/gbcr-logo-stacked.svg' ) ); ?>" alt="Gansbaai Community Radio" width="520" height="562"></h1>
			<p class="tag">The voice of the bay, from the harbour to the hills.</p>
			<div class="onair" aria-live="polite"><span class="live-dot"></span><b>On air</b><span data-nowplaying>Gansbaai Community Radio</span></div>
			<div class="btn-row">
				<a class="btn" href="#listen" data-listen><svg class="icon" viewBox="0 0 24 24" style="width:18px;height:18px" aria-hidden="true"><polygon points="6 3 20 12 6 21 6 3" fill="currentColor"/></svg>Listen live</a>
				<a class="btn ghost" href="<?php echo esc_url( $gbcr_lineup ); ?>">See the line-up</a>
			</div>
		</div>
		<span class="scroll-cue"><?php echo gbcr_icon( 'down' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
	</section>

	<section class="section">
		<div class="wrap">
			<div class="section-head center">
				<span class="eyebrow">Made in Gansbaai</span>
				<h2>Your town, on air</h2>
				<p class="lead">A radio station run by and for the people of Gansbaai, Kleinbaai, De Kelders, Franskraal and the whole Overberg coast.</p>
			</div>
			<div class="cards">
				<div class="card">
					<div class="badge-icon"><?php echo gbcr_icon( 'mic' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
					<h3>Local voices</h3>
					<p>News, notices, sport and stories from the harbour, the schools, the churches and the clubs.</p>
				</div>
				<div class="card">
					<div class="badge-icon"><?php echo gbcr_icon( 'music' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
					<h3>Music all day</h3>
					<p>Between the live shows the music never stops: day and night, seven days a week.</p>
				</div>
				<div class="card">
					<div class="badge-icon"><?php echo gbcr_icon( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
					<h3>Free on any device</h3>
					<p>Listen on your phone, tablet or computer, at sea or at home. No app or sign-up needed.</p>
				</div>
			</div>
			<?php if ( '' !== $gbcr_text ) : ?>
				<div class="content" style="margin:56px auto 0"><?php the_content(); ?></div>
			<?php endif; ?>
		</div>
	</section>

	<section class="section alt" aria-labelledby="today-h">
		<div class="wrap">
			<div class="section-head">
				<span class="eyebrow">Still to come today</span>
				<h2 id="today-h">On today</h2>
			</div>
			<p class="sample-note" hidden><?php echo gbcr_icon( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>Sample shows: the station's own line-up shows here as soon as it is set up.</p>
			<ul class="slots" id="today-slots"><li class="slot gap"><time></time><div class="name">Loading the line-up…</div><span></span></li></ul>
			<p class="note"><a href="<?php echo esc_url( $gbcr_lineup ); ?>">See the whole week →</a> All times are South African time.</p>
		</div>
	</section>

	<section class="section" aria-labelledby="join-h">
		<div class="wrap">
			<div class="section-head center">
				<span class="eyebrow">Get involved</span>
				<h2 id="join-h">This is your station</h2>
			</div>
			<div class="cards">
				<a class="card" href="<?php echo esc_url( add_query_arg( 'topic', 'Shout-out', $gbcr_contact ) ); ?>">
					<div class="badge-icon"><?php echo gbcr_icon( 'heart' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
					<h3>Send a shout-out</h3>
					<p>A birthday, a song for someone special, or a hello to the boats at sea.</p>
					<span class="more">Send one →</span>
				</a>
				<a class="card" href="<?php echo esc_url( add_query_arg( 'topic', 'Community notice', $gbcr_contact ) ); ?>">
					<div class="badge-icon"><?php echo gbcr_icon( 'notice' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
					<h3>Share a notice</h3>
					<p>A market, a meeting, a lost dog or a fundraiser: we read the notices on air.</p>
					<span class="more">Share it →</span>
				</a>
				<a class="card" href="<?php echo esc_url( add_query_arg( 'topic', 'Advertise', $gbcr_contact ) ); ?>">
					<div class="badge-icon"><?php echo gbcr_icon( 'trend' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
					<h3>Advertise with us</h3>
					<p>Reach the whole coast with an advert or a sponsored show, at a local price.</p>
					<span class="more">Ask us →</span>
				</a>
			</div>
		</div>
	</section>

	<section class="section" style="padding-top:0">
		<div class="wrap">
			<div class="band">
				<div><h2>Tune in now</h2><p>Live from Gansbaai, free, wherever you are.</p></div>
				<a class="btn" href="#listen" data-listen><span class="live-dot"></span>Listen live</a>
			</div>
		</div>
	</section>
</main>
<?php
get_footer();
