"""Render an SVG to a transparent PNG using headless Microsoft Edge (Windows).
Usage: python render_png.py <svg path> <out.png> <pixel width>"""
import os, re, subprocess, sys, tempfile
from PIL import Image

EDGE = "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe"
SCRATCH = tempfile.gettempdir()

def render(svg_path, out_path, px_w):
    src = open(svg_path, encoding="utf-8").read()
    vb = re.search(r'viewBox="0 0 ([\d.]+) ([\d.]+)"', src)
    w, h = float(vb.group(1)), float(vb.group(2))
    px_h = round(px_w * h / w)
    html = os.path.join(SCRATCH, "_wrap.html")
    uri = "file:///" + svg_path.replace("\\", "/")
    open(html, "w", encoding="utf-8").write(
        f'<!doctype html><meta charset="utf-8"><style>html,body{{margin:0;background:transparent}}'
        f'img{{display:block;width:{px_w}px;height:{px_h}px}}</style><img src="{uri}">')
    shot = os.path.join(SCRATCH, "_shot.png")
    if os.path.exists(shot): os.remove(shot)
    subprocess.run([EDGE, "--headless=new", "--disable-gpu", "--hide-scrollbars", "--force-device-scale-factor=1",
                    "--default-background-color=00000000", f"--window-size={px_w + 40},{px_h + 200}",
                    f"--screenshot={shot}", "--allow-file-access-from-files", "file:///" + html.replace("\\", "/")],
                   check=True, capture_output=True, timeout=60)
    im = Image.open(shot).convert("RGBA").crop((0, 0, px_w, px_h))
    os.makedirs(os.path.dirname(out_path), exist_ok=True)
    im.save(out_path, optimize=True)
    return im.size

if __name__ == "__main__":
    print(render(sys.argv[1], sys.argv[2], int(sys.argv[3])))
