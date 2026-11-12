package com.dorr.freelance.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.VerifiedUser
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.freelance.R
import com.dorr.freelance.ui.kit.Tints
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsNight
import java.time.YearMonth
import java.time.format.DateTimeFormatter

/*
 * FS-601 / FS-602 / FS-604 (quality & trust): the pieces the verified works, the skill levels and the reputation screen share — a
 * skill level as a pill and as a four-step meter, the «Verified by Dorr» badge and a tinted note banner.
 */

/** The green of «verified» / «accepted» across the quality screens. */
internal val QualityGreen = Color(0xFF16A34A)
internal val QualityAmber = Color(0xFFD97706)
internal val QualityRed = Color(0xFFDC2626)
internal val QualitySlate = Color(0xFF64748B)

/** The four levels of a skill badge, lowest first. */
internal val SKILL_LEVELS = listOf("beginner", "intermediate", "advanced", "expert")

@Composable
internal fun skillLevelLabel(level: String?): String = when (level) {
    "intermediate" -> stringResource(R.string.skl_level_intermediate)
    "advanced" -> stringResource(R.string.skl_level_advanced)
    "expert" -> stringResource(R.string.skl_level_expert)
    else -> stringResource(R.string.skl_level_beginner)
}

/** Each level has its colour: blue, teal, violet and gold. */
internal fun skillLevelColor(level: String?): Color = when (level) {
    "intermediate" -> Tints.teal
    "advanced" -> Tints.violet
    "expert" -> Tints.amber
    else -> Tints.blue
}

/** A skill's level as a pill in the level's colour, with a seal icon. */
@Composable
internal fun SkillLevelPill(level: String?, modifier: Modifier = Modifier) {
    val tint = skillLevelColor(level)
    Row(
        modifier.clip(RoundedCornerShape(999.dp)).background(tint.copy(alpha = if (settingsNight()) 0.22f else 0.12f)).padding(horizontal = 10.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Rounded.VerifiedUser, null, tint = tint, modifier = Modifier.size(13.dp))
        Spacer(Modifier.width(4.dp))
        Text(skillLevelLabel(level), color = tint, fontSize = 11.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
    }
}

/**
 * Where a level stands among the four: four bars filled up to it in the level's colour, the four names under them (the reached ones in
 * ink). [level] null draws them all empty.
 */
@Composable
internal fun SkillLevelMeter(level: String?, modifier: Modifier = Modifier) {
    val reached = if (level == null) -1 else SKILL_LEVELS.indexOf(level).coerceAtLeast(0)
    val tint = skillLevelColor(level)
    val track = settingsMut().copy(alpha = 0.16f)
    Column(modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(5.dp)) {
            SKILL_LEVELS.forEachIndexed { i, _ ->
                Box(Modifier.weight(1f).height(6.dp).clip(RoundedCornerShape(99.dp)).background(if (i <= reached) tint else track))
            }
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(5.dp)) {
            SKILL_LEVELS.forEachIndexed { i, l ->
                Text(
                    skillLevelLabel(l), color = if (i == reached) tint else if (i < reached) settingsInk() else settingsMut(),
                    fontSize = 10.5.sp, fontWeight = if (i == reached) FontWeight.ExtraBold else FontWeight.SemiBold,
                    maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f),
                )
            }
        }
    }
}

/** "2026-03" → "Mar 2026" in the app's language (Latin digits); the text itself when it can't be read. */
internal fun monthLabel(yearMonth: String?): String = runCatching {
    YearMonth.parse(yearMonth).format(DateTimeFormatter.ofPattern("MMMM yyyy"))
}.getOrDefault(yearMonth.orEmpty())

/** «Verified by Dorr · finished {month}»: a green shield and the line, on a soft green pill ([compact] for a card, smaller). */
@Composable
internal fun VerifiedBadge(verifiedOn: String?, modifier: Modifier = Modifier, compact: Boolean = false) {
    val text = if (verifiedOn.isNullOrBlank()) stringResource(R.string.pfv_badge) else stringResource(R.string.pfv_badge_on, monthLabel(verifiedOn))
    Row(
        modifier.clip(RoundedCornerShape(999.dp)).background(QualityGreen.copy(alpha = if (settingsNight()) 0.22f else 0.11f))
            .padding(horizontal = if (compact) 8.dp else 10.dp, vertical = if (compact) 3.dp else 5.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Rounded.VerifiedUser, null, tint = QualityGreen, modifier = Modifier.size(if (compact) 13.dp else 15.dp))
        Spacer(Modifier.width(4.dp))
        Text(text, color = QualityGreen, fontSize = if (compact) 11.sp else 12.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

/** A note on a soft tint with a hairline: an icon on a disc, an optional bold title and the text (a warning, a success, a hint). */
@Composable
internal fun NoteBanner(icon: ImageVector, text: String, tint: Color, modifier: Modifier = Modifier, title: String? = null) {
    val shape = RoundedCornerShape(16.dp)
    Row(
        modifier.fillMaxWidth().clip(shape).background(tint.copy(alpha = if (settingsNight()) 0.16f else 0.08f))
            .border(1.dp, tint.copy(alpha = 0.25f), shape).padding(12.dp),
        horizontalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Box(Modifier.size(30.dp).clip(CircleShape).background(tint.copy(alpha = 0.16f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = tint, modifier = Modifier.size(17.dp))
        }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            title?.let { Text(it, color = settingsInk(), fontSize = 13.5.sp, fontWeight = FontWeight.ExtraBold) }
            Text(text, color = if (title == null) settingsInk() else settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp)
        }
    }
}
