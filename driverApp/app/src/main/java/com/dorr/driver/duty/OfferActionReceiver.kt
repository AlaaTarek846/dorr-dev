package com.dorr.driver.duty

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.widget.Toast
import androidx.core.app.NotificationManagerCompat
import com.dorr.core.network.ApiResult
import com.dorr.core.network.CoreHttp
import com.dorr.core.network.apiCall
import com.dorr.driver.R
import com.dorr.driver.net.DriverBackend
import com.google.gson.JsonObject
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/** Accept / Decline pressed on the request notification, without opening the app first. */
class OfferActionReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val uuid = intent.getStringExtra(DriverDutyService.EXTRA_OFFER) ?: return
        NotificationManagerCompat.from(context).cancel(DriverDutyService.ID_OFFER)
        val done = goAsync()
        CoroutineScope(Dispatchers.IO).launch {
            try {
                if (intent.action == ACCEPT) {
                    val result = apiCall { DriverBackend.api.accept(CoreHttp.newIdempotencyKey(), uuid) }
                    withContext(Dispatchers.Main) {
                        if (result is ApiResult.Ok) {
                            Duty.clearOffer(uuid)
                            Duty.setOnTrip(true)
                            Duty.rideChanged()
                            // The ride screen, right away.
                            context.startActivity(
                                android.content.Intent(context, com.dorr.driver.MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP),
                            )
                        } else {
                            val message = (result as? ApiResult.Failed)?.error?.message ?: context.getString(R.string.error_offline)
                            Toast.makeText(context, message, Toast.LENGTH_LONG).show()
                        }
                    }
                } else {
                    apiCall { DriverBackend.api.decline(uuid, JsonObject()) }
                    Duty.clearOffer(uuid)
                }
            } finally {
                done.finish()
            }
        }
    }

    companion object {
        const val ACCEPT = "com.dorr.driver.offer.ACCEPT"
        const val DECLINE = "com.dorr.driver.offer.DECLINE"
    }
}
