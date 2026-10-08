package com.dorr.app.ui.screens.sports

import android.widget.Toast
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.EmojiEvents
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
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
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ConversationDto
import com.dorr.app.network.MessageDto
import com.dorr.app.network.SpContestDto
import com.dorr.app.network.SpMatchDto
import com.dorr.app.network.SpPlayDto
import com.dorr.app.network.SpPrizeDto
import com.dorr.app.network.SpTeamDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaEmpty
import com.dorr.app.ui.screens.wallet.WaNote
import com.dorr.app.ui.screens.wallet.WaPage
import com.dorr.app.ui.screens.wallet.WaSectionTitle
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.wallet.waRise
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.util.Locale

/*
 * Predictions, contests and prizes (196), the community rating (199), sharing a match and
 * watching together (195). Predicting is free; prizes reach only where they're allowed.
 */

internal val SpGold = Color(0xFFF59E0B)
internal val SpGoldBrush = Brush.linearGradient(listOf(Color(0xFFF59E0B), Color(0xFFB45309), Color(0xFF78350F)))

internal fun money(minor: Long?, currency: String?): String =
    if (minor == null) "" else String.format(Locale.US, "%.2f", minor / 100.0) + (currency?.let { " $it" } ?: "")

@Composable
internal fun prizeLine(c: SpContestDto): String = when (c.prizeType) {
    "badge" -> stringResource(R.string.sp_prize_badge)
    "coupon" -> c.prizePercent?.let { stringResource(R.string.sp_prize_coupon_pct, it) } ?: stringResource(R.string.sp_prize_coupon, money(c.prizeAmountMinor, c.currencyCode))
    else -> stringResource(R.string.sp_prize_wallet, money(c.prizeAmountMinor, c.currencyCode))
}

/** On the match page: predict (until kick-off), what the crowd thinks, the contests on it, the rating after. */
@Composable
internal fun PlayCard(m: SpMatchDto, onContest: (String) -> Unit) {
    var play by remember { mutableStateOf<SpPlayDto?>(null) }
    var home by remember { mutableIntStateOf(1) }
    var away by remember { mutableIntStateOf(0) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val saved = stringResource(R.string.sp_predicted)
    LaunchedEffect(m.id, m.status) {
        play = runCatching { ApiClient.sports.play(spAuth(), m.id).data }.getOrNull()
        play?.mine?.let { p -> p.homeScore?.let { home = it }; p.awayScore?.let { away = it } }
    }
    val p = play ?: return
    if (!p.enabled) return
    val hColor = teamColor(m.home?.color, m.home?.id ?: 0)
    val aColor = teamColor(m.away?.color, m.away?.id ?: 1)

    WaCard(Modifier.fillMaxWidth().padding(top = 12.dp), padding = 14.dp) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("🔮", fontSize = 20.sp)
            Spacer(Modifier.width(8.dp))
            Text(stringResource(R.string.sp_predict_title), color = Wa.Ink, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
            Text(stringResource(R.string.sp_free), color = SpGreen, fontSize = 11.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.clip(CircleShape).background(SpGreen.copy(alpha = 0.12f)).padding(horizontal = 8.dp, vertical = 3.dp))
        }
        if (!p.locked) {
            Row(Modifier.fillMaxWidth().padding(top = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                Stepper(m.home, home, hColor, Modifier.weight(1f)) { home = it }
                Text("–", color = Wa.Soft, fontSize = 26.sp, fontWeight = FontWeight.Black, modifier = Modifier.padding(horizontal = 6.dp))
                Stepper(m.away, away, aColor, Modifier.weight(1f)) { away = it }
            }
            WaButton(stringResource(if (p.mine == null) R.string.sp_predict_save else R.string.sp_predict_update), {
                busy = true
                scope.launch {
                    runCatching { ApiClient.sports.predict(spAuth(), m.id, JsonObject().apply { addProperty("home_score", home); addProperty("away_score", away) }).data }
                        .onSuccess { play = it; Toast.makeText(context, saved, Toast.LENGTH_SHORT).show() }
                        .onFailure { Toast.makeText(context, it.apiFailure().message, Toast.LENGTH_LONG).show() }
                    busy = false
                }
            }, loading = busy, modifier = Modifier.fillMaxWidth().padding(top = 12.dp))
            Text(stringResource(R.string.sp_predict_locks), color = Wa.Soft, fontSize = 11.5.sp, modifier = Modifier.padding(top = 6.dp))
        } else p.mine?.let { mine ->
            Row(Modifier.fillMaxWidth().padding(top = 10.dp).clip(RoundedCornerShape(16.dp)).background(Wa.Field).padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(stringResource(R.string.sp_my_prediction), color = Wa.Mut, fontSize = 13.sp, modifier = Modifier.weight(1f))
                Text(if (mine.homeScore != null) "${mine.homeScore} – ${mine.awayScore}" else stringResource(when (mine.winner) { "home" -> R.string.sp_pick_home; "away" -> R.string.sp_pick_away; else -> R.string.sp_pick_draw }), color = Wa.Ink, fontSize = 16.sp, fontWeight = FontWeight.Black)
                mine.points?.let { pts ->
                    val pop = remember(pts) { Animatable(0.4f) }
                    LaunchedEffect(pts) { pop.animateTo(1f, spring(dampingRatio = 0.4f)) }
                    Text(
                        if (mine.result == "void") stringResource(R.string.sp_void) else "+$pts", color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.Black,
                        modifier = Modifier.padding(start = 10.dp).scale(pop.value).clip(CircleShape).background(if (pts >= 3) SpGold else if (pts > 0) SpGreen else Wa.Soft).padding(horizontal = 10.dp, vertical = 4.dp),
                    )
                }
            }
        }
        p.crowd?.takeIf { it.count > 0 }?.let { c ->
            Text(stringResource(R.string.sp_crowd, c.count), color = Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 12.dp, bottom = 6.dp))
            CrowdBar(c.home, c.draw, c.away, hColor, aColor)
            Row(Modifier.fillMaxWidth().padding(top = 4.dp)) {
                Text("${c.home}%", color = hColor, fontSize = 12.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                Text(stringResource(R.string.sp_pick_draw) + " ${c.draw}%", color = Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center, modifier = Modifier.weight(1f))
                Text("${c.away}%", color = aColor, fontSize = 12.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.End, modifier = Modifier.weight(1f))
            }
            c.topScore?.let { Text(stringResource(R.string.sp_top_score, it), color = Wa.Soft, fontSize = 11.5.sp, modifier = Modifier.padding(top = 4.dp)) }
        }
        p.contests.forEach { c ->
            Row(
                Modifier.fillMaxWidth().padding(top = 10.dp).clip(RoundedCornerShape(16.dp)).background(SpGoldBrush).clickable { onContest(c.id) }.padding(12.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text("🏆", fontSize = 22.sp)
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(c.name.orEmpty(), color = Color.White, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    Text(if (c.prizesHere) prizeLine(c) else stringResource(R.string.sp_prize_not_here), color = Color.White.copy(alpha = 0.9f), fontSize = 12.sp)
                }
            }
        }
        if (m.status == "finished") RatingRow(m, p) { play = it }
    }
}

@Composable
private fun Stepper(team: SpTeamDto?, value: Int, color: Color, modifier: Modifier, onChange: (Int) -> Unit) {
    Column(modifier, horizontalAlignment = Alignment.CenterHorizontally) {
        Crest(team, 34.dp)
        Text(team?.name.orEmpty(), color = Wa.Mut, fontSize = 11.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(vertical = 4.dp))
        Row(verticalAlignment = Alignment.CenterVertically) {
            StepBtn("−", value > 0) { onChange(value - 1) }
            FlipScore(value, 30.sp, color, Modifier.padding(horizontal = 12.dp))
            StepBtn("+", value < 20) { onChange(value + 1) }
        }
    }
}

@Composable
private fun StepBtn(label: String, enabled: Boolean, onClick: () -> Unit) {
    Box(
        Modifier.size(34.dp).clip(CircleShape).background(if (enabled) Wa.Field else Wa.Field.copy(alpha = 0.4f)).clickable(enabled = enabled, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Text(label, color = if (enabled) Wa.Ink else Wa.Soft, fontSize = 18.sp, fontWeight = FontWeight.Black) }
}

@Composable
private fun CrowdBar(home: Int, draw: Int, away: Int, hColor: Color, aColor: Color) {
    val grow = remember { Animatable(0f) }
    LaunchedEffect(home, draw, away) { grow.snapTo(0f); grow.animateTo(1f, tween(800)) }
    Row(Modifier.fillMaxWidth().height(10.dp).clip(CircleShape).background(Wa.Field)) {
        listOf(home to hColor, draw to Color(0xFF94A3B8), away to aColor).forEach { (v, c) ->
            if (v > 0) Box(Modifier.weight(v.toFloat() * grow.value + 0.0001f).fillMaxSize().background(c))
        }
        Box(Modifier.weight((100 - home - draw - away).coerceAtLeast(0).toFloat() + (home + draw + away) * (1 - grow.value) + 0.0001f))
    }
}

/** After the whistle: how fun, how exciting — a community rating, not an official one (199). */
@Composable
private fun RatingRow(m: SpMatchDto, p: SpPlayDto, onSaved: (SpPlayDto) -> Unit) {
    var fun_ by remember(p.rating?.mine) { mutableIntStateOf(p.rating?.mine?.funScore ?: 0) }
    var ex by remember(p.rating?.mine) { mutableIntStateOf(p.rating?.mine?.excitement ?: 0) }
    val scope = rememberCoroutineScope()
    val save: () -> Unit = {
        if (fun_ > 0 && ex > 0) scope.launch {
            runCatching { ApiClient.sports.rate(spAuth(), m.id, JsonObject().apply { addProperty("fun", fun_); addProperty("excitement", ex) }).data }.getOrNull()?.let(onSaved)
        }
    }
    Column(Modifier.fillMaxWidth().padding(top = 14.dp)) {
        Text(stringResource(R.string.sp_rate_title), color = Wa.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.ExtraBold)
        Stars(stringResource(R.string.sp_rate_fun), fun_) { fun_ = it; save() }
        Stars(stringResource(R.string.sp_rate_ex), ex) { ex = it; save() }
        p.rating?.takeIf { it.count > 0 }?.let { r ->
            Text(stringResource(R.string.sp_rate_community, r.funScore ?: 0.0, r.excitement ?: 0.0, r.count), color = Wa.Soft, fontSize = 11.5.sp, modifier = Modifier.padding(top = 4.dp))
        }
    }
}

@Composable
private fun Stars(label: String, value: Int, onPick: (Int) -> Unit) {
    Row(Modifier.padding(top = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(label, color = Wa.Mut, fontSize = 13.sp, modifier = Modifier.width(90.dp))
        (1..5).forEach { i ->
            val on = i <= value
            val c by animateColorAsState(if (on) SpGold else Wa.Line, label = "star")
            Text("★", color = c, fontSize = 26.sp, modifier = Modifier.clip(CircleShape).clickable { onPick(i) }.padding(horizontal = 2.dp))
        }
    }
}

// =============================================================================== contests & prizes

@Composable
internal fun ContestsPage(onBack: () -> Unit, onOpen: (String) -> Unit, onPrizes: () -> Unit) {
    var rows by remember { mutableStateOf<List<SpContestDto>?>(null) }
    LaunchedEffect(Unit) { rows = runCatching { ApiClient.sports.contests(spAuth()).data }.getOrNull().orEmpty() }
    WaPage(title = stringResource(R.string.sp_contests), onBack = onBack, actions = {
        Text("🎁", fontSize = 22.sp, modifier = Modifier.clip(CircleShape).clickable(onClick = onPrizes).padding(8.dp))
    }) {
        WaNote(stringResource(R.string.sp_contests_note), Modifier.waRise(0))
        Spacer(Modifier.height(12.dp))
        when {
            rows == null -> repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(96.dp).padding(bottom = 10.dp), RoundedCornerShape(22.dp)) }
            rows!!.isEmpty() -> WaCard(Modifier.fillMaxWidth()) { WaEmpty(Icons.Rounded.EmojiEvents, Tone.Amber, stringResource(R.string.sp_no_contests), stringResource(R.string.sp_no_contests_text)) }
            else -> rows!!.forEachIndexed { i, c -> ContestCard(c, Modifier.padding(bottom = 10.dp).waRise(i.coerceAtMost(6))) { onOpen(c.id) } }
        }
        Spacer(Modifier.height(24.dp))
    }
}

@Composable
private fun ContestCard(c: SpContestDto, modifier: Modifier = Modifier, onClick: () -> Unit) {
    Column(modifier.fillMaxWidth().clip(RoundedCornerShape(22.dp)).background(SpGoldBrush).clickable(onClick = onClick).padding(16.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("🏆", fontSize = 28.sp)
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(c.name.orEmpty(), color = Color.White, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
                Text(listOfNotNull(c.match, c.competition, c.round).joinToString(" · "), color = Color.White.copy(alpha = 0.85f), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
        }
        Row(Modifier.padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(if (c.prizesHere) prizeLine(c) else stringResource(R.string.sp_prize_not_here), color = Color(0xFF78350F), fontSize = 12.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.clip(CircleShape).background(Color.White).padding(horizontal = 10.dp, vertical = 5.dp))
            Text(stringResource(when (c.rule) { "exact" -> R.string.sp_rule_exact; "winner" -> R.string.sp_rule_winner; else -> R.string.sp_rule_points }), color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(CircleShape).background(Color.White.copy(alpha = 0.18f)).padding(horizontal = 10.dp, vertical = 5.dp))
        }
    }
}

@Composable
internal fun ContestPage(id: String, onBack: () -> Unit, onMatch: (String) -> Unit) {
    var c by remember { mutableStateOf<SpContestDto?>(null) }
    val now = rememberTicker()
    LaunchedEffect(id) { c = runCatching { ApiClient.sports.contest(spAuth(), id, spZone()).data }.getOrNull() }
    WaPage(title = c?.name ?: stringResource(R.string.sp_contests), onBack = onBack) {
        val x = c ?: run { WaSkeleton(Modifier.fillMaxWidth().height(140.dp), RoundedCornerShape(22.dp)); return@WaPage }
        ContestCard(x, Modifier.waRise(0)) {}
        x.myPrize?.let { prize ->
            WaNote(stringResource(R.string.sp_you_won, money(prize.amountMinor, prize.currencyCode).ifBlank { "🏅" }), Modifier.padding(top = 10.dp))
        }
        Row(Modifier.fillMaxWidth().padding(top = 12.dp).clip(RoundedCornerShape(18.dp)).background(Wa.Surface).padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
            Text(stringResource(R.string.sp_my_points), color = Wa.Mut, fontSize = 13.sp, modifier = Modifier.weight(1f))
            Text("${x.myPoints}", color = Wa.Ink, fontSize = 22.sp, fontWeight = FontWeight.Black)
        }
        if (x.leaderboard.isNotEmpty()) {
            WaSectionTitle(stringResource(R.string.sp_leaderboard))
            WaCard(Modifier.fillMaxWidth(), padding = 8.dp) {
                x.leaderboard.forEach { r ->
                    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(if (r.me) Wa.Red.copy(alpha = 0.08f) else Color.Transparent).padding(horizontal = 8.dp, vertical = 7.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text(when (r.rank) { 1 -> "🥇"; 2 -> "🥈"; 3 -> "🥉"; else -> "${r.rank}" }, fontSize = 15.sp, modifier = Modifier.width(32.dp), textAlign = TextAlign.Center)
                        Text(r.name.orEmpty(), color = Wa.Ink, fontSize = 14.sp, fontWeight = if (r.me) FontWeight.ExtraBold else FontWeight.SemiBold, modifier = Modifier.weight(1f), maxLines = 1)
                        Text("${r.points}", color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.Black)
                    }
                }
            }
        }
        if (x.matches.isNotEmpty()) {
            WaSectionTitle(stringResource(R.string.sp_contest_matches))
            x.matches.forEach { m -> MatchDayRow(m, now) { onMatch(m.id) } }
        }
        x.terms?.takeIf { it.isNotBlank() }?.let {
            WaSectionTitle(stringResource(R.string.sp_terms))
            Text(it, color = Wa.Mut, fontSize = 12.5.sp)
        }
        Spacer(Modifier.height(24.dp))
    }
}

@Composable
internal fun PrizesPage(onBack: () -> Unit) {
    var rows by remember { mutableStateOf<List<SpPrizeDto>?>(null) }
    LaunchedEffect(Unit) { rows = runCatching { ApiClient.sports.prizes(spAuth()).data }.getOrNull().orEmpty() }
    WaPage(title = stringResource(R.string.sp_my_prizes), onBack = onBack) {
        when {
            rows == null -> WaSkeleton(Modifier.fillMaxWidth().height(80.dp), RoundedCornerShape(18.dp))
            rows!!.isEmpty() -> WaCard(Modifier.fillMaxWidth()) { WaEmpty(Icons.Rounded.EmojiEvents, Tone.Amber, stringResource(R.string.sp_no_prizes), stringResource(R.string.sp_no_prizes_text)) }
            else -> rows!!.forEach { w ->
                Row(Modifier.fillMaxWidth().padding(bottom = 8.dp).clip(RoundedCornerShape(18.dp)).background(Wa.Surface).padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text(when (w.prizeType) { "coupon" -> "🎟️"; "badge" -> "🏅"; else -> "💰" }, fontSize = 26.sp)
                    Spacer(Modifier.width(12.dp))
                    Column(Modifier.weight(1f)) {
                        Text(w.name.orEmpty(), color = Wa.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.Bold)
                        Text(stringResource(when (w.status) { "paid" -> R.string.sp_prize_paid; "review" -> R.string.sp_prize_review; "rejected" -> R.string.sp_prize_rejected; else -> R.string.sp_prize_pending }), color = if (w.status == "paid") SpGreen else Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                    }
                    Text(money(w.amountMinor, w.currencyCode), color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.Black)
                }
            }
        }
        Spacer(Modifier.height(24.dp))
    }
}

// =============================================================================== share & watch together (195)

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun ShareMatchSheet(m: SpMatchDto, onDismiss: () -> Unit) {
    var chats by remember { mutableStateOf<List<ConversationDto>?>(null) }
    var picked by remember { mutableStateOf<String?>(null) }
    var poll by remember { mutableStateOf(m.status == "scheduled") }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val sent = stringResource(R.string.sp_shared)
    LaunchedEffect(Unit) { chats = runCatching { ApiClient.chat.conversations(spAuth(), perPage = 60).data }.getOrNull().orEmpty().filter { it.canSend } }
    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 24.dp)) {
            Text(stringResource(R.string.sp_share_title), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(10.dp))
            LazyColumn(Modifier.heightIn(max = 320.dp)) {
                items(chats.orEmpty(), key = { it.id }) { c ->
                    val on = picked == c.id
                    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable { picked = c.id }.padding(vertical = 8.dp, horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        com.dorr.app.ui.screens.chat.ChAvatar(c.avatar, c.title, c.peer?.key ?: c.id, size = 40.dp, isGroup = c.isGroup)
                        Spacer(Modifier.width(10.dp))
                        Text(c.title.orEmpty(), color = Wa.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, modifier = Modifier.weight(1f))
                        Icon(if (on) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (on) Wa.Red else Wa.Soft, modifier = Modifier.size(24.dp))
                    }
                }
            }
            if (chats == null) WaSkeleton(Modifier.fillMaxWidth().height(120.dp), RoundedCornerShape(16.dp))
            if (m.status == "scheduled") ToggleLine(stringResource(R.string.sp_share_poll), poll, null) { poll = it }
            WaButton(stringResource(R.string.sp_share_send), {
                val target = picked ?: return@WaButton
                busy = true
                scope.launch {
                    runCatching { ApiClient.sports.share(spAuth(), m.id, JsonObject().apply { addProperty("conversation_id", target); addProperty("poll", poll) }) }
                        .onSuccess { Toast.makeText(context, sent, Toast.LENGTH_SHORT).show(); onDismiss() }
                        .onFailure { Toast.makeText(context, it.apiFailure().message, Toast.LENGTH_LONG).show() }
                    busy = false
                }
            }, enabled = picked != null, loading = busy, icon = Icons.AutoMirrored.Rounded.Send, modifier = Modifier.fillMaxWidth().padding(top = 10.dp))
        }
    }
}

internal suspend fun makeRoom(m: SpMatchDto, members: List<Int>): String? =
    runCatching { ApiClient.sports.room(spAuth(), m.id, JsonObject().apply { add("members", JsonArray().apply { members.forEach { add(it) } }) }).data }
        .getOrNull()?.getAsJsonObject("conversation")?.get("id")?.asString

// =============================================================================== the card in a chat

/** A match shared in a chat: both crests, kick-off or the live score (from Pusher), opens the match. */
@Composable
fun MatchCardBubble(dto: MessageDto, footer: @Composable () -> Unit) {
    val snap = dto.meta?.get("match")?.takeIf { it.isJsonObject }?.asJsonObject ?: return
    fun team(key: String): SpTeamDto? = snap.getAsJsonObject(key)?.let { t ->
        SpTeamDto(id = t.get("id")?.takeIf { !it.isJsonNull }?.asInt ?: 0, name = t.get("name")?.takeIf { !it.isJsonNull }?.asString, logo = t.get("logo")?.takeIf { !it.isJsonNull }?.asString, color = t.get("color")?.takeIf { !it.isJsonNull }?.asString)
    }
    val id = snap.get("id")?.asString ?: return
    val home = team("home")
    val away = team("away")
    val live = SportsLive.latest[id]
    val now = rememberTicker()
    val started = live != null && live.status in setOf("live", "break", "finished")
    val hide = SportsLive.prefs.noSpoilers
    val start = runCatching { java.time.OffsetDateTime.parse(snap.get("starts_at")?.asString).atZoneSameInstant(java.time.ZoneId.systemDefault()) }.getOrNull()
    Column(Modifier.width(262.dp)) {
        Column(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp))
                .background(Brush.linearGradient(listOf(teamColor(home?.color, home?.id ?: 0), Color(0xFF0B1220), teamColor(away?.color, away?.id ?: 1))))
                .clickable { SportsLink.show(id) }.padding(14.dp),
        ) {
            Text(snap.get("competition")?.takeIf { !it.isJsonNull }?.asString.orEmpty(), color = Color.White.copy(alpha = 0.8f), fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1)
            Row(Modifier.fillMaxWidth().padding(top = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally) {
                    Crest(home, 40.dp, Modifier.background(Color.White, CircleShape))
                    Text(home?.name.orEmpty(), color = Color.White, fontSize = 11.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
                Column(Modifier.width(80.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                    if (started && !hide) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            FlipScore(live!!.homeScore, 22.sp, Color.White)
                            Text(" : ", color = Color.White.copy(alpha = 0.7f), fontSize = 18.sp)
                            FlipScore(live.awayScore, 22.sp, Color.White)
                        }
                        StatusChip(live, now, onDark = true)
                    } else {
                        Text(start?.format(java.time.format.DateTimeFormatter.ofPattern("HH:mm")).orEmpty(), color = Color.White, fontSize = 20.sp, fontWeight = FontWeight.Black)
                        Text(start?.format(java.time.format.DateTimeFormatter.ofPattern("d MMM", Locale.getDefault())).orEmpty(), color = Color.White.copy(alpha = 0.75f), fontSize = 11.sp)
                    }
                }
                Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally) {
                    Crest(away, 40.dp, Modifier.background(Color.White, CircleShape))
                    Text(away?.name.orEmpty(), color = Color.White, fontSize = 11.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
            }
            Text(
                stringResource(R.string.sp_view_match), color = Color.White, fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center,
                modifier = Modifier.padding(top = 10.dp).fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(Color.White.copy(alpha = 0.16f)).padding(vertical = 7.dp),
            )
        }
        Box(Modifier.fillMaxWidth().padding(horizontal = 6.dp)) { footer() }
    }
}
