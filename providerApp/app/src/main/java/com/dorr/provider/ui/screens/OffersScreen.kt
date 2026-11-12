package com.dorr.provider.ui.screens

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.clickable
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Store
import androidx.compose.ui.draw.clip
import com.dorr.provider.ui.kit.settingsSurface
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.RequestQuote
import androidx.compose.material.icons.rounded.Undo
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.provider.R
import com.dorr.provider.net.ApiClient
import com.dorr.provider.net.MovingOfferDto
import com.dorr.provider.net.PagedRows
import com.dorr.provider.net.Realtime
import com.dorr.provider.net.Session
import com.dorr.provider.net.apiDone
import com.dorr.provider.net.can
import com.dorr.provider.net.collectReconnectTick
import com.dorr.provider.net.errorText
import com.dorr.provider.net.included
import com.dorr.provider.ui.components.Card
import com.dorr.provider.ui.components.ChipRow
import com.dorr.provider.ui.components.EmptyState
import com.dorr.provider.ui.components.ErrorBanner
import com.dorr.provider.ui.components.Page
import com.dorr.provider.ui.components.PagedFooter
import com.dorr.provider.ui.kit.ConfirmDialog
import com.dorr.provider.ui.kit.settingsAccent
import com.dorr.provider.ui.kit.settingsInk
import com.dorr.provider.ui.kit.settingsMut
import kotlinx.coroutines.launch

/**
 * The establishment's offers (C-04): all of them, or those waiting for the approver (`offers.approve`) — an offer above its sender's
 * limit waits there (§17). An offer the customer hasn't answered can be changed or taken back (`offers.manage`). Read on open, when
 * the connection returns and on every notice.
 */
@Composable
fun OffersScreen(startOnApproval: Boolean, onBack: () -> Unit, onOpenRequest: (String) -> Unit) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val seat by Session.membership.collectAsState()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var filter by rememberSaveable { mutableStateOf(if (startOnApproval) "awaiting_approval" else "") }
    var editing by remember { mutableStateOf<MovingOfferDto?>(null) }
    var withdrawing by remember { mutableStateOf<MovingOfferDto?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var info by remember { mutableStateOf<String?>(null) }
    val approvedText = stringResource(R.string.mv_offer_approved_ok)

    val paged = remember(filter) { PagedRows<MovingOfferDto>(20, { it.uuid }) { page -> apiCall { ApiClient.moving.offers(filter.ifEmpty { null }, page) } } }
    LaunchedEffect(reconnect, filter) { paged.reload() }
    LaunchedEffect(filter) { Realtime.changed.collect { paged.reload() } }

    editing?.let { offer ->
        BackHandler { editing = null }
        OfferForm(move = null, offer = offer, onBack = { editing = null }, onDone = { message ->
            info = message
            editing = null
            scope.launch { paged.reload() }
        })
        return
    }

    fun approve(offer: MovingOfferDto) {
        busy = true
        error = null
        scope.launch {
            val result = apiCall { ApiClient.moving.approveOffer(offer.uuid) }
            busy = false
            if (result is ApiResult.Ok) {
                info = approvedText
                paged.reload()
            } else {
                error = result.errorText(offlineText) ?: genericError
            }
        }
    }

    fun withdraw(offer: MovingOfferDto) {
        busy = true
        scope.launch {
            val failed = apiDone(offlineText, genericError) { ApiClient.moving.withdrawOffer(offer.uuid) }
            busy = false
            withdrawing = null
            if (failed != null) error = failed else paged.reload()
        }
    }

    Page(title = stringResource(R.string.mv_offers_title), onBack = onBack, onNearEnd = { scope.launch { paged.loadMore() } }) {
        if (seat.can("offers.approve")) {
            ChipRow(
                options = listOf("" to stringResource(R.string.mv_filter_all), "awaiting_approval" to stringResource(R.string.mv_offer_awaiting_approval)),
                selected = setOf(filter),
                onToggle = { filter = it },
                modifier = Modifier.fillMaxWidth(),
            )
        }
        info?.let { Text(it, color = Green, fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.fillMaxWidth()) }
        when {
            !paged.loaded -> com.dorr.provider.ui.components.SkeletonList(3, Modifier.fillMaxWidth())
            paged.rows.isEmpty() -> EmptyState(Icons.Rounded.RequestQuote, stringResource(R.string.mv_offers_empty_title), stringResource(R.string.mv_offers_empty_body), Modifier.padding(top = 30.dp))
            else -> paged.rows.forEach { offer ->
                OfferCard(
                    offer, busy,
                    canManage = seat.can("offers.manage") && offer.status in setOf("sent", "awaiting_approval"),
                    canApprove = seat.can("offers.approve") && offer.status == "awaiting_approval",
                    onEdit = { editing = offer },
                    onWithdraw = { withdrawing = offer },
                    onApprove = { approve(offer) },
                    onOpenRequest = onOpenRequest,
                )
            }
        }
        PagedFooter(paged, onRetry = { paged.retry(); scope.launch { paged.loadMore() } })
        ErrorBanner(error ?: paged.failure?.errorText(offlineText), Modifier.fillMaxWidth())
    }

    withdrawing?.let { offer ->
        ConfirmDialog(
            icon = Icons.Rounded.Undo,
            title = stringResource(R.string.mv_withdraw_title),
            message = stringResource(R.string.mv_withdraw_message),
            confirmLabel = stringResource(R.string.mv_withdraw),
            loading = busy,
            onConfirm = { withdraw(offer) },
            onDismiss = { if (!busy) withdrawing = null },
        )
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun OfferCard(
    offer: MovingOfferDto,
    busy: Boolean,
    canManage: Boolean,
    canApprove: Boolean,
    onEdit: () -> Unit,
    onWithdraw: () -> Unit,
    onApprove: () -> Unit,
    onOpenRequest: (String) -> Unit,
) {
    val color = offerStatusColor(offer.status)
    Column(
        Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(22.dp))
            .then(if (offer.requestUuid != null) Modifier.clickable { onOpenRequest(offer.requestUuid) } else Modifier)
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(40.dp).clip(RoundedCornerShape(12.dp)).background(color.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                MvVehicleImage(offer.vehicle?.type?.image, 30)
            }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(formatMinor(offer.priceMinor, offer.currencyCode), color = settingsInk(), fontSize = 19.sp, fontWeight = FontWeight.Black)
                offer.vehicle?.type?.name?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
            }
            StatusPill(offerStatusLabel(offer.status), color)
        }
        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            dateTimeText(offer.arrivalAt).takeIf { it.isNotBlank() }?.let { MvChip(Icons.Rounded.Schedule, it) }
            offer.workersCount?.takeIf { it > 0 }?.let { MvChip(Icons.Rounded.Groups, stringResource(R.string.mv_workers_n, it), Slate) }
            offer.tripsCount?.takeIf { it > 1 }?.let { MvChip(null, stringResource(R.string.mv_trips_n, it), Slate) }
            offer.branch?.takeIf { it.isNotBlank() }?.let { MvChip(Icons.Rounded.Store, it, Slate) }
            offer.included().filter { it != "tax" }.forEach { MvChip(includeIcon(it), includeLabel(it), Slate) }
        }
        InfoLine(stringResource(R.string.mv_valid_until), dateTimeText(offer.validUntil))
        if (offer.status == "awaiting_approval") Text(stringResource(R.string.mv_awaiting_approval_hint), color = Amber, fontSize = 12.sp, lineHeight = 18.sp)
        if (canManage || canApprove) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                if (canApprove) MvOutlineButton(stringResource(R.string.mv_approve), Green, busy, Modifier.weight(1f), onApprove)
                if (canManage) {
                    MvOutlineButton(stringResource(R.string.common_edit), settingsAccent(), busy, Modifier.weight(1f), onEdit)
                    MvOutlineButton(stringResource(R.string.mv_withdraw), Red, busy, Modifier.weight(1f), onWithdraw)
                }
            }
        }
    }
}
