<?php
/**
 * The Gansbaai Community Radio theme's test (round 79). Runs inside a WordPress that has the theme active
 * and the Home, Line-up and Contact pages (wp-cli):
 *
 *   wp --path=<wordpress> eval-file stations/gansbaai/tests/theme_test.php
 *
 * It reads the three pages over HTTP as a visitor would, then calls the contact form's REST route the way
 * the page does. No email leaves: `pre_wp_mail` catches every message the test causes. The theme's settings
 * are put back as they were at the end, and the test's rate-limit entries are removed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this with wp eval-file.\n" );
	exit( 1 );
}

$GLOBALS['gbcr_fails'] = 0;
$GLOBALS['gbcr_count'] = 0;
function gbcr_check( $what, $ok, $detail = '' ) {
	$GLOBALS['gbcr_count']++;
	if ( ! $ok ) {
		$GLOBALS['gbcr_fails']++;
	}
	echo ( $ok ? '  ok   ' : '  FAIL ' ) . $what . ( ! $ok && '' !== $detail ? '  (' . $detail . ')' : '' ) . "\n";
}
function gbcr_get( $url ) {
	$r = wp_remote_get( $url, array( 'timeout' => 30, 'sslverify' => false, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
	return is_wp_error( $r ) ? array( 0, $r->get_error_message() ) : array( (int) wp_remote_retrieve_response_code( $r ), (string) wp_remote_retrieve_body( $r ) );
}

$keep = array();
foreach ( array( 'station_url', 'contact_to', 'public_email', 'whatsapp', 'studio', 'sample', 'turnstile_sitekey' ) as $k ) {
	$keep[ $k ] = get_theme_mod( 'gbcr_' . $k, null );
}
$restore = static function () use ( $keep ) {
	foreach ( $keep as $k => $v ) {
		null === $v ? remove_theme_mod( 'gbcr_' . $k ) : set_theme_mod( 'gbcr_' . $k, $v );
	}
};

echo "The theme\n";
gbcr_check( 'Gansbaai Community Radio is the active theme', 'gansbaai-radio' === get_stylesheet(), get_stylesheet() );
gbcr_check( 'the front page is a page (Home)', 'page' === get_option( 'show_on_front' ) && get_option( 'page_on_front' ) > 0 );
foreach ( array( 'line-up', 'contact' ) as $slug ) {
	$p = get_page_by_path( $slug );
	gbcr_check( "the $slug page exists and is published", $p && 'publish' === $p->post_status );
}

echo "The pages, as a visitor sees them\n";
set_theme_mod( 'gbcr_whatsapp', '082 123 4567' );
set_theme_mod( 'gbcr_public_email', 'studio@example.org' );
set_theme_mod( 'gbcr_studio', 'Main Road, Gansbaai' );
list( $st, $home ) = gbcr_get( add_query_arg( 'nocache', time(), home_url( '/' ) ) );
gbcr_check( 'the home page opens', 200 === $st, (string) $st );
gbcr_check( '  with the sea video (both sizes and the poster) and the stacked logo', str_contains( $home, 'data-src-large="' ) && str_contains( $home, 'hero/gbcr-hero-1280.mp4' ) && str_contains( $home, 'hero/gbcr-hero-854.mp4' )
	&& str_contains( $home, 'hero/gbcr-hero-poster.jpg' ) && str_contains( $home, 'gbcr-logo-stacked.svg' ) );
gbcr_check( '  the song on air, today\'s line-up and the three get-involved cards', str_contains( $home, 'data-nowplaying' ) && str_contains( $home, 'id="today-slots"' ) && 3 === substr_count( $home, '?topic=' ) - 2 );
gbcr_check( '  the menu marks Home, and links Line-up and Contact', 1 === preg_match( '~<a href="[^"]+"\s+aria-current="page">Home</a>~', $home ) && str_contains( $home, '>Line-up</a>' ) && str_contains( $home, '>Contact</a>' ) );
$gbcr_studio = esc_url( trailingslashit( gbcr_setting( 'station_url' ) ) . 'studio' );
gbcr_check( '  "Studio login" in the menu and in the footer, both to the station\'s studio (round 83)', 2 === substr_count( $home, 'class="staff" href="' . $gbcr_studio . '"' ) && 2 === substr_count( $home, '>Studio login</a>' ), $gbcr_studio );
gbcr_check( '  the player bar is there, closed (its frame has no address until Listen live)', str_contains( $home, 'class="player-bar"' ) && 1 === preg_match( '~<iframe title="[^"]*" allow="autoplay"></iframe>~', $home ) );
gbcr_check( '  the theme\'s style and script, with the settings for the script', 1 === preg_match( '~assets/site\.css\?v=\d+~', $home ) && 1 === preg_match( '~assets/site\.js\?v=\d+~', $home )
	&& str_contains( $home, 'window.GBCR = {"station":' ) && str_contains( $home, 'gbcr\/v1\/contact' ) );
gbcr_check( '  WordPress\'s block styles are left out', ! str_contains( $home, 'wp-block-library-css' ) );
gbcr_check( '  the favicon and the sea-blue browser bar', str_contains( $home, 'assets/img/favicon.ico' ) && str_contains( $home, '<meta name="theme-color" content="#03182B">' ) );

list( $st, $lineup ) = gbcr_get( add_query_arg( 'nocache', time(), get_permalink( get_page_by_path( 'line-up' ) ) ) );
gbcr_check( 'the line-up page opens', 200 === $st, (string) $st );
gbcr_check( '  with On now, Up next, the day tabs and the week\'s list', str_contains( $lineup, 'id="now-show"' ) && str_contains( $lineup, 'id="next-show"' ) && str_contains( $lineup, 'role="tablist"' ) && str_contains( $lineup, 'id="week-slots"' ) );
gbcr_check( '  the menu marks Line-up', 1 === preg_match( '~aria-current="page">Line-up</a>~', $lineup ) );

$contact_url = get_permalink( get_page_by_path( 'contact' ) );
list( $st, $contact ) = gbcr_get( add_query_arg( array( 'topic' => 'Advertise', 'nocache' => time() ), $contact_url ) );
gbcr_check( 'the contact page opens', 200 === $st, (string) $st );
gbcr_check( '  with the form: name, phone, email, the seven topics, message and the hidden robot trap', str_contains( $contact, 'class="js-contact-form"' ) && str_contains( $contact, 'name="phone"' )
	&& 7 === substr_count( $contact, '<option' ) && str_contains( $contact, 'name="website"' ) && str_contains( $contact, 'name="message"' ) );
gbcr_check( '  ?topic=Advertise picks that topic', str_contains( $contact, '<option selected=\'selected\'>Advertise</option>' ) );
gbcr_check( '  the WhatsApp number links to WhatsApp (a local 0 becomes 27)', str_contains( $contact, 'href="https://wa.me/27821234567"' ) && str_contains( $contact, '>082 123 4567</a>' ) );
// behind Cloudflare the address is hidden from robots ("email address obfuscation"; its script shows it to people)
$mail_shown = str_contains( $contact, 'href="mailto:studio@example.org"' ) || 1 === preg_match( '~href="/cdn-cgi/l/email-protection#[0-9a-f]+"~', $contact );
gbcr_check( '  the email and the studio show', $mail_shown && str_contains( $contact, 'Main Road, Gansbaai' ), preg_match( '~<strong>Email</strong>\s*<span>.{0,160}~s', $contact, $mm ) ? $mm[0] : 'no Email line' );
set_theme_mod( 'gbcr_whatsapp', '' );
set_theme_mod( 'gbcr_public_email', '' );
list( , $contact ) = gbcr_get( add_query_arg( 'nocache', time() + 1, $contact_url ) );
gbcr_check( '  without them it says they are still to come', str_contains( $contact, 'Number to come' ) && str_contains( $contact, 'Address to come' ) );

// the spam check's box: there only with a site key AND the secret in wp-config.php (the page is drawn by another PHP)
$real_secret = defined( 'GBCR_TURNSTILE_SECRET' ) && '' !== trim( (string) GBCR_TURNSTILE_SECRET );
$real_key    = get_theme_mod( 'gbcr_turnstile_sitekey', '' );
set_theme_mod( 'gbcr_turnstile_sitekey', '' );
list( , $contact ) = gbcr_get( add_query_arg( 'nocache', time() + 2, $contact_url ) );
gbcr_check( '  with no Turnstile site key, no spam-check box and no Cloudflare script', ! str_contains( $contact, 'id="c-turnstile"' ) && ! str_contains( $contact, 'challenges.cloudflare.com' ) && str_contains( $contact, '"turnstile":""' ) );
set_theme_mod( 'gbcr_turnstile_sitekey', '' !== (string) $real_key ? $real_key : '1x00000000000000000000AA' );
list( , $contact ) = gbcr_get( add_query_arg( 'nocache', time() + 3, $contact_url ) );
$box = str_contains( $contact, 'id="c-turnstile"' ) && 1 === preg_match( '~challenges\.cloudflare\.com/turnstile/v0/api\.js\?render=explicit&(amp;|#038;)?onload=gbcrTurnstileReady~', $contact )
	&& 1 === preg_match( '~"turnstile":"[^"]+"~', $contact );
gbcr_check( $real_secret ? '  with a site key and the secret, the box, Cloudflare\'s script (after ours) and the key for the script' : '  with a site key but no secret in wp-config.php, still no box (it would refuse every message)', $real_secret ? $box && strpos( $contact, 'assets/site.js' ) < strpos( $contact, 'turnstile/v0/api.js' ) : ! str_contains( $contact, 'id="c-turnstile"' ) );
list( , $home ) = gbcr_get( add_query_arg( 'nocache', time() + 4, home_url( '/' ) ) );
gbcr_check( '  and Cloudflare\'s script only on the contact page', ! str_contains( $home, 'challenges.cloudflare.com' ) );
set_theme_mod( 'gbcr_turnstile_sitekey', $real_key );

list( $st, $missing ) = gbcr_get( home_url( '/no-such-page-' . time() . '/' ) );
gbcr_check( 'a missing page says so, in the station\'s look', 404 === $st && str_contains( $missing, 'We can&#039;t find that page' ) || 404 === $st && str_contains( $missing, "We can't find that page" ), (string) $st );

echo "The contact form's sending\n";
$mails = array();
add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) use ( &$mails ) {
		$mails[] = $atts;
		return true; // caught: nothing is sent
	},
	10,
	2
);
$no_turnstile = static function () {
	return ''; // the form's own checks first, without the spam check (its part is further down)
};
add_filter( 'gbcr_turnstile_secret', $no_turnstile );
$ip                     = '203.0.113.' . wp_rand( 1, 200 ); // a test address, so a real visitor's limit is never touched
$_SERVER['REMOTE_ADDR'] = $ip;
$post                   = static function ( array $body ) {
	$req = new WP_REST_Request( 'POST', '/gbcr/v1/contact' );
	$req->set_header( 'content-type', 'application/json' );
	$req->set_body( wp_json_encode( $body ) );
	$res = rest_do_request( $req );
	return array( $res->get_status(), $res->get_data() );
};
$good = array( 'website' => '', 'name' => 'Test Listener', 'phone' => '082 123 4567', 'email' => 'listener@example.org', 'topic' => 'Shout-out', 'message' => "Hello to the harbour!\nAnd the boats." );
set_theme_mod( 'gbcr_contact_to', 'station@example.org' );

list( $s, $d ) = $post( array_merge( $good, array( 'name' => '' ) ) );
gbcr_check( 'no name is refused, with a reason', 422 === $s && false === $d['ok'] && str_contains( $d['error'], 'name' ), $s . ' ' . wp_json_encode( $d ) );
list( $s, $d ) = $post( array_merge( $good, array( 'email' => 'not-an-email' ) ) );
gbcr_check( 'a bad email is refused', 422 === $s && str_contains( $d['error'], 'email' ), $s . ' ' . wp_json_encode( $d ) );
list( $s, $d ) = $post( array_merge( $good, array( 'message' => 'hi' ) ) );
gbcr_check( 'a message that is too short is refused', 422 === $s && str_contains( $d['error'], 'message' ), $s . ' ' . wp_json_encode( $d ) );
gbcr_check( '  and none of those sent anything', array() === $mails, (string) count( $mails ) );
list( $s, $d ) = $post( array_merge( $good, array( 'website' => 'http://spam.example' ) ) );
gbcr_check( 'a robot that fills the hidden field is told it worked, and nothing is sent', 200 === $s && true === $d['ok'] && array() === $mails, $s . ' ' . count( $mails ) );

list( $s, $d ) = $post( $good );
$m = $mails[0] ?? array();
$h = implode( "\n", (array) ( $m['headers'] ?? array() ) );
gbcr_check( 'a good message is sent', 200 === $s && true === $d['ok'] && 1 === count( $mails ), $s . ' ' . wp_json_encode( $d ) );
gbcr_check( '  to the station\'s address, with the topic and the name in the subject', 'station@example.org' === ( $m['to'] ?? '' ) && '[GBCR] Shout-out from Test Listener' === ( $m['subject'] ?? '' ), wp_json_encode( array( $m['to'] ?? '', $m['subject'] ?? '' ) ) );
$from_ok = is_email( 'wordpress@' . wp_parse_url( home_url(), PHP_URL_HOST ) ) // a real domain: the website's own sender; "localhost": WordPress's default
	? 1 === preg_match( '~From: Gansbaai Community Radio website <wordpress@[^>]+>~', $h ) : ! str_contains( $h, 'From:' );
gbcr_check( '  Reply goes to the listener; it comes from the website; it is HTML', str_contains( $h, 'Reply-To: Test Listener <listener@example.org>' ) && $from_ok && str_contains( $h, 'Content-Type: text/html' ), $h );
gbcr_check( '  the email has the name, email, phone and the message (its line break kept)', str_contains( $m['message'] ?? '', 'Test Listener' ) && str_contains( $m['message'] ?? '', 'mailto:listener@example.org' )
	&& str_contains( $m['message'] ?? '', 'tel:0821234567' ) && str_contains( $m['message'] ?? '', 'Hello to the harbour!<br>' ) );
gbcr_check( '  in Gansbaai\'s colours, with no WordWave left in it', str_contains( $m['message'] ?? '', '#0A6FA8' ) && str_contains( $m['message'] ?? '', 'Gansbaai Community Radio' ) && false === stripos( $m['message'] ?? '', 'wordwave' ) );
list( , $text ) = GBCR_Mail::contact( 'Shout-out', 'Test Listener', 'listener@example.org', '', 'Hi there' );
gbcr_check( '  and a plain-text copy goes with it', str_starts_with( $text, "Topic: Shout-out\r\nName: Test Listener\r\nEmail: listener@example.org\r\nPhone: -\r\n" ) && str_ends_with( $text, "\r\n\r\nHi there" ), $text );

$mails = array();
list( $s, $d ) = $post( array_merge( $good, array( 'name' => "Eve\r\nBcc: victim@example.org", 'topic' => 'Not a topic', 'message' => '<script>alert(1)</script> Hello there' ) ) );
$m = $mails[0] ?? array();
$h = implode( "\n", (array) ( $m['headers'] ?? array() ) );
gbcr_check( 'a line break in the name can\'t add a mail header', 200 === $s && ! str_contains( $h, "\nBcc:" ) && ! str_contains( (string) ( $m['subject'] ?? '' ), "\n" ), $h );
gbcr_check( '  an unknown topic becomes "Something else"', str_starts_with( (string) ( $m['subject'] ?? '' ), '[GBCR] Something else from ' ), (string) ( $m['subject'] ?? '' ) );
gbcr_check( '  and HTML in the message is never sent as HTML', ! str_contains( $m['message'] ?? '', '<script>' ), '' );

$mails = array();
for ( $i = 0; $i < 4; $i++ ) {
	list( $s, $d ) = $post( $good ); // the 3rd to 6th in this hour
}
gbcr_check( 'after five messages in an hour from one visitor, the next is turned away', 429 === $s && false === $d['ok'] && 3 === count( $mails ), $s . ' ' . count( $mails ) );
$_SERVER['REMOTE_ADDR'] = '203.0.113.251';
list( $s, $d ) = $post( $good );
gbcr_check( '  while another visitor can still send', 200 === $s && true === $d['ok'], (string) $s );

set_theme_mod( 'gbcr_contact_to', 'not an address' );
$_SERVER['REMOTE_ADDR'] = '203.0.113.252';
$mails                  = array();
list( $s, $d )          = $post( $good );
gbcr_check( 'with no good address set for messages, they go to the site\'s admin email', 200 === $s && ( $mails[0]['to'] ?? '' ) === get_option( 'admin_email' ), wp_json_encode( $mails[0]['to'] ?? '' ) );

echo "The spam check (Cloudflare Turnstile)\n";
set_theme_mod( 'gbcr_contact_to', 'station@example.org' );
remove_filter( 'gbcr_turnstile_secret', $no_turnstile );
$secret = 'the-test-secret';
add_filter(
	'gbcr_turnstile_secret',
	static function () use ( &$secret ) {
		return $secret;
	}
);
set_theme_mod( 'gbcr_turnstile_sitekey', 'the-test-sitekey' );
$host   = wp_parse_url( home_url(), PHP_URL_HOST );
$answer = null; // what "Cloudflare" says next: an array (as JSON, HTTP 200), an int (that HTTP status) or a WP_Error; null: the real Cloudflare
$asked  = array();
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$answer, &$asked ) {
		if ( 'https://challenges.cloudflare.com/turnstile/v0/siteverify' !== $url || null === $answer ) {
			return $pre;
		}
		$asked[] = $args['body'];
		if ( $answer instanceof WP_Error ) {
			return $answer;
		}
		return array(
			'headers'  => array(),
			'body'     => is_int( $answer ) ? 'Bad gateway' : wp_json_encode( $answer ),
			'response' => array( 'code' => is_int( $answer ) ? $answer : 200, 'message' => '' ),
			'cookies'  => array(),
		);
	},
	10,
	3
);
$ips  = array();
$send = static function ( $token, $cf ) use ( $post, $good, &$answer, &$asked, &$mails, &$ips ) {
	$_SERVER['REMOTE_ADDR'] = '203.0.113.' . ( 210 + count( $ips ) ); // each its own visitor, so the hourly limit stays out of it
	$ips[]                  = $_SERVER['REMOTE_ADDR'];
	$answer                 = $cf;
	$asked                  = array();
	$mails                  = array();
	$body                   = null === $token ? $good : array_merge( $good, array( 'cf-turnstile-response' => $token ) );
	return $post( $body );
};
$pass = array( 'success' => true, 'action' => 'contact', 'hostname' => $host, 'error-codes' => array() );

list( $s, $d ) = $send( null, $pass );
gbcr_check( 'with the check on, a message without its token is refused, with a reason, and Cloudflare isn\'t even asked', 403 === $s && str_contains( (string) ( $d['error'] ?? '' ), 'spam check' ) && array() === $mails && array() === $asked, $s . ' ' . wp_json_encode( $d ) );
list( $s, ) = $send( str_repeat( 'x', 2049 ), $pass );
gbcr_check( '  so is a token that is far too long', 403 === $s && array() === $mails && array() === $asked, (string) $s );
list( $s, ) = $send( array( 'a' ), $pass );
gbcr_check( '  and a token that isn\'t text', 403 === $s && array() === $mails, (string) $s );
list( $s, $d ) = $send( 'a-good-token', $pass );
gbcr_check( 'a token Cloudflare passes, for this form on this site: the message is sent', 200 === $s && true === $d['ok'] && 1 === count( $mails ), $s . ' ' . wp_json_encode( $d ) );
gbcr_check( '  Cloudflare was asked once, with our secret and the visitor\'s token', 1 === count( $asked ) && 'the-test-secret' === ( $asked[0]['secret'] ?? '' ) && 'a-good-token' === ( $asked[0]['response'] ?? '' ), wp_json_encode( $asked ) );
list( $s, ) = $send( 'a-used-token', array( 'success' => false, 'error-codes' => array( 'timeout-or-duplicate' ) ) );
gbcr_check( 'a token used before (Cloudflare: timeout-or-duplicate) is refused', 403 === $s && array() === $mails, (string) $s );
list( $s, ) = $send( 'a-bad-token', array( 'success' => false, 'error-codes' => array( 'invalid-input-response' ) ) );
gbcr_check( 'a token Cloudflare fails is refused', 403 === $s && array() === $mails, (string) $s );
list( $s, ) = $send( 'another-forms-token', array_merge( $pass, array( 'action' => 'signup' ) ) );
gbcr_check( 'a token from another form (another action) is refused', 403 === $s && array() === $mails, (string) $s );
list( $s, ) = $send( 'another-sites-token', array_merge( $pass, array( 'hostname' => 'elsewhere.example' ) ) );
gbcr_check( 'a token from another website (another host name) is refused', 403 === $s && array() === $mails, (string) $s );
$prod          = 'production' === wp_get_environment_type();
list( $s, )    = $send( 'a-test-key-token', array( 'success' => true, 'hostname' => 'example.com', 'error-codes' => array(), 'metadata' => array( 'result_with_testing_key' => true ) ) );
gbcr_check( $prod ? 'an answer for Cloudflare\'s test keys is refused on a production site' : 'an answer for Cloudflare\'s test keys passes on this ' . wp_get_environment_type() . ' site', $prod ? 403 === $s : 200 === $s, (string) $s );
list( $s, ) = $send( 'a-token', new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) );
gbcr_check( 'when Cloudflare can\'t be reached, the message still goes (the user\'s choice)', 200 === $s && 1 === count( $mails ), (string) $s );
list( $s, ) = $send( 'a-token', 502 );
gbcr_check( '  or answers with an error page', 200 === $s && 1 === count( $mails ), (string) $s );
list( $s, ) = $send( 'a-token', array( 'success' => false, 'error-codes' => array( 'invalid-input-secret' ) ) );
gbcr_check( '  or says our own secret is wrong (our fault, not the visitor\'s)', 200 === $s && 1 === count( $mails ), (string) $s );
list( $s, ) = $send( 'a-token', array( 'success' => false, 'error-codes' => array( 'invalid-input-response' ) ) );
gbcr_check( 'a refused token doesn\'t use up one of the visitor\'s five', 403 === $s && false === get_transient( 'gbcr_rl_' . substr( wp_hash( (string) end( $ips ) ), 0, 24 ) ), (string) $s );

// the real Cloudflare, with its public test secrets (one always fails, one always passes)
$secret     = '2x0000000000000000000000000000000AA';
list( $s, ) = $send( 'XXXX.DUMMY.TOKEN.XXXX', null );
gbcr_check( 'the real Cloudflare, with its always-fail test secret: refused', 403 === $s && array() === $mails, (string) $s );
$secret     = '1x0000000000000000000000000000000AA';
list( $s, ) = $send( 'XXXX.DUMMY.TOKEN.XXXX', null );
gbcr_check( $prod ? '  with its always-pass test secret: still refused here (a test key on a production site)' : '  with its always-pass test secret: sent on this test site', $prod ? 403 === $s : 200 === $s && 1 === count( $mails ), (string) $s );
$ip_list = array_merge( array( $ip, '203.0.113.251', '203.0.113.252' ), $ips );

// the rate-limit entries of the test's addresses go, and the settings come back
foreach ( $ip_list as $a ) {
	delete_transient( 'gbcr_rl_' . substr( wp_hash( $a ), 0, 24 ) );
}
$restore();

echo "\n" . ( 0 === $GLOBALS['gbcr_fails'] ? 'All ' . $GLOBALS['gbcr_count'] . ' checks passed' : $GLOBALS['gbcr_fails'] . ' of ' . $GLOBALS['gbcr_count'] . ' checks FAILED' ) . "\n";
if ( $GLOBALS['gbcr_fails'] ) {
	exit( 1 );
}
