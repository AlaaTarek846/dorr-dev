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
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.Fingerprint
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
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.PinStatusDto
import com.dorr.app.network.apiFailure
import com.dorr.app.network.collectReconnectTick
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.rememberUpdatedState

/**
 * The wallet, as one visit: PIN gate first (every time it is entered — leaving re-locks it), then a
 * small stack of pages that slide over each other, with bottom sheets and a toast on top. It is drawn
 * *inside* the main screen, above the tab content and below the tab bar, so the bar stays visible.
 */
@Composable
fun WalletScreen(onExit: () -> Unit) {
    val currentOnExit by rememberUpdatedState(onExit)
    val scope = rememberCoroutineScope()
    val host = remember { WalletHost(scope, onExit = { currentOnExit() }) }
    SideEffect {
        host.onExit = { currentOnExit() }
    }
    var unlocked by remember { mutableStateOf(false) }

    CompositionLocalProvider(LocalWallet provides host) {
        Box(Modifier.fillMaxSize().background(Wa.Bg)) {
            if (!unlocked) {
                BackHandler { currentOnExit() }
                WaGate(onUnlocked = { unlocked = true }, onCancel = { currentOnExit() })
            } else {
                BackHandler { host.pop() }
                // A wallet QR tapped in a chat: straight to its transfer confirmation.
                LaunchedEffect(Unit) {
                    val qr = WalletDeepLink.consumeQr() ?: return@LaunchedEffect
                    runCatching { ApiClient.wallet.transferLookup(walletAuth(), com.dorr.app.network.TransferLookupRequest(mode = "qr", qr = qr)).data }
                        .onSuccess { who -> who?.let { host.push(WaRoute.TransferConfirm(it)) } }
                        .onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }
                }
                WalletPagesFor(host)
                WaSheetHost(host)
            }
            // Outside the lock check: a wrong PIN at the gate is reported the same way.
            WaToastHost(host)
        }
    }
}

@Composable
internal fun WalletPagesFor(host: WalletHost) {
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
            is WaRoute.Checkout -> WalletCheckout(route.state)
            is WaRoute.TopupAmount -> WalletTopup(initialAmount = minorToInput(route.minor))
        }
    }
}

@Composable
internal fun WaToastHost(host: WalletHost) {
    val message = host.toast
    var last by remember { mutableStateOf("") }
    var lastIsError by remember { mutableStateOf(false) }
    if (message != null) {
        last = message
        lastIsError = host.toastError
    }
    // A notification at the top, over everything (the PIN gate included), so it never hides the keypad or the buttons.
    Box(Modifier.fillMaxSize().padding(start = 16.dp, end = 16.dp, top = 10.dp), contentAlignment = Alignment.TopCenter) {
        AnimatedVisibility(
            visible = message != null,
            enter = slideInVertically(tween(300)) { -it } + fadeIn(tween(250)),
            exit = slideOutVertically(tween(250)) { -it } + fadeOut(tween(200)),
        ) {
            Row(
                Modifier
                    .shadow(12.dp, RoundedCornerShape(16.dp), ambientColor = Color(0x33111928), spotColor = Color(0x44111928))
                    .clip(RoundedCornerShape(16.dp))
                    .background(if (lastIsError) Wa.Danger else Color(0xFF111928))
                    .padding(horizontal = 18.dp, vertical = 12.dp),
                horizontalArrangement = Arrangement.spacedBy(10.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(if (lastIsError) Icons.Rounded.ErrorOutline else Icons.Rounded.CheckCircle, null, tint = Color.White, modifier = Modifier.size(18.dp))
                Text(last, color = Color.White, fontSize = 13.5.sp, fontWeight = FontWeight.SemiBold, lineHeight = 20.sp, modifier = Modifier.weight(1f, fill = false))
            }
        }
    }
}

/**
 * Asks for the PIN before the wallet opens. With a PIN it verifies it (and offers "forgot your PIN?");
 * without one it walks the person through the recovery-method choice and then creating the PIN.
 */
@Composable
private fun WaGate(onUnlocked: () -> Unit, onCancel: () -> Unit) {
    val context = androidx.compose.ui.platform.LocalContext.current
    val activity = context as? androidx.fragment.app.FragmentActivity
    val networkError = stringResource(R.string.wa_error_network)
    val enterTitle = stringResource(R.string.wa_gate_enter_title)
    val enterSub = stringResource(R.string.wa_gate_enter_sub)

    var status by remember { mutableStateOf<PinStatusDto?>(null) }
    var failed by remember { mutableStateOf<String?>(null) }
    var attempt by remember { mutableIntStateOf(0) }
    var forgot by remember { mutableStateOf(false) }
    // The PIN typed at the gate when it turned out to be the reset value 0000: it has to be replaced first.
    var resetPin by remember { mutableStateOf<String?>(null) }
    // The PIN that verified fine but came from a device this wallet has never opened from before —
    // kept only long enough to resend it as X-Wallet-Pin while proving the phone (wallet policy bend 3).
    var untrustedDevicePin by remember { mutableStateOf<String?>(null) }

    val reconnectTick = collectReconnectTick()
    LaunchedEffect(attempt, reconnectTick) {
        failed = null
        status = null
        try {
            val loaded = ApiClient.wallet.pinStatus(walletAuth()).data
            if (loaded == null) failed = networkError else status = loaded
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            val failure = e.apiFailure()
            // Offline is covered by the app-wide screen and a reconnect reloads (skeleton meanwhile);
            // a real server answer gets the retry card.
            if (failure.httpStatus != null) failed = failure.message ?: networkError
        }
    }

    val current = status
    when {
        failed == null && current != null && current.isFrozen -> WaFrozenPage(current, onExit = onCancel, onLifted = { attempt++ })
        failed == null && current != null && !current.hasPin -> WaPinSetupPage(
            onExit = onCancel,
            onDone = onUnlocked,
            title = stringResource(R.string.wa_gate_create_title),
            showSaved = false,
        )
        resetPin != null -> WaForcePinChangePage(resetPin.orEmpty(), onExit = onCancel, onDone = onUnlocked)
        untrustedDevicePin != null -> WaDeviceTrustPage(untrustedDevicePin.orEmpty(), onExit = onCancel, onDone = onUnlocked)
        forgot -> WaForgotPinPage(current, onExit = { forgot = false }, onDone = { forgot = false; attempt++ })
        else -> WaPage(title = stringResource(R.string.wa_my_wallet), onBack = onCancel, scroll = false) {
            when {
                failed != null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    WaEmpty(
                        icon = Icons.Rounded.Warning, tone = Tone.Gray,
                        title = stringResource(R.string.wa_load_failed), text = failed.orEmpty(),
                        action = { WaButton(stringResource(R.string.wa_retry), onClick = { attempt++ }, style = WaButtonStyle.Ghost, icon = Icons.Rounded.Refresh, modifier = Modifier.padding(horizontal = 60.dp)) },
                    )
                }
                current == null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    WaSkeleton(Modifier.fillMaxWidth().padding(24.dp).size(300.dp), RoundedCornerShape(24.dp))
                }
                else -> {
                    val scope = rememberCoroutineScope()

                    suspend fun completeWithPin(pin: String): PadResult {
                        val verified = try {
                            ApiClient.wallet.verifyPin(walletAuth(), pin).data
                        } catch (e: CancellationException) {
                            throw e
                        } catch (e: Exception) {
                            val failure = e.apiFailure()
                            // Froze between loading this screen and typing the PIN: reload so the
                            // frozen branch above takes over, instead of just showing a pad error.
                            if (failure.errorCode == "wallet_pin_frozen") {
                                attempt++
                                return PadResult.Reset
                            }
                            // A temporary lock: show the countdown, refuse any more attempts until it
                            // ends on its own — never keep asking the server during the wait.
                            val lockedUntil = failure.lockedUntil?.let { runCatching { java.time.Instant.parse(it).toEpochMilli() }.getOrNull() }
                            if (failure.errorCode == "wallet_pin_locked" && lockedUntil != null) {
                                return PadResult.Locked(lockedUntil)
                            }
                            return PadResult.Error(if (failure.httpStatus == null) networkError else failure.message ?: networkError)
                        }
                        when {
                            verified?.mustChange == true -> resetPin = pin
                            verified?.deviceTrusted == false -> untrustedDevicePin = pin
                            else -> onUnlocked()
                        }
                        return PadResult.Ok
                    }

                    Column(Modifier.fillMaxSize().padding(horizontal = 8.dp)) {
                        WaPinPad(
                            title = enterTitle,
                            sub = enterSub,
                            modifier = Modifier.weight(1f).waRise(0),
                            onComplete = { pin -> completeWithPin(pin) },
                            lockedUntil = current.lockedUntil?.let { runCatching { java.time.Instant.parse(it).toEpochMilli() }.getOrNull() },
                        )
                        if (activity != null && WaBiometric.isEnabled(context)) {
                            WaButton(
                                stringResource(R.string.wa_biometric_unlock_button),
                                {
                                    WaBiometric.unlock(activity) { pin ->
                                        if (pin == null) return@unlock
                                        scope.launch {
                                            if (completeWithPin(pin) is PadResult.Error) {
                                                // The stored PIN no longer matches the server's — drop it
                                                // rather than fail silently on every future attempt.
                                                WaBiometric.disable(context)
                                            }
                                        }
                                    }
                                },
                                style = WaButtonStyle.Ghost,
                                icon = Icons.Rounded.Fingerprint,
                            )
                        }
                        WaButton(stringResource(R.string.wa_forgot_link), { forgot = true }, style = WaButtonStyle.Quiet, modifier = Modifier.padding(bottom = 6.dp))
                    }
                }
            }
        }
    }
}

/**
 * Opens the PIN sheet for a protected action. Asks the server first whether a PIN exists so a
 * first-time user is sent to create one instead of getting a "wrong PIN" error.
 */
fun WalletHost.requestPin(
    subtitle: String,
    onError: (String) -> Unit,
    onSubmit: suspend (String) -> PinOutcome,
    onClose: (String) -> Unit = onError,
) {
    scope.launch {
        val status = try {
            ApiClient.wallet.pinStatus(walletAuth()).data
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            onError(e.apiFailure().message ?: "")
            return@launch
        }
        // No PIN yet (the gate normally guarantees one): the PIN page walks through recovery method + PIN.
        if (status?.hasPin == false) {
            push(WaRoute.PinSettings)
            return@launch
        }
        val lockedUntil = status?.lockedUntil?.let { runCatching { java.time.Instant.parse(it).toEpochMilli() }.getOrNull() }
        openSheet(WaSheet.Pin(subtitle = subtitle, onSubmit = onSubmit, onClose = onClose, lockedUntil = lockedUntil))
    }
}

/**
 * The PIN screen reached from Account → Settings → Wallet → Change PIN, outside a
 * wallet visit: same page, its own little host (so the toast and back handling work),
 * leaving straight back to the wallet settings menu.
 */
@Composable
fun WalletPinSettingsScreen(onBack: () -> Unit, onSaved: (String) -> Unit = {}) {
    val currentOnBack by rememberUpdatedState(onBack)
    val scope = rememberCoroutineScope()
    val host = remember { WalletHost(scope, onExit = { currentOnBack() }) }
    SideEffect {
        host.onExit = { currentOnBack() }
    }
    CompositionLocalProvider(LocalWallet provides host) {
        BackHandler { currentOnBack() }
        Box(Modifier.fillMaxSize()) {
            WalletPinSettings()
            WaToastHost(host)
        }
    }
}

/** "30" for 3000, "12.5" for 1250 — how a top-up amount is typed (parseAmountToMinor reads it back). */
internal fun minorToInput(minor: Long): String {
    if (minor <= 0) return ""
    val whole = minor / 100
    val cents = minor % 100
    return if (cents == 0L) whole.toString() else "$whole." + cents.toString().padStart(2, '0').trimEnd('0')
}
