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
import com.dorr.app.network.ApiClient
import com.dorr.app.network.CreatePinRequest
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
) {
    var value by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf("") }
    var bad by remember { mutableStateOf(false) }
    val shake = remember { Animatable(0f) }
    val scope = rememberCoroutineScope()
    val haptic = LocalHapticFeedback.current

    fun complete(pin: String) {
        scope.launch {
            busy = true
            val result = try {
                onComplete(pin)
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                PadResult.Error(e.apiFailure().message ?: "")
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
                PadResult.Reset -> value = ""
                PadResult.Ok -> Unit
            }
        }
    }

    fun press(key: String) {
        if (busy) return
        error = ""
        if (key == "del") value = value.dropLast(1) else if (value.length < 4) value += key
        if (value.length == 4) complete(value)
    }

    Column(
        modifier
            .fillMaxWidth()
            .fillMaxHeight()
            .alpha(if (busy) 0.55f else 1f),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Spacer(Modifier.weight(0.15f))
        WaIconWell(icon, tone, size = 58.dp, iconSize = 28.dp)
        Spacer(Modifier.height(10.dp))
        Text(title, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp, color = Wa.Ink, textAlign = TextAlign.Center)
        Spacer(Modifier.height(4.dp))
        Text(sub, color = Wa.Mut, fontSize = 13.sp, textAlign = TextAlign.Center, modifier = Modifier.heightIn(min = 20.dp))

        Row(
            Modifier.padding(top = 16.dp, bottom = 6.dp).graphicsLayer { translationX = shake.value * 6.dp.toPx() },
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
                        .border(2.dp, if (on) fill else Color(0xFFD1D5DB), CircleShape),
                )
            }
        }

        Text(error, color = Wa.Danger, fontSize = 12.5.sp, textAlign = TextAlign.Center, modifier = Modifier.heightIn(min = 22.dp).padding(vertical = 2.dp))

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
                                if (key.isNotEmpty()) PadKey(key, Modifier.fillMaxSize()) { press(key) }
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun PadKey(key: String, modifier: Modifier = Modifier, onClick: () -> Unit) {
    val source = remember { MutableInteractionSource() }
    val pressScale by rememberPressScale(source, 0.9f)
    val isDelete = key == "del"
    Box(
        modifier
            .scale(pressScale)
            .clip(RoundedCornerShape(18.dp))
            .background(if (isDelete) Color.Transparent else Wa.Key)
            .clickable(interactionSource = source, indication = null, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        if (isDelete) Icon(Icons.AutoMirrored.Rounded.Backspace, null, tint = Wa.Ink, modifier = Modifier.size(24.dp))
        else Text(key, fontSize = 23.sp, fontWeight = FontWeight.Bold, color = Wa.Ink)
    }
}

/**
 * One PIN prompt for every protected action, as the pad inside a bottom sheet. Creation is lazy:
 * with no PIN yet the enter + confirm steps replace the verify step right here. [onSubmit] runs
 * the action and says how to react (done / retry with a message / close with a message).
 */
@Composable
fun WaPinSheetContent(sheet: WaSheet.Pin, host: WalletHost) {
    val enterTitle = stringResource(R.string.wa_pin_enter_title)
    val createTitle = stringResource(R.string.wa_pin_create_title_sheet)
    val createSub = stringResource(R.string.wa_pin_create_sub_sheet)
    val confirmTitle = stringResource(R.string.wa_pin_confirm_title)
    val confirmSub = stringResource(R.string.wa_pin_confirm_sub)
    val mismatch = stringResource(R.string.wa_pin_mismatch)
    val networkError = stringResource(R.string.wa_error_network)

    var step by remember { mutableStateOf(if (sheet.hasPin) "enter" else "create") }
    var first by remember { mutableStateOf("") }

    val (title, sub) = when (step) {
        "enter" -> enterTitle to sheet.subtitle
        "create" -> createTitle to createSub
        else -> confirmTitle to confirmSub
    }

    WaPinPad(title = title, sub = sub, onComplete = { pin ->
        if (step == "create") {
            first = pin
            step = "confirm"
            return@WaPinPad PadResult.Reset
        }
        if (step == "confirm") {
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
            step = "enter"
        }
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
    })
}
