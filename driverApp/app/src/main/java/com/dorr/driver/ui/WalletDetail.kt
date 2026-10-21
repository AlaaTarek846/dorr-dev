package com.dorr.driver.ui

import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Undo
import androidx.compose.material.icons.filled.ArrowDownward
import androidx.compose.material.icons.filled.ArrowUpward
import androidx.compose.material.icons.filled.DirectionsCar
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
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
import com.dorr.driver.ui.theme.DorrBrand
import com.dorr.driver.R
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.WalletDetailDto
import com.dorr.driver.net.WalletDto

/** What narrows the statement (D-213): the kind of row, money in or out, and a range of days. */
internal data class StatementFilter(val type: String? = null, val direction: String? = null, val from: String? = null, val to: String? = null) {
    val active: Boolean get() = type != null || direction != null || from != null || to != null
}

private val TYPES = listOf(
    "service_earning" to R.string.wl_ty_service_earning,
    "tip" to R.string.wl_ty_tip,
    "commission" to R.string.wl_ty_commission,
    "withdrawal" to R.string.wl_ty_withdrawal,
    "topup" to R.string.wl_ty_topup,
    "penalty" to R.string.wl_ty_penalty,
)

/** The statement's filters: in / out, the kind of row, from–to — one scrolling row, each chip tapped again clears itself. */
@Composable
internal fun StatementFilters(filter: StatementFilter, onChange: (StatementFilter) -> Unit) {
    var picking by remember { mutableStateOf<String?>(null) }
    Column(Modifier.padding(bottom = 8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Row(Modifier.horizontalScroll(rememberScrollState()).padding(horizontal = 16.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            FilterPill(stringResource(R.string.wl_filter_in), filter.direction == "credit") { onChange(filter.copy(direction = if (filter.direction == "credit") null else "credit")) }
            FilterPill(stringResource(R.string.wl_filter_out), filter.direction == "debit") { onChange(filter.copy(direction = if (filter.direction == "debit") null else "debit")) }
            TYPES.forEach { (key, label) ->
                FilterPill(stringResource(label), filter.type == key) { onChange(filter.copy(type = if (filter.type == key) null else key)) }
            }
        }
        Row(Modifier.horizontalScroll(rememberScrollState()).padding(horizontal = 16.dp), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
            FilterPill(stringResource(R.string.wl_filter_from) + ": " + (filter.from?.let { dateLabel(it) } ?: "—"), filter.from != null) {
                if (filter.from != null) onChange(filter.copy(from = null)) else picking = "from"
            }
            FilterPill(stringResource(R.string.wl_filter_to) + ": " + (filter.to?.let { dateLabel(it) } ?: "—"), filter.to != null) {
                if (filter.to != null) onChange(filter.copy(to = null)) else picking = "to"
            }
            if (filter.active) {
                Text(
                    stringResource(R.string.wl_filter_clear), color = DorrBrand.orange, fontWeight = FontWeight.SemiBold, fontSize = 13.sp,
                    modifier = Modifier.clip(RoundedCornerShape(50)).clickable { onChange(StatementFilter()) }.padding(horizontal = 8.dp, vertical = 6.dp),
                )
            }
        }
    }
    picking?.let { which ->
        DatePickDialog(
            onPicked = { onChange(if (which == "from") filter.copy(from = it) else filter.copy(to = it)) },
            onDismiss = { picking = null },
        )
    }
}

@Composable
private fun FilterPill(label: String, selected: Boolean, onClick: () -> Unit) {
    val bg by animateColorAsState(if (selected) DorrBrand.navy else DorrBrand.navy.copy(alpha = 0.07f), tween(200), label = "filter-pill")
    Text(
        label, color = if (selected) Color.White else DorrBrand.navy, fontWeight = FontWeight.SemiBold, fontSize = 13.sp, maxLines = 1,
        modifier = Modifier.clip(RoundedCornerShape(50)).background(bg).clickable(onClick = onClick).padding(horizontal = 14.dp, vertical = 7.dp),
    )
}

/**
 * One statement row in full (D-212): the amount and which way it went — said in words as well as
 * colour — the date, the note, the balances after it, the ride it came from, the other rows of the same
 * operation, and whether it was reversed.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun TransactionDetailSheet(uuid: String, w: WalletDto?, onClose: () -> Unit) {
    var detail by remember { mutableStateOf<WalletDetailDto?>(null) }
    var failed by remember { mutableStateOf(false) }
    LaunchedEffect(uuid) {
        when (val r = apiCall { DriverBackend.api.walletTransaction(uuid) }) {
            is ApiResult.Ok -> detail = r.data
            else -> failed = true
        }
    }
    ModalBottomSheet(onDismissRequest = onClose, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 28.dp)) {
            Text(stringResource(R.string.wl_det_title), fontWeight = FontWeight.Bold, fontSize = 18.sp)
            Spacer(Modifier.height(14.dp))
            val d = detail
            when {
                d != null -> {
                    DetailBody(d, w)
                    Spacer(Modifier.height(16.dp))
                    Text(
                        stringResource(R.string.sp_not_me), color = DorrBrand.danger, fontWeight = FontWeight.Bold, fontSize = 13.sp,
                        modifier = Modifier.fillMaxWidth().clip(androidx.compose.foundation.shape.RoundedCornerShape(12.dp)).clickable {
                            SupportPrefill.open.value = Triple("unauthorized_transaction", uuid, "")
                            onClose()
                        }.padding(12.dp),
                    )
                }
                failed -> Text(stringResource(R.string.wl_det_failed), color = DorrBrand.danger)
                else -> Box(Modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(28.dp), strokeWidth = 2.dp, color = DorrBrand.orange) }
            }
        }
    }
}

@Composable
private fun DetailBody(d: WalletDetailDto, w: WalletDto?) {
    val credit = d.direction == "credit"
    val color = if (credit) DorrBrand.success else DorrBrand.danger
    Row(verticalAlignment = Alignment.CenterVertically) {
        Icon(if (credit) Icons.Filled.ArrowDownward else Icons.Filled.ArrowUpward, null, tint = color, modifier = Modifier.size(22.dp))
        Spacer(Modifier.width(8.dp))
        Column {
            Text(d.typeLabel, fontWeight = FontWeight.SemiBold)
            Text(stringResource(if (credit) R.string.wl_det_in else R.string.wl_det_out), color = color, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
        }
    }
    Text((if (credit) "+" else "−") + amount(d.amountMinor, w), color = color, fontWeight = FontWeight.ExtraBold, fontSize = 30.sp, modifier = Modifier.padding(top = 6.dp))
    d.note?.takeIf { it.isNotBlank() }?.let { Text(it, fontSize = 13.sp, modifier = Modifier.padding(top = 4.dp)) }
    Spacer(Modifier.height(12.dp))

    DetailRow(stringResource(R.string.wl_det_date), whenText(d.createdAt))
    d.balanceAfterMinor?.let { DetailRow(stringResource(R.string.wl_det_balance_after) + " (" + bucketLabel(d.bucket) + ")", amount(it, w)) }
    d.totalBalanceAfterMinor?.let { DetailRow(stringResource(R.string.wl_det_total_after), amount(it, w)) }

    d.reference?.takeIf { it.type == "ride" && !it.number.isNullOrBlank() }?.let { ref ->
        Row(
            Modifier.fillMaxWidth().padding(top = 12.dp).clip(RoundedCornerShape(14.dp)).background(DorrBrand.navy.copy(alpha = 0.06f)).padding(12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Filled.DirectionsCar, null, tint = DorrBrand.navy, modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(10.dp))
            Text(stringResource(R.string.wl_det_ride, ref.number.orEmpty()), fontWeight = FontWeight.SemiBold)
        }
    }
    if (d.reversed) NoticeRow(stringResource(R.string.wl_det_reversed))
    if (d.reversesUuid != null) NoticeRow(stringResource(R.string.wl_det_reversal))

    if (d.related.isNotEmpty()) {
        Spacer(Modifier.height(16.dp))
        Text(stringResource(R.string.wl_det_related), fontWeight = FontWeight.Bold, fontSize = 14.sp)
        d.related.forEach { r ->
            val rc = r.direction == "credit"
            Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                Icon(if (rc) Icons.Filled.ArrowDownward else Icons.Filled.ArrowUpward, null, tint = if (rc) DorrBrand.success else DorrBrand.danger, modifier = Modifier.size(16.dp))
                Spacer(Modifier.width(8.dp))
                Text(r.typeLabel, Modifier.weight(1f), fontSize = 13.sp)
                Text((if (rc) "+" else "−") + amount(r.amountMinor, w), fontWeight = FontWeight.Bold, fontSize = 13.sp, color = if (rc) DorrBrand.success else DorrBrand.danger)
            }
        }
    }
}

@Composable
private fun DetailRow(label: String, value: String) {
    Row(Modifier.fillMaxWidth().padding(vertical = 5.dp)) {
        Text(label, Modifier.weight(1f), fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
        Text(value, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
private fun NoticeRow(text: String) {
    Row(Modifier.fillMaxWidth().padding(top = 12.dp).clip(RoundedCornerShape(14.dp)).background(DorrBrand.warning.copy(alpha = 0.14f)).padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(Icons.AutoMirrored.Filled.Undo, null, tint = DorrBrand.warning, modifier = Modifier.size(18.dp))
        Spacer(Modifier.width(10.dp))
        Text(text, fontSize = 13.sp)
    }
}

@Composable
private fun bucketLabel(bucket: String): String = when (bucket) {
    "withdrawable" -> stringResource(R.string.wl_withdrawable)
    "spend_only" -> stringResource(R.string.wl_spend_only)
    else -> stringResource(R.string.wl_det_balance)
}
