@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.dorr.app.ui.screens.chat

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Folder
import androidx.compose.material.icons.rounded.Forum
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.LockOpen
import androidx.compose.material.icons.rounded.PersonAdd
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.Restore
import androidx.compose.material.icons.rounded.WorkspacePremium
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ConversationDto
import com.dorr.app.network.DeletedGroupDto
import com.dorr.app.network.GroupSpaceDto
import com.dorr.app.network.MemberDto
import com.dorr.app.network.apiFailure
import kotlinx.coroutines.launch

/*
 * Educational and work groups (docs/groups_tasks.md):
 * - GR-005: a hand-over waits for the member's yes — a card for the member and for the owner.
 * - GR-006: archive (read only) and deleted groups that can still come back.
 * - GR-007: terms / subgroups / projects, each its own chat — tabs over the chat, and a sheet to
 *   make and run them.
 * Everything refreshes on `chat.conversation.updated` (no asking the server on a timer).
 */

// ============================================================================ GR-005 hand-over

/** "Ahmed wants to hand the group to you" (accept / decline), or, for the owner, "waiting for Sara". */
@Composable
internal fun OwnershipOfferCard(conversation: ConversationDto, members: List<MemberDto>, onChanged: (ConversationDto) -> Unit) {
    val host = LocalChat.current
    val offer = conversation.group?.ownershipOffer ?: return
    var busy by remember { mutableStateOf(false) }
    val other = members.firstOrNull { it.participantId == if (offer.forMe) offer.fromParticipantId else offer.toParticipantId }?.profile?.name.orEmpty()

    fun act(call: suspend () -> ConversationDto?) {
        busy = true
        host.scope.launch {
            try {
                call()?.let { onChanged(it); host.upsert(it) }
            } catch (e: Exception) {
                e.apiFailure().message?.let { host.showToast(it) }
            }
            busy = false
        }
    }

    Column(
        Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 8.dp).clip(RoundedCornerShape(20.dp))
            .background(Color(0xFFF59E0B).copy(alpha = 0.10f)).border(1.dp, Color(0xFFF59E0B).copy(alpha = 0.35f), RoundedCornerShape(20.dp)).padding(14.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.WorkspacePremium, null, tint = Color(0xFFD97706), modifier = Modifier.size(22.dp))
            Spacer(Modifier.width(10.dp))
            Text(
                if (offer.forMe) stringResource(R.string.ch_owner_offer_for_me, other) else stringResource(R.string.ch_owner_offer_waiting, other),
                color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.sp, modifier = Modifier.weight(1f),
            )
        }
        Spacer(Modifier.height(10.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            if (offer.forMe) {
                ChPrimaryButton(stringResource(R.string.ch_owner_offer_accept), icon = Icons.Rounded.CheckCircle, modifier = Modifier.weight(1f), enabled = !busy) {
                    act { ApiClient.chat.acceptOwnership(chatAuth(), conversation.id).data }
                }
                SmallOutlined(stringResource(R.string.ch_owner_offer_decline), Modifier.weight(1f), enabled = !busy) {
                    act { ApiClient.chat.declineOwnership(chatAuth(), conversation.id).data }
                }
            } else {
                SmallOutlined(stringResource(R.string.ch_owner_offer_cancel), Modifier.fillMaxWidth(), enabled = !busy) {
                    act { ApiClient.chat.cancelOwnership(chatAuth(), conversation.id).data }
                }
            }
        }
    }
}

@Composable
private fun SmallOutlined(text: String, modifier: Modifier, enabled: Boolean = true, onClick: () -> Unit) {
    Box(
        modifier.clip(RoundedCornerShape(16.dp)).border(1.5.dp, Ch.Red.copy(alpha = 0.4f), RoundedCornerShape(16.dp))
            .clickable(enabled = enabled, onClick = onClick).padding(vertical = 12.dp),
        contentAlignment = Alignment.Center,
    ) { Text(text, color = Ch.Red, fontWeight = FontWeight.ExtraBold, fontSize = 14.sp) }
}

// ============================================================================ GR-006 deleted groups

/** My deleted groups that can still come back, each with "Bring back". */
@Composable
internal fun DeletedGroupsSheet(onDismiss: () -> Unit) {
    val host = LocalChat.current
    var rows by remember { mutableStateOf<List<DeletedGroupDto>?>(null) }
    val restored = stringResource(R.string.ch_group_restored)
    LaunchedEffect(Unit) { rows = runCatching { ApiClient.chat.deletedGroups(chatAuth()).data }.getOrNull().orEmpty() }

    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 18.dp).padding(bottom = 20.dp)) {
            Text(stringResource(R.string.ch_deleted_groups), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 19.sp)
            Text(stringResource(R.string.ch_deleted_groups_sub), color = Ch.Mut, fontSize = 12.5.sp, modifier = Modifier.padding(top = 4.dp, bottom = 12.dp))
            val list = rows
            when {
                list == null -> Box(Modifier.fillMaxWidth().height(120.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp) }
                list.isEmpty() -> Text(stringResource(R.string.ch_deleted_groups_empty), color = Ch.Soft, modifier = Modifier.fillMaxWidth().padding(vertical = 30.dp), textAlign = androidx.compose.ui.text.style.TextAlign.Center)
                else -> LazyColumn(Modifier.heightIn(max = 420.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    itemsIndexed(list, key = { _, g -> g.id }) { i, g ->
                        Row(
                            Modifier.fillMaxWidth().chStagger(i).clip(RoundedCornerShape(16.dp)).background(Ch.SurfaceMuted).padding(12.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(GroupKind.of(g.kind).icon, null, tint = Ch.Red, modifier = Modifier.size(22.dp))
                            Spacer(Modifier.width(10.dp))
                            Column(Modifier.weight(1f)) {
                                Text(g.name, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                Text(stringResource(R.string.ch_deleted_until, g.purgeAfter?.take(10).orEmpty()), color = Ch.Mut, fontSize = 12.sp)
                            }
                            Row(
                                Modifier.clip(RoundedCornerShape(50)).background(Ch.Red).clickable {
                                    host.scope.launch {
                                        try {
                                            ApiClient.chat.restoreGroup(chatAuth(), g.id).data?.let { host.upsert(it) }
                                            rows = rows.orEmpty().filter { it.id != g.id }
                                            host.refreshList()
                                            host.showToast(restored)
                                        } catch (e: Exception) {
                                            e.apiFailure().message?.let { host.showToast(it) }
                                        }
                                    }
                                }.padding(horizontal = 12.dp, vertical = 7.dp),
                                verticalAlignment = Alignment.CenterVertically,
                            ) {
                                Icon(Icons.Rounded.Restore, null, tint = Color.White, modifier = Modifier.size(16.dp))
                                Spacer(Modifier.width(4.dp))
                                Text(stringResource(R.string.ch_group_restore), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 12.5.sp)
                            }
                        }
                    }
                }
            }
        }
    }
}

// ============================================================================ GR-007 spaces

/** The main group of a chat: itself, or the group a term / project is in. */
private fun ConversationDto.mainGroupId(): String? = group?.space?.parentId ?: id.takeIf { (group?.kind ?: "normal") != "normal" }

/**
 * Over an educational / work group's chat (and its terms / projects): "General" and the spaces I'm
 * in, one tap away. Asked once when the chat opens and when it changes, never on a timer.
 */
@Composable
internal fun GroupSpaceTabs(c: ConversationDto?) {
    val host = LocalChat.current
    val main = c?.mainGroupId() ?: return
    var spaces by remember(main) { mutableStateOf<List<GroupSpaceDto>>(emptyList()) }
    LaunchedEffect(main, c.group?.membersCount, c.group?.space?.isClosed) {
        spaces = runCatching { ApiClient.chat.groupSpaces(chatAuth(), main).data }.getOrNull().orEmpty().filter { it.isMember }
    }
    AnimatedVisibility(spaces.isNotEmpty(), enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
        LazyRow(contentPadding = PaddingValues(horizontal = 14.dp, vertical = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            item(key = "main") {
                SpaceChip(stringResource(R.string.ch_space_general), Icons.Rounded.Forum, selected = c.id == main, closed = false) {
                    if (c.id != main) host.replace(ChRoute.Conversation(main))
                }
            }
            items(spaces, key = { it.id }) { s ->
                SpaceChip(s.name.orEmpty(), spaceIcon(s.type), selected = c.id == s.conversationId, closed = s.isClosed) {
                    if (c.id != s.conversationId) host.replace(ChRoute.Conversation(s.conversationId))
                }
            }
        }
    }
}

@Composable
private fun SpaceChip(label: String, icon: androidx.compose.ui.graphics.vector.ImageVector, selected: Boolean, closed: Boolean, onClick: () -> Unit) {
    Row(
        Modifier.clip(RoundedCornerShape(50))
            .then(if (selected) Modifier.background(Ch.HeaderBrush) else Modifier.background(Ch.Surface).border(1.dp, Ch.Line, RoundedCornerShape(50)))
            .clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 7.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(if (closed) Icons.Rounded.Lock else icon, null, tint = if (selected) Color.White else Ch.Red, modifier = Modifier.size(15.dp))
        Spacer(Modifier.width(5.dp))
        Text(label, color = if (selected) Color.White else Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 13.sp, maxLines = 1)
    }
}

private fun spaceIcon(type: String) = when (type) {
    "project" -> Icons.Rounded.Folder
    else -> Icons.Rounded.Forum
}

@Composable
private fun spaceTypeLabel(type: String): String = stringResource(
    when (type) {
        "term" -> R.string.ch_space_term
        "subgroup" -> R.string.ch_space_subgroup
        else -> R.string.ch_space_project
    },
)

/**
 * Terms / subgroups (educational) or projects (work): open one, and — with `spaces.manage` —
 * make one, add members from the group, close / reopen it.
 */
@Composable
internal fun GroupSpacesSheet(groupId: String, kind: String?, canManage: Boolean, members: List<MemberDto>, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var spaces by remember { mutableStateOf<List<GroupSpaceDto>?>(null) }
    // null: the list; "new": making one; else the space being given members.
    var mode by remember { mutableStateOf<Any?>(null) }
    // GR-012: the term whose next one is being started.
    var nextFor by remember { mutableStateOf<GroupSpaceDto?>(null) }
    suspend fun load() {
        spaces = runCatching { ApiClient.chat.groupSpaces(chatAuth(), groupId).data }.getOrNull().orEmpty()
    }
    LaunchedEffect(groupId) { load() }
    val types = if (kind == "work") listOf("project") else listOf("term", "subgroup")

    nextFor?.let { term ->
        NextTermSheet(groupId, term, members, onDone = { nextFor = null; host.scope.launch { load() } }, onDismiss = { nextFor = null })
        return
    }
    when (val m = mode) {
        "new" -> {
            NewSpaceSheet(groupId, types, members, onDone = { host.scope.launch { load() }; mode = null }, onDismiss = { mode = null })
            return
        }
        is GroupSpaceDto -> {
            PickMembersSheet(stringResource(R.string.ch_space_add_members), members.filter { it.role != "owner" }, onDismiss = { mode = null }) { ids ->
                host.scope.launch {
                    try {
                        ApiClient.chat.addSpaceMembers(chatAuth(), groupId, m.id, mapOf("members" to ids))
                        load()
                        mode = null
                    } catch (e: Exception) {
                        e.apiFailure().message?.let { host.showToast(it) }
                    }
                }
            }
            return
        }
    }

    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 18.dp).padding(bottom = 20.dp)) {
            Text(stringResource(if (kind == "work") R.string.ch_spaces_work else R.string.ch_spaces_education), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 19.sp)
            Text(stringResource(R.string.ch_spaces_sub), color = Ch.Mut, fontSize = 12.5.sp, lineHeight = 17.sp, modifier = Modifier.padding(top = 4.dp, bottom = 12.dp))
            val list = spaces
            when {
                list == null -> Box(Modifier.fillMaxWidth().height(120.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp) }
                list.isEmpty() -> Text(stringResource(R.string.ch_spaces_empty), color = Ch.Soft, fontSize = 13.sp, modifier = Modifier.padding(vertical = 18.dp))
                else -> LazyColumn(Modifier.heightIn(max = 400.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    itemsIndexed(list, key = { _, s -> s.id }) { i, s ->
                        Column(
                            Modifier.fillMaxWidth().chStagger(i).clip(RoundedCornerShape(16.dp)).background(Ch.SurfaceMuted)
                                .clickable(enabled = s.isMember) { onDismiss(); host.push(ChRoute.Conversation(s.conversationId)) }.padding(12.dp),
                        ) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Icon(if (s.isClosed) Icons.Rounded.Lock else spaceIcon(s.type), null, tint = Ch.Red, modifier = Modifier.size(20.dp))
                                Spacer(Modifier.width(10.dp))
                                Column(Modifier.weight(1f)) {
                                    Text(s.name.orEmpty(), color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                    Text(
                                        listOfNotNull(
                                            spaceTypeLabel(s.type),
                                            stringResource(R.string.ch_members, s.membersCount),
                                            if (s.isClosed) stringResource(R.string.ch_space_closed) else null,
                                        ).joinToString(" · "),
                                        color = Ch.Mut, fontSize = 12.sp,
                                    )
                                }
                            }
                            if (canManage) {
                                Row(Modifier.padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                    MiniAction(Icons.Rounded.PersonAdd, stringResource(R.string.ch_space_add_members)) { mode = s }
                                    if (s.type == "term") MiniAction(Icons.Rounded.Forum, stringResource(R.string.cl_next_term)) { nextFor = s }
                                    MiniAction(if (s.isClosed) Icons.Rounded.LockOpen else Icons.Rounded.Lock, stringResource(if (s.isClosed) R.string.ch_space_reopen else R.string.ch_space_close)) {
                                        host.scope.launch {
                                            try {
                                                ApiClient.chat.closeSpace(chatAuth(), groupId, s.id, mapOf("closed" to !s.isClosed))
                                                load()
                                            } catch (e: Exception) {
                                                e.apiFailure().message?.let { host.showToast(it) }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            if (canManage) {
                Spacer(Modifier.height(12.dp))
                ChPrimaryButton(stringResource(R.string.ch_space_new), icon = Icons.Rounded.Add, modifier = Modifier.fillMaxWidth()) { mode = "new" }
            }
        }
    }
}

@Composable
private fun MiniAction(icon: androidx.compose.ui.graphics.vector.ImageVector, label: String, onClick: () -> Unit) {
    Row(
        Modifier.clip(RoundedCornerShape(50)).background(Ch.Red.copy(alpha = 0.1f)).clickable(onClick = onClick).padding(horizontal = 10.dp, vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = Ch.Red, modifier = Modifier.size(15.dp))
        Spacer(Modifier.width(4.dp))
        Text(label, color = Ch.Red, fontWeight = FontWeight.Bold, fontSize = 12.sp)
    }
}

/** A new term / subgroup / project: its type, name, and who's in it (from the group's members). */
@Composable
private fun NewSpaceSheet(groupId: String, types: List<String>, members: List<MemberDto>, onDone: () -> Unit, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var type by remember { mutableStateOf(types.first()) }
    var name by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    val picked = remember { mutableStateListOf<Int>() }
    val candidates = members.filter { it.role != "owner" && it.profile?.isMe != true }
    // A term's dates (optional — GR-011).
    val context = androidx.compose.ui.platform.LocalContext.current
    var starts by remember { mutableStateOf<java.time.LocalDate?>(null) }
    var ends by remember { mutableStateOf<java.time.LocalDate?>(null) }

    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 18.dp).padding(bottom = 20.dp)) {
            Text(stringResource(R.string.ch_space_new), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 19.sp)
            Spacer(Modifier.height(12.dp))
            if (types.size > 1) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    types.forEach { t ->
                        SpaceChip(spaceTypeLabel(t), spaceIcon(t), selected = t == type, closed = false) { type = t }
                    }
                }
                Spacer(Modifier.height(10.dp))
            }
            ChField(name, { name = it.take(100) }, stringResource(R.string.ch_space_name), icon = spaceIcon(type))
            if (type == "term") {
                Row(Modifier.padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    SpaceChip("📅 " + (starts?.toString() ?: stringResource(R.string.cl_starts)), Icons.Rounded.Forum, selected = starts != null, closed = false) { pickDate(context) { starts = it } }
                    SpaceChip("📅 " + (ends?.toString() ?: stringResource(R.string.cl_ends)), Icons.Rounded.Forum, selected = ends != null, closed = false) { pickDate(context) { ends = it } }
                }
            }
            Text(stringResource(R.string.ch_space_members_pick, picked.size), color = Ch.Mut, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.padding(top = 12.dp, bottom = 4.dp))
            MemberChecklist(candidates, picked, Modifier.heightIn(max = 300.dp))
            Spacer(Modifier.height(12.dp))
            ChPrimaryButton(stringResource(R.string.ch_space_create), icon = Icons.Rounded.Add, modifier = Modifier.fillMaxWidth(), enabled = name.isNotBlank() && !busy) {
                busy = true
                host.scope.launch {
                    try {
                        ApiClient.chat.createSpace(chatAuth(), groupId, mapOf(
                            "type" to type, "name" to name.trim(), "members" to picked.toList(),
                            "starts_on" to starts?.takeIf { type == "term" }?.toString(), "ends_on" to ends?.takeIf { type == "term" }?.toString(),
                        ))
                        onDone()
                    } catch (e: Exception) {
                        e.apiFailure().message?.let { host.showToast(it) }
                    }
                    busy = false
                }
            }
        }
    }
}

/** Pick members of the group (ticks). */
@Composable
private fun PickMembersSheet(title: String, members: List<MemberDto>, onDismiss: () -> Unit, onPicked: (List<Int>) -> Unit) {
    val picked = remember { mutableStateListOf<Int>() }
    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 18.dp).padding(bottom = 20.dp)) {
            Text(title, color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 19.sp)
            Spacer(Modifier.height(10.dp))
            MemberChecklist(members.filter { it.profile?.isMe != true }, picked, Modifier.heightIn(max = 380.dp))
            Spacer(Modifier.height(12.dp))
            ChPrimaryButton(stringResource(R.string.ch_save), icon = Icons.Rounded.PersonAdd, modifier = Modifier.fillMaxWidth(), enabled = picked.isNotEmpty()) { onPicked(picked.toList()) }
        }
    }
}

@Composable
private fun MemberChecklist(members: List<MemberDto>, picked: MutableList<Int>, modifier: Modifier) {
    LazyColumn(modifier) {
        items(members, key = { it.participantId }) { m ->
            val on = m.participantId in picked
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp))
                    .clickable { if (on) picked.remove(m.participantId) else picked.add(m.participantId) }
                    .padding(horizontal = 6.dp, vertical = 8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                ChAvatar(m.profile?.avatar, m.profile?.name, m.profile?.key, size = 36.dp)
                Spacer(Modifier.width(10.dp))
                Text(m.profile?.name.orEmpty(), color = Ch.Ink, fontSize = 14.5.sp, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                Icon(if (on) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (on) Ch.Red else Ch.Soft, modifier = Modifier.size(22.dp))
            }
        }
    }
}
