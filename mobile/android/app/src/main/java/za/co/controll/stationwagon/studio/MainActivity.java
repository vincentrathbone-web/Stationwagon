package za.co.controll.stationwagon.studio;

import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.webkit.JavascriptInterface;
import android.webkit.WebView;
import androidx.activity.OnBackPressedCallback;
import androidx.core.content.ContextCompat;
import androidx.credentials.CreateCredentialResponse;
import androidx.credentials.CreatePasswordRequest;
import androidx.credentials.CredentialManager;
import androidx.credentials.CredentialManagerCallback;
import androidx.credentials.exceptions.CreateCredentialException;
import com.getcapacitor.BridgeActivity;
import com.getcapacitor.CapConfig;

/**
 * Opens either the app's own start page (the station's address, updates) or the station's studio
 * page, which then is the app's "server": only that address may use the app's native parts
 * (Capacitor gives its bridge to the app's own origin alone). Switching between the two starts the
 * activity again with the other address. Opening the app shows the start page first ("boot"): it
 * checks for an update, then opens the studio.
 */
public class MainActivity extends BridgeActivity {
    /** This start is the app opening (the start page checks for updates, then opens the studio). */
    boolean boot;
    /** The station this start had saved (its cookies are the ones to keep over a restart). */
    private String loadedStation;
    /** A sign-in just sent from the station's page (email, password), until the next page loads. */
    String[] pendingSignIn;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        Prefs prefs = new Prefs(this);
        // a test's station or update address, on a fresh start only (a restart may hand the same intent back)
        if (BuildConfig.SW_TEST && savedInstanceState == null) prefs.takeTestExtras(getIntent());
        String station = prefs.station();
        String next = prefs.takeNext();
        boolean studio = station != null && "studio".equals(next);
        boot = next == null && station != null;
        CapConfig.Builder b = new CapConfig.Builder(this)
            .setAndroidScheme("https")
            .setHostname("stationwagon.app")
            .setErrorPath("offline.html")
            .setAppendedUserAgentString("StationwagonStudio/" + BuildConfig.VERSION_NAME + " (Android)")
            .setWebContentsDebuggingEnabled(BuildConfig.DEBUG || BuildConfig.SW_TEST);
        if (studio) {
            b.setServerUrl(station + "studio");
            b.setAllowNavigation(new String[] { Uri.parse(station).getHost() });
        }
        config = b.create();
        registerPlugin(StationAppPlugin.class);
        super.onCreate(savedInstanceState);
        loadedStation = station;
        if (BuildConfig.SW_TEST) {
            long age = prefs.takeSignInAge();
            if (age >= 0) keepSignIn(station, KEEP_SIGN_IN_MS - age);
        }
        bridge.addWebViewListener(new com.getcapacitor.WebViewListener() {
            @Override
            public void onPageLoaded(WebView webView) {
                keepSignIn(loadedStation, KEEP_SIGN_IN_MS);
                offerToSave(webView.getUrl());
            }
        });

        WebView web = bridge.getWebView();
        // keep the page's sound and timers going while the screen is off (the on-air service keeps the CPU awake)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) web.setRendererPriorityPolicy(WebView.RENDERER_PRIORITY_IMPORTANT, false);
        // the offline page (no bridge there): try again, or the start page
        web.addJavascriptInterface(new Offline(), "SwOffline");
        bridge.setWebViewClient(new com.getcapacitor.BridgeWebViewClient(bridge) {
            @Override
            public void onReceivedHttpError(WebView view, android.webkit.WebResourceRequest request, android.webkit.WebResourceResponse response) {
                // the station's own answers (a wrong password's 401, 403, 404) show as they are; only a
                // station that's down (5xx, as Cloudflare answers then) gets the offline page
                if (response.getStatusCode() >= 500) super.onReceivedHttpError(view, request, response);
            }
        });

        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override
            public void handleOnBackPressed() {
                StationAppPlugin app = StationAppPlugin.current;
                if (OnAirService.running) {
                    // never leave a show by accident: the studio asks what to do
                    if (app != null) app.back();
                } else if (bridge.getWebView().canGoBack() && !onStudio()) {
                    bridge.getWebView().goBack();
                } else {
                    // the studio is the app's home: Back there closes the app, not back to the sign-in pages
                    finish();
                }
            }
        });
    }

    /** After a sign-in: past the sign-in page means it worked, so Android offers to save it; back on it, it didn't. */
    private void offerToSave(String url) {
        String[] s = pendingSignIn;
        pendingSignIn = null;
        String path = url == null ? null : Uri.parse(url).getPath();
        // only the station's own next page (not the offline page or the start page) means it worked
        if (s == null || path == null || path.endsWith("/signin") || loadedStation == null || !url.startsWith(loadedStation)) return;
        CredentialManager.create(this).createCredentialAsync(this, new CreatePasswordRequest(s[0], s[1]), null, ContextCompat.getMainExecutor(this),
            new CredentialManagerCallback<CreateCredentialResponse, CreateCredentialException>() {
                @Override
                public void onResult(CreateCredentialResponse r) {
                }

                @Override
                public void onError(CreateCredentialException e) {
                    // not saved (the DJ said no, or there's no password manager): nothing to do
                }
            });
    }

    private boolean onStudio() {
        String url = bridge.getWebView().getUrl();
        return url != null && Uri.parse(url).getPath() != null && Uri.parse(url).getPath().endsWith("/studio");
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        // a test's new station or update address: start again with it (a new station: from the top)
        if (BuildConfig.SW_TEST && new Prefs(this).takeTestExtras(intent)) recreate();
    }

    /**
     * The station's sign-in lasts up to 12 hours (the station's own idle limit) after the app was last
     * used, also when the app is closed (the user's choice, 2026-10-23). The station's sign-in cookie
     * (`sw_session`) is a session cookie, which the phone would drop with the app, and Capacitor's cookie
     * plugin removes session cookies whenever a page starts or stops (each switch between the start page
     * and the studio). So whenever a page has loaded, and when the app leaves the screen, the app makes it
     * a cookie that lasts 12 hours from then. Signing out ends that session on the server.
     */
    static final long KEEP_SIGN_IN_MS = 12 * 60 * 60 * 1000L;
    static final String SIGN_IN_COOKIE = "sw_session";

    /** The station's sign-in cookie, set to last msLeft from now (0 or less: removed). */
    static void keepSignIn(String station, long msLeft) {
        if (station == null) return;
        android.webkit.CookieManager cm = android.webkit.CookieManager.getInstance();
        String all = cm.getCookie(station);
        if (all == null) return;
        Uri u = Uri.parse(station);
        String attrs = "; Path=" + (u.getPath() == null || u.getPath().isEmpty() ? "/" : u.getPath()) + (msLeft > 0 ? "; Max-Age=" + msLeft / 1000 : "; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT")
            + "; HttpOnly; SameSite=Lax" + ("https".equals(u.getScheme()) ? "; Secure" : "");
        for (String pair : all.split(";\\s*")) {
            if (pair.startsWith(SIGN_IN_COOKIE + "=")) cm.setCookie(station, (msLeft > 0 ? pair : SIGN_IN_COOKIE + "=") + attrs);
        }
        cm.flush();
    }

    @Override
    public void onStop() {
        keepSignIn(loadedStation, KEEP_SIGN_IN_MS);
        super.onStop();
    }

    @Override
    public void onDestroy() {
        WebView web = bridge != null ? bridge.getWebView() : null;
        keepSignIn(loadedStation, KEEP_SIGN_IN_MS); // before the cookie plugin removes session cookies
        super.onDestroy();
        // A restart (recreate) keeps the window, so Capacitor never destroys the old page's WebView: it
        // would go on running (a studio page with its mic and sound). Stop it here.
        if (web != null) {
            web.stopLoading();
            if (web.getParent() instanceof android.view.ViewGroup) ((android.view.ViewGroup) web.getParent()).removeView(web);
            web.removeAllViews();
            web.destroy();
        }
    }

    /** Start again on the studio (true) or the start page (false). */
    void restart(boolean studio) {
        new Prefs(this).next(studio ? "studio" : "settings");
        runOnUiThread(this::recreate);
    }

    private class Offline {
        @JavascriptInterface
        public void retry() {
            runOnUiThread(() -> bridge.getWebView().loadUrl(bridge.getAppUrl()));
        }

        @JavascriptInterface
        public void settings() {
            restart(false);
        }
    }
}
