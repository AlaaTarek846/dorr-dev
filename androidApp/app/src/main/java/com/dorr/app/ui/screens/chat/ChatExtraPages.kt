package com.dorr.app.ui.screens.chat

import androidx.compose.foundation.background
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
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.AddPhotoAlternate
import androidx.compose.material.icons.rounded.Block
import androidx.compose.material.icons.rounded.BookmarkAdd
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.CallMade
import androidx.compose.material.icons.rounded.CallMissed
import androidx.compose.material.icons.rounded.CallReceived
import androidx.compose.material.icons.rounded.DoneAll
import androidx.compose.material.icons.rounded.FiberManualRecord
import androidx.compose.material.icons.rounded.GroupAdd
import androidx.compose.material.icons.rounded.Message
import androidx.compose.material.icons.rounded.NotificationsActive
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Screenshot
import androidx.compose.material.icons.rounded.PhoneDisabled
import androidx.compose.material.icons.rounded.Timer
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material.icons.rounded.Videocam
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.chat.CallController
import com.dorr.app.network.ApiClient
import com.dorr.app.ui.screens.AccountDark
import com.dorr.app.ui.screens.profile.PinkIcon
import com.dorr.app.ui.screens.profile.RedToggle
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.screens.profile.settingsSurface
import com.dorr.app.network.BlockDto
import com.dorr.app.network.CallDto
import com.dorr.app.network.MessageDto
import com.dorr.app.network.PrivacyDto
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.filter
import kotlinx.coroutines.launch

// =============================================================================== privacy

@Composable
fun PrivacyPage() {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var privacy by remember { mutableStateOf<PrivacyDto?>(null) }
    var picker by remember { mutableStateOf<Triple<String, String, String>?>(null) }
    var notificationPicker by remember { mutableStateOf(false) }
    var timerPicker by remember { mutableStateOf(false) }
    var summary by remember { mutableStateOf<com.dorr.app.network.PrivacySummaryDto?>(null) }

    LaunchedEffect(Unit) {
        privacy = runCatching { ApiClient.chat.privacy(chatAuth()).data }.getOrNull()
    }
    val savedToast = stringResource(R.string.ch_saved)
    fun save(key: String, value: Any) = scope.launch {
        runCatching { ApiClient.chat.updatePrivacy(chatAuth(), mapOf(key to value)).data }.getOrNull()?.let {
            privacy = it
            host.showToast(savedToast)
        }
    }

    ChPage(stringResource(R.string.ch_privacy_title), onBack = { host.pop() }, settingsHeader = true) {
        val p = privacy
        LazyColumn(contentPadding = PaddingValues(start = 14.dp, end = 14.dp, top = 6.dp, bottom = 16.dp), verticalArrangement = Arrangement.spacedBy(12.dp), modifier = Modifier.fillMaxSize()) {
            if (p == null) {
                item { com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().height(320.dp), RoundedCornerShape(18.dp)) }
            } else {
                item {
                    Column(
                        Modifier
                            .fillMaxWidth()
                            .settingsSurface(RoundedCornerShape(18.dp), 8.dp)
                            .padding(horizontal = 8.dp, vertical = 4.dp),
                    ) {
                        AudienceRow(Icons.Rounded.Visibility, R.string.ch_last_seen_setting, p.lastSeen, "last_seen") { picker = it }
                        AudienceRow(Icons.Rounded.AddPhotoAlternate, R.string.ch_profile_photo_setting, p.profilePhoto, "profile_photo") { picker = it }
                        AudienceRow(Icons.Rounded.Message, R.string.ch_who_message, p.whoCanMessage, "who_can_message") { picker = it }
                        AudienceRow(Icons.Rounded.GroupAdd, R.string.ch_who_groups, p.whoCanAddToGroups, "who_can_add_to_groups") { picker = it }
                        AudienceRow(Icons.Rounded.Call, R.string.ch_who_call, p.whoCanCall, "who_can_call") { picker = it }
                        AudienceRow(Icons.Rounded.NotificationsActive, R.string.ch_who_urgent, p.whoCanUrgent ?: "contacts", "who_can_urgent") { picker = it }
                        PrivacySettingsRow(Icons.Rounded.DoneAll, stringResource(R.string.ch_read_receipts), subtitle = stringResource(R.string.ch_read_receipts_sub), checked = p.readReceipts, onChecked = { save("read_receipts", it) })
                        PrivacySettingsRow(Icons.Rounded.Screenshot, stringResource(R.string.ch_block_screenshots), checked = p.blockScreenshots, onChecked = { save("block_screenshots", it) })
                        PrivacySettingsRow(Icons.Rounded.Timer, stringResource(R.string.bk_default_timer), value = stringResource(timerLabel(p.defaultDisappearingSeconds))) { timerPicker = true }
                        PrivacySettingsRow(Icons.Rounded.PhoneDisabled, stringResource(R.string.bk_silence_unknown), subtitle = stringResource(R.string.bk_silence_unknown_sub), checked = p.silenceUnknownCallers, onChecked = { save("silence_unknown_callers", it) })
                        val level = p.notificationPrivacy ?: if (p.notificationPreview) "all" else "none"
                        PrivacySettingsRow(Icons.Rounded.NotificationsActive, stringResource(R.string.ch_notification_preview), value = stringResource(notificationLevelLabel(level))) { notificationPicker = true }
                        PrivacyShieldCards(p, onChanged = { privacy = it }, onSummary = { summary = it })
                    }
                }
            }
        }
    }

    summary?.let { PrivacySummaryDialog(it) { summary = null } }
    if (timerPicker) {
        val current = privacy?.defaultDisappearingSeconds
        val choices = listOf<Int?>(null, 86400, 604800, 7776000)
        ChoiceSheet(
            title = stringResource(R.string.bk_default_timer),
            subtitle = stringResource(R.string.bk_default_timer_sub),
            options = choices.map { value -> stringResource(timerLabel(value)) to { save("default_disappearing_seconds", value ?: 0); Unit } },
            radios = true,
            selected = choices.indexOfFirst { it == current }.takeIf { it >= 0 } ?: 0,
            onDismiss = { timerPicker = false },
        )
    }
    if (notificationPicker) {
        val current = privacy?.notificationPrivacy ?: "all"
        val choices = listOf("all", "name", "none")
        ChoiceSheet(
            title = stringResource(R.string.ch_notification_preview),
            options = choices.map { value -> stringResource(notificationLevelLabel(value)) to { save("notification_privacy", value); Unit } },
            subtitle = stringResource(R.string.ch_notification_privacy_sub),
            radios = true,
            selected = choices.indexOfFirst { it == current }.takeIf { it >= 0 } ?: 0,
            onDismiss = { notificationPicker = false },
        )
    }

    picker?.let { (key, current, title) ->
        val choices = listOf("everyone" to R.string.ch_everyone, "contacts" to R.string.ch_my_contacts, "nobody" to R.string.ch_nobody)
        ChoiceSheet(
            title = title,
            options = choices.map { (value, label) -> stringResource(label) to { save(key, value); Unit } },
            radios = true,
            selected = choices.indexOfFirst { it.first == current }.takeIf { it >= 0 } ?: 0,
            onDismiss = { picker = null },
        )
    }
}

@Composable
fun BlockedContactsPage() {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    val listState = rememberLazyListState()
    var query by remember { mutableStateOf("") }
    var list by remember { mutableStateOf<List<BlockDto>?>(null) }
    var page by remember { mutableIntStateOf(1) }
    var hasMore by remember { mutableStateOf(false) }
    var loadingMore by remember { mutableStateOf(false) }
    val unblocked = stringResource(R.string.ch_unblock_done)

    suspend fun loadPage(reset: Boolean) {
        if (!reset && (loadingMore || !hasMore)) return
        if (!reset) loadingMore = true
        val next = if (reset) 1 else page + 1
        val envelope = runCatching {
            ApiClient.chat.blocks(chatAuth(), q = query.trim().ifBlank { null }, page = next, perPage = 15)
        }.getOrNull()
        val chunk = envelope?.data.orEmpty()
        list = if (reset) chunk else {
            val known = list.orEmpty().map { it.profile?.key }.toSet()
            list.orEmpty() + chunk.filter { it.profile?.key !in known }
        }
        page = next
        hasMore = envelope?.pagination?.hasMorePages == true
        if (!reset) loadingMore = false
    }

    LaunchedEffect(query) {
        delay(280)
        loadPage(reset = true)
    }
    LaunchedEffect(listState, list?.size, hasMore) {
        snapshotFlow {
            val info = listState.layoutInfo
            val last = info.visibleItemsInfo.lastOrNull()?.index ?: -1
            info.totalItemsCount > 0 && last >= info.totalItemsCount - 3
        }.distinctUntilChanged().filter { it }.collect { scope.launch { loadPage(reset = false) } }
    }

    ChPage(stringResource(R.string.ch_blocked_list), onBack = { host.pop() }, settingsHeader = true) {
        Column(Modifier.fillMaxSize().padding(horizontal = 14.dp)) {
            ChField(
                query, { query = it.take(80) }, stringResource(R.string.ch_blocked_search),
                icon = Icons.Rounded.Search, clearable = true, modifier = Modifier.padding(top = 6.dp, bottom = 8.dp),
            )
            val items = list
            when {
                items == null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(color = Ch.Red)
                }
                items.isEmpty() -> Box(Modifier.fillMaxSize()) {
                    ChEmptyState(Icons.Rounded.Block, stringResource(R.string.ch_blocked_list), stringResource(R.string.ch_no_blocked), animated = true)
                }
                else -> LazyColumn(
                    state = listState,
                    contentPadding = PaddingValues(bottom = 16.dp),
                    verticalArrangement = Arrangement.spacedBy(8.dp),
                    modifier = Modifier.fillMaxSize(),
                ) {
                    itemsIndexed(items, key = { _, b -> b.profile?.key ?: b.blockedAt.orEmpty() }) { i, b ->
                        Row(
                            Modifier.fillMaxWidth().chStagger(i).clip(RoundedCornerShape(18.dp)).background(Ch.Surface).padding(12.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            ChAvatar(b.profile?.avatar, b.profile?.name, b.profile?.key, size = 44.dp)
                            Spacer(Modifier.width(12.dp))
                            Column(Modifier.weight(1f)) {
                                Text(b.profile?.name.orEmpty(), color = Ch.Ink, fontWeight = FontWeight.Bold)
                                b.profile?.phone?.takeIf { it.isNotBlank() }?.let { Text(it, color = Ch.Mut, fontSize = 12.5.sp) }
                            }
                            Text(
                                stringResource(R.string.ch_unblock), color = Ch.Red, fontWeight = FontWeight.ExtraBold, fontSize = 13.sp,
                                modifier = Modifier.clip(RoundedCornerShape(12.dp)).background(Ch.Red.copy(alpha = 0.08f)).clickable {
                                    val id = b.profile?.id ?: return@clickable
                                    scope.launch {
                                        runCatching { ApiClient.chat.unblock(chatAuth(), mapOf("participant_id" to id)) }.onSuccess {
                                            list = list.orEmpty().filter { it.profile?.id != id }
                                            host.showToast(unblocked)
                                        }
                                    }
                                }.padding(horizontal = 12.dp, vertical = 7.dp),
                            )
                        }
                    }
                    if (loadingMore) item {
                        Box(Modifier.fillMaxWidth().padding(12.dp), contentAlignment = Alignment.Center) {
                            CircularProgressIndicator(color = Ch.Red, strokeWidth = 2.dp, modifier = Modifier.size(22.dp))
                        }
                    }
                }
            }
        }
    }
}

private fun timerLabel(seconds: Int?): Int = when (seconds) {
    86400 -> R.string.bk_timer_24h
    604800 -> R.string.bk_timer_7d
    7776000 -> R.string.bk_timer_90d
    else -> R.string.bk_timer_off
}

private fun notificationLevelLabel(level: String): Int = when (level) {
    "name" -> R.string.ch_notif_name
    "none" -> R.string.ch_notif_none
    else -> R.string.ch_notif_all
}

@Composable
private fun AudienceRow(icon: ImageVector, titleRes: Int, value: String, key: String, onPick: (Triple<String, String, String>) -> Unit) {
    val title = stringResource(titleRes)
    PrivacySettingsRow(
        icon,
        title,
        value = stringResource(when (value) {
            "contacts" -> R.string.ch_my_contacts
            "nobody" -> R.string.ch_nobody
            else -> R.string.ch_everyone
        }),
    ) { onPick(Triple(key, value, title)) }
}

/** One row of the privacy card — same type, size and toggle as Account → Notifications. */
@Composable
internal fun PrivacySettingsRow(
    icon: ImageVector,
    title: String,
    subtitle: String? = null,
    value: String? = null,
    trailing: String? = null,
    last: Boolean = false,
    checked: Boolean? = null,
    onChecked: ((Boolean) -> Unit)? = null,
    onClick: (() -> Unit)? = null,
) {
    val dark = settingsNight()
    val mut = settingsMut()
    val label = buildAnnotatedString {
        append(title)
        if (!value.isNullOrBlank()) {
            withStyle(SpanStyle(fontSize = 11.sp, fontWeight = FontWeight.Normal, color = mut)) {
                append(" ($value)")
            }
        }
    }
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .then(if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier)
            .padding(horizontal = 4.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        PinkIcon(icon)
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(label, fontSize = 13.5.sp, fontWeight = FontWeight.Bold, color = settingsInk(), maxLines = 2, overflow = TextOverflow.Ellipsis)
            if (!subtitle.isNullOrBlank()) {
                Text(subtitle, fontSize = 11.sp, color = settingsMut(), lineHeight = 15.sp, modifier = Modifier.padding(top = 2.dp))
            }
        }
        if (!trailing.isNullOrBlank()) {
            Text(trailing, fontSize = 13.5.sp, fontWeight = FontWeight.ExtraBold, color = settingsInk())
            Spacer(Modifier.width(6.dp))
        }
        if (checked != null && onChecked != null) {
            RedToggle(checked, dark) { onChecked(!checked) }
        } else if (onClick != null) {
            Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, null, tint = settingsMut(), modifier = Modifier.size(22.dp))
        }
    }
    if (!last) {
        Box(Modifier.fillMaxWidth().height(1.dp).background(if (dark) AccountDark.line else Color(0xFFF6E4E8)))
    }
}

// =============================================================================== starred

@Composable
fun StarredPage() {
    // Favourites with my own folders (spec 24).
    FavouritesPage()
}

// =============================================================================== calls

@Composable
fun CallsPage() {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    val listState = rememberLazyListState()
    var list by remember { mutableStateOf<List<CallDto>?>(null) }
    var page by remember { mutableIntStateOf(1) }
    var hasMore by remember { mutableStateOf(false) }
    var loadingMore by remember { mutableStateOf(false) }
    var total by remember { mutableIntStateOf(0) }
    val startCall = rememberCallStarter()

    suspend fun loadPage(reset: Boolean) {
        if (!reset && (loadingMore || !hasMore)) return
        if (!reset) loadingMore = true
        val next = if (reset) 1 else page + 1
        val envelope = runCatching { ApiClient.chat.calls(chatAuth(), page = next, perPage = 15) }.getOrNull()
        val chunk = envelope?.data.orEmpty()
        list = if (reset) chunk else {
            val known = list.orEmpty().map { it.id }.toSet()
            list.orEmpty() + chunk.filter { it.id !in known }
        }
        page = next
        hasMore = envelope?.pagination?.hasMorePages == true
        total = envelope?.pagination?.total ?: if (reset) chunk.size else maxOf(total, list.orEmpty().size)
        if (!reset) loadingMore = false
    }

    LaunchedEffect(Unit) { loadPage(reset = true) }
    LaunchedEffect(listState, list?.size, hasMore) {
        snapshotFlow {
            val info = listState.layoutInfo
            val last = info.visibleItemsInfo.lastOrNull()?.index ?: -1
            info.totalItemsCount > 0 && last >= info.totalItemsCount - 3
        }.distinctUntilChanged().filter { it }.collect { scope.launch { loadPage(reset = false) } }
    }

    ChPage(stringResource(R.string.ch_calls_title), onBack = { host.pop() }, settingsHeader = true) {
        val items = list
        when {
            items == null -> Box(Modifier.fillMaxSize()) { com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().padding(16.dp).height(200.dp)) }
            else -> LazyColumn(
                state = listState,
                contentPadding = PaddingValues(14.dp),
                verticalArrangement = Arrangement.spacedBy(6.dp),
            ) {
                item(key = "call-tools") {
                    CallPermissionCard()
                    Spacer(Modifier.height(8.dp))
                }
                item(key = "call-list-head") {
                    Row(
                        Modifier.fillMaxWidth().padding(horizontal = 4.dp, vertical = 8.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        PinkIcon(Icons.Rounded.Call)
                        Spacer(Modifier.width(10.dp))
                        Text(
                            stringResource(R.string.ch_calls_title),
                            color = settingsInk(),
                            fontSize = 16.sp,
                            fontWeight = FontWeight.ExtraBold,
                            modifier = Modifier.weight(1f),
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                        )
                        Text(total.toString(), color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
                    }
                }
                if (items.isEmpty()) item(key = "empty") {
                    Box(Modifier.fillMaxWidth().height(360.dp)) { ChEmptyState(Icons.Rounded.Call, stringResource(R.string.ch_calls_title), stringResource(R.string.ch_no_calls), animated = true) }
                }
                itemsIndexed(items, key = { _, c -> c.id }) { i, call ->
                    val other = if (call.conversationType == "group") null else call.participants.firstOrNull { it.profile?.isMe != true }?.profile
                    val title = call.groupName ?: other?.name.orEmpty()
                    val missed = call.status in setOf("missed", "cancelled", "declined")
                    Row(Modifier.fillMaxWidth().chStagger(i).clip(RoundedCornerShape(18.dp)).background(Ch.Surface).padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                        ChAvatar(other?.avatar, title, other?.key ?: call.conversationId, size = 48.dp, isGroup = call.conversationType == "group")
                        Spacer(Modifier.width(12.dp))
                        Column(Modifier.weight(1f)) {
                            Text(title, color = if (missed && !call.isOutgoing) Ch.Danger else Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Icon(
                                    when { missed && !call.isOutgoing -> Icons.Rounded.CallMissed; call.isOutgoing -> Icons.Rounded.CallMade; else -> Icons.Rounded.CallReceived },
                                    null, tint = if (missed) Ch.Danger else Ch.Success, modifier = Modifier.size(15.dp),
                                )
                                Spacer(Modifier.width(4.dp))
                                Text(listTime(call.createdAt) + " " + clockTime(call.createdAt) + (call.durationSeconds?.let { "  ·  " + durationText(it * 1000L) } ?: ""), color = Ch.Mut, fontSize = 12.5.sp)
                                // Didn't ring: an unknown caller, silenced by my setting.
                                if (call.silenced) {
                                    Spacer(Modifier.width(6.dp))
                                    Text("🔕 " + stringResource(R.string.bk_silenced), color = Ch.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                                }
                            }
                        }
                        // Recordings of this call I agreed to (spec 92).
                        if (call.recordingsCount > 0) {
                            Box(
                                Modifier.size(42.dp).clip(CircleShape).background(Ch.Red.copy(alpha = 0.08f)).clickable { ChatViewers.recordings = call.id },
                                contentAlignment = Alignment.Center,
                            ) { Icon(Icons.Rounded.FiberManualRecord, null, tint = Ch.Red, modifier = Modifier.size(16.dp)) }
                            Spacer(Modifier.width(8.dp))
                        }
                        Box(
                            Modifier.size(42.dp).clip(CircleShape).background(Ch.Red.copy(alpha = 0.08f))
                                .clickable { startCall(call.conversationId, call.type == "video", title, other?.avatar, other?.key) },
                            contentAlignment = Alignment.Center,
                        ) { Icon(if (call.type == "video") Icons.Rounded.Videocam else Icons.Rounded.Call, null, tint = Ch.Red, modifier = Modifier.size(21.dp)) }
                    }
                }
                if (loadingMore) item(key = "more") {
                    Box(Modifier.fillMaxWidth().padding(12.dp), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator(color = Ch.Red, strokeWidth = 2.dp, modifier = Modifier.size(22.dp))
                    }
                }
            }
        }
    }
}
