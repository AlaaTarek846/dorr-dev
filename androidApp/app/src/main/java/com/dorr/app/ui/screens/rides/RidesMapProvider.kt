package com.dorr.app.ui.screens.rides

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
import com.dorr.app.network.ApiClient
import com.google.android.gms.maps.model.UrlTileProvider
import com.google.maps.android.compose.MapProperties
import com.google.maps.android.compose.MapType
import com.google.maps.android.compose.TileOverlay
import java.net.URL

/**
 * Which map the rides screens draw (O-03): the provider chosen in admin → Rides → Settings. With Mapbox,
 * Google's own map layer is switched off and Mapbox's tiles are drawn in its place — the camera, the pins,
 * the routes and the moving car stay exactly as they are. Read once per app run (`map-config`).
 */
internal object RidesMap {
    var provider by mutableStateOf("google")
        private set
    private var token: String? = null
    private var style: String = "mapbox/streets-v12"
    private var loaded = false

    val mapbox: Boolean get() = provider == "mapbox" && !token.isNullOrBlank()

    suspend fun load() {
        if (loaded) return
        runCatching { ApiClient.rides.mapConfig(ridesAuth()).data }.getOrNull()?.let { c ->
            token = c.mapboxToken
            style = c.mapboxStyle ?: style
            provider = c.provider ?: "google"
            loaded = true
        }
    }

    /** Google's layer off when Mapbox draws the map. */
    fun properties(base: MapProperties): MapProperties = if (mapbox) base.copy(mapType = MapType.NONE) else base

    fun tiles(): UrlTileProvider = object : UrlTileProvider(512, 512) {
        override fun getTileUrl(x: Int, y: Int, zoom: Int): URL? = runCatching {
            URL("https://api.mapbox.com/styles/v1/$style/tiles/256/$zoom/$x/$y@2x?access_token=$token")
        }.getOrNull()
    }
}

/** Inside a GoogleMap { }: the Mapbox tiles under everything else, when Mapbox is the provider. */
@Composable
internal fun MapboxTilesIfChosen() {
    if (!RidesMap.mapbox) return
    val provider = remember { RidesMap.tiles() }
    TileOverlay(tileProvider = provider, zIndex = -1f, fadeIn = true)
}

/** Mapbox's attribution (required with its tiles), small, in a corner of the map. */
@Composable
internal fun MapboxAttribution(modifier: Modifier) {
    if (!RidesMap.mapbox) return
    Text(
        "© Mapbox © OpenStreetMap", color = Color(0xFF374151), fontSize = 9.sp,
        modifier = modifier.clip(RoundedCornerShape(6.dp)).background(Color.White.copy(alpha = 0.75f)).padding(horizontal = 6.dp, vertical = 2.dp),
    )
}
