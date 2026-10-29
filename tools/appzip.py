# The station app's files as one zip, code only: no settings, setup key, tests or uploaded media.
# Shared by build-wp-plugin.py (app.zip inside the plugin) and build-setup.py (stationwagon.zip).
import io, os, zipfile

repo = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
app = os.path.join(repo, "app")

def app_files():
    for root, dirs, files in os.walk(app):
        rel_root = os.path.relpath(root, app).replace("\\", "/")
        if rel_root == ".":
            rel_root = ""
        dirs[:] = sorted(d for d in dirs if not (rel_root == "" and d == "tests"))
        for f in sorted(files):
            rel = (rel_root + "/" + f).lstrip("/")
            if rel.startswith("storage/") and rel != "storage/.htaccess":
                continue
            if f.startswith("config.local.php") or f == "install.key" or f.endswith(".local.txt"):
                continue
            yield rel

def zip_bytes(entries):
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for rel, src in entries:
            z.write(src, rel)
    return buf.getvalue()

def app_zip():
    return zip_bytes((rel, os.path.join(app, rel)) for rel in app_files())
