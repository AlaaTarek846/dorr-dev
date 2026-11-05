package com.dorr.freelance.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowForward
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.freelance.R
import com.dorr.freelance.net.HealthReasonDto
import com.dorr.freelance.net.ProjectHealthDto
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsSurface

/** FS-106: the colour of a project health state (the colours the agreement screens already use). */
internal fun healthColor(state: String): Color = when (state) {
    "at_risk" -> Color(0xFFF59E0B)
    "delayed" -> Color(0xFFD97706)
    "blocked" -> Color(0xFFDC2626)
    else -> Color(0xFF16A34A)
}

private fun healthStateRes(state: String): Int = when (state) {
    "at_risk" -> R.string.fs_health_state_at_risk
    "delayed" -> R.string.fs_health_state_delayed
    "blocked" -> R.string.fs_health_state_blocked
    else -> R.string.fs_health_state_healthy
}

private fun healthReasonRes(code: String, hasDays: Boolean): Int? = when (code) {
    "dispute_open" -> R.string.fs_health_r_dispute_open
    "part_unfunded" -> R.string.fs_health_r_part_unfunded
    "part_overdue" -> if (hasDays) R.string.fs_health_r_part_overdue else R.string.fs_health_r_part_overdue_nodays
    "part_due_soon" -> R.string.fs_health_r_part_due_soon
    "part_not_started" -> R.string.fs_health_r_part_not_started
    "review_waiting" -> R.string.fs_health_r_review_waiting
    "report_late" -> R.string.fs_health_r_report_late
    "change_waiting" -> R.string.fs_health_r_change_waiting
    else -> null
}

/** The next step in its two variants: [mine] = something for me to do, else what I wait for. */
private fun healthNextRes(code: String, mine: Boolean, hasDays: Boolean): Int? = if (mine) when (code) {
    "dispute_open" -> R.string.fs_health_n_mine_dispute_open
    "part_unfunded" -> R.string.fs_health_n_mine_part_unfunded
    "part_overdue" -> if (hasDays) R.string.fs_health_n_mine_part_overdue else R.string.fs_health_n_mine_part_overdue_nodays
    "part_due_soon" -> R.string.fs_health_n_mine_part_due_soon
    "part_not_started" -> R.string.fs_health_n_mine_part_not_started
    "review_waiting" -> R.string.fs_health_n_mine_review_waiting
    "report_late" -> R.string.fs_health_n_mine_report_late
    "change_waiting" -> R.string.fs_health_n_mine_change_waiting
    else -> null
} else when (code) {
    "dispute_open" -> R.string.fs_health_n_wait_dispute_open
    "part_unfunded" -> R.string.fs_health_n_wait_part_unfunded
    "part_overdue" -> if (hasDays) R.string.fs_health_n_wait_part_overdue else R.string.fs_health_n_wait_part_overdue_nodays
    "part_due_soon" -> R.string.fs_health_n_wait_part_due_soon
    "part_not_started" -> R.string.fs_health_n_wait_part_not_started
    "review_waiting" -> R.string.fs_health_n_wait_review_waiting
    "report_late" -> R.string.fs_health_n_wait_report_late
    "change_waiting" -> R.string.fs_health_n_wait_change_waiting
    else -> null
}

/** The sentence of one reason (null for a code this version does not know: it is left out, never shown raw). */
@Composable
private fun healthReasonText(r: HealthReasonDto): String? {
    val days = r.data?.days
    val res = healthReasonRes(r.code, days != null) ?: return null
    val part = r.data?.part?.takeIf { it.isNotBlank() } ?: stringResource(R.string.fs_health_part_fallback)
    return stringResource(res, part, if (days != null) healthDaysText(days) else "")
}

/**
 * FS-106: how the running project is doing - a coloured state, the reasons in plain words and ONE next-step line (what I can do now,
 * else what I am waiting for). Informational only: it moves no money and says nothing about my score. Healthy with nothing to say is
 * a small "on track" line; a project that is not running has none (the card is not drawn).
 */
@Composable
internal fun ProjectHealthCard(h: ProjectHealthDto) {
    val tint = healthColor(h.state)
    val reasons = h.reasons.filter { healthReasonRes(it.code, false) != null }
    if (h.state == "healthy" && reasons.isEmpty()) {
        Row(Modifier.fillMaxWidth().padding(horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            Icon(Icons.Rounded.CheckCircle, null, tint = tint, modifier = Modifier.size(16.dp))
            Text(stringResource(R.string.fs_health_on_track), color = tint, fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold)
        }
        return
    }
    val step = h.nextStep
    val stepReason = step?.let { s -> reasons.firstOrNull { it.code == s.code && it.mine == s.mine } ?: reasons.firstOrNull { it.code == s.code } }
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(stringResource(R.string.fs_health_title), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
            Text(
                stringResource(healthStateRes(h.state)), color = tint, fontSize = 11.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(tint.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 3.dp),
            )
        }
        reasons.forEach { r ->
            val text = healthReasonText(r) ?: return@forEach
            Row(verticalAlignment = Alignment.Top) {
                Box(Modifier.padding(top = 6.dp).size(7.dp).clip(CircleShape).background(healthColor(r.level)))
                Spacer(Modifier.width(8.dp))
                Text(text, color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp, modifier = Modifier.weight(1f))
            }
        }
        if (step != null) {
            val days = stepReason?.data?.days
            val res = healthNextRes(step.code, step.mine, days != null)
            if (res != null) {
                val part = stepReason?.data?.part?.takeIf { it.isNotBlank() } ?: stringResource(R.string.fs_health_part_fallback)
                val line = stringResource(res, part, if (days != null) healthDaysText(days) else "")
                val accent = if (step.mine) settingsAccent() else settingsMut()
                Row(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(accent.copy(alpha = 0.08f)).padding(10.dp),
                    verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    Icon(Icons.AutoMirrored.Rounded.ArrowForward, null, tint = accent, modifier = Modifier.size(16.dp))
                    Text(line, color = if (step.mine) settingsInk() else settingsMut(), fontSize = 13.sp, lineHeight = 19.sp, fontWeight = if (step.mine) FontWeight.Bold else FontWeight.Normal, modifier = Modifier.weight(1f))
                }
            }
        }
    }
}

/** "1 day", "2 days", "3-10 days" and the rest - Arabic counts them differently. */
@Composable
private fun healthDaysText(n: Int): String = stringResource(
    when {
        n == 1 -> R.string.fs_health_days_1
        n == 2 -> R.string.fs_health_days_2
        n in 3..10 -> R.string.fs_health_days_few
        else -> R.string.fs_health_days_many
    },
    n,
)
