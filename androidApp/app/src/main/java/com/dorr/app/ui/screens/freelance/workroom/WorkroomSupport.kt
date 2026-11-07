package com.dorr.app.ui.screens.freelance.workroom

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Archive
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material.icons.rounded.Image
import androidx.compose.material.icons.rounded.InsertDriveFile
import androidx.compose.material.icons.rounded.Movie
import androidx.compose.material.icons.rounded.MusicNote
import androidx.compose.material.icons.rounded.PictureAsPdf
import androidx.compose.material3.DatePicker
import androidx.compose.material3.DatePickerDefaults
import androidx.compose.material3.DatePickerDialog
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.SelectableDates
import androidx.compose.material3.Text
import androidx.compose.material3.rememberDatePickerState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.MutableState
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import kotlinx.coroutines.CancellationException
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
import com.dorr.app.R
import com.dorr.app.chat.ChatRealtime
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ApiEnvelope
import com.dorr.app.network.WorkPartDto
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.locale.rememberAppLocaleFrame
import com.dorr.app.ui.screens.AccountDark
import com.dorr.app.ui.screens.freelance.Chip
import com.dorr.app.ui.screens.freelance.PagedRows
import com.dorr.app.ui.screens.freelance.auth
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsNight
import java.time.Instant
import java.time.LocalDate
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.ZoneOffset
import java.time.format.DateTimeFormatter
import java.util.Locale

/** How many rows a page of the Workroom asks for. */
internal const val WORK_PAGE = 30

// ---------------------------------------------------------------- live

/**
 * "Something changed" counters of one project's Workroom, bumped when its private channel says so (`freelance.workroom`: a task, a file, a
 * note or a decision). A screen reads its list again when its counter moves — one request per event, nothing on a timer.
 */
@Stable
internal class WorkroomLive {
    var tasks by mutableIntStateOf(0)
    var files by mutableIntStateOf(0)
    var comments by mutableIntStateOf(0)
    var decisions by mutableIntStateOf(0)

    /** Any step at all: the timeline reads again. */
    var timeline by mutableIntStateOf(0)
}

/** Listens to the project's Workroom channel while the screen is on, and counts what arrives. */
@Composable
internal fun rememberWorkroomLive(project: String): WorkroomLive {
    val live = remember(project) { WorkroomLive() }
    DisposableEffect(project) {
        ChatRealtime.watchWorkroom(project)
        onDispose { ChatRealtime.unwatchWorkroom(project) }
    }
    LaunchedEffect(project) {
        ChatRealtime.events.collect { event ->
            if (event.name != ChatRealtime.WORKROOM_EVENT) return@collect
            val of = event.data.get("project")?.takeIf { it.isJsonPrimitive }?.asString
            if (of != project) return@collect
            when (event.data.get("kind")?.takeIf { it.isJsonPrimitive }?.asString) {
                "task" -> live.tasks++
                "file" -> live.files++
                // A note changes the count on its file's card as well as the file's own page.
                "comment" -> { live.comments++; live.files++ }
                "decision" -> live.decisions++
            }
            live.timeline++
        }
    }
    return live
}

// ---------------------------------------------------------------- the agreement's parts and people

/** What the forms need from the agreement: its parts (to tie a task, a file or a decision to one) and the freelancer's name. */
@Stable
internal class WorkroomPeople {
    var parts by mutableStateOf<List<WorkPartDto>>(emptyList())
    var freelancer by mutableStateOf("")
}

/** Reads the agreement once when the screen opens (and when the connection returns); a failed read just leaves the lists empty. */
@Composable
internal fun rememberWorkroomPeople(project: String, reconnect: Int): WorkroomPeople {
    val people = remember(project) { WorkroomPeople() }
    LaunchedEffect(project, reconnect) {
        try {
            ApiClient.freelance.agreement(auth(), project).data?.let { a ->
                people.parts = a.milestones.filter { it.status != "cancelled" }.map { WorkPartDto(it.uuid, it.title, it.position) }
                people.freelancer = a.freelancer?.name.orEmpty()
            }
        } catch (e: CancellationException) {
            throw e
        } catch (_: Exception) {
        }
    }
    return people
}

// ---------------------------------------------------------------- paging

/**
 * A page-by-page list for an answer that is an object (the rows, the numbers for the chips, what I `can` do): the rows go to the pager and
 * the first page's whole answer to [meta]. The envelope is rebuilt around the rows so the app's own [PagedRows] does the paging.
 */
internal fun <R, T> workPager(meta: MutableState<R?>, rows: (R) -> List<T>, key: (T) -> String, fetch: suspend (page: Int) -> ApiEnvelope<R>): PagedRows<T> =
    PagedRows(key) { page ->
        val answer = fetch(page)
        if (page == 1) meta.value = answer.data
        ApiEnvelope(answer.success, answer.status, answer.code, answer.message, answer.data?.let(rows), answer.pagination)
    }

// ---------------------------------------------------------------- dates and sizes

private val DAY = DateTimeFormatter.ofPattern("dd/MM/yyyy", Locale.US)
private val CLOCK = DateTimeFormatter.ofPattern("HH:mm", Locale.US)

/** A server day ("2026-11-20", or a full time) as "20/11/2026"; empty when it cannot be read. */
internal fun wrDay(date: String?): String =
    date?.takeIf { it.length >= 10 }?.let { runCatching { LocalDate.parse(it.take(10)).format(DAY) }.getOrNull() }.orEmpty()

private fun local(iso: String?): java.time.ZonedDateTime? =
    iso?.let { runCatching { OffsetDateTime.parse(it).atZoneSameInstant(ZoneId.systemDefault()) }.getOrNull() }

/** The day a server time falls on, in the phone's zone ("27/10/2026"), to group a timeline by. */
internal fun wrLocalDay(iso: String?): String = local(iso)?.format(DAY).orEmpty()

internal fun wrLocalTime(iso: String?): String = local(iso)?.format(CLOCK).orEmpty()

internal fun wrLocalDateTime(iso: String?): String = local(iso)?.let { it.format(DAY) + " " + it.format(CLOCK) }.orEmpty()

/** "1.4 MB" · "230 KB". */
internal fun wrSize(bytes: Long): String = when {
    bytes >= 1_048_576L -> String.format(Locale.US, "%.1f MB", bytes / 1_048_576.0)
    bytes >= 1024L -> "${bytes / 1024} KB"
    else -> "$bytes B"
}

internal fun fileIcon(mime: String, name: String): ImageVector {
    val ext = name.substringAfterLast('.', "").lowercase()
    return when {
        mime.startsWith("image/") -> Icons.Rounded.Image
        mime == "application/pdf" || ext == "pdf" -> Icons.Rounded.PictureAsPdf
        mime.startsWith("video/") -> Icons.Rounded.Movie
        mime.startsWith("audio/") -> Icons.Rounded.MusicNote
        ext in setOf("zip", "rar", "7z", "gz", "tar") -> Icons.Rounded.Archive
        ext in setOf("doc", "docx", "txt", "rtf", "odt", "xls", "xlsx", "ppt", "pptx", "csv") -> Icons.Rounded.Description
        else -> Icons.Rounded.InsertDriveFile
    }
}

// ---------------------------------------------------------------- tones and words

internal val WrGray = Color(0xFF6B7280)
internal val WrBlue = Color(0xFF0EA5E9)
internal val WrGreen = Color(0xFF16A34A)
internal val WrAmber = Color(0xFFD97706)
internal val WrRed = Color(0xFFDC2626)
internal val WrViolet = Color(0xFF7C3AED)

internal fun taskTone(status: String): Color = when (status) {
    "doing" -> WrBlue
    "blocked" -> WrRed
    "done" -> WrGreen
    else -> WrGray
}

@Composable
internal fun taskLabel(status: String): String = stringResource(
    when (status) {
        "doing" -> R.string.wr_task_doing
        "blocked" -> R.string.wr_task_blocked
        "done" -> R.string.wr_task_done
        else -> R.string.wr_task_todo
    },
)

internal fun decisionTone(status: String): Color = when (status) {
    "agreed" -> WrGreen
    "declined" -> WrRed
    "superseded" -> WrGray
    else -> WrAmber
}

@Composable
internal fun decisionLabel(status: String): String = stringResource(
    when (status) {
        "agreed" -> R.string.wr_dec_agreed
        "declined" -> R.string.wr_dec_declined
        "superseded" -> R.string.wr_dec_superseded
        else -> R.string.wr_dec_proposed
    },
)

/** Who a side is, said to the client: «You» for the client's own side, the freelancer's name for the other. */
@Composable
internal fun sideName(side: String, freelancer: String): String =
    if (side == "client") stringResource(R.string.wr_you) else freelancer.ifBlank { stringResource(R.string.wr_freelancer) }

// ---------------------------------------------------------------- small pieces

/** A small tag: an icon and a few words on a soft tint (a part, a day, who it is for). */
@Composable
internal fun WrTag(text: String, icon: ImageVector? = null, color: Color = settingsMut(), modifier: Modifier = Modifier) {
    Row(
        modifier.clip(RoundedCornerShape(999.dp)).background(color.copy(alpha = if (settingsNight()) 0.20f else 0.10f)).padding(horizontal = 9.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(5.dp),
    ) {
        if (icon != null) Icon(icon, null, tint = color, modifier = Modifier.size(13.dp))
        Text(text, color = color, fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = androidx.compose.ui.text.style.TextOverflow.Ellipsis)
    }
}

/** What a list says when it has no rows: an icon on a soft disc, a title and a line. */
@Composable
internal fun WrEmpty(icon: ImageVector, title: String, body: String, modifier: Modifier = Modifier) {
    Column(modifier.fillMaxWidth().padding(vertical = 36.dp, horizontal = 24.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Box(Modifier.size(64.dp).clip(androidx.compose.foundation.shape.CircleShape).background(settingsAccent().copy(alpha = 0.10f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = settingsAccent(), modifier = Modifier.size(30.dp))
        }
        Text(title, color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center)
        Text(body, color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp, textAlign = TextAlign.Center)
    }
}

/** A row of chips that scrolls sideways (the filters of a list). */
@Composable
internal fun WrChipRow(content: @Composable () -> Unit) {
    Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) { content() }
}

/** The parts of the agreement to tie something to: «No part» first, then each part by its number. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun PartPicker(parts: List<WorkPartDto>, selected: String?, onPick: (String?) -> Unit) {
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Chip(stringResource(R.string.wr_no_part), selected == null, fit = true) { onPick(null) }
        parts.forEach { p -> Chip("${p.position}. ${p.title}", selected == p.uuid, fit = true) { onPick(p.uuid) } }
    }
}

// ---------------------------------------------------------------- a day to pick

/** A read-only field that opens the calendar; [value] is "yyyy-MM-dd" (empty: none). Clearing is the cross in the field. */
@Composable
internal fun WrDateField(label: String, value: String, onChange: (String) -> Unit, modifier: Modifier = Modifier, min: LocalDate? = LocalDate.now()) {
    var open by remember { mutableStateOf(false) }
    DorrTextField(
        value = wrDay(value), onValueChange = {}, label = label, placeholder = stringResource(R.string.wr_no_day), icon = Icons.Rounded.Event,
        onClick = { open = true }, modifier = modifier.fillMaxWidth(),
        trailing = {
            if (value.isNotBlank()) Icon(
                Icons.Rounded.Close, stringResource(R.string.fl_remove), tint = settingsMut(),
                modifier = Modifier.size(28.dp).clip(RoundedCornerShape(50)).clickable { onChange("") }.padding(5.dp),
            )
        },
    )
    if (open) WrDatePicker(initial = runCatching { LocalDate.parse(value.take(10)) }.getOrNull(), min = min, onDismiss = { open = false }) { onChange(it.toString()); open = false }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun WrDatePicker(initial: LocalDate?, min: LocalDate?, onDismiss: () -> Unit, onPick: (LocalDate) -> Unit) {
    val locale = rememberAppLocaleFrame()
    val start = initial ?: min ?: LocalDate.now()
    val state = rememberDatePickerState(
        initialSelectedDateMillis = start.atStartOfDay(ZoneOffset.UTC).toInstant().toEpochMilli(),
        selectableDates = object : SelectableDates {
            override fun isSelectableDate(utcTimeMillis: Long): Boolean {
                val day = Instant.ofEpochMilli(utcTimeMillis).atZone(ZoneOffset.UTC).toLocalDate()
                // A day already past may stay on a task that has it; a new one starts from the minimum.
                return (min == null || !day.isBefore(min) || day == initial) && !day.isAfter(LocalDate.now().plusYears(3))
            }
        },
    )
    val accent = settingsAccent()
    DatePickerDialog(
        onDismissRequest = onDismiss,
        colors = DatePickerDefaults.colors(containerColor = if (settingsNight()) AccountDark.card else Color.White, selectedDayContainerColor = accent),
        confirmButton = {
            locale.Provide {
                Text(
                    stringResource(R.string.wr_ok), color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 14.sp,
                    modifier = Modifier.padding(end = 6.dp, bottom = 4.dp).clip(RoundedCornerShape(12.dp)).background(accent)
                        .clickable { state.selectedDateMillis?.let { onPick(Instant.ofEpochMilli(it).atZone(ZoneOffset.UTC).toLocalDate()) } }
                        .padding(horizontal = 18.dp, vertical = 10.dp),
                )
            }
        },
        dismissButton = {
            locale.Provide {
                Text(
                    stringResource(R.string.common_cancel), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 14.sp,
                    modifier = Modifier.padding(end = 6.dp, bottom = 4.dp).clip(RoundedCornerShape(12.dp)).clickable(onClick = onDismiss).padding(horizontal = 14.dp, vertical = 10.dp),
                )
            }
        },
    ) {
        DatePicker(state = state, showModeToggle = false)
    }
}
