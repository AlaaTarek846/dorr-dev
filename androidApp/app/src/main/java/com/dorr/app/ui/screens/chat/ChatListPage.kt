package com.dorr.app.ui.screens.chat

import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateDpAsState
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.absoluteOffset
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material.icons.rounded.Redeem
import androidx.compose.material.icons.rounded.Inventory2
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Folder
import androidx.compose.material.icons.rounded.AlternateEmail
import androidx.compose.material.icons.rounded.Archive
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.ChatBubble
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.GroupAdd
import androidx.compose.material.icons.rounded.Image
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material.icons.rounded.VisibilityOff
import androidx.compose.material.icons.rounded.MarkChatUnread
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.MoreVert
import androidx.compose.material.icons.rounded.NotificationsOff
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Place
import androidx.compose.material.icons.rounded.Gif
import androidx.compose.material.icons.rounded.Poll
import androidx.compose.material.icons.rounded.Diversity3
import androidx.compose.material.icons.rounded.SmartButton
import androidx.compose.material.icons.rounded.StickyNote2
import androidx.compose.material.icons.rounded.PushPin
import androidx.compose.material.icons.rounded.QrCode2
import androidx.compose.material.icons.rounded.QrCodeScanner
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Settings
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.Videocam
import androidx.compose.material.icons.rounded.Warning
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.SwipeToDismissBox
import androidx.compose.material3.SwipeToDismissBoxValue
import androidx.compose.material3.Text
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.material3.rememberSwipeToDismissBoxState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshotFlow
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.rotate
import androidx.compose.ui.draw.scale
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.compositeOver
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ConversationDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.components.DarkHeroButton
import com.dorr.app.ui.components.DarkHeroPanel
import com.dorr.app.ui.locale.rememberAppLocaleFrame
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.theme.CairoFontFamily
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * The chat list: the same navy header as the calendar (title size, icon wells, spacing), search,
 * filter chips, the message-requests banner, and rows that swipe to pin / archive.
 */
@OptIn(ExperimentalMaterial3Api::class, ExperimentalFoundationApi::class)
@Composable
fun ChatListPage() {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    val listState = rememberLazyListState()
    var refreshing by remember { mutableStateOf(false) }
    var query by remember { mutableStateOf("") }
    var searchResults by remember { mutableStateOf<List<ConversationDto>?>(null) }
    val searching = query.isNotBlank()

    var addStory by remember { mutableStateOf(false) }
    var listMenu by remember { mutableStateOf(false) }
    val locale = rememberAppLocaleFrame()
    val listMenuContext = androidx.compose.ui.platform.LocalContext.current
    var folderFor by remember { mutableStateOf<ConversationDto?>(null) }
    val storyMedia = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri ->
        uri?.let { host.push(ChRoute.StoryComposer(it.toString())) }
    }

    LaunchedEffect(host.filter) {
        host.refreshList()
    }
    // Banner is prepended after the list paints; keep it on screen if we were at the top.
    LaunchedEffect(host.requestsCount, host.filter, host.listLoaded) {
        if (host.listLoaded && host.requestsCount > 0 && host.filter == "all" && !searching &&
            listState.firstVisibleItemIndex <= 1
        ) {
            listState.scrollToItem(0)
        }
    }
    LaunchedEffect(Unit) { host.refreshStories() }

    // In the requests box, "back" returns to the chats — it must not close the whole chat. Side by
    // side (spec 89), the page open beside the list closes first.
    BackHandler(enabled = (host.filter in SpecialLists || searching) && !(host.twoPane && host.detail != null)) {
        if (searching) query = "" else host.filter = "all"
    }

    // Debounced server search (names, numbers and message text).
    LaunchedEffect(query, host.filter) {
        if (query.isBlank()) {
            searchResults = null
            return@LaunchedEffect
        }
        delay(280)
        val listFilter = host.filter.takeIf { it in SpecialLists }
        searchResults = runCatching {
            ApiClient.chat.conversations(chatAuth(), filter = listFilter, search = query, perPage = 30).data.orEmpty()
        }.getOrNull()
    }

    val listTitle = stringResource(
        when (host.filter) {
            "requests" -> R.string.ch_requests_banner
            "locked" -> R.string.ch_locked_chats
            else -> R.string.ch_title
        },
    )

    Box(Modifier.fillMaxSize().background(Ch.Bg)) {
        Column(Modifier.fillMaxSize()) {
            Box(Modifier.fillMaxWidth().padding(horizontal = 16.dp)) {
                DarkHeroPanel {
                    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                        Text(
                            listTitle,
                            color = Color.White,
                            fontSize = 22.sp,
                            fontWeight = FontWeight.Black,
                            fontFamily = CairoFontFamily,
                            maxLines = 2,
                            overflow = TextOverflow.Ellipsis,
                            modifier = Modifier.weight(1f),
                        )
                        val shieldContext = androidx.compose.ui.platform.LocalContext.current
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                            DarkHeroButton(
                                if (com.dorr.app.chat.ChatShield.safeView) Icons.Rounded.VisibilityOff else Icons.Rounded.Visibility,
                                { com.dorr.app.chat.ChatShield.setSafeView(shieldContext, !com.dorr.app.chat.ChatShield.safeView) },
                                stringResource(R.string.ch_safe_view),
                            )
                            ListMenu { listMenu = true }
                            DarkHeroButton(
                                Icons.AutoMirrored.Rounded.ArrowBack,
                                {
                                    if (host.filter in SpecialLists) host.filter = "all" else host.pop()
                                },
                                stringResource(R.string.common_back),
                                flip = true,
                            )
                        }
                    }
                    Spacer(Modifier.height(10.dp))
                    SearchField(
                        query,
                        onChange = { query = it },
                        hint = if (host.filter == "locked") R.string.ch_locked_search else R.string.ch_search_hint,
                    )
                }
                Box(Modifier.align(Alignment.BottomEnd)) {
                    DropdownMenu(
                        expanded = listMenu,
                        onDismissRequest = { listMenu = false },
                        shape = RoundedCornerShape(18.dp),
                        containerColor = Ch.Surface,
                    ) {
                        locale.Provide {
                            MenuItem(Icons.Rounded.Star, stringResource(R.string.ch_starred_title)) { listMenu = false; host.push(ChRoute.Starred) }
                            MenuItem(Icons.Rounded.Lock, stringResource(R.string.ch_locked_chats)) {
                                listMenu = false
                                ChatLock.unlock(listMenuContext) { host.filter = "locked" }
                            }
                            MenuItem(Icons.Rounded.Diversity3, stringResource(R.string.cm_title)) { listMenu = false; host.push(ChRoute.Communities) }
                            MenuItem(Icons.Rounded.Settings, stringResource(R.string.ch_settings)) { listMenu = false; host.push(ChRoute.ChatSettings) }
                        }
                    }
                }
                ChLoadingBar(refreshing, Modifier.align(Alignment.BottomCenter))
            }

            if (!searching && host.filter !in SpecialLists && com.dorr.app.chat.ChatFeatures.allows("stories")) {
                StoriesBar(onAdd = { addStory = true })
            }

            // -------------------------------------------------------------- filters
            AnimatedVisibility(!searching && host.filter !in SpecialLists, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
                FilterChips()
            }

            // -------------------------------------------------------------- list
            PullToRefreshBox(
                isRefreshing = refreshing,
                onRefresh = {
                    scope.launch {
                        refreshing = true
                        host.refreshList()
                        refreshing = false
                    }
                },
                modifier = Modifier.weight(1f),
            ) {
                // Safe View: the chats I marked stay out of the list while it's on.
                val rows = (searchResults ?: host.conversations).let { all ->
                    if (com.dorr.app.chat.ChatShield.safeView) all.filterNot { it.id in com.dorr.app.chat.ChatShield.safeHidden } else all
                }.let { all ->
                    // A circle that hides its chats keeps them out of everything but the circle itself.
                    if (host.filter.startsWith("circle:")) all else all.filterNot { it.circle?.hideFromList == true }
                }
                when {
                    !host.listLoaded -> SkeletonRows()
                    host.listFailed && rows.isEmpty() -> ChEmptyState(
                        icon = Icons.Rounded.Warning,
                        title = stringResource(R.string.ch_load_failed),
                        text = stringResource(R.string.ch_error_network),
                        action = stringResource(R.string.ch_retry),
                        onAction = { scope.launch { host.refreshList() } },
                    )
                    rows.isEmpty() && host.filter.startsWith("folder:") && searchResults == null -> {
                        val folder = host.folders.firstOrNull { "folder:${it.id}" == host.filter }
                        ChEmptyState(
                            icon = Icons.Rounded.Folder,
                            title = stringResource(R.string.ch_folder_empty_title),
                            text = stringResource(R.string.ch_folder_empty_text),
                            action = stringResource(R.string.ch_folder_add_chats),
                            onAction = { host.fillingFolder = folder },
                            animated = true,
                        )
                    }
                    rows.isEmpty() && host.filter == "locked" && searchResults == null -> ChEmptyState(
                        icon = Icons.Rounded.Lock,
                        title = stringResource(R.string.ch_locked_chats),
                        text = stringResource(R.string.ch_no_locked),
                        animated = true,
                    )
                    rows.isEmpty() && host.requestsCount == 0 -> Column(Modifier.fillMaxSize()) {
                        Box(Modifier.weight(1f)) {
                            ChEmptyState(
                                icon = Icons.Rounded.ChatBubble,
                                title = stringResource(R.string.ch_empty_title),
                                text = stringResource(R.string.ch_empty_text),
                                action = stringResource(R.string.ch_start_chat),
                                onAction = { host.push(ChRoute.NewChat) },
                                animated = true,
                            )
                        }
                    }
                    else -> {
                        LaunchedEffect(listState, rows.size, host.listHasMore, searchResults) {
                            snapshotFlow { listState.layoutInfo.visibleItemsInfo.lastOrNull()?.index to listState.layoutInfo.totalItemsCount }
                                .collect { (last, total) ->
                                    if (searchResults == null && last != null && total > 0 && last >= total - 3) {
                                        host.loadMoreList()
                                    }
                                }
                        }
                        LazyColumn(
                        state = listState,
                        contentPadding = PaddingValues(top = 4.dp, bottom = 120.dp),
                        modifier = Modifier.fillMaxSize(),
                    ) {
                        if (host.requestsCount > 0 && searchResults == null && host.filter == "all") {
                            item(key = "requests") {
                                RequestsBanner(host.requestsCount) { host.filter = "requests" }
                            }
                        }
                        itemsIndexed(rows, key = { _, c -> c.id }) { index, conversation ->
                            SwipeRow(conversation, Modifier.animateItem(fadeInSpec = tween(260), placementSpec = spring(dampingRatio = 0.8f, stiffness = 380f))) {
                                ConversationRow(conversation, index, onLongClick = { folderFor = conversation }) {
                                    host.push(ChRoute.Conversation(conversation.id, conversation))
                                }
                            }
                        }
                    }
                    }
                }
            }
        }

        SpeedDial(Modifier.align(Alignment.BottomEnd).navigationBarsPadding().padding(end = 20.dp, bottom = 24.dp))
    }

    // Long-press: the chat's actions (pin, read, mute, archive, folder, lock, delete).
    folderFor?.let { ChatActionsSheet(it) { folderFor = null } }
    host.fillingFolder?.let { FolderChatsSheet(it) { host.fillingFolder = null } }

    if (addStory) StoryAddSheet(
        onDismiss = { addStory = false },
        onText = { host.push(ChRoute.StoryComposer()) },
        onMedia = { storyMedia.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageAndVideo)) },
        onVoice = { host.push(ChRoute.StoryComposer(STORY_VOICE)) },
    )
}

// ------------------------------------------------------------------------------- header parts

@Composable
internal fun GlassIcon(icon: ImageVector, size: Dp = 40.dp, onClick: () -> Unit) {
    val source = remember { MutableInteractionSource() }
    val pressed by com.dorr.app.ui.screens.wallet.rememberPressScale(source, 0.88f)
    Box(
        Modifier
            .size(size)
            .scale(pressed)
            .clip(CircleShape)
            .background(Color.White.copy(alpha = 0.16f))
            .border(1.dp, Color.White.copy(alpha = 0.22f), CircleShape)
            .clickable(interactionSource = source, indication = null, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        Icon(icon, null, tint = Color.White, modifier = Modifier.size(size * 0.52f))
    }
}

@Composable
private fun SearchField(value: String, onChange: (String) -> Unit, hint: Int = R.string.ch_search_hint) {
    Row(
        Modifier
            .fillMaxWidth()
            .height(42.dp)
            .clip(RoundedCornerShape(21.dp))
            .background(Color.White.copy(alpha = 0.16f))
            .padding(horizontal = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Rounded.Search, null, tint = Color.White.copy(alpha = 0.85f), modifier = Modifier.size(18.dp))
        Spacer(Modifier.width(8.dp))
        Box(Modifier.weight(1f)) {
            if (value.isEmpty()) {
                Text(stringResource(hint), color = Color.White.copy(alpha = 0.55f), fontSize = 14.sp, fontFamily = CairoFontFamily, maxLines = 1)
            }
            BasicTextField(
                value = value,
                onValueChange = onChange,
                singleLine = true,
                textStyle = TextStyle(color = Color.White, fontSize = 14.5.sp, fontWeight = FontWeight.Medium, fontFamily = CairoFontFamily),
                cursorBrush = SolidColor(Color.White),
                keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                modifier = Modifier.fillMaxWidth(),
            )
        }
        if (value.isNotEmpty()) {
            Icon(
                Icons.Rounded.Close, stringResource(R.string.ch_close_sheet),
                tint = Color.White.copy(alpha = 0.8f),
                modifier = Modifier.size(18.dp).clickable { onChange("") },
            )
        }
    }
}

@Composable
private fun ListMenu(onOpen: () -> Unit) {
    DarkHeroButton(Icons.Rounded.MoreVert, onOpen, stringResource(R.string.ch_share_more))
}

@Composable
internal fun MenuItem(icon: ImageVector, text: String, tint: Color = Ch.Ink, onClick: () -> Unit) {
    DropdownMenuItem(
        text = { Text(text, color = tint, fontWeight = FontWeight.SemiBold, fontSize = 14.sp) },
        leadingIcon = { Icon(icon, null, tint = if (tint == Ch.Ink) Ch.Red else tint, modifier = Modifier.size(20.dp)) },
        onClick = onClick,
    )
}

// ------------------------------------------------------------------------------- filters

/** Lists with their own title and a back arrow (not chips). */
private val SpecialLists = setOf("requests", "locked")

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun FilterChips() {
    val host = LocalChat.current
    var creating by remember { mutableStateOf(false) }
    var managing by remember { mutableStateOf<com.dorr.app.network.FolderDto?>(null) }
    var renaming by remember { mutableStateOf<com.dorr.app.network.FolderDto?>(null) }
    LaunchedEffect(Unit) { host.refreshFolders(); host.refreshCircles() }
    val context = androidx.compose.ui.platform.LocalContext.current
    var editingCircle by remember { mutableStateOf<com.dorr.app.network.CircleDto?>(null) }
    var lookFor by remember { mutableStateOf<com.dorr.app.network.FolderDto?>(null) }

    val options = listOf(
        "all" to stringResource(R.string.ch_filter_all),
        "unread" to stringResource(R.string.ch_filter_unread),
        "priority" to stringResource(R.string.cp_priority),
        "groups" to stringResource(R.string.ch_filter_groups),
        "channels" to stringResource(R.string.ch_filter_channels),
        "archived" to stringResource(R.string.ch_filter_archived),
    ) + host.folders.map { "folder:${it.id}" to listOfNotNull(it.emoji, it.name).joinToString(" ") } +
        host.circles.map { "circle:${it.id}" to listOfNotNull(it.emoji, it.shownName, if (it.locked) "🔒" else null, if (it.unread > 0) "(${it.unread})" else null).joinToString(" ") }

    Row(
        Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(horizontal = 16.dp, vertical = 12.dp),
        horizontalArrangement = Arrangement.spacedBy(8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        options.forEach { (key, label) ->
            val active = key == host.filter
            val fill by animateFloatAsState(if (active) 1f else 0f, tween(260), label = "chipFill")
            val pop by animateFloatAsState(if (active) 1f else 0.96f, spring(dampingRatio = 0.45f, stiffness = 500f), label = "chipPop")
            val textColor by animateColorAsState(if (active) Color.White else Ch.Mut, tween(260), label = "chipText")
            val folder = host.folders.firstOrNull { "folder:${it.id}" == key }
            Box(
                Modifier
                    .scale(pop)
                    .height(38.dp)
                    .shadow(if (active) 8.dp else 1.dp, RoundedCornerShape(19.dp), spotColor = if (active) Ch.Red.copy(alpha = 0.4f) else Color.Black.copy(alpha = 0.08f))
                    .clip(RoundedCornerShape(19.dp))
                    .background(lookColor(folder?.color)?.copy(alpha = 0.12f)?.compositeOver(Ch.Surface) ?: Ch.Surface)
                    .background(lookColor(folder?.color)?.let { Brush.horizontalGradient(listOf(it, it)) } ?: Brush.horizontalGradient(listOf(Ch.Red, Ch.RedDeep)), alpha = fill)
                    .combinedClickable(
                        onClick = {
                            val circle = host.circles.firstOrNull { "circle:${it.id}" == key }
                            if (circle?.locked == true && host.filter != key) ChatLock.unlockCircle(context, circle.id) { host.filter = key } else host.filter = key
                        },
                        onLongClick = {
                            val circle = host.circles.firstOrNull { "circle:${it.id}" == key }
                            when {
                                folder != null -> managing = folder
                                circle != null -> if (circle.locked) ChatLock.unlockCircle(context, circle.id) { editingCircle = circle } else editingCircle = circle
                            }
                        },
                    )
                    .padding(horizontal = 18.dp),
                contentAlignment = Alignment.Center,
            ) {
                Text(label, color = textColor, fontSize = 13.5.sp, fontWeight = FontWeight.Bold)
            }
        }
        Box(
            Modifier.size(38.dp).shadow(1.dp, CircleShape).clip(CircleShape).background(Ch.Surface).clickable { creating = true },
            contentAlignment = Alignment.Center,
        ) { Icon(Icons.Rounded.Add, null, tint = Ch.Red, modifier = Modifier.size(20.dp)) }
    }

    if (creating) TextInputSheet(stringResource(R.string.ch_new_folder), action = stringResource(R.string.ch_save), onDismiss = { creating = false }) { name ->
        host.scope.launch {
            runCatching { ApiClient.chat.createFolder(chatAuth(), mapOf("name" to name)).data }
                .onSuccess { f -> f?.let { host.folders.add(it); host.filter = "folder:${it.id}"; host.fillingFolder = it } }
                .onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }
        }
    }
    editingCircle?.let { c -> CircleEditorSheet(c, onDismiss = { editingCircle = null }) {} }
    managing?.let { f ->
        ChoiceSheet(
            title = f.name,
            options = listOf(
                stringResource(R.string.ch_folder_edit_chats) to { host.fillingFolder = f },
                stringResource(R.string.ch_rename_folder) to { renaming = f },
                stringResource(R.string.cp_folder_look) to { lookFor = f },
                stringResource(R.string.ch_delete_folder) to {
                    host.scope.launch {
                        runCatching { ApiClient.chat.deleteFolder(chatAuth(), f.id) }
                        host.folders.removeAll { it.id == f.id }
                        if (host.filter == "folder:${f.id}") host.filter = "all"
                    }
                    Unit
                },
            ),
            onDismiss = { managing = null },
        )
    }
    lookFor?.let { f ->
        FolderLookSheet(f, onDismiss = { lookFor = null }) { updated ->
            val index = host.folders.indexOfFirst { it.id == updated.id }
            if (index >= 0) host.folders[index] = updated
        }
    }
    renaming?.let { f ->
        TextInputSheet(stringResource(R.string.ch_rename_folder), initial = f.name, action = stringResource(R.string.ch_save), onDismiss = { renaming = null }) { name ->
            host.scope.launch {
                runCatching { ApiClient.chat.renameFolder(chatAuth(), f.id, mapOf("name" to name)).data }.getOrNull()?.let { updated ->
                    val index = host.folders.indexOfFirst { it.id == updated.id }
                    if (index >= 0) host.folders[index] = updated.copy(conversationIds = f.conversationIds)
                }
            }
        }
    }
}

// ------------------------------------------------------------------------------- rows

@Composable
private fun RequestsBanner(count: Int, onClick: () -> Unit) {
    Row(
        Modifier
            .fillMaxWidth()
            .background(Ch.Red.copy(alpha = 0.1f))
            .clickable(onClick = onClick)
            .padding(start = 16.dp, end = 16.dp, top = 10.dp, bottom = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(32.dp).clip(RoundedCornerShape(10.dp)).background(Ch.Red.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.MarkChatUnread, null, tint = Ch.Red, modifier = Modifier.size(16.dp))
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(stringResource(R.string.ch_requests_banner), color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 13.5.sp)
            Text(stringResource(R.string.ch_requests_count, count), color = Ch.Mut, fontSize = 11.5.sp)
        }
        ChBadge(count)
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun SwipeRow(conversation: ConversationDto, modifier: Modifier, content: @Composable () -> Unit) {
    val host = LocalChat.current
    val haptic = LocalHapticFeedback.current
    val state = rememberSwipeToDismissBoxState(
        confirmValueChange = { value ->
            when (value) {
                SwipeToDismissBoxValue.StartToEnd -> {
                    haptic.performHapticFeedback(HapticFeedbackType.LongPress)
                    host.scope.launch {
                        runCatching { ApiClient.chat.updateSettings(chatAuth(), conversation.id, mapOf("pinned" to !conversation.isPinned)).data }
                            .getOrNull()?.let { host.upsert(it) }
                    }
                }
                SwipeToDismissBoxValue.EndToStart -> {
                    haptic.performHapticFeedback(HapticFeedbackType.LongPress)
                    host.remove(conversation.id)
                    host.scope.launch {
                        runCatching { ApiClient.chat.updateSettings(chatAuth(), conversation.id, mapOf("archived" to !conversation.isArchived)) }
                        host.refreshList()
                    }
                }
                else -> Unit
            }
            false // always spring back: the row itself moves (or leaves) through the list animation
        },
        positionalThreshold = { it * 0.32f },
    )
    SwipeToDismissBox(
        state = state,
        modifier = modifier,
        backgroundContent = {
            val direction = state.dismissDirection
            // Only while actually swiping — otherwise nothing is drawn behind the row.
            if (direction == SwipeToDismissBoxValue.Settled) return@SwipeToDismissBox
            val pin = direction == SwipeToDismissBoxValue.StartToEnd
            val bg by animateColorAsState(if (pin) Color(0xFFF59E0B) else Color(0xFF6B7280), label = "swipeBg")
            val iconScale by animateFloatAsState(if (state.progress > 0.25f && state.progress < 1f) 1.15f else 0.8f, spring(dampingRatio = 0.4f), label = "swipeIcon")
            Box(
                Modifier.fillMaxSize().background(bg).padding(horizontal = 26.dp),
                contentAlignment = if (pin) Alignment.CenterStart else Alignment.CenterEnd,
            ) {
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Icon(if (pin) Icons.Rounded.PushPin else Icons.Rounded.Archive, null, tint = Color.White, modifier = Modifier.size(24.dp).scale(iconScale))
                    Text(
                        stringResource(if (pin) (if (conversation.isPinned) R.string.ch_unpin_chat else R.string.ch_pin_chat) else (if (conversation.isArchived) R.string.ch_unarchive else R.string.ch_archive)),
                        color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.Bold,
                    )
                }
            }
        },
    ) { content() }
}

/**
 * One chat in the list, laid out in three clean columns:
 *  - the avatar (with the online dot);
 *  - the name (and its lock / ✔ / why-it's-a-priority marks) over the last message;
 *  - a fixed column on the far end (the left in Arabic): the time on top, and under it the
 *    mention mark, mute / pin icons and the unread count — always lined up, whatever the name's length.
 * Flat rows with a thin divider (inset past the avatar); a pinned chat sits on a light tint.
 */
@Composable
@OptIn(ExperimentalFoundationApi::class)
private fun ConversationRow(c: ConversationDto, index: Int, onLongClick: () -> Unit = {}, onClick: () -> Unit) {
    val host = LocalChat.current
    val haptic = LocalHapticFeedback.current
    val peerKey = c.peer?.key
    val online = peerKey != null && (host.presence[peerKey]?.online ?: false)
    val activity = host.activity(c.id)
    val unread = c.unreadCount > 0 || c.markedUnread
    val compact = ChatPrefs.compactList
    val avatar = if (compact) 44.dp else 50.dp
    val selected = host.twoPane && host.openConversationId == c.id

    Column(
        Modifier
            .fillMaxWidth()
            .chStagger(index)
            .background(Ch.Bg)
            .background(if (selected) Ch.Red.copy(alpha = 0.1f) else if (c.isPinned) Ch.Red.copy(alpha = 0.045f) else Color.Transparent)
            .combinedClickable(onClick = onClick, onLongClick = { haptic.performHapticFeedback(HapticFeedbackType.LongPress); onLongClick() }),
    ) {
        Row(
            Modifier.fillMaxWidth().padding(start = 16.dp, end = 16.dp, top = if (compact) 8.dp else 10.dp, bottom = if (compact) 8.dp else 10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            ChAvatar(c.avatar, c.title, peerKey ?: c.id, size = avatar, isGroup = c.isGroup, online = online)
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        c.title.orEmpty(), color = Ch.Ink, fontSize = if (compact) 14.sp else 15.sp,
                        fontWeight = FontWeight.SemiBold,
                        maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false),
                    )
                    // A verified channel, or a verified person (account verification).
                    if (c.group?.isVerified == true || c.peer?.isVerified == true) Icon(Icons.Rounded.Verified, null, tint = Color(0xFF0EA5E9), modifier = Modifier.padding(start = 4.dp).size(14.dp))
                    if (c.isLocked) Icon(Icons.Rounded.Lock, null, tint = Ch.Soft, modifier = Modifier.padding(start = 4.dp).size(12.dp))
                }
                Spacer(Modifier.height(2.dp))
                AnimatedContent(targetState = activity.firstOrNull(), label = "preview", transitionSpec = {
                    (slideInVertically { it / 2 } + fadeIn()) togetherWith (slideOutVertically { -it / 2 } + fadeOut())
                }) { act ->
                    if (act != null) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(stringResource(if (act.state == "recording") R.string.ch_recording else R.string.ch_typing), color = Ch.Red, fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold)
                            Spacer(Modifier.width(6.dp))
                            TypingDots(dot = 4.dp)
                        }
                    } else {
                        LastMessagePreview(c, strong = false, ticks = false)
                    }
                }
            }
            Spacer(Modifier.width(8.dp))
            Column(Modifier.widthIn(min = 40.dp), horizontalAlignment = Alignment.End) {
                Text(listTime(c.lastMessageAt), color = Ch.Mut, fontSize = 11.5.sp, fontWeight = FontWeight.Medium, maxLines = 1)
                Spacer(Modifier.height(4.dp))
                Row(Modifier.height(22.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                    if (c.isPinned) Icon(Icons.Rounded.PushPin, null, tint = Ch.Soft, modifier = Modifier.size(14.dp).rotate(35f))
                    if (c.isMuted) Icon(Icons.Rounded.NotificationsOff, null, tint = Ch.Soft, modifier = Modifier.size(14.dp))
                    if (c.hasUnreadMention) MentionMark()
                    c.priority?.firstOrNull()?.let { why ->
                        Text(
                            stringResource(when (why) { "mention" -> R.string.cp_why_mention; "urgent" -> R.string.cp_why_urgent; "owed" -> R.string.cp_why_owed; else -> R.string.cp_why_direct }),
                            color = Ch.Red, fontSize = 10.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1,
                            modifier = Modifier.clip(CircleShape).background(Ch.Red.copy(alpha = 0.1f)).padding(horizontal = 6.dp, vertical = 2.dp),
                        )
                    }
                    AnimatedVisibility(unread, enter = scaleIn(spring(dampingRatio = 0.4f)) + fadeIn(), exit = scaleOut() + fadeOut()) {
                        if (c.unreadCount > 0) ChBadge(c.unreadCount, muted = c.isMuted)
                        else Box(Modifier.size(10.dp).background(Ch.Red, CircleShape))
                    }
                }
            }
        }
        Box(Modifier.fillMaxWidth().height(0.6.dp).background(Ch.Line))
    }
}

@Composable
private fun MentionMark() {
    Box(Modifier.size(20.dp).background(Ch.Mention, CircleShape), contentAlignment = Alignment.Center) {
        Icon(Icons.Rounded.AlternateEmail, null, tint = Color.White, modifier = Modifier.size(13.dp))
    }
}

@Composable
private fun LastMessagePreview(c: ConversationDto, strong: Boolean = false, ticks: Boolean = true) {
    // Unread: the message reads darker, so the eye finds it.
    val tone = if (strong) Ch.Ink.copy(alpha = 0.78f) else Ch.Mut
    val last = c.lastMessage
    // Something I typed here and didn't send: "Draft: …" in red, like WhatsApp.
    val draft = com.dorr.app.chat.ChatStore.drafts[c.id]
    Row(verticalAlignment = Alignment.CenterVertically) {
        if (draft != null) {
            Text(stringResource(R.string.ch_draft) + " ", color = Ch.Red, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, maxLines = 1)
            Text(plainChatText(draft).replace('\n', ' '), color = tone, fontSize = 12.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            return@Row
        }
        if (last == null) {
            Text(
                when {
                    c.isChannel -> stringResource(R.string.ch_followers, c.group?.membersCount ?: 0)
                    c.isGroup -> stringResource(R.string.ch_members, c.group?.membersCount ?: 0)
                    else -> ""
                },
                color = tone, fontSize = 12.5.sp, maxLines = 1,
            )
            return@Row
        }
        // Safe View, or a sensitive message: no preview at all.
        if (com.dorr.app.chat.ChatShield.safeView || last.isSensitive) {
            Text(stringResource(if (last.isSensitive) R.string.ch_sensitive else R.string.ch_safe_view_hidden), color = tone, fontSize = 12.5.sp, maxLines = 1)
            return@Row
        }
        if (ticks && last.isMine && last.system == null && !last.isDeleted) {
            ChTicks(last.status, onBubble = false, size = 16.dp)
            Spacer(Modifier.width(3.dp))
        } else if (c.isGroup && !c.isChannel && last.sender != null && last.system == null) {
            Text((last.sender.name ?: "") + ": ", color = Ch.colorFor(last.sender.key), fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold, maxLines = 1)
        }
        val (icon, label) = previewOf(last.type, last.isDeleted)
        if (icon != null) {
            Icon(icon, null, tint = Ch.Soft, modifier = Modifier.size(16.dp))
            Spacer(Modifier.width(3.dp))
        }
        Text(
            when {
                last.isDeleted -> stringResource(R.string.ch_deleted)
                last.system != null -> last.body ?: ""
                // Formatting marks (*bold* …) don't show in the preview.
                !last.body.isNullOrBlank() -> plainChatText(last.body)
                else -> label
            },
            color = tone, fontSize = 12.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis,
            fontStyle = if (last.isDeleted) androidx.compose.ui.text.font.FontStyle.Italic else null,
        )
    }
}

@Composable
internal fun previewOf(type: String, deleted: Boolean): Pair<ImageVector?, String> = when {
    deleted -> null to ""
    type == "image" -> Icons.Rounded.Image to stringResource(R.string.ch_photo)
    type == "video" -> Icons.Rounded.Videocam to stringResource(R.string.ch_video)
    type == "voice" || type == "audio" -> Icons.Rounded.Mic to stringResource(R.string.ch_voice)
    type == "document" -> Icons.Rounded.Description to stringResource(R.string.ch_document)
    type == "location" -> Icons.Rounded.Place to stringResource(R.string.ch_location)
    type == "contact" -> Icons.Rounded.Person to stringResource(R.string.ch_contact_card)
    type == "wallet_transfer" -> Icons.Rounded.Payments to stringResource(R.string.ch_transfer_receipt)
    type == "wallet_qr" -> Icons.Rounded.QrCode2 to stringResource(R.string.ch_wallet_qr_title)
    type == "call" -> Icons.Rounded.Call to stringResource(R.string.ch_call_voice)
    type == "poll" -> Icons.Rounded.Poll to stringResource(R.string.ch_poll)
    type == "interactive" -> Icons.Rounded.SmartButton to stringResource(R.string.ia_preview)
    type == "gif" -> Icons.Rounded.Gif to "GIF"
    type == "sticker" -> Icons.Rounded.StickyNote2 to stringResource(R.string.ch_sticker)
    type == "money_request" -> Icons.Rounded.Payments to stringResource(R.string.ch_money_request)
    type == "bill_split" -> Icons.Rounded.Payments to stringResource(R.string.ch_split_title)
    type == "moment_card" -> Icons.Rounded.Redeem to stringResource(R.string.mo_card_preview)
    type == "event_card" -> Icons.Rounded.Place to stringResource(R.string.ev_event_preview)
    type == "match_card" -> Icons.Rounded.Place to stringResource(R.string.sp_match_preview)
    type == "story_mention" -> Icons.Rounded.AlternateEmail to stringResource(R.string.bk_story_mention_line)
    else -> null to ""
}

@Composable
private fun SkeletonRows() {
    Column(Modifier.fillMaxSize().padding(horizontal = 14.dp)) {
        repeat(8) {
            Row(Modifier.fillMaxWidth().padding(vertical = 9.dp), verticalAlignment = Alignment.CenterVertically) {
                WaSkeleton(Modifier.size(54.dp), CircleShape)
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    WaSkeleton(Modifier.fillMaxWidth(0.55f).height(14.dp))
                    Spacer(Modifier.height(8.dp))
                    WaSkeleton(Modifier.fillMaxWidth(0.85f).height(12.dp))
                }
            }
        }
    }
}

// ------------------------------------------------------------------------------- empty state

/**
 * Empty / error state. `animated` floats three chat bubbles up and down around the icon — a small
 * living illustration drawn in code.
 */
@Composable
internal fun ChEmptyState(icon: ImageVector, title: String, text: String, action: String? = null, onAction: () -> Unit = {}, animated: Boolean = false) {
    val t = rememberInfiniteTransition(label = "empty")
    val float by t.animateFloat(0f, 1f, infiniteRepeatable(tween(2200, easing = FastOutSlowInEasing), RepeatMode.Reverse), label = "float")
    Column(Modifier.fillMaxSize().padding(32.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
        Box(Modifier.size(170.dp), contentAlignment = Alignment.Center) {
            if (animated) {
                Bubble(Modifier.align(Alignment.TopStart).offset(y = (float * 10).dp), 46.dp, Color(0xFFFFE4E6))
                Bubble(Modifier.align(Alignment.TopEnd).offset(x = (-6).dp, y = (18 - float * 12).dp), 36.dp, Color(0xFFFDE68A))
                Bubble(Modifier.align(Alignment.BottomEnd).offset(y = (-float * 8).dp), 28.dp, Color(0xFFDBEAFE))
            }
            Box(
                Modifier.size(100.dp).graphicsLayer { translationY = -float * 6.dp.toPx() }
                    .shadow(24.dp, CircleShape, spotColor = Ch.Red.copy(alpha = 0.5f))
                    .clip(CircleShape).background(Ch.HeaderBrush),
                contentAlignment = Alignment.Center,
            ) {
                Icon(icon, null, tint = Color.White, modifier = Modifier.size(46.dp))
            }
        }
        Spacer(Modifier.height(18.dp))
        Text(title, color = Ch.Ink, fontSize = 19.sp, fontWeight = FontWeight.ExtraBold)
        Spacer(Modifier.height(6.dp))
        Text(text, color = Ch.Mut, fontSize = 14.sp, textAlign = androidx.compose.ui.text.style.TextAlign.Center)
        if (action != null) {
            Spacer(Modifier.height(20.dp))
            ChPrimaryButton(action, onClick = onAction)
        }
    }
}

@Composable
private fun Bubble(modifier: Modifier, size: Dp, color: Color) {
    Box(modifier.size(size).clip(RoundedCornerShape(topStart = size / 2, topEnd = size / 2, bottomEnd = size / 2, bottomStart = 4.dp)).background(color))
}

@Composable
internal fun ChPrimaryButton(text: String, modifier: Modifier = Modifier, icon: ImageVector? = null, enabled: Boolean = true, onClick: () -> Unit) {
    val source = remember { MutableInteractionSource() }
    val scale by com.dorr.app.ui.screens.wallet.rememberPressScale(source, 0.95f)
    Row(
        modifier
            .scale(scale)
            .graphicsLayer { alpha = if (enabled) 1f else 0.5f }
            .shadow(14.dp, RoundedCornerShape(18.dp), spotColor = Ch.Red.copy(alpha = 0.45f))
            .clip(RoundedCornerShape(18.dp))
            .background(Brush.horizontalGradient(listOf(Ch.Red, Ch.RedDeep)))
            .clickable(interactionSource = source, indication = null, enabled = enabled, onClick = onClick)
            .padding(horizontal = 26.dp, vertical = 14.dp),
        horizontalArrangement = Arrangement.spacedBy(8.dp, Alignment.CenterHorizontally),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (icon != null) Icon(icon, null, tint = Color.White, modifier = Modifier.size(20.dp))
        Text(text, color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp)
    }
}

// ------------------------------------------------------------------------------- speed dial

@Composable
private fun SpeedDial(modifier: Modifier) {
    val host = LocalChat.current
    val haptic = LocalHapticFeedback.current
    var open by remember { mutableStateOf(false) }
    val rotation by animateFloatAsState(if (open) 135f else 0f, spring(dampingRatio = 0.5f, stiffness = 300f), label = "fab")

    Column(modifier, horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(12.dp)) {
        val actions = listOf(
            Triple(Icons.Rounded.QrCodeScanner, R.string.ch_scan_qr, ChRoute.NewChat),
            Triple(Icons.Rounded.GroupAdd, R.string.ch_new_group, ChRoute.NewGroup()),
            Triple(Icons.Rounded.Edit, R.string.ch_new_chat, ChRoute.NewChat),
        )
        actions.forEachIndexed { i, (icon, label, route) ->
            AnimatedVisibility(
                visible = open,
                enter = scaleIn(spring(dampingRatio = 0.55f, stiffness = 500f), initialScale = 0.3f, transformOrigin = androidx.compose.ui.graphics.TransformOrigin(1f, 1f)) + fadeIn(tween(160, delayMillis = (actions.size - i) * 40)),
                exit = scaleOut(tween(140), targetScale = 0.4f) + fadeOut(tween(120)),
            ) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        stringResource(label), color = Ch.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold,
                        modifier = Modifier.shadow(6.dp, RoundedCornerShape(12.dp)).clip(RoundedCornerShape(12.dp)).background(Ch.Surface).padding(horizontal = 12.dp, vertical = 7.dp),
                    )
                    Spacer(Modifier.width(10.dp))
                    Box(
                        Modifier.size(46.dp).shadow(10.dp, CircleShape, spotColor = Ch.Red.copy(alpha = 0.4f)).clip(CircleShape).background(Ch.Surface)
                            .clickable { open = false; host.push(route) },
                        contentAlignment = Alignment.Center,
                    ) { Icon(icon, null, tint = Ch.Red, modifier = Modifier.size(22.dp)) }
                }
            }
        }
        val fabShape = RoundedCornerShape(16.dp)
        Box(
            Modifier
                .size(56.dp)
                .shadow(14.dp, fabShape, spotColor = Ch.Red.copy(alpha = 0.45f))
                .clip(fabShape)
                .background(Ch.HeaderBrush)
                .clickable { haptic.performHapticFeedback(HapticFeedbackType.TextHandleMove); open = !open },
            contentAlignment = Alignment.Center,
        ) {
            Box(Modifier.rotate(rotation), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.ChatBubble, null, tint = Color.White, modifier = Modifier.size(28.dp))
                Icon(Icons.Rounded.Add, null, tint = Ch.Red, modifier = Modifier.size(14.dp).padding(bottom = 1.dp))
            }
        }
    }
}
