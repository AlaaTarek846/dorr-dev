package com.dorr.freelance.ui.screens

import androidx.activity.compose.BackHandler
import androidx.compose.animation.animateColorAsState
import androidx.compose.foundation.background
import androidx.compose.foundation.border
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
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.EmojiEvents
import androidx.compose.material.icons.rounded.Quiz
import androidx.compose.material.icons.rounded.Timer
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material3.Icon
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.SkillAttemptDto
import com.dorr.freelance.net.SkillResultDto
import com.dorr.freelance.net.SkillSubmitRequest
import com.dorr.freelance.net.SkillTestDto
import com.dorr.freelance.net.SkillTestsDto
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.EmptyState
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.Page
import com.dorr.freelance.ui.components.SkeletonDetail
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsSurface
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter

/** FL-L08: the test being taken, or the answer to it, on top of the list. Nothing here is kept after the screen is left but the attempt on the server. */
private sealed interface Stage {
    data object Listing : Stage
    data class Running(val attempt: SkillAttemptDto, val endsAtMs: Long) : Stage
    data class Done(val result: SkillResultDto, val name: String?) : Stage
}

private fun day(iso: String?): String = runCatching {
    OffsetDateTime.parse(iso).atZoneSameInstant(ZoneId.systemDefault()).format(DateTimeFormatter.ofPattern("d MMM yyyy"))
}.getOrDefault("")

private fun dayTime(iso: String?): String = runCatching {
    OffsetDateTime.parse(iso).atZoneSameInstant(ZoneId.systemDefault()).format(DateTimeFormatter.ofPattern("EEE d MMM, HH:mm"))
}.getOrDefault("")

/**
 * Skill tests and badges: pick a skill of mine that has a test, answer a timed multiple-choice test, and see the mark. The server draws,
 * times and marks — the countdown here is only the clock of this phone counting down to the end the server gave. The list is read when the
 * page opens, when the connection returns and when a notice arrives, never on a timer.
 */
@Composable
fun SkillTestsScreen(onBack: () -> Unit, onOpenSkills: () -> Unit) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var overview by remember { mutableStateOf<SkillTestsDto?>(null) }
    var failure by remember { mutableStateOf<String?>(null) }
    var stage by remember { mutableStateOf<Stage>(Stage.Listing) }
    var busySkill by remember { mutableIntStateOf(0) }
    var notice by remember { mutableStateOf<String?>(null) }

    suspend fun load() {
        when (val r = apiCall { ApiClient.skillTests.overview() }) {
            is ApiResult.Ok -> { overview = r.data; failure = null }
            else -> if (overview == null) failure = r.errorText(offlineText) ?: genericError
        }
    }
    LaunchedEffect(reconnect) { load() }
    LaunchedEffect(Unit) { Realtime.changed.collect { if (stage is Stage.Listing) load() } }

    fun start(test: SkillTestDto) {
        busySkill = test.skillId
        notice = null
        scope.launch {
            when (val r = apiCall { ApiClient.skillTests.start(test.skillId) }) {
                is ApiResult.Ok -> stage = Stage.Running(r.data, System.currentTimeMillis() + r.data.secondsLeft * 1000L)
                else -> { notice = r.errorText(offlineText) ?: genericError; load() }
            }
            busySkill = 0
        }
    }

    val current = stage
    // Leaving a running test keeps it on the server: opening it again resumes the same questions until the time is up.
    BackHandler(enabled = current !is Stage.Listing) { stage = Stage.Listing; scope.launch { load() } }

    Page(title = stringResource(R.string.skt_title), onBack = { if (current is Stage.Listing) onBack() else { stage = Stage.Listing; scope.launch { load() } } }) {
        when (current) {
            is Stage.Running -> TestRunner(current, onResult = { result, name -> stage = Stage.Done(result, name); scope.launch { load() } }, offlineText = offlineText, genericError = genericError, name = overview?.tests?.firstOrNull { it.skillId == current.attempt.skillId }?.name)
            is Stage.Done -> TestResult(current.result, current.name, onDone = { stage = Stage.Listing })
            Stage.Listing -> {
                val data = overview
                when {
                    data == null && failure != null -> EmptyState(Icons.Rounded.Quiz, stringResource(R.string.skt_title), failure.orEmpty(), Modifier.padding(top = 70.dp))
                    data == null -> SkeletonDetail()
                    else -> TestList(data, busySkill, onStart = ::start, onOpenSkills = onOpenSkills)
                }
                ErrorBanner(notice ?: if (data != null) failure else null, Modifier.fillMaxWidth())
            }
        }
    }
}

@Composable
private fun TestList(data: SkillTestsDto, busySkill: Int, onStart: (SkillTestDto) -> Unit, onOpenSkills: () -> Unit) {
    Hero(data)
    if (!data.enabled) {
        EmptyState(Icons.Rounded.Quiz, stringResource(R.string.skt_off_title), stringResource(R.string.skt_off_body), Modifier.padding(top = 8.dp))
        return
    }
    if (data.tests.isEmpty()) {
        EmptyState(Icons.Rounded.Quiz, stringResource(R.string.skt_none_title), stringResource(R.string.skt_none_body), Modifier.padding(top = 8.dp))
        Text(
            stringResource(R.string.skt_open_skills), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold,
            modifier = Modifier.clip(RoundedCornerShape(12.dp)).clickable(onClick = onOpenSkills).padding(12.dp),
        )
        return
    }
    data.tests.forEach { test -> TestRow(test, loading = busySkill == test.skillId, onStart = { onStart(test) }) }
}

/** What I hold: a trophy, how many badges, and a chip for each. */
@Composable
private fun Hero(data: SkillTestsDto) {
    val accent = settingsAccent()
    Column(
        Modifier.fillMaxWidth().widthIn(max = 520.dp).clip(RoundedCornerShape(24.dp))
            .background(androidx.compose.ui.graphics.Brush.linearGradient(listOf(accent, accent.copy(alpha = 0.72f)))).padding(20.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(14.dp)) {
            Box(Modifier.size(54.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.2f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.EmojiEvents, null, tint = Color.White, modifier = Modifier.size(30.dp))
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text(
                    if (data.badges.isEmpty()) stringResource(R.string.skt_hero_none) else stringResource(R.string.skt_hero_count, data.badges.size),
                    color = Color.White, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold,
                )
                Text(stringResource(R.string.skt_hero_sub), color = Color.White.copy(alpha = 0.9f), fontSize = 12.sp, lineHeight = 17.sp)
            }
        }
        if (data.badges.isNotEmpty()) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                data.badges.take(3).forEach { b ->
                    Row(
                        Modifier.clip(RoundedCornerShape(999.dp)).background(Color.White.copy(alpha = 0.2f)).padding(horizontal = 10.dp, vertical = 5.dp),
                        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp),
                    ) {
                        Icon(Icons.Rounded.Verified, null, tint = Color.White, modifier = Modifier.size(14.dp))
                        Text(b.name.orEmpty(), color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold, maxLines = 1)
                    }
                }
            }
        }
    }
}

@Composable
private fun TestRow(test: SkillTestDto, loading: Boolean, onStart: () -> Unit) {
    Column(
        Modifier.fillMaxWidth().widthIn(max = 520.dp).clip(RoundedCornerShape(20.dp)).settingsSurface(RoundedCornerShape(20.dp)).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Box(Modifier.size(42.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(if (test.badge != null) Icons.Rounded.Verified else Icons.Rounded.Quiz, null, tint = settingsAccent(), modifier = Modifier.size(22.dp))
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(test.name.orEmpty(), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                Text(stringResource(R.string.skt_meta, test.questions, test.minutes, test.passPercent), color = settingsMut(), fontSize = 12.sp)
            }
            when {
                test.badge != null -> SvcPill(stringResource(R.string.skt_badge_held, test.badge.scorePercent), serviceTone("active"))
                test.inProgress -> SvcPill(stringResource(R.string.skt_in_progress), serviceTone("awaiting"))
            }
        }
        when {
            test.badge != null -> Text(
                if (test.badge.expiresAt != null) stringResource(R.string.skt_badge_until, day(test.badge.expiresAt)) else stringResource(R.string.skt_badge_forever),
                color = settingsMut(), fontSize = 12.sp,
            )
            test.retakeAt != null -> Text(stringResource(R.string.skt_retake_at, dayTime(test.retakeAt)), color = settingsMut(), fontSize = 12.sp)
            else -> SettingsPrimaryButton(
                text = stringResource(if (test.inProgress) R.string.skt_resume else R.string.skt_start),
                loading = loading, enabled = test.canStart || test.inProgress, onClick = onStart,
            )
        }
    }
}

/** One question at a time with a countdown to the end the server gave; the last question sends the answers, and so does the end of the time. */
@Composable
private fun TestRunner(stage: Stage.Running, onResult: (SkillResultDto, String?) -> Unit, offlineText: String, genericError: String, name: String?) {
    val scope = rememberCoroutineScope()
    val questions = stage.attempt.questions
    val answers = remember { mutableStateMapOf<Int, Int>() }
    var index by remember { mutableIntStateOf(0) }
    var sending by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var left by remember { mutableLongStateOf(((stage.endsAtMs - System.currentTimeMillis()) / 1000).coerceAtLeast(0)) }

    fun send() {
        if (sending) return
        sending = true
        error = null
        scope.launch {
            val body = SkillSubmitRequest(answers.mapKeys { it.key.toString() })
            when (val r = apiCall { ApiClient.skillTests.submit(stage.attempt.attemptId, body) }) {
                is ApiResult.Ok -> onResult(r.data, name)
                else -> { error = r.errorText(offlineText) ?: genericError; sending = false }
            }
        }
    }

    // The phone's clock counting down to a time the server already gave — nothing is asked of the server meanwhile.
    LaunchedEffect(stage) {
        while (true) {
            left = ((stage.endsAtMs - System.currentTimeMillis()) / 1000).coerceAtLeast(0)
            if (left <= 0L) { send(); break }
            delay(1000)
        }
    }

    val q = questions.getOrNull(index) ?: return
    val lowTime = left in 1..59
    Row(Modifier.fillMaxWidth().widthIn(max = 520.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        Text(stringResource(R.string.skt_question_of, index + 1, questions.size), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
        val tone = if (lowTime) Color(0xFFDC2626) else settingsAccent()
        Row(
            Modifier.clip(RoundedCornerShape(999.dp)).background(tone.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 5.dp),
            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp),
        ) {
            Icon(Icons.Rounded.Timer, null, tint = tone, modifier = Modifier.size(16.dp))
            Text("%d:%02d".format(left / 60, left % 60), color = tone, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
        }
    }
    LinearProgressIndicator(
        progress = { (index + 1) / questions.size.toFloat() }, color = settingsAccent(), trackColor = settingsAccent().copy(alpha = 0.14f),
        modifier = Modifier.fillMaxWidth().widthIn(max = 520.dp).height(6.dp).clip(RoundedCornerShape(99.dp)),
    )
    Text(q.prompt, color = settingsInk(), fontSize = 17.sp, fontWeight = FontWeight.ExtraBold, lineHeight = 25.sp, modifier = Modifier.fillMaxWidth().widthIn(max = 520.dp).padding(top = 6.dp))
    q.options.forEachIndexed { i, text ->
        val picked = answers[q.id] == i
        val border by animateColorAsState(if (picked) settingsAccent() else settingsMut().copy(alpha = 0.25f), label = "optBorder")
        Row(
            Modifier.fillMaxWidth().widthIn(max = 520.dp).clip(RoundedCornerShape(16.dp))
                .background(if (picked) settingsAccent().copy(alpha = 0.10f) else Color.Transparent)
                .border(1.5.dp, border, RoundedCornerShape(16.dp))
                .clickable { answers[q.id] = i }.padding(14.dp),
            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Box(Modifier.size(22.dp).clip(CircleShape).border(2.dp, border, CircleShape), contentAlignment = Alignment.Center) {
                if (picked) Box(Modifier.size(12.dp).clip(CircleShape).background(settingsAccent()))
            }
            Text(text, color = settingsInk(), fontSize = 14.sp, modifier = Modifier.weight(1f))
        }
    }
    Spacer(Modifier.height(4.dp))
    ErrorBanner(error, Modifier.fillMaxWidth())
    val last = index == questions.lastIndex
    Row(Modifier.fillMaxWidth().widthIn(max = 520.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        if (index > 0) {
            Text(
                stringResource(R.string.skt_prev), color = settingsAccent(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold,
                modifier = Modifier.clip(RoundedCornerShape(16.dp)).border(1.5.dp, settingsAccent(), RoundedCornerShape(16.dp)).clickable { index -= 1 }.padding(horizontal = 22.dp, vertical = 15.dp),
            )
        }
        SettingsPrimaryButton(
            text = stringResource(if (last) R.string.skt_finish else R.string.skt_next), modifier = Modifier.weight(1f), loading = sending,
            onClick = { if (last) send() else index += 1 },
        )
    }
    if (last && answers.size < questions.size) {
        Text(stringResource(R.string.skt_unanswered, questions.size - answers.size), color = settingsMut(), fontSize = 12.sp)
    }
}

@Composable
private fun TestResult(result: SkillResultDto, name: String?, onDone: () -> Unit) {
    val ok = result.passed
    val tone = if (ok) Color(0xFF16A34A) else Color(0xFFD97706)
    Column(Modifier.fillMaxWidth().widthIn(max = 520.dp).padding(top = 16.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(12.dp)) {
        CoachRing(result.scorePercent, 132, track = tone.copy(alpha = 0.16f), bar = tone, ink = tone)
        Icon(if (ok) Icons.Rounded.CheckCircle else Icons.Rounded.Timer, null, tint = tone, modifier = Modifier.size(34.dp))
        Text(
            stringResource(when (result.status) { "passed" -> R.string.skt_passed; "expired" -> R.string.skt_expired; else -> R.string.skt_failed }),
            color = settingsInk(), fontSize = 20.sp, fontWeight = FontWeight.ExtraBold,
        )
        Text(stringResource(R.string.skt_score_line, result.correctCount, result.total, result.passPercent), color = settingsMut(), fontSize = 13.sp)
        when {
            ok -> Text(stringResource(R.string.skt_passed_body, name.orEmpty()), color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp)
            result.retakeAt != null -> Text(stringResource(R.string.skt_retake_at, dayTime(result.retakeAt)), color = settingsMut(), fontSize = 13.sp)
        }
        Spacer(Modifier.height(6.dp))
        SettingsPrimaryButton(text = stringResource(R.string.skt_done), onClick = onDone)
    }
}
