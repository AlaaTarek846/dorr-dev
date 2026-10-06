package com.dorr.app.ui.screens.calendar

import android.widget.Toast
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Alarm
import androidx.compose.material.icons.rounded.ArrowDownward
import androidx.compose.material.icons.rounded.ArrowUpward
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.Chat
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.Place
import androidx.compose.material.icons.rounded.Public
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material.icons.rounded.VisibilityOff
import androidx.compose.material3.DatePicker
import androidx.compose.material3.DatePickerDialog
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.rememberDatePickerState
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.chat.ChatDeepLink
import com.dorr.app.chat.ChatPush
import com.dorr.app.network.ApiClient
import com.dorr.app.network.CalItemDto
import com.dorr.app.network.CalPrefsDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.chat.TimePickDialog
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaEmpty
import com.dorr.app.ui.screens.wallet.WaError
import com.dorr.app.ui.screens.wallet.WaIconWell
import com.dorr.app.ui.screens.wallet.WaPage
import com.dorr.app.ui.screens.wallet.WaSectionTitle
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.wallet.waRise
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.time.Instant
import java.time.LocalDate
import java.time.LocalTime
import java.time.ZoneId
import java.time.ZoneOffset
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import java.util.Locale

/** "30 min", "1 h", "1 day" — a reminder offset. */
@Composable
internal fun reminderLabel(minutes: Int, allDay: Boolean = false): String = when {
    minutes == 0 -> stringResource(if (allDay) R.string.cal_rem_morning else R.string.cal_rem_at_time)
    minutes < 60 -> stringResource(R.string.cal_rem_minutes, minutes)
    minutes < 1440 -> stringResource(R.string.cal_rem_hours, minutes / 60)
    minutes < 10080 -> stringResource(R.string.cal_rem_days, minutes / 1440)
    else -> stringResource(R.string.cal_rem_week)
}

private val ReminderChoices = listOf(0, 5, 10, 15, 30, 60, 120, 180, 1440, 2880, 10080)
private val AllDayChoices = listOf(0, 1440, 2880, 10080)
private val EventColors = listOf(null, "#2563EB", "#16A34A", "#D97706", "#DB2777", "#7C3AED", "#0891B2")

// =============================================================================== one item

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun ItemSheet(item: CalItemDto, onDismiss: () -> Unit, onOpenMoments: () -> Unit) {
    val scope = rememberCoroutineScope()
    var editing by remember { mutableStateOf(false) }
    if (editing) {
        EventEditorSheet(existing = item, day = runCatching { LocalDate.parse(item.date) }.getOrDefault(LocalDate.now())) { editing = false; onDismiss() }
        return
    }
    val color = typeColor(item)

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 22.dp).padding(bottom = 26.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(46.dp).clip(RoundedCornerShape(15.dp)).background(color.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                    if (item.emoji != null) Text(item.emoji, fontSize = 22.sp) else Icon(typeIcon(item.type), null, tint = color, modifier = Modifier.size(24.dp))
                }
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(item.title, color = Wa.Ink, fontSize = 19.sp, fontWeight = FontWeight.ExtraBold)
                    Text(kindLabel(item.type), color = color, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
                }
            }
            Spacer(Modifier.height(14.dp))
            val date = runCatching { LocalDate.parse(item.date) }.getOrNull()
            InfoLine(Icons.Rounded.Schedule, listOfNotNull(
                date?.let { dayTitle(it) },
                if (item.allDay || item.startsAt == null) stringResource(R.string.cal_all_day) else timeText(item.startsAt) + (item.endsAt?.let { " – " + timeText(it) } ?: ""),
            ).joinToString("  ·  "))
            item.originTime?.let { InfoLine(Icons.Rounded.Public, stringResource(R.string.cal_origin_time, it, item.timezone.orEmpty().substringAfterLast('/').replace('_', ' '))) }
            item.location?.let { InfoLine(Icons.Rounded.Place, it) }
            item.note?.let { InfoLine(Icons.Rounded.Notes, it) }
            item.reminders?.takeIf { it.isNotEmpty() }?.let { r -> InfoLine(Icons.Rounded.Alarm, r.map { reminderLabel(it, item.allDay) }.joinToString("، ")) }
            if (item.overdue) Text(stringResource(R.string.cal_overdue), color = Wa.Danger, fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 4.dp))

            Spacer(Modifier.height(16.dp))
            when (item.type) {
                "event" -> {
                    WaButton(stringResource(R.string.cal_edit), { editing = true }, icon = Icons.Rounded.Edit)
                    Spacer(Modifier.height(6.dp))
                    WaButton(stringResource(R.string.cal_delete), {
                        scope.launch { runCatching { ApiClient.calendar.delete(calAuth(), item.ref) }; CalendarStore.changed(); onDismiss() }
                    }, style = WaButtonStyle.Quiet, icon = Icons.Rounded.Delete)
                }
                "task" -> WaButton(stringResource(if (item.done) R.string.cal_task_undo else R.string.cal_task_done), {
                    scope.launch {
                        runCatching { ApiClient.aiTools.updateTask(calAuth(), item.ref, JsonObject().apply { addProperty("done", !item.done) }) }
                        CalendarStore.changed()
                        onDismiss()
                    }
                }, icon = Icons.Rounded.CheckCircle)
                "moment", "personal" -> WaButton(stringResource(R.string.cal_open_moments), { onDismiss(); onOpenMoments() }, icon = Icons.Rounded.Event)
            }
            item.conversationId?.let { c ->
                Spacer(Modifier.height(6.dp))
                WaButton(stringResource(R.string.cal_open_chat), { onDismiss(); ChatPush.open(ChatDeepLink.Conversation(c)) }, style = WaButtonStyle.Ghost, icon = Icons.Rounded.Chat)
            }
        }
    }
}

@Composable
private fun kindLabel(type: String): String = stringResource(
    when (type) {
        "task" -> R.string.cal_kind_task
        "reminder" -> R.string.cal_kind_reminder
        "moment" -> R.string.cal_kind_moment
        "personal" -> R.string.cal_kind_personal
        "capsule" -> R.string.cal_kind_capsule
        else -> R.string.cal_kind_event
    },
)

@Composable
private fun InfoLine(icon: ImageVector, text: String) {
    Row(Modifier.fillMaxWidth().padding(vertical = 5.dp), verticalAlignment = Alignment.Top) {
        Icon(icon, null, tint = Wa.Mut, modifier = Modifier.size(19.dp))
        Spacer(Modifier.width(10.dp))
        Text(text, color = Wa.Ink, fontSize = 14.5.sp)
    }
}

// =============================================================================== add / edit

/**
 * A new appointment (or a change to one): title, all day or a time (and an end), where, a note,
 * when to remind me, a colour. Saved in this phone's time zone — shown right wherever I go.
 */
@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
internal fun EventEditorSheet(existing: CalItemDto?, day: LocalDate, onDismiss: () -> Unit) {
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val zone = ZoneId.systemDefault()
    val start0 = localTime(existing?.startsAt)
    var title by remember { mutableStateOf(existing?.title.orEmpty()) }
    var allDay by remember { mutableStateOf(existing?.allDay ?: false) }
    var date by remember { mutableStateOf(start0?.toLocalDate() ?: runCatching { LocalDate.parse(existing?.date) }.getOrNull() ?: day) }
    var start by remember { mutableStateOf(start0?.toLocalTime() ?: LocalTime.now().plusHours(1).withMinute(0).withSecond(0).withNano(0)) }
    var end by remember { mutableStateOf(localTime(existing?.endsAt)?.toLocalTime()) }
    var location by remember { mutableStateOf(existing?.location.orEmpty()) }
    var note by remember { mutableStateOf(existing?.note.orEmpty()) }
    var color by remember { mutableStateOf(existing?.color) }
    val reminders = remember { mutableStateListOf<Int>().apply { existing?.reminders?.let { addAll(it) } } }
    var remindersTouched by remember { mutableStateOf(existing != null) }
    var picking by remember { mutableStateOf<String?>(null) } // date · start · end
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val added = stringResource(R.string.cal_saved)
    val dupe = stringResource(R.string.cal_duplicate)

    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).imePadding().padding(horizontal = 22.dp).padding(bottom = 26.dp)) {
            Text(stringResource(if (existing == null) R.string.cal_add else R.string.cal_edit), color = Wa.Ink, fontSize = 19.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(12.dp))
            DorrTextField(title, { title = it.take(160) }, placeholder = stringResource(R.string.cal_title_hint), modifier = Modifier.fillMaxWidth())
            Spacer(Modifier.height(10.dp))

            Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Wa.Field).padding(horizontal = 14.dp, vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(stringResource(R.string.cal_all_day), color = Wa.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                Switch(allDay, { allDay = it; if (!remindersTouched) reminders.clear() }, colors = SwitchDefaults.colors(checkedTrackColor = Wa.Red))
            }
            Spacer(Modifier.height(8.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                PickBox(Icons.Rounded.CalendarMonth, stringResource(R.string.cal_date), date.format(DateTimeFormatter.ofLocalizedDate(FormatStyle.MEDIUM).withLocale(Locale.getDefault())), Modifier.weight(1.3f)) { picking = "date" }
                if (!allDay) PickBox(Icons.Rounded.Schedule, stringResource(R.string.cal_starts), fmt(start), Modifier.weight(1f)) { picking = "start" }
            }
            AnimatedVisibility(!allDay) {
                Row(Modifier.padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    PickBox(Icons.Rounded.Schedule, stringResource(R.string.cal_ends), end?.let { fmt(it) } ?: stringResource(R.string.cal_no_end), Modifier.weight(1f)) { picking = "end" }
                    if (end != null) Text(stringResource(R.string.cal_clear), color = Wa.Red, fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.align(Alignment.CenterVertically).clickable { end = null }.padding(8.dp))
                }
            }
            Spacer(Modifier.height(10.dp))
            DorrTextField(location, { location = it.take(200) }, placeholder = stringResource(R.string.cal_location_hint), modifier = Modifier.fillMaxWidth())
            Spacer(Modifier.height(8.dp))
            DorrTextField(note, { note = it.take(1000) }, placeholder = stringResource(R.string.cal_note_hint), modifier = Modifier.fillMaxWidth())

            Text(stringResource(R.string.cal_reminders), color = Wa.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 14.dp, bottom = 6.dp))
            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                (if (allDay) AllDayChoices else ReminderChoices).forEach { m ->
                    val on = m in reminders
                    Text(
                        reminderLabel(m, allDay), color = if (on) Color.White else Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold,
                        modifier = Modifier.clip(CircleShape).background(if (on) Wa.Red else Wa.Field).clickable {
                            remindersTouched = true
                            if (on) reminders.remove(m) else if (reminders.size < 5) reminders.add(m)
                        }.padding(horizontal = 12.dp, vertical = 7.dp),
                    )
                }
            }
            if (!remindersTouched) Text(stringResource(R.string.cal_reminders_default), color = Wa.Soft, fontSize = 11.5.sp, modifier = Modifier.padding(top = 4.dp))

            Text(stringResource(R.string.cal_color), color = Wa.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 14.dp, bottom = 6.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                EventColors.forEach { c ->
                    val swatch = c?.let { com.dorr.app.ui.screens.moments.momentColor(it, Wa.Red) } ?: Wa.Red
                    Box(
                        Modifier.size(32.dp).clip(CircleShape).background(swatch).border(3.dp, if (color == c) Wa.Ink.copy(alpha = 0.5f) else Color.Transparent, CircleShape).clickable { color = c },
                    )
                }
            }

            WaError(error)
            Spacer(Modifier.height(16.dp))
            WaButton(stringResource(R.string.cal_save), enabled = title.isNotBlank() && !busy, loading = busy, icon = Icons.Rounded.CheckCircle, onClick = {
                busy = true
                error = null
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
                    color?.let { addProperty("color", it) } ?: add("color", com.google.gson.JsonNull.INSTANCE)
                    if (remindersTouched) add("reminders", JsonArray().apply { reminders.sorted().forEach { add(it) } })
                }
                scope.launch {
                    runCatching { if (existing == null) ApiClient.calendar.create(calAuth(), body).data else ApiClient.calendar.update(calAuth(), existing.ref, body).data }
                        .onSuccess { saved ->
                            Toast.makeText(context, if (saved?.duplicate == true) dupe else added, Toast.LENGTH_SHORT).show()
                            CalendarStore.changed()
                            onDismiss()
                        }
                        .onFailure { error = it.apiFailure().message; busy = false }
                }
            })
        }
    }

    when (picking) {
        "date" -> {
            val state = rememberDatePickerState(initialSelectedDateMillis = date.atStartOfDay(ZoneOffset.UTC).toInstant().toEpochMilli())
            DatePickerDialog(
                onDismissRequest = { picking = null },
                confirmButton = {
                    TextButton({ state.selectedDateMillis?.let { date = Instant.ofEpochMilli(it).atZone(ZoneOffset.UTC).toLocalDate() }; picking = null }) {
                        Text(stringResource(R.string.cal_ok), color = Wa.Red, fontWeight = FontWeight.Bold)
                    }
                },
                dismissButton = { TextButton({ picking = null }) { Text(stringResource(R.string.cal_cancel), color = Wa.Mut) } },
            ) { DatePicker(state) }
        }
        "start" -> TimePickDialog(start, onDismiss = { picking = null }) { start = it; picking = null }
        "end" -> TimePickDialog(end ?: start.plusHours(1), onDismiss = { picking = null }) { end = it; picking = null }
    }
}

private fun fmt(t: LocalTime): String = t.format(DateTimeFormatter.ofLocalizedTime(FormatStyle.SHORT).withLocale(Locale.getDefault()))

@Composable
private fun PickBox(icon: ImageVector, label: String, value: String, modifier: Modifier = Modifier, onClick: () -> Unit) {
    Row(modifier.clip(RoundedCornerShape(16.dp)).background(Wa.Field).clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(icon, null, tint = Wa.Red, modifier = Modifier.size(19.dp))
        Spacer(Modifier.width(8.dp))
        Column {
            Text(label, color = Wa.Mut, fontSize = 11.sp, fontWeight = FontWeight.Bold)
            Text(value, color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.Bold, maxLines = 1)
        }
    }
}

// =============================================================================== settings (201, 202, 204, 207)

private val SourceLabels = listOf(
    "events" to R.string.cal_src_events, "moments" to R.string.cal_src_moments, "personal" to R.string.cal_src_personal,
    "tasks" to R.string.cal_src_tasks, "reminders" to R.string.cal_src_reminders,
)

private val SectionLabels = mapOf(
    "next" to R.string.cal_next, "events" to R.string.cal_sec_events, "tasks" to R.string.cal_sec_tasks,
    "reminders" to R.string.cal_sec_reminders, "moments" to R.string.cal_sec_moments, "around" to R.string.cal_around,
)

@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun CalendarSettings(onBack: () -> Unit) {
    val scope = rememberCoroutineScope()
    var prefs by remember { mutableStateOf<CalPrefsDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(Unit) { prefs = runCatching { ApiClient.calendar.preferences(calAuth()).data }.getOrNull() }
    fun save(body: JsonObject) {
        scope.launch {
            runCatching { ApiClient.calendar.savePreferences(calAuth(), body).data }
                .onSuccess { it?.let { p -> prefs = p }; CalendarStore.changed() }
                .onFailure { error = it.apiFailure().message }
        }
    }

    WaPage(title = stringResource(R.string.cal_settings), onBack = onBack) {
        val p = prefs
        if (p == null) {
            repeat(4) { WaSkeleton(Modifier.fillMaxWidth().height(56.dp).padding(bottom = 8.dp), RoundedCornerShape(16.dp)) }
            return@WaPage
        }
        WaError(error)

        WaSectionTitle(stringResource(R.string.cal_sources), Modifier.waRise(0))
        Text(stringResource(R.string.cal_sources_sub), color = Wa.Mut, fontSize = 12.sp, modifier = Modifier.padding(bottom = 6.dp))
        WaCard(Modifier.fillMaxWidth().waRise(0), padding = 6.dp) {
            SourceLabels.forEach { (key, label) ->
                ToggleRow(stringResource(label), p.sources[key] != false) { on -> save(JsonObject().apply { add("sources", JsonObject().apply { addProperty(key, on) }) }) }
            }
        }

        WaSectionTitle(stringResource(R.string.cal_default_reminders), Modifier.waRise(1))
        ReminderPicker(ReminderChoices, p.defaultReminders, false) { list -> save(JsonObject().apply { add("default_reminders", JsonArray().apply { list.forEach { add(it) } }) }) }
        Text(stringResource(R.string.cal_all_day_reminders), color = Wa.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 12.dp, bottom = 6.dp))
        ReminderPicker(AllDayChoices, p.allDayReminders, true) { list -> save(JsonObject().apply { add("all_day_reminders", JsonArray().apply { list.forEach { add(it) } }) }) }

        Spacer(Modifier.height(14.dp))
        WaCard(Modifier.fillMaxWidth().waRise(2), padding = 6.dp) {
            ToggleRow(stringResource(R.string.cal_respect_quiet), p.respectQuiet, stringResource(R.string.cal_respect_quiet_sub)) { on -> save(JsonObject().apply { addProperty("respect_quiet", on) }) }
            ToggleRow(stringResource(R.string.cal_personalised), p.personalised, stringResource(R.string.cal_personalised_sub)) { on -> save(JsonObject().apply { addProperty("personalised", on) }) }
        }

        WaSectionTitle(stringResource(R.string.cal_today_order), Modifier.waRise(3))
        val order = p.todaySections?.order.orEmpty()
        val hidden = p.todaySections?.hidden.orEmpty()
        fun saveSections(newOrder: List<String>, newHidden: List<String>) = save(JsonObject().apply {
            add("today_sections", JsonObject().apply {
                add("order", JsonArray().apply { newOrder.forEach { add(it) } })
                add("hidden", JsonArray().apply { newHidden.forEach { add(it) } })
            })
        })
        WaCard(Modifier.fillMaxWidth().waRise(3), padding = 6.dp) {
            order.forEachIndexed { i, key ->
                val off = key in hidden
                Row(Modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text(SectionLabels[key]?.let { stringResource(it) } ?: key, color = if (off) Wa.Soft else Wa.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                    SmallIcon(Icons.Rounded.ArrowUpward, i > 0) { saveSections(order.toMutableList().apply { add(i - 1, removeAt(i)) }, hidden) }
                    SmallIcon(Icons.Rounded.ArrowDownward, i < order.lastIndex) { saveSections(order.toMutableList().apply { add(i + 1, removeAt(i)) }, hidden) }
                    SmallIcon(if (off) Icons.Rounded.VisibilityOff else Icons.Rounded.Visibility, true) { saveSections(order, if (off) hidden - key else hidden + key) }
                }
            }
        }
    }
}

@Composable
private fun SmallIcon(icon: ImageVector, enabled: Boolean, onClick: () -> Unit) {
    Box(Modifier.size(34.dp).clip(CircleShape).clickable(enabled = enabled, onClick = onClick), contentAlignment = Alignment.Center) {
        Icon(icon, null, tint = if (enabled) Wa.Red else Wa.Line, modifier = Modifier.size(19.dp))
    }
}

@Composable
private fun ToggleRow(title: String, on: Boolean, sub: String? = null, onChange: (Boolean) -> Unit) {
    Row(Modifier.fillMaxWidth().padding(horizontal = 10.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(title, color = Wa.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.Bold)
            sub?.let { Text(it, color = Wa.Mut, fontSize = 12.sp) }
        }
        Switch(on, onChange, colors = SwitchDefaults.colors(checkedTrackColor = Wa.Red))
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun ReminderPicker(choices: List<Int>, selected: List<Int>, allDay: Boolean, onChange: (List<Int>) -> Unit) {
    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        choices.forEach { m ->
            val on = m in selected
            Text(
                reminderLabel(m, allDay), color = if (on) Color.White else Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(CircleShape).background(if (on) Wa.Red else Wa.Surface).border(1.dp, if (on) Color.Transparent else Wa.Line, CircleShape)
                    .clickable { onChange(if (on) selected - m else (selected + m).take(5)) }.padding(horizontal = 12.dp, vertical = 7.dp),
            )
        }
    }
}

// =============================================================================== search (206)

@Composable
internal fun CalendarSearch(onBack: () -> Unit, onOpenMoments: () -> Unit) {
    var query by remember { mutableStateOf("") }
    var results by remember { mutableStateOf<List<CalItemDto>?>(null) }
    var open by remember { mutableStateOf<CalItemDto?>(null) }
    LaunchedEffect(query) {
        val q = query.trim()
        if (q.length < 2) { results = null; return@LaunchedEffect }
        delay(350)
        results = runCatching { ApiClient.calendar.search(calAuth(), q, zoneId()).data?.items.orEmpty() }.getOrDefault(emptyList())
    }

    WaPage(title = stringResource(R.string.cal_search), onBack = onBack) {
        DorrTextField(query, { query = it.take(100) }, placeholder = stringResource(R.string.cal_search_hint), modifier = Modifier.fillMaxWidth().waRise(0))
        Text(stringResource(R.string.cal_search_sub), color = Wa.Soft, fontSize = 11.5.sp, modifier = Modifier.padding(top = 6.dp, bottom = 10.dp))
        val r = results
        when {
            r == null -> Box(Modifier.fillMaxWidth().padding(top = 30.dp), contentAlignment = Alignment.Center) { WaIconWell(Icons.Rounded.Search, Tone.Gray, size = 64.dp, iconSize = 28.dp) }
            r.isEmpty() -> WaCard(Modifier.fillMaxWidth()) { WaEmpty(Icons.Rounded.Search, Tone.Gray, stringResource(R.string.cal_search_none), stringResource(R.string.cal_search_none_sub)) }
            else -> r.forEachIndexed { i, item -> ItemRow(item, Modifier.padding(bottom = 8.dp).waRise(i)) { if (item.type != "capsule") open = item } }
        }
    }
    open?.let { item -> ItemSheet(item, onDismiss = { open = null }, onOpenMoments = onOpenMoments) }
}
