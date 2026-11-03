# Stationwagon brand files

Open [`index.html`](index.html) in a browser for a visual overview of every file here.

The logo is a station wagon whose wheels are speaker cones and whose roof sends out a radio signal, with the name in lowercase.

Every logo comes in two formats:

- **`svg/`**: editable vector files. All text is outlines, so no font has to be installed. Open them in Inkscape, Illustrator, Figma or any text editor. They scale to any size.
- **`png/`**: ready-to-use images with a **transparent background** (sizes below).

## Which one do I use?

| Need | File |
|------|------|
| Main logo, dark or coloured background | `stationwagon-logo-horizontal` |
| Main logo, white or light background | `stationwagon-logo-horizontal-light-bg` |
| Square space (profile picture, poster) | `stationwagon-logo-stacked` / `-light-bg` |
| Just the wagon | `stationwagon-mark` (dark bg), `stationwagon-mark-light-bg` (light bg) |
| Just the word | `stationwagon-wordmark` / `-light-bg` |
| One-colour printing, stamps, embroidery, photos | `*-white` (on dark) or `*-black` (on light) |
| App store, social media avatar | `stationwagon-app-icon` (dark tile) or `stationwagon-app-icon-green` (green tile, like the studio apps' icon) |
| Browser tab icon | `stationwagon-favicon` (bolder strokes, so it stays clear at 16 px) |

The files with plain names are drawn for **dark** backgrounds (white text). The `-light-bg` ones use a deeper green and dark text so they stay readable on white.

## PNG sizes

| Group | Width |
|-------|-------|
| Marks | 2000 |
| Horizontal logos, wordmarks | 2400 |
| Stacked logos | 1600 |
| App icons | 1024 (dark also 512) |
| Favicon | 16, 32, 48, 192, 512 |
| Apple touch icon | 180 |

`favicon.ico` holds the 16, 32 and 48 px versions.

## Colours

| Name | Dark backgrounds | Light backgrounds |
|------|------------------|-------------------|
| Green (start of the gradient) | `#3BE477` | `#19B24F` |
| Green (end of the gradient) | `#1ED760` | `#0B7A38` |
| Wordmark | `#FFFFFF` | `#14241A` |
| App icon tile | `#232323` to `#0B0B0B`, with a faint green glow | |
| Green tile | `#3BE477` to `#1ED760`, mark in `#121212` | |

`#1ED760` is the same green the station's own pages use for their main action (the Green look's `--amber` token in `app/assets/app.css`).

## The wordmark

Set in Nunito ExtraBold (800), lowercase, in one colour. Nunito is under the SIL Open Font Licence, so the outlines can be used freely. The outlines are kept in `tools/brand-wordmark.json`.

## Use

- Keep clear space around the logo of at least the height of one wheel.
- Don't stretch it, recolour it outside the sets above, or put the full-colour version on a busy photo (use the white or black version).
- Below about 120 px wide, use the favicon artwork (`stationwagon-favicon`) instead of the full mark: its signal and wheels are drawn heavier.

## Regenerating

The scripts are in [`../tools`](../tools). From the repo root (Windows; needs Python with Pillow and Microsoft Edge):

```
python tools/gen_brand.py .
python tools/render_png.py brand/svg/stationwagon-mark.svg brand/png/stationwagon-mark.png 2000
```

Change colours or layout in `tools/gen_brand.py`, run it again, then export the PNGs again. If you edit an SVG by hand, regenerating will overwrite your edits.
