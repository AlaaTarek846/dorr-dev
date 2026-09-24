package com.dorr.app.ui.screens.wallet

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.WalletTransactionDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.theme.AppColors

private enum class HistoryFilter(val label: Int, val direction: String?, val bucket: String?) {
    ALL(R.string.wallet_history_all, null, null),
    IN(R.string.wallet_history_in, "credit", null),
    OUT(R.string.wallet_history_out, "debit", null),
    SPEND_ONLY(R.string.wallet_spend_only, null, "spend_only"),
}

/**
 * Statement: every posted wallet movement, newest first. A row that moved
 * spend-only money says so, because that money can never be withdrawn.
 */
@Composable
fun HistoryScreen(currency: String?) {
    var filter by remember { mutableStateOf(HistoryFilter.ALL) }
    var rows by remember { mutableStateOf<List<WalletTransactionDto>>(emptyList()) }
    var page by remember { mutableIntStateOf(1) }
    var hasMore by remember { mutableStateOf(false) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    val networkError = stringResource(R.string.wallet_error_network)

    // Re-runs when the filter or the requested page changes; page 1 replaces the
    // list, later pages append. A superseded request is cancelled by Compose.
    LaunchedEffect(filter, page) {
        loading = true
        error = null
        runCatching {
            ApiClient.wallet.transactions(walletAuth(), page = page, direction = filter.direction, bucket = filter.bucket)
        }.onSuccess { envelope ->
            val fresh = envelope.data.orEmpty()
            rows = if (page == 1) fresh else rows + fresh
            hasMore = envelope.pagination?.hasMorePages == true
        }.onFailure {
            error = it.apiFailure().message ?: networkError
        }
        loading = false
    }

    Column(modifier = Modifier.fillMaxSize()) {
        LazyRow(
            contentPadding = PaddingValues(horizontal = 20.dp, vertical = 12.dp),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            items(HistoryFilter.entries) { option ->
                FilterChip(
                    selected = filter == option,
                    onClick = {
                        if (filter != option) {
                            filter = option
                            page = 1
                            rows = emptyList()
                        }
                    },
                    label = { Text(stringResource(option.label)) },
                )
            }
        }

        LazyColumn(modifier = Modifier.fillMaxSize(), contentPadding = PaddingValues(horizontal = 20.dp)) {
            items(rows, key = { it.uuid }) { tx ->
                TransactionRow(tx, currency)
                HorizontalDivider()
            }
            item {
                when {
                    loading -> Column(Modifier.fillMaxWidth().padding(24.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                        CircularProgressIndicator()
                    }
                    error != null -> Text(error.orEmpty(), color = MaterialTheme.colorScheme.error, modifier = Modifier.padding(vertical = 16.dp))
                    rows.isEmpty() -> Text(
                        stringResource(R.string.wallet_history_empty),
                        color = AppColors.textSecondary,
                        modifier = Modifier.padding(vertical = 24.dp),
                    )
                    hasMore -> OutlinedButton(onClick = { page += 1 }, modifier = Modifier.fillMaxWidth().padding(vertical = 16.dp)) {
                        Text(stringResource(R.string.wallet_history_more))
                    }
                }
            }
        }
    }
}

@Composable
private fun TransactionRow(tx: WalletTransactionDto, currency: String?) {
    val credit = tx.direction == "credit"
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(modifier = Modifier.weight(1f)) {
            Text(tx.typeLabel, fontWeight = FontWeight.SemiBold)
            if (tx.bucket == "spend_only") {
                Text(stringResource(R.string.wallet_spend_only), color = AppColors.primary, style = MaterialTheme.typography.labelSmall)
            }
            tx.counterparty?.let { party ->
                Text(
                    listOfNotNull(party.name, party.phone).joinToString("  "),
                    color = AppColors.textSecondary,
                    style = MaterialTheme.typography.bodySmall,
                )
            }
            val note = tx.note
            if (!note.isNullOrBlank() && note != tx.typeLabel) {
                Text(note, color = AppColors.textSecondary, style = MaterialTheme.typography.bodySmall)
            }
            Text(tx.createdAt?.take(16)?.replace('T', ' ').orEmpty(), color = AppColors.textSecondary, style = MaterialTheme.typography.bodySmall)
        }
        Column(horizontalAlignment = Alignment.End) {
            Text(
                (if (credit) "+ " else "− ") + formatMinor(tx.amountMinor, currency),
                color = if (credit) Color(0xFF16A34A) else AppColors.danger,
                fontWeight = FontWeight.Bold,
            )
            Text(formatMinor(tx.balanceAfterMinor, null), color = AppColors.textSecondary, style = MaterialTheme.typography.bodySmall)
        }
    }
}
