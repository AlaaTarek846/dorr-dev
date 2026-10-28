package com.dorr.app.ui.screens.rides

import android.app.Activity
import android.content.ActivityNotFoundException
import android.content.Intent
import android.speech.RecognizerIntent
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AcUnit
import androidx.compose.material.icons.rounded.AccessTime
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.Bookmark
import androidx.compose.material.icons.rounded.DirectionsCar
import androidx.compose.material.icons.rounded.Group
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.LocationOn
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.MusicNote
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Send
import androidx.compose.material.icons.rounded.Spa
import androidx.compose.material.icons.rounded.StickyNote2
import androidx.compose.material.icons.rounded.VolumeOff
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
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
import androidx.compose.ui.draw.rotate
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.map.DorrCamera
import com.dorr.app.network.ApiClient
import com.dorr.app.network.RideAiPlaceDto
import com.dorr.app.network.RideAiStatusDto
import com.dorr.app.network.RideAiUnderstandDto
import com.dorr.app.network.RideChoiceDto
import com.dorr.app.network.RideComfortPrefsDto
import com.dorr.app.network.RideComfortScreenDto
import com.dorr.app.network.RideDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.aichat.AiPlanGate
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.theme.AppColors
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.time.ZonedDateTime
import java.util.Locale

/* Block 06 — DORR AI in the rider's rides: «دُرر يفهم مقصدك» (the trip in my own words, text or voice),
 * the category I usually take (AI-09), «راحتي», and the captain finishing nearby / a booking that may be late. */

private val AiGradient = listOf(Color(0xFF6D5BFF), Color(0xFFB54BFF), Color(0xFFFF7A59))

/** The entry under "Where to?": a glowing pill that opens the assistant — locked with the plan when needed. */
@Composable
internal fun SmartDestinationEntry(state: RidesState, onOpen: () -> Unit) {
    if (!state.feature("ai_booking")) return
    var status by remember { mutableStateOf<RideAiStatusDto?>(null) }
    LaunchedEffect(state.pickup?.lat) {
        status = runCatching { ApiClient.rides.aiStatus(ridesAuth(), state.pickup?.lat ?: state.me?.latitude, state.pickup?.lng ?: state.me?.longitude).data }.getOrNull()
    }
    val s = status ?: return
    if (!s.available) return
    val planText = stringResource(R.string.rd_ai_plan_needed)
    val shimmer = rememberInfiniteTransition(label = "ai-entry")
    val spin by shimmer.animateFloat(0f, 360f, infiniteRepeatable(tween(4000, easing = LinearEasing), RepeatMode.Restart), label = "spin")
    Spacer(Modifier.height(10.dp))
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp))
            .background(Brush.horizontalGradient(AiGradient.map { it.copy(alpha = 0.14f) }))
            .border(BorderStroke(1.dp, Brush.horizontalGradient(AiGradient.map { it.copy(alpha = 0.5f) })), RoundedCornerShape(16.dp))
            .pressable { if (s.allowed) onOpen() else AiPlanGate.offer(planText) }
            .padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(34.dp).clip(CircleShape).background(Brush.linearGradient(AiGradient)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.AutoAwesome, null, tint = Color.White, modifier = Modifier.size(18.dp).rotate(if (s.allowed) spin else 0f))
        }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(stringResource(R.string.rd_ai_title), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 14.sp)
            Text(stringResource(R.string.rd_ai_sub), color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        if (!s.allowed) {
            Row(Modifier.clip(RoundedCornerShape(50)).background(settingsCard()).padding(horizontal = 8.dp, vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.Lock, null, tint = AppColors.brandOrange, modifier = Modifier.size(13.dp))
                Spacer(Modifier.width(4.dp))
                Text(stringResource(R.string.rd_ai_locked), color = AppColors.brandOrange, fontSize = 11.sp, fontWeight = FontWeight.Bold)
            }
        } else Icon(Icons.Rounded.Mic, null, tint = AiGradient[0], modifier = Modifier.size(22.dp))
    }
}

/**
 * The assistant: say or write the trip, see what DORR AI understood, pick the place — then it is shown on
 * the map to confirm or move (nothing is booked here).
 */
@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
internal fun SmartDestinationSheet(state: RidesState, camera: DorrCamera, onClose: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var text by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var answer by remember { mutableStateOf<RideAiUnderstandDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val noVoice = stringResource(R.string.rd_ai_no_voice)
    val failed = stringResource(R.string.rd_error)

    fun ask(words: String) {
        val clean = words.trim()
        if (clean.length < 2 || busy) return
        busy = true
        error = null
        scope.launch {
            val body = JsonObject().apply {
                addProperty("text", clean)
                (state.pickup?.latLng ?: state.me)?.let { addProperty("lat", it.latitude); addProperty("lng", it.longitude) }
            }
            runCatching { ApiClient.rides.aiUnderstand(ridesAuth(), body).data }
                .onSuccess { answer = it }
                .onFailure { error = it.apiFailure().message ?: failed }
            busy = false
        }
    }

    // Voice: the phone's own speech recogniser turns it into text (nothing recorded here), then the same ask.
    val voice = rememberLauncherForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
        if (result.resultCode == Activity.RESULT_OK) {
            result.data?.getStringArrayListExtra(RecognizerIntent.EXTRA_RESULTS)?.firstOrNull()?.let { heard ->
                text = heard
                ask(heard)
            }
        }
    }
    fun listen() {
        val intent = Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH).apply {
            putExtra(RecognizerIntent.EXTRA_LANGUAGE_MODEL, RecognizerIntent.LANGUAGE_MODEL_FREE_FORM)
            putExtra(RecognizerIntent.EXTRA_LANGUAGE, if (Locale.getDefault().language == "ar") "ar-SA" else Locale.getDefault().toLanguageTag())
            putExtra(RecognizerIntent.EXTRA_PROMPT, context.getString(R.string.rd_ai_listening))
        }
        try { voice.launch(intent) } catch (_: ActivityNotFoundException) { error = noVoice }
    }

    fun pick(place: RideAiPlaceDto) {
        val understood = answer?.understood
        // What else was said fills the booking sheet; the rider still sees and changes all of it.
        understood?.passengers?.let { state.passengers = it }
        understood?.payment?.takeIf { state.feature(it) }?.let { state.payment = it }
        understood?.note?.let { state.pickupNote = it }
        understood?.category?.let { state.preferCategory = it }
        understood?.`when`?.takeIf { state.feature("scheduled") }?.let { iso ->
            runCatching { ZonedDateTime.parse(iso) }.getOrNull()?.let { state.scheduledAt = it }
        }
        state.editing = "destination"
        state.set(RidePoint(place.lat, place.lng, place.address.ifBlank { place.name.orEmpty() }, place.name))
        // Confirm (or move) it on the map before anything is priced or booked.
        state.step = RideStep.PickOnMap
        scope.launch { camera.animate(com.google.android.gms.maps.model.LatLng(place.lat, place.lng), 16f) }
        onClose()
    }

    ModalBottomSheet(onDismissRequest = onClose, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = settingsCard()) {
        Column(Modifier.fillMaxWidth().imePadding().padding(horizontal = 18.dp).padding(bottom = 24.dp).heightIn(max = 640.dp).verticalScroll(rememberScrollState())) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(44.dp).clip(RoundedCornerShape(14.dp)).background(Brush.linearGradient(AiGradient)), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.AutoAwesome, null, tint = Color.White, modifier = Modifier.size(22.dp))
                }
                Spacer(Modifier.width(12.dp))
                Column {
                    Text(stringResource(R.string.rd_ai_title), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
                    Text(stringResource(R.string.rd_ai_sheet_sub), color = settingsMut(), fontSize = 12.sp)
                }
            }
            Spacer(Modifier.height(14.dp))
            DorrTextField(
                value = text, onValueChange = { text = it.take(500) }, placeholder = stringResource(R.string.rd_ai_hint),
                singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth(),
            )
            Spacer(Modifier.height(10.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                MicButton(onClick = { listen() })
                Box(Modifier.weight(1f)) {
                    AccentButton(stringResource(R.string.rd_ai_send), enabled = text.trim().length >= 2, busy = busy, icon = Icons.Rounded.Send) { ask(text) }
                }
            }
            error?.let { Text(it, color = AppColors.danger, fontSize = 13.sp, modifier = Modifier.padding(top = 8.dp)) }

            answer?.let { a ->
                AnimatedVisibility(visible = true, enter = fadeIn() + expandVertically()) {
                    Column(Modifier.padding(top = 16.dp)) {
                        a.clarify?.let { q ->
                            Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(AiGradient[0].copy(alpha = 0.08f)).padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                                Icon(Icons.Rounded.AutoAwesome, null, tint = AiGradient[0], modifier = Modifier.size(18.dp))
                                Spacer(Modifier.width(8.dp))
                                Text(q, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.SemiBold)
                            }
                        }
                        val u = a.understood
                        val (today, tomorrow) = rememberWhenLabels()
                        val chips = listOfNotNull(
                            u.categoryName?.let { Icons.Rounded.DirectionsCar to it },
                            u.`when`?.let { iso -> runCatching { ZonedDateTime.parse(iso) }.getOrNull()?.let { Icons.Rounded.Schedule to whenText(it, today, tomorrow) } },
                            u.passengers?.let { Icons.Rounded.Group to stringResource(R.string.rd_ai_chip_passengers, it) },
                            u.payment?.let { Icons.Rounded.Payments to stringResource(when (it) { "wallet" -> R.string.rd_ai_pay_wallet; "card" -> R.string.rd_ai_pay_card; else -> R.string.rd_ai_pay_cash }) },
                            u.note?.let { Icons.Rounded.StickyNote2 to it },
                        )
                        if (chips.isNotEmpty()) {
                            Text(stringResource(R.string.rd_ai_understood), color = settingsMut(), fontSize = 12.sp, modifier = Modifier.padding(top = 10.dp, bottom = 6.dp))
                            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                                chips.forEach { (icon, label) -> AiChip(icon, label) }
                            }
                        }
                        if (a.places.isNotEmpty()) {
                            Text(stringResource(R.string.rd_ai_pick_place), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 14.sp, modifier = Modifier.padding(top = 14.dp, bottom = 4.dp))
                            a.places.forEachIndexed { i, place -> Box(Modifier.staggerIn(i)) { AiPlaceRow(place) { pick(place) } } }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun MicButton(onClick: () -> Unit) {
    val pulse = rememberInfiniteTransition(label = "mic")
    val glow by pulse.animateFloat(0.18f, 0.38f, infiniteRepeatable(tween(1200), RepeatMode.Reverse), label = "glow")
    Box(
        Modifier.size(54.dp).clip(RoundedCornerShape(18.dp)).background(AiGradient[0].copy(alpha = glow)).pressable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Icon(Icons.Rounded.Mic, stringResource(R.string.rd_ai_listening), tint = AiGradient[0], modifier = Modifier.size(26.dp)) }
}

@Composable
private fun AiChip(icon: ImageVector, label: String) {
    Row(Modifier.clip(RoundedCornerShape(50)).background(settingsBackground()).padding(horizontal = 10.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(icon, null, tint = settingsAccent(), modifier = Modifier.size(14.dp))
        Spacer(Modifier.width(5.dp))
        Text(label, color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

@Composable
private fun AiPlaceRow(place: RideAiPlaceDto, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).pressable(onClick = onClick).padding(vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(38.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
            Icon(if (place.source == "saved") Icons.Rounded.Bookmark else Icons.Rounded.LocationOn, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
        }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(place.name ?: place.address, color = settingsInk(), fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            if (place.name != null && place.name != place.address) Text(place.address, color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        Text(stringResource(if (place.source == "saved") R.string.rd_ai_saved else R.string.rd_ai_on_map), color = settingsMut(), fontSize = 11.sp)
    }
}

/** AI-09: the category this rider usually takes for a trip like this — asked once per list of prices. */
@Composable
internal fun SuggestionEffect(state: RidesState) {
    LaunchedEffect(state.quotes.map { it.uuid }) {
        state.suggestion = null
        val quotes = state.quotes
        if (quotes.size < 2 || !state.feature("ai_booking")) return@LaunchedEffect
        val body = JsonObject().apply {
            quotes.firstOrNull()?.route?.distanceM?.let { addProperty("distance_m", it) }
            addProperty("passengers", state.passengers)
            add("categories", JsonArray().apply { quotes.mapNotNull { it.category.slug }.forEach { add(it) } })
        }
        state.suggestion = runCatching { ApiClient.rides.aiSuggest(ridesAuth(), body).data }.getOrNull()?.takeIf { it.allowed && it.category != null }
    }
}

/** "✨ Suggested for you" under a category's name, with the reason under the list. */
@Composable
internal fun SuggestedTag() {
    Row(
        Modifier.padding(top = 3.dp).clip(RoundedCornerShape(50)).background(Brush.horizontalGradient(AiGradient.map { it.copy(alpha = 0.16f) })).padding(horizontal = 8.dp, vertical = 2.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Rounded.AutoAwesome, null, tint = AiGradient[1], modifier = Modifier.size(11.dp))
        Spacer(Modifier.width(3.dp))
        Text(stringResource(R.string.rd_ai_suggested), color = AiGradient[1], fontSize = 10.sp, fontWeight = FontWeight.Bold)
    }
}

/** «راحتي» on the booking sheet: what I like, in one line — tap to change. */
@Composable
internal fun ComfortRow(state: RidesState) {
    if (!state.feature("comfort")) return
    var open by remember { mutableStateOf(false) }
    var screen by remember { mutableStateOf<RideComfortScreenDto?>(null) }
    LaunchedEffect(Unit) { screen = runCatching { ApiClient.rides.comfort(ridesAuth(), state.pickup?.lat, state.pickup?.lng).data }.getOrNull() }
    val s = screen ?: return
    if (!s.enabled) return
    val summary = comfortSummary(s.prefs, s)
    Row(
        Modifier.fillMaxWidth().padding(vertical = 6.dp).clip(RoundedCornerShape(12.dp)).pressable { open = true }.padding(vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(30.dp).clip(CircleShape).background(AppColors.success.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.Spa, null, tint = AppColors.success, modifier = Modifier.size(17.dp))
        }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(stringResource(R.string.rd_comfort_title), color = settingsInk(), fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
            Text(summary.ifBlank { stringResource(R.string.rd_comfort_none) }, color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        Text(stringResource(R.string.rd_change), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
    }
    if (open) ComfortSheet(s, onClose = { open = false }) { screen = it; open = false }
}

private fun comfortSummary(p: RideComfortPrefsDto, s: RideComfortScreenDto): String = listOfNotNull(
    if (p.quiet) "🤫" else null,
    p.ac.takeIf { it != "any" }?.let { v -> "❄️ " + (s.options.ac.firstOrNull { it.value == v }?.label ?: v) },
    p.music.takeIf { it != "any" }?.let { v -> "🎵 " + (s.options.music.firstOrNull { it.value == v }?.label ?: v) },
    p.notes.takeIf { it.isNotEmpty() }?.let { notes -> notes.mapNotNull { n -> s.options.notes.firstOrNull { it.value == n }?.label }.joinToString("، ") },
).joinToString(" · ")

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
private fun ComfortSheet(screen: RideComfortScreenDto, onClose: () -> Unit, onSaved: (RideComfortScreenDto) -> Unit) {
    val scope = rememberCoroutineScope()
    var quiet by remember { mutableStateOf(screen.prefs.quiet) }
    var ac by remember { mutableStateOf(screen.prefs.ac) }
    var music by remember { mutableStateOf(screen.prefs.music) }
    var notes by remember { mutableStateOf(screen.prefs.notes.toSet()) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val failed = stringResource(R.string.rd_error)

    ModalBottomSheet(onDismissRequest = onClose, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = settingsCard()) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 18.dp).padding(bottom = 24.dp).verticalScroll(rememberScrollState())) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(44.dp).clip(RoundedCornerShape(14.dp)).background(AppColors.success.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.Spa, null, tint = AppColors.success, modifier = Modifier.size(24.dp))
                }
                Spacer(Modifier.width(12.dp))
                Column {
                    Text(stringResource(R.string.rd_comfort_title), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
                    Text(stringResource(R.string.rd_comfort_sub), color = settingsMut(), fontSize = 12.sp)
                }
            }
            Spacer(Modifier.height(16.dp))
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.VolumeOff, null, tint = settingsAccent(), modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.rd_comfort_quiet), color = settingsInk(), fontWeight = FontWeight.SemiBold)
                    Text(stringResource(R.string.rd_comfort_quiet_sub), color = settingsMut(), fontSize = 12.sp)
                }
                Switch(checked = quiet, onCheckedChange = { quiet = it }, colors = SwitchDefaults.colors(checkedTrackColor = settingsAccent()))
            }
            ChoiceGroup(Icons.Rounded.AcUnit, stringResource(R.string.rd_comfort_ac), screen.options.ac, ac) { ac = it }
            ChoiceGroup(Icons.Rounded.MusicNote, stringResource(R.string.rd_comfort_music), screen.options.music, music) { music = it }
            Text(stringResource(R.string.rd_comfort_notes), color = settingsInk(), fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 14.dp, bottom = 8.dp))
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                screen.options.notes.forEach { n ->
                    val on = n.value in notes
                    ChoicePill(n.label, on) { notes = if (on) notes - n.value else notes + n.value }
                }
            }
            screen.disclaimer?.let { d ->
                Row(Modifier.padding(top = 14.dp).fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(settingsBackground()).padding(10.dp)) {
                    Icon(Icons.Rounded.Info, null, tint = settingsMut(), modifier = Modifier.size(16.dp))
                    Spacer(Modifier.width(6.dp))
                    Text(d, color = settingsMut(), fontSize = 12.sp)
                }
            }
            error?.let { Text(it, color = AppColors.danger, fontSize = 13.sp, modifier = Modifier.padding(top = 8.dp)) }
            Spacer(Modifier.height(14.dp))
            AccentButton(stringResource(R.string.rd_comfort_save), busy = busy) {
                busy = true
                scope.launch {
                    val body = JsonObject().apply {
                        addProperty("quiet", quiet)
                        addProperty("ac", ac)
                        addProperty("music", music)
                        add("notes", JsonArray().apply { notes.forEach { add(it) } })
                    }
                    runCatching { ApiClient.rides.saveComfort(ridesAuth(), body).data }
                        .onSuccess { it?.let(onSaved) }
                        .onFailure { error = it.apiFailure().message ?: failed }
                    busy = false
                }
            }
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun ChoiceGroup(icon: ImageVector, title: String, choices: List<RideChoiceDto>, value: String, onPick: (String) -> Unit) {
    Row(Modifier.padding(top = 14.dp, bottom = 8.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(icon, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
        Spacer(Modifier.width(8.dp))
        Text(title, color = settingsInk(), fontWeight = FontWeight.SemiBold)
    }
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        choices.forEach { c -> ChoicePill(c.label, c.value == value) { onPick(c.value) } }
    }
}

@Composable
private fun ChoicePill(label: String, selected: Boolean, onClick: () -> Unit) {
    val accent = settingsAccent()
    Text(
        label, color = if (selected) accent else settingsInk(), fontSize = 13.sp, fontWeight = if (selected) FontWeight.Bold else FontWeight.Normal,
        modifier = Modifier.clip(RoundedCornerShape(50))
            .border(BorderStroke(if (selected) 2.dp else 1.dp, if (selected) accent else settingsInk().copy(alpha = 0.12f)), RoundedCornerShape(50))
            .background(if (selected) accent.copy(alpha = 0.08f) else Color.Transparent)
            .pressable(onClick = onClick).padding(horizontal = 14.dp, vertical = 8.dp),
    )
}

/** On the ride: what the rider asked for, and anything the captain said they can't do (with why). */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun RideComfortCard(ride: RideDto) {
    val comfort = ride.comfort ?: return
    Column(Modifier.padding(top = 10.dp).fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(AppColors.success.copy(alpha = 0.07f)).padding(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.Spa, null, tint = AppColors.success, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(6.dp))
            Text(stringResource(R.string.rd_comfort_title), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 14.sp)
        }
        Spacer(Modifier.height(6.dp))
        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            comfort.items.forEach { item ->
                val refused = comfort.unable.any { it.item == item.item }
                Text(
                    item.label, color = if (refused) settingsMut() else settingsInk(), fontSize = 12.sp,
                    modifier = Modifier.clip(RoundedCornerShape(50)).background(settingsCard()).padding(horizontal = 10.dp, vertical = 5.dp),
                    textDecoration = if (refused) androidx.compose.ui.text.style.TextDecoration.LineThrough else null,
                )
            }
        }
        comfort.unable.forEach { u ->
            Text(
                stringResource(R.string.rd_comfort_unable, u.label.orEmpty(), u.reasonLabel.orEmpty()),
                color = AppColors.warning, fontSize = 12.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 6.dp),
            )
        }
    }
}

/** The captain finishing a ride nearby, or a booking that may be late — said early and plainly, never a promise. */
@Composable
internal fun RideAiBanners(ride: RideDto) {
    val minute = stringResource(R.string.rd_minutes)
    val hour = stringResource(R.string.rd_hours)
    ride.driverFinishing?.takeIf { it.finishing }?.let { f ->
        Banner(Icons.Rounded.DirectionsCar, stringResource(R.string.rd_finishing, minutesText(f.etaS, minute, hour)), settingsAccent())
    }
    ride.lateRisk?.let { r ->
        Banner(Icons.Rounded.AccessTime, stringResource(R.string.rd_late_risk, r.minutes, r.reasonLabel.orEmpty()), AppColors.warning)
    }
}

@Composable
private fun Banner(icon: ImageVector, text: String, color: Color) {
    Row(
        Modifier.padding(top = 10.dp).fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(color.copy(alpha = 0.1f)).padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = color, modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(8.dp))
        Text(text, color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
    }
}
