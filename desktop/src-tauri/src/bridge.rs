//! The local bridge: a small stand-in for an Icecast server on this PC (127.0.0.1 only), so BUTT or
//! Mixxx can send to it exactly as they would to Icecast. It takes the MP3 stream, cuts it into whole
//! frames (mp3.rs) and hands them to the studio page, which sends them to the station as its pieces.
//!
//! Spoken: Icecast 2 (`SOURCE`, and `PUT` with or without `Expect: 100-continue`, as libshout and
//! BUTT send it), Shoutcast 1 (the password on the first line, then `icy-` headers), and the
//! metadata calls (`GET /admin/metadata`), which are answered and passed on as the song title.
//! One source at a time; MP3 only.

use crate::mp3::{Cutter, Frame};
use base64::Engine;
use serde::Serialize;
use std::io::{Read, Write};
use std::net::{Shutdown, TcpListener, TcpStream};
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::{Arc, Mutex};
use std::thread;
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

/// What the bridge tells the studio page (and the settings page).
#[derive(Serialize, Clone, Debug)]
#[serde(tag = "type", rename_all = "lowercase")]
pub enum Event {
    /// A source connected and its first frames are coming.
    Connect { status: Status },
    /// Whole MP3 frames, joined, with each frame's length; `at` is when the first one arrived (ms).
    Frames { data: String, sizes: Vec<u32>, samples: u32, rate: u32, at: f64 },
    /// The source stopped (BUTT's Stop, a closed app, or nothing for 10 s).
    Disconnect { reason: String },
    /// The song title BUTT or Mixxx sent.
    Title { title: String },
    /// Something was refused (a wrong password, Ogg instead of MP3, a second source).
    Refused { reason: String },
    /// The listening state changed (a new port, or the port is taken).
    Status { status: Status },
}

#[derive(Serialize, Clone, Debug, Default)]
pub struct Status {
    pub port: u16,
    pub listening: bool,
    pub error: Option<String>,
    pub connected: bool,
    pub client: Option<String>,
    pub protocol: Option<String>,
    pub mount: Option<String>,
    pub kbps: Option<u32>,
    pub rate: Option<u32>,
    pub channels: Option<u8>,
    pub since: Option<f64>,
    pub bytes: u64,
    pub frames: u64,
    pub title: Option<String>,
    pub refused: Option<String>,
}

pub type Emit = Arc<dyn Fn(Event) + Send + Sync>;

pub struct Bridge {
    status: Arc<Mutex<Status>>,
    password: Arc<Mutex<String>>,
    generation: Arc<AtomicU64>,
    busy: Arc<AtomicBool>,
    emit: Emit,
}

fn now_ms() -> f64 {
    SystemTime::now().duration_since(UNIX_EPOCH).map(|d| d.as_secs_f64() * 1000.0).unwrap_or(0.0)
}

impl Bridge {
    pub fn new(emit: Emit) -> Self {
        Self {
            status: Arc::new(Mutex::new(Status::default())),
            password: Arc::new(Mutex::new(String::new())),
            generation: Arc::new(AtomicU64::new(0)),
            busy: Arc::new(AtomicBool::new(false)),
            emit,
        }
    }

    pub fn status(&self) -> Status {
        self.status.lock().unwrap().clone()
    }

    pub fn set_password(&self, password: &str) {
        *self.password.lock().unwrap() = password.to_string();
    }

    /// (Re)starts listening on 127.0.0.1:`port`. A source already connected stays connected.
    pub fn listen(&self, port: u16) {
        let gen = self.generation.fetch_add(1, Ordering::SeqCst) + 1;
        let listener = TcpListener::bind(("127.0.0.1", port)).and_then(|l| l.set_nonblocking(true).map(|_| l));
        {
            let mut s = self.status.lock().unwrap();
            s.port = port;
            match &listener {
                Ok(_) => { s.listening = true; s.error = None; }
                Err(e) => {
                    s.listening = false;
                    s.error = Some(if e.kind() == std::io::ErrorKind::AddrInUse {
                        format!("Port {port} is already in use on this PC (another program, or Stationwagon Studio open twice). Pick another port.")
                    } else {
                        format!("Can't listen on port {port}: {e}")
                    });
                }
            }
            (self.emit)(Event::Status { status: s.clone() });
        }
        let Ok(listener) = listener else { return };
        // Shoutcast's way: the source goes to the port after the server's, and song titles to the
        // server's own (admin.cgi). BUTT set to Shoutcast at port - 1 sends its sound here and its
        // titles to port - 1, so listen there too (for titles; quietly skipped if it's taken).
        let mut listeners = vec![listener];
        if let Ok(l) = TcpListener::bind(("127.0.0.1", port - 1)).and_then(|l| l.set_nonblocking(true).map(|_| l)) {
            listeners.push(l);
        }
        let (generation, status, password, busy, emit) = (self.generation.clone(), self.status.clone(), self.password.clone(), self.busy.clone(), self.emit.clone());
        thread::spawn(move || {
            while generation.load(Ordering::SeqCst) == gen {
                let mut idle = true;
                for listener in &listeners {
                    if let Ok((stream, _)) = listener.accept() {
                        idle = false;
                        let (status, password, busy, emit) = (status.clone(), password.clone(), busy.clone(), emit.clone());
                        thread::spawn(move || {
                            let _ = stream.set_nonblocking(false);
                            serve(stream, status, password, busy, emit);
                        });
                    }
                }
                if idle { thread::sleep(Duration::from_millis(50)); }
            }
        });
    }
}

/// Reads until the end of the request's header block; returns it and any bytes already past it.
fn read_head(stream: &mut TcpStream, first: Vec<u8>) -> Option<(String, Vec<u8>)> {
    let mut buf = first;
    let mut chunk = [0u8; 2048];
    loop {
        if let Some(p) = find(&buf, b"\r\n\r\n") {
            return Some((String::from_utf8_lossy(&buf[..p]).into_owned(), buf[p + 4..].to_vec()));
        }
        if let Some(p) = find(&buf, b"\n\n") {
            return Some((String::from_utf8_lossy(&buf[..p]).into_owned(), buf[p + 2..].to_vec()));
        }
        if buf.len() > 16 * 1024 {
            return None;
        }
        match stream.read(&mut chunk) {
            Ok(0) | Err(_) => return None,
            Ok(n) => buf.extend_from_slice(&chunk[..n]),
        }
    }
}

fn find(h: &[u8], n: &[u8]) -> Option<usize> {
    h.windows(n.len()).position(|w| w == n)
}

fn headers(head: &str) -> Vec<(String, String)> {
    head.lines().skip(1).filter_map(|l| l.split_once(':')).map(|(k, v)| (k.trim().to_ascii_lowercase(), v.trim().to_string())).collect()
}

fn get<'a>(h: &'a [(String, String)], k: &str) -> Option<&'a str> {
    h.iter().find(|(n, _)| n == k).map(|(_, v)| v.as_str())
}

fn same(a: &str, b: &str) -> bool {
    // the same length, compared without stopping early
    a.len() == b.len() && a.bytes().zip(b.bytes()).fold(0u8, |d, (x, y)| d | (x ^ y)) == 0
}

fn query(path: &str, key: &str) -> Option<String> {
    let q = path.split_once('?')?.1;
    url::form_urlencoded::parse(q.as_bytes()).find(|(k, _)| k == key).map(|(_, v)| v.into_owned())
}

fn serve(mut stream: TcpStream, status: Arc<Mutex<Status>>, password: Arc<Mutex<String>>, busy: Arc<AtomicBool>, emit: Emit) {
    let _ = stream.set_read_timeout(Some(Duration::from_secs(10)));
    let refuse = |stream: &mut TcpStream, reply: &str, reason: String| {
        let _ = stream.write_all(reply.as_bytes());
        let _ = stream.shutdown(Shutdown::Both);
        status.lock().unwrap().refused = Some(reason.clone());
        emit(Event::Refused { reason });
    };
    let pw = password.lock().unwrap().clone();

    // the first line tells Icecast (an HTTP request line) from Shoutcast 1 (the password)
    let mut buf = Vec::new();
    let mut chunk = [0u8; 2048];
    let line_end = loop {
        if let Some(p) = find(&buf, b"\n") { break p; }
        if buf.len() > 4096 { return; }
        match stream.read(&mut chunk) { Ok(0) | Err(_) => return, Ok(n) => buf.extend_from_slice(&chunk[..n]) }
    };
    let first = String::from_utf8_lossy(&buf[..line_end]).trim_end_matches('\r').to_string();
    let method = first.split(' ').next().unwrap_or("").to_ascii_uppercase();
    let is_http = first.ends_with("HTTP/1.0") || first.ends_with("HTTP/1.1");

    let (protocol, client, mount, ctype, rest, expect);
    if is_http {
        let Some((head, after)) = read_head(&mut stream, buf) else { return };
        let h = headers(&head);
        let path = first.split(' ').nth(1).unwrap_or("/").to_string();
        let auth_ok = get(&h, "authorization")
            .and_then(|a| a.strip_prefix("Basic ").or_else(|| a.strip_prefix("basic ")))
            .and_then(|b| base64::engine::general_purpose::STANDARD.decode(b.trim()).ok())
            .map(|d| String::from_utf8_lossy(&d).into_owned())
            .and_then(|up| up.split_once(':').map(|(_, p)| same(p, &pw)))
            .unwrap_or(false);
        if method == "GET" {
            if path.starts_with("/admin.cgi") {
                // Shoutcast 1's title update: the password is in the address
                if !query(&path, "pass").map(|p| same(&p, &pw)).unwrap_or(false) {
                    let _ = stream.write_all(b"HTTP/1.0 403 Forbidden\r\nContent-Length: 0\r\n\r\n");
                    return;
                }
                if let Some(song) = query(&path, "song") {
                    let song = song.trim().to_string();
                    status.lock().unwrap().title = Some(song.clone());
                    emit(Event::Title { title: song });
                }
                let _ = stream.write_all(b"HTTP/1.0 200 OK\r\nContent-Type: text/html\r\nContent-Length: 38\r\n\r\n<html><body>Update OK.</body></html>\r\n");
                return;
            }
            if path.starts_with("/admin/metadata") {
                if !auth_ok {
                    let _ = stream.write_all(b"HTTP/1.0 401 Unauthorized\r\nWWW-Authenticate: Basic realm=\"Icecast2 Server\"\r\nContent-Length: 0\r\n\r\n");
                    return;
                }
                if let Some(song) = query(&path, "song") {
                    let song = song.trim().to_string();
                    status.lock().unwrap().title = Some(song.clone());
                    emit(Event::Title { title: song });
                }
                let body = "<?xml version=\"1.0\"?>\n<iceresponse><message>Metadata update successful</message><return>1</return></iceresponse>\n";
                let _ = write!(stream, "HTTP/1.0 200 OK\r\nContent-Type: text/xml\r\nContent-Length: {}\r\n\r\n{body}", body.len());
            } else {
                let body = "Stationwagon Studio bridge: send your show here with BUTT or Mixxx (Icecast, MP3).\n";
                let _ = write!(stream, "HTTP/1.0 200 OK\r\nContent-Type: text/plain\r\nContent-Length: {}\r\n\r\n{body}", body.len());
            }
            return;
        }
        if method != "SOURCE" && method != "PUT" {
            let _ = stream.write_all(b"HTTP/1.0 405 Method Not Allowed\r\nContent-Length: 0\r\n\r\n");
            return;
        }
        if !auth_ok {
            return refuse(&mut stream, "HTTP/1.0 401 Unauthorized\r\nWWW-Authenticate: Basic realm=\"Icecast2 Server\"\r\nContent-Length: 0\r\n\r\n",
                "BUTT or Mixxx tried to connect with the wrong password. Copy the password from Stationwagon Studio's settings.".into());
        }
        protocol = format!("Icecast ({method})");
        client = get(&h, "user-agent").map(str::to_string);
        mount = Some(path.split('?').next().unwrap_or("/").to_string());
        ctype = get(&h, "content-type").map(|c| c.to_ascii_lowercase());
        expect = get(&h, "expect").map(|e| e.eq_ignore_ascii_case("100-continue")).unwrap_or(false);
        rest = after;
    } else {
        // Shoutcast 1: "password\r\n" (Shoutcast 2 style "password:#1" too), answered with OK2
        let given = first.split(":#").next().unwrap_or("").to_string();
        if !same(&given, &pw) {
            return refuse(&mut stream, "invalid password\r\n",
                "BUTT or Mixxx tried to connect with the wrong password. Copy the password from Stationwagon Studio's settings.".into());
        }
        if stream.write_all(b"OK2\r\nicy-caps:11\r\n\r\n").is_err() { return; }
        let leftover = buf[line_end + 1..].to_vec();
        let Some((head, after)) = read_head(&mut stream, leftover) else { return };
        let h = headers(&format!("\n{head}")); // headers() skips a request line
        protocol = "Shoutcast".to_string();
        client = get(&h, "icy-name").map(|n| format!("Shoutcast source ({n})")).or(Some("Shoutcast source".into()));
        mount = None;
        ctype = get(&h, "content-type").map(|c| c.to_ascii_lowercase());
        expect = false;
        rest = after;
    }

    if let Some(ct) = &ctype {
        if !(ct.contains("mpeg") || ct.contains("mp3")) {
            let kind = if ct.contains("ogg") { "Ogg (Vorbis or Opus)" } else if ct.contains("aac") { "AAC" } else if ct.contains("flac") { "FLAC" } else { ct.as_str() };
            let reply = if is_http { "HTTP/1.0 415 Unsupported Media Type\r\nContent-Length: 0\r\n\r\n" } else { "" };
            return refuse(&mut stream, reply, format!("BUTT or Mixxx is sending {kind}. Set it to MP3: the station takes MP3 only."));
        }
    }
    if busy.swap(true, Ordering::SeqCst) {
        let reply = if is_http { "HTTP/1.0 403 Forbidden\r\nContent-Type: text/plain\r\nContent-Length: 18\r\n\r\nMountpoint in use\n" } else { "" };
        let _ = stream.write_all(reply.as_bytes());
        emit(Event::Refused { reason: "A second source tried to connect while one is already sending. Only one can send at a time.".into() });
        return;
    }
    if is_http {
        // as Icecast does: "100 Continue" first when asked for it (BUTT's PUT, libshout), then "200 OK"
        let reply: &[u8] = if expect { b"HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 200 OK\r\n\r\n" } else { b"HTTP/1.0 200 OK\r\n\r\n" };
        if stream.write_all(reply).is_err() {
            busy.store(false, Ordering::SeqCst);
            return;
        }
    }

    {
        let mut s = status.lock().unwrap();
        s.connected = true;
        s.client = client;
        s.protocol = Some(protocol);
        s.mount = mount;
        s.kbps = None; s.rate = None; s.channels = None;
        s.since = Some(now_ms());
        s.bytes = 0; s.frames = 0; s.title = None; s.refused = None;
    }
    let reason = pump(&mut stream, rest, &status, &emit);
    let _ = stream.shutdown(Shutdown::Both);
    {
        let mut s = status.lock().unwrap();
        s.connected = false;
        s.since = None;
    }
    busy.store(false, Ordering::SeqCst);
    emit(Event::Disconnect { reason });
}

/// Reads the stream until it ends; sends frames about five times a second.
fn pump(stream: &mut TcpStream, first: Vec<u8>, status: &Arc<Mutex<Status>>, emit: &Emit) -> String {
    let mut cutter = Cutter::new();
    let mut batch: Vec<Frame> = Vec::new();
    let mut batch_at = 0.0;
    let mut announced = false;
    let mut last_send = Instant::now();
    let started = Instant::now();
    let mut chunk = vec![0u8; 16 * 1024];
    let mut pending = Some(first); // bytes that came in with the request
    let send = |batch: &mut Vec<Frame>, at: f64| {
        if batch.is_empty() { return; }
        let mut data = Vec::with_capacity(batch.iter().map(|f| f.bytes.len()).sum());
        for f in batch.iter() { data.extend_from_slice(&f.bytes); }
        let sizes = batch.iter().map(|f| f.bytes.len() as u32).collect();
        let samples = batch.iter().map(|f| f.samples).sum();
        emit(Event::Frames { data: base64::engine::general_purpose::STANDARD.encode(&data), sizes, samples, rate: batch[0].rate, at });
        batch.clear();
    };
    let reason = loop {
        let bytes: Vec<u8> = if let Some(p) = pending.take() {
            p
        } else {
            match stream.read(&mut chunk) {
                Ok(0) => break "BUTT or Mixxx stopped sending.".to_string(),
                Ok(n) => chunk[..n].to_vec(),
                Err(e) if matches!(e.kind(), std::io::ErrorKind::WouldBlock | std::io::ErrorKind::TimedOut) => break "Nothing came from BUTT or Mixxx for 10 seconds.".to_string(),
                Err(_) => break "The connection from BUTT or Mixxx closed.".to_string(),
            }
        };
        let n = bytes.len();
        let frames = cutter.push(&bytes);
        {
            let mut s = status.lock().unwrap();
            s.bytes += n as u64;
            s.frames += frames.len() as u64;
            if let Some(f) = frames.first() {
                s.kbps = Some(f.kbps); s.rate = Some(f.rate); s.channels = Some(f.channels);
            }
            if !announced && !frames.is_empty() {
                announced = true;
                emit(Event::Connect { status: s.clone() });
            }
        }
        if !announced && started.elapsed() > Duration::from_secs(5) {
            break "That isn't MP3 audio. Set BUTT or Mixxx to send MP3.".to_string();
        }
        if !frames.is_empty() && batch.is_empty() { batch_at = now_ms(); }
        batch.extend(frames);
        if batch.len() >= 8 || (!batch.is_empty() && last_send.elapsed() >= Duration::from_millis(200)) {
            send(&mut batch, batch_at);
            last_send = Instant::now();
        }
    };
    batch.extend(cutter.finish());
    send(&mut batch, batch_at);
    if !announced { emit(Event::Refused { reason: reason.clone() }); }
    reason
}
