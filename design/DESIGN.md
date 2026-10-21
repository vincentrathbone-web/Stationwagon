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
  **Spectrum as built (round 31):** 28 bars, 2 px apart, across the display, 46 px high; each bar
  is 2 px segments every 3 px (light green in the top 30 %, the accent to 60 %, darker below) and a
  1.5 px peak mark that holds 0.6 s and falls. Bands are log-spaced from 40 Hz to 16 kHz, so music
  fills the left two thirds. It shows the station's sound (the listener's volume doesn't change it),
  falls to nothing on Stop, redraws once a second with reduced motion (no peak marks), and stays
  empty in stream-only mode.
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
  Links are real `<a href>` links that open in a new tab. The studio posts them from its
  **notes drawer** (below, under DJ studio).
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
- **Notes drawer** (chosen by the user on 2026-10-20; built in the app, not in the mock-ups): a
  **Notes** button with a count sits in the top bar beside Go live. It opens a drawer from the right
  side, over the right of the desk (420 px; full width on a phone); Close or Esc shuts it.
  Inside: "Notes for listeners", a hint ("Go live to post notes…" until on air), four kind tabs
  (Verse, References, Video, Note), one small form per kind (Verse: reference, version with KJV
  filled in and a list of common codes, the pasted text; References: an optional intro, one
  reference per line, version; Video: title and address; Note: text), **Post note** with "Listeners
  see it 20 s after you post, when they hear this moment", and the show's posted notes, newest
  first, each with its time, kind, a short summary, "Listeners see it at 14:05:32" (amber) turning
  into "Showing to listeners" (green), and Remove, which asks "Remove this note?" in the row first.
  An error ("Write the note first.") shows above Post note.

### DJ studio as built (round 21)

The app's `/studio` follows the green mock-up, with these additions and differences:

- **Switching on:** the banner first offers **Switch on the studio** (sound and the microphone need
  a click, and the browser asks for the mic); "Wear headphones, so your speakers don't feed back into
  the mic." Until then the decks, mixer and Go live are greyed out.
- **Go live** is grey and off with the reason in the banner when the person may not go live ("Your
  next show, Evening praise, starts today at 19:00. You can go live from 18:55." or "You have no live
  show in the schedule. Ask a manager to add one for you."). Pressed, it turns amber "Joining…" at
  once, then red "On air 0:12"; pressed on air it asks "End the show?" in the banner.
- **Top bar:** the show is the live block's title and DJ, the bar fills across the block, and the
  meta line shows the block's times (or "All day") and the time left; outside a block a manager's
  show is "Live with <name>".
- **Decks** show "Empty", "Loading…", "Ready" or "Playing"; Deck A says it picks up the AutoDJ song
  when you go live. A deck that is on air can't be reloaded until you fade across or press Stop.
- **Running order** can be edited by its DJ (or a manager): a library search with A / B / + Song /
  + ID on each result, "Add talk" with a name and minutes, ↑ ↓ ✕ on each row, "Saving…" / "Saved"
  in the title bar. The fill bar is the block's length; "AutoDJ fills the last …" or "Over by …".
- **Status strip** shows "Waiting to send" and the connection as "Not sending", "Joining",
  "Connection good", "Connection slow" or "Connection trouble: …". Recording is not built yet.
- **Recording (round 29):** the status strip says "Records your shows", then "● Recording 12:34" on
  air, "Uploading recording 40%" after End show, "Recording waiting to upload" or "Recording trouble:
  …". After the upload the banner says "Your show’s recording is uploaded. Trim it and publish it as a
  podcast episode." with **Edit the episode**. On a phone or tablet, switching on adds "Turn on Do Not
  Disturb (Focus on an iPhone or iPad) and wear headphones, so notification sounds can’t reach your
  mic." Leaving the page while an upload runs asks first.

- **Small items (round 30):** the status strip's **Headphones** is a menu of the computer's sound
  outputs ("System default (…)" first) when there is more than one, else the output's name;
  everything the DJ hears goes there. A posted note has **Edit** beside Remove: the note's form
  fills in (its kind stays; the other kind tabs are greyed), the button says **Save changes**, a
  **Cancel** appears, the hint says "Listeners get the changed note as new, 20 s after you save.",
  and the note being changed has an outline. In the running order a talk item has a **Now** button
  instead of A / B (gone while it is now); opening the mic marks the next talk item as now.

### Podcast (round 29; not in the mock-ups)

- **Episode editor** (`/manage/podcast/<id>`, the green manager frame): a toolbar (− and + zoom, also
  Ctrl + wheel; **Whole show**; **Delete clip**; **Undo**; what is selected), then a canvas with a time
  ruler and one row per track: **Music** and **Mic** for a studio recording, **As aired** otherwise (an
  aired part of a two-track episode spans both rows). Clips are rounded blocks of the track's peaks,
  split at every silence of 2 s or more; a selected clip has an outline, a cut clip is struck through and
  greyed. A line under it explains ("12 clips. Click a clip, then Delete to cut it."), including when a
  part is as aired and why. **Mix down** with a progress bar and "about N min left", then a player for
  the mix. Title and description fields, **Save**, **Publish** (after a mixdown that matches the cuts)
  and **Hide** once published.
- **Podcast screen** (`/manage/podcast`): the episodes newest first with their state (draft,
  mixing, published, hidden, skipped), length and an Edit link; DJs see their own, managers all. For
  managers, "The podcast": title, description, author, language, category, explicit, a cover image,
  the feed address with **Copy**.
- **Public page** (`/podcast`): the cover, title and description, the feed link, and the published
  episodes with a player each; linked from the station page ("Podcast: past shows"). The RSS feed has
  the iTunes tags.

### Output strip (player and studio, round 22)

A scrolling picture of **what goes out** (the wet mix, never the inputs), settled with the user on
2026-10-20 from the prototype at https://claude.ai/artifact/7B369bBNhrMkKLBLrv1Ssa. Built once in
`app/assets/outwave.js` and used by both screens.

- **Now is a white line in the middle**; the sound moves right to left past it.
- **One colour per source**, stacked and mirrored around the middle: Deck A / song A blue
  (`--src-a` `#4da3ff`), Deck B / song B pink (`--src-b` `#ff5fa8`), the mic (studio) or the live
  show (player) yellow (`--src-live` `#f7d23e`). The output itself is a thin white line
  (`--src-out`).
- **Left of now is measured** in the sound engine (an AudioWorklet meter, 10 ms peaks), after the
  crossfades, ducking and every other gain stage. **Right of now, dimmed, is the coming output**,
  mixed ahead from audio the page already holds: the player reads the next 20 s itself (song pieces
  as byte ranges, from the browser's cache, and the live pieces hls.js has loaded) and mixes them
  through the fades it will play; the studio mixes its decks' songs through the crossfader and the
  ducking as it is now (future speech can't be known).
- **Level lines**, barely visible on purpose: −1 dB (dashed amber at 35 %: song prep's peak ceiling),
  −6, −12 and −20 dB (white at 10 %), labelled on the left. Linear height.
- **Events** are short ticks at the top and bottom edges in the source's colour (a song starting,
  a fade starting and ending, the mic going on or off).
- **Player:** 60 px high, the width of the player window (between the display and the progress
  bar), 10 s shown (5 s either side of now).
- **Studio:** above the decks, full width, 50 px high to start; a drag handle under it (or the arrow
  keys) sets 30–240 px, and a small round toggle on the left hides it (then it reads "Going out").
  Both are remembered on that computer. 20 s shown. Deck titles and the MIC meter carry a small
  square in their colour, as the legend.

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
  **As built (round 25):** four cards. *Transitions*: crossfade (0–12 s), fade shape (equal power,
  linear or S-curve, with a small curve drawn by `assets/fades.js`, the same code the player and the
  studio fade with), skip fade, live cut-in fade. *Live shows*: music under the voice (−24 to 0 dB, the
  starting depth of every studio mic preset), back to AutoDJ (at least one piece length + 2 s), DJ
  rejoin window (0–30 min; the studio offers "Rejoin your show"). *Songs*: move on when a song goes
  quiet (off, −50, −40 or −30 dB; song prep stores each song's last minute, second by second, and the
  planner starts the next song where the music stays below the level to the end), songs not to
  repeat. *Sound quality*: live shows and prepared songs (96–256 kbps, each with its MB an hour), and
  the listener delay shown but not editable ("About 20 s", from the 6 s pieces), since songs on air
  are already cut into pieces of that length.
- **Listeners** (as built, rounds 27–28; not in the mock-ups): six stat tiles (listening now, most at
  once today, hours listened today, listens today, unique listeners today and this month), then two cards with a column chart each: the
  last 48 hours (the most listeners at once in each hour; labels at 00, 06, 12 and 18, midnight by
  its day) and the last 30 days (hours listened per day). One colour (`--chart`), 4 px rounded tops,
  hairline grid, a tooltip per column on hover or focus, and "Show as a table" under each. On a
  phone the tiles go two by two and the charts scroll inside their cards, starting at the newest
  end. The overview's On air card shows "Listening now" and "Most at once today" as two tiles, as
  in the mock-up; the player's health line shows "Listening now".

## Copy rules

  Round 28 adds a third chart card (unique listeners per day, "By month" as its table), "Who listens,
  the last 30 days" (six small lists: where, device, browser, app, country, city; each a name, its
  share of the hours listened and a thin bar in `--chart`, top 6 then "Others (n)"), the DB-IP credit
  line, and a Privacy card with the contact address field.
- **Privacy page** (`/privacy`, round 28): plain sections in the site layout (what is kept when you
  listen, why, how long, who can see it, your rights, who to ask), 680 px wide. Linked by a quiet line
  under the player ("Listening sets no cookies… Privacy") and a small "Privacy" link in the embed
  (under "Open the player", or top right when the embed is narrower than 380 px).
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
