// Shows the Gansbaai brand overview (with the hero video) on http://localhost:8765. Run: node tools/brandkit/serve-gansbaai.mjs
import { createServer } from "http";
import { readFile, stat } from "fs/promises";
import { createReadStream } from "fs";
import { join, extname, normalize } from "path";
import { fileURLToPath } from "url";
const root = fileURLToPath(new URL("../../stations/gansbaai/", import.meta.url));
const types = { ".html": "text/html; charset=utf-8", ".svg": "image/svg+xml", ".png": "image/png", ".ico": "image/x-icon", ".md": "text/plain; charset=utf-8", ".mp4": "video/mp4", ".jpg": "image/jpeg", ".css": "text/css; charset=utf-8", ".js": "text/javascript; charset=utf-8", ".json": "application/json" };
createServer(async (req, res) => {
  let p = normalize(decodeURIComponent(new URL(req.url, "http://x").pathname)).replace(/^[\\/.]+$/, "").replace(/^[\\/]+/, "");
  if (!p) { res.writeHead(302, { Location: "/brand/index.html" }); return res.end(); }
  if (p.startsWith("..")) { res.writeHead(403); return res.end(); }
  const file = join(root, p), type = types[extname(p)] || "application/octet-stream";
  try {
    const { size } = await stat(file);
    const m = /bytes=(\d*)-(\d*)/.exec(req.headers.range || "");
    if (m) {
      const start = m[1] ? +m[1] : size - +m[2], end = m[1] && m[2] ? +m[2] : size - 1;
      res.writeHead(206, { "Content-Type": type, "Accept-Ranges": "bytes", "Content-Range": `bytes ${start}-${end}/${size}`, "Content-Length": end - start + 1 });
      return createReadStream(file, { start, end }).pipe(res);
    }
    res.writeHead(200, { "Content-Type": type, "Accept-Ranges": "bytes", "Content-Length": size, "Cache-Control": "no-store" });
    res.end(await readFile(file));
  } catch { res.writeHead(404); res.end("not found"); }
}).listen(8765, "127.0.0.1", () => console.log("gansbaai on http://localhost:8765"));
