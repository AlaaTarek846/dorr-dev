@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.dorr.app.ui.screens.chat

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
import androidx.compose.material.icons.rounded.Campaign
import androidx.compose.material.icons.rounded.Send
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.GroupAuditItemDto
import com.dorr.app.network.MessageDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.profile.settingsSwitchColors
import kotlinx.coroutines.launch

/*
 * Educational / work group tools (docs/groups_tasks.md): announcements (GR-008), which notices of
 * this group may be pushed to me (GR-009), and the activity log (GR-010).
 */

/** The group's announcements, newest first — and the box to post one, for whoever may. */
@Composable
internal fun AnnouncementsSheet(groupId: String, canPost: Boolean, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var rows by remember { mutableStateOf<List<MessageDto>?>(null) }
    var text by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    suspend fun load() {
        rows = runCatching { ApiClient.chat.groupAnnouncements(chatAuth(), groupId).data }.getOrNull().orEmpty()
    }
    LaunchedEffect(groupId) { load() }

    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 18.dp).padding(bottom = 20.dp)) {
            Text(stringResource(R.string.ch_announcements), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 19.sp)
            Spacer(Modifier.height(10.dp))
            if (canPost) {
                ChField(text, { text = it.take(4000) }, stringResource(R.string.ch_announce_hint), singleLine = false, minLines = 2, icon = Icons.Rounded.Campaign)
                Text(stringResource(R.string.ch_announce_note), color = Ch.Soft, fontSize = 11.5.sp, modifier = Modifier.padding(top = 4.dp, start = 6.dp))
                Spacer(Modifier.height(8.dp))
                ChPrimaryButton(stringResource(R.string.ch_announce_send), icon = Icons.Rounded.Send, modifier = Modifier.fillMaxWidth(), enabled = text.isNotBlank() && !busy) {
                    busy = true
                    host.scope.launch {
                        try {
                            ApiClient.chat.announce(chatAuth(), groupId, mapOf("body" to text.trim()))
                            text = ""
                            load()
                        } catch (e: Exception) {
                            e.apiFailure().message?.let { host.showToast(it) }
                        }
                        busy = false
                    }
                }
                Spacer(Modifier.height(14.dp))
            }
            val list = rows
            when {
                list == null -> Box(Modifier.fillMaxWidth().height(120.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp) }
                list.isEmpty() -> Text(stringResource(R.string.ch_announcements_empty), color = Ch.Soft, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(vertical = 26.dp))
                else -> LazyColumn(Modifier.heightIn(max = 420.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    itemsIndexed(list, key = { _, m -> m.id }) { i, m ->
                        Column(Modifier.fillMaxWidth().chStagger(i).clip(RoundedCornerShape(16.dp)).background(Color(0xFFF59E0B).copy(alpha = 0.08f)).padding(12.dp)) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Text("📢", fontSize = 15.sp)
                                Spacer(Modifier.width(6.dp))
                                Text(m.sender?.name.orEmpty(), color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 13.5.sp, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                                Text(listTime(m.createdAt), color = Ch.Mut, fontSize = 11.5.sp)
                            }
                            Text(m.body.orEmpty(), color = Ch.Ink, fontSize = 14.sp, lineHeight = 20.sp, modifier = Modifier.padding(top = 6.dp))
                        }
                    }
                }
            }
        }
    }
}

/** Which of this group's notices may reach me as a push (the live update always comes). */
@Composable
internal fun GroupNoticesSheet(groupId: String, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var muted by remember { mutableStateOf<List<String>?>(null) }
    LaunchedEffect(groupId) { muted = runCatching { ApiClient.chat.groupNoticePrefs(chatAuth(), groupId).data?.muted }.getOrNull().orEmpty() }
    val types = listOf("ownership" to R.string.ch_notice_ownership, "spaces" to R.string.ch_notice_spaces, "roles" to R.string.ch_notice_roles, "tasks" to R.string.ch_notice_tasks, "assignments" to R.string.ch_notice_assignments, "quizzes" to R.string.ch_notice_quizzes, "sessions" to R.string.ch_notice_sessions, "grades" to R.string.ch_notice_grades, "help" to R.string.ch_notice_help)

    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 18.dp).padding(bottom = 20.dp)) {
            Text(stringResource(R.string.ch_group_notices), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 19.sp)
            Text(stringResource(R.string.ch_group_notices_sub), color = Ch.Mut, fontSize = 12.5.sp, lineHeight = 17.sp, modifier = Modifier.padding(top = 4.dp, bottom = 10.dp))
            val off = muted
            if (off == null) {
                Box(Modifier.fillMaxWidth().height(100.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp) }
            } else {
                types.forEach { (type, label) ->
                    val on = type !in off
                    Row(
                        Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable {
                            host.scope.launch {
                                runCatching { ApiClient.chat.setGroupNoticePrefs(chatAuth(), groupId, mapOf("prefs" to mapOf(type to !on))).data }
                                    .onSuccess { muted = it?.muted ?: muted }
                                    .onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }
                            }
                        }.padding(horizontal = 6.dp, vertical = 10.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(stringResource(label), color = Ch.Ink, fontSize = 15.sp, modifier = Modifier.weight(1f))
                        Switch(on, null, colors = settingsSwitchColors())
                    }
                }
            }
        }
    }
}

/** Who did what in the group, newest first, 30 at a time. */
@Composable
internal fun AuditLogSheet(groupId: String, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var rows by remember { mutableStateOf<List<GroupAuditItemDto>?>(null) }
    var page by remember { mutableIntStateOf(1) }
    var more by remember { mutableStateOf(false) }
    suspend fun load(p: Int) {
        try {
            val result = ApiClient.chat.groupAuditLog(chatAuth(), groupId, p).data
            rows = (if (p == 1) emptyList() else rows.orEmpty()) + result?.items.orEmpty()
            more = result?.hasMore == true
            page = p
        } catch (e: Exception) {
            e.apiFailure().message?.let { host.showToast(it) }
            if (rows == null) rows = emptyList()
        }
    }
    LaunchedEffect(groupId) { load(1) }

    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 18.dp).padding(bottom = 20.dp)) {
            Text(stringResource(R.string.ch_audit_log), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 19.sp)
            Spacer(Modifier.height(10.dp))
            val list = rows
            when {
                list == null -> Box(Modifier.fillMaxWidth().height(120.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp) }
                list.isEmpty() -> Text(stringResource(R.string.ch_audit_empty), color = Ch.Soft, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(vertical = 26.dp))
                else -> LazyColumn(Modifier.heightIn(max = 460.dp)) {
                    itemsIndexed(list, key = { _, r -> r.id }) { i, r ->
                        Row(Modifier.fillMaxWidth().chStagger(i).padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                            ChAvatar(r.actor?.avatar, r.actor?.name, r.actor?.key, size = 36.dp)
                            Spacer(Modifier.width(10.dp))
                            Column(Modifier.weight(1f)) {
                                Text(
                                    listOfNotNull(r.actor?.name, r.text, r.subject).joinToString(" · "),
                                    color = Ch.Ink, fontSize = 13.5.sp, maxLines = 2, overflow = TextOverflow.Ellipsis,
                                )
                                Text(listTime(r.createdAt), color = Ch.Mut, fontSize = 11.5.sp)
                            }
                        }
                    }
                    if (more) item {
                        Text(
                            stringResource(R.string.ch_audit_more), color = Ch.Red, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center,
                            modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable { host.scope.launch { load(page + 1) } }.padding(12.dp),
                        )
                    }
                }
            }
        }
    }
}
