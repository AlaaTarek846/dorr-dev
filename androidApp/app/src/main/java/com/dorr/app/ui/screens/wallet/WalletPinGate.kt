package com.dorr.app.ui.screens.wallet

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.apiFailure

/**
 * Shown every time the wallet is entered, before any wallet screen. With a PIN
 * it asks for it; without one, [WalletPinDialog] runs its lazy enter + confirm
 * creation. The wallet opens ([onUnlocked]) only after that succeeds; cancelling
 * goes [onCancel] (back out of the wallet).
 *
 * The "unlocked" state lives in WalletScreen and is dropped when that screen
 * leaves composition, so leaving the wallet and coming back asks again.
 */
@Composable
fun WalletPinGate(onUnlocked: () -> Unit, onCancel: () -> Unit) {
    val networkError = stringResource(R.string.wallet_error_network)
    var hasPin by remember { mutableStateOf<Boolean?>(null) }
    var unlocked by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) {
        hasPin = runCatching { ApiClient.wallet.pinStatus(walletAuth()).data?.hasPin }.getOrNull()
        if (hasPin == null) onCancel() // can't tell whether a PIN exists: don't open the wallet
    }

    val known = hasPin
    if (known == null) {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
        return
    }

    WalletPinDialog(
        hasPin = known,
        onDismiss = { if (!unlocked) onCancel() },
        onSubmit = { pin ->
            // A brand-new PIN was just created by the dialog and is already proven; otherwise verify it.
            if (!known) {
                unlocked = true
                onUnlocked()
                return@WalletPinDialog PinOutcome.Done
            }
            try {
                ApiClient.wallet.verifyPin(walletAuth(), pin)
                unlocked = true
                onUnlocked()
                PinOutcome.Done
            } catch (e: Exception) {
                if (e is kotlinx.coroutines.CancellationException) throw e
                val failure = e.apiFailure()
                PinOutcome.Retry(if (failure.httpStatus == null) networkError else failure.message ?: networkError)
            }
        },
    )
}
