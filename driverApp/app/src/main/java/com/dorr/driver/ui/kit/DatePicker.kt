package com.dorr.driver.ui.kit

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.lazy.grid.rememberLazyGridState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowLeft
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.ArrowDropDown
import androidx.compose.material.icons.rounded.CalendarMonth
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import com.dorr.driver.R
import com.dorr.driver.ui.theme.Pal
import java.time.DayOfWeek
import java.time.LocalDate
import java.time.YearMonth
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import java.time.format.TextStyle
import java.time.temporal.WeekFields
import java.util.Locale

/** "15 Oct 2026" in the app's language; "" for nothing, the text itself if it isn't a date. */
fun prettyDate(iso: String?): String {
    if (iso.isNullOrBlank()) return ""
    return runCatching { LocalDate.parse(iso.take(10)).format(DateTimeFormatter.ofLocalizedDate(FormatStyle.MEDIUM).withLocale(Locale.getDefault())) }.getOrDefault(iso)
}

/**
 * The DORR app's calendar (month grid, today ringed, the chosen day filled) in the app's language — plus a year list
 * behind the title, so a date of birth isn't thirty years of "next month" away. Days outside [min]..[max] can't be
 * picked. Answers `yyyy-MM-dd`, what the server takes.
 */
@Composable
fun DorrDatePickerDialog(initial: LocalDate?, min: LocalDate? = null, max: LocalDate? = null, onDismiss: () -> Unit, onPick: (LocalDate) -> Unit) {
    val today = LocalDate.now()
    val start = (initial ?: max?.takeIf { it.isBefore(today) } ?: today).let { d ->
        when {
            min != null && d.isBefore(min) -> min
            max != null && d.isAfter(max) -> max
            else -> d
        }
    }
    var month by remember { mutableStateOf(YearMonth.from(start)) }
    var chosen by remember { mutableStateOf(initial) }
    var years by remember { mutableStateOf(false) }
    val locale = Locale.getDefault()
    val firstDay = WeekFields.of(locale).firstDayOfWeek
    fun allowed(d: LocalDate) = (min == null || !d.isBefore(min)) && (max == null || !d.isAfter(max))

    Dialog(onDismissRequest = onDismiss, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        Column(Modifier.padding(horizontal = 24.dp).fillMaxWidth().surface(RoundedCornerShape(28.dp), 12.dp).padding(16.dp)) {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Arrow(Icons.AutoMirrored.Rounded.KeyboardArrowLeft, enabled = !years && (min == null || month.isAfter(YearMonth.from(min)))) { month = month.minusMonths(1) }
                Row(
                    Modifier.weight(1f).clip(RoundedCornerShape(12.dp)).clickable { years = !years }.padding(vertical = 6.dp),
                    horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(
                        month.atDay(1).format(DateTimeFormatter.ofPattern("LLLL yyyy", locale)),
                        color = Pal.ink, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center,
                    )
                    Icon(Icons.Rounded.ArrowDropDown, null, tint = Pal.accent, modifier = Modifier.size(22.dp))
                }
                Arrow(Icons.AutoMirrored.Rounded.KeyboardArrowRight, enabled = !years && (max == null || month.isBefore(YearMonth.from(max)))) { month = month.plusMonths(1) }
            }
            Spacer(Modifier.height(8.dp))

            AnimatedContent(targetState = years, transitionSpec = { fadeIn(tween(180)) togetherWith fadeOut(tween(120)) }, label = "date-mode") { yearMode ->
                if (yearMode) {
                    val first = (min?.year ?: (today.year - 100))
                    val last = (max?.year ?: (today.year + 20))
                    val list = (last downTo first).toList()
                    val grid = rememberLazyGridState()
                    LaunchedEffect(Unit) { grid.scrollToItem((list.indexOf(month.year) - 6).coerceAtLeast(0)) }
                    LazyVerticalGrid(GridCells.Fixed(3), state = grid, modifier = Modifier.fillMaxWidth().heightIn(max = 300.dp)) {
                        items(list) { y ->
                            val on = y == month.year
                            Text(
                                y.toString(),
                                color = if (on) Color.White else Pal.ink, fontWeight = if (on) FontWeight.ExtraBold else FontWeight.Medium, fontSize = 15.sp,
                                textAlign = TextAlign.Center,
                                modifier = Modifier.padding(4.dp).clip(RoundedCornerShape(12.dp)).background(if (on) Pal.accent else Color.Transparent)
                                    .clickable { month = month.withYear(y); years = false }.padding(vertical = 10.dp),
                            )
                        }
                    }
                } else {
                    Column {
                        Row(Modifier.fillMaxWidth()) {
                            List(7) { firstDay.plus(it.toLong()) }.forEach { d: DayOfWeek ->
                                Text(d.getDisplayName(TextStyle.SHORT, locale), color = Pal.mut, fontSize = 11.5.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center, maxLines = 1, modifier = Modifier.weight(1f))
                            }
                        }
                        Spacer(Modifier.height(4.dp))
                        val offset = (month.atDay(1).dayOfWeek.value - firstDay.value + 7) % 7
                        val rows = (offset + month.lengthOfMonth() + 6) / 7
                        repeat(rows) { row ->
                            Row(Modifier.fillMaxWidth()) {
                                repeat(7) { col ->
                                    val n = row * 7 + col - offset + 1
                                    val day = if (n in 1..month.lengthOfMonth()) month.atDay(n) else null
                                    Box(Modifier.weight(1f).aspectRatio(1f).padding(2.dp), contentAlignment = Alignment.Center) {
                                        if (day != null) {
                                            val on = day == chosen
                                            val ok = allowed(day)
                                            Box(
                                                Modifier.fillMaxWidth().aspectRatio(1f).clip(CircleShape)
                                                    .background(if (on) Pal.accent else Color.Transparent)
                                                    .then(if (day == today && !on) Modifier.border(1.5.dp, Pal.accent, CircleShape) else Modifier)
                                                    .clickable(enabled = ok) { chosen = day },
                                                contentAlignment = Alignment.Center,
                                            ) {
                                                Text(
                                                    day.dayOfMonth.toString(),
                                                    color = when { on -> Color.White; ok -> Pal.ink; else -> Pal.faint.copy(alpha = 0.5f) },
                                                    fontSize = 14.sp, fontWeight = if (on) FontWeight.ExtraBold else FontWeight.Medium,
                                                )
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            Row(Modifier.fillMaxWidth().padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                OutlineButton(stringResource(R.string.cancel), Modifier.weight(1f), color = Pal.mut, onClick = onDismiss)
                Box(Modifier.weight(1f)) {
                    PrimaryButton(stringResource(R.string.date_ok), enabled = chosen != null, onClick = { chosen?.let(onPick) })
                }
            }
        }
    }
}

@Composable
private fun Arrow(icon: ImageVector, enabled: Boolean, onClick: () -> Unit) {
    Box(
        Modifier.size(36.dp).clip(CircleShape).background(if (enabled) Pal.well else Color.Transparent).clickable(enabled = enabled, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Icon(icon, null, tint = if (enabled) Pal.accent else Pal.faint.copy(alpha = 0.4f), modifier = Modifier.size(22.dp)) }
}

/** A pill field that opens the calendar — looks like every other field, never opens the keyboard. */
@Composable
fun DateField(
    label: String?,
    value: String?,
    onPick: (String) -> Unit,
    modifier: Modifier = Modifier,
    placeholder: String? = null,
    min: LocalDate? = null,
    max: LocalDate? = null,
    error: Boolean = false,
    enabled: Boolean = true,
) {
    var open by remember { mutableStateOf(false) }
    DorrTextField(
        value = prettyDate(value), onValueChange = {}, label = label, placeholder = placeholder ?: stringResource(R.string.date_pick),
        icon = Icons.Rounded.CalendarMonth, onClick = { open = true }, error = error, enabled = enabled, modifier = modifier,
    )
    if (open) {
        DorrDatePickerDialog(
            initial = value?.let { runCatching { LocalDate.parse(it.take(10)) }.getOrNull() },
            min = min, max = max,
            onDismiss = { open = false },
            onPick = { onPick(it.toString()); open = false },
        )
    }
}
