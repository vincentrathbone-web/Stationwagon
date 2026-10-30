<?php
declare(strict_types=1);

namespace Stationwagon;

/**
 * The database tables, version 6. Plain MySQL 8 / MariaDB 10.5+ DDL: InnoDB, utf8mb4, no JSON
 * column type (JSON lives in MEDIUMTEXT so both servers behave the same), no generated columns.
 *
 * Times: DATETIME columns are UTC wall-clock times (created_at and the like). Stream times
 * (when a song or live block plays) are unix seconds with milliseconds, DECIMAL(15,3), because
 * the timeline needs sub-second precision and does arithmetic on them.
 *
 * create() always makes the newest tables; an older install is brought up to date by upgrade(),
 * step by step (migrations()). The installed version is in settings.schema_version.
 *   v1  the first twelve tables
 *   v2  running_orders (the studio's show plans)
 *   v3  tracks.outro (the last minute's loudness, for silence detection) and
 *       live_shows.continues_show_id (a show a DJ rejoined after a drop)
 *   v4  listeners (who is listening now, by a random id per Listen) and listener_hours (stats)
 *   v5  listeners keeps each Listen for 30 days with its address, browser details, device, app
 *       and place; listener_tally (daily totals by those) and listener_visitors (daily unique marks)
 *   v7  presenter_notes.replaced_by: an edited note is posted again as a new row; the old one stays
 *       for listeners until they reach the edit
 *   v6  podcasts: episodes (one per live show, with its continuations), episode_takes (each show's
 *       recording: from the studio's device, or as aired) and episode_chunks (the device's pieces)
 *   v8  tracks.mastered; v9 remember_tokens; v10 prep_presets and tracks.preset (the Song prep screen)
 *   v11 a song's loudness normalization: tracks.source_lufs, level_gain_db, level_target (the station's
 *       loudness target it was levelled to) and preset_settings (the mastering it was made with, so
 *       re-levelling for a new target keeps its sound)
 */
final class Schema
{
    public const VERSION = 11;

    /** Table names without prefix, in creation order (foreign keys need their parents first). */
    public const TABLES = [
        'settings', 'stations', 'users', 'station_users', 'tracks', 'playlists', 'playlist_tracks',
        'schedule', 'live_shows', 'live_pieces', 'timeline', 'presenter_notes', 'running_orders',
        'listeners', 'listener_hours', 'listener_tally', 'listener_visitors',
        'episodes', 'episode_takes', 'episode_chunks', 'remember_tokens', 'prep_presets',
    ];

    /** @return array<string,string> table name => CREATE TABLE statement */
    public static function create(string $p): array
    {
        $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $fk = fn(string $name, string $col, string $parent, string $onDelete = 'CASCADE') =>
            "CONSTRAINT `{$p}fk_{$name}` FOREIGN KEY (`$col`) REFERENCES `{$p}{$parent}` (`id`) ON DELETE $onDelete";

        return [
            // Key/value settings for the whole install (schema_version, defaults)
            'settings' => "CREATE TABLE `{$p}settings` (
                `name` VARCHAR(64) NOT NULL,
                `value` MEDIUMTEXT NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`name`)
            ) $opts",

            // One row per station. v0.1 has one; the tables allow more later
            'stations' => "CREATE TABLE `{$p}stations` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `slug` VARCHAR(64) NOT NULL,
                `name` VARCHAR(190) NOT NULL,
                `timezone` VARCHAR(64) NOT NULL DEFAULT 'Africa/Johannesburg',
                `settings` MEDIUMTEXT NULL COMMENT 'JSON: crossfade, piece length, live timeout…',
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `{$p}stations_slug` (`slug`)
            ) $opts",

            // People who sign in: admins, station managers and DJs/presenters
            'users' => "CREATE TABLE `{$p}users` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `email` VARCHAR(190) NOT NULL,
                `name` VARCHAR(190) NOT NULL,
                `password_hash` VARCHAR(255) NOT NULL,
                `role` ENUM('admin','manager','dj') NOT NULL DEFAULT 'dj',
                `created_at` DATETIME NOT NULL,
                `last_login_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `{$p}users_email` (`email`)
            ) $opts",

            // Which users work on which station
            'station_users' => "CREATE TABLE `{$p}station_users` (
                `station_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`station_id`, `user_id`),
                {$fk('su_station', 'station_id', 'stations')},
                {$fk('su_user', 'user_id', 'users')}
            ) $opts",

            // The music library. pieces = JSON [[byte offset, byte length, seconds], ...]: the
            // ~6 s cut points on MP3 frame boundaries that the HLS stream serves as byte ranges
            'tracks' => "CREATE TABLE `{$p}tracks` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `station_id` INT UNSIGNED NOT NULL,
                `file` VARCHAR(255) NOT NULL COMMENT 'path under storage/media',
                `sha1` CHAR(40) NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `artist` VARCHAR(255) NULL,
                `album` VARCHAR(255) NULL,
                `duration_s` DECIMAL(10,3) NOT NULL,
                `bitrate_kbps` SMALLINT UNSIGNED NOT NULL,
                `sample_rate` MEDIUMINT UNSIGNED NOT NULL,
                `bytes` INT UNSIGNED NOT NULL,
                `pieces` MEDIUMTEXT NOT NULL,
                `loudness_lufs` DECIMAL(5,2) NULL,
                `mastered` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'song prep mastered it like the station''s Audition chain',
                `preset` VARCHAR(60) NULL COMMENT 'the name of the mastering preset song prep used',
                `source_lufs` DECIMAL(5,2) NULL COMMENT 'its loudness before levelling (after mastering)',
                `level_gain_db` DECIMAL(5,2) NULL COMMENT 'the gain levelling applied',
                `level_target` DECIMAL(4,1) NULL COMMENT 'the station loudness target it was levelled to (LUFS)',
                `preset_settings` TEXT NULL COMMENT 'JSON: the mastering settings it was made with',
                `outro` MEDIUMTEXT NULL COMMENT 'JSON: loudness (LUFS) of each second of the last minute, from song prep',
                `status` ENUM('processing','ready','error') NOT NULL DEFAULT 'ready',
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `{$p}tracks_station_sha1` (`station_id`, `sha1`),
                KEY `{$p}tracks_station_status` (`station_id`, `status`),
                {$fk('tracks_station', 'station_id', 'stations')}
            ) $opts",

            'playlists' => "CREATE TABLE `{$p}playlists` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `station_id` INT UNSIGNED NOT NULL,
                `name` VARCHAR(190) NOT NULL,
                `mode` ENUM('shuffle','in_order') NOT NULL DEFAULT 'shuffle',
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `{$p}playlists_station` (`station_id`),
                {$fk('playlists_station', 'station_id', 'stations')}
            ) $opts",

            'playlist_tracks' => "CREATE TABLE `{$p}playlist_tracks` (
                `playlist_id` INT UNSIGNED NOT NULL,
                `track_id` INT UNSIGNED NOT NULL,
                `position` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`playlist_id`, `track_id`),
                KEY `{$p}pt_order` (`playlist_id`, `position`),
                {$fk('pt_playlist', 'playlist_id', 'playlists')},
                {$fk('pt_track', 'track_id', 'tracks')}
            ) $opts",

            // Weekly blocks: a playlist (AutoDJ) or a live show. days is a bitmask, Monday = 1
            // ... Sunday = 64; times are in the station's timezone
            'schedule' => "CREATE TABLE `{$p}schedule` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `station_id` INT UNSIGNED NOT NULL,
                `kind` ENUM('playlist','live') NOT NULL,
                `title` VARCHAR(190) NOT NULL,
                `playlist_id` INT UNSIGNED NULL,
                `dj_user_id` INT UNSIGNED NULL,
                `days` TINYINT UNSIGNED NOT NULL DEFAULT 127,
                `start_time` TIME NOT NULL,
                `end_time` TIME NOT NULL,
                `valid_from` DATE NULL,
                `valid_to` DATE NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `{$p}schedule_station` (`station_id`),
                {$fk('schedule_station', 'station_id', 'stations')},
                {$fk('schedule_playlist', 'playlist_id', 'playlists', 'SET NULL')},
                {$fk('schedule_dj', 'dj_user_id', 'users', 'SET NULL')}
            ) $opts",

            // A live block on the timeline; open until the DJ ends it or it times out
            'live_shows' => "CREATE TABLE `{$p}live_shows` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `station_id` INT UNSIGNED NOT NULL,
                `public_id` VARCHAR(40) NOT NULL,
                `dj_user_id` INT UNSIGNED NULL,
                `title` VARCHAR(190) NOT NULL,
                `start_at` DECIMAL(15,3) NOT NULL,
                `open` TINYINT(1) NOT NULL DEFAULT 1,
                `last_piece_at` DECIMAL(15,3) NOT NULL,
                `closed_reason` VARCHAR(64) NULL,
                `continues_show_id` INT UNSIGNED NULL COMMENT 'the show this one carries on after a drop',
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `{$p}live_public` (`public_id`),
                KEY `{$p}live_station_open` (`station_id`, `open`),
                {$fk('live_station', 'station_id', 'stations')},
                {$fk('live_dj', 'dj_user_id', 'users', 'SET NULL')}
            ) $opts",

            // The ~6 s MP3 pieces a live show uploads, in order
            'live_pieces' => "CREATE TABLE `{$p}live_pieces` (
                `show_id` INT UNSIGNED NOT NULL,
                `seq` INT UNSIGNED NOT NULL,
                `file` VARCHAR(255) NOT NULL COMMENT 'path under storage/live',
                `duration_s` DECIMAL(8,4) NOT NULL,
                `received_at` DECIMAL(15,3) NOT NULL,
                PRIMARY KEY (`show_id`, `seq`),
                CONSTRAINT `{$p}fk_pieces_show` FOREIGN KEY (`show_id`) REFERENCES `{$p}live_shows` (`id`) ON DELETE CASCADE
            ) $opts",

            // What plays when. n = entry counter (HLS discontinuity sequence); seq = HLS media
            // sequence of the entry's first piece; both continuous across entries
            'timeline' => "CREATE TABLE `{$p}timeline` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `station_id` INT UNSIGNED NOT NULL,
                `n` BIGINT UNSIGNED NOT NULL,
                `type` ENUM('song','live') NOT NULL,
                `track_id` INT UNSIGNED NULL,
                `live_show_id` INT UNSIGNED NULL,
                `start_at` DECIMAL(15,3) NOT NULL,
                `play_s` DECIMAL(10,4) NOT NULL DEFAULT 0,
                `full_s` DECIMAL(10,4) NOT NULL DEFAULT 0,
                `fade_out_s` DECIMAL(6,3) NOT NULL DEFAULT 0,
                `seq` BIGINT UNSIGNED NOT NULL,
                `piece_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `cut` TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `{$p}timeline_station_n` (`station_id`, `n`),
                KEY `{$p}timeline_station_start` (`station_id`, `start_at`),
                {$fk('timeline_station', 'station_id', 'stations')},
                {$fk('timeline_track', 'track_id', 'tracks', 'SET NULL')},
                {$fk('timeline_live', 'live_show_id', 'live_shows', 'SET NULL')}
            ) $opts",

            // Verses, references, video links and notes a presenter posts for listeners.
            // body = JSON, e.g. {\"ref\":\"Psalm 23:1\",\"version\":\"KJV\",\"text\":\"...\"}
            'presenter_notes' => "CREATE TABLE `{$p}presenter_notes` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `station_id` INT UNSIGNED NOT NULL,
                `live_show_id` INT UNSIGNED NULL,
                `user_id` INT UNSIGNED NULL,
                `kind` ENUM('verse','refs','video','note') NOT NULL,
                `body` MEDIUMTEXT NOT NULL,
                `posted_at` DATETIME NOT NULL,
                `replaced_by` INT UNSIGNED NULL,
                PRIMARY KEY (`id`),
                KEY `{$p}notes_station_posted` (`station_id`, `posted_at`),
                {$fk('notes_station', 'station_id', 'stations')},
                {$fk('notes_show', 'live_show_id', 'live_shows', 'SET NULL')},
                {$fk('notes_user', 'user_id', 'users', 'SET NULL')}
            ) $opts",

            'running_orders' => self::runningOrders($p, $opts),
            'listeners' => self::listeners($p, $opts),
            'listener_hours' => self::listenerHours($p, $opts),
            'listener_tally' => self::listenerTally($p, $opts),
            'listener_visitors' => self::listenerVisitors($p, $opts),
            'episodes' => self::episodes($p, $opts),
            'episode_takes' => self::episodeTakes($p, $opts),
            'episode_chunks' => self::episodeChunks($p, $opts),
            'remember_tokens' => self::rememberTokens($p, $opts),
            'prep_presets' => self::prepPresets($p, $opts),
        ];
    }

    /** The studio's show plans: one per live block (scope "block:<id>") or per person ("user:<id>"). items = JSON list */
    private static function runningOrders(string $p, string $opts): string
    {
        return "CREATE TABLE IF NOT EXISTS `{$p}running_orders` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `station_id` INT UNSIGNED NOT NULL,
                `scope` VARCHAR(40) NOT NULL,
                `items` MEDIUMTEXT NOT NULL,
                `updated_at` DATETIME NOT NULL,
                `updated_by` INT UNSIGNED NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `{$p}order_scope` (`station_id`, `scope`),
                CONSTRAINT `{$p}fk_order_station` FOREIGN KEY (`station_id`) REFERENCES `{$p}stations` (`id`) ON DELETE CASCADE,
                CONSTRAINT `{$p}fk_order_user` FOREIGN KEY (`updated_by`) REFERENCES `{$p}users` (`id`) ON DELETE SET NULL
            ) $opts";
    }

    /**
     * One row per Listen (a random id the page makes when Listen is pressed; no cookie), kept for
     * Listeners::RAW_DAYS: when and how long, the address and browser details it came from, and
     * what they say (device, browser, app, country, city). `ended` is set by Stop; `test` marks our
     * own test runs, which count as listening now but stay out of the stats.
     */
    private static function listeners(string $p, string $opts): string
    {
        return "CREATE TABLE IF NOT EXISTS `{$p}listeners` (
                `station_id` INT UNSIGNED NOT NULL,
                `sid` CHAR(16) NOT NULL,
                `source` ENUM('player','embed') NOT NULL,
                `first_seen` DECIMAL(15,3) NOT NULL,
                `last_seen` DECIMAL(15,3) NOT NULL,
                `minutes` INT UNSIGNED NOT NULL DEFAULT 1,
                `ended` TINYINT(1) NOT NULL DEFAULT 0,
                `test` TINYINT(1) NOT NULL DEFAULT 0,
                `ip` VARCHAR(45) NOT NULL DEFAULT '',
                `user_agent` VARCHAR(400) NOT NULL DEFAULT '',
                `device` VARCHAR(20) NOT NULL DEFAULT '',
                `browser` VARCHAR(30) NOT NULL DEFAULT '',
                `app` VARCHAR(30) NOT NULL DEFAULT '',
                `country_code` CHAR(2) NOT NULL DEFAULT '',
                `country` VARCHAR(80) NOT NULL DEFAULT '',
                `city` VARCHAR(120) NOT NULL DEFAULT '',
                PRIMARY KEY (`station_id`, `sid`),
                KEY `{$p}listeners_seen` (`station_id`, `last_seen`),
                KEY `{$p}listeners_first` (`station_id`, `first_seen`),
                KEY `{$p}listeners_ip` (`station_id`, `ip`, `first_seen`),
                CONSTRAINT `{$p}fk_listeners_station` FOREIGN KEY (`station_id`) REFERENCES `{$p}stations` (`id`) ON DELETE CASCADE
            ) $opts";
    }

    /** Daily totals (the station's days) by where, device, browser, app, country and city: Listen presses and minutes. */
    private static function listenerTally(string $p, string $opts): string
    {
        return "CREATE TABLE IF NOT EXISTS `{$p}listener_tally` (
                `station_id` INT UNSIGNED NOT NULL,
                `day` DATE NOT NULL,
                `kind` ENUM('source','device','browser','app','country','city') NOT NULL,
                `value` VARCHAR(160) NOT NULL,
                `listens` INT UNSIGNED NOT NULL DEFAULT 0,
                `minutes` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`station_id`, `day`, `kind`, `value`),
                CONSTRAINT `{$p}fk_ltally_station` FOREIGN KEY (`station_id`) REFERENCES `{$p}stations` (`id`) ON DELETE CASCADE
            ) $opts";
    }

    /**
     * Unique listeners: one mark per listener per day, a hash of address and browser details with
     * a salt that changes each month (so a month's marks can be counted together, and once the
     * salt is gone they can't be traced back to anyone).
     */
    private static function listenerVisitors(string $p, string $opts): string
    {
        return "CREATE TABLE IF NOT EXISTS `{$p}listener_visitors` (
                `station_id` INT UNSIGNED NOT NULL,
                `day` DATE NOT NULL,
                `visitor` BINARY(8) NOT NULL,
                PRIMARY KEY (`station_id`, `day`, `visitor`),
                CONSTRAINT `{$p}fk_lvisitors_station` FOREIGN KEY (`station_id`) REFERENCES `{$p}stations` (`id`) ON DELETE CASCADE
            ) $opts";
    }

    /** Listening per hour (UTC hour start): the most at once, listener-minutes and Listen presses. */
    private static function listenerHours(string $p, string $opts): string
    {
        return "CREATE TABLE IF NOT EXISTS `{$p}listener_hours` (
                `station_id` INT UNSIGNED NOT NULL,
                `hour` DATETIME NOT NULL,
                `peak` INT UNSIGNED NOT NULL DEFAULT 0,
                `minutes` INT UNSIGNED NOT NULL DEFAULT 0,
                `listens` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`station_id`, `hour`),
                CONSTRAINT `{$p}fk_lhours_station` FOREIGN KEY (`station_id`) REFERENCES `{$p}stations` (`id`) ON DELETE CASCADE
            ) $opts";
    }

    /**
     * Podcast episodes: one per live show (keyed by the first show of a chain of rejoins). A draft
     * until someone publishes it; `edits` is JSON (the clips deleted in the editor); the mixdown is
     * `audio_file` under storage/podcasts (private `.chunk` until published, then a public `.mp3`).
     */
    private static function episodes(string $p, string $opts): string
    {
        return "CREATE TABLE IF NOT EXISTS `{$p}episodes` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `station_id` INT UNSIGNED NOT NULL,
                `public_id` VARCHAR(40) NOT NULL,
                `live_show_id` INT UNSIGNED NULL COMMENT 'the first show of the chain',
                `dj_user_id` INT UNSIGNED NULL,
                `title` VARCHAR(190) NOT NULL,
                `description` TEXT NULL,
                `status` ENUM('draft','published','hidden','skipped') NOT NULL DEFAULT 'draft',
                `recorded_at` DECIMAL(15,3) NOT NULL,
                `edits` MEDIUMTEXT NULL COMMENT 'JSON: deleted clip ids',
                `audio_file` VARCHAR(255) NULL,
                `audio_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `audio_seconds` DECIMAL(10,3) NOT NULL DEFAULT 0,
                `mixed_at` DATETIME NULL,
                `published_at` DATETIME NULL,
                `raw_deleted_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `{$p}episodes_public` (`public_id`),
                UNIQUE KEY `{$p}episodes_show` (`live_show_id`),
                KEY `{$p}episodes_station` (`station_id`, `status`, `recorded_at`),
                CONSTRAINT `{$p}fk_episodes_station` FOREIGN KEY (`station_id`) REFERENCES `{$p}stations` (`id`) ON DELETE CASCADE,
                CONSTRAINT `{$p}fk_episodes_show` FOREIGN KEY (`live_show_id`) REFERENCES `{$p}live_shows` (`id`) ON DELETE SET NULL,
                CONSTRAINT `{$p}fk_episodes_dj` FOREIGN KEY (`dj_user_id`) REFERENCES `{$p}users` (`id`) ON DELETE SET NULL
            ) $opts";
    }

    /**
     * One recording per live show of an episode: `device` (the studio's two tracks, music and mic,
     * uploaded after End show) or `aired` (the pieces listeners heard, one track). `analysis` is JSON
     * from the browser: per track, peaks for the waveform and the clips (split at silence).
     */
    private static function episodeTakes(string $p, string $opts): string
    {
        return "CREATE TABLE IF NOT EXISTS `{$p}episode_takes` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `episode_id` INT UNSIGNED NOT NULL,
                `live_show_id` INT UNSIGNED NOT NULL,
                `kind` ENUM('device','aired') NOT NULL,
                `start_at` DECIMAL(15,3) NOT NULL,
                `duration_s` DECIMAL(10,3) NOT NULL DEFAULT 0,
                `complete` TINYINT(1) NOT NULL DEFAULT 0,
                `analysis` MEDIUMTEXT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `{$p}takes_show_kind` (`episode_id`, `live_show_id`, `kind`),
                CONSTRAINT `{$p}fk_takes_episode` FOREIGN KEY (`episode_id`) REFERENCES `{$p}episodes` (`id`) ON DELETE CASCADE
            ) $opts";
    }

    /**
     * "Keep me signed in": one row per device. The browser holds selector:validator in its sw_remember
     * cookie; only the validator's SHA-256 is kept here. pw = the end of the password hash when it was
     * made, so a new password ends it, as it ends other sessions.
     */
    private static function rememberTokens(string $p, string $opts): string
    {
        return "CREATE TABLE IF NOT EXISTS `{$p}remember_tokens` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `selector` CHAR(24) NOT NULL,
                `validator_hash` CHAR(64) NOT NULL,
                `pw` VARCHAR(12) NOT NULL,
                `created_at` DATETIME NOT NULL,
                `expires_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `{$p}remember_selector` (`selector`),
                KEY `{$p}remember_expires` (`expires_at`),
                CONSTRAINT `{$p}fk_remember_user` FOREIGN KEY (`user_id`) REFERENCES `{$p}users` (`id`) ON DELETE CASCADE
            ) $opts";
    }

    /** Song prep's mastering presets (the Song prep screen): the chain's settings as JSON. */
    private static function prepPresets(string $p, string $opts): string
    {
        return "CREATE TABLE IF NOT EXISTS `{$p}prep_presets` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `station_id` INT UNSIGNED NOT NULL,
                `name` VARCHAR(60) NOT NULL,
                `settings` TEXT NOT NULL COMMENT 'JSON for assets/master-core.js',
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `{$p}prep_preset_name` (`station_id`, `name`),
                CONSTRAINT `{$p}fk_prep_preset_station` FOREIGN KEY (`station_id`) REFERENCES `{$p}stations` (`id`) ON DELETE CASCADE
            ) $opts";
    }

    /** A device take's pieces: 10 s of one track each, cut on whole MP3 frames. */
    private static function episodeChunks(string $p, string $opts): string
    {
        return "CREATE TABLE IF NOT EXISTS `{$p}episode_chunks` (
                `take_id` INT UNSIGNED NOT NULL,
                `track` ENUM('music','mic') NOT NULL,
                `n` INT UNSIGNED NOT NULL,
                `samples` INT UNSIGNED NOT NULL,
                `bytes` INT UNSIGNED NOT NULL,
                `file` VARCHAR(255) NOT NULL,
                PRIMARY KEY (`take_id`, `track`, `n`),
                CONSTRAINT `{$p}fk_chunks_take` FOREIGN KEY (`take_id`) REFERENCES `{$p}episode_takes` (`id`) ON DELETE CASCADE
            ) $opts";
    }

    /** @return array<int, list<string>> version => the statements that bring the version before it up to it */
    public static function migrations(string $p): array
    {
        $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return [
            2 => [self::runningOrders($p, $opts)],
            3 => [
                "ALTER TABLE `{$p}tracks` ADD COLUMN `outro` MEDIUMTEXT NULL COMMENT 'JSON: loudness (LUFS) of each second of the last minute, from song prep' AFTER `loudness_lufs`",
                "ALTER TABLE `{$p}live_shows` ADD COLUMN `continues_show_id` INT UNSIGNED NULL COMMENT 'the show this one carries on after a drop' AFTER `closed_reason`",
            ],
            4 => [self::listeners($p, $opts), self::listenerHours($p, $opts)],
            // listeners held only the open players: it is simply made again in its new shape
            5 => ["DROP TABLE IF EXISTS `{$p}listeners`", self::listeners($p, $opts), self::listenerTally($p, $opts), self::listenerVisitors($p, $opts)],
            6 => [self::episodes($p, $opts), self::episodeTakes($p, $opts), self::episodeChunks($p, $opts)],
            7 => ["ALTER TABLE `{$p}presenter_notes` ADD COLUMN `replaced_by` INT UNSIGNED NULL COMMENT 'the edited copy that replaces this note' AFTER `posted_at`"],
            8 => ["ALTER TABLE `{$p}tracks` ADD COLUMN `mastered` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'song prep mastered it like the station''s Audition chain' AFTER `loudness_lufs`"],
            9 => [self::rememberTokens($p, $opts)],
            10 => [self::prepPresets($p, $opts), "ALTER TABLE `{$p}tracks` ADD COLUMN `preset` VARCHAR(60) NULL COMMENT 'the name of the mastering preset song prep used' AFTER `mastered`"],
            // songs levelled before the target could be set were levelled to -16 LUFS, the only target there was
            11 => [
                "ALTER TABLE `{$p}tracks` ADD COLUMN `source_lufs` DECIMAL(5,2) NULL COMMENT 'its loudness before levelling (after mastering)' AFTER `loudness_lufs`",
                "ALTER TABLE `{$p}tracks` ADD COLUMN `level_gain_db` DECIMAL(5,2) NULL COMMENT 'the gain levelling applied' AFTER `source_lufs`",
                "ALTER TABLE `{$p}tracks` ADD COLUMN `level_target` DECIMAL(4,1) NULL COMMENT 'the station loudness target it was levelled to (LUFS)' AFTER `level_gain_db`",
                "ALTER TABLE `{$p}tracks` ADD COLUMN `preset_settings` TEXT NULL COMMENT 'JSON: the mastering settings it was made with' AFTER `preset`",
                "UPDATE `{$p}tracks` SET `level_target` = -16.0 WHERE `loudness_lufs` IS NOT NULL AND `level_target` IS NULL",
            ],
        ];
    }

    /** The version recorded in the database (0 if it can't be read). */
    public static function installed(Db $db): int
    {
        try {
            return (int) $db->run('SELECT value FROM ' . $db->table('settings') . " WHERE name = 'schema_version'")->fetchColumn();
        } catch (\PDOException) {
            return 0;
        }
    }

    /**
     * Bring an installed database up to VERSION, one step at a time, under a named lock so two
     * requests never run the same step. Each step only adds (CREATE TABLE IF NOT EXISTS and the
     * like), so a step cut short can simply run again. @return list<int> the versions applied
     */
    public static function upgrade(Db $db): array
    {
        $lock = 'sw.schema.' . substr(sha1((string) $db->pdo->query('SELECT DATABASE()')->fetchColumn() . '|' . $db->prefix), 0, 24);
        if ((int) $db->run('SELECT GET_LOCK(?, 10)', [$lock])->fetchColumn() !== 1) {
            throw new \RuntimeException('Another request is upgrading the database. Try again.');
        }
        try {
            $from = self::installed($db);
            $done = [];
            foreach (self::migrations($db->prefix) as $version => $statements) {
                if ($version <= $from || $version > self::VERSION) {
                    continue;
                }
                foreach ($statements as $sql) {
                    try {
                        $db->pdo->exec($sql);
                    } catch (\PDOException $e) {
                        // an ADD COLUMN that a step cut short already made (MySQL has no ADD COLUMN IF NOT EXISTS)
                        if ((int) ($e->errorInfo[1] ?? 0) !== 1060) {
                            throw $e;
                        }
                    }
                }
                $db->run('UPDATE ' . $db->table('settings') . " SET value = ?, updated_at = ? WHERE name = 'schema_version'", [(string) $version, gmdate('Y-m-d H:i:s')]);
                $done[] = $version;
            }
            return $done;
        } finally {
            $db->run('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /**
     * Upgrade if needed, checked cheaply: storage/cache/schema-version remembers the last version
     * seen, so most requests only read a small file.
     */
    public static function ensure(Db $db, string $root): void
    {
        $mark = $root . '/storage/cache/schema-version';
        if ((int) @file_get_contents($mark) === self::VERSION) {
            return;
        }
        if (self::installed($db) < self::VERSION) {
            self::upgrade($db);
        }
        if (!is_dir(dirname($mark))) {
            @mkdir(dirname($mark), 0775, true);
        }
        @file_put_contents($mark, (string) self::VERSION);
    }
}
