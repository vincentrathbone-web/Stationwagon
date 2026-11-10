package za.co.controll.stationwagon.studio;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URI;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.Future;
import java.util.regex.Matcher;
import java.util.regex.Pattern;
import org.json.JSONObject;

/**
 * Finds the station from the address a DJ typed (round 59): they may type the website's address
 * (https://wordwave.co.za) instead of the station's (https://wordwave.co.za/radio/). Every Stationwagon
 * station answers api/nowplaying.php with { station: { name } }, so this asks there: at the address
 * typed, at the stations linked from that page (…/radio/, …/radio/signin, …/radio/embed), and in the
 * usual folders. The same as find_station in the Windows app (desktop/src-tauri/src/lib.rs).
 */
final class StationFinder {
    static final String[] FOLDERS = { "radio/", "stationwagon-app/", "stationwagon/", "station/" };
    private static final String[] MARKS = { "/studio", "/signin", "/embed", "/manage", "/podcast", "/api/" };
    private static final Pattern LINK = Pattern.compile("(?:href|src)\\s*=\\s*[\"']([^\"']+)[\"']", Pattern.CASE_INSENSITIVE);

    /** found: base and name; else reachable and a message saying why not. */
    static final class Result {
        boolean found, exact, reachable;
        String base = "", name = "", message = "";
    }

    static Result find(String typedAddress) {
        Result none = new Result();
        String typed;
        try { typed = Prefs.base(typedAddress); } catch (IllegalArgumentException e) { none.message = e.getMessage(); return none; }
        Boolean[] reached = { false };
        String[] hit = ask(typed, reached);
        if (hit != null) return found(hit, typed);
        List<String> candidates = new ArrayList<>();
        try {
            Page page = get(typed, 8000);
            reached[0] = true;
            if (page.body != null) for (String b : stationLinks(page.url, page.body)) if (!candidates.contains(b)) candidates.add(b);
        } catch (Exception e) { /* not reachable */ }
        if (!reached[0]) {
            none.message = "Couldn’t reach " + typed + ". Check the address and your internet connection, then try again.";
            return none;
        }
        for (String f : FOLDERS) { String c = typed + f; if (!candidates.contains(c)) candidates.add(c); }
        candidates.remove(typed);
        ExecutorService pool = Executors.newFixedThreadPool(Math.min(8, Math.max(1, candidates.size())));
        try {
            List<Future<String[]>> asks = new ArrayList<>();
            for (String c : candidates) asks.add(pool.submit(() -> ask(c, new Boolean[] { false })));
            for (Future<String[]> f : asks) {
                try { String[] r = f.get(); if (r != null) return found(r, typed); } catch (Exception e) { /* next */ }
            }
        } finally { pool.shutdownNow(); }
        none.reachable = true;
        none.message = "There’s no Stationwagon station at " + typed + ". Type the station’s own address, for example https://example.com/radio";
        return none;
    }

    private static Result found(String[] hit, String typed) {
        Result r = new Result();
        r.found = true; r.reachable = true; r.base = hit[0]; r.name = hit[1]; r.exact = hit[0].equals(typed);
        return r;
    }

    /** { base after any redirect, name } when base is a station, else null; reached[0] true when the server answered. */
    static String[] ask(String base, Boolean[] reached) {
        try {
            Page p = get(base + "api/nowplaying.php", 8000);
            reached[0] = true;
            if (p.status < 200 || p.status >= 300 || p.body == null) return null;
            JSONObject station = new JSONObject(p.body).optJSONObject("station");
            if (station == null || !station.has("name")) return null;
            String name = station.optString("name", "").trim();
            String real = p.url.replaceAll("\\?.*$", "");
            real = real.endsWith("api/nowplaying.php") ? real.substring(0, real.length() - "api/nowplaying.php".length()) : base;
            return new String[] { real, name.isEmpty() ? "a Stationwagon station" : name };
        } catch (Exception e) {
            return null;
        }
    }

    /** The station's base for a link: the part before /studio, /signin, /embed, … (null if none). */
    static String baseFromLink(String link) {
        try {
            URI u = new URI(link);
            String path = u.getRawPath() == null ? "" : u.getRawPath();
            for (String mark : MARKS) {
                int i = path.indexOf(mark);
                if (i < 0) continue;
                String rest = path.substring(i + mark.length());
                if (mark.endsWith("/") || rest.isEmpty() || rest.startsWith("/") || rest.startsWith(".")) {
                    return u.getScheme() + "://" + u.getRawAuthority() + path.substring(0, i) + "/";
                }
            }
        } catch (Exception e) { /* not a link */ }
        return null;
    }

    static List<String> stationLinks(String page, String html) {
        List<String> out = new ArrayList<>();
        Matcher m = LINK.matcher(html);
        while (m.find() && out.size() < 8) {
            try {
                URI link = new URI(page).resolve(m.group(1).replace("&amp;", "&").trim());
                if (!sameSite(new URI(page), link)) continue;
                String b = baseFromLink(link.toString());
                if (b != null && !out.contains(b)) out.add(b);
            } catch (Exception e) { /* skip */ }
        }
        return out;
    }

    private static boolean sameSite(URI a, URI b) {
        String ha = a.getHost() == null ? "" : a.getHost().toLowerCase(Locale.ROOT).replaceFirst("^www\\.", "");
        String hb = b.getHost() == null ? "" : b.getHost().toLowerCase(Locale.ROOT).replaceFirst("^www\\.", "");
        return !ha.isEmpty() && ha.equals(hb) && a.getPort() == b.getPort();
    }

    private static final class Page { int status; String url; String body; }

    /** GET with redirects followed (also http to https); the body up to 2 MB. */
    private static Page get(String at, int timeout) throws Exception {
        String url = at;
        for (int hop = 0; hop < 5; hop++) {
            HttpURLConnection c = (HttpURLConnection) new URL(url).openConnection();
            c.setConnectTimeout(timeout); c.setReadTimeout(timeout);
            c.setInstanceFollowRedirects(false);
            c.setRequestProperty("User-Agent", "Stationwagon Studio/" + BuildConfig.VERSION_NAME);
            try {
                int code = c.getResponseCode();
                String next = c.getHeaderField("Location");
                if (code >= 300 && code < 400 && next != null) { url = new URL(new URL(url), next).toString(); continue; }
                Page p = new Page();
                p.status = code; p.url = url;
                InputStream in = code < 400 ? c.getInputStream() : c.getErrorStream();
                if (in != null) {
                    ByteArrayOutputStream buf = new ByteArrayOutputStream();
                    byte[] b = new byte[16384];
                    for (int n; (n = in.read(b)) > 0 && buf.size() < 2_000_000; ) buf.write(b, 0, n);
                    in.close();
                    p.body = buf.toString(StandardCharsets.UTF_8.name());
                }
                return p;
            } finally { c.disconnect(); }
        }
        throw new Exception("Too many redirects");
    }
}
