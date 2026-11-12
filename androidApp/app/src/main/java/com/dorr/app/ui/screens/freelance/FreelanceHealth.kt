package com.dorr.app.ui.screens.freelance

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.Block
import androidx.compose.material.icons.rounded.MonitorHeart
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Warning
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.FreelanceHealthDto
import com.dorr.app.network.FreelanceHealthReasonDto
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsSurface

/**
 * FS-106: how a running project is doing, in a small card at the top of the agreement — a coloured state, the reasons in plain words and
 * one next step (what I can do now, or what I am waiting for). Information only: nothing here moves money or counts against anyone. Drawn
 * from the agreement that is read once on open (and again when a health notice arrives); nothing is asked on a timer. Hidden when the
 * agreement is not running; a healthy project with nothing to say shows just «On track».
 */
@Composable
internal fun HealthCard(health: FreelanceHealthDto?) {
    if (health == null) return
    val color = healthColor(health.state)
    if (health.state == "healthy" && health.reasons.isEmpty()) {
        Row(
            Modifier.fillMaxWidth().padding(horizontal = 4.dp),
            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            Icon(Icons.Rounded.CheckCircle, null, tint = color, modifier = Modifier.size(16.dp))
            Text(stringResource(R.string.fs_hl_on_track), color = color, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
        }
        return
    }
    Column(
        Modifier.fillMaxWidth().settingsSurface(FlCorners.card).padding(14.dp).semantics(mergeDescendants = true) {},
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Icon(Icons.Rounded.MonitorHeart, null, tint = color, modifier = Modifier.size(20.dp))
            Text(stringResource(R.string.fs_hl_title), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
            Row(
                Modifier.clip(RoundedCornerShape(999.dp)).background(color.copy(alpha = 0.14f)).padding(horizontal = 10.dp, vertical = 3.dp),
                verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp),
            ) {
                Icon(healthIcon(health.state), null, tint = color, modifier = Modifier.size(13.dp))
                Text(healthLabel(health.state), color = color, fontSize = 11.5.sp, fontWeight = FontWeight.ExtraBold)
            }
        }
        health.reasons.forEach { r ->
            Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Icon(Icons.Rounded.ErrorOutline, null, tint = healthColor(r.level), modifier = Modifier.padding(top = 2.dp).size(14.dp))
                Text(healthReasonText(r), color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp, modifier = Modifier.weight(1f))
            }
        }
        health.nextStep?.let { step ->
            // The step carries no part name: it is the one of the reason with the same code.
            val part = health.reasons.firstOrNull { it.code == step.code }?.data?.part.orEmpty()
            FlNotice(healthStepText(step.code, step.mine, part), if (step.mine) Icons.Rounded.Schedule else Icons.Rounded.HourglassTop, if (step.mine) healthColor(health.state) else settingsMut())
        }
    }
}

internal fun healthColor(state: String): Color = when (state) {
    "at_risk" -> Color(0xFFF59E0B)
    "delayed" -> Color(0xFFD97706)
    "blocked" -> Color(0xFFDC2626)
    else -> Color(0xFF16A34A)
}

private fun healthIcon(state: String): ImageVector = when (state) {
    "at_risk" -> Icons.Rounded.Warning
    "delayed" -> Icons.Rounded.Schedule
    "blocked" -> Icons.Rounded.Block
    else -> Icons.Rounded.CheckCircle
}

@Composable
private fun healthLabel(state: String): String = stringResource(
    when (state) {
        "at_risk" -> R.string.fs_hl_state_at_risk
        "delayed" -> R.string.fs_hl_state_delayed
        "blocked" -> R.string.fs_hl_state_blocked
        else -> R.string.fs_hl_state_healthy
    },
)

/** The reason in plain words; a code the app does not know yet says nothing alarming. */
@Composable
private fun healthReasonText(r: FreelanceHealthReasonDto): String {
    val part = r.data?.part.orEmpty()
    val days = healthDaysText(r.data?.days ?: 1)
    return when (r.code) {
        "dispute_open" -> stringResource(R.string.fs_hl_r_dispute_open, part)
        "part_unfunded" -> stringResource(R.string.fs_hl_r_part_unfunded, part)
        "part_overdue" -> stringResource(R.string.fs_hl_r_part_overdue, part, days)
        "part_due_soon" -> stringResource(R.string.fs_hl_r_part_due_soon, part)
        "part_not_started" -> stringResource(R.string.fs_hl_r_part_not_started, part)
        "review_waiting" -> stringResource(R.string.fs_hl_r_review_waiting, part, days)
        "report_late" -> stringResource(R.string.fs_hl_r_report_late)
        "change_waiting" -> stringResource(R.string.fs_hl_r_change_waiting)
        "task_overdue" -> stringResource(R.string.fs_hl_r_task_overdue, part, days, r.data?.count ?: 1)
        else -> stringResource(R.string.fs_hl_r_other)
    }
}

/** [mine]: what I can do now; else what I am waiting for. */
@Composable
private fun healthStepText(code: String, mine: Boolean, part: String): String = when (code) {
    "dispute_open" -> stringResource(if (mine) R.string.fs_hl_n_dispute_open else R.string.fs_hl_w_dispute_open, part)
    "part_unfunded" -> stringResource(if (mine) R.string.fs_hl_n_part_unfunded else R.string.fs_hl_w_part_unfunded, part)
    "part_overdue" -> stringResource(if (mine) R.string.fs_hl_n_part_overdue else R.string.fs_hl_w_part_overdue, part)
    "part_due_soon" -> stringResource(if (mine) R.string.fs_hl_n_part_due_soon else R.string.fs_hl_w_part_due_soon, part)
    "part_not_started" -> stringResource(if (mine) R.string.fs_hl_n_part_not_started else R.string.fs_hl_w_part_not_started, part)
    "review_waiting" -> stringResource(if (mine) R.string.fs_hl_n_review_waiting else R.string.fs_hl_w_review_waiting, part)
    "report_late" -> stringResource(if (mine) R.string.fs_hl_n_report_late else R.string.fs_hl_w_report_late)
    "change_waiting" -> stringResource(if (mine) R.string.fs_hl_n_change_waiting else R.string.fs_hl_w_change_waiting)
    "task_overdue" -> stringResource(if (mine) R.string.fs_hl_n_task_overdue else R.string.fs_hl_w_task_overdue, part)
    else -> stringResource(if (mine) R.string.fs_hl_n_other else R.string.fs_hl_w_other)
}

/** "1 day", "2 days", "3-10 days" and the rest - Arabic counts them differently. */
@Composable
private fun healthDaysText(n: Int): String = stringResource(
    when {
        n == 1 -> R.string.fs_hl_days_1
        n == 2 -> R.string.fs_hl_days_2
        n in 3..10 -> R.string.fs_hl_days_few
        else -> R.string.fs_hl_days_many
    },
    n,
)
