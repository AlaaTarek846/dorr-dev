package com.dorr.freelance.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Insights
import androidx.compose.material.icons.rounded.WorkspacePremium
import com.dorr.freelance.ui.kit.AlertDialog
import com.dorr.freelance.ui.kit.DialogButton
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiEnvelope
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.BuyPackRequest
import com.dorr.freelance.net.MySubscriptionDto
import com.dorr.freelance.net.OfferPackDto
import com.dorr.freelance.net.OfferPacksDto
import com.dorr.freelance.net.PlanDto
import com.dorr.freelance.net.PlansDto
import com.dorr.freelance.net.SubscriptionDto
import com.dorr.freelance.net.TakePlanRequest
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.Card
import com.dorr.freelance.ui.components.ChipRow
import com.dorr.freelance.ui.components.EmptyState
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.FieldLabel
import com.dorr.freelance.ui.components.MessageToast
import com.dorr.freelance.ui.components.Page
import com.dorr.freelance.ui.components.SkeletonDetail
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.wallet.longDate
import kotlinx.coroutines.launch
import retrofit2.Response
import java.math.BigDecimal
import java.util.Locale
import java.util.UUID

/** A price in the currency's own decimals ("34.00 SAR", "7.500 KWD", "1,350 JPY") — digits stay Latin and left-to-right in Arabic text too. */
internal fun planMoney(minor: Long, decimals: Int, currency: String?, symbol: String? = null): String {
    val text = String.format(Locale.US, "%,.${decimals}f", BigDecimal.valueOf(minor, decimals))
    return "‎" + listOf(text, symbol?.takeIf { it.isNotBlank() } ?: currency.orEmpty()).filter { it.isNotBlank() }.joinToString(" ")
}

private val LIVE = setOf("trialing", "active", "past_due")

/**
 * My plan (FL-L01): the plan I am on and when it renews, the free offers I have left, the plans with their prices here (monthly or
 * yearly), and my invoices. A plan is taken, changed, cancelled or resumed with one request each; the server decides what a change means
 * (a better plan starts now with the unused time taken off, a lower one waits for the end of the period), and every answer is the
 * whole page again. Read once when the page opens and after each action.
 */
@Composable
fun PlansScreen(onBack: () -> Unit, onOpenWallet: () -> Unit, onOpenInsights: () -> Unit) {
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

    var catalog by remember { mutableStateOf<PlansDto?>(null) }
    var mine by remember { mutableStateOf<MySubscriptionDto?>(null) }
    var period by remember { mutableStateOf("monthly") }
    var confirm by remember { mutableStateOf<PlanDto?>(null) }
    var confirmCancel by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var needsMoney by remember { mutableStateOf(false) }
    var failed by remember { mutableStateOf<String?>(null) }
    var packs by remember { mutableStateOf<OfferPacksDto?>(null) }
    var buying by remember { mutableStateOf<OfferPackDto?>(null) }
    // One id per confirmed purchase: a double tap or a retry after a lost answer is the same purchase, never charged twice.
    var buyRequest by remember { mutableStateOf("") }

    suspend fun readPlans() {
        val plans = apiCall { ApiClient.subscription.plans() }
        if (plans is ApiResult.Ok) catalog = plans.data else if (catalog == null) failed = plans.errorText(offlineText) ?: genericError
    }

    suspend fun readPacks() {
        (apiCall { ApiClient.subscription.offerPacks() } as? ApiResult.Ok)?.data?.let { packs = it }
    }

    LaunchedEffect(Unit) {
        val own = apiCall { ApiClient.subscription.show() }
        if (own is ApiResult.Ok) mine = own.data
        readPlans()
        readPacks()
        // A plan already running opens on the period it was bought for.
        mine?.subscription?.takeIf { it.status in LIVE }?.let { period = it.period }
    }

    fun act(call: suspend () -> Response<ApiEnvelope<MySubscriptionDto>>) {
        error = null
        notice = null
        needsMoney = false
        busy = true
        scope.launch {
            val result = apiCall { call() }
            if (result is ApiResult.Ok) {
                mine = result.data
                notice = doneText
                readPlans()
                readPacks()
            } else {
                error = result.errorText(offlineText) ?: genericError
                needsMoney = (result as? ApiResult.Failed)?.error?.errorCode?.endsWith("_insufficient") == true
                if (needsMoney) goToWallet(error)
            }
            busy = false
        }
    }

    fun buy(pack: OfferPackDto, requestId: String) {
        error = null
        notice = null
        needsMoney = false
        busy = true
        scope.launch {
            val result = apiCall { ApiClient.subscription.buyPack(pack.slug, BuyPackRequest(requestId)) }
            if (result is ApiResult.Ok) {
                packs = result.data
                // The page's own numbers (free offers left, bought ones) come from the same quota.
                mine = mine?.copy(quota = result.data.quota) ?: MySubscriptionDto(quota = result.data.quota)
                notice = doneText
            } else {
                error = result.errorText(offlineText) ?: genericError
                needsMoney = (result as? ApiResult.Failed)?.error?.errorCode?.endsWith("_insufficient") == true
                if (needsMoney) goToWallet(error)
            }
            busy = false
        }
    }

    val live = mine?.subscription?.takeIf { it.status in LIVE }
    val data = catalog

    Page(title = stringResource(R.string.plans_title), onBack = onBack) {
        if (data == null) {
            if (failed != null) EmptyState(Icons.Rounded.WorkspacePremium, stringResource(R.string.plans_title), failed.orEmpty()) else SkeletonDetail()
            return@Page
        }
        if (!data.enabled || data.plans.isEmpty()) {
            EmptyState(Icons.Rounded.WorkspacePremium, stringResource(R.string.plans_unavailable_title), stringResource(R.string.plans_unavailable))
            return@Page
        }

        MyPlanCard(
            sub = live, quota = mine?.quota, busy = busy, plans = data,
            onPay = { act { ApiClient.subscription.renew() } },
            onCancel = { confirmCancel = true },
            onResume = { act { ApiClient.subscription.resume() } },
            onOpenInsights = onOpenInsights,
        )

        if (needsMoney) {
            Card {
                Text(error.orEmpty(), color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp)
                SettingsPrimaryButton(text = stringResource(R.string.plans_wallet_topup), onClick = onOpenWallet)
            }
        }

        ChipRow(
            options = listOf("monthly" to stringResource(R.string.plans_period_monthly), "yearly" to stringResource(R.string.plans_period_yearly)),
            selected = setOf(period),
            onToggle = { period = it },
        )

        data.plans.forEach { plan ->
            PlanCard(
                plan = plan, plans = data, period = period, live = live, busy = busy,
                onChoose = { confirm = plan },
            )
        }

        // FS-705c: the plans side by side, on every feature that works today.
        PlanComparison(data.features, data.plans.map { PlanColumn(it.slug, it.name, it.current, it.perks) })

        packs?.takeIf { it.enabled && it.packs.isNotEmpty() }?.let { p ->
            ExtraOffersCard(p, busy) { pack ->
                buyRequest = UUID.randomUUID().toString()
                buying = pack
            }
        }

        val invoices = mine?.invoices.orEmpty()
        Card {
            FieldLabel(stringResource(R.string.plans_invoices))
            if (invoices.isEmpty()) Text(stringResource(R.string.plans_invoices_none), color = settingsMut(), fontSize = 12.sp)
            invoices.forEach { i ->
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
                            listOfNotNull(longDate(i.createdAt).ifBlank { null }, if (i.creditMinor > 0) stringResource(R.string.plans_invoice_credit, planMoney(i.creditMinor, data.decimals, i.currency, i.currencySymbol)) else null).joinToString(" · "),
                            color = settingsMut(), fontSize = 11.sp,
                        )
                    }
                    if (i.status == "failed") {
                        Text(stringResource(R.string.plans_invoice_failed), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
                    } else {
                        Text(planMoney(i.amountMinor, data.decimals, i.currency, i.currencySymbol), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
                    }
                }
            }
        }

        MessageToast(notice)
        if (!needsMoney) ErrorBanner(error, Modifier.fillMaxWidth())
    }

    confirm?.let { plan ->
        val decimals = catalog?.decimals ?: 2
        val currency = catalog?.currency
        val price = (if (period == "yearly") plan.prices.yearly else plan.prices.monthly) ?: 0L
        val periodName = stringResource(if (period == "yearly") R.string.plans_period_yearly else R.string.plans_period_monthly)
        val unit = stringResource(if (period == "yearly") R.string.plans_per_year else R.string.plans_per_month)
        val money = planMoney(price, decimals, currency, catalog?.currencySymbol)
        val sub = mine?.subscription?.takeIf { it.status in LIVE }
        val message = when {
            sub == null && plan.trialDays > 0 -> stringResource(R.string.plans_confirm_trial, plan.name, plan.trialDays, money, unit)
            sub == null -> stringResource(R.string.plans_confirm_pay, plan.name, periodName, money)
            isUpgrade(plan, period, sub) -> stringResource(R.string.plans_confirm_upgrade, plan.name, periodName, money)
            else -> stringResource(R.string.plans_confirm_later, plan.name, periodName, longDate(sub.periodEnd), money)
        }
        AlertDialog(
            onDismissRequest = { confirm = null },
            title = { Text(plan.name, fontWeight = FontWeight.ExtraBold) },
            text = { Text(message, lineHeight = 20.sp) },
            confirmButton = {
                DialogButton(onClick = {
                    confirm = null
                    act { ApiClient.subscription.take(TakePlanRequest(plan.slug, period)) }
                }) { Text(stringResource(R.string.plans_confirm_ok), fontWeight = FontWeight.Bold) }
            },
            dismissButton = { DialogButton(onClick = { confirm = null }) { Text(stringResource(R.string.common_cancel)) } },
        )
    }

    buying?.let { pack ->
        val p = packs
        val money = planMoney(pack.priceMinor, p?.decimals ?: 2, p?.currency, p?.currencySymbol)
        val validDays = p?.validDays ?: 0
        AlertDialog(
            onDismissRequest = { buying = null },
            title = { Text(pack.name, fontWeight = FontWeight.ExtraBold) },
            text = {
                Text(
                    stringResource(R.string.plans_pack_confirm, pack.offers, money) + if (validDays > 0) "\n" + stringResource(R.string.plans_pack_valid, validDays) else "",
                    lineHeight = 20.sp,
                )
            },
            confirmButton = {
                DialogButton(onClick = {
                    buying = null
                    buy(pack, buyRequest)
                }) { Text(stringResource(R.string.plans_confirm_ok), fontWeight = FontWeight.Bold) }
            },
            dismissButton = { DialogButton(onClick = { buying = null }) { Text(stringResource(R.string.common_cancel)) } },
        )
    }

    if (confirmCancel) {
        AlertDialog(
            onDismissRequest = { confirmCancel = false },
            title = { Text(stringResource(R.string.plans_cancel_confirm_title), fontWeight = FontWeight.ExtraBold) },
            text = { Text(stringResource(R.string.plans_cancel_confirm, live?.plan?.name.orEmpty(), longDate(live?.periodEnd)), lineHeight = 20.sp) },
            confirmButton = {
                DialogButton(onClick = {
                    confirmCancel = false
                    act { ApiClient.subscription.cancel() }
                }) { Text(stringResource(R.string.plans_cancel_renewal), fontWeight = FontWeight.Bold) }
            },
            dismissButton = { DialogButton(onClick = { confirmCancel = false }) { Text(stringResource(R.string.common_cancel)) } },
        )
    }
}

/** Extra offers: how many bought ones are left (and when they end) and the packs to buy more — the plan with no limit has no use for them. */
@Composable
private fun ExtraOffersCard(p: OfferPacksDto, busy: Boolean, onBuy: (OfferPackDto) -> Unit) {
    Card {
        FieldLabel(stringResource(R.string.plans_extra_title))
        if (p.unlimited) {
            Text(stringResource(R.string.plans_extra_unlimited), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp)
            return@Card
        }
        Text(stringResource(R.string.plans_extra_sub), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp)
        if (p.credits.available > 0) {
            Text(
                stringResource(R.string.plans_extra_left, p.credits.available), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(settingsAccent().copy(alpha = 0.10f)).padding(horizontal = 12.dp, vertical = 5.dp),
            )
            p.credits.batches.forEach { b ->
                Text(
                    if (b.expiresAt == null) stringResource(R.string.plans_extra_batch_open, b.remaining, b.quantity)
                    else stringResource(R.string.plans_extra_batch, b.remaining, b.quantity, longDate(b.expiresAt)),
                    color = settingsMut(), fontSize = 11.sp,
                )
            }
        }
        p.packs.forEach { pack ->
            Row(Modifier.fillMaxWidth().padding(vertical = 2.dp), verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(pack.name, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                    Text(stringResource(R.string.plans_pack_offers, pack.offers), color = settingsMut(), fontSize = 12.sp)
                }
                Text(planMoney(pack.priceMinor, p.decimals, p.currency, p.currencySymbol), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
                Spacer(Modifier.width(10.dp))
                Text(
                    stringResource(R.string.plans_pack_buy), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                    modifier = Modifier.clickable(enabled = !busy) { onBuy(pack) }.padding(6.dp),
                )
            }
        }
    }
}

/** A better plan (or a longer period of the same one) starts now; anything else waits for the end of the period. */
private fun isUpgrade(plan: PlanDto, period: String, sub: SubscriptionDto): Boolean =
    plan.rank > sub.plan.rank || (plan.rank == sub.plan.rank && period == "yearly" && sub.period == "monthly")

/** The plan I am on: its status, when it renews or ends, what waits, the free offers left, and the actions that fit its state. */
@Composable
private fun MyPlanCard(
    sub: SubscriptionDto?,
    quota: com.dorr.freelance.net.OfferQuotaDto?,
    busy: Boolean,
    plans: PlansDto,
    onPay: () -> Unit,
    onCancel: () -> Unit,
    onResume: () -> Unit,
    onOpenInsights: () -> Unit,
) {
    val current = plans.plans.firstOrNull { it.current }
    Card {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.WorkspacePremium, null, tint = settingsAccent(), modifier = Modifier.size(28.dp))
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.plans_current), color = settingsMut(), fontSize = 11.sp)
                Text(sub?.plan?.name ?: current?.name.orEmpty(), color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            }
            sub?.let { StatusPill(it.status) }
        }
        if (sub != null) {
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
        }
        quota?.takeIf { it.enabled }?.let { q ->
            Text(
                if (q.limit == null) stringResource(R.string.plans_quota_unlimited) else stringResource(R.string.plans_quota_left, q.left ?: 0, q.limit ?: 0),
                color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(settingsAccent().copy(alpha = 0.10f)).padding(horizontal = 12.dp, vertical = 5.dp),
            )
        }
        quota?.takeIf { it.enabled && it.credits > 0 }?.let { q ->
            Text(stringResource(R.string.plans_quota_extra, q.credits), color = settingsMut(), fontSize = 12.sp)
        }
        // Phase 4: my numbers are one tap away from my plan (locked ones say what opens them).
        Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable(onClick = onOpenInsights).padding(vertical = 8.dp, horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.Insights, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(8.dp))
            Text(stringResource(R.string.plans_open_insights), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
        }
        if (sub?.status == "past_due") SettingsPrimaryButton(text = stringResource(R.string.plans_pay_now), loading = busy, onClick = onPay)
        if (sub != null && sub.status != "past_due") {
            Text(
                stringResource(if (sub.autoRenew) R.string.plans_cancel_renewal else R.string.plans_resume),
                color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clickable(enabled = !busy) { if (sub.autoRenew) onCancel() else onResume() }.padding(vertical = 4.dp),
            )
        }
    }
}

@Composable
internal fun periodName(period: String?): String = stringResource(if (period == "yearly") R.string.plans_period_yearly else R.string.plans_period_monthly)

@Composable
internal fun StatusPill(status: String) {
    val text = stringResource(
        when (status) {
            "trialing" -> R.string.plans_status_trialing
            "past_due" -> R.string.plans_status_past_due
            "expired" -> R.string.plans_status_expired
            else -> R.string.plans_status_active
        },
    )
    Text(
        text, color = settingsAccent(), fontSize = 11.sp, fontWeight = FontWeight.Bold,
        modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(settingsAccent().copy(alpha = 0.10f)).padding(horizontal = 10.dp, vertical = 4.dp),
    )
}

/** One plan: its price for the chosen period, what it gives, and the button that fits where I am now. */
@Composable
private fun PlanCard(plan: PlanDto, plans: PlansDto, period: String, live: SubscriptionDto?, busy: Boolean, onChoose: () -> Unit) {
    val price = if (period == "yearly") plan.prices.yearly else plan.prices.monthly
    val sameAsMine = plan.current && live != null && live.period == period
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
            Text(stringResource(R.string.plans_price_line, planMoney(price, plans.decimals, plans.currency, plans.currencySymbol), unit), color = settingsInk(), fontSize = 22.sp, fontWeight = FontWeight.ExtraBold)
            if (period == "yearly") plan.yearlySavingMinor?.takeIf { it > 0 }?.let {
                Text(stringResource(R.string.plans_saves, planMoney(it, plans.decimals, plans.currency, plans.currencySymbol)), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
            }
        }
        Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
            val b = plan.benefits
            Benefit(
                when {
                    b.offersUnlimited -> stringResource(R.string.plans_offers_unlimited)
                    b.offersMonthly != null -> stringResource(R.string.plans_offers_n, b.offersMonthly)
                    else -> null
                },
            )
            Benefit(
                if (b.commissionBp != null) stringResource(R.string.plans_commission, trimPercent(b.commissionBp)) else stringResource(R.string.plans_commission_country),
            )
            // Phase 4: what the plan shows and opens besides offers and commission.
            if (b.badge) Benefit(stringResource(R.string.plans_perk_badge, plan.name))
            if (b.boost > 0) Benefit(stringResource(R.string.plans_perk_boost))
            when (b.analytics) {
                1 -> Benefit(stringResource(R.string.plans_perk_analytics_1))
                2 -> Benefit(stringResource(R.string.plans_perk_analytics_2))
            }
            // FS-703: my clients — 0 shows nothing, 1 the clients and notes, 2 + the follow-up reminders.
            when (b.crm) {
                1 -> Benefit(stringResource(R.string.plans_perk_crm_1))
                2 -> Benefit(stringResource(R.string.plans_perk_crm_2))
            }
            b.servicesMax?.let { Benefit(stringResource(R.string.plans_perk_services, it)) }
            if (plan.trialDays > 0) Benefit(stringResource(R.string.plans_trial, plan.trialDays))
        }
        if (!plan.free && !sameAsMine) {
            val label = when {
                live == null && plan.trialDays > 0 -> stringResource(R.string.plans_btn_trial, plan.trialDays)
                live == null -> stringResource(R.string.plans_btn_subscribe)
                isUpgrade(plan, period, live) -> stringResource(R.string.plans_btn_upgrade)
                else -> stringResource(R.string.plans_btn_switch)
            }
            SettingsPrimaryButton(text = label, enabled = live?.status != "past_due", loading = busy, onClick = onChoose)
        }
    }
}

/** 800 → "8", 750 → "7.5" (hundredths of a percent). */
private fun trimPercent(bp: Int): String = BigDecimal.valueOf(bp.toLong(), 2).stripTrailingZeros().toPlainString()

@Composable
internal fun Benefit(text: String?) {
    if (text == null) return
    Row(verticalAlignment = Alignment.CenterVertically) {
        Icon(Icons.Rounded.Check, null, tint = settingsAccent(), modifier = Modifier.size(16.dp))
        Spacer(Modifier.width(8.dp))
        Text(text, color = settingsInk(), fontSize = 13.sp)
    }
}
