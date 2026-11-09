package com.dorr.freelance.ui.screens.workroom

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyListState
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.snapshotFlow
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.freelance.R
import com.dorr.freelance.net.PagedRows
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.EmptyState
import com.dorr.freelance.ui.components.PagedFooter
import com.dorr.freelance.ui.components.SkeletonList
import com.google.gson.JsonObject
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsMut

/**
 * One list of the Workroom (tasks, files, decisions, the timeline): pages of [WORK_PAGE] rows, read again as a whole on open, on a live
 * event, on a notice and when the connection returns. A read that arrives while another is still on its way waits for it (so a filter
 * changed in the middle of a read is never lost), and nothing here runs on a timer.
 */
internal class WorkFeed<T>(key: (T) -> Any, fetch: suspend (page: Int) -> ApiResult<List<T>>) {
    val paged = PagedRows(WORK_PAGE, key, fetch)

    suspend fun reload() {
        snapshotFlow { paged.loading }.first { !it }
        paged.reload()
    }

    suspend fun more() = paged.loadMore()
}

/** A text field of a live event's data. */
internal fun JsonObject.text(key: String): String? = runCatching { get(key)?.takeIf { it.isJsonPrimitive }?.asString }.getOrNull()

/** Asks for the next page when the end of a lazy list comes near. */
@Composable
internal fun NearEnd(state: LazyListState, onNearEnd: () -> Unit) {
    val latest by rememberUpdatedState(onNearEnd)
    val near by remember(state) {
        derivedStateOf {
            val info = state.layoutInfo
            info.totalItemsCount > 0 && (info.visibleItemsInfo.lastOrNull()?.index ?: 0) >= info.totalItemsCount - 4
        }
    }
    LaunchedEffect(near, state.layoutInfo.totalItemsCount) { if (near) latest() }
}

/** The round «add» button of a tab (bottom corner, over the list). */
@Composable
internal fun BoxScopeFab(label: String, icon: ImageVector = Icons.Rounded.Add, modifier: Modifier = Modifier, onClick: () -> Unit) {
    Row(
        modifier.shadow(10.dp, RoundedCornerShape(999.dp), spotColor = settingsAccent().copy(alpha = 0.4f)).clip(RoundedCornerShape(999.dp)).background(settingsAccent())
            .clickable(onClick = onClick).padding(horizontal = 18.dp, vertical = 14.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Icon(icon, contentDescription = null, tint = Color.White, modifier = Modifier.size(20.dp))
        Text(label, color = Color.White, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
    }
}

/**
 * The body of a Workroom tab: [header] over the rows (a spinner before the first read, an empty page, the rows, a footer for the next
 * page) and a round button when the person may add. The first read's failure is shown with «try again».
 */
@Composable
internal fun <T> FeedList(
    feed: WorkFeed<T>,
    keyOf: (T) -> Any,
    empty: Triple<ImageVector, String, String>,
    modifier: Modifier = Modifier,
    fabLabel: String? = null,
    onFab: (() -> Unit)? = null,
    header: @Composable () -> Unit = {},
    row: @Composable (T) -> Unit,
) {
    val paged = feed.paged
    val scope = rememberCoroutineScope()
    val listState = rememberLazyListState()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    NearEnd(listState) { scope.launch { feed.more() } }
    Box(modifier.fillMaxSize()) {
        LazyColumn(
            Modifier.fillMaxSize(), state = listState,
            contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 4.dp, bottom = if (onFab != null) 104.dp else 28.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            item(key = "head") { header() }
            when {
                !paged.loaded && paged.failure == null -> item(key = "loading") { SkeletonList(count = 3) }
                paged.rows.isEmpty() && paged.failure != null -> item(key = "failed") {
                    Text(
                        (paged.failure?.errorText(offlineText) ?: genericError) + "  ·  " + stringResource(R.string.common_retry), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                        modifier = Modifier.fillMaxWidth().clickable { paged.retry(); scope.launch { feed.reload() } }.padding(24.dp),
                    )
                }
                paged.rows.isEmpty() -> item(key = "empty") { EmptyState(icon = empty.first, title = empty.second, body = empty.third, modifier = Modifier.padding(top = 28.dp)) }
                else -> items(paged.rows, key = { keyOf(it) }) { row(it) }
            }
            item(key = "foot") { PagedFooter(paged) { scope.launch { feed.more() } } }
        }
        if (onFab != null && fabLabel != null) BoxScopeFab(fabLabel, modifier = Modifier.align(Alignment.BottomEnd).padding(16.dp), onClick = onFab)
    }
}
