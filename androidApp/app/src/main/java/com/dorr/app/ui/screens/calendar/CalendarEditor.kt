package com.dorr.app.ui.screens.calendar

import android.widget.Toast
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowLeft
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.Alarm
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.KeyboardArrowDown
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.Place
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Warning
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TimePicker
import androidx.compose.material3.TimePickerColors
import androidx.compose.material3.TimePickerDefaults
import androidx.compose.material3.TimePickerSelectionMode
import androidx.compose.material3.TimePickerState
import androidx.compose.material3.rememberTimePickerState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.Layout
import androidx.compose.ui.layout.onSizeChanged
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Constraints
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.CalItemDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.locale.LocaleAwareDialog
import com.dorr.app.ui.locale.rememberAppLocaleFrame
import com.dorr.app.ui.screens.AccountDark
import com.dorr.app.ui.screens.ConfirmDialog
import com.dorr.app.ui.screens.moments.momentColor
import com.dorr.app.ui.screens.profile.PinkBackdrop
import com.dorr.app.ui.screens.profile.SubHeader
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.google.gson.JsonArray
import com.google.gson.JsonNull
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.time.LocalDate
import java.time.LocalTime
import java.time.YearMonth
import java.time.ZoneId
import java.time.ZonedDateTime
import java.time.temporal.WeekFields

private const val NOTE_MAX = 1000

/**
 * A new appointment, or a change to one — a whole screen (not a sheet): a live summary of what it will look like, then
 * the details, when, the reminders and the colour, with the save button pinned at the bottom. Saved in this phone's
 * time zone — shown right wherever I go. Going back with something typed asks first.
 */
@Composable
internal fun EventEditorScreen(existing: CalItemDto?, day: LocalDate, onBack: () -> Unit) {
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val zone = ZoneId.systemDefault()
    val start0 = localTime(existing?.startsAt)
    val initialDate = start0?.toLocalDate() ?: runCatching { LocalDate.parse(existing?.date) }.getOrNull() ?: day
    val initialStart = start0?.toLocalTime() ?: LocalTime.now().plusHours(1).withMinute(0).withSecond(0).withNano(0)
    val initialEnd = localTime(existing?.endsAt)?.toLocalTime()

    var title by remember { mutableStateOf(existing?.title.orEmpty()) }
    var allDay by remember { mutableStateOf(existing?.allDay ?: false) }
    var date by remember { mutableStateOf(initialDate) }
    var start by remember { mutableStateOf(initialStart) }
    var end by remember { mutableStateOf(initialEnd) }
    var location by remember { mutableStateOf(existing?.location.orEmpty()) }
    var note by remember { mutableStateOf(existing?.note.orEmpty()) }
    var color by remember { mutableStateOf(existing?.color) }
    val reminders = remember { mutableStateListOf<Int>().apply { existing?.reminders?.let { addAll(it) } } }
    var remindersTouched by remember { mutableStateOf(existing != null) }
    var picking by remember { mutableStateOf<String?>(null) } // date · start · end
    var busy by remember { mutableStateOf(false) }
    var tried by remember { mutableStateOf(false) }
    var confirmLeave by remember { mutableStateOf(false) }
    val added = stringResource(R.string.cal_saved)
    val updatedText = stringResource(R.string.cal_updated)
    val dupe = stringResource(R.string.cal_duplicate)

    val dirty = if (existing == null) {
        title.isNotBlank() || location.isNotBlank() || note.isNotBlank() || remindersTouched || allDay
    } else {
        title != existing.title || allDay != existing.allDay || date != initialDate || (!allDay && (start != initialStart || end != initialEnd)) ||
            location != existing.location.orEmpty() || note != existing.note.orEmpty() || color != existing.color ||
            reminders.sorted() != existing.reminders.orEmpty().sorted()
    }
    fun leave() { if (dirty && !busy) confirmLeave = true else onBack() }
    BackHandler { leave() }

    val accent = color?.let { momentColor(it, Wa.Red) } ?: Wa.Red
    val titleMissing = tried && title.isBlank()
    val endsNextDay = !allDay && end?.isBefore(start) == true

    fun save() {
        tried = true
        if (title.isBlank() || busy) return
        busy = true
        val body = JsonObject().apply {
            addProperty("title", title.trim())
            addProperty("all_day", allDay)
            addProperty("timezone", zone.id)
            if (allDay) addProperty("date", date.toString())
            else {
                addProperty("starts_at", ZonedDateTime.of(date, start, zone).toOffsetDateTime().toString())
                end?.let { e -> addProperty("ends_at", ZonedDateTime.of(if (e.isBefore(start)) date.plusDays(1) else date, e, zone).toOffsetDateTime().toString()) }
            }
            addProperty("location", location.trim())
            addProperty("note", note.trim())
            color?.let { addProperty("color", it) } ?: add("color", JsonNull.INSTANCE)
            if (remindersTouched) add("reminders", JsonArray().apply { reminders.sorted().forEach { add(it) } })
        }
        scope.launch {
            runCatching { if (existing == null) ApiClient.calendar.create(calAuth(), body).data else ApiClient.calendar.update(calAuth(), existing.ref, body).data }
                .onSuccess { saved ->
                    Toast.makeText(context, if (saved?.duplicate == true) dupe else if (existing == null) added else updatedText, Toast.LENGTH_SHORT).show()
                    CalendarStore.changed()
                    onBack()
                }
                .onFailure { failure ->
                    Toast.makeText(context, failure.apiFailure().message ?: context.getString(R.string.cal_save_failed), Toast.LENGTH_LONG).show()
                    busy = false
                }
        }
    }

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize()) {
            SubHeader(stringResource(if (existing == null) R.string.cal_add else R.string.cal_edit), ::leave)
            Column(
                Modifier
                    .weight(1f)
                    .fillMaxWidth()
                    .verticalScroll(rememberScrollState())
                    .padding(horizontal = 14.dp)
                    .padding(top = 6.dp, bottom = 12.dp),
            ) {
                Column(
                    Modifier
                        .fillMaxWidth()
                        .clip(RoundedCornerShape(14.dp))
                        .background(Brush.linearGradient(listOf(accent, accent.copy(alpha = 0.78f))))
                        .padding(horizontal = 14.dp, vertical = 12.dp),
                ) {
                    Text(
                        title.ifBlank { stringResource(R.string.cal_summary_untitled) },
                        color = Color.White,
                        fontSize = 16.sp,
                        fontWeight = FontWeight.ExtraBold,
                        maxLines = 2,
                        overflow = TextOverflow.Ellipsis,
                    )
                    Spacer(Modifier.height(4.dp))
                    SummaryLine(
                        Icons.Rounded.Schedule,
                        listOf(
                            dayTitle(date),
                            if (allDay) stringResource(R.string.cal_all_day)
                            else timeFormatter().format(start) + (end?.let { " – " + timeFormatter().format(it) } ?: ""),
                        ).joinToString("  ·  "),
                    )
                    if (location.isNotBlank()) SummaryLine(Icons.Rounded.Place, location.trim())
                }

                EditorLabel(stringResource(R.string.cal_sec_details))
                EditorCard {
                    DorrTextField(
                        title, { title = it.take(160) }, modifier = Modifier.fillMaxWidth(),
                        label = stringResource(R.string.cal_lbl_title), placeholder = stringResource(R.string.cal_title_hint), icon = Icons.Rounded.Edit, error = titleMissing,
                    )
                    if (titleMissing) Text(stringResource(R.string.cal_err_title), color = Wa.Danger, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 4.dp))
                    Spacer(Modifier.height(8.dp))
                    DorrTextField(
                        location, { location = it.take(200) }, modifier = Modifier.fillMaxWidth(),
                        label = stringResource(R.string.cal_lbl_location), placeholder = stringResource(R.string.cal_location_hint), icon = Icons.Rounded.Place,
                    )
                    Spacer(Modifier.height(8.dp))
                    DorrTextField(
                        note, { note = it.take(NOTE_MAX) }, modifier = Modifier.fillMaxWidth(),
                        label = stringResource(R.string.cal_lbl_note), placeholder = stringResource(R.string.cal_note_hint), icon = Icons.Rounded.Notes,
                        singleLine = false, minLines = 2,
                    )
                    Text(
                        stringResource(R.string.cal_chars, note.length, NOTE_MAX), color = settingsMut(), fontSize = 11.sp, textAlign = TextAlign.End,
                        modifier = Modifier.fillMaxWidth().padding(top = 4.dp),
                    )
                }

                EditorLabel(stringResource(R.string.cal_sec_when))
                EditorCard {
                    ToggleRow(stringResource(R.string.cal_all_day), allDay) { allDay = it; if (!remindersTouched) reminders.clear() }
                    Spacer(Modifier.height(8.dp))
                    DorrTextField(
                        date.format(dateFormatter()), {}, modifier = Modifier.fillMaxWidth(),
                        label = stringResource(R.string.cal_date), icon = Icons.Rounded.CalendarMonth,
                        onClick = { picking = "date" },
                    )
                    if (!allDay) {
                        Spacer(Modifier.height(8.dp))
                        DorrTextField(
                            timeFormatter().format(start), {}, modifier = Modifier.fillMaxWidth(),
                            label = stringResource(R.string.cal_starts), icon = Icons.Rounded.Schedule,
                            onClick = { picking = "start" },
                        )
                        Spacer(Modifier.height(8.dp))
                        DorrTextField(
                            end?.let { timeFormatter().format(it) }.orEmpty(), {}, modifier = Modifier.fillMaxWidth(),
                            label = stringResource(R.string.cal_ends), placeholder = stringResource(R.string.cal_no_end),
                            icon = Icons.Rounded.Schedule, onClick = { picking = "end" },
                            trailing = if (end != null) {
                                {
                                    Box(
                                        Modifier.size(30.dp).clip(CircleShape).clickable { end = null },
                                        contentAlignment = Alignment.Center,
                                    ) {
                                        Icon(Icons.Rounded.Close, null, tint = settingsMut(), modifier = Modifier.size(18.dp))
                                    }
                                }
                            } else null,
                        )
                        if (endsNextDay) {
                            Text(stringResource(R.string.cal_end_next_day), color = settingsMut(), fontSize = 11.sp, modifier = Modifier.padding(top = 6.dp))
                        }
                    }
                }

                EditorLabel(stringResource(R.string.cal_reminders))
                EditorCard {
                    var open by remember { mutableStateOf(false) }
                    val choices = if (allDay) AllDayChoices else ReminderChoices
                    val reminderNames = choices.associateWith { reminderLabel(it, allDay) }
                    val shown = reminders.sorted().filter { it in choices }
                    val value = when {
                        shown.isNotEmpty() -> shown.map { reminderNames.getValue(it) }.joinToString(listSeparator())
                        remindersTouched -> stringResource(R.string.cal_rem_none)
                        else -> stringResource(R.string.cal_rem_default_value)
                    }
                    SelectRow(Icons.Rounded.Alarm, value, open, { open = it }) {
                        choices.forEach { m ->
                            val on = m in reminders
                            DropdownMenuItem(
                                text = { Text(reminderNames.getValue(m), color = if (on) settingsAccent() else settingsInk(), fontWeight = if (on) FontWeight.Bold else FontWeight.Medium) },
                                trailingIcon = { if (on) Icon(Icons.Rounded.Check, null, tint = settingsAccent(), modifier = Modifier.size(16.dp)) },
                                enabled = on || reminders.size < 5,
                                onClick = {
                                    remindersTouched = true
                                    if (on) reminders.remove(m) else reminders.add(m)
                                },
                            )
                        }
                    }
                    if (!remindersTouched) {
                        Text(stringResource(R.string.cal_reminders_default), color = settingsMut(), fontSize = 11.sp, modifier = Modifier.padding(top = 6.dp))
                    }
                    Spacer(Modifier.height(10.dp))
                    Text(stringResource(R.string.cal_color), fontSize = 13.sp, fontWeight = FontWeight.Bold, color = settingsInk(), modifier = Modifier.padding(bottom = 8.dp))
                    Row(Modifier.fillMaxWidth().padding(bottom = 2.dp), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                        EventColors.forEach { c ->
                            val swatch = c?.let { momentColor(it, Wa.Red) } ?: Wa.Red
                            val selected = color == c
                            Box(
                                Modifier.size(28.dp).clip(CircleShape).background(swatch)
                                    .border(2.dp, if (selected) settingsInk().copy(alpha = 0.45f) else Color.Transparent, CircleShape)
                                    .clickable { color = c },
                                contentAlignment = Alignment.Center,
                            ) {
                                if (selected) Icon(Icons.Rounded.Check, null, tint = Color.White, modifier = Modifier.size(14.dp))
                            }
                        }
                    }
                }
            }
            Box(Modifier.fillMaxWidth().padding(horizontal = 14.dp).padding(bottom = 16.dp)) {
                WaButton(stringResource(R.string.cal_save), { save() }, enabled = !busy, loading = busy, icon = Icons.Rounded.CheckCircle)
            }
        }
    }

    when (picking) {
        "date" -> CalDatePickerDialog(date, onDismiss = { picking = null }) { date = it; picking = null }
        "start" -> CalTimePickerDialog(start, onDismiss = { picking = null }) { start = it; picking = null }
        "end" -> CalTimePickerDialog(end ?: start.plusHours(1), onDismiss = { picking = null }) { end = it; picking = null }
    }

    if (confirmLeave) {
        ConfirmDialog(
            icon = Icons.Rounded.Warning,
            title = stringResource(R.string.cal_discard_title),
            message = stringResource(R.string.cal_discard_text),
            confirmLabel = stringResource(R.string.cal_discard_yes),
            onConfirm = { confirmLeave = false; onBack() },
            onDismiss = { confirmLeave = false },
        )
    }
}

@Composable
private fun EditorLabel(text: String) {
    Text(
        text,
        color = settingsInk(),
        fontSize = 13.5.sp,
        fontWeight = FontWeight.ExtraBold,
        modifier = Modifier.padding(top = 14.dp, bottom = 8.dp, start = 2.dp),
    )
}

@Composable
private fun EditorCard(content: @Composable ColumnScope.() -> Unit) {
    val dark = settingsNight()
    val shape = RoundedCornerShape(14.dp)
    Column(
        Modifier
            .fillMaxWidth()
            .then(if (dark) Modifier else Modifier.shadow(6.dp, shape, spotColor = settingsAccent().copy(alpha = 0.07f)))
            .clip(shape)
            .background(settingsCard())
            .then(if (dark) Modifier.border(1.dp, AccountDark.line, shape) else Modifier)
            .padding(horizontal = 12.dp, vertical = 10.dp),
        content = content,
    )
}

@Composable
private fun SummaryLine(icon: ImageVector, text: String) {
    Row(Modifier.padding(top = 2.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(icon, null, tint = Color.White.copy(alpha = 0.9f), modifier = Modifier.size(14.dp))
        Spacer(Modifier.width(6.dp))
        Text(text, color = Color.White.copy(alpha = 0.92f), fontSize = 12.sp, fontWeight = FontWeight.SemiBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
    }
}

/** A select: the same pill as the other fields, with a down arrow; tapping opens the options under it. */
@Composable
internal fun SelectRow(icon: ImageVector, value: String, open: Boolean, onOpen: (Boolean) -> Unit, menu: @Composable ColumnScope.() -> Unit) {
    val dark = settingsNight()
    val density = androidx.compose.ui.platform.LocalDensity.current
    val locale = rememberAppLocaleFrame()
    var fieldWidth by remember { mutableStateOf(0.dp) }
    Box(Modifier.fillMaxWidth().onSizeChanged { fieldWidth = with(density) { it.width.toDp() } }) {
        DorrTextField(
            value, {}, modifier = Modifier.fillMaxWidth(),
            icon = icon, onClick = { onOpen(true) },
            trailing = {
                Icon(Icons.Rounded.KeyboardArrowDown, null, tint = settingsMut(), modifier = Modifier.size(22.dp))
            },
        )
        DropdownMenu(
            expanded = open,
            onDismissRequest = { onOpen(false) },
            containerColor = if (dark) AccountDark.card else Color.White,
            shape = RoundedCornerShape(16.dp),
            shadowElevation = 10.dp,
            modifier = Modifier.then(if (fieldWidth > 0.dp) Modifier.width(fieldWidth) else Modifier).heightIn(max = 340.dp),
        ) {
            val scope = this
            locale.Provide { scope.menu() }
        }
    }
}

// =============================================================================== pickers in the app's language

/**
 * A day picker in the app's language: month and weekday names and the digits follow it (the Material picker, in its own
 * window, named them in the phone's language). Tap a day, then OK.
 */
@Composable
internal fun CalDatePickerDialog(initial: LocalDate, onDismiss: () -> Unit, onPick: (LocalDate) -> Unit) {
    var month by remember { mutableStateOf(YearMonth.from(initial)) }
    var chosen by remember { mutableStateOf(initial) }
    val firstDay = WeekFields.of(calLocale()).firstDayOfWeek
    val today = LocalDate.now()
    val offset = (month.atDay(1).dayOfWeek.value - firstDay.value + 7) % 7
    val cells = offset + month.lengthOfMonth()
    val rows = (cells + 6) / 7

    LocaleAwareDialog(onDismissRequest = onDismiss) {
        Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(28.dp)).background(Wa.Surface).padding(16.dp)) {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                MonthArrow(Icons.AutoMirrored.Rounded.KeyboardArrowLeft) { month = month.minusMonths(1) }
                Text(monthTitle(month), color = Wa.Ink, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center, modifier = Modifier.weight(1f))
                MonthArrow(Icons.AutoMirrored.Rounded.KeyboardArrowRight) { month = month.plusMonths(1) }
            }
            Spacer(Modifier.height(8.dp))
            Row(Modifier.fillMaxWidth()) {
                List(7) { firstDay.plus(it.toLong()) }.forEach { d ->
                    Text(weekdayShort(d), color = Wa.Mut, fontSize = 11.5.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center, modifier = Modifier.weight(1f))
                }
            }
            Spacer(Modifier.height(4.dp))
            repeat(rows) { row ->
                Row(Modifier.fillMaxWidth()) {
                    repeat(7) { col ->
                        val dayOfMonth = row * 7 + col - offset + 1
                        val day = if (dayOfMonth in 1..month.lengthOfMonth()) month.atDay(dayOfMonth) else null
                        Box(Modifier.weight(1f).aspectRatio(1f).padding(2.dp), contentAlignment = Alignment.Center) {
                            if (day != null) {
                                val selected = day == chosen
                                Box(
                                    Modifier.fillMaxWidth().aspectRatio(1f).clip(CircleShape)
                                        .background(if (selected) Wa.Red else Color.Transparent)
                                        .then(if (day == today && !selected) Modifier.border(1.5.dp, Wa.Red, CircleShape) else Modifier)
                                        .clickable { chosen = day },
                                    contentAlignment = Alignment.Center,
                                ) {
                                    Text(dayNumber(day), color = if (selected) Color.White else Wa.Ink, fontSize = 14.sp, fontWeight = if (selected) FontWeight.ExtraBold else FontWeight.Medium)
                                }
                            }
                        }
                    }
                }
            }
            PickerActions(onDismiss = onDismiss, onOk = { onPick(chosen) })
        }
    }
}

@Composable
private fun PickerActions(onDismiss: () -> Unit, onOk: () -> Unit) {
    Row(Modifier.fillMaxWidth().padding(top = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        WaButton(stringResource(R.string.cal_cancel), onDismiss, style = WaButtonStyle.Ghost, compact = true, modifier = Modifier.weight(1f))
        WaButton(stringResource(R.string.cal_ok), onOk, compact = true, modifier = Modifier.weight(1f))
    }
}

@Composable
private fun MonthArrow(icon: ImageVector, onClick: () -> Unit) {
    Box(Modifier.size(36.dp).clip(CircleShape).background(Wa.Field).clickable(onClick = onClick), contentAlignment = Alignment.Center) {
        Icon(icon, null, tint = Wa.Red, modifier = Modifier.size(22.dp))
    }
}

/** The clock in the app's language (its labels and buttons come from the localized context the dialog is given). */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun CalTimePickerDialog(initial: LocalTime, onDismiss: () -> Unit, onPick: (LocalTime) -> Unit) {
    val state = rememberTimePickerState(initial.hour, initial.minute, is24Hour = android.text.format.DateFormat.is24HourFormat(LocalContext.current))
    val locale = calLocale()
    val colors = TimePickerDefaults.colors(
        selectorColor = Wa.Red,
        containerColor = Wa.Surface,
        clockDialColor = Wa.Field,
        clockDialSelectedContentColor = Color.White,
        clockDialUnselectedContentColor = Wa.Ink,
        periodSelectorSelectedContainerColor = Wa.Red.copy(alpha = 0.15f),
        periodSelectorSelectedContentColor = Wa.Red,
        periodSelectorUnselectedContainerColor = Wa.Field,
        periodSelectorUnselectedContentColor = Wa.Ink,
        timeSelectorSelectedContainerColor = Color.Transparent,
        timeSelectorSelectedContentColor = Color.Transparent,
        timeSelectorUnselectedContainerColor = Color.Transparent,
        timeSelectorUnselectedContentColor = Color.Transparent,
    )
    LocaleAwareDialog(onDismissRequest = onDismiss) {
        Column(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(28.dp)).background(Wa.Surface).padding(horizontal = 16.dp, vertical = 14.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    TimeChip(
                        String.format(locale, "%02d", state.hour),
                        selected = state.selection == TimePickerSelectionMode.Hour,
                    ) { state.selection = TimePickerSelectionMode.Hour }
                    Text(":", color = Wa.Ink, fontSize = 26.sp, fontWeight = FontWeight.ExtraBold)
                    TimeChip(
                        String.format(locale, "%02d", state.minute),
                        selected = state.selection == TimePickerSelectionMode.Minute,
                    ) { state.selection = TimePickerSelectionMode.Minute }
                }
                Spacer(Modifier.height(16.dp))
                AnalogClockOnly(state, colors)
            }
            PickerActions(onDismiss = onDismiss, onOk = { onPick(LocalTime.of(state.hour, state.minute)) })
        }
    }
}

/** Analog dial only — LTR so Arabic and English both get a round clock, not a clipped RTL mess. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun AnalogClockOnly(state: TimePickerState, colors: TimePickerColors) {
    val header = 132.dp
    Layout(
        modifier = Modifier.fillMaxWidth(),
        content = { TimePicker(state, colors = colors, modifier = Modifier.fillMaxWidth()) },
    ) { measurables, constraints ->
        val headerPx = header.roundToPx()
        val width = if (constraints.maxWidth == Constraints.Infinity) constraints.minWidth else constraints.maxWidth
        val placeable = measurables.first().measure(
            Constraints(minWidth = width, maxWidth = width, minHeight = 0, maxHeight = Constraints.Infinity),
        )
        val h = (placeable.height - headerPx).coerceAtLeast(0)
        layout(placeable.width, h) { placeable.place(0, -headerPx) }
    }
}

@Composable
private fun TimeChip(value: String, selected: Boolean, onClick: () -> Unit) {
    val shape = RoundedCornerShape(16.dp)
    Box(
        Modifier.width(88.dp).height(56.dp).clip(shape)
            .background(if (selected) Wa.Red.copy(alpha = 0.16f) else Wa.Field)
            .border(1.dp, if (selected) Wa.Red.copy(alpha = 0.75f) else Wa.Mut.copy(alpha = 0.32f), shape)
            .clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        Text(value, color = if (selected) Wa.Red else Wa.Ink, fontSize = 28.sp, fontWeight = FontWeight.ExtraBold)
    }
}
