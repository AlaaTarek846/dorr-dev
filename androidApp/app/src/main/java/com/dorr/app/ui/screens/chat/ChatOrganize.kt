package com.dorr.app.ui.screens.chat

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.spring
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.clickable
import androidx.compose.ui.graphics.Color
import androidx.compose.material.icons.rounded.Unarchive
import androidx.compose.material.icons.rounded.PushPin
import androidx.compose.material.icons.rounded.NotificationsOff
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.MarkChatUnread
import androidx.compose.material.icons.rounded.MarkChatRead
import androidx.compose.material.icons.rounded.LockOpen
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Folder
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Archive
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Campaign
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ContactDto
import com.dorr.app.network.ConversationDto
import com.dorr.app.network.FolderDto
import com.dorr.app.network.OpenDirectRequest
import com.dorr.app.network.apiFailure
import kotlinx.coroutines.launch

/** One tickable row: avatar, name, a line under it, and the tick. */
@Composable
private fun PickRow(index: Int, avatar: String?, title: String, subtitle: String?, key: String?, isGroup: Boolean, checked: Boolean, onToggle: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().chStagger(index % 14).clip(RoundedCornerShape(16.dp)).clickable(onClick = onToggle).padding(vertical = 8.dp, horizontal = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        ChAvatar(avatar, title, key, size = 42.dp, isGroup = isGroup)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(title, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            subtitle?.takeIf { it.isNotBlank() }?.let { Text(it, color = Ch.Mut, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis) }
        }
        AnimatedContent(checked, label = "pickTick", transitionSpec = { scaleIn(spring(dampingRatio = 0.4f)) togetherWith scaleOut() }) { on ->
            Icon(if (on) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (on) Ch.Red else Ch.Soft, modifier = Modifier.size(24.dp))
        }
    }
}

/**
 * Fill a folder: every chat I have (not requests), ticked when it's in the folder — search, tick
 * as many as you like, save once. Opens right after creating a folder, from its long-press menu,
 * and from an empty folder.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun FolderChatsSheet(folder: FolderDto, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var all by remember { mutableStateOf<List<ConversationDto>?>(null) }
    val picked = remember { mutableStateListOf<String>().apply { addAll(folder.conversationIds) } }
    var query by remember { mutableStateOf("") }
    var saving by remember { mutableStateOf(false) }
    val savedText = stringResource(R.string.ch_folder_saved)

    // Every chat, whatever the list on screen is filtered by right now.
    LaunchedEffect(Unit) {
        all = runCatching { ApiClient.chat.conversations(chatAuth(), perPage = 100).data }.getOrNull().orEmpty().filter { !it.isRequest }
    }
    val shown = all.orEmpty().filter { query.isBlank() || it.title.orEmpty().contains(query.trim(), ignoreCase = true) }

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 24.dp)) {
            Text(stringResource(R.string.ch_folder_pick_title), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(folder.name, color = Ch.Mut, fontSize = 13.sp)
            Spacer(Modifier.height(10.dp))
            ChField(query, { query = it }, stringResource(R.string.ch_search_hint), icon = Icons.Rounded.Search, clearable = true)
            Spacer(Modifier.height(8.dp))
            Box(Modifier.fillMaxWidth().heightIn(min = 120.dp, max = 420.dp)) {
                when {
                    all == null -> CircularProgressIndicator(Modifier.align(Alignment.Center).size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp)
                    shown.isEmpty() -> Text(stringResource(R.string.ch_no_results), color = Ch.Soft, modifier = Modifier.align(Alignment.Center))
                    else -> LazyColumn {
                        itemsIndexed(shown, key = { _, c -> c.id }) { i, c ->
                            val on = c.id in picked
                            PickRow(i, c.avatar, c.title.orEmpty(), plainChatText(c.lastMessage?.body), c.peer?.key ?: c.id, c.isGroup, on) {
                                if (on) picked.remove(c.id) else picked.add(c.id)
                            }
                        }
                    }
                }
            }
            Spacer(Modifier.height(12.dp))
            ChPrimaryButton(
                stringResource(R.string.ch_save) + if (picked.isNotEmpty()) "  (${picked.size})" else "",
                icon = Icons.Rounded.Check, modifier = Modifier.fillMaxWidth(), enabled = !saving && all != null,
            ) {
                saving = true
                host.scope.launch {
                    try {
                        ApiClient.chat.folderConversations(chatAuth(), folder.id, mapOf("conversations" to picked.toList())).data?.let { updated ->
                            val index = host.folders.indexOfFirst { it.id == updated.id }
                            if (index >= 0) host.folders[index] = updated
                        }
                        host.showToast(savedText.format(picked.size))
                        // Showing this folder now: refresh it with its new chats.
                        if (host.filter == "folder:${folder.id}") host.refreshList()
                        onDismiss()
                    } catch (e: Exception) {
                        e.apiFailure().message?.let { host.showToast(it) }
                    }
                    saving = false
                }
            }
        }
    }
}

/**
 * Nobody is *added* to a channel (people choose to follow it), so its admins invite instead: pick
 * contacts on Dorr, and each gets a private message with the channel's link — one tap to follow.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ChannelInviteSheet(channel: ConversationDto, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var contacts by remember { mutableStateOf<List<ContactDto>?>(null) }
    val picked = remember { mutableStateListOf<Int>() }
    var query by remember { mutableStateOf("") }
    var sending by remember { mutableStateOf(false) }
    val inviteText = stringResource(R.string.ch_channel_invite_text)
    val sentText = stringResource(R.string.ch_channel_invited)

    LaunchedEffect(Unit) {
        contacts = runCatching { ApiClient.chat.contacts(chatAuth(), registered = 1).data }.getOrNull().orEmpty()
            .filter { it.profile != null && it.profile.isMe != true }
            .distinctBy { it.profile!!.id }
    }
    val shown = contacts.orEmpty().filter { query.isBlank() || it.name.contains(query.trim(), ignoreCase = true) || it.phone.contains(query.filter { c -> c.isDigit() }.ifEmpty { "~" }) }

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 24.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.Campaign, null, tint = Ch.Red, modifier = Modifier.size(24.dp))
                Spacer(Modifier.width(8.dp))
                Text(stringResource(R.string.ch_channel_invite_contacts), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            }
            Text(stringResource(R.string.ch_channel_invite_sub), color = Ch.Mut, fontSize = 12.5.sp)
            Spacer(Modifier.height(10.dp))
            ChField(query, { query = it }, stringResource(R.string.ch_search_hint), icon = Icons.Rounded.Search, clearable = true)
            Spacer(Modifier.height(8.dp))
            Box(Modifier.fillMaxWidth().heightIn(min = 120.dp, max = 420.dp)) {
                when {
                    contacts == null -> CircularProgressIndicator(Modifier.align(Alignment.Center).size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp)
                    shown.isEmpty() -> Text(stringResource(R.string.ch_no_results), color = Ch.Soft, modifier = Modifier.align(Alignment.Center))
                    else -> LazyColumn {
                        itemsIndexed(shown, key = { _, c -> c.id }) { i, c ->
                            val id = c.profile!!.id
                            PickRow(i, c.profile.avatar, c.name, ltrNumber(c.phone), c.profile.key, false, id in picked) {
                                if (id in picked) picked.remove(id) else picked.add(id)
                            }
                        }
                    }
                }
            }
            Spacer(Modifier.height(12.dp))
            ChPrimaryButton(
                stringResource(R.string.ch_channel_invite_send, picked.size), icon = Icons.Rounded.Check,
                modifier = Modifier.fillMaxWidth(), enabled = !sending && picked.isNotEmpty(),
            ) {
                sending = true
                host.scope.launch {
                    try {
                        val link = ApiClient.chat.invite(chatAuth(), channel.id).data?.link ?: return@launch
                        val body = inviteText.format(channel.title.orEmpty(), link)
                        var sent = 0
                        // One by one into each person's direct chat (it's a normal message, not a group add).
                        contacts.orEmpty().filter { it.profile?.id in picked }.forEach { c ->
                            runCatching {
                                val chat = ApiClient.chat.openDirect(chatAuth(), OpenDirectRequest(c.profile!!.id, c.profile.type)).data ?: return@runCatching
                                ApiClient.chat.send(chatAuth(), chat.id, mapOf("type" to "text", "body" to body, "uuid" to java.util.UUID.randomUUID().toString()))
                                sent++
                            }
                        }
                        host.showToast(sentText.format(sent))
                        onDismiss()
                    } catch (e: Exception) {
                        e.apiFailure().message?.let { host.showToast(it) }
                    } finally {
                        sending = false
                    }
                }
            }
        }
    }
}

/**
 * Long-press on a chat in the list: everything you can do with it, like WhatsApp — pin, read /
 * unread, mute, archive, add to a folder, lock, delete. (Swiping the row still pins / archives.)
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ChatActionsSheet(conversation: ConversationDto, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var sub by remember { mutableStateOf<String?>(null) }

    fun settings(body: Map<String, Any?>, leavesList: Boolean = false) {
        onDismiss()
        if (leavesList) host.remove(conversation.id)
        host.scope.launch {
            runCatching { ApiClient.chat.updateSettings(chatAuth(), conversation.id, body).data }
                .onSuccess { updated -> if (leavesList) host.refreshList() else updated?.let { host.upsert(it) } }
                .onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }
        }
    }

    when (sub) {
        "folder" -> {
            FolderPickerSheet(conversation) { sub = null; onDismiss() }
            return
        }
        "mute" -> {
            ChoiceSheet(stringResource(R.string.ch_mute_notifications), listOf(
                stringResource(R.string.ch_mute_8h) to { settings(mapOf("mute" to "8h")); Unit },
                stringResource(R.string.ch_mute_1w) to { settings(mapOf("mute" to "1w")); Unit },
                stringResource(R.string.ch_mute_always) to { settings(mapOf("mute" to "always")); Unit },
            ), onDismiss = { sub = null; onDismiss() })
            return
        }
        "delete" -> {
            ChoiceSheet(stringResource(R.string.ch_delete_chat), listOf(stringResource(R.string.ch_delete_chat) to {
                onDismiss()
                host.remove(conversation.id)
                host.scope.launch { runCatching { ApiClient.chat.deleteConversation(chatAuth(), conversation.id) } }
                Unit
            }), danger = true, subtitle = stringResource(R.string.ch_confirm_delete_chat), onDismiss = { sub = null; onDismiss() })
            return
        }
    }

    val unread = conversation.unreadCount > 0 || conversation.markedUnread
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                ChAvatar(conversation.avatar, conversation.title, conversation.peer?.key ?: conversation.id, size = 46.dp, isGroup = conversation.isGroup)
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(conversation.title.orEmpty(), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    Text(stringResource(R.string.ch_swipe_hint), color = Ch.Soft, fontSize = 11.5.sp, maxLines = 2)
                }
            }
            Spacer(Modifier.height(12.dp))
            ActionRow(0, Icons.Rounded.PushPin, Color(0xFFF59E0B), stringResource(if (conversation.isPinned) R.string.ch_unpin_chat else R.string.ch_pin_chat)) {
                settings(mapOf("pinned" to !conversation.isPinned))
            }
            ActionRow(1, if (unread) Icons.Rounded.MarkChatRead else Icons.Rounded.MarkChatUnread, Color(0xFF0EA5E9), stringResource(if (unread) R.string.ch_mark_read else R.string.ch_mark_unread)) {
                if (unread) {
                    onDismiss()
                    host.upsert(conversation.copy(unreadCount = 0, markedUnread = false, hasUnreadMention = false))
                    host.scope.launch { runCatching { ApiClient.chat.markRead(chatAuth(), conversation.id) } }
                } else {
                    settings(mapOf("marked_unread" to true))
                }
            }
            ActionRow(2, if (conversation.isMuted) Icons.Rounded.Notifications else Icons.Rounded.NotificationsOff, Color(0xFF8B5CF6), stringResource(if (conversation.isMuted) R.string.ch_unmute else R.string.ch_mute)) {
                if (conversation.isMuted) settings(mapOf("mute" to "off")) else sub = "mute"
            }
            ActionRow(3, if (conversation.isArchived) Icons.Rounded.Unarchive else Icons.Rounded.Archive, Color(0xFF6B7280), stringResource(if (conversation.isArchived) R.string.ch_unarchive else R.string.ch_archive)) {
                // It leaves the list it's in (archived chats live under "Archived").
                settings(mapOf("archived" to !conversation.isArchived), leavesList = true)
            }
            ActionRow(4, Icons.Rounded.Folder, Color(0xFF10B981), stringResource(R.string.ch_add_to_folder)) { sub = "folder" }
            ActionRow(5, if (conversation.isLocked) Icons.Rounded.LockOpen else Icons.Rounded.Lock, Color(0xFF334155), stringResource(if (conversation.isLocked) R.string.ch_unlock_chat else R.string.ch_lock_chat)) {
                settings(mapOf("locked" to !conversation.isLocked), leavesList = true)
            }
            if (!conversation.isGroup || !conversation.isMember) {
                ActionRow(6, Icons.Rounded.Delete, Ch.Danger, stringResource(R.string.ch_delete_chat), danger = true) { sub = "delete" }
            }
        }
    }
}

@Composable
private fun ActionRow(index: Int, icon: androidx.compose.ui.graphics.vector.ImageVector, tint: Color, label: String, danger: Boolean = false, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().chStagger(index).clip(RoundedCornerShape(16.dp)).clickable(onClick = onClick).padding(vertical = 10.dp, horizontal = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(38.dp).clip(RoundedCornerShape(12.dp)).background(tint.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = tint, modifier = Modifier.size(21.dp))
        }
        Spacer(Modifier.width(14.dp))
        Text(label, color = if (danger) Ch.Danger else Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp)
    }
}
