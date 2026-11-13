fn main() {
    // the app's own commands, so capabilities can name them (allow-bridge-status and so on)
    tauri_build::try_build(tauri_build::Attributes::new().app_manifest(tauri_build::AppManifest::new().commands(&[
        "get_info", "save_settings", "renew_password", "open_station", "check_update", "install_update",
        "bridge_status", "set_busy", "quit_app", "open_settings", "set_look", "find_station", "bar_tall", "app_version",
        "stations", "switch_station", "add_station", "forget_station",
    ])))
    .expect("failed to run the Tauri build script");
}
