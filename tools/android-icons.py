# Makes the Android app's icons and splash screens from the Windows app's icon
# (desktop/src-tauri/icons/icon.png): the legacy launcher PNGs, the adaptive icon (a green
# background and the on-air sign as a vector), and plain light splash screens with the icon.
#   python tools/android-icons.py
import os
from PIL import Image, ImageDraw

root = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..")
res = os.path.join(root, "mobile/android/app/src/main/res")
icon = Image.open(os.path.join(root, "desktop/src-tauri/icons/icon.png")).convert("RGBA")
GREEN = "#1DB954"
LIGHT = (246, 247, 244, 255)

sizes = {"mdpi": 48, "hdpi": 72, "xhdpi": 96, "xxhdpi": 144, "xxxhdpi": 192}
for dpi, px in sizes.items():
    d = os.path.join(res, "mipmap-" + dpi)
    icon.resize((px, px), Image.LANCZOS).save(os.path.join(d, "ic_launcher.png"))
    # the round one: the icon's middle, in a circle
    big = icon.resize((px * 4, px * 4), Image.LANCZOS)
    mask = Image.new("L", big.size, 0)
    ImageDraw.Draw(mask).ellipse((px * 0.12, px * 0.12, px * 3.88, px * 3.88), fill=255)
    round_ = Image.new("RGBA", big.size, (0, 0, 0, 0))
    round_.paste(big, (0, 0), mask)
    round_.resize((px, px), Image.LANCZOS).save(os.path.join(d, "ic_launcher_round.png"))
    fg = os.path.join(d, "ic_launcher_foreground.png")
    if os.path.exists(fg):
        os.remove(fg)

# the adaptive icon: the sign kept inside the middle 66 of 108 units
def arc(r, right):
    c = 0.70710678 * r
    x = 54 + c if right else 54 - c
    return f"M{x:.2f},{54 - c:.2f}A{r},{r} 0,0 {1 if right else 0} {x:.2f},{54 + c:.2f}"

paths = "".join(
    f'\n    <path android:fillColor="#00000000" android:strokeColor="#FF141414" android:strokeWidth="5.4" android:strokeLineCap="round" android:pathData="{arc(r, side)}" />'
    for r in (20, 32.6) for side in (False, True))
fg_xml = f'''<?xml version="1.0" encoding="utf-8"?>
<!-- the on-air sign, from the Windows app's icon (tools/android-icons.py) -->
<vector xmlns:android="http://schemas.android.com/apk/res/android"
    android:width="108dp" android:height="108dp" android:viewportWidth="108" android:viewportHeight="108">
    <path android:fillColor="#FF141414" android:pathData="M54,54m-7.6,0a7.6,7.6 0,1 1,15.2 0a7.6,7.6 0,1 1,-15.2 0" />{paths}
</vector>
'''
open(os.path.join(res, "drawable-v24/ic_launcher_foreground.xml"), "w").write(fg_xml)
open(os.path.join(res, "drawable/ic_launcher_background.xml"), "w").write(f'''<?xml version="1.0" encoding="utf-8"?>
<vector xmlns:android="http://schemas.android.com/apk/res/android"
    android:width="108dp" android:height="108dp" android:viewportWidth="108" android:viewportHeight="108">
    <path android:fillColor="{GREEN}" android:pathData="M0,0h108v108h-108z" />
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
