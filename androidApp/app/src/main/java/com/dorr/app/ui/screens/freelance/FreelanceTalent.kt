package com.dorr.app.ui.screens.freelance

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.*
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.FreelanceProjectDto
import com.dorr.app.network.TalentInviteRequest
import com.dorr.app.network.TalentInviteResultDto
import com.dorr.app.network.TalentListDetailDto
import com.dorr.app.network.TalentListDto
import com.dorr.app.network.TalentListRequest
import com.dorr.app.network.TalentMemberDto
import com.dorr.app.network.TalentMemberRequest
import com.dorr.app.network.TalentSpecialtyDto
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.components.PullToRefreshHost
import com.dorr.app.ui.screens.ConfirmDialog
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsSurface
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject

/*
 * FS-103, the client's talent lists: lists of freelancers (a name, an optional specialty), a note and a private reminder on each one, and
 * «Invite to a project» for a whole list. Only the client sees them — the freelancer is never told he was saved. Each screen reads once
 * when it opens (and when the connection comes back) and again after every action; nothing is asked on a timer.
 */

private val Green = Color(0xFF16A34A)
private val Red = Color(0xFFDC2626)

private fun jsonBody(build: JSONObject.() -> Unit): RequestBody = JSONObject().apply(build).toString().toRequestBody("application/json; charset=utf-8".toMediaTypeOrNull())

// ---------------------------------------------------------------- my lists

@Composable
internal fun TalentListsPage(onBack: () -> Unit, onOpen: (String) -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    var filter by rememberSaveable { mutableStateOf<Int?>(null) }
    var data by remember { mutableStateOf<com.dorr.app.network.TalentListsDto?>(null) }
    // The specialties my lists are kept under (seen on a read without a filter) — the chips.
    var known by remember { mutableStateOf<List<TalentSpecialtyDto>>(emptyList()) }
    var error by remember { mutableStateOf<String?>(null) }
    var version by remember { mutableStateOf(0) }
    var creating by remember { mutableStateOf(false) }
    var editing by remember { mutableStateOf<TalentListDto?>(null) }
    var deleting by remember { mutableStateOf<TalentListDto?>(null) }
    var busy by remember { mutableStateOf(false) }
    var failure by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(filter, reconnect, version) {
        try {
            val d = ApiClient.freelanceTalent.lists(auth(), filter).data ?: com.dorr.app.network.TalentListsDto()
            data = d
            error = null
            if (filter == null) known = d.lists.mapNotNull { it.specialty }.distinctBy { it.id }
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message(generic)
        }
    }

    Column(Modifier.fillMaxSize()) {
        FlPageHeader(stringResource(R.string.fs_tp_title), onBack, trailing = { FlHeaderAction(stringResource(R.string.fs_tp_new), Icons.Rounded.Add) { creating = true } })
        PullToRefreshHost(Modifier.weight(1f).fillMaxWidth()) {
            LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(horizontal = 16.dp, vertical = 10.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                item { FlHint(stringResource(R.string.fs_tp_private)) }
                if (known.isNotEmpty() || filter != null) item {
                    Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Chip(stringResource(R.string.fl_filter_all), selected = filter == null, icon = Icons.Rounded.Apps) { filter = null }
                        known.forEach { s -> Chip(s.name, selected = filter == s.id, icon = Icons.Rounded.Category) { filter = s.id } }
                    }
                }
                failure?.let { f -> item { FlNotice(f, Icons.Rounded.ErrorOutline, Red) } }
                val d = data
                when {
                    d == null && error == null -> items(4) { com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().height(76.dp), RoundedCornerShape(18.dp)) }
                    d == null -> item { FlRetry(error.orEmpty()) }
                    d.lists.isEmpty() -> item { com.dorr.app.ui.screens.wallet.WaEmpty(Icons.Rounded.Groups, com.dorr.app.ui.screens.wallet.Tone.Pink, stringResource(R.string.fs_tp_title), stringResource(R.string.fs_tp_empty)) }
                    else -> {
                        items(d.lists, key = { it.uuid }) { l ->
                            Row(Modifier.fillMaxWidth().settingsSurface(FlCorners.card).clickable { onOpen(l.uuid) }.padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                                Box(Modifier.size(44.dp).clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                                    Icon(Icons.Rounded.Groups, null, tint = settingsAccent(), modifier = Modifier.size(22.dp))
                                }
                                Spacer(Modifier.width(12.dp))
                                Column(Modifier.weight(1f)) {
                                    Text(l.name, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                    Text(listOfNotNull(l.specialty?.name, stringResource(R.string.fs_tp_count, l.count)).joinToString("  ·  "), color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                }
                                Icon(Icons.Rounded.Edit, stringResource(R.string.fs_tp_rename), tint = settingsMut(), modifier = Modifier.size(36.dp).clip(CircleShape).clickable { editing = l }.padding(8.dp))
                                Icon(Icons.Rounded.DeleteOutline, stringResource(R.string.fs_tp_delete), tint = Red, modifier = Modifier.size(36.dp).clip(CircleShape).clickable { deleting = l }.padding(8.dp))
                            }
                        }
                        item { Text(stringResource(R.string.fs_tp_max, d.maxLists), color = settingsMut(), fontSize = 11.5.sp, modifier = Modifier.padding(horizontal = 4.dp)) }
                    }
                }
            }
        }
    }

    if (creating) TalentFormSheet(
        title = stringResource(R.string.fs_tp_new), initialName = "", initialSpecialty = null, onDismiss = { creating = false },
        submit = { name, specialty -> ApiClient.freelanceTalent.create(auth(), TalentListRequest(name, specialty)); version++ },
    )
    editing?.let { l ->
        TalentFormSheet(
            title = stringResource(R.string.fs_tp_rename), initialName = l.name, initialSpecialty = l.specialty, onDismiss = { editing = null },
            submit = { name, specialty -> ApiClient.freelanceTalent.update(auth(), l.uuid, TalentListRequest(name, specialty)); version++ },
        )
    }
    deleting?.let { l ->
        ConfirmDialog(
            icon = Icons.Rounded.DeleteOutline, title = stringResource(R.string.fs_tp_delete), message = stringResource(R.string.fs_tp_delete_confirm, l.name),
            loading = busy, onDismiss = { deleting = null }, confirmLabel = stringResource(R.string.fs_tp_delete),
            onConfirm = {
                busy = true
                scope.launch {
                    try {
                        ApiClient.freelanceTalent.delete(auth(), l.uuid)
                        failure = null
                        version++
                    } catch (e: CancellationException) {
                        throw e
                    } catch (e: Exception) {
                        failure = e.message(generic)
                    }
                    busy = false
                    deleting = null
                }
            },
        )
    }
}

/** The form of a list: a name and a specialty (or none). [submit] throws what the server refused; the sheet shows it and stays open. */
@Composable
private fun TalentFormSheet(title: String, initialName: String, initialSpecialty: TalentSpecialtyDto?, onDismiss: () -> Unit, submit: suspend (String, Int?) -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    var name by remember { mutableStateOf(initialName) }
    var specialty by remember { mutableStateOf(initialSpecialty) }
    var picking by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    FlSheet(Icons.Rounded.Groups, title, stringResource(R.string.fs_tp_form_hint), onDismiss) {
        DorrTextField(name, { name = it.take(80) }, label = stringResource(R.string.fs_tp_name), icon = Icons.Rounded.Edit, modifier = Modifier.fillMaxWidth())
        FlHeading(Icons.Rounded.Category, stringResource(R.string.fs_tp_specialty), stringResource(R.string.fs_tp_specialty_hint))
        Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Chip(stringResource(R.string.fs_tp_any), selected = specialty == null) { specialty = null }
            Chip(specialty?.name ?: stringResource(R.string.fl_pick_specialty), selected = specialty != null, icon = Icons.Rounded.Category) { picking = true }
        }
        error?.let { FlNotice(it, Icons.Rounded.ErrorOutline, Red) }
        FlAction(stringResource(R.string.common_save), Icons.Rounded.Check, FlTone.Primary, enabled = name.isNotBlank(), loading = busy) {
            busy = true
            error = null
            scope.launch {
                try {
                    submit(name.trim(), specialty?.id)
                    onDismiss()
                } catch (e: CancellationException) {
                    throw e
                } catch (e: Exception) {
                    error = e.message(generic)
                }
                busy = false
            }
        }
    }
    if (picking) TalentSpecialtySheet(onDismiss = { picking = false }, onPick = { specialty = it; picking = false })
}

/** The specialty tree, read once: a parent is a heading, its children are picked (a parent without children is picked itself). */
@Composable
private fun TalentSpecialtySheet(onDismiss: () -> Unit, onPick: (TalentSpecialtyDto) -> Unit) {
    var tree by remember { mutableStateOf<List<com.dorr.app.network.FreelanceSpecialtyNodeDto>?>(null) }
    LaunchedEffect(Unit) { tree = runCatching { ApiClient.freelance.specialties(auth()).data.orEmpty() }.getOrDefault(emptyList()) }
    FlSheet(Icons.Rounded.Category, stringResource(R.string.fl_pick_specialty), "", onDismiss = onDismiss) {
        val nodes = tree
        if (nodes == null) {
            repeat(5) { com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().height(40.dp), RoundedCornerShape(12.dp)) }
        } else {
            nodes.forEach { parent ->
                if (parent.children.isEmpty()) {
                    TalentPickRow(parent.name, bold = true) { onPick(TalentSpecialtyDto(parent.id, parent.name)) }
                } else {
                    Text(parent.name, color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 6.dp))
                    parent.children.forEach { child -> TalentPickRow(child.name, bold = false) { onPick(TalentSpecialtyDto(child.id, child.name)) } }
                }
            }
        }
    }
}

@Composable
private fun TalentPickRow(text: String, bold: Boolean, onClick: () -> Unit) {
    Text(
        text, color = settingsInk(), fontSize = 14.sp, fontWeight = if (bold) FontWeight.Bold else FontWeight.Normal,
        modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.06f)).clickable(onClick = onClick).padding(vertical = 12.dp, horizontal = 12.dp),
    )
}

@Composable
private fun TalentAvatar(name: String?, url: String?, size: Int = 46) {
    Box(Modifier.size(size.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
        if (!url.isNullOrBlank()) AsyncImage(model = url, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
        else Text(name?.take(1).orEmpty(), color = settingsAccent(), fontSize = (size / 2.4).sp, fontWeight = FontWeight.ExtraBold)
    }
}

// ---------------------------------------------------------------- one list

@Composable
internal fun TalentListPage(uuid: String, onBack: () -> Unit, onGone: () -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    var list by remember { mutableStateOf<TalentListDetailDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var failure by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var version by remember { mutableStateOf(0) }
    var renaming by remember { mutableStateOf(false) }
    var deleting by remember { mutableStateOf(false) }
    var noteFor by remember { mutableStateOf<TalentMemberDto?>(null) }
    var remindFor by remember { mutableStateOf<TalentMemberDto?>(null) }
    var removeFor by remember { mutableStateOf<TalentMemberDto?>(null) }
    var inviting by remember { mutableStateOf(false) }
    var result by remember { mutableStateOf<TalentInviteResultDto?>(null) }

    LaunchedEffect(uuid, reconnect, version) {
        try {
            list = ApiClient.freelanceTalent.show(auth(), uuid).data
            error = null
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message(generic)
        }
    }

    /** Runs a call that answers with the list as it is now. */
    fun act(after: (() -> Unit)? = null, block: suspend () -> TalentListDetailDto?) {
        busy = true
        failure = null
        scope.launch {
            try {
                block()?.let { list = it }
                after?.invoke()
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                failure = e.message(generic)
            }
            busy = false
        }
    }

    Column(Modifier.fillMaxSize()) {
        val l = list
        FlPageHeader(l?.name ?: stringResource(R.string.fs_tp_title), onBack)
        if (l == null) {
            FlLoading(error)
            return@Column
        }
        PullToRefreshHost(Modifier.weight(1f).fillMaxWidth()) {
            Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text(listOfNotNull(l.specialty?.name, stringResource(R.string.fs_tp_count, l.count)).joinToString("  ·  "), color = settingsMut(), fontSize = 13.sp)
                FlHint(stringResource(R.string.fs_tp_private))
                failure?.let { FlNotice(it, Icons.Rounded.ErrorOutline, Red) }
                result?.let { r -> TalentInviteResult(r, l.members) }
                if (l.members.isEmpty()) {
                    com.dorr.app.ui.screens.wallet.WaEmpty(Icons.Rounded.PersonAdd, com.dorr.app.ui.screens.wallet.Tone.Pink, stringResource(R.string.fs_tp_members), stringResource(R.string.fs_tp_members_empty))
                }
                l.members.forEach { m ->
                    Column(Modifier.fillMaxWidth().settingsSurface(FlCorners.card).padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            TalentAvatar(m.name, m.avatarUrl)
                            Spacer(Modifier.width(10.dp))
                            Column(Modifier.weight(1f)) {
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    Text(m.name ?: "—", color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false))
                                    if (m.verified) Icon(Icons.Rounded.Verified, stringResource(R.string.fl_verified), tint = Green, modifier = Modifier.padding(start = 4.dp).size(16.dp))
                                }
                                m.headline?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis) }
                                Text(stringResource(R.string.fl_completeness, m.completeness), color = settingsMut(), fontSize = 12.sp)
                            }
                            Icon(Icons.Rounded.Close, stringResource(R.string.fs_tp_remove_member), tint = settingsMut(), modifier = Modifier.size(36.dp).clip(CircleShape).clickable { removeFor = m }.padding(8.dp))
                        }
                        if (m.workedTogether) Row(
                            Modifier.clip(RoundedCornerShape(999.dp)).background(Green.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 3.dp),
                            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp),
                        ) {
                            Icon(Icons.Rounded.Handshake, null, tint = Green, modifier = Modifier.size(13.dp))
                            Text(stringResource(R.string.fs_tp_worked_together), color = Green, fontSize = 11.sp, fontWeight = FontWeight.Bold)
                        }
                        // The note (mine alone) and the reminder (mine alone): a tap changes either.
                        Row(
                            Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.06f)).clickable { noteFor = m }.padding(horizontal = 12.dp, vertical = 10.dp),
                            verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(8.dp),
                        ) {
                            Icon(Icons.Rounded.EditNote, null, tint = settingsAccent(), modifier = Modifier.size(17.dp))
                            Text(m.note?.takeIf { it.isNotBlank() } ?: stringResource(R.string.fs_tp_note_add), color = if (m.note.isNullOrBlank()) settingsMut() else settingsInk(), fontSize = 13.sp, lineHeight = 19.sp, modifier = Modifier.weight(1f))
                        }
                        Row(
                            Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.06f)).clickable { remindFor = m }.padding(horizontal = 12.dp, vertical = 10.dp),
                            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp),
                        ) {
                            Icon(if (m.remindAt != null) Icons.Rounded.NotificationsActive else Icons.Rounded.NotificationsNone, null, tint = settingsAccent(), modifier = Modifier.size(17.dp))
                            Text(
                                m.remindAt?.let { stringResource(R.string.fs_tp_remind_on, "‎" + it.take(10)) } ?: stringResource(R.string.fs_tp_remind_off),
                                color = if (m.remindAt == null) settingsMut() else settingsInk(), fontSize = 13.sp, modifier = Modifier.weight(1f),
                            )
                        }
                    }
                }
                FlAction(stringResource(R.string.fs_tp_invite), Icons.Rounded.Send, FlTone.Primary, enabled = l.members.isNotEmpty()) { result = null; inviting = true }
                FlAction(stringResource(R.string.fs_tp_rename), Icons.Rounded.Edit) { renaming = true }
                FlAction(stringResource(R.string.fs_tp_delete), Icons.Rounded.DeleteOutline, FlTone.Danger) { deleting = true }
                Spacer(Modifier.height(20.dp))
            }
        }

        if (renaming) TalentFormSheet(
            title = stringResource(R.string.fs_tp_rename), initialName = l.name, initialSpecialty = l.specialty, onDismiss = { renaming = false },
            submit = { name, specialty -> list = ApiClient.freelanceTalent.update(auth(), uuid, TalentListRequest(name, specialty)).data ?: list },
        )
        if (deleting) ConfirmDialog(
            icon = Icons.Rounded.DeleteOutline, title = stringResource(R.string.fs_tp_delete), message = stringResource(R.string.fs_tp_delete_confirm, l.name),
            loading = busy, onDismiss = { deleting = false }, confirmLabel = stringResource(R.string.fs_tp_delete),
            onConfirm = {
                busy = true
                scope.launch {
                    try {
                        ApiClient.freelanceTalent.delete(auth(), uuid)
                        deleting = false
                        onGone()
                    } catch (e: CancellationException) {
                        throw e
                    } catch (e: Exception) {
                        failure = e.message(generic)
                        deleting = false
                    }
                    busy = false
                }
            },
        )
        removeFor?.let { m ->
            ConfirmDialog(
                icon = Icons.Rounded.Close, title = stringResource(R.string.fs_tp_remove_member), message = stringResource(R.string.fs_tp_remove_confirm, m.name ?: "—"),
                loading = busy, onDismiss = { removeFor = null }, confirmLabel = stringResource(R.string.fl_remove),
                onConfirm = { act(after = { removeFor = null }) { ApiClient.freelanceTalent.removeMember(auth(), uuid, m.uuid).data } },
            )
        }
        noteFor?.let { m ->
            TalentNoteSheet(m, onDismiss = { noteFor = null }) { text ->
                list = ApiClient.freelanceTalent.updateMember(auth(), uuid, m.uuid, jsonBody { put("note", text) }).data ?: list
            }
        }
        remindFor?.let { m ->
            TalentRemindSheet(m, l.remindOptions, onDismiss = { remindFor = null }) { days ->
                list = ApiClient.freelanceTalent.updateMember(auth(), uuid, m.uuid, jsonBody { put("remind_in_days", days ?: JSONObject.NULL) }).data ?: list
            }
        }
        if (inviting) TalentInviteSheet(l, onDismiss = { inviting = false }, onDone = { result = it })
    }
}

@Composable
private fun TalentNoteSheet(member: TalentMemberDto, onDismiss: () -> Unit, submit: suspend (String) -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    var text by remember { mutableStateOf(member.note.orEmpty()) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    FlSheet(Icons.Rounded.EditNote, stringResource(R.string.fs_tp_note_title, member.name ?: "—"), stringResource(R.string.fs_tp_note_hint), onDismiss) {
        DorrTextField(text, { text = it.take(200) }, label = stringResource(R.string.fs_tp_note), icon = Icons.Rounded.Notes, singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
        error?.let { FlNotice(it, Icons.Rounded.ErrorOutline, Red) }
        FlAction(stringResource(R.string.common_save), Icons.Rounded.Check, FlTone.Primary, loading = busy) {
            busy = true
            error = null
            scope.launch {
                try {
                    submit(text.trim())
                    onDismiss()
                } catch (e: CancellationException) {
                    throw e
                } catch (e: Exception) {
                    error = e.message(generic)
                }
                busy = false
            }
        }
    }
}

/** Off · 30 · 60 · 90 · 180 days. The reminder is for me alone; the freelancer is never told. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun TalentRemindSheet(member: TalentMemberDto, options: List<Int>, onDismiss: () -> Unit, submit: suspend (Int?) -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    fun pick(days: Int?) {
        busy = true
        error = null
        scope.launch {
            try {
                submit(days)
                onDismiss()
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                error = e.message(generic)
            }
            busy = false
        }
    }
    FlSheet(Icons.Rounded.NotificationsActive, stringResource(R.string.fs_tp_remind_title, member.name ?: "—"), stringResource(R.string.fs_tp_remind_hint), onDismiss) {
        member.remindAt?.let { FlNotice(stringResource(R.string.fs_tp_remind_on, "‎" + it.take(10)), Icons.Rounded.NotificationsActive, settingsAccent()) }
        // Wraps (every choice is in view at once - a sideways scroll hid the longer ones).
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Chip(stringResource(R.string.fs_tp_remind_none), selected = member.remindAt == null, icon = Icons.Rounded.NotificationsOff) { if (!busy) pick(null) }
            options.forEach { d -> Chip(stringResource(R.string.fs_tp_remind_days, d), selected = false, icon = Icons.Rounded.Schedule) { if (!busy) pick(d) } }
        }
        error?.let { FlNotice(it, Icons.Rounded.ErrorOutline, Red) }
    }
}

/** «Invite to a project»: one of my private published projects, and who (everyone unless some are unticked). */
@Composable
private fun TalentInviteSheet(list: TalentListDetailDto, onDismiss: () -> Unit, onDone: (TalentInviteResultDto) -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    var projects by remember { mutableStateOf<List<FreelanceProjectDto>?>(null) }
    var loadError by remember { mutableStateOf<String?>(null) }
    var project by remember { mutableStateOf<String?>(null) }
    val picked = remember { mutableStateListOf<String>().apply { addAll(list.members.map { it.uuid }) } }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(Unit) {
        try {
            projects = ApiClient.freelance.projects(auth(), status = "published", perPage = 50, scope = "active").data.orEmpty()
                .filter { it.visibility == "private" && it.status == "published" }
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            loadError = e.message(generic)
        }
    }

    FlSheet(
        Icons.Rounded.Send, stringResource(R.string.fs_tp_invite), stringResource(R.string.fs_tp_invite_hint), onDismiss,
        footer = {
            error?.let { FlNotice(it, Icons.Rounded.ErrorOutline, Red) }
            FlAction(stringResource(R.string.fs_tp_invite_go, picked.size), Icons.Rounded.Send, FlTone.Primary, enabled = project != null && picked.isNotEmpty(), loading = busy) {
                val target = project ?: return@FlAction
                busy = true
                error = null
                scope.launch {
                    try {
                        // Everyone: leave the list out, as the server reads it.
                        val who = if (picked.size == list.members.size) null else picked.toList()
                        val r = ApiClient.freelanceTalent.invite(auth(), list.uuid, TalentInviteRequest(target, who)).data ?: TalentInviteResultDto()
                        onDone(r)
                        onDismiss()
                    } catch (e: CancellationException) {
                        throw e
                    } catch (e: Exception) {
                        error = e.message(generic)
                    }
                    busy = false
                }
            }
        },
    ) {
        FlHeading(Icons.Rounded.Work, stringResource(R.string.fs_tp_pick_project))
        val ps = projects
        when {
            ps == null && loadError == null -> repeat(2) { com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().height(52.dp), RoundedCornerShape(14.dp)) }
            ps == null -> FlNotice(loadError.orEmpty(), Icons.Rounded.ErrorOutline, Red)
            ps.isEmpty() -> FlNotice(stringResource(R.string.fs_tp_no_projects), Icons.Rounded.Info, settingsAccent())
            else -> ps.forEach { p ->
                val on = project == p.uuid
                Row(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(if (on) settingsAccent().copy(alpha = 0.14f) else settingsAccent().copy(alpha = 0.06f)).clickable { project = p.uuid }.padding(horizontal = 12.dp, vertical = 12.dp),
                    verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp),
                ) {
                    Icon(if (on) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (on) settingsAccent() else settingsMut(), modifier = Modifier.size(20.dp))
                    Text(p.title ?: "—", color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold, maxLines = 2, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                }
            }
        }
        FlHeading(Icons.Rounded.Groups, stringResource(R.string.fs_tp_pick_who), stringResource(R.string.fs_tp_pick_who_hint))
        list.members.forEach { m ->
            val on = m.uuid in picked
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable { if (on) picked.remove(m.uuid) else picked.add(m.uuid) }.padding(horizontal = 4.dp, vertical = 6.dp),
                verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                Icon(if (on) Icons.Rounded.CheckBox else Icons.Rounded.CheckBoxOutlineBlank, null, tint = if (on) settingsAccent() else settingsMut(), modifier = Modifier.size(22.dp))
                TalentAvatar(m.name, m.avatarUrl, 34)
                Text(m.name ?: "—", color = settingsInk(), fontSize = 14.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
            }
        }
    }
}

/** What an invite did: how many were invited, and who was left out and why. */
@Composable
private fun TalentInviteResult(r: TalentInviteResultDto, members: List<TalentMemberDto>) {
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        // Green only when somebody really was invited - a "0 invited" in green read like a success.
        if (r.invited > 0) FlNotice(stringResource(R.string.fs_tp_invited_n, r.invited), Icons.Rounded.CheckCircle, Green)
        if (r.skipped.isNotEmpty()) {
            FlNotice(stringResource(R.string.fs_tp_skipped_n, r.skipped.size), Icons.Rounded.Info, Color(0xFFD97706))
            r.skipped.forEach { s ->
                val name = members.firstOrNull { it.uuid == s.uuid }?.name ?: "—"
                val why = stringResource(
                    when (s.reason) {
                        "already_offered" -> R.string.fs_tp_skip_already_offered
                        "already_invited" -> R.string.fs_tp_skip_already_invited
                        "declined" -> R.string.fs_tp_skip_declined
                        else -> R.string.fs_tp_skip_not_found
                    },
                )
                Text("$name — $why", color = settingsMut(), fontSize = 12.sp, modifier = Modifier.padding(horizontal = 6.dp))
            }
        }
    }
}

// ---------------------------------------------------------------- «Save to a list»

/** A small icon that opens «Save to a list» for the freelancer (a row of the freelancers I keep). */
@Composable
internal fun SaveToListButton(freelancer: String) {
    var open by remember { mutableStateOf(false) }
    Icon(Icons.Rounded.BookmarkAdd, stringResource(R.string.fs_tp_save), tint = settingsAccent(), modifier = Modifier.size(34.dp).clip(CircleShape).clickable { open = true }.padding(7.dp))
    if (open) SaveToListSheet(freelancer) { open = false }
}

/** The same, as a pill with its words (a freelancer's profile). */
@Composable
internal fun SaveToListPill(freelancer: String) {
    var open by remember { mutableStateOf(false) }
    Row(
        Modifier.clip(RoundedCornerShape(999.dp)).clickable { open = true }.padding(horizontal = 10.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Rounded.BookmarkAdd, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
        Text(stringResource(R.string.fs_tp_save), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(start = 4.dp))
    }
    if (open) SaveToListSheet(freelancer) { open = false }
}

/** My lists as ticks (the ones that hold this freelancer are ticked): a tap adds or removes him; «New list» makes one and saves him in it. */
@Composable
private fun SaveToListSheet(freelancer: String, onDismiss: () -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    var lists by remember { mutableStateOf<List<TalentListDto>?>(null) }
    val holding = remember { mutableStateListOf<String>() }
    var error by remember { mutableStateOf<String?>(null) }
    var failure by remember { mutableStateOf<String?>(null) }
    var busyList by remember { mutableStateOf<String?>(null) }
    var creating by remember { mutableStateOf(false) }
    var version by remember { mutableStateOf(0) }

    LaunchedEffect(freelancer, version) {
        try {
            val all = ApiClient.freelanceTalent.lists(auth()).data?.lists.orEmpty()
            val mine = ApiClient.freelanceTalent.containing(auth(), freelancer).data?.lists.orEmpty()
            lists = all
            holding.clear()
            holding.addAll(mine)
            error = null
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message(generic)
        }
    }

    FlSheet(Icons.Rounded.BookmarkAdd, stringResource(R.string.fs_tp_save_title), stringResource(R.string.fs_tp_private), onDismiss) {
        failure?.let { FlNotice(it, Icons.Rounded.ErrorOutline, Red) }
        val ls = lists
        when {
            ls == null && error == null -> repeat(3) { com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().height(52.dp), RoundedCornerShape(14.dp)) }
            ls == null -> FlNotice(error.orEmpty(), Icons.Rounded.ErrorOutline, Red)
            else -> {
                if (ls.isEmpty()) FlHint(stringResource(R.string.fs_tp_save_none))
                ls.forEach { l ->
                    val on = l.uuid in holding
                    Row(
                        Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = if (on) 0.14f else 0.06f))
                            .clickable(enabled = busyList == null) {
                                busyList = l.uuid
                                failure = null
                                scope.launch {
                                    try {
                                        if (on) ApiClient.freelanceTalent.removeMember(auth(), l.uuid, freelancer) else ApiClient.freelanceTalent.addMember(auth(), l.uuid, TalentMemberRequest(freelancer))
                                        if (on) holding.remove(l.uuid) else holding.add(l.uuid)
                                        // The counts move too: read the lists again.
                                        lists = runCatching { ApiClient.freelanceTalent.lists(auth()).data?.lists }.getOrNull() ?: lists
                                    } catch (e: CancellationException) {
                                        throw e
                                    } catch (e: Exception) {
                                        failure = e.message(generic)
                                    }
                                    busyList = null
                                }
                            }.padding(horizontal = 12.dp, vertical = 12.dp),
                        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp),
                    ) {
                        Icon(if (on) Icons.Rounded.CheckBox else Icons.Rounded.CheckBoxOutlineBlank, null, tint = if (on) settingsAccent() else settingsMut(), modifier = Modifier.size(22.dp))
                        Column(Modifier.weight(1f)) {
                            Text(l.name, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            Text(listOfNotNull(l.specialty?.name, stringResource(R.string.fs_tp_count, l.count)).joinToString("  ·  "), color = settingsMut(), fontSize = 11.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        }
                        if (busyList == l.uuid) androidx.compose.material3.CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(18.dp))
                    }
                }
                FlAction(stringResource(R.string.fs_tp_new), Icons.Rounded.Add) { creating = true }
            }
        }
    }

    if (creating) TalentFormSheet(
        title = stringResource(R.string.fs_tp_new), initialName = "", initialSpecialty = null, onDismiss = { creating = false },
        submit = { name, specialty ->
            val made = ApiClient.freelanceTalent.create(auth(), TalentListRequest(name, specialty)).data
            // The list exists even if saving him in it fails: the sheet then reads the lists again.
            made?.let { runCatching { ApiClient.freelanceTalent.addMember(auth(), it.uuid, TalentMemberRequest(freelancer)) } }
            version++
        },
    )
}
