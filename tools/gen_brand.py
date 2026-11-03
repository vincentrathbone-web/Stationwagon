"""Generate the Stationwagon brand SVGs (all outlines, no live text) into brand/svg, and the overview page.
Usage: python tools/gen_brand.py <repo root>
The wordmark's outlines (Nunito ExtraBold, SIL Open Font Licence) are kept in tools/brand-wordmark.json.
PNGs are then made with tools/render_png.py (see brand/README.md)."""
import json, math, os, sys

ROOT = sys.argv[1]
SVG = os.path.join(ROOT, "brand", "svg")
os.makedirs(SVG, exist_ok=True)

WM = json.load(open(os.path.join(ROOT, "tools", "brand-wordmark.json"), encoding="utf-8"))
WM_D, WM_ADV = WM["d"], WM["advance"]          # outlines at 1000 units to the em, baseline y = 0
WM_X1, WM_X2, WM_TOP, WM_BOT = WM["bbox"][0], WM["bbox"][2], WM["bbox"][1], WM["bbox"][3]

# colour sets: gradient ends for the mark, wordmark colour
DARK = dict(g1="#3be477", g2="#1ed760", word="#FFFFFF")      # for dark and coloured backgrounds
LIGHT = dict(g1="#19b24f", g2="#0b7a38", word="#14241A")     # for white and light backgrounds

# the mark lives in a box x 4..198, y -40..87 (194 x 127)
MX, MY, MW, MH = 4, -40, 194, 127
BODY = ("M8 70V40Q8 20 24 20H114L148 44H184Q194 46 194 56V70H168A18 18 0 0 0 132 70H64A18 18 0 0 0 28 70Z"
        "M22 28H58V44H22Z M64 28H100V44H64Z M106 28H116L138.7 44H106Z")

def arc(cx, cy, r, a1, a2):
    x1, y1 = cx + r * math.cos(math.radians(a1)), cy - r * math.sin(math.radians(a1))
    x2, y2 = cx + r * math.cos(math.radians(a2)), cy - r * math.sin(math.radians(a2))
    return f"M{x1:.1f} {y1:.1f}A{r} {r} 0 0 1 {x2:.1f} {y2:.1f}"

def mark(c, uid, mono=None, bold=False):
    """The wagon with speaker-cone wheels and a signal rising from the roof. bold = heavier strokes for tiny sizes."""
    if mono:
        paint, defs = mono, ""
    else:
        paint = f"url(#{uid})"
        defs = (f'<linearGradient id="{uid}" gradientUnits="userSpaceOnUse" x1="{MX}" y1="0" x2="{MX + MW}" y2="0">'
                f'<stop offset="0" stop-color="{c["g1"]}"/><stop offset="1" stop-color="{c["g2"]}"/></linearGradient>')
    body = f'<path d="{BODY}" fill="{paint}" fill-rule="evenodd"/>'
    ring1, ring2, hub = (11.6, 3.8, 3.2) if bold else (11.8, 2.6, 2.4)
    for cx in (46, 150):
        body += (f'<circle cx="{cx}" cy="70" r="{ring1}" fill="none" stroke="{paint}" stroke-width="{ring2}"/>'
                 f'<circle cx="{cx}" cy="70" r="6.4" fill="none" stroke="{paint}" stroke-width="{3.0 if bold else 2.2}"/>'
                 f'<circle cx="{cx}" cy="70" r="{hub}" fill="{paint}"/>')
    radii, sw, dot = ((22, 40), 9, 6) if bold else ((17, 31, 45), 6, 4.5)
    body += f'<circle cx="69" cy="12" r="{dot}" fill="{paint}"/>'
    for r in radii:
        body += f'<path d="{arc(69, 12, r, 150, 30)}" fill="none" stroke="{paint}" stroke-width="{sw}" stroke-linecap="round"/>'
    return defs, body

def word(color, x, baseline, size):
    """The wordmark as outlines: x is where the first letter's ink starts, baseline the line the letters sit on."""
    k = size / 1000
    return (f'<path transform="translate({x - WM_X1 * k:.2f} {baseline}) scale({k:.5f})" d="{WM_D}" fill="{color}"/>')

def word_w(size):
    return (WM_X2 - WM_X1) / 1000 * size

def svg(w, h, title, defs, body, bg=None):
    rect = f'<rect width="{w:.0f}" height="{h:.0f}" fill="{bg}"/>' if bg else ""
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {w:.0f} {h:.0f}" width="{w:.0f}" height="{h:.0f}" role="img" aria-label="{title}">\n'
            f'  <title>{title}</title>\n  <defs>{defs}</defs>\n  {rect}\n  {body}\n</svg>\n')

written = []
def write(name, content):
    with open(os.path.join(SVG, name + ".svg"), "w", encoding="utf-8") as f:
        f.write(content)
    written.append(name)
    print(name)

def place(body, scale, tx, ty):
    """Put the mark (drawn in its own box) at tx, ty on the canvas, scaled."""
    return f'<g transform="translate({tx:.1f} {ty:.1f}) scale({scale})">{body}</g>'

# ---- Marks (the mark's own box, with a little room) ----
PAD = 4
for name, c, mono in [("stationwagon-mark", DARK, None), ("stationwagon-mark-light-bg", LIGHT, None),
                      ("stationwagon-mark-white", None, "#FFFFFF"), ("stationwagon-mark-black", None, "#000000")]:
    d, b = mark(c, "mg", mono=mono)
    write(name, svg(MW + 2 * PAD, MH + 2 * PAD, "Stationwagon mark", d,
                    place(b, 1, PAD - MX, PAD - MY)))

# ---- Horizontal: mark + wordmark, the letters sitting on the wagon's bottom line ----
FS, GAP, P = 100, 38, 6
tw = word_w(FS)
W = P + MW + GAP + tw + P
H = P + MH + P
base = P + (70 - MY)                      # the wagon's bottom edge
for suffix, c in [("", DARK), ("-light-bg", LIGHT)]:
    d, b = mark(c, "mg")
    write(f"stationwagon-logo-horizontal{suffix}", svg(W, H, "Stationwagon", d,
          place(b, 1, P - MX, P - MY) + word(c["word"], P + MW + GAP, base, FS)))
for name, col in [("stationwagon-logo-horizontal-white", "#FFFFFF"), ("stationwagon-logo-horizontal-black", "#000000")]:
    d, b = mark(None, "x", mono=col)
    write(name, svg(W, H, "Stationwagon", d, place(b, 1, P - MX, P - MY) + word(col, P + MW + GAP, base, FS)))

# ---- Stacked: mark above the wordmark ----
S, FS2, pad, gap = 1.8, 66, 44, 36
tw2 = word_w(FS2)
w2 = max(tw2, MW * S) + pad * 2
mx = (w2 - MW * S) / 2
base2 = pad + MH * S + gap + 0.732 * FS2
h2 = base2 + 0.191 * FS2 + pad
for suffix, c in [("", DARK), ("-light-bg", LIGHT)]:
    d, b = mark(c, "mg")
    write(f"stationwagon-logo-stacked{suffix}", svg(w2, h2, "Stationwagon", d,
          place(b, S, mx - MX * S, pad - MY * S) + word(c["word"], (w2 - tw2) / 2, round(base2, 1), FS2)))

# ---- Wordmark only ----
FS3, pad3 = 120, 24
for suffix, c in [("", DARK), ("-light-bg", LIGHT)]:
    write(f"stationwagon-wordmark{suffix}", svg(word_w(FS3) + pad3 * 2, (0.732 + 0.191) * FS3 + pad3 * 2, "Stationwagon", "",
          word(c["word"], pad3, round(pad3 + 0.732 * FS3, 1), FS3)))

# ---- App icons and favicons (square tiles) ----
tile_defs = ('<linearGradient id="tile" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#232323"/>'
             '<stop offset="1" stop-color="#0b0b0b"/></linearGradient>'
             '<radialGradient id="glow" cx=".5" cy=".42" r=".62"><stop offset="0" stop-color="#1ed760" stop-opacity=".22"/>'
             '<stop offset="1" stop-color="#1ed760" stop-opacity="0"/></radialGradient>')

def tile(name, size, radius, mark_w, bold=False, glow=True, label="Stationwagon app icon", green=False):
    s = mark_w / MW
    tx = (size - MW * s) / 2 - MX * s
    ty = (size - MH * s) / 2 - MY * s + size * 0.015
    if green:
        d, b = mark(None, "x", mono="#121212", bold=bold)
        fill = "url(#greentile)"
        defs = ('<linearGradient id="greentile" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#3be477"/>'
                '<stop offset="1" stop-color="#1ed760"/></linearGradient>')
    else:
        d, b = mark(DARK, "mg", bold=bold)
        fill = "url(#tile)"
        defs = tile_defs
    body = (f'<rect width="{size}" height="{size}" rx="{radius}" fill="{fill}"/>'
            + (f'<rect width="{size}" height="{size}" rx="{radius}" fill="url(#glow)"/>' if glow and not green else "")
            + place(b, s, tx, ty))
    write(name, svg(size, size, label, defs + d, body))

tile("stationwagon-app-icon", 1024, 224, 720)                                   # dark tile, for stores and social
tile("stationwagon-app-icon-green", 1024, 224, 720, glow=False, green=True,
     label="Stationwagon app icon, green")                                      # green tile, like the studio apps
tile("stationwagon-favicon", 64, 14, 52, bold=True, glow=False, label="Stationwagon favicon")  # browser tab
tile("stationwagon-apple-touch-icon", 180, 0, 124, label="Stationwagon apple touch icon")      # square, the phone rounds it

# ---- Overview page ----
FILES = sorted(written)
GROUPS = [
    ("Marks", [n for n in FILES if "-mark" in n]),
    ("Horizontal logos", [n for n in FILES if "logo-horizontal" in n]),
    ("Stacked logos", [n for n in FILES if "logo-stacked" in n]),
    ("Wordmarks", [n for n in FILES if "wordmark" in n]),
    ("App icons and favicons", [n for n in FILES if "icon" in n or "favicon" in n]),
]
def surface(name):
    if name.endswith("-white") or name.endswith("-black"):
        return "mono-w" if name.endswith("-white") else "mono-b"
    if name.endswith("-light-bg") or "-icon" in name or "favicon" in name or "apple" in name:
        return "light" if name.endswith("-light-bg") else "free"
    return "dark"
import base64
def data_uri(n):
    """The logo inside the page itself, so the overview shows it wherever it is opened (a file viewer, an email, a copy)."""
    raw = open(os.path.join(SVG, n + ".svg"), "rb").read()
    return "data:image/svg+xml;base64," + base64.b64encode(raw).decode("ascii")
cards = ""
for title, names in GROUPS:
    cards += f'<h2>{title}</h2><div class="grid">'
    for n in names:
        cards += (f'<figure class="{surface(n)}"><div class="pic"><img src="{data_uri(n)}" alt="{n.replace("-", " ")}"></div>'
                  f'<figcaption><b>{n}</b><span><a href="svg/{n}.svg">SVG</a></span></figcaption></figure>')
    cards += '</div>'
INDEX = f"""<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Stationwagon brand files</title>
<style>
:root {{ --bg:#f4f6f4; --ink:#14241a; --soft:#55645b; --line:#d7e0da; --green:#1ed760; --deep:#0b7a38; --graphite:#151515; }}
* {{ box-sizing:border-box; }}
body {{ margin:0; background:var(--bg); color:var(--ink); font:16px/1.55 "Segoe UI", system-ui, Arial, sans-serif; padding:40px 20px 72px; }}
main {{ max-width:1080px; margin:0 auto; }}
h1 {{ font-size:clamp(28px,5vw,38px); margin:0 0 6px; }} h2 {{ font-size:20px; margin:36px 0 12px; }}
p {{ margin:0 0 8px; color:var(--soft); max-width:68ch; }}
.grid {{ display:grid; grid-template-columns:repeat(auto-fill,minmax(250px,1fr)); gap:14px; }}
figure {{ margin:0; border:1px solid var(--line); border-radius:14px; overflow:hidden; background:#fff; }}
.pic {{ height:170px; display:grid; place-items:center; padding:18px; }}
.pic img {{ max-width:100%; max-height:134px; }}
.dark .pic {{ background:var(--graphite); }} .mono-w .pic {{ background:#3a3a3a; }}
.light .pic, .mono-b .pic, .free .pic {{ background:#fff; }}
figcaption {{ display:flex; justify-content:space-between; gap:8px; padding:10px 14px; font-size:13px; border-top:1px solid var(--line); }}
figcaption b {{ font-weight:600; overflow-wrap:anywhere; }} a {{ color:var(--deep); }}
.swatches {{ display:flex; flex-wrap:wrap; gap:10px; margin-top:8px; }}
.sw {{ width:130px; border:1px solid var(--line); border-radius:12px; overflow:hidden; background:#fff; font-size:13px; }}
.sw i {{ display:block; height:54px; }} .sw span {{ display:block; padding:6px 10px; }}
</style></head><body><main>
<h1>Stationwagon brand files</h1>
<p>Every logo comes as an editable SVG (all text is outlines, so no font is needed) and a PNG with a transparent background. See README.md for which one to use and how to regenerate them.</p>
<div class="swatches">
<div class="sw"><i style="background:#1ed760"></i><span>Station green<br>#1ED760</span></div>
<div class="sw"><i style="background:#0b7a38"></i><span>Deep green<br>#0B7A38</span></div>
<div class="sw"><i style="background:#151515"></i><span>Graphite<br>#151515</span></div>
<div class="sw"><i style="background:#14241a"></i><span>Ink<br>#14241A</span></div>
</div>
{cards}
</main></body></html>
"""
with open(os.path.join(ROOT, "brand", "index.html"), "w", encoding="utf-8") as f:
    f.write(INDEX)
print("index.html")
