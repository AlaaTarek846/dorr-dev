package com.dorr.app.ui.screens.freelance

import android.app.Activity
import android.content.ActivityNotFoundException
import android.content.Intent
import android.speech.RecognizerIntent
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.Bolt
import androidx.compose.material.icons.rounded.ChangeCircle
import androidx.compose.material.icons.rounded.Flag
import androidx.compose.material.icons.rounded.Gavel
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.TaskAlt
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.PersonAdd
import androidx.compose.material.icons.rounded.QuestionAnswer
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Savings
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material.icons.rounded.WorkspacePremium
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
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
import androidx.compose.ui.draw.scale
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
import com.dorr.app.network.ApiClient
import com.dorr.app.network.FreelanceAiAnswer
import com.dorr.app.network.FreelanceAiBuildDto
import com.dorr.app.network.FreelanceAiBuildRequest
import com.dorr.app.network.FreelanceAiStatusDto
import com.dorr.app.network.FreelanceInviteRequest
import com.dorr.app.network.FreelanceMatchDto
import com.dorr.app.network.FreelanceMatchesDto
import com.dorr.app.network.FreelancePmSummaryDto
import com.dorr.app.network.FreelanceTeamMatchDto
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.aichat.AiPlanGate
import com.dorr.app.ui.screens.profile.SettingsPrimaryButton
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsNight
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch
import java.util.Locale

/*
 * FS-401 / FS-501 (light): DORR AI for the freelance client, on the client's DORR AI plan.
 *  - «خلّي دُرر AI يكتب مشروعك»: the idea in the client's own words (or voice) becomes a professional project — the form is filled in, the
 *    client checks and saves. Nothing is saved or published by AI.
 *  - «أنسب ناس لمشروعك»: the best freelancers and teams for a project, each with a match score, the reasons and DORR AI's words; a
 *    private project's invitations go out from the list.
 */

private val AiViolet = Color(0xFF7C3AED)
private val AiPink = Color(0xFFDB2777)

/** Which of the two tools are on in the client's country, and whether the plan allows them now — read once where they show. */
@Composable
internal fun rememberFlAiStatus(): FreelanceAiStatusDto? {
    var status by remember { mutableStateOf<FreelanceAiStatusDto?>(null) }
    LaunchedEffect(Unit) { status = runCatching { ApiClient.freelance.aiStatus(auth()).data }.getOrNull() }
    return status
}

/** The glowing card that opens one of the tools — locked (it offers the DORR AI plans) when the plan does not allow AI now. */
@Composable
internal fun FlAiEntry(title: String, body: String, action: String, status: FreelanceAiStatusDto, onOpen: () -> Unit) {
    val lockedText = stringResource(R.string.fla_locked)
    val planText = stringResource(R.string.ai_plan_required_title)
    val glow = rememberInfiniteTransition(label = "fl-ai")
    val pulse by glow.animateFloat(0.92f, 1.08f, infiniteRepeatable(tween(1100, easing = FastOutSlowInEasing), RepeatMode.Reverse), label = "pulse")
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp))
            .background(Brush.linearGradient(listOf(AiViolet.copy(alpha = 0.14f), AiPink.copy(alpha = 0.10f))))
            .clickable { if (status.allowed) onOpen() else AiPlanGate.offer(planText) }
            .padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Box(Modifier.size(38.dp).clip(RoundedCornerShape(12.dp)).background(Brush.linearGradient(listOf(AiViolet, AiPink))), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.AutoAwesome, null, tint = Color.White, modifier = Modifier.size(20.dp).scale(pulse))
            }
            Column(Modifier.weight(1f)) {
                Text(title, color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 15.sp)
                Text(body, color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp)
            }
        }
        Row(
            Modifier.clip(RoundedCornerShape(50)).background(if (status.allowed) AiViolet else settingsMut().copy(alpha = 0.18f)).padding(horizontal = 14.dp, vertical = 7.dp),
            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            Icon(if (status.allowed) Icons.Rounded.AutoAwesome else Icons.Rounded.Lock, null, tint = if (status.allowed) Color.White else settingsInk(), modifier = Modifier.size(15.dp))
            Text(if (status.allowed) action else lockedText, color = if (status.allowed) Color.White else settingsInk(), fontWeight = FontWeight.Bold, fontSize = 12.5.sp)
        }
    }
}

// ================================================================================================== AI Project Builder

/**
 * «اوصف فكرتك»: the idea typed or spoken, DORR AI writes the project, asks what is still unclear (answers make it sharper), shows the
 * brief, and [onUse] fills the project form with it.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun ProjectBuilderSheet(onDismiss: () -> Unit, onUse: (FreelanceAiBuildDto) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.fl_error_generic)
    val noVoice = stringResource(R.string.fla_no_voice)
    var idea by rememberSaveable { mutableStateOf("") }
    var result by remember { mutableStateOf<FreelanceAiBuildDto?>(null) }
    val answers = remember { mutableStateListOf<String>() }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    fun write() {
        if (idea.trim().length < 10 || busy) return
        busy = true
        error = null
        val asked = result?.questions.orEmpty()
        val body = FreelanceAiBuildRequest(idea.trim(), asked.mapIndexedNotNull { i, q -> answers.getOrNull(i)?.trim()?.takeIf { it.isNotEmpty() }?.let { FreelanceAiAnswer(q, it) } })
        scope.launch {
            try {
                ApiClient.freelance.aiBuild(auth(), body).data?.let { built ->
                    result = built
                    answers.clear()
                    repeat(built.questions.size) { answers.add("") }
                }
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                error = e.message(generic)
            }
            busy = false
        }
    }

    // Voice: the phone's own speech recogniser turns it into text (nothing recorded here).
    val voice = rememberLauncherForActivityResult(ActivityResultContracts.StartActivityForResult()) { r ->
        if (r.resultCode == Activity.RESULT_OK) r.data?.getStringArrayListExtra(RecognizerIntent.EXTRA_RESULTS)?.firstOrNull()?.let { heard ->
            idea = (idea.trim() + " " + heard).trim().take(2000)
        }
    }
    fun listen() {
        val intent = Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH).apply {
            putExtra(RecognizerIntent.EXTRA_LANGUAGE_MODEL, RecognizerIntent.LANGUAGE_MODEL_FREE_FORM)
            putExtra(RecognizerIntent.EXTRA_LANGUAGE, if (Locale.getDefault().language == "ar") "ar-EG" else Locale.getDefault().toLanguageTag())
            putExtra(RecognizerIntent.EXTRA_PROMPT, context.getString(R.string.fla_listen))
        }
        try { voice.launch(intent) } catch (_: ActivityNotFoundException) { error = noVoice }
    }

    FlSheet(
        icon = Icons.Rounded.AutoAwesome, title = stringResource(R.string.fla_builder_title), body = stringResource(R.string.fla_builder_sheet_sub), onDismiss = onDismiss,
        footer = {
            val built = result
            if (built != null && !busy) SettingsPrimaryButton(text = stringResource(R.string.fla_use), onClick = { onUse(built) })
            FlAction(
                stringResource(if (built?.questions?.isNotEmpty() == true) R.string.fla_rewrite else R.string.fla_write), Icons.Rounded.AutoAwesome,
                if (built == null) FlTone.Primary else FlTone.Soft, enabled = idea.trim().length >= 10, loading = busy, modifier = Modifier.fillMaxWidth(),
            ) { write() }
        },
    ) {
        Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            DorrTextField(idea, { idea = it.take(2000) }, placeholder = stringResource(R.string.fla_idea_hint), singleLine = false, minLines = 4, modifier = Modifier.weight(1f))
            Box(
                Modifier.size(48.dp).clip(RoundedCornerShape(16.dp)).background(AiViolet.copy(alpha = 0.14f)).clickable { listen() },
                contentAlignment = Alignment.Center,
            ) { Icon(Icons.Rounded.Mic, stringResource(R.string.fla_listen), tint = AiViolet) }
        }
        if (busy) Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            CircularProgressIndicator(Modifier.size(18.dp), color = AiViolet, strokeWidth = 2.dp)
            Text(stringResource(R.string.fla_writing), color = settingsMut(), fontSize = 13.sp)
        }
        error?.let { FlNotice(it, Icons.Rounded.ErrorOutline, Color(0xFFDC2626)) }

        result?.let { built ->
            // What is still unclear: answering is optional, and "rewrite" sends the answers with the idea.
            if (built.questions.isNotEmpty()) {
                FlHeading(Icons.Rounded.QuestionAnswer, stringResource(R.string.fla_questions_title))
                built.questions.forEachIndexed { i, q ->
                    Text(q, color = settingsInk(), fontWeight = FontWeight.SemiBold, fontSize = 13.sp)
                    DorrTextField(answers.getOrElse(i) { "" }, { v -> if (i < answers.size) answers[i] = v.take(500) }, placeholder = stringResource(R.string.fla_answer_hint), modifier = Modifier.fillMaxWidth())
                }
            }
            BriefPreview(built)
        }
    }
}

/** The project DORR AI wrote, as the client will see it before using it. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun BriefPreview(built: FreelanceAiBuildDto) {
    val d = built.draft
    val b = built.brief
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(settingsAccent().copy(alpha = 0.06f)).padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            Icon(Icons.Rounded.CheckCircle, null, tint = Color(0xFF16A34A), modifier = Modifier.size(18.dp))
            Text(stringResource(R.string.fla_preview_title), color = Color(0xFF16A34A), fontWeight = FontWeight.Bold, fontSize = 12.5.sp)
        }
        d.title?.let { Text(it, color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 17.sp) }
        b.summary?.let { Text(it, color = settingsInk(), fontSize = 13.5.sp, lineHeight = 20.sp) }
        val tags = listOfNotNull(d.specialty?.name) + d.skills.map { it.name }
        if (tags.isNotEmpty()) FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            tags.forEachIndexed { i, t -> Pill(t, if (i == 0 && d.specialty != null) AiViolet else settingsAccent()) }
        }
        b.goal?.let { Line(stringResource(R.string.fla_goal), it) }
        b.audience?.let { Line(stringResource(R.string.fla_audience), it) }
        Bullets(stringResource(R.string.fla_features), b.features)
        Bullets(stringResource(R.string.fla_deliverables), b.deliverables)
        Bullets(stringResource(R.string.fla_acceptance), b.acceptance)
        Bullets(stringResource(R.string.fla_out_of_scope), b.outOfScope)
        Bullets(stringResource(R.string.fla_assumptions), b.assumptions)
        val money = d.budget.minMinor?.let { low -> (if (d.budget.type == "range" && d.budget.maxMinor != null) "${money(low)} – ${money(d.budget.maxMinor)}" else money(low)) + " " + d.budget.currency }
        val days = d.durationDays?.let { stringResource(R.string.fla_days, it) }
        if (money != null || days != null) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Icon(Icons.Rounded.Savings, null, tint = settingsAccent(), modifier = Modifier.size(17.dp))
                Text(stringResource(R.string.fla_estimate, money ?: "—", days ?: "—"), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 13.sp)
            }
            Text(stringResource(R.string.fla_estimate_note), color = settingsMut(), fontSize = 11.5.sp)
        }
        Bullets(stringResource(R.string.fla_for_freelancers), d.questions)
    }
}

@Composable
private fun Line(label: String, text: String) {
    Column {
        Text(label, color = settingsMut(), fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
        Text(text, color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp)
    }
}

@Composable
private fun Bullets(label: String, lines: List<String>) {
    if (lines.isEmpty()) return
    Column(verticalArrangement = Arrangement.spacedBy(3.dp)) {
        Text(label, color = settingsMut(), fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
        lines.forEach { Text("•  $it", color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp) }
    }
}

@Composable
private fun Pill(text: String, color: Color, icon: ImageVector? = null) {
    Row(
        Modifier.clip(RoundedCornerShape(50)).background(color.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        icon?.let { Icon(it, null, tint = color, modifier = Modifier.size(13.dp)) }
        Text(text, color = color, fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

// ================================================================================================== Smart Matching

/** «أنسب ناس لمشروعك»: the lists, each candidate's score and reasons, DORR AI's words; invitations from here on a private project. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun MatchesPage(projectUuid: String, onBack: () -> Unit, onTeam: (String) -> Unit) {
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.fl_error_generic)
    val sentText = stringResource(R.string.fla_invite_done)
    var data by remember { mutableStateOf<FreelanceMatchesDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var tab by rememberSaveable { mutableStateOf(0) }
    var inviting by remember { mutableStateOf<String?>(null) }
    var reload by remember { mutableStateOf(0) }

    LaunchedEffect(reload) {
        error = null
        try {
            data = ApiClient.freelance.aiMatches(auth(), projectUuid, if (reload > 0) 1 else null).data
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message(generic)
        }
    }

    fun invite(freelancer: String? = null, team: String? = null) {
        val key = freelancer ?: team ?: return
        inviting = key
        scope.launch {
            try {
                ApiClient.freelance.invite(auth(), projectUuid, FreelanceInviteRequest(listOfNotNull(freelancer), listOfNotNull(team)))
                data = data?.let { d -> d.copy(candidates = d.candidates.map { if (it.uuid == freelancer) it.copy(invited = true) else it }) }
                notice = sentText
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                error = e.message(generic)
            }
            inviting = null
        }
    }

    Column(Modifier.fillMaxSize()) {
        FlPageHeader(stringResource(R.string.fla_matches_title), onBack, trailing = {
            if (data != null) Icon(Icons.Rounded.Refresh, stringResource(R.string.fla_refresh), tint = settingsInk(), modifier = Modifier.size(40.dp).clip(CircleShape).clickable { data = null; reload++ }.padding(9.dp))
        })
        val d = data
        if (d == null) {
            if (error != null) Column(Modifier.padding(16.dp)) { FlNotice(error!!, Icons.Rounded.ErrorOutline, Color(0xFFDC2626)) } else MatchesLoading()
            return@Column
        }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            if (d.needs == "specialty") {
                FlNotice(stringResource(R.string.fla_needs_specialty), Icons.Rounded.Info, Color(0xFFD97706))
                return@Column
            }
            // DORR AI's recommendation, on top.
            d.summary?.let { s ->
                Row(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Brush.linearGradient(listOf(AiViolet, AiPink))).padding(14.dp),
                    horizontalArrangement = Arrangement.spacedBy(10.dp),
                ) {
                    Icon(Icons.Rounded.AutoAwesome, null, tint = Color.White, modifier = Modifier.size(22.dp))
                    Column {
                        Text(stringResource(R.string.fla_ai_says), color = Color.White.copy(alpha = 0.85f), fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
                        Text(s, color = Color.White, fontSize = 13.5.sp, lineHeight = 20.sp, fontWeight = FontWeight.SemiBold)
                    }
                }
            }
            notice?.let { FlNotice(it, Icons.Rounded.CheckCircle, Color(0xFF16A34A)) }
            error?.let { FlNotice(it, Icons.Rounded.ErrorOutline, Color(0xFFDC2626)) }
            if (!d.canInvite) FlHint(stringResource(R.string.fla_invite_public))

            val keys = listOf("best", "quality", "value", "fastest") + if (d.teams.isNotEmpty()) listOf("teams") else emptyList()
            val icons = mapOf("best" to Icons.Rounded.AutoAwesome, "quality" to Icons.Rounded.Star, "value" to Icons.Rounded.Savings, "fastest" to Icons.Rounded.Bolt, "teams" to Icons.Rounded.Groups)
            val labels = mapOf(
                "best" to stringResource(R.string.fla_tab_best), "quality" to stringResource(R.string.fla_tab_quality), "value" to stringResource(R.string.fla_tab_value),
                "fastest" to stringResource(R.string.fla_tab_fastest), "teams" to stringResource(R.string.fla_tab_teams),
            )
            val current = keys.getOrElse(tab) { "best" }
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                keys.forEachIndexed { i, k -> Chip(labels.getValue(k), current == k, icons.getValue(k)) { tab = i } }
            }

            if (current == "teams") {
                d.teams.forEach { t -> TeamMatchCard(t, d.canInvite, inviting == t.uuid, onOpen = { onTeam(t.uuid) }, onInvite = { invite(team = t.uuid) }) }
            } else {
                val byId = d.candidates.associateBy { it.uuid }
                val shown = d.lists[current].orEmpty().mapNotNull { byId[it] }
                if (shown.isEmpty()) Text(stringResource(R.string.fla_empty), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.padding(vertical = 18.dp))
                shown.forEachIndexed { i, c -> MatchCard(c, i + 1, d.canInvite, inviting == c.uuid) { invite(freelancer = c.uuid) } }
            }
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Icon(Icons.Rounded.Info, null, tint = settingsMut(), modifier = Modifier.size(14.dp))
                Text(stringResource(R.string.fla_fair), color = settingsMut(), fontSize = 11.5.sp)
            }
            Spacer(Modifier.height(16.dp))
        }
    }
}

@Composable
private fun MatchesLoading() {
    Column(Modifier.fillMaxWidth().padding(top = 40.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(12.dp)) {
        val glow = rememberInfiniteTransition(label = "fl-match")
        val pulse by glow.animateFloat(0.85f, 1.15f, infiniteRepeatable(tween(900, easing = FastOutSlowInEasing), RepeatMode.Reverse), label = "pulse")
        Box(Modifier.size(64.dp).scale(pulse).clip(CircleShape).background(Brush.linearGradient(listOf(AiViolet, AiPink))), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.AutoAwesome, null, tint = Color.White, modifier = Modifier.size(30.dp))
        }
        Text(stringResource(R.string.fla_matches_entry), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.padding(horizontal = 32.dp))
    }
    FlSkeletonList(3, 150.dp)
}

/** A freelancer: rank, picture, name, the match score ring, why DORR AI picked them, the reasons, and the invitation. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun MatchCard(c: FreelanceMatchDto, rank: Int, canInvite: Boolean, busy: Boolean, onInvite: () -> Unit) {
    val surface = if (settingsNight()) Color(0xFF1F2937) else Color.White
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(surface).padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Box {
                FlAvatar(c.avatarUrl, c.name, 48)
                Box(Modifier.align(Alignment.BottomEnd).size(20.dp).clip(CircleShape).background(if (rank == 1) Color(0xFFF59E0B) else settingsAccent()), contentAlignment = Alignment.Center) {
                    Text("$rank", color = Color.White, fontSize = 10.sp, fontWeight = FontWeight.ExtraBold)
                }
            }
            Column(Modifier.weight(1f)) {
                Text(c.name, color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 15.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                c.headline?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis) }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                    c.rating?.let { Text("★ $it", color = Color(0xFFF59E0B), fontSize = 12.sp, fontWeight = FontWeight.Bold) }
                    if (c.availableNow) Text("● " + stringResource(R.string.fla_tab_fastest), color = Color(0xFF16A34A), fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold)
                }
            }
            ScoreRing(c.score)
        }
        c.why?.let { why ->
            Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(AiViolet.copy(alpha = 0.08f)).padding(10.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Icon(Icons.Rounded.AutoAwesome, null, tint = AiViolet, modifier = Modifier.size(15.dp))
                Text(why, color = settingsInk(), fontSize = 12.5.sp, lineHeight = 18.sp)
            }
        }
        if (c.reasons.isNotEmpty()) FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            c.reasons.forEach { Pill(it, settingsAccent(), Icons.Rounded.CheckCircle) }
        }
        when {
            c.offered -> Pill(stringResource(R.string.fla_offered), Color(0xFF16A34A), Icons.Rounded.WorkspacePremium)
            c.invited -> Pill(stringResource(R.string.fla_invited), Color(0xFF16A34A), Icons.Rounded.CheckCircle)
            canInvite -> FlAction(stringResource(R.string.fla_invite), Icons.Rounded.PersonAdd, FlTone.Primary, loading = busy, modifier = Modifier.fillMaxWidth(), onClick = onInvite)
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun TeamMatchCard(t: FreelanceTeamMatchDto, canInvite: Boolean, busy: Boolean, onOpen: () -> Unit, onInvite: () -> Unit) {
    val surface = if (settingsNight()) Color(0xFF1F2937) else Color.White
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(surface).clickable(onClick = onOpen).padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            FlAvatar(t.logoUrl, t.name, 48)
            Column(Modifier.weight(1f)) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(t.name, color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 15.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false))
                    if (t.verified) Icon(Icons.Rounded.Verified, null, tint = Color(0xFF2563EB), modifier = Modifier.size(16.dp))
                }
                Text(stringResource(R.string.fla_team_members, t.membersCount), color = settingsMut(), fontSize = 12.sp)
            }
            ScoreRing(t.score)
        }
        t.why?.let { Text(it, color = settingsInk(), fontSize = 12.5.sp, lineHeight = 18.sp) }
        if (t.reasons.isNotEmpty()) FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            t.reasons.forEach { Pill(it, settingsAccent(), Icons.Rounded.CheckCircle) }
        }
        if (canInvite) FlAction(stringResource(R.string.fla_invite), Icons.Rounded.PersonAdd, FlTone.Primary, loading = busy, modifier = Modifier.fillMaxWidth(), onClick = onInvite)
    }
}

/** The match score as a ring: green from 75, amber from 50. */
@Composable
private fun ScoreRing(score: Int) {
    val color = when {
        score >= 75 -> Color(0xFF16A34A)
        score >= 50 -> Color(0xFFD97706)
        else -> settingsMut()
    }
    Box(Modifier.size(52.dp), contentAlignment = Alignment.Center) {
        androidx.compose.material3.CircularProgressIndicator(progress = { score / 100f }, modifier = Modifier.matchParentSize(), color = color, strokeWidth = 4.dp, trackColor = color.copy(alpha = 0.15f))
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Text("$score", color = color, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp)
            Text("%", color = color, fontSize = 9.sp)
        }
    }
    Spacer(Modifier.width(2.dp))
}

// ================================================================================================== AI Project Manager (FS-801)

/** The card on a running agreement that opens DORR AI's summary — shown only when the tool is on in the client's country. */
@Composable
internal fun FlPmEntry(onOpen: () -> Unit) {
    val status = rememberFlAiStatus() ?: return
    if (!status.manager) return
    FlAiEntry(stringResource(R.string.fla_pm_title), stringResource(R.string.fla_pm_entry), stringResource(R.string.fla_pm_open), status, onOpen)
}

/**
 * «ملخص مشروعك»: what happened on the running project today, this week or this month — DORR AI's headline and lists (done, decisions,
 * changes, what may slip, the next step of each side), the numbers DORR counted, and the project's health. Read when it opens and when
 * the period changes (the server keeps the answer while nothing new happens); the refresh button asks DORR AI again.
 */
@Composable
internal fun ProjectSummaryPage(projectUuid: String, onBack: () -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    var days by rememberSaveable { mutableStateOf(7) }
    var data by remember { mutableStateOf<FreelancePmSummaryDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var refresh by remember { mutableStateOf(0) }

    LaunchedEffect(days, refresh) {
        data = null
        error = null
        try {
            data = ApiClient.freelance.aiSummary(auth(), projectUuid, days, if (refresh > 0) 1 else null).data
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message(generic)
        }
    }

    Column(Modifier.fillMaxSize()) {
        FlPageHeader(stringResource(R.string.fla_pm_title), onBack, trailing = {
            if (data?.quiet == false) {
                Icon(
                    Icons.Rounded.Refresh, stringResource(R.string.fla_refresh), tint = settingsInk(),
                    modifier = Modifier.size(40.dp).clip(CircleShape).clickable { refresh++ }.padding(9.dp),
                )
            }
        })
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf(1 to R.string.fla_pm_day, 7 to R.string.fla_pm_week, 30 to R.string.fla_pm_month).forEach { (period, label) ->
                    Chip(stringResource(label), days == period) { days = period }
                }
            }
            val d = data
            val problem = error
            when {
                problem != null -> FlNotice(problem, Icons.Rounded.ErrorOutline, Color(0xFFDC2626))
                d == null -> PmLoading()
                else -> PmBody(d)
            }
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Icon(Icons.Rounded.Info, null, tint = settingsMut(), modifier = Modifier.size(14.dp))
                Text(stringResource(R.string.fla_pm_note), color = settingsMut(), fontSize = 11.5.sp)
            }
            Spacer(Modifier.height(16.dp))
        }
    }
}

@Composable
private fun PmLoading() {
    Column(Modifier.fillMaxWidth().padding(top = 24.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(12.dp)) {
        val glow = rememberInfiniteTransition(label = "fl-pm")
        val pulse by glow.animateFloat(0.85f, 1.15f, infiniteRepeatable(tween(900, easing = FastOutSlowInEasing), RepeatMode.Reverse), label = "pulse")
        Box(Modifier.size(64.dp).scale(pulse).clip(CircleShape).background(Brush.linearGradient(listOf(AiViolet, AiPink))), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.AutoAwesome, null, tint = Color.White, modifier = Modifier.size(30.dp))
        }
        Text(stringResource(R.string.fla_pm_reading), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.padding(horizontal = 32.dp))
    }
    FlSkeletonList(2, 120.dp)
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun PmBody(d: FreelancePmSummaryDto) {
    val s = d.summary
    s?.headline?.let { headline ->
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Brush.linearGradient(listOf(AiViolet, AiPink))).padding(14.dp),
            horizontalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Icon(Icons.Rounded.AutoAwesome, null, tint = Color.White, modifier = Modifier.size(22.dp))
            Column {
                Text(stringResource(R.string.fla_ai_says), color = Color.White.copy(alpha = 0.85f), fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
                Text(headline, color = Color.White, fontSize = 14.sp, lineHeight = 21.sp, fontWeight = FontWeight.SemiBold)
            }
        }
    }
    // The numbers DORR counted itself (never the model's).
    val f = d.facts
    val facts = buildList {
        if (f.parts > 0) add(stringResource(R.string.fla_pm_f_parts, f.partsDone, f.parts) to settingsAccent())
        f.percent?.let { add(stringResource(R.string.fla_pm_f_percent, it) to AiViolet) }
        if (f.tasksDone > 0) add(stringResource(R.string.fla_pm_f_done, f.tasksDone) to Color(0xFF16A34A))
        if (f.tasksOverdue > 0) add(stringResource(R.string.fla_pm_f_overdue, f.tasksOverdue) to Color(0xFFDC2626))
        if (f.files > 0) add(stringResource(R.string.fla_pm_f_files, f.files) to settingsAccent())
        if (f.messages > 0) add(stringResource(R.string.fla_pm_f_messages, f.messages) to settingsMut())
    }
    if (facts.isNotEmpty()) {
        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            facts.forEach { (text, color) -> Pill(text, color) }
        }
    }
    if (d.quiet || s == null) {
        FlNotice(stringResource(R.string.fla_pm_quiet), Icons.Rounded.CheckCircle, Color(0xFF16A34A))
    } else {
        PmSection(Icons.Rounded.TaskAlt, Color(0xFF16A34A), stringResource(R.string.fla_pm_progress), s.progress)
        PmSection(Icons.Rounded.Gavel, settingsAccent(), stringResource(R.string.fla_pm_decisions), s.decisions)
        PmSection(Icons.Rounded.ChangeCircle, Color(0xFFD97706), stringResource(R.string.fla_pm_changes), s.changes)
        PmSection(Icons.Rounded.WarningAmber, Color(0xFFDC2626), stringResource(R.string.fla_pm_risks), s.risks)
        PmSection(Icons.Rounded.Flag, AiViolet, stringResource(R.string.fla_pm_next_client), s.nextClient)
        PmSection(Icons.Rounded.HourglassTop, settingsMut(), stringResource(R.string.fla_pm_next_freelancer), s.nextFreelancer)
    }
    d.health?.let { HealthCard(it) }
}

@Composable
private fun PmSection(icon: ImageVector, color: Color, title: String, lines: List<String>) {
    if (lines.isEmpty()) return
    val surface = if (settingsNight()) Color(0xFF1F2937) else Color.White
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(surface).padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Box(Modifier.size(28.dp).clip(RoundedCornerShape(9.dp)).background(color.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                Icon(icon, null, tint = color, modifier = Modifier.size(16.dp))
            }
            Text(title, color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 14.sp)
        }
        lines.forEach { Text("•  $it", color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp) }
    }
}
