package com.dorr.app.ui.screens.wallet

import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.runtime.staticCompositionLocalOf
import com.dorr.app.network.ApiClient
import com.dorr.app.network.TransferRecipientDto
import com.dorr.app.network.WalletBalanceDto
import com.dorr.app.network.WalletTransactionDto
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** What the wallet's own screen stack can show (the preview's `push(buildX)` pages). */
sealed interface WaRoute {
    data object Home : WaRoute
    data object Topup : WaRoute
    data object Transfer : WaRoute
    data class TransferConfirm(val who: TransferRecipientDto) : WaRoute
    data object MyQr : WaRoute
    data object Scanner : WaRoute
    data object History : WaRoute
    data object PinSettings : WaRoute

    /** The payment screen (CheckoutScreen) — its state rides in the route so a top-up and back keeps it. */
    data class Checkout(val state: CheckoutState) : WaRoute

    /** Top up a given amount (what's missing on the payment screen). */
    data class TopupAmount(val minor: Long) : WaRoute
}

/** Bottom sheets that float over any wallet page. */
sealed interface WaSheet {
    data object Explain : WaSheet
    data class Tx(val tx: WalletTransactionDto) : WaSheet
    data class Pin(
        val subtitle: String,
        val onSubmit: suspend (String) -> PinOutcome,
        val onClose: (String) -> Unit,
        /** A temporary PIN lock already running when the sheet opens (epoch ms) — shows the countdown. */
        val lockedUntil: Long? = null,
    ) : WaSheet
}

/** What the PIN pad should do after the protected action ran. */
sealed interface PinOutcome {
    /** Action succeeded — close the sheet. */
    data object Done : PinOutcome

    /** PIN problem (wrong/locked) — stay open and show [message] so the user can retry. */
    data class Retry(val message: String) : PinOutcome

    /** Any other failure — close the sheet; the caller shows [message] on its own page. */
    data class Close(val message: String) : PinOutcome
}

/** PIN-related failures the pad keeps open for; everything else is the caller's to show. */
internal val PIN_ERROR_CODES = setOf("wallet_pin_invalid", "wallet_pin_locked", "wallet_pin_required", "wallet_pin_not_set")

/**
 * State shared by every wallet page: the balance, the page stack, the open sheet and the toast.
 * One instance lives for one visit to the wallet (leaving it re-locks it), like the preview's
 * module-level `state`/`stack`.
 */
@Stable
class WalletHost(val scope: CoroutineScope, var onExit: () -> Unit, start: WaRoute = WaRoute.Home) {
    var balance by mutableStateOf<WalletBalanceDto?>(null)
    var hideBalance by mutableStateOf(false)
    var sheet by mutableStateOf<WaSheet?>(null)
    var toast by mutableStateOf<String?>(null)
    /** True when the toast on screen reports a problem (validation, a refused request) rather than a success. */
    var toastError by mutableStateOf(false)

    /** The total the hero card last counted up to, so the next count starts from there. */
    var shownTotal: Long = 0L

    val stack = mutableStateListOf(start)
    val current: WaRoute get() = stack.last()

    /** True when the last move was a push, so pages slide in from the trailing side and back the other way. */
    var forward by mutableStateOf(true)
        private set

    private var toastJob: Job? = null

    fun push(route: WaRoute) {
        forward = true
        stack.add(route)
    }

    fun pop() {
        if (sheet != null) {
            sheet = null
            return
        }
        if (stack.size <= 1) {
            onExit()
            return
        }
        forward = false
        stack.removeAt(stack.lastIndex)
    }

    fun showToast(message: String, error: Boolean = false) {
        toast = message
        toastError = error
        toastJob?.cancel()
        toastJob = scope.launch {
            delay(if (error) 3200 else 2200)
            toast = null
        }
    }

    /** A validation or request problem, shown as a notification at the top instead of text inside the page. */
    fun showError(message: String) = showToast(message, error = true)

    fun openSheet(value: WaSheet) {
        sheet = value
    }

    fun closeSheet() {
        sheet = null
    }

    /** Balance + wallet number for the request's country; the wallet is created on first look. */
    suspend fun refreshBalance(): Boolean {
        val fresh = runCatching { ApiClient.wallet.balance(walletAuth()).data }.getOrNull()
        if (fresh != null) balance = fresh
        return fresh != null
    }
}

val LocalWallet = staticCompositionLocalOf<WalletHost> { error("WalletHost missing — wrap wallet pages in WalletScreen") }
