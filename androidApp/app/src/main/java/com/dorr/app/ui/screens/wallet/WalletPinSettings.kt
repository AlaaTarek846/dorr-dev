package com.dorr.app.ui.screens.wallet

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.Fingerprint
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material.icons.rounded.VpnKey
import androidx.compose.material.icons.rounded.Warning
import androidx.compose.material3.Icon
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.fragment.app.FragmentActivity
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ChangePinRequest
import com.dorr.app.network.PinStatusDto
import com.dorr.app.network.apiFailure
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.theme.LocalThemeState
import kotlinx.coroutines.CancellationException

/** The three pages behind the wallet PIN entry, plus the menu that lists them. */
private enum class PinSub { Menu, ChangePin, Recovery, Biometric }

/**
 * Security settings of the wallet. Opening "PIN" from the wallet shows a menu of three pages, like the
 * app's own settings: the wallet PIN (change it), the recovery method, and unlocking the wallet with a
 * fingerprint or face. The very first PIN (no PIN yet) and a frozen wallet still take over the whole
 * screen, because nothing else makes sense before they are sorted out.
 */
@Composable
fun WalletPinSettings() {
    val host = LocalWallet.current
    val networkError = stringResource(R.string.wa_error_network)

    var status by remember { mutableStateOf<PinStatusDto?>(null) }
    var forgot by remember { mutableStateOf(false) }
    var sub by remember { mutableStateOf(PinSub.Menu) }
    var failed by remember { mutableStateOf<String?>(null) }
    var attempt by remember { mutableIntStateOf(0) }

    val reconnectTick = collectReconnectTick()
    LaunchedEffect(attempt, reconnectTick) {
        failed = null
        status = null
        try {
            val loaded = ApiClient.wallet.pinStatus(walletAuth()).data
            status = loaded
            if (loaded == null) failed = networkError
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            val failure = e.apiFailure()
            // Offline is covered by the app-wide screen and a reconnect reloads (skeleton meanwhile);
            // a real server answer gets the retry card.
            if (failure.httpStatus != null) failed = failure.message ?: networkError
        }
    }

    // A sub page's back goes to the menu; the menu's back leaves (the wallet's own handler pops).
    BackHandler(enabled = sub != PinSub.Menu && !forgot) { sub = PinSub.Menu }

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
    if (forgot) {
        WaForgotPinPage(loaded, onExit = { forgot = false }, onDone = { forgot = false; attempt++ })
        return
    }
    if (sub == PinSub.Recovery) {
        WaChangeRecoveryPage(onExit = { sub = PinSub.Menu }, onDone = { sub = PinSub.Menu; attempt++ })
        return
    }

    val night = LocalThemeState.current.isDark ?: isSystemInDarkTheme()
    when {
        failed != null -> WaPage(title = stringResource(R.string.wa_security_title), onBack = { host.pop() }, scroll = false, dark = night) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                WaEmpty(
                    Icons.Rounded.Warning, Tone.Gray, stringResource(R.string.wa_load_failed), failed.orEmpty(),
                    action = { WaButton(stringResource(R.string.wa_retry), { attempt++ }, style = WaButtonStyle.Ghost, icon = Icons.Rounded.Refresh, modifier = Modifier.padding(horizontal = 60.dp)) },
                )
            }
        }
        loaded == null -> WaPage(title = stringResource(R.string.wa_security_title), onBack = { host.pop() }, scroll = false, dark = night) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                WaSkeleton(Modifier.fillMaxWidth().padding(24.dp).size(300.dp), RoundedCornerShape(24.dp))
            }
        }
        sub == PinSub.ChangePin -> WaChangePinPage(night, onBack = { sub = PinSub.Menu }, onForgot = { forgot = true })
        sub == PinSub.Biometric -> WaBiometricPage(night, onBack = { sub = PinSub.Menu })
        else -> WaSecurityMenu(night, onBack = { host.pop() }, onOpen = { sub = it })
    }
}

// ------------------------------------------------------------------------------- the menu

@Composable
private fun WaSecurityMenu(night: Boolean, onBack: () -> Unit, onOpen: (PinSub) -> Unit) {
    val context = LocalContext.current
    val biometricAvailable = remember { WaBiometric.isAvailable(context) }
    val biometricOn = remember { WaBiometric.isEnabled(context) }

    WaPage(title = stringResource(R.string.wa_security_title), onBack = onBack, dark = night) {
        // A short reassurance strip: why this page exists.
        Row(
            Modifier
                .fillMaxWidth()
                .padding(top = 4.dp, bottom = 6.dp)
                .waRise(0)
                .clip(RoundedCornerShape(22.dp))
                .background(Wa.Red.copy(alpha = 0.10f))
                .border(1.dp, Wa.Red.copy(alpha = 0.25f), RoundedCornerShape(22.dp))
                .padding(horizontal = 16.dp, vertical = 16.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            WaIconWell(Icons.Rounded.Shield, Tone.Red, size = 52.dp, iconSize = 26.dp)
            Text(stringResource(R.string.wa_security_intro), color = Wa.Ink, fontSize = 13.5.sp, lineHeight = 22.sp, fontWeight = FontWeight.Medium, modifier = Modifier.weight(1f))
        }

        Spacer(Modifier.height(10.dp))
        WaSecurityRow(Icons.Rounded.Lock, R.string.wa_pin_menu_pin_title, R.string.wa_pin_menu_pin_sub, 1, null) { onOpen(PinSub.ChangePin) }
        WaSecurityRow(Icons.Rounded.VpnKey, R.string.wa_rec_change_link, R.string.wa_pin_menu_recovery_sub, 2, null) { onOpen(PinSub.Recovery) }
        WaSecurityRow(
            Icons.Rounded.Fingerprint, R.string.wa_biometric_menu_title, R.string.wa_biometric_menu_sub, 3,
            when {
                !biometricAvailable -> stringResource(R.string.wa_not_available)
                biometricOn -> stringResource(R.string.wa_on)
                else -> stringResource(R.string.wa_off)
            },
        ) { onOpen(PinSub.Biometric) }
    }
}

/** One entry of the security menu: brand-tinted icon, title + one line of help, optional state, chevron. */
@Composable
private fun WaSecurityRow(icon: ImageVector, title: Int, subtitle: Int, index: Int, state: String?, onClick: () -> Unit) {
    val source = remember { MutableInteractionSource() }
    val scale by rememberPressScale(source, 0.97f)
    val shape = RoundedCornerShape(20.dp)
    Row(
        Modifier
            .fillMaxWidth()
            .padding(vertical = 6.dp)
            .waRise(index)
            .scale(scale)
            .clip(shape)
            .background(Wa.Surface)
            .border(1.dp, Wa.Red.copy(alpha = 0.3f), shape)
            .clickable(interactionSource = source, indication = null, onClick = onClick)
            .padding(horizontal = 14.dp, vertical = 16.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        WaIconWell(icon, Tone.Red, size = 48.dp, iconSize = 24.dp)
        Column(Modifier.weight(1f)) {
            Text(stringResource(title), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, color = Wa.Ink)
            Text(stringResource(subtitle), fontSize = 12.5.sp, lineHeight = 19.sp, color = Wa.Mut, modifier = Modifier.padding(top = 3.dp))
        }
        if (state != null) {
            Text(
                state, color = Wa.Red, fontSize = 11.5.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(Wa.Red.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 4.dp),
            )
        }
        Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, null, tint = Wa.Soft, modifier = Modifier.size(20.dp))
    }
}

// ------------------------------------------------------------------------------- 1. the wallet PIN

/**
 * Change the PIN: current → new → confirm. Does its own verification: a wrong current PIN sends the person
 * back to the first step. Ends on a "saved" seal. "Forgot your PIN?" on the first step opens the recovery flow.
 */
@Composable
private fun WaChangePinPage(night: Boolean, onBack: () -> Unit, onForgot: () -> Unit) {
    val networkError = stringResource(R.string.wa_error_network)
    val mismatch = stringResource(R.string.wa_pin_mismatch)
    val textCurrent = stringResource(R.string.wa_pin_current_title) to stringResource(R.string.wa_pin_current_sub)
    val textNew = stringResource(R.string.wa_pin_new_title) to stringResource(R.string.wa_pin_digits_sub)
    val textConfirm = stringResource(R.string.wa_pin_confirm_title) to stringResource(R.string.wa_pin_confirm_sub)

    var step by remember { mutableStateOf("current") }
    var current by remember { mutableStateOf("") }
    var fresh by remember { mutableStateOf("") }
    var saved by remember { mutableStateOf(false) }

    WaPage(title = stringResource(R.string.wa_pin_menu_pin_title), onBack = onBack, scroll = false, dark = night) {
        if (saved) {
            WaStatusColumn {
                WaSeal()
                WaStatusTitle(stringResource(R.string.wa_pin_saved_title))
                WaStatusText(stringResource(R.string.wa_pin_saved_text))
                Spacer(Modifier.padding(top = 12.dp))
                WaButton(stringResource(R.string.wa_done), onBack, modifier = Modifier.padding(horizontal = 20.dp))
            }
        } else {
            Column(Modifier.fillMaxSize().padding(horizontal = 8.dp)) {
                val (title, sub) = when (step) {
                    "current" -> textCurrent
                    "new" -> textNew
                    else -> textConfirm
                }
                WaPinPad(
                    title = title, sub = sub, icon = Icons.Rounded.Shield, dark = night,
                    modifier = Modifier.weight(1f).waRise(0),
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
                    WaButton(stringResource(R.string.wa_forgot_link), onForgot, style = WaButtonStyle.Quiet)
                }
            }
        }
    }
}

// ------------------------------------------------------------------------------- 3. fingerprint / face

/**
 * Turning the biometric unlock on or off. Turning it on needs the PIN once (the PIN pad), then the system
 * prompt; turning it off needs nothing — it is only a local convenience, never a way to pay by itself.
 */
@Composable
private fun WaBiometricPage(night: Boolean, onBack: () -> Unit) {
    val host = LocalWallet.current
    val context = LocalContext.current
    val activity = context.findFragmentActivity()
    val networkError = stringResource(R.string.wa_error_network)
    val setupFailed = stringResource(R.string.wa_biometric_setup_failed)
    val available = remember { WaBiometric.isAvailable(context) }
    var enabled by remember { mutableStateOf(WaBiometric.isEnabled(context)) }
    var verifying by remember { mutableStateOf(false) }

    BackHandler(enabled = verifying) { verifying = false }

    WaPage(
        title = stringResource(R.string.wa_biometric_menu_title),
        onBack = { if (verifying) verifying = false else onBack() },
        scroll = !verifying,
        dark = night,
    ) {
        if (verifying) {
            WaPinPad(
                title = stringResource(R.string.wa_pin_enter_title), sub = stringResource(R.string.wa_biometric_toggle),
                icon = Icons.Rounded.Fingerprint, dark = night,
                modifier = Modifier.weight(1f).waRise(0),
                onComplete = { pin ->
                    try {
                        ApiClient.wallet.verifyPin(walletAuth(), pin)
                    } catch (e: CancellationException) {
                        throw e
                    } catch (e: Exception) {
                        val failure = e.apiFailure()
                        val lockedUntil = failure.lockedUntil?.let { runCatching { java.time.Instant.parse(it).toEpochMilli() }.getOrNull() }
                        if (failure.errorCode == "wallet_pin_locked" && lockedUntil != null) {
                            return@WaPinPad PadResult.Locked(lockedUntil)
                        }
                        return@WaPinPad PadResult.Error(if (failure.httpStatus == null) networkError else failure.message ?: networkError)
                    }
                    verifying = false
                    if (activity == null) {
                        host.showToast(setupFailed)
                        return@WaPinPad PadResult.Ok
                    }
                    WaBiometric.enable(activity, pin) { ok ->
                        enabled = ok
                        if (!ok) host.showToast(setupFailed)
                    }
                    PadResult.Ok
                },
            )
        } else {
            Column(Modifier.fillMaxWidth().padding(top = 8.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                Box(Modifier.waRise(0)) { WaIconWell(Icons.Rounded.Fingerprint, Tone.Red, size = 96.dp, iconSize = 48.dp) }
                Text(
                    stringResource(R.string.wa_biometric_menu_title),
                    fontSize = 20.sp, fontWeight = FontWeight.ExtraBold, color = Wa.Ink, textAlign = TextAlign.Center,
                    modifier = Modifier.padding(top = 18.dp).waRise(1),
                )
                Text(
                    stringResource(R.string.wa_biometric_toggle_hint),
                    fontSize = 13.sp, lineHeight = 23.sp, color = Wa.Mut, textAlign = TextAlign.Center,
                    modifier = Modifier.padding(top = 10.dp, start = 8.dp, end = 8.dp).waRise(2),
                )
            }
            Spacer(Modifier.height(24.dp))
            if (available) {
                WaCard(Modifier.fillMaxWidth().waRise(3), padding = 16.dp) {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                        WaIconWell(Icons.Rounded.Fingerprint, Tone.Red, size = 44.dp, iconSize = 22.dp)
                        Column(Modifier.weight(1f)) {
                            Text(stringResource(R.string.wa_biometric_toggle), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, color = Wa.Ink)
                            Text(
                                stringResource(if (enabled) R.string.wa_on else R.string.wa_off),
                                fontSize = 12.sp, color = if (enabled) Wa.Red else Wa.Mut, modifier = Modifier.padding(top = 2.dp),
                            )
                        }
                        Switch(
                            checked = enabled,
                            onCheckedChange = { checked ->
                                if (checked) {
                                    verifying = true
                                } else {
                                    WaBiometric.disable(context)
                                    enabled = false
                                }
                            },
                            colors = SwitchDefaults.colors(
                                checkedThumbColor = Color.White,
                                checkedTrackColor = Wa.Red,
                                uncheckedThumbColor = Color.White,
                                uncheckedTrackColor = Wa.Soft,
                                uncheckedBorderColor = Color.Transparent,
                            ),
                        )
                    }
                }
            } else {
                WaNote(stringResource(R.string.wa_biometric_unavailable), icon = Icons.Rounded.Info, modifier = Modifier.waRise(3))
            }
        }
    }
}
