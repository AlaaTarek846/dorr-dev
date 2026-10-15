package com.dorr.driver.live

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import androidx.core.app.NotificationCompat
import com.dorr.driver.R
import com.dorr.driver.net.DriverBackend
import com.onesignal.notifications.INotificationReceivedEvent
import com.onesignal.notifications.INotificationServiceExtension
import kotlinx.coroutines.runBlocking
import kotlinx.coroutines.withTimeoutOrNull

/**
 * Runs for every push the moment it arrives — even with the app closed (registered in the manifest as
 * OneSignal's NotificationServiceExtension).
 *  - A ride request is not shown as a plain banner: the server's request is read once and rung as the
 *    full-screen request with Accept / Decline ([com.dorr.driver.duty.DriverDutyService.ring]).
 *  - Everything else (wallet, support, Wasl…) pops down over the screen on the captain's own channel,
 *    in the app's colour and icon.
 *  - Nobody signed in on this phone: nothing is shown.
 */
class DriverPushExtension : INotificationServiceExtension {
    override fun onNotificationReceived(event: INotificationReceivedEvent) {
        val context = event.context
        if (!DriverBackend.isReady() || !DriverBackend.session.isSignedIn) {
            event.preventDefault()
            return
        }
        val data = event.notification.additionalData
        if (data?.optString("type") == "ride_offer") {
            event.preventDefault()
            // The app is on screen: its card shows the request already.
            if (DriverLive.inForeground) {
                DriverLive.requestSync()
                return
            }
            // One read of the request, then it rings — the process may have been asleep a moment ago.
            runBlocking { withTimeoutOrNull(10_000) { DriverLive.syncOffers(context) } }
            return
        }
        event.notification.setExtender { builder -> popDown(context, builder) }
    }

    companion object {
        const val CHANNEL_GENERAL = "driver_notifications"

        fun popDown(context: Context, builder: NotificationCompat.Builder): NotificationCompat.Builder {
            val manager = context.getSystemService(NotificationManager::class.java)
            if (manager != null && manager.getNotificationChannel(CHANNEL_GENERAL) == null) {
                manager.createNotificationChannel(
                    NotificationChannel(CHANNEL_GENERAL, context.getString(R.string.push_channel_general), NotificationManager.IMPORTANCE_HIGH).apply {
                        enableVibration(true)
                        lockscreenVisibility = Notification.VISIBILITY_PRIVATE
                    },
                )
            }
            return builder.setChannelId(CHANNEL_GENERAL).setPriority(NotificationCompat.PRIORITY_HIGH)
                .setSmallIcon(R.drawable.ic_stat_driver).setColor(0xFF001B53.toInt())
        }
    }
}
