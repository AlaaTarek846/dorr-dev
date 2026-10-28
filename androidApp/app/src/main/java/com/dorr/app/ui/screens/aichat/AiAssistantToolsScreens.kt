package com.dorr.app.ui.screens.aichat

import android.content.Intent
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
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
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.ArrowBackIosNew
import androidx.compose.material.icons.rounded.AudioFile
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.DeleteOutline
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.Movie
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CheckboxDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.AiCapsuleDto
import com.dorr.app.network.AiContinueRequestDto
import com.dorr.app.network.AiConversationDto
import com.dorr.app.network.AiMemoryDto
import com.dorr.app.network.AiMemoryRequestDto
import com.dorr.app.network.AiMemorySettingsDto
import com.dorr.app.network.AiOutputDto
import com.dorr.app.network.AiProjectDto
import com.dorr.app.network.AiProjectRequestDto
import com.dorr.app.network.AiSearchHitDto
import com.dorr.app.network.ApiClient
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.chat.chatAuth
import com.dorr.app.ui.screens.chat.timeAgo
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/*
 * DORR AI's own tools (2026-10-14), each one screen inside AiChatHost: search the history by word
 * and by meaning (spec 133–139), memory (140–144), the outputs gallery (344), projects (151) and
 * "continue in a new chat" with the carried-over context it shows in the conversation (121–132).
 */

private class AiPalette(night: Boolean) {
    val bg = if (night) Ai.bgDark else Ai.bgLight
    val ink = if (night) Ai.inkDark else Ai.inkLight
    val mut = if (night) Ai.mutDark else Ai.mutLight
    val surface = if (night) Ai.surfaceDark else Ai.surfaceLight
    val line = if (night) Ai.lineDark else Ai.lineLight
}

@Composable
private fun AiToolHeader(title: String, ink: Color, onBack: () -> Unit, trailing: @Composable () -> Unit = {}) {
    Row(
        Modifier.fillMaxWidth().statusBarsPadding().padding(horizontal = 8.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(38.dp).clip(CircleShape).clickable(onClick = onBack), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.ArrowBackIosNew, contentDescription = null, tint = ink, modifier = Modifier.size(18.dp))
        }
        Spacer(Modifier.width(8.dp))
        Text(title, color = ink, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp, modifier = Modifier.weight(1f))
        trailing()
    }
}

@Composable
private fun AiToolNotice(text: String, mut: Color) {
    Box(Modifier.fillMaxSize().padding(32.dp), contentAlignment = Alignment.Center) {
        Text(text, color = mut, fontSize = 13.5.sp, textAlign = androidx.compose.ui.text.style.TextAlign.Center)
    }
}

@Composable
private fun AiToolLoading() {
    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        CircularProgressIndicator(color = Ai.Red, strokeWidth = 2.5.dp, modifier = Modifier.size(28.dp))
    }
}

@Composable
private fun aiFieldColors(p: AiPalette) = OutlinedTextFieldDefaults.colors(
    focusedBorderColor = Ai.Red, unfocusedBorderColor = p.line, cursorColor = Ai.Red,
    focusedTextColor = p.ink, unfocusedTextColor = p.ink, focusedContainerColor = p.surface, unfocusedContainerColor = p.surface,
)

// ==================================================================== search (spec 133–139)

@Composable
internal fun AiSearchScreen(night: Boolean, onExit: () -> Unit, onOpen: (conversationId: Int, messageId: Int?) -> Unit) {
    val p = AiPalette(night)
    var query by rememberSaveable { mutableStateOf("") }
    var results by remember { mutableStateOf<List<AiSearchHitDto>?>(null) }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    BackHandler { onExit() }

    // Typing pauses for a moment before searching — each search may ask the AI what the words mean.
    LaunchedEffect(query) {
        val q = query.trim()
        if (q.length < 2) {
            results = null
            return@LaunchedEffect
        }
        delay(450)
        loading = true
        error = null
        runCatching { ApiClient.aiAssistant.search(chatAuth(), q).data?.results.orEmpty() }
            .onSuccess { results = it }
            .onFailure { error = it.apiFailure().message }
        loading = false
    }

    Column(Modifier.fillMaxSize().background(p.bg)) {
        AiToolHeader(stringResource(R.string.ai_tools_search_title), p.ink, onExit)
        OutlinedTextField(
            value = query,
            onValueChange = { query = it.take(100) },
            singleLine = true,
            placeholder = { Text(stringResource(R.string.ai_tools_search_hint), color = p.mut, fontSize = 14.sp) },
            leadingIcon = { Icon(Icons.Rounded.Search, contentDescription = null, tint = p.mut) },
            shape = RoundedCornerShape(16.dp),
            colors = aiFieldColors(p),
            modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 4.dp),
        )
        when {
            loading && results == null -> AiToolLoading()
            error != null -> AiToolNotice(error ?: "", p.mut)
            results?.isEmpty() == true -> AiToolNotice(stringResource(R.string.ai_tools_search_empty), p.mut)
            else -> LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(vertical = 8.dp)) {
                items(results.orEmpty(), key = { "${it.type}:${it.conversation.id}:${it.messageId}:${it.term}" }) { hit ->
                    AiSearchHitRow(hit, p) { onOpen(hit.conversation.id, hit.messageId) }
                }
            }
        }
    }
}

@Composable
private fun AiSearchHitRow(hit: AiSearchHitDto, p: AiPalette, onClick: () -> Unit) {
    val red = Ai.Red
    // The matched word, highlighted where the server says it is (spec 136).
    val snippet: AnnotatedString = remember(hit) {
        buildAnnotatedString {
            append(hit.snippet)
            hit.highlights.forEach { h ->
                val end = (h.start + h.length).coerceAtMost(hit.snippet.length)
                if (h.start in 0 until end) addStyle(SpanStyle(background = red.copy(alpha = 0.22f), fontWeight = FontWeight.Bold), h.start, end)
            }
        }
    }
    val where = when (hit.type) {
        "title" -> stringResource(R.string.ai_tools_search_in_title)
        "file" -> stringResource(R.string.ai_tools_search_in_file)
        "project" -> stringResource(R.string.ai_tools_search_in_project, hit.project?.name ?: "")
        else -> null
    }
    Column(Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 16.dp, vertical = 10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(hit.conversation.title?.takeIf { it.isNotBlank() } ?: stringResource(R.string.ai_new_chat), color = p.ink, fontWeight = FontWeight.Bold, fontSize = 14.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
            Text(timeAgo(hit.createdAt), color = p.mut, fontSize = 11.sp)
        }
        Text(snippet, color = p.ink, fontSize = 13.sp, maxLines = 3, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 3.dp))
        Row(Modifier.padding(top = 4.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            if (hit.match == "semantic") AiChip(stringResource(R.string.ai_tools_search_by_meaning, hit.term ?: ""), p)
            if (where != null) AiChip(where, p)
        }
    }
}

@Composable
private fun AiChip(text: String, p: AiPalette, selected: Boolean = false, onClick: (() -> Unit)? = null) {
    Box(
        Modifier.clip(RoundedCornerShape(10.dp))
            .background(if (selected) Ai.Red else Ai.Red.copy(alpha = 0.10f))
            .then(if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier)
            .padding(horizontal = 8.dp, vertical = 3.dp),
    ) { Text(text, color = if (selected) Color.White else Ai.Red, fontSize = 11.sp, fontWeight = FontWeight.SemiBold) }
}

// ==================================================================== memory (spec 140–144)

@Composable
internal fun AiMemoryScreen(night: Boolean, onExit: () -> Unit) {
    val p = AiPalette(night)
    val scope = rememberCoroutineScope()
    var enabled by remember { mutableStateOf(true) }
    val memories = remember { mutableStateListOf<AiMemoryDto>() }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var draft by remember { mutableStateOf("") }
    var editing by remember { mutableStateOf<AiMemoryDto?>(null) }
    var confirmDeleteAll by remember { mutableStateOf(false) }

    BackHandler { onExit() }

    LaunchedEffect(Unit) {
        runCatching { ApiClient.aiAssistant.memories(chatAuth()).data }
            .onSuccess { it?.let { m -> enabled = m.enabled; memories.clear(); memories.addAll(m.memories) } }
            .onFailure { error = it.apiFailure().message }
        loading = false
    }

    fun save() {
        val text = draft.trim()
        if (text.isEmpty()) return
        val target = editing
        scope.launch {
            error = null
            runCatching {
                if (target == null) ApiClient.aiAssistant.addMemory(chatAuth(), AiMemoryRequestDto(text)).data
                else ApiClient.aiAssistant.updateMemory(chatAuth(), target.id, AiMemoryRequestDto(text)).data
            }.onSuccess { saved ->
                if (saved != null) {
                    val at = memories.indexOfFirst { it.id == saved.id }
                    if (at >= 0) memories[at] = saved else memories.add(0, saved)
                }
                draft = ""
                editing = null
            }.onFailure { error = it.apiFailure().message }
        }
    }

    Column(Modifier.fillMaxSize().background(p.bg)) {
        AiToolHeader(stringResource(R.string.ai_tools_memory_title), p.ink, onExit) {
            if (memories.isNotEmpty()) {
                Text(
                    stringResource(R.string.ai_tools_memory_delete_all), color = Color(0xFFDC2626), fontWeight = FontWeight.Bold, fontSize = 12.5.sp,
                    modifier = Modifier.clip(RoundedCornerShape(10.dp)).clickable { confirmDeleteAll = true }.padding(8.dp),
                )
            }
        }
        // Memory on/off (spec 143).
        Row(
            Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 4.dp).clip(RoundedCornerShape(16.dp)).background(p.surface).padding(14.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.ai_tools_memory_toggle), color = p.ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                Text(stringResource(R.string.ai_tools_memory_toggle_sub), color = p.mut, fontSize = 11.5.sp)
            }
            Switch(
                checked = enabled,
                onCheckedChange = { next ->
                    enabled = next
                    scope.launch { runCatching { ApiClient.aiAssistant.memorySettings(chatAuth(), AiMemorySettingsDto(next)) }.onFailure { enabled = !next; error = it.apiFailure().message } }
                },
                colors = SwitchDefaults.colors(checkedTrackColor = Ai.Red),
            )
        }
        if (enabled) {
            Row(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                OutlinedTextField(
                    value = draft,
                    onValueChange = { draft = it.take(500) },
                    placeholder = { Text(stringResource(R.string.ai_tools_memory_hint), color = p.mut, fontSize = 13.sp) },
                    shape = RoundedCornerShape(14.dp),
                    colors = aiFieldColors(p),
                    modifier = Modifier.weight(1f),
                )
                Spacer(Modifier.width(8.dp))
                Box(
                    Modifier.clip(RoundedCornerShape(12.dp)).background(Ai.Red).clickable { save() }.padding(horizontal = 14.dp, vertical = 12.dp),
                ) { Text(stringResource(if (editing == null) R.string.ai_tools_add else R.string.ai_tools_save), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 13.sp) }
            }
        }
        error?.let { Text(it, color = Color(0xFFB91C1C), fontSize = 12.sp, modifier = Modifier.padding(horizontal = 20.dp, vertical = 2.dp)) }
        when {
            loading -> AiToolLoading()
            memories.isEmpty() -> AiToolNotice(stringResource(R.string.ai_tools_memory_empty), p.mut)
            else -> LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(horizontal = 16.dp, vertical = 6.dp)) {
                items(memories, key = { it.id }) { memory ->
                    Row(
                        Modifier.fillMaxWidth().padding(vertical = 4.dp).clip(RoundedCornerShape(14.dp)).background(p.surface).padding(horizontal = 14.dp, vertical = 10.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(memory.content, color = p.ink, fontSize = 13.5.sp, modifier = Modifier.weight(1f))
                        Box(Modifier.size(32.dp).clip(CircleShape).clickable { editing = memory; draft = memory.content }, contentAlignment = Alignment.Center) {
                            Icon(Icons.Rounded.Edit, contentDescription = stringResource(R.string.ai_tools_edit), tint = p.mut, modifier = Modifier.size(17.dp))
                        }
                        Box(
                            Modifier.size(32.dp).clip(CircleShape).clickable {
                                scope.launch { runCatching { ApiClient.aiAssistant.deleteMemory(chatAuth(), memory.id) }.onSuccess { memories.removeAll { it.id == memory.id } } }
                            },
                            contentAlignment = Alignment.Center,
                        ) { Icon(Icons.Rounded.DeleteOutline, contentDescription = stringResource(R.string.ai_delete), tint = p.mut, modifier = Modifier.size(18.dp)) }
                    }
                }
            }
        }
    }

    if (confirmDeleteAll) {
        AiConfirmDialog(
            night = night,
            title = stringResource(R.string.ai_tools_memory_delete_all),
            message = stringResource(R.string.ai_tools_memory_delete_all_confirm),
            onConfirm = {
                confirmDeleteAll = false
                scope.launch { runCatching { ApiClient.aiAssistant.deleteAllMemories(chatAuth()) }.onSuccess { memories.clear() } }
            },
            onDismiss = { confirmDeleteAll = false },
        )
    }
}

// ==================================================================== outputs gallery (spec 344)

@Composable
internal fun AiOutputsScreen(night: Boolean, onExit: () -> Unit, onOpenConversation: (conversationId: Int, messageId: Int?) -> Unit) {
    val p = AiPalette(night)
    val context = LocalContext.current
    var kind by rememberSaveable { mutableStateOf<String?>(null) }
    var items by remember { mutableStateOf<List<AiOutputDto>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }

    BackHandler { onExit() }

    LaunchedEffect(kind) {
        error = null
        runCatching { ApiClient.aiAssistant.outputs(chatAuth(), kind).data.orEmpty() }
            .onSuccess { items = it }
            .onFailure { error = it.apiFailure().message }
    }

    Column(Modifier.fillMaxSize().background(p.bg)) {
        AiToolHeader(stringResource(R.string.ai_tools_outputs_title), p.ink, onExit)
        Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(horizontal = 16.dp, vertical = 4.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            listOf(null to R.string.ai_tools_kind_all, "image" to R.string.ai_tools_kind_image, "video" to R.string.ai_tools_kind_video, "audio" to R.string.ai_tools_kind_audio, "document" to R.string.ai_tools_kind_document)
                .forEach { (value, label) -> AiChip(stringResource(label), p, selected = kind == value) { kind = value } }
        }
        when {
            error != null -> AiToolNotice(error ?: "", p.mut)
            items == null -> AiToolLoading()
            items?.isEmpty() == true -> AiToolNotice(stringResource(R.string.ai_tools_outputs_empty), p.mut)
            else -> LazyVerticalGrid(
                columns = GridCells.Fixed(2),
                modifier = Modifier.fillMaxSize(),
                contentPadding = PaddingValues(12.dp),
                horizontalArrangement = Arrangement.spacedBy(10.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                items(items.orEmpty(), key = { "${it.kind}:${it.messageId}:${it.url}" }) { output ->
                    Column(
                        Modifier.clip(RoundedCornerShape(14.dp)).background(p.surface).clickable {
                            output.url?.let { url -> runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(ApiClient.mediaUrl(url)))) } }
                        },
                    ) {
                        Box(Modifier.fillMaxWidth().aspectRatio(1f).background(Ai.Red.copy(alpha = 0.06f)), contentAlignment = Alignment.Center) {
                            if (output.kind == "image" && output.url != null) {
                                AsyncImage(model = ApiClient.mediaUrl(output.url), contentDescription = output.name, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                            } else {
                                val icon = when (output.kind) { "video" -> Icons.Rounded.Movie; "audio" -> Icons.Rounded.AudioFile; else -> Icons.Rounded.Description }
                                Icon(icon, contentDescription = null, tint = Ai.Red, modifier = Modifier.size(36.dp))
                            }
                        }
                        Text(output.name ?: "", color = p.ink, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(horizontal = 8.dp, vertical = 6.dp))
                        output.conversation?.let { conv ->
                            Text(
                                conv.title?.takeIf { it.isNotBlank() } ?: stringResource(R.string.ai_new_chat), color = Ai.Red, fontSize = 11.sp, maxLines = 1, overflow = TextOverflow.Ellipsis,
                                modifier = Modifier.clickable { onOpenConversation(conv.id, output.messageId) }.padding(start = 8.dp, end = 8.dp, bottom = 8.dp),
                            )
                        }
                    }
                }
            }
        }
    }
}

// ==================================================================== projects (spec 151)

@Composable
internal fun AiProjectsScreen(night: Boolean, conversations: List<AiConversationDto>, onExit: () -> Unit, onOpenConversation: (Int) -> Unit) {
    val p = AiPalette(night)
    val scope = rememberCoroutineScope()
    var projects by remember { mutableStateOf<List<AiProjectDto>?>(null) }
    var open by remember { mutableStateOf<AiProjectDto?>(null) }
    var editing by remember { mutableStateOf<AiProjectDto?>(null) }
    var creating by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    suspend fun reload() {
        runCatching { ApiClient.aiAssistant.projects(chatAuth()).data.orEmpty() }.onSuccess { projects = it }.onFailure { error = it.apiFailure().message }
    }
    suspend fun reopen(id: Int) {
        runCatching { ApiClient.aiAssistant.project(chatAuth(), id).data }.onSuccess { open = it }
    }

    BackHandler { if (open != null) open = null else onExit() }
    LaunchedEffect(Unit) { reload() }

    Column(Modifier.fillMaxSize().background(p.bg)) {
        val current = open
        AiToolHeader(current?.name ?: stringResource(R.string.ai_tools_projects_title), p.ink, onBack = { if (open != null) open = null else onExit() }) {
            Box(
                Modifier.size(38.dp).clip(CircleShape).background(Ai.Red.copy(alpha = 0.12f)).clickable { if (current != null) editing = current else creating = true },
                contentAlignment = Alignment.Center,
            ) { Icon(if (current != null) Icons.Rounded.Edit else Icons.Rounded.Add, contentDescription = null, tint = Ai.Red, modifier = Modifier.size(19.dp)) }
        }
        error?.let { Text(it, color = Color(0xFFB91C1C), fontSize = 12.sp, modifier = Modifier.padding(horizontal = 20.dp)) }

        if (current == null) {
            when {
                projects == null -> AiToolLoading()
                projects?.isEmpty() == true -> AiToolNotice(stringResource(R.string.ai_tools_projects_empty), p.mut)
                else -> LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(horizontal = 16.dp, vertical = 6.dp)) {
                    items(projects.orEmpty(), key = { it.id }) { project ->
                        Column(
                            Modifier.fillMaxWidth().padding(vertical = 4.dp).clip(RoundedCornerShape(16.dp)).background(p.surface)
                                .clickable { scope.launch { reopen(project.id) } }.padding(14.dp),
                        ) {
                            Text(project.name, color = p.ink, fontWeight = FontWeight.Bold, fontSize = 14.5.sp)
                            project.description?.takeIf { it.isNotBlank() }?.let { Text(it, color = p.mut, fontSize = 12.sp, maxLines = 2, overflow = TextOverflow.Ellipsis) }
                            Text(stringResource(R.string.ai_tools_project_chats, project.conversationsCount ?: 0), color = Ai.Red, fontSize = 11.5.sp, modifier = Modifier.padding(top = 4.dp))
                        }
                    }
                }
            }
        } else {
            // One project: its instructions and conversations; add any of the person's chats.
            var picking by remember(current.id) { mutableStateOf(false) }
            LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(horizontal = 16.dp, vertical = 6.dp)) {
                current.instructions?.takeIf { it.isNotBlank() }?.let { text ->
                    item { Text(text, color = p.mut, fontSize = 12.5.sp, modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(p.surface).padding(12.dp)) }
                }
                items(current.conversations.orEmpty(), key = { it.id }) { conv ->
                    Row(
                        Modifier.fillMaxWidth().padding(vertical = 3.dp).clip(RoundedCornerShape(14.dp)).background(p.surface).clickable { onOpenConversation(conv.id) }.padding(horizontal = 14.dp, vertical = 10.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(conv.title?.takeIf { it.isNotBlank() } ?: stringResource(R.string.ai_new_chat), color = p.ink, fontSize = 13.5.sp, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                        Box(
                            Modifier.size(30.dp).clip(CircleShape).clickable {
                                scope.launch { runCatching { ApiClient.aiAssistant.removeProjectConversation(chatAuth(), current.id, conv.id) }; reopen(current.id) }
                            },
                            contentAlignment = Alignment.Center,
                        ) { Icon(Icons.Rounded.Close, contentDescription = null, tint = p.mut, modifier = Modifier.size(16.dp)) }
                    }
                }
                item {
                    Text(
                        stringResource(R.string.ai_tools_project_add_chat), color = Ai.Red, fontWeight = FontWeight.Bold, fontSize = 13.sp,
                        modifier = Modifier.padding(vertical = 10.dp).clip(RoundedCornerShape(10.dp)).clickable { picking = true }.padding(8.dp),
                    )
                }
            }
            if (picking) {
                val inProject = current.conversations.orEmpty().map { it.id }.toSet()
                AiConversationPicker(
                    night = night,
                    title = stringResource(R.string.ai_tools_project_add_chat),
                    conversations = conversations.filterNot { it.id in inProject },
                    max = 1,
                    confirmLabel = stringResource(R.string.ai_tools_add),
                    onDismiss = { picking = false },
                    onConfirm = { ids ->
                        picking = false
                        scope.launch {
                            ids.forEach { id -> runCatching { ApiClient.aiAssistant.addProjectConversation(chatAuth(), current.id, id) } }
                            reopen(current.id)
                        }
                    },
                )
            }
        }
    }

    if (creating || editing != null) {
        val target = editing
        AiProjectDialog(
            night = night,
            project = target,
            onDismiss = { creating = false; editing = null },
            onDelete = target?.let {
                {
                    editing = null
                    open = null
                    scope.launch { runCatching { ApiClient.aiAssistant.deleteProject(chatAuth(), it.id) }; reload() }
                }
            },
            onSave = { body ->
                scope.launch {
                    runCatching {
                        if (target == null) ApiClient.aiAssistant.createProject(chatAuth(), body).data else ApiClient.aiAssistant.updateProject(chatAuth(), target.id, body).data
                    }.onSuccess { saved ->
                        creating = false
                        editing = null
                        if (target != null) open = saved
                        reload()
                    }.onFailure { error = it.apiFailure().message }
                }
            },
        )
    }
}

@Composable
private fun AiProjectDialog(night: Boolean, project: AiProjectDto?, onDismiss: () -> Unit, onDelete: (() -> Unit)?, onSave: (AiProjectRequestDto) -> Unit) {
    val p = AiPalette(night)
    var name by remember { mutableStateOf(project?.name ?: "") }
    var description by remember { mutableStateOf(project?.description ?: "") }
    var instructions by remember { mutableStateOf(project?.instructions ?: "") }
    var confirmDelete by remember { mutableStateOf(false) }

    Dialog(onDismissRequest = onDismiss) {
        Column(Modifier.clip(RoundedCornerShape(20.dp)).background(p.surface).padding(18.dp).verticalScroll(rememberScrollState())) {
            Text(project?.name ?: stringResource(R.string.ai_tools_project_new), color = p.ink, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp)
            Spacer(Modifier.height(10.dp))
            OutlinedTextField(name, { name = it.take(120) }, label = { Text(stringResource(R.string.ai_tools_project_name)) }, singleLine = true, colors = aiFieldColors(p), modifier = Modifier.fillMaxWidth())
            Spacer(Modifier.height(8.dp))
            OutlinedTextField(description, { description = it.take(2000) }, label = { Text(stringResource(R.string.ai_tools_project_description)) }, colors = aiFieldColors(p), modifier = Modifier.fillMaxWidth().heightIn(min = 70.dp))
            Spacer(Modifier.height(8.dp))
            OutlinedTextField(instructions, { instructions = it.take(4000) }, label = { Text(stringResource(R.string.ai_tools_project_instructions)) }, colors = aiFieldColors(p), modifier = Modifier.fillMaxWidth().heightIn(min = 90.dp))
            Spacer(Modifier.height(14.dp))
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                if (onDelete != null) {
                    Box(Modifier.size(36.dp).clip(CircleShape).clickable { confirmDelete = true }, contentAlignment = Alignment.Center) {
                        Icon(Icons.Rounded.DeleteOutline, contentDescription = stringResource(R.string.ai_delete), tint = Color(0xFFDC2626), modifier = Modifier.size(19.dp))
                    }
                }
                Spacer(Modifier.weight(1f))
                Text(stringResource(R.string.ai_cancel), color = p.mut, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.clip(RoundedCornerShape(10.dp)).clickable(onClick = onDismiss).padding(10.dp))
                Box(
                    Modifier.clip(RoundedCornerShape(12.dp)).background(if (name.isBlank()) Ai.Red.copy(alpha = 0.4f) else Ai.Red)
                        .clickable(enabled = name.isNotBlank()) { onSave(AiProjectRequestDto(name.trim(), description.trim().ifBlank { null }, instructions.trim().ifBlank { null })) }
                        .padding(horizontal = 16.dp, vertical = 9.dp),
                ) { Text(stringResource(R.string.ai_tools_save), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 13.sp) }
            }
        }
    }
    if (confirmDelete && onDelete != null) {
        AiConfirmDialog(
            night = night,
            title = project?.name ?: "",
            message = stringResource(R.string.ai_tools_project_delete_confirm),
            onConfirm = { confirmDelete = false; onDelete() },
            onDismiss = { confirmDelete = false },
        )
    }
}

// ==================================================================== continue in a new chat (spec 121–132)

/** Picks one or more of the person's conversations (checkboxes), up to [max]. */
@Composable
internal fun AiConversationPicker(
    night: Boolean,
    title: String,
    conversations: List<AiConversationDto>,
    max: Int,
    confirmLabel: String,
    onDismiss: () -> Unit,
    onConfirm: (List<Int>) -> Unit,
    busy: Boolean = false,
    error: String? = null,
) {
    val p = AiPalette(night)
    val picked = remember { mutableStateListOf<Int>() }

    Dialog(onDismissRequest = onDismiss) {
        Column(Modifier.clip(RoundedCornerShape(20.dp)).background(p.surface).padding(18.dp)) {
            Text(title, color = p.ink, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp)
            Spacer(Modifier.height(8.dp))
            LazyColumn(Modifier.heightIn(max = 360.dp)) {
                items(conversations, key = { it.id }) { conv ->
                    val checked = conv.id in picked
                    Row(
                        Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).clickable {
                            if (checked) picked.remove(conv.id) else if (max == 1) { picked.clear(); picked.add(conv.id) } else if (picked.size < max) picked.add(conv.id)
                        }.padding(vertical = 2.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Checkbox(checked = checked, onCheckedChange = null, colors = CheckboxDefaults.colors(checkedColor = Ai.Red))
                        Text(conv.title?.takeIf { it.isNotBlank() } ?: stringResource(R.string.ai_new_chat), color = p.ink, fontSize = 13.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                }
            }
            error?.let { Text(it, color = Color(0xFFB91C1C), fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp)) }
            Spacer(Modifier.height(12.dp))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End, verticalAlignment = Alignment.CenterVertically) {
                Text(stringResource(R.string.ai_cancel), color = p.mut, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.clip(RoundedCornerShape(10.dp)).clickable(onClick = onDismiss).padding(10.dp))
                Box(
                    Modifier.clip(RoundedCornerShape(12.dp)).background(if (picked.isEmpty() || busy) Ai.Red.copy(alpha = 0.4f) else Ai.Red)
                        .clickable(enabled = picked.isNotEmpty() && !busy) { onConfirm(picked.toList()) }
                        .padding(horizontal = 16.dp, vertical = 9.dp),
                    contentAlignment = Alignment.Center,
                ) {
                    if (busy) CircularProgressIndicator(color = Color.White, strokeWidth = 2.dp, modifier = Modifier.size(16.dp))
                    else Text(confirmLabel, color = Color.White, fontWeight = FontWeight.Bold, fontSize = 13.sp)
                }
            }
        }
    }
}

/** "Continue in a new chat": pick the chats, DORR AI builds the capsule, the new chat opens. */
@Composable
internal fun AiContinueDialog(night: Boolean, conversations: List<AiConversationDto>, onDismiss: () -> Unit, onStarted: (AiConversationDto) -> Unit) {
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    AiConversationPicker(
        night = night,
        title = stringResource(R.string.ai_tools_continue_pick),
        conversations = conversations,
        max = 5,
        confirmLabel = stringResource(R.string.ai_tools_continue_go),
        busy = busy,
        error = error,
        onDismiss = { if (!busy) onDismiss() },
        onConfirm = { ids ->
            busy = true
            error = null
            scope.launch {
                runCatching { ApiClient.aiAssistant.continueConversations(chatAuth(), AiContinueRequestDto(ids)).data }
                    .onSuccess { result -> result?.conversation?.let(onStarted) }
                    .onFailure { error = it.apiFailure().message }
                busy = false
            }
        },
    )
}

/**
 * Above a conversation that carries context from earlier chats: where it came from, the full
 * capsule on "View", and a way to remove it (spec 130). Shows nothing for any other conversation.
 */
@Composable
internal fun AiCapsuleBanner(conversationId: Int, night: Boolean) {
    val p = AiPalette(night)
    val scope = rememberCoroutineScope()
    var capsule by remember(conversationId) { mutableStateOf<AiCapsuleDto?>(null) }
    var showing by remember(conversationId) { mutableStateOf(false) }
    var confirmDelete by remember(conversationId) { mutableStateOf(false) }

    LaunchedEffect(conversationId) {
        capsule = runCatching { ApiClient.aiAssistant.capsule(chatAuth(), conversationId).data?.capsule }.getOrNull()
    }

    val current = capsule ?: return
    val from = current.sources.mapNotNull { it.title?.takeIf { t -> t.isNotBlank() } }.joinToString("، ")
    Row(
        Modifier.fillMaxWidth().background(Ai.Red.copy(alpha = 0.08f)).clickable { showing = true }.padding(horizontal = 16.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Rounded.History, contentDescription = null, tint = Ai.Red, modifier = Modifier.size(16.dp))
        Spacer(Modifier.width(8.dp))
        Text(stringResource(R.string.ai_tools_capsule_banner, from), color = Ai.Red, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
        Text(stringResource(R.string.ai_tools_capsule_view), color = Ai.Red, fontWeight = FontWeight.Bold, fontSize = 12.sp)
    }

    if (showing) {
        Dialog(onDismissRequest = { showing = false }) {
            Column(Modifier.clip(RoundedCornerShape(20.dp)).background(p.surface).padding(18.dp).heightIn(max = 520.dp).verticalScroll(rememberScrollState())) {
                Text(stringResource(R.string.ai_tools_capsule_title), color = p.ink, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp)
                current.goal?.takeIf { it.isNotBlank() }?.let { CapsuleSection(stringResource(R.string.ai_tools_capsule_goal), listOf(it), p) }
                CapsuleSection(stringResource(R.string.ai_tools_capsule_decisions), current.decisions, p)
                CapsuleSection(stringResource(R.string.ai_tools_capsule_facts), current.facts, p)
                CapsuleSection(stringResource(R.string.ai_tools_capsule_files), current.files, p)
                CapsuleSection(stringResource(R.string.ai_tools_capsule_open), current.openTasks, p)
                CapsuleSection(stringResource(R.string.ai_tools_capsule_done), current.done, p)
                Spacer(Modifier.height(14.dp))
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        stringResource(R.string.ai_tools_capsule_delete), color = Color(0xFFDC2626), fontWeight = FontWeight.Bold, fontSize = 13.sp,
                        modifier = Modifier.clip(RoundedCornerShape(10.dp)).clickable { confirmDelete = true }.padding(8.dp),
                    )
                    Spacer(Modifier.weight(1f))
                    Text(stringResource(R.string.ai_tools_close), color = p.mut, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.clip(RoundedCornerShape(10.dp)).clickable { showing = false }.padding(8.dp))
                }
            }
        }
    }
    if (confirmDelete) {
        AiConfirmDialog(
            night = night,
            title = stringResource(R.string.ai_tools_capsule_delete),
            message = stringResource(R.string.ai_tools_capsule_delete_confirm),
            onConfirm = {
                confirmDelete = false
                showing = false
                scope.launch { runCatching { ApiClient.aiAssistant.deleteCapsule(chatAuth(), conversationId) }.onSuccess { capsule = null } }
            },
            onDismiss = { confirmDelete = false },
        )
    }
}

@Composable
private fun CapsuleSection(title: String, items: List<String>, p: AiPalette) {
    if (items.isEmpty()) return
    Text(title, color = Ai.Red, fontWeight = FontWeight.Bold, fontSize = 12.5.sp, modifier = Modifier.padding(top = 12.dp, bottom = 2.dp))
    items.forEach { Text("• $it", color = p.ink, fontSize = 13.sp, modifier = Modifier.padding(vertical = 1.dp)) }
}
