package com.dorr.app.ui.screens.freelance

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.height
import androidx.compose.ui.draw.clip
import androidx.compose.foundation.background
import androidx.compose.foundation.shape.RoundedCornerShape
import com.dorr.app.ui.screens.profile.settingsSurface
import com.dorr.app.ui.screens.profile.settingsAccent
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material.icons.rounded.*
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
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.rememberCoroutineScope
import com.dorr.app.network.ApiClient
import com.dorr.app.network.FreelanceObjectionRequest
import com.dorr.app.network.FreelanceReviewDto
import com.dorr.app.network.FreelanceSurveyRequest
import com.dorr.app.network.FreelanceSurveyDto
import com.dorr.app.network.collectReconnectTick
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch
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
internal fun ReviewCard(review: FreelanceReviewDto, busy: Boolean, onObject: (FreelanceObjectionRequest) -> Unit, onSend: (FreelanceReviewRequest) -> Unit) {
    var objecting by remember { mutableStateOf(false) }
    val scores = remember { mutableStateMapOf<String, Int>() }
    var comment by remember { mutableStateOf("") }
    Card(Icons.Rounded.Star, stringResource(R.string.fl_rv_title)) {
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
                if (review.theirs.commentHidden == true) Text(stringResource(R.string.fl_ob_hidden), color = settingsMut(), fontSize = 12.sp)
                else review.theirs.comment?.let { Text(it, color = settingsInk(), fontSize = 13.sp) }
                review.objection?.let { o ->
                    Text(
                        stringResource(when (o.status) { "rejected" -> R.string.fl_ob_rejected; "comment_hidden" -> R.string.fl_ob_comment_hidden; "rating_removed" -> R.string.fl_ob_rating_removed; else -> R.string.fl_ob_open }),
                        color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold,
                    )
                    o.decisionNote?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
                }
                if (review.canObject == true) {
                    TextLink(stringResource(R.string.fl_ob_action)) { objecting = true }
                    Text(stringResource(R.string.fl_ob_until, "‎" + review.objectUntil?.take(10).orEmpty()), color = settingsMut(), fontSize = 11.sp)
                }
            }
            review.theirsWaiting -> Text(stringResource(R.string.fl_rv_waiting), color = settingsMut(), fontSize = 12.sp)
            !review.canRate && review.mine == null -> Text(stringResource(R.string.fl_rv_closed), color = settingsMut(), fontSize = 12.sp)
        }
        if (objecting) ObjectionDialog(review.objectionReasons.orEmpty(), busy, onSend = { objecting = false; onObject(it) }, onDismiss = { objecting = false })
    }
}

@Composable
private fun ObjectionDialog(reasons: List<String>, busy: Boolean, onSend: (FreelanceObjectionRequest) -> Unit, onDismiss: () -> Unit) {
    var code by remember { mutableStateOf<String?>(null) }
    var note by remember { mutableStateOf("") }
    FlSheet(Icons.Rounded.Gavel, stringResource(R.string.fl_ob_title), stringResource(R.string.fl_ob_hint), onDismiss = { if (!busy) onDismiss() }) {
        reasons.forEach { r -> Chip(stringResource(objectionReasonLabel(r)), code == r, Icons.Rounded.Flag) { code = r } }
        DorrTextField(note, { note = it.take(500) }, placeholder = stringResource(R.string.fl_ob_note), icon = Icons.Rounded.Notes, singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
        FlAction(stringResource(R.string.fl_ob_send), Icons.Rounded.Send, FlTone.Danger, enabled = code != null && note.trim().length >= 5, loading = busy) { onSend(FreelanceObjectionRequest(code.orEmpty(), note.trim())) }
        FlAction(stringResource(R.string.fl_not_now), null, onClick = onDismiss)
    }
}

private fun objectionReasonLabel(r: String): Int = when (r) {
    "abusive" -> R.string.fl_ob_r_abusive
    "false" -> R.string.fl_ob_r_false
    "unfair" -> R.string.fl_ob_r_unfair
    else -> R.string.fl_ob_r_other
}

private fun surveyReasonLabel(r: String): Int = when (r) {
    "quality" -> R.string.fl_sv_r_quality
    "late" -> R.string.fl_sv_r_late
    "communication" -> R.string.fl_sv_r_communication
    "price" -> R.string.fl_sv_r_price
    "scope" -> R.string.fl_sv_r_scope
    "payment" -> R.string.fl_sv_r_payment
    "platform" -> R.string.fl_sv_r_platform
    else -> R.string.fl_sv_r_other
}

/**
 * FL-702: a short survey once the project is over - how satisfied, and when not (3 stars or less) what went wrong. Shown only while it can
 * be answered, and afterwards as a thank-you. The other side never sees it. Read on open and again when the connection returns.
 */
@Composable
internal fun SurveyCard(projectUuid: String, refreshKey: String) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.fl_error_generic)
    var survey by remember { mutableStateOf<FreelanceSurveyDto?>(null) }
    var score by remember { mutableStateOf(0) }
    val picked = remember { mutableStateListOf<String>() }
    var comment by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(projectUuid, refreshKey, reconnect) {
        try {
            survey = ApiClient.freelance.survey(auth(), projectUuid).data
        } catch (e: CancellationException) {
            throw e
        } catch (_: Throwable) {
        }
    }
    val s = survey ?: return
    val mine = s.mine
    if (s.canAnswer != true && mine == null) return
    val max = s.notSatisfiedMax ?: 3
    Card(Icons.Rounded.Poll, stringResource(R.string.fl_sv_title)) {
        if (mine != null) {
            Row(verticalAlignment = Alignment.CenterVertically) { Stars(mine.score, 18); Text("  " + stringResource(R.string.fl_sv_thanks), color = settingsInk(), fontSize = 13.sp) }
        } else {
            Text(stringResource(R.string.fl_sv_hint, "‎" + s.answerUntil?.take(10).orEmpty()), color = settingsMut(), fontSize = 12.sp)
            Stars(score, 30) { score = it }
            if (score in 1..max) {
                Text(stringResource(R.string.fl_sv_why), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
                s.reasons.orEmpty().forEach { r -> Chip(stringResource(surveyReasonLabel(r)), r in picked) { if (r in picked) picked.remove(r) else picked.add(r) } }
            }
            if (score > 0) DorrTextField(comment, { comment = it.take(500) }, label = stringResource(R.string.fl_sv_comment), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
            error?.let { Text(it, color = Color(0xFFDC2626), fontSize = 12.sp) }
            SettingsPrimaryButton(
                text = stringResource(R.string.fl_sv_send), loading = busy, enabled = score > 0 && (score > max || picked.isNotEmpty()),
                onClick = {
                    busy = true
                    error = null
                    scope.launch {
                        try {
                            survey = ApiClient.freelance.answerSurvey(auth(), projectUuid, FreelanceSurveyRequest(score, if (score <= max) picked.toList() else emptyList(), comment.trim().ifBlank { null })).data
                        } catch (e: CancellationException) {
                            throw e
                        } catch (e: Exception) {
                            error = e.message(generic)
                        }
                        busy = false
                    }
                },
            )
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
