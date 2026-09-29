package com.dorr.app.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.MaterialTheme
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Shape
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.ui.theme.AppColors
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material.icons.rounded.VisibilityOff
import androidx.compose.material3.Icon
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.input.PasswordVisualTransformation
import com.dorr.app.ui.screens.AccountDark
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.theme.CairoFontFamily
import com.dorr.app.ui.theme.appearanceColor

/**
 * The app's text field, in the system's own design (the profile page's fields): a rounded pill
 * with a soft fill, a hairline that turns the **app colour** when focused, an icon in the app
 * colour, and colours / font from the **appearance settings** in light and dark mode.
 *
 * Built on [BasicTextField] instead of Material's OutlinedTextField: that one has a hard minimum
 * height of 56dp with its own padding, so text gets cut in tighter rows. Here the field is exactly
 * as tall as its content plus padding.
 *
 * [icon] draws the leading icon; [password] hides the text and adds an eye to show it. [label] is a
 * small caption above the field (never a floating label, which squashes layouts).
 */
@Composable
fun DorrTextField(
    value: String,
    onValueChange: (String) -> Unit,
    modifier: Modifier = Modifier,
    label: String? = null,
    placeholder: String? = null,
    icon: ImageVector? = null,
    password: Boolean = false,
    leadingIcon: (@Composable () -> Unit)? = null,
    trailing: (@Composable () -> Unit)? = null,
    keyboardOptions: KeyboardOptions = KeyboardOptions.Default,
    singleLine: Boolean = true,
    minLines: Int = 1,
    error: Boolean = false,
    enabled: Boolean = true,
    minHeight: Dp = 46.dp,
    shape: Shape? = null,
    containerColor: Color? = null,
    visualTransformation: VisualTransformation = VisualTransformation.None,
    textStyle: TextStyle = MaterialTheme.typography.bodyLarge,
) {
    var focused by remember { mutableStateOf(false) }
    var revealed by remember { mutableStateOf(false) }
    val night = settingsNight()
    val accent = settingsAccent()
    val ink = settingsInk()
    val danger = appearanceColor("danger", AppColors.danger)
    val fieldShape = shape ?: if (singleLine) RoundedCornerShape(999.dp) else RoundedCornerShape(20.dp)
    val fill = containerColor ?: when {
        night -> AccountDark.bg
        focused -> appearanceColor("surface", Color.White, night = false)
        else -> FieldFill
    }
    val borderColor by animateColorAsState(
        when {
            error -> danger
            focused -> accent
            night -> AccountDark.line
            else -> FieldBorder
        },
        tween(200), label = "fieldBorder",
    )
    val iconTint by animateColorAsState(if (error) danger else if (focused) accent else accent.copy(alpha = 0.8f), tween(200), label = "fieldIcon")

    Column(modifier) {
        if (label != null) {
            Text(label, fontSize = 13.sp, fontWeight = FontWeight.Bold, color = ink, modifier = Modifier.padding(bottom = 8.dp))
        }
        BasicTextField(
            value = value,
            onValueChange = onValueChange,
            enabled = enabled,
            singleLine = singleLine,
            minLines = minLines,
            keyboardOptions = keyboardOptions,
            visualTransformation = if (password && !revealed) PasswordVisualTransformation() else visualTransformation,
            textStyle = textStyle.copy(color = ink, fontFamily = CairoFontFamily),
            cursorBrush = SolidColor(accent),
            modifier = Modifier.fillMaxWidth().onFocusChanged { focused = it.isFocused },
            decorationBox = { inner ->
                Row(
                    Modifier
                        .fillMaxWidth()
                        .heightIn(min = minHeight)
                        .clip(fieldShape)
                        .background(fill)
                        .border(if (focused || error) 1.5.dp else 1.dp, borderColor, fieldShape)
                        .padding(horizontal = 14.dp, vertical = if (singleLine) 8.dp else 12.dp),
                    verticalAlignment = if (singleLine) Alignment.CenterVertically else Alignment.Top,
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    if (icon != null) Icon(icon, contentDescription = null, tint = iconTint, modifier = Modifier.size(18.dp))
                    leadingIcon?.invoke()
                    Box(Modifier.weight(1f), contentAlignment = Alignment.CenterStart) {
                        if (value.isEmpty() && placeholder != null) {
                            Text(placeholder, style = textStyle.copy(fontFamily = CairoFontFamily), color = if (night) AccountDark.mut else Color(0xFFB4BAC4))
                        }
                        inner()
                    }
                    if (password) {
                        Box(
                            Modifier.size(30.dp).clip(RoundedCornerShape(999.dp)).clickable { revealed = !revealed },
                            contentAlignment = Alignment.Center,
                        ) {
                            Icon(
                                if (revealed) Icons.Rounded.VisibilityOff else Icons.Rounded.Visibility, contentDescription = null,
                                tint = if (night) AccountDark.mut else AppColors.textMuted, modifier = Modifier.size(18.dp),
                            )
                        }
                    }
                    trailing?.invoke()
                }
            },
        )
    }
}

/** The light-mode field fill / hairline of the system design (the profile page's fields). */
private val FieldFill = Color(0xFFFBF7F8)
private val FieldBorder = Color(0xFFF3D5DB)

/**
 * One box of a verification code: a single centred digit. Fixed 44×52dp like the design, but built on
 * [BasicTextField] so the digit is never squeezed or cut (see [DorrTextField]).
 */
@Composable
fun DorrDigitBox(
    value: String,
    onValueChange: (String) -> Unit,
    modifier: Modifier = Modifier,
    borderColor: Color,
    focusedBorderColor: Color,
    background: Color = Color.Transparent,
    textColor: Color = AppColors.textPrimary,
) {
    var focused by remember { mutableStateOf(false) }
    val shape = RoundedCornerShape(14.dp)
    BasicTextField(
        value = value,
        onValueChange = onValueChange,
        singleLine = true,
        textStyle = MaterialTheme.typography.headlineMedium.copy(
            textAlign = androidx.compose.ui.text.style.TextAlign.Center,
            fontSize = 22.sp,
            fontWeight = FontWeight.SemiBold,
            color = textColor,
        ),
        keyboardOptions = KeyboardOptions(keyboardType = androidx.compose.ui.text.input.KeyboardType.NumberPassword),
        cursorBrush = SolidColor(AppColors.waRed),
        modifier = modifier.onFocusChanged { focused = it.isFocused },
        decorationBox = { inner ->
            Box(
                Modifier
                    .fillMaxWidth()
                    .heightIn(min = 52.dp)
                    .clip(shape)
                    .background(background)
                    .border(if (focused) 2.dp else 1.dp, if (focused) focusedBorderColor else borderColor, shape),
                contentAlignment = Alignment.Center,
            ) { inner() }
        },
    )
}
