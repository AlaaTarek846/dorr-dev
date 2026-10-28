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
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Chat
import androidx.compose.material.icons.rounded.AddModerator
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.PersonRemove
import androidx.compose.material.icons.rounded.RemoveModerator
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Videocam
import androidx.compose.material.icons.rounded.WorkspacePremium
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshotFlow
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ConversationDto
import com.dorr.app.network.MemberDto
import com.dorr.app.network.OpenDirectRequest
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.ConfirmDialog
import com.dorr.app.ui.screens.profile.PinkBackdrop
import com.dorr.app.ui.screens.profile.SubHeader
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.filter
import kotlinx.coroutines.launch

@Composable
fun GroupMembersPage(id: String) {
    val host = LocalChat.current
    var conversation by remember { mutableStateOf(host.find(id)) }
    var members by remember { mutableStateOf<List<MemberDto>?>(null) }
    var query by remember { mutableStateOf("") }
    var visibleCount by remember { mutableIntStateOf(15) }
    var memberSheet by remember { mutableStateOf<MemberDto?>(null) }
    val listState = rememberLazyListState()

    suspend fun reload() {
        conversation = runCatching { ApiClient.chat.conversation(chatAuth(), id).data }.getOrNull() ?: conversation
        members = runCatching { ApiClient.chat.members(chatAuth(), id).data }.getOrNull().orEmpty()
    }
    LaunchedEffect(id) { reload() }
    LaunchedEffect(query) { visibleCount = 15 }

    val filtered = remember(members, query) {
        val q = query.trim()
        val digits = q.filter(Char::isDigit)
        members.orEmpty().filter { m ->
            if (q.isBlank()) true
            else {
                val name = m.profile?.name.orEmpty()
                val phone = m.profile?.phone.orEmpty()
                name.contains(q, ignoreCase = true) ||
                    (digits.isNotEmpty() && phone.filter(Char::isDigit).contains(digits))
            }
        }
    }
    val paged = filtered.take(visibleCount)
    LaunchedEffect(listState, paged.size, filtered.size) {
        snapshotFlow {
            val info = listState.layoutInfo
            val last = info.visibleItemsInfo.lastOrNull()?.index ?: 0
            info.totalItemsCount > 0 && last >= info.totalItemsCount - 3 && visibleCount < filtered.size
        }.distinctUntilChanged().filter { it }.collect {
            visibleCount = (visibleCount + 15).coerceAtMost(filtered.size)
        }
    }

    val title = if (conversation?.isChannel == true) stringResource(R.string.ch_followers_list) else stringResource(R.string.ch_group_members)
    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize()) {
            SubHeader(title) { host.pop() }
            ChField(
                query, { query = it }, stringResource(R.string.ch_search_contacts),
                modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp).fillMaxWidth(),
                icon = Icons.Rounded.Search, clearable = true,
            )
            when {
                members == null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(Modifier.size(28.dp), color = Ch.Red, strokeWidth = 2.5.dp)
                }
                filtered.isEmpty() -> Text(
                    stringResource(R.string.ch_no_results), color = Ch.Soft, textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth().padding(30.dp),
                )
                else -> LazyColumn(
                    state = listState,
                    contentPadding = PaddingValues(start = 14.dp, end = 14.dp, bottom = 24.dp),
                    verticalArrangement = Arrangement.spacedBy(6.dp),
                    modifier = Modifier.weight(1f).fillMaxWidth(),
                ) {
                    itemsIndexed(paged, key = { _, m -> m.participantId }) { i, m ->
                        GroupMemberRow(m, i, isAdmin = conversation?.isAdmin == true, carded = true, lined = false) { memberSheet = m }
                    }
                }
            }
        }
    }

    memberSheet?.let { m ->
        MemberActionsSheet(
            groupId = id,
            member = m,
            myRole = conversation?.myRole,
            onMembers = { members = it },
            onConversation = { conversation = it },
            onDismiss = { memberSheet = null },
        )
    }
}

@Composable
internal fun GroupMemberRow(m: MemberDto, index: Int, isAdmin: Boolean, carded: Boolean = false, lined: Boolean = true, onClick: () -> Unit) {
    val host = LocalChat.current
    val name = if (m.profile?.isMe == true) stringResource(R.string.ch_you) else m.profile?.name.orEmpty()
    val phone = m.profile?.phone
    val canOpen = isAdmin && m.profile?.isMe != true && m.role != "owner"
    Column(Modifier.fillMaxWidth().chStagger(index)) {
        Row(
            Modifier.fillMaxWidth()
                .then(if (carded) Modifier.clip(RoundedCornerShape(18.dp)).background(Ch.Surface) else Modifier)
                .clickable(enabled = canOpen, onClick = onClick)
                .padding(horizontal = if (carded) 12.dp else 16.dp, vertical = if (carded) 12.dp else 10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            ChAvatar(m.profile?.avatar, m.profile?.name, m.profile?.key, size = if (carded) 46.dp else 40.dp, online = host.presence[m.profile?.key]?.online == true)
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(name, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                if (carded && !phone.isNullOrBlank()) {
                    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                        Text(ltrNumber(phone), color = Ch.Mut, fontSize = 12.5.sp)
                    }
                }
            }
            if (m.role != "member") {
                Text(
                    stringResource(if (m.role == "owner") R.string.ch_owner else R.string.ch_admin),
                    color = Ch.Red, fontSize = 11.5.sp, fontWeight = FontWeight.ExtraBold,
                    modifier = Modifier.clip(RoundedCornerShape(10.dp)).background(Ch.Red.copy(alpha = 0.1f)).padding(horizontal = 8.dp, vertical = 3.dp),
                )
            }
        }
        if (lined && !carded) HorizontalDivider(Modifier.padding(start = 68.dp, end = 16.dp), color = Ch.Line)
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun MemberActionsSheet(
    groupId: String,
    member: MemberDto,
    myRole: String?,
    onMembers: (List<MemberDto>) -> Unit,
    onConversation: (ConversationDto) -> Unit,
    onDismiss: () -> Unit,
) {
    val host = LocalChat.current
    val startCall = rememberCallStarter()
    val profile = member.profile
    val name = if (profile?.isMe == true) stringResource(R.string.ch_you) else profile?.name.orEmpty()
    val phone = profile?.phone
    val who = profile?.name.orEmpty()
    val isAdmin = member.role == "admin"
    val networkError = stringResource(R.string.ch_error_network)
    val ownerDone = stringResource(R.string.ch_member_now_owner, who)
    val roleDone = stringResource(if (isAdmin) R.string.ch_member_admin_dismissed else R.string.ch_member_now_admin, who)
    val removedDone = stringResource(R.string.ch_member_removed, who)
    var confirmRemove by remember { mutableStateOf(false) }

    if (confirmRemove) {
        ConfirmDialog(
            icon = Icons.Rounded.PersonRemove,
            title = stringResource(R.string.ch_remove_member),
            message = stringResource(R.string.ch_confirm_remove_member, who),
            confirmLabel = stringResource(R.string.ch_remove),
            onConfirm = {
                runMemberAction(host, onDismiss, removedDone, networkError) {
                    ApiClient.chat.removeMember(chatAuth(), groupId, member.participantId).data?.let(onMembers)
                }
            },
            onDismiss = { confirmRemove = false },
        )
        return
    }

    val adminRows = listOfNotNull(
        if (myRole == "owner") MemberAdminAction(Icons.Rounded.WorkspacePremium, stringResource(R.string.ch_make_owner)) {
            runMemberAction(host, onDismiss, ownerDone, networkError) {
                ApiClient.chat.transferOwnership(chatAuth(), groupId, mapOf("participant_id" to member.participantId)).data?.let {
                    onConversation(it)
                    host.upsert(it)
                }
                ApiClient.chat.members(chatAuth(), groupId).data?.let(onMembers)
            }
        } else null,
        MemberAdminAction(
            if (isAdmin) Icons.Rounded.RemoveModerator else Icons.Rounded.AddModerator,
            stringResource(if (isAdmin) R.string.ch_dismiss_admin else R.string.ch_make_admin),
        ) {
            runMemberAction(host, onDismiss, roleDone, networkError) {
                ApiClient.chat.setRole(chatAuth(), groupId, member.participantId, mapOf("role" to if (isAdmin) "member" else "admin")).data?.let(onMembers)
            }
        },
        MemberAdminAction(Icons.Rounded.PersonRemove, stringResource(R.string.ch_remove_member), danger = true) { confirmRemove = true },
    )

    val openDirectThen: (Boolean?) -> Unit = { video ->
        val p = profile
        if (p != null) {
            onDismiss()
            if (video == null) {
                host.openChatWith(p, keepCurrent = true)
            } else {
                host.scope.launch {
                    try {
                        val conv = ApiClient.chat.openDirect(chatAuth(), OpenDirectRequest(p.id, p.type)).data
                        if (conv != null) startCall(conv.id, video, p.name.orEmpty(), p.avatar, p.key)
                    } catch (e: Exception) {
                        e.apiFailure().message?.let { host.showToast(it) }
                    }
                }
            }
        }
    }

    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(
            Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 20.dp).padding(bottom = 24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            ChAvatar(profile?.avatar, name, profile?.key, size = 82.dp, online = host.presence[profile?.key]?.online == true)
            Spacer(Modifier.height(10.dp))
            Text(name, color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp, textAlign = TextAlign.Center, maxLines = 1, overflow = TextOverflow.Ellipsis)
            if (!phone.isNullOrBlank()) {
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    Text(ltrNumber(phone), color = Ch.Mut, fontSize = 14.sp, fontWeight = FontWeight.SemiBold)
                }
            }
            Spacer(Modifier.height(20.dp))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceEvenly) {
                MemberQuickIcon(Icons.AutoMirrored.Rounded.Chat, stringResource(R.string.ch_message)) { openDirectThen(null) }
                MemberQuickIcon(Icons.Rounded.Call, stringResource(R.string.ch_call)) { openDirectThen(false) }
                MemberQuickIcon(Icons.Rounded.Videocam, stringResource(R.string.ch_video)) { openDirectThen(true) }
            }
            Spacer(Modifier.height(14.dp))
            HorizontalDivider(color = Ch.Line)
            adminRows.forEachIndexed { i, row ->
                val tint = if (row.danger) Ch.Danger else Ch.Red
                Row(
                    Modifier.fillMaxWidth().chStagger(i).clickable(onClick = row.onClick).padding(horizontal = 4.dp, vertical = 12.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Box(Modifier.size(34.dp).clip(RoundedCornerShape(10.dp)).background(tint.copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
                        Icon(row.icon, null, tint = tint, modifier = Modifier.size(18.dp))
                    }
                    Spacer(Modifier.width(12.dp))
                    Text(
                        row.label,
                        color = if (row.danger) Ch.Danger else Ch.Ink,
                        fontWeight = FontWeight.Bold,
                        fontSize = 15.sp,
                        modifier = Modifier.weight(1f),
                    )
                }
                HorizontalDivider(color = Ch.Line)
            }
            Box(
                Modifier.fillMaxWidth().padding(top = 14.dp).clip(RoundedCornerShape(16.dp)).background(Ch.SurfaceMuted).clickable(onClick = onDismiss).padding(vertical = 14.dp),
                contentAlignment = Alignment.Center,
            ) {
                Text(stringResource(R.string.ch_cancel), color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp)
            }
        }
    }
}

private fun runMemberAction(host: ChatHost, onDismiss: () -> Unit, done: String, networkError: String, call: suspend () -> Unit) {
    onDismiss()
    host.scope.launch {
        try {
            call()
            host.showToast(done)
        } catch (e: Exception) {
            host.showToast(e.apiFailure().message ?: networkError)
        }
    }
}

private class MemberAdminAction(val icon: ImageVector, val label: String, val danger: Boolean = false, val onClick: () -> Unit)

@Composable
private fun MemberQuickIcon(icon: ImageVector, label: String, onClick: () -> Unit) {
    Column(
        Modifier.width(84.dp).clickable(onClick = onClick).padding(vertical = 4.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Box(
            Modifier
                .size(56.dp)
                .shadow(10.dp, CircleShape, spotColor = Color.Black.copy(alpha = 0.22f), ambientColor = Color.Black.copy(alpha = 0.1f))
                .clip(CircleShape)
                .background(Ch.Red.copy(alpha = 0.12f)),
            contentAlignment = Alignment.Center,
        ) {
            Icon(icon, null, tint = Ch.Red, modifier = Modifier.size(24.dp))
        }
        Spacer(Modifier.height(8.dp))
        Text(label, color = Ch.Ink, fontSize = 12.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center, maxLines = 1)
    }
}
