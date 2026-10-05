package com.dorr.app.ui.screens.wallet

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.KeyboardArrowDown
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
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
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import java.time.LocalDate
import java.time.Month
import java.time.YearMonth
import java.util.Locale

/**
 * Inputs of the wallet's recovery screens, drawn like the app's personal-data screens: a pill field with
 * a brand icon, four rounded boxes for a code, and a date of birth chosen from drop-downs.
 */

/** A pill-shaped text field with a brand-coloured icon in front (the e-mail field of the personal data screen). */
@Composable
internal fun WaPillField(
    value: String,
    onChange: (String) -> Unit,
    placeholder: String,
    icon: ImageVector,
    modifier: Modifier = Modifier,
    keyboardOptions: KeyboardOptions = KeyboardOptions.Default,
    error: Boolean = false,
) {
    var focused by remember { mutableStateOf(false) }
    // E-mail addresses and numbers read left-to-right in every language.
    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
        val shape = RoundedCornerShape(999.dp)
        Row(
            modifier
                .fillMaxWidth()
                .height(50.dp)
                .clip(shape)
                .background(Wa.Field)
                .border(1.dp, if (error) Wa.Danger else if (focused) Wa.Red else Wa.Line, shape)
                .padding(horizontal = 16.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(icon, null, tint = Wa.Red, modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(10.dp))
            BasicTextField(
                value = value,
                onValueChange = onChange,
                singleLine = true,
                textStyle = TextStyle(fontSize = 15.sp, color = Wa.Ink),
                cursorBrush = SolidColor(Wa.Red),
                keyboardOptions = keyboardOptions,
                modifier = Modifier.weight(1f).onFocusChanged { focused = it.isFocused },
                decorationBox = { inner ->
                    Box(contentAlignment = Alignment.CenterStart) {
                        if (value.isEmpty()) Text(placeholder, color = Wa.Soft, fontSize = 15.sp)
                        inner()
                    }
                },
            )
        }
    }
}

/**
 * [length] rounded boxes holding a one-time code. One invisible text field takes the typing (so the
 * keyboard, paste and backspace just work); the boxes only draw what it holds.
 */
@Composable
internal fun WaOtpBoxes(value: String, onChange: (String) -> Unit, error: Boolean, length: Int = 4) {
    val focus = remember { FocusRequester() }
    var focused by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { runCatching { focus.requestFocus() } }

    // The digits read left-to-right in every language.
    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
        Box(
            Modifier
                .fillMaxWidth()
                .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null) { runCatching { focus.requestFocus() } },
            contentAlignment = Alignment.Center,
        ) {
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                repeat(length) { index ->
                    val digit = value.getOrNull(index)
                    val current = focused && index == value.length.coerceAtMost(length - 1)
                    val shape = RoundedCornerShape(14.dp)
                    Box(
                        Modifier
                            .width(48.dp)
                            .height(56.dp)
                            .clip(shape)
                            .background(Wa.Surface)
                            .border(
                                1.5.dp,
                                when {
                                    error -> Wa.Danger
                                    current || digit != null -> Wa.Red
                                    else -> Wa.Red.copy(alpha = 0.3f)
                                },
                                shape,
                            ),
                        contentAlignment = Alignment.Center,
                    ) {
                        if (digit != null) Text(digit.toString(), fontSize = 22.sp, fontWeight = FontWeight.ExtraBold, color = Wa.Ink)
                    }
                }
            }
            BasicTextField(
                value = value,
                onValueChange = { onChange(it.filter(Char::isDigit).take(length)) },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
                cursorBrush = SolidColor(androidx.compose.ui.graphics.Color.Transparent),
                textStyle = TextStyle(color = androidx.compose.ui.graphics.Color.Transparent),
                modifier = Modifier
                    .matchParentSize()
                    .alpha(0f)
                    .focusRequester(focus)
                    .onFocusChanged { focused = it.isFocused },
            )
        }
    }
}

/** One drop-down in the date picker: a small label, a pill showing the choice, and the list under it. */
@Composable
private fun WaDropdownPill(
    label: String,
    value: String,
    placeholder: String,
    options: List<String>,
    display: (String) -> String,
    error: Boolean,
    modifier: Modifier = Modifier,
    onSelect: (String) -> Unit,
) {
    var open by remember { mutableStateOf(false) }
    val shape = RoundedCornerShape(16.dp)
    Column(modifier) {
        Text(label, color = Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(bottom = 6.dp, start = 2.dp))
        Box {
            Row(
                Modifier
                    .fillMaxWidth()
                    .height(50.dp)
                    .clip(shape)
                    .background(Wa.Field)
                    .border(1.dp, if (error) Wa.Danger else if (open) Wa.Red else Wa.Line, shape)
                    .clickable { open = true }
                    .padding(horizontal = 12.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    if (value.isEmpty()) placeholder else display(value),
                    color = if (value.isEmpty()) Wa.Soft else Wa.Ink,
                    fontSize = 14.5.sp,
                    fontWeight = if (value.isEmpty()) FontWeight.Normal else FontWeight.Bold,
                    maxLines = 1,
                    modifier = Modifier.weight(1f),
                )
                Icon(Icons.Rounded.KeyboardArrowDown, null, tint = Wa.Red, modifier = Modifier.size(20.dp))
            }
            DropdownMenu(
                expanded = open,
                onDismissRequest = { open = false },
                modifier = Modifier.heightIn(max = 280.dp),
                containerColor = Wa.Surface,
            ) {
                options.forEach { option ->
                    DropdownMenuItem(
                        text = {
                            Text(
                                display(option),
                                color = if (option == value) Wa.Red else Wa.Ink,
                                fontWeight = if (option == value) FontWeight.ExtraBold else FontWeight.Medium,
                            )
                        },
                        onClick = {
                            onSelect(option)
                            open = false
                        },
                    )
                }
            }
        }
    }
}

/** Day / month / year drop-downs with the chosen date shown in full above them. [day] etc. are plain numbers ("5", "9", "1990"). */
@Composable
internal fun WaBirthPicker(day: String, month: String, year: String, onChange: (String, String, String) -> Unit, error: Boolean) {
    val locale = LocalConfiguration.current.locales[0] ?: Locale.getDefault()
    val monthNames = remember(locale) { (1..12).map { Month.of(it).getDisplayName(java.time.format.TextStyle.FULL, locale) } }
    val years = remember { ((LocalDate.now().year - 1) downTo 1920).map { it.toString() } }
    val monthOptions = remember { (1..12).map { it.toString() } }

    val monthNumber = month.toIntOrNull()
    val yearNumber = year.toIntOrNull()
    // How many days the chosen month has (29 for February until a year says otherwise).
    val daysInMonth = when {
        monthNumber == null -> 31
        yearNumber != null -> YearMonth.of(yearNumber, monthNumber).lengthOfMonth()
        else -> YearMonth.of(2000, monthNumber).lengthOfMonth()
    }
    val days = remember(daysInMonth) { (1..daysInMonth).map { it.toString() } }

    fun changed(d: String, m: String, y: String) {
        val max = m.toIntOrNull()?.let { mm -> y.toIntOrNull()?.let { YearMonth.of(it, mm).lengthOfMonth() } ?: YearMonth.of(2000, mm).lengthOfMonth() } ?: 31
        // A day that the new month does not have (31 → February) falls back to its last day.
        onChange(if ((d.toIntOrNull() ?: 0) > max) max.toString() else d, m, y)
    }

    val complete = day.isNotEmpty() && monthNumber != null && year.isNotEmpty()
    val preview = if (complete) "$day ${monthNames[monthNumber!! - 1]} $year" else stringResource(R.string.wa_rec_birth_pick)

    WaCard(Modifier.fillMaxWidth(), padding = 16.dp) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.wa_rec_birth_label), color = Wa.Mut, fontSize = 12.sp)
                Text(
                    preview,
                    color = if (complete) Wa.Ink else Wa.Soft,
                    fontSize = 17.sp,
                    fontWeight = FontWeight.ExtraBold,
                    modifier = Modifier.padding(top = 2.dp),
                )
            }
        }
        Spacer(Modifier.height(16.dp))
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            WaDropdownPill(
                stringResource(R.string.wa_rec_day), day, "—", days, { it }, error, Modifier.weight(0.8f),
            ) { changed(it, month, year) }
            WaDropdownPill(
                stringResource(R.string.wa_rec_month), month, "—", monthOptions, { monthNames[it.toInt() - 1] }, error, Modifier.weight(1.5f),
            ) { changed(day, it, year) }
            WaDropdownPill(
                stringResource(R.string.wa_rec_year), year, "—", years, { it }, error, Modifier.weight(1f),
            ) { changed(day, month, it) }
        }
    }
}
