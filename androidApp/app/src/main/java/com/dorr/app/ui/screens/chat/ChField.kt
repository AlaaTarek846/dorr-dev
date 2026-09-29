package com.dorr.app.ui.screens.chat

import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.ui.theme.CairoFontFamily

/**
 * The chat's text field — the **system's field design** (the profile page's fields): a rounded
 * pill (a softer card when it's several lines), the settings' field fill and hairline, an icon
 * in the app colour, the hairline turning the app colour when focused, the app font. Colours come
 * from [Ch], which reads the appearance settings — so it looks right in light and dark mode.
 *
 * [clearable] adds a ✕ once something is typed (search boxes).
 */
@Composable
fun ChField(
    value: String,
    onValueChange: (String) -> Unit,
    placeholder: String,
    modifier: Modifier = Modifier,
    icon: ImageVector? = null,
    singleLine: Boolean = true,
    minLines: Int = 1,
    maxLines: Int = if (singleLine) 1 else 6,
    keyboardOptions: KeyboardOptions = KeyboardOptions.Default,
    fontSize: TextUnit = 14.5.sp,
    bold: Boolean = false,
    center: Boolean = false,
    error: Boolean = false,
    clearable: Boolean = false,
    fieldModifier: Modifier = Modifier,
    trailing: (@Composable () -> Unit)? = null,
) {
    var focused by remember { mutableStateOf(false) }
    val shape = if (singleLine) RoundedCornerShape(999.dp) else RoundedCornerShape(20.dp)
    val line by animateColorAsState(
        when {
            error -> Ch.Danger
            focused -> Ch.Red
            else -> Ch.FieldLine
        },
        tween(200), label = "chFieldLine",
    )
    val fill = if (focused && !Ch.dark) Ch.Surface else Ch.FieldFill
    val style = TextStyle(
        color = Ch.Ink, fontSize = fontSize, fontFamily = CairoFontFamily,
        fontWeight = if (bold) FontWeight.Bold else FontWeight.Normal,
        textAlign = if (center) TextAlign.Center else TextAlign.Start,
    )

    BasicTextField(
        value = value,
        onValueChange = onValueChange,
        singleLine = singleLine,
        minLines = minLines,
        maxLines = maxLines,
        keyboardOptions = keyboardOptions,
        textStyle = style,
        cursorBrush = SolidColor(Ch.Red),
        modifier = modifier.fillMaxWidth().onFocusChanged { focused = it.isFocused }.then(fieldModifier),
        decorationBox = { inner ->
            Row(
                Modifier
                    .fillMaxWidth()
                    .heightIn(min = 46.dp)
                    .clip(shape)
                    .background(fill)
                    .border(if (focused || error) 1.5.dp else 1.dp, line, shape)
                    .padding(horizontal = 14.dp, vertical = if (singleLine) 8.dp else 12.dp),
                verticalAlignment = if (singleLine) Alignment.CenterVertically else Alignment.Top,
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                if (icon != null) {
                    Icon(icon, null, tint = if (error) Ch.Danger else Ch.Red.copy(alpha = if (focused) 1f else 0.85f), modifier = Modifier.size(18.dp))
                }
                Box(Modifier.weight(1f), contentAlignment = if (center) Alignment.Center else Alignment.CenterStart) {
                    if (value.isEmpty()) Text(placeholder, style = style.copy(color = Ch.Soft, fontWeight = if (bold) FontWeight.SemiBold else FontWeight.Normal))
                    inner()
                }
                if (clearable && value.isNotEmpty()) {
                    Box(Modifier.size(26.dp).clip(CircleShape).background(Ch.Soft.copy(alpha = 0.18f)).clickable { onValueChange("") }, contentAlignment = Alignment.Center) {
                        Icon(Icons.Rounded.Close, null, tint = Ch.Mut, modifier = Modifier.size(14.dp))
                    }
                }
                trailing?.invoke()
            }
        },
    )
}
