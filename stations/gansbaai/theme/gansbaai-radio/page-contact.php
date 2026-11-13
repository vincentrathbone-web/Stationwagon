<?php
/**
 * Contact (the page with the slug "contact"): the station's details and the form. The form posts to
 * /wp-json/gbcr/v1/contact (inc/class-gbcr-contact.php), which emails the station. The WhatsApp number,
 * the email and the studio come from Appearance > Customize > Gansbaai Community Radio; an empty one says
 * "to come". ?topic= picks the form's topic (the home page's cards link that way).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();
the_post();
$gbcr_email = (string) gbcr_setting( 'public_email' );
$gbcr_wa    = (string) gbcr_setting( 'whatsapp' );
$gbcr_wa_no = preg_replace( '/\D+/', '', $gbcr_wa );
if ( str_starts_with( $gbcr_wa_no, '0' ) ) {
	$gbcr_wa_no = '27' . substr( $gbcr_wa_no, 1 ); // a South African number written the local way
}
$gbcr_topics = GBCR_Contact::topics();
$gbcr_want   = isset( $_GET['topic'] ) ? sanitize_text_field( wp_unslash( $_GET['topic'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
?>
<main id="main">
	<section class="page-head">
		<div class="wrap">
			<span class="eyebrow">Contact us</span>
			<h1>Talk to your station</h1>
			<p class="lead">A shout-out, a song request, a notice for the town or a question: send us a note and we'll get back to you.</p>
			<?php if ( '' !== trim( (string) get_the_content() ) ) : ?>
				<div class="content page-intro"><?php the_content(); ?></div>
			<?php endif; ?>
		</div>
	</section>

	<section class="section" style="padding-top:48px">
		<div class="wrap contact-grid">
			<div>
				<h2>Get in touch</h2>
				<ul class="info-list">
					<li><span class="ic"><?php echo gbcr_icon( 'pin' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						<div><strong>The studio</strong><span><?php echo esc_html( gbcr_setting( 'studio' ) ); ?></span></div></li>
					<li><span class="ic"><?php echo gbcr_icon( 'chat' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						<div><strong>WhatsApp the studio</strong>
						<?php if ( '' !== $gbcr_wa_no ) : ?>
							<span><a href="<?php echo esc_url( 'https://wa.me/' . $gbcr_wa_no ); ?>"><?php echo esc_html( $gbcr_wa ); ?></a></span>
						<?php else : ?>
							<span class="placeholder">Number to come</span>
						<?php endif; ?>
						</div></li>
					<li><span class="ic"><?php echo gbcr_icon( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						<div><strong>Email</strong>
						<?php if ( is_email( $gbcr_email ) ) : ?>
							<span><a href="<?php echo esc_url( 'mailto:' . $gbcr_email ); ?>"><?php echo esc_html( $gbcr_email ); ?></a></span>
						<?php else : ?>
							<span class="placeholder">Address to come</span>
						<?php endif; ?>
						</div></li>
					<li><span class="ic"><?php echo gbcr_icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						<div><strong>On air</strong><span>24 hours a day, with live shows through the week. <a href="<?php echo esc_url( gbcr_page_url( 'line-up' ) ); ?>">See the line-up</a></span></div></li>
				</ul>
				<h3>Quick picks</h3>
				<div class="chips">
					<?php foreach ( array_slice( $gbcr_topics, 0, 5 ) as $gbcr_t ) : ?>
						<button type="button" data-topic="<?php echo esc_attr( $gbcr_t ); ?>" aria-pressed="<?php echo $gbcr_t === $gbcr_want ? 'true' : 'false'; ?>"><?php echo esc_html( $gbcr_t ); ?></button>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="form-card">
				<form class="js-contact-form" novalidate>
					<div class="hp" aria-hidden="true"><label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
					<div class="two">
						<div class="field"><label for="c-name">Your name</label><input type="text" id="c-name" name="name" autocomplete="name" maxlength="100" required><p class="err">Please type your name.</p></div>
						<div class="field"><label for="c-phone">Phone <span class="opt">(if you'd like a call back)</span></label><input type="tel" id="c-phone" name="phone" autocomplete="tel" maxlength="30"></div>
					</div>
					<div class="field"><label for="c-email">Your email</label><input type="email" id="c-email" name="email" autocomplete="email" maxlength="200" required><p class="err">Please type an email address we can reply to.</p></div>
					<div class="field"><label for="c-topic">What is this about?</label><select id="c-topic" name="topic">
						<?php foreach ( $gbcr_topics as $gbcr_t ) : ?>
							<option<?php selected( $gbcr_t, $gbcr_want ); ?>><?php echo esc_html( $gbcr_t ); ?></option>
						<?php endforeach; ?>
					</select></div>
					<div class="field"><label for="c-message">Your message</label><textarea id="c-message" name="message" maxlength="4000" required></textarea><p class="err">Please type a message.</p></div>
					<?php if ( gbcr_turnstile_sitekey() ) : ?>
						<div class="turnstile" id="c-turnstile"></div>
					<?php endif; ?>
					<button class="btn" type="submit"><?php echo gbcr_icon( 'send' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>Send message</button>
					<p class="status" role="status" aria-live="polite"></p>
					<p class="fineprint">We use your details only to reply to you. <a href="<?php echo esc_url( gbcr_privacy_url() ); ?>">Privacy policy</a></p>
				</form>
			</div>
		</div>
	</section>
</main>
<?php
get_footer();
