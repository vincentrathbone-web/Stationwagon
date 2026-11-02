<?php
/**
 * Stationwagon → People: the station's People and Roles screens inside WordPress. The station keeps
 * its own accounts (it can also run without WordPress), so these are not WordPress users: this page
 * loads the station's code and changes its accounts directly. A WordPress administrator already
 * controls the station's files, so here they act as one of the station's admins.
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

    /** The station's Staff and Roles, and who is acting: this WordPress administrator, as a station admin. */
    private static function station(): array
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
        $actor = ['id' => 0, 'role' => 'admin', 'name' => $wp->display_name, 'email' => $wp->user_email, 'blocked_at' => null];
        return [new \Stationwagon\Staff($db, $station), new \Stationwagon\Roles($db, $station), $actor, $db, $station];
    }

    public static function post(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Only an administrator can change the station’s people.', 403);
        }
        check_admin_referer(self::ACTION);
        $id = absint($_POST['id'] ?? 0);
        $key = sanitize_key(wp_unslash($_POST['key'] ?? ''));
        $action = sanitize_key(wp_unslash($_POST['sw_action'] ?? ''));
        $text = fn(string $k) => sanitize_text_field(wp_unslash((string) ($_POST[$k] ?? '')));
        $password = (string) wp_unslash($_POST['password'] ?? ''); // never sanitised: it's hashed as typed
        $ticks = array_map('sanitize_key', (array) wp_unslash($_POST['perm'] ?? []));
        $query = '';
        if ($id && !in_array($action, ['remove', 'add'], true)) {
            $query = '&person=' . $id;
        } elseif ($key !== '' && $action === 'role_save') {
            $query = '&role=' . rawurlencode($key) . '#roles';
        } elseif (str_starts_with($action, 'role_')) {
            $query = '#roles';
        }
        try {
            [$staff, $roles, $actor, $db, $station] = self::station();
            if ($action === 'add') {
                [, $errors] = $staff->add($actor, $text('name'), $text('email'), $text('role') ?: 'dj', $password);
                $problem = $errors ? implode(' ', $errors) : null;
                if ($errors) {
                    self::flash(['form' => ['name' => $text('name'), 'email' => $text('email'), 'role' => $text('role')]]);
                }
            } else {
                $problem = match ($action) {
                    'details' => implode(' ', $staff->edit($actor, $id, $text('name'), $text('email'))) ?: null,
                    'role' => $staff->setRole($actor, $id, $text('role')),
                    'password' => $staff->resetPassword($actor, $id, $password, \Stationwagon\Auth::fromConfig()),
                    'block' => $staff->block($actor, $id, new \Stationwagon\Planner($db, $station, SW_ROOT)),
                    'unblock' => $staff->unblock($actor, $id),
                    'remove' => $staff->remove($actor, $id),
                    'role_add' => $roles->add($actor, $text('name'), $ticks)[1],
                    'role_save' => $roles->update($actor, $key, $text('name'), $ticks),
                    'role_delete' => $roles->delete($actor, $key),
                    default => 'Unknown action.',
                };
            }
            self::flash($problem !== null ? ['error' => $problem] : ['ok' => [
                'add' => 'Added. Give them the email and password you chose; they can change the password on Your account in the station.',
                'details' => 'Name and email saved.',
                'role' => 'Role changed. They may now do what that role allows.',
                'password' => 'New password set. Their other sessions are signed out.',
                'block' => 'Blocked. They are signed out everywhere, and if they were live the AutoDJ has taken over.',
                'unblock' => 'Unblocked. They can sign in again.',
                'remove' => 'Removed. They can no longer sign in.',
                'role_add' => 'Role added.',
                'role_save' => 'Saved. Everyone with this role may now do what it allows.',
                'role_delete' => 'Role deleted.',
            ][$action] ?? 'Done.']);
        } catch (Throwable $ex) {
            self::flash(['error' => $ex->getMessage()]);
        }
        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . $query));
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
            [$staff, $rolesObj] = self::station();
            $people = $staff->all();
            $roles = $rolesObj->all();
        } catch (Throwable $ex) {
            printf('<div class="notice notice-error"><p>The station can’t be reached: %s</p></div></div>', esc_html($ex->getMessage()));
            return;
        }
        $s = Stationwagon_Setup::station();
        $names = array_column($roles, 'name', 'key');
        echo '<p class="sw-lead">Everyone who can sign in to your station. These are the station’s own accounts, not WordPress users: the station can also run without WordPress. Each person may do what their role allows (Roles, below); everyone can go live in their own scheduled blocks, keep their running order and edit their own podcast episodes.</p>';
        printf('<p class="description">The same lists are in the station’s manager, under <a href="%s" target="_blank" rel="noopener">People</a> and Roles. Passwords are never shown: whoever sets one passes it on.</p>', esc_url($s['url'] . 'manage/people'));

        $open = absint($_GET['person'] ?? 0);
        $tz = wp_timezone();
        $when = fn(?string $utc) => $utc ? wp_date('j M, H:i', (int) strtotime($utc . ' UTC'), $tz) : 'Never';
        echo '<table class="widefat striped sw-ptable"><thead><tr><th>Name</th><th>Role</th><th>May do</th><th>Last signed in</th><th></th></tr></thead><tbody>';
        foreach ($people as $p) {
            $pid = (int) $p['id'];
            $blocked = !empty($p['blocked_at']);
            printf('<tr class="%s" data-person="%d"><td><strong>%s</strong>%s<br><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td class="sw-acts"><a class="button" href="%s">%s</a></td></tr>',
                $blocked ? 'sw-blocked' : '', $pid, esc_html($p['name']), $blocked ? ' <span class="sw-tag sw-tag-bad">Blocked</span>' : '', esc_html($p['email']),
                esc_html($names[$p['role']] ?? $p['role']), esc_html(\Stationwagon\Permissions::summary(\Stationwagon\Permissions::of($p))), esc_html($when($p['last_login_at'])),
                esc_url(admin_url('admin.php?page=' . self::SLUG . ($open === $pid ? '' : '&person=' . $pid))), $open === $pid ? 'Close' : 'Change');
            if ($open === $pid) {
                echo '<tr class="sw-editing"><td colspan="5">';
                self::personForms($p, $blocked, $when, $names);
                echo '</td></tr>';
            }
        }
        echo '</tbody></table>';

        $in = ($flash['form'] ?? []) + ['name' => '', 'email' => '', 'role' => 'dj'];
        echo '<div class="sw-card sw-addperson"><h2>Add someone</h2>';
        self::open('add');
        printf('<p class="sw-field"><label for="sw-pname">Name</label><input id="sw-pname" name="name" value="%s" class="regular-text" required></p>', esc_attr($in['name']));
        printf('<p class="sw-field"><label for="sw-pemail">Email (their sign-in)</label><input id="sw-pemail" type="email" name="email" value="%s" class="regular-text" required></p>', esc_attr($in['email']));
        printf('<p class="sw-field"><label for="sw-prole">Role</label><select id="sw-prole" name="role">%s</select></p>', self::roleOptions($names, $in['role']));
        echo '<p class="sw-field"><label for="sw-ppass">First password (at least 10 characters)</label><input id="sw-ppass" type="password" name="password" class="regular-text" autocomplete="new-password" minlength="10" required></p>';
        echo '<p><button class="button button-primary">Add</button></p></form></div>';

        self::rolesSection($roles, sanitize_key(wp_unslash($_GET['role'] ?? '')));
        echo '</div>';
    }

    private static function rolesSection(array $roles, string $open): void
    {
        echo '<h2 id="roles" class="sw-h2">Roles</h2><p class="description">What each role may do. A change applies to everyone with the role at once. Admin always has everything; Admin, Manager and DJ can’t be deleted.</p>';
        echo '<table class="widefat striped sw-ptable sw-roles"><thead><tr><th>Role</th><th>May do</th><th>People</th><th></th></tr></thead><tbody>';
        foreach ($roles as $r) {
            $k = $r['key'];
            $isOpen = $open === $k && $k !== 'admin';
            printf('<tr data-role="%s"><td><strong>%s</strong>%s</td><td>%s</td><td>%d</td><td class="sw-acts">%s</td></tr>', esc_attr($k), esc_html($r['name']),
                $r['built_in'] ? '' : ' <span class="sw-tag">Your own</span>', esc_html(\Stationwagon\Permissions::summary($r['permissions'])), $r['people'],
                $k === 'admin' ? '' : sprintf('<a class="button" href="%s">%s</a>', esc_url(admin_url('admin.php?page=' . self::SLUG . ($isOpen ? '' : '&role=' . rawurlencode($k)) . '#roles')), $isOpen ? 'Close' : 'Change'));
            if ($isOpen) {
                echo '<tr class="sw-editing"><td colspan="4"><div class="sw-personform">';
                self::open('role_save', 0, $k);
                printf('<label>Name <input name="name" value="%s" class="regular-text" maxlength="60" required></label>', esc_attr($r['name']));
                self::ticks($r['permissions']);
                echo '<p><button class="button">Save</button></p></form>';
                if (!$r['built_in']) {
                    self::open('role_delete', 0, $k);
                    if ($r['people'] > 0) {
                        printf('<span class="description">To delete this role, give its %d %s another role first.</span></form>', $r['people'], $r['people'] === 1 ? 'person' : 'people');
                    } else {
                        printf('<label><input type="checkbox" required> Yes, delete %s</label> <button class="button sw-danger">Delete</button></form>', esc_html($r['name']));
                    }
                }
                echo '</div></td></tr>';
            }
        }
        echo '</tbody></table>';
        echo '<div class="sw-card sw-addrole"><h2>Add a role</h2>';
        self::open('role_add');
        echo '<p class="sw-field"><label for="sw-rname">Name</label><input id="sw-rname" name="name" class="regular-text" maxlength="60" placeholder="For example: Guest presenter" required></p>';
        self::ticks([]);
        echo '<p><button class="button button-primary">Add role</button></p></form></div>';
    }

    private static function personForms(array $p, bool $blocked, callable $when, array $names): void
    {
        $pid = (int) $p['id'];
        echo '<div class="sw-personform">';
        self::open('details', $pid);
        printf('<label>Name <input name="name" value="%s" class="regular-text" required></label> <label>Email <input type="email" name="email" value="%s" class="regular-text" required></label> <button class="button">Save name and email</button></form>',
            esc_attr($p['name']), esc_attr($p['email']));
        self::open('role', $pid);
        printf('<label>Role <select name="role">%s</select></label> <button class="button">Change role</button></form>', self::roleOptions($names, $p['role']));
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

    private static function roleOptions(array $names, string $selected): string
    {
        $out = '';
        foreach ($names as $k => $name) {
            $out .= sprintf('<option value="%s"%s>%s</option>', esc_attr($k), selected($selected, $k, false), esc_html($name));
        }
        return $out;
    }

    private static function open(string $action, int $id = 0, string $key = ''): void
    {
        printf('<form method="post" action="%s" class="sw-pform" autocomplete="off"><input type="hidden" name="action" value="%s"><input type="hidden" name="sw_action" value="%s">%s%s',
            esc_url(admin_url('admin-post.php')), esc_attr(self::ACTION), esc_attr($action),
            $id ? '<input type="hidden" name="id" value="' . $id . '">' : '', $key !== '' ? '<input type="hidden" name="key" value="' . esc_attr($key) . '">' : '');
        wp_nonce_field(self::ACTION);
    }

    private static function ticks(array $have): void
    {
        echo '<div class="sw-perms">';
        foreach (\Stationwagon\Permissions::ALL as $k => [$label, $what]) {
            printf('<label class="sw-perm"><input type="checkbox" name="perm[]" value="%s"%s> <span><b>%s</b><small>%s</small></span></label>',
                esc_attr($k), checked(in_array($k, $have, true), true, false), esc_html($label), esc_html($what));
        }
        echo '</div>';
    }
}
