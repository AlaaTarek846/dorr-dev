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
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.layout.width
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
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material.icons.rounded.KeyboardArrowDown
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.Palette
import androidx.compose.material.icons.rounded.Place
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Warning
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TimePicker
import androidx.compose.material3.TimePickerDefaults
import androidx.compose.material3.rememberTimePickerState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.onSizeChanged
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.CalItemDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.locale.LocaleAwareDialog
import com.dorr.app.ui.screens.ConfirmDialog
import com.dorr.app.ui.screens.moments.momentColor
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaError
import com.dorr.app.ui.screens.wallet.WaIconWell
import com.dorr.app.ui.screens.wallet.WaPage
import com.dorr.app.ui.screens.wallet.WaSectionTitle
import com.dorr.app.ui.screens.wallet.waRise
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
@OptIn(ExperimentalLayoutApi::class)
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

    WaPage(
        title = stringResource(if (existing == null) R.string.cal_add else R.string.cal_edit),
        onBack = ::leave,
        ctaDock = true,
        cta = { WaButton(stringResource(R.string.cal_save), { save() }, enabled = !busy, loading = busy, icon = Icons.Rounded.CheckCircle) },
    ) {
        // ---- what it will look like
        Column(
            Modifier.fillMaxWidth().waRise(0).clip(RoundedCornerShape(24.dp))
                .background(Brush.linearGradient(listOf(accent, accent.copy(alpha = 0.72f)))).padding(18.dp),
        ) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.Event, null, tint = Color.White.copy(alpha = 0.9f), modifier = Modifier.size(16.dp))
                Spacer(Modifier.width(6.dp))
                Text(stringResource(R.string.cal_kind_event), color = Color.White.copy(alpha = 0.85f), fontSize = 12.sp, fontWeight = FontWeight.Bold)
            }
            Text(
                title.ifBlank { stringResource(R.string.cal_summary_untitled) }, color = Color.White, fontSize = 20.sp, fontWeight = FontWeight.ExtraBold,
                maxLines = 2, overflow = TextOverflow.Ellipsis,
            )
            Spacer(Modifier.height(8.dp))
            SummaryLine(
                Icons.Rounded.Schedule,
                listOf(dayTitle(date), if (allDay) stringResource(R.string.cal_all_day) else timeFormatter().format(start) + (end?.let { " – " + timeFormatter().format(it) } ?: "")).joinToString("  ·  "),
            )
            if (location.isNotBlank()) SummaryLine(Icons.Rounded.Place, location.trim())
        }

        // ---- details
        WaSectionTitle(stringResource(R.string.cal_sec_details), Modifier.waRise(2))
        WaCard(Modifier.fillMaxWidth().waRise(1), padding = 14.dp) {
            DorrTextField(
                title, { title = it.take(160) }, modifier = Modifier.fillMaxWidth(),
                label = stringResource(R.string.cal_lbl_title), placeholder = stringResource(R.string.cal_title_hint), icon = Icons.Rounded.Edit, error = titleMissing,
            )
            if (titleMissing) Text(stringResource(R.string.cal_err_title), color = Wa.Danger, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 4.dp))
            Spacer(Modifier.height(12.dp))
            DorrTextField(
                location, { location = it.take(200) }, modifier = Modifier.fillMaxWidth(),
                label = stringResource(R.string.cal_lbl_location), placeholder = stringResource(R.string.cal_location_hint), icon = Icons.Rounded.Place,
            )
            Spacer(Modifier.height(12.dp))
            DorrTextField(
                note, { note = it.take(NOTE_MAX) }, modifier = Modifier.fillMaxWidth(),
                label = stringResource(R.string.cal_lbl_note), placeholder = stringResource(R.string.cal_note_hint), icon = Icons.Rounded.Notes,
                singleLine = false, minLines = 3,
            )
            Text(
                stringResource(R.string.cal_chars, note.length, NOTE_MAX), color = Wa.Soft, fontSize = 11.sp, textAlign = TextAlign.End,
                modifier = Modifier.fillMaxWidth().padding(top = 4.dp),
            )
        }

        // ---- when
        WaSectionTitle(stringResource(R.string.cal_sec_when), Modifier.waRise(2))
        WaCard(Modifier.fillMaxWidth().waRise(2), padding = 6.dp) {
            ToggleRow(stringResource(R.string.cal_all_day), allDay) { allDay = it; if (!remindersTouched) reminders.clear() }
            EditorDivider()
            PickRow(Icons.Rounded.CalendarMonth, stringResource(R.string.cal_date), date.format(dateFormatter())) { picking = "date" }
            if (!allDay) {
                EditorDivider()
                PickRow(Icons.Rounded.Schedule, stringResource(R.string.cal_starts), timeFormatter().format(start)) { picking = "start" }
                EditorDivider()
                PickRow(
                    Icons.Rounded.Schedule, stringResource(R.string.cal_ends), end?.let { timeFormatter().format(it) } ?: stringResource(R.string.cal_no_end),
                    trailing = end?.let { { ClearButton { end = null } } },
                ) { picking = "end" }
                if (endsNextDay) {
                    Text(stringResource(R.string.cal_end_next_day), color = Wa.Mut, fontSize = 12.sp, modifier = Modifier.padding(horizontal = 12.dp).padding(bottom = 8.dp))
                }
            }
        }

        // ---- reminders
        WaSectionTitle(stringResource(R.string.cal_reminders), Modifier.waRise(3))
        WaCard(Modifier.fillMaxWidth().waRise(3), padding = 6.dp) {
            var open by remember { mutableStateOf(false) }
            val choices = if (allDay) AllDayChoices else ReminderChoices
            val reminderNames = choices.associateWith { reminderLabel(it, allDay) }
            val shown = reminders.sorted().filter { it in choices }
            val value = when {
                shown.isNotEmpty() -> shown.map { reminderNames.getValue(it) }.joinToString(listSeparator())
                remindersTouched -> stringResource(R.string.cal_rem_none)
                else -> stringResource(R.string.cal_rem_default_value)
            }
            SelectRow(Icons.Rounded.Alarm, stringResource(R.string.cal_reminders), value, open, { open = it }) {
                choices.forEach { m ->
                    val on = m in reminders
                    DropdownMenuItem(
                        text = { Text(reminderNames.getValue(m), color = if (on) Wa.Red else Wa.Ink, fontWeight = if (on) FontWeight.ExtraBold else FontWeight.Medium) },
                        trailingIcon = { if (on) Icon(Icons.Rounded.Check, null, tint = Wa.Red, modifier = Modifier.size(18.dp)) },
                        // Up to five, as the server allows; the menu stays open so several can be ticked.
                        enabled = on || reminders.size < 5,
                        onClick = {
                            remindersTouched = true
                            if (on) reminders.remove(m) else reminders.add(m)
                        },
                    )
                }
            }
            if (!remindersTouched) Text(stringResource(R.string.cal_reminders_default), color = Wa.Soft, fontSize = 11.5.sp, modifier = Modifier.padding(horizontal = 12.dp).padding(bottom = 8.dp))
        }


        // ---- colour
        WaSectionTitle(stringResource(R.string.cal_color), Modifier.waRise(4))
        WaCard(Modifier.fillMaxWidth().waRise(4), padding = 14.dp) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) {
                EventColors.forEach { c ->
                    val swatch = c?.let { momentColor(it, Wa.Red) } ?: Wa.Red
                    val selected = color == c
                    Box(
                        Modifier.size(38.dp).clip(CircleShape).background(swatch).border(3.dp, if (selected) Wa.Ink.copy(alpha = 0.45f) else Color.Transparent, CircleShape).clickable { color = c },
                        contentAlignment = Alignment.Center,
                    ) {
                        if (selected) Icon(Icons.Rounded.Check, null, tint = Color.White, modifier = Modifier.size(20.dp))
                    }
                }
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
private fun SummaryLine(icon: ImageVector, text: String) {
    Row(Modifier.padding(top = 2.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(icon, null, tint = Color.White.copy(alpha = 0.9f), modifier = Modifier.size(16.dp))
        Spacer(Modifier.width(6.dp))
        Text(text, color = Color.White, fontSize = 13.5.sp, fontWeight = FontWeight.SemiBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
    }
}

@Composable
private fun EditorDivider() {
    Box(Modifier.fillMaxWidth().padding(horizontal = 10.dp).height(1.dp).background(Wa.Line))
}

/** One value to pick (a day, a time): icon, small label over the value, an optional action, a chevron. */
@Composable
private fun PickRow(
    icon: ImageVector,
    label: String,
    value: String,
    trailing: (@Composable () -> Unit)? = null,
    chevron: ImageVector = Icons.AutoMirrored.Rounded.KeyboardArrowRight,
    onClick: () -> Unit,
) {
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable(onClick = onClick).padding(horizontal = 10.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        WaIconWell(icon, Tone.Red, size = 38.dp, iconSize = 19.dp)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(label, color = Wa.Mut, fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
            Text(value, color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        trailing?.invoke()
        Icon(chevron, null, tint = Wa.Soft, modifier = Modifier.size(20.dp))
    }
}

/** A choice field: icon, small label over the value, a chevron; tapping opens a menu of options right under it. */
@Composable
private fun SelectRow(icon: ImageVector, label: String, value: String, open: Boolean, onOpen: (Boolean) -> Unit, menu: @Composable ColumnScope.() -> Unit) {
    // The menu is exactly as wide as the field (so it lines up under it, in either direction), at most 280dp tall —
    // longer lists scroll inside it.
    val density = androidx.compose.ui.platform.LocalDensity.current
    var fieldWidth by remember { mutableStateOf(0.dp) }
    Box(Modifier.fillMaxWidth().onSizeChanged { fieldWidth = with(density) { it.width.toDp() } }) {
        PickRow(icon, label, value, trailing = null, chevron = Icons.Rounded.KeyboardArrowDown) { onOpen(true) }
        DropdownMenu(
            expanded = open,
            onDismissRequest = { onOpen(false) },
            modifier = Modifier.then(if (fieldWidth > 0.dp) Modifier.width(fieldWidth) else Modifier).heightIn(max = 280.dp).background(Wa.Surface),
            content = menu,
        )
    }
}

@Composable
private fun ClearButton(onClick: () -> Unit) {
    Row(
        Modifier.padding(end = 4.dp).clip(CircleShape).background(Wa.Field).clickable(onClick = onClick).padding(horizontal = 10.dp, vertical = 5.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Rounded.Close, null, tint = Wa.Red, modifier = Modifier.size(14.dp))
        Spacer(Modifier.width(4.dp))
        Text(stringResource(R.string.cal_clear), color = Wa.Red, fontSize = 12.sp, fontWeight = FontWeight.Bold)
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
            Row(Modifier.fillMaxWidth().padding(top = 6.dp), horizontalArrangement = Arrangement.End) {
                TextButton(onDismiss) { Text(stringResource(R.string.cal_cancel), color = Wa.Mut) }
                TextButton({ onPick(chosen) }) { Text(stringResource(R.string.cal_ok), color = Wa.Red, fontWeight = FontWeight.Bold) }
            }
        }
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
    LocaleAwareDialog(onDismissRequest = onDismiss) {
        Column(Modifier.clip(RoundedCornerShape(28.dp)).background(Wa.Surface).padding(20.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            TimePicker(
                state,
                colors = TimePickerDefaults.colors(
                    selectorColor = Wa.Red,
                    clockDialSelectedContentColor = Color.White,
                    periodSelectorSelectedContainerColor = Wa.Red.copy(alpha = 0.15f),
                    periodSelectorSelectedContentColor = Wa.Red,
                    timeSelectorSelectedContainerColor = Wa.Red.copy(alpha = 0.15f),
                    timeSelectorSelectedContentColor = Wa.Red,
                ),
            )
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) {
                TextButton(onDismiss) { Text(stringResource(R.string.cal_cancel), color = Wa.Mut) }
                TextButton({ onPick(LocalTime.of(state.hour, state.minute)) }) { Text(stringResource(R.string.cal_ok), color = Wa.Red, fontWeight = FontWeight.Bold) }
            }
        }
    }
}
