<?php
/**
 * Plugin Name: Stationwagon
 * Description: Sets up a Stationwagon radio station next to this site in a few clicks: an AutoDJ with real crossfades, a listener stream, and a studio for live shows in the browser, on Windows or on Android. Adds a [stationwagon_player] shortcode for the player.
 * Version: 1.2.1
 * Author: Controll
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * License: Proprietary
 * Text Domain: stationwagon
 *
 * The station itself runs on its own, in a folder of this site or on a subdomain: listeners' stream
 * requests never start WordPress. This plugin is its installer (Stationwagon in the admin menu),
 * keeps its code up to date when the plugin is updated, and puts its player on pages.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/includes/class-cpanel.php';
require_once __DIR__ . '/includes/class-setup.php';
require_once __DIR__ . '/includes/class-admin.php';
require_once __DIR__ . '/includes/class-people.php';

Stationwagon_Admin::boot();
Stationwagon_People::boot();

/**
 * [stationwagon_player] or [stationwagon_player look="light"]: the station's small player, the
 * same one its "Put the player on your website" code gives.
 */
add_shortcode('stationwagon_player', static function ($atts): string {
    $station = Stationwagon_Setup::station();
    if (!$station) {
        return current_user_can('manage_options') ? '<p><em>Set up Stationwagon first (Stationwagon in the admin menu).</em></p>' : '';
    }
    $atts = shortcode_atts(['look' => 'dark'], (array) $atts, 'stationwagon_player');
    $src = $station['url'] . 'embed' . ($atts['look'] === 'light' ? '?look=light' : '');
    return sprintf('<iframe src="%s" title="%s player" width="100%%" height="124" style="max-width:520px;border:0" allow="autoplay"></iframe>',
        esc_url($src), esc_attr((string) get_bloginfo('name')));
});
