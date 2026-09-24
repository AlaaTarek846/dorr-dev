package com.dorr.app.ui.screens.wallet

import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.ContentTransform
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutHorizontally
import androidx.compose.animation.slideOutVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Warning
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.CreatePinRequest
import com.dorr.app.network.apiFailure
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

/**
 * The wallet, as one visit: PIN gate first (every time it is entered — leaving re-locks it), then a
 * small stack of pages that slide over each other, with bottom sheets and a toast on top. It is drawn
 * *inside* the main screen, above the tab content and below the tab bar, so the bar stays visible.
 */
@Composable
fun WalletScreen(onExit: () -> Unit) {
    val scope = rememberCoroutineScope()
    val host = remember { WalletHost(scope, onExit) }
    var unlocked by remember { mutableStateOf(false) }

    CompositionLocalProvider(LocalWallet provides host) {
        Box(Modifier.fillMaxSize().background(Wa.Bg)) {
            if (!unlocked) {
                BackHandler { onExit() }
                WaGate(onUnlocked = { unlocked = true }, onCancel = onExit)
            } else {
                BackHandler { host.pop() }
                WalletPages(host)
                WaSheetHost(host)
                WaToastHost(host)
            }
        }
    }
}

@Composable
private fun WalletPages(host: WalletHost) {
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    // Pages come in from the "next" edge of the reading direction and leave towards the other one.
    val sign = if (rtl) -1 else 1

    AnimatedContent(
        targetState = host.current,
        transitionSpec = {
            val forward = host.forward
            val enter = slideInHorizontally(tween(340)) { full -> (if (forward) sign else -sign) * full / 6 } + fadeIn(tween(300))
            val exit = slideOutHorizontally(tween(300)) { full -> (if (forward) -sign else sign) * full / 10 } + fadeOut(tween(220))
            ContentTransform(enter, exit)
        },
        label = "walletPages",
    ) { route ->
        when (route) {
            WaRoute.Home -> WalletHome()
            WaRoute.Topup -> WalletTopup()
            WaRoute.Transfer -> WalletTransfer()
            is WaRoute.TransferConfirm -> WalletTransferConfirm(route.who)
            WaRoute.MyQr -> WalletMyQr()
            WaRoute.Scanner -> WalletScanner()
            WaRoute.History -> WalletHistory()
            WaRoute.PinSettings -> WalletPinSettings()
        }
    }
}

@Composable
private fun WaToastHost(host: WalletHost) {
    val message = host.toast
    var last by remember { mutableStateOf("") }
    if (message != null) last = message
    Box(Modifier.fillMaxSize().padding(bottom = 26.dp), contentAlignment = Alignment.BottomCenter) {
        AnimatedVisibility(
            visible = message != null,
            enter = slideInVertically(tween(300)) { it / 2 } + fadeIn(tween(250)),
            exit = slideOutVertically(tween(250)) { it / 2 } + fadeOut(tween(200)),
        ) {
            Row(
                Modifier.clip(RoundedCornerShape(16.dp)).background(Wa.Ink).padding(horizontal = 18.dp, vertical = 11.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.CheckCircle, null, tint = Color.White, modifier = Modifier.size(16.dp))
                Text(last, color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

/**
 * Asks for the PIN before the wallet opens. With a PIN it verifies it; without one it walks the
 * person through creating it (enter + confirm) — the same screen either way, like the preview.
 */
@Composable
private fun WaGate(onUnlocked: () -> Unit, onCancel: () -> Unit) {
    val networkError = stringResource(R.string.wa_error_network)
    val enterTitle = stringResource(R.string.wa_gate_enter_title)
    val enterSub = stringResource(R.string.wa_gate_enter_sub)
    val createTitle = stringResource(R.string.wa_gate_create_title)
    val createSub = stringResource(R.string.wa_gate_create_sub)
    val confirmTitle = stringResource(R.string.wa_pin_confirm_title)
    val confirmSub = stringResource(R.string.wa_pin_confirm_sub)
    val mismatch = stringResource(R.string.wa_pin_mismatch)

    var hasPin by remember { mutableStateOf<Boolean?>(null) }
    var failed by remember { mutableStateOf<String?>(null) }
    var attempt by remember { mutableIntStateOf(0) }
    var step by remember { mutableStateOf("enter") }
    var first by remember { mutableStateOf("") }

    LaunchedEffect(attempt) {
        failed = null
        hasPin = null
        try {
            val status = ApiClient.wallet.pinStatus(walletAuth()).data
            hasPin = status?.hasPin
            if (status == null) failed = networkError
            step = if (status?.hasPin == true) "enter" else "create"
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            failed = e.apiFailure().message ?: networkError
        }
    }

    WaPage(title = stringResource(R.string.wa_wallet), onBack = onCancel, scroll = false) {
        Box(Modifier.fillMaxSize().padding(horizontal = 8.dp), contentAlignment = Alignment.Center) {
            when {
                failed != null -> WaEmpty(
                    icon = Icons.Rounded.Warning, tone = Tone.Gray,
                    title = stringResource(R.string.wa_load_failed), text = failed.orEmpty(),
                    action = { WaButton(stringResource(R.string.wa_retry), onClick = { attempt++ }, style = WaButtonStyle.Ghost, icon = Icons.Rounded.Refresh, modifier = Modifier.padding(horizontal = 60.dp)) },
                )
                hasPin == null -> WaSkeleton(Modifier.fillMaxWidth().padding(24.dp).size(300.dp), RoundedCornerShape(24.dp))
                else -> Column(Modifier.fillMaxWidth().waRise(0)) {
                    val (title, sub) = when (step) {
                        "enter" -> enterTitle to enterSub
                        "create" -> createTitle to createSub
                        else -> confirmTitle to confirmSub
                    }
                    WaPinPad(title = title, sub = sub, onComplete = { pin ->
                        when (step) {
                            "enter" -> {
                                try {
                                    ApiClient.wallet.verifyPin(walletAuth(), pin)
                                } catch (e: CancellationException) {
                                    throw e
                                } catch (e: Exception) {
                                    val failure = e.apiFailure()
                                    return@WaPinPad PadResult.Error(if (failure.httpStatus == null) networkError else failure.message ?: networkError)
                                }
                                onUnlocked()
                                PadResult.Ok
                            }
                            "create" -> {
                                first = pin
                                step = "confirm"
                                PadResult.Reset
                            }
                            else -> {
                                if (pin != first) {
                                    step = "create"
                                    return@WaPinPad PadResult.Error(mismatch)
                                }
                                try {
                                    ApiClient.wallet.createPin(walletAuth(), CreatePinRequest(pin, pin))
                                } catch (e: CancellationException) {
                                    throw e
                                } catch (e: Exception) {
                                    step = "create"
                                    return@WaPinPad PadResult.Error(e.apiFailure().message ?: networkError)
                                }
                                onUnlocked()
                                PadResult.Ok
                            }
                        }
                    })
                }
            }
        }
    }
}

/**
 * Opens the PIN sheet for a protected action. Asks the server first whether a PIN exists so a
 * first-time user gets the create form instead of a "wrong PIN" error.
 */
fun WalletHost.requestPin(
    subtitle: String,
    onError: (String) -> Unit,
    onSubmit: suspend (String) -> PinOutcome,
    onClose: (String) -> Unit = onError,
) {
    scope.launch {
        val hasPin = try {
            ApiClient.wallet.pinStatus(walletAuth()).data?.hasPin
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            onError(e.apiFailure().message ?: "")
            return@launch
        }
        openSheet(WaSheet.Pin(hasPin = hasPin ?: true, subtitle = subtitle, onSubmit = onSubmit, onClose = onClose))
    }
}

/**
 * The PIN screen reached from Account → Wallet PIN, outside a wallet visit: same page, its own
 * little host (so the toast and back handling work), leaving straight back to the account menu.
 */
@Composable
fun WalletPinSettingsScreen(onBack: () -> Unit, onSaved: (String) -> Unit = {}) {
    val scope = rememberCoroutineScope()
    val host = remember { WalletHost(scope, onBack) }
    CompositionLocalProvider(LocalWallet provides host) {
        BackHandler { onBack() }
        Box(Modifier.fillMaxSize()) {
            WalletPinSettings()
            WaToastHost(host)
        }
    }
}
