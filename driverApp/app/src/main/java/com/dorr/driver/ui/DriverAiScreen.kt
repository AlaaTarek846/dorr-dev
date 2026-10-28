package com.dorr.driver.ui

import android.content.Intent
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.EventBusy
import androidx.compose.material.icons.rounded.Flag
import androidx.compose.material.icons.rounded.LocalFireDepartment
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Navigation
import androidx.compose.material.icons.rounded.Place
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Send
import androidx.compose.material.icons.rounded.TrendingUp
import androidx.compose.material.icons.rounded.WorkspacePremium
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
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
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.R
import com.dorr.driver.net.AiPlanDto
import com.dorr.driver.net.DemandDto
import com.dorr.driver.net.DriverAiCardDto
import com.dorr.driver.net.DriverAiDto
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.ui.kit.Chip
import com.dorr.driver.ui.kit.DorrTextField
import com.dorr.driver.ui.kit.PrimaryButton
import com.dorr.driver.ui.kit.ScreenScaffold
import com.dorr.driver.ui.kit.SectionLabel
import com.dorr.driver.ui.kit.bounceClick
import com.dorr.driver.ui.kit.staggerIn
import com.dorr.driver.ui.kit.surface
import com.dorr.driver.ui.theme.Pal
import com.google.gson.JsonObject
import kotlinx.coroutines.launch

private val AiGlow = listOf(Color(0xFF6D5BFF), Color(0xFFB54BFF), Color(0xFFFF7A59))

/**
 * DORR Driver AI (Block 06, D-394) and where the work is: the assistant's cards — each with why and how
 * sure — and asking it; hot zones now for everyone; the next hour and booked-ahead demand on a DORR AI
 * plan (the same plans as the DORR app, bought from the captain's wallet with the PIN). Loaded when it opens,
 * and again after subscribing or changing the goal — nothing asks the server on a timer.
 */
@Composable
fun DriverAiScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var ai by remember { mutableStateOf<DriverAiDto?>(null) }
    var demand by remember { mutableStateOf<DemandDto?>(null) }
    var here by remember { mutableStateOf<Pair<Double, Double>?>(null) }
    var reload by remember { mutableIntStateOf(0) }
    var plansOpen by remember { mutableStateOf(false) }
    var goalOpen by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    BackHandler(onBack = onBack)

    LaunchedEffect(reload) {
        here = lastPosition(context)
        when (val r = apiCall { DriverBackend.api.aiAssistant(here?.first, here?.second) }) {
            is ApiResult.Ok -> ai = r.data
            is ApiResult.Failed -> error = r.error.displayMessage
            is ApiResult.Offline -> error = context.getString(R.string.error_offline)
        }
        here?.let { (lat, lng) -> (apiCall { DriverBackend.api.demand(lat, lng) } as? ApiResult.Ok)?.let { demand = it.data } }
    }

    ScreenScaffold(stringResource(R.string.dai_title), onBack) {
        Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp).navigationBarsPadding(), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            AiHero()
            error?.let { Text(it, color = Pal.danger, fontSize = 13.sp) }
            val a = ai
            if (a != null && !a.allowed) LockedCard { plansOpen = true }
            if (a != null && a.allowed) {
                if (a.cards.isEmpty()) Text(stringResource(R.string.dai_no_cards), color = Pal.mut, fontSize = 13.sp)
                a.cards.forEachIndexed { i, card ->
                    Box(Modifier.staggerIn(i)) { AiCard(card, onGoal = { goalOpen = true }) }
                }
                if (a.cards.none { it.type == "goal" }) {
                    Text(
                        stringResource(R.string.dai_set_goal), color = Pal.accent, fontWeight = FontWeight.Bold, fontSize = 14.sp,
                        modifier = Modifier.clip(RoundedCornerShape(10.dp)).bounceClick { goalOpen = true }.padding(6.dp),
                    )
                }
                AskBox(here)
            }
            DemandSection(demand, onUnlock = { plansOpen = true })
        }
    }

    if (plansOpen) PlansSheet(onClose = { plansOpen = false }, onSubscribed = { plansOpen = false; reload++ })
    if (goalOpen) GoalDialog(current = ai?.goalMinor, onClose = { goalOpen = false }) { minor ->
        goalOpen = false
        scope.launch {
            apiCall { DriverBackend.api.savePreferences(JsonObject().apply { addProperty("daily_goal_minor", minor) }) }
            reload++
        }
    }
}

@Composable
private fun AiHero() {
    val spin = rememberInfiniteTransition(label = "dai")
    val angle by spin.animateFloat(0f, 360f, infiniteRepeatable(tween(6000, easing = LinearEasing), RepeatMode.Restart), label = "angle")
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(22.dp)).background(Brush.linearGradient(AiGlow)).padding(18.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(52.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.18f)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.AutoAwesome, null, tint = Color.White, modifier = Modifier.size(28.dp).rotate(angle))
        }
        Spacer(Modifier.width(14.dp))
        Column {
            Text(stringResource(R.string.dai_title), color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 19.sp)
            Text(stringResource(R.string.dai_sub), color = Color.White.copy(alpha = 0.9f), fontSize = 12.sp)
        }
    }
}

@Composable
private fun LockedCard(onPlans: () -> Unit) {
    Column(Modifier.fillMaxWidth().surface().padding(16.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Box(Modifier.size(48.dp).clip(CircleShape).background(Pal.orange.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.Lock, null, tint = Pal.orange)
        }
        Spacer(Modifier.height(8.dp))
        Text(stringResource(R.string.dai_locked_title), color = Pal.ink, fontWeight = FontWeight.Bold, fontSize = 15.sp)
        Text(stringResource(R.string.dai_locked_body), color = Pal.mut, fontSize = 13.sp, modifier = Modifier.padding(top = 4.dp))
        Spacer(Modifier.height(12.dp))
        PrimaryButton(stringResource(R.string.dai_see_plans), color = Pal.orange, icon = Icons.Rounded.WorkspacePremium, onClick = onPlans)
    }
}

@Composable
private fun AiCard(card: DriverAiCardDto, onGoal: () -> Unit) {
    val context = LocalContext.current
    val (icon, tint) = when (card.type) {
        "zone" -> Icons.Rounded.Place to Pal.success
        "conflict" -> Icons.Rounded.EventBusy to Pal.warning
        else -> Icons.Rounded.Flag to Pal.accent
    }
    Column(Modifier.fillMaxWidth().surface().padding(14.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(38.dp).clip(RoundedCornerShape(12.dp)).background(tint.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                Icon(icon, null, tint = tint, modifier = Modifier.size(20.dp))
            }
            Spacer(Modifier.width(10.dp))
            Text(card.title.orEmpty(), color = Pal.ink, fontWeight = FontWeight.Bold, fontSize = 15.sp, modifier = Modifier.weight(1f))
            card.confidence?.let { ConfidencePill(it) }
        }
        card.text?.let { Text(it, color = Pal.ink, fontSize = 14.sp, modifier = Modifier.padding(top = 8.dp)) }
        if (card.type == "goal" && card.progress != null) {
            val p by animateFloatAsState(card.progress.toFloat().coerceIn(0f, 1f), tween(900), label = "goal")
            Box(Modifier.padding(top = 10.dp).fillMaxWidth().height(10.dp).clip(RoundedCornerShape(50)).background(Pal.line)) {
                Box(Modifier.fillMaxWidth(p).height(10.dp).clip(RoundedCornerShape(50)).background(Brush.horizontalGradient(listOf(Pal.success, Pal.accent))))
            }
        }
        card.why?.let { Text("• $it", color = Pal.mut, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp)) }
        Row(Modifier.padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            if (card.lat != null && card.lng != null) Chip(stringResource(R.string.dai_go_there)) { openMaps(context, card.lat, card.lng) }
            if (card.type == "goal") Chip(stringResource(R.string.dai_change_goal), onClick = onGoal)
        }
    }
}

@Composable
private fun ConfidencePill(confidence: Double) {
    val pct = (confidence * 100).toInt()
    val color = when { pct >= 70 -> Pal.success; pct >= 45 -> Pal.warning; else -> Pal.mut }
    Text(
        stringResource(R.string.dai_confidence, pct), color = color, fontSize = 11.sp, fontWeight = FontWeight.Bold,
        modifier = Modifier.clip(RoundedCornerShape(50)).background(color.copy(alpha = 0.12f)).padding(horizontal = 8.dp, vertical = 3.dp),
    )
}

@Composable
private fun AskBox(here: Pair<Double, Double>?) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var question by remember { mutableStateOf("") }
    var answer by remember { mutableStateOf<Pair<String, Double?>?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    Column(Modifier.fillMaxWidth().surface().padding(14.dp)) {
        Text(stringResource(R.string.dai_ask_title), color = Pal.ink, fontWeight = FontWeight.Bold, fontSize = 15.sp)
        Spacer(Modifier.height(8.dp))
        DorrTextField(value = question, onValueChange = { question = it.take(400) }, placeholder = stringResource(R.string.dai_ask_hint), singleLine = false, minLines = 2)
        Spacer(Modifier.height(10.dp))
        PrimaryButton(stringResource(R.string.dai_ask_send), enabled = question.trim().length >= 2, busy = busy, icon = Icons.Rounded.Send) {
            busy = true
            error = null
            scope.launch {
                val body = JsonObject().apply {
                    addProperty("question", question.trim())
                    here?.let { addProperty("lat", it.first); addProperty("lng", it.second) }
                }
                when (val r = apiCall { DriverBackend.api.aiAsk(body) }) {
                    is ApiResult.Ok -> answer = r.data.answer to r.data.confidence
                    is ApiResult.Failed -> error = r.error.displayMessage
                    is ApiResult.Offline -> error = context.getString(R.string.error_offline)
                }
                busy = false
            }
        }
        error?.let { Text(it, color = Pal.danger, fontSize = 13.sp, modifier = Modifier.padding(top = 8.dp)) }
        AnimatedVisibility(visible = answer != null, enter = fadeIn() + expandVertically()) {
            answer?.let { (text, confidence) ->
                Column(Modifier.padding(top = 12.dp).fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(AiGlow[0].copy(alpha = 0.08f)).padding(12.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Rounded.AutoAwesome, null, tint = AiGlow[0], modifier = Modifier.size(16.dp))
                        Spacer(Modifier.width(6.dp))
                        Text(stringResource(R.string.dai_title), color = AiGlow[0], fontWeight = FontWeight.Bold, fontSize = 12.sp, modifier = Modifier.weight(1f))
                        confidence?.let { ConfidencePill(it) }
                    }
                    Text(text, color = Pal.ink, fontSize = 14.sp, modifier = Modifier.padding(top = 6.dp))
                }
            }
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun DemandSection(demand: DemandDto?, onUnlock: () -> Unit) {
    val context = LocalContext.current
    val d = demand ?: return
    SectionLabel(stringResource(R.string.dai_hot_now))
    if (d.hotZones.isEmpty()) Text(stringResource(R.string.dai_hot_none), color = Pal.mut, fontSize = 13.sp)
    d.hotZones.forEachIndexed { i, z ->
        Row(
            Modifier.staggerIn(i).fillMaxWidth().surface().bounceClick { openMaps(context, z.lat, z.lng) }.padding(12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(Modifier.size(38.dp).clip(CircleShape).background((if (z.shortage) Pal.danger else Pal.orange).copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.LocalFireDepartment, null, tint = if (z.shortage) Pal.danger else Pal.orange, modifier = Modifier.size(20.dp))
            }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(stringResource(R.string.dai_km_away, String.format("%.1f", z.distanceM / 1000.0)), color = Pal.ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                    if (z.shortage) {
                        Spacer(Modifier.width(6.dp))
                        Text(stringResource(R.string.dai_shortage), color = Pal.danger, fontSize = 11.sp, fontWeight = FontWeight.Bold,
                            modifier = Modifier.clip(RoundedCornerShape(50)).background(Pal.danger.copy(alpha = 0.12f)).padding(horizontal = 7.dp, vertical = 2.dp))
                    }
                }
                z.why?.let { Text(it, color = Pal.mut, fontSize = 12.sp) }
            }
            Icon(Icons.Rounded.Navigation, null, tint = Pal.accent, modifier = Modifier.size(20.dp))
        }
    }

    SectionLabel(stringResource(R.string.dai_forecast))
    val forecast = d.forecast
    if (forecast == null) {
        Row(Modifier.fillMaxWidth().surface().bounceClick(onClick = onUnlock).padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.Lock, null, tint = Pal.orange, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(8.dp))
            Text(stringResource(R.string.dai_forecast_locked), color = Pal.ink, fontSize = 13.sp, modifier = Modifier.weight(1f))
            Text(stringResource(R.string.dai_see_plans), color = Pal.orange, fontWeight = FontWeight.Bold, fontSize = 13.sp)
        }
        return
    }
    var slot by remember { mutableIntStateOf(0) }
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        forecast.forEachIndexed { i, s -> Chip(stringResource(R.string.dai_in_min, s.inMin), selected = slot == i) { slot = i } }
    }
    val zones = forecast.getOrNull(slot)?.zones.orEmpty()
    if (zones.isEmpty()) Text(stringResource(R.string.dai_forecast_none), color = Pal.mut, fontSize = 13.sp)
    zones.forEach { z ->
        Row(Modifier.fillMaxWidth().surface().bounceClick { openMaps(context, z.lat, z.lng) }.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.TrendingUp, null, tint = Pal.success, modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.dai_expected, String.format("%.1f", z.expected), String.format("%.1f", z.distanceM / 1000.0)), color = Pal.ink, fontWeight = FontWeight.SemiBold, fontSize = 13.sp)
            }
            ConfidencePill(z.confidence)
        }
    }
    d.scheduled?.takeIf { it.isNotEmpty() }?.let { hours ->
        SectionLabel(stringResource(R.string.dai_scheduled))
        Column(Modifier.fillMaxWidth().surface().padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            hours.forEach { h ->
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Rounded.Schedule, null, tint = Pal.accent, modifier = Modifier.size(16.dp))
                    Spacer(Modifier.width(8.dp))
                    Text(instantOf(h.hour)?.let { timeOfDay(it) } ?: h.hour, color = Pal.ink, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.width(70.dp))
                    Text(stringResource(R.string.dai_scheduled_row, h.booked, String.format("%.1f", h.usual)), color = Pal.mut, fontSize = 12.sp)
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun PlansSheet(onClose: () -> Unit, onSubscribed: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var plans by remember { mutableStateOf<List<AiPlanDto>?>(null) }
    var picked by remember { mutableStateOf<AiPlanDto?>(null) }
    var pin by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(Unit) {
        when (val r = apiCall { DriverBackend.api.aiPlans() }) {
            is ApiResult.Ok -> plans = r.data.filter { !it.isTrial }
            is ApiResult.Failed -> error = r.error.displayMessage
            is ApiResult.Offline -> error = context.getString(R.string.error_offline)
        }
    }
    ModalBottomSheet(onDismissRequest = onClose, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = Pal.card) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 18.dp).padding(bottom = 24.dp).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(stringResource(R.string.dai_plans_title), color = Pal.ink, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
            Text(stringResource(R.string.dai_plans_sub), color = Pal.mut, fontSize = 12.sp)
            plans?.forEach { plan ->
                val on = picked?.id == plan.id
                Column(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp))
                        .background(if (on) Pal.accent.copy(alpha = 0.1f) else Pal.bg)
                        .bounceClick { picked = plan }.padding(14.dp),
                ) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(plan.name, color = Pal.ink, fontWeight = FontWeight.Bold, fontSize = 15.sp, modifier = Modifier.weight(1f))
                        plan.badge?.let { Chip(it, selected = true, tint = Pal.orange) }
                    }
                    Text(
                        listOfNotNull(plan.price?.let { p -> "${String.format("%.2f", p)} ${plan.currency.orEmpty()}" }, plan.durationDays?.let { context.getString(R.string.dai_days, it) }).joinToString(" · "),
                        color = Pal.accent, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp, modifier = Modifier.padding(top = 4.dp),
                    )
                    plan.description?.let { Text(it, color = Pal.mut, fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp)) }
                }
            }
            picked?.let { plan ->
                DorrTextField(
                    value = pin, onValueChange = { v -> pin = v.filter { it.isDigit() }.take(6) }, placeholder = stringResource(R.string.dai_pin),
                    password = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
                )
                PrimaryButton(stringResource(R.string.dai_subscribe, plan.name), enabled = pin.length >= 4, busy = busy) {
                    busy = true
                    error = null
                    scope.launch {
                        when (val r = apiCall { DriverBackend.api.aiSubscribe(pin, JsonObject().apply { addProperty("plan_id", plan.id) }) }) {
                            is ApiResult.Ok -> onSubscribed()
                            is ApiResult.Failed -> error = r.error.displayMessage
                            is ApiResult.Offline -> error = context.getString(R.string.error_offline)
                        }
                        busy = false
                    }
                }
            }
            error?.let { Text(it, color = Pal.danger, fontSize = 13.sp) }
        }
    }
}

@Composable
private fun GoalDialog(current: Long?, onClose: () -> Unit, onSave: (Long?) -> Unit) {
    var text by remember { mutableStateOf(current?.let { (it / 100).toString() }.orEmpty()) }
    AlertDialog(
        onDismissRequest = onClose,
        icon = { Icon(Icons.Rounded.Flag, null, tint = Pal.accent) },
        title = { Text(stringResource(R.string.dai_goal_title)) },
        text = {
            Column {
                Text(stringResource(R.string.dai_goal_hint), color = Pal.mut, fontSize = 13.sp)
                Spacer(Modifier.height(10.dp))
                DorrTextField(value = text, onValueChange = { v -> text = v.filter { it.isDigit() }.take(7) }, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number))
            }
        },
        confirmButton = { TextButton(onClick = { onSave(text.toLongOrNull()?.takeIf { it > 0 }?.times(100)) }) { Text(stringResource(R.string.dai_save)) } },
        dismissButton = { TextButton(onClick = onClose) { Text(stringResource(R.string.dai_cancel)) } },
        containerColor = Pal.card,
    )
}

private fun openMaps(context: android.content.Context, lat: Double, lng: Double) {
    runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("https://www.google.com/maps/dir/?api=1&destination=$lat,$lng&travelmode=driving")).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
}
