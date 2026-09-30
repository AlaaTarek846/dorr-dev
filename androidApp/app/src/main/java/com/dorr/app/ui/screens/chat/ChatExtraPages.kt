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
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AddPhotoAlternate
import androidx.compose.material.icons.rounded.Block
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.CallMade
import androidx.compose.material.icons.rounded.CallMissed
import androidx.compose.material.icons.rounded.CallReceived
import androidx.compose.material.icons.rounded.DoneAll
import androidx.compose.material.icons.rounded.GroupAdd
import androidx.compose.material.icons.rounded.Message
import androidx.compose.material.icons.rounded.NotificationsActive
import androidx.compose.material.icons.rounded.Screenshot
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material.icons.rounded.Videocam
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
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
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.chat.CallController
import com.dorr.app.network.ApiClient
import com.dorr.app.network.BlockDto
import com.dorr.app.network.CallDto
import com.dorr.app.network.MessageDto
import com.dorr.app.network.PrivacyDto
import kotlinx.coroutines.launch

// =============================================================================== privacy

@Composable
fun PrivacyPage() {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var privacy by remember { mutableStateOf<PrivacyDto?>(null) }
    var blocked by remember { mutableStateOf<List<BlockDto>>(emptyList()) }
    var picker by remember { mutableStateOf<Pair<String, String>?>(null) }

    LaunchedEffect(Unit) {
        privacy = runCatching { ApiClient.chat.privacy(chatAuth()).data }.getOrNull()
        blocked = runCatching { ApiClient.chat.blocks(chatAuth()).data }.getOrNull().orEmpty()
    }
    fun save(key: String, value: Any) = scope.launch {
        runCatching { ApiClient.chat.updatePrivacy(chatAuth(), mapOf(key to value)).data }.getOrNull()?.let { privacy = it }
    }

    ChPage(stringResource(R.string.ch_privacy_title), onBack = { host.pop() }) {
        val p = privacy
        LazyColumn(contentPadding = PaddingValues(14.dp), verticalArrangement = Arrangement.spacedBy(12.dp), modifier = Modifier.fillMaxSize()) {
            if (p == null) {
                item { com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().height(320.dp), RoundedCornerShape(22.dp)) }
            } else {
                item {
                    Card {
                        AudienceRow(Icons.Rounded.Visibility, stringResource(R.string.ch_last_seen_setting), p.lastSeen) { picker = "last_seen" to it }
                        AudienceRow(Icons.Rounded.AddPhotoAlternate, stringResource(R.string.ch_profile_photo_setting), p.profilePhoto) { picker = "profile_photo" to it }
                        AudienceRow(Icons.Rounded.Message, stringResource(R.string.ch_who_message), p.whoCanMessage) { picker = "who_can_message" to it }
                        AudienceRow(Icons.Rounded.GroupAdd, stringResource(R.string.ch_who_groups), p.whoCanAddToGroups) { picker = "who_can_add_to_groups" to it }
                        AudienceRow(Icons.Rounded.Call, stringResource(R.string.ch_who_call), p.whoCanCall) { picker = "who_can_call" to it }
                    }
                }
                item {
                    Card {
                        ToggleRow(Icons.Rounded.DoneAll, stringResource(R.string.ch_read_receipts), p.readReceipts, subtitle = stringResource(R.string.ch_read_receipts_sub)) { save("read_receipts", it) }
                        ToggleRow(Icons.Rounded.Screenshot, stringResource(R.string.ch_block_screenshots), p.blockScreenshots) { save("block_screenshots", it) }
                        ToggleRow(Icons.Rounded.NotificationsActive, stringResource(R.string.ch_notification_preview), p.notificationPreview) { save("notification_preview", it) }
                    }
                }
                item { Text(stringResource(R.string.ch_blocked_list), color = Ch.Mut, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.padding(start = 8.dp)) }
                if (blocked.isEmpty()) {
                    item { Text(stringResource(R.string.ch_no_blocked), color = Ch.Soft, modifier = Modifier.padding(start = 8.dp)) }
                }
                itemsIndexed(blocked, key = { _, b -> b.profile?.key ?: b.blockedAt.orEmpty() }) { i, b ->
                    Row(Modifier.fillMaxWidth().chStagger(i).clip(RoundedCornerShape(18.dp)).background(Ch.Surface).padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                        ChAvatar(b.profile?.avatar, b.profile?.name, b.profile?.key, size = 44.dp)
                        Spacer(Modifier.width(12.dp))
                        Text(b.profile?.name.orEmpty(), color = Ch.Ink, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                        Text(
                            stringResource(R.string.ch_unblock), color = Ch.Red, fontWeight = FontWeight.ExtraBold, fontSize = 13.sp,
                            modifier = Modifier.clip(RoundedCornerShape(12.dp)).background(Ch.Red.copy(alpha = 0.08f)).clickable {
                                val id = b.profile?.id ?: return@clickable
                                scope.launch { blocked = runCatching { ApiClient.chat.unblock(chatAuth(), mapOf("participant_id" to id)).data }.getOrNull() ?: blocked }
                            }.padding(horizontal = 12.dp, vertical = 7.dp),
                        )
                    }
                }
            }
        }
    }

    picker?.let { (key, current) ->
        ChoiceSheet(
            title = stringResource(R.string.ch_privacy_title),
            options = listOf("everyone" to R.string.ch_everyone, "contacts" to R.string.ch_my_contacts, "nobody" to R.string.ch_nobody).map { (value, label) ->
                (if (value == current) "✓  " else "") + stringResource(label) to { save(key, value); Unit }
            },
            onDismiss = { picker = null },
        )
    }
}

@Composable
private fun AudienceRow(icon: ImageVector, title: String, value: String, onClick: (String) -> Unit) {
    SettingRow(icon, title, value = stringResource(when (value) { "contacts" -> R.string.ch_my_contacts; "nobody" -> R.string.ch_nobody; else -> R.string.ch_everyone })) { onClick(value) }
}

// =============================================================================== starred

@Composable
fun StarredPage() {
    val host = LocalChat.current
    var list by remember { mutableStateOf<List<MessageDto>?>(null) }
    LaunchedEffect(Unit) { list = runCatching { ApiClient.chat.starred(chatAuth()).data }.getOrNull().orEmpty() }

    ChPage(stringResource(R.string.ch_starred_title), onBack = { host.pop() }) {
        val items = list
        when {
            items == null -> Box(Modifier.fillMaxSize()) { com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().padding(16.dp).height(200.dp)) }
            items.isEmpty() -> ChEmptyState(Icons.Rounded.Star, stringResource(R.string.ch_starred_title), stringResource(R.string.ch_no_starred), animated = true)
            else -> LazyColumn(contentPadding = PaddingValues(vertical = 12.dp)) {
                itemsIndexed(items, key = { _, m -> m.id }) { i, m ->
                    Column(Modifier.fillMaxWidth().chStagger(i).clickable { host.push(ChRoute.Conversation(m.conversationId)) }.padding(vertical = 6.dp)) {
                        Row(Modifier.padding(horizontal = 18.dp), verticalAlignment = Alignment.CenterVertically) {
                            ChAvatar(m.sender?.avatar, m.sender?.name, m.sender?.key, size = 26.dp)
                            Spacer(Modifier.width(8.dp))
                            Text(if (m.sender?.isMe == true) stringResource(R.string.ch_you) else m.sender?.name.orEmpty(), color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.weight(1f))
                            Text(listTime(m.createdAt), color = Ch.Soft, fontSize = 11.5.sp)
                        }
                        MessageRow(UiMessage(m), firstInRun = true, lastInRun = true, isGroup = false, actions = BubbleActions({}, {}, {}, {}, { _, _ -> }, { host.openWalletQr(it) }, {}))
                    }
                }
            }
        }
    }
}

// =============================================================================== calls

@Composable
fun CallsPage() {
    val host = LocalChat.current
    var list by remember { mutableStateOf<List<CallDto>?>(null) }
    LaunchedEffect(Unit) { list = runCatching { ApiClient.chat.calls(chatAuth()).data }.getOrNull().orEmpty() }
    val startCall = rememberCallStarter()

    ChPage(stringResource(R.string.ch_calls_title), onBack = { host.pop() }) {
        val items = list
        when {
            items == null -> Box(Modifier.fillMaxSize()) { com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().padding(16.dp).height(200.dp)) }
            items.isEmpty() -> ChEmptyState(Icons.Rounded.Call, stringResource(R.string.ch_calls_title), stringResource(R.string.ch_no_calls), animated = true)
            else -> LazyColumn(contentPadding = PaddingValues(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
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
                            }
                        }
                        Box(
                            Modifier.size(42.dp).clip(CircleShape).background(Ch.Red.copy(alpha = 0.08f))
                                .clickable { startCall(call.conversationId, call.type == "video", title, other?.avatar, other?.key) },
                            contentAlignment = Alignment.Center,
                        ) { Icon(if (call.type == "video") Icons.Rounded.Videocam else Icons.Rounded.Call, null, tint = Ch.Red, modifier = Modifier.size(21.dp)) }
                    }
                }
            }
        }
    }
}
