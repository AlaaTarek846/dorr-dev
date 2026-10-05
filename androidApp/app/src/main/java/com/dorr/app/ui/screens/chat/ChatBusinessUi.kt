package com.dorr.app.ui.screens.chat

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Block
import androidx.compose.material.icons.rounded.Bolt
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.ContentCopy
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.EventNote
import androidx.compose.material.icons.rounded.Link
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Share
import androidx.compose.material.icons.rounded.Storefront
import androidx.compose.material.icons.rounded.WavingHand
import androidx.compose.material.icons.rounded.WbTwilight
import androidx.compose.material3.DatePicker
import androidx.compose.material3.DatePickerDialog
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.SelectableDates
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TimePicker
import androidx.compose.material3.rememberDatePickerState
import androidx.compose.material3.rememberTimePickerState
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
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.BusinessDayDto
import com.dorr.app.network.BusinessHoursDto
import com.dorr.app.network.ConversationDto
import com.dorr.app.network.InviteDto
import com.dorr.app.network.QuickReplyDto
import com.dorr.app.network.ScheduledMessageDto
import com.dorr.app.network.apiFailure
import com.google.gson.Gson
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.time.DayOfWeek
import java.time.Instant
import java.time.LocalDate
import java.time.LocalTime
import java.time.ZoneId
import java.time.ZoneOffset
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import java.time.format.TextStyle
import java.util.Locale

// =============================================================================== quick replies cache

/**
 * My quick replies, for the "/" suggestions in every chat. Loaded once, refreshed when the
 * business page changes them. Null = not loaded; empty also when business tools are off for me.
 */
object QuickReplies {
    var list by mutableStateOf<List<QuickReplyDto>?>(null)
        private set

    suspend fun load(force: Boolean = false) {
        if (list != null && !force) return
        list = runCatching { ApiClient.chat.quickReplies(chatAuth()).data }.getOrNull().orEmpty()
    }

    fun set(replies: List<QuickReplyDto>) {
        list = replies.sortedBy { it.shortcut }
    }
}

/**
 * Above the composer while typing "/word": my quick replies that start with it. A tap puts the
 * saved text in the field (to send as it is or change first). With none saved yet, one row offers
 * to make some.
 */
@Composable
fun QuickReplySuggestions(query: String?, onPick: (QuickReplyDto) -> Unit) {
    val host = LocalChat.current
    LaunchedEffect(query != null) { if (query != null) QuickReplies.load() }
    val all = QuickReplies.list
    val matches = if (query == null || all == null) emptyList() else all.filter { it.shortcut.startsWith(query.lowercase()) }.take(6)
    val offerSetup = query != null && query.isEmpty() && all != null && all.isEmpty()

    AnimatedVisibility(matches.isNotEmpty() || offerSetup, enter = expandVertically(spring(dampingRatio = 0.8f)) + fadeIn(), exit = shrinkVertically() + fadeOut()) {
        Column(Modifier.fillMaxWidth().padding(bottom = 6.dp).shadow(10.dp, RoundedCornerShape(20.dp)).clip(RoundedCornerShape(20.dp)).background(Ch.Surface).padding(vertical = 6.dp)) {
            if (offerSetup) {
                Row(Modifier.fillMaxWidth().clickable { host.push(ChRoute.Business) }.padding(horizontal = 14.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Rounded.Bolt, null, tint = Ch.Red, modifier = Modifier.size(22.dp))
                    Spacer(Modifier.width(10.dp))
                    Text(stringResource(R.string.ch_quick_replies_setup), color = Ch.Red, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                }
            }
            matches.forEachIndexed { i, reply ->
                Row(Modifier.fillMaxWidth().chStagger(i).clickable { onPick(reply) }.padding(horizontal = 14.dp, vertical = 9.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        "/${reply.shortcut}", color = Ch.Red, fontWeight = FontWeight.ExtraBold, fontSize = 13.sp,
                        modifier = Modifier.clip(RoundedCornerShape(10.dp)).background(Ch.Red.copy(alpha = 0.08f)).padding(horizontal = 8.dp, vertical = 3.dp),
                    )
                    Spacer(Modifier.width(10.dp))
                    Text(reply.body, color = Ch.Ink, fontSize = 14.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                }
            }
        }
    }
}

// =============================================================================== business page

/**
 * My business tools: opening hours (shown to people who write to me), the welcome message for new
 * customers, the away message when I'm closed, and quick replies typed with "/".
 */
@Composable
fun BusinessPage() {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var loaded by remember { mutableStateOf(false) }
    var unavailable by remember { mutableStateOf(false) }
    var welcomeOn by remember { mutableStateOf(false) }
    var welcome by remember { mutableStateOf("") }
    var awayOn by remember { mutableStateOf(false) }
    var away by remember { mutableStateOf("") }
    var awayMode by remember { mutableStateOf("outside_hours") }
    val days = remember { mutableStateListOf<BusinessDayDto>() }
    var saving by remember { mutableStateOf(false) }
    var editingReply by remember { mutableStateOf<QuickReplyDto?>(null) }
    var newReply by remember { mutableStateOf(false) }
    var pickingTime by remember { mutableStateOf<Pair<Int, Boolean>?>(null) } // day, isFrom
    val saved = stringResource(R.string.ch_saved)
    val networkError = stringResource(R.string.ch_error_network)

    LaunchedEffect(Unit) {
        try {
            val b = ApiClient.chat.business(chatAuth()).data
            if (b != null) {
                welcomeOn = b.profile.welcomeEnabled
                welcome = b.profile.welcomeMessage.orEmpty()
                awayOn = b.profile.awayEnabled
                away = b.profile.awayMessage.orEmpty()
                awayMode = b.profile.awayMode
                days.clear()
                days.addAll(b.profile.hours.ifEmpty { List(7) { BusinessDayDto(false, "09:00", "17:00") } })
                QuickReplies.set(b.quickReplies)
            }
        } catch (e: Exception) {
            unavailable = e.apiFailure().errorCode == "chat_business_unavailable"
            if (!unavailable) host.showToast(e.apiFailure().message ?: networkError)
        }
        loaded = true
    }

    fun save() {
        saving = true
        scope.launch {
            try {
                val body = Gson().toJsonTree(
                    mapOf(
                        "welcome_enabled" to welcomeOn, "welcome_message" to welcome.trim(),
                        "away_enabled" to awayOn, "away_message" to away.trim(), "away_mode" to awayMode,
                        "hours" to days.toList(),
                        // The hours are in my own time zone.
                        "timezone" to ZoneId.systemDefault().id,
                    ),
                ).asJsonObject
                ApiClient.chat.updateBusiness(chatAuth(), body)
                host.showToast(saved)
            } catch (e: Exception) {
                host.showToast(e.apiFailure().message ?: networkError)
            }
            saving = false
        }
    }

    ChPage(stringResource(R.string.ch_business_title), onBack = { host.pop() }) {
        if (!loaded) {
            Box(Modifier.fillMaxSize().padding(14.dp)) { com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().height(360.dp), RoundedCornerShape(22.dp)) }
            return@ChPage
        }
        if (unavailable) {
            ChEmptyState(Icons.Rounded.Storefront, stringResource(R.string.ch_business_title), stringResource(R.string.ch_business_unavailable))
            return@ChPage
        }
        LazyColumn(contentPadding = PaddingValues(14.dp), verticalArrangement = Arrangement.spacedBy(12.dp), modifier = Modifier.fillMaxSize().imePadding()) {
            item { Text(stringResource(R.string.ch_business_intro), color = Ch.Mut, fontSize = 13.sp, modifier = Modifier.padding(horizontal = 6.dp)) }

            // ------------------------------------------------------------ opening hours
            item { SectionTitle(stringResource(R.string.ch_business_hours)) }
            item {
                Card {
                    days.forEachIndexed { i, day ->
                        Row(Modifier.fillMaxWidth().padding(horizontal = 14.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                            Text(dayName(i), color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.sp, modifier = Modifier.width(86.dp))
                            if (day.open) {
                                TimeChip(day.from) { pickingTime = i to true }
                                Text("–", color = Ch.Mut, modifier = Modifier.padding(horizontal = 6.dp))
                                TimeChip(day.to) { pickingTime = i to false }
                            } else {
                                Text(stringResource(R.string.ch_business_closed), color = Ch.Soft, fontSize = 13.sp)
                            }
                            Spacer(Modifier.weight(1f))
                            Switch(day.open, { days[i] = day.copy(open = it) }, colors = SwitchDefaults.colors(checkedTrackColor = Ch.Red, checkedThumbColor = Color.White))
                        }
                    }
                }
            }

            // ------------------------------------------------------------ welcome
            item { SectionTitle(stringResource(R.string.ch_business_welcome)) }
            item {
                Card {
                    ToggleRow(Icons.Rounded.WavingHand, stringResource(R.string.ch_business_welcome_on), welcomeOn, subtitle = stringResource(R.string.ch_business_welcome_sub)) { welcomeOn = it }
                    ChField(welcome, { welcome = it.take(1000) }, stringResource(R.string.ch_business_welcome_hint), singleLine = false, minLines = 2, modifier = Modifier.padding(horizontal = 14.dp).padding(bottom = 14.dp))
                }
            }

            // ------------------------------------------------------------ away
            item { SectionTitle(stringResource(R.string.ch_business_away)) }
            item {
                Card {
                    ToggleRow(Icons.Rounded.WbTwilight, stringResource(R.string.ch_business_away_on), awayOn, subtitle = stringResource(R.string.ch_business_away_sub)) { awayOn = it }
                    Row(Modifier.fillMaxWidth().padding(horizontal = 14.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        ModeChip(stringResource(R.string.ch_business_away_outside), awayMode == "outside_hours", Modifier.weight(1f)) { awayMode = "outside_hours" }
                        ModeChip(stringResource(R.string.ch_business_away_always), awayMode == "always", Modifier.weight(1f)) { awayMode = "always" }
                    }
                    ChField(away, { away = it.take(1000) }, stringResource(R.string.ch_business_away_hint), singleLine = false, minLines = 2, modifier = Modifier.padding(14.dp))
                }
            }
            item { ChPrimaryButton(stringResource(R.string.ch_save), modifier = Modifier.fillMaxWidth(), enabled = !saving) { save() } }

            // ------------------------------------------------------------ quick replies
            item { SectionTitle(stringResource(R.string.ch_quick_replies)) }
            item { Text(stringResource(R.string.ch_quick_replies_sub), color = Ch.Mut, fontSize = 12.5.sp, modifier = Modifier.padding(horizontal = 6.dp)) }
            itemsIndexed(QuickReplies.list.orEmpty(), key = { _, r -> r.id }) { i, reply ->
                Row(
                    Modifier.fillMaxWidth().chStagger(i).clip(RoundedCornerShape(18.dp)).background(Ch.Surface).clickable { editingReply = reply }.padding(12.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text("/${reply.shortcut}", color = Ch.Red, fontWeight = FontWeight.ExtraBold, fontSize = 13.5.sp)
                    Spacer(Modifier.width(10.dp))
                    Text(reply.body, color = Ch.Ink, fontSize = 13.5.sp, maxLines = 2, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                    Icon(Icons.Rounded.Edit, null, tint = Ch.Soft, modifier = Modifier.size(18.dp))
                }
            }
            item {
                Row(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Ch.Red.copy(alpha = 0.08f)).clickable { newReply = true }.padding(14.dp),
                    verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.Center,
                ) {
                    Icon(Icons.Rounded.Add, null, tint = Ch.Red)
                    Spacer(Modifier.width(8.dp))
                    Text(stringResource(R.string.ch_quick_reply_new), color = Ch.Red, fontWeight = FontWeight.ExtraBold)
                }
            }
        }
    }

    pickingTime?.let { (day, isFrom) ->
        val current = days.getOrNull(day) ?: return@let
        TimePickDialog(LocalTime.parse(if (isFrom) current.from else current.to), onDismiss = { pickingTime = null }) { time ->
            val text = time.format(DateTimeFormatter.ofPattern("HH:mm", Locale.US))
            days[day] = if (isFrom) current.copy(from = text) else current.copy(to = text)
            pickingTime = null
        }
    }
    if (newReply || editingReply != null) {
        QuickReplySheet(editingReply, onDismiss = { newReply = false; editingReply = null })
    }
}

@Composable
private fun SectionTitle(text: String) {
    Text(text, color = Ch.Mut, fontWeight = FontWeight.ExtraBold, fontSize = 13.sp, modifier = Modifier.padding(start = 8.dp, top = 4.dp))
}

@Composable
private fun TimeChip(time: String, onClick: () -> Unit) {
    Text(
        time, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 13.5.sp,
        modifier = Modifier.clip(RoundedCornerShape(10.dp)).background(Ch.SurfaceMuted).clickable(onClick = onClick).padding(horizontal = 10.dp, vertical = 5.dp),
    )
}

@Composable
private fun ModeChip(label: String, selected: Boolean, modifier: Modifier, onClick: () -> Unit) {
    val bg by animateColorAsState(if (selected) Ch.Red else Ch.SurfaceMuted, label = "modeChip")
    Text(
        label, color = if (selected) Color.White else Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 13.sp, textAlign = TextAlign.Center,
        modifier = modifier.clip(RoundedCornerShape(14.dp)).background(bg).clickable(onClick = onClick).padding(vertical = 10.dp),
    )
}

/** Sunday first, in the phone's language. */
@Composable
private fun dayName(index: Int): String = DayOfWeek.of(if (index == 0) 7 else index).getDisplayName(TextStyle.FULL, Locale.getDefault())

/** New or change a quick reply: "/shortcut" and its text. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun QuickReplySheet(reply: QuickReplyDto?, onDismiss: () -> Unit) {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var shortcut by remember { mutableStateOf(reply?.shortcut.orEmpty()) }
    var body by remember { mutableStateOf(reply?.body.orEmpty()) }
    var busy by remember { mutableStateOf(false) }
    val networkError = stringResource(R.string.ch_error_network)

    fun done(action: suspend () -> Unit) {
        busy = true
        scope.launch {
            try {
                action()
                QuickReplies.load(force = true)
                onDismiss()
            } catch (e: Exception) {
                host.showToast(e.apiFailure().message ?: networkError)
            }
            busy = false
        }
    }

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().imePadding().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Text(stringResource(if (reply == null) R.string.ch_quick_reply_new else R.string.ch_quick_reply_edit), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(14.dp))
            ChField(shortcut, { shortcut = it.replace(" ", "").removePrefix("/").take(32) }, stringResource(R.string.ch_quick_reply_shortcut), icon = Icons.Rounded.Bolt)
            Spacer(Modifier.height(10.dp))
            ChField(body, { body = it.take(4000) }, stringResource(R.string.ch_quick_reply_body), singleLine = false, minLines = 3)
            Spacer(Modifier.height(16.dp))
            ChPrimaryButton(stringResource(R.string.ch_save), modifier = Modifier.fillMaxWidth(), enabled = !busy && shortcut.isNotBlank() && body.isNotBlank()) {
                done {
                    val fields = mapOf("shortcut" to shortcut, "body" to body.trim())
                    if (reply == null) ApiClient.chat.addQuickReply(chatAuth(), fields) else ApiClient.chat.updateQuickReply(chatAuth(), reply.id, fields)
                }
            }
            if (reply != null) {
                Spacer(Modifier.height(8.dp))
                Row(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).clickable(enabled = !busy) { done { ApiClient.chat.deleteQuickReply(chatAuth(), reply.id) } }.padding(12.dp),
                    horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Rounded.Delete, null, tint = Ch.Danger, modifier = Modifier.size(20.dp))
                    Spacer(Modifier.width(6.dp))
                    Text(stringResource(R.string.ch_delete), color = Ch.Danger, fontWeight = FontWeight.Bold)
                }
            }
        }
    }
}

// =============================================================================== time pickers

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun TimePickDialog(initial: LocalTime, onDismiss: () -> Unit, onPick: (LocalTime) -> Unit) {
    val state = rememberTimePickerState(initial.hour, initial.minute, is24Hour = android.text.format.DateFormat.is24HourFormat(LocalContext.current))
    Dialog(onDismissRequest = onDismiss) {
        Column(Modifier.clip(RoundedCornerShape(28.dp)).background(Ch.Surface).padding(20.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            TimePicker(state)
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) {
                TextButton(onDismiss) { Text(stringResource(R.string.ch_cancel), color = Ch.Mut) }
                TextButton({ onPick(LocalTime.of(state.hour, state.minute)) }) { Text(stringResource(R.string.ch_ok), color = Ch.Red, fontWeight = FontWeight.Bold) }
            }
        }
    }
}

/** Pick a day (today on), then a time; a time already past today is refused. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun DateTimePickDialogs(onDismiss: () -> Unit, onPick: (ZonedDateTime) -> Unit) {
    val zone = ZoneId.systemDefault()
    var date by remember { mutableStateOf<LocalDate?>(null) }
    val today = LocalDate.now(zone)
    val dateState = rememberDatePickerState(
        initialSelectedDateMillis = today.atStartOfDay(ZoneOffset.UTC).toInstant().toEpochMilli(),
        selectableDates = object : SelectableDates {
            override fun isSelectableDate(utcTimeMillis: Long): Boolean {
                val day = Instant.ofEpochMilli(utcTimeMillis).atZone(ZoneOffset.UTC).toLocalDate()
                return !day.isBefore(today) && !day.isAfter(today.plusDays(364))
            }
        },
    )
    val tooSoon = stringResource(R.string.ch_schedule_too_soon)
    val host = LocalChat.current

    if (date == null) {
        DatePickerDialog(
            onDismissRequest = onDismiss,
            confirmButton = {
                TextButton({ dateState.selectedDateMillis?.let { date = Instant.ofEpochMilli(it).atZone(ZoneOffset.UTC).toLocalDate() } }) {
                    Text(stringResource(R.string.ch_next), color = Ch.Red, fontWeight = FontWeight.Bold)
                }
            },
            dismissButton = { TextButton(onDismiss) { Text(stringResource(R.string.ch_cancel), color = Ch.Mut) } },
        ) { DatePicker(dateState) }
    } else {
        TimePickDialog(LocalTime.now(zone).plusHours(1).withMinute(0), onDismiss = onDismiss) { time ->
            val at = ZonedDateTime.of(date, time, zone)
            if (at.isBefore(ZonedDateTime.now(zone).plusMinutes(1))) host.showToast(tooSoon) else onPick(at)
        }
    }
}

// =============================================================================== scheduling

/**
 * "When should it go?" — quick picks (in an hour, tonight, tomorrow morning) or any day and time.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ScheduleSheet(onDismiss: () -> Unit, onPick: (ZonedDateTime) -> Unit) {
    val zone = ZoneId.systemDefault()
    val now = ZonedDateTime.now(zone)
    var custom by remember { mutableStateOf(false) }
    val tonight = now.withHour(21).withMinute(0).withSecond(0).withNano(0).let { if (it.isAfter(now.plusMinutes(5))) it else null }
    val tomorrow = now.plusDays(1).withHour(9).withMinute(0).withSecond(0).withNano(0)
    val options = listOfNotNull(
        stringResource(R.string.ch_schedule_in_hour) to now.plusHours(1).withSecond(0).withNano(0),
        tonight?.let { stringResource(R.string.ch_schedule_tonight) to it },
        stringResource(R.string.ch_schedule_tomorrow) to tomorrow,
    )

    if (custom) {
        DateTimePickDialogs(onDismiss = onDismiss) { onPick(it); onDismiss() }
        return
    }
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Text(stringResource(R.string.ch_schedule_title), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.ch_schedule_sub), color = Ch.Mut, fontSize = 13.sp)
            Spacer(Modifier.height(12.dp))
            options.forEachIndexed { i, (label, at) ->
                ScheduleRow(Icons.Rounded.Schedule, label, scheduleLabel(at), i) { onPick(at); onDismiss() }
            }
            ScheduleRow(Icons.Rounded.EventNote, stringResource(R.string.ch_schedule_pick), null, options.size) { custom = true }
        }
    }
}

@Composable
private fun ScheduleRow(icon: ImageVector, title: String, value: String?, index: Int, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().chStagger(index).clip(RoundedCornerShape(16.dp)).clickable(onClick = onClick).padding(vertical = 12.dp, horizontal = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(36.dp).clip(RoundedCornerShape(11.dp)).background(Ch.Red.copy(alpha = 0.08f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = Ch.Red, modifier = Modifier.size(19.dp))
        }
        Spacer(Modifier.width(12.dp))
        Text(title, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.5.sp, modifier = Modifier.weight(1f))
        value?.let { Text(it, color = Ch.Mut, fontSize = 12.5.sp) }
    }
}

/** "Tue 5 Oct, 21:00" in the phone's language and clock. */
internal fun scheduleLabel(at: ZonedDateTime): String =
    at.withZoneSameInstant(ZoneId.systemDefault()).format(DateTimeFormatter.ofLocalizedDateTime(FormatStyle.MEDIUM, FormatStyle.SHORT).withLocale(Locale.getDefault()))

internal fun scheduleLabel(iso: String): String = runCatching { scheduleLabel(ZonedDateTime.parse(iso)) }.getOrDefault(iso)

/** Above the composer: "2 scheduled messages" — opens the list. */
@Composable
fun ScheduledPill(state: ConversationState, onClick: () -> Unit) {
    val count = state.scheduled.size
    val failed = state.scheduled.any { it.status == "failed" }
    AnimatedVisibility(count > 0, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
        Row(Modifier.fillMaxWidth().padding(bottom = 6.dp), horizontalArrangement = Arrangement.Center) {
            Row(
                Modifier.shadow(4.dp, CircleShape).clip(CircleShape).background(Ch.Surface).clickable(onClick = onClick).padding(horizontal = 14.dp, vertical = 7.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(if (failed) Icons.Rounded.ErrorOutline else Icons.Rounded.Schedule, null, tint = if (failed) Ch.Danger else Ch.Red, modifier = Modifier.size(17.dp))
                Spacer(Modifier.width(6.dp))
                Text(LocalContext.current.resources.getQuantityString(R.plurals.ch_scheduled_count, count, count), color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 13.sp)
            }
        }
    }
}

/** My scheduled messages here: change the time or text, send now, or delete. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ScheduledListSheet(state: ConversationState, onDismiss: () -> Unit) {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var rescheduling by remember { mutableStateOf<ScheduledMessageDto?>(null) }
    var editing by remember { mutableStateOf<ScheduledMessageDto?>(null) }
    val networkError = stringResource(R.string.ch_error_network)

    fun act(action: suspend () -> Unit) = scope.launch {
        try {
            action()
        } catch (e: Exception) {
            host.showToast(e.apiFailure().message ?: networkError)
        }
        state.refreshScheduled()
        if (state.scheduled.isEmpty()) onDismiss()
    }

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 26.dp).verticalScroll(rememberScrollState())) {
            Text(stringResource(R.string.ch_scheduled_title), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(12.dp))
            state.scheduled.forEachIndexed { i, item ->
                Column(Modifier.fillMaxWidth().chStagger(i).padding(vertical = 6.dp).clip(RoundedCornerShape(18.dp)).background(Ch.SurfaceMuted).padding(14.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        val failed = item.status == "failed"
                        Icon(if (failed) Icons.Rounded.ErrorOutline else Icons.Rounded.Schedule, null, tint = if (failed) Ch.Danger else Ch.Red, modifier = Modifier.size(16.dp))
                        Spacer(Modifier.width(6.dp))
                        Text(
                            if (failed) stringResource(R.string.ch_scheduled_failed) else scheduleLabel(item.sendAt),
                            color = if (failed) Ch.Danger else Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold,
                        )
                    }
                    Spacer(Modifier.height(6.dp))
                    Text(item.body, color = Ch.Ink, fontSize = 14.5.sp, maxLines = 4, overflow = TextOverflow.Ellipsis)
                    Spacer(Modifier.height(10.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        MiniAction(Icons.AutoMirrored.Rounded.Send, stringResource(R.string.ch_send_now)) {
                            act { ApiClient.chat.sendScheduledNow(chatAuth(), item.id).data?.let { /* arrives by real time too */ } }
                        }
                        MiniAction(Icons.Rounded.Schedule, stringResource(R.string.ch_reschedule)) { rescheduling = item }
                        MiniAction(Icons.Rounded.Edit, stringResource(R.string.ch_edit)) { editing = item }
                        MiniAction(Icons.Rounded.Delete, stringResource(R.string.ch_delete), danger = true) { act { ApiClient.chat.deleteScheduled(chatAuth(), item.id) } }
                    }
                }
            }
        }
    }

    rescheduling?.let { item ->
        ScheduleSheet(onDismiss = { rescheduling = null }) { at ->
            act { ApiClient.chat.updateScheduled(chatAuth(), item.id, mapOf("send_at" to at.format(DateTimeFormatter.ISO_OFFSET_DATE_TIME))) }
        }
    }
    editing?.let { item ->
        TextInputSheet(stringResource(R.string.ch_edit), initial = item.body, action = stringResource(R.string.ch_save), onDismiss = { editing = null }) { body ->
            act { ApiClient.chat.updateScheduled(chatAuth(), item.id, mapOf("body" to body)) }
        }
    }
}

@Composable
private fun MiniAction(icon: ImageVector, label: String, danger: Boolean = false, onClick: () -> Unit) {
    val tint = if (danger) Ch.Danger else Ch.Red
    Row(
        Modifier.clip(RoundedCornerShape(12.dp)).background(tint.copy(alpha = 0.08f)).clickable(onClick = onClick).padding(horizontal = 9.dp, vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = tint, modifier = Modifier.size(15.dp))
        Spacer(Modifier.width(4.dp))
        Text(label, color = tint, fontSize = 11.5.sp, fontWeight = FontWeight.Bold, maxLines = 1)
    }
}

// =============================================================================== group moderation

/** Slow mode steps (the server's): off, 10 s, 30 s, 1 min, 5 min, 15 min, 1 h. */
internal val SlowModeSteps = listOf(0, 10, 30, 60, 300, 900, 3600)

@Composable
internal fun slowModeLabel(seconds: Int): String = when {
    seconds <= 0 -> stringResource(R.string.ch_off)
    seconds < 60 -> stringResource(R.string.ch_seconds_n, seconds)
    seconds < 3600 -> stringResource(R.string.ch_minutes_n, seconds / 60)
    else -> stringResource(R.string.ch_hours_n, seconds / 3600)
}

/** The words a member's message may not contain (admins only): add, remove, save. */
@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
fun BannedWordsSheet(conversation: ConversationDto, onDismiss: () -> Unit, onSaved: (ConversationDto) -> Unit) {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    val words = remember { mutableStateListOf<String>().apply { addAll(conversation.group?.bannedWords.orEmpty()) } }
    var input by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    val networkError = stringResource(R.string.ch_error_network)

    fun add() {
        val word = input.trim().replace(Regex("\\s+"), " ")
        if (word.isNotEmpty() && words.none { it.equals(word, ignoreCase = true) }) words.add(word)
        input = ""
    }

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().imePadding().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Text(stringResource(R.string.ch_banned_words), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.ch_banned_words_sub), color = Ch.Mut, fontSize = 13.sp)
            Spacer(Modifier.height(12.dp))
            ChField(input, { input = it.take(50) }, stringResource(R.string.ch_banned_words_hint), icon = Icons.Rounded.Block, trailing = {
                Box(Modifier.size(34.dp).clip(CircleShape).background(Ch.Red).clickable { add() }, contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.Add, null, tint = Color.White, modifier = Modifier.size(20.dp))
                }
            })
            Spacer(Modifier.height(12.dp))
            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                words.forEach { word ->
                    Row(
                        Modifier.clip(CircleShape).background(Ch.Danger.copy(alpha = 0.08f)).padding(start = 12.dp, end = 4.dp, top = 4.dp, bottom = 4.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(word, color = Ch.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold)
                        Box(Modifier.size(26.dp).clip(CircleShape).clickable { words.remove(word) }, contentAlignment = Alignment.Center) {
                            Icon(Icons.Rounded.Close, null, tint = Ch.Mut, modifier = Modifier.size(15.dp))
                        }
                    }
                }
            }
            if (words.isEmpty()) Text(stringResource(R.string.ch_banned_words_empty), color = Ch.Soft, fontSize = 13.sp)
            Spacer(Modifier.height(16.dp))
            ChPrimaryButton(stringResource(R.string.ch_save), modifier = Modifier.fillMaxWidth(), enabled = !busy) {
                if (input.isNotBlank()) add()
                busy = true
                scope.launch {
                    try {
                        ApiClient.chat.groupSettings(chatAuth(), conversation.id, mapOf("banned_words" to words.toList())).data?.let(onSaved)
                        onDismiss()
                    } catch (e: Exception) {
                        host.showToast(e.apiFailure().message ?: networkError)
                    }
                    busy = false
                }
            }
        }
    }
}

/**
 * The group's invite link: copy or share it, or make a new one (the old stops working) that
 * lasts an hour, a day, a week, a month — or forever.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun InviteLinkSheet(conversation: ConversationDto, onDismiss: () -> Unit) {
    val host = LocalChat.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var invite by remember { mutableStateOf<InviteDto?>(null) }
    var choosing by remember { mutableStateOf(false) }
    val copied = stringResource(R.string.ch_link_copied)
    val networkError = stringResource(R.string.ch_error_network)
    LaunchedEffect(Unit) { invite = runCatching { ApiClient.chat.invite(chatAuth(), conversation.id).data }.getOrNull() }

    fun reset(hours: Int?) = scope.launch {
        try {
            invite = ApiClient.chat.resetInvite(chatAuth(), conversation.id, hours?.let { mapOf("expires_in_hours" to it) } ?: emptyMap()).data ?: invite
        } catch (e: Exception) {
            host.showToast(e.apiFailure().message ?: networkError)
        }
    }

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Text(stringResource(R.string.ch_invite_link), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(12.dp))
            Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Ch.SurfaceMuted).padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.Link, null, tint = Ch.Red, modifier = Modifier.size(22.dp))
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(invite?.link ?: "…", color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 13.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    Text(
                        invite?.expiresAt?.let { stringResource(R.string.ch_invite_expires, scheduleLabel(it)) } ?: stringResource(R.string.ch_invite_never_expires),
                        color = Ch.Mut, fontSize = 12.sp,
                    )
                }
            }
            Spacer(Modifier.height(10.dp))
            val link = invite?.link
            ScheduleRow(Icons.Rounded.ContentCopy, stringResource(R.string.ch_copy_link), null, 0) {
                link ?: return@ScheduleRow
                (context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager).setPrimaryClip(ClipData.newPlainText("invite", link))
                host.showToast(copied)
            }
            ScheduleRow(Icons.Rounded.Share, stringResource(R.string.ch_share_link), null, 1) {
                link ?: return@ScheduleRow
                context.startActivity(Intent.createChooser(Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(Intent.EXTRA_TEXT, link), null))
            }
            ScheduleRow(Icons.Rounded.Refresh, stringResource(R.string.ch_invite_new), null, 2) { choosing = true }
        }
    }

    if (choosing) ChoiceSheet(
        stringResource(R.string.ch_invite_new),
        listOf(
            stringResource(R.string.ch_invite_1h) to 1, stringResource(R.string.ch_invite_1d) to 24, stringResource(R.string.ch_invite_7d) to 168,
            stringResource(R.string.ch_invite_30d) to 720, stringResource(R.string.ch_invite_forever) to null,
        ).map { (label, hours) -> label to { reset(hours); Unit } },
        subtitle = stringResource(R.string.ch_invite_new_sub),
        onDismiss = { choosing = false },
    )
}

// =============================================================================== business hours for customers

/** Under a business's name: "Open now" / "Closed · opens 09:00". */
@Composable
internal fun businessStatus(business: BusinessHoursDto): String {
    if (business.openNow) return stringResource(R.string.ch_business_open_now)
    val next = nextOpening(business) ?: return stringResource(R.string.ch_business_closed_now)
    return stringResource(R.string.ch_business_opens_at, next)
}

/** When it opens next, in my own time ("09:00" today/tomorrow, or "Sun 09:00"). */
private fun nextOpening(business: BusinessHoursDto): String? {
    val zone = runCatching { ZoneId.of(business.timezone) }.getOrDefault(ZoneOffset.UTC)
    val now = ZonedDateTime.now(zone)
    for (offset in 0..7) {
        val day = now.toLocalDate().plusDays(offset.toLong())
        val hours = business.hours.getOrNull(day.dayOfWeek.value % 7) ?: continue
        if (!hours.open) continue
        val opens = ZonedDateTime.of(day, runCatching { LocalTime.parse(hours.from) }.getOrDefault(LocalTime.MIDNIGHT), zone)
        if (opens.isAfter(now)) {
            val mine = opens.withZoneSameInstant(ZoneId.systemDefault())
            val time = mine.format(DateTimeFormatter.ofLocalizedTime(FormatStyle.SHORT).withLocale(Locale.getDefault()))
            return if (offset <= 1) time else mine.dayOfWeek.getDisplayName(TextStyle.SHORT, Locale.getDefault()) + " " + time
        }
    }
    return null
}
