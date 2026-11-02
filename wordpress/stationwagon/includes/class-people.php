<?php
/**
 * Stationwagon → People: the station's People screen inside WordPress. The station keeps its own
 * accounts (it can also run without WordPress), so these are not WordPress users: this page loads
 * the station's code and changes its accounts directly. A WordPress administrator already controls
 * the station's files, so here they act as one of the station's admins.
 */

defined('ABSPATH') || exit;

final class Stationwagon_People
{
    const SLUG = 'stationwagon-people';
    const ACTION = 'stationwagon_people';

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 11);
        add_action('admin_post_' . self::ACTION, [self::class, 'post']);
    }

    public static function menu(): void
    {
        if (Stationwagon_Setup::station()) {
            add_submenu_page(Stationwagon_Admin::SLUG, 'Stationwagon · People', 'People', 'manage_options', self::SLUG, [self::class, 'page']);
        }
    }

    /** The station's Staff, and who is acting: this WordPress administrator, as a station admin. */
    private static function staff(): array
    {
        $s = Stationwagon_Setup::station();
        if (!$s) {
            throw new RuntimeException('Set up the station first.');
        }
        Stationwagon_Setup::loadApp($s['dir']);
        $db = \Stationwagon\Db::fromConfig();
        \Stationwagon\Schema::ensure($db, SW_ROOT);
        $station = $db->run('SELECT * FROM ' . $db->table('stations') . ' ORDER BY id LIMIT 1')->fetch();
        if (!$station) {
            throw new RuntimeException('The station has no data yet.');
        }
        $wp = wp_get_current_user();
        $actor = ['id' => 0, 'role' => 'admin', 'name' => $wp->display_name, 'email' => $wp->user_email, 'permissions' => null, 'blocked_at' => null];
        return [new \Stationwagon\Staff($db, $station), $actor, $db, $station];
    }

    public static function post(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Only an administrator can change the station’s people.', 403);
        }
        check_admin_referer(self::ACTION);
        $id = absint($_POST['id'] ?? 0);
        $action = sanitize_key(wp_unslash($_POST['sw_action'] ?? ''));
        $text = fn(string $k) => sanitize_text_field(wp_unslash((string) ($_POST[$k] ?? '')));
        $password = (string) wp_unslash($_POST['password'] ?? ''); // never sanitised: it's hashed as typed
        $ticks = isset($_POST['perms']) ? array_map('sanitize_key', (array) wp_unslash($_POST['perm'] ?? [])) : null;
        $back = admin_url('admin.php?page=' . self::SLUG . ($id && $action !== 'remove' && $action !== 'add' ? '&person=' . $id : ''));
        try {
            [$staff, $actor, $db, $station] = self::staff();
            if ($action === 'add') {
                [, $errors] = $staff->add($actor, $text('name'), $text('email'), $text('role') ?: 'dj', $password, $ticks);
                $problem = $errors ? implode(' ', $errors) : null;
                if ($errors) {
                    self::flash(['form' => ['name' => $text('name'), 'email' => $text('email'), 'role' => $text('role'), 'perm' => $ticks]]);
                }
            } else {
                $problem = match ($action) {
                    'details' => implode(' ', $staff->edit($actor, $id, $text('name'), $text('email'))) ?: null,
                    'role' => $staff->setRole($actor, $id, $text('role')),
                    'permissions' => $staff->setPermissions($actor, $id, $ticks ?? []),
                    'password' => $staff->resetPassword($actor, $id, $password, \Stationwagon\Auth::fromConfig()),
                    'block' => $staff->block($actor, $id, new \Stationwagon\Planner($db, $station, SW_ROOT)),
                    'unblock' => $staff->unblock($actor, $id),
                    'remove' => $staff->remove($actor, $id),
                    default => 'Unknown action.',
                };
            }
            self::flash($problem !== null ? ['error' => $problem] : ['ok' => [
                'add' => 'Added. Give them the email and password you chose; they can change the password on Your account in the station.',
                'details' => 'Name and email saved.',
                'role' => 'Role changed. What they may do is back to that role’s usual.',
                'permissions' => 'Saved what they may do.',
                'password' => 'New password set. Their other sessions are signed out.',
                'block' => 'Blocked. They are signed out everywhere, and if they were live the AutoDJ has taken over.',
                'unblock' => 'Unblocked. They can sign in again.',
                'remove' => 'Removed. They can no longer sign in.',
            ][$action] ?? 'Done.']);
        } catch (Throwable $ex) {
            self::flash(['error' => $ex->getMessage()]);
        }
        wp_safe_redirect($back);
        exit;
    }

    private static function flash(?array $set = null): array
    {
        $key = 'stationwagon_people_' . get_current_user_id();
        if ($set !== null) {
            set_transient($key, $set + (get_transient($key) ?: []), 300);
            return $set;
        }
        $v = get_transient($key) ?: [];
        delete_transient($key);
        return $v;
    }

    public static function page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $flash = self::flash();
        echo '<div class="wrap sw sw-people">';
        echo '<h1 class="sw-title"><span class="dashicons dashicons-groups"></span> People</h1>';
        if (!empty($flash['ok'])) {
            printf('<div class="notice notice-success"><p>%s</p></div>', esc_html($flash['ok']));
        }
        if (!empty($flash['error'])) {
            printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($flash['error']));
        }
        try {
            [$staff] = self::staff();
            $people = $staff->all();
        } catch (Throwable $ex) {
            printf('<div class="notice notice-error"><p>The station can’t be reached: %s</p></div></div>', esc_html($ex->getMessage()));
            return;
        }
        $s = Stationwagon_Setup::station();
        echo '<p class="sw-lead">Everyone who can sign in to your station. These are the station’s own accounts, not WordPress users: the station can also run without WordPress. Every DJ can go live in their own block, keep their running order and edit their own podcast episodes; the ticks add what else each person may do.</p>';
        printf('<p class="description">The same list is in the station’s manager, under <a href="%s" target="_blank" rel="noopener">People</a>. Passwords are never shown: whoever sets one passes it on.</p>', esc_url($s['url'] . 'manage/people'));

        $open = absint($_GET['person'] ?? 0);
        $tz = wp_timezone();
        $when = fn(?string $utc) => $utc ? wp_date('j M, H:i', (int) strtotime($utc . ' UTC'), $tz) : 'Never';
        $roles = \Stationwagon\Staff::ROLE_NAMES;
        echo '<table class="widefat striped sw-ptable"><thead><tr><th>Name</th><th>Role</th><th>May do</th><th>Last signed in</th><th></th></tr></thead><tbody>';
        foreach ($people as $p) {
            $pid = (int) $p['id'];
            $have = \Stationwagon\Permissions::of($p);
            $canDo = count($have) === count(\Stationwagon\Permissions::ALL) ? 'Everything'
                : ($have ? implode(', ', array_map(fn($k) => \Stationwagon\Permissions::ALL[$k][0], $have)) : 'Their own shows');
            $blocked = !empty($p['blocked_at']);
            printf('<tr class="%s" data-person="%d"><td><strong>%s</strong>%s<br><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td class="sw-acts"><a class="button" href="%s">%s</a></td></tr>',
                $blocked ? 'sw-blocked' : '', $pid, esc_html($p['name']), $blocked ? ' <span class="sw-tag sw-tag-bad">Blocked</span>' : '', esc_html($p['email']),
                esc_html($roles[$p['role']] ?? $p['role']), esc_html($canDo), esc_html($when($p['last_login_at'])),
                esc_url(admin_url('admin.php?page=' . self::SLUG . ($open === $pid ? '' : '&person=' . $pid))), $open === $pid ? 'Close' : 'Change');
            if ($open === $pid) {
                echo '<tr class="sw-editing"><td colspan="5">';
                self::personForms($p, $blocked, $when);
                echo '</td></tr>';
            }
        }
        echo '</tbody></table>';

        $in = ($flash['form'] ?? []) + ['name' => '', 'email' => '', 'role' => 'dj', 'perm' => null];
        echo '<div class="sw-card sw-addperson"><h2>Add someone</h2>';
        self::open('add');
        printf('<p class="sw-field"><label for="sw-pname">Name</label><input id="sw-pname" name="name" value="%s" class="regular-text" required></p>', esc_attr($in['name']));
        printf('<p class="sw-field"><label for="sw-pemail">Email (their sign-in)</label><input id="sw-pemail" type="email" name="email" value="%s" class="regular-text" required></p>', esc_attr($in['email']));
        echo '<p class="sw-field"><label for="sw-prole">Role</label><select id="sw-prole" name="role">';
        foreach ($roles as $r => $name) {
            printf('<option value="%s"%s>%s</option>', esc_attr($r), selected($in['role'], $r, false), esc_html($name));
        }
        echo '</select></p>';
        echo '<p class="sw-field"><label for="sw-ppass">First password (at least 10 characters)</label><input id="sw-ppass" type="password" name="password" class="regular-text" autocomplete="new-password" minlength="10" required></p>';
        echo '<p><b>What they may do</b></p>';
        self::ticks($in['perm'] ?? \Stationwagon\Permissions::defaults($in['role']), $in['role'] === 'admin');
        echo '<p><button class="button button-primary">Add</button></p></form></div>';
        printf('<script>window.swUsual = %s;</script>', wp_json_encode(array_combine(array_keys($roles), array_map([\Stationwagon\Permissions::class, 'defaults'], array_keys($roles)))));
        echo '</div>';
    }

    private static function personForms(array $p, bool $blocked, callable $when): void
    {
        $pid = (int) $p['id'];
        echo '<div class="sw-personform">';
        self::open('details', $pid);
        printf('<label>Name <input name="name" value="%s" class="regular-text" required></label> <label>Email <input type="email" name="email" value="%s" class="regular-text" required></label> <button class="button">Save name and email</button></form>',
            esc_attr($p['name']), esc_attr($p['email']));
        self::open('role', $pid);
        echo '<label>Role <select name="role">';
        foreach (\Stationwagon\Staff::ROLE_NAMES as $r => $name) {
            printf('<option value="%s"%s>%s</option>', esc_attr($r), selected($p['role'], $r, false), esc_html($name));
        }
        echo '</select></label> <button class="button">Change role</button></form>';
        self::open('permissions', $pid);
        printf('<p><b>What %s may do</b></p>', esc_html($p['name']));
        self::ticks(\Stationwagon\Permissions::of($p), $p['role'] === 'admin');
        echo $p['role'] === 'admin' ? '<p class="description">Admins may always do everything.</p></form>' : '<p><button class="button">Save what they may do</button></p></form>';
        self::open('password', $pid);
        echo '<label>New password <input type="password" name="password" class="regular-text" autocomplete="new-password" minlength="10" required></label> <button class="button">Set password</button> <span class="description">Signs them out elsewhere.</span></form>';
        if ($blocked) {
            self::open('unblock', $pid);
            printf('<span class="description">Blocked since %s. They can’t sign in.</span> <button class="button">Unblock</button></form>', esc_html($when($p['blocked_at'])));
        } else {
            self::open('block', $pid);
            printf('<label><input type="checkbox" required> Yes, block %s: sign them out everywhere and end their live show if they’re on air</label> <button class="button sw-danger">Block</button></form>', esc_html($p['name']));
        }
        self::open('remove', $pid);
        printf('<label><input type="checkbox" required> Yes, remove %s (their past shows stay)</label> <button class="button sw-danger">Remove</button></form>', esc_html($p['name']));
        echo '</div>';
    }

    private static function open(string $action, int $id = 0): void
    {
        printf('<form method="post" action="%s" class="sw-pform" autocomplete="off"><input type="hidden" name="action" value="%s"><input type="hidden" name="sw_action" value="%s">%s',
            esc_url(admin_url('admin-post.php')), esc_attr(self::ACTION), esc_attr($action), $id ? '<input type="hidden" name="id" value="' . $id . '">' : '');
        wp_nonce_field(self::ACTION);
    }

    private static function ticks(array $have, bool $locked): void
    {
        echo '<input type="hidden" name="perms" value="1"><div class="sw-perms">';
        foreach (\Stationwagon\Permissions::ALL as $k => [$label, $what]) {
            printf('<label class="sw-perm"><input type="checkbox" name="perm[]" value="%s"%s%s> <span><b>%s</b><small>%s</small></span></label>',
                esc_attr($k), checked(in_array($k, $have, true), true, false), disabled($locked, true, false), esc_html($label), esc_html($what));
        }
        echo '</div>';
    }
}
