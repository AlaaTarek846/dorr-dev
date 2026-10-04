package com.dorr.app.ui.screens.wallet

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Backspace
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.runtime.CompositionLocalProvider
import com.dorr.app.R
import com.dorr.app.network.apiFailure
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** What the pad does once four digits are in. */
sealed interface PadResult {
    /** All good, the caller moves on. */
    data object Ok : PadResult

    /** Shake, show [message], clear so typing can start again. */
    data class Error(val message: String) : PadResult

    /** Clear silently (moving to the next step). */
    data object Reset : PadResult

    /**
     * A temporary server-side lock (too many wrong PINs) — the pad itself disappears behind a live
     * countdown down to [untilEpochMillis] and refuses all input until it ends, so a person can't keep
     * sending doomed attempts (and, per docs/wallet-tasks.md §10.6, can't accidentally turn a temporary
     * lock into a permanent freeze by trying again before it is actually over).
     */
    data class Locked(val untilEpochMillis: Long) : PadResult
}

/**
 * The four-dot PIN pad used everywhere (wallet gate, PIN sheet, PIN settings) — same as the
 * preview's `pinPadMarkup` + `mountPinPad`: dots that pop in, a shake on a wrong PIN, a keypad
 * that ignores taps while the server is being asked.
 */
@Composable
fun WaPinPad(
    title: String,
    sub: String,
    onComplete: suspend (String) -> PadResult,
    modifier: Modifier = Modifier,
    icon: ImageVector = Icons.Rounded.Lock,
    tone: Tone = Tone.Red,
    dark: Boolean = false,
    /** A lock already known when the pad opens (from the PIN status) — start on the countdown. */
    lockedUntil: Long? = null,
) {
    var value by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf("") }
    var bad by remember { mutableStateOf(false) }
    var lockedUntilMillis by remember(lockedUntil) { mutableStateOf(lockedUntil?.takeIf { it > System.currentTimeMillis() }) }
    var remainingSeconds by remember { mutableStateOf(0L) }
    val shake = remember { Animatable(0f) }
    val scope = rememberCoroutineScope()
    val haptic = LocalHapticFeedback.current
    val night = dark || walletNight()

    // Ticks the countdown while locked; clears itself when time is up — never fires a request on its own.
    LaunchedEffect(lockedUntilMillis) {
        val until = lockedUntilMillis ?: return@LaunchedEffect
        while (true) {
            val left = (until - System.currentTimeMillis()) / 1000L
            if (left <= 0) {
                remainingSeconds = 0
                lockedUntilMillis = null
                break
            }
            remainingSeconds = left
            delay(1000)
        }
    }

    fun complete(pin: String) {
        scope.launch {
            busy = true
            val result = try {
                onComplete(pin)
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                val failure = e.apiFailure()
                val until = failure.lockedUntil?.let { runCatching { java.time.Instant.parse(it).toEpochMilli() }.getOrNull() }
                if (failure.errorCode == "wallet_pin_locked" && until != null) PadResult.Locked(until) else PadResult.Error(failure.message ?: "")
            }
            busy = false
            when (result) {
                is PadResult.Error -> {
                    error = result.message
                    value = ""
                    bad = true
                    haptic.performHapticFeedback(HapticFeedbackType.LongPress)
                    repeat(3) { shake.animateTo(1f, tween(70)); shake.animateTo(-1f, tween(70)) }
                    shake.animateTo(0f, tween(60))
                    bad = false
                }
                is PadResult.Locked -> {
                    value = ""
                    error = ""
                    lockedUntilMillis = result.untilEpochMillis
                }
                PadResult.Reset -> value = ""
                PadResult.Ok -> Unit
            }
        }
    }

    fun press(key: String) {
        if (busy || lockedUntilMillis != null) return
        error = ""
        if (key == "del") value = value.dropLast(1) else if (value.length < 4) value += key
        if (value.length == 4) complete(value)
    }

    if (lockedUntilMillis != null) {
        WaPinLockedCountdown(modifier, icon, remainingSeconds)
        return
    }

    Column(
        modifier
            .fillMaxWidth()
            .fillMaxHeight()
            .alpha(if (busy) 0.55f else 1f),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Spacer(Modifier.weight(0.12f))
        val cardShape = RoundedCornerShape(22.dp)
        Column(
            Modifier
                .fillMaxWidth()
                .padding(horizontal = 8.dp)
                .then(
                    if (night) Modifier
                    else Modifier.shadow(12.dp, cardShape, ambientColor = Color(0x1A001B53), spotColor = Color(0x24001B53)),
                )
                .clip(cardShape)
                .background(if (night) com.dorr.app.ui.screens.AccountDark.card else Color.White)
                .then(if (night) Modifier.border(1.dp, com.dorr.app.ui.screens.AccountDark.line, cardShape) else Modifier)
                .padding(horizontal = 16.dp, vertical = 18.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            WaIconWell(icon, tone, size = 58.dp, iconSize = 28.dp)
            Spacer(Modifier.height(10.dp))
            Text(title, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp, color = if (night) com.dorr.app.ui.screens.AccountDark.ink else Wa.Ink, textAlign = TextAlign.Center)
            Spacer(Modifier.height(4.dp))
            Text(sub, color = if (night) com.dorr.app.ui.screens.AccountDark.mut else Wa.Mut, fontSize = 13.sp, textAlign = TextAlign.Center, modifier = Modifier.heightIn(min = 20.dp))
            Row(
                Modifier.padding(top = 16.dp).graphicsLayer { translationX = shake.value * 6.dp.toPx() },
                horizontalArrangement = Arrangement.spacedBy(16.dp),
            ) {
                repeat(4) { index ->
                    val on = index < value.length
                    val dotScale by animateFloatAsState(if (on) 1.18f else 1f, spring(dampingRatio = 0.45f, stiffness = 500f), label = "dot")
                    val fill = if (on) (if (bad) Wa.Danger else Wa.Red) else Color.Transparent
                    Box(
                        Modifier
                            .size(16.dp)
                            .scale(dotScale)
                            .clip(CircleShape)
                            .background(fill)
                            .border(2.dp, if (on) fill else if (night) com.dorr.app.ui.screens.AccountDark.line else Color(0xFFD1D5DB), CircleShape),
                    )
                }
            }
            Text(error, color = Wa.Danger, fontSize = 12.5.sp, textAlign = TextAlign.Center, modifier = Modifier.heightIn(min = 22.dp).padding(top = 6.dp))
        }

        Spacer(Modifier.weight(0.25f))

        // Digits always read left-to-right, whatever the app language.
        // The keypad stretches to fill the screen down to the bottom bar.
        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
            Column(
                Modifier
                    .widthIn(max = 320.dp)
                    .weight(3.2f)
                    .padding(bottom = 6.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                listOf(listOf("1", "2", "3"), listOf("4", "5", "6"), listOf("7", "8", "9"), listOf("", "0", "del")).forEach { row ->
                    Row(
                        Modifier.weight(1f),
                        horizontalArrangement = Arrangement.spacedBy(10.dp),
                    ) {
                        row.forEach { key ->
                            Box(Modifier.weight(1f).fillMaxHeight()) {
                                if (key.isNotEmpty()) PadKey(key, Modifier.fillMaxSize(), night) { press(key) }
                            }
                        }
                    }
                }
            }
        }
    }
}

/**
 * Replaces the pad entirely while a temporary lock is in effect — a live mm:ss countdown, no digits,
 * nothing to press. Goes away on its own once the countdown reaches zero (see the `LaunchedEffect` in
 * [WaPinPad]); this composable never talks to the network.
 */
@Composable
private fun WaPinLockedCountdown(modifier: Modifier, icon: ImageVector, remainingSeconds: Long) {
    val minutes = remainingSeconds / 60
    val seconds = remainingSeconds % 60
    Column(
        modifier.fillMaxWidth().fillMaxHeight(),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Spacer(Modifier.weight(0.3f))
        WaIconWell(icon, Tone.Amber, size = 58.dp, iconSize = 28.dp)
        Spacer(Modifier.height(14.dp))
        Text(stringResource(R.string.wa_pin_locked_title), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp, color = Wa.Ink, textAlign = TextAlign.Center)
        Spacer(Modifier.height(6.dp))
        Text(stringResource(R.string.wa_pin_locked_sub), color = Wa.Mut, fontSize = 13.sp, textAlign = TextAlign.Center, modifier = Modifier.padding(horizontal = 24.dp))
        Spacer(Modifier.height(18.dp))
        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
            Text(
                "%02d:%02d".format(minutes, seconds),
                fontWeight = FontWeight.ExtraBold,
                fontSize = 40.sp,
                color = Wa.Red,
                letterSpacing = 2.sp,
            )
        }
        Spacer(Modifier.weight(0.5f))
    }
}

@Composable
private fun PadKey(key: String, modifier: Modifier = Modifier, dark: Boolean = false, onClick: () -> Unit) {
    val source = remember { MutableInteractionSource() }
    val pressScale by rememberPressScale(source, 0.9f)
    val isDelete = key == "del"
    val keyShape = RoundedCornerShape(18.dp)
    Box(
        modifier
            .scale(pressScale)
            .then(
                if (isDelete || dark) Modifier
                else Modifier.shadow(6.dp, keyShape, ambientColor = Color(0x14001B53), spotColor = Color(0x1A001B53)),
            )
            .clip(keyShape)
            .background(if (isDelete) Color.Transparent else if (dark) com.dorr.app.ui.screens.AccountDark.card else Color.White)
            .clickable(interactionSource = source, indication = null, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        val ink = if (dark) com.dorr.app.ui.screens.AccountDark.ink else Wa.Ink
        if (isDelete) Icon(Icons.AutoMirrored.Rounded.Backspace, null, tint = ink, modifier = Modifier.size(24.dp))
        else Text(key, fontSize = 23.sp, fontWeight = FontWeight.Bold, color = ink)
    }
}

/**
 * One PIN prompt for every protected action, as the pad inside a bottom sheet. [onSubmit] runs the
 * action and says how to react (done / retry with a message / close with a message). A PIN is always
 * created on its own page first (recovery method + PIN), never here.
 */
@Composable
fun WaPinSheetContent(sheet: WaSheet.Pin, host: WalletHost) {
    WaPinPad(title = stringResource(R.string.wa_pin_enter_title), sub = sheet.subtitle, onComplete = { pin ->
        when (val outcome = sheet.onSubmit(pin)) {
            is PinOutcome.Retry -> PadResult.Error(outcome.message)
            is PinOutcome.Close -> {
                host.closeSheet()
                sheet.onClose(outcome.message)
                PadResult.Ok
            }
            PinOutcome.Done -> {
                host.closeSheet()
                PadResult.Ok
            }
        }
    }, lockedUntil = sheet.lockedUntil)
}
