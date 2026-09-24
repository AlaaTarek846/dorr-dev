package com.dorr.app.ui.screens.wallet

import androidx.compose.animation.animateColorAsState
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
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
import com.dorr.app.R
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
    below: @Composable (() -> Unit)? = null,
) {
    var focused by remember { mutableStateOf(false) }
    val focusRequester = remember { FocusRequester() }
    val glow by animateColorAsState(if (focused) Color(0x59E50914) else Color.Transparent, label = "glow")
    val chosen = parseAmountToMinor(amountText)

    if (autoFocus) LaunchedEffect(Unit) { delay(350); runCatching { focusRequester.requestFocus() } }

    Column(
        modifier
            .fillMaxWidth()
            .shadow(if (focused) 16.dp else 10.dp, Wa.CardShape, ambientColor = Color(0x1A111928), spotColor = if (focused) Color(0x40E50914) else Color(0x26111928))
            .clip(Wa.CardShape)
            .background(Color.White)
            .border(2.dp, glow, Wa.CardShape)
            .padding(start = 16.dp, end = 16.dp, top = 22.dp, bottom = 16.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(label, color = Wa.Mut, fontSize = 13.sp, fontWeight = FontWeight.Bold)
        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
            Row(Modifier.padding(top = 6.dp, bottom = 4.dp), verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(8.dp, Alignment.CenterHorizontally)) {
                BasicTextField(
                    value = amountText,
                    onValueChange = { onAmountChange(it.replace(',', '.').filter { c -> c.isDigit() || c == '.' }.take(12)) },
                    singleLine = true,
                    textStyle = TextStyle(fontSize = 48.sp, fontWeight = FontWeight.ExtraBold, color = Wa.Ink, textAlign = TextAlign.Center, letterSpacing = (-1).sp),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                    cursorBrush = SolidColor(Wa.Red),
                    modifier = Modifier.widthIn(min = 96.dp, max = 190.dp).focusRequester(focusRequester).onFocusChanged { focused = it.isFocused },
                    decorationBox = { inner ->
                        Box(contentAlignment = Alignment.Center) {
                            if (amountText.isEmpty()) Text("0.00", fontSize = 48.sp, fontWeight = FontWeight.ExtraBold, color = Color(0xFFD1D5DB), letterSpacing = (-1).sp)
                            inner()
                        }
                    },
                )
                Text(currency, color = Wa.Mut, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(bottom = 10.dp))
            }
        }
        if (quick.isNotEmpty()) {
            Row(Modifier.padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp, Alignment.CenterHorizontally)) {
                quick.forEach { value ->
                    WaChip(value.toString(), null, selected = chosen == value * 100L) { onAmountChange(value.toString()) }
                }
            }
        }
        if (invalid && amountText.isNotBlank()) WaError(stringResource(R.string.wa_amount_invalid))
        below?.invoke()
    }
}
