package com.dorr.freelance.ui.wallet

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * The little that the DORR app's `WalletHost` gives the wallet pages here: the toast at the top (an error in red, a note in
 * dark), shown for a few seconds. The pages, the PIN and the money logic live in the freelancer app's own wallet screens.
 */
class WalletHost(private val scope: CoroutineScope) {
    var toast by mutableStateOf<String?>(null)
        private set
    var toastError by mutableStateOf(false)
        private set
    private var job: Job? = null

    fun showToast(message: String, error: Boolean = false) {
        if (message.isBlank()) return
        toast = message
        toastError = error
        job?.cancel()
        job = scope.launch {
            delay(3200)
            toast = null
        }
    }

    fun showError(message: String) = showToast(message, error = true)
}

/** The wallet's host when the page is inside the wallet (errors become its toast), null elsewhere (a system toast). */
val LocalWalletOrNull = staticCompositionLocalOf<WalletHost?> { null }

@Composable
fun WaToastHost(host: WalletHost) {
    val message = host.toast
    var last by remember { mutableStateOf("") }
    var lastIsError by remember { mutableStateOf(false) }
    if (message != null) {
        last = message
        lastIsError = host.toastError
    }
    // A notification at the top, over everything (the PIN pad included), so it never hides the keypad or the buttons.
    Box(Modifier.fillMaxWidth().padding(start = 16.dp, end = 16.dp, top = 10.dp), contentAlignment = Alignment.TopCenter) {
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

/** Filter pill (`wa-chip`): white with a hairline border, the brand colour and filled when selected. */
@Composable
fun WaChip(text: String, icon: ImageVector?, selected: Boolean, onClick: () -> Unit) {
    val source = remember { MutableInteractionSource() }
    val pressScale by rememberPressScale(source, 0.92f)
    Row(
        Modifier
            .scale(pressScale)
            .clip(RoundedCornerShape(999.dp))
            .background(if (selected) Wa.Red else Wa.Surface)
            .border(1.5.dp, if (selected) Wa.Red else Wa.Line, RoundedCornerShape(999.dp))
            .clickable(interactionSource = source, indication = null, onClick = onClick)
            .padding(horizontal = 14.dp, vertical = 7.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        if (icon != null) Icon(icon, null, tint = if (selected) Color.White else Wa.Ink, modifier = Modifier.size(16.dp))
        Text(text, color = if (selected) Color.White else Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold)
    }
}

/** "اليوم" / "أمس" / a long date. */
@Composable
internal fun dayText(iso: String?): String = when (daysAgo(iso)) {
    0L -> androidx.compose.ui.res.stringResource(com.dorr.freelance.R.string.wa_today)
    1L -> androidx.compose.ui.res.stringResource(com.dorr.freelance.R.string.wa_yesterday)
    else -> longDate(iso)
}
