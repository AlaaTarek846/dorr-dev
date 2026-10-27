package com.dorr.app.ui.screens.aichat

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.AiWorldCreateRequest
import com.dorr.app.network.AiWorldLocationDto
import com.dorr.app.network.AiWorldPlanDto
import com.dorr.app.network.AiWorldPlanRequest
import com.dorr.app.network.AiWorldProfileDto
import com.dorr.app.network.AiWorldReportRequest
import com.dorr.app.network.AiWorldProjectDto
import com.dorr.app.network.AiWorldSceneInput
import com.dorr.app.network.ApiClient
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.chat.chatAuth
import com.dorr.app.ui.screens.wallet.PadResult
import com.dorr.app.ui.screens.wallet.walletAuth
import kotlinx.coroutines.launch
import java.time.Instant

private sealed interface WorldView {
    data object Home : WorldView
    data object Director : WorldView
    data object Photos : WorldView
    data class Plan(val plan: AiWorldPlanDto, val brief: String, val places: List<Int>, val profileId: Int?) : WorldView
    data class Detail(val id: Int) : WorldView
}

/** 1000 minor units on a 2-decimal currency = "10.00 EGP". */
internal fun worldMoney(minor: Long, currency: String?): String =
    "%d.%02d %s".format(minor / 100, minor % 100, currency.orEmpty()).trim()

private fun worldStatusLabel(status: String): Int = when (status) {
    "awaiting_confirmation" -> R.string.ai_world_st_awaiting
    "generating" -> R.string.ai_world_st_generating
    "review" -> R.string.ai_world_st_review
    "stitching" -> R.string.ai_world_st_stitching
    "completed" -> R.string.ai_world_st_completed
    "failed" -> R.string.ai_world_st_failed
    "cancelled" -> R.string.ai_world_st_cancelled
    "queued" -> R.string.ai_world_st_queued
    "approved" -> R.string.ai_world_st_approved
    else -> R.string.ai_world_st_draft
}

/**
 * DORR AI WORLD, Phase 3: the Director (idea + places -> proposed scenes and a price), the priced plan the
 * customer approves before paying, and "my trip videos" with confirm (wallet PIN) and cancel.
 */
@Composable
internal fun AiWorldScreen(night: Boolean, onExit: () -> Unit) {
    var view by remember { mutableStateOf<WorldView>(WorldView.Home) }

    BackHandler { if (view is WorldView.Home) onExit() else view = WorldView.Home }

    CompositionLocalProvider(LocalSiteNight provides night) {
        val c = sitePalette()
        Column(Modifier.fillMaxSize().background(c.bg)) {
            SiteTopBar(stringResource(R.string.ai_world_title), onBack = { if (view is WorldView.Home) onExit() else view = WorldView.Home })
            when (val v = view) {
                is WorldView.Home -> WorldHome(onNew = { view = WorldView.Director }, onPhotos = { view = WorldView.Photos }, onOpen = { view = WorldView.Detail(it) })
                is WorldView.Director -> WorldDirector(onPlan = { plan, brief, places, profile -> view = WorldView.Plan(plan, brief, places, profile) })
                is WorldView.Photos -> WorldPhotosScreen()
                is WorldView.Plan -> WorldPlan(v, onCreated = { view = WorldView.Detail(it) })
                is WorldView.Detail -> WorldDetail(night, v.id, onDeleted = { view = WorldView.Home })
            }
        }
    }
}

@Composable
private fun WorldHome(onNew: () -> Unit, onPhotos: () -> Unit, onOpen: (Int) -> Unit) {
    val c = sitePalette()
    var enabled by remember { mutableStateOf<Boolean?>(null) }
    var projects by remember { mutableStateOf<List<AiWorldProjectDto>?>(null) }

    LaunchedEffect(Unit) {
        enabled = runCatching { ApiClient.aiWorld.info(chatAuth()).data?.enabled == true }.getOrDefault(false)
        projects = runCatching { ApiClient.aiWorld.projects(chatAuth()).data.orEmpty() }.getOrDefault(emptyList())
    }

    if (enabled == null || projects == null) {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Ai.Red) }
        return
    }

    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        SiteCard {
            Text(stringResource(R.string.ai_world_hero_title), color = c.ink, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
            Text(stringResource(R.string.ai_world_hero_sub), color = c.mut, fontSize = 13.sp, lineHeight = 19.sp, modifier = Modifier.padding(top = 4.dp, bottom = 14.dp))
            if (enabled == true) {
                PrimaryButton(stringResource(R.string.ai_world_new), onClick = onNew)
                Spacer(Modifier.height(8.dp))
                SecondaryButton(stringResource(R.string.ai_world_photos_title), Modifier.fillMaxWidth(), onClick = onPhotos)
            }
            else Text(stringResource(R.string.ai_world_disabled), color = c.warn, fontWeight = FontWeight.SemiBold, fontSize = 13.sp)
        }
        projects.orEmpty().forEach { p ->
            SiteCard(onClick = { onOpen(p.id) }, padding = 14.dp) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(p.title?.takeIf { it.isNotBlank() } ?: stringResource(R.string.ai_world_untitled, p.id), color = c.ink, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp, maxLines = 1)
                        Text(stringResource(R.string.ai_world_seconds_total, p.totalSeconds), color = c.mut, fontSize = 12.sp)
                    }
                    Text(stringResource(worldStatusLabel(p.status)), color = Ai.Red, fontWeight = FontWeight.Bold, fontSize = 12.sp)
                }
            }
        }
    }
}

@Composable
private fun WorldDirector(onPlan: (AiWorldPlanDto, String, List<Int>, Int?) -> Unit) {
    val c = sitePalette()
    val scope = rememberCoroutineScope()
    var brief by remember { mutableStateOf("") }
    var query by remember { mutableStateOf("") }
    var results by remember { mutableStateOf<List<AiWorldLocationDto>>(emptyList()) }
    val picked = remember { mutableStateListOf<AiWorldLocationDto>() }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var profiles by remember { mutableStateOf<List<AiWorldProfileDto>>(emptyList()) }
    var profileId by remember { mutableStateOf<Int?>(null) }

    LaunchedEffect(Unit) {
        profiles = runCatching { ApiClient.aiWorld.profiles(chatAuth()).data.orEmpty().filter { it.status == "active" } }.getOrDefault(emptyList())
    }

    Column(Modifier.fillMaxSize().imePadding().verticalScroll(rememberScrollState()).padding(16.dp)) {
        SiteSectionTitle(stringResource(R.string.ai_world_step_idea), stringResource(R.string.ai_world_step_idea_sub))
        Spacer(Modifier.height(8.dp))
        SiteField(stringResource(R.string.ai_world_brief), brief, { brief = it }, singleLine = false, minLines = 4)

        Spacer(Modifier.height(10.dp))
        SiteSectionTitle(stringResource(R.string.ai_world_places), stringResource(R.string.ai_world_places_sub))
        SiteField(stringResource(R.string.ai_world_search), query, {
            query = it
            if (it.trim().length >= 2) scope.launch {
                results = runCatching { ApiClient.aiWorld.searchLocations(chatAuth(), it.trim()).data.orEmpty() }.getOrDefault(emptyList())
            } else results = emptyList()
        })
        results.filter { r -> picked.none { it.id == r.id } }.forEach { r ->
            SiteCard(Modifier.padding(vertical = 4.dp), padding = 12.dp, onClick = { if (picked.size < 5) { picked.add(r); results = emptyList(); query = "" } }) {
                Text(r.name, color = c.ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                Text(listOfNotNull(r.city, r.fidelity?.label).joinToString(" • "), color = c.mut, fontSize = 12.sp)
            }
        }
        picked.forEach { p ->
            Row(
                Modifier.fillMaxWidth().padding(vertical = 4.dp).clip(RoundedCornerShape(14.dp)).background(Ai.Red.copy(alpha = 0.1f)).clickable { picked.remove(p) }.padding(12.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Column(Modifier.weight(1f)) {
                    Text(p.name, color = c.ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                    p.fidelity?.let { Text(it.description, color = c.mut, fontSize = 11.5.sp) }
                }
                Text("✕", color = Ai.Red, fontWeight = FontWeight.Bold)
            }
        }

        if (profiles.isNotEmpty()) {
            Spacer(Modifier.height(10.dp))
            SiteSectionTitle(stringResource(R.string.ai_world_use_photos), stringResource(R.string.ai_world_use_photos_sub))
            profiles.forEach { p ->
                Row(
                    Modifier.fillMaxWidth().padding(vertical = 3.dp).clip(RoundedCornerShape(14.dp))
                        .background(if (profileId == p.id) Ai.Red.copy(alpha = 0.12f) else c.surface)
                        .clickable { profileId = if (profileId == p.id) null else p.id }.padding(12.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) { Text(p.label, color = c.ink, fontWeight = FontWeight.Bold, fontSize = 14.sp, modifier = Modifier.weight(1f)); if (profileId == p.id) Text("✓", color = Ai.Red, fontWeight = FontWeight.ExtraBold) }
            }
        }
        error?.let { Text(it, color = c.danger, fontSize = 13.sp, modifier = Modifier.padding(top = 8.dp)) }
        Spacer(Modifier.height(16.dp))
        if (busy) Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Ai.Red) }
        else PrimaryButton(stringResource(R.string.ai_world_make_plan), enabled = brief.trim().length >= 3) {
            error = null
            busy = true
            scope.launch {
                runCatching { ApiClient.aiWorld.plan(chatAuth(), AiWorldPlanRequest(brief.trim(), picked.map { it.id })).data }.fold(
                    onSuccess = { plan -> if (plan != null) onPlan(plan, brief.trim(), picked.map { it.id }, profileId) },
                    onFailure = { error = it.apiFailure().message },
                )
                busy = false
            }
        }
    }
}

@Composable
private fun WorldPlan(v: WorldView.Plan, onCreated: (Int) -> Unit) {
    val c = sitePalette()
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val plan = v.plan

    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        SiteSectionTitle(stringResource(R.string.ai_world_plan_title), stringResource(R.string.ai_world_plan_sub))
        plan.scenes.forEachIndexed { i, s ->
            SiteCard(padding = 14.dp) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(stringResource(R.string.ai_world_scene_n, i + 1), color = Ai.Red, fontWeight = FontWeight.ExtraBold, fontSize = 13.sp, modifier = Modifier.weight(1f))
                    Text(stringResource(R.string.ai_world_sec, s.seconds), color = c.mut, fontSize = 12.sp)
                }
                s.locationName?.let { Text(it, color = c.ink, fontWeight = FontWeight.Bold, fontSize = 14.sp, modifier = Modifier.padding(top = 4.dp)) }
                Text(s.prompt, color = c.mut, fontSize = 12.5.sp, lineHeight = 18.sp, modifier = Modifier.padding(top = 4.dp))
            }
        }
        SiteCard(padding = 14.dp) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(stringResource(R.string.ai_world_total, plan.totalSeconds), color = c.ink, fontWeight = FontWeight.Bold, fontSize = 14.sp, modifier = Modifier.weight(1f))
                Text(plan.estimateMinor?.let { worldMoney(it, plan.currency) } ?: stringResource(R.string.ai_world_no_price), color = Ai.Red, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp)
            }
            Text(stringResource(R.string.ai_world_no_charge_yet), color = c.mut, fontSize = 11.5.sp, modifier = Modifier.padding(top = 6.dp))
        }
        error?.let { Text(it, color = c.danger, fontSize = 13.sp) }
        if (busy) Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Ai.Red) }
        else PrimaryButton(stringResource(R.string.ai_world_create), enabled = plan.estimateMinor != null && plan.scenes.isNotEmpty()) {
            error = null
            busy = true
            scope.launch {
                val body = AiWorldCreateRequest(
                    brief = v.brief,
                    identityProfileId = v.profileId,
                    scenes = plan.scenes.map { AiWorldSceneInput(it.prompt, it.seconds, it.locationId) },
                )
                runCatching { ApiClient.aiWorld.create(chatAuth(), body).data }.fold(
                    onSuccess = { p -> if (p != null) onCreated(p.id) },
                    onFailure = { error = it.apiFailure().message },
                )
                busy = false
            }
        }
    }
}

@Composable
private fun WorldDetail(night: Boolean, id: Int, onDeleted: () -> Unit) {
    val c = sitePalette()
    val scope = rememberCoroutineScope()
    var project by remember { mutableStateOf<AiWorldProjectDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var askPin by remember { mutableStateOf(false) }
    var noPin by remember { mutableStateOf(false) }
    var reported by remember { mutableStateOf(false) }

    suspend fun reload() {
        runCatching { ApiClient.aiWorld.project(chatAuth(), id).data }.onSuccess { project = it }.onFailure { error = it.apiFailure().message }
    }

    LaunchedEffect(id) { reload() }
    // While videos are being made the status moves on the server; refresh until it settles.
    LaunchedEffect(project?.status) {
        while (project?.status == "generating" || project?.status == "stitching") {
            kotlinx.coroutines.delay(10_000)
            reload()
        }
    }

    val p = project
    if (p == null) {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Ai.Red) }
        return
    }

    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        SiteCard {
            Text(p.title?.takeIf { it.isNotBlank() } ?: stringResource(R.string.ai_world_untitled, p.id), color = c.ink, fontWeight = FontWeight.ExtraBold, fontSize = 17.sp)
            Text(stringResource(worldStatusLabel(p.status)), color = Ai.Red, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.padding(top = 4.dp))
            Spacer(Modifier.height(8.dp))
            Text(stringResource(R.string.ai_world_price_line, worldMoney(p.estimatedMinor, p.currency)), color = c.mut, fontSize = 12.5.sp)
            if (p.chargedMinor > 0) Text(stringResource(R.string.ai_world_charged_line, worldMoney(p.chargedMinor, p.currency)), color = c.mut, fontSize = 12.5.sp)
            if (p.refundedMinor > 0) Text(stringResource(R.string.ai_world_refunded_line, worldMoney(p.refundedMinor, p.currency)), color = c.success, fontSize = 12.5.sp)
        }
        p.scenes.forEach { s ->
            SiteCard(padding = 14.dp) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(stringResource(R.string.ai_world_scene_n, s.position), color = c.ink, fontWeight = FontWeight.Bold, fontSize = 14.sp, modifier = Modifier.weight(1f))
                    Text(stringResource(worldStatusLabel(s.status)), color = c.mut, fontSize = 12.sp)
                }
                Text(s.prompt, color = c.mut, fontSize = 12.sp, maxLines = 2, modifier = Modifier.padding(top = 4.dp))
                if (s.status == "review") {
                    Spacer(Modifier.height(8.dp))
                    SecondaryButton(stringResource(R.string.ai_world_approve), Modifier.fillMaxWidth()) {
                        scope.launch {
                            runCatching { ApiClient.aiWorld.approve(chatAuth(), p.id, s.id).data }.onSuccess { project = it }.onFailure { error = it.apiFailure().message }
                        }
                    }
                }
            }
        }
        error?.let { Text(it, color = c.danger, fontSize = 13.sp) }
        if (p.status == "awaiting_confirmation") {
            PrimaryButton(stringResource(R.string.ai_world_confirm, worldMoney(p.estimatedMinor, p.currency))) {
                error = null
                scope.launch {
                    val status = runCatching { ApiClient.wallet.pinStatus(walletAuth()).data }.getOrNull()
                    if (status?.hasPin == false) noPin = true else askPin = true
                }
            }
        }
        if (p.status in listOf("completed", "failed", "cancelled", "awaiting_confirmation")) {
            SecondaryButton(stringResource(R.string.ai_world_delete_video), Modifier.fillMaxWidth(), color = c.danger) {
                scope.launch {
                    runCatching { ApiClient.aiWorld.deleteProject(chatAuth(), p.id) }.onSuccess { onDeleted() }.onFailure { error = it.apiFailure().message }
                }
            }
        }
        if (p.status == "completed") {
            SecondaryButton(stringResource(R.string.ai_world_report), Modifier.fillMaxWidth(), color = c.mut) {
                scope.launch {
                    runCatching { ApiClient.aiWorld.report(chatAuth(), p.id, AiWorldReportRequest("complaint")) }
                        .onSuccess { error = null; reported = true }.onFailure { error = it.apiFailure().message }
                }
            }
            if (reported) Text(stringResource(R.string.ai_world_reported), color = c.success, fontSize = 12.5.sp)
        }
        if (p.status in listOf("awaiting_confirmation", "generating", "review")) {
            SecondaryButton(stringResource(R.string.ai_world_cancel), Modifier.fillMaxWidth(), color = c.danger) {
                scope.launch {
                    runCatching { ApiClient.aiWorld.cancel(chatAuth(), p.id).data }.onSuccess { project = it }.onFailure { error = it.apiFailure().message }
                }
            }
        }
    }

    if (noPin) AiInfoDialog(night = night, message = stringResource(R.string.ai_subscription_no_pin_set), onDismiss = { noPin = false })

    if (askPin) {
        AiWalletPinSheet(
            night = night,
            subtitle = stringResource(R.string.ai_world_pin_sub),
            onDismiss = { askPin = false },
            onSubmit = { pin ->
                runCatching { ApiClient.aiWorld.confirm(chatAuth(), pin, p.id).data }.fold(
                    onSuccess = { updated ->
                        project = updated
                        PadResult.Ok
                    },
                    onFailure = { e ->
                        val failure = e.apiFailure()
                        val until = failure.lockedUntil?.let { runCatching { Instant.parse(it).toEpochMilli() }.getOrNull() }
                        if (failure.errorCode == "wallet_pin_locked" && until != null) PadResult.Locked(until) else PadResult.Error(failure.message ?: "")
                    },
                )
            },
        )
    }
}
