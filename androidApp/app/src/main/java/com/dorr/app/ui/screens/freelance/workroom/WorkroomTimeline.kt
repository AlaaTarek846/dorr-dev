package com.dorr.app.ui.screens.freelance.workroom

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AttachFile
import androidx.compose.material.icons.rounded.Comment
import androidx.compose.material.icons.rounded.FiberManualRecord
import androidx.compose.material.icons.rounded.Flag
import androidx.compose.material.icons.rounded.Gavel
import androidx.compose.material.icons.rounded.Handshake
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Report
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.SwapHoriz
import androidx.compose.material.icons.rounded.TaskAlt
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.WorkEventDto
import com.dorr.app.network.WorkTimelineDto
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.PullToRefreshHost
import com.dorr.app.ui.screens.freelance.Chip
import com.dorr.app.ui.screens.freelance.FlCorners
import com.dorr.app.ui.screens.freelance.FlRetry
import com.dorr.app.ui.screens.freelance.FlSkeletonList
import com.dorr.app.ui.screens.freelance.NearEnd
import com.dorr.app.ui.screens.freelance.PagedSpinner
import com.dorr.app.ui.screens.freelance.auth
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.screens.profile.settingsSurface
import kotlinx.coroutines.launch

/** The kinds a step can be filtered by (`null`: all). */
private val KINDS = listOf(null, "task", "file", "comment", "decision", "milestone", "change")

/**
 * The Workroom's timeline: what happened on the project, newest first and grouped by day — tasks, files, notes, decisions and the steps of
 * the agreement (parts, changes, disputes). The text of a step is ready (in my language) and never carries what either side wrote or any
 * amount. A step about a file opens it; one about a task or a decision opens that part of the Workroom. Read when it opens, on a live
 * event, on a pull and when the connection returns.
 */
@Composable
internal fun TimelineTab(
    project: String,
    freelancer: String,
    live: WorkroomLive,
    onOpenFile: (String) -> Unit,
    onOpenTab: (Int) -> Unit,
) {
    val generic = stringResource(R.string.fl_error_generic)
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    var kind by rememberSaveable { mutableStateOf<String?>(null) }
    var mineOnly by rememberSaveable { mutableStateOf(false) }

    val meta = remember(project) { mutableStateOf<WorkTimelineDto?>(null) }
    val pager = remember(project, kind, mineOnly) {
        val k = kind
        val m = if (mineOnly) 1 else null
        workPager(meta, { it.events }, { eventId(it) }) { page ->
            ApiClient.freelanceWorkroom.timeline(auth(), project, kind = k, mine = m, perPage = WORK_PAGE, page = page)
        }
    }
    LaunchedEffect(pager, reconnect, live.timeline) { pager.reload(generic) }
    val listState = rememberLazyListState()
    listState.NearEnd(pager.rows.size) { scope.launch { pager.loadMore(generic) } }

    // Grouped by the day (in the phone's zone), the order of the answer kept.
    val days = pager.rows.groupBy { wrLocalDay(it.at) }

    PullToRefreshHost(Modifier.fillMaxSize()) {
        LazyColumn(
            Modifier.fillMaxSize(), state = listState,
            contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 4.dp, bottom = 28.dp), verticalArrangement = Arrangement.spacedBy(0.dp),
        ) {
            item(key = "filters") {
                Column(Modifier.padding(bottom = 10.dp)) {
                    WrChipRow {
                        KINDS.forEach { k ->
                            Chip(kindLabel(k), kind == k, kindIcon(k ?: "")) { kind = k }
                        }
                        Chip(stringResource(R.string.wr_task_mine), mineOnly, Icons.Rounded.Person) { mineOnly = !mineOnly }
                    }
                }
            }
            when {
                !pager.loaded && pager.error == null -> item(key = "loading") { FlSkeletonList(4, 64.dp) }
                pager.rows.isEmpty() && pager.error != null -> item(key = "failed") { FlRetry(pager.error ?: generic) }
                pager.rows.isEmpty() -> item(key = "empty") {
                    WrEmpty(Icons.Rounded.History, stringResource(R.string.wr_time_none), stringResource(R.string.wr_time_none_body))
                }
            }
            days.forEach { (day, events) ->
                item(key = "day-$day") { DayHeader(day) }
                events.forEachIndexed { i, e ->
                    item(key = "e-" + eventId(e)) {
                        EventRow(e, freelancer, last = i == events.lastIndex) {
                            val subject = e.subject
                            val target = if (subject?.type == "comment") subject.on else subject
                            when (target?.type) {
                                "file" -> target.uuid?.let(onOpenFile)
                                "task" -> onOpenTab(0)
                                "decision" -> onOpenTab(2)
                                else -> Unit
                            }
                        }
                    }
                }
            }
            item(key = "more") { PagedSpinner(pager) }
        }
    }
}

private fun eventId(e: WorkEventDto): String = e.key.ifBlank { listOf(e.kind, e.action, e.at, e.subject?.uuid).joinToString("|") }

private fun kindIcon(kind: String): ImageVector = when (kind) {
    "task" -> Icons.Rounded.TaskAlt
    "file" -> Icons.Rounded.AttachFile
    "comment" -> Icons.Rounded.Comment
    "decision" -> Icons.Rounded.Gavel
    "milestone" -> Icons.Rounded.Flag
    "change" -> Icons.Rounded.SwapHoriz
    "dispute" -> Icons.Rounded.Report
    "review" -> Icons.Rounded.Star
    "rights" -> Icons.Rounded.Lock
    "agreement" -> Icons.Rounded.Handshake
    "" -> Icons.Rounded.History
    else -> Icons.Rounded.FiberManualRecord
}

private fun kindTone(kind: String): Color = when (kind) {
    "task" -> WrBlue
    "file" -> WrViolet
    "comment" -> Color(0xFF14B8A6)
    "decision" -> WrAmber
    "milestone" -> WrGreen
    "change" -> Color(0xFF6366F1)
    "dispute" -> WrRed
    "review" -> WrAmber
    else -> WrGray
}

@Composable
private fun kindLabel(kind: String?): String = stringResource(
    when (kind) {
        "task" -> R.string.wr_time_tasks
        "file" -> R.string.wr_time_files
        "comment" -> R.string.wr_time_notes
        "decision" -> R.string.wr_time_decisions
        "milestone" -> R.string.wr_time_parts
        "change" -> R.string.wr_time_changes
        else -> R.string.wr_all
    },
)

@Composable
private fun DayHeader(day: String) {
    Text(
        day, color = settingsMut(), fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold,
        modifier = Modifier.fillMaxWidth().padding(top = 12.dp, bottom = 8.dp),
    )
}

/** One step on the rail: its kind's icon on a disc, the line down to the next step, the text, who did it and at what time. */
@Composable
private fun EventRow(e: WorkEventDto, freelancer: String, last: Boolean, onClick: () -> Unit) {
    val tone = kindTone(e.kind)
    val by = e.by
    val subject = e.subject
    val who = when {
        by != null && !by.name.isNullOrBlank() -> by.name
        e.mine -> stringResource(R.string.wr_you)
        e.actor == "freelancer" -> freelancer.ifBlank { stringResource(R.string.wr_freelancer) }
        else -> ""
    }
    val opens = subject != null && (subject.type == "file" || subject.type == "task" || subject.type == "decision" || (subject.type == "comment" && subject.on?.type == "file"))
    Row(Modifier.fillMaxWidth().height(IntrinsicSize.Min), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        Column(Modifier.fillMaxHeight().width(34.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Box(Modifier.size(34.dp).clip(CircleShape).background(tone.copy(alpha = if (settingsNight()) 0.25f else 0.14f)), contentAlignment = Alignment.Center) {
                Icon(kindIcon(e.kind), null, tint = tone, modifier = Modifier.size(18.dp))
            }
            if (!last) Box(Modifier.width(2.dp).weight(1f).background(settingsMut().copy(alpha = 0.22f)))
        }
        Column(
            Modifier.weight(1f).padding(bottom = if (last) 4.dp else 14.dp).clip(FlCorners.tile)
                .then(if (opens) Modifier.clickable(role = Role.Button, onClick = onClick) else Modifier),
            verticalArrangement = Arrangement.spacedBy(3.dp),
        ) {
            Text(e.label, color = settingsInk(), fontSize = 13.5.sp, lineHeight = 20.sp, fontWeight = FontWeight.SemiBold)
            Text(
                listOf(who, wrLocalTime(e.at)).filter { it.isNotBlank() }.joinToString("  ·  "),
                color = settingsMut(), fontSize = 11.5.sp,
            )
        }
    }
}
