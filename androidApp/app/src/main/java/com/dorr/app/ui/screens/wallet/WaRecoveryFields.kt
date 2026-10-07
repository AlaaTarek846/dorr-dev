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
import androidx.compose.foundation.text.KeyboardActions
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
import androidx.compose.ui.focus.FocusDirection
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.ImeAction
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

/**
 * One typed number of the date (day, month or year): a small label over a pill, digits only, centred.
 * Turns red as soon as what is typed cannot be right; [onFull] fires when the box is full so the caret can move on.
 */
@Composable
private fun WaDateBox(
    label: String,
    value: String,
    placeholder: String,
    maxLength: Int,
    invalid: Boolean,
    imeAction: ImeAction,
    modifier: Modifier = Modifier,
    onChange: (String) -> Unit,
) {
    var focused by remember { mutableStateOf(false) }
    val focusManager = LocalFocusManager.current
    val shape = RoundedCornerShape(16.dp)
    Column(modifier) {
        Text(label, color = Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(bottom = 6.dp, start = 2.dp))
        Box(
            Modifier
                .fillMaxWidth()
                .height(52.dp)
                .clip(shape)
                .background(Wa.Field)
                .border(1.5.dp, if (invalid) Wa.Danger else if (focused) Wa.Red else Wa.Line, shape)
                .padding(horizontal = 8.dp),
            contentAlignment = Alignment.Center,
        ) {
            BasicTextField(
                value = value,
                onValueChange = { raw ->
                    val digits = raw.filter(Char::isDigit).take(maxLength)
                    onChange(digits)
                    // A full day or month hands the caret to the next box, like typing a date on paper.
                    if (digits.length == maxLength && value.length < maxLength && imeAction == ImeAction.Next) focusManager.moveFocus(FocusDirection.Next)
                },
                singleLine = true,
                textStyle = TextStyle(fontSize = 19.sp, fontWeight = FontWeight.ExtraBold, color = if (invalid) Wa.Danger else Wa.Ink, textAlign = TextAlign.Center),
                cursorBrush = SolidColor(Wa.Red),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number, imeAction = imeAction),
                keyboardActions = KeyboardActions(onNext = { focusManager.moveFocus(FocusDirection.Next) }, onDone = { focusManager.clearFocus() }),
                modifier = Modifier.fillMaxWidth().onFocusChanged { focused = it.isFocused },
                decorationBox = { inner ->
                    Box(contentAlignment = Alignment.Center) {
                        if (value.isEmpty()) Text(placeholder, color = Wa.Soft, fontSize = 17.sp, fontWeight = FontWeight.Medium)
                        inner()
                    }
                },
            )
        }
    }
}

/** What is wrong with the date typed so far, or null while it can still become a valid one. */
private enum class BirthProblem { Day, Month, Year, Future }

private fun birthProblem(day: String, month: String, year: String): BirthProblem? {
    val m = month.toIntOrNull()
    val y = year.toIntOrNull()
    if (month.isNotEmpty() && (m == null || m > 12 || (month.length == 2 && m == 0))) return BirthProblem.Month
    // A year is judged once all four digits are there (typing "19" on the way to 1990 is fine).
    if (year.length == 4 && y != null && y < 1900) return BirthProblem.Year
    if (year.length == 4 && y != null && y > LocalDate.now().year) return BirthProblem.Future
    val d = day.toIntOrNull()
    if (day.isNotEmpty() && (d == null || d > 31 || (day.length == 2 && d == 0))) return BirthProblem.Day
    // Only a real month (1..12) can say how long the month is; a lone "0" is still on its way to 05, 09...
    if (d != null && m != null && m in 1..12) {
        val max = YearMonth.of(y?.takeIf { year.length == 4 } ?: 2000, m).lengthOfMonth()
        if (d > max) return BirthProblem.Day
    }
    if (birthDateOrNull(day, month, year) == null && day.isNotEmpty() && month.isNotEmpty() && year.length == 4) {
        // Complete, a real calendar date, but not in the past.
        return BirthProblem.Future
    }
    return null
}

/**
 * The date of birth typed as day / month / year (numbers only), with the chosen date shown in full above the
 * boxes and a short message under them as soon as something cannot be right. [day] etc. are plain numbers ("5", "9", "1990").
 */
@Composable
internal fun WaBirthPicker(day: String, month: String, year: String, onChange: (String, String, String) -> Unit, error: Boolean) {
    val locale = LocalConfiguration.current.locales[0] ?: Locale.getDefault()
    val monthNames = remember(locale) { (1..12).map { Month.of(it).getDisplayName(java.time.format.TextStyle.FULL, locale) } }
    val problem = birthProblem(day, month, year)
    val complete = birthDateOrNull(day, month, year) != null
    val preview = if (complete) "${day.toInt()} ${monthNames[month.toInt() - 1]} $year" else stringResource(R.string.wa_rec_birth_pick)

    WaCard(Modifier.fillMaxWidth(), padding = 16.dp) {
        Column {
            Text(stringResource(R.string.wa_rec_birth_label), color = Wa.Mut, fontSize = 12.sp)
            Text(
                preview,
                color = if (complete) Wa.Ink else Wa.Soft,
                fontSize = 17.sp,
                fontWeight = FontWeight.ExtraBold,
                modifier = Modifier.padding(top = 2.dp),
            )
        }
        Spacer(Modifier.height(16.dp))
        // Dates are typed left to right in every language: day, month, year.
        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                WaDateBox(
                    stringResource(R.string.wa_rec_day), day, "DD", 2,
                    problem == BirthProblem.Day, ImeAction.Next, Modifier.weight(1f),
                ) { onChange(it, month, year) }
                WaDateBox(
                    stringResource(R.string.wa_rec_month), month, "MM", 2,
                    problem == BirthProblem.Month, ImeAction.Next, Modifier.weight(1f),
                ) { onChange(day, it, year) }
                WaDateBox(
                    stringResource(R.string.wa_rec_year), year, "YYYY", 4,
                    problem == BirthProblem.Year || problem == BirthProblem.Future, ImeAction.Done, Modifier.weight(1.4f),
                ) { onChange(day, month, it) }
            }
        }
        val message = when (problem) {
            BirthProblem.Day -> R.string.wa_rec_birth_bad_day
            BirthProblem.Month -> R.string.wa_rec_birth_bad_month
            BirthProblem.Year -> R.string.wa_rec_birth_bad_year
            BirthProblem.Future -> R.string.wa_rec_birth_future
            null -> if (error) R.string.wa_rec_birth_invalid else null
        }
        if (message != null) {
            Text(stringResource(message), color = Wa.Danger, fontSize = 12.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 10.dp, start = 2.dp))
        }
    }
}
