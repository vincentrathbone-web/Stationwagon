//! Stationwagon Studio for Windows. The main window shows the station's own studio page (so it is
//! always the version on the server); the app adds the local bridge for BUTT and Mixxx (bridge.rs),
//! a settings page (ui/index.html) and updates (checked when the app opens, never during a show).
//!
//! The studio page may only listen to the bridge's events and read its status; everything else the
//! app can do is for its own local pages. The station's address gets that permission when it opens.

mod bridge;
mod mp3;

use bridge::{Bridge, Event};
use serde::{Deserialize, Serialize};
use std::path::PathBuf;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Mutex;
use tauri::ipc::CapabilityBuilder;
use tauri::{AppHandle, Emitter, Manager, State, WebviewUrl, WebviewWindowBuilder};
use tauri_plugin_updater::UpdaterExt;

#[derive(Serialize, Deserialize, Clone)]
pub struct Settings {
    /// The station's address, e.g. https://radio.example.com/stationwagon-app/ (always ends in "/")
    pub station: Option<String>,
    pub port: u16,
    pub password: String,
}

impl Default for Settings {
    fn default() -> Self {
        Self { station: None, port: 8000, password: new_password() }
    }
}

fn new_password() -> String {
    use rand::Rng;
    const CHARS: &[u8] = b"abcdefghjkmnpqrstuvwxyz23456789";
    let mut r = rand::thread_rng();
    (0..12).map(|_| CHARS[r.gen_range(0..CHARS.len())] as char).collect()
}

struct App {
    bridge: Bridge,
    settings: Mutex<Settings>,
    path: PathBuf,
    /// the studio page says it is on air (or still uploading a recording): closing asks first
    busy: AtomicBool,
    update: Mutex<Option<tauri_plugin_updater::Update>>,
    allowed: Mutex<Vec<String>>,
}

impl App {
    fn save(&self) -> Result<(), String> {
        let s = self.settings.lock().unwrap().clone();
        if let Some(dir) = self.path.parent() { std::fs::create_dir_all(dir).map_err(|e| e.to_string())?; }
        std::fs::write(&self.path, serde_json::to_vec_pretty(&s).unwrap()).map_err(|e| e.to_string())
    }
}

/// The station address as typed (with or without https://, with or without /studio) made into the base.
fn station_base(input: &str) -> Result<url::Url, String> {
    let t = input.trim();
    let t = if t.contains("://") { t.to_string() } else { format!("https://{t}") };
    let mut u = url::Url::parse(&t).map_err(|_| "That isn't a web address.".to_string())?;
    let local = matches!(u.host_str(), Some("localhost") | Some("127.0.0.1"));
    if u.scheme() != "https" && !(u.scheme() == "http" && local) {
        return Err("The station's address must start with https://".into());
    }
    u.set_query(None);
    u.set_fragment(None);
    let mut path = u.path().trim_end_matches('/').to_string();
    for tail in ["/studio", "/signin", "/manage"] {
        if let Some(p) = path.strip_suffix(tail) { path = p.to_string(); }
    }
    u.set_path(&format!("{path}/"));
    Ok(u)
}

/// Lets the station's pages (only that site) hear the bridge.
fn allow_station(app: &AppHandle, base: &url::Url) -> Result<(), String> {
    let origin = base.origin().ascii_serialization();
    let state = app.state::<App>();
    let mut allowed = state.allowed.lock().unwrap();
    if allowed.contains(&origin) { return Ok(()); }
    let cap = CapabilityBuilder::new(format!("station-{}", allowed.len()))
        .remote(format!("{origin}/*"))
        .window("main")
        .permission("core:event:allow-listen")
        .permission("core:event:allow-unlisten")
        .permission("allow-bridge-status")
        .permission("allow-set-busy")
        .permission("allow-quit-app")
        .permission("allow-open-settings");
    app.add_capability(cap).map_err(|e| e.to_string())?;
    allowed.push(origin);
    Ok(())
}

// ---- Commands for the app's own pages -----------------------------------------------------------

#[derive(Serialize)]
struct Info { settings: Settings, version: String, bridge: bridge::Status, open: bool }

#[tauri::command]
fn get_info(app: AppHandle, state: State<App>) -> Info {
    let open = app.get_webview_window("main").and_then(|w| w.url().ok()).map(|u| u.scheme().starts_with("http") && u.host_str() != Some("tauri.localhost")).unwrap_or(false);
    Info { settings: state.settings.lock().unwrap().clone(), version: app.package_info().version.to_string(), bridge: state.bridge.status(), open }
}

#[tauri::command]
fn save_settings(station: String, port: u16, state: State<App>) -> Result<Settings, String> {
    let base = station_base(&station)?;
    if port < 1024 { return Err("Pick a port from 1024 to 65535 (8000 is usual).".into()); }
    let changed_port = {
        let mut s = state.settings.lock().unwrap();
        let changed = s.port != port;
        s.station = Some(base.to_string());
        s.port = port;
        changed
    };
    state.save()?;
    if changed_port { state.bridge.listen(port); }
    Ok(state.settings.lock().unwrap().clone())
}

#[tauri::command]
fn renew_password(state: State<App>) -> Result<String, String> {
    let pw = new_password();
    state.settings.lock().unwrap().password = pw.clone();
    state.bridge.set_password(&pw);
    state.save()?;
    Ok(pw)
}

/// Opens the studio of the saved station in the main window (and closes the settings window).
#[tauri::command]
fn open_station(app: AppHandle, state: State<App>) -> Result<(), String> {
    let station = state.settings.lock().unwrap().station.clone().ok_or("Type the station's address first.")?;
    let base = station_base(&station)?;
    allow_station(&app, &base)?;
    let studio = base.join("studio").map_err(|e| e.to_string())?;
    let main = app.get_webview_window("main").ok_or("The main window is gone.")?;
    main.navigate(studio).map_err(|e| e.to_string())?;
    let _ = main.set_focus();
    if let Some(w) = app.get_webview_window("settings") { let _ = w.close(); }
    Ok(())
}

/// Looks for a newer version (at the address in tauri.conf.json, or SW_UPDATE_ENDPOINT for tests).
#[tauri::command]
async fn check_update(app: AppHandle, state: State<'_, App>) -> Result<Option<String>, String> {
    let mut b = app.updater_builder();
    if let Ok(ep) = std::env::var("SW_UPDATE_ENDPOINT") {
        b = b.endpoints(vec![url::Url::parse(&ep).map_err(|e| e.to_string())?]).map_err(|e| e.to_string())?;
    }
    let update = b.build().map_err(|e| e.to_string())?.check().await.map_err(|e| e.to_string())?;
    let version = update.as_ref().map(|u| u.version.clone());
    *state.update.lock().unwrap() = update;
    Ok(version)
}

/// Downloads and installs the update found by check_update (refused while on air), then restarts.
#[tauri::command]
async fn install_update(app: AppHandle, state: State<'_, App>) -> Result<(), String> {
    if state.busy.load(Ordering::SeqCst) || state.bridge.status().connected {
        return Err("Not while you're on air or BUTT is sending. It installs the next time the app opens.".into());
    }
    let update = state.update.lock().unwrap().take().ok_or("No update is waiting.")?;
    let mut got = 0usize;
    let progress = app.clone();
    update
        .download_and_install(
            move |n, total| { got += n; let _ = progress.emit("update-progress", (got, total)); },
            || {},
        )
        .await
        .map_err(|e| e.to_string())?;
    app.restart();
}

// ---- Commands the station's studio page may also use ---------------------------------------------

#[tauri::command]
fn bridge_status(state: State<App>) -> bridge::Status {
    state.bridge.status()
}

#[tauri::command]
fn set_busy(busy: bool, state: State<App>) {
    state.busy.store(busy, Ordering::SeqCst);
}

#[tauri::command]
fn quit_app(app: AppHandle) {
    app.exit(0);
}

/// Async: on Windows, a window made in a synchronous command deadlocks.
#[tauri::command]
async fn open_settings(app: AppHandle) -> Result<(), String> {
    if let Some(w) = app.get_webview_window("settings") {
        let _ = w.set_focus();
        return Ok(());
    }
    WebviewWindowBuilder::new(&app, "settings", WebviewUrl::App("index.html#settings".into()))
        .title("Stationwagon Studio: settings")
        .inner_size(560.0, 720.0)
        .build()
        .map(|_| ())
        .map_err(|e| e.to_string())
}

#[cfg_attr(mobile, tauri::mobile_entry_point)]
/// Lets Edge's password manager save the station's sign-in and fill it in next time (WebView2 has
/// it off unless the app turns it on). The passwords stay in WebView2's own store, never in the app.
#[cfg(windows)]
fn remember_passwords(w: &tauri::WebviewWindow) {
    let _ = w.with_webview(|wv| unsafe {
        use webview2_com::Microsoft::Web::WebView2::Win32::ICoreWebView2Settings4;
        use windows_core::Interface;
        if let Ok(s) = wv.controller().CoreWebView2().and_then(|c| c.Settings()).and_then(|s| s.cast::<ICoreWebView2Settings4>()) {
            let _ = s.SetIsPasswordAutosaveEnabled(true);
            let _ = s.SetIsGeneralAutofillEnabled(true);
        }
    });
}

pub fn run() {
    tauri::Builder::default()
        .plugin(tauri_plugin_single_instance::init(|app, _, _| {
            if let Some(w) = app.get_webview_window("main") { let _ = w.unminimize(); let _ = w.set_focus(); }
        }))
        .plugin(tauri_plugin_updater::Builder::new().build())
        .plugin(tauri_plugin_process::init())
        .setup(|app| {
            let dir = app.path().app_config_dir()?;
            let path = dir.join("settings.json");
            let settings: Settings = std::fs::read(&path).ok().and_then(|b| serde_json::from_slice(&b).ok()).unwrap_or_default();
            let handle = app.handle().clone();
            let bridge = Bridge::new(std::sync::Arc::new(move |e: Event| { let _ = handle.emit("bridge", e); }));
            bridge.set_password(&settings.password);
            let port = settings.port;
            app.manage(App { bridge, settings: Mutex::new(settings), path, busy: AtomicBool::new(false), update: Mutex::new(None), allowed: Mutex::new(Vec::new()) });
            let state = app.state::<App>();
            state.save().ok(); // keeps the first password
            state.bridge.listen(port);
            #[cfg(windows)]
            if let Some(w) = app.get_webview_window("main") { remember_passwords(&w); }
            Ok(())
        })
        .on_window_event(|window, event| {
            // closing the studio while on air: the page asks first ("End the show" or "Close anyway")
            if let tauri::WindowEvent::CloseRequested { api, .. } = event {
                if window.label() == "main" && window.state::<App>().busy.load(Ordering::SeqCst) {
                    api.prevent_close();
                    let _ = window.emit_to("main", "close-requested", ());
                }
            }
        })
        .invoke_handler(tauri::generate_handler![
            get_info, save_settings, renew_password, open_station, check_update, install_update,
            bridge_status, set_busy, quit_app, open_settings
        ])
        .run(tauri::generate_context!())
        .expect("error while running Stationwagon Studio");
}

#[cfg(test)]
mod tests {
    use super::station_base;

    #[test]
    fn station_addresses() {
        let b = |s: &str| station_base(s).map(|u| u.to_string());
        assert_eq!(b("wordwave.controll.co.za/stationwagon-app").unwrap(), "https://wordwave.controll.co.za/stationwagon-app/");
        assert_eq!(b("https://radio.example.com/studio").unwrap(), "https://radio.example.com/");
        assert_eq!(b("https://radio.example.com/sw/studio?x=1#y").unwrap(), "https://radio.example.com/sw/");
        assert_eq!(b("http://localhost:8080/stationwagon-app/").unwrap(), "http://localhost:8080/stationwagon-app/");
        assert!(b("http://radio.example.com/").is_err());
        assert!(b("ftp://radio.example.com/").is_err());
    }
}
