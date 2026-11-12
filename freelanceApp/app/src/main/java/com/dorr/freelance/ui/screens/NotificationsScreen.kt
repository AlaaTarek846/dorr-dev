package com.dorr.freelance.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
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
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.RequestQuote
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material.icons.rounded.Work
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import androidx.compose.material.icons.rounded.DoneAll
import androidx.compose.material.icons.rounded.Gavel
import androidx.compose.material.icons.rounded.Handshake
import androidx.compose.material.icons.rounded.LocalOffer
import androidx.compose.material.icons.rounded.Loyalty
import androidx.compose.material.icons.rounded.MarkEmailUnread
import androidx.compose.material.icons.rounded.Storefront
import androidx.compose.material.icons.rounded.VideoChat
import androidx.compose.runtime.saveable.rememberSaveable
import com.dorr.freelance.net.NotificationDto
import com.dorr.freelance.net.NoticeRoute
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.ui.kit.AccountDark
import com.dorr.freelance.ui.kit.SubHeader
import com.dorr.freelance.ui.kit.PinkBackdrop
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsNight
import com.dorr.freelance.ui.locale.LocaleAwareBottomSheet
import kotlinx.coroutines.launch
import java.time.Duration
import java.time.OffsetDateTime

/** Notifications fetched per request (infinite scroll). */
private const val PAGE_SIZE = 15

private data class NotificationItem(
    val id: String,
    val title: String,
    val body: String,
    val event: String?,
    val minutesAgo: Int,
    val unread: Boolean,
    /** The screen the notice leads to (see [NoticeRoute]); null when it has none of its own. */
    val route: String?,
    /** 0 today, 1 yesterday, 2 earlier this week, 3 older — on the phone's calendar. */
    val day: Int = 0,
)

private fun NotificationDto.toItem(): NotificationItem {
    val minutes = runCatching { Duration.between(OffsetDateTime.parse(createdAtIso), OffsetDateTime.now()).toMinutes().toInt() }.getOrDefault(0)
    val route = NoticeRoute.resolve(event) { key -> data?.get(key)?.takeIf { it.isJsonPrimitive }?.asString?.takeIf { it.isNotBlank() } }
    val daysBack = runCatching {
        java.time.temporal.ChronoUnit.DAYS.between(OffsetDateTime.parse(createdAtIso).atZoneSameInstant(java.time.ZoneId.systemDefault()).toLocalDate(), java.time.LocalDate.now())
    }.getOrDefault(0L)
    val day = when { daysBack <= 0 -> 0; daysBack == 1L -> 1; daysBack < 7 -> 2; else -> 3 }
    return NotificationItem(id, title, message, event, minutes.coerceAtLeast(0), unread = readAt == null, route = route, day = day)
}

/** The icon and colour of a notice from its event: verification, the wallet, the wallet PIN, anything else. */
private fun typeStyle(event: String?): Pair<ImageVector, Color> = when {
    // FS-209: a freelancers' team notice (an invitation, a role, verification, a part given to me).
    event?.startsWith("freelance.team_") == true -> Icons.Rounded.Groups to Color(0xFF14B8A6)
    event?.startsWith("wallet.pin") == true -> Icons.Rounded.Lock to Color(0xFFDC2626)
    event?.startsWith("wallet.") == true -> Icons.Rounded.AccountBalanceWallet to Color(0xFF16A34A)
    event?.startsWith("freelance.verification") == true -> Icons.Rounded.Verified to Color(0xFF2563EB)
    event?.startsWith("freelance.offer") == true -> Icons.Rounded.RequestQuote to Color(0xFFD97706)
    event?.contains("consultation") == true -> Icons.Rounded.VideoChat to Color(0xFF8B5CF6)
    event?.contains("order") == true || event?.contains("service") == true -> Icons.Rounded.Storefront to Color(0xFF14B8A6)
    event?.contains("special_offer") == true -> Icons.Rounded.LocalOffer to Color(0xFFF43F5E)
    event?.contains("loyalty") == true -> Icons.Rounded.Loyalty to Color(0xFFF59E0B)
    event?.contains("dispute") == true -> Icons.Rounded.Gavel to Color(0xFFDC2626)
    event?.contains("agreement") == true || event?.contains("milestone") == true || event?.contains("payment") == true -> Icons.Rounded.Handshake to Color(0xFF3B82F6)
    event?.contains("invitation") == true -> Icons.Rounded.MarkEmailUnread to Color(0xFF3B82F6)
    event?.startsWith("freelance.project") == true -> Icons.Rounded.Work to Color(0xFF16A34A)
    // FL-L12: the professional community.
    event?.startsWith("freelance.community") == true -> Icons.Rounded.Groups to Color(0xFF7C3AED)
    else -> Icons.Rounded.Notifications to Color(0xFF001B53)
}

@Composable
private fun relativeTime(minutesAgo: Int): String = when {
    minutesAgo < 1 -> stringResource(R.string.notif_time_now)
    minutesAgo < 60 -> stringResource(R.string.notif_time_minutes_ago, minutesAgo)
    minutesAgo < 24 * 60 -> stringResource(R.string.notif_time_hours_ago, minutesAgo / 60)
    minutesAgo < 2 * 24 * 60 -> stringResource(R.string.notif_time_yesterday)
    minutesAgo < 7 * 24 * 60 -> stringResource(R.string.notif_time_days_ago, minutesAgo / (24 * 60))
    else -> stringResource(R.string.notif_time_weeks_ago, minutesAgo / (7 * 24 * 60))
}

/**
 * The freelancer's notifications, as in the DORR app: a card per notice, the first page on entry and the next one each time the list
 * is scrolled near its end (a spinner under the rows, "show more" to retry a failed page), "mark all read", and a details sheet.
 * Nothing runs on a timer: the list also reloads when the connection returns and once for every live notice.
 */
@Composable
fun NotificationsScreen(onBack: () -> Unit, onOpenRoute: (String) -> Unit) {
    val notifications = remember { mutableStateListOf<NotificationItem>() }
    val scope = rememberCoroutineScope()
    var loaded by remember { mutableStateOf(false) }
    var selected by remember { mutableStateOf<NotificationItem?>(null) }
    val hasUnread = notifications.any { it.unread }
    var onlyUnread by rememberSaveable { mutableStateOf(false) }
    val night = settingsNight()

    val listState = rememberLazyListState()
    var page by remember { mutableIntStateOf(0) }
    var hasMore by remember { mutableStateOf(true) }
    var loadingMore by remember { mutableStateOf(false) }
    var pageFailed by remember { mutableStateOf(false) }

    suspend fun loadPage(next: Int) {
        // One request at a time: scrolling again while a page is on its way does nothing.
        if (loadingMore) return
        loadingMore = true
        pageFailed = false
        when (val result = apiCall { ApiClient.notifications.list(perPage = PAGE_SIZE, page = next) }) {
            is ApiResult.Ok -> {
                val rows = result.data.map { it.toItem() }
                if (next == 1) notifications.clear()
                // New rows go under the ones already there; a row that moved between pages is not shown twice.
                notifications.addAll(rows.filter { row -> notifications.none { it.id == row.id } })
                page = next
                hasMore = rows.size >= PAGE_SIZE
            }
            else -> pageFailed = true
        }
        loadingMore = false
        loaded = true
    }

    val reconnect = collectReconnectTick()
    LaunchedEffect(reconnect) {
        page = 0
        hasMore = true
        loadPage(1)
    }
    // A notice arrived while this page is open: start again from the first page.
    LaunchedEffect(Unit) {
        Realtime.changed.collect {
            page = 0
            hasMore = true
            loadPage(1)
        }
    }

    // Near the end of what is loaded (the last few rows are on screen)?
    val nearEnd by remember {
        derivedStateOf {
            val info = listState.layoutInfo
            val last = info.visibleItemsInfo.lastOrNull()?.index ?: -1
            info.totalItemsCount > 0 && last >= info.totalItemsCount - 4
        }
    }
    LaunchedEffect(nearEnd, notifications.size, hasMore, pageFailed, loaded) {
        if (loaded && nearEnd && hasMore && !loadingMore && !pageFailed) loadPage(page + 1)
    }

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.matchParentSize())
        Column(Modifier.fillMaxSize()) {
            SubHeader(stringResource(R.string.notifications_title), onBack)
            // All / unread, and «mark all read» while something is unread.
            val unreadCount = notifications.count { it.unread }
            Row(Modifier.fillMaxWidth().padding(horizontal = 16.dp), verticalAlignment = Alignment.CenterVertically) {
                com.dorr.freelance.ui.kit.PillChip(stringResource(R.string.notif_filter_all), !onlyUnread) { onlyUnread = false }
                Spacer(Modifier.width(8.dp))
                com.dorr.freelance.ui.kit.PillChip(
                    stringResource(R.string.notif_filter_unread) + if (unreadCount > 0) "  $unreadCount" else "", onlyUnread, dot = unreadCount > 0 && !onlyUnread,
                ) { onlyUnread = true }
                Spacer(Modifier.weight(1f))
                if (hasUnread) TextButton(
                    onClick = {
                        notifications.replaceAll { it.copy(unread = false) }
                        scope.launch {
                            val result = apiCall { ApiClient.notifications.markAllRead() }
                            if (result is ApiResult.Ok) Realtime.setUnread(result.data.count)
                        }
                    },
                ) {
                    Icon(Icons.Rounded.DoneAll, null, tint = if (night) AccountDark.accent else settingsAccent(), modifier = Modifier.size(17.dp))
                    Spacer(Modifier.width(4.dp))
                    Text(stringResource(R.string.notifications_mark_all_read), color = if (night) AccountDark.accent else settingsAccent(), fontWeight = FontWeight.Bold, fontSize = 13.sp)
                }
            }
            val shown = if (onlyUnread) notifications.filter { it.unread } else notifications
            if (loaded && shown.isEmpty() && !(onlyUnread && hasMore)) {
                if (onlyUnread && notifications.isNotEmpty()) EmptyNotifications(Modifier.weight(1f), allRead = true) else EmptyNotifications(Modifier.weight(1f))
            } else {
                LazyColumn(
                    state = listState,
                    modifier = Modifier.fillMaxSize().weight(1f),
                    contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 12.dp, bottom = 24.dp),
                    verticalArrangement = Arrangement.spacedBy(10.dp),
                ) {
                    shown.forEachIndexed { index, item ->
                    // A day's head above its first notice.
                    if (index == 0 || shown[index - 1].day != item.day) item(key = "day-${item.day}") {
                        Text(
                            stringResource(when (item.day) { 0 -> R.string.notif_day_today; 1 -> R.string.notif_day_yesterday; 2 -> R.string.notif_day_week; else -> R.string.notif_day_older }),
                            color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = if (index == 0) 0.dp else 8.dp, start = 4.dp),
                        )
                    }
                    item(key = item.id) {
                        NotificationCard(item) {
                            val index = notifications.indexOfFirst { it.id == item.id }
                            if (index >= 0) notifications[index] = item.copy(unread = false)
                            // A notice goes to the screen of what it is about; one without a screen of its own shows its details.
                            if (item.route != null) onOpenRoute(item.route) else selected = item.copy(unread = false)
                            if (item.unread) scope.launch {
                                val result = apiCall { ApiClient.notifications.markRead(item.id) }
                                if (result is ApiResult.Ok) Realtime.setUnread(result.data.count)
                            }
                        }
                    }
                    }
                    // The next page is loading (a spinner under the rows), or it failed (tap to try again).
                    if (loadingMore || pageFailed) item(key = "footer") {
                        Box(Modifier.fillMaxWidth().padding(vertical = 8.dp), contentAlignment = Alignment.Center) {
                            if (loadingMore) {
                                CircularProgressIndicator(color = if (night) AccountDark.accent else settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(24.dp))
                            } else {
                                Text(
                                    stringResource(R.string.support_ticket_more),
                                    color = if (night) AccountDark.accent else settingsAccent(),
                                    fontWeight = FontWeight.Bold,
                                    modifier = Modifier.clip(RoundedCornerShape(12.dp)).clickable { scope.launch { loadPage(if (page == 0) 1 else page + 1) } }.padding(horizontal = 16.dp, vertical = 8.dp),
                                )
                            }
                        }
                    }
                }
            }
        }
    }

    selected?.let { item ->
        NotificationDetailSheet(item, onDismiss = { selected = null })
    }
}

@Composable
private fun NotificationCard(item: NotificationItem, onClick: () -> Unit) {
    val (icon, color) = typeStyle(item.event)
    val night = settingsNight()
    val shape = RoundedCornerShape(16.dp)
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .then(if (night) Modifier else Modifier.shadow(6.dp, shape, ambientColor = Color(0x12001B53), spotColor = Color(0x12001B53)))
            .clip(shape)
            .background(if (night) (if (item.unread) AccountDark.well else AccountDark.card) else if (item.unread) Color(0xFFFFF6F7) else Color.White)
            .border(1.dp, if (night) AccountDark.line else Color.Transparent, shape)
            .clickable(onClick = onClick)
            .padding(14.dp),
        verticalAlignment = Alignment.Top,
    ) {
        // Unread is shown by the tinted card and the bold title; no dot above the icon.
        Box(Modifier.size(42.dp).background(color.copy(alpha = 0.1f), RoundedCornerShape(12.dp)), contentAlignment = Alignment.Center) {
            Icon(icon, contentDescription = null, tint = color, modifier = Modifier.size(22.dp))
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.Top) {
                Text(
                    item.title, fontSize = 14.sp, fontWeight = if (item.unread) FontWeight.Bold else FontWeight.SemiBold,
                    maxLines = 1, overflow = TextOverflow.Ellipsis, color = settingsInk(), modifier = Modifier.weight(1f),
                )
                Spacer(Modifier.width(8.dp))
                Text(relativeTime(item.minutesAgo), fontSize = 11.5.sp, color = if (item.unread) settingsAccent() else settingsMut(), fontWeight = if (item.unread) FontWeight.Bold else FontWeight.Normal)
                if (item.unread) Box(Modifier.padding(start = 6.dp, top = 4.dp).size(8.dp).clip(CircleShape).background(settingsAccent()))
            }
            Spacer(Modifier.height(4.dp))
            Text(item.body, fontSize = 12.sp, color = settingsMut(), maxLines = 2, overflow = TextOverflow.Ellipsis)
        }
    }
}

@Composable
private fun NotificationDetailSheet(item: NotificationItem, onDismiss: () -> Unit) {
    val (icon, color) = typeStyle(item.event)
    val night = settingsNight()
    LocaleAwareBottomSheet(onDismissRequest = onDismiss, containerColor = if (night) AccountDark.card else Color.White, shape = RoundedCornerShape(topStart = 28.dp, topEnd = 28.dp)) {
        Column(Modifier.padding(horizontal = 20.dp, vertical = 8.dp)) {
            Row(verticalAlignment = Alignment.Top) {
                Box(Modifier.size(44.dp).background(color.copy(alpha = 0.12f), RoundedCornerShape(12.dp)), contentAlignment = Alignment.Center) {
                    Icon(icon, contentDescription = null, tint = color)
                }
                Spacer(Modifier.width(12.dp))
                Column {
                    Text(item.title.ifBlank { stringResource(R.string.notification_default_title) }, fontSize = 16.sp, fontWeight = FontWeight.Bold, color = settingsInk())
                    Spacer(Modifier.height(4.dp))
                    Text(relativeTime(item.minutesAgo), fontSize = 12.sp, color = settingsMut())
                }
            }
            Spacer(Modifier.height(18.dp))
            Text(item.body, fontSize = 14.sp, lineHeight = 22.sp, color = settingsInk())
            Spacer(Modifier.height(24.dp))
        }
    }
}

@Composable
private fun EmptyNotifications(modifier: Modifier = Modifier, allRead: Boolean = false) {
    val night = settingsNight()
    Column(modifier.fillMaxSize().padding(40.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
        Box(Modifier.size(100.dp).background(if (night) AccountDark.well else settingsAccent().copy(alpha = 0.08f), CircleShape), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.Notifications, contentDescription = null, tint = settingsMut(), modifier = Modifier.size(48.dp))
        }
        Spacer(Modifier.height(24.dp))
        Text(stringResource(if (allRead) R.string.notif_all_read_title else R.string.notifications_empty_title), fontSize = 16.sp, fontWeight = FontWeight.SemiBold, color = settingsInk())
        Spacer(Modifier.height(8.dp))
        Text(stringResource(if (allRead) R.string.notif_all_read_body else R.string.notifications_empty_body), fontSize = 14.sp, color = settingsMut())
    }
}
