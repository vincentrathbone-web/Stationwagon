package za.co.controll.stationwagon.studio;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageInstaller;
import com.getcapacitor.JSObject;

/**
 * Hears from Android's installer: asks the DJ to confirm when Android wants that (the first update),
 * tells the start page when an update failed, and opens the app again once it's been replaced.
 */
public class InstallReceiver extends BroadcastReceiver {
    static final String ACTION = "za.co.controll.stationwagon.studio.INSTALL_STATUS";

    @Override
    public void onReceive(Context ctx, Intent intent) {
        if (Intent.ACTION_MY_PACKAGE_REPLACED.equals(intent.getAction())) {
            Intent open = new Intent(ctx, MainActivity.class).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            try { ctx.startActivity(open); } catch (RuntimeException ignored) { /* Android may not allow it; the app is updated either way */ }
            return;
        }
        if (!ACTION.equals(intent.getAction())) return;
        int status = intent.getIntExtra(PackageInstaller.EXTRA_STATUS, PackageInstaller.STATUS_FAILURE);
        if (status == PackageInstaller.STATUS_PENDING_USER_ACTION) {
            Intent confirm = intent.getParcelableExtra(Intent.EXTRA_INTENT);
            if (confirm != null) {
                confirm.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
                ctx.startActivity(confirm);
            }
            return;
        }
        if (status != PackageInstaller.STATUS_SUCCESS) {
            String why = intent.getStringExtra(PackageInstaller.EXTRA_STATUS_MESSAGE);
            StationAppPlugin app = StationAppPlugin.current;
            if (app != null) app.updateFailed(status == PackageInstaller.STATUS_FAILURE_ABORTED ? "The update was cancelled." : "Android didn’t install the update" + (why != null ? ": " + why : "."));
        }
    }
}
