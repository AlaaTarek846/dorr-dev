package com.dorr.driver.ui

import android.content.Intent
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Place
import androidx.compose.material.icons.rounded.School
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.R
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.PlaceDto
import com.dorr.driver.net.TrainerPackDto
import com.dorr.driver.net.TrainerSessionDto
import com.dorr.driver.ui.kit.Banner
import com.dorr.driver.ui.kit.Chip
import com.dorr.driver.ui.kit.DorrField
import com.dorr.driver.ui.kit.ListRow
import com.dorr.driver.ui.kit.OutlineButton
import com.dorr.driver.ui.kit.PrimaryButton
import com.dorr.driver.ui.kit.ScreenHeader
import com.dorr.driver.ui.kit.TileIcon
import com.dorr.driver.ui.kit.staggerIn
import com.dorr.driver.ui.kit.surface
import com.dorr.driver.ui.theme.Pal
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.time.OffsetDateTime

/*
 * Driving lessons, the trainer's side (docs/rides_tasks.md O-26b): my lesson packs (publish, pause),
 * the lessons learners ask for (accept / decline, call them, the pickup), and «done» once given — the
 * lesson's share lands in the wallet. Requests arrive as notifications (`lesson.*`); the page reloads once.
 */

private val Plum = Color(0xFF7B1FA2)

@Composable
fun TrainerScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var tab by remember { mutableStateOf("lessons") }
    var packs by remember { mutableStateOf<List<TrainerPackDto>?>(null) }
    var sessions by remember { mutableStateOf<List<TrainerSessionDto>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var formOpen by remember { mutableStateOf(false) }
    var tick by remember { mutableStateOf(0) }

    LaunchedEffect(tick) {
        when (val r = apiCall { DriverBackend.lessons.sessions() }) {
            is ApiResult.Ok -> sessions = r.data
            is ApiResult.Failed -> error = r.error.displayMessage
            else -> {}
        }
        (apiCall { DriverBackend.lessons.packs() } as? ApiResult.Ok)?.let { packs = it.data }
    }
    LaunchedEffect(Unit) { com.dorr.driver.live.DriverLive.events.collect { e -> if (e.name.startsWith("lesson.")) tick++ } }

    fun <T> act(call: suspend () -> retrofit2.Response<com.dorr.core.network.ApiEnvelope<T>>) {
        error = null
        scope.launch {
            when (val r = apiCall { call() }) {
                is ApiResult.Ok -> tick++
                is ApiResult.Failed -> error = r.error.displayMessage
                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
            }
        }
    }

    Column(Modifier.fillMaxSize().background(Pal.bg)) {
        ScreenHeader(stringResource(R.string.tr_title), onBack = onBack, modifier = Modifier.statusBarsPadding())
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Chip(stringResource(R.string.tr_lessons_tab, sessions?.count { it.status == "requested" } ?: 0), selected = tab == "lessons", tint = Plum) { tab = "lessons" }
                Chip(stringResource(R.string.tr_packs_tab), selected = tab == "packs", tint = Plum) { tab = "packs" }
            }
            Banner(error)
            if (tab == "packs") {
                PrimaryButton(stringResource(R.string.tr_new_pack), icon = Icons.Rounded.Add, color = Plum) { formOpen = true }
                val list = packs.orEmpty()
                if (packs != null && list.isEmpty()) Text(stringResource(R.string.tr_no_packs), color = Pal.mut, modifier = Modifier.padding(16.dp))
                list.forEachIndexed { i, p ->
                    Column(Modifier.fillMaxWidth().surface().padding(14.dp).staggerIn(i)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            TileIcon(Icons.Rounded.School, Plum, 42.dp)
                            Column(Modifier.weight(1f).padding(horizontal = 10.dp)) {
                                Text(p.title, fontWeight = FontWeight.Bold, color = Pal.ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                Text(
                                    stringResource(R.string.tr_pack_line, p.lessons, p.minutesPerLesson) + " · " + money(p.priceMinor, p.currency),
                                    color = Pal.mut, fontSize = 12.sp,
                                )
                                p.learners?.let { Text(stringResource(R.string.tr_learners, it), color = Plum, fontSize = 12.sp, fontWeight = FontWeight.SemiBold) }
                            }
                            com.dorr.driver.ui.kit.DorrToggle(p.status == "active") { on ->
                                act { DriverBackend.lessons.status(p.uuid, JsonObject().apply { addProperty("status", if (on) "active" else "paused") }) }
                            }
                        }
                    }
                }
            } else {
                val list = sessions.orEmpty()
                if (sessions != null && list.isEmpty()) {
                    Column(Modifier.fillMaxWidth().padding(28.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                        TileIcon(Icons.Rounded.School, Plum, 56.dp)
                        Text(stringResource(R.string.tr_no_lessons), color = Pal.mut, modifier = Modifier.padding(top = 10.dp))
                    }
                }
                list.forEachIndexed { i, s ->
                    Column(Modifier.fillMaxWidth().surface().padding(14.dp).staggerIn(i)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(busDay(s.startsAt) + " · " + busTime(s.startsAt), fontWeight = FontWeight.Black, color = Pal.ink, modifier = Modifier.weight(1f))
                            Text(
                                stringResource(if (s.status == "confirmed") R.string.tr_confirmed else R.string.tr_requested),
                                color = if (s.status == "confirmed") Pal.success else Pal.orange, fontSize = 11.sp, fontWeight = FontWeight.Bold,
                                modifier = Modifier.clip(CircleShape).background((if (s.status == "confirmed") Pal.success else Pal.orange).copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 4.dp),
                            )
                        }
                        Text(
                            (s.learner?.name ?: "—") + " · " + stringResource(R.string.tr_lesson_of, s.number, s.pack?.lessons ?: 0) + " · " + (s.pack?.title ?: ""),
                            color = Pal.ink, fontSize = 13.sp, modifier = Modifier.padding(top = 4.dp),
                        )
                        s.pickup?.address?.let { Text("📍 $it", color = Pal.mut, fontSize = 12.sp, maxLines = 2, overflow = TextOverflow.Ellipsis) }
                        s.learner?.phone?.let { phone ->
                            Row(Modifier.padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                Chip("📞 " + stringResource(R.string.tr_call), tint = Pal.success) { runCatching { context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$phone"))) } }
                                s.pickup?.let { p ->
                                    Chip("🧭 " + stringResource(R.string.hj_navigate), tint = Plum) { runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("google.navigation:q=${p.lat},${p.lng}"))) } }
                                }
                            }
                        }
                        Spacer(Modifier.padding(top = 6.dp))
                        when (s.status) {
                            "requested" -> Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                Box(Modifier.weight(1f)) { PrimaryButton(stringResource(R.string.tr_accept), color = Plum) { act { DriverBackend.lessons.answer(s.uuid, JsonObject().apply { addProperty("approve", true) }) } } }
                                Box(Modifier.weight(1f)) { OutlineButton(stringResource(R.string.tr_decline), color = Pal.danger) { act { DriverBackend.lessons.answer(s.uuid, JsonObject().apply { addProperty("approve", false) }) } } }
                            }
                            "confirmed" -> {
                                val started = s.startsAt?.let { runCatching { OffsetDateTime.parse(it).isBefore(OffsetDateTime.now()) }.getOrNull() } == true
                                PrimaryButton(stringResource(R.string.tr_done), enabled = started, color = Pal.success) { act { DriverBackend.lessons.done(s.uuid) } }
                            }
                        }
                    }
                }
            }
            Spacer(Modifier.navigationBarsPadding())
        }
    }
    if (formOpen) PackForm(onClose = { formOpen = false }, onDone = { formOpen = false; tab = "packs"; tick++ })
}

@Composable
private fun PackForm(onClose: () -> Unit, onDone: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var title by remember { mutableStateOf("") }
    var description by remember { mutableStateOf("") }
    var transmission by remember { mutableStateOf("automatic") }
    var lessons by remember { mutableStateOf("10") }
    var minutes by remember { mutableStateOf("60") }
    var price by remember { mutableStateOf("") }
    var ownCar by remember { mutableStateOf(true) }
    var area by remember { mutableStateOf<PlaceDto?>(null) }
    var picking by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    fun minor(t: String): Long? = t.replace(',', '.').toBigDecimalOrNull()?.movePointRight(2)?.toLong()?.takeIf { it > 0 }
    val ready = title.isNotBlank() && area != null && (lessons.toIntOrNull() ?: 0) > 0 && (minutes.toIntOrNull() ?: 0) >= 15 && minor(price) != null

    BackHandler(onBack = onClose)
    Column(Modifier.fillMaxSize().background(Pal.bg)) {
        ScreenHeader(stringResource(R.string.tr_new_pack), onBack = onClose, modifier = Modifier.statusBarsPadding())
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            DorrField(title, { title = it.take(120) }, label = { Text(stringResource(R.string.tr_pack_name)) }, singleLine = true)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Chip(stringResource(R.string.hj_automatic), selected = transmission == "automatic", tint = Plum) { transmission = "automatic" }
                Chip(stringResource(R.string.hj_manual), selected = transmission == "manual", tint = Plum) { transmission = "manual" }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Box(Modifier.weight(1f)) { DorrField(lessons, { lessons = it.filter(Char::isDigit).take(2) }, label = { Text(stringResource(R.string.tr_lessons)) }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number)) }
                Box(Modifier.weight(1f)) { DorrField(minutes, { minutes = it.filter(Char::isDigit).take(3) }, label = { Text(stringResource(R.string.tr_minutes)) }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number)) }
            }
            DorrField(price, { price = it }, label = { Text(stringResource(R.string.tr_price)) }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal))
            Column(Modifier.fillMaxWidth().surface()) {
                ListRow(Icons.Rounded.Place, area?.address ?: stringResource(R.string.tr_pick_area), subtitle = stringResource(R.string.tr_area), tint = Plum) { picking = true }
            }
            Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable { ownCar = !ownCar }.padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(stringResource(R.string.tr_own_car), fontWeight = FontWeight.Bold, color = Pal.ink, modifier = Modifier.weight(1f))
                com.dorr.driver.ui.kit.DorrToggle(ownCar) { ownCar = it }
            }
            DorrField(description, { description = it.take(2000) }, label = { Text(stringResource(R.string.mr_description)) }, minLines = 3)
            Banner(error)
            PrimaryButton(stringResource(R.string.mr_publish), busy = busy, enabled = ready, color = Plum) {
                busy = true; error = null
                scope.launch {
                    val body = JsonObject().apply {
                        addProperty("title", title.trim())
                        if (description.isNotBlank()) addProperty("description", description.trim())
                        addProperty("transmission", transmission)
                        addProperty("lessons", lessons.toInt())
                        addProperty("minutes_per_lesson", minutes.toInt())
                        addProperty("price_minor", minor(price))
                        addProperty("own_car", ownCar)
                        add("area", JsonObject().apply { addProperty("lat", area!!.lat); addProperty("lng", area!!.lng); addProperty("address", area!!.address) })
                    }
                    when (val r = apiCall { DriverBackend.lessons.publish(body) }) {
                        is ApiResult.Ok -> onDone()
                        is ApiResult.Failed -> error = r.error.displayMessage
                        is ApiResult.Offline -> error = context.getString(R.string.error_offline)
                    }
                    busy = false
                }
            }
            Spacer(Modifier.navigationBarsPadding())
        }
    }
    if (picking) PlacePicker(onClose = { picking = false }) { area = it; picking = false }
}
