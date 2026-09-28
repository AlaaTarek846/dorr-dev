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
<<<<<<< HEAD
import androidx.compose.material.icons.rounded.Fingerprint
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Warning
=======
>>>>>>> origin/main
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
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
                Modifier.clip(RoundedCornerShape(16.dp)).background(Color(0xFF111928)).padding(horizontal = 18.dp, vertical = 11.dp),
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

<<<<<<< HEAD
    var status by remember { mutableStateOf<PinStatusDto?>(null) }
    var failed by remember { mutableStateOf<String?>(null) }
    var attempt by remember { mutableIntStateOf(0) }
    var forgot by remember { mutableStateOf(false) }
    // The PIN typed at the gate when it turned out to be the reset value 0000: it has to be replaced first.
    var resetPin by remember { mutableStateOf<String?>(null) }
    // The PIN that verified fine but came from a device this wallet has never opened from before —
    // kept only long enough to resend it as X-Wallet-Pin while proving the phone (wallet policy bend 3).
    var untrustedDevicePin by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(attempt) {
        failed = null
        status = null
        try {
            val loaded = ApiClient.wallet.pinStatus(walletAuth()).data
            if (loaded == null) failed = networkError else status = loaded
=======
    var hasPin by remember { mutableStateOf<Boolean?>(null) }
    var step by remember { mutableStateOf("enter") }
    var first by remember { mutableStateOf("") }

    val reconnectTick = collectReconnectTick()
    LaunchedEffect(reconnectTick) {
        hasPin = null
        try {
            val status = ApiClient.wallet.pinStatus(walletAuth()).data
            hasPin = status?.hasPin
            step = if (status?.hasPin == true) "enter" else "create"
>>>>>>> origin/main
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            // No error card on purpose: offline is covered by the app-wide
            // screen and a reconnect reloads, so hasPin stays null (skeleton)
            // until an attempt succeeds.
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
        else -> WaPage(title = stringResource(R.string.wa_wallet), onBack = onCancel, scroll = false) {
            when {
<<<<<<< HEAD
                failed != null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    WaEmpty(
                        icon = Icons.Rounded.Warning, tone = Tone.Gray,
                        title = stringResource(R.string.wa_load_failed), text = failed.orEmpty(),
                        action = { WaButton(stringResource(R.string.wa_retry), onClick = { attempt++ }, style = WaButtonStyle.Ghost, icon = Icons.Rounded.Refresh, modifier = Modifier.padding(horizontal = 60.dp)) },
                    )
                }
                current == null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
=======
                hasPin == null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
>>>>>>> origin/main
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
        val hasPin = try {
            ApiClient.wallet.pinStatus(walletAuth()).data?.hasPin
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            onError(e.apiFailure().message ?: "")
            return@launch
        }
        // No PIN yet (the gate normally guarantees one): the PIN page walks through recovery method + PIN.
        if (hasPin == false) {
            push(WaRoute.PinSettings)
            return@launch
        }
        openSheet(WaSheet.Pin(subtitle = subtitle, onSubmit = onSubmit, onClose = onClose))
    }
}

/**
 * The PIN screen reached from Account → Wallet PIN, outside a wallet visit: same page, its own
 * little host (so the toast and back handling work), leaving straight back to the account menu.
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
