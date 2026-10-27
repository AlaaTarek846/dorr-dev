package com.dorr.freelance.ui.screens

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.StarBorder
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
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
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.MyReviewsDto
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.ReviewDto
import com.dorr.freelance.net.ReviewRequest
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.ui.components.Card
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.FieldLabel
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.wallet.Wa
import com.dorr.freelance.ui.wallet.WaCard

private val Gold = Color(0xFFF59E0B)

@Composable
private fun criterion(c: String): String = stringResource(
    when (c) {
        "clarity" -> R.string.rv_c_clarity
        "responsiveness" -> R.string.rv_c_responsiveness
        "payment" -> R.string.rv_c_payment
        "conduct" -> R.string.rv_c_conduct
        "quality" -> R.string.rv_c_quality
        "timeliness" -> R.string.rv_c_timeliness
        "communication" -> R.string.rv_c_communication
        else -> R.string.rv_c_professionalism
    },
)

@Composable
private fun Stars(value: Int, size: Int = 26, onPick: ((Int) -> Unit)? = null) {
    Row(horizontalArrangement = Arrangement.spacedBy(2.dp)) {
        (1..5).forEach { n ->
            Icon(if (n <= value) Icons.Rounded.Star else Icons.Rounded.StarBorder, null, tint = Gold, modifier = Modifier.size(size.dp).then(if (onPick != null) Modifier.clickable { onPick(n) } else Modifier))
        }
    }
}

/** Rating the client after the project (FL-701); the client's rating of me shows once both rated, or when the time is over. */
@Composable
internal fun ReviewClientCard(review: ReviewDto, busy: Boolean, onSend: (ReviewRequest) -> Unit) {
    val scores = remember { mutableStateMapOf<String, Int>() }
    var comment by remember { mutableStateOf("") }
    Card {
        FieldLabel(stringResource(R.string.rv_title))
        if (review.canRate) {
            Text(stringResource(R.string.rv_note, "‎" + review.rateUntil?.take(10).orEmpty()), color = settingsMut(), fontSize = 12.sp)
            review.criteria.forEach { c ->
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text(criterion(c), color = settingsInk(), fontSize = 13.sp, modifier = Modifier.weight(1f))
                    Stars(scores[c] ?: 0) { scores[c] = it }
                }
            }
            DorrTextField(comment, { comment = it.take(1000) }, placeholder = stringResource(R.string.rv_comment), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
            SettingsPrimaryButton(text = stringResource(R.string.rv_send), loading = busy, enabled = review.criteria.all { (scores[it] ?: 0) > 0 }, onClick = {
                onSend(ReviewRequest(scores.toMap(), comment.trim().ifBlank { null }))
            })
        }
        review.mine?.let { r ->
            Text(stringResource(R.string.rv_mine), color = settingsMut(), fontSize = 12.sp)
            Stars(Math.round(r.stars).toInt(), 18)
        }
        when {
            review.theirs != null -> Column(Modifier.padding(top = 6.dp)) {
                Text(stringResource(R.string.rv_theirs), color = settingsMut(), fontSize = 12.sp)
                Row(verticalAlignment = Alignment.CenterVertically) { Stars(Math.round(review.theirs.stars).toInt(), 18); Text("  ${review.theirs.stars}", color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold) }
                review.theirs.comment?.let { Text(it, color = settingsInk(), fontSize = 13.sp) }
                review.theirs.scores.forEach { (c, v) -> Text("${criterion(c)}: $v/5", color = settingsMut(), fontSize = 11.sp) }
            }
            review.theirsWaiting -> Text(stringResource(R.string.rv_waiting), color = settingsMut(), fontSize = 12.sp)
            !review.canRate && review.mine == null -> Text(stringResource(R.string.rv_closed), color = settingsMut(), fontSize = 12.sp)
        }
    }
}

/** My numbers (FL-703): the average once there are three ratings, finished projects, on time, and what clients said. */
@Composable
internal fun MyRatingCard() {
    var data by remember { mutableStateOf<MyReviewsDto?>(null) }
    val reconnect = collectReconnectTick()
    suspend fun load() {
        (apiCall { ApiClient.agreements.reviews() } as? ApiResult.Ok)?.data?.let { data = it }
    }
    LaunchedEffect(reconnect) { load() }
    LaunchedEffect(Unit) { Realtime.changed.collect { load() } }
    val d = data ?: return
    if (d.reviewsCount == 0 && d.completedProjects == 0) return
    WaCard(Modifier.fillMaxWidth().padding(top = 12.dp), padding = 16.dp) {
        Text(stringResource(R.string.rv_my_title), color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
        Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(top = 6.dp)) {
            if (d.rating != null) { Stars(Math.round(d.rating).toInt(), 18); Text("  ${d.rating}", color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.Bold) }
            else Text(stringResource(R.string.rv_my_few, d.reviewsCount), color = Wa.Mut, fontSize = 12.sp)
        }
        Text(listOfNotNull(
            stringResource(R.string.rv_my_completed, d.completedProjects),
            d.onTimeRate?.let { stringResource(R.string.rv_my_on_time, it) },
            d.completionRate?.let { stringResource(R.string.rv_my_completion, it) },
        ).joinToString("  ·  "), color = Wa.Mut, fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp))
        d.latest.take(3).forEach { r ->
            Column(Modifier.padding(top = 8.dp)) {
                Stars(Math.round(r.stars).toInt(), 14)
                r.comment?.let { Text(it, color = Wa.Ink, fontSize = 13.sp) }
                r.project?.let { Text(it, color = Wa.Mut, fontSize = 11.sp) }
            }
        }
    }
}
