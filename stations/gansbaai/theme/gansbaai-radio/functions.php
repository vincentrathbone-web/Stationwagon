<?php
/**
 * Gansbaai Community Radio theme (round 79).
 *
 * The pages: the front page (front-page.php), the line-up (page-line-up.php, a page with the slug
 * "line-up"), contact (page-contact.php, slug "contact") and any other page (page.php, e.g. the privacy
 * policy). The look and the script are assets/site.css and assets/site.js, the same files the design pages
 * in stations/gansbaai/site use. The settings are under Appearance > Customize > Gansbaai Community Radio.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const GBCR_VERSION = '1.0.0';

require __DIR__ . '/inc/class-gbcr-mail.php';
require __DIR__ . '/inc/class-gbcr-contact.php';
GBCR_Contact::init();

/** A theme setting (Customizer), with its default. */
function gbcr_setting( $key ) {
	$defaults = array(
		'station_url'  => home_url( '/radio' ),
		'contact_to'   => get_option( 'admin_email' ),
		'public_email' => '',
		'whatsapp'     => '',
		'studio'       => 'Gansbaai, Western Cape, South Africa',
		'sample'       => true,
	);
	$value = get_theme_mod( 'gbcr_' . $key, $defaults[ $key ] ?? '' );
	return ( '' === $value || null === $value ) && isset( $defaults[ $key ] ) && 'sample' !== $key ? $defaults[ $key ] : $value;
}

/** The address of a page by its slug (the line-up, contact), or the home page with an anchor if it is missing. */
function gbcr_page_url( $slug ) {
	$page = get_page_by_path( $slug );
	return $page ? get_permalink( $page ) : home_url( '/' );
}

/** The privacy policy: the site's own page when it is published, else the station's. */
function gbcr_privacy_url() {
	$url = get_privacy_policy_url();
	return $url ? $url : trailingslashit( gbcr_setting( 'station_url' ) ) . 'privacy';
}

/** The asset's address with its file time as the version, so a new file is never served from a cache. */
function gbcr_asset( $path ) {
	$file = get_theme_file_path( $path );
	$ver  = file_exists( $file ) ? (string) filemtime( $file ) : GBCR_VERSION;
	return add_query_arg( 'v', $ver, get_theme_file_uri( $path ) );
}

/** One of the line icons (Feather, MIT), as inline SVG. */
function gbcr_icon( $name ) {
	$paths = array(
		'pin'     => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
		'chat'    => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
		'mail'    => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
		'clock'   => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
		'mic'     => '<path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/>',
		'music'   => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
		'phone'   => '<rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/>',
		'heart'   => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
		'notice'  => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/>',
		'trend'   => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
		'info'    => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
		'send'    => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
		'menu'    => '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>',
		'close'   => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
		'down'    => '<polyline points="6 9 12 15 18 9"/>',
	);
	return '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">' . ( $paths[ $name ] ?? '' ) . '</svg>';
}

add_action(
	'after_setup_theme',
	static function () {
		add_theme_support( 'title-tag' );
		add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );
	}
);

add_action(
	'wp_enqueue_scripts',
	static function () {
		wp_enqueue_style( 'gbcr-sora', 'https://fonts.googleapis.com/css2?family=Sora:wght@300;400;600&display=swap', array(), null );
		wp_enqueue_style( 'gbcr-site', gbcr_asset( 'assets/site.css' ), array(), null );
		wp_enqueue_script( 'gbcr-site', gbcr_asset( 'assets/site.js' ), array(), null, array( 'in_footer' => true ) );
		wp_add_inline_script(
			'gbcr-site',
			'window.GBCR = ' . wp_json_encode(
				array(
					'station'    => untrailingslashit( gbcr_setting( 'station_url' ) ),
					'contactUrl' => rest_url( 'gbcr/v1/contact' ),
					'sample'     => (bool) gbcr_setting( 'sample' ),
				)
			) . ';',
			'before'
		);
		// WordPress's own block styles are not used by these templates
		wp_dequeue_style( 'wp-block-library' );
		wp_dequeue_style( 'global-styles' );
		wp_dequeue_style( 'classic-theme-styles' );
	},
	20
);

add_action(
	'wp_head',
	static function () {
		echo '<meta name="theme-color" content="#03182B">' . "\n";
		echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
		if ( ! has_site_icon() ) {
			echo '<link rel="icon" href="' . esc_url( gbcr_asset( 'assets/img/favicon.ico' ) ) . '">' . "\n";
			echo '<link rel="apple-touch-icon" href="' . esc_url( gbcr_asset( 'assets/img/apple-touch-icon-180.png' ) ) . '">' . "\n";
		}
		if ( is_front_page() ) {
			echo '<meta name="description" content="' . esc_attr__( 'Gansbaai Community Radio: local voices, music all day and the news from the harbour to the hills. Listen live, free, on any device.', 'gansbaai-radio' ) . '">' . "\n";
		}
	},
	1
);

add_filter(
	'document_title_separator',
	static function () {
		return '·';
	}
);

/* ---------- Appearance > Customize > Gansbaai Community Radio ---------- */
add_action(
	'customize_register',
	static function ( WP_Customize_Manager $c ) {
		$c->add_section(
			'gbcr',
			array(
				'title'       => 'Gansbaai Community Radio',
				'description' => 'The station, and what the contact page shows. Leave a box empty to hide that line.',
				'priority'    => 30,
			)
		);
		$fields = array(
			'station_url'  => array( 'The station (Stationwagon) address', 'url', 'esc_url_raw', 'Where the line-up, the song on air and the player come from.' ),
			'contact_to'   => array( 'Send contact messages to', 'email', 'sanitize_email', 'Not shown on the site.' ),
			'public_email' => array( 'Email shown on the contact page', 'email', 'sanitize_email', '' ),
			'whatsapp'     => array( 'WhatsApp number shown on the contact page', 'text', 'sanitize_text_field', 'For example +27 82 123 4567.' ),
			'studio'       => array( 'Where the studio is', 'text', 'sanitize_text_field', '' ),
		);
		foreach ( $fields as $key => $f ) {
			$c->add_setting( 'gbcr_' . $key, array( 'default' => '', 'sanitize_callback' => $f[2] ) );
			$c->add_control( 'gbcr_' . $key, array( 'label' => $f[0], 'type' => $f[1], 'section' => 'gbcr', 'description' => $f[3] ) );
		}
		$c->add_setting( 'gbcr_sample', array( 'default' => true, 'sanitize_callback' => 'rest_sanitize_boolean' ) );
		$c->add_control(
			'gbcr_sample',
			array(
				'label'       => 'Show sample shows while the station has no line-up',
				'type'        => 'checkbox',
				'section'     => 'gbcr',
				'description' => 'They are marked as samples. Once the station has its own shows, those show instead.',
			)
		);
	}
);
