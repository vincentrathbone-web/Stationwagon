<?php
/**
 * Plugin Name: Stationwagon Updates
 * Description: Hands out Stationwagon Studio for Windows (the installer and its updates) from the private Stationwagon repository's GitHub releases. The GitHub token stays on this server.
 * Version: 1.0.0
 * Author: Controll
 * Requires PHP: 7.4
 * License: Proprietary
 *
 * The app's updater asks a plain public address with no sign-in, and so does a browser downloading
 * the installer; a private repository's releases need a token. This plugin asks GitHub with the
 * token (Settings → Stationwagon Updates) and answers in the updater's format:
 *
 *   GET /wp-json/stationwagon/v1/desktop/latest-release?current_version=0.6.0
 *       204 when there is nothing newer, else {version, notes, pub_date, platforms: {windows-x86_64: {signature, url}}}
 *   GET /wp-json/stationwagon/v1/desktop/latest-release/download?asset=<name>
 *       the newest release's file of that name (a short-lived GitHub download link, or the bytes)
 *   GET /wp-json/stationwagon/v1/desktop/installer
 *       the newest installer, for the "Get the Windows app" link in the studio
 *
 * A release needs the NSIS installer (…-setup.exe) and its signature (…-setup.exe.sig); its tag is
 * the version (v0.6.0). The token: a fine-grained one for this repository only, Contents read-only.
 */

defined('ABSPATH') || exit;

final class Stationwagon_Updates
{
    const OPTION_TOKEN = 'stationwagon_updates_token';
    const OPTION_REPO = 'stationwagon_updates_repo';
    const DEFAULT_REPO = 'vincentrathbone-web/Stationwagon';
    const CACHE_KEY = 'stationwagon_updates_release';
    const NS = 'stationwagon/v1';

    public static function boot(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'settings']);
    }

    // ---- The public routes ------------------------------------------------------------------------

    public static function routes(): void
    {
        $open = ['methods' => 'GET', 'permission_callback' => '__return_true'];
        register_rest_route(self::NS, '/desktop/latest-release', $open + ['callback' => [self::class, 'latest']]);
        register_rest_route(self::NS, '/desktop/latest-release/download', $open + ['callback' => [self::class, 'download']]);
        register_rest_route(self::NS, '/desktop/installer', $open + ['callback' => [self::class, 'installer']]);
    }

    /** The updater's question: is there a version newer than current_version? */
    public static function latest($request)
    {
        $release = self::release();
        $current = (string) $request->get_param('current_version');
        if (!is_array($release) || $current === '') {
            return new WP_REST_Response(null, 204);
        }
        $version = ltrim((string) $release['tag_name'], 'vV');
        if (version_compare($version, $current, '<=')) {
            return new WP_REST_Response(null, 204);
        }
        $installer = self::asset($release, '-setup.exe');
        $signature = self::asset($release, '-setup.exe.sig');
        $sig = $signature ? self::contents((int) $signature['id']) : false;
        if (!$installer || $sig === false) {
            return new WP_REST_Response(null, 204);
        }
        $response = new WP_REST_Response([
            'version' => $version,
            'notes' => (string) ($release['body'] ?? ''),
            'pub_date' => (string) ($release['published_at'] ?? gmdate('c')),
            'platforms' => [
                'windows-x86_64' => [
                    'signature' => trim($sig),
                    'url' => add_query_arg('asset', rawurlencode($installer['name']), rest_url(self::NS . '/desktop/latest-release/download')),
                ],
            ],
        ], 200);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    /** One file of the newest release. */
    public static function download($request)
    {
        $release = self::release();
        $name = (string) $request->get_param('asset');
        $asset = is_array($release) ? self::asset($release, '', $name) : null;
        if (!$asset) {
            return new WP_Error('stationwagon_no_asset', 'That file isn’t in the newest release.', ['status' => 404]);
        }
        return self::send($asset);
    }

    /** The newest installer, for people installing the app for the first time. */
    public static function installer($request)
    {
        $release = self::release();
        $asset = is_array($release) ? self::asset($release, '-setup.exe') : null;
        if (!$asset) {
            return new WP_Error('stationwagon_no_release', 'No installer is published yet.', ['status' => 404]);
        }
        return self::send($asset);
    }

    // ---- GitHub ----------------------------------------------------------------------------------

    private static function api(): string
    {
        // a test can point this at a stand-in for GitHub
        return rtrim((string) apply_filters('stationwagon_updates_github_api', 'https://api.github.com'), '/');
    }

    private static function repo(): string
    {
        $repo = trim((string) get_option(self::OPTION_REPO, ''));
        return preg_match('~^[\w.-]+/[\w.-]+$~', $repo) ? $repo : self::DEFAULT_REPO;
    }

    private static function headers(string $accept): array
    {
        return [
            'Authorization' => 'Bearer ' . get_option(self::OPTION_TOKEN, ''),
            'Accept' => $accept,
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'Stationwagon-Updates-WordPress-Plugin',
        ];
    }

    /** The newest published release (cached for 10 minutes), or null; the reason is kept for the settings page. */
    public static function release(bool $fresh = false): ?array
    {
        if (get_option(self::OPTION_TOKEN, '') === '') {
            self::note('No GitHub token is saved yet.');
            return null;
        }
        if (!$fresh) {
            $cached = get_transient(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $r = wp_remote_get(self::api() . '/repos/' . self::repo() . '/releases/latest', ['timeout' => 10, 'headers' => self::headers('application/vnd.github+json')]);
        if (is_wp_error($r)) {
            self::note('GitHub couldn’t be reached: ' . $r->get_error_message());
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($r);
        if ($code !== 200) {
            self::note($code === 404 ? 'GitHub has no published release there (or the token can’t see the repository).' : ($code === 401 ? 'GitHub refused the token (401).' : "GitHub answered $code."));
            return null;
        }
        $release = json_decode((string) wp_remote_retrieve_body($r), true);
        if (!is_array($release) || empty($release['tag_name'])) {
            self::note('GitHub’s answer had no release in it.');
            return null;
        }
        set_transient(self::CACHE_KEY, $release, 10 * MINUTE_IN_SECONDS);
        self::note('');
        return $release;
    }

    private static function note(string $text): void
    {
        update_option('stationwagon_updates_last_error', $text, false);
    }

    private static function asset(array $release, string $suffix, string $exact = ''): ?array
    {
        foreach ((array) ($release['assets'] ?? []) as $a) {
            $n = (string) ($a['name'] ?? '');
            if ($n === '') {
                continue;
            }
            if ($exact !== '' ? $n === $exact : substr($n, -strlen($suffix)) === $suffix) {
                return $a;
            }
        }
        return null;
    }

    /**
     * Where GitHub keeps an asset's bytes: it answers the signed-in request with a short-lived link
     * (302) that needs no token. Asked without following it, so the token never goes anywhere else.
     */
    private static function location(int $id): array
    {
        $r = wp_remote_get(self::api() . '/repos/' . self::repo() . '/releases/assets/' . $id, ['timeout' => 15, 'redirection' => 0, 'headers' => self::headers('application/octet-stream')]);
        if (is_wp_error($r)) {
            return ['error' => $r->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($r);
        if ($code >= 300 && $code < 400 && wp_remote_retrieve_header($r, 'location')) {
            return ['url' => (string) wp_remote_retrieve_header($r, 'location')];
        }
        if ($code === 200) {
            return ['body' => (string) wp_remote_retrieve_body($r)];
        }
        return ['error' => "GitHub answered $code."];
    }

    /** A small asset's text (the signature). */
    private static function contents(int $id)
    {
        $l = self::location($id);
        if (isset($l['body'])) {
            return $l['body'];
        }
        if (!isset($l['url'])) {
            return false;
        }
        $r = wp_remote_get($l['url'], ['timeout' => 15]);
        return !is_wp_error($r) && (int) wp_remote_retrieve_response_code($r) === 200 ? (string) wp_remote_retrieve_body($r) : false;
    }

    /** Sends the visitor (or the updater) to the file: GitHub's short-lived link, or the bytes. */
    private static function send(array $asset)
    {
        $l = self::location((int) $asset['id']);
        if (isset($l['url'])) {
            nocache_headers();
            header('Location: ' . $l['url'], true, 302);
            exit;
        }
        if (!isset($l['body'])) {
            return new WP_Error('stationwagon_github', 'The file couldn’t be fetched from GitHub: ' . ($l['error'] ?? 'unknown'), ['status' => 502]);
        }
        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', (string) $asset['name']) . '"');
        header('Content-Length: ' . strlen($l['body']));
        echo $l['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a binary file
        exit;
    }

    // ---- Settings → Stationwagon Updates ---------------------------------------------------------------

    public static function menu(): void
    {
        add_options_page('Stationwagon Updates', 'Stationwagon Updates', 'manage_options', 'stationwagon-updates', [self::class, 'page']);
    }

    public static function settings(): void
    {
        register_setting('stationwagon_updates', self::OPTION_TOKEN, ['type' => 'string', 'sanitize_callback' => [self::class, 'keepToken']]);
        register_setting('stationwagon_updates', self::OPTION_REPO, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
    }

    /** An empty field keeps the saved token (it is never shown again); "-" clears it. */
    public static function keepToken($value): string
    {
        delete_transient(self::CACHE_KEY);
        $value = trim((string) $value);
        if ($value === '') {
            return (string) get_option(self::OPTION_TOKEN, '');
        }
        return $value === '-' ? '' : preg_replace('/\s+/', '', $value);
    }

    public static function page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $has = get_option(self::OPTION_TOKEN, '') !== '';
        $release = $has ? self::release(true) : null;
        $err = (string) get_option('stationwagon_updates_last_error', '');
        echo '<div class="wrap"><h1>Stationwagon Updates</h1>';
        echo '<p>Stationwagon Studio for Windows checks here for new versions, and the studio’s “Get the Windows app” link downloads from here. This server asks the private GitHub repository with the token below; the token never leaves this server.</p>';
        echo '<form method="post" action="options.php">';
        settings_fields('stationwagon_updates');
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="sw-token">GitHub token</label></th><td><input type="password" id="sw-token" name="' . esc_attr(self::OPTION_TOKEN) . '" value="" class="regular-text" autocomplete="off" placeholder="' . esc_attr($has ? 'A token is saved (leave empty to keep it)' : 'github_pat_…') . '">';
        echo '<p class="description">A fine-grained personal access token for the Stationwagon repository only, with <b>Contents: Read-only</b>. Make one at github.com → Settings → Developer settings → Fine-grained tokens. Type <code>-</code> to remove the saved token.</p></td></tr>';
        echo '<tr><th scope="row"><label for="sw-repo">Repository</label></th><td><input type="text" id="sw-repo" name="' . esc_attr(self::OPTION_REPO) . '" value="' . esc_attr(self::repo()) . '" class="regular-text"></td></tr>';
        echo '</table>';
        submit_button('Save');
        echo '</form><h2>Now</h2><p>';
        if ($release) {
            $inst = self::asset($release, '-setup.exe');
            $sig = self::asset($release, '-setup.exe.sig');
            echo 'Newest release: <b>' . esc_html((string) $release['tag_name']) . '</b>, published ' . esc_html((string) ($release['published_at'] ?? '')) . '.<br>';
            echo 'Installer: ' . ($inst ? esc_html($inst['name']) : '<b>missing</b>') . ' · Signature: ' . ($sig ? esc_html($sig['name']) : '<b>missing</b>') . '<br>';
            echo 'The app checks: <code>' . esc_html(rest_url(self::NS . '/desktop/latest-release')) . '</code><br>';
            echo 'Download link: <a href="' . esc_url(rest_url(self::NS . '/desktop/installer')) . '">' . esc_html(rest_url(self::NS . '/desktop/installer')) . '</a>';
        } else {
            echo esc_html($err !== '' ? $err : 'No release found yet.');
        }
        echo '</p></div>';
    }
}

Stationwagon_Updates::boot();
