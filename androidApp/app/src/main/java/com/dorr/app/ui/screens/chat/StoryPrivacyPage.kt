package com.dorr.app.ui.screens.chat

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
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
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ContactDto
import com.dorr.app.network.StoryPrivacyDto
import com.dorr.app.ui.screens.profile.PinkBackdrop
import com.dorr.app.ui.screens.profile.SubHeader
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.material.icons.rounded.Group
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.snapshotFlow
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.filter
import androidx.compose.material.icons.rounded.Person
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import kotlinx.coroutines.launch

@Composable
fun StoryPrivacyPage() {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var privacy by remember { mutableStateOf<StoryPrivacyDto?>(null) }
    var picking by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(Unit) { privacy = runCatching { ApiClient.chat.storyPrivacy(chatAuth()).data }.getOrNull() }

    fun save(audience: String, except: List<Int>? = null, only: List<Int>? = null) = scope.launch {
        val body = buildMap<String, Any> {
            put("audience", audience)
            except?.let { put("except", it) }
            only?.let { put("only", it) }
        }
        runCatching { ApiClient.chat.updateStoryPrivacy(chatAuth(), body).data }.getOrNull()?.let { privacy = it }
    }

    // The app's inner-screen look (as «Starred messages»): the soft backdrop and the title with back.
    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize()) {
        SubHeader(stringResource(R.string.st_privacy)) { host.pop() }
        val p = privacy
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 14.dp).padding(bottom = 14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            // First, highlighted: a change applies to new statuses only.
            val noteShape = RoundedCornerShape(16.dp)
            Row(
                Modifier.fillMaxWidth().clip(noteShape).background(Ch.Red.copy(alpha = 0.1f)).border(1.dp, Ch.Red.copy(alpha = 0.25f), noteShape).padding(14.dp),
                verticalAlignment = Alignment.Top,
            ) {
                Icon(Icons.Rounded.Info, null, tint = Ch.Red, modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(10.dp))
                Text(stringResource(R.string.st_privacy_note), color = Ch.Ink, fontSize = 13.sp, lineHeight = 19.sp)
            }
            // The question the choices answer.
            Text(
                stringResource(R.string.st_privacy_who), color = Ch.Mut, fontSize = 13.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.padding(start = 6.dp, end = 6.dp, top = 8.dp),
            )
            listOf(
                Triple("contacts", R.string.st_audience_contacts, R.string.st_audience_contacts_sub),
                Triple("except", R.string.st_audience_except, R.string.st_audience_except_sub),
                Triple("only", R.string.st_audience_only, R.string.st_audience_only_sub),
            ).forEach { (value, label, sub) ->
                val count = when (value) { "except" -> p?.except?.size; "only" -> p?.only?.size; else -> null }
                AudienceOption(
                    label = stringResource(label),
                    sub = stringResource(sub),
                    count = count?.takeIf { it > 0 },
                    selected = p?.audience == value,
                ) {
                    if (value == "contacts") save("contacts") else picking = value
                }
            }
        }
        }
    }

    picking?.let { list ->
        val preselected = (if (list == "except") privacy?.except else privacy?.only).orEmpty().map { it.id }
        PeoplePicker(preselected, onDismiss = { picking = null }) { ids ->
            picking = null
            if (list == "except") save("except", except = ids) else save("only", only = ids)
        }
    }
}

/**
 * One choice as its own card: its name and, under it, what it means; the people picked for it beside the name
 * (👤 N); the radio at the other end.
 */
@Composable
private fun AudienceOption(label: String, sub: String, count: Int?, selected: Boolean, onClick: () -> Unit) {
    val ring by animateColorAsState(if (selected) Ch.Red else Ch.Soft, label = "radio")
    val edge by animateColorAsState(if (selected) Ch.Red.copy(alpha = 0.45f) else Color.Transparent, label = "edge")
    val shape = RoundedCornerShape(18.dp)
    Row(
        Modifier.fillMaxWidth().clip(shape).background(Ch.Surface).border(1.5.dp, edge, shape).clickable(onClick = onClick).padding(horizontal = 16.dp, vertical = 15.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(label, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp)
                if (count != null) {
                    Spacer(Modifier.width(8.dp))
                    val described = stringResource(R.string.st_people_selected, count)
                    Row(
                        Modifier.clip(RoundedCornerShape(10.dp)).background(Ch.Red.copy(alpha = 0.12f)).padding(horizontal = 7.dp, vertical = 2.dp)
                            .semantics(mergeDescendants = true) { contentDescription = described },
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(Icons.Rounded.Person, null, tint = Ch.Red, modifier = Modifier.size(14.dp))
                        Spacer(Modifier.width(3.dp))
                        Text("$count", color = Ch.Red, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                    }
                }
            }
            Text(sub, color = Ch.Mut, fontSize = 12.5.sp)
        }
        Spacer(Modifier.width(12.dp))
        Box(Modifier.size(24.dp).border(2.dp, ring, CircleShape), contentAlignment = Alignment.Center) {
            AnimatedContent(selected, label = "dot", transitionSpec = { scaleIn(spring(dampingRatio = 0.4f)) togetherWith scaleOut() }) { on ->
                if (on) Box(Modifier.size(12.dp).background(Ch.Red, CircleShape)) else Spacer(Modifier.size(12.dp))
            }
        }
    }
}

/**
 * Pick people (my contacts on Dorr): a search, pages of 15 that load as I scroll (as the other pickers), a tick per
 * person — kept while I search — and «Done» across the bottom.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun PeoplePicker(preselected: List<Int>, onDismiss: () -> Unit, onDone: (List<Int>) -> Unit) {
    val scope = rememberCoroutineScope()
    val listState = rememberLazyListState()
    var query by remember { mutableStateOf("") }
    var contacts by remember { mutableStateOf<List<ContactDto>?>(null) }
    var page by remember { mutableIntStateOf(1) }
    var hasMore by remember { mutableStateOf(false) }
    var loadingMore by remember { mutableStateOf(false) }
    val selected = remember { mutableStateListOf<Int>().apply { addAll(preselected) } }

    suspend fun loadPage(reset: Boolean) {
        if (!reset && (loadingMore || !hasMore)) return
        if (!reset) loadingMore = true
        val next = if (reset) 1 else page + 1
        val envelope = runCatching {
            ApiClient.chat.contacts(chatAuth(), registered = 1, q = query.trim().ifBlank { null }, page = next, perPage = 15)
        }.getOrNull()
        val chunk = envelope?.data.orEmpty().filter { it.profile != null }
        contacts = if (reset) chunk else {
            val known = contacts.orEmpty().map { it.id }.toSet()
            contacts.orEmpty() + chunk.filter { it.id !in known }
        }
        page = next
        hasMore = envelope?.pagination?.hasMorePages == true
        if (!reset) loadingMore = false
    }

    LaunchedEffect(query) {
        if (query.isNotEmpty()) delay(280)
        loadPage(reset = true)
    }
    // Near the end of the list → the next 15.
    LaunchedEffect(listState, contacts?.size, hasMore) {
        snapshotFlow {
            val info = listState.layoutInfo
            val last = info.visibleItemsInfo.lastOrNull()?.index ?: -1
            info.totalItemsCount > 0 && last >= info.totalItemsCount - 3
        }.distinctUntilChanged().filter { it }.collect { scope.launch { loadPage(reset = false) } }
    }

    ChSheet(
        onDismissRequest = onDismiss,
        sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true),
        containerColor = Ch.Surface,
        shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp),
    ) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 18.dp).padding(bottom = 20.dp)) {
            // The title with its icon · how many are picked, at the other end.
            Row(verticalAlignment = Alignment.CenterVertically) {
                IconBadge(Icons.Rounded.Group, Ch.Red, size = 38)
                Spacer(Modifier.width(10.dp))
                Text(stringResource(R.string.st_choose_people), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                Text(
                    stringResource(R.string.st_people_selected, selected.size),
                    color = if (selected.isEmpty()) Ch.Mut else Ch.Red, fontSize = 12.5.sp, fontWeight = FontWeight.Bold,
                    modifier = Modifier.clip(RoundedCornerShape(12.dp)).background((if (selected.isEmpty()) Ch.Mut else Ch.Red).copy(alpha = 0.12f))
                        .padding(horizontal = 10.dp, vertical = 5.dp),
                )
            }
            Spacer(Modifier.height(12.dp))
            ChField(query, { query = it.take(80) }, stringResource(R.string.ch_search_contacts), icon = Icons.Rounded.Search, clearable = true)
            Spacer(Modifier.height(8.dp))
            val list = contacts
            when {
                list == null -> com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().height(380.dp))
                list.isEmpty() -> Box(Modifier.fillMaxWidth().height(160.dp), contentAlignment = Alignment.Center) {
                    Text(stringResource(R.string.ch_no_results), color = Ch.Mut, fontSize = 13.5.sp)
                }
                else -> LazyColumn(Modifier.fillMaxWidth().height(380.dp), state = listState) {
                    itemsIndexed(list, key = { _, c -> c.id }) { i, c ->
                        val id = c.profile?.id ?: return@itemsIndexed
                        val on = id in selected
                        Row(
                            Modifier.fillMaxWidth().chStagger(i.coerceAtMost(14)).clip(RoundedCornerShape(16.dp))
                                .clickable { if (on) selected.remove(id) else selected.add(id) }.padding(vertical = 8.dp, horizontal = 6.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            ChAvatar(c.profile.avatar, c.name, c.profile.key, size = 44.dp)
                            Spacer(Modifier.width(12.dp))
                            Text(ltrTitle(c.name), color = Ch.Ink, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f), maxLines = 1)
                            AnimatedContent(on, label = "pick", transitionSpec = { scaleIn(spring(dampingRatio = 0.4f)) togetherWith scaleOut() }) { checked ->
                                Icon(if (checked) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (checked) Ch.Red else Ch.Soft, modifier = Modifier.size(26.dp))
                            }
                        }
                    }
                    if (loadingMore) item {
                        Box(Modifier.fillMaxWidth().padding(12.dp), contentAlignment = Alignment.Center) {
                            CircularProgressIndicator(color = Ch.Red, strokeWidth = 2.dp, modifier = Modifier.size(22.dp))
                        }
                    }
                }
            }
            Spacer(Modifier.height(12.dp))
            // «Done», across the whole width.
            ChPrimaryButton(stringResource(R.string.st_done), modifier = Modifier.fillMaxWidth()) { onDone(selected.toList()) }
        }
    }
}
