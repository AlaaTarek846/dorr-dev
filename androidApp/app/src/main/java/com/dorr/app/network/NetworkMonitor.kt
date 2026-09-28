package com.dorr.app.network

import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.platform.LocalContext
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.withContext

/**
 * Monitors device network connectivity state using [ConnectivityManager].
 * Exposes a reactive [isOnline] StateFlow that updates immediately when connection
 * is lost or restored.
 */
class NetworkMonitor private constructor(context: Context) {

    private val connectivityManager =
        context.applicationContext.getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager

    private val _isOnline = MutableStateFlow(isCurrentlyOnline())
    val isOnline: StateFlow<Boolean> = _isOnline.asStateFlow()

    /**
     * Bumps every time the device goes from offline back to online. Screens
     * that load remote data collect this and re-run their request, which is
     * what refreshes "the screen I'm standing on" the moment the connection
     * returns — without any screen needing to know about any other.
     */
    private val _reconnectTick = MutableStateFlow(0)
    val reconnectTick: StateFlow<Int> = _reconnectTick.asStateFlow()

    private val networkCallback = object : ConnectivityManager.NetworkCallback() {
        override fun onAvailable(network: Network) {
            updateState()
        }

        override fun onLost(network: Network) {
            updateState()
        }

        override fun onCapabilitiesChanged(network: Network, networkCapabilities: NetworkCapabilities) {
            // Recompute from the active network instead of trusting this
            // callback's network: during handoffs this fires for a network
            // that is already gone, which used to flap the offline screen.
            updateState()
        }
    }

    init {
        try {
            connectivityManager?.registerDefaultNetworkCallback(networkCallback)
        } catch (_: Exception) {
            // Fallback for custom or restricted environments
            try {
                val request = NetworkRequest.Builder()
                    .addCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
                    .build()
                connectivityManager?.registerNetworkCallback(request, networkCallback)
            } catch (_: Exception) {
                // Keep initial state if callback registration fails
            }
        }
        updateState()
    }

    /**
     * True only when the OS has actually validated internet access on the
     * active network — not merely that a network exists. A Wi-Fi router with
     * no upstream, or a captive portal, reports NET_CAPABILITY_INTERNET but
     * never VALIDATED, and the old check called that "online", so the offline
     * screen never appeared while every request failed.
     */
    fun isCurrentlyOnline(): Boolean {
        val cm = connectivityManager ?: return false
        val activeNetwork = cm.activeNetwork ?: return false
        val caps = cm.getNetworkCapabilities(activeNetwork) ?: return false
        return caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) &&
            caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED)
    }

    fun updateState() {
        setOnline(isCurrentlyOnline())
    }

    /**
     * Every write goes through here so the reconnect tick can only fire on a
     * genuine offline → online transition — never on a repeated "still online"
     * signal, which would otherwise reload every screen on each heartbeat.
     */
    private fun setOnline(value: Boolean) {
        if (value && !_isOnline.value) {
            _reconnectTick.value++
        }
        _isOnline.value = value
    }

    /**
     * Called by the HTTP layer when a real request round-trips: proof of
     * connectivity that overrides any stale OS state (e.g. validation still
     * pending right after a handoff).
     */
    fun reportReachable() {
        setOnline(true)
    }

    /**
     * Called by the HTTP layer when a request fails before any HTTP response
     * (no route to the server). Timeouts and TLS errors are deliberately NOT
     * reported here — a slow or misconfigured server is a different problem
     * from "no internet" and keeps its per-screen error.
     */
    fun reportUnreachable() {
        setOnline(false)
    }

    suspend fun refresh(): Boolean = withContext(Dispatchers.IO) {
        val online = isCurrentlyOnline()
        _isOnline.value = online
        online
    }

    companion object {
        @Volatile
        private var instance: NetworkMonitor? = null

        fun getInstance(context: Context): NetworkMonitor {
            return instance ?: synchronized(this) {
                instance ?: NetworkMonitor(context.applicationContext).also { instance = it }
            }
        }

        /**
         * The shared monitor without needing a Context — for the HTTP layer,
         * which has none. Null until MainActivity creates it at startup.
         */
        fun current(): NetworkMonitor? = instance
    }
}

/**
 * Collects the reconnect tick for "reload on reconnect" screens. Drop the
 * returned value into the keys of the loading LaunchedEffect and the request
 * re-runs the moment the connection returns:
 *
 *     val reconnectTick = collectReconnectTick()
 *     LaunchedEffect(filter, page, reconnectTick) { load() }
 *
 * For paged lists also reset to the first page on tick, otherwise the reload
 * appends a fresh page 1 onto stale rows.
 */
@Composable
fun collectReconnectTick(): Int {
    val context = LocalContext.current
    val monitor = remember(context) { NetworkMonitor.getInstance(context) }
    val tick by monitor.reconnectTick.collectAsState()
    return tick
}
