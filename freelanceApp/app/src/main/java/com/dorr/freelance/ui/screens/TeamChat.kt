package com.dorr.freelance.ui.screens

import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.Forum
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Handyman
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.derivedStateOf
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
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.AppLocale
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.TeamMessageDto
import com.dorr.freelance.net.TeamMessageRequest
import com.dorr.freelance.net.TeamProjectDto
import com.dorr.freelance.net.TeamThreadDto
import com.dorr.freelance.net.TeamThreadRequest
import com.dorr.freelance.net.TeamThreadsDto
import com.dorr.freelance.net.apiDone
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.EmptyState
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.MessageToast
import com.dorr.freelance.ui.components.ShellState
import com.dorr.freelance.ui.components.SkeletonList
import com.dorr.freelance.ui.kit.Corners
import com.dorr.freelance.ui.kit.PinkBackdrop
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.StatusPill
import com.dorr.freelance.ui.kit.SubHeader
import com.dorr.freelance.ui.kit.appCard
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsCard
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsSurface
import com.dorr.freelance.ui.locale.LocaleAwareBottomSheet
import com.google.gson.Gson
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.time.Duration
import java.time.LocalDate
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.UUID

private val AMBER = Color(0xFFD97706)
private val RED = Color(0xFFDC2626)

/** A message may be taken back by its sender only for a few minutes (the server decides; this only hides the entry afterwards). */
private const val TAKE_BACK_MINUTES = 5L

/**
 * FS-209c: the «Chat» part of a team's page — the team's inside talk (never seen by a client): the general thread first, then one thread
 * per project the team works on. A thread opens from here; an Owner may open the thread of a project that has none yet. The numbers come
 * from the page that holds this part (it reads again when a notice arrives and when the connection returns).
 */
@Composable
internal fun TeamChatSection(
    teamUuid: String,
    data: TeamThreadsDto?,
    failure: String?,
    onOpenThread: (String) -> Unit,
    onReload: suspend () -> Unit,
) {
    var picking by remember { mutableStateOf(false) }
    val list = data
    when {
        list == null && failure == null -> SkeletonList(count = 3)
        list == null -> ErrorBanner(failure, Modifier.fillMaxWidth())
        else -> {
            TeamChatNotice(list.notice)
            if (!list.canWrite) Text(stringResource(R.string.tc_read_only), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp)
            if (list.canOpenProject) SettingsPrimaryButton(text = stringResource(R.string.tc_open_project), onClick = { picking = true })
            if (list.threads.isEmpty()) EmptyState(icon = Icons.Rounded.Forum, title = stringResource(R.string.tc_empty_title), body = stringResource(R.string.tc_empty_body), modifier = Modifier.padding(top = 20.dp))
            list.threads.forEach { t -> ThreadRow(t) { onOpenThread(t.uuid) } }
        }
    }
    if (picking && list != null) ProjectPickerSheet(
        teamUuid = teamUuid, taken = list.threads.mapNotNull { it.project?.uuid }.toSet(),
        onOpened = { thread -> picking = false; onOpenThread(thread) },
        onDismiss = { picking = false },
        onReload = onReload,
    )
}

/** What the team is told about its chat (for example that the staff may read a thread while a dispute is decided). */
@Composable
private fun TeamChatNotice(notice: String?) {
    val text = notice?.takeIf { it.isNotBlank() } ?: stringResource(R.string.tc_private_hint)
    Row(
        Modifier.fillMaxWidth().appCard(Corners.card).padding(14.dp),
        verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Icon(Icons.Rounded.Info, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
        Text(text, color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp, modifier = Modifier.weight(1f))
    }
}

@Composable
private fun ThreadRow(t: TeamThreadDto, onClick: () -> Unit) {
    val tint = settingsAccent()
    Row(
        Modifier.fillMaxWidth().appCard(Corners.card).clickable(onClick = onClick).padding(14.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Box(Modifier.size(42.dp).clip(Corners.small).background(tint.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
            Icon(if (t.general) Icons.Rounded.Groups else Icons.Rounded.Handyman, contentDescription = null, tint = tint, modifier = Modifier.size(22.dp))
        }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Text(
                    if (t.general) stringResource(R.string.tc_general) else t.project?.title.orEmpty(),
                    color = settingsInk(), fontSize = 14.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false),
                )
                if (t.underReview) StatusPill(stringResource(R.string.tc_under_review), AMBER)
            }
            Text(
                t.lastMessagePreview?.takeIf { it.isNotBlank() } ?: stringResource(R.string.tc_no_messages),
                color = if (t.unread > 0) settingsInk() else settingsMut(), fontSize = 12.5.sp, fontWeight = if (t.unread > 0) FontWeight.SemiBold else FontWeight.Normal,
                maxLines = 1, overflow = TextOverflow.Ellipsis,
            )
        }
        Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(6.dp)) {
            whenText(t.lastMessageAt)?.let { Text(it, color = settingsMut(), fontSize = 11.sp) }
            if (t.unread > 0) Box(
                Modifier.clip(CircleShape).background(tint).padding(horizontal = 7.dp, vertical = 2.dp).heightIn(min = 16.dp),
                contentAlignment = Alignment.Center,
            ) { Text(if (t.unread > 99) "99+" else t.unread.toString(), color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.Black) }
            else Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, contentDescription = null, tint = settingsMut(), modifier = Modifier.size(18.dp))
        }
    }
}

/** An Owner opens the thread of a project the team works on (those that have none yet are listed). */
@Composable
private fun ProjectPickerSheet(teamUuid: String, taken: Set<String>, onOpened: (String) -> Unit, onDismiss: () -> Unit, onReload: suspend () -> Unit) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var projects by remember { mutableStateOf<List<TeamProjectDto>?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(teamUuid) {
        val r = apiCall { ApiClient.teams.projects(teamUuid) }
        if (r is ApiResult.Ok) projects = r.data else error = r.errorText(offlineText) ?: genericError
    }

    fun open(project: String) {
        busy = true
        error = null
        scope.launch {
            val r = apiCall { ApiClient.teams.openChatThread(teamUuid, TeamThreadRequest(project)) }
            busy = false
            if (r is ApiResult.Ok) { onReload(); onOpened(r.data.thread.uuid) } else error = r.errorText(offlineText) ?: genericError
        }
    }

    LocaleAwareBottomSheet(onDismissRequest = { if (!busy) onDismiss() }, containerColor = settingsCard(), shape = RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp)) {
        Column(
            Modifier.fillMaxWidth().heightIn(max = 560.dp).verticalScroll(rememberScrollState()).padding(horizontal = 18.dp).padding(bottom = 24.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(stringResource(R.string.tc_open_project_title), color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.tc_open_project_hint), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp)
            val free = projects?.filter { p -> p.project.uuid != null && p.project.uuid !in taken }
            when {
                projects == null && error == null -> SkeletonList(count = 2)
                free.isNullOrEmpty() && projects != null -> Text(stringResource(R.string.tc_open_project_none), color = settingsMut(), fontSize = 13.sp)
                else -> free?.forEach { p ->
                    Row(
                        Modifier.fillMaxWidth().appCard(Corners.card).clickable(enabled = !busy) { p.project.uuid?.let(::open) }.padding(14.dp),
                        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp),
                    ) {
                        Icon(Icons.Rounded.Handyman, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(20.dp))
                        Text(p.project.title.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold, maxLines = 2, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                    }
                }
            }
            ErrorBanner(error, Modifier.fillMaxWidth())
        }
    }
}

// ---------------------------------------------------------------------------------------------------------------------------------

private fun JsonObject.text(key: String): String? = runCatching { get(key)?.takeIf { it.isJsonPrimitive }?.asString }.getOrNull()

/**
 * One of the team's threads (the general one or a project's): the messages newest first from the bottom, older pages when I scroll up, the
 * composer for those who may write. It reads once when it opens, once when a notice arrives and once when the connection returns; while
 * it is open it also listens to the team's channel, which brings each new message (or a taken-back one) as it happens — nothing on a timer.
 */
@OptIn(ExperimentalFoundationApi::class)
@Composable
fun TeamThreadScreen(teamUuid: String, threadUuid: String, onBack: () -> Unit) {
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var thread by remember { mutableStateOf<TeamThreadDto?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    // Newest first, as the server gives them (the list is drawn from the bottom).
    val messages = remember { mutableStateListOf<TeamMessageDto>() }
    var nextBefore by remember { mutableStateOf<Int?>(null) }
    var loadingOlder by remember { mutableStateOf(false) }
    var olderFailed by remember { mutableStateOf(false) }
    val listState = rememberLazyListState()
    var input by remember { mutableStateOf("") }
    var sending by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var loaded by remember { mutableStateOf(false) }
    var toast by remember { mutableStateOf<String?>(null) }
    // What I sent from this screen: a live copy of my own message must not be shown as somebody else's.
    val sentIds = remember { mutableSetOf<String>() }

    // The page is a chat: it takes the whole screen (no tab bar over the keyboard).
    DisposableEffect(Unit) {
        ShellState.immersive = true
        onDispose { ShellState.immersive = false }
    }
    DisposableEffect(teamUuid) {
        Realtime.listenTeam(teamUuid)
        onDispose { Realtime.stopListeningTeam(teamUuid) }
    }

    fun myFreelancerId(): Int? = messages.firstOrNull { it.mine == true }?.from?.id

    /** A message that came live has no `mine` (it was made for everybody): it is mine when I sent it, or when it carries my id. */
    fun withMine(m: TeamMessageDto): TeamMessageDto {
        if (m.mine != null) return m
        val id = myFreelancerId()
        return m.copy(mine = (m.clientMsgId != null && m.clientMsgId in sentIds) || (id != null && m.from?.id == id))
    }

    fun put(m: TeamMessageDto) {
        val at = messages.indexOfFirst { it.id == m.id }
        if (at >= 0) messages[at] = m else messages.add(0, m)
    }

    suspend fun load() {
        val result = apiCall { ApiClient.teams.chatMessages(teamUuid, threadUuid) }
        if (result is ApiResult.Ok) {
            val page = result.data
            page.thread?.let { thread = it }
            notice = page.notice
            // The newest page replaces what is shown; the older messages I scrolled up to stay under it.
            val oldest = page.messages.lastOrNull()?.id
            val older = if (oldest == null) emptyList() else messages.filter { it.id < oldest }
            messages.clear()
            messages.addAll(page.messages)
            messages.addAll(older)
            if (older.isEmpty()) nextBefore = page.nextBefore
            error = null
            if ((page.thread?.unread ?: 0) > 0) apiCall { ApiClient.teams.readChat(teamUuid, threadUuid) }
        } else if (!loaded) {
            error = result.errorText(offlineText) ?: genericError
        }
        loaded = true
    }
    LaunchedEffect(teamUuid, threadUuid, reconnect) { load() }
    LaunchedEffect(teamUuid, threadUuid) { Realtime.changed.collect { load() } }

    // The team's channel: a new message arrives with its body, a taken-back one with its marker (the thread's reads are not shown).
    LaunchedEffect(teamUuid, threadUuid) {
        Realtime.teamEvents.collect { event ->
            if (event.data.text("thread") != threadUuid) return@collect
            val body = runCatching { event.data.getAsJsonObject("message") }.getOrNull()
            when (event.data.text("action")) {
                "message" -> if (body != null) {
                    val m = withMine(Gson().fromJson(body, TeamMessageDto::class.java))
                    put(m)
                    if (m.mine != true) apiCall { ApiClient.teams.readChat(teamUuid, threadUuid) }
                }
                "deleted" -> if (body != null) {
                    val gone = Gson().fromJson(body, TeamMessageDto::class.java)
                    val at = messages.indexOfFirst { it.id == gone.id }
                    if (at >= 0) messages[at] = messages[at].copy(kind = "deleted", body = null)
                }
            }
        }
    }

    // Older messages come by themselves when I scroll up to the oldest one loaded.
    suspend fun loadOlder() {
        val before = nextBefore ?: return
        if (loadingOlder) return
        loadingOlder = true
        olderFailed = false
        val more = apiCall { ApiClient.teams.chatMessages(teamUuid, threadUuid, before = before) }
        if (more is ApiResult.Ok) {
            messages.addAll(more.data.messages.filter { n -> messages.none { it.id == n.id } })
            nextBefore = more.data.nextBefore
        } else olderFailed = true
        loadingOlder = false
    }
    val nearOldest by remember {
        derivedStateOf {
            val info = listState.layoutInfo
            val last = info.visibleItemsInfo.lastOrNull()?.index ?: -1
            info.totalItemsCount > 0 && last >= info.totalItemsCount - 4
        }
    }
    LaunchedEffect(nearOldest, messages.size, nextBefore, olderFailed, loaded) {
        if (loaded && nearOldest && nextBefore != null && !loadingOlder && !olderFailed) loadOlder()
    }

    fun send() {
        val text = input.trim()
        if (text.isEmpty() || sending) return
        sending = true
        error = null
        val clientId = UUID.randomUUID().toString()
        sentIds.add(clientId)
        scope.launch {
            val result = apiCall { ApiClient.teams.sendChat(teamUuid, threadUuid, TeamMessageRequest(text, clientId)) }
            if (result is ApiResult.Ok) {
                put(result.data.message)
                input = ""
            } else {
                error = result.errorText(offlineText) ?: genericError
            }
            sending = false
        }
    }

    fun takeBack(m: TeamMessageDto) {
        scope.launch {
            val problem = apiDone(offlineText, genericError) { ApiClient.teams.deleteChatMessage(teamUuid, m.id) }
            if (problem == null) {
                val at = messages.indexOfFirst { it.id == m.id }
                if (at >= 0) messages[at] = messages[at].copy(kind = "deleted", body = null)
            } else toast = problem
        }
    }

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize().statusBarsPadding().navigationBarsPadding().imePadding()) {
            val t = thread
            SubHeader(
                when {
                    t == null -> stringResource(R.string.tc_title)
                    t.general -> stringResource(R.string.tc_general)
                    else -> t.project?.title ?: stringResource(R.string.tc_title)
                },
                onBack,
            )
            Text(notice?.takeIf { it.isNotBlank() } ?: stringResource(R.string.tc_private_hint), color = settingsMut(), fontSize = 11.sp, lineHeight = 15.sp, modifier = Modifier.padding(horizontal = 18.dp, vertical = 2.dp))
            Box(Modifier.weight(1f).fillMaxWidth()) {
                if (!loaded && error == null) SkeletonList(count = 3, modifier = Modifier.padding(14.dp))
                else LazyColumn(
                    Modifier.fillMaxSize(), state = listState, reverseLayout = true,
                    contentPadding = PaddingValues(horizontal = 14.dp, vertical = 8.dp), verticalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    items(messages, key = { it.id }) { m -> TeamBubble(m, onTakeBack = { takeBack(m) }) }
                    if (nextBefore != null) item {
                        if (olderFailed) Text(
                            stringResource(R.string.common_retry), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                            modifier = Modifier.fillMaxWidth().clickable { olderFailed = false }.padding(12.dp),
                        ) else Box(Modifier.fillMaxWidth().padding(12.dp), contentAlignment = Alignment.Center) {
                            CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(20.dp))
                        }
                    }
                    if (loaded && messages.isEmpty()) item { Text(stringResource(R.string.tc_empty_thread), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.padding(24.dp)) }
                }
            }
            error?.let { Text(it, color = RED, fontSize = 12.sp, modifier = Modifier.padding(horizontal = 18.dp, vertical = 2.dp)) }
            MessageToast(toast)
            if (t?.canWrite == true) Row(Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 8.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                DorrTextField(value = input, onValueChange = { if (it.length <= 2000) input = it }, modifier = Modifier.weight(1f), placeholder = stringResource(R.string.tc_hint), singleLine = false)
                Box(
                    Modifier.size(44.dp).clip(CircleShape).background(settingsAccent().copy(alpha = if (input.isBlank() || sending) 0.4f else 1f)).clickable(enabled = input.isNotBlank() && !sending, onClick = ::send),
                    contentAlignment = Alignment.Center,
                ) { Icon(Icons.AutoMirrored.Rounded.Send, stringResource(R.string.tc_send), tint = Color.White, modifier = Modifier.size(20.dp)) }
            } else if (t != null) Text(stringResource(R.string.tc_closed), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.fillMaxWidth().padding(16.dp))
        }
    }
}

/** One message: the sender's name over somebody else's, the time under it; mine can be taken back for a few minutes (long press). */
@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun TeamBubble(m: TeamMessageDto, onTakeBack: () -> Unit) {
    val mine = m.mine == true
    val deleted = m.kind == "deleted"
    var menu by remember { mutableStateOf(false) }
    val canTakeBack = mine && !deleted && withinTakeBack(m.createdAt)
    Row(Modifier.fillMaxWidth(), horizontalArrangement = if (mine) Arrangement.Absolute.Right else Arrangement.Absolute.Left) {
        Box {
            Column(
                Modifier.widthIn(max = 290.dp).clip(RoundedCornerShape(16.dp))
                    .then(if (mine) Modifier.background(settingsAccent()) else Modifier.settingsSurface(RoundedCornerShape(16.dp)))
                    .combinedClickable(onClick = {}, onLongClick = { if (canTakeBack) menu = true })
                    .padding(horizontal = 12.dp, vertical = 8.dp),
                verticalArrangement = Arrangement.spacedBy(2.dp),
            ) {
                if (!mine) m.from?.name?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsAccent(), fontSize = 11.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis) }
                if (deleted) Text(
                    stringResource(R.string.tc_deleted), color = if (mine) Color.White.copy(alpha = 0.85f) else settingsMut(), fontSize = 13.sp, fontStyle = FontStyle.Italic,
                ) else Text(m.body.orEmpty(), color = if (mine) Color.White else settingsInk(), fontSize = 14.sp, lineHeight = 20.sp)
                clockText(m.createdAt)?.let { Text("‎$it", color = if (mine) Color.White.copy(alpha = 0.8f) else settingsMut(), fontSize = 10.sp) }
            }
            DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                DropdownMenuItem(text = { Text(stringResource(R.string.tc_take_back)) }, onClick = { menu = false; onTakeBack() })
            }
        }
    }
}

private fun parse(iso: String?): OffsetDateTime? = runCatching { OffsetDateTime.parse(iso) }.getOrNull()

private fun withinTakeBack(iso: String?): Boolean = parse(iso)?.let { Duration.between(it, OffsetDateTime.now()).toMinutes() < TAKE_BACK_MINUTES } ?: false

private fun clockText(iso: String?): String? = parse(iso)?.atZoneSameInstant(ZoneId.systemDefault())?.format(DateTimeFormatter.ofPattern("HH:mm"))

/** The time of today's messages, the date of the older ones. */
private fun whenText(iso: String?): String? {
    val at = parse(iso)?.atZoneSameInstant(ZoneId.systemDefault()) ?: return null
    val pattern = if (at.toLocalDate() == LocalDate.now()) "HH:mm" else "d MMM"
    return at.format(DateTimeFormatter.ofPattern(pattern, AppLocale.java()))
}
