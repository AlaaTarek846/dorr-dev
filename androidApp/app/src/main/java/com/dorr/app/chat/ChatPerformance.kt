package com.dorr.app.chat

import android.app.ActivityManager
import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue

/**
 * Data saver (spec 82): on mobile data only, or always. While it's on —
 *  - others' photos don't download by themselves: a tiny preview and a download button (my own,
 *    and any already on the phone, show as usual),
 *  - clips sent as GIFs don't play by themselves,
 *  - photos and videos I send go lighter,
 *  - calls send and receive less video (360p, 15 fps).
 */
object ChatDataSaver {
    private const val PREFS = "chat_data"

    /** off · mobile (on mobile data only) · always */
    var mode by mutableStateOf("off")
        private set

    /** On mobile data (a metered connection) right now. */
    var metered by mutableStateOf(false)
        private set

    val active: Boolean get() = mode == "always" || (mode == "mobile" && metered)

    /** Photos I chose to download while saving data (until the app closes; then they're on the phone). */
    val loaded = mutableStateMapOf<String, Boolean>()

    private var attached = false

    fun attach(context: Context) {
        if (attached) return
        attached = true
        mode = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString("mode", "off") ?: "off"
        val cm = context.getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager ?: return
        metered = cm.isActiveNetworkMetered
        runCatching {
            cm.registerDefaultNetworkCallback(object : ConnectivityManager.NetworkCallback() {
                override fun onCapabilitiesChanged(network: Network, caps: NetworkCapabilities) {
                    metered = !caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_NOT_METERED)
                }

                override fun onLost(network: Network) {
                    metered = cm.isActiveNetworkMetered
                }
            })
        }
    }

    fun setMode(context: Context, value: String) {
        mode = value
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().putString("mode", value).apply()
    }
}

/**
 * Lite mode for modest phones (spec 87): by itself on a phone with little memory, or on / off by
 * choice. While it's on — no bubble animations, no blur (a plain veil instead), still GIFs and
 * stickers, no clips playing by themselves, no camera backgrounds in calls, and lighter call video.
 */
object ChatLite {
    private const val PREFS = "chat_lite"

    /** auto · on · off */
    var mode by mutableStateOf("auto")
        private set

    /** The phone's memory, in GB (shown in the settings). */
    var ramGb = 0f
        private set

    /** A phone with little memory (Android's "low RAM", or 3 GB or less). */
    var weakPhone = false
        private set

    val active: Boolean get() = mode == "on" || (mode == "auto" && weakPhone)

    private var attached = false

    fun attach(context: Context) {
        if (attached) return
        attached = true
        mode = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString("mode", "auto") ?: "auto"
        val am = context.getSystemService(Context.ACTIVITY_SERVICE) as? ActivityManager ?: return
        val info = ActivityManager.MemoryInfo().also { am.getMemoryInfo(it) }
        ramGb = info.totalMem / 1_073_741_824f
        // A "3 GB" phone reports a little under 3 GiB to apps.
        weakPhone = am.isLowRamDevice || info.totalMem < 3_300_000_000L
    }

    fun setMode(context: Context, value: String) {
        mode = value
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().putString("mode", value).apply()
    }
}

/** Calls on a light budget: data saver or lite mode. */
internal val lightCalls: Boolean get() = ChatDataSaver.active || ChatLite.active

/** Clips that play by themselves (GIFs): not while saving data or in lite mode. */
internal val autoplayClips: Boolean get() = !ChatDataSaver.active && !ChatLite.active
