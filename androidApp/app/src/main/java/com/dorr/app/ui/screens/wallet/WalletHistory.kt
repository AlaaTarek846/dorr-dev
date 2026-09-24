package com.dorr.app.ui.screens.wallet

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.List
import androidx.compose.material.icons.rounded.CardGiftcard
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.NorthEast
import androidx.compose.material.icons.rounded.SouthWest
import androidx.compose.material.icons.rounded.Warning
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.WalletTransactionDto
import com.dorr.app.network.apiFailure
import java.time.LocalDate

private enum class HistoryFilter(val label: Int, val icon: ImageVector, val direction: String?, val bucket: String?) {
    ALL(R.string.wa_filter_all, Icons.AutoMirrored.Rounded.List, null, null),
    IN(R.string.wa_filter_in, Icons.Rounded.SouthWest, "credit", null),
    OUT(R.string.wa_filter_out, Icons.Rounded.NorthEast, "debit", null),
    SPEND_ONLY(R.string.wa_spend_only, Icons.Rounded.CardGiftcard, null, "spend_only"),
}

/** The statement: every posted movement, newest first, grouped by day, filterable, paged. */
@Composable
fun WalletHistory() {
    val host = LocalWallet.current
    var filter by remember { mutableStateOf(HistoryFilter.ALL) }
    var rows by remember { mutableStateOf<List<WalletTransactionDto>>(emptyList()) }
    var page by remember { mutableIntStateOf(1) }
    var hasMore by remember { mutableStateOf(false) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    val networkError = stringResource(R.string.wa_error_network)

    LaunchedEffect(Unit) { if (host.balance == null) host.refreshBalance() }

    // A filter change or "show more" re-runs this; page 1 replaces the list, later pages append.
    LaunchedEffect(filter, page) {
        loading = true
        error = null
        runCatching { ApiClient.wallet.transactions(walletAuth(), page = page, perPage = 15, direction = filter.direction, bucket = filter.bucket) }
            .onSuccess { envelope ->
                val fresh = envelope.data.orEmpty()
                rows = if (page == 1) fresh else rows + fresh
                hasMore = envelope.pagination?.hasMorePages == true
            }
            .onFailure { error = it.apiFailure().message ?: networkError }
        loading = false
    }

    WaPage(title = stringResource(R.string.wa_history_title), onBack = { host.pop() }) {
        Row(Modifier.horizontalScroll(rememberScrollState()).padding(bottom = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            HistoryFilter.entries.forEach { option ->
                WaChip(stringResource(option.label), option.icon, selected = filter == option) {
                    if (filter != option) {
                        filter = option
                        page = 1
                        rows = emptyList()
                    }
                }
            }
        }

        when {
            error != null -> WaError(error)
            loading && rows.isEmpty() -> WaCard(Modifier.fillMaxWidth().padding(horizontal = 0.dp)) {
                Column(Modifier.padding(horizontal = 14.dp)) { repeat(5) { WaTxSkeleton() } }
            }
            rows.isEmpty() -> WaCard(Modifier.fillMaxWidth()) {
                WaEmpty(Icons.Rounded.History, Tone.Gray, stringResource(R.string.wa_history_empty_title), stringResource(R.string.wa_history_empty_text))
            }
            else -> {
                // Group consecutive rows of the same calendar day under one heading.
                val groups = mutableListOf<Pair<LocalDate?, MutableList<WalletTransactionDto>>>()
                rows.forEach { tx ->
                    val day = dayKey(tx.createdAt)
                    if (groups.isEmpty() || groups.last().first != day) groups.add(day to mutableListOf(tx)) else groups.last().second.add(tx)
                }
                groups.forEach { (_, items) ->
                    Text(
                        dayText(items.first().createdAt),
                        color = Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold,
                        modifier = Modifier.padding(start = 4.dp, end = 4.dp, top = 16.dp, bottom = 8.dp),
                    )
                    WaCard(Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(horizontal = 14.dp, vertical = 4.dp)) {
                            items.forEachIndexed { index, tx ->
                                WaTxRow(tx, host, withDay = true)
                                if (index < items.lastIndex) WaDivider()
                            }
                        }
                    }
                }
                if (hasMore) {
                    androidx.compose.foundation.layout.Spacer(Modifier.padding(top = 12.dp))
                    WaButton(stringResource(R.string.wa_show_more), { page += 1 }, style = WaButtonStyle.Ghost, loading = loading)
                }
            }
        }
    }
}

/** Filter pill (`wa-chip`): white with a hairline border, red and filled when selected. */
@Composable
fun WaChip(text: String, icon: ImageVector?, selected: Boolean, onClick: () -> Unit) {
    val source = remember { MutableInteractionSource() }
    val pressScale by rememberPressScale(source, 0.92f)
    Row(
        Modifier
            .scale(pressScale)
            .clip(RoundedCornerShape(999.dp))
            .background(if (selected) Wa.Red else Color.White)
            .border(1.5.dp, if (selected) Wa.Red else Wa.Line, RoundedCornerShape(999.dp))
            .clickable(interactionSource = source, indication = null, onClick = onClick)
            .padding(horizontal = 14.dp, vertical = 7.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        if (icon != null) Icon(icon, null, tint = if (selected) Color.White else Wa.Ink, modifier = Modifier.size(16.dp))
        Text(text, color = if (selected) Color.White else Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold)
    }
}
