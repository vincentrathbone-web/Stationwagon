# Builds the Stationwagon WordPress plugin, the zip a site owner uploads in Plugins -> Add New ->
# Upload Plugin. It holds the plugin (wordpress/stationwagon/) and the station app zipped inside it
# (app.zip, code only: no settings, setup key, tests or uploaded media), plus app.version.
#   python tools/build-wp-plugin.py            -> wordpress/dist/stationwagon-<plugin version>.zip
# It also leaves app.zip and app.version in wordpress/stationwagon/ (gitignored) so a copy of the
# plugin folder linked into a test WordPress works as it is.
import os, re, sys, zipfile
from appzip import app_files, zip_bytes

repo = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
app = os.path.join(repo, "app")
plugin = os.path.join(repo, "wordpress", "stationwagon")
dist = os.path.join(repo, "wordpress", "dist")

version = open(os.path.join(app, "VERSION"), encoding="utf8").read().strip()
plugin_version = re.search(r"^\s*\*\s*Version:\s*(\S+)", open(os.path.join(plugin, "stationwagon.php"), encoding="utf8").read(), re.M).group(1)
app_zip = zip_bytes((rel, os.path.join(app, rel)) for rel in app_files())
for name, data in (("app.zip", app_zip), ("app.version", (version + "\n").encode())):
    with open(os.path.join(plugin, name), "wb") as fh:
        fh.write(data)

os.makedirs(dist, exist_ok=True)
out = os.path.join(dist, f"stationwagon-{plugin_version}.zip")
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as z:
    for root, dirs, files in os.walk(plugin):
        dirs.sort()
        for f in sorted(files):
            src = os.path.join(root, f)
            z.write(src, "stationwagon/" + os.path.relpath(src, plugin).replace("\\", "/"))
print(f"{out}  plugin {plugin_version}, app {version}, {os.path.getsize(out) // 1024} KB")
