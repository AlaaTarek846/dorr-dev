package com.dorr.app.ui.screens.wallet

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ChangePinRequest
import com.dorr.app.network.CreatePinRequest
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.components.SettingsScaffold
import com.dorr.app.ui.theme.AppColors
import kotlinx.coroutines.launch

/**
 * Account → Wallet PIN. Same screen creates the PIN the first time and changes
 * it afterwards (changing needs the current PIN — the server counts wrong
 * attempts and locks out, like any other PIN-protected action).
 */
@Composable
fun WalletPinSettingsScreen(onBack: () -> Unit, onSaved: (String) -> Unit) {
    val scope = rememberCoroutineScope()
    var hasPin by remember { mutableStateOf<Boolean?>(null) }
    var loadFailed by remember { mutableStateOf(false) }
    var current by remember { mutableStateOf("") }
    var pin by remember { mutableStateOf("") }
    var confirm by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }

    val networkError = stringResource(R.string.wallet_error_network)
    val mismatchError = stringResource(R.string.wallet_pin_mismatch)
    val savedMessage = stringResource(R.string.wallet_pin_saved)

    LaunchedEffect(Unit) {
        runCatching { ApiClient.wallet.pinStatus(walletAuth()).data?.hasPin }
            .onSuccess { hasPin = it }
            .onFailure { loadFailed = true }
    }

    val changing = hasPin == true
    val canSave = isValidPinInput(pin) && isValidPinInput(confirm) && (!changing || isValidPinInput(current)) && !busy

    SettingsScaffold(title = stringResource(R.string.wallet_pin_title), onBack = onBack) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(20.dp),
        ) {
            when {
                hasPin == null && !loadFailed -> CircularProgressIndicator()
                hasPin == null -> Text(networkError, color = MaterialTheme.colorScheme.error)
                else -> {
                    Text(
                        stringResource(if (changing) R.string.wallet_pin_change_hint else R.string.wallet_pin_create_hint),
                        color = AppColors.textSecondary,
                    )
                    Spacer(Modifier.height(16.dp))
                    if (changing) {
                        PinField(current, { current = it }, stringResource(R.string.wallet_pin_current_label))
                        Spacer(Modifier.height(12.dp))
                    }
                    PinField(pin, { pin = it }, stringResource(if (changing) R.string.wallet_pin_new_label else R.string.wallet_pin_label))
                    Spacer(Modifier.height(12.dp))
                    PinField(confirm, { confirm = it }, stringResource(R.string.wallet_pin_confirm_label))
                    error?.let {
                        Spacer(Modifier.height(12.dp))
                        Text(it, color = MaterialTheme.colorScheme.error)
                    }
                    Spacer(Modifier.height(24.dp))
                    Button(
                        onClick = {
                            if (pin != confirm) {
                                error = mismatchError
                                return@Button
                            }
                            busy = true
                            error = null
                            scope.launch {
                                val failure = runCatching {
                                    if (changing) {
                                        ApiClient.wallet.changePin(walletAuth(), ChangePinRequest(current, pin, confirm))
                                    } else {
                                        ApiClient.wallet.createPin(walletAuth(), CreatePinRequest(pin, confirm))
                                    }
                                }.exceptionOrNull()
                                busy = false
                                if (failure == null) {
                                    onSaved(savedMessage)
                                    onBack()
                                } else {
                                    error = failure.apiFailure().message ?: networkError
                                    current = ""
                                }
                            }
                        },
                        enabled = canSave,
                        modifier = Modifier
                            .fillMaxWidth()
                            .height(50.dp),
                    ) {
                        Text(stringResource(R.string.common_save))
                    }
                }
            }
        }
    }
}
