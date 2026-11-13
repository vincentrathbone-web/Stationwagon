package za.co.controll.stationwagon.studio;

import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.net.Uri;
import java.util.Locale;

/** The app's saved settings: the station's address, and which page to open. */
final class Prefs {
    static final String UPDATE_ENDPOINT = "https://controll.co.za/wp-json/stationwagon/v1/android/latest-release";
    private final SharedPreferences p;

    Prefs(Context c) {
        p = c.getSharedPreferences("stationwagon", Context.MODE_PRIVATE);
    }

    /** The station's address, ending in "/", or null. */
    String station() {
        return p.getString("station", null);
    }

    void station(String base) {
        remember(base, "");
    }

    /** At most this many saved stations (the oldest added goes first, never the default one). */
    private static final int MAX_STATIONS = 20;

    /** The saved stations (round 81): { base, name } each once; the default one is always in it (older settings had only that). */
    java.util.List<String[]> stations() {
        java.util.List<String[]> out = new java.util.ArrayList<>();
        try {
            org.json.JSONArray a = new org.json.JSONArray(p.getString("stations", "[]"));
            for (int i = 0; i < a.length(); i++) {
                org.json.JSONObject o = a.getJSONObject(i);
                String b = base(o.optString("base", ""));
                boolean dup = false;
                for (String[] x : out) if (x[0].equals(b)) dup = true;
                if (!dup) out.add(new String[] { b, o.optString("name", "").trim() });
            }
        } catch (Exception e) { /* a damaged list: start from the default station */ }
        String cur = station();
        if (cur != null) {
            boolean has = false;
            for (String[] x : out) if (x[0].equals(cur)) has = true;
            if (!has) out.add(0, new String[] { cur, "" });
        }
        for (String[] x : out) if (x[1].isEmpty()) x[1] = Uri.parse(x[0]).getHost();
        return out;
    }

    private void saveStations(java.util.List<String[]> list) {
        org.json.JSONArray a = new org.json.JSONArray();
        try {
            for (String[] x : list) a.put(new org.json.JSONObject().put("base", x[0]).put("name", x[1]));
        } catch (org.json.JSONException e) { return; }
        p.edit().putString("stations", a.toString()).apply();
    }

    /** Saves a station (or renames it; an empty name keeps the one it had) and makes it the default. */
    void remember(String base, String name) {
        java.util.List<String[]> list = stations();
        String n = name == null ? "" : name.trim();
        if (n.length() > 80) n = n.substring(0, 80);
        boolean found = false;
        for (String[] x : list) if (x[0].equals(base)) { found = true; if (!n.isEmpty()) x[1] = n; }
        if (!found) list.add(new String[] { base, n.isEmpty() ? Uri.parse(base).getHost() : n });
        while (list.size() > MAX_STATIONS) {
            for (int i = 0; i < list.size(); i++) if (!list.get(i)[0].equals(base)) { list.remove(i); break; }
        }
        saveStations(list);
        p.edit().putString("station", base).apply();
    }

    /** Takes a station off the list; the default moves to the first one left (or none). Returns whether it was there. */
    boolean forget(String base) {
        java.util.List<String[]> list = stations();
        boolean had = false;
        for (int i = list.size() - 1; i >= 0; i--) if (list.get(i)[0].equals(base)) { list.remove(i); had = true; }
        if (!had) return false;
        saveStations(list);
        if (base.equals(station())) {
            if (list.isEmpty()) p.edit().remove("station").apply(); else p.edit().putString("station", list.get(0)[0]).apply();
        }
        return true;
    }

    /** What the next start opens ("studio" or "settings"), once; null: opening the app (check for updates, then the studio). */
    String takeNext() {
        String n = p.getString("next", null);
        if (n != null) p.edit().remove("next").commit();
        return n;
    }

    void next(String what) {
        p.edit().putString("next", what).commit();
    }

    /** Test builds: how long ago the app was "last used" (-1: not asked), once. */
    long takeSignInAge() {
        long a = p.getLong("signinAge", -1);
        if (a >= 0) p.edit().remove("signinAge").commit();
        return a;
    }

    /** The look picked in the studio, as JSON ({ skin, vars }), or null. */
    String look() {
        return p.getString("look", null);
    }

    void look(String json) {
        p.edit().putString("look", json).apply();
    }

    /** Where updates are looked up; test builds may point it at a stand-in. */
    String updateEndpoint() {
        String e = BuildConfig.SW_TEST ? p.getString("endpoint", null) : null;
        return e != null ? e : UPDATE_ENDPOINT;
    }

    /** The Do Not Disturb setting from before the show, to put back (-1: none kept). */
    int dndBefore() {
        return p.getInt("dndBefore", -1);
    }

    void dndBefore(int filter) {
        p.edit().putInt("dndBefore", filter).commit();
    }

    /**
     * Test builds only: `am start -e sw_endpoint <url> -e sw_station <url>`. Taken once (Android may
     * hand the same intent back after a restart); true when it changed something.
     */
    boolean takeTestExtras(Intent i) {
        if (i == null) return false;
        boolean changed = false;
        String e = i.getStringExtra("sw_endpoint");
        if (e != null) {
            String v = e.isEmpty() ? null : e;
            changed |= !java.util.Objects.equals(v, p.getString("endpoint", null));
            p.edit().putString("endpoint", v).commit();
            i.removeExtra("sw_endpoint");
        }
        // as if the app had last been used this long ago: the sign-in cookie lasts only what is left of its 12 hours
        long age = i.getLongExtra("sw_signin_age_ms", -1);
        if (age >= 0) {
            p.edit().putLong("signinAge", age).commit(); // applied once the page has started (MainActivity)
            i.removeExtra("sw_signin_age_ms");
        }
        String s = i.getStringExtra("sw_station");
        if (s != null) {
            String v = s.isEmpty() ? null : base(s);
            changed |= !java.util.Objects.equals(v, station());
            p.edit().putString("station", v).remove("next").commit();
            i.removeExtra("sw_station");
        }
        return changed;
    }

    /**
     * The station's base address from what was typed: https unless it's this device (localhost,
     * for tests over USB), without a page on the end (studio, signin, manage), ending in "/".
     * Throws IllegalArgumentException when it isn't an address.
     */
    static String base(String typed) {
        String s = typed.trim();
        if (s.isEmpty()) throw new IllegalArgumentException("Type the station’s address.");
        if (!s.matches("(?i)^https?://.*")) s = "https://" + s;
        Uri u = Uri.parse(s);
        String host = u.getHost();
        if (host == null || host.isEmpty() || !host.matches("[A-Za-z0-9.-]+")) throw new IllegalArgumentException("That doesn’t look like a web address.");
        host = host.toLowerCase(Locale.ROOT);
        boolean local = host.equals("localhost") || host.equals("127.0.0.1");
        String scheme = local ? u.getScheme().toLowerCase(Locale.ROOT) : "https";
        String path = u.getPath() == null ? "" : u.getPath();
        path = path.replaceAll("/+$", "").replaceAll("(?i)/(studio|signin|manage)(/.*)?$", "");
        return scheme + "://" + host + (u.getPort() > 0 ? ":" + u.getPort() : "") + path + "/";
    }
}
