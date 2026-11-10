<?php
/**
 * Plugin Name: Stationwagon Updates
 * Description: Hands out Stationwagon Studio for Windows and Android (the installers and their updates) from the private Stationwagon repository's GitHub releases. The GitHub token stays on this server.
 * Version: 1.2.0
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
 *   GET /wp-json/stationwagon/v1/android/latest-release?current_version=0.7.0
 *       204 when there is nothing newer, else {version, notes, pub_date, url, size, sha256}
 *   GET /wp-json/stationwagon/v1/android/apk
 *       the newest Android app, for the "Get the Android app" link and the app's own updates
 *
 * Windows and Android are released apart (tags like v0.6.0 and android-v0.7.0): each takes the
 * newest published release that has its file. A Windows release needs the NSIS installer
 * (…-setup.exe) and its signature (…-setup.exe.sig), an Android one the APK (…apk). The version is
 * the one in the file's name (Stationwagon-Studio_0.7.0.apk), else the tag's. The token: a
 * fine-grained one for this repository only, Contents read-only.
 *
 * `notes` is the release's description on GitHub (New features / Improvements / Fixes / Warning,
 * `-` bullets): the apps show it in their update dialog. Trimmed to 4000 characters.
 */

defined('ABSPATH') || exit;

final class Stationwagon_Updates
{
    const NOTES_MAX = 4000;
    const OPTION_TOKEN = 'stationwagon_updates_token';
    const OPTION_REPO = 'stationwagon_updates_repo';
    const DEFAULT_REPO = 'vincentrathbone-web/Stationwagon';
    const CACHE_KEY = 'stationwagon_updates_releases';
    const DESKTOP = '-setup.exe';
    const ANDROID = '.apk';
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
        register_rest_route(self::NS, '/android/latest-release', $open + ['callback' => [self::class, 'androidLatest']]);
        register_rest_route(self::NS, '/android/apk', $open + ['callback' => [self::class, 'apk']]);
    }

    /** The updater's question: is there a version newer than current_version? */
    public static function latest($request)
    {
        $release = self::release(self::DESKTOP);
        $current = (string) $request->get_param('current_version');
        if (!is_array($release) || $current === '') {
            return new WP_REST_Response(null, 204);
        }
        $installer = self::asset($release, self::DESKTOP);
        $version = self::version($release, $installer);
        if (version_compare($version, $current, '<=')) {
            return new WP_REST_Response(null, 204);
        }
        $signature = self::asset($release, self::DESKTOP . '.sig');
        $sig = $signature ? self::contents((int) $signature['id']) : false;
        if (!$installer || $sig === false) {
            return new WP_REST_Response(null, 204);
        }
        $response = new WP_REST_Response([
            'version' => $version,
            'notes' => self::notes($release),
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

    /** One file of the newest Windows release. */
    public static function download($request)
    {
        $release = self::release(self::DESKTOP);
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
        $release = self::release(self::DESKTOP);
        $asset = is_array($release) ? self::asset($release, self::DESKTOP) : null;
        if (!$asset) {
            return new WP_Error('stationwagon_no_release', 'No installer is published yet.', ['status' => 404]);
        }
        return self::send($asset);
    }

    /** The Android app's question: is there a version newer than current_version? */
    public static function androidLatest($request)
    {
        $release = self::release(self::ANDROID);
        $current = (string) $request->get_param('current_version');
        $apk = is_array($release) ? self::asset($release, self::ANDROID) : null;
        if (!$apk || $current === '') {
            return new WP_REST_Response(null, 204);
        }
        $version = self::version($release, $apk);
        if (version_compare($version, $current, '<=')) {
            return new WP_REST_Response(null, 204);
        }
        $digest = (string) ($apk['digest'] ?? '');
        $response = new WP_REST_Response([
            'version' => $version,
            'notes' => self::notes($release),
            'pub_date' => (string) ($release['published_at'] ?? gmdate('c')),
            'url' => rest_url(self::NS . '/android/apk'),
            'size' => (int) ($apk['size'] ?? 0),
            'sha256' => strpos($digest, 'sha256:') === 0 ? substr($digest, 7) : '',
        ], 200);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    /** The newest Android app, for installing it the first time and for its updates. */
    public static function apk($request)
    {
        $release = self::release(self::ANDROID);
        $asset = is_array($release) ? self::asset($release, self::ANDROID) : null;
        if (!$asset) {
            return new WP_Error('stationwagon_no_release', 'No Android app is published yet.', ['status' => 404]);
        }
        return self::send($asset);
    }

    /** The version in the file's name (Stationwagon-Studio_0.7.0.apk), else the tag's (android-v0.7.0). */
    private static function version(array $release, ?array $asset): string
    {
        if ($asset && preg_match('~_(\d+(?:\.\d+)+)(?=[_.-])~', (string) $asset['name'], $m)) {
            return $m[1];
        }
        return (string) preg_replace('~^\D*~', '', (string) $release['tag_name']);
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

    /** The newest published release with a file ending in $suffix (Windows or Android), or null. */
    public static function release(string $suffix, bool $fresh = false): ?array
    {
        foreach (self::releases($fresh) as $release) {
            if (self::asset($release, $suffix)) {
                return $release;
            }
        }
        return null;
    }

    /** The newest published releases, newest first (cached for 10 minutes); the reason for none is kept for the settings page. */
    private static function releases(bool $fresh = false): array
    {
        if (get_option(self::OPTION_TOKEN, '') === '') {
            self::note('No GitHub token is saved yet.');
            return [];
        }
        if (!$fresh) {
            $cached = get_transient(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $r = wp_remote_get(self::api() . '/repos/' . self::repo() . '/releases?per_page=30', ['timeout' => 10, 'headers' => self::headers('application/vnd.github+json')]);
        if (is_wp_error($r)) {
            self::note('GitHub couldn’t be reached: ' . $r->get_error_message());
            return [];
        }
        $code = (int) wp_remote_retrieve_response_code($r);
        if ($code !== 200) {
            self::note($code === 404 ? 'GitHub doesn’t show that repository (or the token can’t see it).' : ($code === 401 ? 'GitHub refused the token (401).' : "GitHub answered $code."));
            return [];
        }
        $list = json_decode((string) wp_remote_retrieve_body($r), true);
        if (!is_array($list)) {
            self::note('GitHub’s answer had no releases in it.');
            return [];
        }
        $list = array_values(array_filter($list, function ($x) {
            return is_array($x) && !empty($x['tag_name']) && empty($x['draft']) && empty($x['prerelease']);
        }));
        usort($list, function ($a, $b) {
            return strcmp((string) ($b['published_at'] ?? ''), (string) ($a['published_at'] ?? ''));
        });
        set_transient(self::CACHE_KEY, $list, 10 * MINUTE_IN_SECONDS);
        self::note($list ? '' : 'GitHub has no published release there yet.');
        return $list;
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
        $desktop = $has ? self::release(self::DESKTOP, true) : null;
        $android = $has ? self::release(self::ANDROID) : null;
        $err = (string) get_option('stationwagon_updates_last_error', '');
        echo '<div class="wrap"><h1>Stationwagon Updates</h1>';
        echo '<p>Stationwagon Studio for Windows and for Android check here for new versions, and the studio’s “Get the Windows app” and “Get the Android app” links download from here. This server asks the private GitHub repository with the token below; the token never leaves this server.</p>';
        echo '<form method="post" action="options.php">';
        settings_fields('stationwagon_updates');
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="sw-token">GitHub token</label></th><td><input type="password" id="sw-token" name="' . esc_attr(self::OPTION_TOKEN) . '" value="" class="regular-text" autocomplete="off" placeholder="' . esc_attr($has ? 'A token is saved (leave empty to keep it)' : 'github_pat_…') . '">';
        echo '<p class="description">A fine-grained personal access token for the Stationwagon repository only, with <b>Contents: Read-only</b>. Make one at github.com → Settings → Developer settings → Fine-grained tokens. Type <code>-</code> to remove the saved token.</p></td></tr>';
        echo '<tr><th scope="row"><label for="sw-repo">Repository</label></th><td><input type="text" id="sw-repo" name="' . esc_attr(self::OPTION_REPO) . '" value="' . esc_attr(self::repo()) . '" class="regular-text"></td></tr>';
        echo '</table>';
        submit_button('Save');
        echo '</form><h2>Windows</h2><p>';
        if ($desktop) {
            $sig = self::asset($desktop, self::DESKTOP . '.sig');
            echo 'Newest release: <b>' . esc_html((string) $desktop['tag_name']) . '</b>, published ' . esc_html((string) ($desktop['published_at'] ?? '')) . '.<br>';
            echo 'Installer: ' . esc_html(self::asset($desktop, self::DESKTOP)['name']) . ' · Signature: ' . ($sig ? esc_html($sig['name']) : '<b>missing</b>') . '<br>';
            echo 'The app checks: <code>' . esc_html(rest_url(self::NS . '/desktop/latest-release')) . '</code><br>';
            echo 'Download link: <a href="' . esc_url(rest_url(self::NS . '/desktop/installer')) . '">' . esc_html(rest_url(self::NS . '/desktop/installer')) . '</a>';
        } else {
            echo esc_html($err !== '' ? $err : 'No Windows release found yet.');
        }
        echo '</p><h2>Android</h2><p>';
        if ($android) {
            echo 'Newest release: <b>' . esc_html((string) $android['tag_name']) . '</b>, published ' . esc_html((string) ($android['published_at'] ?? '')) . '.<br>';
            echo 'App: ' . esc_html(self::asset($android, self::ANDROID)['name']) . '<br>';
            echo 'The app checks: <code>' . esc_html(rest_url(self::NS . '/android/latest-release')) . '</code><br>';
            echo 'Download link: <a href="' . esc_url(rest_url(self::NS . '/android/apk')) . '">' . esc_html(rest_url(self::NS . '/android/apk')) . '</a>';
        } else {
            echo esc_html($err !== '' ? $err : 'No Android release found yet.');
        }
        echo '</p></div>';
    }

    /** The release's description for the update dialog: Windows line ends made plain, at most NOTES_MAX characters. */
    private static function notes(array $release)
    {
        $body = trim(str_replace("\r\n", "\n", (string) ($release['body'] ?? '')));
        if (strlen($body) <= self::NOTES_MAX) {
            return $body;
        }
        $cut = function_exists('mb_strcut') ? mb_strcut($body, 0, self::NOTES_MAX, 'UTF-8') : substr($body, 0, self::NOTES_MAX);
        return rtrim($cut);
    }
}

Stationwagon_Updates::boot();
