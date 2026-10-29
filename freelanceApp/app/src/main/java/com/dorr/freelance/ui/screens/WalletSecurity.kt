package com.dorr.freelance.ui.screens

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
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
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.Fingerprint
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material.icons.rounded.VpnKey
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.ChangePinRequest
import com.dorr.freelance.net.PinStatusDto
import com.dorr.freelance.net.WalletBiometric
import com.dorr.freelance.net.errorText
import com.dorr.freelance.net.findFragmentActivity
import com.dorr.freelance.ui.kit.RedToggle
import com.dorr.freelance.ui.wallet.LocalWalletOrNull
import com.dorr.freelance.ui.wallet.PadResult
import com.dorr.freelance.ui.wallet.Tone
import com.dorr.freelance.ui.wallet.Wa
import com.dorr.freelance.ui.wallet.WaButton
import com.dorr.freelance.ui.wallet.WaButtonStyle
import com.dorr.freelance.ui.wallet.WaCard
import com.dorr.freelance.ui.wallet.WaIconWell
import com.dorr.freelance.ui.wallet.WaNote
import com.dorr.freelance.ui.wallet.WaPage
import com.dorr.freelance.ui.wallet.WaPinPad
import com.dorr.freelance.ui.wallet.WaSeal
import com.dorr.freelance.ui.wallet.WaStatusColumn
import com.dorr.freelance.ui.wallet.WaStatusText
import com.dorr.freelance.ui.wallet.WaStatusTitle
import com.dorr.freelance.ui.wallet.rememberPressScale
import com.dorr.freelance.ui.wallet.waRise

/** The pages behind the wallet's settings: the menu and what it opens. */
private enum class SecuritySub { Menu, ChangePin, Recovery, Biometric }

/**
 * The wallet's security settings, as in the DORR app: a menu of three pages — the wallet PIN (change it, with "forgot your PIN?"
 * on its first step), the recovery method, and unlocking with a fingerprint or face. A sub page's back returns to the menu.
 * The wallet status is read once when this opens (and again after a recovery).
 */
@Composable
fun WalletSecurity(onBack: () -> Unit) {
    var sub by rememberSaveable { mutableStateOf(SecuritySub.Menu.name) }
    var forgot by rememberSaveable { mutableStateOf(false) }
    var status by remember { mutableStateOf<PinStatusDto?>(null) }
    LaunchedEffect(Unit) { (apiCall { ApiClient.wallet.pinStatus() } as? ApiResult.Ok)?.data?.let { status = it } }

    BackHandler(enabled = sub != SecuritySub.Menu.name && !forgot) { sub = SecuritySub.Menu.name }

    val current = status
    if (forgot && current != null) {
        // A recovered PIN is a new one: the wallet locks and asks for it again (the stale one is dropped).
        WalletRecover(current, onBack = { forgot = false }) {
            forgot = false
            WalletSession.lock()
        }
        return
    }
    when (SecuritySub.valueOf(sub)) {
        SecuritySub.ChangePin -> ChangePinPage(onBack = { sub = SecuritySub.Menu.name }, onForgot = { forgot = true })
        SecuritySub.Recovery -> ChangeRecoveryPage(status = current, onExit = { sub = SecuritySub.Menu.name })
        SecuritySub.Biometric -> BiometricPage(onBack = { sub = SecuritySub.Menu.name })
        SecuritySub.Menu -> SecurityMenu(onBack = onBack, onOpen = { sub = it.name })
    }
}

// ------------------------------------------------------------------------------- the menu

@Composable
private fun SecurityMenu(onBack: () -> Unit, onOpen: (SecuritySub) -> Unit) {
    val context = LocalContext.current
    val biometricAvailable = remember { WalletBiometric.isAvailable(context) }

    WaPage(title = stringResource(R.string.wa_security_title), onBack = onBack) {
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
            Text(stringResource(R.string.wa_security_intro), color = Wa.Ink, fontSize = 12.sp, lineHeight = 19.sp, fontWeight = FontWeight.Medium, modifier = Modifier.weight(1f))
        }

        Spacer(Modifier.height(10.dp))
        SecurityRow(Icons.Rounded.Lock, R.string.wa_pin_menu_pin_title, R.string.wa_pin_menu_pin_sub, 1, null) { onOpen(SecuritySub.ChangePin) }
        SecurityRow(Icons.Rounded.VpnKey, R.string.wa_rec_change_link, R.string.wa_pin_menu_recovery_sub, 2, null) { onOpen(SecuritySub.Recovery) }
        SecurityRow(
            Icons.Rounded.Fingerprint, R.string.wa_biometric_menu_title, R.string.wa_biometric_menu_sub, 3,
            if (!biometricAvailable) stringResource(R.string.wa_not_available) else null,
        ) { onOpen(SecuritySub.Biometric) }
    }
}

/** One entry of the menu: brand-tinted icon, title + one line of help, optional state, chevron. */
@Composable
private fun SecurityRow(icon: ImageVector, title: Int, subtitle: Int, index: Int, state: String?, onClick: () -> Unit) {
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
            Text(stringResource(title), fontSize = 13.5.sp, fontWeight = FontWeight.Bold, color = Wa.Ink)
            Text(stringResource(subtitle), fontSize = 11.sp, lineHeight = 16.sp, color = Wa.Mut, modifier = Modifier.padding(top = 2.dp))
        }
        if (state != null) {
            Text(
                state, color = Wa.Red, fontSize = 11.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(Wa.Red.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 4.dp),
            )
        }
        Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, null, tint = Wa.Soft, modifier = Modifier.size(20.dp))
    }
}

// ------------------------------------------------------------------------------- 1. the wallet PIN

/**
 * Change the PIN: current → new → confirm, all on the pad, ending on a "saved" seal. A wrong current PIN sends the person back to
 * the first step. "Forgot your PIN?" on the first step opens the recovery flow.
 */
@Composable
private fun ChangePinPage(onBack: () -> Unit, onForgot: () -> Unit) {
    val context = LocalContext.current
    val offlineText = stringResource(R.string.wa_error_network)
    val genericError = stringResource(R.string.wa_error_generic)
    val mismatch = stringResource(R.string.wa_pin_mismatch)
    val textCurrent = stringResource(R.string.wa_pin_current_title) to stringResource(R.string.wa_pin_current_sub)
    val textNew = stringResource(R.string.wa_pin_new_title) to stringResource(R.string.wa_pin_digits_sub)
    val textConfirm = stringResource(R.string.wa_pin_confirm_title) to stringResource(R.string.wa_pin_confirm_sub)

    var step by remember { mutableStateOf("current") }
    var current by remember { mutableStateOf("") }
    var fresh by remember { mutableStateOf("") }
    var saved by remember { mutableStateOf(false) }

    WaPage(title = stringResource(R.string.wa_pin_menu_pin_title), onBack = onBack, scroll = false) {
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
                    title = title, sub = sub, icon = Icons.Rounded.Shield,
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
                                val result = apiCall { ApiClient.wallet.changePin(ChangePinRequest(current, fresh, pin)) }
                                if (result is ApiResult.Ok) {
                                    // The fingerprint holds the old PIN: it is turned off, and turned on again with the new one.
                                    if (WalletBiometric.isEnabled(context)) WalletBiometric.disable(context)
                                    WalletSession.unlock(fresh)
                                    saved = true
                                    PadResult.Ok
                                } else {
                                    // A wrong current PIN sends the person back to the start; anything else retries.
                                    step = "current"
                                    val failure = padFailure(result, offlineText, genericError)
                                    failure
                                }
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

// ------------------------------------------------------------------------------- 2. the recovery method

/**
 * Switch how a forgotten PIN is recovered. Proves the current PIN first (the server asks for it too), then the same method
 * pages as at setup — which end after the method, since the PIN itself stays.
 */
@Composable
private fun ChangeRecoveryPage(status: PinStatusDto?, onExit: () -> Unit) {
    val offlineText = stringResource(R.string.wa_error_network)
    val genericError = stringResource(R.string.wa_error_generic)
    var pin by remember { mutableStateOf<String?>(null) }
    val proven = pin

    if (proven != null && status != null) {
        WalletSetup(status, onBack = onExit, onUnlocked = { _, _ -> }, onStatus = {}, existingPin = proven, onChanged = onExit)
        return
    }
    WaPage(title = stringResource(R.string.wa_rec_change_title), onBack = onExit, scroll = false) {
        Box(Modifier.weight(1f).fillMaxWidth().padding(horizontal = 8.dp)) {
            WaPinPad(
                title = stringResource(R.string.wa_pin_enter_title),
                sub = stringResource(R.string.wa_rec_change_pin_sub),
                icon = Icons.Rounded.Shield,
                modifier = Modifier.fillMaxSize().waRise(0),
                onComplete = { entered ->
                    val result = apiCall { ApiClient.wallet.verifyPin(entered) }
                    if (result is ApiResult.Ok) {
                        pin = entered
                        PadResult.Ok
                    } else padFailure(result, offlineText, genericError)
                },
            )
        }
    }
}

// ------------------------------------------------------------------------------- 3. fingerprint / face

/**
 * Turning the fingerprint / face unlock on or off. Turning it on needs the PIN once (the pad), then the system prompt; turning it off
 * needs nothing — it is only a local convenience, never a way to pay by itself.
 */
@Composable
private fun BiometricPage(onBack: () -> Unit) {
    val host = LocalWalletOrNull.current
    val context = LocalContext.current
    val activity = context.findFragmentActivity()
    val offlineText = stringResource(R.string.wa_error_network)
    val genericError = stringResource(R.string.wa_error_generic)
    val setupFailed = stringResource(R.string.wa_biometric_setup_failed)
    val available = remember { WalletBiometric.isAvailable(context) }
    var enabled by remember { mutableStateOf(WalletBiometric.isEnabled(context)) }
    var verifying by remember { mutableStateOf(false) }

    BackHandler(enabled = verifying) { verifying = false }

    WaPage(
        title = stringResource(R.string.wa_biometric_menu_title),
        onBack = { if (verifying) verifying = false else onBack() },
        scroll = !verifying,
    ) {
        if (verifying) {
            WaPinPad(
                title = stringResource(R.string.wa_pin_enter_title), sub = stringResource(R.string.wa_biometric_toggle),
                icon = Icons.Rounded.Fingerprint,
                modifier = Modifier.weight(1f).waRise(0),
                onComplete = { pin ->
                    val result = apiCall { ApiClient.wallet.verifyPin(pin) }
                    if (result !is ApiResult.Ok) return@WaPinPad padFailure(result, offlineText, genericError)
                    verifying = false
                    if (activity == null) {
                        host?.showError(setupFailed)
                        return@WaPinPad PadResult.Ok
                    }
                    WalletBiometric.enable(activity, pin) { ok ->
                        enabled = ok
                        if (!ok) host?.showError(setupFailed)
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
                        }
                        RedToggle(on = enabled) {
                            if (!enabled) {
                                verifying = true
                            } else {
                                WalletBiometric.disable(context)
                                enabled = false
                            }
                        }
                    }
                }
            } else {
                WaNote(stringResource(R.string.wa_biometric_unavailable), icon = Icons.Rounded.Info, modifier = Modifier.waRise(3))
            }
        }
    }
}
