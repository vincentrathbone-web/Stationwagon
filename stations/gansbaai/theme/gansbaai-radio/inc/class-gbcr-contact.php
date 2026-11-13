<?php
/**
 * The contact form's end: POST /wp-json/gbcr/v1/contact emails the message to the station
 * (Appearance > Customize > Gansbaai Community Radio > "Send contact messages to", the site's admin email
 * when empty), with Reply-To set to the sender. Ported from WordWave's plugin (Wordwave_Rest::contact).
 *
 * No nonce (pages may be cached). Protection: a hidden honeypot field (a robot that fills it is told it
 * worked), Cloudflare Turnstile when it is set up (round 80; see turnstile()), at most 5 messages an hour
 * from one visitor (a one-way hash of the address, kept an hour), strict lengths and a fixed list of
 * topics. Nothing is stored.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GBCR_Contact {

	const MAX_PER_HOUR = 5;

	/** The topics, in the form's order; the first five are the quick picks. */
	public static function topics() {
		return array( 'Song request', 'Shout-out', 'Community notice', 'Advertise', 'Join the team', 'Feedback', 'Something else' );
	}

	public static function init() {
		add_action(
			'rest_api_init',
			static function () {
				register_rest_route(
					'gbcr/v1',
					'/contact',
					array(
						'methods'             => 'POST',
						'callback'            => array( __CLASS__, 'contact' ),
						'permission_callback' => '__return_true',
					)
				);
			}
		);
	}

	private static function reply( $ok, $error = '', $status = 200 ) {
		return new WP_REST_Response( $ok ? array( 'ok' => true ) : array( 'ok' => false, 'error' => $error ), $status );
	}

	/** One line of text: no line breaks (they could add mail headers), trimmed to $max characters. */
	private static function clean( $value, $max ) {
		$value = preg_replace( '/[\r\n]+/', ' ', (string) $value );
		return mb_substr( trim( sanitize_text_field( $value ) ), 0, $max );
	}

	/** Returns a response if the request stops here (a robot, too many), or null to carry on. */
	private static function guard( WP_REST_Request $req ) {
		if ( '' !== (string) $req->get_param( 'website' ) ) {
			return self::reply( true ); // a robot filled the hidden field: it is told it worked
		}
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key  = 'gbcr_rl_' . substr( wp_hash( $ip ), 0, 24 );
		$now  = time();
		$hits = get_transient( $key );
		$hits = is_array( $hits ) ? array_filter(
			$hits,
			static function ( $t ) use ( $now ) {
				return $t > $now - HOUR_IN_SECONDS;
			}
		) : array();
		if ( count( $hits ) >= self::MAX_PER_HOUR ) {
			return self::reply( false, 'You have sent a few messages already. Please try again in a little while.', 429 );
		}
		$hits[] = $now;
		set_transient( $key, array_values( $hits ), HOUR_IN_SECONDS );
		return null;
	}

	/**
	 * Cloudflare Turnstile (when set up: gbcr_turnstile_sitekey()). Returns a response if the message
	 * stops here, or null to carry on. The token must pass Cloudflare's siteverify for this form ("contact")
	 * on this site's own host name. When Cloudflare can't be reached, or rejects our own secret, the message
	 * goes through anyway (the user's choice, 2026-11-13: never lose a real message; the hidden field and
	 * the hourly limit still apply) and the error log says so. A key from Cloudflare's test set passes only
	 * outside production (Laragon), since its answers carry neither our host name nor the action.
	 */
	private static function turnstile( WP_REST_Request $req ) {
		if ( '' === gbcr_turnstile_sitekey() ) {
			return null;
		}
		$again = 'The spam check above the button didn\'t pass. Please try it again, then press Send.';
		$token = $req->get_param( 'cf-turnstile-response' );
		if ( ! is_string( $token ) || '' === $token || strlen( $token ) > 2048 ) {
			return self::reply( false, $again, 403 );
		}
		$res = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => gbcr_turnstile_secret(),
					'response' => $token,
				),
			)
		);
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		$r    = 200 === $code ? json_decode( (string) wp_remote_retrieve_body( $res ), true ) : null;
		if ( ! is_array( $r ) || ! isset( $r['success'] ) ) {
			error_log( 'GBCR contact: Turnstile could not be checked (' . ( is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . $code ) . '); the message was let through' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return null;
		}
		$errors = isset( $r['error-codes'] ) && is_array( $r['error-codes'] ) ? $r['error-codes'] : array();
		if ( array_intersect( $errors, array( 'missing-input-secret', 'invalid-input-secret' ) ) ) {
			error_log( 'GBCR contact: Cloudflare rejected the Turnstile secret (' . implode( ', ', $errors ) . '); the message was let through' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return null;
		}
		if ( true !== $r['success'] ) {
			return self::reply( false, $again, 403 );
		}
		$testing = ! empty( $r['metadata']['result_with_testing_key'] );
		if ( $testing && 'production' !== wp_get_environment_type() ) {
			return null;
		}
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( $testing || 'contact' !== ( $r['action'] ?? '' ) || strtolower( (string) ( $r['hostname'] ?? '' ) ) !== $host ) {
			return self::reply( false, $again, 403 );
		}
		return null;
	}

	public static function contact( WP_REST_Request $req ) {
		$name    = self::clean( $req->get_param( 'name' ), 100 );
		$email   = sanitize_email( (string) $req->get_param( 'email' ) );
		$phone   = self::clean( preg_replace( '/[^0-9+() \-]/', '', (string) $req->get_param( 'phone' ) ), 30 );
		$message = trim( mb_substr( sanitize_textarea_field( (string) $req->get_param( 'message' ) ), 0, 4000 ) );
		$topic   = (string) $req->get_param( 'topic' );
		$topic   = in_array( $topic, self::topics(), true ) ? $topic : 'Something else';

		// the checks first, so a mistake doesn't use up one of the visitor's five
		if ( '' !== (string) $req->get_param( 'website' ) ) {
			return self::guard( $req );
		}
		if ( mb_strlen( $name ) < 2 ) {
			return self::reply( false, 'Please type your name.', 422 );
		}
		if ( ! is_email( $email ) ) {
			return self::reply( false, 'Please type an email address we can reply to.', 422 );
		}
		if ( mb_strlen( $message ) < 5 ) {
			return self::reply( false, 'Please type a message.', 422 );
		}
		$stop = self::turnstile( $req );
		$stop = $stop ? $stop : self::guard( $req );
		if ( $stop ) {
			return $stop;
		}

		$to = sanitize_email( (string) gbcr_setting( 'contact_to' ) );
		if ( ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' ); // a mistyped setting never loses a message
		}
		list( $html, $text ) = GBCR_Mail::contact( $topic, $name, $email, $phone, $message );
		$headers = array( 'Reply-To: ' . preg_replace( '/[<>",;:]+/', '', $name ) . ' <' . $email . '>' );
		$from    = 'wordpress@' . preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( is_email( $from ) ) {
			$headers[] = 'From: Gansbaai Community Radio website <' . $from . '>';
		}
		if ( ! is_email( $to ) || ! GBCR_Mail::send( $to, '[GBCR] ' . $topic . ' from ' . $name, $html, $text, $headers ) ) {
			return self::reply( false, 'Sorry, we could not send that right now. Please try again later.', 500 );
		}
		return self::reply( true );
	}
}
