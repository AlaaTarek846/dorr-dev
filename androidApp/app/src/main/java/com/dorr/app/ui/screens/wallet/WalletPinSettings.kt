package com.dorr.app.ui.screens.wallet

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
<<<<<<< HEAD
import androidx.compose.material.icons.rounded.Fingerprint
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material.icons.rounded.Warning
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
=======
import androidx.compose.material.icons.rounded.Shield
>>>>>>> origin/main
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
<<<<<<< HEAD
import androidx.compose.ui.unit.sp
import androidx.fragment.app.FragmentActivity
=======
import com.dorr.app.ui.theme.LocalThemeState
>>>>>>> origin/main
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ChangePinRequest
import com.dorr.app.network.PinStatusDto
import com.dorr.app.network.apiFailure
import com.dorr.app.network.collectReconnectTick
import kotlinx.coroutines.CancellationException

/**
 * Create the wallet PIN (recovery method first, then the PIN), or change it (current → new → confirm).
 * Does its own verification: a wrong current PIN sends the person back to the first step. Ends on a
 * "saved" seal. "Forgot your PIN?" on the first step opens the recovery flow.
 */
@Composable
fun WalletPinSettings() {
    val host = LocalWallet.current
    val activity = LocalContext.current as? FragmentActivity
    val networkError = stringResource(R.string.wa_error_network)
    val mismatch = stringResource(R.string.wa_pin_mismatch)
    val setupFailed = stringResource(R.string.wa_biometric_setup_failed)
    val textCurrent = stringResource(R.string.wa_pin_current_title) to stringResource(R.string.wa_pin_current_sub)
    val textNew = stringResource(R.string.wa_pin_new_title) to stringResource(R.string.wa_pin_digits_sub)
    val textConfirm = stringResource(R.string.wa_pin_confirm_title) to stringResource(R.string.wa_pin_confirm_sub)
    val textBiometricPin = stringResource(R.string.wa_pin_enter_title) to stringResource(R.string.wa_biometric_toggle)

<<<<<<< HEAD
    var status by remember { mutableStateOf<PinStatusDto?>(null) }
    var forgot by remember { mutableStateOf(false) }
    var changeMethod by remember { mutableStateOf(false) }
    var failed by remember { mutableStateOf<String?>(null) }
    var attempt by remember { mutableIntStateOf(0) }
=======
    var changing by remember { mutableStateOf<Boolean?>(null) }
>>>>>>> origin/main
    var step by remember { mutableStateOf("current") }
    var current by remember { mutableStateOf("") }
    var fresh by remember { mutableStateOf("") }
    var saved by remember { mutableStateOf(false) }

<<<<<<< HEAD
    LaunchedEffect(attempt) {
        failed = null
        status = null
        try {
            val loaded = ApiClient.wallet.pinStatus(walletAuth()).data
            status = loaded
            step = "current"
            if (loaded == null) failed = networkError
=======
    val reconnectTick = collectReconnectTick()
    LaunchedEffect(reconnectTick) {
        changing = null
        try {
            val status = ApiClient.wallet.pinStatus(walletAuth()).data
            changing = status?.hasPin
            step = if (status?.hasPin == true) "current" else "new"
>>>>>>> origin/main
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            // No error card on purpose: offline is covered by the app-wide
            // screen and a reconnect reloads, so changing stays null
            // (skeleton) until an attempt succeeds.
        }
    }

<<<<<<< HEAD
    val loaded = status
    if (loaded != null && loaded.isFrozen && failed == null) {
        WaFrozenPage(loaded, onExit = { host.pop() }, onLifted = { attempt++ })
        return
    }
    if (loaded != null && !loaded.hasPin && failed == null) {
        // First PIN: choose how it can be recovered, then create it.
        WaPinSetupPage(onExit = { host.pop() }, onDone = { host.pop() })
        return
    }
    if (changeMethod) {
        WaChangeRecoveryPage(onExit = { changeMethod = false }, onDone = { changeMethod = false; attempt++ })
        return
    }
    if (forgot) {
        WaForgotPinPage(loaded, onExit = { forgot = false }, onDone = { forgot = false; attempt++ })
        return
    }

    WaPage(title = stringResource(R.string.wa_pin_page_title), onBack = { host.pop() }, scroll = false) {
        when {
            failed != null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                WaEmpty(
                    Icons.Rounded.Warning, Tone.Gray, stringResource(R.string.wa_load_failed), failed.orEmpty(),
                    action = { WaButton(stringResource(R.string.wa_retry), { attempt++ }, style = WaButtonStyle.Ghost, icon = Icons.Rounded.Refresh, modifier = Modifier.padding(horizontal = 60.dp)) },
                )
            }
            loaded == null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
=======
    val night = LocalThemeState.current.isDark ?: isSystemInDarkTheme()
    WaPage(title = stringResource(R.string.wa_pin_page_title), onBack = { host.pop() }, scroll = false, dark = night) {
        when {
            changing == null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
>>>>>>> origin/main
                WaSkeleton(Modifier.fillMaxWidth().padding(24.dp).size(300.dp), RoundedCornerShape(24.dp))
            }
            saved -> WaStatusColumn {
                WaSeal()
                WaStatusTitle(stringResource(R.string.wa_pin_saved_title))
                WaStatusText(stringResource(R.string.wa_pin_saved_text))
                androidx.compose.foundation.layout.Spacer(Modifier.padding(top = 12.dp))
                WaButton(stringResource(R.string.wa_done), { host.pop() }, modifier = Modifier.padding(horizontal = 20.dp))
            }
            else -> Column(Modifier.fillMaxSize().padding(horizontal = 8.dp)) {
                val (title, sub) = when (step) {
                    "current" -> textCurrent
                    "new" -> textNew
                    "biometric-pin" -> textBiometricPin
                    else -> textConfirm
                }
                WaPinPad(
<<<<<<< HEAD
                    title = title, sub = sub, icon = Icons.Rounded.Shield,
                    modifier = Modifier.weight(1f).waRise(0),
=======
                    title = title, sub = sub, icon = Icons.Rounded.Shield, dark = night,
                    modifier = Modifier.fillMaxSize().waRise(0),
>>>>>>> origin/main
                    onComplete = { pin ->
                        when (step) {
                            "current" -> {
                                current = pin
                                step = "new"
                                PadResult.Reset
                            }
                            "new" -> {
                                fresh = pin
                                step = "confirm"
                                PadResult.Reset
                            }
                            "biometric-pin" -> {
                                try {
                                    ApiClient.wallet.verifyPin(walletAuth(), pin)
                                } catch (e: CancellationException) {
                                    throw e
                                } catch (e: Exception) {
                                    val failure = e.apiFailure()
                                    val lockedUntil = failure.lockedUntil?.let { runCatching { java.time.Instant.parse(it).toEpochMilli() }.getOrNull() }
                                    if (failure.errorCode == "wallet_pin_locked" && lockedUntil != null) {
                                        step = "current"
                                        return@WaPinPad PadResult.Locked(lockedUntil)
                                    }
                                    return@WaPinPad PadResult.Error(if (failure.httpStatus == null) networkError else failure.message ?: networkError)
                                }
                                if (activity == null) {
                                    step = "current"
                                    return@WaPinPad PadResult.Ok
                                }
                                WaBiometric.enable(activity, pin) { ok ->
                                    if (!ok) host.showToast(setupFailed)
                                }
                                step = "current"
                                PadResult.Ok
                            }
                            else -> {
                                if (pin != fresh) {
                                    step = "new"
                                    return@WaPinPad PadResult.Error(mismatch)
                                }
                                try {
                                    ApiClient.wallet.changePin(walletAuth(), ChangePinRequest(current, fresh, pin))
                                } catch (e: CancellationException) {
                                    throw e
                                } catch (e: Exception) {
                                    // A wrong current PIN sends the person back to the start; anything else retries.
                                    step = "current"
                                    val failure = e.apiFailure()
                                    val lockedUntil = failure.lockedUntil?.let { runCatching { java.time.Instant.parse(it).toEpochMilli() }.getOrNull() }
                                    if (failure.errorCode == "wallet_pin_locked" && lockedUntil != null) {
                                        return@WaPinPad PadResult.Locked(lockedUntil)
                                    }
                                    return@WaPinPad PadResult.Error(if (failure.httpStatus == null) networkError else failure.message ?: networkError)
                                }
                                saved = true
                                PadResult.Ok
                            }
                        }
                    },
                )
                if (step == "current") {
                    WaButton(stringResource(R.string.wa_forgot_link), { forgot = true }, style = WaButtonStyle.Quiet)
                    WaButton(stringResource(R.string.wa_rec_change_link), { changeMethod = true }, style = WaButtonStyle.Quiet)
                    WaBiometricToggleRow(onEnableRequested = { step = "biometric-pin" })
                }
            }
        }
    }
}

/**
 * The one-time PIN entry that unlocks biometric setup: the PIN pad above already handles it (its
 * "biometric-pin" step), this is just the switch that starts it, plus turning it off (no PIN needed
 * to turn a local convenience *off*).
 */
@Composable
private fun WaBiometricToggleRow(onEnableRequested: () -> Unit) {
    val context = LocalContext.current
    var enabled by remember { mutableStateOf(WaBiometric.isEnabled(context)) }
    val available = remember { WaBiometric.isAvailable(context) }
    if (!available) return

    Column(Modifier.padding(top = 4.dp, bottom = 6.dp)) {
        Row(
            Modifier.fillMaxWidth().padding(vertical = 6.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(stringResource(R.string.wa_biometric_toggle), fontSize = 13.5.sp, fontWeight = FontWeight.Bold, color = Wa.Ink, modifier = Modifier.weight(1f))
            Switch(
                checked = enabled,
                onCheckedChange = { checked ->
                    if (checked) {
                        onEnableRequested()
                    } else {
                        WaBiometric.disable(context)
                        enabled = false
                    }
                },
            )
        }
        Text(stringResource(R.string.wa_biometric_toggle_hint), fontSize = 11.5.sp, color = Wa.Mut, lineHeight = 17.sp)
    }
}
