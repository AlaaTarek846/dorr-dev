package com.dorr.freelance.ui.screens

import android.widget.Toast
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AddCircleOutline
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.EventBusy
import androidx.compose.material.icons.rounded.EventRepeat
import androidx.compose.material.icons.rounded.FormatQuote
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.Inbox
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.Replay
import androidx.compose.material.icons.rounded.Route
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.ShoppingBag
import androidx.compose.material.icons.rounded.TaskAlt
import androidx.compose.material.icons.rounded.Work
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.OrderAnswerRequest
import com.dorr.freelance.net.PagedRows
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.ServiceOrderDto
import com.dorr.freelance.net.ServiceSubscriptionDto
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.EmptyState
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.Page
import com.dorr.freelance.ui.components.PagedFooter
import com.dorr.freelance.ui.components.SkeletonDetail
import com.dorr.freelance.ui.components.SkeletonList
import com.dorr.freelance.ui.kit.AccountDark
import com.dorr.freelance.ui.kit.ConfirmDialog
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsNight
import com.dorr.freelance.ui.kit.settingsSurface
import com.dorr.freelance.ui.wallet.longDate
import com.dorr.freelance.ui.wallet.timeOf
import kotlinx.coroutines.launch

private val ORDER_TABS = listOf("awaiting", "active", "completed", "ended", "monthly")

/**
 * FL-L04, the orders clients placed on my services: four lists (waiting for my answer first, in progress, completed, ended), each card
 * with the service, the level, the client, what I get and — while it waits — the time left to answer by the phone's clock. Read when
 * the page opens, when the list changes, when the connection returns and when a notice arrives; pages fill in while scrolling.
 */
@Composable
fun ServiceOrdersScreen(onBack: () -> Unit, onOpenOrder: (String) -> Unit) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var tab by rememberSaveable { mutableStateOf("awaiting") }
    val paged = remember(tab) { PagedRows<ServiceOrderDto>(15, { it.uuid }, { page -> apiCall { ApiClient.services.orders(status = tab, page = page, perPage = 15) } }) }
    // FL-L05: the monthly subscriptions to my services (active first).
    val subs = remember { PagedRows<ServiceSubscriptionDto>(15, { it.uuid }, { page -> apiCall { ApiClient.services.subscriptions(page = page, perPage = 15) } }) }
    val monthly = tab == "monthly"

    LaunchedEffect(tab, reconnect) { if (monthly) subs.reload() else paged.reload() }
    LaunchedEffect(tab) { Realtime.changed.collect { if (monthly) subs.reload() else paged.reload() } }

    Page(title = stringResource(R.string.svo_title), onBack = onBack, onNearEnd = { scope.launch { if (monthly) subs.loadMore() else paged.loadMore() } }) {
        Segmented(ORDER_TABS.map { it to orderTabLabel(it) }, tab, marks = if (tab != "awaiting" || paged.rows.isEmpty()) emptySet() else setOf("awaiting")) { tab = it }
        if (monthly) {
            SubscriptionList(subs, onOpenOrder)
            return@Page
        }
        when {
            !paged.loaded && paged.failure == null -> SkeletonList()
            paged.loaded && paged.rows.isEmpty() && paged.failure == null -> EmptyState(
                icon = Icons.Rounded.Inbox,
                title = stringResource(if (tab == "awaiting") R.string.svo_empty_awaiting else R.string.svo_empty_other),
                body = stringResource(R.string.svo_empty_body),
                modifier = Modifier.padding(top = 70.dp),
            )
            else -> paged.rows.forEach { o -> OrderCard(o) { onOpenOrder(o.uuid) } }
        }
        PagedFooter(paged) { scope.launch { paged.loadMore() } }
        ErrorBanner(paged.failure?.let { it.errorText(offlineText) ?: genericError }, Modifier.fillMaxWidth())
    }
}

@Composable
private fun orderTabLabel(tab: String): String = when (tab) {
    "active" -> stringResource(R.string.svo_tab_active)
    "completed" -> stringResource(R.string.svo_tab_completed)
    "ended" -> stringResource(R.string.svo_tab_ended)
    "monthly" -> stringResource(R.string.sub_tab)
    else -> stringResource(R.string.svo_tab_awaiting)
}

@Composable
private fun orderStatusLabel(status: String): String = when (status) {
    "active" -> stringResource(R.string.svo_status_active)
    "completed" -> stringResource(R.string.svo_status_completed)
    "declined" -> stringResource(R.string.svo_status_declined)
    "expired" -> stringResource(R.string.svo_status_expired)
    "cancelled" -> stringResource(R.string.svo_status_cancelled)
    else -> stringResource(R.string.svo_status_awaiting)
}

@Composable
private fun orderTone(status: String): SvcTone = serviceTone(if (status == "cancelled" || status == "expired") "paused" else status)

/** "5 h left to answer", or null when it isn't waiting. */
@Composable
private fun leftText(o: ServiceOrderDto): String? {
    if (o.status != "awaiting") return null
    val left = timeLeft(o.answerBy) ?: return null
    return when {
        left.isNegative || left.isZero -> stringResource(R.string.svo_left_ended)
        left.toHours() >= 1 -> stringResource(R.string.svo_left_hours, left.toHours().toInt())
        else -> stringResource(R.string.svo_left_minutes, left.toMinutes().coerceAtLeast(1).toInt())
    }
}

@Composable
private fun OrderCard(o: ServiceOrderDto, onClick: () -> Unit) {
    val tone = orderTone(o.status)
    Column(
        Modifier.fillMaxWidth().widthIn(max = 520.dp).settingsSurface(RoundedCornerShape(20.dp)).clickable(onClick = onClick).padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(o.service.title.orEmpty(), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                Text(listOfNotNull(levelLabel(o.level), o.tierName?.takeIf { it.isNotBlank() }).joinToString(" · "), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
            }
            Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(4.dp)) {
                SvcPill(orderStatusLabel(o.status), tone)
                // FL-L05: one month of a subscription.
                o.subscription?.let { SvcPill(stringResource(R.string.sub_month_n, it.cycle), SvcTone(settingsAccent(), settingsAccent().copy(alpha = 0.10f))) }
            }
        }
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Initial(o.client?.name)
            Text(o.client?.name.orEmpty(), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
            Icon(Icons.Rounded.Schedule, contentDescription = null, tint = settingsMut(), modifier = Modifier.size(15.dp))
            Text(daysLabel(o.deliveryDays), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
        }
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.06f)).padding(horizontal = 12.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(stringResource(R.string.svo_you_get, svcMoney(o.money.netMinor, o.decimals, o.currency)), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
            if (o.addons.isNotEmpty()) Text("+${o.addons.size}", color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.ExtraBold)
        }
        leftText(o)?.let { text ->
            val warn = serviceTone("awaiting")
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Icon(Icons.Rounded.HourglassTop, contentDescription = null, tint = warn.ink, modifier = Modifier.size(16.dp))
                Text(text, color = warn.ink, fontSize = 12.sp, fontWeight = FontWeight.Bold)
            }
        }
    }
}

@Composable
private fun Initial(name: String?) {
    Box(Modifier.size(30.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.16f)), contentAlignment = Alignment.Center) {
        Text(name?.trim()?.firstOrNull()?.uppercase() ?: "?", color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
    }
}

/**
 * One order. While it waits: the time left (and the hour it ends), the client, what was ordered (the level and what it includes, the
 * extras, the revisions and days), the client's answer to my question, the money (price, commission, what I get), what happens after
 * accepting, then Accept (asked once more) or Decline (with an optional reason). Once accepted it opens the work — the agreement screen.
 */
@Composable
fun ServiceOrderScreen(uuid: String, onBack: () -> Unit, onOpenWork: (String) -> Unit) {
    val context = LocalContext.current
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var order by remember { mutableStateOf<ServiceOrderDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var asking by remember { mutableStateOf(false) }
    var declining by remember { mutableStateOf(false) }
    var reason by rememberSaveable { mutableStateOf("") }

    suspend fun load() {
        when (val r = apiCall { ApiClient.services.order(uuid) }) {
            is ApiResult.Ok -> { order = r.data; error = null }
            else -> error = r.errorText(offlineText) ?: genericError
        }
    }
    LaunchedEffect(reconnect) { load() }
    LaunchedEffect(Unit) { Realtime.changed.collect { load() } }

    fun answer(action: String) {
        busy = true
        scope.launch {
            val r = apiCall { ApiClient.services.answer(uuid, action, OrderAnswerRequest(reason.trim().ifEmpty { null })) }
            busy = false
            asking = false
            declining = false
            if (r is ApiResult.Ok) {
                order = r.data
                Toast.makeText(context, context.getString(if (action == "accept") R.string.svo_accepted else R.string.svo_declined_done), Toast.LENGTH_SHORT).show()
            } else {
                error = r.errorText(offlineText) ?: genericError
                load()
            }
        }
    }

    Page(title = stringResource(R.string.svo_detail_title), onBack = onBack) {
        val o = order
        if (o == null) {
            if (error == null) SkeletonDetail()
        } else {
            OrderHead(o)
            InfoCard(title = stringResource(R.string.svo_ordered), icon = Icons.Rounded.ShoppingBag) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    SvcPill(levelLabel(o.level), serviceTone("draft").copy(ink = settingsAccent(), fill = settingsAccent().copy(alpha = 0.10f)))
                    o.tierName?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold) }
                }
                o.features.forEach { f ->
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Icon(Icons.Rounded.CheckCircle, contentDescription = null, tint = Color(0xFF16A34A), modifier = Modifier.size(16.dp))
                        Text(f, color = settingsInk(), fontSize = 13.sp)
                    }
                }
                if (o.addons.isNotEmpty()) {
                    Text(stringResource(R.string.svo_extras), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 4.dp))
                    o.addons.forEach { a ->
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Icon(Icons.Rounded.AddCircleOutline, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(16.dp))
                            Text(a.title + if (a.extraDays > 0) " · +${daysLabel(a.extraDays)}" else "", color = settingsInk(), fontSize = 13.sp, modifier = Modifier.weight(1f))
                            Text(svcMoney(a.priceMinor, o.decimals, o.currency), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                        }
                    }
                }
                Row(Modifier.padding(top = 4.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Fact(Icons.Rounded.Schedule, daysLabel(o.deliveryDays), Modifier.weight(1f))
                    Fact(Icons.Rounded.Replay, if (o.revisions > 0) stringResource(R.string.svc_revisions, o.revisions) else stringResource(R.string.svc_no_revisions), Modifier.weight(1f))
                }
            }
            o.note?.takeIf { it.isNotBlank() }?.let { note ->
                InfoCard(title = stringResource(R.string.svo_note), icon = Icons.Rounded.FormatQuote) {
                    Text(note, color = settingsInk(), fontSize = 14.sp, lineHeight = 21.sp,
                        modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.06f)).padding(12.dp))
                }
            }
            InfoCard(title = stringResource(R.string.svo_money), icon = Icons.Rounded.Payments) {
                MoneyLine(stringResource(R.string.svo_price), svcMoney(o.money.priceMinor, o.decimals, o.currency))
                MoneyLine(stringResource(R.string.svo_commission), "− " + svcMoney(o.money.commissionMinor, o.decimals, o.currency), muted = true)
                Box(Modifier.fillMaxWidth().height(1.dp).background(if (settingsNight()) AccountDark.line else Color(0xFFEEF0F3)))
                MoneyLine(stringResource(R.string.svo_net), svcMoney(o.money.netMinor, o.decimals, o.currency), strong = true)
                Text(stringResource(R.string.svo_money_hint), color = settingsMut(), fontSize = 11.sp, lineHeight = 16.sp)
            }
            if (o.status == "awaiting") NextSteps()

            when {
                o.can.accept -> Column(Modifier.fillMaxWidth().widthIn(max = 520.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    SettingsPrimaryButton(text = stringResource(R.string.svo_accept), loading = busy && !declining, onClick = { asking = true })
                    Row(
                        Modifier.fillMaxWidth().height(50.dp).clip(RoundedCornerShape(18.dp))
                            .border(1.5.dp, if (settingsNight()) AccountDark.line else settingsAccent().copy(alpha = 0.3f), RoundedCornerShape(18.dp))
                            .clickable(enabled = !busy) { declining = true },
                        horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
                    ) { Text(stringResource(R.string.svo_decline), color = settingsAccent(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold) }
                }
                (o.status == "active" || o.status == "completed") && o.project != null ->
                    SettingsPrimaryButton(text = stringResource(R.string.svo_open_work), modifier = Modifier.widthIn(max = 520.dp), onClick = { onOpenWork(o.project) })
                o.status == "declined" || o.status == "expired" || o.status == "cancelled" -> Text(
                    stringResource(when (o.status) { "declined" -> R.string.svo_ended_declined; "expired" -> R.string.svo_ended_expired; else -> R.string.svo_ended_cancelled }),
                    color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp,
                )
            }
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }

    val o = order
    if (asking && o != null) {
        ConfirmDialog(
            icon = Icons.Rounded.TaskAlt, title = stringResource(R.string.svo_accept_title),
            message = stringResource(R.string.svo_accept_body, o.service.title.orEmpty(), daysLabel(o.deliveryDays)),
            confirmLabel = stringResource(R.string.svo_accept), loading = busy, onConfirm = { answer("accept") }, onDismiss = { asking = false },
        )
    }
    if (declining) {
        AlertDialog(
            onDismissRequest = { if (!busy) declining = false },
            title = { Text(stringResource(R.string.svo_decline_title), fontWeight = FontWeight.ExtraBold) },
            text = { DorrTextField(reason, { reason = it.take(500) }, label = stringResource(R.string.svo_decline_reason), singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth()) },
            confirmButton = { TextButton(enabled = !busy, onClick = { answer("decline") }) { Text(stringResource(R.string.svo_decline_confirm), color = serviceTone("rejected").ink, fontWeight = FontWeight.Bold) } },
            dismissButton = { TextButton(enabled = !busy, onClick = { declining = false }) { Text(stringResource(R.string.common_cancel), color = settingsMut()) } },
        )
    }
}

@Composable
private fun OrderHead(o: ServiceOrderDto) {
    val tone = orderTone(o.status)
    Column(
        Modifier.fillMaxWidth().widthIn(max = 520.dp).settingsSurface(RoundedCornerShape(22.dp)).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        SvcPill(orderStatusLabel(o.status), tone, solid = true)
        Text(o.service.title.orEmpty(), color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, lineHeight = 25.sp)
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Initial(o.client?.name)
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.svo_client), color = settingsMut(), fontSize = 11.sp)
                Text(o.client?.name.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
            }
            Text(longDate(o.createdAt), color = settingsMut(), fontSize = 11.sp)
        }
        leftText(o)?.let { text ->
            val warn = serviceTone("awaiting")
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(warn.fill).padding(12.dp),
                verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                Icon(Icons.Rounded.HourglassTop, contentDescription = null, tint = warn.ink, modifier = Modifier.size(20.dp))
                Column {
                    Text(text, color = warn.ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                    o.answerBy?.let { Text(stringResource(R.string.svo_answer_by, "${longDate(it)} ${timeOf(it)}"), color = warn.ink.copy(alpha = 0.85f), fontSize = 12.sp) }
                }
            }
        }
    }
}

@Composable
private fun Fact(icon: androidx.compose.ui.graphics.vector.ImageVector, text: String, modifier: Modifier) {
    Row(
        modifier.clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.06f)).padding(horizontal = 10.dp, vertical = 9.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Icon(icon, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(16.dp))
        Text(text, color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.Bold, maxLines = 1)
    }
}

/**
 * FL-L05: the monthly subscriptions to my services. Each card: the service and level, the client, the month it is in, what I get each
 * month, when this month ends (and whether the client can confirm the next one), or the ending asked with its day; ended ones say why.
 * I can end one with notice (said before asking: asked `notice_days` before the month ends it ends with it, later with the next) or take
 * my ending back. Tapping opens the month running now.
 */
@Composable
private fun SubscriptionList(subs: PagedRows<ServiceSubscriptionDto>, onOpenOrder: (String) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var busy by remember { mutableStateOf<String?>(null) }
    var ending by remember { mutableStateOf<ServiceSubscriptionDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }

    fun act(s: ServiceSubscriptionDto, action: String) {
        busy = s.uuid
        scope.launch {
            val r = apiCall { ApiClient.services.subscriptionAct(s.uuid, action) }
            busy = null
            ending = null
            if (r is ApiResult.Ok) {
                Toast.makeText(context, context.getString(if (action == "cancel") R.string.sub_cancel_done else R.string.sub_keep_done), Toast.LENGTH_SHORT).show()
                subs.reload()
            } else error = r.errorText(offlineText) ?: genericError
        }
    }

    when {
        !subs.loaded && subs.failure == null -> SkeletonList()
        subs.loaded && subs.rows.isEmpty() && subs.failure == null -> EmptyState(
            icon = Icons.Rounded.EventRepeat, title = stringResource(R.string.sub_empty), body = stringResource(R.string.sub_empty_body), modifier = Modifier.padding(top = 70.dp),
        )
        else -> subs.rows.forEach { s ->
            SubscriptionCard(s, busy == s.uuid, onOpen = { s.current?.uuid?.let(onOpenOrder) }, onCancel = { ending = s }, onKeep = { act(s, "keep") })
        }
    }
    PagedFooter(subs) { scope.launch { subs.loadMore() } }
    ErrorBanner(error ?: subs.failure?.let { it.errorText(offlineText) ?: genericError }, Modifier.fillMaxWidth())

    ending?.let { s ->
        ConfirmDialog(
            icon = Icons.Rounded.EventBusy, title = stringResource(R.string.sub_cancel_title),
            message = stringResource(R.string.sub_cancel_body, s.noticeDays),
            confirmLabel = stringResource(R.string.sub_cancel), loading = busy == s.uuid, onConfirm = { act(s, "cancel") }, onDismiss = { ending = null },
        )
    }
}

@Composable
private fun SubscriptionCard(s: ServiceSubscriptionDto, busy: Boolean, onOpen: () -> Unit, onCancel: () -> Unit, onKeep: () -> Unit) {
    val tone = serviceTone(when (s.status) { "active" -> "active"; "pending" -> "awaiting"; else -> "paused" })
    Column(
        Modifier.fillMaxWidth().widthIn(max = 520.dp).settingsSurface(RoundedCornerShape(20.dp)).clickable(enabled = s.current != null, onClick = onOpen).padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(s.service.title.orEmpty(), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                Text(listOfNotNull(levelLabel(s.level), s.tierName?.takeIf { it.isNotBlank() }).joinToString(" · "), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
            }
            SvcPill(stringResource(when (s.status) { "active" -> R.string.sub_status_active; "pending" -> R.string.sub_status_pending; else -> R.string.sub_status_ended }), tone)
        }
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Initial(s.client?.name)
            Text(s.client?.name.orEmpty(), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
            Icon(Icons.Rounded.EventRepeat, contentDescription = null, tint = settingsMut(), modifier = Modifier.size(15.dp))
            Text(stringResource(R.string.sub_month_n, s.cycle), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
        }
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.06f)).padding(horizontal = 12.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(stringResource(R.string.sub_you_get, svcMoney(s.money.netMinor, s.decimals, s.currency)), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
        }
        val cancel = s.cancel
        when {
            s.status == "ended" -> Note(Icons.Rounded.EventBusy, stringResource(endReason(s.endReason)), serviceTone("paused"))
            cancel != null -> Note(Icons.Rounded.EventBusy, stringResource(if (cancel.by == "client") R.string.sub_ending_client else R.string.sub_ending_me, longDate(cancel.endsAt)), serviceTone("awaiting"))
            s.status == "active" -> Note(Icons.Rounded.Schedule,
                stringResource(if (s.renew?.open == true) R.string.sub_renew_open else R.string.sub_month_ends, longDate(s.periodEndsAt)), serviceTone("active"))
            else -> Note(Icons.Rounded.HourglassTop, stringResource(R.string.sub_pending_hint), serviceTone("awaiting"))
        }
        if (s.can.cancel || s.can.keep) {
            Row(
                Modifier.fillMaxWidth().height(44.dp).clip(RoundedCornerShape(14.dp))
                    .border(1.5.dp, if (settingsNight()) AccountDark.line else settingsAccent().copy(alpha = 0.3f), RoundedCornerShape(14.dp))
                    .clickable(enabled = !busy) { if (s.can.keep) onKeep() else onCancel() },
                horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(stringResource(if (s.can.keep) R.string.sub_keep else R.string.sub_cancel), color = if (s.can.keep) settingsAccent() else serviceTone("rejected").ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
            }
        }
    }
}

@Composable
private fun Note(icon: androidx.compose.ui.graphics.vector.ImageVector, text: String, tone: SvcTone) {
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(tone.fill).padding(10.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Icon(icon, contentDescription = null, tint = tone.ink, modifier = Modifier.size(16.dp))
        Text(text, color = tone.ink, fontSize = 12.sp, fontWeight = FontWeight.Bold, lineHeight = 17.sp)
    }
}

private fun endReason(reason: String?): Int = when (reason) {
    "cancelled_client" -> R.string.sub_end_client
    "cancelled_freelancer" -> R.string.sub_end_me
    "not_renewed" -> R.string.sub_end_not_renewed
    else -> R.string.sub_end_first
}

/** Accept → the client pays into the hold → I deliver → approved and paid: the order's road, as a short timeline. */
@Composable
private fun NextSteps() {
    InfoCard(title = stringResource(R.string.svo_next_title), icon = Icons.Rounded.Route) {
        val steps = listOf(R.string.svo_next_1, R.string.svo_next_2, R.string.svo_next_3, R.string.svo_next_4)
        val icons = listOf(Icons.Rounded.TaskAlt, Icons.Rounded.Payments, Icons.Rounded.Work, Icons.Rounded.CheckCircle)
        steps.forEachIndexed { i, s ->
            Row(verticalAlignment = Alignment.Top) {
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Box(Modifier.size(28.dp).clip(CircleShape).background(if (i == 0) settingsAccent() else settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                        Icon(icons[i], contentDescription = null, tint = if (i == 0) Color.White else settingsAccent(), modifier = Modifier.size(15.dp))
                    }
                    if (i < steps.lastIndex) Box(Modifier.width(2.dp).height(16.dp).background(settingsAccent().copy(alpha = 0.18f)))
                }
                Spacer(Modifier.width(12.dp))
                Text(stringResource(s), color = if (i == 0) settingsInk() else settingsMut(), fontSize = 13.sp, fontWeight = if (i == 0) FontWeight.Bold else FontWeight.Medium, modifier = Modifier.padding(top = 5.dp))
            }
        }
    }
}
