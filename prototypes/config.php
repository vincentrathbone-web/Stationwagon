<?php
// Shared settings for all Stationwagon prototypes.
// Change control_key before uploading to a public host.
return [
    'media_dir'            => __DIR__ . '/media',   // drop test MP3s here
    'data_dir'             => __DIR__ . '/data',    // generated: library index, timeline, live pieces
    // URLs as seen from prototypes/api/ (HLS segment URIs are relative to the playlist)
    'media_url'            => '../media',
    'data_url'             => '../data',

    'segment_seconds'      => 6.0,     // length of one HLS piece
    'window_segments'      => 5,       // pieces listed in the live playlist
    'target_duration'      => 7,       // HLS #EXT-X-TARGETDURATION (>= longest piece, rounded up)
    'crossfade_seconds'    => 6.0,     // song-to-song overlap in the two-deck player
    'skip_fade_seconds'    => 2.0,     // fade used by Skip
    'plan_ahead_seconds'   => 3 * 3600,
    'recent_tracks'        => 5,       // don't repeat any of the last N songs
    'cut_lead_seconds'     => 1.0,     // skip / go-live land on the first piece boundary after now + this
    'live_timeout_seconds' => 8,       // no live piece for this long -> back to AutoDJ
    'control_key'          => 'stationwagon-poc',
];
