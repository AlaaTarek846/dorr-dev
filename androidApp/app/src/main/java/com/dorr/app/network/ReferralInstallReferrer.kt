package com.dorr.app.network

import android.content.Context
import com.android.installreferrer.api.InstallReferrerClient
import com.android.installreferrer.api.InstallReferrerStateListener

/**
 * Reads Google Play Install Referrer once. Sideloaded / debug builds get a
 * failure here — that is expected until the app is on Play.
 */
object ReferralInstallReferrer {
    fun capture(context: Context) {
        ReferralStore.attach(context)
        if (ReferralStore.installReferrerAlreadyRead()) return

        val client = InstallReferrerClient.newBuilder(context.applicationContext).build()
        try {
            client.startConnection(object : InstallReferrerStateListener {
                override fun onInstallReferrerSetupFinished(responseCode: Int) {
                    try {
                        if (responseCode == InstallReferrerClient.InstallReferrerResponse.OK) {
                            ReferralStore.remember(client.installReferrer?.installReferrer)
                        }
                    } catch (_: Exception) {
                    } finally {
                        ReferralStore.markInstallReferrerRead()
                        runCatching { client.endConnection() }
                    }
                }

                override fun onInstallReferrerServiceDisconnected() {}
            })
        } catch (_: Exception) {
            ReferralStore.markInstallReferrerRead()
        }
    }
}
