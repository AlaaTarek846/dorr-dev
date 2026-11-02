package com.dorr.freelance.ui.screens

import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowLeft
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.AccountCircle
import androidx.compose.material.icons.rounded.AddAPhoto
import androidx.compose.material.icons.rounded.AlternateEmail
import androidx.compose.material.icons.rounded.Bolt
import androidx.compose.material.icons.rounded.Category
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.EmojiEvents
import androidx.compose.material.icons.rounded.Language
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Storefront
import androidx.compose.material.icons.rounded.Subtitles
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material.icons.rounded.Work
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.CoachDto
import com.dorr.freelance.net.CoachTipDto
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.EmptyState
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.Page
import com.dorr.freelance.ui.components.SkeletonDetail
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsSurface

/**
 * FL-L11, «improve my profile»: what to do next to be found and chosen more. The server decides what is worth saying (the same rules for
 * every plan) and ranks it; this writes each step in plain words and opens the screen that fixes it. Read when the page opens, when the
 * connection returns and when a notice arrives — never on a timer — so a step ticks off as soon as the profile changes.
 */
@Composable
fun rememberCoach(): CoachState {
    val reconnect = collectReconnectTick()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var coach by remember { mutableStateOf<CoachDto?>(null) }
    var failure by remember { mutableStateOf<String?>(null) }

    suspend fun load() {
        when (val result = apiCall { ApiClient.coach.coach() }) {
            is ApiResult.Ok -> { coach = result.data; failure = null }
            else -> if (coach == null) failure = result.errorText(offlineText) ?: genericError
        }
    }
    LaunchedEffect(reconnect) { load() }
    LaunchedEffect(Unit) { Realtime.changed.collect { load() } }
    return CoachState(coach, failure)
}

/** What was read: [coach] is null until the first answer, and [failure] says why it did not come. */
class CoachState(val coach: CoachDto?, val failure: String?)

/** The full page: the ring, a line for where the profile stands, and every step with what it is worth. */
@Composable
fun ProfileCoachScreen(onBack: () -> Unit, onOpen: (String) -> Unit) {
    val state = rememberCoach()
    Page(title = stringResource(R.string.coach_title), onBack = onBack) {
        val coach = state.coach
        when {
            coach == null && state.failure != null -> EmptyState(Icons.Rounded.AccountCircle, stringResource(R.string.coach_title), state.failure.orEmpty(), Modifier.padding(top = 70.dp))
            coach == null -> SkeletonDetail()
            else -> CoachBody(coach, onOpen)
        }
        ErrorBanner(if (state.coach != null) state.failure else null, Modifier.fillMaxWidth())
    }
}

@Composable
private fun CoachBody(coach: CoachDto, onOpen: (String) -> Unit) {
    CoachHero(coach)
    if (coach.tips.isEmpty()) {
        EmptyState(Icons.Rounded.EmojiEvents, stringResource(R.string.coach_great_title), stringResource(R.string.coach_great_body), Modifier.padding(top = 8.dp))
        return
    }
    listOf("high", "medium", "low").forEach { impact ->
        val group = coach.tips.filter { it.impact == impact }
        if (group.isEmpty()) return@forEach
        Text(impactTitle(impact), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 4.dp))
        group.forEach { tip -> TipRow(tip, onClick = { onOpen(tip.action) }) }
    }
}

/** The ring on the accent colour with where the profile stands in words, and how many steps of the bar are done. */
@Composable
private fun CoachHero(coach: CoachDto) {
    val accent = settingsAccent()
    Row(
        Modifier.fillMaxWidth().widthIn(max = 520.dp).clip(RoundedCornerShape(24.dp)).background(Brush.linearGradient(listOf(accent, accent.copy(alpha = 0.72f)))).padding(20.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(18.dp),
    ) {
        CoachRing(coach.percent, 92, track = Color.White.copy(alpha = 0.25f), bar = Color.White, ink = Color.White)
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(levelTitle(coach.level), color = Color.White, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.coach_steps_done, coach.done, coach.total), color = Color.White.copy(alpha = 0.88f), fontSize = 12.sp)
            if (coach.tips.isNotEmpty()) Text(stringResource(R.string.coach_next_hint), color = Color.White.copy(alpha = 0.88f), fontSize = 12.sp, lineHeight = 17.sp)
        }
    }
}

/** A ring that fills to the percent when it appears; the number sits in the middle (left to right in either language). */
@Composable
fun CoachRing(percent: Int, size: Int, track: Color = settingsAccent().copy(alpha = 0.14f), bar: Color = settingsAccent(), ink: Color = settingsInk()) {
    var shown by remember { mutableStateOf(false) }
    LaunchedEffect(percent) { shown = true }
    val sweep by animateFloatAsState(if (shown) percent.coerceIn(0, 100) / 100f else 0f, tween(900), label = "coachRing")
    Box(Modifier.size(size.dp), contentAlignment = Alignment.Center) {
        Canvas(Modifier.size(size.dp)) {
            val stroke = Stroke(width = this.size.width * 0.11f, cap = StrokeCap.Round)
            val inset = stroke.width / 2
            val arc = Size(this.size.width - stroke.width, this.size.height - stroke.width)
            drawArc(track, 0f, 360f, false, Offset(inset, inset), arc, style = stroke)
            drawArc(bar, -90f, 360f * sweep, false, Offset(inset, inset), arc, style = stroke)
        }
        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
            Text("$percent%", color = ink, fontSize = (size * 0.22f).sp, fontWeight = FontWeight.ExtraBold)
        }
    }
}

@Composable
private fun TipRow(tip: CoachTipDto, onClick: () -> Unit) {
    val text = tipText(tip.key) ?: return
    val tone = impactTone(tip.impact)
    Row(
        Modifier.fillMaxWidth().widthIn(max = 520.dp).clip(RoundedCornerShape(18.dp)).settingsSurface(RoundedCornerShape(18.dp)).clickable(onClick = onClick).padding(14.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Box(Modifier.size(42.dp).clip(CircleShape).background(tone.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
            Icon(tipIcon(tip.key), null, tint = tone, modifier = Modifier.size(22.dp))
        }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
            Text(stringResource(text.first), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
            Text(stringResource(text.second), color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp)
            if (tip.points > 0) {
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    Text("+${tip.points}%", color = tone, fontSize = 12.sp, fontWeight = FontWeight.ExtraBold,
                        modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(tone.copy(alpha = 0.12f)).padding(horizontal = 8.dp, vertical = 2.dp))
                }
            }
        }
        Icon(
            if (LocalLayoutDirection.current == LayoutDirection.Rtl) Icons.AutoMirrored.Rounded.KeyboardArrowLeft else Icons.AutoMirrored.Rounded.KeyboardArrowRight,
            null, tint = settingsMut(), modifier = Modifier.size(22.dp),
        )
    }
}

/**
 * The card on Home: the ring and the next two steps, and the way to all of them. Once nothing is left to do it shrinks to a line of
 * thanks; before the first answer it shows the percent the account already carries, so the card never jumps in.
 */
@Composable
fun CoachCard(fallbackPercent: Int, onAll: () -> Unit, onOpen: (String) -> Unit) {
    val state = rememberCoach()
    val coach = state.coach
    val percent = coach?.percent ?: fallbackPercent
    val tips = coach?.tips.orEmpty()
    Column(
        Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable(onClick = onAll), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(14.dp)) {
            CoachRing(percent, 64)
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(stringResource(R.string.coach_card_title), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                Text(
                    when {
                        coach == null -> stringResource(R.string.home_completeness)
                        tips.isEmpty() -> stringResource(R.string.coach_great_title)
                        else -> stringResource(R.string.coach_card_sub, tips.size)
                    },
                    color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp,
                )
            }
            if (tips.isNotEmpty()) {
                Icon(
                    if (LocalLayoutDirection.current == LayoutDirection.Rtl) Icons.AutoMirrored.Rounded.KeyboardArrowLeft else Icons.AutoMirrored.Rounded.KeyboardArrowRight,
                    null, tint = settingsMut(), modifier = Modifier.size(22.dp),
                )
            }
        }
        tips.take(2).forEach { tip ->
            val text = tipText(tip.key) ?: return@forEach
            val tone = impactTone(tip.impact)
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(tone.copy(alpha = 0.08f)).clickable { onOpen(tip.action) }.padding(horizontal = 12.dp, vertical = 10.dp),
                verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                Icon(tipIcon(tip.key), null, tint = tone, modifier = Modifier.size(20.dp))
                Text(stringResource(text.first), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f), maxLines = 2)
                if (tip.points > 0) {
                    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                        Text("+${tip.points}%", color = tone, fontSize = 12.sp, fontWeight = FontWeight.ExtraBold)
                    }
                }
            }
        }
        if (tips.size > 2) {
            Text(
                stringResource(R.string.coach_see_all, tips.size), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center,
                modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable(onClick = onAll).padding(vertical = 6.dp),
            )
        }
    }
}

// ---------------------------------------------------------------- what each step says

@Composable
private fun levelTitle(level: String): String = stringResource(
    when (level) {
        "great" -> R.string.coach_level_great
        "good" -> R.string.coach_level_good
        else -> R.string.coach_level_start
    },
)

@Composable
private fun impactTitle(impact: String): String = stringResource(
    when (impact) {
        "high" -> R.string.coach_impact_high
        "medium" -> R.string.coach_impact_medium
        else -> R.string.coach_impact_low
    },
)

/** High is the accent, medium amber, low grey-blue: how much a step matters is readable before the words. */
@Composable
private fun impactTone(impact: String): Color = when (impact) {
    "high" -> settingsAccent()
    "medium" -> Color(0xFFD97706)
    else -> Color(0xFF64748B)
}

/** The title and the one-line why of a step, or null for one this version of the app does not know (it is then left out). */
private fun tipText(key: String): Pair<Int, Int>? = when (key) {
    "name" -> R.string.coach_tip_name to R.string.coach_tip_name_body
    "avatar" -> R.string.coach_tip_avatar to R.string.coach_tip_avatar_body
    "headline" -> R.string.coach_tip_headline to R.string.coach_tip_headline_body
    "bio" -> R.string.coach_tip_bio to R.string.coach_tip_bio_body
    "email" -> R.string.coach_tip_email to R.string.coach_tip_email_body
    "languages" -> R.string.coach_tip_languages to R.string.coach_tip_languages_body
    "experience" -> R.string.coach_tip_experience to R.string.coach_tip_experience_body
    "specialties" -> R.string.coach_tip_specialties to R.string.coach_tip_specialties_body
    "skills" -> R.string.coach_tip_skills to R.string.coach_tip_skills_body
    "work" -> R.string.coach_tip_work to R.string.coach_tip_work_body
    "portfolio" -> R.string.coach_tip_portfolio to R.string.coach_tip_portfolio_body
    "more_skills" -> R.string.coach_tip_more_skills to R.string.coach_tip_more_skills_body
    "more_portfolio" -> R.string.coach_tip_more_portfolio to R.string.coach_tip_more_portfolio_body
    "verification" -> R.string.coach_tip_verification to R.string.coach_tip_verification_body
    "first_service" -> R.string.coach_tip_first_service to R.string.coach_tip_first_service_body
    else -> null
}

private fun tipIcon(key: String): ImageVector = when (key) {
    "name" -> Icons.Rounded.Person
    "avatar" -> Icons.Rounded.AddAPhoto
    "headline" -> Icons.Rounded.Subtitles
    "bio" -> Icons.Rounded.Notes
    "email" -> Icons.Rounded.AlternateEmail
    "languages" -> Icons.Rounded.Language
    "experience" -> Icons.Rounded.Schedule
    "specialties" -> Icons.Rounded.Category
    "skills", "more_skills" -> Icons.Rounded.Bolt
    "work" -> Icons.Rounded.Work
    "portfolio", "more_portfolio" -> Icons.Rounded.Work
    "verification" -> Icons.Rounded.Verified
    "first_service" -> Icons.Rounded.Storefront
    else -> Icons.Rounded.CheckCircle
}
