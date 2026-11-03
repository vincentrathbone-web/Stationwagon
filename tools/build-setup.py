# Builds the standalone setup for sites without WordPress: setup.php (the one file a site owner
# uploads) and stationwagon.zip (the station app, code only), the two files of a release in the
# public vincentrathbone-web/stationwagon-releases repo.
#   python tools/build-setup.py                       -> setup/dist/setup.php, setup/dist/stationwagon.zip
#   python tools/build-setup.py --source URL [--cpanel URL] --out DIR
#                                                     a test build that downloads from URL (and talks to a cPanel stand-in)
import argparse, os, re
from appzip import repo, app, app_zip

ap = argparse.ArgumentParser()
ap.add_argument("--source")
ap.add_argument("--cpanel")
ap.add_argument("--out", default=os.path.join(repo, "setup", "dist"))
a = ap.parse_args()

version = open(os.path.join(app, "VERSION"), encoding="utf8").read().strip()
php = open(os.path.join(repo, "setup", "setup.php"), encoding="utf8").read()
# the page's logo and tab icon go inline, so the one file needs nothing else
import base64
logo = open(os.path.join(app, "views", "logo.svg"), encoding="utf8").read().strip()
icon = base64.b64encode(open(os.path.join(app, "assets", "brand", "favicon.svg"), "rb").read()).decode("ascii")
php = php.replace("<!--SW_LOGO-->", logo).replace("<!--SW_ICON-->", f'<link rel="icon" type="image/svg+xml" href="data:image/svg+xml;base64,{icon}">')
if a.source:
    php = re.sub(r"define\('SW_SOURCE', '[^']*'\);", lambda m: f"define('SW_SOURCE', '{a.source}');", php)
if a.cpanel:
    php = php.replace("define('SW_MAX_AGE', 3600);", f"define('SW_MAX_AGE', 3600);\ndefine('SW_CPANEL_URL', '{a.cpanel}');")
os.makedirs(a.out, exist_ok=True)
with open(os.path.join(a.out, "setup.php"), "w", encoding="utf8", newline="\n") as fh:
    fh.write(php)
with open(os.path.join(a.out, "stationwagon.zip"), "wb") as fh:
    fh.write(app_zip())
print(f"{a.out}: setup.php and stationwagon.zip (app {version}, {os.path.getsize(os.path.join(a.out, 'stationwagon.zip')) // 1024} KB)")
