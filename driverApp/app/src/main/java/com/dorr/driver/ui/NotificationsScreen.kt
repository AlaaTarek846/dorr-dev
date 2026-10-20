package com.dorr.driver.ui

import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
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
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.AccountBalanceWallet
import androidx.compose.material.icons.filled.DirectionsCar
import androidx.compose.material.icons.filled.DoneAll
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material.icons.filled.Shield
import androidx.compose.material.icons.filled.SupportAgent
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.LaunchedEffect
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
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.ui.kit.HeroBadge
import com.dorr.driver.ui.kit.RoundIconButton
import com.dorr.driver.ui.kit.ScreenScaffold
import com.dorr.driver.ui.kit.TileIcon
import com.dorr.driver.ui.kit.bounceClick
import com.dorr.driver.ui.kit.surface
import com.dorr.driver.ui.theme.DorrBrand
import com.dorr.driver.R
import com.dorr.driver.live.DriverLive
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.NotificationDto
import kotlinx.coroutines.launch
import java.time.Duration
import java.time.OffsetDateTime

/**
 * The captain's notifications — the same list the rider has (wallet, rides, support, Wasl…), in the
 * app's language. A new one arrives on the captain's real-time channel and appears at the top by
 * itself; opening one marks it read; the bell on Home carries the unread count.
 */
@Composable
fun NotificationsScreen(onBack: () -> Unit, onOpen: (NotificationDto) -> Unit) {
    val scope = rememberCoroutineScope()
    var items by remember { mutableStateOf<List<NotificationDto>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    BackHandler(onBack = onBack)

    suspend fun load() {
        when (val r = apiCall { DriverBackend.api.notifications() }) {
            is ApiResult.Ok -> { items = r.data; error = null }
            is ApiResult.Failed -> error = r.error.displayMessage
            is ApiResult.Offline -> error = null
        }
        DriverLive.refreshUnread()
    }
    LaunchedEffect(Unit) {
        load()
        // A notification row arrived (any event that isn't just "something changed"): the list refreshes.
        DriverLive.events.collect { e ->
            if (e.name != DriverLive.DRIVER_CHANGED && e.name != "ride.changed" && e.name != "ride.message" && e.name != "ride.driver_location") load()
        }
    }

    val unread by DriverLive.unread.collectAsState()
    ScreenScaffold(
        stringResource(R.string.nt_title), onBack,
        actions = {
            AnimatedVisibility(visible = unread > 0, enter = fadeIn(), exit = fadeOut()) {
                RoundIconButton(Icons.Filled.DoneAll, stringResource(R.string.nt_read_all), onClick = {
                    scope.launch {
                        (apiCall { DriverBackend.api.markAllRead() } as? ApiResult.Ok)?.let { DriverLive.setUnread(it.data.count) }
                        items = items?.map { it.copy(readAt = it.readAt ?: "now") }
                    }
                })
            }
        },
    ) {
        val list = items
        when {
            list == null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                if (error != null) Text(error.orEmpty(), color = MaterialTheme.colorScheme.error, modifier = Modifier.padding(24.dp), textAlign = TextAlign.Center)
                else CircularProgressIndicator(color = DorrBrand.orange)
            }
            list.isEmpty() -> Column(Modifier.fillMaxSize().padding(32.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
                HeroBadge(Icons.Filled.Notifications, tint = DorrBrand.orange, size = 76.dp)
                Spacer(Modifier.height(10.dp))
                Text(stringResource(R.string.nt_empty), color = MaterialTheme.colorScheme.onBackground.copy(alpha = 0.6f))
            }
            else -> LazyColumn(Modifier.fillMaxSize().padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                item { Spacer(Modifier.height(6.dp)) }
                items(list, key = { it.id }) { n ->
                    NotificationRow(n) {
                        if (n.readAt == null) scope.launch {
                            (apiCall { DriverBackend.api.markRead(n.id) } as? ApiResult.Ok)?.let { DriverLive.setUnread(it.data.count) }
                            items = items?.map { if (it.id == n.id) it.copy(readAt = "now") else it }
                        }
                        onOpen(n)
                    }
                }
                item { Spacer(Modifier.height(16.dp)) }
            }
        }
    }
}

@Composable
private fun NotificationRow(n: NotificationDto, onClick: () -> Unit) {
    val unread = n.readAt == null
    val (icon, tint) = iconFor(n.event)
    Row(
        Modifier.fillMaxWidth().bounceClick(onClick = onClick).surface(RoundedCornerShape(18.dp), 4.dp)
            .background(if (unread) DorrBrand.orange.copy(alpha = 0.08f) else Color.Transparent)
            .padding(14.dp),
        verticalAlignment = Alignment.Top,
    ) {
        TileIcon(icon, tint, 42.dp)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(n.title, fontWeight = if (unread) FontWeight.ExtraBold else FontWeight.SemiBold, fontSize = 15.sp, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(ago(n.createdAtIso), fontSize = 11.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.5f))
            }
            Text(n.message, fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.75f), maxLines = 3, overflow = TextOverflow.Ellipsis)
        }
        if (unread) Box(Modifier.padding(start = 8.dp, top = 6.dp).size(9.dp).clip(CircleShape).background(DorrBrand.orange))
    }
}

private fun iconFor(event: String?): Pair<ImageVector, Color> = when {
    event == null -> Icons.Filled.Notifications to DorrBrand.navy
    event.startsWith("wallet") || event.contains("earnings") -> Icons.Filled.AccountBalanceWallet to DorrBrand.success
    event.contains("sos") || event.contains("safety") || event.contains("contact") -> Icons.Filled.Shield to Color(0xFFA32D2D)
    event.startsWith("support") -> Icons.Filled.SupportAgent to DorrBrand.navy
    event.startsWith("ride") -> Icons.Filled.DirectionsCar to DorrBrand.orange
    else -> Icons.Filled.Notifications to DorrBrand.navy
}

/** "now", "5 min", "3 h", "2 d" — short, in the app's language. */
@Composable
private fun ago(iso: String?): String {
    val at = iso?.let { runCatching { OffsetDateTime.parse(it) }.getOrNull() } ?: return ""
    val minutes = Duration.between(at, OffsetDateTime.now()).toMinutes().coerceAtLeast(0)
    return when {
        minutes < 1 -> stringResource(R.string.nt_now)
        minutes < 60 -> stringResource(R.string.nt_min, minutes.toInt())
        minutes < 1440 -> stringResource(R.string.nt_hour, (minutes / 60).toInt())
        else -> stringResource(R.string.nt_day, (minutes / 1440).toInt())
    }
}

/** The bell: a round button with the unread count in a badge (Home and Account). */
@Composable
internal fun NotificationBell(onClick: () -> Unit) {
    val unread by DriverLive.unread.collectAsState()
    Box(contentAlignment = Alignment.Center) {
        com.dorr.driver.ui.kit.RoundIconButton(Icons.Filled.Notifications, stringResource(R.string.nt_title), onClick)
        AnimatedVisibility(
            visible = unread > 0, modifier = Modifier.align(Alignment.TopEnd).padding(top = 0.dp, end = 0.dp),
            enter = fadeIn(tween(200)) + slideInVertically(tween(200)) { -it }, exit = fadeOut(),
        ) {
            Box(Modifier.clip(CircleShape).background(DorrBrand.danger).padding(horizontal = 5.dp, vertical = 1.dp), contentAlignment = Alignment.Center) {
                Text(if (unread > 99) "99+" else unread.toString(), color = Color.White, fontSize = 10.sp, fontWeight = FontWeight.Bold)
            }
        }
    }
}
