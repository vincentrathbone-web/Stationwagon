<?php
/**
 * The contact form's end: POST /wp-json/gbcr/v1/contact emails the message to the station
 * (Appearance > Customize > Gansbaai Community Radio > "Send contact messages to", the site's admin email
 * when empty), with Reply-To set to the sender. Ported from WordWave's plugin (Wordwave_Rest::contact).
 *
 * No nonce (pages may be cached). Protection: a hidden honeypot field (a robot that fills it is told it
 * worked), at most 5 messages an hour from one visitor (a one-way hash of the address, kept an hour),
 * strict lengths and a fixed list of topics. Nothing is stored.
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
		$stop = self::guard( $req );
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
