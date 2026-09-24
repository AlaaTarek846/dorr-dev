package com.dorr.app.ui.screens.wallet

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import androidx.compose.ui.platform.LocalClipboardManager
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.WalletBalanceDto
import com.dorr.app.ui.components.SettingsScaffold
import com.dorr.app.ui.theme.AppColors

/**
 * Sub-screens of the wallet. Withdraw is added with
 * its backend phase (8) — a screen without an endpoint behind it
 * would only be a placeholder.
 */
private enum class WalletSub { OVERVIEW, TOPUP, TRANSFER, HISTORY }

/** Opened from the wallet icon in the Home header. */
@Composable
fun WalletScreen(onBack: () -> Unit) {
    // Not remembered across visits: every time this screen is entered the PIN is asked again.
    var unlocked by remember { mutableStateOf(false) }
    if (!unlocked) {
        WalletPinGate(onUnlocked = { unlocked = true }, onCancel = onBack)
        return
    }

    var sub by remember { mutableStateOf(WalletSub.OVERVIEW) }
    var balance by remember { mutableStateOf<WalletBalanceDto?>(null) }
    var loading by remember { mutableStateOf(true) }
    var failed by remember { mutableStateOf(false) }
    // Bumped to re-fetch the balance (first open, and after a finished top-up).
    var reloadKey by remember { mutableStateOf(0) }

    LaunchedEffect(reloadKey) {
        loading = true
        failed = false
        runCatching { ApiClient.wallet.balance(walletAuth()).data }
            .onSuccess { balance = it }
            .onFailure { failed = true }
        loading = false
    }

    fun goBack() {
        if (sub == WalletSub.OVERVIEW) {
            onBack()
        } else {
            sub = WalletSub.OVERVIEW
        }
    }
    BackHandler { goBack() }

    val title = stringResource(
        when (sub) {
            WalletSub.TOPUP -> R.string.wallet_topup_title
            WalletSub.TRANSFER -> R.string.wallet_transfer_title
            WalletSub.HISTORY -> R.string.wallet_history_title
            WalletSub.OVERVIEW -> R.string.wallet_title
        },
    )
    SettingsScaffold(title = title, onBack = { goBack() }) { padding ->
        Box(modifier = Modifier.padding(padding)) {
            when (sub) {
                WalletSub.OVERVIEW -> WalletOverview(
                    balance = balance,
                    loading = loading,
                    failed = failed,
                    onRetry = { reloadKey++ },
                    onTopup = { sub = WalletSub.TOPUP },
                    onHistory = { sub = WalletSub.HISTORY },
                    onTransfer = { sub = WalletSub.TRANSFER },
                )
                WalletSub.TRANSFER -> TransferScreen(
                    balance = balance,
                    onDone = { reloadKey++; sub = WalletSub.OVERVIEW },
                )
                WalletSub.TOPUP -> TopupScreen(
                    balance = balance,
                    onDone = { reloadKey++; sub = WalletSub.OVERVIEW },
                )
                WalletSub.HISTORY -> HistoryScreen(currency = balance?.currencyCode)
            }
        }
    }
}

@Composable
private fun WalletOverview(
    balance: WalletBalanceDto?,
    loading: Boolean,
    failed: Boolean,
    onRetry: () -> Unit,
    onTopup: () -> Unit,
    onHistory: () -> Unit,
    onTransfer: () -> Unit,
) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(20.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        when {
            balance == null && loading -> Box(Modifier.fillMaxWidth().padding(40.dp), contentAlignment = Alignment.Center) {
                CircularProgressIndicator()
            }
            balance == null && failed -> {
                Text(stringResource(R.string.wallet_error_network), color = MaterialTheme.colorScheme.error)
                OutlinedButton(onClick = onRetry) { Text(stringResource(R.string.wallet_retry)) }
            }
            balance != null -> {
                BalanceCard(balance)
                MyWalletNumber(balance)
                balance.otherWallets.orEmpty().takeIf { it.isNotEmpty() }?.let { others ->
                    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text(stringResource(R.string.wallet_other_wallets), fontWeight = FontWeight.SemiBold)
                        others.forEach { other ->
                            Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                                Text(other.countryCode.orEmpty())
                                Text(formatMinor(other.totalMinor, other.currencyCode))
                            }
                        }
                        Text(
                            stringResource(R.string.wallet_other_wallets_note),
                            color = AppColors.textSecondary,
                            style = MaterialTheme.typography.bodySmall,
                        )
                    }
                }
                Button(
                    onClick = onTopup,
                    modifier = Modifier
                        .fillMaxWidth()
                        .height(50.dp),
                ) {
                    Icon(Icons.Rounded.Add, contentDescription = null)
                    Text(stringResource(R.string.wallet_topup_title), modifier = Modifier.padding(start = 8.dp))
                }
                OutlinedButton(
                    onClick = onTransfer,
                    modifier = Modifier
                        .fillMaxWidth()
                        .height(50.dp),
                ) {
                    Text(stringResource(R.string.wallet_transfer_title))
                }
                OutlinedButton(
                    onClick = onHistory,
                    modifier = Modifier
                        .fillMaxWidth()
                        .height(50.dp),
                ) {
                    Text(stringResource(R.string.wallet_history_title))
                }
            }
        }
    }
}

/** The number others use to send money to THIS country wallet, with a copy button. */
@Composable
private fun MyWalletNumber(balance: WalletBalanceDto) {
    val number = balance.walletNumber ?: return
    val shown = balance.walletNumberFormatted ?: number
    val clipboard = LocalClipboardManager.current
    var copied by remember { mutableStateOf(false) }
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(16.dp))
            .background(MaterialTheme.colorScheme.surfaceVariant)
            .padding(horizontal = 16.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.SpaceBetween,
    ) {
        Column(modifier = Modifier.weight(1f)) {
            Text(
                stringResource(R.string.wallet_my_number, balance.countryCode.orEmpty()),
                color = AppColors.textSecondary,
                style = MaterialTheme.typography.bodySmall,
            )
            Text(shown, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
        }
        TextButton(onClick = { clipboard.setText(AnnotatedString(shown)); copied = true }) {
            Text(stringResource(if (copied) R.string.wallet_number_copied else R.string.wallet_number_copy))
        }
    }
}

@Composable
private fun BalanceCard(balance: WalletBalanceDto) {
    val currency = balance.currencyCode
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(20.dp))
            .background(AppColors.headerBackground)
            .padding(20.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        Text(stringResource(R.string.wallet_total_balance), color = Color.White.copy(alpha = 0.8f))
        Text(
            formatMinor(balance.totalMinor, currency),
            color = Color.White,
            style = MaterialTheme.typography.headlineLarge,
            fontWeight = FontWeight.Bold,
        )
        BalancePart(stringResource(R.string.wallet_withdrawable), stringResource(R.string.wallet_withdrawable_note), formatMinor(balance.withdrawableMinor, currency))
        BalancePart(stringResource(R.string.wallet_spend_only), stringResource(R.string.wallet_spend_only_note), formatMinor(balance.spendOnlyMinor, currency))
        if (balance.heldMinor > 0) {
            BalancePart(stringResource(R.string.wallet_held), stringResource(R.string.wallet_held_note), formatMinor(balance.heldMinor, currency))
        }
    }
}

@Composable
private fun BalancePart(label: String, note: String, value: String) {
    Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Column(modifier = Modifier.weight(1f)) {
            Text(label, color = Color.White, fontWeight = FontWeight.SemiBold)
            Text(note, color = Color.White.copy(alpha = 0.7f), style = MaterialTheme.typography.bodySmall)
        }
        Spacer(Modifier.width(12.dp))
        Text(value, color = Color.White, fontWeight = FontWeight.SemiBold)
    }
}
