package com.dorr.app.ui.components

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.layout.positionInWindow
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.unit.dp

/**
 * The app's paging rule (owner 2026-11-11): lists come 15 at a time and the next 15 load by themselves — no «show more»
 * button. Put this under the last row of a list that sits in a normal scrolling column: once it comes within about a
 * screen-fifth of the bottom edge it asks for the next page, one request at a time, and shows a small spinner while that
 * page is on its way. A short page that leaves it on screen keeps asking until the screen is full or [hasMore] is false.
 * (A LazyColumn does the same from its own list state — see NotificationsScreen.)
 */
@Composable
fun AutoLoadMore(hasMore: Boolean, loading: Boolean, color: Color, modifier: Modifier = Modifier, onLoadMore: () -> Unit) {
    if (!hasMore) return
    val view = LocalView.current
    val load by rememberUpdatedState(onLoadMore)
    var near by remember { mutableStateOf(false) }
    Box(
        modifier
            .fillMaxWidth()
            .padding(top = 10.dp)
            .height(28.dp)
            // Fires on every scroll, so «near the end» follows the finger.
            .onGloballyPositioned { near = it.positionInWindow().y < view.height + view.height / 5 },
        contentAlignment = Alignment.Center,
    ) {
        if (loading) CircularProgressIndicator(color = color, strokeWidth = 2.5.dp, modifier = Modifier.size(24.dp))
    }
    LaunchedEffect(near, loading) { if (near && !loading) load() }
}
