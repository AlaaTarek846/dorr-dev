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

/**
 * The app's text field — the look of the design (soft pink hairline, red when focused, rounded),
 * built on [BasicTextField] instead of Material's OutlinedTextField.
 *
 * Why not OutlinedTextField: it has a hard minimum height of 56dp with its own internal padding, so
 * as soon as a screen gives it less room (a 48dp row, a narrow digit box) the lower half of the typed
 * text is cut off. Here the field is exactly as tall as its content plus padding, so text is never
 * clipped, whatever the language or font size.
 *
 * [label] is drawn as a small caption above the field (the design's `profile-field-label`) rather than
 * Material's floating label, which is another source of squashed layouts.
 */
@Composable
fun DorrTextField(
    value: String,
    onValueChange: (String) -> Unit,
    modifier: Modifier = Modifier,
    label: String? = null,
    placeholder: String? = null,
    leadingIcon: (@Composable () -> Unit)? = null,
    keyboardOptions: KeyboardOptions = KeyboardOptions.Default,
    singleLine: Boolean = true,
    minLines: Int = 1,
    error: Boolean = false,
    enabled: Boolean = true,
    minHeight: Dp = 48.dp,
    shape: Shape = RoundedCornerShape(12.dp),
    containerColor: Color = Color.White,
    visualTransformation: VisualTransformation = VisualTransformation.None,
    textStyle: TextStyle = MaterialTheme.typography.bodyLarge,
) {
    var focused by remember { mutableStateOf(false) }
    val borderColor = when {
        error -> AppColors.danger
        focused -> AppColors.waRed
        else -> Color(0xFFF3D5DB)
    }

    Column(modifier) {
        if (label != null) {
            Text(
                label,
                fontSize = 13.sp,
                fontWeight = FontWeight.Bold,
                color = AppColors.textPrimary,
                modifier = Modifier.padding(bottom = 8.dp),
            )
        }
        BasicTextField(
            value = value,
            onValueChange = onValueChange,
            enabled = enabled,
            singleLine = singleLine,
            minLines = minLines,
            keyboardOptions = keyboardOptions,
            visualTransformation = visualTransformation,
            textStyle = textStyle.copy(color = AppColors.textPrimary),
            cursorBrush = SolidColor(AppColors.waRed),
            modifier = Modifier
                .fillMaxWidth()
                .onFocusChanged { focused = it.isFocused },
            decorationBox = { inner ->
                Row(
                    Modifier
                        .fillMaxWidth()
                        .heightIn(min = minHeight)
                        .clip(shape)
                        .background(containerColor)
                        .border(if (focused || error) 1.5.dp else 1.dp, borderColor, shape)
                        .padding(horizontal = 14.dp, vertical = 10.dp),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    leadingIcon?.invoke()
                    Box(Modifier.weight(1f), contentAlignment = Alignment.CenterStart) {
                        if (value.isEmpty() && placeholder != null) {
                            Text(placeholder, style = textStyle, color = AppColors.textMuted)
                        }
                        inner()
                    }
                }
            },
        )
    }
}

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
