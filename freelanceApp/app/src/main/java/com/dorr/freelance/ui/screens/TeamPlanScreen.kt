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
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.WorkspacePremium
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
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
import com.dorr.core.network.ApiEnvelope
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.TakePlanRequest
import com.dorr.freelance.net.TeamPlanCatalogDto
import com.dorr.freelance.net.TeamPlanDto
import com.dorr.freelance.net.TeamPlanPageDto
import com.dorr.freelance.net.TeamSubscriptionDto
import com.dorr.freelance.net.TeamUsageDto
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.Card
import com.dorr.freelance.ui.components.ChipRow
import com.dorr.freelance.ui.components.EmptyState
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.FieldLabel
import com.dorr.freelance.ui.components.MessageToast
import com.dorr.freelance.ui.components.Page
import com.dorr.freelance.ui.components.SkeletonDetail
import com.dorr.freelance.ui.kit.AlertDialog
import com.dorr.freelance.ui.kit.DialogButton
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.wallet.longDate
import kotlinx.coroutines.launch
import retrofit2.Response

private val TEAM_LIVE = setOf("trialing", "active", "past_due")
private val LIMIT_RED = Color(0xFFDC2626)

/**
 * A team's plan (FS-211), for its Owners: what the team holds against its room (people, projects at once, offers this month), the plan it is on
 * and when it renews, the plans with their prices (monthly or yearly), and the invoices. The price is the whole team's, charged from the wallet of
 * the Owner who subscribes: only that Owner changes the plan, pays again or turns renewal back on; any Owner turns renewal off. The server decides
 * what a change means (a better plan starts now with the unused time taken off, a lower one waits for the end of the period) and every answer is
 * the whole page again. Read when the page opens, after each action and when the connection returns - nothing runs on a timer.
 */
@Composable
fun TeamPlanScreen(teamUuid: String, onBack: () -> Unit, onOpenWallet: () -> Unit) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val doneText = stringResource(R.string.plans_done)
    val context = androidx.compose.ui.platform.LocalContext.current
    val shortText = stringResource(R.string.plans_short_go_wallet)
    // Not enough in the wallet: straight to it (the message says what is missing), instead of leaving a button to find.
    fun goToWallet(message: String?) {
        android.widget.Toast.makeText(context, message?.takeIf { it.isNotBlank() } ?: shortText, android.widget.Toast.LENGTH_LONG).show()
        onOpenWallet()
    }

    var page by remember { mutableStateOf<TeamPlanPageDto?>(null) }
    var period by remember { mutableStateOf("monthly") }
    var seeded by remember { mutableStateOf(false) }
    var confirm by remember { mutableStateOf<TeamPlanDto?>(null) }
    var confirmCancel by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var needsMoney by remember { mutableStateOf(false) }
    var failed by remember { mutableStateOf<String?>(null) }

    suspend fun load() {
        val result = apiCall { ApiClient.teamPlan.show(teamUuid) }
        if (result is ApiResult.Ok) {
            page = result.data
            failed = null
            // A plan already running opens on the period it was bought for.
            if (!seeded) {
                seeded = true
                result.data.subscription?.takeIf { it.status in TEAM_LIVE }?.let { period = it.period }
            }
        } else if (page == null) {
            failed = result.errorText(offlineText) ?: genericError
        }
    }

    LaunchedEffect(reconnect) { load() }

    fun act(call: suspend () -> Response<ApiEnvelope<TeamPlanPageDto>>) {
        error = null
        notice = null
        needsMoney = false
        busy = true
        scope.launch {
            val result = apiCall { call() }
            if (result is ApiResult.Ok) {
                page = result.data
                notice = doneText
            } else {
                error = result.errorText(offlineText) ?: genericError
                needsMoney = (result as? ApiResult.Failed)?.error?.errorCode?.endsWith("_insufficient") == true
                if (needsMoney) goToWallet(error) else load() // another Owner may have changed the plan meanwhile: show what is true now
            }
            busy = false
        }
    }

    val data = page
    val live = data?.subscription?.takeIf { it.status in TEAM_LIVE }

    Page(title = stringResource(R.string.tpl_title), onBack = onBack) {
        if (data == null) {
            if (failed != null) EmptyState(Icons.Rounded.WorkspacePremium, stringResource(R.string.tpl_title), failed.orEmpty()) else SkeletonDetail()
            return@Page
        }
        val catalog = data.catalog
        val onSale = catalog.plans.any { !it.free }

        TeamUsageCard(data.usage)

        if (live != null) {
            MyTeamPlanCard(
                sub = live, busy = busy,
                onPay = { act { ApiClient.teamPlan.renew(teamUuid) } },
                onCancel = { confirmCancel = true },
                onResume = { act { ApiClient.teamPlan.resume(teamUuid) } },
            )
        }

        if (needsMoney) {
            Card {
                Text(error.orEmpty(), color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp)
                SettingsPrimaryButton(text = stringResource(R.string.plans_wallet_topup), onClick = onOpenWallet)
            }
        }

        if (!onSale && live == null) {
            EmptyState(Icons.Rounded.WorkspacePremium, stringResource(R.string.tpl_unavailable_title), stringResource(R.string.tpl_unavailable))
        } else {
            ChipRow(
                options = listOf("monthly" to stringResource(R.string.plans_period_monthly), "yearly" to stringResource(R.string.plans_period_yearly)),
                selected = setOf(period),
                onToggle = { period = it },
            )
            catalog.plans.forEach { plan ->
                TeamPlanCard(plan = plan, catalog = catalog, period = period, live = live, busy = busy, onChoose = { confirm = plan })
            }
            // FS-705c: the plans side by side, on the team's work only.
            PlanComparison(catalog.features, catalog.plans.map { PlanColumn(it.slug, it.name, it.current, it.perks) })
            Text(stringResource(R.string.tpl_footer), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp)
        }

        if (data.invoices.isNotEmpty()) {
            Card {
                FieldLabel(stringResource(R.string.plans_invoices))
                data.invoices.forEach { i ->
                    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(
                                "${i.plan} · " + stringResource(
                                    when (i.kind) {
                                        "upgrade" -> R.string.plans_invoice_upgrade
                                        "renewal" -> R.string.plans_invoice_renewal
                                        else -> R.string.plans_invoice_purchase
                                    },
                                ),
                                color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold,
                            )
                            Text(
                                listOfNotNull(
                                    longDate(i.createdAt).ifBlank { null },
                                    if (i.creditMinor > 0) stringResource(R.string.plans_invoice_credit, planMoney(i.creditMinor, catalog.decimals, i.currency, i.currencySymbol)) else null,
                                ).joinToString(" · "),
                                color = settingsMut(), fontSize = 11.sp,
                            )
                        }
                        if (i.status == "failed") {
                            Text(stringResource(R.string.plans_invoice_failed), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
                        } else {
                            Text(planMoney(i.amountMinor, catalog.decimals, i.currency, i.currencySymbol), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
                        }
                    }
                }
            }
        }

        MessageToast(notice)
        if (!needsMoney) ErrorBanner(error, Modifier.fillMaxWidth())
    }

    confirm?.let { plan ->
        val catalog = page?.catalog ?: TeamPlanCatalogDto()
        val price = (if (period == "yearly") plan.prices.yearly else plan.prices.monthly) ?: 0L
        val periodName = stringResource(if (period == "yearly") R.string.plans_period_yearly else R.string.plans_period_monthly)
        val unit = stringResource(if (period == "yearly") R.string.plans_per_year else R.string.plans_per_month)
        val money = planMoney(price, catalog.decimals, catalog.currency, catalog.currencySymbol)
        val message = when {
            live == null && plan.trialDays > 0 -> stringResource(R.string.plans_confirm_trial, plan.name, plan.trialDays, money, unit)
            live == null -> stringResource(R.string.plans_confirm_pay, plan.name, periodName, money)
            teamIsUpgrade(plan, period, live) -> stringResource(R.string.plans_confirm_upgrade, plan.name, periodName, money)
            else -> stringResource(R.string.plans_confirm_later, plan.name, periodName, longDate(live.periodEnd), money)
        }
        AlertDialog(
            onDismissRequest = { confirm = null },
            title = { Text(plan.name, fontWeight = FontWeight.ExtraBold) },
            text = { Text(message, lineHeight = 20.sp) },
            confirmButton = {
                DialogButton(onClick = {
                    confirm = null
                    act { ApiClient.teamPlan.take(teamUuid, TakePlanRequest(plan.slug, period)) }
                }) { Text(stringResource(R.string.plans_confirm_ok), fontWeight = FontWeight.Bold) }
            },
            dismissButton = { DialogButton(onClick = { confirm = null }) { Text(stringResource(R.string.common_cancel)) } },
        )
    }

    if (confirmCancel) {
        AlertDialog(
            onDismissRequest = { confirmCancel = false },
            title = { Text(stringResource(R.string.plans_cancel_confirm_title), fontWeight = FontWeight.ExtraBold) },
            text = { Text(stringResource(R.string.tpl_cancel_confirm, live?.plan?.name.orEmpty(), longDate(live?.periodEnd)), lineHeight = 20.sp) },
            confirmButton = {
                DialogButton(onClick = {
                    confirmCancel = false
                    act { ApiClient.teamPlan.cancel(teamUuid) }
                }) { Text(stringResource(R.string.plans_cancel_renewal), fontWeight = FontWeight.Bold) }
            },
            dismissButton = { DialogButton(onClick = { confirmCancel = false }) { Text(stringResource(R.string.common_cancel)) } },
        )
    }
}

/** A better plan (or a longer period of the same one) starts now; anything else waits for the end of the period. */
private fun teamIsUpgrade(plan: TeamPlanDto, period: String, sub: TeamSubscriptionDto): Boolean =
    plan.rank > sub.plan.rank || (plan.rank == sub.plan.rank && period == "yearly" && sub.period == "monthly")

/** What the team holds against its room: three meters that turn red when a limit is reached. A limit only stops what is new. */
@Composable
private fun TeamUsageCard(usage: TeamUsageDto) {
    Card {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.Groups, null, tint = settingsAccent(), modifier = Modifier.size(28.dp))
            Spacer(Modifier.width(10.dp))
            Text(stringResource(R.string.tpl_usage_title), color = settingsInk(), fontSize = 17.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
        }
        UsageMeter(stringResource(R.string.tpl_people), usage.members.used, usage.members.max)
        UsageMeter(stringResource(R.string.tpl_projects), usage.projects.used, usage.projects.max)
        UsageMeter(stringResource(R.string.tpl_offers), usage.offers.used, usage.offers.limit)
        Text(stringResource(R.string.tpl_usage_hint), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp)
    }
}

@Composable
private fun UsageMeter(label: String, used: Int, max: Int?) {
    val full = max != null && used >= max
    Column(verticalArrangement = Arrangement.spacedBy(5.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(label, color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
            Text(
                if (max == null) stringResource(R.string.tpl_no_cap, used) else stringResource(R.string.tpl_of, used, max),
                color = if (full) LIMIT_RED else settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold,
            )
        }
        if (max != null && max > 0) {
            Box(Modifier.fillMaxWidth().height(6.dp).clip(RoundedCornerShape(999.dp)).background(settingsMut().copy(alpha = 0.15f))) {
                Box(
                    Modifier.fillMaxWidth((used.toFloat() / max).coerceIn(0f, 1f)).height(6.dp).clip(RoundedCornerShape(999.dp))
                        .background(if (full) LIMIT_RED else settingsAccent()),
                )
            }
            if (full) Text(stringResource(R.string.tpl_full), color = LIMIT_RED, fontSize = 11.sp, fontWeight = FontWeight.Bold)
        }
    }
}

/** The plan the team is on: its status, when it renews or ends, what waits, who pays, and the actions that fit its state and the person looking. */
@Composable
private fun MyTeamPlanCard(sub: TeamSubscriptionDto, busy: Boolean, onPay: () -> Unit, onCancel: () -> Unit, onResume: () -> Unit) {
    Card {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.WorkspacePremium, null, tint = settingsAccent(), modifier = Modifier.size(28.dp))
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.plans_current), color = settingsMut(), fontSize = 11.sp)
                Text(sub.plan.name, color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            }
            StatusPill(sub.status)
        }
        val line = when {
            sub.status == "trialing" -> stringResource(R.string.plans_line_trial, longDate(sub.trialEndsAt ?: sub.periodEnd))
            sub.status == "past_due" -> stringResource(R.string.plans_line_grace, longDate(sub.graceUntil))
            !sub.autoRenew -> stringResource(R.string.plans_line_ends, longDate(sub.periodEnd))
            else -> stringResource(R.string.plans_line_renews, longDate(sub.periodEnd))
        }
        Text(line, color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp)
        sub.pending?.let { p ->
            Text(stringResource(R.string.plans_line_pending, p.plan.name.orEmpty(), periodName(p.period), longDate(sub.periodEnd)), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp)
        }
        Text(
            if (sub.paidByMe) stringResource(R.string.tpl_paid_by_me) else stringResource(R.string.tpl_paid_by, sub.paidBy?.name.orEmpty()),
            color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp,
        )
        if (sub.status == "past_due" && sub.paidByMe) SettingsPrimaryButton(text = stringResource(R.string.plans_pay_now), loading = busy, onClick = onPay)
        // Turning renewal off is any Owner's; turning it back on spends the payer's wallet, so it is the payer's.
        if (sub.status != "past_due" && (sub.autoRenew || sub.paidByMe)) {
            Text(
                stringResource(if (sub.autoRenew) R.string.plans_cancel_renewal else R.string.plans_resume),
                color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clickable(enabled = !busy) { if (sub.autoRenew) onCancel() else onResume() }.padding(vertical = 4.dp),
            )
        }
    }
}

/** One plan: its price for the chosen period, what it opens for the team, and the button that fits where the team is now. */
@Composable
private fun TeamPlanCard(plan: TeamPlanDto, catalog: TeamPlanCatalogDto, period: String, live: TeamSubscriptionDto?, busy: Boolean, onChoose: () -> Unit) {
    val price = if (period == "yearly") plan.prices.yearly else plan.prices.monthly
    val sameAsMine = plan.current && live != null && live.period == period
    // Changing a running plan spends the payer's wallet: another Owner reads it but does not change it.
    val mine = live == null || live.paidByMe
    Card {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(plan.name, color = settingsInk(), fontSize = 17.sp, fontWeight = FontWeight.ExtraBold)
                plan.description?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp) }
            }
            if (plan.current) {
                Text(
                    stringResource(R.string.plans_current), color = settingsAccent(), fontSize = 11.sp, fontWeight = FontWeight.Bold,
                    modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(settingsAccent().copy(alpha = 0.10f)).padding(horizontal = 10.dp, vertical = 4.dp),
                )
            }
        }
        if (price == null) {
            Text(stringResource(R.string.plans_free_price), color = settingsInk(), fontSize = 22.sp, fontWeight = FontWeight.ExtraBold)
        } else {
            val unit = stringResource(if (period == "yearly") R.string.plans_per_year else R.string.plans_per_month)
            Text(stringResource(R.string.plans_price_line, planMoney(price, catalog.decimals, catalog.currency, catalog.currencySymbol), unit), color = settingsInk(), fontSize = 22.sp, fontWeight = FontWeight.ExtraBold)
            if (period == "yearly") plan.yearlySavingMinor?.takeIf { it > 0 }?.let {
                Text(stringResource(R.string.plans_saves, planMoney(it, catalog.decimals, catalog.currency, catalog.currencySymbol)), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
            }
        }
        Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
            val b = plan.benefits
            Benefit(b.membersMax?.let { stringResource(R.string.tpl_benefit_people_n, it) } ?: stringResource(R.string.tpl_benefit_people_any))
            Benefit(b.projectsMax?.let { stringResource(R.string.tpl_benefit_projects_n, it) } ?: stringResource(R.string.tpl_benefit_projects_any))
            Benefit(b.offersMonthly?.let { stringResource(R.string.tpl_benefit_offers_n, it) } ?: stringResource(R.string.tpl_benefit_offers_any))
            if (plan.trialDays > 0) Benefit(stringResource(R.string.plans_trial, plan.trialDays))
        }
        if (!plan.free && !sameAsMine) {
            val label = when {
                live == null && plan.trialDays > 0 -> stringResource(R.string.plans_btn_trial, plan.trialDays)
                live == null -> stringResource(R.string.plans_btn_subscribe)
                teamIsUpgrade(plan, period, live) -> stringResource(R.string.plans_btn_upgrade)
                else -> stringResource(R.string.plans_btn_switch)
            }
            SettingsPrimaryButton(text = label, enabled = mine && live?.status != "past_due", loading = busy, onClick = onChoose)
            if (!mine) Text(stringResource(R.string.tpl_payer_only, live?.paidBy?.name.orEmpty()), color = settingsMut(), fontSize = 12.sp)
        }
    }
}
