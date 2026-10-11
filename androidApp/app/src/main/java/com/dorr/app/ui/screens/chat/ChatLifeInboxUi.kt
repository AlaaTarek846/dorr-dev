package com.dorr.app.ui.screens.chat

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Inbox
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.LifeInboxDto
import com.dorr.app.network.LifeItemDto
import java.time.ZoneId

/*
 * The life inbox (spec 147): not how many messages came, but what needs something from me —
 * an answer, a payment, an approval, a follow-up, an appointment. Each row opens where it is.
 */

private data class InboxSection(val key: String, val emoji: String, val title: Int, val color: Color, val items: List<LifeItemDto>)

@Composable
fun LifeInboxPage() {
    val host = LocalChat.current
    var data by remember { mutableStateOf<LifeInboxDto?>(null) }
    var filter by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(Unit) { data = runCatching { ApiClient.life.lifeInbox(chatAuth(), ZoneId.systemDefault().id).data }.getOrNull() ?: LifeInboxDto() }
    val open: (LifeItemDto) -> Unit = { item ->
        when {
            item.kind == "decision" && item.id != null -> host.push(ChRoute.DecisionRoom(item.id))
            item.kind in setOf("appointment", "task") -> host.push(if (item.kind == "task") ChRoute.Tasks else ChRoute.Commitments)
            item.conversationId != null -> {
                item.messageId?.let { host.focusRequest = item.conversationId to it }
                host.push(ChRoute.Conversation(item.conversationId))
            }
        }
    }

    ChPage(stringResource(R.string.li_title), onBack = { host.pop() }) {
        val d = data
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp)) {
            if (d == null) {
                repeat(4) { Box(Modifier.fillMaxWidth().height(80.dp).padding(bottom = 10.dp).clip(RoundedCornerShape(22.dp)).background(Ch.SurfaceMuted)) }
                return@Column
            }
            val sections = listOf(
                InboxSection("reply", "💬", R.string.li_reply, Color(0xFF2563EB), d.reply),
                InboxSection("pay", "💸", R.string.li_pay, Color(0xFF16A34A), d.pay),
                InboxSection("approve", "✅", R.string.li_approve, Color(0xFFF59E0B), d.approve),
                InboxSection("follow", "📌", R.string.li_follow, Color(0xFF7C3AED), d.follow),
                InboxSection("appointments", "🗓️", R.string.li_appointments, Color(0xFF0891B2), d.appointments),
            )
            // The summary: how much, by kind — tap one to see only it.
            Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(26.dp)).background(Brush.linearGradient(listOf(Ch.Red, Ch.RedDeep))).padding(16.dp)) {
                Text(stringResource(R.string.li_needs_you), color = Color.White.copy(alpha = 0.8f), fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
                CountUp(d.total)
                Row(Modifier.padding(top = 10.dp).horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    sections.filter { it.items.isNotEmpty() }.forEach { s ->
                        val on = filter == s.key
                        Text(
                            "${s.emoji} ${s.items.size}", color = if (on) Ch.Red else Color.White, fontSize = 13.sp, fontWeight = FontWeight.Bold,
                            modifier = Modifier.clip(CircleShape).background(if (on) Color.White else Color.White.copy(alpha = 0.16f)).clickable { filter = if (on) null else s.key }.padding(horizontal = 12.dp, vertical = 7.dp),
                        )
                    }
                }
            }
            if (d.messageRequests > 0) {
                Row(
                    Modifier.fillMaxWidth().padding(top = 12.dp).clip(RoundedCornerShape(18.dp)).background(Ch.Surface).clickable { host.filter = "requests"; host.pop() }.padding(14.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text("📨", fontSize = 18.sp)
                    Spacer(Modifier.width(10.dp))
                    Text(stringResource(R.string.li_requests, d.messageRequests), color = Ch.Ink, fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                    Text("›", color = Ch.Soft, fontSize = 20.sp)
                }
            }
            if (d.total == 0) {
                Spacer(Modifier.height(20.dp))
                ChEmptyState(Icons.Rounded.Inbox, stringResource(R.string.li_empty_title), stringResource(R.string.li_empty_text), animated = true)
            }
            sections.filter { it.items.isNotEmpty() && (filter == null || filter == it.key) }.forEach { s ->
                Row(Modifier.fillMaxWidth().padding(top = 18.dp, bottom = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(28.dp).clip(RoundedCornerShape(9.dp)).background(s.color.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) { Text(s.emoji, fontSize = 14.sp) }
                    Spacer(Modifier.width(8.dp))
                    Text(stringResource(s.title), color = Ch.Ink, fontSize = 15.5.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                    Text("${s.items.size}", color = s.color, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
                }
                s.items.forEachIndexed { i, item -> InboxRow(item, s.color, i) { open(item) } }
            }
            Spacer(Modifier.height(24.dp))
        }
    }
}

@Composable
private fun CountUp(target: Int) {
    val n = remember { Animatable(0f) }
    LaunchedEffect(target) { n.animateTo(target.toFloat(), tween(700, easing = FastOutSlowInEasing)) }
    Text(
        if (target == 0) stringResource(R.string.li_nothing) else stringResource(R.string.li_n_things, n.value.toInt()),
        color = Color.White, fontSize = 24.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 2.dp),
    )
}

@Composable
private fun kindLabel(kind: String): String = stringResource(
    when (kind) {
        "needs_reply" -> R.string.li_k_needs_reply
        "mention" -> R.string.li_k_mention
        "money_request" -> R.string.li_k_money_request
        "bill_split" -> R.string.li_k_bill_split
        "commitment" -> R.string.li_k_commitment
        "handoff" -> R.string.li_k_handoff
        "agreement" -> R.string.li_k_agreement
        "join_request" -> R.string.li_k_join_request
        "decision" -> R.string.li_k_decision
        "my_commitment" -> R.string.li_k_my_commitment
        "my_handoff" -> R.string.li_k_my_handoff
        "waiting_for" -> R.string.li_k_waiting_for
        "task" -> R.string.li_k_task
        else -> R.string.li_k_appointment
    },
)

@Composable
private fun InboxRow(item: LifeItemDto, color: Color, index: Int, onClick: () -> Unit) {
    val enter = remember { Animatable(0f) }
    LaunchedEffect(Unit) { enter.animateTo(1f, tween(360, delayMillis = (index * 40).coerceAtMost(320))) }
    Row(
        Modifier.fillMaxWidth().padding(bottom = 8.dp).graphicsLayer { alpha = enter.value; translationY = (1 - enter.value) * 18f }
            .clip(RoundedCornerShape(18.dp)).background(Ch.Surface).clickable(onClick = onClick).padding(14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.width(4.dp).height(36.dp).clip(CircleShape).background(if (item.late) Ch.Danger else color))
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(
                listOfNotNull(kindLabel(item.kind), item.by, item.chat?.takeIf { it != item.by }).joinToString(" · "),
                color = if (item.late) Ch.Danger else color, fontSize = 11.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis,
            )
            Text(item.title.orEmpty().ifBlank { "—" }, color = Ch.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.SemiBold, maxLines = 2, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 2.dp))
            val whenText = if (item.allDay) item.date else lifeWhen(item.dueAt ?: item.at.takeIf { item.kind in setOf("appointment", "task") })
            listOfNotNull(whenText, item.location).joinToString(" · ").takeIf { it.isNotBlank() }?.let {
                Text((if (item.late) "⏰ " else "") + it, color = if (item.late) Ch.Danger else Ch.Mut, fontSize = 12.sp, modifier = Modifier.padding(top = 2.dp))
            }
        }
        item.amountMinor?.let { minor ->
            Text("%.2f %s".format(minor / 100.0, item.currency.orEmpty()), color = Color(0xFF16A34A), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(start = 8.dp))
        }
    }
}
