package com.dorr.freelance.ui.screens.workroom

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.AttachFile
import androidx.compose.material.icons.rounded.ChatBubbleOutline
import androidx.compose.material.icons.rounded.Checklist
import androidx.compose.material.icons.rounded.Flag
import androidx.compose.material.icons.rounded.Gavel
import androidx.compose.material.icons.rounded.Handshake
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.SwapHoriz
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.AppLocale
import com.dorr.freelance.net.WorkEventDto
import com.dorr.freelance.ui.kit.FilterChips
import com.dorr.freelance.ui.kit.FilterOption
import com.dorr.freelance.ui.kit.Tints
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import kotlinx.coroutines.launch
import java.time.LocalDate

/** The project's timeline: every step of the work, newest first (read as pages; a filter picks one kind of step). */
internal class TimelineState(private val project: String) {
    var filter by mutableStateOf("all")

    val feed = WorkFeed<WorkEventDto>({ it.key }) { page ->
        val f = filter
        apiCall { ApiClient.workroom.timeline(project, kind = f.takeIf { it != "all" && it != "mine" }, mine = if (f == "mine") 1 else null, perPage = WORK_PAGE, page = page) }.rows { it.events }
    }
}

private fun kindIcon(kind: String): Pair<ImageVector, Color> = when (kind) {
    "task" -> Icons.Rounded.Checklist to Tints.blue
    "file" -> Icons.Rounded.AttachFile to Tints.teal
    "comment" -> Icons.Rounded.ChatBubbleOutline to Tints.violet
    "decision" -> Icons.Rounded.Gavel to Tints.amber
    "agreement" -> Icons.Rounded.Handshake to Tints.green
    "milestone" -> Icons.Rounded.Flag to Tints.green
    "dispute" -> Icons.Rounded.Gavel to Tints.rose
    "change" -> Icons.Rounded.SwapHoriz to Tints.amber
    "review" -> Icons.Rounded.Star to Tints.amber
    "rights" -> Icons.Rounded.AccountBalanceWallet to Tints.teal
    else -> Icons.Rounded.History to Color(0xFF9CA3AF)
}

/** The tab of the timeline: one line per step, under the heading of its day. */
@Composable
internal fun TimelineTab(state: TimelineState) {
    val scope = rememberCoroutineScope()
    val locale = AppLocale.java()
    val today = LocalDate.now()
    val filters = listOf(
        FilterOption("all", stringResource(R.string.wr_f_all)),
        FilterOption("mine", stringResource(R.string.wr_f_mine)),
        FilterOption("task", stringResource(R.string.wr_k_task)),
        FilterOption("file", stringResource(R.string.wr_k_file)),
        FilterOption("comment", stringResource(R.string.wr_k_comment)),
        FilterOption("decision", stringResource(R.string.wr_k_decision)),
        FilterOption("milestone", stringResource(R.string.wr_k_milestone)),
    )
    FeedList(
        feed = state.feed, keyOf = { it.key },
        empty = Triple(Icons.Rounded.History, stringResource(R.string.wr_tl_empty_title), stringResource(R.string.wr_tl_empty_body)),
        header = {
            FilterChips(filters, state.filter, { key ->
                if (key != state.filter) {
                    state.filter = key
                    scope.launch { state.feed.reload() }
                }
            })
        },
    ) { e ->
        val rows = state.feed.paged.rows
        val at = rows.indexOfFirst { it.key == e.key }
        val day = dayKey(e.at)
        val first = at <= 0 || dayKey(rows[at - 1].at) != day
        Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            if (first && day != null) Text(
                when (day) {
                    today -> stringResource(R.string.wr_today)
                    today.minusDays(1) -> stringResource(R.string.wr_yesterday)
                    else -> workDay(day.toString(), locale).orEmpty()
                },
                color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = if (at <= 0) 0.dp else 8.dp),
            )
            EventRow(e, locale)
        }
    }
}

@Composable
private fun EventRow(e: WorkEventDto, locale: java.util.Locale) {
    val (icon, tint) = kindIcon(e.kind)
    WorkCard {
        Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Box(Modifier.size(34.dp).clip(CircleShape).background(tint.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                Icon(icon, contentDescription = null, tint = tint, modifier = Modifier.size(18.dp))
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text(e.label, color = settingsInk(), fontSize = 13.5.sp, lineHeight = 19.sp, fontWeight = FontWeight.SemiBold)
                val who = e.by?.name ?: e.actor
                val line = listOfNotNull(
                    who?.takeIf { it.isNotBlank() }?.let { if (e.mine) stringResource(R.string.wr_side_me) else it },
                    workClock(e.at, locale),
                ).joinToString(" · ")
                if (line.isNotBlank()) Text(line, color = settingsMut(), fontSize = 11.5.sp)
            }
        }
    }
}
