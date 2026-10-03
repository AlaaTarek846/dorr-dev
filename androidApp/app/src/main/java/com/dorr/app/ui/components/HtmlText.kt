package com.dorr.app.ui.components

import android.widget.TextView
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.text.HtmlCompat
import com.dorr.app.ui.screens.profile.settingsInk

/**
 * Renders admin-authored HTML (p, h3, ul, strong, …) as formatted text instead of raw tags.
 */
@Composable
fun HtmlText(
    html: String,
    modifier: Modifier = Modifier,
) {
    val ink = settingsInk()
    AndroidView(
        modifier = modifier,
        factory = { context ->
            TextView(context).apply {
                setTextColor(ink.toArgb())
                textSize = 14f
                setLineSpacing(0f, 1.35f)
            }
        },
        update = { view ->
            view.setTextColor(ink.toArgb())
            view.text = HtmlCompat.fromHtml(html, HtmlCompat.FROM_HTML_MODE_COMPACT)
        },
    )
}
