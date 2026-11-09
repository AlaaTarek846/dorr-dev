package com.dorr.app.ui.screens.freelance.workroom

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.AttachFile
import androidx.compose.material.icons.rounded.Forum
import androidx.compose.material.icons.rounded.Gavel
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.TaskAlt
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.saveable.rememberSaveableStateHolder
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.apiFailure
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.screens.freelance.FlCorners
import com.dorr.app.ui.screens.freelance.FlCountBadge
import com.dorr.app.ui.screens.freelance.FlNotice
import com.dorr.app.ui.screens.freelance.FlPageHeader
import com.dorr.app.ui.screens.freelance.FlPartTabs
import com.dorr.app.ui.screens.freelance.FlTab
import com.dorr.app.ui.screens.freelance.auth
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsSurface
import kotlinx.coroutines.CancellationException

/** The Workroom's four parts, in the order of its tabs; a notice names one of them in its page key («workroom:<project>:files»). */
private val TABS = listOf("tasks", "files", "decisions", "timeline")

internal fun workroomTabIndex(name: String?): Int = TABS.indexOf(name).coerceAtLeast(0)

/**
 * The Workroom of a running (or finished) work: the tasks both sides keep, the files with their versions and notes, the decisions they settle
 * and the timeline of it all — four tabs of one page, with the numbers that ask something of me (open tasks, decisions waiting for my answer)
 * on the tabs. It is shared with the freelancer (what I write, they see) and is read only once the agreement is completed. Live while it is
 * open (the project's channel), read again on a pull and when the connection returns — nothing on a timer.
 */
@Composable
internal fun WorkroomPage(project: String, startTab: String, onBack: () -> Unit, onOpenFile: (String) -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val reconnect = collectReconnectTick()
    val live = rememberWorkroomLive(project)
    val people = rememberWorkroomPeople(project, reconnect)
    var tab by rememberSaveable { mutableIntStateOf(workroomTabIndex(startTab)) }
    // What the tabs say: tasks still open, decisions waiting for me; and whether the work is over (read only).
    var openTasks by remember { mutableIntStateOf(0) }
    var waiting by remember { mutableIntStateOf(0) }
    var unavailable by remember { mutableStateOf<String?>(null) }

    // The two numbers on the tabs, read small (one row each) while another tab is on show; the tabs themselves refresh them as they read.
    LaunchedEffect(project, reconnect, live.tasks, live.decisions) {
        try {
            val tasks = ApiClient.freelanceWorkroom.tasks(auth(), project, perPage = 1).data
            openTasks = tasks?.summary?.open ?: 0
            unavailable = null
            waiting = ApiClient.freelanceWorkroom.decisions(auth(), project, perPage = 1).data?.summary?.waitingForMe ?: 0
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            // The Workroom is off in this country, or the work has none (not running): say so. Anything else leaves the tabs to try for themselves.
            val failure = e.apiFailure()
            if (failure.httpStatus == 403 || failure.httpStatus == 404) unavailable = failure.message ?: generic
        }
    }

    Column(Modifier.fillMaxSize()) {
        FlPageHeader(
            stringResource(R.string.wr_title), onBack,
            subtitle = people.freelancer.takeIf { it.isNotBlank() }?.let { stringResource(R.string.wr_subtitle_with, it) } ?: stringResource(R.string.wr_subtitle),
        )
        val problem = unavailable
        if (problem != null) {
            WrEmpty(Icons.Rounded.Lock, stringResource(R.string.wr_unavailable), problem)
            return@Column
        }
        FlPartTabs(
            listOf(
                FlTab(Icons.Rounded.TaskAlt, stringResource(R.string.wr_tab_tasks), openTasks),
                FlTab(Icons.Rounded.AttachFile, stringResource(R.string.wr_tab_files)),
                FlTab(Icons.Rounded.Gavel, stringResource(R.string.wr_tab_decisions), waiting),
                FlTab(Icons.Rounded.History, stringResource(R.string.wr_tab_timeline)),
            ),
            tab, compact = true,
        ) { tab = it }
        // Each tab keeps its filter and scroll while another one is on show.
        val holder = rememberSaveableStateHolder()
        Box(Modifier.weight(1f).fillMaxWidth()) {
            holder.SaveableStateProvider(tab) {
                when (tab) {
                    0 -> TasksTab(project, people.parts, people.freelancer, live) { summary, _ -> openTasks = summary.open }
                    1 -> FilesTab(project, people.parts, people.freelancer, live, onOpenFile)
                    2 -> DecisionsTab(project, people.parts, people.freelancer, live) { summary, _ -> waiting = summary.waitingForMe }
                    else -> TimelineTab(project, people.freelancer, live, onOpenFile) { tab = it }
                }
            }
        }
    }
}

/**
 * The card on a running or finished agreement that opens its Workroom: what is open there (tasks done of all, decisions waiting for my
 * answer). It shows nothing while the Workroom is switched off in the country (or the work has none); read once when the page opens, when a
 * notice arrives and when the connection returns.
 */
@Composable
internal fun WorkroomEntryCard(project: String, onOpen: () -> Unit) {
    val reconnect = collectReconnectTick()
    // null: not answered yet; false: not available (off in the country); true: shown (the numbers may be missing if the read failed).
    var shown by remember(project) { mutableStateOf<Boolean?>(null) }
    var done by remember(project) { mutableIntStateOf(0) }
    var total by remember(project) { mutableIntStateOf(0) }
    var waiting by remember(project) { mutableIntStateOf(0) }
    LaunchedEffect(project, reconnect) {
        try {
            val tasks = ApiClient.freelanceWorkroom.tasks(auth(), project, perPage = 1).data
            done = tasks?.summary?.done ?: 0
            total = tasks?.summary?.total ?: 0
            shown = true
            waiting = ApiClient.freelanceWorkroom.decisions(auth(), project, perPage = 1).data?.summary?.waitingForMe ?: 0
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            val status = e.apiFailure().httpStatus
            shown = status != 403 && status != 404
        }
    }
    if (shown != true) return
    val line = listOfNotNull(
        if (total > 0) stringResource(R.string.wr_entry_tasks, done, total) else null,
        if (waiting > 0) stringResource(R.string.wr_entry_waiting, waiting) else null,
    ).joinToString("  ·  ").ifBlank { stringResource(R.string.wr_entry_idle) }
    Row(
        Modifier.fillMaxWidth().settingsSurface(FlCorners.card).clickable(role = Role.Button, onClick = onOpen).padding(14.dp)
            .semantics(mergeDescendants = true) {},
        horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(44.dp).clip(FlCorners.icon).background(WrViolet.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.Forum, null, tint = WrViolet, modifier = Modifier.size(23.dp))
        }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(stringResource(R.string.wr_title), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
            Text(line, color = settingsMut(), fontSize = 12.sp, lineHeight = 16.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
        }
        FlCountBadge(waiting)
        Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, null, tint = settingsMut(), modifier = Modifier.size(22.dp))
    }
}
