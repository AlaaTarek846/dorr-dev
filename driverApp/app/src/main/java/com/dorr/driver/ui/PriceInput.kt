package com.dorr.driver.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Remove
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
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
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.driver.net.CurrencyDto
import com.dorr.driver.ui.theme.DorrBrand
import java.math.BigDecimal
import java.util.Locale

/**
 * A price the captain can type straight in — or move with − / + by a real step (the server's first
 * step, 5 by default), not one unit per tap (O-43). [onChange] gets minor units; the caller decides
 * what is allowed (the send button stays off outside [minMinor]..[maxMinor]).
 */
@Composable
internal fun PriceInput(
    priceMinor: Long,
    currency: CurrencyDto?,
    stepMinor: Long,
    minMinor: Long,
    maxMinor: Long,
    onChange: (Long) -> Unit,
) {
    val places = currency?.decimals ?: 2
    val unit = remember(places) { BigDecimal.TEN.pow(places) }
    fun shown(minor: Long): String {
        val major = BigDecimal.valueOf(minor).divide(unit)
        return if (major.stripTrailingZeros().scale() <= 0) major.toBigInteger().toString() else String.format(Locale.US, "%.${places}f", major)
    }

    var text by remember { mutableStateOf(shown(priceMinor)) }
    var editing by remember { mutableStateOf(false) }
    // The buttons and the chips move the number; while typing, the field keeps what was typed.
    LaunchedEffect(priceMinor) { if (!editing) text = shown(priceMinor) }
    val focus = LocalFocusManager.current
    val outOfRange = priceMinor < minMinor || priceMinor > maxMinor

    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth()) {
        StepButton(Icons.Filled.Remove, enabled = priceMinor - stepMinor >= minMinor) {
            focus.clearFocus()
            onChange((priceMinor - stepMinor).coerceIn(minMinor, maxMinor))
        }
        Spacer(Modifier.width(10.dp))
        Box(
            Modifier.weight(1f).height(56.dp).clip(RoundedCornerShape(16.dp))
                .background(MaterialTheme.colorScheme.onSurface.copy(alpha = 0.05f))
                .border(if (editing) 2.dp else 1.dp, if (outOfRange) DorrBrand.danger else if (editing) DorrBrand.orange else MaterialTheme.colorScheme.onSurface.copy(alpha = 0.12f), RoundedCornerShape(16.dp))
                .padding(horizontal = 12.dp),
            contentAlignment = Alignment.Center,
        ) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                BasicTextField(
                    value = text,
                    onValueChange = { raw ->
                        val clean = raw.replace(',', '.').filter { it.isDigit() || it == '.' }.take(9)
                        if (clean.count { it == '.' } > 1 || (places == 0 && clean.contains('.'))) return@BasicTextField
                        text = clean
                        clean.toBigDecimalOrNull()?.let { onChange(it.multiply(unit).toLong()) }
                    },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = if (places > 0) KeyboardType.Decimal else KeyboardType.Number, imeAction = ImeAction.Done),
                    keyboardActions = KeyboardActions(onDone = { focus.clearFocus() }),
                    textStyle = TextStyle(fontSize = 24.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center, color = MaterialTheme.colorScheme.onSurface),
                    cursorBrush = SolidColor(DorrBrand.orange),
                    modifier = Modifier.width(120.dp).onFocusChanged { state ->
                        editing = state.isFocused
                        if (!state.isFocused) text = shown(priceMinor)
                    },
                )
                currency?.symbol?.takeIf { it.isNotBlank() }?.let {
                    Text(it, fontSize = 14.sp, fontWeight = FontWeight.SemiBold, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
                }
            }
        }
        Spacer(Modifier.width(10.dp))
        StepButton(Icons.Filled.Add, enabled = priceMinor + stepMinor <= maxMinor) {
            focus.clearFocus()
            onChange((priceMinor + stepMinor).coerceIn(minMinor, maxMinor))
        }
    }
}

@Composable
private fun StepButton(icon: ImageVector, enabled: Boolean, onClick: () -> Unit) {
    Box(
        Modifier.size(48.dp).clip(CircleShape).background(if (enabled) DorrBrand.navy else Color.Gray.copy(alpha = 0.3f)).clickable(enabled = enabled, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Icon(icon, null, tint = Color.White) }
}
