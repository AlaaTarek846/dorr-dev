package com.dorr.app.ui.screens.aichat

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
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
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.AiArCreateExperienceRequest
import com.dorr.app.network.AiArDisputeRequest
import com.dorr.app.network.AiArOrderDto
import com.dorr.app.network.AiArExperienceDto
import com.dorr.app.network.AiArProviderDto
import com.dorr.app.network.AiArReviewDto
import com.dorr.app.network.AiArSubmitRequest
import com.dorr.app.network.ApiClient
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.chat.chatAuth
import com.dorr.app.ui.screens.wallet.PadResult
import com.dorr.app.ui.screens.wallet.walletAuth
import java.time.Instant
import kotlinx.coroutines.launch

private fun arStatusLabel(status: String): Int = when (status) {
    "active" -> R.string.ai_ar_st_active
    "submitted" -> R.string.ai_ar_st_submitted
    "approved" -> R.string.ai_ar_st_approved
    "accepted" -> R.string.ai_ar_st_accepted
    else -> R.string.ai_ar_st_draft
}

private fun arOrderLabel(status: String): Int = when (status) {
    "delivered" -> R.string.ai_ar_od_delivered
    "disputed" -> R.string.ai_ar_od_disputed
    "released" -> R.string.ai_ar_od_released
    "refunded" -> R.string.ai_ar_od_refunded
    else -> R.string.ai_ar_od_paid
}

private fun arReviewLabel(status: String): Int = when (status) {
    "approved" -> R.string.ai_ar_rv_approved
    "changes_requested" -> R.string.ai_ar_rv_changes
    "rejected" -> R.string.ai_ar_rv_rejected
    "withdrawn" -> R.string.ai_ar_rv_withdrawn
    else -> R.string.ai_ar_rv_pending
}

/**
 * "My designs": create a design, send it to a provider for review, read the answer (note and price quote), accept it.
 * Placing models happens in the AR editor (separate module, see docs); this screen only shows the state of the review.
 * While a design is under review or accepted it cannot be edited, so what the provider approved is what you accept.
 */
@Composable
internal fun AiArDesignsPanel(onOpenEditor: ((Int) -> Unit)?) {
    val c = sitePalette()
    val scope = rememberCoroutineScope()
    var designs by remember { mutableStateOf<List<AiArExperienceDto>?>(null) }
    var selected by remember { mutableStateOf<AiArExperienceDto?>(null) }
    var reviews by remember { mutableStateOf<List<AiArReviewDto>>(emptyList()) }
    var title by remember { mutableStateOf("") }
    var query by remember { mutableStateOf("") }
    var providers by remember { mutableStateOf<List<AiArProviderDto>>(emptyList()) }
    var chosen by remember { mutableStateOf<AiArProviderDto?>(null) }
    var note by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var order by remember { mutableStateOf<AiArOrderDto?>(null) }
    var payFor by remember { mutableStateOf<AiArExperienceDto?>(null) }
    var noPin by remember { mutableStateOf(false) }
    var disputing by remember { mutableStateOf(false) }
    var reason by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }

    fun reload(keep: Int? = null) {
        scope.launch {
            designs = runCatching { ApiClient.aiAr.experiences(chatAuth()).data.orEmpty() }.getOrDefault(emptyList())
            selected = designs.orEmpty().firstOrNull { it.id == (keep ?: selected?.id) }
            order = selected?.let { s -> runCatching { ApiClient.aiAr.order(chatAuth(), s.id).data }.getOrNull() }
            reviews = selected?.let { s -> runCatching { ApiClient.aiAr.reviews(chatAuth(), s.id).data.orEmpty() }.getOrDefault(emptyList()) } ?: emptyList()
        }
    }

    fun act(block: suspend () -> Unit) {
        scope.launch {
            busy = true
            error = null
            runCatching { block() }.onFailure { error = it.apiFailure().message }
            busy = false
            reload()
        }
    }

    LaunchedEffect(Unit) { reload() }
    BackHandler(enabled = selected != null) { selected = null }

    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        error?.let { Text(it, color = c.warn, fontSize = 13.sp) }
        val current = selected

        if (current == null) {
            SiteCard {
                SiteField(stringResource(R.string.ai_ar_design_title), title, { title = it })
                Spacer(Modifier.height(8.dp))
                PrimaryButton(stringResource(R.string.ai_ar_design_new), enabled = title.isNotBlank() && !busy, onClick = {
                    act {
                        val created = ApiClient.aiAr.createExperience(chatAuth(), AiArCreateExperienceRequest("decor", title.trim())).data
                        title = ""
                        selected = created
                    }
                })
            }
            designs.orEmpty().forEach { d ->
                SiteCard(onClick = { selected = d; reload(d.id) }, padding = 14.dp) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(d.title.orEmpty(), color = c.ink, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp, modifier = Modifier.weight(1f), maxLines = 1)
                        Text(stringResource(arStatusLabel(d.status)), color = Ai.Red, fontWeight = FontWeight.Bold, fontSize = 12.sp)
                    }
                }
            }
            if (designs?.isEmpty() == true) Text(stringResource(R.string.ai_ar_designs_empty), color = c.mut, fontSize = 13.sp)
            return@Column
        }

        SiteCard {
            Text(current.title.orEmpty(), color = c.ink, fontWeight = FontWeight.ExtraBold, fontSize = 17.sp)
            Text(stringResource(arStatusLabel(current.status)), color = Ai.Red, fontWeight = FontWeight.Bold, fontSize = 12.sp, modifier = Modifier.padding(top = 2.dp))
            current.notice?.let { Text(it, color = c.mut, fontSize = 11.sp, lineHeight = 16.sp, modifier = Modifier.padding(top = 8.dp)) }
            val locked = current.status == "submitted" || current.status == "accepted"
            if (onOpenEditor != null && !locked) {
                Spacer(Modifier.height(10.dp))
                SecondaryButton(stringResource(R.string.ai_ar_open_editor), Modifier, onClick = { onOpenEditor(current.id) })
            }
            if (locked) Text(stringResource(R.string.ai_ar_locked), color = c.mut, fontSize = 12.sp, modifier = Modifier.padding(top = 8.dp))
        }

        reviews.forEach { r ->
            SiteCard(padding = 14.dp) {
                Text(stringResource(arReviewLabel(r.status)), color = c.ink, fontWeight = FontWeight.ExtraBold, fontSize = 14.sp)
                r.providerNote?.takeIf { it.isNotBlank() }?.let { Text(it, color = c.ink, fontSize = 13.sp, modifier = Modifier.padding(top = 4.dp)) }
                r.quoteMinor?.let { Text(stringResource(R.string.ai_ar_quote, worldMoney(it, r.currency)), color = c.ink, fontWeight = FontWeight.SemiBold, fontSize = 13.sp, modifier = Modifier.padding(top = 4.dp)) }
                if (r.status == "pending") {
                    Spacer(Modifier.height(8.dp))
                    SecondaryButton(stringResource(R.string.ai_ar_withdraw), Modifier, enabled = !busy, onClick = { act { ApiClient.aiAr.withdraw(chatAuth(), current.id, r.id) } })
                }
            }
        }

        if (current.status == "approved") {
            val quote = reviews.firstOrNull { it.status == "approved" }?.quoteMinor ?: 0L
            if (quote > 0) {
                // A priced design is accepted by paying from the wallet; the money is held until the provider delivers.
                Text(stringResource(R.string.ai_ar_escrow_hint), color = c.mut, fontSize = 12.sp, lineHeight = 16.sp)
                PrimaryButton(stringResource(R.string.ai_ar_pay), enabled = !busy, onClick = {
                    scope.launch {
                        val status = runCatching { ApiClient.wallet.pinStatus(walletAuth()).data }.getOrNull()
                        if (status?.hasPin == false) noPin = true else payFor = current
                    }
                })
            } else {
                PrimaryButton(stringResource(R.string.ai_ar_accept), enabled = !busy, onClick = { act { ApiClient.aiAr.accept(chatAuth(), current.id) } })
            }
        }

        order?.let { o ->
            SiteCard(padding = 14.dp) {
                Text(stringResource(arOrderLabel(o.status)), color = c.ink, fontWeight = FontWeight.ExtraBold, fontSize = 14.sp)
                Text(worldMoney(o.amountMinor, o.currency), color = c.ink, fontSize = 13.sp, modifier = Modifier.padding(top = 4.dp))
                o.deliveryNote?.takeIf { it.isNotBlank() }?.let { Text(it, color = c.ink, fontSize = 13.sp, modifier = Modifier.padding(top = 4.dp)) }
                o.disputeReason?.takeIf { it.isNotBlank() }?.let { Text(it, color = c.warn, fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp)) }
                o.resolutionNote?.takeIf { it.isNotBlank() }?.let { Text(it, color = c.mut, fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp)) }
                if (o.status == "paid") {
                    Spacer(Modifier.height(8.dp))
                    SecondaryButton(stringResource(R.string.ai_ar_cancel_order), Modifier, enabled = !busy, onClick = { act { ApiClient.aiAr.cancelOrder(chatAuth(), o.id) } })
                }
                if (o.status == "delivered") {
                    Text(stringResource(R.string.ai_ar_confirm_hint), color = c.mut, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp))
                    Spacer(Modifier.height(8.dp))
                    PrimaryButton(stringResource(R.string.ai_ar_confirm_delivery), enabled = !busy, onClick = { act { ApiClient.aiAr.confirmOrder(chatAuth(), o.id) } })
                    Spacer(Modifier.height(6.dp))
                    if (disputing) {
                        SiteField(stringResource(R.string.ai_ar_dispute_reason), reason, { reason = it }, singleLine = false, minLines = 2)
                        Spacer(Modifier.height(6.dp))
                        PrimaryButton(stringResource(R.string.ai_ar_dispute_send), enabled = !busy && reason.trim().length >= 5, onClick = {
                            act { ApiClient.aiAr.disputeOrder(chatAuth(), o.id, AiArDisputeRequest(reason.trim())); reason = ""; disputing = false }
                        })
                    } else {
                        SecondaryButton(stringResource(R.string.ai_ar_dispute), Modifier, enabled = !busy, onClick = { disputing = true })
                    }
                }
            }
        }

        if (current.status == "active" || current.status == "draft") {
            SiteCard {
                SiteSectionTitle(stringResource(R.string.ai_ar_send_title), stringResource(R.string.ai_ar_send_sub))
                SiteField(stringResource(R.string.ai_ar_provider_search), query, {
                    query = it
                    chosen = null
                    if (it.trim().length >= 2) scope.launch { providers = runCatching { ApiClient.aiAr.searchProviders(chatAuth(), it.trim()).data.orEmpty() }.getOrDefault(emptyList()) }
                    else providers = emptyList()
                })
                providers.forEach { p ->
                    SecondaryButton(p.name.orEmpty() + if (chosen?.id == p.id) "  ✓" else "", Modifier, onClick = { chosen = p })
                }
                Spacer(Modifier.height(6.dp))
                SiteField(stringResource(R.string.ai_ar_note), note, { note = it }, singleLine = false, minLines = 2)
                Spacer(Modifier.height(8.dp))
                PrimaryButton(stringResource(R.string.ai_ar_send), enabled = chosen != null && !busy, onClick = {
                    act { ApiClient.aiAr.submit(chatAuth(), current.id, AiArSubmitRequest(chosen!!.id, note.ifBlank { null })) }
                })
            }
        }
    }

    if (noPin) AiInfoDialog(night = LocalSiteNight.current, message = stringResource(R.string.ai_subscription_no_pin_set), onDismiss = { noPin = false })

    val target = payFor
    if (target != null) {
        AiWalletPinSheet(
            night = LocalSiteNight.current,
            subtitle = stringResource(R.string.ai_ar_pay_pin_sub),
            onDismiss = { payFor = null },
            onSubmit = { pin ->
                runCatching { ApiClient.aiAr.acceptAndPay(chatAuth(), pin, target.id).data }.fold(
                    onSuccess = { payFor = null; reload(target.id); PadResult.Ok },
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
