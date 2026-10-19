<?php
declare(strict_types=1);

namespace Stationwagon;

/**
 * The database tables, version 1. Plain MySQL 8 / MariaDB 10.5+ DDL: InnoDB, utf8mb4, no JSON
 * column type (JSON lives in MEDIUMTEXT so both servers behave the same), no generated columns.
 *
 * Times: DATETIME columns are UTC wall-clock times (created_at and the like). Stream times
 * (when a song or live block plays) are unix seconds with milliseconds, DECIMAL(15,3), because
 * the timeline needs sub-second precision and does arithmetic on them.
 *
 * Later versions add migrations here; the installed version is in settings.schema_version.
 */
final class Schema
{
    public const VERSION = 1;

    /** Table names without prefix, in creation order (foreign keys need their parents first). */
    public const TABLES = [
        'settings', 'stations', 'users', 'station_users', 'tracks', 'playlists', 'playlist_tracks',
        'schedule', 'live_shows', 'live_pieces', 'timeline', 'presenter_notes',
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
                `settings` MEDIUMTEXT NULL COMMENT 'JSON: crossfade, piece length, live timeout, skin',
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
                PRIMARY KEY (`id`),
                KEY `{$p}notes_station_posted` (`station_id`, `posted_at`),
                {$fk('notes_station', 'station_id', 'stations')},
                {$fk('notes_show', 'live_show_id', 'live_shows', 'SET NULL')},
                {$fk('notes_user', 'user_id', 'users', 'SET NULL')}
            ) $opts",
        ];
    }
}
