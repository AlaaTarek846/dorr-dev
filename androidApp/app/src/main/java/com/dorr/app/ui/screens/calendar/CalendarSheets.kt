package com.dorr.app.ui.screens.calendar

import android.widget.Toast
import com.dorr.app.ui.screens.profile.settingsSurface
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.RedToggle
import com.dorr.app.ui.screens.profile.PinkBackdrop
import com.dorr.app.ui.screens.profile.PinkIcon
import com.dorr.app.ui.screens.profile.SubHeader
import com.dorr.app.ui.screens.AccountDark
import androidx.compose.material.icons.rounded.SportsSoccer
import androidx.compose.material.icons.rounded.Celebration
import androidx.compose.material.icons.rounded.Cake
import androidx.compose.material.icons.rounded.Bedtime
import androidx.compose.foundation.layout.ColumnScope
import com.dorr.app.ui.screens.ConfirmDialog
import androidx.compose.material.icons.rounded.SearchOff
import androidx.compose.material.icons.rounded.Warning
import androidx.compose.material.icons.rounded.Close
import com.dorr.app.network.CalTodayDto
import com.dorr.app.network.CalSectionsDto
import androidx.compose.runtime.mutableIntStateOf
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.sync.Mutex
import androidx.compose.material.icons.rounded.Settings
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.activity.compose.BackHandler
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
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
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
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.pluralStringResource
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
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaEmpty
import com.dorr.app.ui.screens.wallet.WaError
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
import com.dorr.app.ui.screens.chat.ChSheet

/** The comma between items of a list, in the app's language ("، " in Arabic). */
internal fun listSeparator(): String = if (calLocale().language == "ar") "، " else ", "

/** "30 min", "1 h", "1 day" — a reminder offset. */
@Composable
internal fun reminderLabel(minutes: Int, allDay: Boolean = false): String = when {
    minutes == 0 -> stringResource(if (allDay) R.string.cal_rem_morning else R.string.cal_rem_at_time)
    minutes < 60 -> stringResource(R.string.cal_rem_minutes, minutes)
    minutes < 1440 -> stringResource(R.string.cal_rem_hours, minutes / 60)
    minutes < 10080 -> pluralStringResource(R.plurals.cal_rem_days, minutes / 1440, minutes / 1440)
    else -> stringResource(R.string.cal_rem_week)
}

internal val ReminderChoices = listOf(0, 5, 10, 15, 30, 60, 120, 180, 1440, 2880, 10080)
internal val AllDayChoices = listOf(0, 1440, 2880, 10080)
internal val EventColors = listOf(null, "#2563EB", "#16A34A", "#D97706", "#DB2777", "#7C3AED", "#0891B2")

// =============================================================================== one item

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun ItemSheet(item: CalItemDto, onDismiss: () -> Unit, onOpenMoments: () -> Unit, onEdit: (CalItemDto) -> Unit) {
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    var confirmDelete by remember { mutableStateOf(false) }
    var deleting by remember { mutableStateOf(false) }
    val deletedText = stringResource(R.string.cal_deleted)
    val deleteFailedText = stringResource(R.string.cal_delete_failed)
    val color = typeColor(item)

    ChSheet(
        onDismissRequest = onDismiss,
        sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true),
        containerColor = Wa.Surface,
        shape = RoundedCornerShape(topStart = 24.dp, topEnd = 24.dp),
    ) {
        Column(
            Modifier
                .fillMaxWidth()
                .navigationBarsPadding()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 18.dp)
                .padding(bottom = 20.dp),
        ) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(40.dp).clip(RoundedCornerShape(12.dp)).background(color.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                    if (item.emoji != null) Text(item.emoji, fontSize = 20.sp) else Icon(itemIcon(item), null, tint = color, modifier = Modifier.size(20.dp))
                }
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(item.title, color = Wa.Ink, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis, lineHeight = 20.sp)
                    Spacer(Modifier.height(4.dp))
                    Text(
                        kindLabel(item.type),
                        color = color,
                        fontSize = 11.sp,
                        fontWeight = FontWeight.Bold,
                        modifier = Modifier
                            .clip(RoundedCornerShape(8.dp))
                            .background(color.copy(alpha = 0.12f))
                            .padding(horizontal = 8.dp, vertical = 3.dp),
                    )
                }
            }
            Spacer(Modifier.height(12.dp))
            val date = runCatching { LocalDate.parse(item.date) }.getOrNull()
            val lines = buildList {
                add(
                    Icons.Rounded.Schedule to listOfNotNull(
                        date?.let { dayTitle(it) },
                        if (item.allDay || item.startsAt == null) stringResource(R.string.cal_all_day) else timeText(item.startsAt) + (item.endsAt?.let { " – " + timeText(it) } ?: ""),
                    ).joinToString("  ·  "),
                )
                item.originTime?.let { add(Icons.Rounded.Public to stringResource(R.string.cal_origin_time, it, item.timezone.orEmpty().substringAfterLast('/').replace('_', ' '))) }
                item.location?.let { add(Icons.Rounded.Place to it) }
                item.note?.let { add(Icons.Rounded.Notes to it) }
                item.reminders?.takeIf { it.isNotEmpty() }?.let { r -> add(Icons.Rounded.Alarm to r.map { reminderLabel(it, item.allDay) }.joinToString(listSeparator())) }
            }
            val shape = RoundedCornerShape(16.dp)
            Column(
                Modifier
                    .fillMaxWidth()
                    .border(1.dp, Wa.Line, shape)
                    .clip(shape)
                    .background(Wa.Surface)
                    .padding(horizontal = 8.dp, vertical = 2.dp),
            ) {
                lines.forEachIndexed { i, (icon, text) ->
                    InfoLine(icon, text)
                    if (i != lines.lastIndex) {
                        Box(Modifier.fillMaxWidth().padding(start = 46.dp).height(1.dp).background(Wa.Line))
                    }
                }
                if (item.overdue) {
                    Text(stringResource(R.string.cal_overdue), color = Wa.Danger, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(horizontal = 8.dp, vertical = 8.dp))
                }
            }

            Spacer(Modifier.height(12.dp))
            when (item.type) {
                "event" -> {
                    SheetAction(stringResource(R.string.cal_edit), { onDismiss(); onEdit(item) }, icon = Icons.Rounded.Edit)
                    Spacer(Modifier.height(8.dp))
                    SheetAction(stringResource(R.string.cal_delete), { confirmDelete = true }, icon = Icons.Rounded.Delete, tone = SheetActionTone.Quiet)
                }
                "task" -> SheetAction(stringResource(if (item.done) R.string.cal_task_undo else R.string.cal_task_done), {
                    scope.launch {
                        runCatching { ApiClient.aiTools.updateTask(calAuth(), item.ref, JsonObject().apply { addProperty("done", !item.done) }) }
                        CalendarStore.changed()
                        onDismiss()
                    }
                }, icon = Icons.Rounded.CheckCircle)
                "moment", "personal" -> SheetAction(stringResource(R.string.cal_open_moments), { onDismiss(); onOpenMoments() }, icon = Icons.Rounded.Event)
            }
            item.conversationId?.let { c ->
                Spacer(Modifier.height(8.dp))
                SheetAction(stringResource(R.string.cal_open_chat), { onDismiss(); ChatPush.open(ChatDeepLink.Conversation(c)) }, icon = Icons.Rounded.Chat, tone = SheetActionTone.Ghost)
            }
        }
    }

    if (confirmDelete) {
        ConfirmDialog(
            icon = Icons.Rounded.Delete,
            title = stringResource(R.string.cal_delete),
            message = stringResource(R.string.cal_delete_confirm, item.title),
            confirmLabel = stringResource(R.string.cal_delete_yes),
            loading = deleting,
            onConfirm = {
                deleting = true
                scope.launch {
                    runCatching { ApiClient.calendar.delete(calAuth(), item.ref) }
                        .onSuccess {
                            Toast.makeText(context, deletedText, Toast.LENGTH_SHORT).show()
                            CalendarStore.changed()
                            onDismiss()
                        }
                        .onFailure { failure ->
                            Toast.makeText(context, failure.apiFailure().message ?: deleteFailedText, Toast.LENGTH_LONG).show()
                            deleting = false
                            confirmDelete = false
                        }
                }
            },
            onDismiss = { if (!deleting) confirmDelete = false },
        )
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

private enum class SheetActionTone { Primary, Quiet, Ghost }

@Composable
private fun SheetAction(text: String, onClick: () -> Unit, icon: ImageVector? = null, tone: SheetActionTone = SheetActionTone.Primary) {
    val shape = RoundedCornerShape(14.dp)
    val row = Modifier
        .fillMaxWidth()
        .height(44.dp)
        .clip(shape)
        .then(
            when (tone) {
                SheetActionTone.Primary -> Modifier.background(Wa.HeroBrush)
                SheetActionTone.Quiet -> Modifier.background(Wa.Field)
                SheetActionTone.Ghost -> Modifier.border(1.dp, Wa.Line, shape)
            },
        )
        .clickable(onClick = onClick)
        .padding(horizontal = 14.dp)
    val fg = when (tone) {
        SheetActionTone.Primary -> Color.White
        SheetActionTone.Quiet -> Wa.Danger
        SheetActionTone.Ghost -> Wa.Ink
    }
    Row(row, verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.Center) {
        icon?.let {
            Icon(it, null, tint = fg, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(8.dp))
        }
        Text(text, color = fg, fontSize = 13.5.sp, fontWeight = FontWeight.Bold)
    }
}

@Composable
private fun InfoLine(icon: ImageVector, text: String) {
    Row(Modifier.fillMaxWidth().padding(horizontal = 4.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(
            Modifier.size(32.dp).clip(RoundedCornerShape(10.dp)).background(Wa.Field),
            contentAlignment = Alignment.Center,
        ) {
            Icon(icon, null, tint = Wa.Ink, modifier = Modifier.size(16.dp))
        }
        Spacer(Modifier.width(10.dp))
        Text(text, color = Wa.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f), lineHeight = 18.sp)
    }
}

// =============================================================================== settings (201, 202, 204, 207)

// Every source the server knows (ChatCalendarPreference::SOURCES) — Discover events and Sports matches included.
private class CalSource(val key: String, val icon: ImageVector, val title: Int, val help: Int)

private val SourceLabels = listOf(
    CalSource("events", Icons.Rounded.Event, R.string.cal_src_events, R.string.cal_src_events_sub),
    CalSource("moments", Icons.Rounded.Celebration, R.string.cal_src_moments, R.string.cal_src_moments_sub),
    CalSource("personal", Icons.Rounded.Cake, R.string.cal_src_personal, R.string.cal_src_personal_sub),
    CalSource("tasks", Icons.Rounded.CheckCircle, R.string.cal_src_tasks, R.string.cal_src_tasks_sub),
    CalSource("reminders", Icons.Rounded.Alarm, R.string.cal_src_reminders, R.string.cal_src_reminders_sub),
    CalSource("discover", Icons.Rounded.Place, R.string.cal_src_discover, R.string.cal_src_discover_sub),
    CalSource("sports", Icons.Rounded.SportsSoccer, R.string.cal_src_sports, R.string.cal_src_sports_sub),
)

private val SectionLabels = mapOf(
    "events" to R.string.cal_sec_events, "tasks" to R.string.cal_sec_tasks,
    "reminders" to R.string.cal_sec_reminders, "moments" to R.string.cal_sec_moments,
)

private enum class CalSub { Menu, Sources, Reminders, Today }

/**
 * Calendar settings, laid out like the wallet's: a short menu, and each entry opens its own page — what shows in the
 * calendar, reminders (with quiet hours and personalisation), and the order of the Today page. A sub page's back goes
 * to the menu; the menu's back leaves.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun CalendarSettings(onBack: () -> Unit) {
    var prefs by remember { mutableStateOf<CalPrefsDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var sub by remember { mutableStateOf(CalSub.Menu) }
    // Saves go out one at a time, in the order they were made; only the last answer is shown.
    val saving = remember { Mutex() }
    var latest by remember { mutableIntStateOf(0) }
    LaunchedEffect(Unit) { prefs = runCatching { ApiClient.calendar.preferences(calAuth()).data }.getOrNull() }

    /**
     * Shows the change at once (the switch flips, the section moves), then saves it. Building every change on what is
     * shown — not on the last server answer — means quick taps add up (two taps on ↑ move a section two places).
     */
    fun change(update: (CalPrefsDto) -> CalPrefsDto, body: JsonObject) {
        val current = prefs ?: return
        prefs = update(current)
        error = null
        val ticket = ++latest
        CalendarStore.scope.launch {
            saving.withLock {
                runCatching { ApiClient.calendar.savePreferences(calAuth(), body).data }
                    .onSuccess { saved -> if (ticket == latest && saved != null) prefs = saved }
                    .onFailure { failure ->
                        error = failure.apiFailure().message
                        // Back to what is really saved.
                        runCatching { ApiClient.calendar.preferences(calAuth()).data }.getOrNull()?.let { prefs = it }
                    }
            }
            if (ticket == latest) CalendarStore.changed()
        }
    }

    BackHandler(enabled = sub != CalSub.Menu) { sub = CalSub.Menu }

    val title = when (sub) {
        CalSub.Menu -> stringResource(R.string.cal_settings)
        CalSub.Sources -> stringResource(R.string.cal_sources)
        CalSub.Reminders -> stringResource(R.string.cal_menu_reminders)
        CalSub.Today -> stringResource(R.string.cal_today_order)
    }

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize()) {
            SubHeader(title) { if (sub == CalSub.Menu) onBack() else sub = CalSub.Menu }
            Column(
                Modifier
                    .weight(1f)
                    .fillMaxWidth()
                    .verticalScroll(rememberScrollState())
                    .padding(horizontal = 14.dp)
                    .padding(top = 6.dp, bottom = 16.dp),
            ) {
        val p = prefs
        if (p == null) {
            repeat(4) { WaSkeleton(Modifier.fillMaxWidth().height(56.dp).padding(bottom = 8.dp), RoundedCornerShape(16.dp)) }
            return@Column
        }
        WaError(error)

        when (sub) {
            CalSub.Menu -> {
                CalSettingsRow(Icons.Rounded.CalendarMonth, R.string.cal_sources, R.string.cal_menu_sources_sub) { sub = CalSub.Sources }
                CalSettingsRow(Icons.Rounded.Alarm, R.string.cal_menu_reminders, R.string.cal_menu_reminders_sub) { sub = CalSub.Reminders }
                CalSettingsRow(Icons.Rounded.Schedule, R.string.cal_today_order, R.string.cal_menu_today_sub) { sub = CalSub.Today }
            }

            CalSub.Sources -> {
                Text(stringResource(R.string.cal_sources_sub), color = Wa.Mut, fontSize = 12.sp, modifier = Modifier.padding(bottom = 6.dp))
                SettingsToggleCard(Modifier.waRise(0)) {
                    SourceLabels.forEachIndexed { index, source ->
                        SettingsToggleRow(
                            source.icon, stringResource(source.title), stringResource(source.help), p.sources[source.key] != false, divider = index != SourceLabels.lastIndex,
                        ) { on ->
                            change({ it.copy(sources = it.sources + (source.key to on)) }, JsonObject().apply { add("sources", JsonObject().apply { addProperty(source.key, on) }) })
                        }
                    }
                }
            }

            CalSub.Reminders -> {
                // Waiting for quiet hours comes first: it decides when every reminder below actually arrives.
                SettingsToggleCard(Modifier.waRise(0)) {
                    SettingsToggleRow(Icons.Rounded.Bedtime, stringResource(R.string.cal_respect_quiet), stringResource(R.string.cal_respect_quiet_sub), p.respectQuiet, divider = false) { on ->
                        change({ it.copy(respectQuiet = on) }, JsonObject().apply { addProperty("respect_quiet", on) })
                    }
                }

                Spacer(Modifier.height(14.dp))
                SettingsToggleCard(Modifier.waRise(1)) {
                    ReminderSection(stringResource(R.string.cal_default_reminders), stringResource(R.string.cal_default_reminders_sub)) {
                        ReminderRows(Icons.Rounded.Alarm, ReminderChoices, p.defaultReminders, false) { list ->
                            change({ it.copy(defaultReminders = list) }, JsonObject().apply { add("default_reminders", JsonArray().apply { list.forEach { add(it) } }) })
                        }
                    }
                }

                Spacer(Modifier.height(14.dp))
                SettingsToggleCard(Modifier.waRise(2)) {
                    ReminderSection(stringResource(R.string.cal_all_day_reminders), stringResource(R.string.cal_all_day_reminders_sub)) {
                        ReminderRows(Icons.Rounded.Event, AllDayChoices, p.allDayReminders, true) { list ->
                            change({ it.copy(allDayReminders = list) }, JsonObject().apply { add("all_day_reminders", JsonArray().apply { list.forEach { add(it) } }) })
                        }
                    }
                }
            }

            CalSub.Today -> {
                val saved = p.todaySections?.order.orEmpty().filter { it in DefaultSections }
                val order = saved + (DefaultSections - saved.toSet())
                val hidden = p.todaySections?.hidden.orEmpty()
                val today = CalendarStore.today
                fun saveSections(newOrder: List<String>, newHidden: List<String>) = change(
                    { it.copy(todaySections = CalSectionsDto(newOrder, newHidden)) },
                    JsonObject().apply {
                        add("today_sections", JsonObject().apply {
                            add("order", JsonArray().apply { newOrder.forEach { add(it) } })
                            add("hidden", JsonArray().apply { newHidden.forEach { add(it) } })
                        })
                    },
                )
                Text(stringResource(R.string.cal_today_order_hint), color = Wa.Mut, fontSize = 12.sp, lineHeight = 18.sp, modifier = Modifier.padding(bottom = 8.dp))
                WaCard(Modifier.fillMaxWidth().waRise(0), padding = 6.dp) {
                    order.forEachIndexed { i, key ->
                        val off = key in hidden
                        Row(Modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                            Text("${i + 1}", color = Wa.Soft, fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.width(22.dp))
                            Column(Modifier.weight(1f)) {
                                Text(SectionLabels[key]?.let { stringResource(it) } ?: key, color = if (off) Wa.Soft else Wa.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.Bold)
                                // What is in it today — so a move or a hide is easy to check on the Today page.
                                Text(
                                    if (off) stringResource(R.string.cal_section_hidden) else sectionToday(key, today),
                                    color = if (off) Wa.Red else Wa.Mut, fontSize = 11.5.sp,
                                )
                            }
                            SmallIcon(Icons.Rounded.ArrowUpward, i > 0) { saveSections(order.toMutableList().apply { add(i - 1, removeAt(i)) }, hidden) }
                            SmallIcon(Icons.Rounded.ArrowDownward, i < order.lastIndex) { saveSections(order.toMutableList().apply { add(i + 1, removeAt(i)) }, hidden) }
                            SmallIcon(if (off) Icons.Rounded.VisibilityOff else Icons.Rounded.Visibility, true) { saveSections(order, if (off) hidden - key else hidden + key) }
                        }
                    }
                }
            }
        }
            }
        }
    }
}

/** "3 today", "Nothing today", or "Not shown" — what a kind holds on today's page. */
@Composable
private fun sectionToday(key: String, today: CalTodayDto?): String {
    // Its sources are all off: it isn't on the page whatever its place.
    if (!sectionSourcesOn(key, today?.sources)) return stringResource(R.string.cal_section_source_off)
    val count = when (key) {
        "events" -> today?.events?.size ?: 0
        "tasks" -> today?.tasks?.size ?: 0
        "reminders" -> today?.reminders?.size ?: 0
        "moments" -> today?.moments?.size ?: 0
        else -> 0
    }
    return if (count == 0) stringResource(R.string.cal_section_empty) else pluralStringResource(R.plurals.cal_section_count, count, count)
}

@Composable
private fun SmallIcon(icon: ImageVector, enabled: Boolean, onClick: () -> Unit) {
    Box(Modifier.size(34.dp).clip(CircleShape).clickable(enabled = enabled, onClick = onClick), contentAlignment = Alignment.Center) {
        Icon(icon, null, tint = if (enabled) Wa.Red else Wa.Line, modifier = Modifier.size(19.dp))
    }
}

/** A card of toggle rows — the notification settings' card. */
@Composable
internal fun SettingsToggleCard(modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    Column(
        modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp), 8.dp).padding(horizontal = 8.dp, vertical = 4.dp),
        content = content,
    )
}

/** A settings card section: title and one line of help (no icon beside them), then its own content (the reminder rows). */
@Composable
private fun ReminderSection(title: String, help: String, content: @Composable () -> Unit) {
    Column(Modifier.fillMaxWidth().padding(horizontal = 4.dp).padding(top = 12.dp, bottom = 10.dp)) {
        Text(title, fontSize = 13.5.sp, fontWeight = FontWeight.Bold, color = settingsInk())
        Text(help, fontSize = 11.sp, color = settingsMut(), lineHeight = 15.sp, modifier = Modifier.padding(top = 2.dp))
    }
    Column(Modifier.fillMaxWidth().padding(bottom = 4.dp)) { content() }
}

/** An icon, a title with one line of help, and the settings toggle; a thin line under it unless it is the last. */
@Composable
internal fun SettingsToggleRow(icon: ImageVector, title: String, help: String?, on: Boolean, divider: Boolean, onChange: (Boolean) -> Unit) {
    val dark = settingsNight()
    Row(Modifier.fillMaxWidth().padding(horizontal = 4.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
        PinkIcon(icon)
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(title, fontSize = 13.5.sp, fontWeight = FontWeight.Bold, color = settingsInk())
            help?.let { Text(it, fontSize = 11.sp, color = settingsMut(), lineHeight = 15.sp, modifier = Modifier.padding(top = 2.dp)) }
        }
        RedToggle(on = on, dark = dark) { onChange(!on) }
    }
    if (divider) Box(Modifier.fillMaxWidth().height(1.dp).background(if (dark) AccountDark.line else Color(0xFFF6E4E8)))
}

/** One entry of the settings menu — same row as Account (icon well, 13.5/11 type, 14.dp card). */
@Composable
internal fun CalSettingsRow(icon: ImageVector, title: Int, subtitle: Int, onClick: () -> Unit) {
    val dark = settingsNight()
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    val shape = RoundedCornerShape(14.dp)
    Row(
        Modifier
            .fillMaxWidth()
            .padding(vertical = 4.dp)
            .then(if (dark) Modifier else Modifier.shadow(6.dp, shape, spotColor = settingsAccent().copy(alpha = 0.07f)))
            .clip(shape)
            .background(settingsCard())
            .then(if (dark) Modifier.border(1.dp, AccountDark.line, shape) else Modifier)
            .clickable(onClick = onClick)
            .padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            Modifier
                .size(34.dp)
                .clip(RoundedCornerShape(10.dp))
                .background(if (dark) AccountDark.well else settingsAccent().copy(alpha = 0.14f)),
            contentAlignment = Alignment.Center,
        ) {
            Icon(icon, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
        }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(stringResource(title), fontSize = 13.5.sp, fontWeight = FontWeight.Bold, color = settingsInk())
            Text(stringResource(subtitle), fontSize = 11.sp, color = settingsInk().copy(alpha = 0.65f))
        }
        Icon(
            Icons.Rounded.ChevronRight,
            null,
            tint = if (dark) AccountDark.chevron else settingsAccent().copy(alpha = 0.55f),
            modifier = Modifier.size(16.dp).graphicsLayer { if (rtl) scaleX = -1f },
        )
    }
}

@Composable
internal fun ToggleRow(title: String, on: Boolean, sub: String? = null, onChange: (Boolean) -> Unit) {
    Row(Modifier.fillMaxWidth().padding(horizontal = 10.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(title, color = Wa.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.Bold)
            sub?.let { Text(it, color = Wa.Mut, fontSize = 12.sp) }
        }
        // The same toggle as Account → Settings → Notifications and the wallet.
        com.dorr.app.ui.screens.profile.RedToggle(on = on, dark = com.dorr.app.ui.screens.profile.settingsNight()) { onChange(!on) }
    }
}

@OptIn(ExperimentalLayoutApi::class)
/** The reminder choices as a list: icon well, name, a tick when chosen (up to five), a thin line between rows. */
@Composable
private fun ReminderRows(icon: ImageVector, choices: List<Int>, selected: List<Int>, allDay: Boolean, onChange: (List<Int>) -> Unit) {
    val dark = settingsNight()
    choices.forEachIndexed { index, m ->
        val on = m in selected
        val rowShape = RoundedCornerShape(12.dp)
        Row(
            Modifier
                .fillMaxWidth()
                .clip(rowShape)
                .background(if (on) (if (dark) AccountDark.line else Color(0xFFEDEEF3)) else Color.Transparent)
                .clickable { onChange(if (on) selected - m else (selected + m).take(5)) }
                .padding(horizontal = 4.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            PinkIcon(icon)
            Spacer(Modifier.width(10.dp))
            Text(reminderLabel(m, allDay), fontSize = 13.5.sp, fontWeight = FontWeight.Bold, color = settingsInk(), modifier = Modifier.weight(1f))
            if (on) Icon(Icons.Rounded.CheckCircle, null, tint = Wa.Ink, modifier = Modifier.size(22.dp))
        }
        if (index != choices.lastIndex) Box(Modifier.fillMaxWidth().height(1.dp).background(if (dark) AccountDark.line else Color(0xFFF6E4E8)))
    }
}

// =============================================================================== search (206)

@Composable
internal fun CalendarSearch(onBack: () -> Unit, onOpenMoments: () -> Unit, onEdit: (CalItemDto) -> Unit) {
    var query by remember { mutableStateOf("") }
    var results by remember { mutableStateOf<List<CalItemDto>?>(null) }
    var open by remember { mutableStateOf<CalItemDto?>(null) }
    var loading by remember { mutableStateOf(false) }
    var failed by remember { mutableStateOf(false) }
    var off by remember { mutableStateOf(false) }
    var attempt by remember { mutableIntStateOf(0) }
    LaunchedEffect(query, attempt) {
        val q = query.trim()
        if (q.length < 2) { results = null; loading = false; failed = false; return@LaunchedEffect }
        delay(350)
        loading = true
        failed = false
        try {
            val answer = ApiClient.calendar.search(calAuth(), q, zoneId()).data
            off = answer?.enabled == false
            results = answer?.items.orEmpty()
        } catch (e: kotlinx.coroutines.CancellationException) {
            // A newer word replaced this one: not a failure.
            throw e
        } catch (e: Exception) {
            failed = true
            results = null
        }
        loading = false
    }

    WaPage(title = stringResource(R.string.cal_search), onBack = onBack) {
        DorrTextField(
            query, { query = it.take(100) }, placeholder = stringResource(R.string.cal_search_hint), modifier = Modifier.fillMaxWidth().waRise(0), icon = Icons.Rounded.Search,
            trailing = if (query.isEmpty()) null else ({
                Icon(Icons.Rounded.Close, stringResource(R.string.cal_clear), tint = Wa.Mut, modifier = Modifier.size(20.dp).clip(CircleShape).clickable { query = "" })
            }),
        )
        Text(stringResource(R.string.cal_search_sub), color = Wa.Soft, fontSize = 11.5.sp, modifier = Modifier.padding(top = 6.dp, bottom = 10.dp))
        val r = results
        when {
            off -> CalEmptyState(Icons.Rounded.CalendarMonth, stringResource(R.string.cal_off_title), stringResource(R.string.cal_off_text))
            failed -> CalEmptyState(
                Icons.Rounded.Warning, stringResource(R.string.cal_search_failed), stringResource(R.string.cal_search_failed_sub),
                action = { WaButton(stringResource(R.string.wa_retry), { attempt++ }, style = WaButtonStyle.Ghost, modifier = Modifier.padding(horizontal = 40.dp)) },
            )
            r == null && loading -> repeat(2) { WaSkeleton(Modifier.fillMaxWidth().height(58.dp).padding(bottom = 8.dp), RoundedCornerShape(14.dp)) }
            r == null -> CalEmptyState(Icons.Rounded.Search, stringResource(R.string.cal_search_idle), stringResource(R.string.cal_search_idle_sub))
            r.isEmpty() -> CalEmptyState(Icons.Rounded.SearchOff, stringResource(R.string.cal_search_none), stringResource(R.string.cal_search_none_sub))
            else -> r.forEachIndexed { i, item -> ItemRow(item, Modifier.padding(bottom = 8.dp).waRise(i), showDate = true) { if (item.type != "capsule") open = item } }
        }
    }
    open?.let { item -> ItemSheet(item, onDismiss = { open = null }, onOpenMoments = onOpenMoments, onEdit = onEdit) }
}
