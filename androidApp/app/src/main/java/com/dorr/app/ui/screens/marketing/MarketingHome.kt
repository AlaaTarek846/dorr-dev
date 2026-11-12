@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.dorr.app.ui.screens.marketing

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.Business
import androidx.compose.material.icons.rounded.Campaign
import androidx.compose.material.icons.rounded.LocationCity
import androidx.compose.material.icons.rounded.Public
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.WorkspacePremium
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.MkPackageDto
import com.dorr.app.network.MkProjectDto
import com.dorr.app.network.MkProjectRequest
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.chat.ChSheet
import com.dorr.app.ui.screens.wallet.CheckoutLauncher
import com.dorr.app.ui.screens.wallet.CheckoutRequest
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaEmpty
import com.dorr.app.ui.screens.wallet.WaIconWell
import com.dorr.app.ui.screens.wallet.WaNote
import com.dorr.app.ui.screens.wallet.WaPage
import com.dorr.app.ui.screens.wallet.WaSectionTitle
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.wallet.waRise
import com.dorr.app.ui.screens.watchearn.WeChip
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** The landing page: is it open here, my package, my projects (pages of 15, search), a new project. */
@Composable
fun MarketingHome() {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    val reconnect = collectReconnectTick()

    var statusError by remember { mutableStateOf(false) }
    LaunchedEffect(host.statusTick, reconnect) {
        statusError = false
        runCatching { ApiClient.marketing.status(mkAuth()).data }
            .onSuccess { host.status = it }
            .onFailure { if (host.status == null) statusError = true }
    }

    val projects = remember { mutableStateListOf<MkProjectDto>() }
    var page by remember { mutableIntStateOf(1) }
    var hasMore by remember { mutableStateOf(false) }
    var loaded by remember { mutableStateOf(false) }
    var loadingMore by remember { mutableStateOf(false) }
    var query by remember { mutableStateOf("") }
    var reload by remember { mutableIntStateOf(0) }
    var creating by remember { mutableStateOf(false) }

    val status = host.status
    val open = status?.available == true
    LaunchedEffect(open, query, reload, reconnect) {
        if (!open) return@LaunchedEffect
        if (query.isNotEmpty()) delay(350)
        runCatching { ApiClient.marketing.projects(mkAuth(), q = query.ifBlank { null }, page = 1) }
            .onSuccess { env ->
                projects.clear()
                projects.addAll(env.data.orEmpty())
                page = 1
                hasMore = env.pagination?.hasMorePages == true
            }
        loaded = true
    }

    WaPage(
        title = stringResource(R.string.mktg_title),
        onBack = { host.pop() },
        cta = if (open && status?.subscription?.active == true) {
            { WaButton(stringResource(R.string.mktg_new_project), { creating = true }, icon = Icons.Rounded.Add) }
        } else {
            null
        },
    ) {
        when {
            status == null && statusError -> WaEmpty(
                Icons.Rounded.Campaign, Tone.Gray, stringResource(R.string.mktg_error_generic), "",
                action = { WaButton(stringResource(R.string.mktg_retry), { host.reloadStatus() }, style = WaButtonStyle.Ghost, compact = true) },
            )
            status == null -> Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                WaSkeleton(Modifier.fillMaxWidth().height(120.dp), RoundedCornerShape(22.dp))
                repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(70.dp), RoundedCornerShape(22.dp)) }
            }
            !status.available -> WaEmpty(Icons.Rounded.Public, Tone.Blue, stringResource(R.string.mktg_unavailable_title), stringResource(R.string.mktg_unavailable_text))
            else -> {
                PackageCard()
                if (!status.researchReady) {
                    Spacer(Modifier.height(10.dp))
                    WaNote(stringResource(R.string.mktg_research_not_ready))
                }
                WaSectionTitle(stringResource(R.string.mktg_projects))
                DorrTextField(
                    value = query,
                    onValueChange = { query = it.take(100) },
                    placeholder = stringResource(R.string.mktg_search_projects),
                    icon = Icons.Rounded.Search,
                )
                Spacer(Modifier.height(12.dp))
                when {
                    !loaded -> Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(70.dp), RoundedCornerShape(22.dp)) }
                    }
                    projects.isEmpty() -> WaEmpty(Icons.Rounded.Business, Tone.Red, stringResource(R.string.mktg_no_projects_title), stringResource(R.string.mktg_no_projects_text))
                    else -> Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        projects.forEachIndexed { i, p ->
                            ProjectRow(p, Modifier.waRise(i.coerceAtMost(6))) { host.push(MkRoute.Project(p.id)) }
                        }
                        if (hasMore) {
                            WaButton(
                                stringResource(R.string.mktg_load_more),
                                onClick = {
                                    loadingMore = true
                                    scope.launch {
                                        runCatching { ApiClient.marketing.projects(mkAuth(), q = query.ifBlank { null }, page = page + 1) }
                                            .onSuccess { env ->
                                                env.data.orEmpty().forEach { n -> if (projects.none { it.id == n.id }) projects.add(n) }
                                                page += 1
                                                hasMore = env.pagination?.hasMorePages == true
                                            }
                                            .onFailure { host.fail(it, generic) }
                                        loadingMore = false
                                    }
                                },
                                style = WaButtonStyle.Ghost, loading = loadingMore, compact = true,
                            )
                        }
                    }
                }
            }
        }
    }

    if (creating) {
        NewProjectSheet(onDismiss = { creating = false }) { created ->
            creating = false
            reload++
            host.reloadStatus()
            host.push(MkRoute.Project(created.id))
        }
    }
}

/** My package: active until …, what is used of the period — or the way to subscribe. */
@Composable
private fun PackageCard() {
    val host = LocalMk.current
    val sub = host.status?.subscription
    WaCard(Modifier.fillMaxWidth(), padding = 16.dp) {
        Row(verticalAlignment = androidx.compose.ui.Alignment.CenterVertically) {
            WaIconWell(Icons.Rounded.WorkspacePremium, if (sub?.active == true) Tone.Green else Tone.Amber)
            Spacer(Modifier.padding(start = 12.dp))
            Column(Modifier.weight(1f)) {
                Text(
                    if (sub?.active == true) sub.pkg?.name ?: stringResource(R.string.mktg_package) else stringResource(R.string.mktg_no_package_title),
                    color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold,
                )
                Text(
                    if (sub?.active == true) stringResource(R.string.mktg_active_until, mkDate(sub.paidUntil ?: sub.endsAt)) else stringResource(R.string.mktg_no_package_text),
                    color = Wa.Mut, fontSize = 12.5.sp, lineHeight = 19.sp,
                )
            }
        }
        if (sub?.active == true) {
            Spacer(Modifier.height(12.dp))
            val unlimited = stringResource(R.string.mktg_unlimited)
            listOf(
                "projects" to R.string.mktg_limit_projects,
                "research_runs" to R.string.mktg_limit_research_runs,
                "plan_generations" to R.string.mktg_limit_plan_generations,
                "content_generations" to R.string.mktg_limit_content_generations,
            ).forEach { (key, label) ->
                val used = sub.usage[key] ?: 0
                val limit = sub.limits?.get(key)
                Row(Modifier.fillMaxWidth().padding(vertical = 3.dp)) {
                    Text(stringResource(label, ""), color = Wa.Mut, fontSize = 12.5.sp, modifier = Modifier.weight(1f))
                    Text(stringResource(R.string.mktg_used_of, used, limit?.toString() ?: unlimited), color = Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
                }
                if (limit != null && limit > 0) MkBar(used.toFloat() / limit, if (used >= limit) Wa.Danger else Wa.Red)
            }
            Spacer(Modifier.height(12.dp))
            WaButton(stringResource(R.string.mktg_renew), { host.push(MkRoute.Packages) }, style = WaButtonStyle.Ghost, compact = true)
        } else {
            Spacer(Modifier.height(12.dp))
            WaButton(stringResource(R.string.mktg_see_packages), { host.push(MkRoute.Packages) }, compact = true)
        }
    }
}

@Composable
private fun ProjectRow(p: MkProjectDto, modifier: Modifier, onClick: () -> Unit) {
    val subtitle = listOfNotNull(p.industry, p.cities.firstOrNull(), stringResource(mkStageLabel(p.stage))).joinToString(" · ")
    MkNavCard(
        Icons.Rounded.Business, if (p.status == "archived") Tone.Gray else Tone.Red, p.name, subtitle, modifier,
        trailing = {
            if (p.status == "archived") MkSmallTag(stringResource(R.string.mktg_archived_badge), Wa.Mut)
            else MkSmallTag(stringResource(mkRoleLabel(p.myRole)), Wa.Red)
        },
        onClick = onClick,
    )
}

@Composable
private fun NewProjectSheet(onDismiss: () -> Unit, onCreated: (MkProjectDto) -> Unit) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    val done = stringResource(R.string.mktg_created)
    var name by remember { mutableStateOf("") }
    var industry by remember { mutableStateOf("") }
    var city by remember { mutableStateOf("") }
    var stage by remember { mutableStateOf("idea") }
    var busy by remember { mutableStateOf(false) }
    val valid = name.trim().length >= 2 && industry.trim().length >= 2

    ChSheet(onDismissRequest = onDismiss, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).imePadding().navigationBarsPadding()
                .padding(horizontal = 20.dp).padding(bottom = 20.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(stringResource(R.string.mktg_new_project), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            DorrTextField(value = name, onValueChange = { name = it.take(120) }, label = stringResource(R.string.mktg_project_name), icon = Icons.Rounded.Business)
            DorrTextField(
                value = industry, onValueChange = { industry = it.take(120) }, label = stringResource(R.string.mktg_industry),
                placeholder = stringResource(R.string.mktg_industry_hint), icon = Icons.Rounded.AutoAwesome,
            )
            DorrTextField(value = city, onValueChange = { city = it.take(100) }, label = stringResource(R.string.mktg_city), icon = Icons.Rounded.LocationCity)
            Text(stringResource(R.string.mktg_stage), color = Wa.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf("idea", "launch", "growth").forEach { s ->
                    WeChip(stringResource(mkStageLabel(s)), selected = stage == s, onClick = { stage = s })
                }
            }
            Spacer(Modifier.height(4.dp))
            WaButton(
                stringResource(R.string.mktg_create),
                onClick = {
                    busy = true
                    scope.launch {
                        runCatching {
                            ApiClient.marketing.createProject(
                                mkAuth(),
                                MkProjectRequest(name = name.trim(), industry = industry.trim(), stage = stage, cities = city.trim().takeIf { it.isNotEmpty() }?.let { listOf(it) }),
                            ).data
                        }.onSuccess { created ->
                            if (created != null) {
                                host.showToast(done)
                                onCreated(created)
                            }
                        }.onFailure { host.fail(it, generic) }
                        busy = false
                    }
                },
                enabled = valid, loading = busy,
            )
        }
    }
}

/** The packages sold here, each bought on the app's one payment screen (card or wallet). */
@Composable
fun MarketingPackages() {
    val host = LocalMk.current
    val paid = stringResource(R.string.mktg_paid)
    val status = host.status
    WaPage(title = stringResource(R.string.mktg_packages), onBack = { host.pop() }) {
        val packages = status?.packages.orEmpty()
        if (status == null) {
            repeat(2) { WaSkeleton(Modifier.fillMaxWidth().height(160.dp).padding(bottom = 12.dp), RoundedCornerShape(22.dp)) }
        } else if (packages.isEmpty()) {
            WaEmpty(Icons.Rounded.WorkspacePremium, Tone.Gray, stringResource(R.string.mktg_packages), stringResource(R.string.mktg_no_packages))
        } else {
            Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                packages.forEachIndexed { i, pkg ->
                    PackageOffer(pkg, active = status.subscription?.pkg?.code == pkg.code, modifier = Modifier.waRise(i)) {
                        CheckoutLauncher.open(CheckoutRequest(status.checkoutPurpose, mapOf("package" to pkg.code))) {
                            host.showToast(paid)
                            host.reloadStatus()
                            host.pop()
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun PackageOffer(pkg: MkPackageDto, active: Boolean, modifier: Modifier, onBuy: () -> Unit) {
    val unlimited = stringResource(R.string.mktg_unlimited)
    WaCard(modifier.fillMaxWidth(), padding = 18.dp) {
        Row(verticalAlignment = androidx.compose.ui.Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(pkg.name ?: pkg.code, color = Wa.Ink, fontSize = 17.sp, fontWeight = FontWeight.ExtraBold)
                Text(stringResource(R.string.mktg_per_days, pkg.periodDays), color = Wa.Mut, fontSize = 12.5.sp)
            }
            Text(mkMoney(pkg.priceMinor, pkg.currencyCode.orEmpty()), color = Wa.Red, fontSize = 18.sp, fontWeight = FontWeight.Black)
        }
        if (!pkg.description.isNullOrBlank()) {
            Spacer(Modifier.height(8.dp))
            Text(pkg.description, color = Wa.Mut, fontSize = 13.sp, lineHeight = 20.sp)
        }
        Spacer(Modifier.height(12.dp))
        MkBullets(
            listOf(
                stringResource(R.string.mktg_limit_projects, pkg.limits["projects"]?.toString() ?: unlimited),
                stringResource(R.string.mktg_limit_research_runs, pkg.limits["research_runs"]?.toString() ?: unlimited),
                stringResource(R.string.mktg_limit_plan_generations, pkg.limits["plan_generations"]?.toString() ?: unlimited),
                stringResource(R.string.mktg_limit_content_generations, pkg.limits["content_generations"]?.toString() ?: unlimited),
                stringResource(R.string.mktg_limit_members, pkg.limits["members"]?.toString() ?: unlimited),
            ),
        )
        Spacer(Modifier.height(14.dp))
        WaButton(stringResource(if (active) R.string.mktg_renew else R.string.mktg_buy), onBuy, style = if (active) WaButtonStyle.Ghost else WaButtonStyle.Primary)
    }
}
