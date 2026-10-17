package com.dorr.driver.ui.kit

import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material.icons.rounded.VisibilityOff
import androidx.compose.material3.Icon
import androidx.compose.material3.LocalContentColor
import androidx.compose.material3.ProvideTextStyle
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Shape
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.driver.ui.theme.CairoFamily
import com.dorr.driver.ui.theme.Pal

/**
 * The text field of the DORR app, in the same design: a rounded pill with a soft fill, a hairline that turns the
 * brand colour when focused, an icon in the brand colour, and a small caption above (never a floating label).
 * Built on [BasicTextField] — Material's own field has a hard 56dp minimum and squeezes text in tight rows.
 *
 * [onClick] turns the pill into a tap target (date and time pickers) without opening the keyboard.
 */
@Composable
fun DorrTextField(
    value: String,
    onValueChange: (String) -> Unit,
    modifier: Modifier = Modifier,
    label: String? = null,
    placeholder: String? = null,
    placeholderContent: (@Composable () -> Unit)? = null,
    icon: ImageVector? = null,
    password: Boolean = false,
    leading: (@Composable () -> Unit)? = null,
    trailing: (@Composable () -> Unit)? = null,
    keyboardOptions: KeyboardOptions = KeyboardOptions.Default,
    singleLine: Boolean = true,
    minLines: Int = 1,
    error: Boolean = false,
    enabled: Boolean = true,
    onClick: (() -> Unit)? = null,
    minHeight: Dp = 50.dp,
    shape: Shape? = null,
    visualTransformation: VisualTransformation = VisualTransformation.None,
    textStyle: TextStyle = MaterialTheme.typography.bodyLarge,
) {
    var focused by remember { mutableStateOf(false) }
    var revealed by remember { mutableStateOf(false) }
    val fieldShape = shape ?: if (singleLine) RoundedCornerShape(999.dp) else RoundedCornerShape(20.dp)
    val fill by animateColorAsState(if (focused && !Pal.night) Pal.card else Pal.fieldFill, tween(200), label = "fieldFill")
    val borderColor by animateColorAsState(
        when {
            error -> Pal.danger
            focused -> Pal.accent
            else -> Pal.fieldLine
        },
        tween(200), label = "fieldBorder",
    )
    val iconTint by animateColorAsState(if (error) Pal.danger else if (focused) Pal.accent else Pal.accent.copy(alpha = 0.8f), tween(200), label = "fieldIcon")
    val typing = enabled && onClick == null

    Column(modifier) {
        if (label != null) {
            Text(label, fontSize = 13.sp, fontWeight = FontWeight.Bold, color = Pal.ink, modifier = Modifier.padding(bottom = 8.dp))
        }
        BasicTextField(
            value = value,
            onValueChange = onValueChange,
            enabled = typing,
            singleLine = singleLine,
            minLines = minLines,
            keyboardOptions = keyboardOptions,
            visualTransformation = if (password && !revealed) PasswordVisualTransformation() else visualTransformation,
            textStyle = textStyle.copy(color = Pal.ink, fontFamily = CairoFamily),
            cursorBrush = SolidColor(Pal.accent),
            modifier = Modifier.fillMaxWidth().onFocusChanged { focused = it.isFocused },
            decorationBox = { inner ->
                Row(
                    Modifier
                        .fillMaxWidth()
                        .heightIn(min = minHeight)
                        .clip(fieldShape)
                        .background(fill)
                        .border(if (focused || error) 1.5.dp else 1.dp, borderColor, fieldShape)
                        .then(if (onClick != null && enabled) Modifier.clickable(onClick = onClick) else Modifier)
                        .padding(horizontal = 14.dp, vertical = if (singleLine) 8.dp else 12.dp),
                    verticalAlignment = if (singleLine) Alignment.CenterVertically else Alignment.Top,
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    if (icon != null) Icon(icon, null, tint = iconTint, modifier = Modifier.size(18.dp))
                    leading?.invoke()
                    Box(Modifier.weight(1f), contentAlignment = Alignment.CenterStart) {
                        if (value.isEmpty()) {
                            if (placeholderContent != null) {
                                CompositionLocalProvider(LocalContentColor provides Pal.faint) { placeholderContent() }
                            } else if (placeholder != null) {
                                Text(placeholder, style = textStyle.copy(fontFamily = CairoFamily), color = Pal.faint)
                            }
                        }
                        inner()
                    }
                    if (password) {
                        Box(
                            Modifier.size(30.dp).clip(RoundedCornerShape(999.dp)).clickable { revealed = !revealed },
                            contentAlignment = Alignment.Center,
                        ) {
                            Icon(if (revealed) Icons.Rounded.VisibilityOff else Icons.Rounded.Visibility, null, tint = Pal.faint, modifier = Modifier.size(18.dp))
                        }
                    }
                    trailing?.invoke()
                }
            },
        )
    }
}

/**
 * A verification code as separate boxes, driven by one hidden field (so paste and the SMS keyboard
 * suggestion both work). The focused box takes the brand colour, a typed digit pops, and a wrong code
 * shakes the row and turns it red.
 */
@Composable
fun OtpBoxes(code: String, length: Int, error: Boolean, onChange: (String) -> Unit, modifier: Modifier = Modifier) {
    val focus = remember { FocusRequester() }
    val shake = remember { Animatable(0f) }
    LaunchedEffect(Unit) { focus.requestFocus() }
    LaunchedEffect(error) {
        if (!error) return@LaunchedEffect
        repeat(3) {
            shake.animateTo(10f, tween(50))
            shake.animateTo(-10f, tween(50))
        }
        shake.animateTo(0f, tween(50))
    }

    BasicTextField(
        value = code,
        onValueChange = { onChange(it.filter(Char::isDigit).take(length)) },
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
        cursorBrush = SolidColor(Color.Transparent),
        textStyle = TextStyle(color = Color.Transparent),
        modifier = modifier.focusRequester(focus),
        decorationBox = {
            Row(Modifier.offset(x = shake.value.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                repeat(length) { index ->
                    DigitBox(digit = code.getOrNull(index)?.toString(), current = index == code.length, error = error)
                }
            }
        },
    )
}

@Composable
private fun DigitBox(digit: String?, current: Boolean, error: Boolean) {
    val shape = RoundedCornerShape(16.dp)
    val pop = remember { Animatable(1f) }
    LaunchedEffect(digit) {
        if (digit != null) {
            pop.animateTo(1.14f, tween(90))
            pop.animateTo(1f, tween(110))
        }
    }
    val edge by animateColorAsState(
        when {
            error -> Pal.danger
            current -> Pal.accent
            digit != null -> Pal.accent.copy(alpha = 0.55f)
            else -> Pal.fieldLine
        },
        tween(180), label = "digitBorder",
    )
    Box(
        Modifier
            .size(width = 58.dp, height = 66.dp)
            .scale(pop.value)
            .clip(shape)
            .background(if (error) Pal.danger.copy(alpha = 0.06f) else Pal.fieldFill)
            .border(if (current || error) 2.dp else 1.5.dp, edge, shape),
        contentAlignment = Alignment.Center,
    ) {
        Text(digit.orEmpty(), color = Pal.ink, fontSize = 26.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center)
    }
}

/**
 * A drop-in for Material's `OutlinedTextField` as the old screens call it — the label and placeholder
 * are composable slots there — drawn as the app's own field: the label a bold caption above, the pill below.
 */
@Composable
fun DorrField(
    value: String,
    onValueChange: (String) -> Unit,
    modifier: Modifier = Modifier,
    label: (@Composable () -> Unit)? = null,
    placeholder: (@Composable () -> Unit)? = null,
    singleLine: Boolean = false,
    minLines: Int = 1,
    keyboardOptions: KeyboardOptions = KeyboardOptions.Default,
    visualTransformation: VisualTransformation = VisualTransformation.None,
    enabled: Boolean = true,
    error: Boolean = false,
) {
    Column(modifier) {
        if (label != null) {
            ProvideTextStyle(TextStyle(fontSize = 13.sp, fontWeight = FontWeight.Bold, fontFamily = CairoFamily)) {
                CompositionLocalProvider(LocalContentColor provides Pal.ink) {
                    Box(Modifier.padding(bottom = 8.dp)) { label() }
                }
            }
        }
        DorrTextField(
            value = value,
            onValueChange = onValueChange,
            placeholderContent = placeholder,
            singleLine = singleLine,
            minLines = minLines,
            keyboardOptions = keyboardOptions,
            visualTransformation = visualTransformation,
            enabled = enabled,
            error = error,
        )
    }
}
