package com.dorr.app.ui.screens.chat

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
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
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.automirrored.rounded.ArrowForward
import androidx.compose.material.icons.automirrored.rounded.ExitToApp
import androidx.compose.material.icons.rounded.AlternateEmail
import androidx.compose.material.icons.rounded.Block
import com.dorr.app.network.apiFailure
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.CleaningServices
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Flag
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.Link
import androidx.compose.material.icons.rounded.HowToReg
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.NotificationsOff
import androidx.compose.material.icons.rounded.Palette
import androidx.compose.material.icons.rounded.PersonAdd
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.Settings
import androidx.compose.material.icons.rounded.Timer
import androidx.compose.material.icons.rounded.Videocam
import androidx.compose.material3.Icon
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.chat.CallController
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ConversationDto
import com.dorr.app.network.MemberDto
import com.dorr.app.network.MessageDto
import kotlinx.coroutines.launch

@Composable
fun ChatInfoPage(id: String) {
    val host = LocalChat.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var c by remember { mutableStateOf(host.find(id)) }
    var members by remember { mutableStateOf<List<MemberDto>>(emptyList()) }
    var gallery by remember { mutableStateOf<List<MessageDto>>(emptyList()) }
    var tab by remember { mutableStateOf("media") }
    var sheet by remember { mutableStateOf<String?>(null) }
    var memberSheet by remember { mutableStateOf<MemberDto?>(null) }
    val listState = rememberLazyListState()
    val linkCopied = stringResource(R.string.ch_link_copied)
    val startCall = rememberCallStarter()
    var editing by remember { mutableStateOf(false) }

    suspend fun reload() {
        c = runCatching { ApiClient.chat.conversation(chatAuth(), id).data }.getOrNull() ?: c
        // A channel's followers are listed to its admins only.
        if (c?.isGroup == true && (c?.isChannel != true || c?.isAdmin == true)) {
            members = runCatching { ApiClient.chat.members(chatAuth(), id).data }.getOrNull().orEmpty()
        }
    }
    LaunchedEffect(id) { reload() }
    LaunchedEffect(tab) {
        gallery = runCatching { ApiClient.chat.gallery(chatAuth(), id, if (tab == "docs") "documents" else tab).data?.messages }.getOrNull().orEmpty()
    }

    fun update(body: Map<String, Any?>) = scope.launch {
        runCatching { ApiClient.chat.updateSettings(chatAuth(), id, body).data }.getOrNull()?.let { c = it; host.upsert(it) }
    }

    val conversation = c
    val scrolled by remember { derivedStateOf { listState.firstVisibleItemIndex > 0 || listState.firstVisibleItemScrollOffset > 140 } }
    val headerAlpha by animateFloatAsState(if (scrolled) 1f else 0f, label = "infoHeader")

    Box(Modifier.fillMaxSize().background(Ch.Bg)) {
        LazyColumn(state = listState, contentPadding = PaddingValues(bottom = 40.dp), modifier = Modifier.fillMaxSize()) {
            // ------------------------------------------------------------ hero
            item {
                val shrink = (listState.firstVisibleItemScrollOffset / 400f).coerceIn(0f, 1f)
                Box(Modifier.fillMaxWidth().clip(RoundedCornerShape(bottomStart = 34.dp, bottomEnd = 34.dp)).background(Ch.HeaderBrush).padding(top = 66.dp, bottom = 22.dp)) {
                    Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
                        Box(Modifier.graphicsLayer { val s = 1f - 0.35f * shrink; scaleX = s; scaleY = s; alpha = 1f - shrink }) {
                            ChAvatar(conversation?.avatar, conversation?.title, conversation?.peer?.key ?: id, size = 112.dp, isGroup = conversation?.isGroup == true, ring = true)
                        }
                        Spacer(Modifier.height(12.dp))
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(conversation?.title.orEmpty(), color = Color.White, fontSize = 23.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center)
                            val g = conversation?.group
                            if (g != null && conversation.isMember && (conversation.isAdmin || !g.onlyAdminsEditInfo)) {
                                Spacer(Modifier.width(8.dp))
                                GlassIcon(Icons.Rounded.Edit, size = 34.dp) { editing = true }
                            }
                        }
                        Text(
                            when {
                                conversation?.isChannel == true -> listOfNotNull(conversation.group?.handle?.let { "@$it" }, stringResource(R.string.ch_followers, conversation.group?.membersCount ?: 0)).joinToString("  ·  ")
                                conversation?.isGroup == true -> stringResource(R.string.ch_members, conversation.group?.membersCount ?: 0)
                                conversation?.isSelf == true -> stringResource(R.string.ch_note_to_self_sub)
                                else -> ltrNumber(conversation?.peer?.phone)
                            },
                            color = Color.White.copy(alpha = 0.8f), fontSize = 14.sp,
                        )
                        conversation?.group?.description?.takeIf { it.isNotBlank() }?.let {
                            Text(it, color = Color.White.copy(alpha = 0.9f), fontSize = 13.5.sp, textAlign = TextAlign.Center, modifier = Modifier.padding(horizontal = 30.dp, vertical = 6.dp))
                        }
                        Spacer(Modifier.height(16.dp))
                        if (conversation != null && conversation.canSend && !conversation.isChannel && !conversation.isSelf) {
                            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                                QuickAction(Icons.Rounded.Call, stringResource(R.string.ch_call_voice), 0) { startCall(id, false, conversation.title.orEmpty(), conversation.avatar, conversation.peer?.key); host.pop() }
                                QuickAction(Icons.Rounded.Videocam, stringResource(R.string.ch_call_video), 1) { startCall(id, true, conversation.title.orEmpty(), conversation.avatar, conversation.peer?.key); host.pop() }
                                if (conversation.isGroup && (conversation.isAdmin || !conversation.group!!.onlyAdminsAddMembers)) {
                                    QuickAction(Icons.Rounded.PersonAdd, stringResource(R.string.ch_add_members), 2) { host.push(ChRoute.NewGroup(addTo = id)) }
                                }
                            }
                        }
                    }
                }
            }

            // ------------------------------------------------------------ gallery
            item {
                Card(Modifier.padding(horizontal = 14.dp, vertical = 8.dp)) {
                    Row(Modifier.fillMaxWidth().padding(6.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        listOf("media" to R.string.ch_media, "docs" to R.string.ch_docs, "links" to R.string.ch_links).forEach { (key, label) ->
                            val active = tab == key
                            val bg by animateColorAsState(if (active) Ch.Red else Color.Transparent, label = "tab")
                            Box(Modifier.weight(1f).clip(RoundedCornerShape(14.dp)).background(bg).clickable { tab = key }.padding(vertical = 9.dp), contentAlignment = Alignment.Center) {
                                Text(stringResource(label), color = if (active) Color.White else Ch.Mut, fontWeight = FontWeight.Bold, fontSize = 13.5.sp)
                            }
                        }
                    }
                    AnimatedContent(targetState = tab to gallery, label = "gallery", transitionSpec = { fadeIn() togetherWith fadeOut() }) { (_, items) ->
                        if (items.isEmpty()) {
                            Text(stringResource(R.string.ch_no_media), color = Ch.Soft, modifier = Modifier.fillMaxWidth().padding(24.dp), textAlign = TextAlign.Center)
                        } else if (tab == "media") {
                            Column(Modifier.padding(6.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                items.flatMap { m -> m.attachments.map { m to it } }.take(12).chunked(3).forEach { row ->
                                    Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                                        row.forEach { (m, a) ->
                                            Box(Modifier.weight(1f).aspectRatio(1f).clip(RoundedCornerShape(12.dp)).background(Ch.SurfaceMuted).clickable { host.push(ChRoute.Media(id, tab)) }) {
                                                AsyncImage(ApiClient.mediaUrl(if (m.type == "video") a.thumbnail ?: a.url else a.url), null, imageLoader = chatImages(context), contentScale = ContentScale.Crop, modifier = Modifier.matchParentSize())
                                                if (m.type == "video") Icon(Icons.Rounded.PlayArrow, null, tint = Color.White, modifier = Modifier.align(Alignment.Center).size(30.dp))
                                            }
                                        }
                                        repeat(3 - row.size) { Spacer(Modifier.weight(1f)) }
                                    }
                                }
                            }
                        } else {
                            Column(Modifier.padding(horizontal = 8.dp, vertical = 4.dp)) {
                                items.take(5).forEach { m ->
                                    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).clickable { host.push(ChRoute.Media(id, tab)) }.padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                                        Icon(if (tab == "docs") Icons.Rounded.Description else Icons.Rounded.Link, null, tint = Ch.Red, modifier = Modifier.size(22.dp))
                                        Spacer(Modifier.width(10.dp))
                                        Text(m.attachments.firstOrNull()?.name ?: plainChatText(m.body), color = Ch.Ink, fontSize = 13.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                    }
                                }
                            }
                        }
                    }
                    Row(
                        Modifier.fillMaxWidth().clickable { host.push(ChRoute.Media(id, tab)) }.padding(horizontal = 14.dp, vertical = 12.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(stringResource(R.string.ch_media_all), color = Ch.Red, fontWeight = FontWeight.ExtraBold, fontSize = 14.sp, modifier = Modifier.weight(1f))
                        Icon(Icons.AutoMirrored.Rounded.ArrowForward, null, tint = Ch.Red, modifier = Modifier.size(18.dp))
                    }
                }
            }

            // ------------------------------------------------------------ my settings
            if (conversation != null) item {
                Card(Modifier.padding(horizontal = 14.dp, vertical = 8.dp)) {
                    SettingRow(if (conversation.isMuted) Icons.Rounded.NotificationsOff else Icons.Rounded.Notifications, stringResource(R.string.ch_mute_notifications),
                        value = if (conversation.isMuted) stringResource(R.string.ch_muted) else null) { sheet = "mute" }
                    SettingRow(Icons.Rounded.Timer, stringResource(R.string.ch_disappearing), value = disappearingLabel(conversation.disappearingSeconds)) { sheet = "disappearing" }
                    // In my contacts? (They see my stories only if they are.)
                    if (!conversation.isGroup && !conversation.isSelf && conversation.peer != null) {
                        if (conversation.peer.isContact) {
                            SettingRow(Icons.Rounded.HowToReg, stringResource(R.string.ch_in_contacts), value = "✓") {}
                        } else {
                            val added = stringResource(R.string.ch_contact_added)
                            SettingRow(Icons.Rounded.PersonAdd, stringResource(R.string.ch_add_to_contacts)) {
                                scope.launch {
                                    runCatching { addPeerToContacts(conversation) }
                                        .onSuccess { fresh -> fresh?.let { c = it; host.upsert(it) }; host.showToast(added) }
                                        .onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }
                                }
                            }
                        }
                    }
                    ToggleRow(Icons.Rounded.Lock, stringResource(R.string.ch_lock_chat), conversation.isLocked) { update(mapOf("locked" to it)) }
                    SettingRow(Icons.Rounded.Palette, stringResource(R.string.ch_chat_theme), value = conversation.theme?.applied?.name ?: stringResource(R.string.ch_theme_dorr)) { sheet = "theme" }
                }
            }

            // ------------------------------------------------------------ group
            if (conversation?.isGroup == true) {
                if (conversation.isAdmin) item {
                    Card(Modifier.padding(horizontal = 14.dp, vertical = 8.dp)) {
                        SettingRow(Icons.Rounded.Link, stringResource(R.string.ch_invite_link)) {
                            scope.launch {
                                runCatching { ApiClient.chat.invite(chatAuth(), id).data }.getOrNull()?.let {
                                    (context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager).setPrimaryClip(ClipData.newPlainText("invite", it.link))
                                    host.showToast(linkCopied)
                                }
                            }
                        }
                        val g = conversation.group!!
                        if (conversation.isChannel) {
                            // A channel: public (in Discover) or link-only, and its @handle.
                            GroupToggle(stringResource(R.string.ch_channel_public), g.isPublic) { scope.launch { runCatching { ApiClient.chat.groupSettings(chatAuth(), id, mapOf("is_public" to it)).data }.getOrNull()?.let { c = it } } }
                            SettingRow(Icons.Rounded.AlternateEmail, stringResource(R.string.ch_channel_handle), value = g.handle?.let { "@$it" }) { sheet = "handle" }
                        } else {
                            GroupToggle(stringResource(R.string.ch_only_admins_send), g.onlyAdminsSend) { scope.launch { runCatching { ApiClient.chat.groupSettings(chatAuth(), id, mapOf("only_admins_send" to it)).data }.getOrNull()?.let { c = it } } }
                            GroupToggle(stringResource(R.string.ch_only_admins_add), g.onlyAdminsAddMembers) { scope.launch { runCatching { ApiClient.chat.groupSettings(chatAuth(), id, mapOf("only_admins_add_members" to it)).data }.getOrNull()?.let { c = it } } }
                        }
                        GroupToggle(stringResource(R.string.ch_only_admins_edit), g.onlyAdminsEditInfo) { scope.launch { runCatching { ApiClient.chat.groupSettings(chatAuth(), id, mapOf("only_admins_edit_info" to it)).data }.getOrNull()?.let { c = it } } }
                        GroupToggle(stringResource(R.string.ch_approve_joins), g.approveJoins) { scope.launch { runCatching { ApiClient.chat.groupSettings(chatAuth(), id, mapOf("approve_joins" to it)).data }.getOrNull()?.let { c = it } } }
                        // Who's waiting, with a badge that pops when someone new asks.
                        androidx.compose.animation.AnimatedVisibility(g.approveJoins || g.pendingJoinRequests > 0) {
                            SettingRow(
                                Icons.Rounded.HourglassTop, stringResource(R.string.ch_join_requests),
                                value = if (g.pendingJoinRequests > 0) g.pendingJoinRequests.toString() else null,
                            ) { sheet = "joins" }
                        }
                    }
                }
                if (members.isNotEmpty()) item {
                    Text(
                        stringResource(if (conversation.isChannel) R.string.ch_followers else R.string.ch_members, members.size),
                        color = Ch.Mut, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.padding(start = 26.dp, top = 12.dp, bottom = 4.dp),
                    )
                }
                itemsIndexed(members, key = { _, m -> m.participantId }) { i, m ->
                    Row(
                        Modifier.fillMaxWidth().chStagger(i).padding(horizontal = 14.dp, vertical = 2.dp).clip(RoundedCornerShape(18.dp)).background(Ch.Surface)
                            .clickable(enabled = conversation.isAdmin && m.profile?.isMe != true && m.role != "owner") { memberSheet = m }
                            .padding(12.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        ChAvatar(m.profile?.avatar, m.profile?.name, m.profile?.key, size = 44.dp, online = host.presence[m.profile?.key]?.online == true)
                        Spacer(Modifier.width(12.dp))
                        Text(if (m.profile?.isMe == true) stringResource(R.string.ch_you) else m.profile?.name.orEmpty(), color = Ch.Ink, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f), maxLines = 1)
                        if (m.role != "member") {
                            Text(
                                stringResource(if (m.role == "owner") R.string.ch_owner else R.string.ch_admin), color = Ch.Red, fontSize = 11.5.sp, fontWeight = FontWeight.ExtraBold,
                                modifier = Modifier.clip(RoundedCornerShape(10.dp)).background(Ch.Red.copy(alpha = 0.1f)).padding(horizontal = 8.dp, vertical = 3.dp),
                            )
                        }
                    }
                }
            }

            // ------------------------------------------------------------ danger zone
            if (conversation != null) item {
                Card(Modifier.padding(horizontal = 14.dp, vertical = 12.dp)) {
                    if (!conversation.isGroup && conversation.peer != null) {
                        SettingRow(Icons.Rounded.Block, stringResource(if (conversation.iBlocked == true) R.string.ch_unblock else R.string.ch_block), danger = true) { sheet = "block" }
                    }
                    // Nobody to report in my own notes.
                    if (!conversation.isSelf) SettingRow(Icons.Rounded.Flag, stringResource(R.string.ch_report), danger = true) { sheet = "report" }
                    SettingRow(Icons.Rounded.CleaningServices, stringResource(R.string.ch_clear_chat), danger = true) { sheet = "clear" }
                    if (conversation.isGroup && conversation.isMember) SettingRow(
                        Icons.AutoMirrored.Rounded.ExitToApp,
                        stringResource(if (conversation.isChannel) R.string.ch_unfollow else R.string.ch_leave_group), danger = true,
                    ) { sheet = "leave" }
                    else SettingRow(Icons.Rounded.Delete, stringResource(R.string.ch_delete_chat), danger = true) { sheet = "delete" }
                }
            }
        }

        // Compact header that fades in once the hero scrolls away.
        Box(Modifier.fillMaxWidth().graphicsLayer { alpha = headerAlpha }.background(Ch.HeaderBrush).padding(start = 66.dp, top = 22.dp, bottom = 18.dp)) {
            Text(conversation?.title.orEmpty(), color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 17.sp, maxLines = 1)
        }
        Box(Modifier.padding(12.dp)) { GlassIcon(Icons.AutoMirrored.Rounded.ArrowBack) { host.pop() } }
    }

    // ------------------------------------------------------------ sheets
    if (editing) c?.let { current ->
        GroupEditSheet(current, onDismiss = { editing = false }) { saved ->
            c = saved
            host.upsert(saved)
        }
    }
    when (sheet) {
        "theme" -> c?.let { current ->
            ThemePickerSheet(current, onDismiss = { sheet = null }) { saved ->
                c = saved
                host.upsert(saved)
            }
        }
        "report" -> c?.let { current ->
            ReportSheet(current, onDismiss = { sheet = null }) { changed -> if (changed) scope.launch { reload() } }
        }
        "joins" -> JoinRequestsSheet(id, onDismiss = { sheet = null }) { scope.launch { reload() } }
        "handle" -> TextInputSheet(
            stringResource(R.string.ch_channel_handle), initial = c?.group?.handle.orEmpty(), action = stringResource(R.string.ch_save),
            onDismiss = { sheet = null },
        ) { value ->
            host.scope.launch {
                try {
                    ApiClient.chat.channelHandle(chatAuth(), id, mapOf("handle" to value.ifBlank { null })).data?.let { c = it }
                } catch (e: Exception) {
                    e.apiFailure().message?.let { host.showToast(it) }
                }
            }
        }
        "mute" -> ChoiceSheet(stringResource(R.string.ch_mute_notifications), listOf(
            stringResource(R.string.ch_mute_8h) to { update(mapOf("mute" to "8h")); Unit },
            stringResource(R.string.ch_mute_1w) to { update(mapOf("mute" to "1w")); Unit },
            stringResource(R.string.ch_mute_always) to { update(mapOf("mute" to "always")); Unit },
            stringResource(R.string.ch_unmute) to { update(mapOf("mute" to "off")); Unit },
        ), onDismiss = { sheet = null })
        "disappearing" -> ChoiceSheet(stringResource(R.string.ch_disappearing), listOf(null, 86400, 604800, 7776000).map { s ->
            disappearingLabel(s) to {
                scope.launch { runCatching { ApiClient.chat.disappearing(chatAuth(), id, mapOf("seconds" to s)).data }.getOrNull()?.let { c = it } }
                Unit
            }
        }, onDismiss = { sheet = null })
        "clear" -> ChoiceSheet(stringResource(R.string.ch_clear_chat), listOf(stringResource(R.string.ch_clear_chat) to {
            scope.launch { runCatching { ApiClient.chat.clear(chatAuth(), id) }; host.pop(); host.pop() }
            Unit
        }), danger = true, subtitle = stringResource(R.string.ch_confirm_clear), onDismiss = { sheet = null })
        "delete" -> ChoiceSheet(stringResource(R.string.ch_delete_chat), listOf(stringResource(R.string.ch_delete_chat) to {
            scope.launch { runCatching { ApiClient.chat.deleteConversation(chatAuth(), id) }; host.remove(id); host.pop(); host.pop() }
            Unit
        }), danger = true, subtitle = stringResource(R.string.ch_confirm_delete_chat), onDismiss = { sheet = null })
        "leave" -> {
            val label = stringResource(if (c?.isChannel == true) R.string.ch_unfollow else R.string.ch_leave_group)
            ChoiceSheet(label, listOf(label to {
                scope.launch { runCatching { ApiClient.chat.leave(chatAuth(), id) }; reload() }
                Unit
            }), danger = true, subtitle = if (c?.isChannel == true) null else stringResource(R.string.ch_confirm_leave), onDismiss = { sheet = null })
        }
        "block" -> {
            val peer = conversation?.peer
            val blocking = conversation?.iBlocked != true
            ChoiceSheet(
                stringResource(if (blocking) R.string.ch_block else R.string.ch_unblock),
                listOf(stringResource(if (blocking) R.string.ch_block else R.string.ch_unblock) to {
                    scope.launch {
                        peer?.let { p ->
                            runCatching { if (blocking) ApiClient.chat.block(chatAuth(), mapOf("participant_id" to p.id)) else ApiClient.chat.unblock(chatAuth(), mapOf("participant_id" to p.id)) }
                        }
                        reload()
                    }
                    Unit
                }),
                danger = blocking,
                subtitle = if (blocking) stringResource(R.string.ch_confirm_block, conversation?.title.orEmpty()) else null,
                onDismiss = { sheet = null },
            )
        }
    }
    memberSheet?.let { m ->
        ChoiceSheet(m.profile?.name.orEmpty(), listOf(
            stringResource(if (m.role == "admin") R.string.ch_dismiss_admin else R.string.ch_make_admin) to {
                scope.launch { members = runCatching { ApiClient.chat.setRole(chatAuth(), id, m.participantId, mapOf("role" to if (m.role == "admin") "member" else "admin")).data }.getOrNull() ?: members }
                Unit
            },
            stringResource(R.string.ch_remove_member) to {
                scope.launch { members = runCatching { ApiClient.chat.removeMember(chatAuth(), id, m.participantId).data }.getOrNull() ?: members }
                Unit
            },
        ), onDismiss = { memberSheet = null })
    }
}

@Composable
private fun disappearingLabel(seconds: Int?): String = stringResource(
    when (seconds) {
        86400 -> R.string.ch_24h
        604800 -> R.string.ch_7d
        7776000 -> R.string.ch_90d
        else -> R.string.ch_off
    },
)

@Composable
private fun QuickAction(icon: ImageVector, label: String, index: Int, onClick: () -> Unit) {
    Column(
        Modifier.width(92.dp).chStagger(index).clip(RoundedCornerShape(18.dp)).background(Color.White.copy(alpha = 0.16f)).clickable(onClick = onClick).padding(vertical = 12.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Icon(icon, null, tint = Color.White, modifier = Modifier.size(24.dp))
        Spacer(Modifier.height(4.dp))
        Text(label, color = Color.White, fontSize = 11.5.sp, fontWeight = FontWeight.Bold, maxLines = 1)
    }
}

@Composable
internal fun Card(modifier: Modifier = Modifier, content: @Composable () -> Unit) {
    Column(modifier.fillMaxWidth().shadow(4.dp, RoundedCornerShape(22.dp), spotColor = Color.Black.copy(alpha = 0.08f)).clip(RoundedCornerShape(22.dp)).background(Ch.Surface)) { content() }
}

@Composable
internal fun SettingRow(icon: ImageVector, title: String, value: String? = null, danger: Boolean = false, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 16.dp, vertical = 14.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(36.dp).clip(RoundedCornerShape(11.dp)).background(if (danger) Ch.Danger.copy(alpha = 0.1f) else Ch.Red.copy(alpha = 0.08f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = if (danger) Ch.Danger else Ch.Red, modifier = Modifier.size(19.dp))
        }
        Spacer(Modifier.width(12.dp))
        Text(title, color = if (danger) Ch.Danger else Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.5.sp, modifier = Modifier.weight(1f))
        value?.let { Text(it, color = Ch.Mut, fontSize = 13.sp) }
    }
}

@Composable
internal fun ToggleRow(icon: ImageVector, title: String, checked: Boolean, subtitle: String? = null, onChange: (Boolean) -> Unit) {
    Row(Modifier.fillMaxWidth().clickable { onChange(!checked) }.padding(horizontal = 16.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(36.dp).clip(RoundedCornerShape(11.dp)).background(Ch.Red.copy(alpha = 0.08f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = Ch.Red, modifier = Modifier.size(19.dp))
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(title, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.5.sp)
            subtitle?.let { Text(it, color = Ch.Mut, fontSize = 12.sp) }
        }
        Switch(checked, onChange, colors = SwitchDefaults.colors(checkedTrackColor = Ch.Red, checkedThumbColor = Color.White))
    }
}

@Composable
private fun GroupToggle(title: String, checked: Boolean, onChange: (Boolean) -> Unit) = ToggleRow(Icons.Rounded.Settings, title, checked, onChange = onChange)
