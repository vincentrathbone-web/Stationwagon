//! Cuts an MP3 byte stream (as BUTT or Mixxx sends it) into whole MPEG audio frames, the way the
//! studio's own encoder hands them over: the station's pieces must start on a frame and carry no tags.
//! Bytes can arrive in any chunks; a frame is only handed out once all of it is here, and the next
//! frame header follows it (or, once locked on, the header is where the last frame said it would be).

/// One whole frame and what it holds.
#[derive(Debug, Clone, PartialEq)]
pub struct Frame {
    pub bytes: Vec<u8>,
    pub samples: u32,
    pub rate: u32,
    pub kbps: u32,
    pub channels: u8,
}

#[derive(Debug, Clone, Copy, PartialEq)]
struct Header {
    len: usize,
    samples: u32,
    rate: u32,
    kbps: u32,
    channels: u8,
    version: u8,
    layer: u8,
}

const KBPS_V1_L3: [u32; 15] = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320];
const KBPS_V1_L2: [u32; 15] = [0, 32, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 384];
const KBPS_V2_L23: [u32; 15] = [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160];

/// Reads a frame header at the start of `b` (layers II and III; free bitrate is refused).
fn header(b: &[u8]) -> Option<Header> {
    if b.len() < 4 || b[0] != 0xFF || b[1] & 0xE0 != 0xE0 {
        return None;
    }
    let version = (b[1] >> 3) & 3; // 3 = MPEG-1, 2 = MPEG-2, 0 = MPEG-2.5
    let layer = (b[1] >> 1) & 3; // 1 = layer III, 2 = layer II
    if version == 1 || !(layer == 1 || layer == 2) {
        return None;
    }
    let bi = (b[2] >> 4) as usize;
    let si = ((b[2] >> 2) & 3) as usize;
    if bi == 0 || bi == 15 || si == 3 {
        return None;
    }
    let pad = ((b[2] >> 1) & 1) as usize;
    let base = [44100, 48000, 32000][si];
    let rate = match version { 3 => base, 2 => base / 2, _ => base / 4 };
    let kbps = match (version, layer) {
        (3, 1) => KBPS_V1_L3[bi],
        (3, _) => KBPS_V1_L2[bi],
        _ => KBPS_V2_L23[bi],
    };
    let samples: u32 = if layer == 2 || version == 3 { 1152 } else { 576 };
    let len = (samples as usize / 8) * kbps as usize * 1000 / rate as usize + pad;
    let channels = if b[3] >> 6 == 3 { 1 } else { 2 };
    Some(Header { len, samples, rate, kbps, channels, version, layer })
}

/// Bytes in, whole frames out.
#[derive(Default)]
pub struct Cutter {
    buf: Vec<u8>,
    locked: Option<(u8, u8, u32)>, // version, layer and rate of the stream once two frames line up
    skip: usize,                  // bytes of an ID3v2 tag still to drop
    pub dropped: u64,             // bytes thrown away while looking for frames
}

impl Cutter {
    pub fn new() -> Self {
        Self::default()
    }

    /// Adds bytes and returns every frame that is now whole.
    pub fn push(&mut self, data: &[u8]) -> Vec<Frame> {
        let mut data = data;
        if self.skip > 0 {
            let n = self.skip.min(data.len());
            self.skip -= n;
            self.dropped += n as u64;
            data = &data[n..];
        }
        self.buf.extend_from_slice(data);
        let mut out = Vec::new();
        let mut i = 0;
        loop {
            let b = &self.buf[i..];
            // an ID3v2 tag (some encoders send one first): 10-byte header, synchsafe size
            if self.locked.is_none() && b.len() >= 10 && &b[..3] == b"ID3" {
                let size = ((b[6] as usize & 0x7F) << 21) | ((b[7] as usize & 0x7F) << 14) | ((b[8] as usize & 0x7F) << 7) | (b[9] as usize & 0x7F);
                let total = 10 + size + if b[5] & 0x10 != 0 { 10 } else { 0 };
                if b.len() >= total {
                    i += total;
                    self.dropped += total as u64;
                } else {
                    self.skip = total - b.len();
                    self.dropped += b.len() as u64;
                    i = self.buf.len();
                }
                continue;
            }
            if b.len() < 4 {
                break;
            }
            match header(b) {
                Some(h) if self.locked.map_or(true, |l| l == (h.version, h.layer, h.rate)) => {
                    if b.len() < h.len {
                        break; // the rest of this frame hasn't arrived
                    }
                    // the next frame must start right after this one (a frame cut short shows here)
                    if b.len() < h.len + 4 {
                        break;
                    }
                    match header(&b[h.len..]) {
                        Some(n) if (n.version, n.layer, n.rate) == (h.version, h.layer, h.rate) => {
                            self.locked = Some((h.version, h.layer, h.rate));
                        }
                        _ => {
                            self.locked = None;
                            i += 1;
                            self.dropped += 1;
                            continue;
                        }
                    }
                    out.push(Frame { bytes: b[..h.len].to_vec(), samples: h.samples, rate: h.rate, kbps: h.kbps, channels: h.channels });
                    i += h.len;
                }
                _ => {
                    // lost the beat (or junk before the first frame): look for the next header again
                    self.locked = None;
                    i += 1;
                    self.dropped += 1;
                }
            }
        }
        self.buf.drain(..i);
        out
    }

    /// The stream ended: the last frame, if what is left is exactly one whole frame.
    pub fn finish(&mut self) -> Vec<Frame> {
        let b = std::mem::take(&mut self.buf);
        match header(&b) {
            Some(h) if self.locked == Some((h.version, h.layer, h.rate)) && b.len() == h.len => {
                vec![Frame { bytes: b, samples: h.samples, rate: h.rate, kbps: h.kbps, channels: h.channels }]
            }
            _ => {
                self.dropped += b.len() as u64;
                Vec::new()
            }
        }
    }

    /// The stream's sample rate once locked on.
    #[cfg(test)]
    pub fn rate(&self) -> Option<u32> {
        self.locked.map(|l| l.2)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// An MPEG-1 layer III frame at 44.1 kHz, `kbps`, stereo, with the body filled with `fill`.
    fn frame(kbps_index: u8, pad: bool, fill: u8) -> Vec<u8> {
        let b2 = (kbps_index << 4) | if pad { 2 } else { 0 };
        let h = [0xFF, 0xFB, b2, 0x00];
        let len = header(&h).unwrap().len;
        let mut f = h.to_vec();
        f.resize(len, fill);
        f
    }

    #[test]
    fn frame_lengths() {
        assert_eq!(header(&frame(9, false, 0)).unwrap().len, 417); // 128 kbps
        assert_eq!(header(&frame(9, true, 0)).unwrap().len, 418);
        assert_eq!(header(&frame(14, false, 0)).unwrap().len, 1044); // 320 kbps
        let m2 = [0xFF, 0xF3, 0x80, 0xC0]; // MPEG-2 layer III, 64 kbps, 22.05 kHz, mono
        let h = header(&m2).unwrap();
        assert_eq!((h.len, h.samples, h.rate, h.channels), (208, 576, 22050, 1));
        assert!(header(&[0xFF, 0xFB, 0xF0, 0]).is_none()); // bad bitrate
        assert!(header(&[0xFF, 0xFB, 0x9C, 0]).is_none()); // bad rate
    }

    #[test]
    fn whole_frames_from_any_chunks() {
        let mut stream = Vec::new();
        for i in 0..20 {
            stream.extend(frame(9, i % 3 == 0, i as u8));
        }
        for chunk in [1usize, 7, 100, 417, 5000] {
            let mut c = Cutter::new();
            let mut got = Vec::new();
            for part in stream.chunks(chunk) {
                got.extend(c.push(part));
            }
            // the last frame waits for the next header to be sure; all the others are out, in order
            assert!(got.len() >= 19, "chunk {chunk}: {} frames", got.len());
            let joined: Vec<u8> = got.iter().flat_map(|f| f.bytes.clone()).collect();
            assert_eq!(&stream[..joined.len()], &joined[..]);
            assert!(got.iter().all(|f| f.samples == 1152 && f.rate == 44100 && f.kbps == 128));
            assert_eq!(c.dropped, 0);
        }
    }

    #[test]
    fn junk_and_tags_are_dropped() {
        let mut s = b"junk\xFF\xFB\x00".to_vec(); // junk, including a false header start
        s.extend(b"ID3\x04\x00\x00\x00\x00\x00\x05hello");
        for i in 0..5 {
            s.extend(frame(11, false, i));
        }
        let mut c = Cutter::new();
        let mut got = c.push(&s);
        got.extend(c.finish());
        assert_eq!(got.len(), 5);
        assert_eq!(got[0].bytes[..2], [0xFF, 0xFB]);
        assert_eq!(got[0].kbps, 192);
        assert_eq!(c.rate(), Some(44100));
    }

    #[test]
    fn a_tag_split_over_chunks() {
        let mut s = b"ID3\x04\x00\x00\x00\x00\x01\x00".to_vec(); // 128-byte tag
        s.extend(vec![0u8; 128]);
        for i in 0..4 {
            s.extend(frame(9, false, i));
        }
        let mut c = Cutter::new();
        let mut got = c.push(&s[..20]);
        got.extend(c.push(&s[20..]));
        got.extend(c.finish());
        assert_eq!(got.len(), 4);
        assert_eq!(c.dropped, 138);
    }

    #[test]
    fn resyncs_after_a_broken_frame() {
        let mut s = Vec::new();
        for i in 0..4 {
            s.extend(frame(9, false, i));
        }
        s.extend(&frame(9, false, 9)[..100]); // cut short
        for i in 0..4 {
            s.extend(frame(9, false, i));
        }
        let mut c = Cutter::new();
        let mut got = c.push(&s);
        got.extend(c.finish());
        // the frame cut short is dropped whole; the others come through untouched
        assert_eq!(got.len(), 8);
        assert!(got.iter().all(|f| f.bytes.len() == 417 && f.bytes[4] != 9));
        assert_eq!(c.dropped, 100);
    }
}
