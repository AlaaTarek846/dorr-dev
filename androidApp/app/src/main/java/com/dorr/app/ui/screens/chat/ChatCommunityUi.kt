package com.dorr.app.ui.screens.chat

import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ExitToApp
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.AddLink
import androidx.compose.material.icons.rounded.Campaign
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Diversity3
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.GroupAdd
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.Subject
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
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
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.CommunityDto
import com.dorr.app.network.CommunityGroupDto
import com.dorr.app.network.MemberDto
import com.dorr.app.network.apiFailure
import kotlinx.coroutines.launch

/*
 * Communities (WhatsApp list 93–98): related groups under one roof, with an announcements group
 * only its admins write in. Members see every group in one place and hop in; admins (up to 20)
 * add / make / remove groups and people; the creator stays an admin until they delete it.
 */

// =============================================================================== my communities

@Composable
fun CommunitiesPage() {
    val host = LocalChat.current
    var list by remember { mutableStateOf<List<CommunityDto>?>(null) }
    var creating by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { list = runCatching { ApiClient.chat.communities(chatAuth()).data }.getOrNull().orEmpty() }

    ChPage(stringResource(R.string.cm_title), onBack = { host.pop() }) {
        LazyColumn(contentPadding = PaddingValues(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            item(key = "new") {
                CommunityRow(Icons.Rounded.Add, stringResource(R.string.cm_new), stringResource(R.string.cm_new_sub), avatar = null) { creating = true }
            }
            val items = list
            when {
                items == null -> item(key = "loading") { Box(Modifier.fillMaxWidth().height(160.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp) } }
                items.isEmpty() -> item(key = "empty") {
                    Box(Modifier.fillMaxWidth().height(340.dp)) { ChEmptyState(Icons.Rounded.Diversity3, stringResource(R.string.cm_title), stringResource(R.string.cm_empty), animated = true) }
                }
                else -> itemsIndexed(items, key = { _, c -> c.id }) { i, c ->
                    Box(Modifier.chStagger(i)) {
                        CommunityRow(null, c.name, stringResource(R.string.cm_counts, c.groupsCount, c.membersCount), avatar = c.avatar, key = c.id) { host.push(ChRoute.Community(c.id)) }
                    }
                }
            }
        }
    }

    if (creating) NewCommunitySheet(onDismiss = { creating = false }) { made ->
        list = listOf(made) + list.orEmpty()
        host.push(ChRoute.Community(made.id))
    }
}

@Composable
private fun CommunityRow(icon: ImageVector?, title: String, sub: String?, avatar: String?, key: String? = null, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Ch.Surface).clickable(onClick = onClick).padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (icon != null) {
            Box(Modifier.size(48.dp).clip(RoundedCornerShape(14.dp)).background(Ch.Red.copy(alpha = 0.08f)), contentAlignment = Alignment.Center) {
                Icon(icon, null, tint = Ch.Red, modifier = Modifier.size(24.dp))
            }
        } else {
            ChAvatar(avatar, title, key, size = 48.dp, isGroup = true)
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(title, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            sub?.let { Text(it, color = Ch.Mut, fontSize = 12.5.sp, maxLines = 2, overflow = TextOverflow.Ellipsis) }
        }
    }
}

/** Name it, say what it's for, and pick the groups I run that start in it. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun NewCommunitySheet(onDismiss: () -> Unit, onCreated: (CommunityDto) -> Unit) {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var name by remember { mutableStateOf("") }
    var description by remember { mutableStateOf("") }
    val picked = remember { mutableStateListOf<String>() }
    var busy by remember { mutableStateOf(false) }
    val networkError = stringResource(R.string.ch_error_network)
    val mine = remember(host.conversations.size) { myFreeGroups(host) }

    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().imePadding().heightIn(max = 660.dp).verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                IconBadge(Icons.Rounded.Diversity3, Color(0xFF14B8A6))
                Spacer(Modifier.width(12.dp))
                Column {
                    Text(stringResource(R.string.cm_new), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
                    Text(stringResource(R.string.cm_new_sub), color = Ch.Mut, fontSize = 12.5.sp)
                }
            }
            Spacer(Modifier.height(14.dp))
            SheetField(name, stringResource(R.string.cm_name_hint), bold = true, icon = Icons.Rounded.Diversity3) { name = it.take(100) }
            Spacer(Modifier.height(8.dp))
            SheetField(description, stringResource(R.string.cm_description_hint), icon = Icons.Rounded.Subject) { description = it.take(2000) }
            Spacer(Modifier.height(14.dp))
            GroupPicker(mine, picked)
            Spacer(Modifier.height(14.dp))
            ChPrimaryButton(stringResource(R.string.cm_create), icon = Icons.Rounded.Diversity3, modifier = Modifier.fillMaxWidth(), enabled = name.isNotBlank() && !busy) {
                busy = true
                scope.launch {
                    try {
                        val body = buildMap<String, Any> {
                            put("name", name.trim())
                            description.trim().takeIf { it.isNotEmpty() }?.let { put("description", it) }
                            put("groups", picked.toList())
                        }
                        ApiClient.chat.createCommunity(chatAuth(), body).data?.let {
                            host.refreshList()
                            onCreated(it)
                            onDismiss()
                        }
                    } catch (e: Exception) {
                        host.showToast(e.apiFailure().message ?: networkError)
                    }
                    busy = false
                }
            }
        }
    }
}

/** Groups I run that aren't in a community yet — they can be brought in. */
private fun myFreeGroups(host: ChatHost) = host.conversations.filter { it.isGroup && !it.isChannel && it.isAdmin && it.isMember && it.group?.community == null }

@Composable
private fun GroupPicker(groups: List<com.dorr.app.network.ConversationDto>, picked: MutableList<String>) {
    Text(stringResource(R.string.cm_pick_groups), color = Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(start = 4.dp, bottom = 6.dp))
    if (groups.isEmpty()) {
        Text(stringResource(R.string.cm_no_free_groups), color = Ch.Soft, fontSize = 13.sp, modifier = Modifier.padding(4.dp))
        return
    }
    groups.forEachIndexed { i, c ->
        val on = c.id in picked
        Row(
            Modifier.fillMaxWidth().chStagger(i).clip(RoundedCornerShape(14.dp)).clickable { if (on) picked.remove(c.id) else picked.add(c.id) }.padding(vertical = 8.dp, horizontal = 4.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            ChAvatar(c.avatar, c.title, c.id, size = 40.dp, isGroup = true)
            Spacer(Modifier.width(10.dp))
            Text(c.title.orEmpty(), color = Ch.Ink, fontWeight = FontWeight.SemiBold, fontSize = 14.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
            Icon(if (on) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (on) Ch.Red else Ch.Mut, modifier = Modifier.size(22.dp))
        }
    }
}

// =============================================================================== one community

@OptIn(ExperimentalFoundationApi::class)
@Composable
fun CommunityPage(id: String) {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var community by remember { mutableStateOf<CommunityDto?>(null) }
    var failed by remember { mutableStateOf<String?>(null) }
    var adding by remember { mutableStateOf(false) }
    var newGroup by remember { mutableStateOf(false) }
    var renaming by remember { mutableStateOf(false) }
    var membersOpen by remember { mutableStateOf(false) }
    var confirmLeave by remember { mutableStateOf(false) }
    var confirmDelete by remember { mutableStateOf(false) }
    var groupMenu by remember { mutableStateOf<CommunityGroupDto?>(null) }
    val networkError = stringResource(R.string.ch_error_network)
    val askedText = stringResource(R.string.ch_join_request_sent)

    suspend fun load() {
        try {
            community = ApiClient.chat.community(chatAuth(), id).data
        } catch (e: Exception) {
            failed = e.apiFailure().message ?: networkError
        }
    }
    LaunchedEffect(id) { load() }

    fun act(block: suspend () -> CommunityDto?) {
        scope.launch {
            try {
                block()?.let { community = it }
            } catch (e: Exception) {
                host.showToast(e.apiFailure().message ?: networkError)
            }
        }
    }

    ChPage(community?.name ?: stringResource(R.string.cm_title), onBack = { host.pop() }) {
        val c = community
        when {
            failed != null && c == null -> ChEmptyState(Icons.Rounded.Diversity3, stringResource(R.string.cm_title), failed.orEmpty())
            c == null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(28.dp), color = Ch.Red, strokeWidth = 2.5.dp) }
            else -> LazyColumn(contentPadding = PaddingValues(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                // Who it is.
                item(key = "head") {
                    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(22.dp)).background(Ch.Surface).padding(18.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                        ChAvatar(c.avatar, c.name, c.id, size = 84.dp, isGroup = true)
                        Spacer(Modifier.height(10.dp))
                        Text(c.name, color = Ch.Ink, fontSize = 20.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center)
                        Text(stringResource(R.string.cm_counts, c.groupsCount, c.membersCount), color = Ch.Mut, fontSize = 13.sp)
                        c.description?.takeIf { it.isNotBlank() }?.let { Text(it, color = Ch.Ink, fontSize = 13.5.sp, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 8.dp)) }
                    }
                }
                // Announcements: only admins write there (94).
                item(key = "announcements") {
                    CommunityRow(Icons.Rounded.Campaign, stringResource(R.string.cm_announcements), stringResource(R.string.cm_announcements_sub), avatar = null) {
                        host.push(ChRoute.Conversation(c.announcementId))
                    }
                }
                val (inside, outside) = c.groups.partition { it.isMember }
                if (inside.isNotEmpty()) item(key = "in-title") { SectionTitle(stringResource(R.string.cm_your_groups)) }
                items(inside, key = { "in" + it.id }) { g ->
                    GroupLine(g, c.isAdmin, onLong = { groupMenu = g }) { host.push(ChRoute.Conversation(g.id)) }
                }
                if (outside.isNotEmpty()) item(key = "out-title") { SectionTitle(stringResource(R.string.cm_other_groups)) }
                items(outside, key = { "out" + it.id }) { g ->
                    // Hop in (95): straight in, or ask when that group approves new members.
                    GroupLine(g, c.isAdmin, onLong = { groupMenu = g }) {
                        if (g.requestPending) return@GroupLine
                        act {
                            val res = ApiClient.chat.joinCommunityGroup(chatAuth(), c.id, g.id).data
                            if (res?.status == "joined") {
                                host.refreshList()
                                host.push(ChRoute.Conversation(g.id))
                            } else {
                                host.showToast(askedText)
                            }
                            ApiClient.chat.community(chatAuth(), c.id).data
                        }
                    }
                }
                // Admins (96): bring groups in, make one, the people, the name.
                if (c.isAdmin) {
                    item(key = "admin") {
                        Card {
                            SettingRow(Icons.Rounded.AddLink, stringResource(R.string.cm_add_groups)) { adding = true }
                            SettingRow(Icons.Rounded.GroupAdd, stringResource(R.string.cm_new_group)) { newGroup = true }
                            SettingRow(Icons.Rounded.Groups, stringResource(R.string.cm_members), stringResource(R.string.cm_admins_of, c.adminsCount, c.maxAdmins)) { membersOpen = true }
                            SettingRow(Icons.Rounded.Edit, stringResource(R.string.cm_rename)) { renaming = true }
                        }
                    }
                } else {
                    item(key = "members") { Card { SettingRow(Icons.Rounded.Groups, stringResource(R.string.cm_members), c.membersCount.toString()) { membersOpen = true } } }
                }
                // The creator stays (98): they can only delete it; everyone else can leave.
                item(key = "end") {
                    Card {
                        if (c.isCreator) SettingRow(Icons.Rounded.Delete, stringResource(R.string.cm_delete), danger = true) { confirmDelete = true }
                        else SettingRow(Icons.AutoMirrored.Rounded.ExitToApp, stringResource(R.string.cm_leave), danger = true) { confirmLeave = true }
                    }
                }
            }
        }
    }

    val c = community ?: return
    if (adding) AddGroupsSheet(onDismiss = { adding = false }) { ids ->
        act {
            val fresh = ApiClient.chat.addCommunityGroups(chatAuth(), c.id, mapOf("groups" to ids)).data
            host.refreshList()
            fresh
        }
    }
    if (newGroup) TextInputSheet(stringResource(R.string.cm_new_group), action = stringResource(R.string.cm_create_group), icon = Icons.Rounded.GroupAdd, onDismiss = { newGroup = false }) { name ->
        if (name.isNotBlank()) act {
            val fresh = ApiClient.chat.addCommunityGroups(chatAuth(), c.id, mapOf("name" to name.trim())).data
            host.refreshList()
            fresh
        }
    }
    if (renaming) TextInputSheet(stringResource(R.string.cm_rename), initial = c.name, action = stringResource(R.string.ch_save), onDismiss = { renaming = false }) { name ->
        if (name.isNotBlank()) act { ApiClient.chat.updateCommunity(chatAuth(), c.id, mapOf("name" to name.trim())).data }
    }
    if (membersOpen) CommunityMembersSheet(c, onDismiss = { membersOpen = false }) { scope.launch { load() } }
    groupMenu?.takeIf { c.isAdmin }?.let { g ->
        ChoiceSheet(g.name, listOf(stringResource(R.string.cm_remove_group) to { act { ApiClient.chat.removeCommunityGroup(chatAuth(), c.id, g.id).data } }), danger = true, subtitle = stringResource(R.string.cm_remove_group_sub), onDismiss = { groupMenu = null })
    }
    if (confirmLeave) ChoiceSheet(stringResource(R.string.cm_leave), listOf(stringResource(R.string.cm_leave) to {
        act {
            ApiClient.chat.leaveCommunity(chatAuth(), c.id)
            host.refreshList()
            host.pop()
            null
        }
    }), danger = true, subtitle = stringResource(R.string.cm_leave_sub), onDismiss = { confirmLeave = false })
    if (confirmDelete) ChoiceSheet(stringResource(R.string.cm_delete), listOf(stringResource(R.string.cm_delete) to {
        act {
            ApiClient.chat.deleteCommunity(chatAuth(), c.id)
            host.refreshList()
            host.pop()
            null
        }
    }), danger = true, subtitle = stringResource(R.string.cm_delete_sub), onDismiss = { confirmDelete = false })
}

@Composable
private fun SectionTitle(text: String) {
    Text(text, color = Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(start = 6.dp, top = 8.dp))
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun GroupLine(g: CommunityGroupDto, admin: Boolean, onLong: () -> Unit, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Ch.Surface)
            .combinedClickable(onClick = onClick, onLongClick = if (admin) onLong else null).padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        ChAvatar(g.avatar, g.name, g.id, size = 46.dp, isGroup = true)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(g.name, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(stringResource(R.string.ch_members, g.membersCount), color = Ch.Mut, fontSize = 12.5.sp)
        }
        when {
            g.isMember -> Unit
            g.requestPending -> Icon(Icons.Rounded.HourglassTop, null, tint = Color(0xFFD97706), modifier = Modifier.size(20.dp))
            else -> Text(
                stringResource(if (g.approveJoins) R.string.cm_ask_join else R.string.cm_join), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 13.sp,
                modifier = Modifier.clip(RoundedCornerShape(12.dp)).background(Ch.Red).padding(horizontal = 12.dp, vertical = 6.dp),
            )
        }
    }
}

/** Bring in more groups I run. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun AddGroupsSheet(onDismiss: () -> Unit, onAdd: (List<String>) -> Unit) {
    val host = LocalChat.current
    val picked = remember { mutableStateListOf<String>() }
    val mine = remember(host.conversations.size) { myFreeGroups(host) }
    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().heightIn(max = 620.dp).verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Text(stringResource(R.string.cm_add_groups), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(12.dp))
            GroupPicker(mine, picked)
            Spacer(Modifier.height(14.dp))
            ChPrimaryButton(stringResource(R.string.cm_add_groups), icon = Icons.Rounded.AddLink, modifier = Modifier.fillMaxWidth(), enabled = picked.isNotEmpty()) {
                onAdd(picked.toList())
                onDismiss()
            }
        }
    }
}

/** Everyone in it; admins make / unmake admins and remove people from the community and all its groups. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun CommunityMembersSheet(c: CommunityDto, onDismiss: () -> Unit, onChanged: () -> Unit) {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var members by remember { mutableStateOf<List<MemberDto>?>(null) }
    var menu by remember { mutableStateOf<MemberDto?>(null) }
    val networkError = stringResource(R.string.ch_error_network)
    suspend fun load() { members = runCatching { ApiClient.chat.communityMembers(chatAuth(), c.id).data }.getOrNull().orEmpty() }
    LaunchedEffect(c.id) { load() }

    fun act(block: suspend () -> Unit) {
        scope.launch {
            try {
                block()
                load()
                onChanged()
            } catch (e: Exception) {
                host.showToast(e.apiFailure().message ?: networkError)
            }
        }
    }

    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Text(stringResource(R.string.cm_members), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.cm_admins_of, c.adminsCount, c.maxAdmins), color = Ch.Mut, fontSize = 12.5.sp)
            Spacer(Modifier.height(10.dp))
            val list = members
            if (list == null) {
                Box(Modifier.fillMaxWidth().height(120.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp) }
            } else {
                LazyColumn(Modifier.heightIn(max = 480.dp)) {
                    itemsIndexed(list, key = { _, m -> m.participantId }) { i, m ->
                        val canManage = c.isAdmin && m.role != "owner" && m.profile?.isMe != true
                        Row(
                            Modifier.fillMaxWidth().chStagger(i).clip(RoundedCornerShape(14.dp)).clickable(enabled = canManage) { menu = m }.padding(vertical = 8.dp, horizontal = 4.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            ChAvatar(m.profile?.avatar, m.profile?.name, m.profile?.key, size = 40.dp)
                            Spacer(Modifier.width(10.dp))
                            Text(if (m.profile?.isMe == true) stringResource(R.string.ch_you) else m.profile?.name.orEmpty(), color = Ch.Ink, fontWeight = FontWeight.SemiBold, fontSize = 14.5.sp, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                            when (m.role) {
                                "owner" -> RoleTag(stringResource(R.string.cm_creator))
                                "admin" -> RoleTag(stringResource(R.string.cm_admin))
                            }
                        }
                    }
                }
            }
        }
    }

    menu?.let { m ->
        val makeAdmin = m.role != "admin"
        ChoiceSheet(
            m.profile?.name.orEmpty(),
            listOf(
                stringResource(R.string.cm_remove_member) to { act { ApiClient.chat.removeCommunityMember(chatAuth(), c.id, m.participantId) } },
                stringResource(if (makeAdmin) R.string.cm_make_admin else R.string.cm_unmake_admin) to {
                    act { ApiClient.chat.communityRole(chatAuth(), c.id, m.participantId, mapOf("role" to if (makeAdmin) "admin" else "member")) }
                },
            ),
            danger = true, subtitle = stringResource(R.string.cm_remove_member_sub), onDismiss = { menu = null },
        )
    }
}

@Composable
private fun RoleTag(text: String) {
    Text(text, color = Ch.Red, fontSize = 11.5.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.clip(CircleShape).background(Ch.Red.copy(alpha = 0.08f)).padding(horizontal = 8.dp, vertical = 3.dp))
}
