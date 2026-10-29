<?php
/**
 * The admin screens: the setup wizard before the station exists, its status afterwards. Only
 * people who can manage the site's options see or use them.
 */

defined('ABSPATH') || exit;

final class Stationwagon_Admin
{
    const SLUG = 'stationwagon';
    const FLASH = 'stationwagon_flash_';

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'keepUpToDate']);
        add_action('admin_post_stationwagon_install', [self::class, 'install']);
        add_action('admin_post_stationwagon_check', [self::class, 'check']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_filter('plugin_action_links_' . plugin_basename(dirname(__DIR__) . '/stationwagon.php'), [self::class, 'links']);
    }

    public static function menu(): void
    {
        add_menu_page('Stationwagon', 'Stationwagon', 'manage_options', self::SLUG, [self::class, 'page'], 'dashicons-microphone', 58);
    }

    public static function links(array $links): array
    {
        array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">' . (Stationwagon_Setup::station() ? 'Open' : 'Set up') . '</a>');
        return $links;
    }

    public static function assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_' . self::SLUG) {
            return;
        }
        $base = plugins_url('assets/', dirname(__DIR__) . '/stationwagon.php');
        wp_enqueue_style('stationwagon-admin', $base . 'admin.css', [], '1.0.0');
        wp_enqueue_script('stationwagon-admin', $base . 'admin.js', [], '1.0.0', true);
    }

    /** After the plugin is updated, bring the station's code along (on the next admin page). */
    public static function keepUpToDate(): void
    {
        if (!current_user_can('manage_options') || wp_doing_ajax()) {
            return;
        }
        try {
            $v = Stationwagon_Setup::update();
            if ($v !== null) {
                self::flash(['ok' => "Your station was updated to version $v."]);
            }
        } catch (Throwable $ex) {
            self::flash(['error' => 'Updating the station failed: ' . $ex->getMessage()]);
        }
    }

    // ---- Form posts ------------------------------------------------------------------------------

    public static function install(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Only an administrator can set up Stationwagon.', 403);
        }
        check_admin_referer('stationwagon_install');
        $in = Stationwagon_Setup::defaults();
        foreach ($in as $k => $v) {
            if (isset($_POST[$k]) && is_string($_POST[$k])) {
                $raw = wp_unslash($_POST[$k]);
                $in[$k] = in_array($k, ['cpanel_pass', 'db_pass', 'admin_password'], true) ? $raw : sanitize_text_field($raw);
            }
        }
        foreach (['folder', 'subdomain'] as $k) {
            $in[$k] = strtolower(trim($in[$k]));
        }
        $keep = array_diff_key($in, array_flip(['cpanel_pass', 'db_pass', 'admin_password'])); // never kept
        $errors = Stationwagon_Setup::validate($in);
        if ($errors) {
            self::flash(['form' => $keep, 'errors' => $errors]);
            self::back();
        }
        try {
            $result = Stationwagon_Setup::install($in);
            self::flash(['steps' => $result['steps'], 'ok' => 'Your station is set up.']);
        } catch (Stationwagon_Setup_Failed $ex) {
            self::flash(['form' => $keep, 'steps' => $ex->steps, 'error' => $ex->getMessage()]);
        }
        self::back();
    }

    /** "Check again" for a subdomain waiting for its DNS record. */
    public static function check(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Only an administrator can do this.', 403);
        }
        check_admin_referer('stationwagon_check');
        $s = Stationwagon_Setup::station();
        if ($s) {
            $why = Stationwagon_Setup::reachable($s['url']);
            $s['reachable'] = $why === null;
            update_option(Stationwagon_Setup::OPTION, $s, false);
            self::flash($why === null ? ['ok' => 'Your station answers at ' . $s['url']] : ['error' => "Not yet: $why DNS changes can take up to an hour."]);
        }
        self::back();
    }

    private static function back(): void
    {
        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
        exit;
    }

    private static function flash(?array $set = null): array
    {
        $key = self::FLASH . get_current_user_id();
        if ($set !== null) {
            set_transient($key, $set + (get_transient($key) ?: []), 300);
            return $set;
        }
        $v = get_transient($key) ?: [];
        delete_transient($key);
        return $v;
    }

    // ---- The page --------------------------------------------------------------------------------

    public static function page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $flash = self::flash();
        $station = Stationwagon_Setup::station();
        echo '<div class="wrap sw">';
        echo '<h1 class="sw-title"><span class="dashicons dashicons-microphone"></span> Stationwagon</h1>';
        if (!empty($flash['ok'])) {
            printf('<div class="notice notice-success"><p>%s</p></div>', esc_html($flash['ok']));
        }
        if (!empty($flash['error'])) {
            printf('<div class="notice notice-error"><p><strong>Setup stopped:</strong> %s</p></div>', esc_html($flash['error']));
        }
        if (!empty($flash['steps'])) {
            echo '<ol class="sw-done">';
            foreach ($flash['steps'] as [$what, $detail]) {
                printf('<li><span class="dashicons dashicons-yes"></span> %s%s</li>', esc_html($what), $detail !== '' ? ' <small>' . esc_html($detail) . '</small>' : '');
            }
            echo '</ol>';
        }
        $station ? self::status($station) : self::wizard(($flash['form'] ?? []) + Stationwagon_Setup::defaults(), $flash['errors'] ?? []);
        echo '</div>';
    }

    private static function status(array $s): void
    {
        $url = $s['url'];
        echo '<div class="sw-card sw-hero">';
        echo '<p class="sw-kicker">Your station</p>';
        printf('<p class="sw-address"><a href="%1$s" target="_blank" rel="noopener">%1$s</a></p>', esc_url($url));
        echo '<p class="sw-actions">';
        printf('<a class="button button-primary button-hero" href="%s" target="_blank" rel="noopener">Open the manager</a> ', esc_url($url . 'manage'));
        printf('<a class="button button-hero" href="%s" target="_blank" rel="noopener">Open the studio</a> ', esc_url($url . 'studio'));
        printf('<a class="button button-hero" href="%s" target="_blank" rel="noopener">The station page</a>', esc_url($url));
        echo '</p><p class="description">Sign in with the email and password you chose during setup. Add DJs and managers in the manager, under DJs.</p></div>';

        if (empty($s['reachable']) && $s['address'] === 'subdomain') {
            echo '<div class="sw-card sw-warn"><h2>One step left: point the subdomain at this server</h2>';
            echo '<p>The subdomain is made, but its address doesn’t reach this server yet. Where your domain’s DNS is kept (for example Cloudflare, or your domain registrar), add this record:</p>';
            echo '<table class="widefat striped sw-dns"><thead><tr><th>Type</th><th>Name</th><th>Points to</th></tr></thead><tbody>';
            printf('<tr><td>A</td><td><code>%s</code></td><td><code>%s</code></td></tr>', esc_html($s['host']), esc_html($s['ip'] ?: ($_SERVER['SERVER_ADDR'] ?? '')));
            echo '</tbody></table>';
            $elsewhere = array_values(array_diff((array) @gethostbynamel($s['host']), [$s['ip']]));
            if ($elsewhere) {
                printf('<p>%s already leads somewhere else (%s), probably through a catch-all (*) record. This record for the name itself takes its place.</p>', esc_html($s['host']), esc_html(implode(', ', $elsewhere)));
            }
            if (!empty($s['deep'])) {
                printf('<p><b>DNS at Cloudflare?</b> Cloudflare’s free certificate only covers names one level below your domain, and %s is two. Set this record’s <b>Proxy status</b> to <b>DNS only</b> (the grey cloud); this hosting then makes the certificate itself (cPanel AutoSSL), which can take a few hours.</p>', esc_html($s['host']));
            }
            echo '<p class="description">If the DNS is at this hosting (cPanel’s Zone Editor), it’s already there and only needs a few minutes. The secure https address can take up to a day while cPanel makes its certificate.</p>';
            printf('<form method="post" action="%s"><input type="hidden" name="action" value="stationwagon_check">', esc_url(admin_url('admin-post.php')));
            wp_nonce_field('stationwagon_check');
            echo '<p><button class="button button-primary">Check again</button></p></form></div>';
        }

        echo '<div class="sw-grid">';
        echo '<div class="sw-card"><h2>Put the player on a page</h2><p>Add this shortcode to any page or post:</p>';
        echo '<p><code>[stationwagon_player]</code> &nbsp; or for light pages &nbsp; <code>[stationwagon_player look="light"]</code></p></div>';
        echo '<div class="sw-card"><h2>Background jobs</h2>';
        if ($s['jobs'] === 'manual') {
            echo '<p>They run during visits. For exact timing, add this cron job in cPanel → Cron Jobs, <b>Every 5 minutes</b>:</p>';
            printf('<p><code class="sw-copy">%s</code></p>', esc_html($s['cron']));
        } elseif ($s['jobs'] === 'cpanel') {
            echo '<p>A cron job runs them every 5 minutes, and they also run during visits. The station’s Overview shows if the cron job stops.</p>';
        } else {
            echo '<p>They run by themselves during visits, at most every 5 minutes. Nothing to set up.</p>';
        }
        echo '</div>';
        printf('<div class="sw-card"><h2>Details</h2><table class="sw-facts"><tr><th>Version</th><td>%s</td></tr><tr><th>Folder</th><td><code>%s</code></td></tr><tr><th>Database</th><td>%s</td></tr></table>'
            . '<p class="description">Updating this plugin updates the station too. Removing the plugin leaves the station running.</p></div>',
            esc_html($s['version']), esc_html($s['dir']), esc_html(['wordpress' => 'This site’s database', 'new' => 'Its own database', 'manual' => 'The database you gave'][$s['database']] ?? ''));
        echo '</div>';
    }

    private static function wizard(array $in, array $errors): void
    {
        $err = function (string $k) use ($errors): void {
            if (isset($errors[$k])) {
                printf('<p class="sw-error">%s</p>', esc_html($errors[$k]));
            }
        };
        $radio = function (string $name, string $value, string $title, string $text, array $tags = []) use ($in): void {
            printf('<label class="sw-choice"><input type="radio" name="%s" value="%s"%s%s> <span><b>%s</b>%s<small>%s</small></span></label>',
                esc_attr($name), esc_attr($value), checked($in[$name], $value, false),
                in_array('cpanel', $tags, true) ? ' data-cpanel="1"' : '', esc_html($title),
                in_array('cpanel', $tags, true) ? ' <em class="sw-tag">cPanel login</em>' : (in_array('easy', $tags, true) ? ' <em class="sw-tag sw-easy">Recommended</em>' : ''),
                wp_kses($text, ['code' => [], 'b' => []]));
        };
        $field = function (string $name, string $label, string $type = 'text', string $extra = '') use ($in, $err): void {
            printf('<p class="sw-field"><label for="sw-%1$s">%2$s</label><input id="sw-%1$s" name="%1$s" type="%3$s" value="%4$s" class="regular-text" %5$s></p>',
                esc_attr($name), esc_html($label), esc_attr($type), $type === 'password' ? '' : esc_attr($in[$name]), $extra);
            $err($name);
        };
        $site = Stationwagon_Setup::siteHost();
        $ready = Stationwagon_Setup::requirementsMet();

        echo '<p class="sw-lead">Set up your radio station in one go. It runs next to this site on its own, so listeners never slow WordPress down. Every choice below has a sensible answer already picked.</p>';

        $problems = array_filter(Stationwagon_Setup::requirements(), fn($r) => !$r['ok']);
        if ($problems) {
            echo '<div class="sw-card sw-checks"><h2>Server check</h2><ul>';
            foreach ($problems as $r) {
                printf('<li class="%s"><b>%s</b> (%s). %s</li>', $r['required'] ? 'sw-bad' : 'sw-soft', esc_html($r['label']), esc_html($r['found']), esc_html($r['why']));
            }
            echo '</ul></div>';
        } else {
            echo '<p class="sw-ready"><span class="dashicons dashicons-yes-alt"></span> This server is ready for Stationwagon.</p>';
        }

        printf('<form method="post" action="%s" class="sw-form" id="sw-form">', esc_url(admin_url('admin-post.php')));
        echo '<input type="hidden" name="action" value="stationwagon_install">';
        wp_nonce_field('stationwagon_install');

        echo '<section class="sw-card"><h2><span class="sw-n">1</span> Where should the station live?</h2>';
        $radio('address', 'folder', 'A folder of this site', 'Listeners go to <code>' . esc_html(trailingslashit(site_url())) . '<b>radio</b></code>. Nothing else to set up.', ['easy']);
        echo '<p class="sw-sub" data-for="address=folder"><label for="sw-folder">Folder name</label> <code>' . esc_html(trailingslashit(site_url())) . '</code><input id="sw-folder" name="folder" value="' . esc_attr($in['folder']) . '" class="small-text sw-name"></p>';
        $err('folder');
        $radio('address', 'subdomain', 'Its own subdomain', 'Listeners go to <code><b>radio</b>.' . esc_html($site) . '</code>. Made for you in cPanel. If your domain’s DNS is kept elsewhere (like Cloudflare), you add one record afterwards; the next screen shows it.', ['cpanel']);
        echo '<p class="sw-sub" data-for="address=subdomain"><label for="sw-subdomain">Subdomain</label> <input id="sw-subdomain" name="subdomain" value="' . esc_attr($in['subdomain']) . '" class="small-text sw-name"><code>.' . esc_html($site) . '</code></p>';
        $err('subdomain');
        $err('address');
        echo '</section>';

        echo '<section class="sw-card"><h2><span class="sw-n">2</span> Which database?</h2>';
        $radio('database', 'wordpress', 'This site’s database', 'The station keeps its own tables (starting with <code>sw_</code>) next to WordPress’s. Nothing to set up.', ['easy']);
        $radio('database', 'new', 'A new database of its own', 'Made for you in cPanel, with its own user and password. Keeps the station apart from WordPress.', ['cpanel']);
        $radio('database', 'manual', 'A database I’ve already made', 'You made it in cPanel → MySQL Databases and added a user with All Privileges.');
        echo '<div class="sw-sub" data-for="database=manual">';
        $field('db_host', 'Database server');
        $field('db_name', 'Database name');
        $field('db_user', 'Database user');
        $field('db_pass', 'Database password', 'password', 'autocomplete="new-password"');
        echo '</div>';
        $err('database');
        echo '</section>';

        echo '<section class="sw-card"><h2><span class="sw-n">3</span> How should the background jobs run?</h2>';
        echo '<p class="description">The station plans its music ahead, makes podcast episodes after live shows and keeps its listener stats up to date.</p>';
        $radio('jobs', 'visits', 'Automatically, during visits', 'They run by themselves when listeners and staff use the station, at most every 5 minutes. Nothing to set up.', ['easy']);
        $radio('jobs', 'cpanel', 'Also add a cron job for me', 'A cron job runs them every 5 minutes, even at quiet times. Added for you in cPanel.', ['cpanel']);
        $radio('jobs', 'manual', 'I’ll add the cron job myself', 'You get the exact line to paste into cPanel → Cron Jobs after setup.');
        $err('jobs');
        echo '</section>';

        echo '<section class="sw-card sw-cpanel" id="sw-cpanel"><h2><span class="sw-n">4</span> Your cPanel login</h2>';
        echo '<p class="description">Some choices above are done for you in cPanel. Your login is used once, for this setup only, and never saved. (A cPanel API token works in place of the password.)</p>';
        $field('cpanel_user', 'cPanel username', 'text', 'autocomplete="off"');
        $field('cpanel_pass', 'cPanel password', 'password', 'autocomplete="off"');
        echo '</section>';

        echo '<section class="sw-card"><h2><span class="sw-n sw-n-last">5</span> Your station</h2>';
        $field('station_name', 'Station name');
        echo '<p class="sw-field"><label for="sw-timezone">Timezone</label><select id="sw-timezone" name="timezone">';
        foreach (timezone_identifiers_list() as $tz) {
            printf('<option%s>%s</option>', selected($in['timezone'], $tz, false), esc_html($tz));
        }
        echo '</select></p>';
        $err('timezone');
        echo '<p class="description">The schedule and the station clock use it.</p>';
        echo '<h3>Your sign-in for the station</h3><p class="description">You’ll be the station’s first admin. This is the station’s own sign-in, apart from WordPress.</p>';
        $field('admin_name', 'Your name');
        $field('admin_email', 'Email', 'email');
        $field('admin_password', 'Password (at least 10 characters)', 'password', 'autocomplete="new-password" minlength="10"');
        echo '</section>';

        printf('<p class="sw-go"><button type="submit" class="button button-primary button-hero"%s>Set up my station</button> <span class="sw-wait" hidden>Setting up… this takes about a minute.</span></p>', $ready ? '' : ' disabled');
        echo '</form>';
    }
}
