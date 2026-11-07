package com.dorr.freelance.ui.screens.workroom

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.ui.Alignment
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp
import com.dorr.freelance.ui.kit.Corners
import com.dorr.freelance.ui.kit.appCard
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AttachFile
import androidx.compose.material.icons.rounded.Checklist
import androidx.compose.material.icons.rounded.Gavel
import androidx.compose.material.icons.rounded.History
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.AgreementDto
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.ui.components.MessageToast
import com.dorr.freelance.ui.kit.PinkBackdrop
import com.dorr.freelance.ui.kit.Segment
import com.dorr.freelance.ui.kit.SegmentTabs
import com.dorr.freelance.ui.kit.SubHeader
import kotlinx.coroutines.coroutineScope
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** The keys of the Workroom's tabs (also what a notice's route carries: `tasks` · `files` · `decisions` · `timeline`). */
private val TABS = listOf("tasks", "files", "decisions", "timeline")

/**
 * FS-150e: a project's Workroom for the freelancer — the tasks, the files (with versions and notes), the decisions both sides agreed and the
 * timeline of the whole project, in one place under the agreement. It opens on the tab a notice points at.
 *
 * Everything reads once when it opens, once when the connection returns and once when a notice arrives. While it is open it also listens to
 * the project's private channel (`freelance.workroom`: kind, action, uuid, by — never the text): each change by the other side (or by my
 * team) reloads only the tab it belongs to, once, after the burst settles. Nothing runs on a timer.
 */
@Composable
fun WorkroomScreen(project: String, tab: String, onBack: () -> Unit, onOpenFile: (String) -> Unit) {
    val reconnect = collectReconnectTick()
    val tasks = remember(project) { TasksState(project) }
    val files = remember(project) { FilesState(project) }
    val decisions = remember(project) { DecisionsState(project) }
    val timeline = remember(project) { TimelineState(project) }
    var agreement by remember(project) { mutableStateOf<AgreementDto?>(null) }
    var selected by rememberSaveable { mutableStateOf(tab.takeIf { it in TABS } ?: "tasks") }
    var toast by remember { mutableStateOf<String?>(null) }
    // The timeline is read when its tab is open; a change meanwhile only marks it old.
    var timelineStale by remember(project) { mutableStateOf(true) }
    val pending = remember(project) { mutableSetOf<String>() }
    var dirty by remember(project) { mutableIntStateOf(0) }

    DisposableEffect(project) {
        Realtime.listenWorkroom(project)
        onDispose { Realtime.stopListeningWorkroom(project) }
    }
    // FS-209: a team's project also hears its team's channel, where the inside items (tasks, files, notes) travel.
    val teamUuid = agreement?.team?.uuid
    DisposableEffect(teamUuid) {
        if (teamUuid != null) Realtime.listenTeamWorkroom(teamUuid)
        onDispose { if (teamUuid != null) Realtime.stopListeningTeamWorkroom(teamUuid) }
    }

    suspend fun loadAll() {
        coroutineScope {
            launch { (apiCall { ApiClient.agreements.show(project) } as? ApiResult.Ok)?.let { agreement = it.data } }
            launch { tasks.feed.reload() }
            launch { files.feed.reload() }
            launch { decisions.feed.reload() }
        }
        timelineStale = true
    }
    LaunchedEffect(project, reconnect) { loadAll() }
    LaunchedEffect(project) { Realtime.changed.collect { synchronized(pending) { pending.add("all") }; dirty++ } }
    LaunchedEffect(project) {
        Realtime.workroomEvents.collect { e ->
            // An event of the team's channel names its project; one of another project's Workroom is not ours.
            if (e.data.text("project")?.let { it != project } == true) return@collect
            synchronized(pending) { pending.add(e.data.text("kind") ?: "all") }
            dirty++
        }
    }
    // A burst of changes is one read per tab: wait for it to settle, then read what changed.
    LaunchedEffect(dirty) {
        if (dirty == 0) return@LaunchedEffect
        delay(350)
        val kinds = synchronized(pending) { pending.toSet().also { pending.clear() } }
        if (kinds.isEmpty()) return@LaunchedEffect
        val all = "all" in kinds
        coroutineScope {
            if (all || "task" in kinds) launch { tasks.feed.reload() }
            if (all || "file" in kinds || "comment" in kinds) launch { files.feed.reload() }
            if (all || "decision" in kinds) launch { decisions.feed.reload() }
            if (all) launch { (apiCall { ApiClient.agreements.show(project) } as? ApiResult.Ok)?.let { agreement = it.data } }
        }
        timelineStale = true
    }
    LaunchedEffect(selected, timelineStale) {
        if (selected == "timeline" && timelineStale) {
            timelineStale = false
            timeline.feed.reload()
        }
    }

    val parts = agreement?.milestones?.asParts().orEmpty()
    val team = agreement?.team

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize().statusBarsPadding().navigationBarsPadding()) {
            SubHeader(stringResource(R.string.wr_title), onBack)
            SegmentTabs(
                listOf(
                    Segment("tasks", stringResource(R.string.wr_tab_tasks), Icons.Rounded.Checklist, tasks.summary.open),
                    Segment("files", stringResource(R.string.wr_tab_files), Icons.Rounded.AttachFile),
                    Segment("decisions", stringResource(R.string.wr_tab_decisions), Icons.Rounded.Gavel, decisions.summary.waitingForMe),
                    Segment("timeline", stringResource(R.string.wr_tab_timeline), Icons.Rounded.History),
                ),
                selected, { selected = it }, Modifier.padding(horizontal = 16.dp).padding(bottom = 8.dp),
            )
            Box(Modifier.weight(1f)) {
                when (selected) {
                    "files" -> FilesTab(project, files, parts, team, onOpenFile, { toast = it }, onChanged = { timelineStale = true })
                    "decisions" -> DecisionsTab(project, decisions, parts, { toast = it }, onChanged = { timelineStale = true })
                    "timeline" -> TimelineTab(timeline)
                    else -> TasksTab(project, tasks, parts, team, { toast = it }, onChanged = { timelineStale = true })
                }
            }
        }
        MessageToast(toast)
    }
}

/** The card on the agreement page that opens the Workroom (its title, what is inside, and — once the project is over — that it is read only). */
@Composable
internal fun WorkroomEntryCard(readOnly: Boolean, onClick: () -> Unit) {
    val tint = settingsAccent()
    androidx.compose.foundation.layout.Row(
        Modifier.fillMaxWidth().appCard(Corners.card).clip(Corners.card).clickable(onClick = onClick).padding(14.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = androidx.compose.foundation.layout.Arrangement.spacedBy(12.dp),
    ) {
        Box(Modifier.size(44.dp).clip(Corners.small).background(tint.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.Checklist, contentDescription = null, tint = tint, modifier = Modifier.size(22.dp))
        }
        Column(Modifier.weight(1f), verticalArrangement = androidx.compose.foundation.layout.Arrangement.spacedBy(2.dp)) {
            Text(stringResource(R.string.wr_title), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
            Text(
                stringResource(if (readOnly) R.string.wr_entry_body_done else R.string.wr_entry_body), color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp,
            )
        }
        Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, contentDescription = null, tint = settingsMut(), modifier = Modifier.size(22.dp))
    }
}
