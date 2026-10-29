package com.dorr.app.ui.screens.chat

import androidx.activity.compose.BackHandler
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.border
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
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.automirrored.rounded.ExitToApp
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.AddLink
import androidx.compose.material.icons.rounded.AddModerator
import androidx.compose.material.icons.rounded.Campaign
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Diversity3
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.GroupAdd
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.PersonRemove
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.RemoveModerator
import androidx.compose.material.icons.rounded.Subject
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshotFlow
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
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
import com.dorr.app.network.CommunityDto
import com.dorr.app.network.CommunityGroupDto
import com.dorr.app.network.ConversationDto
import com.dorr.app.network.MemberDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.components.DarkHeroButton
import com.dorr.app.ui.screens.ConfirmDialog
import com.dorr.app.ui.screens.profile.PinkBackdrop
import com.dorr.app.ui.screens.profile.SubHeader
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.filter
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
    val scope = rememberCoroutineScope()
    val listState = rememberLazyListState()
    var list by remember { mutableStateOf<List<CommunityDto>?>(null) }
    var query by remember { mutableStateOf("") }
    var page by remember { mutableIntStateOf(1) }
    var hasMore by remember { mutableStateOf(false) }
    var loadingMore by remember { mutableStateOf(false) }
    var creating by remember { mutableStateOf(false) }

    suspend fun loadPage(reset: Boolean) {
        if (!reset && (loadingMore || !hasMore)) return
        if (!reset) loadingMore = true
        val next = if (reset) 1 else page + 1
        val envelope = runCatching {
            ApiClient.chat.communities(chatAuth(), page = next, perPage = 15, q = query.trim().ifBlank { null })
        }.getOrNull()
        val chunk = envelope?.data.orEmpty()
        list = if (reset) chunk else {
            val known = list.orEmpty().map { it.id }.toSet()
            list.orEmpty() + chunk.filter { it.id !in known }
        }
        page = next
        hasMore = envelope?.pagination?.hasMorePages == true
        if (!reset) loadingMore = false
    }

    LaunchedEffect(query) {
        if (query.isNotBlank()) delay(300)
        loadPage(reset = true)
    }
    LaunchedEffect(listState, list?.size, hasMore) {
        snapshotFlow {
            val info = listState.layoutInfo
            val last = info.visibleItemsInfo.lastOrNull()?.index ?: -1
            info.totalItemsCount > 0 && last >= info.totalItemsCount - 3
        }.distinctUntilChanged().filter { it }.collect { scope.launch { loadPage(reset = false) } }
    }

    ChPage(stringResource(R.string.cm_title), onBack = { host.pop() }, settingsHeader = true) {
        Box(Modifier.fillMaxSize()) {
            Column(Modifier.fillMaxSize()) {
                ChField(
                    query, { query = it.take(80) }, stringResource(R.string.cm_search),
                    icon = Icons.Rounded.Search, clearable = true,
                    modifier = Modifier.padding(start = 14.dp, end = 14.dp, top = 8.dp, bottom = 4.dp),
                )
                val items = list
                when {
                    items == null -> Box(Modifier.weight(1f).fillMaxWidth(), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator(Modifier.size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp)
                    }
                    else -> LazyColumn(
                        state = listState,
                        contentPadding = PaddingValues(start = 14.dp, end = 14.dp, top = 8.dp, bottom = 110.dp),
                        verticalArrangement = Arrangement.spacedBy(8.dp),
                        modifier = Modifier.weight(1f).fillMaxWidth(),
                    ) {
                        if (items.isEmpty()) item(key = "empty") {
                            Box(Modifier.fillMaxWidth().height(340.dp)) {
                                ChEmptyState(Icons.Rounded.Diversity3, stringResource(R.string.cm_title), stringResource(R.string.cm_empty), animated = true)
                            }
                        } else itemsIndexed(items, key = { _, c -> c.id }) { i, c ->
                            Box(Modifier.chStagger(i)) {
                                CommunityRow(null, c.name, avatar = c.avatar, key = c.id, groups = c.groupsCount, members = c.membersCount) {
                                    host.push(ChRoute.Community(c.id))
                                }
                            }
                        }
                        if (loadingMore) item(key = "more") {
                            Box(Modifier.fillMaxWidth().padding(12.dp), contentAlignment = Alignment.Center) {
                                CircularProgressIndicator(color = Ch.Red, strokeWidth = 2.dp, modifier = Modifier.size(22.dp))
                            }
                        }
                    }
                }
            }
            Column(
                Modifier
                    .align(Alignment.BottomCenter)
                    .fillMaxWidth()
                    .background(Brush.verticalGradient(listOf(Color.Transparent, Ch.Bg.copy(alpha = 0.96f)), startY = 0f, endY = 90f))
                    .navigationBarsPadding()
                    .padding(start = 16.dp, end = 16.dp, top = 26.dp, bottom = 16.dp),
            ) {
                CommunityCreateBar { creating = true }
            }
        }
    }

    if (creating) NewCommunitySheet(onDismiss = { creating = false }) { made ->
        list = listOf(made) + list.orEmpty().filterNot { it.id == made.id }
        host.push(ChRoute.Community(made.id))
    }
}

@Composable
private fun CommunityCreateBar(onCreate: () -> Unit) {
    Box(
        Modifier
            .fillMaxWidth()
            .shadow(14.dp, RoundedCornerShape(18.dp), spotColor = Ch.Red.copy(alpha = 0.45f))
            .clip(RoundedCornerShape(18.dp))
            .background(Ch.HeaderBrush)
            .clickable(onClick = onCreate)
            .padding(horizontal = 10.dp, vertical = 10.dp),
    ) {
        Text(
            stringResource(R.string.cm_new),
            color = Color.White,
            fontWeight = FontWeight.ExtraBold,
            fontSize = 15.sp,
            textAlign = TextAlign.Center,
            modifier = Modifier.fillMaxWidth().align(Alignment.Center),
        )
        Box(
            Modifier
                .align(Alignment.CenterStart)
                .size(34.dp)
                .clip(CircleShape)
                .background(Color.White.copy(alpha = 0.16f)),
            contentAlignment = Alignment.Center,
        ) { Icon(Icons.Rounded.Diversity3, null, tint = Color.White, modifier = Modifier.size(18.dp)) }
    }
}

@Composable
private fun CommunityRow(icon: ImageVector?, title: String, sub: String? = null, avatar: String?, key: String? = null, groups: Int? = null, members: Int? = null, onClick: () -> Unit) {
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
            if (groups != null && members != null) {
                CommunityStats(groups, members)
            } else {
                sub?.let { Text(it, color = Ch.Mut, fontSize = 12.5.sp, maxLines = 2, overflow = TextOverflow.Ellipsis) }
            }
        }
    }
}

@Composable
private fun CommunityStats(groups: Int, members: Int, ink: Color = Ch.Ink, mut: Color = Ch.Mut, accent: Color = Ch.Red) {
    Row(Modifier.padding(top = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        CommunityStat(Icons.Rounded.Groups, groups, stringResource(R.string.cm_group), ink, mut, accent)
        Text("  ·  ", color = mut, fontSize = 12.sp)
        CommunityStat(Icons.Rounded.Person, members, stringResource(R.string.cm_member), ink, mut, accent)
    }
}

@Composable
private fun CommunityStat(icon: ImageVector, count: Int, label: String, ink: Color, mut: Color, accent: Color) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Icon(icon, null, tint = accent, modifier = Modifier.size(14.dp))
        Spacer(Modifier.width(4.dp))
        Text(count.toString(), color = ink, fontWeight = FontWeight.ExtraBold, fontSize = 13.sp)
        Spacer(Modifier.width(4.dp))
        Text(label, color = mut, fontSize = 12.sp, fontWeight = FontWeight.Bold)
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
    var mine by remember { mutableStateOf<List<ConversationDto>?>(null) }
    LaunchedEffect(Unit) { mine = runCatching { loadFreeGroups() }.getOrNull() ?: myFreeGroups(host) }

    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Bg, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
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

/** A new group inside this community: name, and optionally what it's for. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun NewCommunityGroupSheet(onDismiss: () -> Unit, onCreate: (String, String) -> Unit) {
    var name by remember { mutableStateOf("") }
    var description by remember { mutableStateOf("") }
    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Bg, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().imePadding().heightIn(max = 520.dp).verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                IconBadge(Icons.Rounded.GroupAdd, Ch.Red)
                Spacer(Modifier.width(12.dp))
                Text(stringResource(R.string.cm_new_group), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            }
            Spacer(Modifier.height(14.dp))
            SheetField(name, stringResource(R.string.ch_group_name_hint), bold = true, icon = Icons.Rounded.GroupAdd) { name = it.take(100) }
            Spacer(Modifier.height(8.dp))
            SheetField(description, stringResource(R.string.cm_description_hint), icon = Icons.Rounded.Subject) { description = it.take(2000) }
            Spacer(Modifier.height(16.dp))
            ChPrimaryButton(stringResource(R.string.cm_create_group), icon = Icons.Rounded.GroupAdd, modifier = Modifier.fillMaxWidth(), enabled = name.isNotBlank()) {
                onCreate(name.trim(), description.trim())
                onDismiss()
            }
        }
    }
}

/** Name and what it's for — same two fields as create. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun EditCommunitySheet(c: CommunityDto, onDismiss: () -> Unit, onSave: (String, String) -> Unit) {
    var name by remember { mutableStateOf(c.name) }
    var description by remember { mutableStateOf(c.description.orEmpty()) }
    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().imePadding().heightIn(max = 520.dp).verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Text(stringResource(R.string.cm_rename), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(14.dp))
            SheetField(name, stringResource(R.string.cm_name_hint), bold = true, icon = Icons.Rounded.Edit) { name = it.take(100) }
            Spacer(Modifier.height(8.dp))
            SheetField(description, stringResource(R.string.cm_description_hint), icon = Icons.Rounded.Subject) { description = it.take(2000) }
            Spacer(Modifier.height(16.dp))
            ChPrimaryButton(stringResource(R.string.ch_save), modifier = Modifier.fillMaxWidth(), enabled = name.isNotBlank()) {
                onSave(name.trim(), description.trim())
                onDismiss()
            }
        }
    }
}

/** Groups I run that aren't in a community yet — they can be brought in. */
private fun isFreeGroup(c: ConversationDto) = c.isGroup && !c.isChannel && c.isAdmin && c.isMember && c.group?.community == null

private fun myFreeGroups(host: ChatHost) = host.conversations.filter(::isFreeGroup)

private suspend fun loadFreeGroups(): List<ConversationDto> {
    val out = ArrayList<ConversationDto>()
    var page = 1
    while (page <= 10) {
        val res = ApiClient.chat.conversations(chatAuth(), filter = "groups", page = page, perPage = 50)
        out += res.data.orEmpty().filter(::isFreeGroup)
        if (res.pagination?.hasMorePages != true) break
        page++
    }
    return out.distinctBy { it.id }
}

@Composable
private fun GroupPicker(groups: List<ConversationDto>?, picked: MutableList<String>, showTitle: Boolean = true) {
    var query by remember { mutableStateOf("") }
    if (groups == null) {
        Box(Modifier.fillMaxWidth().height(120.dp), contentAlignment = Alignment.Center) {
            CircularProgressIndicator(Modifier.size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp)
        }
        return
    }
    val shown = groups.filter { query.isBlank() || it.title.orEmpty().contains(query.trim(), ignoreCase = true) }
    if (showTitle) Text(stringResource(R.string.cm_pick_groups), color = Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(start = 4.dp, bottom = 8.dp))
    if (groups.isNotEmpty()) {
        ChField(query, { query = it }, stringResource(R.string.ch_info_search), icon = Icons.Rounded.Search, clearable = true, modifier = Modifier.fillMaxWidth().padding(bottom = 10.dp))
    }
    if (shown.isEmpty()) {
        Column(Modifier.fillMaxWidth().padding(vertical = 18.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Box(Modifier.size(56.dp).clip(CircleShape).background(Ch.Red.copy(alpha = 0.08f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Groups, null, tint = Ch.Red, modifier = Modifier.size(26.dp))
            }
            Spacer(Modifier.height(10.dp))
            Text(stringResource(R.string.cm_no_free_groups), color = Ch.Mut, fontSize = 13.sp, textAlign = TextAlign.Center, modifier = Modifier.padding(horizontal = 12.dp))
        }
        return
    }
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        shown.forEachIndexed { i, c ->
            val on = c.id in picked
            Row(
                Modifier.fillMaxWidth().chStagger(i)
                    .shadow(3.dp, RoundedCornerShape(18.dp), spotColor = Color.Black.copy(alpha = 0.06f))
                    .clip(RoundedCornerShape(18.dp))
                    .background(if (on) Ch.Red.copy(alpha = 0.08f) else Ch.Surface)
                    .clickable { if (on) picked.remove(c.id) else picked.add(c.id) }
                    .padding(horizontal = 12.dp, vertical = 12.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                ChAvatar(c.avatar, c.title, c.id, size = 46.dp, isGroup = true)
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(c.title.orEmpty(), color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    CommunityStat(Icons.Rounded.Person, c.group?.membersCount ?: 0, stringResource(R.string.cm_member), Ch.Ink, Ch.Mut, Ch.Red)
                }
                Icon(if (on) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (on) Ch.Red else Ch.Mut, modifier = Modifier.size(22.dp))
            }
        }
    }
}

// =============================================================================== one community

@OptIn(ExperimentalFoundationApi::class)
@Composable
fun CommunityPage(id: String) {
    val host = LocalChat.current
    val context = LocalContext.current
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
    var searching by remember { mutableStateOf(false) }
    var infoQuery by remember { mutableStateOf("") }
    val listState = rememberLazyListState()
    val networkError = stringResource(R.string.ch_error_network)
    val askedText = stringResource(R.string.ch_join_request_sent)
    val q = infoQuery.trim()
    fun hit(vararg parts: String?) = q.isBlank() || parts.any { !it.isNullOrBlank() && it.contains(q, ignoreCase = true) }
    fun hitRes(vararg ids: Int) = q.isBlank() || ids.any { context.getString(it).contains(q, ignoreCase = true) }
    BackHandler(searching) { searching = false; infoQuery = "" }

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

    val shown = community
    val scrolled by remember { derivedStateOf { listState.firstVisibleItemIndex > 0 || listState.firstVisibleItemScrollOffset > 140 } }
    val headerAlpha by animateFloatAsState(if (scrolled && !searching) 1f else 0f, label = "communityHeader")

    Box(Modifier.fillMaxSize().background(Ch.Bg)) {
        when {
            failed != null && shown == null -> ChEmptyState(Icons.Rounded.Diversity3, stringResource(R.string.cm_title), failed.orEmpty())
            shown == null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(28.dp), color = Ch.Red, strokeWidth = 2.5.dp) }
            else -> {
                val (insideAll, outsideAll) = shown.groups.partition { it.isMember }
                val joinLabel = stringResource(R.string.cm_join)
                val askLabel = stringResource(R.string.cm_ask_join)
                val membersLabel = stringResource(R.string.ch_members, 0).replace(Regex("\\d+"), "")
                val inside = insideAll.filter { hit(it.name, membersLabel) }
                val outside = outsideAll.filter { g -> hit(g.name, membersLabel, if (g.approveJoins) askLabel else joinLabel) }
                LazyColumn(state = listState, contentPadding = PaddingValues(bottom = 40.dp), modifier = Modifier.fillMaxSize()) {
                    item(key = "head") {
                        val shrink = (listState.firstVisibleItemScrollOffset / 400f).coerceIn(0f, 1f)
                        Box(Modifier.fillMaxWidth().clip(RoundedCornerShape(bottomStart = 34.dp, bottomEnd = 34.dp)).background(Ch.HeaderBrush).padding(top = 48.dp, bottom = 14.dp)) {
                            Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
                                Box(Modifier.graphicsLayer { val s = 1f - 0.35f * shrink; scaleX = s; scaleY = s; alpha = 1f - shrink }) {
                                    ChAvatar(shown.avatar, shown.name, shown.id, size = 72.dp, isGroup = true)
                                }
                                Spacer(Modifier.height(8.dp))
                                Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(horizontal = 16.dp)) {
                                    Text(
                                        shown.name,
                                        color = Color.White,
                                        fontSize = 18.sp,
                                        fontWeight = FontWeight.Bold,
                                        textAlign = TextAlign.Center,
                                        maxLines = 2,
                                        overflow = TextOverflow.Ellipsis,
                                        modifier = Modifier.weight(1f, fill = false),
                                    )
                                    if (shown.isAdmin) {
                                        Spacer(Modifier.width(8.dp))
                                        GlassIcon(Icons.Rounded.Edit, size = 32.dp) { renaming = true }
                                    }
                                }
                                CommunityStats(shown.groupsCount, shown.membersCount, Color.White, Color.White.copy(alpha = 0.8f), Color.White)
                                shown.description?.takeIf { it.isNotBlank() }?.let {
                                    Text(it, color = Color.White.copy(alpha = 0.9f), fontSize = 12.5.sp, textAlign = TextAlign.Center, modifier = Modifier.padding(horizontal = 24.dp, vertical = 4.dp), maxLines = 2, overflow = TextOverflow.Ellipsis)
                                }
                                Spacer(Modifier.height(10.dp))
                            }
                        }
                    }
                    if (hitRes(R.string.cm_announcements, R.string.cm_announcements_sub)) item(key = "announcements") {
                        Column(Modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 4.dp)) {
                            CommunitySettingRow(
                                Icons.Rounded.Campaign,
                                stringResource(R.string.cm_announcements),
                                stringResource(R.string.cm_announcements_sub),
                            ) { host.push(ChRoute.Conversation(shown.announcementId)) }
                        }
                    }
                    if (inside.isNotEmpty()) item(key = "in-title") { SectionTitle(stringResource(R.string.cm_your_groups)) }
                    itemsIndexed(inside.take(if (q.isBlank()) 6 else inside.size), key = { _, g -> "in" + g.id }) { i, g ->
                        val preview = inside.take(if (q.isBlank()) 6 else inside.size)
                        GroupLine(g, shown.isAdmin, index = i, lined = i < preview.lastIndex, onLong = { groupMenu = g }) { host.push(ChRoute.Conversation(g.id)) }
                    }
                    if (q.isBlank() && inside.size > 6) item(key = "in-all") {
                        SeeAllGroups(inside.size) { host.push(ChRoute.CommunityGroups(shown.id, yours = true)) }
                    }
                    if (outside.isNotEmpty()) item(key = "out-title") { SectionTitle(stringResource(R.string.cm_other_groups)) }
                    itemsIndexed(outside.take(if (q.isBlank()) 6 else outside.size), key = { _, g -> "out" + g.id }) { i, g ->
                        val preview = outside.take(if (q.isBlank()) 6 else outside.size)
                        GroupLine(g, shown.isAdmin, index = i, lined = i < preview.lastIndex, onLong = { groupMenu = g }) {
                            if (g.requestPending) return@GroupLine
                            act {
                                val res = ApiClient.chat.joinCommunityGroup(chatAuth(), shown.id, g.id).data
                                if (res?.status == "joined") {
                                    host.refreshList()
                                    host.push(ChRoute.Conversation(g.id))
                                } else {
                                    host.showToast(askedText)
                                }
                                ApiClient.chat.community(chatAuth(), shown.id).data
                            }
                        }
                    }
                    if (q.isBlank() && outside.size > 6) item(key = "out-all") {
                        SeeAllGroups(outside.size) { host.push(ChRoute.CommunityGroups(shown.id, yours = false)) }
                    }
                    if (shown.isAdmin && hitRes(R.string.cm_add_groups, R.string.cm_new_group, R.string.cm_members, R.string.cm_rename)) item(key = "admin") {
                        Column(Modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 4.dp)) {
                            if (hitRes(R.string.cm_add_groups)) SettingRow(Icons.Rounded.AddLink, stringResource(R.string.cm_add_groups)) { adding = true }
                            if (hitRes(R.string.cm_new_group)) SettingRow(Icons.Rounded.GroupAdd, stringResource(R.string.cm_new_group)) { newGroup = true }
                            if (hitRes(R.string.cm_members) || hit(stringResource(R.string.cm_admins_of, shown.adminsCount, shown.maxAdmins))) SettingRow(Icons.Rounded.Groups, stringResource(R.string.cm_members), stringResource(R.string.cm_admins_of, shown.adminsCount, shown.maxAdmins)) { membersOpen = true }
                            if (hitRes(R.string.cm_rename)) SettingRow(Icons.Rounded.Edit, stringResource(R.string.cm_rename), lined = false) { renaming = true }
                        }
                    } else if (!shown.isAdmin && hitRes(R.string.cm_members)) item(key = "members") {
                        Column(Modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 4.dp)) {
                            SettingRow(Icons.Rounded.Groups, stringResource(R.string.cm_members), shown.membersCount.toString(), lined = false) { membersOpen = true }
                        }
                    }
                    if ((shown.isCreator && hitRes(R.string.cm_delete)) || (!shown.isCreator && hitRes(R.string.cm_leave))) item(key = "end") {
                        Column(Modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 8.dp)) {
                            if (shown.isCreator) SettingRow(Icons.Rounded.Delete, stringResource(R.string.cm_delete), danger = true, opens = false, lined = false) { confirmDelete = true }
                            else SettingRow(Icons.AutoMirrored.Rounded.ExitToApp, stringResource(R.string.cm_leave), danger = true, opens = false, lined = false) { confirmLeave = true }
                        }
                    }
                }
            }
        }

        Box(Modifier.fillMaxWidth().graphicsLayer { alpha = headerAlpha }.background(Ch.HeaderBrush).padding(start = 16.dp, end = 96.dp, top = 12.dp, bottom = 12.dp)) {
            Text(shown?.name.orEmpty(), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 16.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        Column(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 8.dp)) {
            if (searching) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    ChField(
                        infoQuery, { infoQuery = it.take(80) }, stringResource(R.string.ch_info_search),
                        icon = Icons.Rounded.Search, clearable = true, modifier = Modifier.weight(1f),
                    )
                    Spacer(Modifier.width(8.dp))
                    DarkHeroButton(Icons.Rounded.Close, { searching = false; infoQuery = "" }, stringResource(R.string.ch_close_sheet))
                }
            } else {
                Row(Modifier.align(Alignment.End), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    DarkHeroButton(Icons.Rounded.Search, { searching = true }, stringResource(R.string.ch_info_search))
                    DarkHeroButton(Icons.AutoMirrored.Rounded.ArrowBack, { host.pop() }, stringResource(R.string.common_back), flip = true)
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
    if (newGroup) NewCommunityGroupSheet(onDismiss = { newGroup = false }) { name, description ->
        act {
            val body = buildMap<String, Any> {
                put("name", name)
                description.takeIf { it.isNotEmpty() }?.let { put("description", it) }
            }
            val fresh = ApiClient.chat.addCommunityGroups(chatAuth(), c.id, body).data
            host.refreshList()
            fresh
        }
    }
    if (renaming) EditCommunitySheet(c, onDismiss = { renaming = false }) { name, description ->
        act {
            ApiClient.chat.updateCommunity(chatAuth(), c.id, mapOf("name" to name, "description" to description)).data
        }
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
    if (confirmDelete) {
        val deleted = stringResource(R.string.cm_delete_done)
        ConfirmDialog(
            icon = Icons.Rounded.Delete,
            title = stringResource(R.string.cm_delete),
            message = stringResource(R.string.cm_delete_sub),
            confirmLabel = stringResource(R.string.ch_delete),
            onConfirm = {
                confirmDelete = false
                host.scope.launch {
                    runCatching { ApiClient.chat.deleteCommunity(chatAuth(), c.id) }
                        .onSuccess {
                            host.showToast(deleted)
                            host.refreshList()
                            host.pop()
                        }
                        .onFailure { e -> e.apiFailure().message?.let { host.showToast(it, error = true) } }
                }
            },
            onDismiss = { confirmDelete = false },
        )
    }
}

@Composable
private fun SectionTitle(text: String) {
    Text(
        text,
        color = Ch.Mut,
        fontWeight = FontWeight.Bold,
        fontSize = 12.5.sp,
        modifier = Modifier.padding(start = 20.dp, end = 20.dp, top = 14.dp, bottom = 6.dp),
    )
}

@Composable
private fun CommunitySettingRow(icon: ImageVector, title: String, subtitle: String, onClick: () -> Unit) {
    Column(Modifier.fillMaxWidth()) {
        Row(Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 14.dp, vertical = 11.dp), verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(32.dp).clip(RoundedCornerShape(10.dp)).background(Ch.Red.copy(alpha = 0.08f)), contentAlignment = Alignment.Center) {
                Icon(icon, null, tint = Ch.Red, modifier = Modifier.size(17.dp))
            }
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(title, color = Ch.Ink, fontWeight = FontWeight.SemiBold, fontSize = 14.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(subtitle, color = Ch.Mut, fontSize = 12.sp, lineHeight = 16.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
            }
            Spacer(Modifier.width(6.dp))
            Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, null, tint = Ch.Soft, modifier = Modifier.size(18.dp))
        }
        HorizontalDivider(Modifier.padding(start = 58.dp, end = 16.dp), color = Ch.Line)
    }
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun GroupLine(g: CommunityGroupDto, admin: Boolean, index: Int = 0, lined: Boolean = true, onLong: () -> Unit, onClick: () -> Unit) {
    Column(Modifier.fillMaxWidth().chStagger(index)) {
        Row(
            Modifier.fillMaxWidth()
                .combinedClickable(onClick = onClick, onLongClick = if (admin) onLong else null)
                .padding(horizontal = 16.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            ChAvatar(g.avatar, g.name, g.id, size = 40.dp, isGroup = true)
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(g.name, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                CommunityStat(Icons.Rounded.Person, g.membersCount, stringResource(R.string.cm_member), Ch.Ink, Ch.Mut, Ch.Red)
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
        if (lined) HorizontalDivider(Modifier.padding(start = 68.dp, end = 16.dp), color = Ch.Line)
    }
}

@Composable
private fun SeeAllGroups(total: Int, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 8.dp)
            .clip(RoundedCornerShape(18.dp))
            .border(1.5.dp, Ch.Red, RoundedCornerShape(18.dp))
            .background(Ch.Red.copy(alpha = 0.1f))
            .clickable(onClick = onClick)
            .padding(vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.Center,
    ) {
        Text(stringResource(R.string.ch_see_all_members, total), color = Ch.Red, fontWeight = FontWeight.ExtraBold, fontSize = 14.sp)
    }
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
fun CommunityGroupsPage(id: String, yours: Boolean) {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var community by remember { mutableStateOf<CommunityDto?>(null) }
    var query by remember { mutableStateOf("") }
    var visibleCount by remember { mutableIntStateOf(15) }
    var groupMenu by remember { mutableStateOf<CommunityGroupDto?>(null) }
    val listState = rememberLazyListState()
    val networkError = stringResource(R.string.ch_error_network)
    val askedText = stringResource(R.string.ch_join_request_sent)

    suspend fun load() {
        community = runCatching { ApiClient.chat.community(chatAuth(), id).data }.getOrNull() ?: community
    }
    LaunchedEffect(id) { load() }
    LaunchedEffect(query) { visibleCount = 15 }

    fun act(block: suspend () -> CommunityDto?) {
        scope.launch {
            try {
                block()?.let { community = it }
            } catch (e: Exception) {
                host.showToast(e.apiFailure().message ?: networkError)
            }
        }
    }

    val c = community
    val groups = remember(c, yours, query) {
        val q = query.trim()
        val source = c?.groups.orEmpty().filter { if (yours) it.isMember else !it.isMember }
        if (q.isBlank()) source else source.filter { it.name.contains(q, ignoreCase = true) }
    }
    val paged = groups.take(visibleCount)
    LaunchedEffect(listState, paged.size, groups.size) {
        snapshotFlow {
            val info = listState.layoutInfo
            val last = info.visibleItemsInfo.lastOrNull()?.index ?: 0
            info.totalItemsCount > 0 && last >= info.totalItemsCount - 3 && visibleCount < groups.size
        }.distinctUntilChanged().filter { it }.collect {
            visibleCount = (visibleCount + 15).coerceAtMost(groups.size)
        }
    }

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize()) {
            SubHeader(stringResource(if (yours) R.string.cm_your_groups else R.string.cm_other_groups)) { host.pop() }
            ChField(
                query, { query = it }, stringResource(R.string.ch_info_search),
                modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp).fillMaxWidth(),
                icon = Icons.Rounded.Search, clearable = true,
            )
            when {
                c == null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(Modifier.size(28.dp), color = Ch.Red, strokeWidth = 2.5.dp)
                }
                groups.isEmpty() -> Text(
                    stringResource(R.string.ch_no_results), color = Ch.Soft, textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth().padding(30.dp),
                )
                else -> LazyColumn(
                    state = listState,
                    contentPadding = PaddingValues(bottom = 24.dp),
                    modifier = Modifier.weight(1f).fillMaxWidth(),
                ) {
                    itemsIndexed(paged, key = { _, g -> g.id }) { i, g ->
                        GroupLine(g, c.isAdmin, index = i, lined = i < paged.lastIndex, onLong = { groupMenu = g }) {
                            if (g.isMember) {
                                host.push(ChRoute.Conversation(g.id))
                                return@GroupLine
                            }
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
                }
            }
        }
    }
    val adminCommunity = c?.takeIf { it.isAdmin }
    groupMenu?.let { g ->
        if (adminCommunity == null) return@let
        ChoiceSheet(g.name, listOf(stringResource(R.string.cm_remove_group) to { act { ApiClient.chat.removeCommunityGroup(chatAuth(), adminCommunity.id, g.id).data } }), danger = true, subtitle = stringResource(R.string.cm_remove_group_sub), onDismiss = { groupMenu = null })
    }
}

/** Bring in more groups I run. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun AddGroupsSheet(onDismiss: () -> Unit, onAdd: (List<String>) -> Unit) {
    val host = LocalChat.current
    val picked = remember { mutableStateListOf<String>() }
    var mine by remember { mutableStateOf<List<ConversationDto>?>(null) }
    LaunchedEffect(Unit) { mine = runCatching { loadFreeGroups() }.getOrNull() ?: myFreeGroups(host) }
    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Bg, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().imePadding().heightIn(max = 660.dp).verticalScroll(rememberScrollState()).padding(horizontal = 16.dp).padding(bottom = 26.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                IconBadge(Icons.Rounded.AddLink, Ch.Red)
                Spacer(Modifier.width(12.dp))
                Column {
                    Text(stringResource(R.string.cm_add_groups), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
                    Text(stringResource(R.string.cm_pick_groups), color = Ch.Mut, fontSize = 12.5.sp)
                }
            }
            Spacer(Modifier.height(14.dp))
            GroupPicker(mine, picked, showTitle = false)
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

    fun act(done: String? = null, block: suspend () -> Unit) {
        scope.launch {
            try {
                block()
                load()
                onChanged()
                if (done != null) host.showToast(done)
            } catch (e: Exception) {
                host.showToast(e.apiFailure().message ?: networkError)
            }
        }
    }

    var query by remember { mutableStateOf("") }
    val filtered = remember(members, query) {
        val q = query.trim()
        members.orEmpty().filter { m ->
            q.isBlank() || m.profile?.name.orEmpty().contains(q, ignoreCase = true)
        }
    }

    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Bg, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 16.dp).padding(bottom = 26.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                IconBadge(Icons.Rounded.Groups, Ch.Red)
                Spacer(Modifier.width(12.dp))
                Column {
                    Text(stringResource(R.string.cm_members), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
                    Text(stringResource(R.string.cm_admins_of, c.adminsCount, c.maxAdmins), color = Ch.Mut, fontSize = 12.5.sp)
                }
            }
            Spacer(Modifier.height(12.dp))
            ChField(
                query, { query = it }, stringResource(R.string.ch_search_contacts),
                icon = Icons.Rounded.Search, clearable = true, modifier = Modifier.fillMaxWidth(),
            )
            Spacer(Modifier.height(12.dp))
            val list = members
            if (list == null) {
                Box(Modifier.fillMaxWidth().height(120.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp) }
            } else if (filtered.isEmpty()) {
                Text(stringResource(R.string.ch_no_results), color = Ch.Soft, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(24.dp))
            } else {
                LazyColumn(Modifier.heightIn(max = 480.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    itemsIndexed(filtered, key = { _, m -> m.participantId }) { i, m ->
                        val canManage = c.isAdmin && m.role != "owner" && m.profile?.isMe != true
                        CommunityMemberCard(m, i, canManage) { menu = m }
                    }
                }
            }
        }
    }

    menu?.let { m ->
        CommunityMemberActionsSheet(
            community = c,
            member = m,
            onDismiss = { menu = null },
            onAct = { done, block -> act(done, block) },
        )
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun CommunityMemberActionsSheet(
    community: CommunityDto,
    member: MemberDto,
    onDismiss: () -> Unit,
    onAct: (String?, suspend () -> Unit) -> Unit,
) {
    val name = member.profile?.name.orEmpty()
    val phone = member.profile?.phone
    val makeAdmin = member.role != "admin"
    var confirmRemove by remember { mutableStateOf(false) }
    val removed = stringResource(R.string.cm_member_removed, name)
    val roleDone = stringResource(if (makeAdmin) R.string.cm_member_now_admin else R.string.cm_member_admin_dismissed, name)

    if (confirmRemove) {
        ConfirmDialog(
            icon = Icons.Rounded.PersonRemove,
            title = stringResource(R.string.cm_remove_member),
            message = stringResource(R.string.cm_confirm_remove_member, name),
            confirmLabel = stringResource(R.string.ch_delete),
            onConfirm = {
                confirmRemove = false
                onDismiss()
                onAct(removed) { ApiClient.chat.removeCommunityMember(chatAuth(), community.id, member.participantId) }
            },
            onDismiss = { confirmRemove = false },
        )
        return
    }

    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(
            Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 20.dp).padding(bottom = 24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            ChAvatar(member.profile?.avatar, name, member.profile?.key, size = 72.dp)
            Spacer(Modifier.height(10.dp))
            Text(name, color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp, textAlign = TextAlign.Center, maxLines = 1, overflow = TextOverflow.Ellipsis)
            if (!phone.isNullOrBlank()) {
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    Text(ltrNumber(phone), color = Ch.Mut, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
                }
            }
            Spacer(Modifier.height(16.dp))
            HorizontalDivider(color = Ch.Line)
            CommunityMemberActionRow(
                if (makeAdmin) Icons.Rounded.AddModerator else Icons.Rounded.RemoveModerator,
                stringResource(if (makeAdmin) R.string.cm_make_admin else R.string.cm_unmake_admin),
            ) {
                onDismiss()
                onAct(roleDone) { ApiClient.chat.communityRole(chatAuth(), community.id, member.participantId, mapOf("role" to if (makeAdmin) "admin" else "member")) }
            }
            HorizontalDivider(color = Ch.Line)
            CommunityMemberActionRow(Icons.Rounded.PersonRemove, stringResource(R.string.cm_remove_member), danger = true) { confirmRemove = true }
            HorizontalDivider(color = Ch.Line)
            Box(
                Modifier.fillMaxWidth().padding(top = 14.dp).clip(RoundedCornerShape(16.dp)).background(Ch.SurfaceMuted).clickable(onClick = onDismiss).padding(vertical = 14.dp),
                contentAlignment = Alignment.Center,
            ) {
                Text(stringResource(R.string.common_cancel), color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp)
            }
        }
    }
}

@Composable
private fun CommunityMemberActionRow(icon: ImageVector, label: String, danger: Boolean = false, onClick: () -> Unit) {
    val tint = if (danger) Ch.Danger else Ch.Red
    Row(
        Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 4.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(34.dp).clip(RoundedCornerShape(10.dp)).background(tint.copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = tint, modifier = Modifier.size(18.dp))
        }
        Spacer(Modifier.width(12.dp))
        Text(label, color = if (danger) Ch.Danger else Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp, modifier = Modifier.weight(1f))
    }
}

@Composable
private fun CommunityMemberCard(m: MemberDto, index: Int, canManage: Boolean, onClick: () -> Unit) {
    val host = LocalChat.current
    val name = if (m.profile?.isMe == true) stringResource(R.string.ch_you) else m.profile?.name.orEmpty()
    val phone = m.profile?.phone
    Row(
        Modifier.fillMaxWidth().chStagger(index)
            .shadow(3.dp, RoundedCornerShape(18.dp), spotColor = Color.Black.copy(alpha = 0.06f))
            .clip(RoundedCornerShape(18.dp))
            .background(Ch.Surface)
            .clickable(enabled = canManage, onClick = onClick)
            .padding(horizontal = 12.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        ChAvatar(m.profile?.avatar, m.profile?.name, m.profile?.key, size = 46.dp, online = host.presence[m.profile?.key]?.online == true)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            com.dorr.app.ui.components.VerifiedName(name, verified = m.profile?.isVerified == true, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp)
            if (!phone.isNullOrBlank()) {
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    Text(ltrNumber(phone), color = Ch.Mut, fontSize = 12.5.sp)
                }
            }
        }
        when (m.role) {
            "owner" -> RoleTag(stringResource(R.string.cm_creator))
            "admin" -> RoleTag(stringResource(R.string.cm_admin))
        }
    }
}

@Composable
private fun RoleTag(text: String) {
    Text(
        text,
        color = Ch.Red,
        fontSize = 11.5.sp,
        fontWeight = FontWeight.ExtraBold,
        modifier = Modifier.clip(RoundedCornerShape(10.dp)).background(Ch.Red.copy(alpha = 0.1f)).padding(horizontal = 8.dp, vertical = 3.dp),
    )
}