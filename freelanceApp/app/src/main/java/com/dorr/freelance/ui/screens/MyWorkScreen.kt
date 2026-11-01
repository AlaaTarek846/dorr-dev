package com.dorr.freelance.ui.screens

import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material3.Icon
import androidx.compose.material.icons.rounded.Apps
import androidx.compose.material.icons.rounded.Block
import androidx.compose.material.icons.rounded.Gavel
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.PlayCircle
import com.dorr.freelance.ui.components.SkeletonList
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Handyman
import androidx.compose.material3.CircularProgressIndicator
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
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.MyProjectDto
import com.dorr.freelance.net.PagedRows
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.EmptyState
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.PagedFooter
import com.dorr.freelance.ui.components.TabPage
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsSurface
import kotlinx.coroutines.launch

private val groups = listOf("all", "active", "completed", "disputes", "closed")

/**
 * My work (a tab): the projects I was chosen for - the ones I am working on, the finished ones and the ones that ended without being done -
 * newest first, fifteen at a time while I scroll. Read when the tab opens, when a notice arrives and when the connection returns.
 */
@Composable
fun MyWorkTab(onBack: () -> Unit, onOpenProject: (String) -> Unit) {
    val reconnect = collectReconnectTick()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val scope = rememberCoroutineScope()
    var group by rememberSaveable { mutableStateOf("all") }
    val paged = remember(group) { PagedRows<MyProjectDto>(15, { it.uuid }, { page -> apiCall { ApiClient.agreements.myProjects(page = page, perPage = 15, group = group) } }) }

    LaunchedEffect(reconnect, group) { paged.reload() }
    LaunchedEffect(group) { Realtime.changed.collect { paged.reload() } }

    TabPage(title = stringResource(R.string.work_title), onBack = onBack, onNearEnd = { scope.launch { paged.loadMore() } }) {
        Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            groups.forEach { g ->
                val selected = group == g
                val ink = if (selected) Color.White else settingsInk()
                Row(
                    Modifier.clip(RoundedCornerShape(999.dp)).background(if (selected) settingsAccent() else settingsAccent().copy(alpha = 0.08f)).clickable { group = g }.padding(horizontal = 14.dp, vertical = 8.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(workGroupIcon(g), contentDescription = null, tint = if (selected) ink else settingsAccent(), modifier = Modifier.size(15.dp))
                    Spacer(Modifier.size(6.dp))
                    Text(stringResource(workGroupLabel(g)), color = ink, fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 1, softWrap = false)
                }
            }
        }
        when {
            !paged.loaded && paged.failure == null -> SkeletonList()
            paged.loaded && paged.rows.isEmpty() && paged.failure == null -> EmptyState(
                icon = Icons.Rounded.Handyman,
                title = stringResource(workEmptyTitle(group)),
                body = stringResource(workEmptyBody(group)),
                modifier = Modifier.padding(top = 90.dp),
            )
            else -> paged.rows.toList().forEach { p -> MyProjectCard(p) { p.project.uuid?.let(onOpenProject) } }
        }
        PagedFooter(paged) { scope.launch { paged.loadMore() } }
        ErrorBanner(paged.failure?.let { it.errorText(offlineText) ?: genericError }, Modifier.fillMaxWidth())
    }
}

private fun workGroupIcon(group: String) = when (group) {
    "active" -> Icons.Rounded.PlayCircle
    "completed" -> Icons.Rounded.CheckCircle
    "disputes" -> Icons.Rounded.Gavel
    "closed" -> Icons.Rounded.Block
    else -> Icons.Rounded.Apps
}

private fun workGroupLabel(group: String) = when (group) {
    "all" -> R.string.work_all
    "disputes" -> R.string.work_disputes
    "completed" -> R.string.work_completed
    "closed" -> R.string.work_closed
    else -> R.string.work_active
}

private fun workEmptyTitle(group: String) = when (group) {
    "all" -> R.string.work_empty_all_title
    "disputes" -> R.string.work_empty_disputes_title
    "completed" -> R.string.work_empty_completed_title
    "closed" -> R.string.work_empty_closed_title
    else -> R.string.work_empty_active_title
}

private fun workEmptyBody(group: String) = when (group) {
    "all" -> R.string.work_empty_all_body
    "disputes" -> R.string.work_empty_disputes_body
    "completed" -> R.string.work_empty_completed_body
    "closed" -> R.string.work_empty_closed_body
    else -> R.string.work_empty_active_body
}

private fun statusColor(status: String): Color = when (status) {
    "active" -> Color(0xFF2563EB)
    "completed" -> Color(0xFF16A34A)
    "pending_approval" -> Color(0xFFD97706)
    else -> Color(0xFF6B7280)
}

@Composable
private fun statusLabel(status: String): String = stringResource(
    when (status) {
        "active" -> R.string.work_status_active
        "completed" -> R.string.work_status_completed
        "pending_approval" -> R.string.work_status_pending
        "declined" -> R.string.work_status_declined
        "cancelled" -> R.string.work_status_cancelled
        else -> R.string.work_status_expired
    },
)

@Composable
private fun MyProjectCard(p: MyProjectDto, onClick: () -> Unit) {
    val tint = statusColor(p.status)
    val red = Color(0xFFDC2626)
    Column(
        Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).clickable(onClick = onClick).padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(42.dp).clip(RoundedCornerShape(13.dp)).background(tint.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Handyman, contentDescription = null, tint = tint, modifier = Modifier.size(21.dp))
            }
            Spacer(Modifier.size(10.dp))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(p.project.title.orEmpty(), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        statusLabel(p.status), color = tint, fontSize = 11.sp, fontWeight = FontWeight.Bold,
                        modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(tint.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 3.dp),
                    )
                    if (p.hasDispute) Row(
                        Modifier.clip(RoundedCornerShape(999.dp)).background(red.copy(alpha = 0.12f)).padding(horizontal = 8.dp, vertical = 3.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(Icons.Rounded.Gavel, contentDescription = null, tint = red, modifier = Modifier.size(12.dp))
                        Spacer(Modifier.size(4.dp))
                        Text(stringResource(R.string.work_dispute_badge), color = red, fontSize = 11.sp, fontWeight = FontWeight.Bold)
                    }
                }
            }
            Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, contentDescription = null, tint = settingsMut(), modifier = Modifier.size(20.dp))
        }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            OfferStat("\u200E${formatMinor(p.priceMinor, null)} ${p.currency}", stringResource(R.string.offer_stat_price), Modifier.weight(1f))
            OfferStat(stringResource(R.string.projects_days, p.durationDays), stringResource(R.string.offer_stat_time), Modifier.weight(1f))
        }
        if (p.milestonesCount > 0) {
            val fraction = p.milestonesDone.toFloat() / p.milestonesCount
            Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(stringResource(R.string.work_parts_done, p.milestonesDone, p.milestonesCount), color = settingsMut(), fontSize = 11.5.sp, modifier = Modifier.weight(1f))
                    Text("\u200E${(fraction * 100).toInt()}%", color = tint, fontSize = 11.5.sp, fontWeight = FontWeight.ExtraBold)
                }
                Box(Modifier.fillMaxWidth().height(6.dp).clip(RoundedCornerShape(999.dp)).background(settingsMut().copy(alpha = 0.18f))) {
                    Box(Modifier.fillMaxWidth(fraction).height(6.dp).clip(RoundedCornerShape(999.dp)).background(tint))
                }
            }
        }
    }
}
