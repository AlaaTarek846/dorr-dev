package com.dorr.freelance.ui.components

import androidx.compose.foundation.ScrollState
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.freelance.R
import com.dorr.freelance.net.PagedRows
import com.dorr.freelance.ui.kit.settingsAccent

/**
 * Asks for the next page when a scrolling page comes near its bottom — also when everything loaded fits on the screen, and again each
 * time the page grows. [onNearEnd] must ignore a call it can't use (no more pages, a page already on its way): see [PagedRows.loadMore].
 */
@Composable
fun NearEndEffect(scroll: ScrollState, onNearEnd: (() -> Unit)?) {
    if (onNearEnd == null) return
    val px = with(LocalDensity.current) { 400.dp.toPx() }
    val near by remember(scroll) { derivedStateOf { scroll.maxValue - scroll.value <= px } }
    val latest by rememberUpdatedState(onNearEnd)
    LaunchedEffect(near, scroll.maxValue) { if (near) latest() }
}

/** Under a paged list: a small spinner while the next page comes, or «try again» when it failed. */
@Composable
fun PagedFooter(paged: PagedRows<*>, onRetry: () -> Unit) {
    if (!paged.loaded || paged.rows.isEmpty()) return
    when {
        paged.failure != null -> Text(
            stringResource(R.string.common_retry), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
            modifier = Modifier.clickable { paged.retry(); onRetry() }.padding(12.dp),
        )
        paged.loading || paged.hasMore -> Row(horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically) {
            CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(22.dp))
        }
    }
}
