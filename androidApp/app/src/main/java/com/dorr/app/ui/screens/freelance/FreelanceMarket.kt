package com.dorr.app.ui.screens.freelance

import android.widget.Toast
import androidx.compose.animation.animateContentSize
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.border
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
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.pager.HorizontalPager
import androidx.compose.foundation.pager.rememberPagerState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AddCircleOutline
import androidx.compose.material.icons.rounded.ArrowForward
import androidx.compose.material.icons.rounded.Bolt
import androidx.compose.material.icons.rounded.CheckBox
import androidx.compose.material.icons.rounded.CheckBoxOutlineBlank
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.EventBusy
import androidx.compose.material.icons.rounded.EventRepeat
import androidx.compose.material.icons.rounded.VideoChat
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.Image
import androidx.compose.material.icons.rounded.Inbox
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.QuestionAnswer
import androidx.compose.material.icons.rounded.Receipt
import androidx.compose.material.icons.rounded.Replay
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.ShoppingBag
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.Storefront
import androidx.compose.material.icons.rounded.TaskAlt
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.FreelanceSpecialtyNodeDto
import com.dorr.app.network.MarketCapacityDto
import com.dorr.app.network.MarketFreelancerDto
import com.dorr.app.network.MarketOrderDto
import com.dorr.app.network.MarketOrderRequest
import com.dorr.app.network.MarketQuoteDto
import com.dorr.app.network.MarketServiceCardDto
import com.dorr.app.network.MarketServiceDto
import com.dorr.app.network.MarketSubscriptionDto
import com.dorr.app.network.MarketSubscriptionTermsDto
import com.dorr.app.network.apiFailure
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.AccountDark
import com.dorr.app.ui.screens.ConfirmDialog
import com.dorr.app.ui.screens.profile.SettingsPrimaryButton
import com.dorr.app.ui.screens.profile.SubHeader
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.screens.profile.settingsSurface
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.math.BigDecimal
import java.time.Duration
import java.time.Instant
import java.time.OffsetDateTime
import java.util.Locale
import java.util.UUID

/*
 * FL-L04, ready-made services for the client: the market (search, specialties, sort), one service (its images, the freelancer, the
 * packages side by side, extras to tick, the freelancer's question, a live total with the fee and a sticky order bar), the order
 * confirmation (nothing is paid before the freelancer accepts), my orders and one order with where it stands. Each page reads when it
 * opens, when the connection returns and when a freelance notice arrives (collectReconnectTick) — never on a timer.
 */

private val LEVELS = listOf("basic", "standard", "premium")
private val GREEN = Color(0xFF16A34A)
private val AMBER = Color(0xFFD97706)
private val RED = Color(0xFFDC2626)
private val SLATE = Color(0xFF64748B)

/** "1,250.00 SAR" in the currency's own decimals; digits stay Latin and left-to-right inside Arabic text. */
internal fun mkMoney(minor: Long, decimals: Int, currency: String?): String {
    val text = String.format(Locale.US, "%,.${decimals}f", BigDecimal.valueOf(minor, decimals))
    return "‎" + if (currency.isNullOrBlank()) text else "$text $currency"
}

private fun leftOf(iso: String?): Duration? {
    val at = runCatching { Instant.parse(iso) }.getOrNull() ?: runCatching { OffsetDateTime.parse(iso).toInstant() }.getOrNull() ?: return null
    return Duration.between(Instant.now(), at)
}

@Composable
private fun levelName(level: String): String = when (level) {
    "standard" -> stringResource(R.string.mk_level_standard)
    "premium" -> stringResource(R.string.mk_level_premium)
    else -> stringResource(R.string.mk_level_basic)
}

@Composable
private fun days(n: Int): String = if (n == 1) stringResource(R.string.mk_day_one) else stringResource(R.string.mk_days, n)

@Composable
private fun Pill(text: String, color: Color, solid: Boolean = false, modifier: Modifier = Modifier) {
    Row(
        modifier.clip(RoundedCornerShape(999.dp)).background(if (solid) color else color.copy(alpha = if (settingsNight()) 0.22f else 0.12f)).padding(horizontal = 10.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(6.dp).clip(CircleShape).background(if (solid) Color.White else color))
        Spacer(Modifier.width(6.dp))
        Text(text, color = if (solid) Color.White else color, fontSize = 11.5.sp, fontWeight = FontWeight.Bold, maxLines = 1)
    }
}

@Composable
private fun Avatar(url: String?, name: String?, size: Int = 34) {
    Box(Modifier.size(size.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
        val reachable = ApiClient.mediaUrl(url)
        if (!reachable.isNullOrBlank()) AsyncImage(model = reachable, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
        else Text(name?.trim()?.firstOrNull()?.uppercase() ?: "?", color = settingsAccent(), fontSize = (size / 2.4).sp, fontWeight = FontWeight.ExtraBold)
    }
}

@Composable
private fun SectionCard(title: String? = null, icon: androidx.compose.ui.graphics.vector.ImageVector? = null, content: @Composable () -> Unit) {
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        if (title != null) Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            if (icon != null) Icon(icon, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
            Text(title, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
        }
        content()
    }
}

@Composable
private fun Line(label: String, value: String, strong: Boolean = false) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Text(label, color = if (strong) settingsInk() else settingsMut(), fontSize = if (strong) 15.sp else 13.sp, fontWeight = if (strong) FontWeight.ExtraBold else FontWeight.Medium, modifier = Modifier.weight(1f))
        Text(value, color = if (strong) settingsAccent() else settingsInk(), fontSize = if (strong) 17.sp else 13.sp, fontWeight = if (strong) FontWeight.ExtraBold else FontWeight.SemiBold)
    }
}

// ==================================================================== the entry on "My projects"

/** The door to the market on the projects list: a soft gradient card, and "My orders" beside the button. */
@Composable
internal fun MarketBanner(onBrowse: () -> Unit, onOrders: () -> Unit) {
    val accent = settingsAccent()
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(22.dp)).background(Brush.linearGradient(listOf(accent, accent.copy(alpha = 0.75f)))).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Box(Modifier.size(44.dp).clip(RoundedCornerShape(14.dp)).background(Color.White.copy(alpha = 0.2f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Storefront, null, tint = Color.White, modifier = Modifier.size(24.dp))
            }
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.mk_banner_title), color = Color.White, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
                Text(stringResource(R.string.mk_banner_body), color = Color.White.copy(alpha = 0.9f), fontSize = 12.sp, lineHeight = 17.sp)
            }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Row(
                Modifier.weight(1f).clip(RoundedCornerShape(14.dp)).background(Color.White).clickable(onClick = onBrowse).padding(vertical = 11.dp),
                horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(stringResource(R.string.mk_banner_cta), color = accent, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                Spacer(Modifier.width(6.dp))
                val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
                Icon(Icons.Rounded.ArrowForward, null, tint = accent, modifier = Modifier.size(16.dp).graphicsLayer { if (rtl) scaleX = -1f })
            }
            Row(
                Modifier.clip(RoundedCornerShape(14.dp)).background(Color.White.copy(alpha = 0.2f)).clickable(onClick = onOrders).padding(horizontal = 14.dp, vertical = 11.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.Receipt, null, tint = Color.White, modifier = Modifier.size(16.dp))
                Spacer(Modifier.width(6.dp))
                Text(stringResource(R.string.mk_my_orders), color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.Bold)
            }
        }
    }
}

// ==================================================================== the market

@Composable
internal fun MarketPage(onBack: () -> Unit, onOpen: (String) -> Unit, onOrders: () -> Unit, onConsultations: () -> Unit = {}) {
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    var typed by rememberSaveable { mutableStateOf("") }
    var search by rememberSaveable { mutableStateOf("") }
    var specialty by rememberSaveable { mutableStateOf<Int?>(null) }
    var sort by rememberSaveable { mutableStateOf("recommended") }
    var fast by rememberSaveable { mutableStateOf(false) }
    var specialties by remember { mutableStateOf<List<FreelanceSpecialtyNodeDto>>(emptyList()) }
    var closed by remember { mutableStateOf(false) }
    val listState = rememberLazyListState()

    // The search goes out once typing pauses.
    LaunchedEffect(typed) { delay(400); search = typed.trim() }
    LaunchedEffect(Unit) { runCatching { specialties = ApiClient.freelance.specialties(auth()).data.orEmpty() } }

    val paged = remember(search, specialty, sort, fast) {
        PagedRows<MarketServiceCardDto>({ it.uuid }, { page ->
            try {
                ApiClient.freelanceServices.browse(auth(), search = search.ifBlank { null }, specialtyId = specialty, maxDays = if (fast) 3 else null, sort = sort, page = page)
            } catch (e: retrofit2.HttpException) {
                if (e.code() == 403) closed = true
                throw e
            }
        })
    }
    LaunchedEffect(paged, reconnect) { paged.reload(generic) }
    listState.NearEnd(paged.rows.size) { scope.launch { paged.loadMore(generic) } }

    Column(Modifier.fillMaxSize()) {
        SubHeader(stringResource(R.string.mk_title), onBack)
        LazyColumn(Modifier.weight(1f).fillMaxWidth(), state = listState, contentPadding = PaddingValues(horizontal = 16.dp, vertical = 6.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            item {
                DorrTextField(typed, { typed = it.take(80) }, placeholder = stringResource(R.string.mk_search), icon = Icons.Rounded.Search,
                    trailing = if (typed.isNotEmpty()) { { Icon(Icons.Rounded.Close, null, tint = settingsMut(), modifier = Modifier.padding(end = 10.dp).size(18.dp).clickable { typed = "" }) } } else null,
                    modifier = Modifier.fillMaxWidth())
            }
            item {
                Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    // FL-L07: consultations are a list of their own, next to the services.
                    Chip(stringResource(R.string.cs_market_title), selected = false, icon = Icons.Rounded.VideoChat) { onConsultations() }
                    Chip(stringResource(R.string.mk_my_orders), selected = false, icon = Icons.Rounded.Receipt) { onOrders() }
                    Chip(stringResource(R.string.mk_fast), selected = fast, icon = Icons.Rounded.Bolt) { fast = !fast }
                    listOf("recommended" to R.string.mk_sort_recommended, "newest" to R.string.mk_sort_newest, "price_low" to R.string.mk_sort_price_low, "price_high" to R.string.mk_sort_price_high, "popular" to R.string.mk_sort_popular)
                        .forEach { (value, label) -> Chip(stringResource(label), selected = sort == value) { sort = value } }
                }
            }
            if (specialties.isNotEmpty()) item {
                Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Chip(stringResource(R.string.mk_all), selected = specialty == null) { specialty = null }
                    specialties.forEach { s -> Chip(s.name, selected = specialty == s.id) { specialty = if (specialty == s.id) null else s.id } }
                }
            }
            if (sort == "recommended" && !closed) item { FlHint(stringResource(R.string.mk_sort_recommended_hint), Modifier.padding(horizontal = 2.dp)) }
            when {
                closed -> item { com.dorr.app.ui.screens.wallet.WaEmpty(Icons.Rounded.Storefront, com.dorr.app.ui.screens.wallet.Tone.Blue, stringResource(R.string.mk_title), stringResource(R.string.mk_closed)) }
                !paged.loaded -> item { FlSkeletonList(count = 3, height = 230.dp) }
                paged.rows.isEmpty() && paged.error == null -> item {
                    com.dorr.app.ui.screens.wallet.WaEmpty(Icons.Rounded.Search, com.dorr.app.ui.screens.wallet.Tone.Blue, stringResource(R.string.mk_empty_title), stringResource(R.string.mk_empty_body))
                }
                else -> items(paged.rows, key = { it.uuid }) { s -> ServiceTile(s) { onOpen(s.uuid) } }
            }
            item { PagedSpinner(paged) }
            paged.error?.takeIf { !closed }?.let { item { TextButton(onClick = { paged.clearError(); scope.launch { paged.loadMore(generic) } }, modifier = Modifier.fillMaxWidth()) { Text(it, color = RED, fontSize = 13.sp) } } }
        }
    }
}

@Composable
private fun ServiceTile(s: MarketServiceCardDto, onClick: () -> Unit) {
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(22.dp)).clickable(onClick = onClick)) {
        Box(Modifier.fillMaxWidth().aspectRatio(16f / 9f).background(settingsAccent().copy(alpha = 0.10f))) {
            val cover = ApiClient.mediaUrl(s.cover)
            if (cover != null) AsyncImage(model = cover, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
            else Icon(Icons.Rounded.Image, null, tint = settingsAccent().copy(alpha = 0.55f), modifier = Modifier.align(Alignment.Center).size(42.dp))
            s.specialty?.name?.let {
                Text(it, color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.Bold,
                    modifier = Modifier.align(Alignment.TopStart).padding(10.dp).clip(RoundedCornerShape(999.dp)).background(Color.Black.copy(alpha = 0.45f)).padding(horizontal = 10.dp, vertical = 4.dp))
            }
            // FL-L05: sold by the month.
            if (s.billing == "monthly") {
                Row(Modifier.align(Alignment.BottomStart).padding(10.dp).clip(RoundedCornerShape(999.dp)).background(settingsAccent()).padding(horizontal = 10.dp, vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Rounded.EventRepeat, null, tint = Color.White, modifier = Modifier.size(13.dp))
                    Spacer(Modifier.width(4.dp))
                    Text(stringResource(R.string.mks_monthly), color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.Bold)
                }
            }
            // FL-L10: the freelancer is full right now — ordering waits for a free place.
            if (s.atCapacity) {
                Text(stringResource(R.string.mk_full_pill), color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.Bold,
                    modifier = Modifier.align(Alignment.TopEnd).padding(10.dp).clip(RoundedCornerShape(999.dp)).background(Color(0xFFD97706)).padding(horizontal = 10.dp, vertical = 4.dp))
            }
        }
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Avatar(s.freelancer.avatarUrl, s.freelancer.name, 28)
                Row(Modifier.weight(1f), verticalAlignment = Alignment.CenterVertically) {
                    Text(s.freelancer.name.orEmpty(), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false))
                    PlanBadge(s.freelancer.badge, Modifier.padding(start = 6.dp))
                }
                if (s.completedOrders > 0) Text(stringResource(R.string.mk_done_n, s.completedOrders), color = GREEN, fontSize = 11.sp, fontWeight = FontWeight.Bold)
            }
            Text(s.title, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis, lineHeight = 21.sp)
            Box(Modifier.fillMaxWidth().height(1.dp).background(if (settingsNight()) AccountDark.line else Color(0xFFF0F1F4)))
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.Schedule, null, tint = settingsMut(), modifier = Modifier.size(15.dp))
                Spacer(Modifier.width(4.dp))
                Text(s.deliveryDays?.let { days(it) }.orEmpty(), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                s.startingPriceMinor?.let {
                    val price = mkMoney(it, s.decimals, s.currency)
                    Text(if (s.billing == "monthly") stringResource(R.string.mks_from_month, price) else stringResource(R.string.mk_from, price), color = settingsAccent(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                }
            }
        }
    }
}

// ==================================================================== one service

@OptIn(ExperimentalFoundationApi::class)
@Composable
internal fun ServicePage(uuid: String, onBack: () -> Unit, onOrdered: (String) -> Unit) {
    val context = LocalContext.current
    val generic = stringResource(R.string.fl_error_generic)
    val answerNeeded = stringResource(R.string.mk_answer_needed)
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    var service by remember(uuid) { mutableStateOf<MarketServiceDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var level by rememberSaveable { mutableStateOf("basic") }
    val picked = remember { mutableStateListOf<Int>() }
    var note by rememberSaveable { mutableStateOf("") }
    var quote by remember { mutableStateOf<MarketQuoteDto?>(null) }
    var confirming by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var expanded by remember { mutableStateOf(false) }
    // One id per order attempt: a retry after a lost answer is the same order, never a second one.
    var requestId by rememberSaveable { mutableStateOf(UUID.randomUUID().toString()) }

    LaunchedEffect(uuid, reconnect) {
        try {
            val s = ApiClient.freelanceServices.service(auth(), uuid).data
            service = s
            if (s != null && s.tiers.none { it.level == level }) level = s.tiers.firstOrNull()?.level ?: "basic"
            error = null
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.apiFailure().message ?: generic
        }
    }
    // The total with the fee follows the package and the extras.
    LaunchedEffect(service, level, picked.toList()) {
        if (service == null) return@LaunchedEffect
        delay(200)
        runCatching { quote = ApiClient.freelanceServices.quote(auth(), uuid, level, picked.toList()).data }
    }

    fun place() {
        val s = service ?: return
        busy = true
        scope.launch {
            try {
                val order = ApiClient.freelanceServices.order(auth(), s.uuid, MarketOrderRequest(level, picked.toList(), note.trim().ifEmpty { null }, requestId)).data
                busy = false
                confirming = false
                requestId = UUID.randomUUID().toString()
                Toast.makeText(context, context.getString(R.string.mk_ordered_toast), Toast.LENGTH_SHORT).show()
                order?.let { onOrdered(it.uuid) }
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                busy = false
                confirming = false
                error = e.apiFailure().message ?: generic
            }
        }
    }

    // FL-L10: join or leave the line; the answer is where I stand now.
    fun waitlist(join: Boolean) {
        val current = service ?: return
        busy = true
        scope.launch {
            try {
                val cap = if (join) ApiClient.freelanceServices.joinWaitlist(auth(), current.uuid).data else ApiClient.freelanceServices.leaveWaitlist(auth(), current.uuid).data
                if (cap != null) service = current.copy(capacity = cap)
                error = null
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                error = e.apiFailure().message ?: generic
            }
            busy = false
        }
    }

    val s = service
    Column(Modifier.fillMaxSize()) {
        SubHeader(s?.title ?: stringResource(R.string.mk_title), onBack)
        if (s == null) {
            Box(Modifier.weight(1f)) { FlLoading(error) }
        } else {
        val tier = s.tiers.firstOrNull { it.level == level } ?: s.tiers.first()
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = 16.dp).padding(bottom = 16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            // The images, swiped.
            if (s.images.isNotEmpty()) {
                val pager = rememberPagerState { s.images.size }
                Box(Modifier.fillMaxWidth().aspectRatio(16f / 10f).clip(RoundedCornerShape(22.dp)).background(settingsAccent().copy(alpha = 0.08f))) {
                    HorizontalPager(pager, Modifier.fillMaxSize()) { i ->
                        AsyncImage(model = ApiClient.mediaUrl(s.images[i].url), contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                    }
                    if (s.images.size > 1) Row(Modifier.align(Alignment.BottomCenter).padding(10.dp), horizontalArrangement = Arrangement.spacedBy(5.dp)) {
                        repeat(s.images.size) { i ->
                            Box(Modifier.height(6.dp).width(if (i == pager.currentPage) 18.dp else 6.dp).clip(CircleShape).background(Color.White.copy(alpha = if (i == pager.currentPage) 1f else 0.6f)))
                        }
                    }
                }
            }
            // The title and what it stands on.
            Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                s.specialty?.name?.let { Pill(it, settingsAccent()) }
                Text(s.title, color = settingsInk(), fontSize = 20.sp, fontWeight = FontWeight.ExtraBold, lineHeight = 27.sp)
                if (s.completedOrders > 0) Text(stringResource(R.string.mk_done_n, s.completedOrders), color = GREEN, fontSize = 12.sp, fontWeight = FontWeight.Bold)
            }
            FreelancerCard(s.freelancer)

            SectionCard(stringResource(R.string.mk_about), Icons.Rounded.Notes) {
                Text(s.description, color = settingsInk(), fontSize = 14.sp, lineHeight = 22.sp, maxLines = if (expanded) Int.MAX_VALUE else 5, overflow = TextOverflow.Ellipsis, modifier = Modifier.animateContentSize())
                if (s.description.length > 240) Text(stringResource(if (expanded) R.string.mk_less else R.string.mk_more), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable { expanded = !expanded })
            }

            // The packages.
            SectionCard(stringResource(R.string.mk_choose_level), Icons.Rounded.ShoppingBag) {
                if (s.tiers.size > 1) PackageTabs(s, level) { level = it }
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(tier.name.ifBlank { levelName(tier.level) }, color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
                            tier.description?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp) }
                        }
                        Column(horizontalAlignment = Alignment.End) {
                            Text(mkMoney(tier.priceMinor, s.decimals, s.currency), color = settingsAccent(), fontSize = 20.sp, fontWeight = FontWeight.Black)
                            if (s.billing == "monthly") Text(stringResource(R.string.mks_per_month), color = settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.Bold)
                        }
                    }
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Fact(Icons.Rounded.Schedule, days(tier.deliveryDays), Modifier.weight(1f))
                        Fact(Icons.Rounded.Replay, if (tier.revisions > 0) stringResource(R.string.mk_revisions, tier.revisions) else stringResource(R.string.mk_no_revisions), Modifier.weight(1f))
                    }
                    if (tier.features.isNotEmpty()) {
                        Text(stringResource(R.string.mk_includes), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 4.dp))
                        tier.features.forEach { f ->
                            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                Icon(Icons.Rounded.CheckCircle, null, tint = GREEN, modifier = Modifier.size(17.dp))
                                Text(f, color = settingsInk(), fontSize = 13.sp)
                            }
                        }
                    }
                }
            }

            // FL-L05: how a monthly subscription runs, said before subscribing.
            s.subscription?.let { t -> SubscriptionTerms(t) }

            // Extras to tick.
            if (s.addons.isNotEmpty()) SectionCard(stringResource(R.string.mk_extras), Icons.Rounded.AddCircleOutline) {
                s.addons.forEach { a ->
                    val on = a.id in picked
                    Row(
                        Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp))
                            .border(1.5.dp, if (on) settingsAccent() else if (settingsNight()) AccountDark.line else Color(0xFFE8EAEE), RoundedCornerShape(14.dp))
                            .background(if (on) settingsAccent().copy(alpha = 0.06f) else Color.Transparent)
                            .clickable { if (on) picked.remove(a.id) else picked.add(a.id) }.padding(12.dp),
                        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp),
                    ) {
                        Icon(if (on) Icons.Rounded.CheckBox else Icons.Rounded.CheckBoxOutlineBlank, null, tint = if (on) settingsAccent() else settingsMut(), modifier = Modifier.size(22.dp))
                        Column(Modifier.weight(1f)) {
                            Text(a.title, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                            if (a.extraDays > 0) Text(stringResource(R.string.mk_extra_days, days(a.extraDays)), color = settingsMut(), fontSize = 12.sp)
                        }
                        Text("+" + mkMoney(a.priceMinor, s.decimals, s.currency), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
                    }
                }
            }

            // The freelancer's question, or a note.
            SectionCard(if (s.requirements != null) stringResource(R.string.mk_question) else stringResource(R.string.mk_note), Icons.Rounded.QuestionAnswer) {
                s.requirements?.let { q ->
                    Text(q, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold, lineHeight = 21.sp,
                        modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.06f)).padding(12.dp))
                }
                DorrTextField(note, { note = it.take(3000) }, label = if (s.requirements != null) stringResource(R.string.mk_answer) else null, placeholder = stringResource(R.string.mk_note_hint), singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
            }

            // What it comes to.
            quote?.let { q ->
                SectionCard(stringResource(R.string.mk_total), Icons.Rounded.Payments) {
                    Line(stringResource(R.string.mk_price), mkMoney(q.priceMinor, q.decimals, q.currency))
                    if (q.clientFeeMinor > 0) Line(stringResource(R.string.mk_fee), mkMoney(q.clientFeeMinor, q.decimals, q.currency))
                    Line(stringResource(R.string.mk_delivery), days(q.deliveryDays))
                    Box(Modifier.fillMaxWidth().height(1.dp).background(if (settingsNight()) AccountDark.line else Color(0xFFF0F1F4)))
                    Line(stringResource(R.string.mk_total), mkMoney(q.totalMinor, q.decimals, q.currency), strong = true)
                }
            }
            WaitlistCard(s.capacity, busy, onLeave = { waitlist(false) })
            error?.let { FlNotice(it, Icons.Rounded.Close, RED) }
        }

        // The sticky order bar.
        Row(
            Modifier.fillMaxWidth().background(if (settingsNight()) AccountDark.card else Color.White).padding(horizontal = 16.dp, vertical = 12.dp),
            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.mk_total), color = settingsMut(), fontSize = 11.sp)
                Text(quote?.let { mkMoney(it.totalMinor, it.decimals, it.currency) } ?: mkMoney(tier.priceMinor, s.decimals, s.currency), color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.Black)
            }
            val cap = s.capacity
            val mine = cap.mine
            when {
                // Full, and I am not in line yet: wait instead of ordering.
                cap.full && mine == null -> SettingsPrimaryButton(text = stringResource(R.string.mk_wait_join), modifier = Modifier.weight(1.3f), loading = busy, onClick = { waitlist(true) })
                cap.full && mine?.status == "waiting" -> SettingsPrimaryButton(text = stringResource(R.string.mk_wait_position, mine.position ?: 0), modifier = Modifier.weight(1.3f), enabled = false, onClick = {})
                else -> SettingsPrimaryButton(text = stringResource(if (s.billing == "monthly") R.string.mks_subscribe else R.string.mk_order_now), modifier = Modifier.weight(1.3f), onClick = {
                    if (s.requirements != null && note.isBlank()) error = answerNeeded else { error = null; confirming = true }
                })
            }
        }
        }
    }

    if (confirming && s != null) {
        val tier = s.tiers.firstOrNull { it.level == level } ?: s.tiers.first()
        val monthly = s.billing == "monthly"
        FlSheet(Icons.Rounded.TaskAlt, stringResource(if (monthly) R.string.mks_confirm_title else R.string.mk_confirm_title),
            if (monthly) stringResource(R.string.mks_confirm_body, s.answerHours) else stringResource(R.string.mk_confirm_body, s.answerHours), onDismiss = { if (!busy) confirming = false }, fullHeight = true) {
            Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(settingsAccent().copy(alpha = 0.06f)).padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(s.title, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                Line(levelName(tier.level), mkMoney(tier.priceMinor, s.decimals, s.currency))
                s.addons.filter { it.id in picked }.forEach { a -> Line("+ " + a.title, mkMoney(a.priceMinor, s.decimals, s.currency)) }
                quote?.let { q ->
                    if (q.clientFeeMinor > 0) Line(stringResource(R.string.mk_fee), mkMoney(q.clientFeeMinor, q.decimals, q.currency))
                    Line(stringResource(R.string.mk_delivery), days(q.deliveryDays))
                    Line(stringResource(R.string.mk_total), mkMoney(q.totalMinor, q.decimals, q.currency), strong = true)
                }
            }
            FlNotice(stringResource(R.string.mk_confirm_safe), Icons.Rounded.Lock, GREEN)
            SettingsPrimaryButton(text = stringResource(R.string.mk_confirm_cta), loading = busy, onClick = ::place)
        }
    }
}

@Composable
private fun PackageTabs(s: MarketServiceDto, selected: String, onSelect: (String) -> Unit) {
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(if (settingsNight()) AccountDark.well else settingsAccent().copy(alpha = 0.08f)).padding(4.dp),
        horizontalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        LEVELS.mapNotNull { l -> s.tiers.firstOrNull { it.level == l } }.forEach { t ->
            val on = t.level == selected
            Column(
                Modifier.weight(1f).clip(RoundedCornerShape(12.dp)).background(if (on) settingsAccent() else Color.Transparent).clickable { onSelect(t.level) }.padding(vertical = 8.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                Text(levelName(t.level), color = if (on) Color.White else settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
                Text(mkMoney(t.priceMinor, s.decimals, null), color = if (on) Color.White.copy(alpha = 0.85f) else settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.SemiBold, maxLines = 1)
            }
        }
    }
}

@Composable
private fun Fact(icon: androidx.compose.ui.graphics.vector.ImageVector, text: String, modifier: Modifier) {
    Row(
        modifier.clip(RoundedCornerShape(12.dp)).background(if (settingsNight()) AccountDark.well else settingsAccent().copy(alpha = 0.06f)).padding(horizontal = 10.dp, vertical = 9.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Icon(icon, null, tint = settingsAccent(), modifier = Modifier.size(16.dp))
        Text(text, color = settingsInk(), fontSize = 12.5.sp, fontWeight = FontWeight.Bold, maxLines = 1)
    }
}

/**
 * FL-L10: where I stand with a freelancer who caps their running projects — full (and how many wait), my place in line, or the place kept
 * for me with the time it is kept until. Nothing is drawn while there is room and I am not in line.
 */
@Composable
private fun WaitlistCard(cap: MarketCapacityDto, busy: Boolean, onLeave: () -> Unit) {
    val mine = cap.mine
    val amber = Color(0xFFD97706)
    when {
        mine?.status == "offered" -> FlNotice(stringResource(R.string.mk_wait_turn, holdText(mine.holdUntil)), Icons.Rounded.TaskAlt, GREEN)
        mine?.status == "waiting" -> Column(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(amber.copy(alpha = 0.10f)).padding(14.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                Box(Modifier.size(46.dp).clip(CircleShape).background(amber), contentAlignment = Alignment.Center) {
                    Text("${mine.position ?: "-"}", color = Color.White, fontSize = 20.sp, fontWeight = FontWeight.Black)
                }
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(stringResource(R.string.mk_wait_in_line), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                    Text(stringResource(R.string.mk_wait_in_line_body), color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp)
                }
            }
            FlAction(stringResource(R.string.mk_wait_leave), Icons.Rounded.Close, FlTone.Danger, loading = busy, onClick = onLeave)
        }
        cap.full -> FlNotice(
            if (cap.waiting > 0) stringResource(R.string.mk_wait_full_n, cap.waiting) else stringResource(R.string.mk_wait_full),
            Icons.Rounded.HourglassTop, amber,
        )
    }
}

private fun holdText(iso: String?): String = runCatching {
    java.time.OffsetDateTime.parse(iso).atZoneSameInstant(java.time.ZoneId.systemDefault()).format(java.time.format.DateTimeFormatter.ofPattern("EEE d MMM, HH:mm"))
}.getOrDefault("")

@Composable
private fun FreelancerCard(f: MarketFreelancerDto) {
    Row(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).padding(14.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        Avatar(f.avatarUrl, f.name, 48)
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(f.name.orEmpty(), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false))
                Icon(Icons.Rounded.Verified, null, tint = Color(0xFF0EA5E9), modifier = Modifier.size(16.dp))
                PlanBadge(f.badge)
            }
            f.headline?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis) }
            SkillBadges(f.skillBadges)
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                if (f.rating != null) {
                    Icon(Icons.Rounded.Star, null, tint = Color(0xFFF59E0B), modifier = Modifier.size(15.dp))
                    Text(String.format(Locale.US, "%.1f", f.rating), color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.ExtraBold)
                    Text(stringResource(R.string.mk_reviews, f.reviewsCount), color = settingsMut(), fontSize = 11.sp)
                } else Text(stringResource(R.string.mk_rating_none), color = settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
            }
        }
        Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(3.dp)) {
            Text(stringResource(R.string.mk_projects, f.completedProjects), color = settingsMut(), fontSize = 11.sp)
            f.onTimeRate?.let { Text(stringResource(R.string.mk_on_time, it), color = GREEN, fontSize = 11.sp, fontWeight = FontWeight.Bold) }
        }
    }
}

// ==================================================================== my orders

private val ORDER_TABS = listOf("awaiting", "active", "completed", "ended")

@Composable
private fun orderStatus(status: String): Pair<String, Color> = when (status) {
    "active" -> stringResource(R.string.mko_status_active) to Color(0xFF0EA5E9)
    "completed" -> stringResource(R.string.mko_status_completed) to GREEN
    "declined" -> stringResource(R.string.mko_status_declined) to RED
    "expired" -> stringResource(R.string.mko_status_expired) to SLATE
    "cancelled" -> stringResource(R.string.mko_status_cancelled) to SLATE
    else -> stringResource(R.string.mko_status_awaiting) to AMBER
}

@Composable
private fun leftText(o: MarketOrderDto): String? {
    if (o.status != "awaiting") return null
    val left = leftOf(o.answerBy) ?: return null
    return when {
        left.isNegative || left.isZero -> stringResource(R.string.mko_left_over)
        left.toHours() >= 1 -> stringResource(R.string.mko_left_hours, left.toHours().toInt())
        else -> stringResource(R.string.mko_left_minutes, left.toMinutes().coerceAtLeast(1).toInt())
    }
}

@Composable
internal fun MyOrdersPage(onBack: () -> Unit, onOpen: (String) -> Unit, onBrowse: () -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    var tab by rememberSaveable { mutableStateOf(0) }
    val listState = rememberLazyListState()
    val monthly = tab == ORDER_TABS.size
    val paged = remember(tab) { PagedRows<MarketOrderDto>({ it.uuid }, { page -> ApiClient.freelanceServices.orders(auth(), status = ORDER_TABS.getOrElse(tab) { "active" }, page = page) }) }
    // FL-L05: my monthly subscriptions.
    val subs = remember { PagedRows<MarketSubscriptionDto>({ it.uuid }, { page -> ApiClient.freelanceServices.subscriptions(auth(), page = page) }) }
    LaunchedEffect(paged, reconnect, monthly) { if (monthly) subs.reload(generic) else paged.reload(generic) }
    listState.NearEnd(if (monthly) subs.rows.size else paged.rows.size) { scope.launch { if (monthly) subs.loadMore(generic) else paged.loadMore(generic) } }

    Column(Modifier.fillMaxSize()) {
        SubHeader(stringResource(R.string.mko_title), onBack)
        Box(Modifier.padding(horizontal = 16.dp, vertical = 4.dp)) {
            FlTabs(
                listOf(
                    FlTab(Icons.Rounded.HourglassTop, stringResource(R.string.mko_tab_awaiting)),
                    FlTab(Icons.Rounded.Bolt, stringResource(R.string.mko_tab_active)),
                    FlTab(Icons.Rounded.TaskAlt, stringResource(R.string.mko_tab_completed)),
                    FlTab(Icons.Rounded.Inbox, stringResource(R.string.mko_tab_ended)),
                    FlTab(Icons.Rounded.EventRepeat, stringResource(R.string.mks_tab)),
                ),
                tab,
            ) { tab = it }
        }
        if (monthly) {
            SubscriptionsList(subs, listState, onOpen, onBrowse)
            return@Column
        }
        LazyColumn(Modifier.weight(1f).fillMaxWidth(), state = listState, contentPadding = PaddingValues(horizontal = 16.dp, vertical = 10.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            when {
                !paged.loaded -> item { FlSkeletonList(count = 3, height = 130.dp) }
                paged.rows.isEmpty() && paged.error == null -> item {
                    com.dorr.app.ui.screens.wallet.WaEmpty(
                        Icons.Rounded.Receipt, com.dorr.app.ui.screens.wallet.Tone.Blue, stringResource(R.string.mko_empty_title), stringResource(R.string.mko_empty_body),
                        action = { FlAction(stringResource(R.string.mko_browse), Icons.Rounded.Storefront, FlTone.Primary, onClick = onBrowse) },
                    )
                }
                else -> items(paged.rows, key = { it.uuid }) { o -> OrderTile(o) { onOpen(o.uuid) } }
            }
            item { PagedSpinner(paged) }
            paged.error?.let { item { TextButton(onClick = { paged.clearError(); scope.launch { paged.loadMore(generic) } }, modifier = Modifier.fillMaxWidth()) { Text(it, color = RED, fontSize = 13.sp) } } }
        }
    }
}

@Composable
private fun OrderTile(o: MarketOrderDto, onClick: () -> Unit) {
    val (label, color) = orderStatus(o.status)
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).clickable(onClick = onClick).padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Box(Modifier.size(42.dp).clip(RoundedCornerShape(14.dp)).background(color.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.ShoppingBag, null, tint = color, modifier = Modifier.size(21.dp))
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text(o.service.title.orEmpty(), color = settingsInk(), fontSize = 14.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                Text(listOfNotNull(levelName(o.level), o.freelancer?.name).joinToString(" · "), color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
            Pill(label, color)
        }
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.Schedule, null, tint = settingsMut(), modifier = Modifier.size(15.dp))
            Spacer(Modifier.width(4.dp))
            Text(days(o.deliveryDays), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
            Text(mkMoney(o.money.totalMinor, o.decimals, o.currency), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
        }
        leftText(o)?.let { FlNotice(it, Icons.Rounded.HourglassTop, AMBER) }
    }
}

// ==================================================================== one order

@Composable
internal fun MyOrderPage(uuid: String, onBack: () -> Unit, onOpenWork: (String) -> Unit, onOrderAgain: (String) -> Unit) {
    val context = LocalContext.current
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    var order by remember(uuid) { mutableStateOf<MarketOrderDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var asking by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }

    LaunchedEffect(uuid, reconnect) {
        try {
            order = ApiClient.freelanceServices.orderOf(auth(), uuid).data
            error = null
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.apiFailure().message ?: generic
        }
    }

    fun cancel() {
        busy = true
        scope.launch {
            try {
                order = ApiClient.freelanceServices.cancel(auth(), uuid).data
                Toast.makeText(context, context.getString(R.string.mko_cancelled_toast), Toast.LENGTH_SHORT).show()
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                error = e.apiFailure().message ?: generic
            } finally {
                busy = false
                asking = false
            }
        }
    }

    val o = order
    Column(Modifier.fillMaxSize()) {
        SubHeader(stringResource(R.string.mko_detail), onBack)
        if (o == null) {
            Box(Modifier.weight(1f)) { FlLoading(error) }
        } else {
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = 16.dp).padding(bottom = 20.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            StatusHero(o)
            SectionCard(stringResource(R.string.mko_summary), Icons.Rounded.ShoppingBag) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Pill(levelName(o.level), settingsAccent())
                    o.tierName?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold) }
                }
                o.features.forEach { f ->
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Icon(Icons.Rounded.CheckCircle, null, tint = GREEN, modifier = Modifier.size(16.dp))
                        Text(f, color = settingsInk(), fontSize = 13.sp)
                    }
                }
                o.addons.forEach { a ->
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Icon(Icons.Rounded.AddCircleOutline, null, tint = settingsAccent(), modifier = Modifier.size(16.dp))
                        Text(a.title, color = settingsInk(), fontSize = 13.sp, modifier = Modifier.weight(1f))
                        Text(mkMoney(a.priceMinor, o.decimals, o.currency), color = settingsMut(), fontSize = 12.sp)
                    }
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Fact(Icons.Rounded.Schedule, days(o.deliveryDays), Modifier.weight(1f))
                    Fact(Icons.Rounded.Replay, if (o.revisions > 0) stringResource(R.string.mk_revisions, o.revisions) else stringResource(R.string.mk_no_revisions), Modifier.weight(1f))
                }
                o.note?.takeIf { it.isNotBlank() }?.let { n ->
                    Text(stringResource(R.string.mko_your_note), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
                    Text(n, color = settingsInk(), fontSize = 13.sp, lineHeight = 20.sp, modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.06f)).padding(10.dp))
                }
            }
            SectionCard(stringResource(R.string.mko_money), Icons.Rounded.Payments) {
                Line(stringResource(R.string.mk_price), mkMoney(o.money.priceMinor, o.decimals, o.currency))
                if (o.money.clientFeeMinor > 0) Line(stringResource(R.string.mk_fee), mkMoney(o.money.clientFeeMinor, o.decimals, o.currency))
                Box(Modifier.fillMaxWidth().height(1.dp).background(if (settingsNight()) AccountDark.line else Color(0xFFF0F1F4)))
                Line(stringResource(R.string.mk_total), mkMoney(o.money.totalMinor, o.decimals, o.currency), strong = true)
                if (o.status == "awaiting") Text(stringResource(R.string.mko_pay_later), color = settingsMut(), fontSize = 11.sp)
            }
            error?.let { FlNotice(it, Icons.Rounded.Close, RED) }

            when {
                (o.status == "active" || o.status == "completed") && o.project != null ->
                    SettingsPrimaryButton(text = stringResource(R.string.mko_open_work), onClick = { onOpenWork(o.project) })
                o.can.cancel -> FlAction(stringResource(R.string.mko_cancel), Icons.Rounded.Close, FlTone.Danger, onClick = { asking = true })
                o.service.uuid != null && o.status in listOf("declined", "expired", "cancelled") ->
                    FlAction(stringResource(R.string.mko_order_again), Icons.Rounded.Replay, FlTone.Primary, onClick = { onOrderAgain(o.service.uuid) })
            }
        }
        }
    }

    if (asking) ConfirmDialog(
        icon = Icons.Rounded.Close, title = stringResource(R.string.mko_cancel_title), message = stringResource(R.string.mko_cancel_body),
        onConfirm = ::cancel, onDismiss = { asking = false }, loading = busy, confirmLabel = stringResource(R.string.mko_cancel),
    )
}

/** Where the order stands: a pill, a line of what it means, and four steps (ordered → accepted → paid → done). */
@Composable
private fun StatusHero(o: MarketOrderDto) {
    val (label, color) = orderStatus(o.status)
    val ended = o.status in listOf("declined", "expired", "cancelled")
    val (title, body) = when (o.status) {
        "active" -> stringResource(R.string.mko_active_title) to stringResource(R.string.mko_active_body)
        "completed" -> stringResource(R.string.mko_completed_title) to stringResource(R.string.mko_completed_body)
        "declined" -> label to stringResource(R.string.mko_declined_body)
        "expired" -> label to stringResource(R.string.mko_expired_body)
        "cancelled" -> label to stringResource(R.string.mko_cancelled_body)
        else -> stringResource(R.string.mko_awaiting_title, o.freelancer?.name.orEmpty()) to stringResource(R.string.mko_awaiting_body)
    }
    // How far along: 1 ordered, 2 accepted, 4 done (paid is read on the work's page).
    val reached = when (o.status) { "active" -> 2; "completed" -> 4; else -> 1 }
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(22.dp)).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Pill(label, color, solid = true)
            Spacer(Modifier.weight(1f))
            // FL-L05: one month of a subscription.
            o.subscription?.let { Pill(stringResource(R.string.mks_month_n, it.cycle), settingsAccent()) }
                ?: Text(stringResource(R.string.mko_service_badge), color = settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
        }
        Text(o.service.title.orEmpty(), color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, lineHeight = 25.sp)
        if (!ended) {
            val steps = listOf(R.string.mko_step_ordered, R.string.mko_step_accepted, R.string.mko_step_paid, R.string.mko_step_done)
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                steps.forEachIndexed { i, s ->
                    val done = i < reached
                    Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.weight(1f)) {
                        Box(Modifier.size(24.dp).clip(CircleShape).background(if (done) settingsAccent() else settingsAccent().copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                            if (done) Icon(Icons.Rounded.CheckCircle, null, tint = Color.White, modifier = Modifier.size(15.dp))
                            else Text("${i + 1}", color = settingsAccent(), fontSize = 11.sp, fontWeight = FontWeight.ExtraBold)
                        }
                        Spacer(Modifier.height(4.dp))
                        Text(stringResource(s), color = if (done) settingsInk() else settingsMut(), fontSize = 11.sp, fontWeight = if (done) FontWeight.Bold else FontWeight.Medium, maxLines = 1)
                    }
                }
            }
        }
        Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(color.copy(alpha = if (settingsNight()) 0.18f else 0.08f)).padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(title, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
            Text(body, color = settingsMut(), fontSize = 12.5.sp, lineHeight = 19.sp)
            leftText(o)?.let { Text(it, color = AMBER, fontSize = 12.5.sp, fontWeight = FontWeight.Bold) }
        }
    }
}

// ==================================================================== FL-L05: monthly subscriptions

/** How a monthly subscription runs, on the service page before subscribing. */
@Composable
private fun SubscriptionTerms(t: MarketSubscriptionTermsDto) {
    SectionCard(stringResource(R.string.mks_terms_title), Icons.Rounded.EventRepeat) {
        listOf(
            stringResource(R.string.mks_term_month, t.periodDays),
            stringResource(R.string.mks_term_confirm, t.renewWindowDays),
            stringResource(R.string.mks_term_notice, t.noticeDays),
            stringResource(R.string.mks_term_price),
        ).forEach { line ->
            Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Icon(Icons.Rounded.CheckCircle, null, tint = GREEN, modifier = Modifier.padding(top = 2.dp).size(16.dp))
                Text(line, color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp)
            }
        }
    }
}

/**
 * My monthly subscriptions: each with the service, the freelancer, the month it is in and what a month costs, then where it stands —
 * confirm the next month when it opens (paid from the wallet in the same tap), the day this month ends, or the ending asked and its day.
 * I can end one with notice (said before asking) or take my ending back. Tapping opens the month running now.
 */
@Composable
private fun SubscriptionsList(subs: PagedRows<MarketSubscriptionDto>, listState: androidx.compose.foundation.lazy.LazyListState, onOpen: (String) -> Unit, onBrowse: () -> Unit) {
    val context = LocalContext.current
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf<String?>(null) }
    var renewing by remember { mutableStateOf<MarketSubscriptionDto?>(null) }
    var ending by remember { mutableStateOf<MarketSubscriptionDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }

    fun act(s: MarketSubscriptionDto, action: String) {
        busy = s.uuid
        scope.launch {
            try {
                if (action == "renew") ApiClient.freelanceServices.renewSubscription(auth(), s.uuid) else ApiClient.freelanceServices.subscriptionAct(auth(), s.uuid, action)
                error = null
                Toast.makeText(context, context.getString(when (action) { "renew" -> R.string.mks_renew_done; "cancel" -> R.string.mks_cancel_done; else -> R.string.mks_keep_done }), Toast.LENGTH_SHORT).show()
                subs.reload(generic)
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                error = e.apiFailure().message ?: generic
            }
            busy = null
            renewing = null
            ending = null
        }
    }

    LazyColumn(Modifier.fillMaxWidth(), state = listState, contentPadding = PaddingValues(horizontal = 16.dp, vertical = 10.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        error?.let { item { FlNotice(it, Icons.Rounded.Close, RED) } }
        when {
            !subs.loaded -> item { FlSkeletonList(count = 3, height = 150.dp) }
            subs.rows.isEmpty() && subs.error == null -> item {
                com.dorr.app.ui.screens.wallet.WaEmpty(
                    Icons.Rounded.EventRepeat, com.dorr.app.ui.screens.wallet.Tone.Blue, stringResource(R.string.mks_empty_title), stringResource(R.string.mks_empty_body),
                    action = { FlAction(stringResource(R.string.mko_browse), Icons.Rounded.Storefront, FlTone.Primary, onClick = onBrowse) },
                )
            }
            else -> items(subs.rows, key = { it.uuid }) { s ->
                SubscriptionTile(s, busy == s.uuid, onOpen = { s.current?.uuid?.let(onOpen) }, onRenew = { renewing = s }, onCancel = { ending = s }, onKeep = { act(s, "keep") })
            }
        }
        item { PagedSpinner(subs) }
        subs.error?.let { item { TextButton(onClick = { subs.clearError(); scope.launch { subs.loadMore(generic) } }, modifier = Modifier.fillMaxWidth()) { Text(it, color = RED, fontSize = 13.sp) } } }
    }

    renewing?.let { s ->
        FlSheet(Icons.Rounded.EventRepeat, stringResource(R.string.mks_renew_title), stringResource(R.string.mks_renew_body), onDismiss = { if (busy == null) renewing = null }) {
            Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(settingsAccent().copy(alpha = 0.06f)).padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(s.service.title.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                Line(stringResource(R.string.mks_month_n, s.cycle + 1), mkMoney(s.money.priceMinor, s.decimals, s.currency))
                if (s.money.clientFeeMinor > 0) Line(stringResource(R.string.mk_fee), mkMoney(s.money.clientFeeMinor, s.decimals, s.currency))
                Line(stringResource(R.string.mk_total), mkMoney(s.money.totalMinor, s.decimals, s.currency), strong = true)
            }
            FlNotice(stringResource(R.string.mks_renew_safe), Icons.Rounded.Lock, GREEN)
            SettingsPrimaryButton(text = stringResource(R.string.mks_renew_cta), loading = busy == s.uuid, onClick = { act(s, "renew") })
        }
    }
    ending?.let { s ->
        ConfirmDialog(
            icon = Icons.Rounded.EventBusy, title = stringResource(R.string.mks_cancel_title), message = stringResource(R.string.mks_cancel_body, s.noticeDays),
            onConfirm = { act(s, "cancel") }, onDismiss = { ending = null }, loading = busy == s.uuid, confirmLabel = stringResource(R.string.mks_cancel),
        )
    }
}

@Composable
private fun SubscriptionTile(s: MarketSubscriptionDto, busy: Boolean, onOpen: () -> Unit, onRenew: () -> Unit, onCancel: () -> Unit, onKeep: () -> Unit) {
    val (label, color) = when (s.status) {
        "active" -> stringResource(R.string.mks_status_active) to GREEN
        "pending" -> stringResource(R.string.mks_status_pending) to AMBER
        else -> stringResource(R.string.mks_status_ended) to SLATE
    }
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).clickable(enabled = s.current != null, onClick = onOpen).padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Box(Modifier.size(42.dp).clip(RoundedCornerShape(14.dp)).background(color.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.EventRepeat, null, tint = color, modifier = Modifier.size(21.dp))
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text(s.service.title.orEmpty(), color = settingsInk(), fontSize = 14.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                Text(listOfNotNull(levelName(s.level), s.freelancer?.name).joinToString(" · "), color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
            Pill(label, color)
        }
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(stringResource(R.string.mks_month_n, s.cycle), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
            Text(stringResource(R.string.mks_price_month, mkMoney(s.money.totalMinor, s.decimals, s.currency)), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
        }
        val cancel = s.cancel
        when {
            s.status == "ended" -> FlNotice(stringResource(subEndReason(s.endReason)), Icons.Rounded.EventBusy, SLATE)
            cancel != null -> FlNotice(stringResource(if (cancel.by == "client") R.string.mks_ending_me else R.string.mks_ending_freelancer, dayText(cancel.endsAt)), Icons.Rounded.EventBusy, AMBER)
            s.renew?.open == true -> FlNotice(stringResource(R.string.mks_renew_due, dayText(s.renew.dueBy)), Icons.Rounded.HourglassTop, AMBER)
            s.status == "active" -> FlNotice(stringResource(R.string.mks_month_ends, dayText(s.periodEndsAt)), Icons.Rounded.Schedule, GREEN)
            else -> FlNotice(stringResource(R.string.mks_pending_hint), Icons.Rounded.HourglassTop, AMBER)
        }
        if (s.can.renew) SettingsPrimaryButton(text = stringResource(R.string.mks_renew_cta), loading = busy, onClick = onRenew)
        when {
            s.can.keep -> FlAction(stringResource(R.string.mks_keep), Icons.Rounded.Replay, FlTone.Primary, loading = busy, onClick = onKeep)
            s.can.cancel -> FlAction(stringResource(R.string.mks_cancel), Icons.Rounded.EventBusy, FlTone.Danger, onClick = onCancel)
        }
    }
}

private fun subEndReason(reason: String?): Int = when (reason) {
    "cancelled_client" -> R.string.mks_end_me
    "cancelled_freelancer" -> R.string.mks_end_freelancer
    "not_renewed" -> R.string.mks_end_not_renewed
    else -> R.string.mks_end_first
}

/** "Fri 30 Oct" in the phone's zone. */
private fun dayText(iso: String?): String = runCatching {
    OffsetDateTime.parse(iso).atZoneSameInstant(java.time.ZoneId.systemDefault()).format(java.time.format.DateTimeFormatter.ofPattern("EEE d MMM"))
}.getOrDefault("")
