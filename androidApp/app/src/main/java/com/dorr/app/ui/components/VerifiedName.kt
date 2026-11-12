package com.dorr.app.ui.components

import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.dp
import com.dorr.app.R

/** The account verification ✔ — the same blue tick channels already wear, everywhere a verified name shows. */
val VerifiedBlue = Color(0xFF0EA5E9)

/** The ✔ alone, after a name (docs/verification_tasks.md AV-027). The server says who is verified (`is_verified`). */
@Composable
fun VerifiedTick(size: Dp = 14.dp, tint: Color = VerifiedBlue, modifier: Modifier = Modifier) {
    Icon(
        Icons.Rounded.Verified,
        contentDescription = stringResource(R.string.verification_verified_badge),
        tint = tint,
        modifier = modifier.padding(start = 4.dp).size(size),
    )
}

/**
 * A person's name with the ✔ after it when [verified] — the name keeps its own style and shrinks first,
 * so the tick never gets pushed off the row.
 */
@Composable
fun VerifiedName(
    name: String,
    verified: Boolean,
    modifier: Modifier = Modifier,
    color: Color = Color.Unspecified,
    fontSize: TextUnit = TextUnit.Unspecified,
    fontWeight: FontWeight? = null,
    fontFamily: FontFamily? = null,
    maxLines: Int = 1,
    tickSize: Dp = 14.dp,
    tickTint: Color = VerifiedBlue,
) {
    Row(modifier, verticalAlignment = Alignment.CenterVertically) {
        Text(
            name, color = color, fontSize = fontSize, fontWeight = fontWeight, fontFamily = fontFamily,
            maxLines = maxLines, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false),
        )
        if (verified) VerifiedTick(tickSize, tickTint)
    }
}
