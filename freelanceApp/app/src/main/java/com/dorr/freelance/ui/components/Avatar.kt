package com.dorr.freelance.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material3.Icon
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import coil.compose.AsyncImage
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.ui.kit.settingsAccent

/** The server keeps one file name per account, so a new photo has the same URL: this stamp makes the image loader refetch it. */
object AvatarVersion {
    var value by mutableLongStateOf(0L)

    fun bump() {
        value = System.currentTimeMillis()
    }
}

/** The profile photo in a circle, or a person icon when there is none (or it can't be reached). */
@Composable
fun AvatarImage(url: String?, size: Dp, modifier: Modifier = Modifier, onBrand: Boolean = false) {
    val shown = remember(url, AvatarVersion.value) {
        ApiClient.mediaUrl(url)?.let { if (AvatarVersion.value == 0L) it else "$it${if ('?' in it) "&" else "?"}v=${AvatarVersion.value}" }
    }
    var failed by remember(shown) { mutableStateOf(false) }
    // On the coloured header the empty avatar is white on a lighter tint (the app colour would vanish into it).
    val accent = if (onBrand) androidx.compose.ui.graphics.Color.White else settingsAccent()
    Box(modifier.size(size).clip(CircleShape).background(accent.copy(alpha = if (onBrand) 0.22f else 0.14f)), contentAlignment = Alignment.Center) {
        if (shown != null && !failed) {
            AsyncImage(model = shown, contentDescription = null, contentScale = ContentScale.Crop, onError = { failed = true }, modifier = Modifier.size(size))
        } else {
            Icon(Icons.Rounded.Person, contentDescription = null, tint = accent, modifier = Modifier.size(size / 2))
        }
    }
}
