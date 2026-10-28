package com.dorr.app.ui.screens.freelance

import androidx.compose.foundation.ScrollState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyListState
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.dorr.app.network.ApiEnvelope
import com.dorr.app.ui.screens.profile.settingsAccent
import kotlinx.coroutines.CancellationException

/**
 * A list that fills itself page by page while the client scrolls (nothing on a timer): [reload] reads the first page and replaces
 * what is shown, [loadMore] adds the next page under it. One request at a time; a row that moved between pages is not shown twice.
 * The server says whether another page exists (`pagination.has_more_pages`).
 *
 * The screen calls [loadMore] when the end comes near ([LazyListState.NearEnd]) and [reload] when it opens, when a notice arrives
 * and when the connection returns.
 */
class PagedRows<T>(
    private val key: (T) -> Any,
    private val fetch: suspend (page: Int) -> ApiEnvelope<List<T>>,
) {
    val rows = mutableStateListOf<T>()

    /** The first page has been answered (rows or an error) — before that the screen shows its spinner. */
    var loaded by mutableStateOf(false)
        private set

    var loading by mutableStateOf(false)
        private set

    var hasMore by mutableStateOf(false)
        private set

    /** The text of the last page that failed; cleared by the next success. */
    var error by mutableStateOf<String?>(null)
        private set

    private var page = 0

    suspend fun reload(generic: String) = load(1, generic)

    suspend fun loadMore(generic: String) {
        if (loaded && hasMore && error == null) load(page + 1, generic)
    }

    /** After a failed page, the next scroll tries it again. */
    fun clearError() {
        error = null
    }

    private suspend fun load(next: Int, generic: String) {
        if (loading) return
        loading = true
        try {
            val envelope = fetch(next)
            val data = envelope.data.orEmpty()
            if (next == 1) rows.clear()
            rows.addAll(data.filter { row -> rows.none { key(it) == key(row) } })
            page = next
            hasMore = envelope.pagination?.hasMorePages == true
            error = null
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message(generic)
        } finally {
            // Also when the screen cancels the call: the list must never stay on its spinner.
            loading = false
        }
        loaded = true
    }

    /** Changes one row where it is, without reading anything again. */
    fun update(match: (T) -> Boolean, change: (T) -> T) {
        val i = rows.indexOfFirst(match)
        if (i >= 0) rows[i] = change(rows[i])
    }
}

/** Asks for the next page when the last few rows of a lazy list are on screen — and again each time the list grows. */
@Composable
fun LazyListState.NearEnd(rowsLoaded: Int, onNearEnd: () -> Unit) {
    val near by remember(this) {
        derivedStateOf {
            val last = layoutInfo.visibleItemsInfo.lastOrNull()?.index ?: -1
            layoutInfo.totalItemsCount > 0 && last >= layoutInfo.totalItemsCount - 4
        }
    }
    val latest by rememberUpdatedState(onNearEnd)
    LaunchedEffect(near, rowsLoaded) { if (near) latest() }
}

/** Same for a page that scrolls as a whole column. */
@Composable
fun ScrollState.NearEnd(rowsLoaded: Int, onNearEnd: () -> Unit) {
    val near by remember(this) { derivedStateOf { maxValue - value <= 1200 } }
    val latest by rememberUpdatedState(onNearEnd)
    LaunchedEffect(near, rowsLoaded, maxValue) { if (near) latest() }
}

/** Under a paged list: a small spinner while the next page comes (or may come). */
@Composable
fun PagedSpinner(paged: PagedRows<*>) {
    if (paged.loaded && paged.rows.isNotEmpty() && (paged.loading || paged.hasMore) && paged.error == null) {
        Box(Modifier.fillMaxWidth().padding(12.dp), contentAlignment = Alignment.Center) {
            CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(22.dp))
        }
    }
}
