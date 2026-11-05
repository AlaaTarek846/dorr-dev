package com.dorr.provider.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.ConfirmationNumber
import androidx.compose.material.icons.rounded.Notes
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
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.provider.R
import com.dorr.provider.net.ApiClient
import com.dorr.provider.net.PagedRows
import com.dorr.provider.net.Realtime
import com.dorr.provider.net.ReplyBody
import com.dorr.provider.net.TicketDto
import com.dorr.provider.net.collectReconnectTick
import com.dorr.provider.net.errorText
import com.dorr.provider.ui.components.Card
import com.dorr.provider.ui.components.DorrTextField
import com.dorr.provider.ui.components.EmptyState
import com.dorr.provider.ui.components.ErrorBanner
import com.dorr.provider.ui.components.Page
import com.dorr.provider.ui.components.PagedFooter
import com.dorr.provider.ui.kit.SettingsPrimaryButton
import com.dorr.provider.ui.kit.settingsAccent
import com.dorr.provider.ui.kit.settingsCard
import com.dorr.provider.ui.kit.settingsInk
import com.dorr.provider.ui.kit.settingsMut
import kotlinx.coroutines.launch

/** The customers' tickets on the establishment's moves (C-04, `tickets.handle`), newest first. */
@Composable
fun TicketsScreen(onBack: () -> Unit, onOpenTicket: (String) -> Unit) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val paged = remember { PagedRows<TicketDto>(20, { it.uuid }) { page -> apiCall { ApiClient.moving.tickets(page) } } }
    LaunchedEffect(reconnect) { paged.reload() }
    LaunchedEffect(Unit) { Realtime.changed.collect { paged.reload() } }

    Page(title = stringResource(R.string.mv_tickets_title), onBack = onBack, onNearEnd = { scope.launch { paged.loadMore() } }) {
        when {
            !paged.loaded -> CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.5.dp, modifier = Modifier.padding(top = 40.dp).size(28.dp))
            paged.rows.isEmpty() -> EmptyState(Icons.Rounded.ConfirmationNumber, stringResource(R.string.mv_tickets_empty_title), stringResource(R.string.mv_tickets_empty_body), Modifier.padding(top = 30.dp))
            else -> paged.rows.forEach { ticket ->
                RowCard(
                    Icons.Rounded.ConfirmationNumber,
                    ticket.subject?.takeIf { it.isNotBlank() } ?: stringResource(R.string.mv_ticket),
                    dateTimeText(ticket.createdAt),
                    pill = { StatusPill(ticketStatusLabel(ticket.status), ticketStatusColor(ticket.status)) },
                    onClick = { onOpenTicket(ticket.uuid) },
                )
            }
        }
        PagedFooter(paged, onRetry = { paged.retry(); scope.launch { paged.loadMore() } })
        paged.failure?.let { ErrorBanner(it.errorText(offlineText), Modifier.fillMaxWidth()) }
    }
}

/**
 * One ticket: its messages (the customer's, ours and Dorr's) and our reply. Replying does not close it: the customer accepts the
 * answer, or Dorr decides after the waiting time — said under the field.
 */
@Composable
fun TicketScreen(uuid: String, onBack: () -> Unit, onOpenJob: (String) -> Unit) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var ticket by remember { mutableStateOf<TicketDto?>(null) }
    var reply by rememberSaveable { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    suspend fun reload() {
        when (val result = apiCall { ApiClient.moving.ticket(uuid) }) {
            is ApiResult.Ok -> {
                ticket = result.data
                error = null
            }
            else -> error = result.errorText(offlineText) ?: genericError
        }
    }
    LaunchedEffect(reconnect) { reload() }
    LaunchedEffect(Unit) { Realtime.changed.collect { reload() } }

    fun send() {
        busy = true
        error = null
        scope.launch {
            val result = apiCall { ApiClient.moving.reply(uuid, ReplyBody(reply.trim())) }
            busy = false
            if (result is ApiResult.Ok) {
                ticket = result.data
                reply = ""
            } else {
                error = result.errorText(offlineText) ?: genericError
            }
        }
    }

    val current = ticket
    Page(title = stringResource(R.string.mv_ticket), onBack = onBack) {
        if (current == null) {
            if (error == null) CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.5.dp, modifier = Modifier.padding(top = 40.dp).size(28.dp))
        } else {
            Card {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(current.subject ?: stringResource(R.string.mv_ticket), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                    StatusPill(ticketStatusLabel(current.status), ticketStatusColor(current.status))
                }
                InfoLine(stringResource(R.string.mv_opened_at), dateTimeText(current.createdAt))
                current.escalationReason?.takeIf { it.isNotBlank() }?.let { InfoLine(stringResource(R.string.mv_escalation), it) }
                current.requestUuid?.let { job ->
                    Text(stringResource(R.string.mv_open_job), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable { onOpenJob(job) })
                }
            }
            current.messages.orEmpty().forEach { message -> MessageBubble(message.author, message.body.orEmpty(), message.at) }
            val open = current.status !in setOf("resolved", "unresolved")
            if (open) {
                DorrTextField(reply, { reply = it.take(3000) }, label = stringResource(R.string.mv_reply), icon = Icons.Rounded.Notes, singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
                Text(stringResource(R.string.mv_reply_hint), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp, modifier = Modifier.fillMaxWidth())
                SettingsPrimaryButton(text = stringResource(R.string.mv_send_reply), enabled = reply.isNotBlank(), loading = busy, onClick = ::send)
            }
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}

/** One message: ours on the leading side in the app colour, the customer's and Dorr's in a card, each with who and when. */
@Composable
private fun MessageBubble(author: String?, body: String, at: String?) {
    val ours = author == "provider"
    Column(Modifier.fillMaxWidth(), horizontalAlignment = if (ours) Alignment.Start else Alignment.End) {
        Column(
            Modifier.widthIn(max = 320.dp).clip(RoundedCornerShape(16.dp))
                .background(if (ours) settingsAccent().copy(alpha = 0.12f) else settingsCard())
                .padding(12.dp),
            verticalArrangement = Arrangement.spacedBy(4.dp),
        ) {
            Text(
                when (author) {
                    "provider" -> stringResource(R.string.mv_author_us)
                    "admin" -> stringResource(R.string.mv_author_dorr)
                    else -> stringResource(R.string.mv_author_customer)
                },
                color = settingsAccent(), fontSize = 11.sp, fontWeight = FontWeight.Bold,
            )
            Text(body, color = settingsInk(), fontSize = 13.sp, lineHeight = 20.sp)
            Row {
                Spacer(Modifier.width(0.dp))
                Text(dateTimeText(at), color = settingsMut(), fontSize = 10.sp)
            }
        }
    }
}
