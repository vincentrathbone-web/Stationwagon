# Makes the Android app's icons and splash screens from the Windows app's icon
# (desktop/src-tauri/icons/icon.png): the legacy launcher PNGs, the adaptive icon (the Station green
# tile's gradient and the logo's mark as vectors), and plain light splash screens with the icon.
#   python tools/android-icons.py
import os
from PIL import Image, ImageDraw

root = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..")
res = os.path.join(root, "mobile/android/app/src/main/res")
icon = Image.open(os.path.join(root, "desktop/src-tauri/icons/icon.png")).convert("RGBA")
GREEN = "#1ED760"      # Station green (brand/README.md); the tile fades from GREEN_FROM to it
GREEN_FROM = "#3BE477"
LIGHT = (246, 247, 244, 255)

sizes = {"mdpi": 48, "hdpi": 72, "xhdpi": 96, "xxhdpi": 144, "xxxhdpi": 192}
for dpi, px in sizes.items():
    d = os.path.join(res, "mipmap-" + dpi)
    icon.resize((px, px), Image.LANCZOS).save(os.path.join(d, "ic_launcher.png"))
    # the round one: the icon in a circle
    big = icon.resize((px * 4, px * 4), Image.LANCZOS)
    mask = Image.new("L", big.size, 0)
    ImageDraw.Draw(mask).ellipse((px * 0.04, px * 0.04, px * 3.96, px * 3.96), fill=255)
    round_ = Image.new("RGBA", big.size, (0, 0, 0, 0))
    round_.paste(big, (0, 0), mask)
    round_.resize((px, px), Image.LANCZOS).save(os.path.join(d, "ic_launcher_round.png"))
    fg = os.path.join(d, "ic_launcher_foreground.png")
    if os.path.exists(fg):
        os.remove(fg)

# the adaptive icon: the logo's mark (brand/svg/stationwagon-mark.svg: the wagon, its speaker wheels
# and the signal) as a vector, kept inside the middle 66 of 108 units, on the Station green tile's gradient
MARK_CX, MARK_CY, SCALE = 101.0, 23.5, 0.33    # the mark's middle in its own units, and its size in the 108 grid
INK = "#FF121212"
def circle(cx, cy, r):
    return f"M{cx - r},{cy}a{r},{r} 0,1 1,{2 * r},0a{r},{r} 0,1 1,{-2 * r},0"
def stroke(d, w):
    return f'<path android:fillColor="#00000000" android:strokeColor="{INK}" android:strokeWidth="{w}" android:strokeLineCap="round" android:pathData="{d}" />'
def fill(d, evenodd=False):
    rule = ' android:fillType="evenOdd"' if evenodd else ""
    return f'<path android:fillColor="{INK}"{rule} android:pathData="{d}" />'
parts = [
    fill("M8 70V40Q8 20 24 20H114L148 44H184Q194 46 194 56V70H168A18 18 0 0 0 132 70H64A18 18 0 0 0 28 70ZM22 28H58V44H22Z M64 28H100V44H64Z M106 28H116L138.7 44H106Z", True),
    fill(circle(69, 12, 4.5)),
    stroke("M54.3 3.5A17 17 0 0 1 83.7 3.5", 6), stroke("M42.2 -3.5A31 31 0 0 1 95.8 -3.5", 6), stroke("M30.0 -10.5A45 45 0 0 1 108.0 -10.5", 6)]
for cx in (46, 150):
    parts += [stroke(circle(cx, 70, 11.8), 2.6), stroke(circle(cx, 70, 6.4), 2.2), fill(circle(cx, 70, 2.4))]
body = "\n        ".join(parts)
fg_xml = f'''<?xml version="1.0" encoding="utf-8"?>
<!-- the Stationwagon logo's mark, from brand/svg (tools/android-icons.py) -->
<vector xmlns:android="http://schemas.android.com/apk/res/android"
    android:width="108dp" android:height="108dp" android:viewportWidth="108" android:viewportHeight="108">
    <group android:scaleX="{SCALE}" android:scaleY="{SCALE}" android:translateX="{54 - MARK_CX * SCALE:.3f}" android:translateY="{54 - MARK_CY * SCALE:.3f}">
        {body}
    </group>
</vector>
'''
open(os.path.join(res, "drawable-v24/ic_launcher_foreground.xml"), "w").write(fg_xml)
open(os.path.join(res, "drawable/ic_launcher_background.xml"), "w").write(f'''<?xml version="1.0" encoding="utf-8"?>
<vector xmlns:android="http://schemas.android.com/apk/res/android" xmlns:aapt="http://schemas.android.com/aapt"
    android:width="108dp" android:height="108dp" android:viewportWidth="108" android:viewportHeight="108">
    <path android:pathData="M0,0h108v108h-108z">
        <aapt:attr name="android:fillColor">
            <gradient android:startX="0" android:startY="0" android:endX="108" android:endY="108" android:type="linear">
                <item android:offset="0" android:color="{GREEN_FROM}" />
                <item android:offset="1" android:color="{GREEN}" />
            </gradient>
        </aapt:attr>
    </path>
</vector>
''')
open(os.path.join(res, "values/ic_launcher_background.xml"), "w").write(f'''<?xml version="1.0" encoding="utf-8"?>
<resources>
    <color name="ic_launcher_background">{GREEN}</color>
</resources>
''')
adaptive = '''<?xml version="1.0" encoding="utf-8"?>
<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">
    <background android:drawable="@color/ic_launcher_background"/>
    <foreground android:drawable="@drawable/ic_launcher_foreground"/>
</adaptive-icon>
'''
for n in ("ic_launcher.xml", "ic_launcher_round.xml"):
    open(os.path.join(res, "mipmap-anydpi-v26", n), "w").write(adaptive)

# splash screens: light, with the icon in the middle
for d in os.listdir(res):
    f = os.path.join(res, d, "splash.png")
    if not d.startswith("drawable") or not os.path.exists(f):
        continue
    w, h = Image.open(f).size
    s = Image.new("RGBA", (w, h), LIGHT)
    px = int(min(w, h) * 0.28)
    s.paste(icon.resize((px, px), Image.LANCZOS), ((w - px) // 2, (h - px) // 2), icon.resize((px, px), Image.LANCZOS))
    s.convert("RGB").save(f)
print("icons and splash screens written")
