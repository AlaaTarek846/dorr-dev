package com.dorr.app.ui.screens.marketing

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.OpenInNew
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.Insights
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.MkDiscoveryDto
import com.dorr.app.network.MkProjectDto
import com.dorr.app.network.MkQuestionDto
import com.dorr.app.network.MkRunDto
import com.dorr.app.network.MkSourceDto
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaDivider
import com.dorr.app.ui.screens.wallet.WaEmpty
import com.dorr.app.ui.screens.wallet.WaNote
import com.dorr.app.ui.screens.wallet.WaSectionTitle
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.wallet.waRise
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import com.google.gson.JsonPrimitive
import kotlinx.coroutines.launch

/**
 * Discovery (U02): only the questions that are missing are highlighted; the budget is never guessed — «I don't know
 * yet» clears it and it is asked again. Lists are one item per line. Only changed answers are sent.
 */
@Composable
fun MarketingDiscovery(projectId: String) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    val saved = stringResource(R.string.mktg_saved)
    var state by remember { mutableStateOf<MkDiscoveryDto?>(null) }
    var project by remember { mutableStateOf<MkProjectDto?>(null) }
    val drafts = remember { mutableStateMapOf<String, String>() }
    val cleared = remember { mutableStateMapOf<String, Boolean>() }
    val invalid = remember { mutableStateMapOf<String, Boolean>() }
    var saving by remember { mutableStateOf(false) }
    var suggesting by remember { mutableStateOf(false) }
    var reload by remember { mutableIntStateOf(0) }

    LaunchedEffect(projectId, reload) {
        runCatching {
            project = ApiClient.marketing.project(mkAuth(), projectId).data
            state = ApiClient.marketing.discovery(mkAuth(), projectId).data
        }.onFailure { host.fail(it, generic) }
    }
    val currency = project?.currency.orEmpty()
    val canEdit = project?.abilities?.edit == true

    fun body(): JsonObject? {
        val answers = JsonObject()
        val clear = JsonArray()
        invalid.clear()
        state?.questions.orEmpty().forEach { q ->
            if (cleared[q.key] == true) {
                clear.add(q.key)
                return@forEach
            }
            val text = drafts[q.key] ?: return@forEach
            when (q.type) {
                "list" -> {
                    val items = text.lines().map { it.trim() }.filter { it.isNotEmpty() }.distinct().take(20)
                    if (items.isEmpty()) clear.add(q.key) else answers.add(q.key, JsonArray().apply { items.forEach { add(it) } })
                }
                "money" -> if (text.isBlank()) clear.add(q.key) else {
                    val minor = mkParseMinor(text)
                    if (minor == null) invalid[q.key] = true else answers.add(q.key, JsonPrimitive(minor))
                }
                "number" -> if (text.isBlank()) clear.add(q.key) else {
                    val n = text.trim().toIntOrNull()
                    if (n == null || n < 1) invalid[q.key] = true else answers.add(q.key, JsonPrimitive(n))
                }
                else -> if (text.isBlank()) clear.add(q.key) else answers.add(q.key, JsonPrimitive(text.trim()))
            }
        }
        if (invalid.isNotEmpty()) return null
        return JsonObject().apply {
            add("answers", answers)
            add("clear", clear)
        }
    }

    val dirty = drafts.isNotEmpty() || cleared.isNotEmpty()
    WaPage2(
        title = stringResource(R.string.mktg_discovery),
        cta = if (canEdit) {
            {
                WaButton(
                    stringResource(R.string.mktg_save),
                    onClick = {
                        val payload = body() ?: return@WaButton
                        saving = true
                        scope.launch {
                            runCatching { ApiClient.marketing.answer(mkAuth(), projectId, payload).data }
                                .onSuccess {
                                    state = it
                                    drafts.clear()
                                    cleared.clear()
                                    host.showToast(saved)
                                }
                                .onFailure { host.fail(it, generic) }
                            saving = false
                        }
                    },
                    enabled = dirty, loading = saving,
                )
            }
        } else {
            null
        },
    ) {
        val s = state
        if (s == null) {
            repeat(4) { WaSkeleton(Modifier.fillMaxWidth().height(96.dp).padding(bottom = 12.dp), RoundedCornerShape(22.dp)) }
            return@WaPage2
        }
        WaCard(Modifier.fillMaxWidth(), padding = 16.dp) {
            Text(stringResource(R.string.mktg_discovery_sub, s.answered, s.total), color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(8.dp))
            MkBar(if (s.total == 0) 0f else s.answered.toFloat() / s.total, if (s.complete) Wa.Green else Wa.Red)
            Spacer(Modifier.height(8.dp))
            Text(stringResource(if (s.complete) R.string.mktg_discovery_done else R.string.mktg_discovery_missing), color = Wa.Mut, fontSize = 12.5.sp, lineHeight = 19.sp)
        }
        Spacer(Modifier.height(12.dp))
        Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
            s.questions.forEachIndexed { i, q ->
                QuestionCard(
                    q, currency, Modifier.waRise(i.coerceAtMost(8)),
                    text = drafts[q.key] ?: q.value.answerText(q.type, currency),
                    cleared = cleared[q.key] == true,
                    invalid = invalid[q.key] == true,
                    enabled = canEdit,
                    onText = { drafts[q.key] = it; cleared.remove(q.key); invalid.remove(q.key) },
                    onDontKnow = { cleared[q.key] = true; drafts.remove(q.key) },
                )
            }
        }
        if (canEdit) {
            Spacer(Modifier.height(14.dp))
            WaButton(
                stringResource(R.string.mktg_suggest),
                onClick = {
                    suggesting = true
                    scope.launch {
                        runCatching { ApiClient.marketing.suggestQuestions(mkAuth(), projectId).data }
                            .onSuccess { state = it }
                            .onFailure { host.fail(it, generic) }
                        suggesting = false
                    }
                },
                style = WaButtonStyle.Ghost, icon = Icons.Rounded.AutoAwesome, loading = suggesting,
            )
        }
    }
}

@Composable
private fun QuestionCard(
    q: MkQuestionDto,
    currency: String,
    modifier: Modifier,
    text: String,
    cleared: Boolean,
    invalid: Boolean,
    enabled: Boolean,
    onText: (String) -> Unit,
    onDontKnow: () -> Unit,
) {
    WaCard(modifier.fillMaxWidth(), padding = 14.dp) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(q.text, color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.Bold, lineHeight = 21.sp, modifier = Modifier.weight(1f))
            if (q.required && !q.answered) {
                Spacer(Modifier.width(6.dp))
                MkSmallTag(stringResource(R.string.mktg_required), Wa.Danger)
            } else if (q.origin == "ai") {
                Spacer(Modifier.width(6.dp))
                MkSmallTag(stringResource(R.string.mktg_from_ai), Wa.Red)
            }
        }
        Spacer(Modifier.height(10.dp))
        DorrTextField(
            value = if (cleared) "" else text,
            onValueChange = onText,
            placeholder = when (q.type) {
                "list" -> stringResource(R.string.mktg_one_per_line)
                "money" -> "0.00"
                "number" -> "1"
                else -> null
            },
            singleLine = q.type == "money" || q.type == "number",
            minLines = if (q.type == "list" || q.type == "text") 2 else 1,
            keyboardOptions = when (q.type) {
                "money" -> KeyboardOptions(keyboardType = KeyboardType.Decimal)
                "number" -> KeyboardOptions(keyboardType = KeyboardType.Number)
                else -> KeyboardOptions.Default
            },
            trailing = if (q.type == "money") ({ Text(currency, color = Wa.Mut, fontSize = 13.sp, fontWeight = FontWeight.Bold) }) else null,
            error = invalid,
            enabled = enabled,
        )
        if (invalid) Text(stringResource(R.string.mktg_answer_invalid), color = Wa.Danger, fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp))
        if (q.type == "money" && enabled && (q.answered || text.isNotBlank()) && !cleared) {
            Text(
                stringResource(R.string.mktg_dont_know), color = Wa.Red, fontSize = 12.5.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.padding(top = 8.dp).clickable(onClick = onDontKnow),
            )
        }
    }
}

// =============================================================================== one research run

/** A run (U03): its summary, evidence quality, what the sources don't cover, the findings with their sources. */
@Composable
fun MarketingResearchPage(runId: String) {
    val host = LocalMk.current
    val generic = stringResource(R.string.mktg_error_generic)
    val reconnect = collectReconnectTick()
    var run by remember { mutableStateOf<MkRunDto?>(null) }
    LaunchedEffect(runId, reconnect, host.changes) {
        runCatching { ApiClient.marketing.researchRun(mkAuth(), runId).data }
            .onSuccess { run = it }
            .onFailure { host.fail(it, generic) }
    }
    val r = run
    WaPage2(title = r?.let { stringResource(mkKindLabel(it.kind)) } ?: stringResource(R.string.mktg_research)) {
        if (r == null) {
            repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(110.dp).padding(bottom = 12.dp), RoundedCornerShape(22.dp)) }
            return@WaPage2
        }
        WaCard(Modifier.fillMaxWidth(), padding = 16.dp) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(mkDate(r.createdAt), color = Wa.Mut, fontSize = 12.5.sp, modifier = Modifier.weight(1f))
                MkStatusBadge(r.status)
            }
            if (!r.summary.isNullOrBlank()) {
                Spacer(Modifier.height(10.dp))
                Text(r.summary, color = Wa.Ink, fontSize = 14.sp, lineHeight = 22.sp)
            }
            val q = r.qualityScore
            if (q != null) {
                Spacer(Modifier.height(12.dp))
                Row {
                    Text(stringResource(R.string.mktg_quality), color = Wa.Mut, fontSize = 12.5.sp, modifier = Modifier.weight(1f))
                    Text("$q%", color = Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold)
                }
                Spacer(Modifier.height(6.dp))
                MkBar(q / 100f, if (q >= 70) Wa.Green else if (q >= 40) androidx.compose.ui.graphics.Color(0xFFD97706) else Wa.Danger)
            }
        }
        if (r.gaps.isNotEmpty()) {
            Spacer(Modifier.height(12.dp))
            WaNote(stringResource(R.string.mktg_gaps) + "\n" + r.gaps.joinToString("\n") { "• $it" })
        }

        val sources = r.sources.orEmpty()
        WaSectionTitle(stringResource(R.string.mktg_findings))
        val findings = r.findings.orEmpty()
        if (findings.isEmpty()) {
            if (r.finished) WaEmpty(Icons.Rounded.Insights, Tone.Gray, stringResource(R.string.mktg_findings), stringResource(R.string.mktg_no_findings))
        } else {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                findings.forEachIndexed { i, f ->
                    WaCard(Modifier.fillMaxWidth().waRise(i.coerceAtMost(6)), padding = 14.dp) {
                        Text(f.claim, color = Wa.Ink, fontSize = 13.5.sp, lineHeight = 21.sp)
                        Spacer(Modifier.height(8.dp))
                        Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                            if (f.isAssumption) MkSmallTag(stringResource(R.string.mktg_assumption), androidx.compose.ui.graphics.Color(0xFFD97706))
                            MkSmallTag(
                                stringResource(
                                    when (f.confidence) {
                                        "high" -> R.string.mktg_confidence_high
                                        "medium" -> R.string.mktg_confidence_medium
                                        else -> R.string.mktg_confidence_low
                                    },
                                ),
                                Wa.Mut,
                            )
                            f.sources.forEach { ref -> MkSmallTag(ref, Wa.Red) }
                        }
                    }
                }
            }
        }

        if (sources.isNotEmpty()) {
            WaSectionTitle(stringResource(R.string.mktg_sources))
            WaCard(Modifier.fillMaxWidth(), padding = 6.dp) {
                sources.forEachIndexed { i, s ->
                    SourceRow(s)
                    if (i < sources.lastIndex) WaDivider()
                }
            }
        }
    }
}

@Composable
private fun SourceRow(s: MkSourceDto) {
    val context = LocalContext.current
    Row(
        Modifier.fillMaxWidth().clickable { runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(s.url))) } }.padding(10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        MkSmallTag(s.ref, Wa.Red)
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(s.title ?: s.publisher ?: s.url, color = Wa.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.Bold, maxLines = 2, overflow = TextOverflow.Ellipsis)
            val meta = listOfNotNull(
                s.publisher,
                s.publishedAt?.let { stringResource(R.string.mktg_published, it) },
                s.retrievedAt?.let { stringResource(R.string.mktg_retrieved, mkDate(it)) },
            ).joinToString(" · ")
            Text(meta, color = Wa.Mut, fontSize = 11.5.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
        }
        Icon(Icons.AutoMirrored.Rounded.OpenInNew, stringResource(R.string.mktg_open_source), tint = Wa.Soft, modifier = Modifier.padding(start = 6.dp))
    }
}
