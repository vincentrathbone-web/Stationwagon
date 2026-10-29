package za.co.controll.stationwagon.studio;

import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageInstaller;
import android.os.Build;
import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLEncoder;
import java.security.MessageDigest;
import org.json.JSONObject;

/**
 * The app's updates, from the Stationwagon Updates plugin (which asks GitHub with its token):
 * check, download the APK (its size and SHA-256 checked when the plugin gives them), install it with
 * Android's package installer. Android itself refuses an APK not signed with the app's own key.
 */
final class Updater {
    static final class Release {
        String version, notes, url, sha256;
        long size;
    }

    interface Progress {
        void on(long got, long total);
    }

    /** The newer release, or null when there's nothing newer. */
    static Release check(String endpoint, String current) throws IOException {
        URL u = new URL(endpoint + (endpoint.contains("?") ? "&" : "?") + "current_version=" + URLEncoder.encode(current, "UTF-8"));
        HttpURLConnection c = (HttpURLConnection) u.openConnection();
        c.setConnectTimeout(10000);
        c.setReadTimeout(15000);
        c.setRequestProperty("Accept", "application/json");
        try {
            int code = c.getResponseCode();
            if (code == 204) return null;
            if (code != 200) throw new IOException("The update server answered " + code + ".");
            JSONObject o = new JSONObject(new String(readAll(c.getInputStream()), "UTF-8"));
            Release r = new Release();
            r.version = o.getString("version");
            r.notes = o.optString("notes", "");
            r.url = o.getString("url");
            r.size = o.optLong("size", 0);
            r.sha256 = o.optString("sha256", "");
            return r;
        } catch (org.json.JSONException e) {
            throw new IOException("The update server’s answer didn’t make sense.");
        } finally {
            c.disconnect();
        }
    }

    static File download(Context ctx, Release r, Progress progress) throws IOException {
        File dir = new File(ctx.getCacheDir(), "updates");
        dir.mkdirs();
        File[] old = dir.listFiles();
        if (old != null) for (File f : old) f.delete();
        File apk = new File(dir, "Stationwagon-Studio_" + r.version.replaceAll("[^0-9A-Za-z.-]", "") + ".apk");
        String at = r.url;
        HttpURLConnection c = null;
        // follow redirects by hand: the plugin sends on to GitHub's short-lived link (https to https)
        for (int hops = 0; hops < 5; hops++) {
            c = (HttpURLConnection) new URL(at).openConnection();
            c.setInstanceFollowRedirects(false);
            c.setConnectTimeout(15000);
            c.setReadTimeout(30000);
            int code = c.getResponseCode();
            if (code >= 300 && code < 400 && c.getHeaderField("Location") != null) {
                at = new URL(new URL(at), c.getHeaderField("Location")).toString();
                c.disconnect();
                continue;
            }
            if (code != 200) { c.disconnect(); throw new IOException("The download failed (" + code + ")."); }
            break;
        }
        try {
            long total = c.getContentLengthLong() > 0 ? c.getContentLengthLong() : r.size;
            MessageDigest sha = MessageDigest.getInstance("SHA-256");
            long got = 0, told = 0;
            try (InputStream in = c.getInputStream(); OutputStream out = new FileOutputStream(apk)) {
                byte[] buf = new byte[65536];
                int n;
                while ((n = in.read(buf)) > 0) {
                    out.write(buf, 0, n);
                    sha.update(buf, 0, n);
                    got += n;
                    if (got - told > 256 * 1024 || got == total) { progress.on(got, total); told = got; }
                }
            }
            progress.on(got, total);
            if (r.size > 0 && got != r.size) throw new IOException("The download was cut short. Try again.");
            if (!r.sha256.isEmpty() && !r.sha256.equalsIgnoreCase(hex(sha.digest()))) throw new IOException("The download doesn’t match the release. Try again.");
            return apk;
        } catch (java.security.NoSuchAlgorithmException e) {
            throw new IOException(e);
        } finally {
            c.disconnect();
        }
    }

    /** Hands the APK to Android's installer; InstallReceiver hears how it went. */
    static void install(Context ctx, File apk) throws IOException {
        PackageInstaller pi = ctx.getPackageManager().getPackageInstaller();
        PackageInstaller.SessionParams p = new PackageInstaller.SessionParams(PackageInstaller.SessionParams.MODE_FULL_INSTALL);
        p.setAppPackageName(ctx.getPackageName());
        p.setSize(apk.length());
        // after the first install, an update from the app itself needs no extra tap (Android 12+)
        if (Build.VERSION.SDK_INT >= 31) p.setRequireUserAction(PackageInstaller.SessionParams.USER_ACTION_NOT_REQUIRED);
        int id = pi.createSession(p);
        try (PackageInstaller.Session s = pi.openSession(id)) {
            try (InputStream in = new FileInputStream(apk); OutputStream out = s.openWrite("app.apk", 0, apk.length())) {
                byte[] buf = new byte[65536];
                int n;
                while ((n = in.read(buf)) > 0) out.write(buf, 0, n);
                s.fsync(out);
            }
            Intent done = new Intent(ctx, InstallReceiver.class).setAction(InstallReceiver.ACTION);
            int flags = PendingIntent.FLAG_UPDATE_CURRENT | (Build.VERSION.SDK_INT >= 31 ? PendingIntent.FLAG_MUTABLE : 0);
            s.commit(PendingIntent.getBroadcast(ctx, id, done, flags).getIntentSender());
        }
    }

    private static byte[] readAll(InputStream in) throws IOException {
        java.io.ByteArrayOutputStream b = new java.io.ByteArrayOutputStream();
        byte[] buf = new byte[8192];
        int n;
        while ((n = in.read(buf)) > 0) b.write(buf, 0, n);
        in.close();
        return b.toByteArray();
    }

    private static String hex(byte[] d) {
        StringBuilder s = new StringBuilder();
        for (byte x : d) s.append(String.format("%02x", x));
        return s.toString();
    }
}
