# Stationwagon design

Design reference for the three Stationwagon screens and their two skins. The mock-ups in
`design/skins/` are clickable HTML previews (no real audio). Open any of them straight in a
browser.

| Screen | Who uses it | Hi-fi skin | Green skin |
|---|---|---|---|
| Listener player | The public, and the embed on a church website | [hifi/player.html](skins/hifi/player.html) | [green/player.html](skins/green/player.html) |
| DJ studio | DJs and preachers going live (browser, Windows app or Android app) | [hifi/studio.html](skins/hifi/studio.html) | [green/studio.html](skins/green/studio.html) |
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

Every colour, font and shape comes from CSS custom properties on `:root`. Component CSS reads only
tokens.

**As built (round 32).** The tokens are the top of `app/assets/app.css`: green is plain `:root`, each
other skin is one `:root[data-skin="id"]` block, and `:root.light` (the embed's `?look=light`) lays
light colours over whichever skin is on, keeping its shapes and type. Beyond the tokens below there
are tokens for the in-between surfaces (`--lcd`, `--track`, `--raised-hover`, `--now-bg`,
`--live-bg`…), text on the accent and on air (`--on-accent`, `--on-onair`), corner sizes
(`--r-card` 16, `--r-box` 12, `--r-field` 10, `--r-chip` 8, `--r-small` 4, `--r-pill`), edges and
bevels (`--card-edge`, `--card-shadow`, `--btn-shadow`, `--well-shadow`, `--lcd-glow`), title case,
the fonts (`--f-ui`, `--f-body`, `--f-lcd`, `--f-mono`) and the canvases' colours (output strip,
spectrum, deck waves, the episode editor), which scripts read through `SwSkin.tok()`. The web test
fails if a colour or a corner size (other than 1–2 px and circles) is written in the components.

**The viewer picks the look, per device.** A "Look" menu sits in the top bar of every page; the
choice is kept in the browser (`localStorage` `sw.skin`, no cookie) and every screen of the
station follows it, including tabs already open. An inline script in `<head>` applies it before
the page draws. The embed has a small "Look" button: framed on another website, browsers keep its
storage apart, so its choice there is its own. The list of skins is `Skins::ALL`
(`app/src/Skins.php`); adding a skin is one token block and one line there.

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

### Song prep switches (round 34; not in the mock-ups)

The Music library's drop zone has a row of two switches (the `.sw` toggles) under its text: **Apply
pre-processing** *(mastering, as in Audition)* and **Loudness normalization** *(−16 LUFS)*, with a quiet
"Saved for every upload" after a change. Each upload row reads "Mastering 40 %", "Levelling", "Encoding"
while it works and ends "Added, mastered, levelled −9.1 LUFS → −16.0 LUFS (−6.9 dB)" or "Added, kept at
−21.1 LUFS". Mastered songs carry a **Mastered** pill next to **Levelled** in the library table.

Since round 40 the mastering switch names its preset (*mastering with “Warm night”*, with a "change in
Song prep" link), the Mastered pill's tooltip names the preset a song was made with, each row has a
**Song prep** button, and an upload row ends "· original kept" once the file the manager chose is saved.

### Loudness target (round 41; not in the mock-ups)

Under the two switches in the Music library's drop zone: **Loudness target −16.0 LUFS** (the value in
the mono face) with a plain line of help, a full-width range slider in the accent colour (−23 to −8 LUFS,
half-dB steps) and four small coloured marks under it at the usual levels, each in an existing token that
gets hotter as it gets louder: −16 `--cue` (Apple Music, podcasts), −14 the accent (Spotify, YouTube),
−11 `--warn` (loud, like FM radio), −9 `--onair` (very loud), named in a legend of dots beneath. A drag
snaps to a mark; the keys don't. The switch's label repeats the value. With normalization off the
whole block dims to half.

After a change, a raised panel: "8 songs were levelled to −16.0 LUFS; the station's target is now
−14.0 LUFS." with **Re-level 8 songs now** (primary) and **Only new uploads**, a line on what re-levelling
does, and the upload-row list for its progress ("Fetching the original", "Levelling", "Levelled −23.0 →
−14.0 LUFS (+9.0 dB)", or "On air: waiting until it isn't"). Chosen "Only new uploads", a quiet line with
a small **Re-level** button stays while any song is at another target. The Levelled pill's tooltip gives
the target and the gain ("from −23.0 LUFS").

### Song prep screen (round 40; not in the mock-ups)

**Manage → Song prep**, in the rail after Sound & safety (managers and admins). Built from the existing
tokens, cards, buttons and `.sw` switches; no new colours. Top to bottom:

- **A picker card**: Song, Preset (the import default marked ★ new uploads), Name, then the buttons Save,
  Save as new, Remove, ★ Use for new uploads and the primary **Apply to this song**. A warn-coloured "Changed,
  not saved yet." line while the settings differ from the preset, and one line saying where the preview
  comes from (the original, or the stored copy when its original wasn't kept, in the warn colour).
- **A player card**: Play, an **Original / Processed** pair (the pressed one in the accent; the A key
  switches, Space plays), the time, a quiet "Processing 40 %" while pieces render, and Volume. Under it the
  whole song as a 72 px strip (original grey, processed in the accent where it's up to date; click to play
  from there), then the enlarged wave around the playhead, 240 px high, 4–30 s wide, with dB lines and
  a white playhead, both versions at the level the station plays them. A numbers table (loudness, loudness
  range, peak, level on import; Original and Processed rows) beside six gain-reduction meters (Low, Low
  mid, High mid, High, MB limiter, Limiter) showing the most reduction of the last 80 ms.
- **Two cards side by side** (stacked on phones): **EQ** with its switch in the heading, the high-pass
  switch, the curve (20 Hz–20 kHz, ±15 dB; round handles L, 1, 2, 3, H that drag, the wheel changing a
  bell's width) and a row of number fields per band; **Dynamics** with its switch, four sliders (Amount,
  Punch, Loudness, Ceiling, each with a line of plain help) and a closed **All the settings** with the
  Finish switch and grouped fieldsets (crossovers, the four bands, timing, the multiband limiter, Finish).

Confirmations (Remove, Apply) use the browser's own dialog. Messages land in one polite live line under
the picker buttons, in the bad colour when something failed.

### Windows app (round 33; not in the mock-ups)

The app's main window is the station's own studio page. Its own settings page (`desktop/ui/index.html`,
green tokens, one column) has two cards: **Your station** (the address, Save and open the studio, Check
for updates) and **BUTT and Mixxx** (a lamp and what is sending, the address, port, mount, user, password
and format to type into BUTT or Mixxx, Copy the password, New password, and folded how-tos). When the app
opens it shows "Checking for updates…", then the **update window** when a newer version is out (see below), or
"Opening the studio…". In the
studio's status strip the app adds **BUTT / Mixxx** with its state ("waiting on 127.0.0.1:8000", "BUTT
sending · MP3 128 kbps") and an **App settings** button; a Windows browser gets **Get the Windows app**
there instead.

### The update window (round 58; both apps; modelled on the KDOHC app's dialog)

`update-dialog.js` (the same file in `desktop/ui` and `mobile/www`) draws a modal card over a dimmed page:
a header in the accent colour ("Version X is now available", "You have version Y." at 80 % opacity), the
release's notes (bold headings with a gap above, **Warning** in the warn colour with a triangle sign, `•`
bullets, other lines muted), a thin bordered box with what happens next (Windows, bold in the warn colour:
the app closes while it updates and opens again; Android, muted: the phone asks to confirm), and a footer
with a muted text button **Remind me later** and the accent button **Update now** (a turning arrow and
"Downloading n%" while busy; **Try again** after an error, which shows in bold in the bad colour).
Remind me later swaps the card for a short notice ("No problem, you can update later.", where App settings
is) with **Open App settings** and **OK**. Windows: dim 50 %, card up to 512 px, 18 px title; Android: dim
90 %, 448 px, 16 px title and taller, bolder buttons. Colours, corners, bevels and the font are the look
picked in the studio, which the studio page passes to the app (Hi-fi gives an amber header and square
corners); the app's own start page uses the same look.

### Android app (round 35; not in the mock-ups)

The same idea on Android (`mobile/`, Capacitor): the app's main screen is the station's own studio page.
Its own start page (`mobile/www/index.html`, green tokens, one column, 44 px buttons, safe-area padding)
has three cards: **Your station** (the address, Save and open the studio), **On air on this phone** (a lamp
and "Do Not Disturb: allowed / not allowed yet", what the app does when you go live, and **Allow Do Not
Disturb**, which opens Android's own screen) and **Updates** (Check for updates, which opens the update window, and a lamp with "Opening again after an update: allowed / not allowed yet", **Allow display over other apps**, and on Xiaomi phones **Allow Autostart**, each opening Android's own list, where the DJ switches Stationwagon Studio on).
Opening the app shows "Checking for updates…", the update window when a newer version is out, or "Opening the studio…".
When the station can't be reached the app shows its own **offline page** ("The station can't be reached",
Try again, Change the station). In the studio, once switched on, the banner explains Do Not Disturb: with
access, that it turns on at Go live (alarms still ring) and back at End show; without, the same with **Allow
Do Not Disturb** and Not now. While on air an **"You're on air"** notification shows (it opens the studio),
Back asks "Close the app?" as the Windows app's close does, and **App settings** (status strip) is refused
until the show ends. An Android browser gets **Get the Android app** in the status strip. The launcher icon is
the Windows icon's on-air sign on the station green (an adaptive icon); the splash is the icon on light ground.

### Setup in WordPress (round 37; not in the mock-ups)

The plugin's screens use WordPress's own admin look with the station green as the accent (light only). **Stationwagon**
(microphone icon) in the admin menu. Before setup: a one-line lead, the server check (only problems shown, red for
required and amber for advice, else "This server is ready"), then numbered cards, each a set of choice tiles (the
picked one green, a **Recommended** or **cPanel login** tag): 1 where the station lives (with the folder or subdomain
name typed into the example address), 2 the database, 3 background jobs, 4 the cPanel login (shown only when a choice
needs it; the last card's number follows), 5 the station and the first admin. **Set up my station** (big primary
button), "Setting up… this takes about a minute." After setup: the done steps with ticks, then a green-edged card with
the station's address and **Open the manager**, **Open the studio**, **The station page**; an amber card with the DNS
record and **Check again** while a subdomain doesn't answer; cards for the shortcode, the background jobs (with the cron
line to copy when chosen) and the details. The station's Overview gains a **Background jobs** health line.

### Setup without WordPress (round 38; not in the mock-ups)

`setup.php` is one self-contained light page in the station green (system font, no files of its own): the wordmark
"stationwagon setup", a one-line lead, the server check card (every row with a dot: green, red for required, amber
for advice, with what to change in cPanel under a failed row), then one card **Where should the station live?** with
choice tiles like the plugin's (**A subfolder** tagged **Recommended**, its name typed into the example address;
**This folder**; **Its own subdomain** tagged **cPanel login**), the cPanel login card only for a subdomain, and
**Download and set up** ("Downloading and unpacking… about a minute."). When the station can't be reached yet: the
done steps with ticks and an amber card with the A record (or, rarely, "the web server is still catching up") and
the installer's link with its key. The station's own installer (`/install`) uses the app's look: the server check,
the setup key ("Filled in by setup.php."), then numbered cards with the same choice tiles in the station's amber
accent: 1 Database, 2 Background jobs, 3 Your cPanel login (only when needed; the last card's number follows),
4 Station and your sign-in; **Set up my station**. Its done page lists the steps with ticks and, for the cron line to
paste, a **Your cron job** card.

### Remembering the password (round 36; not in the mock-ups)

The sign-in page has a **Keep me signed in on this device for 30 days** tick box under the password
(the manager's switch row, off at first). Saving the password is left to each place's own password
manager, so its look is theirs: the browser's; in the Windows app Edge's **"Save your password?"** bubble
(top right of the window) and its filled-in fields; in the Android app Google's sheets, **"Save password to
sign in to Stationwagon Studio?"** after signing in and **"Use your saved password"** on the sign-in page,
which fills the form in and leaves the DJ to press Sign in. A wrong password in the Android app shows the
sign-in page's own message, not the offline page.

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
  Schedule, DJs, Sound & safety, Song prep (round 40), Listeners, Podcast.
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
