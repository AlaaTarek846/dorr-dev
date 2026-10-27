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
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Inbox
import androidx.compose.material.icons.rounded.KeyboardArrowDown
import androidx.compose.material.icons.rounded.MarkChatUnread
import androidx.compose.material.icons.rounded.KeyboardArrowUp
import androidx.compose.material.icons.rounded.Search
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
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshotFlow
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
import com.dorr.app.network.LifeInboxCountsDto
import com.dorr.app.network.LifeInboxDto
import com.dorr.app.network.LifeItemDto
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.time.ZoneId

/*
 * The life inbox (spec 147): not how many messages came, but what needs something from me —
 * an answer, a payment, an approval, a follow-up, an appointment. Each row opens where it is.
 */

private data class InboxSection(val key: String, val emoji: String, val title: Int, val color: Color)

private val inboxOrder = listOf(
    InboxSection("reply", "💬", R.string.li_reply, Color(0xFF2563EB)),
    InboxSection("pay", "💸", R.string.li_pay, Color(0xFF16A34A)),
    InboxSection("approve", "✅", R.string.li_approve, Color(0xFFF59E0B)),
    InboxSection("follow", "📌", R.string.li_follow, Color(0xFF7C3AED)),
    InboxSection("appointments", "🗓️", R.string.li_appointments, Color(0xFF0891B2)),
)

private fun LifeInboxCountsDto?.of(key: String): Int = when (key) {
    "reply" -> this?.reply ?: 0
    "pay" -> this?.pay ?: 0
    "approve" -> this?.approve ?: 0
    "follow" -> this?.follow ?: 0
    else -> this?.appointments ?: 0
}

private fun LifeInboxDto.itemsOf(key: String): List<LifeItemDto> = when (key) {
    "reply" -> reply
    "pay" -> pay
    "approve" -> approve
    "follow" -> follow
    else -> appointments
}

private fun LifeItemDto.rowKey(): String = id ?: messageId ?: "$kind-$at-$title"

@Composable
fun LifeInboxPage() {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    val listState = rememberLazyListState()
    val zone = remember { ZoneId.systemDefault().id }
    var data by remember { mutableStateOf<LifeInboxDto?>(null) }
    var filter by remember { mutableStateOf<String?>(null) }
    var items by remember { mutableStateOf<List<LifeItemDto>>(emptyList()) }
    var page by remember { mutableIntStateOf(1) }
    var hasMore by remember { mutableStateOf(false) }
    var loadingMore by remember { mutableStateOf(false) }
    var loadedSection by remember { mutableStateOf<String?>(null) }
    var query by remember { mutableStateOf("") }
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
    suspend fun load(section: String, reset: Boolean) {
        if (!reset && (loadingMore || !hasMore)) return
        if (!reset) loadingMore = true
        val next = if (reset) 1 else page + 1
        val q = query.trim().ifBlank { null }
        val envelope = runCatching { ApiClient.life.lifeInbox(chatAuth(), zone, section = section, page = next, perPage = 15, q = q) }.getOrNull()
        val body = envelope?.data
        if (body != null) data = body
        val chunk = body?.itemsOf(section).orEmpty()
        items = if (reset) chunk else {
            val known = items.map { it.rowKey() }.toSet()
            items + chunk.filter { it.rowKey() !in known }
        }
        page = next
        hasMore = envelope?.pagination?.hasMorePages == true
        loadedSection = section
        if (!reset) loadingMore = false
    }
    LaunchedEffect(query) {
        if (query.isNotBlank()) delay(280)
        val q = query.trim().ifBlank { null }
        val envelope = runCatching { ApiClient.life.lifeInbox(chatAuth(), zone, perPage = 15, q = q) }.getOrNull()
        val body = envelope?.data ?: LifeInboxDto()
        data = body
        val first = inboxOrder.firstOrNull { body.counts.of(it.key) > 0 }?.key
        val current = filter
        if (current == null || body.counts.of(current) == 0) filter = first
        val section = filter ?: first
        if (section != null) {
            items = body.itemsOf(section)
            page = 1
            hasMore = body.counts.of(section) > items.size
            loadedSection = section
        } else {
            items = emptyList()
            page = 1
            hasMore = false
            loadedSection = null
        }
    }
    LaunchedEffect(filter) {
        val section = filter ?: return@LaunchedEffect
        if (data == null || section == loadedSection) return@LaunchedEffect
        load(section, reset = true)
    }
    LaunchedEffect(listState, items.size, hasMore, filter) {
        snapshotFlow { listState.layoutInfo.visibleItemsInfo.lastOrNull()?.index to listState.layoutInfo.totalItemsCount }
            .collect { (last, total) ->
                val section = filter ?: return@collect
                if (last != null && total > 0 && last >= total - 3) {
                    scope.launch { load(section, reset = false) }
                }
            }
    }

    ChPage(stringResource(R.string.li_title), onBack = { host.pop() }, settingsHeader = true) {
        val d = data
        Column(Modifier.fillMaxSize().padding(horizontal = 16.dp)) {
            ChField(
                query, { query = it.take(80) }, stringResource(R.string.li_search),
                icon = Icons.Rounded.Search, clearable = true, fontSize = 13.sp,
                modifier = Modifier.padding(top = 4.dp, bottom = 4.dp),
            )
            if (d == null) {
                repeat(4) { Box(Modifier.fillMaxWidth().height(80.dp).padding(top = 16.dp).clip(RoundedCornerShape(22.dp)).background(Ch.SurfaceMuted)) }
                return@Column
            }
            val chips = inboxOrder.filter { d.counts.of(it.key) > 0 }
            Row(
                Modifier.fillMaxWidth().padding(top = 12.dp).clip(RoundedCornerShape(18.dp)).background(Brush.linearGradient(listOf(Ch.Red, Ch.RedDeep))).padding(horizontal = 12.dp, vertical = 10.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Box(
                    Modifier.size(28.dp).clip(RoundedCornerShape(9.dp)).background(Color.White.copy(alpha = 0.18f)),
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(Icons.Rounded.Inbox, null, tint = Color.White, modifier = Modifier.size(16.dp))
                }
                Spacer(Modifier.width(8.dp))
                Text(stringResource(R.string.li_needs_you), color = Color.White, fontSize = 13.5.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                CountUp(d.total)
            }
            Row(
                Modifier.fillMaxWidth().padding(top = 12.dp).horizontalScroll(rememberScrollState()),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                chips.forEach { s ->
                    val on = filter == s.key
                    Row(
                        Modifier.clip(RoundedCornerShape(16.dp)).background(if (on) s.color else Ch.Surface).clickable { filter = s.key }.padding(horizontal = 12.dp, vertical = 10.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(s.emoji, fontSize = 14.sp)
                        Spacer(Modifier.width(6.dp))
                        Text("${d.counts.of(s.key)}", color = if (on) Color.White else s.color, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                        Spacer(Modifier.width(6.dp))
                        Text(stringResource(s.title), color = if (on) Color.White else Ch.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, maxLines = 1)
                    }
                }
            }
            if (d.messageRequests > 0) {
                Row(
                    Modifier.fillMaxWidth().padding(top = 12.dp).clip(RoundedCornerShape(18.dp)).background(Ch.Surface).clickable { host.showRequests() }.padding(14.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Box(Modifier.size(32.dp).clip(RoundedCornerShape(10.dp)).background(Ch.Red.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                        Icon(Icons.Rounded.MarkChatUnread, null, tint = Ch.Red, modifier = Modifier.size(16.dp))
                    }
                    Spacer(Modifier.width(10.dp))
                    Text(stringResource(R.string.ch_requests_banner), color = Ch.Ink, fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                    ChBadge(d.messageRequests)
                }
            }
            val section = inboxOrder.firstOrNull { it.key == filter }
            when {
                d.total == 0 -> {
                    Spacer(Modifier.height(20.dp))
                    ChEmptyState(Icons.Rounded.Inbox, stringResource(R.string.li_empty_title), stringResource(R.string.li_empty_text), animated = true)
                }
                else -> LazyColumn(
                    state = listState,
                    contentPadding = PaddingValues(top = 14.dp, bottom = 24.dp),
                    modifier = Modifier.fillMaxWidth().weight(1f),
                ) {
                    itemsIndexed(items, key = { _, item -> item.rowKey() }) { i, item ->
                        InboxRow(item, section?.color ?: Ch.Red, i) { open(item) }
                    }
                    if (loadingMore) {
                        item(key = "inbox-more") {
                            Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) {
                                CircularProgressIndicator(color = Ch.Red, modifier = Modifier.size(22.dp), strokeWidth = 2.dp)
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun CountUp(target: Int) {
    val n = remember { Animatable(0f) }
    LaunchedEffect(target) { n.animateTo(target.toFloat(), tween(700, easing = FastOutSlowInEasing)) }
    Text(
        if (target == 0) stringResource(R.string.li_nothing) else stringResource(R.string.li_n_things, n.value.toInt()),
        color = Color.White, fontSize = 13.5.sp, fontWeight = FontWeight.ExtraBold,
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
    var open by remember(item.rowKey()) { mutableStateOf(false) }
    val enter = remember { Animatable(0f) }
    LaunchedEffect(Unit) { enter.animateTo(1f, tween(360, delayMillis = (index * 40).coerceAtMost(320))) }
    val who = item.by ?: item.chat
    Row(
        Modifier.fillMaxWidth().padding(bottom = 8.dp).graphicsLayer { alpha = enter.value; translationY = (1 - enter.value) * 18f }
            .clip(RoundedCornerShape(18.dp)).background(Ch.Surface).clickable(onClick = onClick).padding(14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.width(4.dp).height(36.dp).clip(CircleShape).background(if (item.late) Ch.Danger else color))
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(
                listOfNotNull(kindLabel(item.kind), who).joinToString(" · "),
                color = if (item.late) Ch.Danger else color,
                fontSize = 11.5.sp,
                fontWeight = FontWeight.Bold,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            Text(
                item.title.orEmpty().ifBlank { "—" },
                color = Ch.Ink,
                fontSize = 14.5.sp,
                fontWeight = FontWeight.SemiBold,
                maxLines = if (open) 8 else 1,
                overflow = TextOverflow.Ellipsis,
                modifier = Modifier.padding(top = 4.dp),
            )
            val whenText = if (item.allDay) item.date else lifeWhen(item.dueAt ?: item.at.takeIf { item.kind in setOf("appointment", "task") })
            listOfNotNull(whenText, item.location).joinToString(" · ").takeIf { it.isNotBlank() }?.let {
                Text((if (item.late) "⏰ " else "") + it, color = if (item.late) Ch.Danger else Ch.Mut, fontSize = 12.sp, modifier = Modifier.padding(top = 2.dp))
            }
        }
        Spacer(Modifier.width(8.dp))
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Box(
                Modifier.size(28.dp).clip(RoundedCornerShape(8.dp)).background(Ch.SurfaceMuted).clickable { open = !open },
                contentAlignment = Alignment.Center,
            ) {
                Icon(if (open) Icons.Rounded.KeyboardArrowUp else Icons.Rounded.KeyboardArrowDown, null, tint = Ch.Mut, modifier = Modifier.size(18.dp))
            }
            item.amountMinor?.let { minor ->
                Text(
                    "%.2f %s".format(minor / 100.0, item.currency.orEmpty()),
                    color = Color(0xFF16A34A),
                    fontSize = 12.sp,
                    fontWeight = FontWeight.ExtraBold,
                    maxLines = 1,
                    modifier = Modifier.padding(top = 4.dp),
                )
            }
        }
    }
}
