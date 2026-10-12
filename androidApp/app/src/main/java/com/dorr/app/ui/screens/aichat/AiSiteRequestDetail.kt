package com.dorr.app.ui.screens.aichat

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Chat
import androidx.compose.material.icons.rounded.Dashboard
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.Send
import androidx.compose.material.icons.rounded.Timer
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
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
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import com.dorr.app.R
import com.dorr.app.network.AiRequestInstallmentDto
import com.dorr.app.network.AiRequestMessageDto
import com.dorr.app.network.AiSiteRequestDto
import com.dorr.app.network.ApiClient
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.chat.chatAuth
import com.dorr.app.ui.screens.wallet.PadResult
import com.dorr.app.ui.screens.wallet.walletAuth
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle

private fun money(value: Double?, currency: String?): String {
    val v = value ?: 0.0
    val text = if (v % 1.0 == 0.0) v.toLong().toString() else "%.2f".format(v)
    return "$text ${currency.orEmpty()}".trim()
}

private fun fmtDate(value: String?): String =
    parseInstant(value)?.let { DateTimeFormatter.ofLocalizedDate(FormatStyle.MEDIUM).withZone(ZoneId.systemDefault()).format(it) }.orEmpty()

private fun fmtTime(value: String?): String =
    parseInstant(value)?.let { DateTimeFormatter.ofLocalizedDateTime(FormatStyle.SHORT).withZone(ZoneId.systemDefault()).format(it) }.orEmpty()

/** 0..4 position on the journey: received, quoted, building, delivered, done. */
private fun journeyIndex(code: String): Int = when (code) {
    "submitted", "under_review" -> 0
    "quote_sent", "quote_declined" -> 1
    "in_progress", "on_hold" -> 2
    "delivered" -> 3
    "completed" -> 4
    else -> 0
}

@Composable
private fun Journey(code: String, onDark: Boolean) {
    val labels = listOf(R.string.ai_req_j_received, R.string.ai_req_j_quote, R.string.ai_req_j_build, R.string.ai_req_j_deliver, R.string.ai_req_j_done)
    val at = journeyIndex(code)
    val fg = Color.White
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
        labels.forEachIndexed { i, label ->
            Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally) {
                Box(
                    Modifier.size(22.dp).clip(CircleShape).background(if (i <= at) fg else fg.copy(alpha = 0.25f)),
                    contentAlignment = Alignment.Center,
                ) {
                    if (i < at || code == "completed") Icon(Icons.Rounded.CheckCircle, contentDescription = null, tint = Ai.Red, modifier = Modifier.size(22.dp))
                    else Text("${i + 1}", color = if (i <= at) Ai.Red else fg, fontSize = 11.sp, fontWeight = FontWeight.ExtraBold)
                }
                Spacer(Modifier.height(5.dp))
                Text(stringResource(label), color = fg.copy(alpha = if (i <= at) 1f else 0.6f), fontSize = 10.5.sp, fontWeight = FontWeight.SemiBold, maxLines = 1)
            }
        }
    }
}

@Composable
private fun RequestHero(r: AiSiteRequestDto) {
    val code = r.code
    val remaining = remainingParts(r.timeline?.dueAt)
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(26.dp)).background(siteGradient()).padding(18.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(r.title.orEmpty(), color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 20.sp, maxLines = 2)
                Text(r.reference.orEmpty(), color = Color.White.copy(alpha = 0.8f), fontSize = 12.sp, modifier = Modifier.padding(top = 2.dp))
            }
            RequestStatusChip(r.status?.name.orEmpty(), r.status?.color, onDark = true)
        }
        Spacer(Modifier.height(14.dp))

        val message = when {
            code == "quote_sent" -> stringResource(R.string.ai_req_hero_quote)
            (code == "in_progress" || code == "on_hold") && remaining != null && !remaining.third -> null
            (code == "in_progress" || code == "on_hold") && remaining != null -> stringResource(R.string.ai_req_hero_overdue)
            code == "delivered" -> stringResource(R.string.ai_req_hero_delivered)
            code == "completed" -> stringResource(R.string.ai_req_hero_completed)
            code == "quote_declined" -> stringResource(R.string.ai_req_hero_declined)
            code == "rejected" || code == "cancelled" -> stringResource(R.string.ai_req_hero_closed)
            else -> stringResource(R.string.ai_req_hero_review)
        }

        if (message == null && remaining != null) {
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Color.White.copy(alpha = 0.14f)).padding(14.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.Timer, contentDescription = null, tint = Color.White, modifier = Modifier.size(28.dp))
                Spacer(Modifier.width(12.dp))
                Column {
                    Text(stringResource(R.string.ai_req_time_left), color = Color.White.copy(alpha = 0.8f), fontSize = 11.5.sp)
                    Text(stringResource(R.string.ai_req_days_hours, remaining.first, remaining.second), color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 22.sp)
                }
            }
        } else if (message != null) {
            Text(message, color = Color.White, fontSize = 13.5.sp, lineHeight = 20.sp, fontWeight = FontWeight.SemiBold)
        }

        if (code !in listOf("rejected", "cancelled")) {
            Spacer(Modifier.height(16.dp))
            Journey(code, true)
        }
    }
}

@Composable
private fun RequestTabs(selected: Int, unread: Int, onSelect: (Int) -> Unit) {
    val c = sitePalette()
    val items: List<Pair<ImageVector, Int>> = listOf(Icons.Rounded.Dashboard to R.string.ai_req_tab_overview, Icons.Rounded.Payments to R.string.ai_req_tab_payments, Icons.Rounded.Chat to R.string.ai_req_tab_chat)
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(c.surface).border(1.dp, c.line, RoundedCornerShape(18.dp)).padding(5.dp),
        horizontalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        items.forEachIndexed { index, (icon, label) ->
            val on = index == selected
            Row(
                Modifier.weight(1f).clip(RoundedCornerShape(14.dp)).then(if (on) Modifier.background(siteGradient()) else Modifier).clickable { onSelect(index) }.padding(vertical = 11.dp),
                horizontalArrangement = Arrangement.Center,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(icon, contentDescription = null, tint = if (on) Color.White else c.mut, modifier = Modifier.size(17.dp))
                Spacer(Modifier.width(6.dp))
                Text(stringResource(label), color = if (on) Color.White else c.mut, fontSize = 12.5.sp, fontWeight = if (on) FontWeight.ExtraBold else FontWeight.SemiBold, maxLines = 1)
                if (index == 2 && unread > 0 && !on) {
                    Spacer(Modifier.width(5.dp))
                    Box(Modifier.size(8.dp).clip(CircleShape).background(c.danger))
                }
            }
        }
    }
}

@Composable
private fun InfoLine(label: String, value: String) {
    val c = sitePalette()
    Row(Modifier.fillMaxWidth().padding(vertical = 5.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(label, color = c.mut, fontSize = 12.5.sp, modifier = Modifier.weight(1f))
        Text(value, color = c.ink, fontSize = 13.sp, fontWeight = FontWeight.Bold)
    }
}

@Composable
private fun PaymentRow(p: AiRequestInstallmentDto, currency: String?, busy: Boolean, onPay: () -> Unit) {
    val c = sitePalette()
    val status = p.status.orEmpty()
    val color = when (status) { "paid" -> c.success; "due" -> c.warn; else -> c.mut }
    Row(Modifier.fillMaxWidth().padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(if (status == "paid") Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, contentDescription = null, tint = color, modifier = Modifier.size(24.dp))
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(p.title.orEmpty(), color = c.ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
            Text(
                when (status) {
                    "paid" -> stringResource(R.string.ai_req_paid_on, fmtDate(p.paidAt))
                    "due" -> stringResource(R.string.ai_req_due_now)
                    else -> when (p.trigger) {
                        "on_milestone" -> stringResource(R.string.ai_req_when_milestone)
                        "on_delivery" -> stringResource(R.string.ai_req_when_delivery)
                        else -> stringResource(R.string.ai_req_when_accept)
                    }
                },
                color = if (status == "due") c.warn else c.mut, fontSize = 11.5.sp, modifier = Modifier.padding(top = 2.dp),
            )
        }
        Column(horizontalAlignment = Alignment.End) {
            Text(money(p.amount, currency), color = c.ink, fontWeight = FontWeight.ExtraBold, fontSize = 13.5.sp)
            if (p.canPay == true) {
                Spacer(Modifier.height(6.dp))
                Box(Modifier.clip(RoundedCornerShape(12.dp)).background(siteGradient()).clickable(enabled = !busy, onClick = onPay).padding(horizontal = 16.dp, vertical = 7.dp)) {
                    Text(stringResource(R.string.ai_req_pay), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 12.sp)
                }
            }
        }
    }
}

@Composable
private fun ChatBubble(m: AiRequestMessageDto) {
    val c = sitePalette()
    when (m.sender) {
        "system" -> Box(Modifier.fillMaxWidth().padding(vertical = 5.dp), contentAlignment = Alignment.Center) {
            Text(
                m.body.orEmpty(), color = c.mut, fontSize = 11.5.sp, lineHeight = 17.sp,
                modifier = Modifier.clip(RoundedCornerShape(12.dp)).background(c.field).padding(horizontal = 12.dp, vertical = 6.dp),
            )
        }
        else -> {
            val mine = m.sender == "customer"
            Column(Modifier.fillMaxWidth().padding(vertical = 4.dp), horizontalAlignment = if (mine) Alignment.End else Alignment.Start) {
                Text(
                    m.body.orEmpty(), color = if (mine) Color.White else c.ink, fontSize = 13.5.sp, lineHeight = 20.sp,
                    modifier = Modifier.widthIn(max = 300.dp)
                        .clip(RoundedCornerShape(topStart = 18.dp, topEnd = 18.dp, bottomStart = if (mine) 18.dp else 4.dp, bottomEnd = if (mine) 4.dp else 18.dp))
                        .background(if (mine) Ai.Red else c.surface)
                        .then(if (mine) Modifier else Modifier.border(1.dp, c.line, RoundedCornerShape(topStart = 18.dp, topEnd = 18.dp, bottomStart = 4.dp, bottomEnd = 18.dp)))
                        .padding(horizontal = 14.dp, vertical = 10.dp),
                )
                Text(
                    (if (mine) "" else stringResource(R.string.ai_req_team) + " · ") + fmtTime(m.createdAt),
                    color = c.mut, fontSize = 10.5.sp, modifier = Modifier.padding(top = 3.dp, start = 4.dp, end = 4.dp),
                )
            }
        }
    }
}

@Composable
private fun DeclineDialog(onDismiss: () -> Unit, onConfirm: (String) -> Unit) {
    val c = sitePalette()
    var reason by remember { mutableStateOf("") }
    Dialog(onDismissRequest = onDismiss) {
        Column(Modifier.clip(RoundedCornerShape(24.dp)).background(c.surface).padding(20.dp)) {
            Text(stringResource(R.string.ai_req_decline_title), color = c.ink, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp)
            Text(stringResource(R.string.ai_req_decline_sub), color = c.mut, fontSize = 12.5.sp, modifier = Modifier.padding(top = 4.dp, bottom = 8.dp))
            SiteField(stringResource(R.string.ai_req_decline_reason), reason, { reason = it }, singleLine = false, minLines = 3)
            Spacer(Modifier.height(10.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                SecondaryButton(stringResource(R.string.ai_req_cancel), Modifier.weight(1f), color = c.mut) { onDismiss() }
                SecondaryButton(stringResource(R.string.ai_req_decline_confirm), Modifier.weight(1f), color = c.danger) { onConfirm(reason) }
            }
        }
    }
}

/**
 * A person-built site request: where it stands, the quote (accept with the wallet PIN or decline), the
 * countdown, instalments to pay as they are released, the deliverables, and the conversation with the team.
 */
@Composable
internal fun SiteRequestDetail(night: Boolean, id: Int, onClosed: () -> Unit) {
    val c = sitePalette()
    val scope = rememberCoroutineScope()
    val uri = LocalUriHandler.current
    var request by remember { mutableStateOf<AiSiteRequestDto?>(null) }
    var loadFailed by remember { mutableStateOf(false) }
    var tab by rememberSaveable { mutableIntStateOf(0) }
    var busy by remember { mutableStateOf(false) }
    var message by remember { mutableStateOf<String?>(null) }
    var failed by remember { mutableStateOf(false) }
    var askPin by remember { mutableStateOf<(suspend (String) -> AiSiteRequestDto?)?>(null) }
    var pinSub by remember { mutableStateOf("") }
    var noPin by remember { mutableStateOf(false) }
    var decline by remember { mutableStateOf(false) }
    var messages by remember { mutableStateOf<List<AiRequestMessageDto>>(emptyList()) }
    var draft by remember { mutableStateOf("") }
    val scroll = rememberScrollState()

    suspend fun refresh() {
        runCatching { ApiClient.aiSites.request(chatAuth(), id).data }.onSuccess { request = it; loadFailed = it == null }.onFailure { if (request == null) loadFailed = true }
    }

    suspend fun refreshMessages() {
        runCatching { ApiClient.aiSites.requestMessages(chatAuth(), id).data }.onSuccess { list ->
            val grew = (list?.size ?: 0) != messages.size
            messages = list.orEmpty()
            if (grew && tab == 2) scroll.animateScrollTo(scroll.maxValue)
        }
    }

    LaunchedEffect(id) {
        while (true) {
            refresh()
            delay(20_000)
        }
    }

    LaunchedEffect(id, tab) {
        while (tab == 2) {
            refreshMessages()
            delay(8_000)
        }
    }

    val r = request
    if (r == null) {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            if (loadFailed) Text(stringResource(R.string.ai_req_load_failed), color = c.mut) else CircularProgressIndicator(color = Ai.Red)
        }
        return
    }

    val unreadChat = r.unread ?: 0
    val currency = r.currency
    val firstAmount = r.installmentList.filter { it.trigger == "on_accept" }.sumOf { it.amount ?: 0.0 }
    val finished = r.status?.isFinal == true
    val pinAcceptText = stringResource(R.string.ai_req_pin_accept)
    val pinPayText = stringResource(R.string.ai_req_pin_pay)

    fun withPin(subtitle: String, action: suspend (String) -> AiSiteRequestDto?) {
        scope.launch {
            val pin = runCatching { ApiClient.wallet.pinStatus(walletAuth()).data }.getOrNull()
            if (pin?.hasPin == false) { noPin = true } else { pinSub = subtitle; askPin = action }
        }
    }

    fun act(call: suspend () -> AiSiteRequestDto?) {
        busy = true; message = null
        scope.launch {
            runCatching { call() }.fold(
                onSuccess = { updated -> if (updated != null) request = updated; failed = false },
                onFailure = { e -> failed = true; message = e.apiFailure().message },
            )
            busy = false
        }
    }

    Column(Modifier.fillMaxSize()) {
        Column(Modifier.weight(1f).verticalScroll(scroll).padding(horizontal = 16.dp).padding(top = 6.dp, bottom = 24.dp)) {
            RequestHero(r)
            Spacer(Modifier.height(14.dp))
            RequestTabs(tab, unreadChat) { tab = it }
            Spacer(Modifier.height(14.dp))

            when (tab) {
                0 -> {
                    r.quote?.let { q ->
                        SiteCard {
                            SiteSectionTitle(stringResource(R.string.ai_req_quote_title))
                            Spacer(Modifier.height(10.dp))
                            Text(money(q.amount, currency), color = Ai.Red, fontWeight = FontWeight.ExtraBold, fontSize = 28.sp)
                            Spacer(Modifier.height(6.dp))
                            InfoLine(stringResource(R.string.ai_req_duration), stringResource(R.string.ai_req_days, q.durationDays ?: 0))
                            if (r.code == "quote_sent") InfoLine(stringResource(R.string.ai_req_valid_until), fmtDate(q.validUntil))
                            if (!q.notes.isNullOrBlank()) {
                                Text(q.notes, color = c.mut, fontSize = 12.5.sp, lineHeight = 19.sp, modifier = Modifier.padding(top = 6.dp))
                            }
                            if (r.code == "quote_sent") {
                                Spacer(Modifier.height(16.dp))
                                if (q.canAccept == true) {
                                    PrimaryButton(stringResource(R.string.ai_req_accept, money(firstAmount, currency)), enabled = !busy) {
                                        withPin(pinAcceptText) { pin -> ApiClient.aiSites.acceptQuote(chatAuth(), pin, id).data }
                                    }
                                    Spacer(Modifier.height(8.dp))
                                    SecondaryButton(stringResource(R.string.ai_req_decline), Modifier.fillMaxWidth(), color = c.danger, enabled = !busy) { decline = true }
                                } else {
                                    Text(stringResource(R.string.ai_req_quote_expired), color = c.danger, fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold)
                                }
                            }
                        }
                        Spacer(Modifier.height(14.dp))
                    }

                    if (r.quote != null && r.code != "quote_sent") {
                        SiteCard {
                            SiteSectionTitle(stringResource(R.string.ai_req_payments_summary))
                            Spacer(Modifier.height(8.dp))
                            InfoLine(stringResource(R.string.ai_req_total), money(r.payments?.total, currency))
                            InfoLine(stringResource(R.string.ai_req_paid), money(r.payments?.paid, currency))
                            InfoLine(stringResource(R.string.ai_req_remaining), money(r.payments?.remaining, currency))
                            val total = r.payments?.total ?: 0.0
                            val fraction = if (total > 0) ((r.payments?.paid ?: 0.0) / total).toFloat().coerceIn(0f, 1f) else 0f
                            Box(Modifier.padding(top = 10.dp).fillMaxWidth().height(7.dp).clip(RoundedCornerShape(50)).background(c.line)) {
                                Box(Modifier.fillMaxWidth(fraction).height(7.dp).clip(RoundedCornerShape(50)).background(c.success))
                            }
                        }
                        Spacer(Modifier.height(14.dp))
                    }

                    if (!r.declineReason.isNullOrBlank() && r.code == "quote_declined") {
                        SiteCard {
                            Text(stringResource(R.string.ai_req_your_reason), color = c.mut, fontSize = 12.sp)
                            Text(r.declineReason, color = c.ink, fontSize = 13.sp, modifier = Modifier.padding(top = 4.dp))
                        }
                        Spacer(Modifier.height(14.dp))
                    }

                    r.delivery?.let { d ->
                        SiteCard {
                            SiteSectionTitle(stringResource(R.string.ai_req_handover_title), stringResource(R.string.ai_req_handover_sub))
                            if (!d.notes.isNullOrBlank()) Text(d.notes, color = c.ink, fontSize = 13.sp, lineHeight = 20.sp, modifier = Modifier.padding(top = 8.dp))
                            if (!d.url.isNullOrBlank()) {
                                Spacer(Modifier.height(12.dp))
                                PrimaryButton(stringResource(R.string.ai_req_open_site)) { runCatching { uri.openUri(d.url) } }
                            }
                        }
                        Spacer(Modifier.height(14.dp))
                    }

                    if (r.code in listOf("submitted", "under_review", "quote_sent", "quote_declined")) {
                        SecondaryButton(stringResource(R.string.ai_req_cancel_request), Modifier.fillMaxWidth(), color = c.danger, enabled = !busy) {
                            act { ApiClient.aiSites.cancelRequest(chatAuth(), id).data }
                        }
                    }
                }

                1 -> {
                    if (r.installmentList.isEmpty() && r.milestoneList.isEmpty()) {
                        SiteCard { Text(stringResource(R.string.ai_req_nothing_yet), color = c.mut, fontSize = 13.sp, lineHeight = 20.sp) }
                    }
                    if (r.installmentList.isNotEmpty()) {
                        SiteCard {
                            SiteSectionTitle(stringResource(R.string.ai_req_payments_title), stringResource(R.string.ai_req_payments_sub))
                            Spacer(Modifier.height(6.dp))
                            r.installmentList.forEach { p ->
                                PaymentRow(p, currency, busy) {
                                    withPin(pinPayText) { pin -> ApiClient.aiSites.payInstallment(chatAuth(), pin, id, p.id).data }
                                }
                            }
                        }
                        Spacer(Modifier.height(14.dp))
                    }
                    if (r.milestoneList.isNotEmpty()) {
                        SiteCard {
                            SiteSectionTitle(stringResource(R.string.ai_req_schedule_title), stringResource(R.string.ai_req_schedule_sub))
                            Spacer(Modifier.height(6.dp))
                            r.milestoneList.forEach { m ->
                                val done = m.status == "delivered"
                                Row(Modifier.fillMaxWidth().padding(vertical = 8.dp), verticalAlignment = Alignment.Top) {
                                    Icon(if (done) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, contentDescription = null, tint = if (done) c.success else c.mut, modifier = Modifier.size(22.dp))
                                    Spacer(Modifier.width(12.dp))
                                    Column(Modifier.weight(1f)) {
                                        Text(m.title.orEmpty(), color = c.ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                                        if (!m.description.isNullOrBlank()) Text(m.description, color = c.mut, fontSize = 12.sp, modifier = Modifier.padding(top = 2.dp))
                                        val sub = when {
                                            done -> stringResource(R.string.ai_req_ms_delivered)
                                            m.status == "in_progress" -> stringResource(R.string.ai_req_ms_working)
                                            m.dueAt != null -> stringResource(R.string.ai_req_ms_due, fmtDate(m.dueAt))
                                            else -> ""
                                        }
                                        if (sub.isNotEmpty()) Text(sub, color = if (done) c.success else c.mut, fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 3.dp))
                                    }
                                }
                            }
                        }
                    }
                }

                else -> {
                    if (messages.isEmpty()) Text(stringResource(R.string.ai_req_chat_empty), color = c.mut, fontSize = 13.sp, modifier = Modifier.padding(vertical = 24.dp).fillMaxWidth())
                    messages.forEach { ChatBubble(it) }
                }
            }

            message?.let { Text(it, color = if (failed) c.danger else c.success, fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 12.dp)) }
        }

        if (tab == 2 && !(finished && r.code != "completed")) {
            Row(
                Modifier.fillMaxWidth().background(c.bg).navigationBarsPadding().imePadding().padding(horizontal = 16.dp, vertical = 10.dp),
                verticalAlignment = Alignment.Bottom,
                horizontalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                Box(Modifier.weight(1f)) { SiteField(stringResource(R.string.ai_req_write_message), draft, { draft = it }, singleLine = false) }
                Box(
                    Modifier.padding(bottom = 7.dp).size(52.dp).clip(CircleShape).background(if (draft.isBlank()) Ai.Red.copy(alpha = 0.35f) else Ai.Red)
                        .clickable(enabled = draft.isNotBlank() && !busy) {
                            val text = draft.trim()
                            draft = ""
                            scope.launch {
                                runCatching { ApiClient.aiSites.sendRequestMessage(chatAuth(), id, text).data }.onSuccess { messages = it.orEmpty(); scroll.animateScrollTo(scroll.maxValue) }.onFailure { draft = text; message = it.apiFailure().message; failed = true }
                            }
                        },
                    contentAlignment = Alignment.Center,
                ) { Icon(Icons.Rounded.Send, contentDescription = null, tint = Color.White, modifier = Modifier.size(22.dp)) }
            }
        }
    }

    if (decline) {
        DeclineDialog(onDismiss = { decline = false }, onConfirm = { reason -> decline = false; act { ApiClient.aiSites.declineQuote(chatAuth(), id, reason).data } })
    }

    if (noPin) AiInfoDialog(night = night, message = stringResource(R.string.ai_subscription_no_pin_set), onDismiss = { noPin = false })

    askPin?.let { action ->
        AiWalletPinSheet(
            night = night,
            subtitle = pinSub,
            onDismiss = { askPin = null },
            onSubmit = { pin ->
                runCatching { action(pin) }.fold(
                    onSuccess = { updated -> if (updated != null) request = updated; message = null; PadResult.Ok },
                    onFailure = { e ->
                        val failure = e.apiFailure()
                        val until = failure.lockedUntil?.let { runCatching { Instant.parse(it).toEpochMilli() }.getOrNull() }
                        if (failure.errorCode == "wallet_pin_locked" && until != null) PadResult.Locked(until) else PadResult.Error(failure.message ?: "")
                    },
                )
            },
        )
    }
}
