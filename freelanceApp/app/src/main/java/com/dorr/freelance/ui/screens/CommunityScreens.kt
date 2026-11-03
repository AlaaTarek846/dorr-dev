@file:OptIn(ExperimentalMaterial3Api::class)

package com.dorr.freelance.ui.screens

import android.widget.Toast
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyListState
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.outlined.BookmarkBorder
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.Block
import androidx.compose.material.icons.rounded.Bookmark
import androidx.compose.material.icons.rounded.Category
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.CloudOff
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.EditNote
import androidx.compose.material.icons.rounded.ExpandMore
import androidx.compose.material.icons.rounded.Flag
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.MoreHoriz
import androidx.compose.material.icons.rounded.People
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.PersonAdd
import androidx.compose.material.icons.rounded.QuestionAnswer
import androidx.compose.material.icons.rounded.Reply
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.SearchOff
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.Tune
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material.icons.rounded.Work
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalSoftwareKeyboardController
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.compose.viewModel
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.CommunityAcceptRequest
import com.dorr.freelance.net.CommunityAuthorDto
import com.dorr.freelance.net.CommunityChange
import com.dorr.freelance.net.CommunityCommentDto
import com.dorr.freelance.net.CommunityCommentRequest
import com.dorr.freelance.net.CommunityHomeDto
import com.dorr.freelance.net.CommunityHub
import com.dorr.freelance.net.CommunityMemberDto
import com.dorr.freelance.net.CommunityPostDto
import com.dorr.freelance.net.CommunityReactionRequest
import com.dorr.freelance.net.CursorRows
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.apiDone
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.cursorCall
import com.dorr.freelance.net.errorText
import com.dorr.freelance.net.optimisticReaction
import com.dorr.freelance.ui.components.AvatarImage
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.EmptyState
import com.dorr.freelance.ui.components.SkeletonBlock
import com.dorr.freelance.ui.kit.AccountDark
import com.dorr.freelance.ui.kit.ConfirmDialog
import com.dorr.freelance.ui.kit.PinkBackdrop
import com.dorr.freelance.ui.kit.SettingsScreenTitle
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsCard
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsNight
import com.dorr.freelance.ui.kit.settingsSurface
import com.dorr.freelance.ui.locale.LocaleAwareBottomSheet
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/*
 * FL-L12, the professional community (freelancers only, inside this app): the feed, one post with its comments, a member's profile and
 * their followers / following. Everything is read when a screen opens, on pull-to-refresh, and once after my own action — never on a timer.
 * A change made on one screen (a reaction, a save, a follow, a new comment) shows on the others through [CommunityHub.changes].
 */

private val FEED_TABS = listOf("for_you", "following", "specialty", "mine", "saved")

@Composable
private fun tabLabel(tab: String): String = stringResource(
    when (tab) {
        "following" -> R.string.cm_tab_following
        "specialty" -> R.string.cm_tab_specialty
        "mine" -> R.string.cm_tab_mine
        "saved" -> R.string.cm_tab_saved
        else -> R.string.cm_tab_for_you
    },
)

private fun tabIcon(tab: String): ImageVector = when (tab) {
    "following" -> Icons.Rounded.People
    "specialty" -> Icons.Rounded.Category
    "mine" -> Icons.Rounded.Person
    "saved" -> Icons.Rounded.Bookmark
    else -> Icons.Rounded.AutoAwesome
}

// ===================================================================================================== the frame

/** A community page: the soft backdrop, its title with a few round action buttons and the back button, then the content. */
@Composable
internal fun CommunityFrame(title: String, onBack: (() -> Unit)?, actions: @Composable RowScope.() -> Unit = {}, content: @Composable () -> Unit) {
    // As a tab of the bar ([onBack] null) it sits under the app header: no backdrop, status bar padding or back button of its own.
    val tab = onBack == null
    Box(Modifier.fillMaxSize()) {
        if (!tab) PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize().then(if (tab) Modifier else Modifier.statusBarsPadding()).imePadding()) {
            Row(
                Modifier.fillMaxWidth().padding(horizontal = 14.dp).padding(top = 14.dp, bottom = 10.dp),
                verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                SettingsScreenTitle(title, Modifier.weight(1f))
                actions()
                if (onBack != null) RoundIcon(Icons.AutoMirrored.Rounded.ArrowBack, stringResource(R.string.common_back), mirrored = true, onClick = onBack)
            }
            Box(Modifier.weight(1f).fillMaxWidth()) { content() }
        }
    }
}

@Composable
internal fun RoundIcon(icon: ImageVector, description: String?, mirrored: Boolean = false, tint: Color = settingsAccent(), onClick: () -> Unit) {
    val dark = settingsNight()
    Box(
        Modifier.size(36.dp)
            .then(if (dark) Modifier else Modifier.shadow(6.dp, CircleShape, ambientColor = Color(0x14001B53), spotColor = Color(0x14001B53)))
            .clip(CircleShape).background(settingsCard())
            .then(if (dark) Modifier.border(1.dp, AccountDark.line, CircleShape) else Modifier)
            .clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        Icon(icon, contentDescription = description, tint = tint, modifier = Modifier.size(18.dp).then(if (mirrored) Modifier.graphicsLayer { scaleX = -1f } else Modifier))
    }
}

/** Asks for more when the list's end comes near (never on a timer). */
@Composable
private fun NearEnd(list: LazyListState, size: Int, onNearEnd: () -> Unit) {
    val near by remember(list) {
        derivedStateOf {
            val info = list.layoutInfo
            val last = info.visibleItemsInfo.lastOrNull()?.index ?: 0
            info.totalItemsCount > 0 && last >= info.totalItemsCount - 4
        }
    }
    LaunchedEffect(near, size) { if (near) onNearEnd() }
}

@Composable
private fun ListFooter(loading: Boolean, failed: Boolean, end: Boolean, onRetry: () -> Unit) {
    Box(Modifier.fillMaxWidth().padding(vertical = 14.dp), contentAlignment = Alignment.Center) {
        when {
            failed -> Text(
                stringResource(R.string.common_retry), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(RoundedCornerShape(10.dp)).clickable(onClick = onRetry).padding(10.dp),
            )
            loading -> SmallSpinner(size = 22.dp)
            end -> Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Icon(Icons.Rounded.CheckCircle, contentDescription = null, tint = settingsMut().copy(alpha = 0.6f), modifier = Modifier.size(16.dp))
                Text(stringResource(R.string.cm_feed_end), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

/** Shown when the community is off in my country. */
@Composable
private fun CommunityClosed() {
    EmptyState(Icons.Rounded.Block, stringResource(R.string.cm_closed_title), stringResource(R.string.cm_closed_body), Modifier.padding(top = 80.dp))
}

// ===================================================================================================== the feed

class CommunityFeedVm : ViewModel() {
    var tab by mutableStateOf("for_you")
        private set
    var kind by mutableStateOf<String?>(null)
        private set
    var specialty by mutableStateOf<Int?>(null)
        private set
    var search by mutableStateOf("")
        private set
    var searchOpen by mutableStateOf(false)
    var closed by mutableStateOf(false)
        private set

    /** Bumped when my new post lands on top: the list scrolls up to it. */
    var toTop by mutableIntStateOf(0)
        private set

    val rows = CursorRows<CommunityPostDto>({ it.uuid }) { cursor ->
        cursorCall {
            ApiClient.community.feed(
                tab = tab, kind = kind, specialtyId = specialty, search = search.trim().takeIf { it.length >= 2 }, cursor = cursor,
            )
        }.also { if (it is ApiResult.Failed && it.error.errorCode == "freelance_feature_off") closed = true }
    }

    private var job: Job? = null
    private var searchJob: Job? = null

    init {
        viewModelScope.launch { CommunityHub.changes.collect(::apply) }
        job = viewModelScope.launch { rows.reload() }
    }

    val filtered: Boolean get() = kind != null || specialty != null || search.trim().length >= 2

    fun refresh() {
        if (rows.loading) return
        job = viewModelScope.launch {
            rows.reload()
            CommunityHub.refresh()
        }
    }

    fun more() {
        if (job?.isActive == true) return
        job = viewModelScope.launch { rows.loadMore() }
    }

    fun retry() {
        rows.retry()
        if (!rows.loaded) refilter() else more()
    }

    private fun refilter() {
        job?.cancel()
        rows.reset()
        job = viewModelScope.launch { rows.reload() }
    }

    fun pickTab(t: String) {
        if (t == tab) return
        tab = t
        refilter()
    }

    fun pickKind(k: String?) {
        if (k == kind) return
        kind = k
        refilter()
    }

    fun pickSpecialty(id: Int?) {
        if (id == specialty) return
        specialty = id
        refilter()
    }

    /** The search runs once the typing stops for a moment (not on a timer). */
    fun type(text: String) {
        val before = search.trim()
        search = text.take(80)
        searchJob?.cancel()
        if (before == search.trim()) return
        searchJob = viewModelScope.launch {
            delay(400)
            refilter()
        }
    }

    fun clearFilters() {
        kind = null
        specialty = null
        search = ""
        searchOpen = false
        refilter()
    }

    private fun apply(change: CommunityChange) {
        when (change) {
            is CommunityChange.PostUpdated -> rows.update({ it.uuid == change.post.uuid }) { change.post }
            is CommunityChange.PostDeleted -> rows.remove { it.uuid == change.uuid }
            is CommunityChange.FollowChanged -> rows.update({ it.author.uuid == change.member }) { it.copy(author = it.author.copy(isFollowing = change.following)) }
            is CommunityChange.PostCreated -> {
                val p = change.post
                val fits = (tab == "for_you" || tab == "mine") && (kind == null || kind == p.kind) && (specialty == null || specialty == p.specialty?.id) && search.isBlank()
                if (fits) {
                    rows.prepend(p)
                    toTop++
                }
            }
        }
    }
}

/**
 * The feed: «start a post» on top (my photo, then post / question / work), the tabs (for you · following · my specialty · mine · saved),
 * the kind and specialty filters and a search, then the posts — pulled down to refresh, and the next page as the end comes near.
 */
@Composable
fun CommunityScreen(nav: CommunityNav, onBack: (() -> Unit)?) {
    val vm: CommunityFeedVm = viewModel()
    val home by CommunityHub.home.collectAsState()
    val available by CommunityHub.available.collectAsState()
    val reconnect = collectReconnectTick()
    val list = rememberLazyListState()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var pickingSpecialty by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) { if (CommunityHub.home.value == null) CommunityHub.refresh() }
    // The connection came back after a failed read: read once more.
    LaunchedEffect(reconnect) { if (reconnect > 0 && vm.rows.failure != null) vm.retry() }
    LaunchedEffect(vm.toTop) { if (vm.toTop > 0) list.animateScrollToItem(0) }
    NearEnd(list, vm.rows.rows.size) { vm.more() }

    CommunityFrame(
        title = stringResource(R.string.cm_title),
        onBack = onBack,
        actions = {
            RoundIcon(Icons.Rounded.Search, stringResource(R.string.cm_search), tint = if (vm.searchOpen) settingsAccent() else settingsMut()) {
                vm.searchOpen = !vm.searchOpen
                if (!vm.searchOpen && vm.search.isNotEmpty()) vm.type("")
            }
            home?.me?.uuid?.takeIf { it.isNotBlank() }?.let { me ->
                Box(Modifier.clip(CircleShape).clickable { nav.openMember(me) }) { AvatarImage(home?.me?.avatarUrl, 36.dp) }
            }
        },
    ) {
        if (available == false || vm.closed) {
            CommunityClosed()
            return@CommunityFrame
        }
        PullToRefreshBox(isRefreshing = vm.rows.refreshing, onRefresh = { vm.refresh() }, modifier = Modifier.fillMaxSize()) {
            LazyColumn(state = list, modifier = Modifier.fillMaxSize(), contentPadding = PaddingValues(start = 12.dp, end = 12.dp, bottom = 24.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                item(key = "composer") { ComposerCard(home, nav.compose) }
                home?.takeIf { !it.canWrite }?.let { h -> item(key = "blocked") { WriteBlockedNote(h, nav.openVerification) } }
                item(key = "filters") { FeedFilters(vm, home) { pickingSpecialty = true } }
                val rows = vm.rows
                when {
                    !rows.loaded && rows.failure == null -> items(3, key = { "sk$it" }) { PostSkeleton() }
                    rows.rows.isEmpty() && rows.failure != null -> item(key = "error") {
                        LoadFailed(rows.failure?.errorText(offlineText) ?: genericError) { vm.retry() }
                    }
                    rows.loaded && rows.rows.isEmpty() -> item(key = "empty") { FeedEmpty(vm) { nav.compose("post") } }
                    else -> {
                        items(rows.rows, key = { it.uuid }) { post -> PostCard(post, nav) }
                        item(key = "footer") { ListFooter(rows.loading && !rows.refreshing, rows.failure != null, !rows.hasMore) { vm.retry() } }
                    }
                }
            }
        }
    }

    if (pickingSpecialty) {
        SpecialtySheet(home, vm.specialty, onPick = { vm.pickSpecialty(it); pickingSpecialty = false }, onDismiss = { pickingSpecialty = false })
    }
}

/** LinkedIn's «start a post»: my photo and a pill, then the three kinds of post as quick buttons. */
@Composable
private fun ComposerCard(home: CommunityHomeDto?, onCompose: (String) -> Unit) {
    val me by com.dorr.freelance.net.Session.me.collectAsState()
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp), elevation = 4.dp).padding(14.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            AvatarImage(home?.me?.avatarUrl ?: me?.avatarUrl, 46.dp)
            Spacer(Modifier.width(10.dp))
            Box(
                Modifier.weight(1f).height(46.dp).clip(RoundedCornerShape(999.dp))
                    .border(1.dp, if (settingsNight()) AccountDark.line else Color(0xFFD1D5DB), RoundedCornerShape(999.dp))
                    .clickable { onCompose("post") }.padding(horizontal = 16.dp),
                contentAlignment = Alignment.CenterStart,
            ) { Text(stringResource(R.string.cm_start_post), color = settingsMut(), fontSize = 14.sp, fontWeight = FontWeight.SemiBold, maxLines = 1) }
        }
        Row(Modifier.fillMaxWidth()) {
            QuickKind(Icons.Rounded.EditNote, stringResource(R.string.cm_kind_post), settingsAccent(), Modifier.weight(1f)) { onCompose("post") }
            QuickKind(Icons.Rounded.QuestionAnswer, stringResource(R.string.cm_kind_question), QUESTION_TONE, Modifier.weight(1f)) { onCompose("question") }
            QuickKind(Icons.Rounded.Work, stringResource(R.string.cm_kind_work), WORK_TONE, Modifier.weight(1f)) { onCompose("work") }
        }
    }
}

@Composable
private fun QuickKind(icon: ImageVector, label: String, tone: Color, modifier: Modifier, onClick: () -> Unit) {
    Row(
        modifier.clip(RoundedCornerShape(12.dp)).clickable(onClick = onClick).padding(vertical = 8.dp),
        horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, contentDescription = null, tint = tone, modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(6.dp))
        Text(label, color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 1)
    }
}

/** Why I can read but not write: not verified yet, or the staff muted me until a date. */
@Composable
internal fun WriteBlockedNote(home: CommunityHomeDto, onVerify: () -> Unit) {
    val tone = Color(0xFFD97706)
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(tone.copy(alpha = if (settingsNight()) 0.2f else 0.1f)).padding(12.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Icon(Icons.Rounded.Lock, contentDescription = null, tint = tone, modifier = Modifier.size(20.dp))
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            val muted = home.muted
            if (muted != null) {
                Text(stringResource(R.string.cm_muted_until, cmDate(muted.until)), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
                muted.reason?.takeIf { it.isNotBlank() }?.let { Text(stringResource(R.string.cm_muted_reason, it), color = settingsMut(), fontSize = 12.sp) }
            } else {
                Text(stringResource(R.string.cm_need_verified), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
                Text(
                    stringResource(R.string.cm_verify_now), color = settingsAccent(), fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold,
                    modifier = Modifier.clip(RoundedCornerShape(6.dp)).clickable(onClick = onVerify).padding(vertical = 2.dp),
                )
            }
        }
    }
}

@Composable
private fun FeedFilters(vm: CommunityFeedVm, home: CommunityHomeDto?, onSpecialty: () -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
        if (vm.searchOpen || vm.search.isNotEmpty()) {
            DorrTextField(
                vm.search, vm::type, placeholder = stringResource(R.string.cm_search_hint), icon = Icons.Rounded.Search,
                trailing = if (vm.search.isNotEmpty()) ({
                    Icon(Icons.Rounded.Close, contentDescription = null, tint = settingsMut(), modifier = Modifier.size(18.dp).clickable { vm.type("") })
                }) else null,
                modifier = Modifier.fillMaxWidth(),
            )
        }
        Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            FEED_TABS.forEach { t -> FilterChip(tabLabel(t), tabIcon(t), vm.tab == t, solid = true) { vm.pickTab(t) } }
        }
        Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
            val specialtyName = vm.specialty?.let { id -> home?.specialties?.firstOrNull { it.id == id }?.name }
            FilterChip(specialtyName ?: stringResource(R.string.cm_all_specialties), Icons.Rounded.Tune, vm.specialty != null, trailing = Icons.Rounded.ExpandMore, onClick = onSpecialty)
            FilterChip(stringResource(R.string.cm_kind_all), null, vm.kind == null) { vm.pickKind(null) }
            FilterChip(stringResource(R.string.cm_kind_posts), Icons.Rounded.EditNote, vm.kind == "post") { vm.pickKind("post") }
            FilterChip(stringResource(R.string.cm_kind_questions), Icons.Rounded.QuestionAnswer, vm.kind == "question") { vm.pickKind("question") }
            FilterChip(stringResource(R.string.cm_kind_works), Icons.Rounded.Work, vm.kind == "work") { vm.pickKind("work") }
        }
    }
}

@Composable
internal fun FilterChip(label: String, icon: ImageVector?, on: Boolean, solid: Boolean = false, trailing: ImageVector? = null, onClick: () -> Unit) {
    val night = settingsNight()
    val accent = settingsAccent()
    val bg = when {
        on && solid -> accent
        on -> accent.copy(alpha = 0.12f)
        else -> settingsCard()
    }
    val fg = when {
        on && solid -> Color.White
        on -> accent
        else -> settingsInk()
    }
    Row(
        Modifier.height(36.dp).clip(RoundedCornerShape(999.dp)).background(bg)
            .border(1.dp, if (on) (if (solid) accent else accent.copy(alpha = 0.5f)) else (if (night) AccountDark.line else Color(0xFFE5E7EB)), RoundedCornerShape(999.dp))
            .clickable(onClick = onClick).padding(horizontal = 13.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (icon != null) {
            Icon(icon, contentDescription = null, tint = fg, modifier = Modifier.size(16.dp))
            Spacer(Modifier.width(5.dp))
        }
        Text(label, color = fg, fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 1)
        if (trailing != null) {
            Spacer(Modifier.width(3.dp))
            Icon(trailing, contentDescription = null, tint = fg, modifier = Modifier.size(16.dp))
        }
    }
}

@Composable
private fun FeedEmpty(vm: CommunityFeedVm, onCompose: () -> Unit) {
    val (icon, title, body) = when {
        vm.filtered -> Triple(Icons.Rounded.SearchOff, R.string.cm_empty_filtered, R.string.cm_empty_filtered_body)
        vm.tab == "following" -> Triple(Icons.Rounded.PersonAdd, R.string.cm_empty_following, R.string.cm_empty_following_body)
        vm.tab == "specialty" -> Triple(Icons.Rounded.Category, R.string.cm_empty_specialty, R.string.cm_empty_specialty_body)
        vm.tab == "mine" -> Triple(Icons.Rounded.EditNote, R.string.cm_empty_mine, R.string.cm_empty_mine_body)
        vm.tab == "saved" -> Triple(Icons.Outlined.BookmarkBorder, R.string.cm_empty_saved, R.string.cm_empty_saved_body)
        else -> Triple(Icons.Rounded.Groups, R.string.cm_empty_all, R.string.cm_empty_all_body)
    }
    Column(Modifier.fillMaxWidth().padding(top = 36.dp, bottom = 20.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        EmptyState(icon, stringResource(title), stringResource(body))
        val action = when {
            vm.filtered -> R.string.cm_clear_filters
            vm.tab == "mine" || vm.tab == "for_you" || vm.tab == "specialty" -> R.string.cm_start_post_short
            else -> null
        }
        if (action != null) {
            Spacer(Modifier.height(16.dp))
            Text(
                stringResource(action), color = Color.White, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold,
                modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(settingsAccent())
                    .clickable { if (vm.filtered) vm.clearFilters() else onCompose() }.padding(horizontal = 22.dp, vertical = 11.dp),
            )
        }
    }
}

@Composable
private fun LoadFailed(text: String, onRetry: () -> Unit) {
    Column(Modifier.fillMaxWidth().padding(top = 40.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        EmptyState(Icons.Rounded.CloudOff, stringResource(R.string.cm_load_failed), text)
        Spacer(Modifier.height(14.dp))
        Text(
            stringResource(R.string.common_retry), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold,
            modifier = Modifier.clip(RoundedCornerShape(999.dp)).border(1.5.dp, settingsAccent(), RoundedCornerShape(999.dp)).clickable(onClick = onRetry)
                .padding(horizontal = 22.dp, vertical = 10.dp),
        )
    }
}

/** The specialties to filter by (or «all»), mine first. */
@Composable
internal fun SpecialtySheet(home: CommunityHomeDto?, selected: Int?, allowAll: Boolean = true, onPick: (Int?) -> Unit, onDismiss: () -> Unit) {
    val mine = home?.mySpecialties.orEmpty().toSet()
    val all = home?.specialties.orEmpty().sortedBy { if (it.id in mine) 0 else 1 }
    LocaleAwareBottomSheet(onDismissRequest = onDismiss, containerColor = settingsCard(), shape = RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp)) {
        Column(Modifier.fillMaxWidth().heightIn(max = 560.dp).verticalScroll(rememberScrollState()).padding(horizontal = 18.dp).padding(bottom = 24.dp)) {
            Text(stringResource(R.string.cm_pick_specialty), color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(bottom = 10.dp))
            if (allowAll) SheetOption(stringResource(R.string.cm_all_specialties), selected == null, false) { onPick(null) }
            all.forEach { s -> SheetOption(s.name, selected == s.id, s.id in mine) { onPick(s.id) } }
        }
    }
}

@Composable
private fun SheetOption(label: String, on: Boolean, mine: Boolean, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(if (on) settingsAccent().copy(alpha = 0.1f) else Color.Transparent)
            .clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 13.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(label, color = if (on) settingsAccent() else settingsInk(), fontSize = 14.sp, fontWeight = if (on) FontWeight.ExtraBold else FontWeight.SemiBold, modifier = Modifier.weight(1f))
        if (mine) {
            SoftPill(stringResource(R.string.cm_my_specialty), settingsMut())
            Spacer(Modifier.width(8.dp))
        }
        if (on) Icon(Icons.Rounded.Check, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(20.dp))
    }
}

// ===================================================================================================== one post

/** A comment with its replies as shown: the first few, then the rest page by page. */
data class CmThread(
    val comment: CommunityCommentDto,
    val replies: List<CommunityCommentDto>,
    val cursor: String? = null,
    val opened: Boolean = false,
    val loading: Boolean = false,
) {
    val hidden: Int get() = (comment.replies - replies.size).coerceAtLeast(0)
}

class PostDetailVm(private val uuid: String) : ViewModel() {
    var post by mutableStateOf<CommunityPostDto?>(null)
        private set
    var failure by mutableStateOf<ApiResult<*>?>(null)
        private set
    var gone by mutableStateOf(false)
        private set
    var refreshing by mutableStateOf(false)
        private set
    val threads = mutableStateListOf<CmThread>()
    var hasMore by mutableStateOf(false)
        private set
    var loadingMore by mutableStateOf(false)
        private set
    private var cursor: String? = null

    init {
        viewModelScope.launch {
            CommunityHub.changes.collect { c ->
                when (c) {
                    is CommunityChange.PostUpdated -> if (c.post.uuid == uuid) post = c.post
                    is CommunityChange.PostDeleted -> if (c.uuid == uuid) gone = true
                    is CommunityChange.FollowChanged -> post?.let { p -> if (p.author.uuid == c.member) post = p.copy(author = p.author.copy(isFollowing = c.following)) }
                    else -> Unit
                }
            }
        }
        viewModelScope.launch { load() }
        // A notice (a comment on my post, a reply) may be about this post: one reload, no timer.
        viewModelScope.launch { Realtime.changed.collect { load() } }
    }

    suspend fun load() {
        refreshing = post != null
        val r = cursorCall { ApiClient.community.post(uuid) }
        refreshing = false
        when (r) {
            is ApiResult.Ok -> {
                failure = null
                post = r.data.data.post
                threads.clear()
                threads.addAll(r.data.data.comments.map { CmThread(it, it.firstReplies.orEmpty()) })
                cursor = r.data.next
                hasMore = r.data.next != null
            }
            is ApiResult.Failed -> if (r.error.httpStatus == 404) gone = true else failure = r
            else -> failure = r
        }
    }

    fun refresh() {
        viewModelScope.launch { load() }
    }

    fun more() {
        if (!hasMore || loadingMore) return
        loadingMore = true
        viewModelScope.launch {
            val r = cursorCall { ApiClient.community.comments(uuid, cursor) }
            if (r is ApiResult.Ok) {
                r.data.data.filter { c -> threads.none { it.comment.uuid == c.uuid } }.forEach { threads.add(CmThread(it, it.firstReplies.orEmpty())) }
                cursor = r.data.next
                hasMore = r.data.hasMore
            }
            loadingMore = false
        }
    }

    fun moreReplies(root: String) {
        val i = threads.indexOfFirst { it.comment.uuid == root }.takeIf { it >= 0 } ?: return
        val t = threads[i]
        if (t.loading) return
        threads[i] = t.copy(loading = true)
        viewModelScope.launch {
            val r = cursorCall { ApiClient.community.replies(root, if (t.opened) t.cursor else null) }
            val j = threads.indexOfFirst { it.comment.uuid == root }.takeIf { it >= 0 } ?: return@launch
            val now = threads[j]
            threads[j] = if (r is ApiResult.Ok) {
                val merged = if (now.opened) now.replies + r.data.data.filter { n -> now.replies.none { it.uuid == n.uuid } }
                else r.data.data + now.replies.filter { o -> r.data.data.none { it.uuid == o.uuid } }
                val left = r.data.hasMore
                now.copy(replies = merged, cursor = r.data.next, opened = true, loading = false, comment = if (!left) now.comment.copy(replies = merged.size) else now.comment)
            } else {
                now.copy(loading = false)
            }
        }
    }

    private fun changePost(change: (CommunityPostDto) -> CommunityPostDto) {
        val p = post ?: return
        val next = change(p)
        post = next
        CommunityHub.emit(CommunityChange.PostUpdated(next))
    }

    /** Puts a comment (top or reply) through [change] where it is. */
    private fun changeComment(uuid: String, change: (CommunityCommentDto) -> CommunityCommentDto) {
        for (i in threads.indices) {
            val t = threads[i]
            if (t.comment.uuid == uuid) {
                threads[i] = t.copy(comment = change(t.comment))
                return
            }
            if (t.replies.any { it.uuid == uuid }) {
                threads[i] = t.copy(replies = t.replies.map { if (it.uuid == uuid) change(it) else it })
                return
            }
        }
    }

    fun send(body: String, replyTo: CommunityCommentDto?, editing: CommunityCommentDto?, onDone: () -> Unit, onFail: (ApiResult<*>) -> Unit) {
        viewModelScope.launch {
            if (editing != null) {
                val r = apiCall { ApiClient.community.updateComment(editing.uuid, CommunityCommentRequest(body)) }
                if (r is ApiResult.Ok) {
                    changeComment(editing.uuid) { r.data.copy(firstReplies = null, replies = it.replies) }
                    onDone()
                } else onFail(r)
                return@launch
            }
            val root = replyTo?.let { it.parent ?: it.uuid }
            val r = apiCall { ApiClient.community.comment(uuid, CommunityCommentRequest(body, replyTo?.uuid)) }
            if (r !is ApiResult.Ok) {
                onFail(r)
                return@launch
            }
            val c = r.data
            val parent = c.parent ?: root
            if (parent != null) {
                val i = threads.indexOfFirst { it.comment.uuid == parent }
                if (i >= 0) {
                    val t = threads[i]
                    threads[i] = t.copy(replies = t.replies + c.copy(firstReplies = null), comment = t.comment.copy(replies = t.comment.replies + 1))
                }
            } else {
                threads.add(CmThread(c.copy(firstReplies = null), emptyList()))
            }
            changePost { it.copy(comments = it.comments + 1) }
            onDone()
        }
    }

    fun delete(c: CommunityCommentDto, onDone: () -> Unit, onFail: (String) -> Unit, offline: String, generic: String) {
        viewModelScope.launch {
            val failed = apiDone(offline, generic) { ApiClient.community.deleteComment(c.uuid) }
            if (failed != null) {
                onFail(failed)
                return@launch
            }
            if (c.parent == null) {
                threads.removeAll { it.comment.uuid == c.uuid }
                changePost { p -> p.copy(comments = (p.comments - 1 - c.replies).coerceAtLeast(0), acceptedComment = p.acceptedComment.takeIf { it != c.uuid }) }
            } else {
                val i = threads.indexOfFirst { it.comment.uuid == c.parent }
                if (i >= 0) {
                    val t = threads[i]
                    threads[i] = t.copy(replies = t.replies.filter { it.uuid != c.uuid }, comment = t.comment.copy(replies = (t.comment.replies - 1).coerceAtLeast(0)))
                }
                changePost { p -> p.copy(comments = (p.comments - 1).coerceAtLeast(0)) }
            }
            onDone()
        }
    }

    fun react(c: CommunityCommentDto, kind: String?, onFail: (ApiResult<*>) -> Unit) {
        val (n, _) = optimisticReaction(c.reactions, emptyList(), c.myReaction, kind)
        changeComment(c.uuid) { it.copy(reactions = n, myReaction = kind) }
        viewModelScope.launch {
            val r = apiCall { ApiClient.community.react("comments", c.uuid, CommunityReactionRequest(kind)) }
            if (r is ApiResult.Ok) changeComment(c.uuid) { it.copy(reactions = r.data.reactions, myReaction = r.data.myReaction) }
            else {
                changeComment(c.uuid) { it.copy(reactions = c.reactions, myReaction = c.myReaction) }
                onFail(r)
            }
        }
    }

    /** Marks (or, with null, unmarks) a question's best answer; the marked comment moves to the top like the server orders it. */
    fun accept(comment: String?, onFail: (ApiResult<*>) -> Unit) {
        viewModelScope.launch {
            val r = apiCall { ApiClient.community.accept(uuid, CommunityAcceptRequest(comment)) }
            if (r !is ApiResult.Ok) {
                onFail(r)
                return@launch
            }
            post = r.data
            CommunityHub.emit(CommunityChange.PostUpdated(r.data))
            for (i in threads.indices) threads[i] = threads[i].copy(comment = threads[i].comment.copy(accepted = threads[i].comment.uuid == r.data.acceptedComment))
            val top = threads.indexOfFirst { it.comment.accepted }
            if (top > 0) threads.add(0, threads.removeAt(top))
        }
    }
}

/**
 * One post in full, then its comments (a question's best answer first and highlighted) with one level of replies, and the comment box
 * pinned at the bottom (reply-to and edit chips). The question's author can mark the best answer.
 */
@Composable
fun CommunityPostScreen(uuid: String, nav: CommunityNav, onBack: () -> Unit) {
    val vm: PostDetailVm = viewModel(key = "community-post-$uuid") { PostDetailVm(uuid) }
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val home by CommunityHub.home.collectAsState()
    val list = rememberLazyListState()
    val focus = remember { FocusRequester() }
    val keyboard = LocalSoftwareKeyboardController.current
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var replyTo by remember { mutableStateOf<CommunityCommentDto?>(null) }
    var editing by remember { mutableStateOf<CommunityCommentDto?>(null) }
    var text by rememberSaveable { mutableStateOf("") }
    var sending by remember { mutableStateOf(false) }
    var inlineError by remember { mutableStateOf<String?>(null) }
    var deleting by remember { mutableStateOf<CommunityCommentDto?>(null) }
    var deleteBusy by remember { mutableStateOf(false) }
    var reporting by remember { mutableStateOf<CommunityCommentDto?>(null) }
    fun say(r: ApiResult<*>) = Toast.makeText(context, r.errorText(offlineText) ?: genericError, Toast.LENGTH_SHORT).show()

    LaunchedEffect(Unit) { if (CommunityHub.home.value == null) CommunityHub.refresh() }
    NearEnd(list, vm.threads.size) { vm.more() }

    CommunityFrame(title = stringResource(if (vm.post?.kind == "question") R.string.cm_question_title else R.string.cm_post_title), onBack = onBack) {
        val post = vm.post
        when {
            vm.gone -> {
                EmptyState(Icons.Rounded.SearchOff, stringResource(R.string.cm_post_gone), stringResource(R.string.cm_post_gone_body), Modifier.padding(top = 80.dp))
                return@CommunityFrame
            }
            post == null && vm.failure != null -> {
                LoadFailed(vm.failure?.errorText(offlineText) ?: genericError) { vm.refresh() }
                return@CommunityFrame
            }
            post == null -> {
                Column(Modifier.padding(horizontal = 12.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    PostSkeleton()
                    repeat(3) { CommentSkeleton() }
                }
                return@CommunityFrame
            }
        }
        val p = post!!
        val canAccept = p.can.accept && p.kind == "question"
        Column(Modifier.fillMaxSize()) {
            PullToRefreshBox(isRefreshing = vm.refreshing, onRefresh = { vm.refresh() }, modifier = Modifier.weight(1f).fillMaxWidth()) {
                LazyColumn(state = list, modifier = Modifier.fillMaxSize(), contentPadding = PaddingValues(start = 12.dp, end = 12.dp, bottom = 16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    item(key = "post") {
                        PostCard(p, nav, expanded = true, onComment = { replyTo = null; editing = null; focus.requestFocus() }, onDeleted = onBack)
                    }
                    item(key = "head") {
                        Row(Modifier.fillMaxWidth().padding(top = 6.dp, start = 4.dp, end = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                            Text(stringResource(R.string.cm_comments_title), color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
                            Spacer(Modifier.width(6.dp))
                            if (p.comments > 0) Text("(${p.comments})", color = settingsMut(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                        }
                    }
                    if (vm.threads.isEmpty()) {
                        item(key = "none") {
                            Column(Modifier.fillMaxWidth().padding(vertical = 26.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                                Icon(if (p.kind == "question") Icons.Rounded.QuestionAnswer else Icons.Rounded.Groups, contentDescription = null, tint = settingsMut().copy(alpha = 0.6f), modifier = Modifier.size(40.dp))
                                Spacer(Modifier.height(8.dp))
                                Text(
                                    stringResource(if (p.kind == "question") R.string.cm_no_answers else R.string.cm_no_comments),
                                    color = settingsMut(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold, textAlign = TextAlign.Center,
                                )
                            }
                        }
                    }
                    items(vm.threads, key = { it.comment.uuid }) { t ->
                        ThreadView(
                            t, p, canAccept, nav,
                            onReply = { c -> editing = null; replyTo = c; focus.requestFocus() },
                            onEdit = { c -> replyTo = null; editing = c; text = c.body; focus.requestFocus() },
                            onDelete = { deleting = it },
                            onReport = { reporting = it },
                            onReact = { c, k ->
                                val why = CommunityActions.cannotWrite(context)
                                if (why != null) Toast.makeText(context, why, Toast.LENGTH_SHORT).show() else vm.react(c, k, ::say)
                            },
                            onAccept = { c -> vm.accept(if (c.accepted) null else c.uuid, ::say) },
                            onMoreReplies = { vm.moreReplies(t.comment.uuid) },
                        )
                    }
                    if (vm.hasMore || vm.loadingMore) item(key = "more") { ListFooter(loading = true, failed = false, end = false) {} }
                }
            }
            CommentBar(
                home = home, text = text, onText = { text = it; inlineError = null }, replyTo = replyTo, editing = editing, busy = sending, error = inlineError, focus = focus,
                onCancel = { if (editing != null) text = ""; replyTo = null; editing = null },
                onVerify = nav.openVerification,
                onSend = {
                    val body = text.trim()
                    if (body.isEmpty() || sending) return@CommentBar
                    sending = true
                    inlineError = null
                    val wasReply = replyTo != null
                    vm.send(
                        body, replyTo, editing,
                        onDone = {
                            sending = false
                            text = ""
                            val edited = editing != null
                            replyTo = null
                            editing = null
                            keyboard?.hide()
                            // My new comment is at the end of the list: show it.
                            if (!edited && !wasReply) scope.launch {
                                delay(60)
                                val n = list.layoutInfo.totalItemsCount
                                if (n > 0) list.animateScrollToItem(n - 1)
                            }
                        },
                        onFail = { r ->
                            sending = false
                            val msg = r.errorText(offlineText) ?: genericError
                            if (r is ApiResult.Failed && r.error.httpStatus == 422) inlineError = msg else Toast.makeText(context, msg, Toast.LENGTH_SHORT).show()
                        },
                    )
                },
            )
        }
    }

    deleting?.let { c ->
        ConfirmDialog(
            icon = Icons.Rounded.Delete, title = stringResource(R.string.cm_delete_comment_title), message = stringResource(R.string.cm_delete_comment_body),
            confirmLabel = stringResource(R.string.common_delete), loading = deleteBusy,
            onConfirm = {
                deleteBusy = true
                vm.delete(c, onDone = { deleteBusy = false; deleting = null }, onFail = { deleteBusy = false; Toast.makeText(context, it, Toast.LENGTH_SHORT).show() }, offline = offlineText, generic = genericError)
            },
            onDismiss = { if (!deleteBusy) deleting = null },
        )
    }
    reporting?.let { c -> ReportSheet("comment", c.uuid) { reporting = null } }
}

@Composable
private fun ThreadView(
    t: CmThread,
    post: CommunityPostDto,
    canAccept: Boolean,
    nav: CommunityNav,
    onReply: (CommunityCommentDto) -> Unit,
    onEdit: (CommunityCommentDto) -> Unit,
    onDelete: (CommunityCommentDto) -> Unit,
    onReport: (CommunityCommentDto) -> Unit,
    onReact: (CommunityCommentDto, String?) -> Unit,
    onAccept: (CommunityCommentDto) -> Unit,
    onMoreReplies: () -> Unit,
) {
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        CommentItem(t.comment, post, canAccept && t.comment.hidden == null, false, nav, onReply, onEdit, onDelete, onReport, onReact, onAccept)
        if (t.replies.isNotEmpty() || t.hidden > 0) {
            Column(Modifier.fillMaxWidth().padding(start = 46.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                t.replies.forEach { r -> CommentItem(r, post, false, true, nav, onReply, onEdit, onDelete, onReport, onReact, onAccept) }
                if (t.hidden > 0 || t.loading) {
                    Row(
                        Modifier.clip(RoundedCornerShape(10.dp)).clickable(enabled = !t.loading, onClick = onMoreReplies).padding(horizontal = 6.dp, vertical = 6.dp),
                        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp),
                    ) {
                        if (t.loading) SmallSpinner(size = 14.dp) else Icon(Icons.Rounded.Reply, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(16.dp).graphicsLayer { scaleY = -1f })
                        Text(stringResource(R.string.cm_more_replies, t.hidden), color = settingsAccent(), fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold)
                    }
                }
            }
        }
    }
}

@Composable
private fun CommentItem(
    c: CommunityCommentDto,
    post: CommunityPostDto,
    canAccept: Boolean,
    isReply: Boolean,
    nav: CommunityNav,
    onReply: (CommunityCommentDto) -> Unit,
    onEdit: (CommunityCommentDto) -> Unit,
    onDelete: (CommunityCommentDto) -> Unit,
    onReport: (CommunityCommentDto) -> Unit,
    onReact: (CommunityCommentDto, String?) -> Unit,
    onAccept: (CommunityCommentDto) -> Unit,
) {
    val night = settingsNight()
    var menu by remember { mutableStateOf(false) }
    val a = c.author
    val accepted = c.accepted && !isReply
    val bubble = when {
        accepted -> WORK_TONE.copy(alpha = if (night) 0.18f else 0.08f)
        night -> AccountDark.well
        else -> Color(0xFFF3F4F6)
    }
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top) {
        Box(Modifier.clip(CircleShape).clickable(enabled = a.uuid != null) { a.uuid?.let(nav.openMember) }) { AvatarImage(a.avatarUrl, if (isReply) 30.dp else 38.dp) }
        Spacer(Modifier.width(8.dp))
        Column(Modifier.weight(1f)) {
            Column(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(topStart = 4.dp, topEnd = 16.dp, bottomStart = 16.dp, bottomEnd = 16.dp)).background(bubble)
                    .then(if (accepted) Modifier.border(1.5.dp, WORK_TONE.copy(alpha = 0.6f), RoundedCornerShape(topStart = 4.dp, topEnd = 16.dp, bottomStart = 16.dp, bottomEnd = 16.dp)) else Modifier)
                    .padding(start = 12.dp, end = 4.dp, top = 8.dp, bottom = 10.dp),
                verticalArrangement = Arrangement.spacedBy(3.dp),
            ) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f).clickable(enabled = a.uuid != null) { a.uuid?.let(nav.openMember) }) {
                        NameWithBadge(a.name, a.badge, size = 13)
                        a.headline?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 11.sp, maxLines = 1, overflow = TextOverflow.Ellipsis) }
                    }
                    if (a.uuid == post.author.uuid && post.author.uuid != null) {
                        SoftPill(stringResource(R.string.cm_author), settingsAccent())
                        Spacer(Modifier.width(4.dp))
                    }
                    Box {
                        Box(Modifier.size(30.dp).clip(CircleShape).clickable { menu = true }, contentAlignment = Alignment.Center) {
                            Icon(Icons.Rounded.MoreHoriz, contentDescription = stringResource(R.string.cm_more), tint = settingsMut(), modifier = Modifier.size(18.dp))
                        }
                        DropdownMenu(expanded = menu, onDismissRequest = { menu = false }, containerColor = settingsCard()) {
                            if (c.can.edit) MenuLine(Icons.Rounded.Edit, stringResource(R.string.cm_edit_comment)) { menu = false; onEdit(c) }
                            if (c.can.delete) MenuLine(Icons.Rounded.Delete, stringResource(R.string.cm_delete_comment), danger = true) { menu = false; onDelete(c) }
                            if (c.can.report) MenuLine(Icons.Rounded.Flag, stringResource(R.string.cm_report_comment)) { menu = false; onReport(c) }
                            if (!c.can.edit && !c.can.delete && !c.can.report) MenuLine(Icons.Rounded.Person, stringResource(R.string.cm_view_profile)) { menu = false; a.uuid?.let(nav.openMember) }
                        }
                    }
                }
                if (accepted) Box(Modifier.padding(vertical = 2.dp)) { SoftPill(stringResource(R.string.cm_best_answer), WORK_TONE, Icons.Rounded.CheckCircle, solid = true) }
                c.hidden?.let { HiddenNote(false, it.reason) }
                Box(Modifier.padding(end = 8.dp)) { ExpandableBody(c.body, "comment-${c.uuid}", collapsedLines = 6, size = 13) }
            }
            Row(Modifier.fillMaxWidth().padding(start = 4.dp, top = 2.dp), verticalAlignment = Alignment.CenterVertically) {
                val time = cmRelativeTime(c.createdAt)
                Text(if (c.edited) "$time · ${stringResource(R.string.cm_edited)}" else time, color = settingsMut(), fontSize = 11.sp)
                Spacer(Modifier.width(4.dp))
                ReactButton(c.myReaction, compact = true) { onReact(c, it) }
                if (c.reactions > 0) {
                    Text("· ${c.reactions}", color = settingsMut(), fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold)
                }
                Text(
                    stringResource(R.string.cm_reply), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold,
                    modifier = Modifier.padding(start = 6.dp).clip(RoundedCornerShape(8.dp)).clickable { onReply(c) }.padding(horizontal = 6.dp, vertical = 4.dp),
                )
                if (canAccept && !isReply) {
                    Text(
                        stringResource(if (c.accepted) R.string.cm_unmark_best else R.string.cm_mark_best), color = WORK_TONE, fontSize = 12.sp, fontWeight = FontWeight.ExtraBold,
                        maxLines = 1, overflow = TextOverflow.Ellipsis,
                        modifier = Modifier.weight(1f, fill = false).clip(RoundedCornerShape(8.dp)).clickable { onAccept(c) }.padding(horizontal = 6.dp, vertical = 4.dp),
                    )
                }
            }
        }
    }
}

@Composable
private fun CommentSkeleton() {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top) {
        SkeletonBlock(Modifier.size(38.dp), height = 38.dp, radius = 19.dp)
        Spacer(Modifier.width(8.dp))
        Column(Modifier.weight(1f).clip(RoundedCornerShape(16.dp)).background(if (settingsNight()) AccountDark.well else Color(0xFFF3F4F6)).padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            SkeletonBlock(Modifier.fillMaxWidth(0.4f), height = 11.dp)
            SkeletonBlock(Modifier.fillMaxWidth(), height = 10.dp)
            SkeletonBlock(Modifier.fillMaxWidth(0.7f), height = 10.dp)
        }
    }
}

/** The comment box pinned at the bottom: a chip saying who I reply to (or that I edit), the field, the counter near the limit, send. */
@Composable
private fun CommentBar(
    home: CommunityHomeDto?,
    text: String,
    onText: (String) -> Unit,
    replyTo: CommunityCommentDto?,
    editing: CommunityCommentDto?,
    busy: Boolean,
    error: String?,
    focus: FocusRequester,
    onCancel: () -> Unit,
    onVerify: () -> Unit,
    onSend: () -> Unit,
) {
    val max = home?.limits?.commentMax ?: 1500
    val me by com.dorr.freelance.net.Session.me.collectAsState()
    Column(
        Modifier.fillMaxWidth()
            .shadow(16.dp, RoundedCornerShape(topStart = 22.dp, topEnd = 22.dp), ambientColor = Color(0x1A001B53), spotColor = Color(0x1A001B53))
            .clip(RoundedCornerShape(topStart = 22.dp, topEnd = 22.dp)).background(settingsCard())
            .padding(horizontal = 12.dp, vertical = 10.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        if (home != null && !home.canWrite) {
            WriteBlockedNote(home, onVerify)
            return@Column
        }
        if (replyTo != null || editing != null) {
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.08f)).padding(start = 10.dp, end = 4.dp, top = 4.dp, bottom = 4.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(if (editing != null) Icons.Rounded.Edit else Icons.Rounded.Reply, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(16.dp))
                Spacer(Modifier.width(6.dp))
                Text(
                    if (editing != null) stringResource(R.string.cm_editing_comment) else stringResource(R.string.cm_replying_to, replyTo?.author?.name.orEmpty()),
                    color = settingsAccent(), fontSize = 12.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f),
                )
                Box(Modifier.size(28.dp).clip(CircleShape).clickable(onClick = onCancel), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.Close, contentDescription = stringResource(R.string.common_cancel), tint = settingsMut(), modifier = Modifier.size(16.dp))
                }
            }
        }
        error?.let {
            Text(it, color = Color(0xFFDC2626), fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).background(Color(0xFFDC2626).copy(alpha = 0.08f)).padding(8.dp))
        }
        Row(verticalAlignment = Alignment.Bottom) {
            AvatarImage(home?.me?.avatarUrl ?: me?.avatarUrl, 36.dp, Modifier.padding(bottom = 4.dp))
            Spacer(Modifier.width(8.dp))
            DorrTextField(
                text, { onText(it.take(max)) },
                placeholder = stringResource(if (replyTo != null) R.string.cm_reply_hint else R.string.cm_comment_hint),
                singleLine = false, minHeight = 44.dp, focusRequester = focus,
                modifier = Modifier.weight(1f).heightIn(max = 150.dp),
            )
            Spacer(Modifier.width(8.dp))
            val ready = text.isNotBlank() && !busy
            Box(
                Modifier.padding(bottom = 2.dp).size(42.dp).clip(CircleShape).background(if (ready) settingsAccent() else settingsAccent().copy(alpha = 0.35f)).clickable(enabled = ready, onClick = onSend),
                contentAlignment = Alignment.Center,
            ) {
                if (busy) SmallSpinner(Color.White, 18.dp)
                else Icon(if (editing != null) Icons.Rounded.Check else Icons.AutoMirrored.Rounded.Send, contentDescription = stringResource(R.string.cm_send), tint = Color.White, modifier = Modifier.size(20.dp))
            }
        }
        if (text.length > max * 0.8) {
            Text("\u200E${text.length} / $max", color = if (text.length >= max) Color(0xFFDC2626) else settingsMut(), fontSize = 11.sp, modifier = Modifier.align(Alignment.End))
        }
    }
}

// ===================================================================================================== a member

class MemberVm(private val uuid: String) : ViewModel() {
    var card by mutableStateOf<CommunityMemberDto?>(null)
        private set
    var failure by mutableStateOf<ApiResult<*>?>(null)
        private set
    var gone by mutableStateOf(false)
        private set
    var refreshing by mutableStateOf(false)
        private set
    val posts = CursorRows<CommunityPostDto>({ it.uuid }) { c -> cursorCall { ApiClient.community.feed(member = uuid, cursor = c) } }
    private var job: Job? = null

    init {
        viewModelScope.launch {
            CommunityHub.changes.collect { c ->
                when (c) {
                    is CommunityChange.PostUpdated -> posts.update({ it.uuid == c.post.uuid }) { c.post }
                    is CommunityChange.PostDeleted -> {
                        if (posts.rows.any { it.uuid == c.uuid }) card = card?.let { it.copy(posts = (it.posts - 1).coerceAtLeast(0)) }
                        posts.remove { it.uuid == c.uuid }
                    }
                    is CommunityChange.PostCreated -> if (c.post.author.uuid == uuid) {
                        posts.prepend(c.post)
                        card = card?.let { it.copy(posts = it.posts + 1) }
                    }
                    is CommunityChange.FollowChanged -> {
                        posts.update({ it.author.uuid == c.member }) { it.copy(author = it.author.copy(isFollowing = c.following)) }
                        val now = card
                        if (c.member == uuid && now != null && now.isFollowing != c.following) {
                            card = now.copy(isFollowing = c.following, followers = (now.followers + if (c.following) 1 else -1).coerceAtLeast(0))
                        }
                    }
                }
            }
        }
        load()
    }

    fun load() {
        viewModelScope.launch {
            refreshing = card != null
            val r = apiCall { ApiClient.community.member(uuid) }
            refreshing = false
            when (r) {
                is ApiResult.Ok -> { card = r.data; failure = null }
                is ApiResult.Failed -> if (r.error.httpStatus == 404) gone = true else failure = r
                else -> failure = r
            }
        }
        job?.cancel()
        job = viewModelScope.launch { posts.reload() }
    }

    fun more() {
        if (job?.isActive == true) return
        job = viewModelScope.launch { posts.loadMore() }
    }

    fun applyCard(c: CommunityMemberDto) {
        card = c
    }
}

/** A member's profile: the header (photo, name, badge, headline, specialties, bio, numbers, follow), then their posts. */
@Composable
fun CommunityMemberScreen(uuid: String, nav: CommunityNav, onBack: () -> Unit) {
    val vm: MemberVm = viewModel(key = "community-member-$uuid") { MemberVm(uuid) }
    val context = LocalContext.current
    val list = rememberLazyListState()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    NearEnd(list, vm.posts.rows.size) { vm.more() }

    CommunityFrame(title = stringResource(R.string.cm_profile_title), onBack = onBack) {
        val card = vm.card
        when {
            vm.gone -> {
                EmptyState(Icons.Rounded.SearchOff, stringResource(R.string.cm_member_gone), stringResource(R.string.cm_member_gone_body), Modifier.padding(top = 80.dp))
                return@CommunityFrame
            }
            card == null && vm.failure != null -> {
                LoadFailed(vm.failure?.errorText(offlineText) ?: genericError) { vm.load() }
                return@CommunityFrame
            }
        }
        PullToRefreshBox(isRefreshing = vm.refreshing, onRefresh = { vm.load() }, modifier = Modifier.fillMaxSize()) {
            LazyColumn(state = list, modifier = Modifier.fillMaxSize(), contentPadding = PaddingValues(start = 12.dp, end = 12.dp, bottom = 24.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                item(key = "head") {
                    if (card == null) MemberSkeleton()
                    else MemberHeader(
                        card,
                        onFollow = { CommunityActions.follow(context, card.uuid, !card.isFollowing, onCard = vm::applyCard) },
                        onPeople = { which -> nav.openPeople(card.uuid, which) },
                        onCompose = { nav.compose("post") },
                    )
                }
                item(key = "posts-title") {
                    Text(
                        if (card?.isMe == true) stringResource(R.string.cm_my_posts) else stringResource(R.string.cm_member_posts),
                        color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 6.dp, start = 4.dp),
                    )
                }
                val rows = vm.posts
                when {
                    !rows.loaded && rows.failure == null -> items(2, key = { "sk$it" }) { PostSkeleton() }
                    rows.loaded && rows.rows.isEmpty() && rows.failure == null -> item(key = "empty") {
                        EmptyState(
                            Icons.Rounded.EditNote,
                            stringResource(if (card?.isMe == true) R.string.cm_empty_mine else R.string.cm_member_no_posts),
                            stringResource(if (card?.isMe == true) R.string.cm_empty_mine_body else R.string.cm_member_no_posts_body),
                            Modifier.padding(top = 24.dp, bottom = 12.dp),
                        )
                    }
                    else -> {
                        items(rows.rows, key = { it.uuid }) { PostCard(it, nav) }
                        item(key = "footer") { ListFooter(rows.loading, rows.failure != null, !rows.hasMore && rows.rows.isNotEmpty()) { rows.retry(); vm.more() } }
                    }
                }
            }
        }
    }
}

@Composable
private fun MemberHeader(m: CommunityMemberDto, onFollow: () -> Unit, onPeople: (String) -> Unit, onCompose: () -> Unit) {
    val accent = settingsAccent()
    val card = settingsCard()
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(24.dp))) {
        Box(Modifier.fillMaxWidth().height(132.dp)) {
            Box(
                Modifier.fillMaxWidth().height(88.dp)
                    .background(Brush.linearGradient(listOf(accent, Color(accent.red * 0.7f, accent.green * 0.7f, accent.blue * 0.8f), accent.copy(alpha = 0.7f)))),
            )
            Box(Modifier.align(Alignment.BottomStart).padding(start = 16.dp).size(92.dp).clip(CircleShape).background(card).padding(4.dp)) {
                AvatarImage(m.avatarUrl, 84.dp)
            }
        }
        Column(Modifier.fillMaxWidth().padding(horizontal = 16.dp).padding(top = 8.dp, bottom = 16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(m.name?.takeIf { it.isNotBlank() } ?: stringResource(R.string.cm_member), color = settingsInk(), fontSize = 21.sp, fontWeight = FontWeight.Black, maxLines = 2, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false))
                if (m.verified) {
                    Spacer(Modifier.width(5.dp))
                    Icon(Icons.Rounded.Verified, contentDescription = null, tint = Color(0xFF2563EB), modifier = Modifier.size(20.dp))
                }
            }
            if (m.badge?.name != null) PlanBadge(m.badge, large = true)
            m.headline?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsInk(), fontSize = 14.sp, lineHeight = 20.sp, fontWeight = FontWeight.Medium) }
            if (m.specialties.isNotEmpty()) {
                Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    m.specialties.sortedByDescending { it.primary }.forEach { s -> SoftPill(s.name, if (s.primary) accent else settingsMut(), if (s.primary) Icons.Rounded.Star else null) }
                }
            }
            m.bio?.takeIf { it.isNotBlank() }?.let { ExpandableBody(it, "bio-${m.uuid}", collapsedLines = 3, size = 13) }
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(if (settingsNight()) AccountDark.well else accent.copy(alpha = 0.06f)).padding(vertical = 10.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                StatCell("${m.posts}", stringResource(R.string.cm_stat_posts), Modifier.weight(1f), null)
                StatDivider()
                StatCell("${m.followers}", stringResource(R.string.cm_stat_followers), Modifier.weight(1f)) { onPeople("followers") }
                StatDivider()
                StatCell("${m.following}", stringResource(R.string.cm_stat_following), Modifier.weight(1f)) { onPeople("following") }
            }
            if (m.isMe) {
                PillButton(stringResource(R.string.cm_start_post_short), Icons.Rounded.EditNote, filled = true, onClick = onCompose)
            } else {
                PillButton(
                    stringResource(if (m.isFollowing) R.string.cm_following else R.string.cm_follow),
                    if (m.isFollowing) Icons.Rounded.Check else Icons.Rounded.PersonAdd,
                    filled = !m.isFollowing, onClick = onFollow,
                )
            }
        }
    }
}

@Composable
private fun StatCell(value: String, label: String, modifier: Modifier, onClick: (() -> Unit)?) {
    Column(
        modifier.clip(RoundedCornerShape(10.dp)).then(if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier).padding(vertical = 2.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(value, color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
        Text(label, color = if (onClick != null) settingsAccent() else settingsMut(), fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
    }
}

@Composable
private fun StatDivider() {
    Box(Modifier.width(1.dp).height(30.dp).background(if (settingsNight()) AccountDark.line else Color(0x1A001B53)))
}

@Composable
internal fun PillButton(label: String, icon: ImageVector?, filled: Boolean, modifier: Modifier = Modifier, enabled: Boolean = true, onClick: () -> Unit) {
    val accent = settingsAccent()
    Row(
        modifier.fillMaxWidth().height(46.dp).clip(RoundedCornerShape(999.dp))
            .background(if (filled) accent.copy(alpha = if (enabled) 1f else 0.5f) else Color.Transparent)
            .then(if (filled) Modifier else Modifier.border(1.5.dp, accent, RoundedCornerShape(999.dp)))
            .clickable(enabled = enabled, onClick = onClick),
        horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
    ) {
        if (icon != null) {
            Icon(icon, contentDescription = null, tint = if (filled) Color.White else accent, modifier = Modifier.size(19.dp))
            Spacer(Modifier.width(7.dp))
        }
        Text(label, color = if (filled) Color.White else accent, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
    }
}

@Composable
private fun MemberSkeleton() {
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(24.dp)).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        SkeletonBlock(Modifier.size(84.dp), height = 84.dp, radius = 42.dp)
        SkeletonBlock(Modifier.fillMaxWidth(0.5f), height = 18.dp)
        SkeletonBlock(Modifier.fillMaxWidth(0.8f), height = 12.dp)
        SkeletonBlock(Modifier.fillMaxWidth(), height = 56.dp, radius = 16.dp)
        SkeletonBlock(Modifier.fillMaxWidth(), height = 46.dp, radius = 23.dp)
    }
}

// ===================================================================================================== followers / following

/** Who follows a member, and whom they follow (two tabs), page by page. */
@Composable
fun CommunityPeopleScreen(uuid: String, startWhich: String, nav: CommunityNav, onBack: () -> Unit) {
    var which by rememberSaveable { mutableStateOf(if (startWhich == "following") "following" else "followers") }
    val scope = rememberCoroutineScope()
    val list = rememberLazyListState()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val rows = remember(which) { CursorRows<CommunityAuthorDto>({ it.uuid.orEmpty() }) { c -> cursorCall { ApiClient.community.people(uuid, which, c) } } }
    LaunchedEffect(which) { rows.reload() }
    NearEnd(list, rows.rows.size) { scope.launch { rows.loadMore() } }

    CommunityFrame(title = stringResource(R.string.cm_people_title), onBack = onBack) {
        Column(Modifier.fillMaxSize()) {
            Box(Modifier.padding(horizontal = 12.dp).padding(bottom = 10.dp)) {
                Segmented(listOf("followers" to stringResource(R.string.cm_stat_followers), "following" to stringResource(R.string.cm_stat_following)), which) { which = it }
            }
            LazyColumn(state = list, modifier = Modifier.weight(1f).fillMaxWidth(), contentPadding = PaddingValues(start = 12.dp, end = 12.dp, bottom = 24.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                when {
                    !rows.loaded && rows.failure == null -> items(5, key = { "sk$it" }) { PersonSkeleton() }
                    rows.rows.isEmpty() && rows.failure != null -> item(key = "error") {
                        LoadFailed(rows.failure?.errorText(offlineText) ?: genericError) { rows.retry(); scope.launch { rows.reload() } }
                    }
                    rows.loaded && rows.rows.isEmpty() -> item(key = "empty") {
                        EmptyState(
                            Icons.Rounded.People,
                            stringResource(if (which == "followers") R.string.cm_no_followers else R.string.cm_no_following),
                            stringResource(R.string.cm_people_empty_body),
                            Modifier.padding(top = 50.dp),
                        )
                    }
                    else -> {
                        items(rows.rows, key = { it.uuid.orEmpty() }) { person -> PersonRow(person) { person.uuid?.let(nav.openMember) } }
                        item(key = "footer") { ListFooter(rows.loading, rows.failure != null, false) { rows.retry(); scope.launch { rows.loadMore() } } }
                    }
                }
            }
        }
    }
}

@Composable
private fun PersonRow(p: CommunityAuthorDto, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp), elevation = 3.dp).clickable(onClick = onClick).padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        AvatarImage(p.avatarUrl, 48.dp)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            NameWithBadge(p.name, p.badge)
            p.headline?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp, maxLines = 2, overflow = TextOverflow.Ellipsis) }
        }
        if (p.isMe) SoftPill(stringResource(R.string.cm_you), settingsAccent())
    }
}

@Composable
private fun PersonSkeleton() {
    Row(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp), elevation = 3.dp).padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
        SkeletonBlock(Modifier.size(48.dp), height = 48.dp, radius = 24.dp)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            SkeletonBlock(Modifier.fillMaxWidth(0.45f), height = 12.dp)
            SkeletonBlock(Modifier.fillMaxWidth(0.7f), height = 10.dp)
        }
    }
}
