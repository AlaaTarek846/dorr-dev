package com.dorr.freelance.ui.screens

import androidx.activity.compose.BackHandler
import androidx.compose.animation.core.animateFloatAsState
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
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowLeft
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.Autorenew
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.EditNote
import androidx.compose.material.icons.rounded.Forum
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.RateReview
import androidx.compose.material.icons.rounded.RemoveCircleOutline
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.TaskAlt
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material.icons.rounded.VerifiedUser
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material.icons.rounded.Work
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
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
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.ReputationCorrectionDto
import com.dorr.freelance.net.ReputationCorrectionRequest
import com.dorr.freelance.net.ReputationDto
import com.dorr.freelance.net.ReputationIndicatorDto
import com.dorr.freelance.net.ReputationProjectDto
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.ChipRow
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.EmptyState
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.FieldLabel
import com.dorr.freelance.ui.components.Page
import com.dorr.freelance.ui.components.SkeletonDetail
import com.dorr.freelance.ui.kit.Corners
import com.dorr.freelance.ui.kit.SectionHeader
import com.dorr.freelance.ui.kit.StatusPill
import com.dorr.freelance.ui.kit.Tints
import com.dorr.freelance.ui.kit.appCard
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsNight
import kotlinx.coroutines.launch
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter

private const val ASK_MIN = 10
private const val ASK_MAX = 1000

/** The figures whose projects I see (and may ask to correct). */
private val CORRECTABLE = setOf("delivery", "completion")

private fun repDay(iso: String?): String = runCatching {
    OffsetDateTime.parse(iso).atZoneSameInstant(ZoneId.systemDefault()).format(DateTimeFormatter.ofPattern("d MMM yyyy"))
}.getOrDefault("")

@Composable
private fun repIndicatorTitle(key: String): String = stringResource(
    when (key) {
        "quality" -> R.string.rep_ind_quality
        "delivery" -> R.string.rep_ind_delivery
        "communication" -> R.string.rep_ind_communication
        "completion" -> R.string.rep_ind_completion
        "repeat_clients" -> R.string.rep_ind_repeat_clients
        else -> R.string.rep_ind_technical
    },
)

private fun repIndicatorIcon(key: String): ImageVector = when (key) {
    "quality" -> Icons.Rounded.Star
    "delivery" -> Icons.Rounded.Schedule
    "communication" -> Icons.Rounded.Forum
    "completion" -> Icons.Rounded.TaskAlt
    "repeat_clients" -> Icons.Rounded.Autorenew
    else -> Icons.Rounded.Verified
}

private fun repIndicatorTint(key: String): Color = when (key) {
    "quality" -> Tints.amber
    "delivery" -> Tints.blue
    "communication" -> Tints.teal
    "completion" -> Tints.green
    "repeat_clients" -> Tints.violet
    else -> Tints.rose
}

/** excellent (85+) · good (70+) · fair (50+) · low · new — the server's own bands, for the overall score. */
private fun repLevelOf(value: Int?): String = when {
    value == null -> "new"
    value >= 85 -> "excellent"
    value >= 70 -> "good"
    value >= 50 -> "fair"
    else -> "low"
}

@Composable
private fun repLevelLabel(level: String): String = stringResource(
    when (level) {
        "excellent" -> R.string.rep_lv_excellent
        "good" -> R.string.rep_lv_good
        "fair" -> R.string.rep_lv_fair
        "low" -> R.string.rep_lv_low
        else -> R.string.rep_lv_new
    },
)

private fun repLevelColor(level: String): Color = when (level) {
    "excellent" -> QualityGreen
    "good" -> Tints.teal
    "fair" -> QualityAmber
    "low" -> QualityRed
    else -> QualitySlate
}

@Composable
private fun repReasonLabel(reason: String): String = stringResource(
    when (reason) {
        "client_delay" -> R.string.rep_reason_client_delay
        "client_ended" -> R.string.rep_reason_client_ended
        "scope_changed" -> R.string.rep_reason_scope_changed
        "wrong_data" -> R.string.rep_reason_wrong_data
        else -> R.string.rep_reason_other
    },
)

/** Where a figure comes from, in plain words: «From 4 parts, 3 on time · a project taken out after a correction». */
@Composable
private fun repSourceLine(ind: ReputationIndicatorDto): String {
    val s = ind.source
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    val parts = mutableListOf<String>()
    if (ind.value == null) parts += stringResource(R.string.rep_no_data)
    when (s.kind) {
        "ratings" -> if (s.count > 0) parts += stringResource(R.string.rep_src_ratings, s.count)
        "parts" -> if (s.count > 0) parts += stringResource(R.string.rep_src_parts, s.count, s.hits ?: 0)
        "projects" -> if (s.count > 0) parts += stringResource(R.string.rep_src_projects, s.count, s.hits ?: 0)
        "clients" -> if (s.count > 0) parts += stringResource(R.string.rep_src_clients, s.count, s.hits ?: 0)
        "certifications" -> {
            if (s.count > 0) parts += stringResource(R.string.rep_src_certs, s.count)
            val best = s.best.orEmpty().filter { !it.name.isNullOrBlank() }.take(2)
            if (best.isNotEmpty()) parts += stringResource(R.string.rep_src_best, best.map { "${it.name} (${skillLevelLabel(it.level)})" }.joinToString(if (rtl) "، " else ", "))
            if ((s.verifiedWorks ?: 0) > 0) parts += stringResource(R.string.rep_src_verified, s.verifiedWorks ?: 0)
        }
    }
    val excluded = s.excluded ?: 0
    if (excluded > 0) parts += pluralStringResource(R.plurals.rep_src_excluded, excluded, excluded)
    return parts.joinToString(" · ")
}

/**
 * FS-604: my reputation — the overall score, the six figures clients see (each with its level and where it comes from), the projects behind
 * delivery and completion with «ask for a correction», and my requests with staff's answers. Read when the page opens, when the connection
 * returns and when a notice arrives (staff answered a request), never on a timer.
 */
@Composable
fun ReputationScreen(onBack: () -> Unit) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var data by remember { mutableStateOf<ReputationDto?>(null) }
    var failure by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    // The figure whose projects are open (delivery / completion), and the project a correction is being asked for.
    var openKey by rememberSaveable { mutableStateOf<String?>(null) }
    var asking by remember { mutableStateOf<ReputationProjectDto?>(null) }

    suspend fun load() {
        when (val r = apiCall { ApiClient.reputation.show() }) {
            is ApiResult.Ok -> { data = r.data; failure = null }
            else -> if (data == null) failure = r.errorText(offlineText) ?: genericError
        }
    }
    LaunchedEffect(reconnect) { load() }
    LaunchedEffect(Unit) { Realtime.changed.collect { load() } }

    BackHandler(enabled = openKey != null) { openKey = null }
    val current = data
    val open = current?.indicators?.firstOrNull { it.key == openKey && it.key in CORRECTABLE }

    Page(title = if (open != null) repIndicatorTitle(open.key) else stringResource(R.string.rep_title), onBack = { if (openKey != null) openKey = null else onBack() }) {
        when {
            current == null && failure != null -> EmptyState(Icons.Rounded.VerifiedUser, stringResource(R.string.rep_title), failure.orEmpty(), Modifier.padding(top = 70.dp))
            current == null -> SkeletonDetail()
            open != null -> ProjectsPart(open, current, onAsk = { asking = it })
            else -> Overview(current, onOpen = { openKey = it })
        }
        ErrorBanner(notice ?: if (current != null) failure else null, Modifier.fillMaxWidth())
    }

    val project = asking
    if (project != null && open != null && current != null) {
        val sentText = stringResource(R.string.rep_ask_sent)
        CorrectionSheet(
            project = project, indicator = open.key, reasons = current.reasons,
            onDismiss = { asking = null },
            send = { reason, note ->
                val r = apiCall { ApiClient.reputation.ask(ReputationCorrectionRequest(project.agreement, open.key, reason, note)) }
                if (r is ApiResult.Ok) {
                    data = current.copy(corrections = r.data.corrections)
                    asking = null
                    notice = sentText
                    scope.launch { load() }
                    null
                } else r.errorText(offlineText) ?: genericError
            },
        )
    }
}

@Composable
private fun Overview(data: ReputationDto, onOpen: (String) -> Unit) {
    OverallHero(data.overall)
    SectionHeader(stringResource(R.string.rep_indicators), Modifier.widthIn(max = 520.dp))
    data.indicators.forEach { ind ->
        IndicatorCard(ind, onOpen = if (ind.key in CORRECTABLE && !ind.projects.isNullOrEmpty()) ({ onOpen(ind.key) }) else null)
    }
    SectionHeader(stringResource(R.string.rep_requests), Modifier.widthIn(max = 520.dp))
    if (data.corrections.isEmpty()) {
        Text(stringResource(R.string.rep_requests_empty), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.fillMaxWidth().widthIn(max = 520.dp))
    } else {
        data.corrections.forEach { CorrectionRow(it) }
    }
}

/** The overall score on the app colour: a white ring that fills to it (a dash while unknown), its level and who sees it. */
@Composable
private fun OverallHero(overall: Int?) {
    val accent = settingsAccent()
    Column(
        Modifier.fillMaxWidth().widthIn(max = 520.dp).clip(Corners.hero)
            .background(Brush.linearGradient(listOf(accent, accent.copy(alpha = 0.72f)))).padding(20.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(16.dp)) {
            if (overall != null) CoachRing(overall, 96, track = Color.White.copy(alpha = 0.25f), bar = Color.White, ink = Color.White)
            else Box(Modifier.size(96.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.18f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.HourglassTop, null, tint = Color.White, modifier = Modifier.size(34.dp))
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Text(stringResource(R.string.rep_overall), color = Color.White.copy(alpha = 0.9f), fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold)
                if (overall != null) {
                    Text(repLevelLabel(repLevelOf(overall)), color = Color.White, fontSize = 22.sp, fontWeight = FontWeight.Black)
                } else {
                    Text(stringResource(R.string.rep_overall_none), color = Color.White, fontSize = 17.sp, fontWeight = FontWeight.ExtraBold)
                    Text(stringResource(R.string.rep_overall_none_body), color = Color.White.copy(alpha = 0.9f), fontSize = 12.sp, lineHeight = 17.sp)
                }
            }
        }
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(Color.White.copy(alpha = 0.14f)).padding(horizontal = 12.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Icon(Icons.Rounded.Visibility, null, tint = Color.White, modifier = Modifier.size(16.dp))
            Text(stringResource(R.string.rep_clients_see), color = Color.White, fontSize = 12.sp, lineHeight = 17.sp, modifier = Modifier.weight(1f))
        }
    }
}

/** One figure: its icon and name, the value (and a bar filling to it), its level, and where it comes from; [onOpen] opens its projects. */
@Composable
private fun IndicatorCard(ind: ReputationIndicatorDto, onOpen: (() -> Unit)?) {
    val tint = repIndicatorTint(ind.key)
    val tone = repLevelColor(ind.level)
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    var shown by remember { mutableStateOf(false) }
    LaunchedEffect(ind.value) { shown = true }
    val fill by animateFloatAsState(if (shown) (ind.value ?: 0).coerceIn(0, 100) / 100f else 0f, tween(800), label = "repBar")
    Column(
        Modifier.fillMaxWidth().widthIn(max = 520.dp).appCard(Corners.card, 4.dp)
            .then(if (onOpen != null) Modifier.clickable(onClick = onOpen) else Modifier).padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Box(Modifier.size(38.dp).clip(Corners.small).background(tint.copy(alpha = if (settingsNight()) 0.22f else 0.12f)), contentAlignment = Alignment.Center) {
                Icon(repIndicatorIcon(ind.key), null, tint = tint, modifier = Modifier.size(20.dp))
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text(repIndicatorTitle(ind.key), color = settingsInk(), fontSize = 14.5.sp, fontWeight = FontWeight.ExtraBold)
                StatusPill(repLevelLabel(ind.level), tone)
            }
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                Text(ind.value?.let { "$it%" } ?: "—", color = if (ind.value != null) tone else settingsMut(), fontSize = 22.sp, fontWeight = FontWeight.Black)
            }
        }
        if (ind.value != null) {
            Box(Modifier.fillMaxWidth().height(8.dp).clip(RoundedCornerShape(99.dp)).background(tone.copy(alpha = 0.14f))) {
                Box(Modifier.fillMaxWidth(fill).fillMaxHeight().clip(RoundedCornerShape(99.dp)).background(tone))
            }
        }
        val line = repSourceLine(ind)
        if (line.isNotBlank()) Text(line, color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp)
        if (onOpen != null) Row(verticalAlignment = Alignment.CenterVertically) {
            Text(stringResource(R.string.rep_see_projects), color = settingsAccent(), fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
            Icon(if (rtl) Icons.AutoMirrored.Rounded.KeyboardArrowLeft else Icons.AutoMirrored.Rounded.KeyboardArrowRight, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
        }
    }
}

/** The projects behind delivery or completion: each with its outcome, and «ask for a correction» on the ones that count against me. */
@Composable
private fun ProjectsPart(ind: ReputationIndicatorDto, data: ReputationDto, onAsk: (ReputationProjectDto) -> Unit) {
    IndicatorCard(ind, onOpen = null)
    Text(
        stringResource(if (ind.key == "delivery") R.string.rep_projects_intro_delivery else R.string.rep_projects_intro_completion),
        color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp, modifier = Modifier.fillMaxWidth().widthIn(max = 520.dp),
    )
    val open = data.corrections.filter { it.status == "open" }
    val full = data.openMax > 0 && open.size >= data.openMax
    if (full) NoteBanner(Icons.Rounded.HourglassTop, stringResource(R.string.rep_ask_limit, open.size), QualityAmber, Modifier.widthIn(max = 520.dp))
    val projects = ind.projects.orEmpty()
    if (projects.isEmpty()) Text(stringResource(R.string.rep_projects_empty), color = settingsMut(), fontSize = 13.sp)
    projects.forEach { p ->
        val asked = open.any { it.indicator == ind.key && it.project?.agreement == p.agreement }
        ProjectRow(p, ind.key, asked = asked, canAsk = !full, onAsk = { onAsk(p) })
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun ProjectRow(p: ReputationProjectDto, indicator: String, asked: Boolean, canAsk: Boolean, onAsk: () -> Unit) {
    val against = !p.excluded && (p.outcome == "late" || p.outcome == "ended_by_me")
    Column(
        Modifier.fillMaxWidth().widthIn(max = 520.dp).appCard(Corners.card, 4.dp).padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Icon(Icons.Rounded.Work, null, tint = settingsMut(), modifier = Modifier.size(18.dp))
            Text(p.title.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, modifier = Modifier.weight(1f))
        }
        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            when (p.outcome) {
                "on_time" -> StatusPill(stringResource(R.string.rep_out_on_time), QualityGreen, Icons.Rounded.CheckCircle)
                "late" -> StatusPill(
                    if ((p.parts ?: 0) > 0 && p.late != null) stringResource(R.string.rep_out_late_n, p.late, p.parts ?: 0) else stringResource(R.string.rep_out_late),
                    QualityAmber, Icons.Rounded.Schedule,
                )
                "completed" -> StatusPill(stringResource(R.string.rep_out_completed), QualityGreen, Icons.Rounded.TaskAlt)
                "ended_by_me" -> StatusPill(stringResource(R.string.rep_out_ended_by_me), QualityRed, Icons.Rounded.Close)
            }
            if (indicator == "delivery" && p.outcome == "on_time" && (p.parts ?: 0) > 0) StatusPill(stringResource(R.string.rep_parts_n, p.parts ?: 0), QualitySlate)
            if (p.excluded) StatusPill(stringResource(R.string.rep_out_excluded), QualitySlate, Icons.Rounded.RemoveCircleOutline)
        }
        when {
            asked -> StatusPill(stringResource(R.string.rep_asked), QualityAmber, Icons.Rounded.HourglassTop)
            against -> ActionButton(stringResource(R.string.rep_ask), Icons.Rounded.EditNote, Tone.Soft, enabled = canAsk, onClick = onAsk)
        }
    }
}

/** One request of mine: the project, the figure and the reason, where it stands, what I wrote and staff's reply. */
@Composable
private fun CorrectionRow(c: ReputationCorrectionDto) {
    val (tone, label) = when (c.status) {
        "accepted" -> QualityGreen to stringResource(R.string.rep_st_accepted)
        "rejected" -> QualityRed to stringResource(R.string.rep_st_rejected)
        else -> QualityAmber to stringResource(R.string.rep_st_open)
    }
    Column(
        Modifier.fillMaxWidth().widthIn(max = 520.dp).appCard(Corners.card, 4.dp).padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(c.project?.title.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, modifier = Modifier.weight(1f))
            StatusPill(label, tone, if (c.status == "open") Icons.Rounded.HourglassTop else if (c.status == "accepted") Icons.Rounded.CheckCircle else Icons.Rounded.Close)
        }
        Text(repIndicatorTitle(c.indicator) + " · " + repReasonLabel(c.reason), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
        c.note?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsInk(), fontSize = 12.5.sp, lineHeight = 18.sp, maxLines = 4) }
        val reply = c.decisionNote?.takeIf { it.isNotBlank() }
        if (reply != null) NoteBanner(Icons.Rounded.RateReview, reply, tone, title = stringResource(R.string.rep_reply))
        else if (c.status == "accepted") Text(stringResource(R.string.rep_st_accepted_body), color = QualityGreen, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
        repDay(c.sentAt).takeIf { it.isNotBlank() }?.let { Text(stringResource(R.string.rep_sent_on, it), color = settingsMut(), fontSize = 11.sp) }
    }
}

/**
 * Asking staff to correct one project's data: the reason as chips and a note of 10..1000 characters. [send] returns null when the server
 * took it (the page then closes the sheet) or the text to show here.
 */
@Composable
private fun CorrectionSheet(project: ReputationProjectDto, indicator: String, reasons: List<String>, onDismiss: () -> Unit, send: suspend (String, String) -> String?) {
    val scope = rememberCoroutineScope()
    var reason by rememberSaveable(project.agreement) { mutableStateOf<String?>(null) }
    var note by rememberSaveable(project.agreement) { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val length = note.trim().length
    val options = reasons.ifEmpty { listOf("client_delay", "client_ended", "scope_changed", "wrong_data", "other") }
    FormSheet(
        Icons.Rounded.EditNote, stringResource(R.string.rep_ask), stringResource(R.string.rep_ask_intro), onDismiss = { if (!busy) onDismiss() },
        // The send button stays above the keyboard.
        footer = {
            error?.let { Text(it, color = QualityRed, fontSize = 12.sp) }
            ActionButton(stringResource(R.string.rep_ask_send), Icons.AutoMirrored.Rounded.Send, Tone.Primary, enabled = reason != null && length in ASK_MIN..ASK_MAX, loading = busy) {
                val picked = reason ?: return@ActionButton
                busy = true
                error = null
                scope.launch {
                    error = send(picked, note.trim())
                    busy = false
                }
            }
        },
    ) {
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsMut().copy(alpha = 0.08f)).padding(12.dp),
            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Icon(repIndicatorIcon(indicator), null, tint = repIndicatorTint(indicator), modifier = Modifier.size(20.dp))
            Column(Modifier.weight(1f)) {
                Text(project.title.orEmpty(), color = settingsInk(), fontSize = 13.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2)
                Text(repIndicatorTitle(indicator), color = settingsMut(), fontSize = 11.5.sp)
            }
        }
        FieldLabel(stringResource(R.string.rep_ask_reason))
        ChipRow(options = options.map { it to repReasonLabel(it) }, selected = setOfNotNull(reason), onToggle = { reason = it })
        DorrTextField(
            note, { note = it.take(ASK_MAX) }, label = stringResource(R.string.rep_ask_note), placeholder = stringResource(R.string.rep_ask_note_ph),
            icon = Icons.Rounded.Notes, singleLine = false, minLines = 4, modifier = Modifier.fillMaxWidth(),
        )
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Text(
                stringResource(R.string.skl_ch_note_min, ASK_MIN), color = if (length in 1 until ASK_MIN) QualityAmber else settingsMut(),
                fontSize = 11.5.sp, modifier = Modifier.weight(1f),
            )
            Text("${note.length}/$ASK_MAX", color = settingsMut(), fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold)
        }
    }
}
