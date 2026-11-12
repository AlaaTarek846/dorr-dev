@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.dorr.app.ui.screens.chat

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.spring
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
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
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.AdminPanelSettings
import androidx.compose.material.icons.rounded.Badge
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.Save
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
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.GroupRoleDto
import com.dorr.app.network.GroupRolesDto
import com.dorr.app.network.MemberDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.ConfirmDialog
import kotlinx.coroutines.launch

/*
 * Roles and permissions in educational and work groups (docs/groups_tasks.md GR-004). Everyone
 * sees the roles; with `roles.manage` you make and edit them and give them to members. A
 * permission you don't hold yourself is greyed out (the server refuses it anyway); the owner
 * holds them all. The members' screens refresh on `chat.conversation.updated`.
 */

/** The group's roles: name, how many hold it, its permissions; tap one to edit it. */
@Composable
internal fun GroupRolesSheet(groupId: String, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var data by remember { mutableStateOf<GroupRolesDto?>(null) }
    // null: the list; a role: editing it; `NEW`: a new one.
    var editing by remember { mutableStateOf<GroupRoleDto?>(null) }
    suspend fun load() {
        try {
            data = ApiClient.chat.groupRoles(chatAuth(), groupId).data
        } catch (e: Exception) {
            e.apiFailure().message?.let { host.showToast(it) }
            onDismiss()
        }
    }
    LaunchedEffect(groupId) { load() }
    val d = data
    val canManage = d?.myPermissions?.contains("roles.manage") == true

    editing?.let { role ->
        if (d != null) {
            RoleEditorSheet(
                groupId, role.takeIf { it.id != NEW_ROLE }, d,
                onDone = { host.scope.launch { load() }; editing = null },
                onDismiss = { editing = null },
            )
            return
        }
    }

    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 18.dp).padding(bottom = 20.dp)) {
            Text(stringResource(R.string.ch_group_roles), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 19.sp)
            Text(stringResource(R.string.ch_group_roles_sub), color = Ch.Mut, fontSize = 12.5.sp, lineHeight = 17.sp, modifier = Modifier.padding(top = 4.dp, bottom = 12.dp))
            if (d == null) {
                Box(Modifier.fillMaxWidth().height(160.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp) }
            } else {
                LazyColumn(Modifier.heightIn(max = 440.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    itemsIndexed(d.roles, key = { _, r -> r.id }) { i, role ->
                        Row(
                            Modifier.fillMaxWidth().chStagger(i).clip(RoundedCornerShape(16.dp)).background(Ch.SurfaceMuted)
                                .clickable(enabled = canManage) { editing = role }.padding(12.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Box(Modifier.size(38.dp).clip(RoundedCornerShape(12.dp)).background(Ch.Red.copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
                                Icon(Icons.Rounded.Badge, null, tint = Ch.Red, modifier = Modifier.size(20.dp))
                            }
                            Spacer(Modifier.width(12.dp))
                            Column(Modifier.weight(1f)) {
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    Text(role.name, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false))
                                    if (role.isDefault) {
                                        Spacer(Modifier.width(6.dp))
                                        Text(
                                            stringResource(R.string.ch_role_default), color = Ch.Red, fontSize = 10.5.sp, fontWeight = FontWeight.Bold,
                                            modifier = Modifier.clip(RoundedCornerShape(50)).background(Ch.Red.copy(alpha = 0.1f)).padding(horizontal = 7.dp, vertical = 2.dp),
                                        )
                                    }
                                }
                                Text(
                                    stringResource(R.string.ch_role_summary, role.membersCount, role.permissions.size),
                                    color = Ch.Mut, fontSize = 12.sp,
                                )
                            }
                        }
                    }
                }
                if (canManage) {
                    Spacer(Modifier.height(12.dp))
                    ChPrimaryButton(stringResource(R.string.ch_role_new), icon = Icons.Rounded.Add, modifier = Modifier.fillMaxWidth()) {
                        editing = GroupRoleDto(NEW_ROLE, name = "")
                    }
                }
            }
        }
    }
}

private const val NEW_ROLE = -1

/** A role's name and its permissions (only the ones I hold can be ticked); save or delete. */
@Composable
private fun RoleEditorSheet(groupId: String, role: GroupRoleDto?, data: GroupRolesDto, onDone: () -> Unit, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var name by remember { mutableStateOf(role?.name.orEmpty()) }
    val picked = remember { mutableStateListOf<String>().apply { addAll(role?.permissions.orEmpty()) } }
    var busy by remember { mutableStateOf(false) }
    var confirmDelete by remember { mutableStateOf(false) }

    fun run(call: suspend () -> Unit) {
        busy = true
        host.scope.launch {
            try {
                call()
                onDone()
            } catch (e: Exception) {
                e.apiFailure().message?.let { host.showToast(it) }
            }
            busy = false
        }
    }

    if (confirmDelete && role != null) {
        ConfirmDialog(
            icon = Icons.Rounded.Delete,
            title = stringResource(R.string.ch_role_delete),
            message = stringResource(R.string.ch_role_delete_confirm, role.name),
            confirmLabel = stringResource(R.string.ch_role_delete),
            onConfirm = { confirmDelete = false; run { ApiClient.chat.deleteGroupRole(chatAuth(), groupId, role.id) } },
            onDismiss = { confirmDelete = false },
        )
        return
    }

    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 18.dp).padding(bottom = 20.dp)) {
            Text(stringResource(if (role == null) R.string.ch_role_new else R.string.ch_role_edit), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 19.sp)
            Spacer(Modifier.height(12.dp))
            ChField(name, { name = it.take(60) }, stringResource(R.string.ch_role_name), icon = Icons.Rounded.Badge)
            Text(stringResource(R.string.ch_role_permissions), color = Ch.Mut, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.padding(top = 14.dp, bottom = 6.dp))
            LazyColumn(Modifier.heightIn(max = 340.dp)) {
                itemsIndexed(data.permissions, key = { _, p -> p.key }) { _, permission ->
                    val mine = permission.key in data.myPermissions
                    val on = permission.key in picked
                    Row(
                        Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp))
                            .clickable(enabled = mine) { if (on) picked.remove(permission.key) else picked.add(permission.key) }
                            .padding(horizontal = 6.dp, vertical = 9.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        AnimatedContent(on, label = "perm", transitionSpec = { scaleIn(spring(dampingRatio = 0.4f)) togetherWith scaleOut() }) { checked ->
                            Icon(
                                if (!mine) Icons.Rounded.Lock else if (checked) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null,
                                tint = if (!mine) Ch.Soft else if (checked) Ch.Red else Ch.Soft, modifier = Modifier.size(22.dp),
                            )
                        }
                        Spacer(Modifier.width(10.dp))
                        Text(permission.name, color = if (mine) Ch.Ink else Ch.Soft, fontSize = 14.sp, modifier = Modifier.weight(1f))
                    }
                }
            }
            Spacer(Modifier.height(12.dp))
            ChPrimaryButton(stringResource(R.string.ch_save), icon = Icons.Rounded.Save, modifier = Modifier.fillMaxWidth(), enabled = !busy && (role?.template != null || name.isNotBlank())) {
                val body = mapOf<String, Any?>("name" to name.trim().ifBlank { null }, "permissions" to picked.toList())
                run {
                    if (role == null) ApiClient.chat.createGroupRole(chatAuth(), groupId, body)
                    else ApiClient.chat.updateGroupRole(chatAuth(), groupId, role.id, body)
                }
            }
            if (role != null && !role.isDefault) {
                Text(
                    stringResource(R.string.ch_role_delete), color = Ch.Danger, fontWeight = FontWeight.ExtraBold, fontSize = 14.sp,
                    modifier = Modifier.align(Alignment.CenterHorizontally).padding(top = 10.dp).clip(RoundedCornerShape(12.dp))
                        .clickable(enabled = !busy) { confirmDelete = true }.padding(horizontal = 14.dp, vertical = 8.dp),
                )
            }
        }
    }
}

/** The roles one member holds (several at once); only roles whose permissions I hold can change. */
@Composable
internal fun MemberRolesSheet(groupId: String, member: MemberDto, onSaved: () -> Unit, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var data by remember { mutableStateOf<GroupRolesDto?>(null) }
    val picked = remember { mutableStateListOf<Int>().apply { addAll(member.roles.orEmpty()) } }
    var busy by remember { mutableStateOf(false) }
    val done = stringResource(R.string.ch_member_roles_saved, member.profile?.name.orEmpty())
    LaunchedEffect(groupId) { data = runCatching { ApiClient.chat.groupRoles(chatAuth(), groupId).data }.getOrNull() }

    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 18.dp).padding(bottom = 20.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                ChAvatar(member.profile?.avatar, member.profile?.name, member.profile?.key, size = 40.dp)
                Spacer(Modifier.width(10.dp))
                Column {
                    Text(stringResource(R.string.ch_member_roles), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 17.sp)
                    Text(member.profile?.name.orEmpty(), color = Ch.Mut, fontSize = 13.sp, maxLines = 1)
                }
            }
            Spacer(Modifier.height(12.dp))
            val d = data
            if (d == null) {
                Box(Modifier.fillMaxWidth().height(140.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp) }
            } else {
                LazyColumn(Modifier.heightIn(max = 380.dp)) {
                    itemsIndexed(d.roles, key = { _, r -> r.id }) { _, role ->
                        val allowed = role.permissions.all { it in d.myPermissions }
                        val on = role.id in picked
                        Row(
                            Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp))
                                .clickable(enabled = allowed) { if (on) picked.remove(role.id) else picked.add(role.id) }
                                .padding(horizontal = 6.dp, vertical = 10.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(
                                if (!allowed) Icons.Rounded.Lock else if (on) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null,
                                tint = if (allowed && on) Ch.Red else Ch.Soft, modifier = Modifier.size(22.dp),
                            )
                            Spacer(Modifier.width(10.dp))
                            Column(Modifier.weight(1f)) {
                                Text(role.name, color = if (allowed) Ch.Ink else Ch.Soft, fontWeight = FontWeight.Bold, fontSize = 14.5.sp)
                                Text(
                                    d.permissions.filter { it.key in role.permissions }.joinToString(" · ") { it.name },
                                    color = Ch.Mut, fontSize = 11.5.sp, maxLines = 2, overflow = TextOverflow.Ellipsis,
                                )
                            }
                        }
                    }
                }
                Spacer(Modifier.height(12.dp))
                ChPrimaryButton(stringResource(R.string.ch_save), icon = Icons.Rounded.AdminPanelSettings, modifier = Modifier.fillMaxWidth(), enabled = !busy) {
                    busy = true
                    host.scope.launch {
                        try {
                            ApiClient.chat.setMemberRoles(chatAuth(), groupId, member.participantId, mapOf("roles" to picked.toList()))
                            host.showToast(done)
                            onSaved()
                            onDismiss()
                        } catch (e: Exception) {
                            e.apiFailure().message?.let { host.showToast(it) }
                        }
                        busy = false
                    }
                }
            }
        }
    }
}
