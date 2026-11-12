package com.dorr.provider.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Fingerprint
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Warning
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.provider.R
import com.dorr.provider.net.ApiClient
import com.dorr.provider.net.PinStatusDto
import com.dorr.provider.net.WalletBiometric
import com.dorr.provider.net.collectReconnectTick
import com.dorr.provider.net.errorText
import com.dorr.provider.net.findFragmentActivity
import com.dorr.provider.ui.theme.ProvideAppFont
import com.dorr.provider.ui.wallet.LocalWalletOrNull
import com.dorr.provider.ui.wallet.PadResult
import com.dorr.provider.ui.wallet.Tone
import com.dorr.provider.ui.wallet.Wa
import com.dorr.provider.ui.wallet.WaButton
import com.dorr.provider.ui.wallet.WaButtonStyle
import com.dorr.provider.ui.wallet.WaDeviceTrustPage
import com.dorr.provider.ui.wallet.WaEmpty
import com.dorr.provider.ui.wallet.WaForcePinChangePage
import com.dorr.provider.ui.wallet.WaForgotPinPage
import com.dorr.provider.ui.wallet.WaFrozenPage
import com.dorr.provider.ui.wallet.WaPage
import com.dorr.provider.ui.wallet.WaPinPad
import com.dorr.provider.ui.wallet.WaPinSetupPage
import com.dorr.provider.ui.wallet.WaSkeleton
import com.dorr.provider.ui.wallet.WaToastHost
import com.dorr.provider.ui.wallet.WalletHost
import com.dorr.provider.ui.wallet.waRise
import kotlinx.coroutines.launch

/**
 * The wallet (FL-005), exactly as the DORR app's wallet gate (androidApp ui/screens/wallet/WalletScreen.kt → WaGate): no PIN yet →
 * the recovery method then the PIN ([WaPinSetupPage]), and the wallet opens straight after (the phone that made the PIN is trusted
 * by the server); with a PIN → the pad (or the fingerprint / face when it is on), "forgot your PIN?" ([WaForgotPinPage]), the reset
 * PIN 0000 replaced first ([WaForcePinChangePage]), a phone the wallet has not seen proven by a code ([WaDeviceTrustPage]), a
 * frozen wallet's selfie + ID review ([WaFrozenPage]). Only then the wallet home ([WalletHomeHost]), with the PIN kept in
 * [WalletSession] for the money pages. Leaving the wallet drops the PIN from memory. Errors come as the wallet's toast at the top.
 */
@Composable
fun WalletRoute(onBack: () -> Unit) {
    val scope = rememberCoroutineScope()
    val host = remember { WalletHost(scope) }
    CompositionLocalProvider(LocalWalletOrNull provides host) {
        ProvideAppFont {
            Box(Modifier.fillMaxSize().background(Wa.Bg).navigationBarsPadding().imePadding()) {
                WalletStages(onBack)
                WaToastHost(host)
            }
        }
    }
}

@Composable
private fun WalletStages(onBack: () -> Unit) {
    val context = LocalContext.current
    val activity = context.findFragmentActivity()
    val reconnect = collectReconnectTick()
    val networkError = stringResource(R.string.wa_error_network)
    val genericError = stringResource(R.string.wa_error_generic)

    var status by remember { mutableStateOf<PinStatusDto?>(null) }
    var failed by remember { mutableStateOf<String?>(null) }
    var attempt by remember { mutableIntStateOf(0) }
    var forgot by remember { mutableStateOf(false) }
    // The PIN typed at the gate when it turned out to be the reset value 0000: it has to be replaced first.
    var resetPin by remember { mutableStateOf<String?>(null) }
    // The PIN that verified fine but came from a device this wallet has never opened from before —
    // kept only long enough to resend it as X-Wallet-Pin while proving the phone (wallet policy bend 3).
    var untrustedDevicePin by remember { mutableStateOf<String?>(null) }

    // One read when the wallet opens (and on a retry, and when the connection comes back) — nothing on a timer.
    LaunchedEffect(attempt, reconnect) {
        failed = null
        val result = apiCall { ApiClient.wallet.pinStatus() }
        // Offline is covered by the app-wide screen and a reconnect reloads (skeleton meanwhile);
        // a real server answer gets the retry card.
        if (result is ApiResult.Ok) {
            status = result.data
        } else if (result is ApiResult.Failed && status == null) {
            failed = result.errorText(networkError) ?: genericError
        }
    }
    // Leaving the wallet forgets the PIN.
    DisposableEffect(Unit) { onDispose { WalletSession.lock() } }

    val current = status
    val unlocked = WalletSession.pin
    when {
        failed == null && current != null && current.isFrozen -> WaFrozenPage(current, onExit = onBack, onLifted = { attempt++ })
        unlocked != null -> WalletHomeHost(onBack = onBack)
        failed == null && current != null && !current.hasPin -> {
            var created by remember { mutableStateOf<String?>(null) }
            WaPinSetupPage(
                onExit = onBack,
                onDone = {
                    // Straight into the wallet, like the DORR app: the PIN was just typed twice, and this phone is trusted.
                    status = current.copy(hasPin = true)
                    created?.let { WalletSession.unlock(it) }
                },
                title = stringResource(R.string.wa_gate_create_title),
                showSaved = false,
                onCreated = { created = it },
            )
        }
        resetPin != null -> WaForcePinChangePage(
            resetPin.orEmpty(),
            onExit = onBack,
            onDone = { resetPin = null },
            onChanged = { fresh ->
                // The fingerprint holds the old PIN: it is turned off, and turned on again with the new one.
                if (WalletBiometric.isEnabled(context)) WalletBiometric.disable(context)
                WalletSession.unlock(fresh)
            },
        )
        untrustedDevicePin != null -> WaDeviceTrustPage(untrustedDevicePin.orEmpty(), onExit = onBack, onDone = {
            val pin = untrustedDevicePin
            untrustedDevicePin = null
            if (pin != null) WalletSession.unlock(pin)
        })
        forgot -> WaForgotPinPage(current, onExit = { forgot = false }, onDone = { forgot = false; attempt++ })
        else -> WaPage(title = stringResource(R.string.wa_my_wallet), onBack = onBack, scroll = false) {
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
                    val lockedUntil = remember(current.lockedUntil) { isoMillis(current.lockedUntil) }
                    val biometric = remember { WalletBiometric.isEnabled(context) && WalletBiometric.isAvailable(context) }

                    suspend fun completeWithPin(pin: String): PadResult {
                        val result = apiCall { ApiClient.wallet.verifyPin(pin) }
                        if (result !is ApiResult.Ok) {
                            // Froze between loading this screen and typing the PIN: reload so the
                            // frozen branch above takes over, instead of just showing a pad error.
                            if (result is ApiResult.Failed && result.error.errorCode == "wallet_pin_frozen") {
                                attempt++
                                return PadResult.Reset
                            }
                            // A temporary lock: the countdown on the pad, never asking the server during the wait.
                            return padFailure(result, networkError, genericError)
                        }
                        val verified = result.data
                        when {
                            verified.mustChange -> resetPin = pin
                            !verified.deviceTrusted -> untrustedDevicePin = pin
                            else -> WalletSession.unlock(pin)
                        }
                        return PadResult.Ok
                    }

                    // The stored PIN, opened by the fingerprint / face prompt. A PIN that no longer matches the
                    // server's is dropped, rather than failing silently on every future attempt.
                    fun unlockWithBiometric() {
                        if (activity == null) return
                        WalletBiometric.unlock(activity) { pin ->
                            if (pin == null) return@unlock
                            scope.launch {
                                if (completeWithPin(pin) is PadResult.Error) WalletBiometric.disable(context)
                            }
                        }
                    }

                    // Opening the wallet shows the prompt at once (once per visit; a cancel leaves the PIN pad and the button).
                    var autoPrompted by remember { mutableStateOf(false) }
                    val lockedNow = (lockedUntil ?: 0L) > System.currentTimeMillis()
                    LaunchedEffect(Unit) {
                        if (!autoPrompted && !lockedNow && activity != null && biometric) {
                            autoPrompted = true
                            unlockWithBiometric()
                        }
                    }

                    Column(Modifier.fillMaxSize().padding(horizontal = 8.dp)) {
                        WaPinPad(
                            title = stringResource(R.string.wa_gate_enter_title),
                            sub = stringResource(R.string.wa_gate_enter_sub),
                            modifier = Modifier.weight(1f).waRise(0),
                            onComplete = { pin -> completeWithPin(pin) },
                            lockedUntil = lockedUntil,
                        )
                        if (activity != null && biometric) {
                            WaButton(
                                stringResource(R.string.wa_biometric_unlock_button),
                                ::unlockWithBiometric,
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

/** An ISO-8601 time from the server as epoch millis, or null. */
private fun isoMillis(iso: String?): Long? =
    iso?.let { runCatching { java.time.OffsetDateTime.parse(it).toInstant().toEpochMilli() }.getOrNull() }

/** A failed call as the pad reports it: a temporary lock becomes the pad's countdown, anything else its message. */
internal fun padFailure(result: ApiResult<*>, offline: String, generic: String): PadResult {
    if (result is ApiResult.Failed && result.error.errorCode == "wallet_pin_locked") {
        val until = runCatching { result.error.data?.asJsonObject?.get("locked_until")?.asString }.getOrNull()
            ?.let { runCatching { java.time.OffsetDateTime.parse(it).toInstant().toEpochMilli() }.getOrNull() }
        if (until != null) return PadResult.Locked(until)
    }
    return PadResult.Error(result.errorText(offline) ?: generic)
}
