package com.dorr.driver.ui

import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Verified
import androidx.compose.material3.Icon
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import com.dorr.driver.R

/** The blue tick of a verified Dorr account (the same one the DORR app shows). */
val VerifiedBlue = Color(0xFF0EA5E9)

/**
 * The rider's account verification tick, after their name (docs/verification_tasks.md AV-028) — nothing at all when
 * the rider isn't verified. The server says who is (`is_verified`); the app never works it out.
 */
@Composable
fun RiderTick(verified: Boolean, size: Dp = 16.dp, tint: Color = VerifiedBlue) {
    if (!verified) return
    Icon(
        Icons.Filled.Verified,
        contentDescription = stringResource(R.string.rider_verified),
        tint = tint,
        modifier = Modifier.padding(start = 4.dp).size(size),
    )
}
