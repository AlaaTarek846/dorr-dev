package com.dorr.app.ui.screens.moving

import android.widget.Toast
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.EditNote
import androidx.compose.material.icons.rounded.LocalOffer
import androidx.compose.material.icons.rounded.LocalShipping
import androidx.compose.material.icons.rounded.LocationOff
import androidx.compose.material.icons.rounded.VerifiedUser
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.MovingRequestDto
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.network.movingRefusal
import com.dorr.app.ui.components.PullToRefreshHost
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.screens.rides.AccentButton
import com.dorr.app.ui.screens.rides.PanelCard
import com.dorr.app.ui.screens.rides.TripLine
import com.dorr.app.ui.screens.rides.parseTime
import com.dorr.app.ui.screens.rides.pressable
import com.dorr.app.ui.screens.rides.rememberWhenLabels
import com.dorr.app.ui.screens.rides.staggerIn
import com.dorr.app.ui.screens.rides.whenText
import com.dorr.app.ui.theme.AppColors
import com.dorr.app.ui.theme.appearanceColor
import kotlinx.coroutines.launch

/**
 * The moving home: a hero that says what the service does (the truck on its road), how it works in three steps, the
 * button for a new request, then «طلباتي» with the "My rides" pill tabs and each request as its history card. Where the
 * service is off in this country (`options.available`) the rides' unavailable card takes the place of the button,
 * before any wizard opens; requests already made can still be followed.
 */
@Composable
internal fun MovingHomePage(nav: MovingNav, onExit: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val tabs = listOf("active" to stringResource(R.string.mv_tab_active), "all" to stringResource(R.string.mv_tab_all))
    var tab by remember { mutableStateOf("active") }
    var rows by remember { mutableStateOf<List<MovingRequestDto>?>(null) }
    var page by remember { mutableIntStateOf(1) }
    var hasMore by remember { mutableStateOf(false) }
    var loadingMore by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val failed = stringResource(R.string.rd_error)
    val tick = collectReconnectTick()
    var checked by remember { mutableStateOf(false) }
    var reloadKey by remember { mutableIntStateOf(0) }
    var deleting by remember { mutableStateOf<MovingRequestDto?>(null) }

    // Is the service open here? Asked on opening, so the button for a new request shows only when it is.
    LaunchedEffect(tick, nav.serviceOff) {
        if (!nav.serviceOff) {
            val options = runCatching { ApiClient.moving.options(mvAuth()).data }.getOrNull()
            if (options?.available == false) nav.serviceOff = true
        }
        checked = true
    }

    LaunchedEffect(tab, tick, reloadKey) {
        error = null
        runCatching { ApiClient.moving.requests(mvAuth(), tab.takeIf { it == "active" }, 1) }
            .onSuccess { envelope ->
                rows = envelope.data.orEmpty()
                page = 1
                hasMore = envelope.pagination?.hasMorePages == true
            }
            .onFailure {
                val refusal = it.movingRefusal()
                if (refusal.code == "moving_service_off") nav.serviceOff = true
                else {
                    error = refusal.message ?: failed
                    if (rows == null) rows = emptyList()
                }
            }
    }

    fun loadMore() {
        if (loadingMore || !hasMore) return
        loadingMore = true
        scope.launch {
            runCatching { ApiClient.moving.requests(mvAuth(), tab.takeIf { it == "active" }, page + 1) }
                .onSuccess { envelope ->
                    val known = rows.orEmpty().map { it.uuid }.toSet()
                    rows = rows.orEmpty() + envelope.data.orEmpty().filter { it.uuid !in known }
                    page += 1
                    hasMore = envelope.pagination?.hasMorePages == true
                }
                .onFailure { hasMore = false }
            loadingMore = false
        }
    }

    MvPage(title = stringResource(R.string.mv_title), onBack = onExit) {
        Column(Modifier.fillMaxSize()) {
            PullToRefreshHost(Modifier.weight(1f).fillMaxWidth()) {
                LazyColumn(
                    Modifier.fillMaxSize(),
                    contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 4.dp, bottom = 24.dp),
                    verticalArrangement = Arrangement.spacedBy(12.dp),
                ) {
                    item(key = "hero") { MovingHero(Modifier.staggerIn(0)) }
                    item(key = "how") { MovingHowItWorks(Modifier.staggerIn(1)) }
                    if (!nav.serviceOff && checked) item(key = "cta") {
                        Box(Modifier.staggerIn(2)) {
                            AccentButton(stringResource(R.string.mv_new_request), icon = Icons.Rounded.Add) { nav.push(MvRoute.Wizard(null)) }
                        }
                    }
                    item(key = "head") {
                        Column(Modifier.padding(top = 6.dp)) {
                            Text(stringResource(R.string.mv_my_requests), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
                            Spacer(Modifier.height(8.dp))
                            // The tabs: a pill that fills the chosen one.
                            Row(Modifier.fillMaxWidth().clip(CircleShape).background(settingsCard()).padding(4.dp)) {
                                tabs.forEach { (key, label) ->
                                    val on = key == tab
                                    val bg by animateColorAsState(if (on) settingsAccent() else Color.Transparent, tween(240), label = "mv-tab")
                                    Box(Modifier.weight(1f).clip(CircleShape).background(bg).clickable { if (!on) { tab = key; rows = null } }.padding(vertical = 10.dp), contentAlignment = Alignment.Center) {
                                        Text(label, color = if (on) Color.White else settingsInk(), fontWeight = if (on) FontWeight.Bold else FontWeight.SemiBold, fontSize = 14.sp)
                                    }
                                }
                            }
                        }
                    }
                    val list = rows
                    when {
                        list == null -> item(key = "loading") {
                            Box(Modifier.fillMaxWidth().height(140.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = settingsAccent()) }
                        }
                        list.isEmpty() -> item(key = "empty") {
                            MovingHomeEmpty(error ?: stringResource(if (nav.serviceOff) R.string.mv_empty_off else R.string.mv_empty))
                        }
                        else -> {
                            itemsIndexedStagger(list) { row ->
                                RequestCard(row, onDelete = if (row.status == "draft") ({ deleting = row }) else null) {
                                    nav.push(if (row.status == "draft") MvRoute.Wizard(row.uuid) else MvRoute.Request(row.uuid))
                                }
                            }
                            if (hasMore) item(key = "more") {
                                LaunchedEffect(page) { loadMore() }
                                Box(Modifier.fillMaxWidth().padding(vertical = 8.dp), contentAlignment = Alignment.Center) {
                                    CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(24.dp))
                                }
                            }
                        }
                    }
                }
            }
            if (nav.serviceOff) {
                MovingUnavailablePanel(
                    onRetry = {
                        checked = false
                        nav.serviceOff = false
                    },
                )
            }
        }
    }

    // A draft is taken back with `withdraw`: it leaves the running list, and «كل طلباتي» still shows it as withdrawn.
    deleting?.let { draft ->
        MovingWithdrawSheet(
            uuid = draft.uuid,
            title = stringResource(R.string.mv_draft_delete_title),
            body = stringResource(R.string.mv_draft_delete_body),
            action = stringResource(R.string.mv_draft_delete),
            onClose = { deleting = null },
            onDone = { message ->
                deleting = null
                message?.let { Toast.makeText(context, it, Toast.LENGTH_SHORT).show() }
                reloadKey += 1
            },
        )
    }
}

/** The hero's colours: the same navy-to-accent wash as the app's dark hero panels (calendar, Moments), by day and night. */
@Composable
private fun mvHeroBrush(): Brush = if (settingsNight()) {
    val accent = appearanceColor("primary", Color(0xFFFA7552), night = true)
    Brush.linearGradient(listOf(accent, Color(accent.red * 0.62f, accent.green * 0.62f, accent.blue * 0.62f), Color(accent.red * 0.40f, accent.green * 0.40f, accent.blue * 0.40f)))
} else {
    val primary = appearanceColor("primary", Color(0xFF001B53), night = false)
    val primaryLight = appearanceColor("primaryLight", Color(0xFF1E3A7B), night = false)
    val primaryDark = appearanceColor("primaryDark", Color(0xFF00113A), night = false)
    val accent = appearanceColor("accent", Color(0xFFFA7552), night = false)
    val top = Color(
        red = (primaryLight.red * 0.70f + accent.red * 0.30f).coerceIn(0f, 1f),
        green = (primaryLight.green * 0.70f + accent.green * 0.30f).coerceIn(0f, 1f),
        blue = (primaryLight.blue * 0.70f + accent.blue * 0.30f).coerceIn(0f, 1f),
    )
    Brush.linearGradient(listOf(top, primary, primaryDark))
}

/** The hero: what the service is in one line, and the truck driving on its road (it faces the reading direction). */
@Composable
private fun MovingHero(modifier: Modifier = Modifier) {
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    val motion = rememberInfiniteTransition(label = "mv-hero")
    val bob by motion.animateFloat(0f, -4f, infiniteRepeatable(tween(420), RepeatMode.Reverse), label = "bob")
    val road by motion.animateFloat(0f, 44f, infiniteRepeatable(tween(900, easing = LinearEasing)), label = "road")
    val glow by motion.animateFloat(0.10f, 0.22f, infiniteRepeatable(tween(1400), RepeatMode.Reverse), label = "glow")
    val shape = RoundedCornerShape(26.dp)
    Box(modifier.fillMaxWidth().clip(shape).background(mvHeroBrush())) {
        // Soft circles behind, for depth.
        Box(Modifier.align(Alignment.TopEnd).offset(x = 40.dp, y = (-50).dp).size(170.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.07f)))
        Box(Modifier.align(Alignment.BottomStart).offset(x = (-30).dp, y = 40.dp).size(120.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.05f)))
        Column(Modifier.fillMaxWidth().padding(20.dp)) {
            Text(
                stringResource(R.string.mv_title), color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(CircleShape).background(Color.White.copy(alpha = 0.16f)).padding(horizontal = 10.dp, vertical = 4.dp),
            )
            Spacer(Modifier.height(10.dp))
            Text(stringResource(R.string.mv_hero_title), color = Color.White, fontWeight = FontWeight.Black, fontSize = 22.sp, lineHeight = 28.sp)
            Spacer(Modifier.height(6.dp))
            Text(stringResource(R.string.mv_hero_body), color = Color.White.copy(alpha = 0.82f), fontSize = 13.sp, lineHeight = 19.sp)
            Spacer(Modifier.height(16.dp))
            Box(Modifier.fillMaxWidth().height(70.dp)) {
                Canvas(Modifier.align(Alignment.BottomCenter).fillMaxWidth().height(10.dp)) {
                    val y = size.height / 2
                    // The dashes run against the truck: drawn from its front to its back.
                    val from = if (rtl) 0f else size.width
                    val to = if (rtl) size.width else 0f
                    drawLine(Color.White.copy(alpha = 0.14f), Offset(0f, y), Offset(size.width, y), strokeWidth = 8.dp.toPx(), cap = StrokeCap.Round)
                    drawLine(
                        Color.White.copy(alpha = 0.7f), Offset(from, y), Offset(to, y), strokeWidth = 2.dp.toPx(),
                        pathEffect = PathEffect.dashPathEffect(floatArrayOf(22f, 22f), -road),
                    )
                }
                Box(
                    Modifier.align(Alignment.Center).offset(y = (-8).dp).graphicsLayer { translationY = bob.dp.toPx() },
                    contentAlignment = Alignment.Center,
                ) {
                    Box(Modifier.size(64.dp).clip(CircleShape).background(Color.White.copy(alpha = glow)))
                    Box(Modifier.size(48.dp).clip(CircleShape).background(Color.White), contentAlignment = Alignment.Center) {
                        Icon(
                            Icons.Rounded.LocalShipping, null, tint = AppColors.brandOrange,
                            modifier = Modifier.size(28.dp).graphicsLayer { scaleX = if (rtl) -1f else 1f },
                        )
                    }
                }
            }
        }
    }
}

/** How it works, in three steps side by side. */
@Composable
private fun MovingHowItWorks(modifier: Modifier = Modifier) {
    MvCard(modifier) {
        MvCardTitle(stringResource(R.string.mv_how_title))
        Spacer(Modifier.height(14.dp))
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            HowStep(1, Icons.Rounded.EditNote, stringResource(R.string.mv_how_1_title), stringResource(R.string.mv_how_1_body), Modifier.weight(1f))
            HowStep(2, Icons.Rounded.LocalOffer, stringResource(R.string.mv_how_2_title), stringResource(R.string.mv_how_2_body), Modifier.weight(1f))
            HowStep(3, Icons.Rounded.VerifiedUser, stringResource(R.string.mv_how_3_title), stringResource(R.string.mv_how_3_body), Modifier.weight(1f))
        }
    }
}

@Composable
private fun HowStep(number: Int, icon: ImageVector, title: String, body: String, modifier: Modifier) {
    val tint = settingsAccent()
    Column(modifier, horizontalAlignment = Alignment.CenterHorizontally) {
        Box(contentAlignment = Alignment.Center) {
            Box(Modifier.size(50.dp).clip(RoundedCornerShape(16.dp)).background(tint.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(icon, null, tint = tint, modifier = Modifier.size(24.dp))
            }
            Box(
                Modifier.align(Alignment.TopEnd).offset(x = 6.dp, y = (-6).dp).size(20.dp).clip(CircleShape).background(tint),
                contentAlignment = Alignment.Center,
            ) { Text(number.toString(), color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.Black) }
        }
        Spacer(Modifier.height(8.dp))
        Text(title, color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 13.sp, textAlign = TextAlign.Center, maxLines = 1)
        Text(body, color = settingsMut(), fontSize = 11.sp, textAlign = TextAlign.Center, lineHeight = 14.sp)
    }
}

/** No requests yet: the truck in its circle and a line, inside the list. */
@Composable
private fun MovingHomeEmpty(text: String) {
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(settingsCard()).padding(24.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Box(Modifier.size(64.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.LocalShipping, null, tint = settingsAccent(), modifier = Modifier.size(30.dp))
        }
        Spacer(Modifier.height(10.dp))
        Text(text, color = settingsMut(), fontSize = 13.5.sp, textAlign = TextAlign.Center)
    }
}

private fun androidx.compose.foundation.lazy.LazyListScope.itemsIndexedStagger(list: List<MovingRequestDto>, row: @Composable (MovingRequestDto) -> Unit) {
    items(list, key = { it.uuid }) { item -> Box(Modifier.staggerIn(list.indexOf(item))) { row(item) } }
}

@Composable
private fun RequestCard(item: MovingRequestDto, onDelete: (() -> Unit)? = null, onClick: () -> Unit) {
    val (label, color) = movingStatusLook(item.status)
    val (today, tomorrow) = rememberWhenLabels()
    val time = parseTime(item.scheduledAt)
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(settingsCard()).pressable(onClick = onClick).padding(14.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(44.dp).clip(RoundedCornerShape(14.dp)).background(color.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.LocalShipping, null, tint = color, modifier = Modifier.size(24.dp))
            }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(loadTypeLabel(item.loadType), color = settingsInk(), fontWeight = FontWeight.Bold)
                time?.let { Text(whenText(it, today, tomorrow), color = settingsMut(), fontSize = 12.sp) }
            }
            MvChip(label, color)
        }
        val from = item.pickup?.area ?: item.pickup?.address
        val to = item.dropoff?.area ?: item.dropoff?.address
        if (from != null || to != null) {
            Spacer(Modifier.height(8.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    TripLine(AppColors.brandNavy, from)
                    TripLine(AppColors.brandOrange, to)
                }
                item.distanceKm?.let {
                    Spacer(Modifier.width(8.dp))
                    Text(stringResource(R.string.mv_km, movingKmText(it)), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                }
            }
        }
        val total = item.money?.totalMinor
        if (total != null || item.establishment?.name != null) {
            Row(Modifier.fillMaxWidth().padding(top = 6.dp)) {
                Text(item.establishment?.name.orEmpty(), color = settingsMut(), fontSize = 12.sp, modifier = Modifier.weight(1f))
                total?.let { Text(mvMoney(it, item.currencyCode, item.currencyDecimals), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 13.sp) }
            }
        }
        onDelete?.let {
            Text(
                stringResource(R.string.mv_draft_delete), color = AppColors.danger, fontWeight = FontWeight.SemiBold, fontSize = 12.sp,
                modifier = Modifier.padding(top = 6.dp).clip(CircleShape).clickable(onClick = it).padding(vertical = 4.dp),
            )
        }
    }
}

/** "Moving isn't available here": the rides' unavailable card, with its own words. */
@Composable
internal fun MovingUnavailablePanel(onRetry: () -> Unit) {
    val pulse = rememberInfiniteTransition(label = "mv-unavailable").animateFloat(
        initialValue = 1f, targetValue = 1.08f,
        animationSpec = infiniteRepeatable(tween(1100), RepeatMode.Reverse), label = "scale",
    )
    PanelCard {
        Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Box(Modifier.size(72.dp).scale(pulse.value).clip(CircleShape).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.LocationOff, null, tint = settingsAccent(), modifier = Modifier.size(34.dp))
            }
            Text(stringResource(R.string.mv_na_title), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 18.sp, textAlign = TextAlign.Center)
            Text(stringResource(R.string.mv_na_body), color = settingsInk().copy(alpha = 0.65f), fontSize = 14.sp, textAlign = TextAlign.Center)
            Spacer(Modifier.height(6.dp))
            AccentButton(stringResource(R.string.rd_retry), onClick = onRetry)
        }
    }
}
