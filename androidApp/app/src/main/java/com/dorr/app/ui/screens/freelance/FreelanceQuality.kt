package com.dorr.app.ui.screens.freelance

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.Build
import androidx.compose.material.icons.rounded.Collections
import androidx.compose.material.icons.rounded.EmojiEvents
import androidx.compose.material.icons.rounded.Flag
import androidx.compose.material.icons.rounded.HelpOutline
import androidx.compose.material.icons.rounded.Insights
import androidx.compose.material.icons.rounded.Lightbulb
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.ReportProblem
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AppLocale
import com.dorr.app.network.FlReputationDto
import com.dorr.app.network.FlReputationIndicatorDto
import com.dorr.app.network.FreelancePortfolioDto
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsNight
import kotlinx.coroutines.CancellationException
import java.time.YearMonth
import java.time.format.DateTimeFormatter
import java.util.Locale

/**
 * Phase 6 — quality and trust, as a client reads a freelancer: the reputation card (FS-604) with «how is it worked out?», the level of a
 * skill badge (FS-602), and a portfolio work that is «Verified by Dorr» with its case study (FS-601).
 */

private val QGreen = Color(0xFF16A34A)
private val QTeal = Color(0xFF0D9488)
private val QAmber = Color(0xFFD97706)
private val QRed = Color(0xFFDC2626)

/** excellent · good · fair · low; «new» is the quiet grey. */
@Composable
internal fun reputationLevelColor(level: String?): Color = when (level) {
    "excellent" -> QGreen
    "good" -> QTeal
    "fair" -> QAmber
    "low" -> QRed
    else -> settingsMut()
}

@Composable
internal fun reputationLevelName(level: String?): String = stringResource(
    when (level) {
        "excellent" -> R.string.fl_rep_level_excellent
        "good" -> R.string.fl_rep_level_good
        "fair" -> R.string.fl_rep_level_fair
        "low" -> R.string.fl_rep_level_low
        else -> R.string.fl_rep_level_new
    },
)

@Composable
private fun indicatorName(key: String): String = stringResource(
    when (key) {
        "quality" -> R.string.fl_rep_quality
        "delivery" -> R.string.fl_rep_delivery
        "communication" -> R.string.fl_rep_communication
        "completion" -> R.string.fl_rep_completion
        "repeat_clients" -> R.string.fl_rep_repeat_clients
        else -> R.string.fl_rep_technical
    },
)

/** The level a skill badge was certified at (FS-602), or null when the server sent none. */
@Composable
internal fun skillLevelName(level: String?): String? = when (level) {
    "beginner" -> stringResource(R.string.fl_skill_level_beginner)
    "intermediate" -> stringResource(R.string.fl_skill_level_intermediate)
    "advanced" -> stringResource(R.string.fl_skill_level_advanced)
    "expert" -> stringResource(R.string.fl_skill_level_expert)
    else -> null
}

/** 1 (beginner) … 4 (expert); 0 when unknown. */
internal fun skillLevelRank(level: String?): Int = when (level) {
    "beginner" -> 1
    "intermediate" -> 2
    "advanced" -> 3
    "expert" -> 4
    else -> 0
}

/** Four dots, filled up to the level. */
@Composable
internal fun SkillLevelDots(level: String?, tint: Color, dot: Int = 5) {
    val rank = skillLevelRank(level)
    if (rank == 0) return
    Row(horizontalArrangement = Arrangement.spacedBy(2.dp), verticalAlignment = Alignment.CenterVertically) {
        repeat(4) { i -> Box(Modifier.size(dot.dp).clip(CircleShape).background(if (i < rank) tint else tint.copy(alpha = 0.22f))) }
    }
}

/** Where a figure comes from, in plain words («From 12 ratings», «4 parts, 3 on time», «3 certified skills, 2 verified works»). */
@Composable
private fun sourceLine(i: FlReputationIndicatorDto): String? {
    val s = i.source
    val parts = mutableListOf<String>()
    when (s.kind) {
        "ratings" -> if (s.count > 0) parts += stringResource(R.string.fl_rep_src_ratings, s.count)
        "parts" -> if (s.count > 0) parts += stringResource(R.string.fl_rep_src_parts, s.count, s.hits ?: 0)
        "projects" -> if (s.count > 0) parts += stringResource(R.string.fl_rep_src_projects, s.count, s.hits ?: 0)
        "clients" -> if (s.count > 0) parts += stringResource(R.string.fl_rep_src_clients, s.count, s.hits ?: 0)
        "certifications" -> {
            if (s.count > 0) parts += stringResource(R.string.fl_rep_src_certs, s.count)
            if ((s.verifiedWorks ?: 0) > 0) parts += stringResource(R.string.fl_rep_src_works, s.verifiedWorks ?: 0)
        }
    }
    if ((s.excluded ?: 0) > 0) parts += stringResource(R.string.fl_rep_src_excluded, s.excluded ?: 0)
    return parts.takeIf { it.isNotEmpty() }?.joinToString("، ")
}

/**
 * FS-604: the freelancer's reputation — the overall score on a ring, then the six figures, each a bar in its level's colour and a line
 * saying what it was worked out from. «How is it worked out?» opens the honest explanation.
 */
@Composable
internal fun ReputationCard(rep: FlReputationDto?, onHow: () -> Unit) {
    if (rep == null || rep.indicators.isEmpty()) return
    Card(Icons.Rounded.Insights, stringResource(R.string.fl_rep_title)) {
        val overall = rep.overall
        val overallLevel = when {
            overall == null -> "new"
            overall >= 85 -> "excellent"
            overall >= 70 -> "good"
            overall >= 50 -> "fair"
            else -> "low"
        }
        val tone = reputationLevelColor(overallLevel)
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(14.dp)) {
            Box(Modifier.size(64.dp), contentAlignment = Alignment.Center) {
                CircularProgressIndicator(progress = { (overall ?: 0) / 100f }, modifier = Modifier.fillMaxSize(), color = tone, strokeWidth = 5.dp, trackColor = tone.copy(alpha = 0.15f))
                Text(overall?.let { "$it" } ?: "—", color = tone, fontSize = 19.sp, fontWeight = FontWeight.ExtraBold)
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(stringResource(R.string.fl_rep_overall), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                if (overall != null) FlDotPill(reputationLevelName(overallLevel), tone)
                else Text(stringResource(R.string.fl_rep_overall_new_line), color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp)
                Text(stringResource(R.string.fl_rep_hint), color = settingsMut(), fontSize = 11.sp)
            }
        }
        Box(Modifier.fillMaxWidth().height(1.dp).background(settingsMut().copy(alpha = 0.15f)))
        rep.indicators.forEach { ReputationRow(it) }
        Row(
            Modifier.clip(RoundedCornerShape(999.dp)).clickable(role = Role.Button, onClick = onHow).background(settingsAccent().copy(alpha = 0.08f)).padding(horizontal = 12.dp, vertical = 7.dp),
            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            Icon(Icons.Rounded.HelpOutline, null, tint = settingsAccent(), modifier = Modifier.size(16.dp))
            Text(stringResource(R.string.fl_rep_how), color = settingsAccent(), fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
        }
    }
}

@Composable
@OptIn(androidx.compose.foundation.layout.ExperimentalLayoutApi::class)
private fun ReputationRow(i: FlReputationIndicatorDto) {
    val name = indicatorName(i.key)
    val tone = reputationLevelColor(i.level)
    val fresh = i.value == null || i.level == "new"
    val source = sourceLine(i)
    val levelName = reputationLevelName(i.level)
    val description = stringResource(R.string.fl_rep_row_desc, name, listOfNotNull(if (fresh) stringResource(R.string.fl_rep_new_line) else "${i.value}% · $levelName", source).joinToString("، "))
    Column(Modifier.fillMaxWidth().semantics(mergeDescendants = true) { contentDescription = description }, verticalArrangement = Arrangement.spacedBy(5.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(name, color = settingsInk(), fontSize = 13.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
            if (fresh) {
                Text(levelName, color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
            } else {
                Text(levelName, color = tone, fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
                Spacer(Modifier.width(8.dp))
                Text("${i.value}", color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
            }
        }
        if (fresh) {
            Text(stringResource(R.string.fl_rep_new_line), color = settingsMut(), fontSize = 11.5.sp)
        } else {
            Box(Modifier.fillMaxWidth().height(7.dp).clip(RoundedCornerShape(999.dp)).background(tone.copy(alpha = if (settingsNight()) 0.22f else 0.13f))) {
                Box(Modifier.fillMaxWidth(((i.value ?: 0).coerceIn(0, 100)) / 100f).fillMaxHeight().clip(RoundedCornerShape(999.dp)).background(tone))
            }
        }
        source?.let { Text(it, color = settingsMut(), fontSize = 11.5.sp, lineHeight = 16.sp) }
        // The best certifications behind «technical quality», each with its level.
        if (i.key == "technical" && i.source.best.isNotEmpty()) {
            androidx.compose.foundation.layout.FlowRow(horizontalArrangement = Arrangement.spacedBy(5.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                i.source.best.forEach { b ->
                    Row(
                        Modifier.clip(RoundedCornerShape(999.dp)).background(QTeal.copy(alpha = 0.10f)).padding(horizontal = 8.dp, vertical = 3.dp),
                        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(5.dp),
                    ) {
                        Text(listOfNotNull(b.name, skillLevelName(b.level)).joinToString(" · "), color = QTeal, fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1)
                        SkillLevelDots(b.level, QTeal, dot = 4)
                    }
                }
            }
        }
    }
}

/** «How is it worked out?»: each figure in plain words, with what never counts against the freelancer. */
@Composable
internal fun ReputationHowSheet(onDismiss: () -> Unit) {
    FlSheet(Icons.Rounded.Insights, stringResource(R.string.fl_rep_how_title), stringResource(R.string.fl_rep_how_body), onDismiss) {
        listOf(
            R.string.fl_rep_quality to R.string.fl_rep_how_quality,
            R.string.fl_rep_delivery to R.string.fl_rep_how_delivery,
            R.string.fl_rep_communication to R.string.fl_rep_how_communication,
            R.string.fl_rep_completion to R.string.fl_rep_how_completion,
            R.string.fl_rep_repeat_clients to R.string.fl_rep_how_repeat,
            R.string.fl_rep_technical to R.string.fl_rep_how_technical,
            R.string.fl_rep_overall to R.string.fl_rep_how_overall,
        ).forEach { (title, body) ->
            Column(verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text(stringResource(title), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                Text(stringResource(body), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 19.sp)
            }
        }
        FlNotice(stringResource(R.string.fl_rep_how_levels), Icons.Rounded.EmojiEvents, settingsAccent())
        FlNotice(stringResource(R.string.fl_rep_how_fix), Icons.Rounded.ReportProblem, QAmber)
        FlAction(stringResource(R.string.fl_rep_how_ok), tone = FlTone.Primary, onClick = onDismiss)
    }
}

// ---------------------------------------------------------------- portfolio works (FS-601)

/** «YYYY-MM» as the month's name and year, with Latin digits («October 2026», «أكتوبر 2026»). */
internal fun workMonth(raw: String?): String? {
    if (raw.isNullOrBlank()) return null
    return runCatching {
        val locale = Locale.forLanguageTag(AppLocale.current)
        YearMonth.parse(raw.take(7)).format(DateTimeFormatter.ofPattern("MMMM yyyy", locale))
    }.getOrDefault(raw.take(7))
}

/** The green «Verified by Dorr» mark on a work. */
@Composable
internal fun VerifiedWorkBadge(modifier: Modifier = Modifier, compact: Boolean = true) {
    Row(
        modifier.clip(RoundedCornerShape(999.dp)).background(QGreen.copy(alpha = if (settingsNight()) 0.22f else 0.12f))
            .padding(horizontal = if (compact) 7.dp else 10.dp, vertical = if (compact) 2.dp else 4.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(3.dp),
    ) {
        Icon(Icons.Rounded.Verified, null, tint = QGreen, modifier = Modifier.size(if (compact) 12.dp else 15.dp))
        Text(stringResource(R.string.fl_work_verified), color = QGreen, fontSize = if (compact) 10.5.sp else 12.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
    }
}

/** A portfolio work in a profile's list: cover, title, «Verified by Dorr · done on Dorr in …», a line, and the report flag. */
@Composable
internal fun PortfolioWorkRow(w: FreelancePortfolioDto, onOpen: () -> Unit, onReport: (String) -> Unit) {
    val open = stringResource(R.string.fl_work_open)
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable(role = Role.Button, onClickLabel = open, onClick = onOpen).padding(vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        val cover = w.images.firstOrNull()?.url
        Box(
            Modifier.size(64.dp).clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.08f))
                .then(if (w.verified) Modifier.border(1.5.dp, QGreen.copy(alpha = 0.55f), RoundedCornerShape(12.dp)) else Modifier),
            contentAlignment = Alignment.Center,
        ) {
            if (cover != null) AsyncImage(model = cover, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
            else Icon(Icons.Rounded.Collections, null, tint = settingsAccent().copy(alpha = 0.5f), modifier = Modifier.size(22.dp))
        }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(w.title.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            if (w.verified) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    VerifiedWorkBadge()
                    workMonth(w.verifiedOn)?.let { Text(it, color = settingsMut(), fontSize = 11.sp, maxLines = 1) }
                }
            }
            w.description?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp, maxLines = 2, overflow = TextOverflow.Ellipsis) }
            if (w.caseStudy.hasText()) {
                Text(stringResource(R.string.fl_work_case_study), color = settingsAccent(), fontSize = 11.sp, fontWeight = FontWeight.Bold)
            }
        }
        w.uuid?.let { id -> Icon(Icons.Rounded.Flag, stringResource(R.string.rights_report), tint = settingsMut(), modifier = Modifier.size(34.dp).clip(RoundedCornerShape(50)).clickable { onReport(id) }.padding(8.dp)) }
        Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, null, tint = settingsMut().copy(alpha = 0.6f), modifier = Modifier.size(18.dp))
    }
}

private fun com.dorr.app.network.FreelanceCaseStudyDto?.hasText(): Boolean =
    this != null && listOf(problem, solution, role, tools, results).any { !it.isNullOrBlank() }

/**
 * One work opened: its images, the «Verified by Dorr» note with the month it was done, what it is, and the case study told in sections —
 * the problem, the solution, my role, the tools and the results (only the parts the freelancer wrote).
 */
@Composable
internal fun PortfolioWorkSheet(w: FreelancePortfolioDto, freelancerUuid: String? = null, onDismiss: () -> Unit, onReport: (String) -> Unit) {
    // FS-704: one open of this work, counted for the freelancer's «Portfolio Analytics» (the server keeps one per client per day). Once per work
    // opened — not on recomposition — and fire-and-forget: nothing shown, any failure ignored. A team's work has no freelancer: not counted.
    LaunchedEffect(w.uuid) {
        val freelancer = freelancerUuid ?: return@LaunchedEffect
        val item = w.uuid ?: return@LaunchedEffect
        try {
            ApiClient.freelance.recordWorkOpened(auth(), freelancer, item)
        } catch (e: CancellationException) {
            throw e
        } catch (_: Exception) {
        }
    }
    val fallback = stringResource(R.string.fl_portfolio)
    FlSheet(Icons.Rounded.Collections, w.title?.takeIf { it.isNotBlank() } ?: fallback, "", onDismiss) {
        val images = w.images.mapNotNull { it.url }
        if (images.isNotEmpty()) {
            Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                images.forEach { url ->
                    AsyncImage(
                        model = url, contentDescription = null, contentScale = ContentScale.Crop,
                        modifier = Modifier.size(width = if (images.size == 1) 320.dp else 240.dp, height = 170.dp).clip(RoundedCornerShape(16.dp)).background(settingsAccent().copy(alpha = 0.08f)),
                    )
                }
            }
        }
        if (w.verified) {
            Column(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(QGreen.copy(alpha = if (settingsNight()) 0.18f else 0.09f)).padding(12.dp),
                verticalArrangement = Arrangement.spacedBy(5.dp),
            ) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    VerifiedWorkBadge(compact = false)
                    Text(
                        workMonth(w.verifiedOn)?.let { stringResource(R.string.fl_work_verified_on, it) } ?: stringResource(R.string.fl_work_verified_line),
                        color = QGreen, fontSize = 12.sp, fontWeight = FontWeight.Bold,
                    )
                }
                Text(stringResource(R.string.fl_work_verified_note), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp)
            }
        }
        w.description?.takeIf { it.isNotBlank() }?.let {
            CaseSection(Icons.Rounded.Description, stringResource(R.string.fl_work_about), it)
        }
        val cs = w.caseStudy
        if (cs.hasText() && cs != null) {
            Text(stringResource(R.string.fl_work_case_study), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 2.dp))
            cs.problem?.takeIf { it.isNotBlank() }?.let { CaseSection(Icons.Rounded.ReportProblem, stringResource(R.string.fl_cs_problem), it, QAmber) }
            cs.solution?.takeIf { it.isNotBlank() }?.let { CaseSection(Icons.Rounded.Lightbulb, stringResource(R.string.fl_cs_solution), it, settingsAccent()) }
            cs.role?.takeIf { it.isNotBlank() }?.let { CaseSection(Icons.Rounded.Person, stringResource(R.string.fl_cs_role), it, Color(0xFF8B5CF6)) }
            cs.tools?.takeIf { it.isNotBlank() }?.let { CaseSection(Icons.Rounded.Build, stringResource(R.string.fl_cs_tools), it, Color(0xFF0EA5E9)) }
            cs.results?.takeIf { it.isNotBlank() }?.let { CaseSection(Icons.Rounded.EmojiEvents, stringResource(R.string.fl_cs_results), it, QGreen) }
        }
        w.uuid?.let { id -> FlAction(stringResource(R.string.rights_report), Icons.Rounded.Flag, onClick = { onReport(id) }) }
    }
}

/** A part of the story: a tinted icon, its name and the text. */
@Composable
private fun CaseSection(icon: ImageVector, title: String, text: String, tint: Color = settingsAccent()) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.Top) {
        Box(Modifier.size(32.dp).clip(RoundedCornerShape(10.dp)).background(tint.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = tint, modifier = Modifier.size(17.dp))
        }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(title, color = settingsInk(), fontSize = 13.5.sp, fontWeight = FontWeight.ExtraBold)
            Text(text, color = settingsInk(), fontSize = 13.sp, lineHeight = 20.sp)
        }
    }
}
