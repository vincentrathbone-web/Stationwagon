# Research: how the player and the strip should get their song data

2026-11-25, round 87. The question: the player's output strip (the moving waveform) sometimes downloads
a song a second time. The user asked how other servers and apps deliver audio, whether anyone else shows
a moving waveform of a streaming song, and whether P2P or a format of our own could solve it.

## The problem in one paragraph

Each song plays on a deck, a plain browser audio player that downloads the whole song file itself.
The strip also needs the song, about 20 s ahead of what is heard, to draw it and to spot sound gaps.
It asks for 6-second pieces of the same file. Chrome often pauses the deck's download partway
(normal buffering) but keeps it open, and while it is open Chrome won't share that file from its cache
with a request for part of it. The strip's piece then comes over the internet again: up to twice the
data for that song (seen in about 1 live test run in 10).

## What others do

| Who | How the sound reaches the listener | Moving waveform of what is playing? |
|---|---|---|
| **Icecast** (and Shoutcast) | One never-ending download of the station's mixed output. On connect the server sends a burst (64 KB by default) to fill the player's buffer quickly; a listener who falls too far behind is dropped. The listener's app does nothing clever. | No. The listener gets the finished mix; there is nothing to look ahead at. |
| **MPD** (its built-in HTTP output) | The same as Icecast: MPD mixes on the server and sends one endless stream to each listener (a 32 KB buffer on the server side). | No. |
| **YouTube / YouTube Music** | The page downloads the audio in **small chunks itself** and feeds them to the browser's player (Media Source). Earlier each chunk was a range request (`&range=start-end`); today it is YouTube's own binary protocol (SABR/UMP), one request per batch of chunks. | No moving waveform; YouTube Music shows a progress bar. |
| **TuneIn** | Plays each station's own stream (mostly Icecast/Shoutcast, some HLS). I found nothing public about its web player's internals. | No. |
| **BBC Sounds** (a big radio app) | Dropped Icecast-style streams in the UK; uses **HLS and DASH**: the station is cut into small numbered files the app fetches one after another. | No. |
| **Radioplayer** (UK stations' app) | Uses each station's Icecast or HLS stream, picking the best one for the browser. | No. |
| **iHeartRadio** | Nothing public found. | No. |
| **Navidrome / Subsonic** (music servers) | The player asks for the song file (often converted on the fly) and the browser downloads it like ours, with range requests for seeking. Known trouble: range and cache handling behind proxies and CDNs. | No moving waveform. |
| **SoundCloud** (the best-known waveform) | Plays the file normally. **The waveform is made once on the server when the track is uploaded** and sent as a small separate file (a picture, and behind it a list of numbers). The player never decodes the audio to draw it. The BBC's open-source `audiowaveform` tool and `waveform-data.js` work the same way. | Yes, but drawn from **server-made data**, not from the audio being played. |
| **"streamerp2p"** | I couldn't find a product or project by this name. The nearest are early-stage P2P streaming projects. | — |

**What stands out:** none of the big players does what our strip does (decoding the coming seconds
of the real audio in the listener's browser). Radio apps send a finished stream and show nothing ahead.
The one well-known moving waveform, SoundCloud's, is drawn from **small data the server made once**.

## Could P2P solve it?

P2P (listeners passing pieces to each other, e.g. P2P Media Loader with hls.js, over WebRTC) cuts the
**station's** bandwidth when many people listen at once. It does **not** cut the listener's data: each
listener still downloads every piece (from a peer instead of the server) and also **uploads** pieces to
others, which costs mobile data. It needs Media Source to play and only shares one track. It would make
our problem worse for listeners, not better. **Not a fit.**

## Could a format of our own solve it?

- **For the sound itself: no.** Whatever we send, the browser must decode it. A page can only play a
  format the browser knows, or decode it in our own code (WebCodecs `AudioDecoder`, on iPhones only
  since iOS 26; or our own decoder in WebAssembly). Playing that ourselves gives up the browser's own
  audio player, which is what keeps sound going on a locked phone. That's a big risk for little gain.
- **For the strip's data: yes, and that's the SoundCloud idea.** A tiny file of our own per song:
  the peak level of every 10 ms (what the strip already draws, one byte each). A 4-minute song is about
  **24 KB** instead of re-reading 8 MB of audio. Every device can read it (it's just numbers), and it
  never touches the song download, so nothing is ever locked or downloaded twice.

## The options

1. **Server-made waveform data (recommended).** When a song is prepared, the server also saves its
   10 ms peaks (one small file per song). The timeline hands it out like the pieces list today, and the
   strip mixes ahead from those numbers instead of decoding audio. The decks stay exactly as they are.
   - Good: never a second download on any device; about 300 times less data for the strip; no change to
     playback, so locked phones are not at risk; slow phones save the decoding work too.
   - Cost: every song in the library needs its peaks made once (a background job); during a crossfade
     the "coming output" line is an estimate from two peak lists (within about 1 dB, the test's own
     margin), where today it is an exact mix. Live shows keep today's way (their pieces arrive as they
     are made). About a round of work.
2. **The pieces idea (Media Source).** The page downloads 6-second pieces itself and feeds them to both
   the deck and the strip, as YouTube does.
   - Good: one download for both, and full control over what is fetched.
   - Cost: rewrites the heart of the player (decks, crossfades, timing, rejoining); on a locked phone the
     page must keep fetching, and some phones (the Xiaomi seen earlier) freeze pages; iPhones before
     iOS 17.1 have no Media Source. Several rounds, with careful phone testing.
3. **Undo and accept.** Back to how it was; the occasional double download stays as a known limit.

## The decision (2026-11-25)

The user chose **option 1, server-made waveform data**, with two more choices:

- **The white line in a crossfade** is the power sum of the songs' peaks (the square root of the sum
  of their squares), the way two unrelated sounds add up. Each song's own colour is exact: its peaks
  times its fade curve (the station's fade shape over the crossfade length from the settings).
- **Stations without Node** have the manager's browser make the peaks while the Music library page
  is open.

How it was built (round 87): `src/Peaks.php` makes the missing peaks for every song file in use,
whatever brought it in (Node in `cli/prep.php`, every minute, and started by the background jobs; or
`assets/peaks-fill.js` in the browser). The format is in `assets/prep-core.js` (`SwPrepCore.peaks`).
The player reads them at `peaks/<song file>.bin` (`api/peaks.php`, cached for a year), and
`assets/outwave.js` mixes ahead from them. A song without peaks yet is drawn from its audio as before.

## Sources

- Icecast burst and queue: [burst-size](https://lists.xiph.org/pipermail/icecast/2022-August/015813.html),
  [slow listeners](https://lists.xiph.org/pipermail/icecast/2024-February/016104.html),
  [all about buffers](https://mcfiredrill.github.io/blog/all-about-buffers)
- MPD httpd output: [source](https://git.pvv.ntnu.no/oysteikt/mpd/src/commit/46528783ef07d1180d7059f3916d277a2f9a0c31/src/output/httpd_output_plugin.c),
  [forced flush](https://git.pvv.ntnu.no/oysteikt/mpd/commit/a77506ae215df09ed02c30f39a3be663a3180d5b)
- YouTube: [VLC issue on `range=`](https://code.videolan.org/videolan/vlc/-/issues/27924),
  [SABR](https://priveetee.github.io/Docs-PipePipe/extractor/sabr.html), [googlevideo](https://unpkg.com/googlevideo@3.0.0/README.md),
  [MSE basics](https://web.dev/articles/media-mse-basics)
- Radio: [Radioplayer stream types](https://support.radioplayer.co.uk/support/solutions/articles/48000984948-stream-types-supported-formats),
  [BBC moves to HLS/DASH](https://www.whathifi.com/news/listeners-complain-about-changes-to-bbc-internet-radio-streaming),
  [Radio World](https://www.radioworld.com/?p=20946), [HLS for audio](https://www.radiomast.io/blog/what-is-hls-for-audio.html),
  [TuneIn](https://Www.Wikipedia.org/wiki/TuneIn)
- Navidrome / Subsonic: [Navidrome overview](https://www.navidrome.org/docs/overview/.),
  [Subsonic API compatibility](https://www.navidrome.org/docs/developers/subsonic-api),
  [range and seeking](https://support.symfonium.app/t/optional-server-controlled-streaming-seek-mode/11869),
  [OpenSubsonic stream](https://opensubsonic.netlify.app/docs/endpoints/stream)
- Waveforms: [SoundCloud blog](https://developers.soundcloud.com/blog/waveforms-let-s-talk-about-them),
  [BBC waveform-data.js](https://github.com/bbc/waveform-data.js)
- P2P: [P2P Media Loader](https://unpkg.com/p2p-media-loader-core@3.0.1/README.md),
  [decentralized p2p streaming](https://github.com/giangndm/decentralized-p2p-streaming)
- Decoding and Media Source on phones: [AudioDecoder support](https://caniuse.com/mdn-api_audiodecoder),
  [Safari 26 notes](https://developer.apple.com/documentation/safari-release-notes/safari-26-release-notes.md),
  [Managed Media Source in Safari 17.1](https://webkit.org/blog/14735/)
