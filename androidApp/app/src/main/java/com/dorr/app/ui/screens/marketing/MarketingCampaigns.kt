@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.dorr.app.ui.screens.marketing

import androidx.annotation.StringRes
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.Campaign
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Handshake
import androidx.compose.material.icons.rounded.LinkOff
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.RocketLaunch
import androidx.compose.material.icons.rounded.Sync
import androidx.compose.material.icons.rounded.Undo
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material.icons.rounded.Hub
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.MkAccountRequest
import com.dorr.app.network.MkCampaignDto
import com.dorr.app.network.MkCampaignMetricsDto
import com.dorr.app.network.MkCampaignVersionRequest
import com.dorr.app.network.MkContentDto
import com.dorr.app.network.MkDelegationDto
import com.dorr.app.network.MkDelegationRequest
import com.dorr.app.network.MkIntegrationDto
import com.dorr.app.network.MkIntegrationsDto
import com.dorr.app.network.MkIssueDto
import com.dorr.app.network.MkMemberDto
import com.dorr.app.network.MkNoteRequest
import com.dorr.app.network.MkProjectDto
import com.dorr.app.network.MkPublishRequest
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.calendar.CalDatePickerDialog
import com.dorr.app.ui.screens.chat.ChSheet
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaDivider
import com.dorr.app.ui.screens.wallet.WaEmpty
import com.dorr.app.ui.screens.wallet.WaIconWell
import com.dorr.app.ui.screens.wallet.WaNote
import com.dorr.app.ui.screens.wallet.WaSectionTitle
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.wallet.waRise
import com.dorr.app.ui.screens.watchearn.WeChip
import com.dorr.app.ui.screens.watchearn.WeConfirmDialog
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import com.google.gson.JsonPrimitive
import kotlinx.coroutines.launch
import java.time.LocalDate
import java.time.LocalTime
import java.util.UUID

private val OBJECTIVES = listOf("awareness", "traffic", "engagement", "leads", "sales")

@StringRes
private fun objectiveLabel(o: String): Int = when (o) {
    "awareness" -> R.string.mktg_obj_awareness
    "engagement" -> R.string.mktg_obj_engagement
    "leads" -> R.string.mktg_obj_leads
    "sales" -> R.string.mktg_obj_sales
    else -> R.string.mktg_obj_traffic
}

@StringRes
private fun platformLabel(p: String): Int = when (p) {
    "google" -> R.string.mktg_pf_google
    "tiktok" -> R.string.mktg_pf_tiktok
    "snapchat" -> R.string.mktg_pf_snapchat
    else -> R.string.mktg_pf_meta
}

private val CAMPAIGN_STATUS = mapOf(
    "DRAFT" to (R.string.mktg_cmp_DRAFT to Color(0xFF6B7280)),
    "VALIDATED" to (R.string.mktg_cmp_VALIDATED to Color(0xFF2563EB)),
    "AWAITING_APPROVAL" to (R.string.mktg_cmp_AWAITING_APPROVAL to Color(0xFFD97706)),
    "APPROVED" to (R.string.mktg_cmp_APPROVED to Color(0xFF16A34A)),
    "SUBMITTING" to (R.string.mktg_cmp_SUBMITTING to Color(0xFF2563EB)),
    "PUBLISHED" to (R.string.mktg_cmp_PUBLISHED to Color(0xFF16A34A)),
    "REJECTED" to (R.string.mktg_cmp_REJECTED to Color(0xFFDC2626)),
    "FAILED" to (R.string.mktg_cmp_FAILED to Color(0xFFDC2626)),
    "UNKNOWN" to (R.string.mktg_cmp_UNKNOWN to Color(0xFFD97706)),
)

@Composable
private fun CampaignStatus(status: String) {
    val (label, color) = CAMPAIGN_STATUS[status] ?: (R.string.mktg_cmp_DRAFT to Color(0xFF6B7280))
    MkBadge(stringResource(label), color)
}

@StringRes
private fun issueLabel(code: String): Int = when (code) {
    "platform_off" -> R.string.mktg_issue_platform_off
    "integration_expired" -> R.string.mktg_issue_integration_expired
    "currency_mismatch" -> R.string.mktg_issue_currency_mismatch
    "budget_too_low" -> R.string.mktg_issue_budget_too_low
    "start_in_past" -> R.string.mktg_issue_start_in_past
    "bad_dates" -> R.string.mktg_issue_bad_dates
    "no_audience" -> R.string.mktg_issue_no_audience
    "content_not_approved" -> R.string.mktg_issue_content_not_approved
    "content_has_blocking_issues" -> R.string.mktg_issue_content_blocking
    else -> R.string.mktg_issue_integration_not_connected
}

// =============================================================================== ad platforms (U10)

/**
 * Connecting ad accounts: Meta now (Google, TikTok and Snapchat shown as coming). The owner connects; the platform's
 * own page opens in the browser, and the screen reloads when the server says the connection changed.
 */
@Composable
fun MarketingPlatforms(projectId: String) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val uri = LocalUriHandler.current
    val generic = stringResource(R.string.mktg_error_generic)
    val reconnect = collectReconnectTick()
    var project by remember { mutableStateOf<MkProjectDto?>(null) }
    var data by remember { mutableStateOf<MkIntegrationsDto?>(null) }
    var reload by remember { mutableIntStateOf(0) }
    var busy by remember { mutableStateOf<String?>(null) }
    var disconnecting by remember { mutableStateOf<MkIntegrationDto?>(null) }
    val relevantChange = if (host.changedProject == null || host.changedProject == projectId) host.changes else -1
    val connected = stringResource(R.string.mktg_platform_connected)
    val continueText = stringResource(R.string.mktg_platform_continue)

    LaunchedEffect(projectId, reload, reconnect, relevantChange) {
        runCatching {
            if (project == null) project = ApiClient.marketing.project(mkAuth(), projectId).data
            data = ApiClient.marketing.integrations(mkAuth(), projectId).data
        }.onFailure { host.fail(it, generic) }
    }
    val owner = project?.abilities?.manageTeam == true

    WaPage2(title = stringResource(R.string.mktg_platforms)) {
        WaNote(stringResource(R.string.mktg_platforms_note))
        val d = data
        if (d == null) {
            Spacer(Modifier.height(12.dp))
            repeat(2) { WaSkeleton(Modifier.fillMaxWidth().height(90.dp).padding(bottom = 12.dp), RoundedCornerShape(22.dp)) }
            return@WaPage2
        }
        Spacer(Modifier.height(12.dp))
        Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
            d.platforms.forEachIndexed { i, pf ->
                val integration = d.integrations.firstOrNull { it.provider == pf.provider && it.status != "REVOKED" }
                WaCard(Modifier.fillMaxWidth().waRise(i), padding = 16.dp) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        WaIconWell(Icons.Rounded.Hub, if (pf.available) Tone.Blue else Tone.Gray, size = 42.dp, iconSize = 21.dp)
                        Spacer(Modifier.width(12.dp))
                        Column(Modifier.weight(1f)) {
                            Text(stringResource(platformLabel(pf.provider)), color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                            val sub = when {
                                pf.comingSoon -> stringResource(R.string.mktg_coming_soon)
                                !pf.available -> stringResource(R.string.mktg_platform_off_here)
                                !pf.configured -> stringResource(R.string.mktg_platform_not_ready)
                                integration?.account != null -> integration.account.name ?: integration.account.id
                                else -> stringResource(R.string.mktg_platform_not_connected)
                            }
                            Text(sub, color = Wa.Mut, fontSize = 12.5.sp)
                        }
                        when {
                            integration?.status == "CONNECTED" -> MkBadge(stringResource(R.string.mktg_connected), Wa.Green)
                            integration?.reconnectNeeded == true -> MkBadge(stringResource(R.string.mktg_reconnect_needed), Wa.Danger)
                            integration?.status == "PENDING" && integration.availableAccounts != null -> MkBadge(stringResource(R.string.mktg_choose_account), MkAmber)
                        }
                    }
                    if (integration?.sandbox == true) {
                        Spacer(Modifier.height(8.dp))
                        MkSmallTag(stringResource(R.string.mktg_sandbox), MkAmber)
                    }
                    // OAuth found several ad accounts: the owner picks one.
                    val choices = integration?.availableAccounts
                    if (integration != null && integration.status == "PENDING" && choices != null && owner) {
                        Spacer(Modifier.height(10.dp))
                        if (choices.isEmpty()) {
                            Text(stringResource(R.string.mktg_no_ad_accounts), color = Wa.Danger, fontSize = 12.5.sp)
                        } else {
                            Text(stringResource(R.string.mktg_choose_account), color = Wa.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
                            Spacer(Modifier.height(6.dp))
                            choices.forEach { acc ->
                                WaButton(
                                    (acc.name ?: acc.id) + (acc.currency?.let { " · $it" } ?: ""),
                                    {
                                        busy = acc.id
                                        scope.launch {
                                            runCatching { ApiClient.marketing.chooseAdAccount(mkAuth(), integration.id, MkAccountRequest(acc.id)) }
                                                .onSuccess { host.showToast(connected); reload++ }
                                                .onFailure { host.fail(it, generic) }
                                            busy = null
                                        }
                                    },
                                    style = WaButtonStyle.Ghost, compact = true, loading = busy == acc.id, modifier = Modifier.padding(bottom = 6.dp),
                                )
                            }
                        }
                    }
                    if (owner && pf.available && pf.configured && !pf.comingSoon) {
                        Spacer(Modifier.height(12.dp))
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            if (integration?.status != "CONNECTED") {
                                WaButton(
                                    stringResource(if (integration?.reconnectNeeded == true) R.string.mktg_reconnect else R.string.mktg_connect),
                                    {
                                        busy = pf.provider
                                        scope.launch {
                                            runCatching { ApiClient.marketing.connectPlatform(mkAuth(), projectId, pf.provider).data }
                                                .onSuccess { r ->
                                                    val url = r?.authorizeUrl
                                                    if (url != null) { host.showToast(continueText); runCatching { uri.openUri(url) } } else { host.showToast(connected) }
                                                    reload++
                                                }
                                                .onFailure { host.fail(it, generic) }
                                            busy = null
                                        }
                                    },
                                    compact = true, loading = busy == pf.provider, modifier = Modifier.weight(1f),
                                )
                            }
                            if (integration != null && integration.status != "REVOKED") {
                                WaButton(stringResource(R.string.mktg_disconnect), { disconnecting = integration }, style = WaButtonStyle.Quiet, icon = Icons.Rounded.LinkOff, compact = true, modifier = Modifier.weight(1f))
                            }
                        }
                    }
                }
            }
        }
        if (!owner) {
            Spacer(Modifier.height(10.dp))
            Text(stringResource(R.string.mktg_owner_connects), color = Wa.Mut, fontSize = 12.5.sp, modifier = Modifier.padding(horizontal = 4.dp))
        }
    }

    disconnecting?.let { i ->
        WeConfirmDialog(
            title = stringResource(R.string.mktg_disconnect), text = stringResource(R.string.mktg_disconnect_text), confirm = stringResource(R.string.mktg_disconnect), danger = true,
            onDismiss = { disconnecting = null },
            onConfirm = {
                disconnecting = null
                scope.launch { runCatching { ApiClient.marketing.disconnectPlatform(mkAuth(), i.id) }.onSuccess { reload++ }.onFailure { host.fail(it, generic) } }
            },
        )
    }
}

// =============================================================================== campaigns (U09) + delegations (D7)

@Composable
fun MarketingCampaigns(projectId: String) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    val reconnect = collectReconnectTick()
    var project by remember { mutableStateOf<MkProjectDto?>(null) }
    var campaigns by remember { mutableStateOf<List<MkCampaignDto>?>(null) }
    var delegations by remember { mutableStateOf<List<MkDelegationDto>>(emptyList()) }
    var reload by remember { mutableIntStateOf(0) }
    var creating by remember { mutableStateOf(false) }
    var delegating by remember { mutableStateOf(false) }
    var revoking by remember { mutableStateOf<MkDelegationDto?>(null) }
    val relevantChange = if (host.changedProject == null || host.changedProject == projectId) host.changes else -1

    LaunchedEffect(projectId, reload, reconnect, relevantChange) {
        runCatching {
            if (project == null) project = ApiClient.marketing.project(mkAuth(), projectId).data
            campaigns = ApiClient.marketing.campaigns(mkAuth(), projectId).data.orEmpty()
            delegations = ApiClient.marketing.delegations(mkAuth(), projectId).data.orEmpty()
        }.onFailure { host.fail(it, generic) }
    }
    val abilities = project?.abilities
    val currency = project?.currency.orEmpty()

    WaPage2(title = stringResource(R.string.mktg_campaigns)) {
        WaNote(stringResource(R.string.mktg_campaigns_note))
        if (abilities?.edit == true) {
            Spacer(Modifier.height(12.dp))
            WaButton(stringResource(R.string.mktg_campaign_new), { creating = true }, icon = Icons.Rounded.Add, compact = true)
        }
        Spacer(Modifier.height(12.dp))
        val list = campaigns
        when {
            list == null -> repeat(2) { WaSkeleton(Modifier.fillMaxWidth().height(84.dp).padding(bottom = 12.dp), RoundedCornerShape(22.dp)) }
            list.isEmpty() -> WaEmpty(Icons.Rounded.Campaign, Tone.Gray, stringResource(R.string.mktg_campaigns_empty), stringResource(R.string.mktg_campaigns_empty_sub))
            else -> Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                list.forEachIndexed { i, c ->
                    MkNavCard(
                        Icons.Rounded.Campaign, Tone.Pink, c.name,
                        mkMoney(c.totalBudgetMinor, c.currency) + " · " + stringResource(objectiveLabel(c.objective)),
                        Modifier.waRise(i), trailing = { CampaignStatus(c.status) },
                    ) { host.push(MkRoute.Campaign(projectId, c.id)) }
                }
            }
        }

        // Delegations (owner sets; everyone sees).
        WaSectionTitle(stringResource(R.string.mktg_delegations), trailing = {
            if (abilities?.manageTeam == true) {
                Text(stringResource(R.string.mktg_add), color = Wa.Red, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.clickable { delegating = true }.padding(6.dp))
            }
        })
        Text(stringResource(R.string.mktg_delegations_note), color = Wa.Mut, fontSize = 12.5.sp, lineHeight = 18.sp, modifier = Modifier.padding(horizontal = 4.dp))
        Spacer(Modifier.height(10.dp))
        if (delegations.isEmpty()) {
            Text(stringResource(R.string.mktg_no_delegations), color = Wa.Soft, fontSize = 12.5.sp, modifier = Modifier.padding(horizontal = 4.dp))
        } else {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                delegations.forEach { d ->
                    WaCard(Modifier.fillMaxWidth(), padding = 14.dp) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            WaIconWell(Icons.Rounded.Handshake, Tone.Amber, size = 38.dp, iconSize = 19.dp)
                            Spacer(Modifier.width(10.dp))
                            Column(Modifier.weight(1f)) {
                                Text(d.name ?: "—", color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                                Text(stringResource(R.string.mktg_until, mkDate(d.expiresAt)), color = Wa.Mut, fontSize = 12.sp)
                            }
                            MkBadge(stringResource(if (d.status == "active") R.string.mktg_active else if (d.status == "expired") R.string.mktg_expired else R.string.mktg_revoked), if (d.status == "active") Wa.Green else Wa.Mut)
                        }
                        Spacer(Modifier.height(10.dp))
                        MkBar(if (d.capMinor > 0) d.usedMinor.toFloat() / d.capMinor else 0f, MkAmber)
                        Spacer(Modifier.height(6.dp))
                        Text(stringResource(R.string.mktg_delegation_left, mkMoney(d.remainingMinor, d.currency ?: currency), mkMoney(d.capMinor, d.currency ?: currency)), color = Wa.Mut, fontSize = 12.sp)
                        if (abilities?.manageTeam == true && d.status == "active") {
                            Spacer(Modifier.height(8.dp))
                            WaButton(stringResource(R.string.mktg_revoke), { revoking = d }, style = WaButtonStyle.Quiet, icon = Icons.Rounded.Lock, compact = true)
                        }
                    }
                }
            }
        }
    }

    if (creating) {
        CampaignSheet(projectId, null, project?.currency.orEmpty(), onDismiss = { creating = false }) { c -> creating = false; reload++; host.push(MkRoute.Campaign(projectId, c.id)) }
    }
    if (delegating) {
        DelegationSheet(projectId, currency, onDismiss = { delegating = false }) { delegating = false; reload++ }
    }
    revoking?.let { d ->
        WeConfirmDialog(
            title = stringResource(R.string.mktg_revoke), text = stringResource(R.string.mktg_revoke_text), confirm = stringResource(R.string.mktg_revoke), danger = true,
            onDismiss = { revoking = null },
            onConfirm = {
                revoking = null
                scope.launch { runCatching { ApiClient.marketing.revokeDelegation(mkAuth(), d.id) }.onSuccess { reload++ }.onFailure { host.fail(it, generic) } }
            },
        )
    }
}

/**
 * One campaign and its steps: validate → ask for approval → approve (owner / delegated manager) → publish (one
 * Idempotency-Key per attempt, reused on retry) → created PAUSED on the platform. An unknown result is checked,
 * never re-sent blindly.
 */
@Composable
fun MarketingCampaignPage(projectId: String, campaignId: String) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    val reconnect = collectReconnectTick()
    var project by remember { mutableStateOf<MkProjectDto?>(null) }
    var campaign by remember { mutableStateOf<MkCampaignDto?>(null) }
    var metrics by remember { mutableStateOf<MkCampaignMetricsDto?>(null) }
    var issues by remember { mutableStateOf<List<MkIssueDto>>(emptyList()) }
    var busy by remember { mutableStateOf<String?>(null) }
    var editing by remember { mutableStateOf(false) }
    var confirmPublish by remember { mutableStateOf(false) }
    var returning by remember { mutableStateOf(false) }
    var deleting by remember { mutableStateOf(false) }
    var reload by remember { mutableIntStateOf(0) }
    // One key per publish attempt of one version: a retry after a lost answer reuses it (AT-14).
    val keys = remember { mutableStateMapOf<Int, String>() }
    val relevantChange = if (host.changedProject == null || host.changedProject == projectId) host.changes else -1

    LaunchedEffect(campaignId, reload, reconnect, relevantChange) {
        runCatching {
            if (project == null) project = ApiClient.marketing.project(mkAuth(), projectId).data
            val c = ApiClient.marketing.campaign(mkAuth(), campaignId).data
            campaign = c
            if (c != null && c.validation.isNotEmpty()) issues = c.validation
            if (c?.status == "PUBLISHED") metrics = ApiClient.marketing.campaignMetrics(mkAuth(), campaignId).data
        }.onFailure { host.fail(it, generic) }
    }

    fun act(key: String, call: suspend () -> MkCampaignDto?) {
        busy = key
        scope.launch {
            runCatching { call() }
                .onSuccess { c -> if (c != null) campaign = c }
                .onFailure { e ->
                    val f = host.fail(e, generic)
                    if (f.errorCode == "marketing_campaign_invalid") {
                        issues = f.data?.get("issues")?.takeIf { it.isJsonArray }?.asJsonArray?.mapNotNull { el ->
                            el.takeIf { it.isJsonObject }?.asJsonObject?.let { o -> MkIssueDto(o.get("code")?.asString ?: "", o.get("detail")?.takeIf { !it.isJsonNull }?.asString) }
                        }.orEmpty()
                    }
                    reload++
                }
            busy = null
        }
    }

    val c = campaign
    val abilities = project?.abilities
    val canEdit = abilities?.edit == true
    val savedText = stringResource(R.string.mktg_saved)
    val publishedText = stringResource(R.string.mktg_published_paused)
    val unknownText = stringResource(R.string.mktg_unknown_note)
    val refusedText = stringResource(R.string.mktg_publish_refused)

    WaPage2(title = c?.name ?: stringResource(R.string.mktg_campaigns)) {
        if (c == null) {
            repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(96.dp).padding(bottom = 12.dp), RoundedCornerShape(22.dp)) }
            return@WaPage2
        }
        WaCard(Modifier.fillMaxWidth().waRise(0), padding = 16.dp) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(c.name, color = Wa.Ink, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
                    Text(stringResource(platformLabel(c.provider)) + " · " + stringResource(objectiveLabel(c.objective)) + " · v" + c.version, color = Wa.Mut, fontSize = 12.5.sp)
                }
                CampaignStatus(c.status)
            }
            Spacer(Modifier.height(14.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(14.dp)) {
                Fact(stringResource(R.string.mktg_daily_budget), mkMoney(c.dailyBudgetMinor, c.currency), Modifier.weight(1f))
                Fact(stringResource(R.string.mktg_total_budget), mkMoney(c.totalBudgetMinor, c.currency), Modifier.weight(1f))
            }
            Spacer(Modifier.height(10.dp))
            Text(mkDate(c.startAt) + " → " + mkDate(c.endAt), color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold)
            val countries = c.targeting?.getAsJsonArray("countries")?.mapNotNull { it.asString }.orEmpty()
            val ages = listOfNotNull(c.targeting?.get("age_min")?.takeIf { !it.isJsonNull }?.asInt, c.targeting?.get("age_max")?.takeIf { !it.isJsonNull }?.asInt)
            Text(countries.joinToString(", ") + if (ages.size == 2) " · ${ages[0]}–${ages[1]}" else "", color = Wa.Mut, fontSize = 12.5.sp)
        }

        c.approval?.let { a ->
            Spacer(Modifier.height(10.dp))
            Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(horizontal = 4.dp)) {
                Icon(Icons.Rounded.Verified, null, tint = Wa.Green, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(6.dp))
                Text(
                    stringResource(if (a.byDelegation) R.string.mktg_approved_by_delegation else R.string.mktg_approved_cap, mkMoney(a.capMinor, c.currency), mkDate(a.expiresAt)),
                    color = Wa.Green, fontSize = 12.5.sp, fontWeight = FontWeight.Bold,
                )
            }
        }
        if (!c.lastError.isNullOrBlank() && c.status in setOf("FAILED", "REJECTED", "UNKNOWN", "APPROVED", "PUBLISHED")) {
            Spacer(Modifier.height(10.dp))
            WaNote(when (c.lastError) {
                "integration_expired" -> stringResource(R.string.mktg_issue_integration_expired)
                "platform_unreachable" -> stringResource(R.string.mktg_platform_no_answer)
                else -> c.lastError
            })
        }
        if (issues.isNotEmpty() && c.status == "DRAFT") {
            WaSectionTitle(stringResource(R.string.mktg_fix_before_approval))
            WaCard(Modifier.fillMaxWidth(), padding = 14.dp) {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    issues.forEach { issue ->
                        Row {
                            Icon(Icons.Rounded.WarningAmber, null, tint = Wa.Danger, modifier = Modifier.size(18.dp))
                            Spacer(Modifier.width(8.dp))
                            Text(stringResource(issueLabel(issue.code), issue.detail.orEmpty()), color = Wa.Ink, fontSize = 13.sp, lineHeight = 19.sp)
                        }
                    }
                }
            }
        }

        Spacer(Modifier.height(16.dp))
        Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
            when (c.status) {
                "DRAFT", "REJECTED", "FAILED" -> if (canEdit) {
                    WaButton(stringResource(R.string.mktg_check_campaign), { act("validate") { ApiClient.marketing.validateCampaign(mkAuth(), c.id).data.also { issues = emptyList() } } }, icon = Icons.Rounded.CheckCircle, loading = busy == "validate")
                }
                "VALIDATED" -> if (canEdit) {
                    WaButton(stringResource(R.string.mktg_ask_approval), { act("request") { ApiClient.marketing.requestCampaignApproval(mkAuth(), c.id).data } }, icon = Icons.AutoMirrored.Rounded.Send, loading = busy == "request")
                }
                "AWAITING_APPROVAL" -> {
                    if (abilities?.approve == true) {
                        WaButton(stringResource(R.string.mktg_approve_spend, mkMoney(c.totalBudgetMinor, c.currency)), { act("approve") { ApiClient.marketing.approveCampaign(mkAuth(), c.id, MkCampaignVersionRequest(c.version)).data } }, icon = Icons.Rounded.Verified, loading = busy == "approve")
                        WaButton(stringResource(R.string.mktg_return), { returning = true }, style = WaButtonStyle.Ghost, icon = Icons.Rounded.Undo)
                        Text(stringResource(R.string.mktg_spend_who), color = Wa.Soft, fontSize = 11.5.sp, lineHeight = 16.sp)
                    } else {
                        WaNote(stringResource(R.string.mktg_waiting_spend_approval))
                    }
                }
                "APPROVED" -> if (canEdit) {
                    WaButton(stringResource(R.string.mktg_publish), { confirmPublish = true }, icon = Icons.Rounded.RocketLaunch, loading = busy == "publish")
                    Text(stringResource(R.string.mktg_publish_paused_note), color = Wa.Mut, fontSize = 12.sp, lineHeight = 17.sp)
                }
                "SUBMITTING" -> WaNote(stringResource(R.string.mktg_publishing))
                "UNKNOWN" -> {
                    WaNote(stringResource(R.string.mktg_unknown_note))
                    if (canEdit) WaButton(stringResource(R.string.mktg_check_status), { act("reconcile") { ApiClient.marketing.reconcileCampaign(mkAuth(), c.id).data } }, icon = Icons.Rounded.Refresh, loading = busy == "reconcile")
                }
                "PUBLISHED" -> {
                    WaCard(Modifier.fillMaxWidth(), padding = 14.dp) {
                        Text(stringResource(R.string.mktg_on_platform, c.platformStatus ?: "PAUSED"), color = Wa.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.Bold)
                        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                            Text(c.platformRef.orEmpty(), color = Wa.Soft, fontSize = 12.sp)
                        }
                        Spacer(Modifier.height(6.dp))
                        Text(stringResource(R.string.mktg_start_on_platform), color = Wa.Mut, fontSize = 12.5.sp, lineHeight = 18.sp)
                    }
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        WaButton(stringResource(R.string.mktg_check_status), { act("reconcile") { ApiClient.marketing.reconcileCampaign(mkAuth(), c.id).data } }, style = WaButtonStyle.Ghost, icon = Icons.Rounded.Refresh, compact = true, loading = busy == "reconcile", modifier = Modifier.weight(1f))
                        if (abilities?.results == true) {
                            WaButton(
                                stringResource(R.string.mktg_sync_results),
                                {
                                    busy = "sync"
                                    scope.launch {
                                        runCatching { ApiClient.marketing.syncCampaignMetrics(mkAuth(), c.id) }
                                            .onSuccess { host.showToast(savedText); reload++ }
                                            .onFailure { host.fail(it, generic) }
                                        busy = null
                                    }
                                },
                                style = WaButtonStyle.Ghost, icon = Icons.Rounded.Sync, compact = true, loading = busy == "sync", modifier = Modifier.weight(1f),
                            )
                        }
                    }
                    metrics?.let { m ->
                        if (m.rows.isNotEmpty()) {
                            WaSectionTitle(stringResource(R.string.mktg_platform_results), trailing = { MkSmallTag(stringResource(R.string.mktg_basis_actual), Wa.Green) })
                            WaCard(Modifier.fillMaxWidth(), padding = 6.dp) {
                                m.rows.take(14).forEachIndexed { i, r ->
                                    if (i > 0) WaDivider()
                                    Row(Modifier.fillMaxWidth().padding(10.dp)) {
                                        Text(r.date, color = Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                                        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                                            Text(mkMoney(r.spendMinor, "") + " · " + (r.clicks ?: 0) + " " + stringResource(R.string.mktg_k_clicks), color = Wa.Mut, fontSize = 12.sp)
                                        }
                                    }
                                }
                            }
                            m.syncedAt?.let { Text(stringResource(R.string.mktg_updated, mkDateTime(it)), color = Wa.Soft, fontSize = 11.5.sp, modifier = Modifier.padding(4.dp)) }
                        }
                    }
                }
            }
            if (canEdit && c.status !in setOf("SUBMITTING", "PUBLISHED", "UNKNOWN")) {
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    WaButton(stringResource(R.string.mktg_edit), { editing = true }, style = WaButtonStyle.Quiet, icon = Icons.Rounded.Edit, compact = true, modifier = Modifier.weight(1f))
                    WaButton(stringResource(R.string.mktg_delete), { deleting = true }, style = WaButtonStyle.Quiet, icon = Icons.Rounded.Delete, compact = true, modifier = Modifier.weight(1f))
                }
                if (c.approval != null) Text(stringResource(R.string.mktg_edit_drops_approval), color = Wa.Soft, fontSize = 11.5.sp, lineHeight = 16.sp)
            }
        }
    }

    val current = campaign
    if (editing && current != null) {
        CampaignSheet(projectId, current, current.currency, onDismiss = { editing = false }) { updated -> editing = false; campaign = updated; issues = emptyList(); host.showToast(savedText) }
    }
    if (returning && current != null) {
        NoteSheet(stringResource(R.string.mktg_return), onDismiss = { returning = false }) { note ->
            returning = false
            act("reject") { ApiClient.marketing.rejectCampaign(mkAuth(), current.id, MkNoteRequest(note.ifBlank { null })).data }
        }
    }
    if (confirmPublish && current != null && current.approval != null) {
        WeConfirmDialog(
            title = stringResource(R.string.mktg_publish),
            text = stringResource(R.string.mktg_publish_confirm, mkMoney(current.totalBudgetMinor, current.currency), stringResource(platformLabel(current.provider))),
            confirm = stringResource(R.string.mktg_publish),
            onDismiss = { confirmPublish = false },
            onConfirm = {
                confirmPublish = false
                val key = keys.getOrPut(current.version) { "mk-" + UUID.randomUUID().toString() }
                busy = "publish"
                scope.launch {
                    runCatching {
                        ApiClient.marketing.publishCampaign(mkAuth(), key, current.id, MkPublishRequest(current.approval.id, current.version, current.totalBudgetMinor, current.currency)).data
                    }.onSuccess { r ->
                        r?.campaign?.let { campaign = it }
                        // A finished attempt (success, refusal, unknown) frees the key; only a lost request keeps it for the retry.
                        keys.remove(current.version)
                        when (r?.action?.status) {
                            "SUCCEEDED" -> host.showToast(publishedText)
                            "UNKNOWN" -> host.showToast(unknownText, error = true)
                            else -> host.showToast(refusedText, error = true)
                        }
                    }.onFailure { host.fail(it, generic); reload++ }
                    busy = null
                }
            },
        )
    }
    if (deleting && current != null) {
        WeConfirmDialog(
            title = stringResource(R.string.mktg_delete), text = current.name, confirm = stringResource(R.string.mktg_delete), danger = true,
            onDismiss = { deleting = false },
            onConfirm = {
                deleting = false
                scope.launch { runCatching { ApiClient.marketing.deleteCampaign(mkAuth(), current.id) }.onSuccess { host.changed(projectId); host.pop() }.onFailure { host.fail(it, generic) } }
            },
        )
    }
}

@Composable
private fun Fact(label: String, value: String, modifier: Modifier = Modifier) {
    Column(modifier) {
        Text(label, color = Wa.Mut, fontSize = 11.5.sp)
        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
            Text(value, color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.Black)
        }
    }
}

/** New campaign, or a change (a new version). */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun CampaignSheet(projectId: String, existing: MkCampaignDto?, currency: String, onDismiss: () -> Unit, onSaved: (MkCampaignDto) -> Unit) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    var name by remember { mutableStateOf(existing?.name.orEmpty()) }
    var objective by remember { mutableStateOf(existing?.objective ?: "traffic") }
    var integration by remember { mutableStateOf(existing?.integrationId) }
    var content by remember { mutableStateOf(existing?.contentId) }
    var daily by remember { mutableStateOf(existing?.let { mkMoney(it.dailyBudgetMinor, "").replace(",", "") }.orEmpty()) }
    var start by remember { mutableStateOf(mkLocal(existing?.startAt)?.toLocalDate() ?: LocalDate.now().plusDays(1)) }
    var end by remember { mutableStateOf(mkLocal(existing?.endAt)?.toLocalDate() ?: LocalDate.now().plusDays(8)) }
    val defaultCountry = host.status?.country?.code.orEmpty()
    var countries by remember { mutableStateOf(existing?.targeting?.getAsJsonArray("countries")?.joinToString(",") { it.asString } ?: defaultCountry) }
    var ageMin by remember { mutableStateOf(existing?.targeting?.get("age_min")?.takeIf { !it.isJsonNull }?.asInt?.toString() ?: "18") }
    var ageMax by remember { mutableStateOf(existing?.targeting?.get("age_max")?.takeIf { !it.isJsonNull }?.asInt?.toString() ?: "55") }
    var accounts by remember { mutableStateOf<List<MkIntegrationDto>>(emptyList()) }
    var approvedAds by remember { mutableStateOf<List<MkContentDto>>(emptyList()) }
    var picking by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }

    LaunchedEffect(projectId) {
        runCatching {
            accounts = ApiClient.marketing.integrations(mkAuth(), projectId).data?.integrations.orEmpty().filter { it.status == "CONNECTED" }
            approvedAds = ApiClient.marketing.contentList(mkAuth(), projectId, status = "APPROVED,SCHEDULED,PUBLISHED", kind = "ad").data.orEmpty()
            if (integration == null) integration = accounts.firstOrNull()?.id
        }
    }
    val dailyMinor = mkParseMinor(daily)
    val days = (java.time.temporal.ChronoUnit.DAYS.between(start, end)).coerceAtLeast(0)
    val codes = countries.split(',', ' ', '،').map { it.trim().uppercase() }.filter { it.length == 2 }

    ChSheet(onDismissRequest = onDismiss, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).imePadding().navigationBarsPadding().padding(horizontal = 20.dp).padding(bottom = 20.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(stringResource(if (existing == null) R.string.mktg_campaign_new else R.string.mktg_edit), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            DorrTextField(value = name, onValueChange = { name = it.take(120) }, label = stringResource(R.string.mktg_campaign_name))
            Text(stringResource(R.string.mktg_objective), color = Wa.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OBJECTIVES.forEach { o -> WeChip(stringResource(objectiveLabel(o)), selected = objective == o, onClick = { objective = o }) }
            }
            Text(stringResource(R.string.mktg_ad_account), color = Wa.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
            if (accounts.isEmpty()) {
                Text(stringResource(R.string.mktg_no_connected_account), color = MkAmber, fontSize = 12.5.sp)
            } else {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    accounts.forEach { a -> WeChip(a.account?.name ?: a.account?.id ?: a.provider, selected = integration == a.id, onClick = { integration = a.id }) }
                }
            }
            DorrTextField(
                value = daily, onValueChange = { daily = it.take(12) }, label = stringResource(R.string.mktg_daily_budget), placeholder = "0.00",
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                trailing = { Text(currency, color = Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold) },
                error = daily.isNotBlank() && dailyMinor == null,
            )
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                DorrTextField(value = mkDate(mkIso(start, LocalTime.of(9, 0))), onValueChange = {}, label = stringResource(R.string.mktg_from), icon = Icons.Rounded.CalendarMonth, onClick = { picking = "start" }, modifier = Modifier.weight(1f))
                DorrTextField(value = mkDate(mkIso(end, LocalTime.of(23, 0))), onValueChange = {}, label = stringResource(R.string.mktg_to), icon = Icons.Rounded.CalendarMonth, onClick = { picking = "end" }, modifier = Modifier.weight(1f))
            }
            if (dailyMinor != null && days > 0) {
                Text(stringResource(R.string.mktg_total_will_be, mkMoney(dailyMinor * days, currency), days.toInt()), color = Wa.Red, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
            }
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                DorrTextField(value = countries, onValueChange = { countries = it.uppercase().take(80) }, label = stringResource(R.string.mktg_countries), placeholder = "SA, AE")
            }
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                DorrTextField(value = ageMin, onValueChange = { ageMin = it.filter(Char::isDigit).take(2) }, label = stringResource(R.string.mktg_age_from), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.weight(1f))
                DorrTextField(value = ageMax, onValueChange = { ageMax = it.filter(Char::isDigit).take(2) }, label = stringResource(R.string.mktg_age_to), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.weight(1f))
            }
            if (approvedAds.isNotEmpty()) {
                Text(stringResource(R.string.mktg_link_ad), color = Wa.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    WeChip(stringResource(R.string.mktg_none), selected = content == null, onClick = { content = null })
                    approvedAds.forEach { a -> WeChip(a.title.take(28), selected = content == a.id, onClick = { content = a.id }) }
                }
            }
            if (existing?.approval != null) WaNote(stringResource(R.string.mktg_edit_drops_approval))
            val ageOk = (ageMin.toIntOrNull() ?: 18) in 13..65 && (ageMax.toIntOrNull() ?: 65) in 13..65 && (ageMin.toIntOrNull() ?: 0) <= (ageMax.toIntOrNull() ?: 65)
            WaButton(
                stringResource(R.string.mktg_save),
                onClick = {
                    busy = true
                    scope.launch {
                        val body = JsonObject().apply {
                            if (existing != null) addProperty("version", existing.version)
                            addProperty("name", name.trim())
                            addProperty("objective", objective)
                            // Gson drops JSON nulls; an empty string reaches the server as null (ConvertEmptyStringsToNull) and clears the link.
                            addProperty("integration", integration ?: "")
                            addProperty("content", content ?: "")
                            addProperty("daily_budget_minor", dailyMinor ?: 0)
                            addProperty("start_at", mkIso(start, if (start == LocalDate.now()) LocalTime.now().plusMinutes(30) else LocalTime.of(9, 0)))
                            addProperty("end_at", mkIso(end, LocalTime.of(23, 0)))
                            add("targeting", JsonObject().apply {
                                add("countries", JsonArray().apply { codes.forEach { add(JsonPrimitive(it)) } })
                                ageMin.toIntOrNull()?.let { addProperty("age_min", it) }
                                ageMax.toIntOrNull()?.let { addProperty("age_max", it) }
                            })
                        }
                        runCatching {
                            if (existing == null) ApiClient.marketing.createCampaign(mkAuth(), projectId, body).data else ApiClient.marketing.updateCampaign(mkAuth(), existing.id, body).data
                        }.onSuccess { if (it != null) onSaved(it) }.onFailure { host.fail(it, generic) }
                        busy = false
                    }
                },
                enabled = name.trim().length >= 2 && dailyMinor != null && dailyMinor > 0 && days > 0 && ageOk, loading = busy,
            )
        }
    }
    when (picking) {
        "start" -> CalDatePickerDialog(start, onDismiss = { picking = null }) { picked -> start = if (picked.isBefore(LocalDate.now())) LocalDate.now() else picked; if (!end.isAfter(start)) end = start.plusDays(1); picking = null }
        "end" -> CalDatePickerDialog(end, onDismiss = { picking = null }) { picked -> end = if (!picked.isAfter(start)) start.plusDays(1) else picked; picking = null }
    }
}

@Composable
private fun DelegationSheet(projectId: String, currency: String, onDismiss: () -> Unit, onSaved: () -> Unit) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    var managers by remember { mutableStateOf<List<MkMemberDto>>(emptyList()) }
    var member by remember { mutableStateOf<Long?>(null) }
    var cap by remember { mutableStateOf("") }
    var until by remember { mutableStateOf(LocalDate.now().plusDays(30)) }
    var picking by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    LaunchedEffect(projectId) {
        runCatching { managers = ApiClient.marketing.members(mkAuth(), projectId).data.orEmpty().filter { it.role == "manager" } }
    }
    val capMinor = mkParseMinor(cap)
    ChSheet(onDismissRequest = onDismiss, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).imePadding().navigationBarsPadding().padding(horizontal = 20.dp).padding(bottom = 20.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(stringResource(R.string.mktg_delegation_new), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.mktg_delegations_note), color = Wa.Mut, fontSize = 12.5.sp, lineHeight = 18.sp)
            if (managers.isEmpty()) {
                Text(stringResource(R.string.mktg_no_managers), color = MkAmber, fontSize = 12.5.sp)
            } else {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    managers.forEach { m -> WeChip(m.name ?: m.phone.orEmpty(), selected = member == m.id, onClick = { member = m.id }) }
                }
            }
            DorrTextField(
                value = cap, onValueChange = { cap = it.take(14) }, label = stringResource(R.string.mktg_delegation_cap), placeholder = "0.00",
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                trailing = { Text(currency, color = Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold) }, error = cap.isNotBlank() && capMinor == null,
            )
            DorrTextField(value = mkDate(mkIso(until, LocalTime.of(23, 0))), onValueChange = {}, label = stringResource(R.string.mktg_valid_until), icon = Icons.Rounded.CalendarMonth, onClick = { picking = true })
            WaButton(
                stringResource(R.string.mktg_save),
                onClick = {
                    val id = member ?: return@WaButton
                    busy = true
                    scope.launch {
                        runCatching { ApiClient.marketing.createDelegation(mkAuth(), projectId, MkDelegationRequest(id, capMinor ?: 0, mkIso(until, LocalTime.of(23, 0)))) }
                            .onSuccess { onSaved() }.onFailure { host.fail(it, generic) }
                        busy = false
                    }
                },
                enabled = member != null && capMinor != null && capMinor >= 100, loading = busy,
            )
        }
    }
    if (picking) {
        CalDatePickerDialog(until, onDismiss = { picking = false }) { picked ->
            until = picked.coerceIn(LocalDate.now().plusDays(1), LocalDate.now().plusDays(90)); picking = false
        }
    }
}
