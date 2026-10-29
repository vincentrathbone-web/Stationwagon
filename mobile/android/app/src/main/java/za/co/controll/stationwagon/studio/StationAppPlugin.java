package za.co.controll.stationwagon.studio;

import android.Manifest;
import android.app.NotificationManager;
import android.content.ActivityNotFoundException;
import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.provider.Settings;
import androidx.core.content.ContextCompat;
import androidx.credentials.Credential;
import androidx.credentials.CredentialManager;
import androidx.credentials.CredentialManagerCallback;
import androidx.credentials.GetCredentialRequest;
import androidx.credentials.GetCredentialResponse;
import androidx.credentials.GetPasswordOption;
import androidx.credentials.PasswordCredential;
import androidx.credentials.exceptions.GetCredentialException;
import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import com.getcapacitor.annotation.Permission;

/**
 * The app's native parts, for its own start page and the station's studio page:
 * - start page only: the station's address, opening the studio, updates
 * - both: Do Not Disturb, on air (the foreground service), the start page, quitting
 * Events: "back" (Back pressed while on air), "updateProgress" ({ got, total }), "updateFailed" ({ message }).
 */
@CapacitorPlugin(
    name = "StationApp",
    permissions = { @Permission(alias = "notifications", strings = { Manifest.permission.POST_NOTIFICATIONS }) }
)
public class StationAppPlugin extends Plugin {
    static StationAppPlugin current;

    @Override
    public void load() {
        current = this;
    }

    /** The start page is the app's own (no station address as its server). */
    private boolean fromStartPage(PluginCall call) {
        if (getBridge().getServerUrl() == null) return true;
        call.reject("Only the app’s own page can do that.");
        return false;
    }

    private Prefs prefs() {
        return new Prefs(getContext());
    }

    private MainActivity main() {
        return (MainActivity) getActivity();
    }

    void back() {
        notifyListeners("back", new JSObject());
    }

    void updateFailed(String why) {
        notifyListeners("updateFailed", new JSObject().put("message", why));
    }

    @PluginMethod
    public void info(PluginCall call) {
        JSObject r = new JSObject();
        r.put("version", BuildConfig.VERSION_NAME);
        r.put("station", prefs().station());
        r.put("studio", getBridge().getServerUrl() != null);
        r.put("boot", main().boot);
        r.put("canInstall", getContext().getPackageManager().canRequestPackageInstalls());
        r.put("onAir", OnAirService.running);
        r.put("dnd", dnd());
        r.put("reopen", reopen());
        r.put("test", BuildConfig.SW_TEST);
        call.resolve(r);
    }

    // ---- start page --------------------------------------------------------------------------

    @PluginMethod
    public void saveStation(PluginCall call) {
        if (!fromStartPage(call)) return;
        try {
            String base = Prefs.base(call.getString("station", ""));
            prefs().station(base);
            call.resolve(new JSObject().put("station", base));
        } catch (IllegalArgumentException e) {
            call.reject(e.getMessage());
        }
    }

    @PluginMethod
    public void openStation(PluginCall call) {
        if (!fromStartPage(call)) return;
        if (prefs().station() == null) { call.reject("Save the station’s address first."); return; }
        call.resolve();
        main().restart(true);
    }

    @PluginMethod
    public void checkUpdate(PluginCall call) {
        if (!fromStartPage(call)) return;
        new Thread(() -> {
            try {
                Updater.Release r = Updater.check(prefs().updateEndpoint(), BuildConfig.VERSION_NAME);
                JSObject o = new JSObject();
                if (r != null) { o.put("version", r.version); o.put("notes", r.notes); }
                call.resolve(o);
            } catch (Exception e) {
                call.reject(e.getMessage() != null ? e.getMessage() : e.toString());
            }
        }).start();
    }

    @PluginMethod
    public void installUpdate(PluginCall call) {
        if (!fromStartPage(call)) return;
        if (OnAirService.running) { call.reject("Not while you’re on air."); return; }
        PackageManager pm = getContext().getPackageManager();
        if (!pm.canRequestPackageInstalls()) {
            // Android asks once: allow this app to install its own updates
            Intent i = new Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:" + getContext().getPackageName()));
            i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            getContext().startActivity(i);
            call.reject("Allow Stationwagon Studio to install its updates, then come back and try again.", "NEEDS_PERMISSION");
            return;
        }
        new Thread(() -> {
            try {
                Updater.Release r = Updater.check(prefs().updateEndpoint(), BuildConfig.VERSION_NAME);
                if (r == null) { call.reject("There’s no newer version."); return; }
                java.io.File apk = Updater.download(getContext(), r, (got, total) -> notifyListeners("updateProgress", new JSObject().put("got", got).put("total", total)));
                Updater.install(getContext(), apk);
                call.resolve(new JSObject().put("version", r.version));
            } catch (Exception e) {
                call.reject(e.getMessage() != null ? e.getMessage() : e.toString());
            }
        }).start();
    }

    // ---- start page and studio ---------------------------------------------------------------

    /**
     * The station's sign-in form was sent ({ email, password }): once the next page shows it worked,
     * Android offers to save it (Google Password Manager). Kept in memory only, until that page.
     */
    @PluginMethod
    public void signingIn(PluginCall call) {
        main().pendingSignIn = new String[] { call.getString("email", ""), call.getString("password", "") };
        call.resolve();
    }

    /** Android's sheet with the sign-ins saved for this app: { email, password }, or {} (none, or not picked). */
    @PluginMethod
    public void savedSignIn(PluginCall call) {
        GetCredentialRequest req = new GetCredentialRequest.Builder().addCredentialOption(new GetPasswordOption()).build();
        CredentialManager.create(getContext()).getCredentialAsync(getActivity(), req, null, ContextCompat.getMainExecutor(getContext()),
            new CredentialManagerCallback<GetCredentialResponse, GetCredentialException>() {
                @Override
                public void onResult(GetCredentialResponse r) {
                    Credential c = r.getCredential();
                    if (c instanceof PasswordCredential) {
                        PasswordCredential p = (PasswordCredential) c;
                        call.resolve(new JSObject().put("email", p.getId()).put("password", p.getPassword()));
                    } else {
                        call.resolve(new JSObject());
                    }
                }

                @Override
                public void onError(GetCredentialException e) {
                    call.resolve(new JSObject());
                }
            });
    }


    @PluginMethod
    public void openSettings(PluginCall call) {
        call.resolve();
        main().restart(false);
    }

    @PluginMethod
    public void quit(PluginCall call) {
        call.resolve();
        onAir(false);
        getActivity().runOnUiThread(() -> getActivity().finishAndRemoveTask());
    }

    /** Do Not Disturb: { access, on } */
    private JSObject dnd() {
        NotificationManager nm = (NotificationManager) getContext().getSystemService(Context.NOTIFICATION_SERVICE);
        return new JSObject()
            .put("access", nm.isNotificationPolicyAccessGranted())
            .put("on", nm.getCurrentInterruptionFilter() > NotificationManager.INTERRUPTION_FILTER_ALL);
    }

    @PluginMethod
    public void dndStatus(PluginCall call) {
        call.resolve(dnd());
    }

    /** Android's own screen for letting this app turn Do Not Disturb on and off (once). */
    @PluginMethod
    public void allowDnd(PluginCall call) {
        Intent i = new Intent(Settings.ACTION_NOTIFICATION_POLICY_ACCESS_SETTINGS);
        i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        getContext().startActivity(i);
        call.resolve();
    }

    /**
     * Opening again by itself after an update. Android (10 and newer) only lets an app open its window
     * from the background with "Display over other apps" (the user's choice, 2026-10-29). Xiaomi phones
     * (MIUI, HyperOS) also don't start the app at all unless the DJ allows its Autostart; nothing tells
     * the app whether that's on, so the start page offers Xiaomi's own screen for it on every Xiaomi phone.
     */
    static final boolean XIAOMI = "xiaomi".equalsIgnoreCase(Build.MANUFACTURER);

    private JSObject reopen() {
        return new JSObject()
            .put("overlay", Build.VERSION.SDK_INT < Build.VERSION_CODES.Q || Settings.canDrawOverlays(getContext()))
            .put("autostart", XIAOMI);
    }

    @PluginMethod
    public void reopenStatus(PluginCall call) {
        call.resolve(reopen());
    }

    /** Android's "Display over other apps" screen for this app. */
    @PluginMethod
    public void allowOverlay(PluginCall call) {
        Intent i = new Intent(Settings.ACTION_MANAGE_OVERLAY_PERMISSION, Uri.parse("package:" + getContext().getPackageName()));
        i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        getContext().startActivity(i);
        call.resolve();
    }

    /** Xiaomi's Autostart screen (else the app's own settings, where newer versions have it). */
    @PluginMethod
    public void allowAutostart(PluginCall call) {
        Intent i = new Intent().setComponent(new ComponentName("com.miui.securitycenter", "com.miui.permcenter.autostart.AutoStartManagementActivity"))
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        try {
            getContext().startActivity(i);
        } catch (ActivityNotFoundException | SecurityException e) {
            getContext().startActivity(new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:" + getContext().getPackageName()))
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK));
        }
        call.resolve();
    }

    /**
     * On air: the foreground service (mic, sound and Wi-Fi stay awake with the screen off) and Do
     * Not Disturb (alarms only), put back as it was when the show ends. { on: boolean } -> dnd
     */
    @PluginMethod
    public void setOnAir(PluginCall call) {
        boolean on = Boolean.TRUE.equals(call.getBoolean("on", false));
        if (on && Build.VERSION.SDK_INT >= 33 && ContextCompat.checkSelfPermission(getContext(), Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED && !prefsAskedNotifications()) {
            // the "On air" notification; the show runs without it too
            requestPermissionForAlias("notifications", call, "afterNotifications");
            return;
        }
        onAir(on);
        call.resolve(dnd());
    }

    @com.getcapacitor.annotation.PermissionCallback
    private void afterNotifications(PluginCall call) {
        getContext().getSharedPreferences("stationwagon", Context.MODE_PRIVATE).edit().putBoolean("askedNotifications", true).apply();
        onAir(Boolean.TRUE.equals(call.getBoolean("on", false)));
        call.resolve(dnd());
    }

    private boolean prefsAskedNotifications() {
        return getContext().getSharedPreferences("stationwagon", Context.MODE_PRIVATE).getBoolean("askedNotifications", false);
    }

    private void onAir(boolean on) {
        Context c = getContext();
        NotificationManager nm = (NotificationManager) c.getSystemService(Context.NOTIFICATION_SERVICE);
        Prefs p = prefs();
        if (on) {
            if (!OnAirService.running) ContextCompat.startForegroundService(c, new Intent(c, OnAirService.class));
            if (nm.isNotificationPolicyAccessGranted() && p.dndBefore() < 0) {
                int before = nm.getCurrentInterruptionFilter();
                p.dndBefore(before);
                if (before == NotificationManager.INTERRUPTION_FILTER_ALL) nm.setInterruptionFilter(NotificationManager.INTERRUPTION_FILTER_ALARMS);
            }
        } else {
            c.stopService(new Intent(c, OnAirService.class));
            int before = p.dndBefore();
            if (before >= 0) {
                // put it back, unless the DJ changed it during the show
                if (nm.isNotificationPolicyAccessGranted() && nm.getCurrentInterruptionFilter() == NotificationManager.INTERRUPTION_FILTER_ALARMS && before != NotificationManager.INTERRUPTION_FILTER_ALARMS) {
                    nm.setInterruptionFilter(before);
                }
                p.dndBefore(-1);
            }
        }
    }

    @Override
    protected void handleOnDestroy() {
        if (current == this) current = null;
    }
}
