package com.dorr.driver.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.net.DriverBackend
import com.google.android.gms.maps.model.UrlTileProvider
import com.google.maps.android.compose.MapProperties
import com.google.maps.android.compose.MapType
import com.google.maps.android.compose.TileOverlay
import java.net.URL

/**
 * Which map the captain's screens draw (O-03): the admin's provider. With Mapbox, Google's own layer is
 * off and Mapbox's tiles take its place — the camera, the blue dot, the pins and the route stay as they are.
 */
internal object DriverMap {
    var provider by mutableStateOf("google")
        private set
    private var token: String? = null
    private var style: String = "mapbox/streets-v12"
    private var loaded = false

    val mapbox: Boolean get() = provider == "mapbox" && !token.isNullOrBlank()

    suspend fun load() {
        if (loaded) return
        (apiCall { DriverBackend.api.mapConfig() } as? ApiResult.Ok)?.data?.let { c ->
            token = c.mapboxToken
            style = c.mapboxStyle ?: style
            provider = c.provider ?: "google"
            loaded = true
        }
    }

    fun properties(base: MapProperties): MapProperties = if (mapbox) base.copy(mapType = MapType.NONE, mapStyleOptions = null) else base

    fun tiles(): UrlTileProvider = object : UrlTileProvider(512, 512) {
        override fun getTileUrl(x: Int, y: Int, zoom: Int): URL? = runCatching {
            URL("https://api.mapbox.com/styles/v1/$style/tiles/256/$zoom/$x/$y@2x?access_token=$token")
        }.getOrNull()
    }
}

@Composable
internal fun DriverMapboxTiles() {
    if (!DriverMap.mapbox) return
    val provider = remember { DriverMap.tiles() }
    TileOverlay(tileProvider = provider, zIndex = -1f, fadeIn = true)
}

@Composable
internal fun DriverMapboxAttribution(modifier: Modifier) {
    if (!DriverMap.mapbox) return
    Text(
        "© Mapbox © OpenStreetMap", color = Color(0xFF374151), fontSize = 9.sp,
        modifier = modifier.clip(RoundedCornerShape(6.dp)).background(Color.White.copy(alpha = 0.75f)).padding(horizontal = 6.dp, vertical = 2.dp),
    )
}
