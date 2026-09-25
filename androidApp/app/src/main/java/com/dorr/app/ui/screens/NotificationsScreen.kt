package com.dorr.app.ui.screens

import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.ui.unit.sp
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
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.Build
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material.icons.rounded.LocalOffer
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.Receipt
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.NotificationDto
import kotlinx.coroutines.launch
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.rememberCoroutineScope
import com.dorr.app.ui.theme.AppColors

private enum class NotificationType { JOB_UPDATE, QUOTE, INVOICE, MAINTENANCE, PROMO, GENERAL, WALLET, WALLET_PIN }

private data class NotificationItem(
    val id: String,
    val type: NotificationType,
    val title: String,
    val body: String,
    val minutesAgo: Int,
    val unread: Boolean,
)

private fun typeStyle(type: NotificationType): Pair<ImageVector, Color> = when (type) {
    NotificationType.JOB_UPDATE -> Icons.Rounded.Build to AppColors.info
    NotificationType.QUOTE -> Icons.Rounded.Description to AppColors.warning
    NotificationType.INVOICE -> Icons.Rounded.Receipt to AppColors.secondary
    NotificationType.MAINTENANCE -> Icons.Rounded.Event to AppColors.accent
    NotificationType.PROMO -> Icons.Rounded.LocalOffer to AppColors.danger
    NotificationType.GENERAL -> Icons.Rounded.Notifications to AppColors.primary
    NotificationType.WALLET -> Icons.Rounded.AccountBalanceWallet to Color(0xFF16A34A)
    NotificationType.WALLET_PIN -> Icons.Rounded.Lock to AppColors.danger
}

private fun NotificationDto.toItem(): NotificationItem {
    // Same precedence as the preview's notifStyleFor: wallet event first, then server type.
    val type = when {
        event?.startsWith("wallet.pin") == true -> NotificationType.WALLET_PIN
        event?.startsWith("wallet.") == true -> NotificationType.WALLET
        type == "job_update" -> NotificationType.JOB_UPDATE
        type == "quote" -> NotificationType.QUOTE
        type == "invoice" -> NotificationType.INVOICE
        type == "maintenance" -> NotificationType.MAINTENANCE
        type == "promo" -> NotificationType.PROMO
        else -> NotificationType.GENERAL
    }
    val minutes = runCatching { java.time.Duration.between(java.time.Instant.parse(createdAtIso), java.time.Instant.now()).toMinutes().toInt() }.getOrDefault(0)
    return NotificationItem(id, type, title, message, minutes.coerceAtLeast(0), unread = readAt == null)
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

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun NotificationsScreen(onBack: () -> Unit) {
    val notifications = remember { mutableStateListOf<NotificationItem>() }
    val scope = rememberCoroutineScope()
    var loaded by remember { mutableStateOf(false) }
    var selected by remember { mutableStateOf<NotificationItem?>(null) }
    val hasUnread = notifications.any { it.unread }

    // The real feed. Reloaded on entry; every row arrives in the language the app is using.
    LaunchedEffect(Unit) {
        runCatching { ApiClient.notifications.list("Bearer ${AuthSession.token.orEmpty()}").data.orEmpty() }
            .onSuccess { rows ->
                notifications.clear()
                notifications.addAll(rows.map { it.toItem() })
            }
        loaded = true
    }

    Scaffold(
        topBar = {
            Column {
                TopAppBar(
                    title = {
                        Text(
                            stringResource(R.string.notifications_title),
                            fontSize = 17.sp,
                            fontWeight = FontWeight.SemiBold,
                            color = AppColors.textPrimary,
                        )
                    },
                    navigationIcon = {
                        IconButton(onClick = onBack) {
                            Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = stringResource(R.string.common_back))
                        }
                    },
                    actions = {
                        if (hasUnread) {
                            TextButton(onClick = {
                                notifications.replaceAll { it.copy(unread = false) }
                                scope.launch { runCatching { ApiClient.notifications.markAllRead("Bearer ${AuthSession.token.orEmpty()}") } }
                            }) {
                                Text(stringResource(R.string.notifications_mark_all_read), color = AppColors.primary)
                            }
                        }
                    },
                    colors = TopAppBarDefaults.topAppBarColors(containerColor = Color.White),
                )
                HorizontalDivider(color = AppColors.border)
            }
        },
    ) { padding ->
        if (loaded && notifications.isEmpty()) {
            EmptyNotifications(modifier = Modifier.padding(padding))
        } else {
            LazyColumn(
                modifier = Modifier
                    .fillMaxSize()
                    .padding(padding),
                contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 12.dp, bottom = 100.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                items(notifications, key = { it.id }) { item ->
                    NotificationCard(
                        item = item,
                        onClick = {
                            val index = notifications.indexOfFirst { it.id == item.id }
                            if (index >= 0) notifications[index] = item.copy(unread = false)
                            selected = item.copy(unread = false)
                            if (item.unread) scope.launch { runCatching { ApiClient.notifications.markRead("Bearer ${AuthSession.token.orEmpty()}", item.id) } }
                        },
                    )
                }
            }
        }
    }

    selected?.let { item ->
        NotificationDetailSheet(item = item, onDismiss = { selected = null })
    }
}

@Composable
private fun NotificationCard(item: NotificationItem, onClick: () -> Unit) {
    val (icon, color) = typeStyle(item.type)

    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(16.dp))
            .background(if (item.unread) AppColors.tint(AppColors.primary) else AppColors.card)
            .border(
                1.dp,
                if (item.unread) AppColors.primary.copy(alpha = 0.15f) else AppColors.border,
                RoundedCornerShape(16.dp),
            )
            .clickable(onClick = onClick)
            .padding(14.dp),
        verticalAlignment = Alignment.Top,
    ) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Box(
                modifier = Modifier
                    .size(8.dp)
                    .background(if (item.unread) AppColors.primary else Color.Transparent, CircleShape),
            )
            Spacer(Modifier.height(4.dp))
            Box(
                modifier = Modifier
                    .size(42.dp)
                    .background(color.copy(alpha = 0.1f), RoundedCornerShape(12.dp)),
                contentAlignment = Alignment.Center,
            ) {
                Icon(icon, contentDescription = null, tint = color, modifier = Modifier.size(22.dp))
            }
        }
        Spacer(Modifier.width(12.dp))
        Column(modifier = Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.Top) {
                Text(
                    item.title,
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = if (item.unread) FontWeight.Bold else FontWeight.SemiBold,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.weight(1f),
                )
                Spacer(Modifier.width(8.dp))
                Text(relativeTime(item.minutesAgo), style = MaterialTheme.typography.bodySmall, color = AppColors.textMuted)
            }
            Spacer(Modifier.height(4.dp))
            Text(
                item.body,
                style = MaterialTheme.typography.bodySmall,
                color = AppColors.textSecondary,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun NotificationDetailSheet(item: NotificationItem, onDismiss: () -> Unit) {
    val (icon, color) = typeStyle(item.type)

    ModalBottomSheet(onDismissRequest = onDismiss) {
        Column(modifier = Modifier.padding(horizontal = 20.dp, vertical = 8.dp)) {
            Row(verticalAlignment = Alignment.Top) {
                Box(
                    modifier = Modifier
                        .size(44.dp)
                        .background(color.copy(alpha = 0.12f), RoundedCornerShape(12.dp)),
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(icon, contentDescription = null, tint = color)
                }
                Spacer(Modifier.width(12.dp))
                Column {
                    Text(
                        item.title.ifBlank { stringResource(R.string.notification_default_title) },
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.Bold,
                    )
                    Spacer(Modifier.height(4.dp))
                    Text(relativeTime(item.minutesAgo), style = MaterialTheme.typography.bodySmall, color = AppColors.textMuted)
                }
            }
            Spacer(Modifier.height(18.dp))
            Text(item.body, style = MaterialTheme.typography.bodyLarge)
            Spacer(Modifier.height(24.dp))
        }
    }
}

@Composable
private fun EmptyNotifications(modifier: Modifier = Modifier) {
    Column(
        modifier = modifier.fillMaxSize().padding(40.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        Box(
            modifier = Modifier
                .size(100.dp)
                .background(AppColors.primary.copy(alpha = 0.08f), CircleShape),
            contentAlignment = Alignment.Center,
        ) {
            Icon(Icons.Rounded.Notifications, contentDescription = null, tint = AppColors.textMuted, modifier = Modifier.size(48.dp))
        }
        Spacer(Modifier.height(24.dp))
        Text(stringResource(R.string.notifications_empty_title), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
        Spacer(Modifier.height(8.dp))
        Text(
            stringResource(R.string.notifications_empty_body),
            style = MaterialTheme.typography.bodyMedium,
            color = AppColors.textSecondary,
        )
    }
}
