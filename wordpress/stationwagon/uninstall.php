<?php
// Deleting the plugin forgets where the station is, but leaves the station itself (its folder,
// database tables, songs and recordings) running and untouched.
defined('WP_UNINSTALL_PLUGIN') || exit;
delete_option('stationwagon_station');
