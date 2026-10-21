package com.dorr.app.ui.screens.rides

import android.content.Intent
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.Canvas
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
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.DirectionsCar
import androidx.compose.material.icons.rounded.Place
import androidx.compose.material.icons.rounded.School
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AppLocale
import com.dorr.app.network.LessonEnrollmentDto
import com.dorr.app.network.LessonPackDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.theme.AppColors
import com.google.gson.JsonObject
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.time.LocalDate
import java.time.LocalTime
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import java.util.UUID

/*
 * Driving lessons (docs/rides_tasks.md O-26b): trainers' lesson packs near me (automatic or manual, how
 * many lessons, how long, the price per lesson), buy one from the wallet, then book each lesson at a time
 * that suits me and where to be picked up; a ring fills as lessons are done. Confirmations arrive as
 * notifications (`lesson.*`) and reload the page once — nothing asks the server on a timer.
 */

private val Plum = Color(0xFF6A1B9A)
private val Lilac = Color(0xFFBA68C8)

private sealed interface LessonPage {
    data object Browse : LessonPage
    data class Pack(val pack: LessonPackDto) : LessonPage
    data object Mine : LessonPage
    data class Enrollment(val enrollment: LessonEnrollmentDto, val fresh: Boolean = false) : LessonPage
}

@Composable
internal fun LessonsHost(state: RidesState, onClose: () -> Unit) {
    var page by remember { mutableStateOf<LessonPage>(LessonPage.Browse) }
    // Opened from a notification: that pack of mine (or my packs).
    LaunchedEffect(ServiceLink.pending) {
        if (ServiceLink.pending?.first != "lesson") return@LaunchedEffect
        val uuid = ServiceLink.take("lesson")
        page = uuid?.let { runCatching { ApiClient.lessons.enrollment(ridesAuth(), it).data }.getOrNull() }?.let { LessonPage.Enrollment(it) } ?: LessonPage.Mine
    }
    var forward by remember { mutableStateOf(true) }
    fun go(to: LessonPage, ahead: Boolean = true) { forward = ahead; page = to }
    BackHandler {
        when (page) {
            LessonPage.Browse -> onClose()
            is LessonPage.Pack, LessonPage.Mine -> go(LessonPage.Browse, false)
            is LessonPage.Enrollment -> go(LessonPage.Mine, false)
        }
    }
    Box(Modifier.fillMaxSize().background(settingsBackground())) {
        AnimatedContent(
            targetState = page,
            transitionSpec = { val s = if (forward) 1 else -1; (slideInHorizontally(tween(320)) { s * it / 4 } + fadeIn(tween(260))) togetherWith fadeOut(tween(180)) },
            label = "lesson-pages",
        ) { p ->
            when (p) {
                LessonPage.Browse -> LessonBrowse(state, onBack = onClose, onMine = { go(LessonPage.Mine) }, onPick = { go(LessonPage.Pack(it)) })
                is LessonPage.Pack -> LessonPackPage(p.pack, onBack = { go(LessonPage.Browse, false) }, onEnrolled = { go(LessonPage.Enrollment(it, fresh = true)) })
                LessonPage.Mine -> LessonMine(onBack = { go(LessonPage.Browse, false) }, onOpen = { go(LessonPage.Enrollment(it)) })
                is LessonPage.Enrollment -> LessonEnrollmentPage(state, p.enrollment, p.fresh, onBack = { go(LessonPage.Mine, false) })
            }
        }
    }
}

// ---------------------------------------------------------------- browse

@Composable
private fun LessonBrowse(rides: RidesState, onBack: () -> Unit, onMine: () -> Unit, onPick: (LessonPackDto) -> Unit) {
    var transmission by remember { mutableStateOf<String?>(null) }
    var list by remember(transmission) { mutableStateOf<List<LessonPackDto>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(transmission) {
        val near = rides.me ?: rides.pickup?.latLng
        if (near == null) { list = emptyList(); return@LaunchedEffect }
        runCatching { ApiClient.lessons.packs(ridesAuth(), near.latitude, near.longitude, transmission).data.orEmpty() }
            .onSuccess { list = it; error = null }.onFailure { error = it.apiFailure().message; list = emptyList() }
    }
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState())) {
        LessonHero(onBack, onMine)
        Row(Modifier.padding(16.dp).horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf(null to R.string.ls_all, "automatic" to R.string.ls_automatic, "manual" to R.string.ls_manual).forEach { (v, label) ->
                LessonChip(stringResource(label), transmission == v) { transmission = v }
            }
        }
        val rows = list
        when {
            rows == null -> Box(Modifier.fillMaxWidth().padding(36.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Plum) }
            error != null && rows.isEmpty() -> Text(error.orEmpty(), color = AppColors.danger, modifier = Modifier.padding(horizontal = 20.dp))
            rows.isEmpty() -> Text(stringResource(R.string.ls_none), color = settingsMut(), textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(36.dp))
            else -> Column(Modifier.padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                rows.forEachIndexed { i, p -> Box(Modifier.staggerIn(i)) { PackCard(p) { onPick(p) } } }
            }
        }
        Spacer(Modifier.navigationBarsPadding().height(24.dp))
    }
}

/** The hero: a little car driving a dashed road, the «L» plate bobbing. */
@Composable
private fun LessonHero(onBack: () -> Unit, onMine: () -> Unit) {
    val t = rememberInfiniteTransition(label = "road")
    val dash by t.animateFloat(0f, 60f, infiniteRepeatable(tween(900)), label = "dash")
    val bob by t.animateFloat(-1f, 1f, infiniteRepeatable(tween(1300), RepeatMode.Reverse), label = "bob")
    Box(Modifier.fillMaxWidth().clip(RoundedCornerShape(bottomStart = 34.dp, bottomEnd = 34.dp)).background(Brush.linearGradient(listOf(Plum, Lilac)))) {
        Canvas(Modifier.fillMaxWidth().height(200.dp)) {
            val y = size.height * 0.82f
            var x = -dash
            while (x < size.width) { drawLine(Color.White.copy(alpha = 0.45f), Offset(x, y), Offset(x + 30f, y), strokeWidth = 6f, cap = StrokeCap.Round); x += 60f }
        }
        Column(Modifier.statusBarsPadding().padding(horizontal = 18.dp).padding(top = 10.dp, bottom = 34.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                LRound(Icons.AutoMirrored.Rounded.ArrowBack, onBack)
                Spacer(Modifier.weight(1f))
                LRound(Icons.Rounded.School, onMine)
            }
            Spacer(Modifier.height(12.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.ls_title), color = Color.White, fontWeight = FontWeight.Black, fontSize = 26.sp)
                    Text(stringResource(R.string.ls_subtitle), color = Color.White.copy(alpha = 0.88f), fontSize = 13.sp)
                }
                Box(contentAlignment = Alignment.TopEnd) {
                    Icon(Icons.Rounded.DirectionsCar, null, tint = Color.White, modifier = Modifier.size(62.dp).graphicsLayer { translationY = bob * 4f })
                    Text(
                        "L", color = Color(0xFFD32F2F), fontWeight = FontWeight.Black, fontSize = 13.sp, textAlign = TextAlign.Center,
                        modifier = Modifier.graphicsLayer { rotationZ = bob * 10f }.clip(RoundedCornerShape(4.dp)).background(Color.White).padding(horizontal = 5.dp, vertical = 1.dp),
                    )
                }
            }
        }
    }
}

@Composable
private fun PackCard(p: LessonPackDto, onClick: () -> Unit) {
    Column(
        Modifier.fillMaxWidth().shadow(10.dp, RoundedCornerShape(24.dp), spotColor = Plum.copy(alpha = 0.25f)).clip(RoundedCornerShape(24.dp))
            .background(settingsCard()).pressable(onClick = onClick).padding(16.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(46.dp).clip(CircleShape).background(Brush.linearGradient(listOf(Plum, Lilac))), contentAlignment = Alignment.Center) {
                Text(p.trainer?.name?.take(1) ?: "?", color = Color.White, fontWeight = FontWeight.Black, fontSize = 18.sp)
            }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(p.title, color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 16.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(stringResource(R.string.ls_trainer, p.trainer?.name.orEmpty()), color = settingsMut(), fontSize = 12.sp)
            }
            Column(horizontalAlignment = Alignment.End) {
                Text(money(p.priceMinor, p.currency), color = Plum, fontWeight = FontWeight.Black, fontSize = 17.sp)
                Text(stringResource(R.string.ls_per_lesson, money(p.perLessonMinor, p.currency)), color = settingsMut(), fontSize = 11.sp)
            }
        }
        Row(Modifier.padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            Badge(stringResource(R.string.ls_lessons_n, p.lessons, p.minutesPerLesson))
            Badge(stringResource(if (p.transmission == "manual") R.string.ls_manual else R.string.ls_automatic))
            if (p.ownCar) Badge(stringResource(R.string.ls_own_car))
        }
        Row(Modifier.padding(top = 8.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.Place, null, tint = Plum, modifier = Modifier.size(15.dp))
            Text(" " + p.area.address.orEmpty(), color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
    }
}

@Composable
private fun LessonPackPage(p: LessonPackDto, onBack: () -> Unit, onEnrolled: (LessonEnrollmentDto) -> Unit) {
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val key = remember { UUID.randomUUID().toString() }
    Column(Modifier.fillMaxSize()) {
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
            Box(Modifier.fillMaxWidth().clip(RoundedCornerShape(bottomStart = 30.dp, bottomEnd = 30.dp)).background(Brush.linearGradient(listOf(Plum, Lilac)))) {
                Column(Modifier.statusBarsPadding().padding(18.dp)) {
                    LRound(Icons.AutoMirrored.Rounded.ArrowBack, onBack)
                    Spacer(Modifier.height(12.dp))
                    Text(p.title, color = Color.White, fontWeight = FontWeight.Black, fontSize = 24.sp)
                    Text(stringResource(R.string.ls_trainer, p.trainer?.name.orEmpty()), color = Color.White.copy(alpha = 0.88f), fontSize = 13.sp)
                }
            }
            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                LCard {
                    Info(Icons.Rounded.School, stringResource(R.string.ls_what), stringResource(R.string.ls_lessons_n, p.lessons, p.minutesPerLesson))
                    Info(Icons.Rounded.DirectionsCar, stringResource(R.string.ls_car), stringResource(if (p.transmission == "manual") R.string.ls_manual else R.string.ls_automatic) + if (p.ownCar) " · " + stringResource(R.string.ls_own_car) else "")
                    Info(Icons.Rounded.Place, stringResource(R.string.ls_area), p.area.address.orEmpty())
                    p.description?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 13.sp, modifier = Modifier.padding(top = 6.dp)) }
                }
                LCard {
                    listOf(R.string.ls_how_1, R.string.ls_how_2, R.string.ls_how_3).forEachIndexed { i, s ->
                        Row(Modifier.padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                            Box(Modifier.size(24.dp).clip(CircleShape).background(Plum.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) { Text("${i + 1}", color = Plum, fontWeight = FontWeight.Black, fontSize = 12.sp) }
                            Spacer(Modifier.width(8.dp))
                            Text(stringResource(s), color = settingsInk(), fontSize = 13.sp)
                        }
                    }
                }
                error?.let { Text(it, color = AppColors.danger, fontSize = 13.sp) }
            }
        }
        Row(Modifier.fillMaxWidth().shadow(18.dp).background(settingsCard()).navigationBarsPadding().padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.ls_pack_price), color = settingsMut(), fontSize = 12.sp)
                Text(money(p.priceMinor, p.currency), color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 20.sp)
            }
            Box(
                Modifier.clip(CircleShape).background(Brush.horizontalGradient(listOf(Plum, Lilac))).clickable(enabled = !busy) {
                    busy = true; error = null
                    scope.launch {
                        runCatching { ApiClient.lessons.enroll(ridesAuth(), key, p.uuid).data }.onSuccess { it?.let(onEnrolled) }.onFailure { error = it.apiFailure().message }
                        busy = false
                    }
                }.padding(horizontal = 26.dp, vertical = 14.dp),
            ) {
                if (busy) CircularProgressIndicator(color = Color.White, strokeWidth = 2.dp, modifier = Modifier.size(20.dp))
                else Text(stringResource(R.string.ls_buy), color = Color.White, fontWeight = FontWeight.Bold)
            }
        }
    }
}

// ---------------------------------------------------------------- my packs and lessons

@Composable
private fun LessonMine(onBack: () -> Unit, onOpen: (LessonEnrollmentDto) -> Unit) {
    var list by remember { mutableStateOf<List<LessonEnrollmentDto>?>(null) }
    LaunchedEffect(Unit) { list = runCatching { ApiClient.lessons.mine(ridesAuth()).data.orEmpty() }.getOrDefault(emptyList()) }
    Column(Modifier.fillMaxSize()) {
        LBar(stringResource(R.string.ls_mine), onBack)
        val rows = list
        when {
            rows == null -> Box(Modifier.fillMaxWidth().padding(40.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Plum) }
            rows.isEmpty() -> Text(stringResource(R.string.ls_no_mine), color = settingsMut(), textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(36.dp))
            else -> Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                rows.forEachIndexed { i, e ->
                    Row(Modifier.staggerIn(i).fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(settingsCard()).pressable { onOpen(e) }.padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                        ProgressRing(e.lessonsDone, e.lessonsTotal, 48)
                        Spacer(Modifier.width(12.dp))
                        Column(Modifier.weight(1f)) {
                            Text(e.pack.title, color = settingsInk(), fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            Text(stringResource(R.string.ls_done_of, e.lessonsDone, e.lessonsTotal), color = settingsMut(), fontSize = 12.sp)
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun LessonEnrollmentPage(rides: RidesState, initial: LessonEnrollmentDto, fresh: Boolean, onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var e by remember { mutableStateOf(initial) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var booking by remember { mutableStateOf(false) }
    suspend fun reload() { runCatching { ApiClient.lessons.enrollment(ridesAuth(), initial.uuid).data }.getOrNull()?.let { e = it } }
    LaunchedEffect(initial.uuid) {
        reload()
        com.dorr.app.chat.ChatRealtime.events.collect { ev -> if (ev.name.startsWith("lesson.")) reload() }
    }
    fun act(call: suspend () -> LessonEnrollmentDto?) {
        busy = true; error = null
        scope.launch { runCatching { call() }.onSuccess { it?.let { x -> e = x } }.onFailure { error = it.apiFailure().message }; busy = false }
    }

    Column(Modifier.fillMaxSize()) {
        LBar(e.pack.title, onBack)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            // O-67: held for its payment — the one payment screen (coupon, top-up), then the lessons.
            if (e.status == "unpaid") {
                ServicePayCard(ServicePay.LESSONS, e.uuid, e.paymentExpiresAt, autoOpen = fresh) { scope.launch { reload() } }
            }
            ProgressRing(e.lessonsDone, e.lessonsTotal, 130)
            Text(stringResource(R.string.ls_done_of, e.lessonsDone, e.lessonsTotal), color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 18.sp)
            e.trainerPhone?.let { phone ->
                Text(
                    "📞 " + stringResource(R.string.ls_call_trainer), color = AppColors.success, fontWeight = FontWeight.Bold, fontSize = 13.sp,
                    modifier = Modifier.clip(CircleShape).background(AppColors.success.copy(alpha = 0.12f)).clickable { runCatching { context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$phone"))) } }.padding(horizontal = 14.dp, vertical = 7.dp),
                )
            }
            if (e.status == "active" && e.lessonsLeft > 0) {
                Box(Modifier.fillMaxWidth().clip(CircleShape).background(Brush.horizontalGradient(listOf(Plum, Lilac))).clickable { booking = true }.padding(14.dp), contentAlignment = Alignment.Center) {
                    Text(stringResource(R.string.ls_book_lesson, e.lessonsLeft), color = Color.White, fontWeight = FontWeight.Bold)
                }
            }
            e.sessions.forEach { s ->
                LCard {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.size(36.dp).clip(CircleShape).background(if (s.status == "done") AppColors.success else Plum.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                            if (s.status == "done") Icon(Icons.Rounded.CheckCircle, null, tint = Color.White, modifier = Modifier.size(20.dp))
                            else Text("${s.number}", color = Plum, fontWeight = FontWeight.Black)
                        }
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text(stringResource(R.string.ls_lesson_n, s.number) + " · " + dayTimeOf(s.startsAt), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 13.sp)
                            Text(stringResource(sessionStatus(s.status)), color = if (s.status == "declined" || s.status == "canceled") AppColors.danger else settingsMut(), fontSize = 12.sp)
                        }
                        if (s.status == "requested" || s.status == "confirmed") {
                            Text(stringResource(R.string.ls_cancel), color = AppColors.danger, fontSize = 12.sp, fontWeight = FontWeight.Bold,
                                modifier = Modifier.clip(CircleShape).clickable(enabled = !busy) { act { ApiClient.lessons.cancelSession(ridesAuth(), s.uuid).data } }.padding(8.dp))
                        }
                    }
                }
            }
            error?.let { Text(it, color = AppColors.danger, fontSize = 13.sp) }
            if (e.status == "active") {
                Text(stringResource(R.string.ls_quit), color = AppColors.danger, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable(enabled = !busy) { act { ApiClient.lessons.quit(ridesAuth(), e.uuid).data } }.padding(12.dp))
            }
            if (e.refundedMinor > 0) Text(stringResource(R.string.ls_refunded, money(e.refundedMinor, e.pack.currency)), color = AppColors.success, fontSize = 13.sp)
            Spacer(Modifier.navigationBarsPadding())
        }
    }
    if (booking) {
        BookLessonSheet(rides, onClose = { booking = false }) { at, place ->
            booking = false
            act {
                ApiClient.lessons.book(ridesAuth(), e.uuid, JsonObject().apply {
                    addProperty("starts_at", at.toOffsetDateTime().toString())
                    add("pickup", JsonObject().apply { addProperty("lat", place.lat); addProperty("lng", place.lng); addProperty("address", place.address) })
                }).data
            }
        }
    }
}

/** Book a lesson: the day, the hour, and where the trainer picks me up. */
@Composable
private fun BookLessonSheet(rides: RidesState, onClose: () -> Unit, onBook: (ZonedDateTime, RidePoint) -> Unit) {
    val context = LocalContext.current
    var day by remember { mutableStateOf(LocalDate.now().plusDays(1)) }
    var hour by remember { mutableStateOf<Int?>(null) }
    var place by remember { mutableStateOf(rides.pickup) }
    var query by remember { mutableStateOf("") }
    var results by remember { mutableStateOf<List<RidePoint>>(emptyList()) }
    LaunchedEffect(query) { delay(300); results = searchPlaces(context, query, rides.me ?: rides.pickup?.latLng) }
    BackHandler(onBack = onClose)
    Column(Modifier.fillMaxSize().background(settingsBackground())) {
        LBar(stringResource(R.string.ls_book_title), onClose)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            LCard {
                Info(Icons.Rounded.Schedule, stringResource(R.string.ls_when), "")
                Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    (0L..20L).map { LocalDate.now().plusDays(it) }.forEach { d -> LessonChip(d.format(DateTimeFormatter.ofPattern("EEE d", AppLocale.java())), d == day) { day = d; hour = null } }
                }
                Spacer(Modifier.height(8.dp))
                val now = ZonedDateTime.now()
                Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    (7..22).forEach { h ->
                        val at = ZonedDateTime.of(day, LocalTime.of(h, 0), ZoneId.systemDefault())
                        LessonChip(at.format(DateTimeFormatter.ofPattern("h a", AppLocale.java())), hour == h, enabled = at.isAfter(now)) { hour = h }
                    }
                }
            }
            LCard {
                Info(Icons.Rounded.Place, stringResource(R.string.ls_pickup), place?.address ?: "—")
                com.dorr.app.ui.components.DorrTextField(value = query, onValueChange = { query = it }, placeholder = stringResource(R.string.rd_search_hint), icon = Icons.Rounded.Search, modifier = Modifier.fillMaxWidth())
                results.take(5).forEach { r ->
                    Text(r.address, color = settingsInk(), fontSize = 13.sp, modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).clickable { place = r; query = ""; results = emptyList() }.padding(10.dp))
                }
            }
        }
        val at = hour?.let { ZonedDateTime.of(day, LocalTime.of(it, 0), ZoneId.systemDefault()) }
        val ok = at != null && place != null
        Box(
            Modifier.fillMaxWidth().navigationBarsPadding().padding(16.dp).clip(CircleShape)
                .background(if (ok) Brush.horizontalGradient(listOf(Plum, Lilac)) else Brush.horizontalGradient(listOf(settingsMut(), settingsMut())))
                .clickable(enabled = ok) { onBook(at!!, place!!) }.padding(14.dp),
            contentAlignment = Alignment.Center,
        ) { Text(stringResource(R.string.ls_send_request), color = Color.White, fontWeight = FontWeight.Bold) }
    }
}

// ---------------------------------------------------------------- small pieces

/** A ring that fills with the lessons done. */
@Composable
private fun ProgressRing(done: Int, total: Int, sizeDp: Int) {
    val target = if (total <= 0) 0f else done.toFloat() / total
    val sweep by animateFloatAsState(target, tween(900), label = "ring")
    Box(Modifier.size(sizeDp.dp), contentAlignment = Alignment.Center) {
        Canvas(Modifier.fillMaxSize()) {
            val w = size.minDimension * 0.1f
            drawArc(Plum.copy(alpha = 0.12f), 0f, 360f, false, style = Stroke(w), topLeft = Offset(w / 2, w / 2), size = Size(size.width - w, size.height - w))
            drawArc(Brush.sweepGradient(listOf(Plum, Lilac, Plum)), -90f, 360f * sweep, false, style = Stroke(w, cap = StrokeCap.Round), topLeft = Offset(w / 2, w / 2), size = Size(size.width - w, size.height - w))
        }
        if (done >= total && total > 0) Icon(Icons.Rounded.Star, null, tint = Plum, modifier = Modifier.size((sizeDp / 3).dp))
        else Text("$done/$total", color = Plum, fontWeight = FontWeight.Black, fontSize = (sizeDp / 5).sp)
    }
}

@Composable
private fun LCard(content: @Composable () -> Unit) {
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(22.dp)).background(settingsCard()).padding(16.dp)) { content() }
}

@Composable
private fun LBar(title: String, onBack: () -> Unit) {
    Row(Modifier.fillMaxWidth().background(Brush.horizontalGradient(listOf(Plum, Lilac))).statusBarsPadding().padding(horizontal = 16.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
        LRound(Icons.AutoMirrored.Rounded.ArrowBack, onBack)
        Spacer(Modifier.width(12.dp))
        Text(title, color = Color.White, fontWeight = FontWeight.Black, fontSize = 20.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

@Composable
private fun LRound(icon: ImageVector, onClick: () -> Unit) {
    Box(Modifier.size(40.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.2f)).clickable(onClick = onClick), contentAlignment = Alignment.Center) {
        Icon(icon, null, tint = Color.White, modifier = Modifier.size(20.dp))
    }
}

@Composable
private fun LessonChip(text: String, on: Boolean, enabled: Boolean = true, onClick: () -> Unit) {
    val bg by animateColorAsState(if (on) Plum else settingsCard(), tween(200), label = "chip")
    Text(
        text, color = if (on) Color.White else if (enabled) settingsInk() else settingsMut().copy(alpha = 0.5f), fontSize = 13.sp, fontWeight = if (on) FontWeight.Bold else FontWeight.Medium,
        modifier = Modifier.clip(CircleShape).background(bg).clickable(enabled = enabled, onClick = onClick).padding(horizontal = 14.dp, vertical = 8.dp),
    )
}

@Composable
private fun Badge(text: String) {
    Text(text, color = Plum, fontSize = 11.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(CircleShape).background(Plum.copy(alpha = 0.1f)).padding(horizontal = 10.dp, vertical = 4.dp))
}

@Composable
private fun Info(icon: ImageVector, label: String, value: String) {
    Row(Modifier.padding(vertical = 5.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(30.dp).clip(CircleShape).background(Plum.copy(alpha = 0.1f)), contentAlignment = Alignment.Center) { Icon(icon, null, tint = Plum, modifier = Modifier.size(16.dp)) }
        Spacer(Modifier.width(10.dp))
        Column {
            Text(label, color = settingsMut(), fontSize = 11.sp)
            if (value.isNotEmpty()) Text(value, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.SemiBold)
        }
    }
}

private fun sessionStatus(s: String): Int = when (s) {
    "confirmed" -> R.string.ls_st_confirmed
    "done" -> R.string.ls_st_done
    "declined" -> R.string.ls_st_declined
    "canceled" -> R.string.ls_st_canceled
    else -> R.string.ls_st_requested
}

private fun dayTimeOf(iso: String?): String = iso?.let {
    runCatching { OffsetDateTime.parse(it).atZoneSameInstant(ZoneId.systemDefault()).format(DateTimeFormatter.ofPattern("EEE d MMM · h:mm a", AppLocale.java())) }.getOrNull()
} ?: "—"
