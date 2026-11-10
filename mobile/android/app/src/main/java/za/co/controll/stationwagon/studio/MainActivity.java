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
 *
 * Under the station's screens (the studio, the manager screens, the account page) a second WebView, the bar
 * (round 61), shows the station's `bar` page: what's on air, with a mute button and a volume slider. It stays
 * loaded while the screen above changes, so its sound has no gap. The browser gets the same from the
 * station's frame page; the app's user agent mark tells the station not to send that.
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

        if (studio) bridge.addWebViewListener(new com.getcapacitor.WebViewListener() {
            @Override
            public void onPageLoaded(WebView webView) {
                screenChanged(webView.getUrl());
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

    // ---- The bar (round 61) -------------------------------------------------------------------------------

    static final int BAR_DP = 64, BAR_TALL_DP = 214;
    /** The bar's WebView (made the first time a screen opens), and its state. */
    private WebView bar;
    private boolean barOn, barTall, keyboard;
    private String barRoute = "";

    /** The screen at an address, if it is one of the station's screens with the bar under it (the same list as the station's App::shellRoute). */
    static String barRoute(String base, String url) {
        if (base == null || url == null) return null;
        Uri b = Uri.parse(base), u = Uri.parse(url);
        if (b.getScheme() == null || !b.getScheme().equals(u.getScheme()) || b.getHost() == null || !b.getHost().equals(u.getHost()) || b.getPort() != u.getPort()) return null;
        String bp = b.getPath() == null ? "/" : b.getPath(), up = u.getPath() == null ? "/" : u.getPath();
        if (!up.startsWith(bp)) return null;
        String r = up.substring(bp.length()).replaceAll("/+$", "");
        boolean screen = r.equals("studio") || r.equals("account") || r.equals("manage")
            || (r.startsWith("manage/") && !r.equals("manage/skip") && !r.equals("manage/songprep/original"));
        return screen ? r : null;
    }

    private int dp(int v) { return Math.round(v * getResources().getDisplayMetrics().density); }

    /** The screen above changed: shows or hides the bar (making it the first time), and tells it the screen. */
    private void screenChanged(String url) {
        String route = barRoute(loadedStation, url);
        barOn = route != null;
        if (route != null) {
            barRoute = route;
            if (bar == null) makeBar(); else tellBar();
        }
        layOut();
    }

    @android.annotation.SuppressLint({ "SetJavaScriptEnabled", "AddJavascriptInterface" })
    private void makeBar() {
        WebView web = bridge.getWebView();
        if (!(web.getParent() instanceof android.view.ViewGroup)) return;
        android.view.ViewGroup parent = (android.view.ViewGroup) web.getParent();
        bar = new WebView(this);
        android.webkit.WebSettings ws = bar.getSettings();
        ws.setJavaScriptEnabled(true);
        ws.setDomStorageEnabled(true);
        ws.setMediaPlaybackRequiresUserGesture(false); // it runs (muted) as soon as it opens
        ws.setUserAgentString(ws.getUserAgentString() + " StationwagonStudio/" + BuildConfig.VERSION_NAME + " (Android)");
        bar.setBackgroundColor(android.graphics.Color.TRANSPARENT); // the screen shows around the volume slider
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) bar.setRendererPriorityPolicy(WebView.RENDERER_PRIORITY_IMPORTANT, false);
        bar.addJavascriptInterface(new BarHost(), "SwBarHost");
        final String page = loadedStation + "bar";
        bar.setWebViewClient(new android.webkit.WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView v, android.webkit.WebResourceRequest req) {
                // the bar stays the bar: its links (the station page) open in the phone's browser
                if (req.getUrl().toString().startsWith(page)) return false;
                try { startActivity(new Intent(Intent.ACTION_VIEW, req.getUrl())); } catch (Exception e) { /* no browser */ }
                return true;
            }

            @Override
            public void onPageFinished(WebView v, String url) {
                tellBar();
            }
        });
        androidx.coordinatorlayout.widget.CoordinatorLayout.LayoutParams lp =
            new androidx.coordinatorlayout.widget.CoordinatorLayout.LayoutParams(android.view.ViewGroup.LayoutParams.MATCH_PARENT, dp(BAR_DP));
        lp.gravity = android.view.Gravity.BOTTOM;
        parent.addView(bar, lp);
        // the keyboard up: the bar steps aside, so the page keeps its room for typing
        androidx.core.view.ViewCompat.setOnApplyWindowInsetsListener(parent, (v, insets) -> {
            boolean k = insets.isVisible(androidx.core.view.WindowInsetsCompat.Type.ime());
            if (k != keyboard) { keyboard = k; layOut(); }
            return insets;
        });
        bar.loadUrl(page);
    }

    private void tellBar() {
        if (bar != null) bar.evaluateJavascript("window.SwBar && (window.SwBar.screen(" + org.json.JSONObject.quote(barRoute) + "), window.SwBar.hide("
            + (bar.getVisibility() != android.view.View.VISIBLE) + "))", null);
    }

    /** The screen above the bar (less the bar's 64 dp), and the bar along the foot: taller over the screen's foot while its slider is up. */
    private void layOut() {
        WebView web = bridge.getWebView();
        boolean show = barOn && bar != null && !keyboard;
        android.view.ViewGroup.MarginLayoutParams wl = (android.view.ViewGroup.MarginLayoutParams) web.getLayoutParams();
        int bottom = show ? dp(BAR_DP) : 0;
        if (wl.bottomMargin != bottom) { wl.bottomMargin = bottom; web.setLayoutParams(wl); }
        if (bar == null) return;
        int vis = show ? android.view.View.VISIBLE : android.view.View.GONE;
        if (bar.getVisibility() != vis) {
            bar.setVisibility(vis);
            // a hidden WebView's page still thinks it's seen: tell it, so the strip stops drawing
            bar.evaluateJavascript("window.SwBar && window.SwBar.hide(" + !show + ")", null);
        }
        android.view.ViewGroup.LayoutParams bl = bar.getLayoutParams();
        int h = dp(barTall ? BAR_TALL_DP : BAR_DP);
        if (bl.height != h) { bl.height = h; bar.setLayoutParams(bl); }
        bar.bringToFront();
    }

    private class BarHost {
        /** The bar's volume slider is up (or down again). */
        @JavascriptInterface
        public void tall(boolean t) {
            runOnUiThread(() -> { barTall = t; layOut(); });
        }
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
        if (bar != null) {
            if (bar.getParent() instanceof android.view.ViewGroup) ((android.view.ViewGroup) bar.getParent()).removeView(bar);
            bar.destroy();
            bar = null;
        }
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
