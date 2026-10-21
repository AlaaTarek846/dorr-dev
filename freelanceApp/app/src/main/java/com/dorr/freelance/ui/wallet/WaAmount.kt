package com.dorr.freelance.ui.wallet

import androidx.compose.animation.animateColorAsState
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.runtime.CompositionLocalProvider
import com.dorr.freelance.R
import kotlinx.coroutines.delay

/**
 * The big centred amount entry used by top-up and transfer (`wa-amount`): label, a huge number
 * field with the currency next to it, optional quick-pick chips, and a red glow while focused.
 */
@Composable
fun WaAmountEntry(
    label: String,
    amountText: String,
    onAmountChange: (String) -> Unit,
    currency: String,
    modifier: Modifier = Modifier,
    quick: List<Int> = emptyList(),
    invalid: Boolean = false,
    autoFocus: Boolean = false,
    hero: Boolean = false,
    subtitle: String? = null,
    footerLabel: String? = null,
    footerValue: String? = null,
    below: @Composable (() -> Unit)? = null,
) {
    var focused by remember { mutableStateOf(false) }
    val focusRequester = remember { FocusRequester() }
    val glow by animateColorAsState(if (focused) Wa.Red.copy(alpha = 0.35f) else Color.Transparent, label = "glow")
    val chosen = parseAmountToMinor(amountText)
    val ink = if (hero) Color.White else Wa.Ink
    val mut = if (hero) Color.White.copy(alpha = 0.72f) else Wa.Mut
    val placeholder = if (hero) Color.White.copy(alpha = 0.35f) else if (walletNight()) Wa.Soft else Color(0xFFD1D5DB)
    val amountSize = if (hero) 36.sp else 48.sp

    if (autoFocus) LaunchedEffect(Unit) { delay(350); runCatching { focusRequester.requestFocus() } }

    Column(
        modifier
            .fillMaxWidth()
            .shadow(
                if (hero) 16.dp else if (focused) 16.dp else 10.dp,
                RoundedCornerShape(if (hero) 28.dp else 22.dp),
                ambientColor = if (hero) Wa.Red.copy(alpha = 0.28f) else Color(0x1A111928),
                spotColor = if (hero) Wa.Red.copy(alpha = 0.36f) else if (focused) Wa.Red.copy(alpha = 0.25f) else Color(0x26111928),
            )
            .clip(RoundedCornerShape(if (hero) 28.dp else 22.dp))
            .then(if (hero) Modifier.background(Wa.HeroBrush) else Modifier.background(Wa.Surface))
            .then(if (hero) Modifier else Modifier.border(2.dp, glow, Wa.CardShape))
            .padding(start = 16.dp, end = 16.dp, top = if (hero) 16.dp else 22.dp, bottom = 16.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        if (hero) {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Box(
                    Modifier.size(28.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.16f)),
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(Icons.Rounded.AccountBalanceWallet, null, tint = Color.White, modifier = Modifier.size(14.dp))
                }
                Column(Modifier.weight(1f).padding(horizontal = 8.dp)) {
                    Text(label, color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.Bold)
                    if (!subtitle.isNullOrBlank()) {
                        Text(subtitle, color = Color.White.copy(alpha = 0.68f), fontSize = 11.sp, modifier = Modifier.padding(top = 1.dp))
                    }
                }
            }
        } else {
            Text(label, color = Wa.Mut, fontSize = 13.sp, fontWeight = FontWeight.Bold)
        }
        if (hero) {
            Row(
                Modifier.fillMaxWidth().padding(top = 10.dp, bottom = 8.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.Center,
            ) {
                if (currency.isNotBlank()) {
                    Text(currency, color = mut, fontSize = 16.sp, fontWeight = FontWeight.Bold)
                }
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    BasicTextField(
                        value = amountText,
                        onValueChange = { onAmountChange(it.replace(',', '.').filter { c -> c.isDigit() || c == '.' }.take(12)) },
                        singleLine = true,
                        textStyle = TextStyle(fontSize = 28.sp, fontWeight = FontWeight.ExtraBold, color = ink, textAlign = TextAlign.Center, letterSpacing = (-0.5).sp),
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                        cursorBrush = SolidColor(Color.White),
                        modifier = Modifier
                            .padding(start = 10.dp)
                            .widthIn(min = 72.dp, max = 180.dp)
                            .focusRequester(focusRequester)
                            .onFocusChanged { focused = it.isFocused },
                        decorationBox = { inner ->
                            Box(contentAlignment = Alignment.Center) {
                                if (amountText.isEmpty()) Text("0.00", fontSize = 28.sp, fontWeight = FontWeight.ExtraBold, color = placeholder, letterSpacing = (-0.5).sp)
                                inner()
                            }
                        },
                    )
                }
            }
            Box(Modifier.fillMaxWidth().height(1.dp).background(Color.White.copy(alpha = 0.18f)))
        } else {
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                Row(Modifier.padding(top = 6.dp, bottom = 4.dp), verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(8.dp, Alignment.CenterHorizontally)) {
                    BasicTextField(
                        value = amountText,
                        onValueChange = { onAmountChange(it.replace(',', '.').filter { c -> c.isDigit() || c == '.' }.take(12)) },
                        singleLine = true,
                        textStyle = TextStyle(fontSize = amountSize, fontWeight = FontWeight.ExtraBold, color = ink, textAlign = TextAlign.Center, letterSpacing = (-1).sp),
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                        cursorBrush = SolidColor(Wa.Red),
                        modifier = Modifier.widthIn(min = 96.dp, max = 190.dp).focusRequester(focusRequester).onFocusChanged { focused = it.isFocused },
                        decorationBox = { inner ->
                            Box(contentAlignment = Alignment.Center) {
                                if (amountText.isEmpty()) Text("0.00", fontSize = amountSize, fontWeight = FontWeight.ExtraBold, color = placeholder, letterSpacing = (-1).sp)
                                inner()
                            }
                        },
                    )
                    if (currency.isNotBlank()) {
                        Text(currency, color = Wa.Mut, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(bottom = 10.dp))
                    }
                }
            }
        }
        if (quick.isNotEmpty()) {
            Row(Modifier.fillMaxWidth().padding(top = if (hero) 14.dp else 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                quick.forEach { value ->
                    if (hero) {
                        val on = chosen == value * 100L
                        val source = remember { MutableInteractionSource() }
                        Box(
                            Modifier
                                .weight(1f)
                                .clip(RoundedCornerShape(50))
                                .background(if (on) Color.White.copy(alpha = 0.28f) else Color.White.copy(alpha = 0.12f))
                                .clickable(interactionSource = source, indication = null) { onAmountChange(value.toString()) }
                                .padding(vertical = 9.dp),
                            contentAlignment = Alignment.Center,
                        ) {
                            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                                Text("+$value", color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                            }
                        }
                    } else {
                        WaChip(value.toString(), null, selected = chosen == value * 100L) { onAmountChange(value.toString()) }
                    }
                }
            }
        }
        if (hero && !footerLabel.isNullOrBlank()) {
            Row(
                Modifier
                    .fillMaxWidth()
                    .padding(top = 12.dp)
                    .shadow(8.dp, RoundedCornerShape(16.dp), ambientColor = Color.Black.copy(alpha = 0.18f), spotColor = Color.Black.copy(alpha = 0.22f))
                    .clip(RoundedCornerShape(16.dp))
                    .background(Color.White.copy(alpha = 0.14f))
                    .padding(horizontal = 12.dp, vertical = 10.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(footerLabel, color = Color.White.copy(alpha = 0.72f), fontSize = 11.sp, modifier = Modifier.weight(1f))
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    Text(footerValue.orEmpty(), color = Color.White, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
                }
            }
        }
        if (invalid && amountText.isNotBlank()) WaError(stringResource(R.string.wa_amount_invalid))
        below?.invoke()
    }
}
