package com.dorr.freelance.ui.screens

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.animateDpAsState
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.slideInVertically
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
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.pager.HorizontalPager
import androidx.compose.foundation.pager.rememberPagerState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.AccessTime
import androidx.compose.material.icons.rounded.Assignment
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.Badge
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.EventAvailable
import androidx.compose.material.icons.rounded.EventBusy
import androidx.compose.material.icons.rounded.Forum
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.Inbox
import androidx.compose.material.icons.rounded.Insights
import androidx.compose.material.icons.rounded.LocalOffer
import androidx.compose.material.icons.rounded.Loyalty
import androidx.compose.material.icons.rounded.MarkEmailUnread
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.Quiz
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.Replay
import androidx.compose.material.icons.rounded.Rocket
import androidx.compose.material.icons.rounded.Send
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.Storefront
import androidx.compose.material.icons.rounded.VideoChat
import androidx.compose.material.icons.rounded.Work
import androidx.compose.material.icons.rounded.WorkspacePremium
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import androidx.compose.ui.semantics.semantics
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.HomeConsultationDto
import com.dorr.freelance.net.HomeDto
import com.dorr.freelance.net.HomeHub
import com.dorr.freelance.net.HomeWorkDto
import com.dorr.freelance.net.ProjectDto
import com.dorr.freelance.net.PromoDto
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.Session
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.ShellState
import com.dorr.freelance.ui.components.Tabs
import com.dorr.freelance.ui.kit.AccountDark
import com.dorr.freelance.ui.kit.AttentionChip
import com.dorr.freelance.ui.kit.Corners
import com.dorr.freelance.ui.kit.SectionHeader
import com.dorr.freelance.ui.kit.ShortcutTile
import com.dorr.freelance.ui.kit.StatTile
import com.dorr.freelance.ui.kit.Tints
import com.dorr.freelance.ui.kit.appCard
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsNight
import com.dorr.freelance.ui.kit.settingsSurface
import com.dorr.freelance.ui.wallet.formatMinor
import java.time.Duration
import kotlinx.coroutines.launch
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

/**
 * The Home tab, a dashboard (the redesign, batch B): what waits for my answer, a slider of the staff's banners and my next steps, my numbers,
 * the next consultation, the work with the nearest dates, projects that fit me and my tools. One `GET home` (and one list of projects) when
 * the tab opens, when the connection returns and when a notice arrives — never on a timer. Each section shows only when it has something.
 */
@OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)
@Composable
fun HomeScreen(actions: MainActions, failed: String?) {
    val me by Session.me.collectAsState()
    val home by HomeHub.home.collectAsState()
    val reconnect = collectReconnectTick()
    var picks by remember { mutableStateOf<List<ProjectDto>?>(null) }

    suspend fun load() {
        HomeHub.refresh()
        (apiCall { ApiClient.projects.list(perPage = 8) } as? ApiResult.Ok)?.data?.let { picks = it }
    }
    LaunchedEffect(reconnect) {
        load()
        com.dorr.freelance.net.CommunityHub.refresh()
    }
    LaunchedEffect(Unit) { Realtime.changed.collect { load() } }

    val profile = me
    val h = home
    val scope = androidx.compose.runtime.rememberCoroutineScope()
    var refreshing by remember { mutableStateOf(false) }
    // Pulling down reads the home once more (the freelancer's own gesture, not a timer).
    androidx.compose.material3.pulltorefresh.PullToRefreshBox(
        isRefreshing = refreshing,
        onRefresh = { refreshing = true; scope.launch { load(); refreshing = false } },
        modifier = Modifier.fillMaxSize(),
    ) {
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(bottom = 24.dp)) {
        Column(Modifier.padding(horizontal = 16.dp).padding(top = 14.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            if (profile != null) {
                // FL-708: shown only while a warning / restriction is active or its appeal waits.
                SanctionsCard()
                if (profile.availability?.canReceiveOffers == false) PausedCard(onOpen = actions.openAvailability)
            }
            if (h != null) AttentionStrip(h, actions)
        }

        Spacer(Modifier.height(14.dp))
        HeroSlider(slides = heroSlides(h, actions))

        Column(Modifier.padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            if (h != null) {
                Reveal(0) { StatsRow(h, actions) }
                h.nextConsultation?.let { c -> Reveal(1) { NextConsultationCard(c, serverTime = h.serverTime) { actions.openConsultation(c.uuid) } } }
                if (h.work.isNotEmpty()) Reveal(2) {
                    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        SectionHeader(stringResource(R.string.home_my_work), action = stringResource(R.string.home_see_all)) { ShellState.open(Tabs.WORK, "projects") }
                        h.work.forEach { w -> WorkRow(w) { w.projectUuid?.let(actions.openProject) } }
                    }
                }
            } else if (failed == null) {
                HomeSkeleton()
            }
        }

        picks?.takeIf { it.isNotEmpty() }?.let { list ->
            Spacer(Modifier.height(14.dp))
            SectionHeader(
                stringResource(R.string.home_picks), Modifier.padding(horizontal = 16.dp),
                action = stringResource(R.string.home_see_all),
            ) { ShellState.open(Tabs.OPPORTUNITIES, "projects") }
            Spacer(Modifier.height(8.dp))
            LazyRow(contentPadding = PaddingValues(horizontal = 16.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                items(list, key = { it.uuid }) { p -> PickCard(p) { actions.openProject(p.uuid) } }
            }
        }

        Column(Modifier.padding(horizontal = 16.dp).padding(top = 14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            if (h != null) ToolsGrid(h, actions)
            if (profile != null && h != null && profile.status != "verified") StartJourney(profile.completeness?.percent ?: 0, profile.status, h, actions)
            ErrorBanner(failed)
        }
    }
    }
}

/** The home while its first answer is on its way: the slider, a row of number tiles and two rows of work, in grey. */
@Composable
private fun HomeSkeleton() {
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        com.dorr.freelance.ui.components.SkeletonBlock(Modifier.width(110.dp), height = 16.dp)
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            repeat(3) { com.dorr.freelance.ui.components.SkeletonBlock(Modifier.weight(1f), height = 96.dp, radius = 18.dp) }
        }
        com.dorr.freelance.ui.components.SkeletonBlock(Modifier.width(140.dp), height = 16.dp)
        repeat(2) { com.dorr.freelance.ui.components.SkeletonBlock(Modifier.fillMaxWidth(), height = 72.dp, radius = 18.dp) }
    }
}

// ---------------------------------------------------------------- what waits for my answer

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun AttentionStrip(h: HomeDto, actions: MainActions) {
    val a = h.attention
    if (a.ordersAwaiting + a.consultationsPending + a.invitationsPending + a.revisions == 0) return
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(stringResource(R.string.home_needs_you), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            if (a.ordersAwaiting > 0) AttentionChip(Icons.Rounded.Inbox, stringResource(R.string.home_att_orders, a.ordersAwaiting), Tints.amber) { ShellState.open(Tabs.WORK, "orders") }
            if (a.consultationsPending > 0) AttentionChip(Icons.Rounded.VideoChat, stringResource(R.string.home_att_consultations, a.consultationsPending), Tints.violet) { ShellState.open(Tabs.WORK, "consultations") }
            if (a.revisions > 0) AttentionChip(Icons.Rounded.Replay, stringResource(R.string.home_att_revisions, a.revisions), Tints.rose) { ShellState.open(Tabs.WORK, "projects") }
            if (a.invitationsPending > 0) AttentionChip(Icons.Rounded.MarkEmailUnread, stringResource(R.string.home_att_invitations, a.invitationsPending), Tints.blue) { ShellState.open(Tabs.OPPORTUNITIES, "invitations") }
        }
    }
}

// ---------------------------------------------------------------- the slider

/** One card of the slider: the staff's banner (with its image) or one of my next steps (drawn on the app colour with an icon). */
private data class HeroSlide(
    val key: String,
    val title: String,
    val body: String?,
    val cta: String?,
    val image: String? = null,
    val icon: ImageVector? = null,
    val big: String? = null,
    val onClick: (() -> Unit)?,
)

@Composable
private fun heroSlides(h: HomeDto?, actions: MainActions): List<HeroSlide> {
    val me by Session.me.collectAsState()
    val uri = LocalUriHandler.current
    val percent = me?.completeness?.percent ?: 0
    val slides = mutableListOf<HeroSlide>()
    h?.promos?.forEach { p ->
        slides += HeroSlide(
            key = p.uuid, title = p.title.orEmpty(), body = p.body, cta = p.cta, image = com.dorr.freelance.net.ApiClient.mediaUrl(p.imagePath),
            onClick = promoAction(p, actions) { url -> runCatching { uri.openUri(url) } },
        )
    }
    if (me != null && percent < 100) slides += HeroSlide(
        "coach", stringResource(R.string.home_slide_coach_title), stringResource(R.string.home_slide_coach_body), stringResource(R.string.home_slide_coach_cta),
        icon = Icons.Rounded.AutoAwesome, big = "$percent%", onClick = actions.openCoach,
    )
    if (me != null && me?.status != "verified") slides += HeroSlide(
        "verify", stringResource(R.string.home_slide_verify_title), stringResource(R.string.home_slide_verify_body), stringResource(R.string.home_slide_verify_cta),
        icon = Icons.Rounded.Badge, onClick = actions.openVerification,
    )
    if (h?.on("community") == true) slides += HeroSlide(
        "community", stringResource(R.string.home_slide_community_title), stringResource(R.string.home_slide_community_body), stringResource(R.string.home_slide_community_cta),
        icon = Icons.Rounded.Forum, onClick = { ShellState.open(Tabs.COMMUNITY) },
    )
    if (h?.on("subscriptions") == true && h.promos.none { it.targetType == "screen" && it.target == "plans" }) slides += HeroSlide(
        "plans", stringResource(R.string.home_slide_plans_title), stringResource(R.string.home_slide_plans_body), stringResource(R.string.home_slide_plans_cta),
        icon = Icons.Rounded.WorkspacePremium, onClick = actions.openPlans,
    )
    return slides
}

/** Where a banner's tap leads: a page of the app (by its key), a web page, or nowhere. */
private fun promoAction(p: PromoDto, actions: MainActions, openUrl: (String) -> Unit): (() -> Unit)? = when (p.targetType) {
    "url" -> p.target?.takeIf { it.startsWith("https://") }?.let { url -> { openUrl(url) } }
    "screen" -> when (p.target) {
        "projects" -> { { ShellState.open(Tabs.OPPORTUNITIES, "projects") } }
        "invitations" -> { { ShellState.open(Tabs.OPPORTUNITIES, "invitations") } }
        "community" -> { { ShellState.open(Tabs.COMMUNITY) } }
        "plans" -> actions.openPlans
        "insights" -> actions.openInsights
        "services" -> actions.openServices
        "consultations" -> actions.openConsultations
        "special_offers" -> actions.openSpecialOffers
        "loyalty" -> actions.openLoyalty
        "skill_tests" -> actions.openSkillTests
        "verification" -> actions.openVerification
        "coach" -> actions.openCoach
        "portfolio" -> actions.openPortfolio
        "wallet" -> actions.openWallet
        "availability" -> actions.openAvailability
        "capacity" -> actions.openCapacity
        "teams" -> actions.openTeams
        else -> null
    }
    else -> null
}

/** The slider: one card at a time, swiped by hand (it never moves by itself), with the dots under it. */
@Composable
private fun HeroSlider(slides: List<HeroSlide>) {
    if (slides.isEmpty()) return
    val pager = rememberPagerState(pageCount = { slides.size })
    Column(Modifier.fillMaxWidth()) {
        HorizontalPager(state = pager, contentPadding = PaddingValues(horizontal = 16.dp), pageSpacing = 10.dp, key = { slides[it].key }) { page ->
            HeroCard(slides[page])
        }
        if (slides.size > 1) Row(Modifier.fillMaxWidth().padding(top = 10.dp, bottom = 4.dp), horizontalArrangement = Arrangement.Center) {
            repeat(slides.size) { i ->
                val on = pager.currentPage == i
                val w by animateDpAsState(if (on) 18.dp else 6.dp, tween(220), label = "dot")
                Box(Modifier.padding(horizontal = 3.dp).height(6.dp).width(w).clip(CircleShape).background(if (on) settingsAccent() else settingsMut().copy(alpha = 0.35f)))
            }
        }
        Spacer(Modifier.height(10.dp))
    }
}

@Composable
private fun HeroCard(s: HeroSlide) {
    val accent = settingsAccent()
    val deep = Color(accent.red * 0.62f, accent.green * 0.62f, accent.blue * 0.62f)
    Box(
        Modifier.fillMaxWidth().height(156.dp * androidx.compose.ui.platform.LocalDensity.current.fontScale.coerceIn(1f, 1.6f))
            .semantics(mergeDescendants = true) {}
            .clip(Corners.hero)
            .background(Brush.linearGradient(listOf(accent, deep)))
            .then(if (s.onClick != null) Modifier.clickable(onClick = s.onClick) else Modifier),
    ) {
        if (s.image != null) {
            AsyncImage(model = s.image, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
            // A shade from the text's side keeps the words readable on any picture.
            Box(Modifier.fillMaxSize().background(Brush.horizontalGradient(listOf(Color.Black.copy(alpha = 0.05f), Color.Black.copy(alpha = 0.60f)))))
        } else {
            // Soft rings of light on the plain colour.
            Box(Modifier.size(170.dp).align(Alignment.TopStart).padding(start = 0.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.08f)))
            Box(Modifier.size(90.dp).align(Alignment.BottomStart).clip(CircleShape).background(Color.White.copy(alpha = 0.07f)))
        }
        Row(Modifier.fillMaxSize().padding(18.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f).fillMaxHeight(), verticalArrangement = Arrangement.Center) {
                Text(s.title, color = Color.White, fontSize = 18.sp, fontWeight = FontWeight.Black, maxLines = 2, overflow = TextOverflow.Ellipsis, lineHeight = 24.sp)
                s.body?.takeIf { it.isNotBlank() }?.let { Text(it, color = Color.White.copy(alpha = 0.88f), fontSize = 12.5.sp, maxLines = 2, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 4.dp)) }
                s.cta?.takeIf { it.isNotBlank() && s.onClick != null }?.let {
                    Text(
                        it, color = accent, fontSize = 12.sp, fontWeight = FontWeight.ExtraBold,
                        modifier = Modifier.padding(top = 10.dp).clip(RoundedCornerShape(50)).background(Color.White).padding(horizontal = 14.dp, vertical = 7.dp),
                    )
                }
            }
            if (s.image == null && (s.icon != null || s.big != null)) {
                Spacer(Modifier.width(12.dp))
                Box(Modifier.size(76.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.16f)), contentAlignment = Alignment.Center) {
                    if (s.big != null) Text(s.big, color = Color.White, fontSize = 20.sp, fontWeight = FontWeight.Black)
                    else s.icon?.let { Icon(it, null, tint = Color.White, modifier = Modifier.size(34.dp)) }
                }
            }
        }
    }
}

// ---------------------------------------------------------------- numbers

@Composable
private fun StatsRow(h: HomeDto, actions: MainActions) {
    val s = h.stats
    val money = { minor: Long -> formatMinor(minor, null) + (s.currency?.let { " ${when (it.uppercase()) { "SAR" -> "﷼"; "USD" -> "$"; else -> it }}" } ?: "") }
    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
        SectionHeader(stringResource(R.string.home_numbers), action = stringResource(R.string.home_more_numbers), onAction = actions.openInsights)
        Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            StatTile(Icons.Rounded.Payments, "‎" + money(s.earnedMonthMinor), stringResource(R.string.home_stat_earned_month), Tints.green, Modifier.width(150.dp), actions.openWallet)
            if (s.pendingMinor > 0) StatTile(Icons.Rounded.HourglassTop, "‎" + money(s.pendingMinor), stringResource(R.string.home_stat_pending), Tints.amber, Modifier.width(150.dp), actions.openWallet)
            StatTile(Icons.Rounded.Send, s.liveOffers.toString(), stringResource(R.string.home_stat_offers), Tints.blue, Modifier.width(118.dp)) { ShellState.open(Tabs.WORK, "offers") }
            StatTile(Icons.Rounded.Work, s.activeProjects.toString(), stringResource(R.string.home_stat_projects), Tints.violet, Modifier.width(118.dp)) { ShellState.open(Tabs.WORK, "projects") }
            StatTile(
                Icons.Rounded.Star, s.rating?.let { String.format(Locale.US, "%.1f", it) } ?: "—",
                if (s.reviewsCount > 0) stringResource(R.string.home_stat_rating_n, s.reviewsCount) else stringResource(R.string.home_stat_rating), Tints.amber, Modifier.width(118.dp), actions.openInsights,
            )
        }
    }
}

// ---------------------------------------------------------------- the next consultation

/** «In 2 hours» / «Now» / a day and an hour, from the server's start time against the phone's clock. */
@Composable
private fun startsIn(iso: String?): String {
    val at = runCatching { OffsetDateTime.parse(iso) }.getOrNull() ?: return ""
    val minutes = Duration.between(OffsetDateTime.now(), at).toMinutes()
    return when {
        minutes <= 0 -> stringResource(R.string.home_cns_now)
        minutes < 60 -> stringResource(R.string.home_in_minutes, minutes.toInt())
        minutes < 24 * 60 -> stringResource(R.string.home_in_hours, (minutes / 60).toInt())
        else -> DateTimeFormatter.ofPattern("EEEE d MMM · HH:mm", com.dorr.freelance.net.AppLocale.java()).format(at.atZoneSameInstant(ZoneId.systemDefault()))
    }
}

@Composable
private fun NextConsultationCard(c: HomeConsultationDto, serverTime: String?, onOpen: () -> Unit) {
    val pending = c.status == "pending"
    val tint = if (pending) Tints.amber else Tints.violet
    Row(
        Modifier.fillMaxWidth().appCard().clickable(onClick = onOpen).padding(14.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Box(Modifier.size(48.dp).clip(RoundedCornerShape(16.dp)).background(tint.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.VideoChat, null, tint = tint, modifier = Modifier.size(24.dp))
        }
        Column(Modifier.weight(1f)) {
            Text(
                stringResource(if (pending) R.string.cns_pending_home else R.string.cns_next_banner, c.clientName.orEmpty()),
                color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis,
            )
            c.title?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis) }
        }
        Row(
            Modifier.clip(RoundedCornerShape(50)).background(tint.copy(alpha = 0.14f)).padding(horizontal = 10.dp, vertical = 6.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.AccessTime, null, tint = tint, modifier = Modifier.size(13.dp))
            Spacer(Modifier.width(4.dp))
            Text(startsIn(c.startsAt), color = tint, fontSize = 11.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
        }
    }
}

// ---------------------------------------------------------------- my work

@Composable
private fun WorkRow(w: HomeWorkDto, onOpen: () -> Unit) {
    val m = w.milestone
    val left = m?.dueAt?.let { runCatching { Duration.between(OffsetDateTime.now(), OffsetDateTime.parse(it)).toDays() }.getOrNull() }
    val late = left != null && left < 0
    val tint = when {
        m?.status == "revision" || late -> Tints.rose
        m?.status == "delivered" -> Tints.green
        else -> Tints.blue
    }
    Row(
        Modifier.fillMaxWidth().appCard().clickable(onClick = onOpen).padding(14.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Box(Modifier.size(44.dp).clip(RoundedCornerShape(14.dp)).background(tint.copy(alpha = 0.13f)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.Assignment, null, tint = tint, modifier = Modifier.size(22.dp))
        }
        Column(Modifier.weight(1f)) {
            Text(w.title.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(
                listOfNotNull(m?.title, w.clientName).joinToString(" · "),
                color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis,
            )
        }
        val label = when {
            m?.status == "revision" -> stringResource(R.string.home_work_revision)
            m?.status == "delivered" -> stringResource(R.string.home_work_delivered)
            late -> stringResource(R.string.home_work_late)
            left != null -> if (left == 0L) stringResource(R.string.home_work_today) else stringResource(R.string.home_work_days_left, left.toInt())
            else -> null
        }
        label?.let {
            Text(
                it, color = tint, fontSize = 11.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1,
                modifier = Modifier.clip(RoundedCornerShape(50)).background(tint.copy(alpha = 0.13f)).padding(horizontal = 10.dp, vertical = 5.dp),
            )
        }
    }
}

// ---------------------------------------------------------------- projects that fit me

@Composable
private fun PickCard(p: ProjectDto, onOpen: () -> Unit) {
    Column(
        Modifier.width(250.dp).height(150.dp).settingsSurface(Corners.card, 4.dp).clickable(onClick = onOpen).padding(14.dp),
        verticalArrangement = Arrangement.SpaceBetween,
    ) {
        Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                p.specialty?.name?.let {
                    Text(
                        it, color = settingsAccent(), fontSize = 10.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis,
                        modifier = Modifier.weight(1f, fill = false).clip(RoundedCornerShape(50)).background(settingsAccent().copy(alpha = 0.10f)).padding(horizontal = 8.dp, vertical = 3.dp),
                    )
                }
                Spacer(Modifier.weight(1f))
                if (p.offered) Icon(Icons.Rounded.CheckCircle, stringResource(R.string.home_pick_offered), tint = Tints.green, modifier = Modifier.size(16.dp))
            }
            Text(p.title.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis, lineHeight = 19.sp)
        }
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(budgetText(p.budget) ?: "", color = settingsInk(), fontSize = 12.5.sp, fontWeight = FontWeight.Black, modifier = Modifier.weight(1f), maxLines = 1)
            publishedAgo(p.publishedAt)?.let { Text(it, color = settingsMut(), fontSize = 11.sp, maxLines = 1) }
        }
    }
}

// ---------------------------------------------------------------- my tools

private data class Tool(val icon: ImageVector, val label: Int, val tint: Color, val count: Int = 0, val onClick: () -> Unit)

@Composable
private fun ToolsGrid(h: HomeDto, actions: MainActions) {
    val tools = buildList {
        if (h.on("services")) add(Tool(Icons.Rounded.Storefront, R.string.svc_home_card, Tints.teal, h.attention.ordersAwaiting, actions.openServices))
        if (h.on("consultations")) add(Tool(Icons.Rounded.VideoChat, R.string.cns_home_card, Tints.violet, h.attention.consultationsPending, actions.openConsultations))
        if (h.on("special_offers")) add(Tool(Icons.Rounded.LocalOffer, R.string.spo_home_card, Tints.rose, onClick = actions.openSpecialOffers))
        if (h.on("loyalty")) add(Tool(Icons.Rounded.Loyalty, R.string.loy_home_card, Tints.amber, onClick = actions.openLoyalty))
        add(Tool(Icons.Rounded.Insights, R.string.insights_home_card, Tints.blue, onClick = actions.openInsights))
        if (h.on("subscriptions")) add(Tool(Icons.Rounded.WorkspacePremium, R.string.plans_title, Tints.amber, onClick = actions.openPlans))
        add(Tool(Icons.Rounded.EventAvailable, R.string.availability_title, Tints.green, onClick = actions.openAvailability))
        if (h.on("skill_tests")) add(Tool(Icons.Rounded.Quiz, R.string.skt_home_card, Tints.blue, onClick = actions.openSkillTests))
        if (h.on("teams")) add(Tool(Icons.Rounded.Groups, R.string.team_home_card, Tints.violet, onClick = actions.openTeams))
    }
    if (tools.isEmpty()) return
    SectionHeader(stringResource(R.string.home_tools))
    tools.chunked(4).forEach { row ->
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            row.forEach { t -> ShortcutTile(t.icon, stringResource(t.label), Modifier.weight(1f), tint = t.tint, count = t.count, onClick = t.onClick) }
            repeat(4 - row.size) { Spacer(Modifier.weight(1f)) }
        }
    }
}

// ---------------------------------------------------------------- a new freelancer's first steps

@Composable
private fun StartJourney(percent: Int, status: String?, h: HomeDto, actions: MainActions) {
    val steps = listOf(
        Triple(R.string.home_step_profile, percent >= 80, actions.openCoach),
        Triple(R.string.home_step_portfolio, percent >= 100, actions.openPortfolio),
        Triple(R.string.home_step_verify, status == "verified" || status == "verification_submitted", actions.openVerification),
        Triple(R.string.home_step_offer, h.stats.liveOffers > 0 || h.stats.activeProjects > 0, { ShellState.open(Tabs.OPPORTUNITIES, "projects") }),
    )
    val done = steps.count { it.second }
    Column(Modifier.fillMaxWidth().appCard(Corners.hero).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(40.dp).clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Rocket, null, tint = settingsAccent(), modifier = Modifier.size(22.dp))
            }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.home_start_title), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                Text(stringResource(R.string.home_start_progress, done, steps.size), color = settingsMut(), fontSize = 12.sp)
            }
        }
        steps.forEach { (label, ok, open) ->
            Row(
                Modifier.fillMaxWidth().clip(Corners.small).clickable(enabled = !ok, onClick = open).padding(vertical = 6.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(if (ok) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (ok) Tints.green else settingsMut(), modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(10.dp))
                Text(
                    stringResource(label), color = if (ok) settingsMut() else settingsInk(), fontSize = 13.sp,
                    fontWeight = if (ok) FontWeight.Medium else FontWeight.Bold, modifier = Modifier.weight(1f),
                )
            }
        }
    }
}

// ---------------------------------------------------------------- helpers

/** A section that rises in softly the first time the home shows, one after the other. */
@Composable
private fun Reveal(index: Int, content: @Composable () -> Unit) {
    var shown by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { shown = true }
    AnimatedVisibility(shown, enter = fadeIn(tween(360, delayMillis = index * 70)) + slideInVertically(tween(360, delayMillis = index * 70)) { it / 6 }) { content() }
}

/** The account status in the app language; an unknown one is shown as the server sent it. */
@Composable
internal fun statusLabel(status: String?): String = when (status) {
    "registered" -> stringResource(R.string.status_registered)
    "profile_ready" -> stringResource(R.string.status_profile_ready)
    "verification_submitted" -> stringResource(R.string.status_verification_submitted)
    "verified" -> stringResource(R.string.status_verified)
    "verification_rejected" -> stringResource(R.string.status_verification_rejected)
    "changes_requested" -> stringResource(R.string.status_changes_requested)
    "suspended" -> stringResource(R.string.status_suspended)
    "blocked" -> stringResource(R.string.status_blocked)
    else -> status.orEmpty()
}

/** A short notice on the home while the freelancer is not taking new offers (paused or on time off); a tap opens the availability page. */
@Composable
private fun PausedCard(onOpen: () -> Unit) {
    val night = settingsNight()
    Row(
        Modifier.fillMaxWidth().clip(Corners.card).background(if (night) AccountDark.card else Tints.amber.copy(alpha = 0.12f)).clickable(onClick = onOpen).padding(14.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Icon(Icons.Rounded.EventBusy, contentDescription = null, tint = Tints.amber, modifier = Modifier.size(22.dp))
        Text(stringResource(R.string.availability_paused_banner), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
    }
}
