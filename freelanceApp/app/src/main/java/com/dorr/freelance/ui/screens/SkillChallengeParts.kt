package com.dorr.freelance.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowForward
import androidx.compose.material.icons.automirrored.rounded.OpenInNew
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.automirrored.rounded.Undo
import androidx.compose.material.icons.automirrored.rounded.Assignment
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.EmojiEvents
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.Link
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.RateReview
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.SkillChallengeDto
import com.dorr.freelance.net.SkillChallengeRequest
import com.dorr.freelance.net.SkillChallengeStateDto
import com.dorr.freelance.net.SkillTestDto
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.FormIntro
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsNight
import kotlinx.coroutines.launch
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter

/*
 * FS-602: the practical challenge of a skill — its card on the skill's row (the brief, where my challenge stands as a timeline, the
 * reviewer's note, when I may send again) and the form that sends one (a link to the work and a note).
 */

private const val NOTE_MIN = 20
private const val NOTE_MAX = 2000
private val HTTP_LINK = Regex("^https?://[^\\s/$.?#][^\\s]*$", RegexOption.IGNORE_CASE)

private fun challengeDay(iso: String?): String = runCatching {
    OffsetDateTime.parse(iso).atZoneSameInstant(ZoneId.systemDefault()).format(DateTimeFormatter.ofPattern("d MMM yyyy"))
}.getOrDefault("")

/** Whether a row has a challenge to show: one sent before, or a level I may ask for now. */
internal fun SkillChallengeStateDto.shown(): Boolean = current != null || (enabled && nextLevel != null)

/**
 * A skill's challenge on its row: the level it is for, the brief (before sending, and after a refusal), my challenge as a three-step
 * timeline with the reviewer's note, the date I may send again, and the buttons (send / withdraw / open my work).
 */
@Composable
internal fun SkillChallengeCard(state: SkillChallengeStateDto, withdrawing: Boolean, onSend: () -> Unit, onWithdraw: (SkillChallengeDto) -> Unit) {
    val current = state.current
    val target = current?.takeIf { it.status == "submitted" }?.targetLevel ?: state.nextLevel ?: current?.targetLevel
    val tint = skillLevelColor(target)
    val uri = LocalUriHandler.current
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(tint.copy(alpha = if (settingsNight()) 0.12f else 0.06f)).padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Box(Modifier.size(36.dp).clip(RoundedCornerShape(12.dp)).background(tint.copy(alpha = 0.16f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.EmojiEvents, null, tint = tint, modifier = Modifier.size(20.dp))
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(1.dp)) {
                Text(
                    if (target != null) stringResource(R.string.skl_ch_for, skillLevelLabel(target)) else stringResource(R.string.skl_ch_title),
                    color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold,
                )
                if (current == null) Text(stringResource(R.string.skl_ch_sub), color = settingsMut(), fontSize = 11.5.sp, lineHeight = 16.sp)
            }
        }
        if (current != null) {
            SkillChallengeTimeline(current)
            if (current.status == "accepted" && current.grantedLevel != null) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(stringResource(R.string.skl_ch_granted, skillLevelLabel(current.grantedLevel)), color = QualityGreen, fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                    SkillLevelPill(current.grantedLevel)
                }
            }
            current.decisionNote?.takeIf { it.isNotBlank() }?.let { note ->
                NoteBanner(Icons.Rounded.RateReview, note, if (current.status == "rejected") QualityRed else QualityGreen, title = stringResource(R.string.skl_ch_note_from))
            }
            current.link?.takeIf { it.isNotBlank() }?.let { link ->
                Row(
                    Modifier.clip(RoundedCornerShape(10.dp)).clickable { runCatching { uri.openUri(link) } }.padding(vertical = 4.dp, horizontal = 2.dp),
                    verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    Icon(Icons.AutoMirrored.Rounded.OpenInNew, null, tint = settingsAccent(), modifier = Modifier.size(16.dp))
                    Text(stringResource(R.string.skl_ch_my_link), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
                }
            }
        }
        val brief = state.brief?.takeIf { it.isNotBlank() }
        if (brief != null && state.nextLevel != null && (current == null || current.status != "submitted")) {
            NoteBanner(Icons.AutoMirrored.Rounded.Assignment, brief, settingsAccent(), title = stringResource(R.string.skl_ch_brief))
        }
        state.retryAt?.let { at ->
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Icon(Icons.Rounded.Schedule, null, tint = settingsMut(), modifier = Modifier.size(15.dp))
                Text(stringResource(R.string.skl_ch_retry, challengeDay(at)), color = settingsMut(), fontSize = 12.sp)
            }
        }
        if (current != null && current.canWithdraw) {
            ActionButton(stringResource(R.string.skl_ch_withdraw), Icons.AutoMirrored.Rounded.Undo, Tone.Danger, loading = withdrawing) { onWithdraw(current) }
        }
        if (state.canSend && state.nextLevel != null) {
            ActionButton(stringResource(R.string.skl_ch_send, skillLevelLabel(state.nextLevel)), Icons.AutoMirrored.Rounded.Send, Tone.Primary, onClick = onSend)
        }
    }
}

private class TimelineStep(val label: String, val sub: String?, val tint: Color, val icon: ImageVector, val reached: Boolean)

/** Sent → under review → the decision, side by side; the steps reached are filled in their colour, the line between them too. */
@Composable
private fun SkillChallengeTimeline(c: SkillChallengeDto) {
    val pending = settingsMut()
    val steps = listOf(
        TimelineStep(stringResource(R.string.skl_ch_step_sent), challengeDay(c.sentAt).ifBlank { null }, QualityGreen, Icons.Rounded.Check, true),
        if (c.status == "submitted") TimelineStep(stringResource(R.string.skl_ch_step_review), stringResource(R.string.skl_ch_submitted), QualityAmber, Icons.Rounded.HourglassTop, true)
        else TimelineStep(stringResource(R.string.skl_ch_step_review), null, QualityGreen, Icons.Rounded.Check, true),
        when (c.status) {
            "accepted" -> TimelineStep(stringResource(R.string.skl_ch_accepted), challengeDay(c.decidedAt).ifBlank { null }, QualityGreen, Icons.Rounded.Check, true)
            "rejected" -> TimelineStep(stringResource(R.string.skl_ch_rejected), challengeDay(c.decidedAt).ifBlank { null }, QualityRed, Icons.Rounded.Close, true)
            "withdrawn" -> TimelineStep(stringResource(R.string.skl_ch_withdrawn), challengeDay(c.decidedAt).ifBlank { null }, QualitySlate, Icons.AutoMirrored.Rounded.Undo, true)
            else -> TimelineStep(stringResource(R.string.skl_ch_step_decided), null, pending, Icons.Rounded.EmojiEvents, false)
        },
    )
    val track = settingsMut().copy(alpha = 0.2f)
    Row(Modifier.fillMaxWidth()) {
        steps.forEachIndexed { i, step ->
            Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally) {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.weight(1f).height(2.dp).background(if (i == 0) Color.Transparent else if (step.reached) steps[i - 1].tint else track))
                    Box(
                        Modifier.size(28.dp).clip(CircleShape).background(if (step.reached) step.tint else step.tint.copy(alpha = 0.14f)),
                        contentAlignment = Alignment.Center,
                    ) { Icon(step.icon, null, tint = if (step.reached) Color.White else step.tint, modifier = Modifier.size(15.dp)) }
                    Box(Modifier.weight(1f).height(2.dp).background(if (i == steps.lastIndex) Color.Transparent else if (steps[i + 1].reached) step.tint else track))
                }
                Spacer(Modifier.height(5.dp))
                Text(step.label, color = if (step.reached) settingsInk() else settingsMut(), fontSize = 11.5.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center, maxLines = 1)
                step.sub?.let { Text(it, color = settingsMut(), fontSize = 10.5.sp, textAlign = TextAlign.Center, maxLines = 1) }
            }
        }
    }
}

/**
 * The form that sends a challenge for the next level of [test]'s skill: where I stand → the level asked for, the brief, a link to the work
 * (http/https) and a note of 20..2000 characters with its count. [onSent] gets the line to show once the server took it.
 */
@Composable
internal fun SkillChallengeForm(test: SkillTestDto, onSent: (String) -> Unit) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val sentText = stringResource(R.string.skl_ch_sent)
    val target = test.challenge?.nextLevel ?: return
    var link by rememberSaveable(test.skillId) { mutableStateOf("") }
    var note by rememberSaveable(test.skillId) { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val linkOk = HTTP_LINK.matches(link.trim())
    val noteLength = note.trim().length
    val canSend = linkOk && noteLength in NOTE_MIN..NOTE_MAX && !busy

    fun send() {
        busy = true
        error = null
        scope.launch {
            when (val r = apiCall { ApiClient.skillTests.challenge(test.skillId, SkillChallengeRequest(target, link.trim(), note.trim())) }) {
                is ApiResult.Ok -> onSent(sentText)
                else -> error = r.errorText(offlineText) ?: genericError
            }
            busy = false
        }
    }

    Column(Modifier.fillMaxWidth().widthIn(max = 520.dp), verticalArrangement = Arrangement.spacedBy(16.dp)) {
        FormIntro(Icons.Rounded.EmojiEvents, stringResource(R.string.skl_ch_send, skillLevelLabel(target)), stringResource(R.string.skl_ch_form_intro, test.name.orEmpty()))
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            SkillLevelPill(test.badge?.level)
            Icon(Icons.AutoMirrored.Rounded.ArrowForward, null, tint = settingsMut(), modifier = Modifier.size(16.dp))
            SkillLevelPill(target)
        }
        test.challenge?.brief?.takeIf { it.isNotBlank() }?.let { NoteBanner(Icons.AutoMirrored.Rounded.Assignment, it, settingsAccent(), title = stringResource(R.string.skl_ch_brief)) }
        Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
            DorrTextField(
                link, { link = it.take(255) }, label = stringResource(R.string.skl_ch_link), icon = Icons.Rounded.Link, placeholder = "https://",
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri), error = link.isNotBlank() && !linkOk, modifier = Modifier.fillMaxWidth(),
                textStyle = MaterialTheme.typography.bodyLarge.copy(textDirection = TextDirection.Ltr),
            )
            Text(
                stringResource(if (link.isNotBlank() && !linkOk) R.string.skl_ch_bad_link else R.string.skl_ch_link_hint),
                color = if (link.isNotBlank() && !linkOk) QualityRed else settingsMut(), fontSize = 11.5.sp, lineHeight = 16.sp,
            )
        }
        Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
            DorrTextField(
                note, { note = it.take(NOTE_MAX) }, label = stringResource(R.string.skl_ch_note), placeholder = stringResource(R.string.skl_ch_note_ph),
                icon = Icons.Rounded.Notes, singleLine = false, minLines = 5, modifier = Modifier.fillMaxWidth(),
            )
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text(
                    stringResource(R.string.skl_ch_note_min, NOTE_MIN), color = if (noteLength in 1 until NOTE_MIN) QualityAmber else settingsMut(),
                    fontSize = 11.5.sp, modifier = Modifier.weight(1f),
                )
                Text("${note.length}/$NOTE_MAX", color = settingsMut(), fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold)
            }
        }
        error?.let { Text(it, color = QualityRed, fontSize = 12.sp) }
        SettingsPrimaryButton(text = stringResource(R.string.skl_ch_send, skillLevelLabel(target)), enabled = canSend, loading = busy, onClick = ::send)
    }
}
