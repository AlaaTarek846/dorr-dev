package com.dorr.app.network

import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
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

    private val networkCallback = object : ConnectivityManager.NetworkCallback() {
        override fun onAvailable(network: Network) {
            updateState()
        }

        override fun onLost(network: Network) {
            updateState()
        }

        override fun onCapabilitiesChanged(network: Network, networkCapabilities: NetworkCapabilities) {
            val hasInternet = networkCapabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
            _isOnline.value = hasInternet
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

    fun isCurrentlyOnline(): Boolean {
        val cm = connectivityManager ?: return false
        val activeNetwork = cm.activeNetwork ?: return false
        val caps = cm.getNetworkCapabilities(activeNetwork) ?: return false
        return caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
    }

    fun updateState() {
        _isOnline.value = isCurrentlyOnline()
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
    }
}
