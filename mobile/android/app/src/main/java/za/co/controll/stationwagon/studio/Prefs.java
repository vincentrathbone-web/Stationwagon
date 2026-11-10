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
        p.edit().putString("station", base).apply();
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
