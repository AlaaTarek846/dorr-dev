package com.dorr.app.ui.components

import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.width
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.unit.Dp
import com.dorr.app.R

/**
 * The Dorr wordmark. [onDark] picks the white-text variant for dark/coloured
 * backgrounds (splash); the default navy variant is for light surfaces.
 * Height follows the image's own aspect ratio.
 */
@Composable
fun DorrLogo(width: Dp, modifier: Modifier = Modifier, onDark: Boolean = false) {
    Image(
        painter = painterResource(if (onDark) R.drawable.dorr_logo_dark else R.drawable.dorr_logo_light),
        contentDescription = null,
        contentScale = ContentScale.Fit,
        modifier = modifier.width(width),
    )
}
