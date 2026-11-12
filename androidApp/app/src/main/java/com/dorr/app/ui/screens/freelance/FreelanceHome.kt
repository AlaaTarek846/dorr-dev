package com.dorr.app.ui.screens.freelance

import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
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
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Build
import androidx.compose.material.icons.rounded.EventAvailable
import androidx.compose.material.icons.rounded.Favorite
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.GroupAdd
import androidx.compose.material.icons.rounded.FactCheck
import androidx.compose.material.icons.rounded.LocalOffer
import androidx.compose.material.icons.rounded.Loyalty
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.Receipt
import androidx.compose.material.icons.rounded.RequestQuote
import androidx.compose.material.icons.rounded.Storefront
import androidx.compose.material.icons.rounded.VideoChat
import androidx.compose.material.icons.rounded.Work
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
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
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.FlHomeConsultationDto
import com.dorr.app.network.FlHomeDto
import com.dorr.app.network.FlPromoDto
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.PullToRefreshHost
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsSurface
import kotlinx.coroutines.CancellationException
import java.time.Instant
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

/**
 * The hub of the client's freelance section (the page it opens on; there is no bottom bar), fed by one call to `GET home`: what waits for
 * me, the staff's banners, «Post a project», my numbers (or how it works, for a new client), the next consultation, my running projects,
 * the sections and my tools, and a row of suggested services. A section whose feature is off in my country does not show. Read on open, on
 * a pull, when a freelance notice arrives and when the connection comes back — nothing on a timer.
 */
@Composable
internal fun FreelanceHome(version: Int, onExit: () -> Unit, open: (FlRoute) -> Unit) {
    val reconnect = collectReconnectTick()
    var home by remember { mutableStateOf<FlHomeDto?>(null) }
    var failed by remember { mutableStateOf(false) }
    // Coming back from the freelance wallet reads the hub once more (its balance may have changed).
    var walletTick by remember { mutableStateOf(0) }
    LaunchedEffect(reconnect, version, walletTick) {
        try {
            home = ApiClient.freelanceHome.home(auth()).data
            failed = false
        } catch (e: CancellationException) {
            throw e
        } catch (_: Exception) {
            failed = home == null
        }
    }
    val h = home
    // Before the first answer every section shows; after it, only those whose feature is on.
    fun on(feature: String): Boolean = h == null || h.on(feature)

    Column(Modifier.fillMaxSize()) {
        FlPageHeader(stringResource(R.string.flh_title), onExit, subtitle = stringResource(R.string.flh_subtitle))
        PullToRefreshHost(Modifier.weight(1f).fillMaxWidth()) {
            Column(
                Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(top = 4.dp, bottom = 24.dp),
                verticalArrangement = Arrangement.spacedBy(14.dp),
            ) {
                Column(Modifier.padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
                    // FL-708: shown only while a warning / restriction is active or its appeal waits.
                    SanctionsCard(version)
                    if (h != null) AttentionStrip(h, open)
                    // FL-L09: only while offers wait.
                    SpecialOffersBanner(h?.attention?.specialOffers ?: 0) { open(FlRoute.SpecialOffers) }
                }
                if (h != null && h.promos.isNotEmpty()) PromoSlider(h.promos, open)
                Column(Modifier.padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
                    // A banner already sits above (often the same ask), so the card waits for the hub to say there is none; it stays when the read failed.
                    if (h?.promos?.isEmpty() == true || (h == null && failed)) NewProjectCard { open(FlRoute.NewProject) }
                    when {
                        h == null && !failed -> HomeSkeleton()
                        h == null -> FlRetry(stringResource(R.string.fl_error_generic))
                        h.isNew -> StartJourney(servicesOn = h.on("services"), onPost = { open(FlRoute.NewProject) }, onServices = { open(FlRoute.Services) })
                        else -> Numbers(h, open)
                    }
                    // The freelance wallet (dollars): every freelance payment comes from it; a tap opens it over the hub.
                    if (h != null) WalletCard(h) { com.dorr.app.ui.screens.wallet.TopupLauncher.openInternationalWallet { walletTick++ } }
                    h?.nextConsultation?.let { NextConsultation(it) { open(FlRoute.Consultation(it.uuid)) } }
                    if (h != null && h.projects.isNotEmpty()) {
                        FlSectionHeader(stringResource(R.string.flh_running), Modifier.padding(top = 4.dp), action = stringResource(R.string.flh_see_all)) { open(FlRoute.Projects) }
                        h.projects.forEach { p -> ProjectCard(p) { open(FlRoute.Project(p.uuid)) } }
                    }

                    FlSectionHeader(stringResource(R.string.flh_sections), Modifier.padding(top = 4.dp))
                    val sections = buildList {
                        add(Tile(Icons.Rounded.Work, stringResource(R.string.fl_my_projects), stringResource(R.string.flh_projects_line), FlTints.projects, FlRoute.Projects))
                        if (on("services")) add(Tile(Icons.Rounded.Storefront, stringResource(R.string.flh_services), stringResource(R.string.flh_services_line), FlTints.services, FlRoute.Services))
                        if (on("consultations")) add(Tile(Icons.Rounded.VideoChat, stringResource(R.string.cs_market_title), stringResource(R.string.flh_consult_line), FlTints.consultations, FlRoute.ConsultMarket))
                        // «My activity»: my orders, consultations and special offers.
                        add(Tile(Icons.Rounded.Receipt, stringResource(R.string.fla_activity), stringResource(R.string.fla_activity_line), FlTints.orders, FlRoute.Orders))
                    }
                    sections.chunked(2).forEach { pair ->
                        Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                            pair.forEach { t -> FlHubTile(t.icon, t.title, t.line, t.tint, Modifier.weight(1f)) { open(t.route) } }
                            if (pair.size == 1) Spacer(Modifier.weight(1f))
                        }
                    }

                    FlSectionHeader(stringResource(R.string.flh_tools), Modifier.padding(top = 4.dp))
                    val tools = buildList {
                        if (on("consultations")) add(Tool(Icons.Rounded.EventAvailable, stringResource(R.string.cs_mine_title), FlTints.mine, 0, FlRoute.Consultations))
                        if (on("special_offers")) add(Tool(Icons.Rounded.LocalOffer, stringResource(R.string.spc_title), FlTints.special, h?.attention?.specialOffers ?: 0, FlRoute.SpecialOffers))
                        if (on("loyalty")) add(Tool(Icons.Rounded.Loyalty, stringResource(R.string.loyc_title), FlTints.loyalty, 0, FlRoute.Loyalty))
                        add(Tool(Icons.Rounded.Favorite, stringResource(R.string.flh_favorites), FlTints.favorites, 0, FlRoute.Favorites))
                        add(Tool(Icons.Rounded.Groups, stringResource(R.string.fs_tp_title), FlTints.favorites, 0, FlRoute.TalentLists))
                        // FS-104: the projects others brought me into (the badge: invitations waiting for my answer).
                        if (on("client_team")) add(Tool(Icons.Rounded.GroupAdd, stringResource(R.string.fs_tm_shared_tool), FlTints.mine, h?.team?.invitations ?: 0, FlRoute.Shared))
                        add(Tool(Icons.Rounded.Receipt, stringResource(R.string.flr_title), FlTints.projects, 0, FlRoute.Receipts))
                    }
                    // Three to a row, so the names keep room.
                    tools.chunked(3).forEach { row ->
                        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                            row.forEach { t -> FlToolTile(t.icon, t.title, t.tint, Modifier.weight(1f), badge = t.badge) { open(t.route) } }
                            repeat(3 - row.size) { Spacer(Modifier.weight(1f)) }
                        }
                    }
                }
                if (h != null && h.services.isNotEmpty()) {
                    FlSectionHeader(stringResource(R.string.flh_suggested), Modifier.padding(horizontal = 16.dp).padding(top = 4.dp), action = stringResource(R.string.flh_see_all)) { open(FlRoute.Services) }
                    LazyRow(contentPadding = androidx.compose.foundation.layout.PaddingValues(horizontal = 16.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                        items(h.services, key = { it.uuid }) { s -> ServiceTile(s, Modifier.width(250.dp)) { open(FlRoute.Service(s.uuid)) } }
                    }
                }
            }
        }
    }
}

private class Tool(val icon: ImageVector, val title: String, val tint: Color, val badge: Int, val route: FlRoute)

private class Tile(val icon: ImageVector, val title: String, val line: String, val tint: Color, val route: FlRoute)

/**
 * What waits for me, as a row of chips coloured by how much it matters: a delivery to review, a part to fund, new offers, a project sent
 * back, special offers. One of a kind opens its project's page; more open my projects.
 */
@Composable
private fun AttentionStrip(h: FlHomeDto, open: (FlRoute) -> Unit) {
    val a = h.attention
    val chips = buildList {
        if (a.toReview.count > 0) add(WaitChip(Icons.Rounded.FactCheck, stringResource(R.string.flh_att_review), a.toReview.count, Color(0xFF7C3AED),
            a.toReview.projectUuid?.takeIf { a.toReview.count == 1 }?.let { FlRoute.Agreement(it) } ?: FlRoute.Projects))
        if (a.toFund.count > 0) add(WaitChip(Icons.Rounded.Payments, stringResource(R.string.flh_att_fund), a.toFund.count, Color(0xFFD97706),
            a.toFund.projectUuid?.takeIf { a.toFund.count == 1 }?.let { FlRoute.Agreement(it) } ?: FlRoute.Projects))
        if (a.newOffers.count > 0) add(WaitChip(Icons.Rounded.RequestQuote, stringResource(R.string.flh_att_offers), a.newOffers.count, Color(0xFF16A34A),
            a.newOffers.projectUuid?.let { FlRoute.Offers(it) }?.takeIf { a.newOffers.count == 1 } ?: FlRoute.Projects))
        if (a.toFix.count > 0) add(WaitChip(Icons.Rounded.Build, stringResource(R.string.flh_att_fix), a.toFix.count, Color(0xFF2563EB),
            a.toFix.projectUuid?.takeIf { a.toFix.count == 1 }?.let { FlRoute.Project(it) } ?: FlRoute.Projects))
    }
    if (chips.isEmpty()) return
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(stringResource(R.string.flh_att_title), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
        Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            chips.forEach { c ->
                Row(
                    Modifier.clip(RoundedCornerShape(14.dp)).background(c.tint.copy(alpha = 0.12f)).clickable(role = Role.Button) { open(c.route) }
                        .padding(horizontal = 12.dp, vertical = 10.dp).semantics(mergeDescendants = true) {},
                    verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(7.dp),
                ) {
                    Icon(c.icon, null, tint = c.tint, modifier = Modifier.size(18.dp))
                    Text(c.text, color = c.tint, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
                    Text(
                        c.count.toString(), color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.ExtraBold,
                        modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(c.tint).padding(horizontal = 7.dp, vertical = 1.dp),
                    )
                }
            }
        }
    }
}

private class WaitChip(val icon: ImageVector, val text: String, val count: Int, val tint: Color, val route: FlRoute)

/** The staff's banners, one at a time, moved by a swipe (never on a timer); a tap opens a page of the hub or a web page. */
@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun PromoSlider(promos: List<FlPromoDto>, open: (FlRoute) -> Unit) {
    val pager = rememberPagerState { promos.size }
    val uri = LocalUriHandler.current
    val accent = settingsAccent()
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        HorizontalPager(pager, contentPadding = androidx.compose.foundation.layout.PaddingValues(horizontal = 16.dp), pageSpacing = 10.dp) { i ->
            val p = promos[i]
            val route = promoRoute(p)
            val tappable = route != null || (p.targetType == "url" && !p.target.isNullOrBlank())
            Box(
                Modifier.fillMaxWidth().height(150.dp).semantics(mergeDescendants = true) {}.clip(FlCorners.card)
                    .background(Brush.linearGradient(listOf(accent, accent.copy(alpha = 0.7f))))
                    .then(if (tappable) Modifier.clickable(role = Role.Button) { if (route != null) open(route) else runCatching { uri.openUri(p.target!!) } } else Modifier),
            ) {
                ApiClient.mediaUrl(p.imagePath)?.let { url ->
                    AsyncImage(model = url, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                    Box(Modifier.fillMaxSize().background(Brush.horizontalGradient(listOf(Color.Black.copy(alpha = 0.55f), Color.Black.copy(alpha = 0.05f)))))
                }
                Column(Modifier.align(Alignment.BottomStart).padding(16.dp).fillMaxWidth(0.8f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(p.title.orEmpty(), color = Color.White, fontSize = 17.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                    if (!p.body.isNullOrBlank()) Text(p.body, color = Color.White.copy(alpha = 0.9f), fontSize = 12.5.sp, lineHeight = 17.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
                    if (!p.cta.isNullOrBlank() && tappable) Text(
                        p.cta, color = accent, fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold,
                        modifier = Modifier.padding(top = 4.dp).clip(RoundedCornerShape(999.dp)).background(Color.White).padding(horizontal = 12.dp, vertical = 5.dp),
                    )
                }
            }
        }
        if (promos.size > 1) Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.Center) {
            repeat(promos.size) { i ->
                val on = i == pager.currentPage
                Box(Modifier.padding(horizontal = 3.dp).size(width = if (on) 18.dp else 6.dp, height = 6.dp).clip(CircleShape).background(if (on) accent else settingsMut().copy(alpha = 0.35f)))
            }
        }
    }
}

/** The page of the hub a banner opens (the names of `FreelancePromo::CLIENT_SCREENS`); null for none, a link or an unknown page. */
private fun promoRoute(p: FlPromoDto): FlRoute? = if (p.targetType != "screen") null else when (p.target) {
    "projects" -> FlRoute.Projects
    "new_project" -> FlRoute.NewProject
    "services" -> FlRoute.Services
    "orders" -> FlRoute.Orders
    "consultations" -> FlRoute.ConsultMarket
    "my_consultations" -> FlRoute.Consultations
    "special_offers" -> FlRoute.SpecialOffers
    "loyalty" -> FlRoute.Loyalty
    "favorites" -> FlRoute.Favorites
    else -> null
}

/** My numbers, side by side (a row that scrolls): each opens its page. */
@Composable
private fun Numbers(h: FlHomeDto, open: (FlRoute) -> Unit) {
    FlSectionHeader(stringResource(R.string.flh_numbers))
    Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        FlStatTile(Icons.Rounded.Work, h.stats.runningProjects.toString(), stringResource(R.string.flh_stat_projects), FlTints.projects) { open(FlRoute.Projects) }
        FlStatTile(Icons.Rounded.RequestQuote, h.stats.newOffers.toString(), stringResource(R.string.flh_stat_offers), Color(0xFF16A34A)) {
            open(h.attention.newOffers.projectUuid?.takeIf { h.stats.newOffers == 1 }?.let { FlRoute.Offers(it) } ?: FlRoute.Projects)
        }
        if (h.on("services")) FlStatTile(Icons.Rounded.Receipt, h.stats.runningOrders.toString(), stringResource(R.string.flh_stat_orders), FlTints.orders) { open(FlRoute.Orders) }
        if (h.on("consultations")) FlStatTile(Icons.Rounded.VideoChat, h.stats.upcomingConsultations.toString(), stringResource(R.string.flh_stat_consults), FlTints.consultations) { open(FlRoute.Consultations) }
        FlStatTile(Icons.Rounded.Payments, mkMoney(h.stats.fundedMonthMinor, h.decimals, h.currency), stringResource(R.string.flh_stat_funded), FlTints.mine) { open(FlRoute.Projects) }
    }
}

/** For a client with nothing posted or ordered yet: how the work goes, in four steps, and the two ways to start. */
@Composable
private fun StartJourney(servicesOn: Boolean, onPost: () -> Unit, onServices: () -> Unit) {
    Column(Modifier.fillMaxWidth().settingsSurface(FlCorners.card).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text(stringResource(R.string.flh_journey_title), color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
        listOf(R.string.flh_step_1, R.string.flh_step_2, R.string.flh_step_3, R.string.flh_step_4).forEachIndexed { i, step ->
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Box(Modifier.size(28.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                    Text((i + 1).toString(), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
                }
                Text(stringResource(step), color = settingsInk(), fontSize = 13.5.sp, lineHeight = 19.sp, modifier = Modifier.weight(1f))
            }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            FlAction(stringResource(R.string.flh_new_action), Icons.Rounded.Add, FlTone.Primary, modifier = Modifier.weight(1f), onClick = onPost)
            if (servicesOn) FlAction(stringResource(R.string.flh_services), Icons.Rounded.Storefront, modifier = Modifier.weight(1f), onClick = onServices)
        }
    }
}

/** The freelance wallet: its balance in dollars, and a tap to open it (top up, statement, withdraw). */
@Composable
private fun WalletCard(h: FlHomeDto, onOpen: () -> Unit) {
    val tint = Color(0xFF16A34A)
    Row(
        Modifier.fillMaxWidth().settingsSurface(FlCorners.card).clickable(role = Role.Button, onClick = onOpen).padding(14.dp).semantics(mergeDescendants = true) {},
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Box(Modifier.size(46.dp).clip(FlCorners.icon).background(tint.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.AccountBalanceWallet, null, tint = tint, modifier = Modifier.size(24.dp))
        }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(stringResource(R.string.fl_intl_wallet), color = settingsInk(), fontSize = 14.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(stringResource(R.string.fl_intl_wallet_sub), color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        Text("‎" + mkMoney(h.walletMinor, h.decimals, h.currency), color = tint, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
    }
}

/** My next consultation: when (on the phone's calendar), with whom, and whether the freelancer has said yes yet. */
@Composable
private fun NextConsultation(c: FlHomeConsultationDto, onOpen: () -> Unit) {
    val tint = FlTints.consultations
    Row(
        Modifier.fillMaxWidth().settingsSurface(FlCorners.card).clickable(role = Role.Button, onClick = onOpen).padding(14.dp).semantics(mergeDescendants = true) {},
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Box(Modifier.size(46.dp).clip(FlCorners.icon).background(tint.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.VideoChat, null, tint = tint, modifier = Modifier.size(24.dp))
        }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(stringResource(R.string.flh_next_consult), color = tint, fontSize = 12.sp, fontWeight = FontWeight.ExtraBold)
            Text(c.title ?: stringResource(R.string.cs_mine_title), color = settingsInk(), fontSize = 14.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            val line = listOfNotNull(whenText(c.startsAt), c.freelancerName?.let { stringResource(R.string.flh_with, it) }).joinToString(" · ")
            if (line.isNotEmpty()) Text(line, color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            if (c.status == "pending") Text(stringResource(R.string.flh_consult_pending), color = Color(0xFFD97706), fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
        }
    }
}

/** «Tue 4 Nov · 15:30» on the phone's own clock and language. */
private fun whenText(iso: String?): String? {
    val at = runCatching { Instant.parse(iso) }.getOrNull() ?: runCatching { OffsetDateTime.parse(iso).toInstant() }.getOrNull() ?: return null
    return DateTimeFormatter.ofPattern("EEE d MMM · HH:mm", Locale.getDefault()).format(at.atZone(ZoneId.systemDefault()))
}

/** The hub while its first answer is on its way: a row of numbers and two cards, in grey. */
@Composable
private fun HomeSkeleton() {
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        WaSkeleton(Modifier.width(110.dp).height(16.dp), RoundedCornerShape(8.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) { repeat(3) { WaSkeleton(Modifier.weight(1f).height(92.dp), RoundedCornerShape(18.dp)) } }
        repeat(2) { WaSkeleton(Modifier.fillMaxWidth().height(96.dp), RoundedCornerShape(20.dp)) }
    }
}

/** The first thing under what waits: posting a project, on the app's colour. */
@Composable
private fun NewProjectCard(onClick: () -> Unit) {
    val accent = settingsAccent()
    Column(
        Modifier.fillMaxWidth().clip(FlCorners.card).background(Brush.linearGradient(listOf(accent, accent.copy(alpha = 0.78f))))
            .clickable(role = Role.Button, onClick = onClick).padding(18.dp).semantics(mergeDescendants = true) {},
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Text(stringResource(R.string.flh_new_title), color = Color.White, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
        Text(stringResource(R.string.flh_new_body), color = Color.White.copy(alpha = 0.9f), fontSize = 13.sp, lineHeight = 19.sp)
        Spacer(Modifier.height(6.dp))
        Row(
            Modifier.clip(RoundedCornerShape(999.dp)).background(Color.White).padding(horizontal = 16.dp, vertical = 9.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.Add, null, tint = accent, modifier = Modifier.size(18.dp))
            Box(Modifier.size(6.dp))
            Text(stringResource(R.string.flh_new_action), color = accent, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
        }
    }
}
