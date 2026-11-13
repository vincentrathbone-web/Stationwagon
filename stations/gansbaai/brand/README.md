# Gansbaai Community Radio brand files

Open [`index.html`](index.html) in a browser for a visual overview of every file here, with a preview of the home page hero and its video loop at the top.

The logo is **Swell**: nine sound bars that rise and fall like the sea, with a line of white foam running through them. It is radio and ocean in one shape, modern and bright. The name is set in two lines: **gansbaai** (lower case) and **COMMUNITY RADIO**, the second as wide as the first.

Every logo comes in two formats:

- **`svg/`**: editable vector files. All lettering is outlines, so no font has to be installed. They scale to any size.
- **`png/`**: ready-to-use images with a **transparent background** (sizes below).

## Which one do I use?

| Need | File |
|------|------|
| Main logo, dark background or over the video (midnight sea is best) | `gbcr-logo-horizontal` |
| Main logo, white or light background | `gbcr-logo-horizontal-light-bg` |
| Square space (hero, poster, profile picture with room) | `gbcr-logo-stacked` / `-light-bg` |
| Just the Swell mark | `gbcr-mark` (dark bg), `gbcr-mark-light-bg` (light bg) |
| Just the name | `gbcr-wordmark` / `-light-bg` |
| One-colour printing, stamps, embroidery, photos | `*-white` (on dark) or `*-black` (on light) |
| App icon, social media avatar | `gbcr-app-icon` (midnight tile) or `gbcr-app-icon-teal` (turquoise tile) |
| Browser tab icon | `gbcr-favicon` / `favicon.ico` (five heavier bars, so it stays clear at 16 px) |
| iPhone home screen | `png/apple-touch-icon-180.png` |

The files with plain names are drawn for **dark** backgrounds: the bars run from neon surf to boat blue, the foam is white, the name is white. The `-light-bg` ones run from turquoise to deep blue with a midnight foam line and midnight lettering, so they stay readable on white.

## PNG sizes

| Group | Width |
|-------|-------|
| Marks | 2000 |
| Horizontal logos, wordmarks | 2400 |
| Stacked logos | 1600 |
| App icons | 1024 (midnight also 512) |
| Favicon | 16, 32, 48, 192, 512 |
| Apple touch icon | 180 |

`favicon.ico` holds the 16, 32 and 48 px versions.

## Colours

Blue and oceanic: from the deep water off Danger Point to the turquoise surf over the rocks.

| Name | Hex | Use |
|------|-----|-----|
| Midnight sea | `#03182B` | Page backgrounds, the app icon tile, the name on light backgrounds |
| Deep blue | `#0A6FA8` | "COMMUNITY RADIO" on light backgrounds, links on white |
| Boat blue | `#0A8FD8` | The main colour: buttons, the player (the blue of the harbour boats) |
| Turquoise | `#0FB5BA` | Second colour, the light-background bars |
| Neon surf | `#3EF0D8` | Glow and highlights on dark: the top of the bars, buttons, focus rings |
| Foam | `#E6FFFB` | Text on dark, the foam line, soft light backgrounds |
| On-air coral | `#FF5A4E` | "Live" and "On air" only, so it always stands out |

The two blues and turquoise run together as gradients (neon surf → boat blue on dark, turquoise → deep blue on light).

## The lettering

Everything is **Sora** (SemiBold 600 for **gansbaai**, Light 300 with wide spacing for **COMMUNITY RADIO**). It is a clean geometric font with a modern, techy feel. Use Sora for the website and the station too (headings 600, text 300 or 400). It is under the SIL Open Font Licence, so it can be used freely, and it is on Google Fonts.

## Hero video

The home page hero is a 21-second loop of five slow-motion sea clips: waves from above, rocks in the surf, a fishing boat, kelp in the waves and the rocky shore. It loops without a jump. The files are in [`../theme/gansbaai-radio/hero/`](../theme/gansbaai-radio/hero/):

| File | Use |
|------|-----|
| `gbcr-hero-1280.mp4` | Computers (720p, 3.9 MB) |
| `gbcr-hero-854.mp4` | Phones (480p, 2.1 MB) |
| `gbcr-hero-poster.jpg` | Shown while the video loads, and instead of the video for people who turn motion off |

Play it muted, looped, with `playsinline`, and put a dark gradient over it so the logo and text stay readable.

The clips are free stock from Pexels (free to use, no credit needed): videos 10540776, 6525612, 8824247, 38149891 and 33091589. The originals and the full-length master are kept outside the repository (`D:\Dev\toolshed\gbcr-clips`).

## Use

- Keep clear space around the logo of at least the width of two bars.
- Don't stretch it, recolour it outside the sets above, or put the full-colour version on a busy photo without the dark gradient (or use the white version).
- Below about 120 px wide, use the favicon artwork instead of the full mark.

## Regenerating

The script is [`tools/brandkit/gen-gbcr-brand.mjs`](../../../tools/brandkit/gen-gbcr-brand.mjs) (Node, and Microsoft Edge for the PNGs). The two fonts go in `tools/brandkit/fonts/` (gitignored): get the TTFs from Google Fonts (`https://fonts.googleapis.com/css?family=Sora:300` and `…family=Sora:600`, with an old browser's user agent so the answer links `.ttf` files) and save them as `Sora-300.ttf` and `Sora-600.ttf`. Then:

```
cd tools/brandkit
npm install
node gen-gbcr-brand.mjs
```

Change colours or layout in the script and run it again. If you edit an SVG by hand, regenerating overwrites your edits.
