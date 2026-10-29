package com.dorr.freelance.ui.screens

import androidx.compose.material.icons.rounded.Apps
import androidx.compose.material.icons.rounded.Cancel
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.HourglassEmpty
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.horizontalScroll
import com.dorr.freelance.ui.components.SkeletonList
import androidx.compose.foundation.clickable
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.MarkEmailUnread
import androidx.compose.material3.Icon
import androidx.compose.ui.Alignment
import androidx.compose.ui.draw.clip
import com.dorr.freelance.ui.components.EmptyState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.InvitationDto
import com.dorr.freelance.net.PagedRows
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.Card
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.Page
import com.dorr.freelance.ui.components.PagedFooter
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import kotlinx.coroutines.launch

@Composable
fun invitationStatusLabel(status: String): String = stringResource(
    when (status) {
        "accepted" -> R.string.inv_s_accepted
        "declined" -> R.string.inv_s_declined
        else -> R.string.inv_s_sent
    },
)

/** The yes / no buttons of an invitation; [onAnswered] gets the new status. Used on the list and on the project page. */
@Composable
fun InvitationAnswer(uuid: String, onAnswered: (String) -> Unit, onError: (String) -> Unit) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var busy by remember { mutableStateOf(false) }
    fun answer(accept: Boolean) {
        busy = true
        scope.launch {
            val result = apiCall { if (accept) ApiClient.invitations.accept(uuid) else ApiClient.invitations.decline(uuid) }
            if (result is ApiResult.Ok) onAnswered(result.data.status) else onError(result.errorText(offlineText) ?: genericError)
            busy = false
        }
    }
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        SettingsPrimaryButton(text = stringResource(R.string.inv_accept), loading = busy, modifier = Modifier.weight(1f), onClick = { answer(true) })
        Text(
            stringResource(R.string.inv_decline), color = Color(0xFFDC2626), fontSize = 14.sp, fontWeight = FontWeight.Bold,
            modifier = Modifier.clickable(enabled = !busy) { answer(false) }.padding(12.dp),
        )
    }
}

/** My invitations (FL-205), fifteen at a time while I scroll. Read when shown, when a notice arrives and when the connection comes back - nothing on a timer. */
@Composable
fun InvitationsScreen(onBack: () -> Unit, onOpenProject: (String) -> Unit) {
    val reconnect = collectReconnectTick()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var filter by rememberSaveable { mutableStateOf<String?>(null) }
    val paged = remember(filter) { PagedRows<InvitationDto>(15, { it.uuid }, { page -> apiCall { ApiClient.invitations.list(page = page, perPage = 15, status = filter) } }) }
    val scope = rememberCoroutineScope()
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(reconnect, filter) { paged.reload() }
    LaunchedEffect(filter) { com.dorr.freelance.net.Realtime.changed.collect { paged.reload() } }

    Page(title = stringResource(R.string.inv_title), onBack = onBack, onNearEnd = { scope.launch { paged.loadMore() } }) {
        Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf<String?>(null, "sent", "accepted", "declined").forEach { f ->
                val selected = filter == f
                val ink = if (selected) Color.White else settingsInk()
                Row(
                    Modifier.clip(RoundedCornerShape(999.dp)).background(if (selected) settingsAccent() else settingsAccent().copy(alpha = 0.08f)).clickable { filter = f }.padding(horizontal = 14.dp, vertical = 8.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(
                        when (f) { "sent" -> Icons.Rounded.HourglassEmpty; "accepted" -> Icons.Rounded.CheckCircle; "declined" -> Icons.Rounded.Cancel; else -> Icons.Rounded.Apps },
                        contentDescription = null, tint = if (selected) ink else settingsAccent(), modifier = Modifier.size(15.dp),
                    )
                    Spacer(Modifier.width(6.dp))
                    Text(
                        stringResource(when (f) { "sent" -> R.string.inv_s_sent; "accepted" -> R.string.inv_s_accepted; "declined" -> R.string.inv_s_declined; else -> R.string.work_all }),
                        color = ink, fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 1, softWrap = false,
                    )
                }
            }
        }
        val list = if (paged.loaded) paged.rows.toList() else null
        when {
            list == null && paged.failure == null -> SkeletonList()
            list != null && list.isEmpty() && paged.failure == null -> EmptyState(
                icon = Icons.Rounded.MarkEmailUnread,
                title = stringResource(if (filter == null) R.string.inv_empty_title else R.string.inv_empty_filtered_title),
                body = stringResource(if (filter == null) R.string.inv_empty_body else R.string.inv_empty_filtered_body),
                modifier = Modifier.padding(top = 110.dp),
            )
            list != null -> list.forEach { i ->
                Card {
                    Row(Modifier.fillMaxWidth().clickable { i.project?.uuid?.let(onOpenProject) }, verticalAlignment = Alignment.CenterVertically) {
                        Box(
                            Modifier.size(42.dp).clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.12f)),
                            contentAlignment = Alignment.Center,
                        ) { Icon(Icons.Rounded.MarkEmailUnread, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(22.dp)) }
                        Spacer(Modifier.width(12.dp))
                        Text(i.project?.title.orEmpty(), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, modifier = Modifier.weight(1f))
                        Spacer(Modifier.width(8.dp))
                        Text(
                            invitationStatusLabel(i.status), color = settingsAccent(), fontSize = 11.sp, fontWeight = FontWeight.Bold,
                            modifier = Modifier.clip(RoundedCornerShape(50)).background(settingsAccent().copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 4.dp),
                        )
                    }
                    i.project?.summary?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 13.sp, maxLines = 3) }
                    if (!i.open && i.status == "sent") Text(stringResource(R.string.inv_closed), color = settingsMut(), fontSize = 12.sp)
                    if (i.can.accept) InvitationAnswer(
                        i.uuid,
                        onAnswered = { s -> paged.update({ it.uuid == i.uuid }) { it.copy(status = s, can = it.can.copy(accept = false, decline = false, offer = s == "accepted" && !it.offered)) } },
                        onError = { error = it },
                    )
                    if (i.can.offer) Text(
                        stringResource(R.string.inv_send_offer), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                        modifier = Modifier.clickable { i.project?.uuid?.let(onOpenProject) }.padding(vertical = 4.dp),
                    )
                }
            }
        }
        PagedFooter(paged) { scope.launch { paged.loadMore() } }
        ErrorBanner(error ?: paged.failure?.let { it.errorText(offlineText) ?: genericError }, Modifier.fillMaxWidth())
    }
}
