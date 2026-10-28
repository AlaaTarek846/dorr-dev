package com.dorr.app.ui.screens.freelance

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.StarBorder
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.FreelanceReviewDto
import com.dorr.app.network.FreelanceReviewRequest
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.profile.SettingsPrimaryButton
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut

private val Gold = Color(0xFFF59E0B)

@Composable
private fun criterionLabel(c: String): String = stringResource(
    when (c) {
        "quality" -> R.string.fl_rc_quality
        "timeliness" -> R.string.fl_rc_timeliness
        "communication" -> R.string.fl_rc_communication
        "professionalism" -> R.string.fl_rc_professionalism
        "clarity" -> R.string.fl_rc_clarity
        "responsiveness" -> R.string.fl_rc_responsiveness
        "payment" -> R.string.fl_rc_payment
        else -> R.string.fl_rc_conduct
    },
)

@Composable
private fun Stars(value: Int, size: Int = 26, onPick: ((Int) -> Unit)? = null) {
    Row(horizontalArrangement = Arrangement.spacedBy(2.dp)) {
        (1..5).forEach { n ->
            Icon(
                if (n <= value) Icons.Rounded.Star else Icons.Rounded.StarBorder, null, tint = Gold,
                modifier = Modifier.size(size.dp).then(if (onPick != null) Modifier.clickable { onPick(n) } else Modifier),
            )
        }
    }
}

/**
 * Rating the freelancer after the project (FL-701): one to five stars on each criterion and a comment. Theirs about me shows once both
 * rated, or when the rating days are over.
 */
@Composable
internal fun ReviewCard(review: FreelanceReviewDto, busy: Boolean, onSend: (FreelanceReviewRequest) -> Unit) {
    val scores = remember { mutableStateMapOf<String, Int>() }
    var comment by remember { mutableStateOf("") }
    Card {
        Label(stringResource(R.string.fl_rv_title))
        if (review.canRate) {
            Text(stringResource(R.string.fl_rv_note, "‎" + review.rateUntil?.take(10).orEmpty()), color = settingsMut(), fontSize = 12.sp)
            review.criteria.forEach { c ->
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text(criterionLabel(c), color = settingsInk(), fontSize = 13.sp, modifier = Modifier.weight(1f))
                    Stars(scores[c] ?: 0) { scores[c] = it }
                }
            }
            DorrTextField(comment, { comment = it.take(1000) }, label = stringResource(R.string.fl_rv_comment), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
            SettingsPrimaryButton(
                text = stringResource(R.string.fl_rv_send), loading = busy, enabled = review.criteria.all { (scores[it] ?: 0) > 0 },
                onClick = { onSend(FreelanceReviewRequest(scores.toMap(), comment.trim().ifBlank { null })) },
            )
        }
        review.mine?.let { r ->
            Text(stringResource(R.string.fl_rv_mine), color = settingsMut(), fontSize = 12.sp)
            Row(verticalAlignment = Alignment.CenterVertically) { Stars(Math.round(r.stars).toInt(), 18); Text("  ${r.stars}", color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold) }
            r.comment?.let { Text(it, color = settingsInk(), fontSize = 13.sp) }
        }
        when {
            review.theirs != null -> Column(Modifier.padding(top = 6.dp)) {
                Text(stringResource(R.string.fl_rv_theirs), color = settingsMut(), fontSize = 12.sp)
                Row(verticalAlignment = Alignment.CenterVertically) { Stars(Math.round(review.theirs.stars).toInt(), 18); Text("  ${review.theirs.stars}", color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold) }
                review.theirs.comment?.let { Text(it, color = settingsInk(), fontSize = 13.sp) }
            }
            review.theirsWaiting -> Text(stringResource(R.string.fl_rv_waiting), color = settingsMut(), fontSize = 12.sp)
            !review.canRate && review.mine == null -> Text(stringResource(R.string.fl_rv_closed), color = settingsMut(), fontSize = 12.sp)
        }
    }
}

/** A short line under the freelancer's name: the average and how many ratings (or «new»). */
@Composable
internal fun ReviewStars(stars: Double) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Stars(Math.round(stars).toInt(), 14)
        Text(" $stars", color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.width(36.dp))
    }
}
