package com.dorr.app.ui.screens.freelance

import androidx.compose.material.icons.Icons
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.width
import androidx.compose.ui.Alignment
import androidx.compose.foundation.shape.RoundedCornerShape
import com.dorr.app.ui.screens.profile.settingsSurface
import androidx.compose.foundation.layout.fillMaxSize
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.components.DorrTextField
import androidx.compose.ui.draw.clip
import androidx.compose.material3.Icon
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.rounded.*
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.FreelanceReportDto
import com.dorr.app.network.FreelanceReportSummaryDto
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import kotlinx.coroutines.CancellationException

/**
 * FL-605: the progress reports the freelancer sent on this agreement, newest first. The client only reads them; how often one is asked
 * for is set while writing the agreement. A late report is said plainly (nothing is blocked). Read again when the agreement changes
 * or the connection returns - the "a report arrived" notice reloads the screen, nothing runs on a timer.
 */
@Composable
internal fun ProgressReportsCard(projectUuid: String, summary: FreelanceReportSummaryDto, refreshKey: String) {
    val reconnect = collectReconnectTick()
    var list by remember { mutableStateOf<List<FreelanceReportDto>>(emptyList()) }
    LaunchedEffect(projectUuid, refreshKey, reconnect, summary.lastAt) {
        try {
            list = ApiClient.freelance.reports(auth(), projectUuid).data?.reports.orEmpty()
        } catch (e: CancellationException) {
            throw e
        } catch (_: Throwable) {
        }
    }
    if (list.isEmpty() && summary.everyDays == null) return
    Card(Icons.Rounded.EventRepeat, stringResource(R.string.fl_rp_title)) {
        summary.everyDays?.let { Text(stringResource(R.string.fl_rp_every, it), color = settingsMut(), fontSize = 12.sp) }
        if (summary.late) FlNotice(stringResource(R.string.fl_rp_late), Icons.Rounded.Warning, Color(0xFFD97706))
        else summary.nextDueAt?.let { Text(stringResource(R.string.fl_rp_next, "‎" + it.take(10)), color = settingsMut(), fontSize = 12.sp) }
        if (list.isEmpty()) Text(stringResource(R.string.fl_rp_empty), color = settingsMut(), fontSize = 13.sp)
        list.forEach { r ->
            Column(Modifier.fillMaxWidth().clip(androidx.compose.foundation.shape.RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.06f)).padding(12.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Row {
                    Text("‎" + r.createdAt?.take(16)?.replace('T', ' ').orEmpty(), color = settingsMut(), fontSize = 11.sp, modifier = Modifier.weight(1f))
                    r.percent?.let { Text(stringResource(R.string.fl_rp_percent, it), color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.Bold) }
                }
                Text(r.note, color = settingsInk(), fontSize = 13.sp)
            }
        }
    }
}
