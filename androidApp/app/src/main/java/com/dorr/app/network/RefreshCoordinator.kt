package com.dorr.app.network

import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.withTimeoutOrNull

/**
 * The shared brain of pull-to-refresh, so no screen needs refresh code of its own.
 *
 *  - [pullTick] goes up on every pull. [collectReconnectTick] folds it in, so every screen that already
 *    reloads when the connection comes back (`LaunchedEffect(…, collectReconnectTick())`) reloads on a pull too —
 *    the screens on show only, nothing restarts.
 *  - [inFlight] counts the API requests under way (an OkHttp interceptor in [ApiClient] reports them), which is how
 *    the pull indicator knows when the reload is done: [refresh] returns once they have all finished.
 */
object RefreshCoordinator {
    private val _pullTick = MutableStateFlow(0)
    val pullTick: StateFlow<Int> = _pullTick.asStateFlow()

    private val _inFlight = MutableStateFlow(0)
    val inFlight: StateFlow<Int> = _inFlight.asStateFlow()

    internal fun requestStarted() = _inFlight.update { it + 1 }

    internal fun requestFinished() = _inFlight.update { (it - 1).coerceAtLeast(0) }

    /**
     * Asks the screens on show to reload, then waits until their requests have finished (or [timeoutMs] passes, so a
     * hung request can never keep the indicator spinning). The short pauses let the reloads begin, and let a reload
     * that starts a second request (a list, then its details) finish too.
     */
    suspend fun refresh(timeoutMs: Long = 12_000) {
        _pullTick.update { it + 1 }
        delay(MIN_VISIBLE_MS)
        withTimeoutOrNull(timeoutMs) {
            do {
                inFlight.first { it == 0 }
                delay(SETTLE_MS)
            } while (inFlight.value != 0)
        }
    }

    private const val MIN_VISIBLE_MS = 450L
    private const val SETTLE_MS = 150L
}
