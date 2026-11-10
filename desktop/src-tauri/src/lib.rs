//! Stationwagon Studio for Windows. The main window shows the station's own studio page (so it is
//! always the version on the server); the app adds the local bridge for BUTT and Mixxx (bridge.rs),
//! a settings page (ui/index.html) and updates (checked when the app opens, never during a show).
//!
//! The studio page may only listen to the bridge's events and read its status; everything else the
//! app can do is for its own local pages. The station's address gets that permission when it opens.
//!
//! Under the station's screens (the studio, the manager screens, the account page) the window has a second
//! view, the bar (round 61): the station's `bar` page, what's on air with a mute button and a volume slider.
//! It stays loaded while the screen above changes, so its sound has no gap. The browser gets the same from
//! the station's frame page (views/shell.php); the app's own user agent mark tells the station not to send it.

mod bridge;
mod mp3;

use bridge::{Bridge, Event};
use serde::{Deserialize, Serialize};
use std::path::PathBuf;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Mutex;
use tauri::ipc::CapabilityBuilder;
use tauri::{AppHandle, Emitter, LogicalPosition, LogicalSize, Manager, State, WebviewBuilder, WebviewUrl, WebviewWindowBuilder};
use tauri_plugin_updater::UpdaterExt;

#[derive(Serialize, Deserialize, Clone)]
pub struct Settings {
    /// The station's address, e.g. https://radio.example.com/stationwagon-app/ (always ends in "/")
    pub station: Option<String>,
    pub port: u16,
    pub password: String,
    /// The look picked in the studio (Green or Hi-fi), for the app's own page and its update dialog
    #[serde(default)]
    pub look: Option<Look>,
}

/// A look's colours and shapes as the studio page sent them: { skin, vars: { "--accent": "#1ed760", … } }.
#[derive(Serialize, Deserialize, Clone, Default)]
pub struct Look {
    pub skin: String,
    pub vars: std::collections::BTreeMap<String, String>,
}

impl Look {
    /// Only short CSS tokens with plain values, so the studio page can't put anything else on the app's page.
    fn checked(self) -> Result<Look, String> {
        let ok_skin = !self.skin.is_empty() && self.skin.len() <= 20 && self.skin.bytes().all(|b| b.is_ascii_lowercase());
        let ok_vars = self.vars.len() <= 40
            && self.vars.iter().all(|(k, v)| {
                k.starts_with("--") && k.len() <= 24 && k[2..].bytes().all(|b| b.is_ascii_lowercase() || b.is_ascii_digit() || b == b'-')
                    && !v.is_empty() && v.len() <= 200 && !v.to_ascii_lowercase().contains("url")
                    && v.chars().all(|c| c.is_ascii_alphanumeric() || " #%(),.'\"-_".contains(c))
            });
        if ok_skin && ok_vars { Ok(self) } else { Err("That isn't a look.".into()) }
    }
}

impl Default for Settings {
    fn default() -> Self {
        Self { station: None, port: 8000, password: new_password(), look: None }
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
    /// the bar: shown (a station screen is open above it), and tall (its volume slider is up)
    bar_on: AtomicBool,
    bar_tall: AtomicBool,
    /// the screen above, told to the bar (it mutes itself in the studio and Song prep)
    bar_route: Mutex<String>,
    /// one screen change at a time (each runs on its own thread)
    bar_busy: Mutex<()>,
    /// the bar's view is shown now (shown or hidden only when that changes: showing a view can take the keyboard)
    bar_shown: AtomicBool,
}

/// The same options for every view in the app: WebView2 refuses a second view whose options differ. The
/// first part is what wry passes by default; the bar may start playing (muted) without a click.
const BROWSER_ARGS: &str = "--disable-features=msWebOOUI,msPdfOOUI,msSmartScreenProtection --autoplay-policy=no-user-gesture-required";
/// The bar's height (84 px: room to breathe, the user's ask), and its height while its volume slider is up
/// (150 px more, over the screen's foot).
const BAR_H: f64 = 84.0;
const BAR_TALL_H: f64 = 234.0;

/// The screen at an address, if it is one of the station's screens with the bar under it (the same list
/// as the station's App::shellRoute): "studio", "account", "manage" or "manage/...".
fn bar_route(base: &url::Url, at: &url::Url) -> Option<String> {
    if base.origin() != at.origin() { return None; }
    let r = at.path().strip_prefix(base.path())?.trim_end_matches('/');
    let screen = r == "studio" || r == "account" || r == "manage"
        || (r.starts_with("manage/") && r != "manage/skip" && r != "manage/songprep/original");
    if screen { Some(r.to_string()) } else { None }
}

/// Puts the screen and the bar where they belong for the window's size: the bar along the foot (taller,
/// over the screen's foot, while its slider is up), or only the screen.
fn lay_out(app: &AppHandle) {
    let state = app.state::<App>();
    // (with the bar in it, "main" is a window with two views, no longer a "webview window")
    let (Some(window), Some(screen)) = (app.get_window("main"), app.get_webview("main")) else { return };
    let Ok(size) = window.inner_size() else { return };
    let scale = window.scale_factor().unwrap_or(1.0);
    let size = size.to_logical::<f64>(scale);
    let bar = app.get_webview("bar");
    let on = state.bar_on.load(Ordering::SeqCst) && bar.is_some();
    let screen_h = if on { (size.height - BAR_H).max(0.0) } else { size.height };
    let _ = screen.set_bounds(tauri::Rect { position: LogicalPosition::new(0.0, 0.0).into(), size: LogicalSize::new(size.width, screen_h).into() });
    if let Some(bar) = bar {
        if on {
            let h = if state.bar_tall.load(Ordering::SeqCst) { BAR_TALL_H } else { BAR_H }.min(size.height);
            let _ = bar.set_bounds(tauri::Rect { position: LogicalPosition::new(0.0, size.height - h).into(), size: LogicalSize::new(size.width, h).into() });
            if !state.bar_shown.swap(true, Ordering::SeqCst) {
                let _ = bar.show();
                let _ = screen.set_focus(); // the keyboard stays with the screen
            }
        } else if state.bar_shown.swap(false, Ordering::SeqCst) {
            let _ = bar.hide();
            let _ = screen.set_focus();
        }
    }
}

/// The screen above changed: shows or hides the bar (making it the first time), and tells it the screen.
fn screen_changed(app: &AppHandle, at: &url::Url) {
    let state = app.state::<App>();
    let _one = state.bar_busy.lock().unwrap();
    let base = state.settings.lock().unwrap().station.clone().and_then(|s| station_base(&s).ok());
    let route = base.as_ref().and_then(|b| bar_route(b, at));
    state.bar_on.store(route.is_some(), Ordering::SeqCst);
    if let (Some(base), Some(route)) = (base, route) {
        *state.bar_route.lock().unwrap() = route.clone();
        let want = base.join("bar").ok();
        match app.get_webview("bar") {
            Some(bar) => {
                // another station: its own bar
                if bar.url().ok().map(|u| u.origin() != base.origin() || !u.path().starts_with(base.path())).unwrap_or(false) {
                    let _ = allow_bar(app, &base);
                    if let Some(w) = want { let _ = bar.navigate(w); }
                } else {
                    tell_bar(&bar, &route);
                }
            }
            None => {
                if let (Some(w), Some(window)) = (want, app.get_window("main")) {
                    let _ = allow_bar(app, &base);
                    let handle = app.clone();
                    let builder = WebviewBuilder::new("bar", WebviewUrl::External(w))
                        .additional_browser_args(BROWSER_ARGS)
                        .transparent(true)
                        .on_page_load(move |bar, p| {
                            if p.event() == tauri::webview::PageLoadEvent::Finished {
                                let r = handle.state::<App>().bar_route.lock().unwrap().clone();
                                tell_bar(&bar, &r);
                            }
                        });
                    let made = window.add_child(builder, LogicalPosition::new(0.0, 0.0), LogicalSize::new(1.0, BAR_H));
                    if made.is_err() { state.bar_on.store(false, Ordering::SeqCst); }
                }
            }
        }
    }
    lay_out(app);
}

fn tell_bar(bar: &tauri::Webview, route: &str) {
    let r = serde_json::to_string(route).unwrap_or_else(|_| String::from("\"\""));
    let _ = bar.eval(format!("window.SwBar && window.SwBar.screen({r})"));
}

/// Lets the station's bar (only that view, only that site) make room for its volume slider.
fn allow_bar(app: &AppHandle, base: &url::Url) -> Result<(), String> {
    let origin = base.origin().ascii_serialization();
    let state = app.state::<App>();
    let mut allowed = state.allowed.lock().unwrap();
    let key = format!("bar {origin}");
    if allowed.contains(&key) { return Ok(()); }
    let cap = CapabilityBuilder::new(format!("bar-{}", allowed.len()))
        .remote(format!("{origin}/*"))
        .webview("bar")
        .permission("allow-bar-tall");
    app.add_capability(cap).map_err(|e| e.to_string())?;
    allowed.push(key);
    Ok(())
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
        .webview("main")
        .permission("core:event:allow-listen")
        .permission("core:event:allow-unlisten")
        .permission("allow-bridge-status")
        .permission("allow-set-busy")
        .permission("allow-quit-app")
        .permission("allow-open-settings")
        .permission("allow-set-look");
    app.add_capability(cap).map_err(|e| e.to_string())?;
    allowed.push(origin);
    Ok(())
}

// ---- Commands for the app's own pages -----------------------------------------------------------

#[derive(Serialize)]
struct Info { settings: Settings, version: String, bridge: bridge::Status, open: bool }

#[tauri::command]
fn get_info(app: AppHandle, state: State<App>) -> Info {
    let open = app.get_webview("main").and_then(|w| w.url().ok()).map(|u| u.scheme().starts_with("http") && u.host_str() != Some("tauri.localhost")).unwrap_or(false);
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

// ---- Finding the station (round 59) ------------------------------------------------------------
// A DJ may type the website's address (https://wordwave.co.za) instead of the station's
// (https://wordwave.co.za/radio/). Every Stationwagon station answers api/nowplaying.php with
// { station: { name } }, so the app asks there: at the address typed, at the stations linked from
// that page (its links to …/radio/, …/radio/signin, …/radio/embed), and in the usual folders.

/// What find_station found: `found` with the station's base and name; else why not.
#[derive(Serialize, Default)]
struct Station { found: bool, base: String, name: String, exact: bool, reachable: bool, message: String }

/// The usual folders a station is installed in, tried after the address typed and its links.
const STATION_FOLDERS: [&str; 4] = ["radio/", "stationwagon-app/", "stationwagon/", "station/"];

/// The station's base for a link on the site: the part before /studio, /signin, /embed, … ("" if none).
fn base_from_link(link: &url::Url) -> Option<url::Url> {
    let path = link.path();
    for mark in ["/studio", "/signin", "/embed", "/manage", "/podcast", "/api/"] {
        if let Some(i) = path.find(mark) {
            let rest = &path[i + mark.len()..];
            if mark.ends_with('/') || rest.is_empty() || rest.starts_with('/') || rest.starts_with('.') {
                let mut b = link.clone();
                b.set_query(None);
                b.set_fragment(None);
                b.set_path(&format!("{}/", &path[..i]));
                return Some(b);
            }
        }
    }
    None
}

/// The same site, with or without www.
fn same_site(a: &url::Url, b: &url::Url) -> bool {
    let h = |u: &url::Url| u.host_str().unwrap_or("").trim_start_matches("www.").to_ascii_lowercase();
    h(a) == h(b) && a.port_or_known_default() == b.port_or_known_default()
}

/// Links (href and src) on a page that point into a station on the same site.
fn station_links(page: &url::Url, html: &str) -> Vec<url::Url> {
    let mut out: Vec<url::Url> = Vec::new();
    for attr in ["href=\"", "src=\"", "href='", "src='"] {
        let quote = attr.chars().last().unwrap();
        let mut rest = html;
        while let Some(i) = rest.find(attr) {
            rest = &rest[i + attr.len()..];
            let end = rest.find(quote).unwrap_or(0);
            let raw = rest[..end].replace("&amp;", "&");
            if let Ok(link) = page.join(&raw) {
                if same_site(page, &link) {
                    if let Some(b) = base_from_link(&link) { if !out.contains(&b) { out.push(b); } }
                }
            }
            if out.len() >= 8 { return out; }
        }
    }
    out
}

/// Asks base/api/nowplaying.php: Some((base after any redirect, the station's name)) for a station.
async fn station_at(client: &reqwest::Client, base: &url::Url) -> Result<Option<(url::Url, String)>, ()> {
    let ask = base.join("api/nowplaying.php").map_err(|_| ())?;
    let r = client.get(ask).header("Accept", "application/json").send().await.map_err(|_| ())?;
    if !r.status().is_success() { return Ok(None); }
    let mut real = r.url().clone();
    let v: serde_json::Value = match r.json().await { Ok(v) => v, Err(_) => return Ok(None) };
    let name = v.get("station").and_then(|s| s.get("name")).and_then(|n| n.as_str()).map(|s| s.trim().to_string());
    let Some(name) = name else { return Ok(None) };
    real.set_query(None);
    let p = real.path().trim_end_matches("api/nowplaying.php").to_string();
    real.set_path(&p);
    Ok(Some((real, if name.is_empty() { "a Stationwagon station".into() } else { name })))
}

fn http_client() -> Result<reqwest::Client, String> {
    let _ = rustls::crypto::ring::default_provider().install_default();
    reqwest::Client::builder()
        .timeout(std::time::Duration::from_secs(8))
        .user_agent(concat!("Stationwagon Studio/", env!("CARGO_PKG_VERSION")))
        .build()
        .map_err(|e| e.to_string())
}

/// Finds the station from an address as typed (see above). Never fails: `found`, or a message saying why not.
#[tauri::command]
async fn find_station(address: String) -> Station {
    let typed = match station_base(&address) { Ok(b) => b, Err(e) => return Station { message: e, ..Default::default() } };
    let client = match http_client() { Ok(c) => c, Err(e) => return Station { message: e, ..Default::default() } };
    let found = |(base, name): (url::Url, String), reachable| Station {
        found: true, exact: base == typed, base: base.to_string(), name, reachable, message: String::new(),
    };
    // the address typed
    let first = station_at(&client, &typed).await;
    if let Ok(Some(hit)) = first { return found(hit, true); }
    // the page at that address, for its links to the station
    let mut candidates: Vec<url::Url> = Vec::new();
    let mut reachable = first.is_ok();
    if let Ok(r) = client.get(typed.clone()).send().await {
        reachable = true;
        let page = r.url().clone();
        if let Ok(html) = r.text().await { candidates.extend(station_links(&page, &html)); }
    }
    if !reachable {
        return Station { reachable: false, message: format!("Couldn’t reach {typed}. Check the address and your internet connection, then try again."), ..Default::default() };
    }
    for f in STATION_FOLDERS {
        if let Ok(u) = typed.join(f) { if !candidates.contains(&u) { candidates.push(u); } }
    }
    candidates.retain(|c| c != &typed);
    // ask them all at once, then take the first that is a station, in the order above
    let tasks: Vec<_> = candidates.iter().cloned().map(|c| {
        let client = client.clone();
        tauri::async_runtime::spawn(async move { station_at(&client, &c).await })
    }).collect();
    for t in tasks {
        if let Ok(Ok(Some(hit))) = t.await { return found(hit, true); }
    }
    Station { reachable: true, message: format!("There’s no Stationwagon station at {typed}. Type the station’s own address, for example https://example.com/radio"), ..Default::default() }
}

/// Opens the studio of the saved station in the main window (and closes the settings window).
#[tauri::command]
fn open_station(app: AppHandle, state: State<App>) -> Result<(), String> {
    let station = state.settings.lock().unwrap().station.clone().ok_or("Type the station's address first.")?;
    let base = station_base(&station)?;
    allow_station(&app, &base)?;
    let studio = base.join("studio").map_err(|e| e.to_string())?;
    let main = app.get_webview("main").ok_or("The main window is gone.")?;
    main.navigate(studio).map_err(|e| e.to_string())?;
    if let Some(w) = app.get_window("main") { let _ = w.set_focus(); }
    if let Some(w) = app.get_webview_window("settings") { let _ = w.close(); }
    Ok(())
}

/// A newer version and what it brings (the release's description on GitHub).
#[derive(Serialize)]
struct Found { version: String, notes: String }

/// Looks for a newer version (at the address in tauri.conf.json, or SW_UPDATE_ENDPOINT for tests).
#[tauri::command]
async fn check_update(app: AppHandle, state: State<'_, App>) -> Result<Option<Found>, String> {
    let mut b = app.updater_builder();
    if let Ok(ep) = std::env::var("SW_UPDATE_ENDPOINT") {
        b = b.endpoints(vec![url::Url::parse(&ep).map_err(|e| e.to_string())?]).map_err(|e| e.to_string())?;
    }
    let update = b.build().map_err(|e| e.to_string())?.check().await.map_err(|e| e.to_string())?;
    let found = update.as_ref().map(|u| Found { version: u.version.clone(), notes: u.body.clone().unwrap_or_default() });
    *state.update.lock().unwrap() = update;
    Ok(found)
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

/// The studio page passes the look picked there; the app's own page uses it from then on.
#[tauri::command]
fn set_look(look: Look, state: State<App>) -> Result<(), String> {
    let look = look.checked()?;
    state.settings.lock().unwrap().look = Some(look);
    state.save()
}

/// The bar's volume slider is up (or down again): the bar grows over the screen's foot, or shrinks back.
#[tauri::command]
async fn bar_tall(tall: bool, app: AppHandle, state: State<'_, App>) -> Result<(), ()> {
    state.bar_tall.store(tall, Ordering::SeqCst);
    lay_out(&app);
    Ok(())
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
        .additional_browser_args(BROWSER_ARGS)
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

/// Marks the app in the station's user agent ("StationwagonStudio/<version> (Windows)"), as the Android app
/// does, so the station sends its screens as they are, not inside its frame page with a bar (the app has its own).
#[cfg(windows)]
fn mark_user_agent(w: &tauri::WebviewWindow) {
    let _ = w.with_webview(|wv| unsafe {
        use webview2_com::Microsoft::Web::WebView2::Win32::ICoreWebView2Settings2;
        use windows_core::{Interface, HSTRING, PWSTR};
        if let Ok(s) = wv.controller().CoreWebView2().and_then(|c| c.Settings()).and_then(|s| s.cast::<ICoreWebView2Settings2>()) {
            let mut ua = PWSTR::null();
            if s.UserAgent(&mut ua).is_ok() && !ua.is_null() {
                let now = ua.to_string().unwrap_or_default();
                drop(webview2_com::CoTaskMemPWSTR::from(ua)); // frees it
                if !now.contains("StationwagonStudio/") {
                    let _ = s.SetUserAgent(&HSTRING::from(format!("{now} StationwagonStudio/{} (Windows)", env!("CARGO_PKG_VERSION"))));
                }
            }
        }
    });
}

pub fn run() {
    tauri::Builder::default()
        .plugin(tauri_plugin_single_instance::init(|app, _, _| {
            if let Some(w) = app.get_window("main") { let _ = w.unminimize(); let _ = w.set_focus(); }
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
            app.manage(App { bridge, settings: Mutex::new(settings), path, busy: AtomicBool::new(false), update: Mutex::new(None), allowed: Mutex::new(Vec::new()),
                bar_on: AtomicBool::new(false), bar_tall: AtomicBool::new(false), bar_route: Mutex::new(String::new()), bar_busy: Mutex::new(()), bar_shown: AtomicBool::new(true) });
            let state = app.state::<App>();
            state.save().ok(); // keeps the first password
            state.bridge.listen(port);
            #[cfg(windows)]
            if let Some(w) = app.get_webview_window("main") { remember_passwords(&w); mark_user_agent(&w); }
            // the screen keeps its own place in the window from now on (the bar goes under it)
            if let Some(w) = app.get_webview_window("main") { let v: &tauri::Webview = w.as_ref(); let _ = v.set_auto_resize(false); }
            Ok(())
        })
        .on_page_load(|webview, p| {
            if webview.label() == "main" && p.event() == tauri::webview::PageLoadEvent::Finished {
                // not on the window's own thread: making the bar waits for that thread
                let (app, at) = (webview.app_handle().clone(), p.url().clone());
                std::thread::spawn(move || screen_changed(&app, &at));
            }
        })
        .on_window_event(|window, event| {
            if let tauri::WindowEvent::Resized(_) | tauri::WindowEvent::ScaleFactorChanged { .. } = event {
                if window.label() == "main" { lay_out(window.app_handle()); }
            }
            // back in front (after the settings window, say): the keys go to the page, not the bar
            if let tauri::WindowEvent::Focused(true) = event {
                if window.label() == "main" {
                    if let Some(screen) = window.app_handle().get_webview("main") { let _ = screen.set_focus(); }
                }
            }
            // closing the studio while on air: the page asks first ("End the show" or "Close anyway")
            if let tauri::WindowEvent::CloseRequested { api, .. } = event {
                if window.label() == "main" && window.state::<App>().busy.load(Ordering::SeqCst) {
                    api.prevent_close();
                    let _ = window.emit_to("main", "close-requested", ());
                }
            }
        })
        .invoke_handler(tauri::generate_handler![
            get_info, save_settings, renew_password, open_station, check_update, install_update, find_station,
            bridge_status, set_busy, quit_app, open_settings, set_look, bar_tall
        ])
        .run(tauri::generate_context!())
        .expect("error while running Stationwagon Studio");
}

#[cfg(test)]
mod tests {
    use super::{bar_route, base_from_link, station_base, station_links, Look};

    #[test]
    fn bar_under_screens() {
        let u = |s: &str| url::Url::parse(s).unwrap();
        let base = u("https://wordwave.co.za/radio/");
        let r = |s: &str| bar_route(&base, &u(s));
        assert_eq!(r("https://wordwave.co.za/radio/studio").as_deref(), Some("studio"));
        assert_eq!(r("https://wordwave.co.za/radio/manage/").as_deref(), Some("manage"));
        assert_eq!(r("https://wordwave.co.za/radio/manage/songprep?x=1").as_deref(), Some("manage/songprep"));
        assert_eq!(r("https://wordwave.co.za/radio/account").as_deref(), Some("account"));
        assert!(r("https://wordwave.co.za/radio/").is_none());
        assert!(r("https://wordwave.co.za/radio/signin").is_none());
        assert!(r("https://wordwave.co.za/radio/manage/skip").is_none());
        assert!(r("https://wordwave.co.za/radio/manage/songprep/original").is_none());
        assert!(r("https://wordwave.co.za/radio/studios").is_none());
        assert!(r("https://other.example/radio/studio").is_none());
        assert!(r("tauri://localhost/index.html").is_none());
    }

    #[test]
    fn station_from_links() {
        let u = |s: &str| url::Url::parse(s).unwrap();
        let b = |s: &str| base_from_link(&u(s)).map(|x| x.to_string());
        assert_eq!(b("https://wordwave.co.za/radio/signin").unwrap(), "https://wordwave.co.za/radio/");
        assert_eq!(b("https://wordwave.co.za/radio/embed?theme=dark").unwrap(), "https://wordwave.co.za/radio/");
        assert_eq!(b("https://wordwave.co.za/radio/api/nowplaying.php").unwrap(), "https://wordwave.co.za/radio/");
        assert_eq!(b("https://x.example/studio").unwrap(), "https://x.example/");
        assert!(b("https://wordwave.co.za/studios-and-more/").is_none());
        assert!(b("https://wordwave.co.za/listen/").is_none());
        let page = u("https://wordwave.co.za/");
        let html = r#"<a href="/listen/">Listen</a><a href="https://wordwave.co.za/radio/signin">Sign in</a>
            <iframe src="https://www.wordwave.co.za/radio/embed"></iframe><a href="https://other.example/radio/signin">x</a>"#;
        let found: Vec<String> = station_links(&page, html).iter().map(|x| x.to_string()).collect();
        assert_eq!(found, vec!["https://wordwave.co.za/radio/", "https://www.wordwave.co.za/radio/"]);
    }

    #[test]
    fn looks() {
        let l = |skin: &str, k: &str, v: &str| Look { skin: skin.into(), vars: [(k.to_string(), v.to_string())].into() }.checked().is_ok();
        assert!(l("hifi", "--accent", "#ffb24a"));
        assert!(l("green", "--card-shadow", "inset 1px 1px 0 #4b463e, inset -1px -1px 0 #0a0908"));
        assert!(l("hifi", "--f-body", "\"Barlow\", system-ui, -apple-system, \"Segoe UI\", sans-serif"));
        assert!(!l("hifi", "--accent", "url(https://evil.example.com/x.png)"));
        assert!(!l("hifi", "--accent", "red; } body { display: none"));
        assert!(!l("hifi", "color", "red"));
        assert!(!l("Hi-fi", "--accent", "red"));
    }

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
