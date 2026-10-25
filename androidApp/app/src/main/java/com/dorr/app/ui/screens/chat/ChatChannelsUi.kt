package com.dorr.app.ui.screens.chat

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
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
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.AddAPhoto
import androidx.compose.material.icons.rounded.AlternateEmail
import androidx.compose.material.icons.rounded.Campaign
import androidx.compose.material.icons.rounded.Category
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.NotificationsOff
import androidx.compose.material.icons.rounded.Public
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Share
import androidx.compose.material.icons.rounded.Tune
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material.icons.rounded.VpnLock
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshotFlow
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.CategoryDto
import com.dorr.app.network.ChannelCardDto
import com.dorr.app.network.ConversationDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.locale.LocaleAwareBottomSheet
import com.dorr.app.ui.screens.events.FieldGap
import com.dorr.app.ui.screens.events.FilterBadgeDot
import com.dorr.app.ui.screens.events.FilterSelect
import com.dorr.app.ui.screens.profile.PinkBackdrop
import com.dorr.app.ui.screens.profile.SubHeader
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaCircleButton
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.filter
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody

// =============================================================================== discover

/**
 * Channels: find public ones by name or @handle (the biggest first), follow with one tap, or
 * create my own. A megaphone that bobs at the top says what this is.
 */
@Composable
fun ChannelsPage() {
    val host = LocalChat.current
    var query by remember { mutableStateOf("") }
    var list by remember { mutableStateOf<List<ChannelCardDto>?>(null) }
    /** Nothing typed, no category picked: the directory, grouped by category (most followed first). */
    var groups by remember { mutableStateOf<List<com.dorr.app.network.ChannelGroupDto>?>(null) }
    var categories by remember { mutableStateOf<List<CategoryDto>>(emptyList()) }
    var selectedFilters by remember { mutableStateOf(setOf("all")) }
    var creating by remember { mutableStateOf(false) }
    var previewing by remember { mutableStateOf<ChannelCardDto?>(null) }
    var visibleCount by remember { mutableIntStateOf(15) }
    val listState = rememberLazyListState()
    LaunchedEffect(Unit) { categories = runCatching { ApiClient.discover.categories(chatAuth()).data }.getOrNull().orEmpty() }
    LaunchedEffect(query) {
        visibleCount = 15
        if (query.isNotEmpty()) delay(300)
        val q = query.trim().ifEmpty { null }
        when {
            q == null -> {
                val directory = runCatching { ApiClient.discover.channelDirectory(chatAuth()).data }.getOrNull()
                groups = directory
                list = if (directory == null) runCatching { ApiClient.chat.discoverChannels(chatAuth(), null).data }.getOrNull().orEmpty() else directory.flatMap { it.channels }
            }
            else -> { groups = null; list = runCatching { ApiClient.chat.discoverChannels(chatAuth(), q).data }.getOrNull().orEmpty() }
        }
    }
    LaunchedEffect(selectedFilters) { visibleCount = 15 }
    val followChanged: (ChannelCardDto, Boolean) -> Unit = { channel, followed ->
        val bump: (ChannelCardDto) -> ChannelCardDto = { if (it.id == channel.id) it.copy(isFollowing = followed, followersCount = it.followersCount + if (followed) 1 else -1) else it }
        list = list?.map(bump)
        groups = groups?.map { g -> g.copy(channels = g.channels.map(bump)) }
    }

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize()) {
            SubHeader(stringResource(R.string.ch_channels)) { host.pop() }
            val items = list
            val grouped = groups
            val allChannels = remember(items, grouped, selectedFilters) {
                val raw = if (grouped != null) grouped.flatMap { g -> g.channels.map { Triple(g.category?.id, g.category, it) } }
                else items.orEmpty().map { Triple(it.category?.id, it.category, it) }
                val allMode = selectedFilters.isEmpty() || "all" in selectedFilters
                val cats = selectedFilters.mapNotNull { it.removePrefix("cat:").toIntOrNull() }.toSet()
                val wantVerified = "verified" in selectedFilters
                val wantUnverified = "unverified" in selectedFilters
                raw.filter { (catId, _, ch) ->
                    val catOk = allMode || cats.isEmpty() || catId in cats || ch.category?.id in cats
                    val verOk = when {
                        allMode || (wantVerified && wantUnverified) || (!wantVerified && !wantUnverified) -> true
                        wantVerified -> ch.isVerified
                        else -> !ch.isVerified
                    }
                    catOk && verOk
                }
            }
            val paged = allChannels.take(visibleCount)
            LaunchedEffect(listState, paged.size, allChannels.size) {
                snapshotFlow {
                    val info = listState.layoutInfo
                    val last = info.visibleItemsInfo.lastOrNull()?.index ?: 0
                    info.totalItemsCount > 0 && last >= info.totalItemsCount - 3 && visibleCount < allChannels.size
                }.distinctUntilChanged().filter { it }.collect {
                    visibleCount = (visibleCount + 15).coerceAtMost(allChannels.size)
                }
            }
            LazyColumn(
                state = listState,
                contentPadding = PaddingValues(start = 14.dp, top = 8.dp, end = 14.dp, bottom = 110.dp),
                verticalArrangement = Arrangement.spacedBy(6.dp),
                modifier = Modifier.weight(1f).fillMaxWidth(),
            ) {
            item {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    ChField(
                        query, { query = it.take(60) }, stringResource(R.string.ch_channels_search),
                        icon = Icons.Rounded.Search, clearable = true, modifier = Modifier.weight(1f),
                    )
                    Spacer(Modifier.width(8.dp))
                    ChannelFilterButton(categories, selectedFilters) { selectedFilters = it }
                }
            }
            when {
                items == null -> items(4) { com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().height(76.dp), RoundedCornerShape(20.dp)) }
                items.isEmpty() || allChannels.isEmpty() -> item {
                    Text(stringResource(R.string.ch_channels_none), color = Ch.Mut, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(30.dp))
                }
                else -> {
                    val seen = mutableSetOf<Int>()
                    paged.forEachIndexed { i, (catId, cat, channel) ->
                        if (cat != null && catId != null && seen.add(catId)) {
                            item(key = "cat-$catId") { ChCategoryHeader(cat) }
                        }
                        item(key = channel.id) {
                            ChannelRow(channel, i, onOpen = { if (channel.isFollowing) host.push(ChRoute.Conversation(channel.id)) else previewing = channel }) { followed -> followChanged(channel, followed) }
                        }
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
            ChannelCreateBar { creating = true }
        }
    }

    previewing?.let { channel ->
        ChannelPreviewSheet(channel, onDismiss = { previewing = null }) { followed ->
            previewing = null
            host.upsert(followed)
            followChanged(channel, true)
            host.push(ChRoute.Conversation(followed.id, followed))
        }
    }

    if (creating) ChannelCreateSheet(onDismiss = { creating = false }) { created ->
        host.upsert(created)
        host.replace(ChRoute.Conversation(created.id, created))
    }
}

@Composable
private fun ChannelCreateBar(onCreate: () -> Unit) {
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
            stringResource(R.string.ch_channel_create),
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
        ) { Icon(Icons.Rounded.Campaign, null, tint = Color.White, modifier = Modifier.size(18.dp)) }
    }
}

@Composable
private fun ChannelFilterButton(categories: List<CategoryDto>, selected: Set<String>, onChange: (Set<String>) -> Unit) {
    var open by remember { mutableStateOf(false) }
    Box {
        WaCircleButton(Icons.Rounded.Tune, { open = true }, contentDescription = stringResource(R.string.ch_channels_filter))
        Box(Modifier.align(Alignment.TopEnd)) { FilterBadgeDot(selected.count { it != "all" }) }
    }
    if (open) ChannelFilterSheet(categories, selected, onApply = { onChange(it); open = false }, onClose = { open = false })
}

@Composable
private fun ChannelFilterSheet(
    categories: List<CategoryDto>,
    current: Set<String>,
    onApply: (Set<String>) -> Unit,
    onClose: () -> Unit,
) {
    val allLabel = stringResource(R.string.ch_all)
    val verifiedLabel = stringResource(R.string.ch_verified)
    val unverifiedLabel = stringResource(R.string.ch_not_verified)
    fun verifyOf(keys: Set<String>) = when {
        "verified" in keys && "unverified" !in keys -> "verified"
        "unverified" in keys && "verified" !in keys -> "unverified"
        else -> "all"
    }
    fun catOf(keys: Set<String>) = keys.firstOrNull { it.startsWith("cat:") }?.removePrefix("cat:")?.toIntOrNull()
    var verify by remember { mutableStateOf(verifyOf(current)) }
    var categoryId by remember { mutableStateOf(catOf(current)) }
    fun pack(v: String, cat: Int?) = buildSet {
        if (v != "all") add(v)
        if (cat != null) add("cat:$cat")
        if (isEmpty()) add("all")
    }
    val verifyValue = when (verify) {
        "verified" -> verifiedLabel
        "unverified" -> unverifiedLabel
        else -> allLabel
    }
    val catName = categories.firstOrNull { it.id == categoryId }?.name?.takeIf { it.isNotBlank() } ?: allLabel
    LocaleAwareBottomSheet(onDismissRequest = onClose, containerColor = Wa.Bg, shape = RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp), fullHeight = true) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 18.dp).padding(bottom = 22.dp)) {
            Text(stringResource(R.string.ev_filter_title), color = Wa.Ink, fontSize = 19.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(10.dp))
            Column(Modifier.fillMaxWidth().heightIn(max = 560.dp).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(FieldGap)) {
                FilterSelect(
                    stringResource(R.string.ch_channels_filter_verify), Icons.Rounded.Verified, verifyValue,
                    options = listOf(
                        allLabel to { verify = "all" },
                        verifiedLabel to { verify = "verified" },
                        unverifiedLabel to { verify = "unverified" },
                    ),
                    selected = verifyValue,
                )
                FilterSelect(
                    stringResource(R.string.ev_f_category), Icons.Rounded.Category, catName,
                    options = listOf(allLabel to { categoryId = null }) +
                        categories.map { c -> (c.name?.takeIf { it.isNotBlank() } ?: "#${c.id}") to { categoryId = c.id } },
                    selected = catName.takeIf { categoryId != null },
                )
            }
            Spacer(Modifier.height(16.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                WaButton(stringResource(R.string.ev_place_clear), { onApply(setOf("all")) }, style = WaButtonStyle.Ghost, modifier = Modifier.weight(1f))
                WaButton(stringResource(R.string.ev_place_apply), { onApply(pack(verify, categoryId)) }, modifier = Modifier.weight(1f))
            }
        }
    }
}

@Composable
private fun ChannelRow(channel: ChannelCardDto, index: Int, onOpen: () -> Unit, onFollowChanged: (Boolean) -> Unit) {
    val host = LocalChat.current
    var busy by remember { mutableStateOf(false) }
    val desc = channel.description?.trim()?.takeIf { it.isNotBlank() && !it.equals(channel.name?.trim(), ignoreCase = true) }
    val handle = channel.handle?.let { "@$it" }
    Row(
        Modifier.fillMaxWidth().chStagger(index).clip(RoundedCornerShape(16.dp)).background(Ch.Surface).clickable(onClick = onOpen).padding(horizontal = 10.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        ChAvatar(channel.avatar, channel.name, channel.id, size = 40.dp, isGroup = true)
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(channel.name.orEmpty(), color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false))
                if (channel.isVerified) { Spacer(Modifier.width(4.dp)); VerifiedBadge(14.dp) }
            }
            if (handle != null) Text(handle, color = Ch.Mut, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            if (desc != null) Text(desc, color = Ch.Soft, fontSize = 11.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        Spacer(Modifier.width(8.dp))
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            FollowPill(channel.isFollowing, busy) {
                busy = true
                host.scope.launch {
                    try {
                        if (channel.isFollowing) {
                            ApiClient.chat.unfollowChannel(chatAuth(), channel.id)
                            host.remove(channel.id)
                            onFollowChanged(false)
                        } else {
                            ApiClient.chat.followChannel(chatAuth(), channel.id).data?.let { host.upsert(it) }
                            onFollowChanged(true)
                        }
                    } catch (e: Exception) {
                        e.apiFailure().message?.let { host.showToast(it) }
                    }
                    busy = false
                }
            }
            Spacer(Modifier.height(4.dp))
            Text(stringResource(R.string.ch_followers, channel.followersCount), color = Ch.Mut, fontSize = 11.sp, maxLines = 1)
        }
    }
}

/** "Follow" (brand) ⇄ "Following ✓" (quiet) — the tick springs in. */
@Composable
private fun FollowPill(following: Boolean, busy: Boolean, onClick: () -> Unit) {
    val bg by animateColorAsState(if (following) Ch.SurfaceMuted else Ch.Red, label = "followBg")
    Box(
        Modifier.clip(RoundedCornerShape(12.dp)).background(bg).clickable(enabled = !busy, onClick = onClick).padding(horizontal = 10.dp, vertical = 6.dp),
        contentAlignment = Alignment.Center,
    ) {
        AnimatedContent(following, label = "follow", transitionSpec = { (scaleIn(spring(dampingRatio = 0.5f)) + fadeIn()) togetherWith (scaleOut() + fadeOut()) }) { on ->
            Row(verticalAlignment = Alignment.CenterVertically) {
                if (on) {
                    Icon(Icons.Rounded.Check, null, tint = Ch.Mut, modifier = Modifier.size(13.dp))
                    Spacer(Modifier.width(3.dp))
                }
                Text(stringResource(if (on) R.string.ch_following else R.string.ch_follow), color = if (on) Ch.Mut else Color.White, fontWeight = FontWeight.Bold, fontSize = 12.sp)
            }
        }
    }
}

/** A channel I don't follow yet: photo, name, @handle, followers, description — and "Follow". */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ChannelPreviewSheet(channel: ChannelCardDto, onDismiss: () -> Unit, onFollowed: (ConversationDto) -> Unit) {
    val host = LocalChat.current
    var busy by remember { mutableStateOf(false) }
    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 24.dp).padding(bottom = 28.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            val pop = remember { Animatable(0.6f) }
            LaunchedEffect(Unit) { pop.animateTo(1f, spring(dampingRatio = 0.45f, stiffness = 300f)) }
            Box(Modifier.scale(pop.value)) { ChAvatar(channel.avatar, channel.name, channel.id, size = 92.dp, isGroup = true) }
            Spacer(Modifier.height(12.dp))
            Text(channel.name.orEmpty(), color = Ch.Ink, fontSize = 20.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center)
            Text(
                listOfNotNull(channel.handle?.let { "@$it" }, stringResource(R.string.ch_followers, channel.followersCount)).joinToString("  ·  "),
                color = Ch.Mut, fontSize = 13.sp,
            )
            channel.description?.takeIf { it.isNotBlank() }?.let {
                Text(it, color = Ch.Mut, fontSize = 13.5.sp, textAlign = TextAlign.Center, maxLines = 5, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 10.dp))
            }
            Spacer(Modifier.height(20.dp))
            ChPrimaryButton(stringResource(R.string.ch_follow_channel), icon = Icons.Rounded.Add, modifier = Modifier.fillMaxWidth(), enabled = !busy) {
                busy = true
                host.scope.launch {
                    try {
                        ApiClient.chat.followChannel(chatAuth(), channel.id).data?.let(onFollowed)
                    } catch (e: Exception) {
                        e.apiFailure().message?.let { host.showToast(it) }
                    }
                    busy = false
                }
            }
        }
    }
}

// =============================================================================== create

/** Name, description, photo, @handle, and public (in Discover) or link-only. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ChannelCreateSheet(onDismiss: () -> Unit, onCreated: (ConversationDto) -> Unit) {
    var categories by remember { mutableStateOf<List<com.dorr.app.network.CategoryDto>>(emptyList()) }
    var category by remember { mutableStateOf<Int?>(null) }
    LaunchedEffect(Unit) { categories = runCatching { ApiClient.discover.categories(chatAuth()).data }.getOrNull().orEmpty() }
    val context = LocalContext.current
    val host = LocalChat.current
    var name by remember { mutableStateOf("") }
    var description by remember { mutableStateOf("") }
    var handle by remember { mutableStateOf("") }
    var isPublic by remember { mutableStateOf(true) }
    var photo by remember { mutableStateOf<Uri?>(null) }
    var busy by remember { mutableStateOf(false) }
    val picker = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { photo = it ?: photo }
    val handleOk = handle.isEmpty() || Regex("^[a-z0-9_]{3,32}$").matches(handle)

    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().imePadding().padding(horizontal = 20.dp).padding(bottom = 26.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Text(stringResource(R.string.ch_channel_new), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(14.dp))
            val pop = remember { Animatable(0.7f) }
            LaunchedEffect(Unit) { pop.animateTo(1f, spring(dampingRatio = 0.45f)) }
            Box(
                Modifier.size(96.dp).scale(pop.value).shadow(14.dp, CircleShape, spotColor = Ch.Red.copy(alpha = 0.4f)).clip(CircleShape).background(Ch.HeaderBrush)
                    .clickable { picker.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) },
                contentAlignment = Alignment.Center,
            ) {
                if (photo != null) AsyncImage(photo, null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                else Icon(Icons.Rounded.AddAPhoto, null, tint = Color.White, modifier = Modifier.size(32.dp))
            }
            Spacer(Modifier.height(14.dp))
            SheetInput(name, stringResource(R.string.ch_channel_name_hint), Icons.Rounded.Campaign) { name = it.take(100) }
            Spacer(Modifier.height(8.dp))
            SheetInput(description, stringResource(R.string.ch_group_desc_hint), Icons.Rounded.Notes) { description = it.take(500) }
            Spacer(Modifier.height(8.dp))
            SheetInput(handle, stringResource(R.string.ch_channel_handle_hint), Icons.Rounded.AlternateEmail) {
                handle = it.lowercase().filter { c -> c.isLetterOrDigit() || c == '_' }.take(32)
            }
            if (!handleOk) Text(stringResource(R.string.ch_channel_handle_rule), color = Ch.Danger, fontSize = 12.sp, modifier = Modifier.fillMaxWidth().padding(start = 6.dp, top = 4.dp))
            if (categories.isNotEmpty()) {
                Text(stringResource(R.string.ch_channel_category), color = Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.fillMaxWidth().padding(start = 6.dp, top = 12.dp, bottom = 6.dp))
                ChCategoryChips(categories, category, stringResource(R.string.ch_channel_category_none)) { category = it }
            }

            // Public / link-only, as two cards.
            Row(Modifier.fillMaxWidth().padding(top = 12.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                listOf(true to Triple(Icons.Rounded.Public, R.string.ch_channel_public, R.string.ch_channel_public_sub), false to Triple(Icons.Rounded.VpnLock, R.string.ch_channel_private, R.string.ch_channel_private_sub))
                    .forEach { (value, meta) ->
                        val on = isPublic == value
                        val bg by animateColorAsState(if (on) Ch.Red.copy(alpha = 0.1f) else Ch.SurfaceMuted, label = "channelKind")
                        Column(
                            Modifier.weight(1f).clip(RoundedCornerShape(18.dp)).background(bg).clickable { isPublic = value }.padding(12.dp),
                        ) {
                            Icon(meta.first, null, tint = if (on) Ch.Red else Ch.Mut, modifier = Modifier.size(22.dp))
                            Text(stringResource(meta.second), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 13.5.sp, modifier = Modifier.padding(top = 4.dp))
                            Text(stringResource(meta.third), color = Ch.Mut, fontSize = 11.5.sp)
                        }
                    }
            }
            Spacer(Modifier.height(16.dp))
            ChPrimaryButton(stringResource(R.string.ch_channel_create), icon = Icons.Rounded.Campaign, modifier = Modifier.fillMaxWidth(), enabled = name.isNotBlank() && handleOk && !busy) {
                busy = true
                host.scope.launch {
                    try {
                        val text = "text/plain".toMediaTypeOrNull()
                        val fields = mutableMapOf<String, RequestBody>(
                            "name" to name.trim().toRequestBody(text),
                            "description" to description.trim().toRequestBody(text),
                            "is_public" to (if (isPublic) "1" else "0").toRequestBody(text),
                        )
                        if (handle.isNotEmpty()) fields["handle"] = handle.toRequestBody(text)
                        category?.let { fields["category_id"] = it.toString().toRequestBody(text) }
                        val part = photo?.let { copyToCache(context, it, "avatar.jpg") }?.let { compressImage(context, it) }?.let { f ->
                            MultipartBody.Part.createFormData("avatar", f.name, f.file.asRequestBody(f.mime.toMediaTypeOrNull()))
                        }
                        ApiClient.chat.createChannel(chatAuth(), fields, part).data?.let { onCreated(it) }
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

@Composable
private fun SheetInput(value: String, hint: String, icon: androidx.compose.ui.graphics.vector.ImageVector, onChange: (String) -> Unit) {
    ChField(value, onChange, hint, icon = icon)
}

// =============================================================================== bottom bars in a channel

/** A channel I don't follow: one big "Follow" that turns into a tick. */
@Composable
fun ChannelFollowBar(c: ConversationDto, onFollowed: (ConversationDto) -> Unit) {
    val host = LocalChat.current
    var busy by remember { mutableStateOf(false) }
    Box(Modifier.fillMaxWidth().padding(12.dp)) {
        ChPrimaryButton(stringResource(R.string.ch_follow_channel), icon = Icons.Rounded.Add, modifier = Modifier.fillMaxWidth(), enabled = !busy) {
            busy = true
            host.scope.launch {
                try {
                    ApiClient.chat.followChannel(chatAuth(), c.id).data?.let { onFollowed(it); host.upsert(it) }
                } catch (e: Exception) {
                    e.apiFailure().message?.let { host.showToast(it) }
                }
                busy = false
            }
        }
    }
}

/** A follower: mute / unmute (the bell rings when turned on) and share the channel. */
@Composable
fun ChannelFollowerBar(c: ConversationDto, onChanged: (ConversationDto) -> Unit) {
    val host = LocalChat.current
    val context = LocalContext.current
    val ring = remember { Animatable(0f) }
    Row(Modifier.fillMaxWidth().padding(12.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(
            Modifier.weight(1f).clip(RoundedCornerShape(18.dp)).background(Ch.Surface).clickable {
                host.scope.launch {
                    val muting = !c.isMuted
                    runCatching { ApiClient.chat.updateSettings(chatAuth(), c.id, mapOf("mute" to if (muting) "always" else "off")).data }.getOrNull()?.let {
                        onChanged(it)
                        host.upsert(it)
                    }
                    if (!muting) repeat(3) { ring.animateTo(1f, tween(70)); ring.animateTo(-1f, tween(70)) }
                    ring.animateTo(0f, tween(60))
                }
            }.padding(vertical = 14.dp),
            horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(
                if (c.isMuted) Icons.Rounded.NotificationsOff else Icons.Rounded.Notifications, null, tint = Ch.Red,
                modifier = Modifier.size(20.dp).graphicsLayer { rotationZ = ring.value * 18f },
            )
            Spacer(Modifier.width(8.dp))
            Text(stringResource(if (c.isMuted) R.string.ch_unmute else R.string.ch_mute), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 14.sp)
        }
        Box(
            Modifier.size(50.dp).clip(RoundedCornerShape(18.dp)).background(Ch.Surface).clickable {
                val link = c.group?.handle?.let { "@$it" } ?: c.title.orEmpty()
                runCatching {
                    context.startActivity(
                        android.content.Intent.createChooser(
                            android.content.Intent(android.content.Intent.ACTION_SEND).setType("text/plain")
                                .putExtra(android.content.Intent.EXTRA_TEXT, context.getString(R.string.ch_channel_share_text, c.title.orEmpty(), link)),
                            null,
                        ),
                    )
                }
            },
            contentAlignment = Alignment.Center,
        ) { Icon(Icons.Rounded.Share, null, tint = Ch.Red, modifier = Modifier.size(20.dp)) }
    }
}
