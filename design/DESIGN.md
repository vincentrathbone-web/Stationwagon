# Stationwagon design

Design reference for the three Stationwagon screens and their two skins. The mock-ups in
`design/skins/` are clickable HTML previews (no real audio). Open any of them straight in a
browser.

| Screen | Who uses it | Hi-fi skin | Green skin |
|---|---|---|---|
| Listener player | The public, and the embed on a church website | [hifi/player.html](skins/hifi/player.html) | [green/player.html](skins/green/player.html) |
| DJ studio | DJs and preachers going live (browser or Windows app) | [hifi/studio.html](skins/hifi/studio.html) | [green/studio.html](skins/green/studio.html) |
| Station manager | The station admin | [hifi/manager.html](skins/hifi/manager.html) | [green/manager.html](skins/green/manager.html) |

Published previews (private links, share from each page's Share menu):

- Hi-fi: [player](https://claude.ai/artifact/9vPMTeSZVDjgU3HaGKcjGU) ·
  [studio](https://claude.ai/artifact/BLzkA52kEozDMxttoJAg2m) ·
  [manager](https://claude.ai/artifact/NeuMPpuuQxvEAmBcF3cd5h)
- Green: [player](https://claude.ai/artifact/CHULBMqMWbg25LjTcovES8) ·
  [studio](https://claude.ai/artifact/BntgUDVB3s69T6CMZYgzsz) ·
  [manager](https://claude.ai/artifact/QxYADjUAtv1qbaQVugHuCq)

## Principles

1. **Simpler than AzuraCast.** Every screen answers "what is on air, and what needs me?" first.
   Settings use plain words ("Show song titles", not "Jingle Mode").
2. **A classic audio player at heart.** Transport buttons, a display, a volume slider and a
   playlist, the way Winamp taught everyone.
3. **State is visible at a glance.** On air is always red. AutoDJ, live, rejoining and warnings
   each have their own lamp, pill or colour, never just a number.
4. **One structure, many skins.** Skins only change tokens (colour, type, shape). Layout, copy and
   behaviour stay the same, so a skin is a stylesheet and nothing more.
5. **Dark by design.** Both skins are deliberately dark (studio lighting, night listening). A
   light skin can be added later through the same tokens.

## Skin system

Every colour, font and shape comes from CSS custom properties on `:root`. The real app sets
`data-skin="hifi"` or `data-skin="green"` on `<html>` and loads one token block per skin.
Component CSS reads only tokens.

### Tokens

| Token | Role | Hi-fi | Green |
|---|---|---|---|
| `--ground` | Page background | `#141311` | `#121212` |
| `--panel` | Windows and cards | `#1f1d1a` | `#181818` |
| `--raised` | Buttons, inner modules | `#2a2723` | `#242424` |
| `--edge` | Hairlines and dividers | `#3a3630` | `#2e2e2e` |
| `--ink` | Main text | `#ece6da` | `#ffffff` |
| `--muted` | Secondary text | `#a49c8d` | `#b3b3b3` |
| `--faint` | Hints, captions | `#70695e` | `#7c7c7c` |
| `--lcd` | Displays and wells | `#0d0c09` | `#1f1f1f` |
| `--amber` | Accent (name kept for both skins) | `#ffb24a` | `#1ed760` |
| `--amber-soft` | Accent, softer | `#c98a3a` | `#1db954` |
| `--amber-dim` | Accent, dim (unplayed waveform, idle lamps) | `#5c4220` | `#1f5133` |
| `--onair` | On air, live, recording | `#ff5442` | `#f15e6c` |
| `--ok` | Healthy | `#6fd398` | `#1ed760` |
| `--warn` | Needs attention | `#f2c14e` | `#ffa42b` |
| `--cue` | Headphone cue, talk segments | `#7cc4ff` | `#509bf5` |

Rename `--amber*` to `--accent*` when this moves into the real codebase.

### Type

| Role | Hi-fi | Green |
|---|---|---|
| UI labels, headings (`--f-ui`) | Barlow Semi Condensed 500–700, labels uppercase with 0.08–0.16em tracking | Figtree 600–800, sentence case, tight tracking on headings |
| Body (`--f-body`) | Barlow 400–600 | Figtree 400–600 |
| Displays and numbers (`--f-lcd`) | Share Tech Mono, amber glow | Figtree with tabular numbers, no glow |
| Code (`--f-mono`) | Share Tech Mono | DM Mono |

All digits that line up in columns use `font-variant-numeric: tabular-nums`.

### Shape

| Element | Hi-fi | Green |
|---|---|---|
| Windows and cards | 3px radius, 1px black border, bevel (light top-left, dark bottom-right) | 16px radius, no border, lifts on hover |
| Buttons | 2px radius, bevelled, pressed state sinks | Pill (999px), grows 3% on hover; primary is accent with black text |
| Displays | Sunken well, amber glow | 12px rounded well, flat |
| Sliders | Sunken track, square bevelled thumb | 5px pill track, round white thumb |
| Title bars | Uppercase label between grip lines | Plain bold title, no grips |
| Toasts | Bevelled chip | White pill with shadow |

## Screens

### Listener player

- **Main window:** state glyph and elapsed time, the song title (scrolls when long), quality
  (192 kbps · 44 kHz · stereo), spectrum, mode line (AutoDJ + playlist, or Live + DJ name), and
  "12 s behind live".
- **Progress bar** with the crossfade zone shaded at the end of the song.
- **Transport:** Listen/Pause, Stop, volume. No skip, because it's radio.
- **Health line:** connection, dropouts, listener count.
- **Playlist window:** recent (dimmed), now (highlighted), next three. During a live show a red
  "LIVE" row sits on top.
- **Side column:** station name, **From the presenter** notes, today's schedule with an "On now"
  marker, embed code with Copy.
- **From the presenter:** notes the DJ posts from the studio during a show, newest first, each
  with the time it was posted. Kinds: verse (reference, version, text, "Read … ↗" link to the
  passage, Copy verse), references (pill links to each passage), video (title + "Open video ↗")
  and a plain note. During a live show the panel says "Live" and new notes arrive with a "New"
  tag and a toast; between shows it keeps the last live show's notes ("This morning").
  Links are real `<a href>` links that open in a new tab. The studio needs a matching notes
  editor (not designed yet).
- **States to support:** AutoDJ, Live, Rejoining (soft fade out, then fade back in).

### DJ studio

- **Top bar:** show name and DJ, show progress bar, time left, station clock, **Go live** button
  (grey → amber "Joining…" → red "On air 0:12").
- **Banner** for the handover ("Deck A picks up *song* at 2:14, exactly where the AutoDJ is") and
  for the end-show confirmation (in the page, never a browser dialog).
- **Decks A and B:** title, artist, remaining time, source ("From AutoDJ", "Running order item 3"),
  clickable waveform with playhead and crossfade zone, Cue (headphones), Play, Stop, volume.
- **Mixer:** L/R/MIC meters, crossfader, "Fade to B · 6 s", "Skip · 2 s fade", **Mic on air**
  (latching), ducking readout ("Music ducked −12 dB while you speak").
- **Microphone strip:** input device, preset (Preacher, Music show, Interview), modules each with
  an on/off switch: Input gain, Gate (threshold, release, open/closed lamp), Compressor
  (threshold, ratio, gain-reduction meter), EQ (low cut, presence), Limiter (ceiling), Ducking
  (depth, release).
- **Running order:** planned clock time, kind (♪ song, MIC talk, ID jingle), name, length, load
  to A/B; a fill bar of the 30-minute slot and "AutoDJ fills the last 2:50".
- **Status strip:** piece size and last upload, listener delay, recording, headphone device,
  connection.

### Station manager

- **Header:** station name and a live "on air" readout.
- **Side rail** (becomes a scrolling tab row on phones): Overview, Music library, Playlists,
  Schedule, DJs, Sound & safety.
- **Overview:** on air now with progress and next song; listeners, next live show, "planned
  until"; **Needs attention** (hidden song titles, duplicates); **Health** (timeline planned,
  storage, live input).
- **Music library:** drop zone ("checked, levelled and given short fades in your browser before
  upload"), search, playlist filter, table, duplicate warnings.
- **Playlists:** songs, length, weight, **Show song titles** switch.
- **Schedule:** week grid 06:00–24:00, live blocks (red edge) and AutoDJ blocks (accent edge),
  station time zone.
- **DJs:** name, username, when they can go live, last on air, Reset password (passwords are never
  shown).
- **Sound & safety:** crossfade length and fade shape with a live curve preview, skip fade, live
  cut-in fade, music under the voice, back-to-AutoDJ timeout, silence detection, rejoin window,
  sound quality (with MB per hour), piece length (with resulting delay).

## Copy rules

- Name things by what people recognise: "Show song titles", "Back to AutoDJ if a DJ drops".
- Buttons say exactly what happens: "Go live", "End show", "Copy code". A toast confirms
  ("Copied", "Saved. New settings apply from the next song.").
- Confirmations happen inside the page, never in `alert`/`confirm` dialogs.
- Example data is marked as example ("Example address").

## Recreating the green skin

`design/skins/make_green_skin.py` generated the green files from the hi-fi files: it swaps the
token block, fonts and literal colours, then appends the shape overrides. It expects the
original file names (`wordwave-player.html` and so on), so rename the hi-fi files first if you
run it again. In the real app the same result comes from a second token block, not a script.
