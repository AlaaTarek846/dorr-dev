package com.dorr.freelance.net

import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.setValue
import com.dorr.core.network.ApiResult

/**
 * A list that fills itself page by page while the freelancer scrolls (nothing on a timer): [reload] reads the first page and
 * replaces what is shown, [loadMore] adds the next page under it. One request at a time; a row that moved between pages is not
 * shown twice. A page shorter than [perPage] is the last one.
 *
 * The screen calls [loadMore] when the end comes near (`Page(onNearEnd = …)` for a scrolling page, [onNearEnd] for a lazy list)
 * and [reload] when it opens, when a notice arrives and when the connection returns.
 */
class PagedRows<T>(
    private val perPage: Int,
    private val key: (T) -> Any,
    private val fetch: suspend (page: Int) -> ApiResult<List<T>>,
) {
    val rows = mutableStateListOf<T>()

    /** The first page has been answered (rows or an error) — before that the screen shows its spinner. */
    var loaded by mutableStateOf(false)
        private set

    var loading by mutableStateOf(false)
        private set

    var hasMore by mutableStateOf(true)
        private set

    /** The last page that failed, for the screen to turn into text; cleared by the next success. */
    var failure by mutableStateOf<ApiResult<*>?>(null)
        private set

    private var page = 0

    suspend fun reload() = load(1)

    suspend fun loadMore() {
        if (loaded && hasMore && failure == null) load(page + 1)
    }

    /** After a failed page, the next scroll tries it again. */
    fun retry() {
        failure = null
    }

    private suspend fun load(next: Int) {
        if (loading) return
        loading = true
        // `loading` is cleared even when the screen cancels the call, so the list can't stay on its spinner.
        try {
            when (val result = fetch(next)) {
                is ApiResult.Ok -> {
                    failure = null
                    if (next == 1) rows.clear()
                    rows.addAll(result.data.filter { row -> rows.none { key(it) == key(row) } })
                    page = next
                    hasMore = result.data.size >= perPage
                }
                else -> failure = result
            }
            loaded = true
        } finally {
            loading = false
        }
    }

    /** Changes one row where it is (an answer to an invitation) without reading anything again. */
    fun update(match: (T) -> Boolean, change: (T) -> T) {
        val i = rows.indexOfFirst(match)
        if (i >= 0) rows[i] = change(rows[i])
    }
}
