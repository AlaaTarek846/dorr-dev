package com.dorr.app.ui.screens.wallet

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.CreatePinRequest
import com.dorr.app.network.apiFailure
import kotlinx.coroutines.launch

/** What the PIN dialog should do after the caller ran the PIN-protected action. */
sealed interface PinOutcome {
    /** Action succeeded — close the dialog. */
    data object Done : PinOutcome

    /** PIN problem (wrong/locked) — stay open and show [message] so the user can retry. */
    data class Retry(val message: String) : PinOutcome

    /** Any other failure — close the dialog; the caller shows [message] on its own screen. */
    data class Close(val message: String) : PinOutcome
}

/** PIN-related failures the dialog keeps open for; everything else is the caller's to show. */
internal val PIN_ERROR_CODES = setOf("wallet_pin_invalid", "wallet_pin_locked", "wallet_pin_required", "wallet_pin_not_set")

internal fun isValidPinInput(pin: String) = pin.length == 4 && pin.all { it.isDigit() }

/**
 * The one PIN prompt shared by every sensitive wallet action (wallet-tasks.md
 * §6.1 item 5). [hasPin] decides the mode, and creation is *lazy*: a user with
 * no PIN yet gets the enter + confirm form right here, in place of the verify
 * form — no detour to another screen, no error. On success the new PIN is used
 * straight away for the action that triggered the prompt.
 *
 * [onSubmit] runs the protected action with the PIN and reports back how the
 * dialog should react.
 */
@Composable
fun WalletPinDialog(
    hasPin: Boolean,
    onDismiss: () -> Unit,
    onSubmit: suspend (pin: String) -> PinOutcome,
) {
    var pin by remember { mutableStateOf("") }
    var confirm by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val networkError = stringResource(R.string.wallet_error_network)
    val mismatchError = stringResource(R.string.wallet_pin_mismatch)

    val creating = !hasPin
    val canSubmit = isValidPinInput(pin) && (!creating || isValidPinInput(confirm)) && !busy

    fun submit() {
        if (creating && pin != confirm) {
            error = mismatchError
            return
        }
        busy = true
        error = null
        scope.launch {
            if (creating) {
                val created = runCatching {
                    ApiClient.wallet.createPin(walletAuth(), CreatePinRequest(pin, confirm))
                }.exceptionOrNull()
                if (created != null) {
                    error = created.apiFailure().message ?: networkError
                    busy = false
                    return@launch
                }
            }
            when (val outcome = onSubmit(pin)) {
                PinOutcome.Done -> onDismiss()
                is PinOutcome.Retry -> { error = outcome.message; pin = ""; busy = false }
                is PinOutcome.Close -> onDismiss()
            }
        }
    }

    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text(stringResource(if (creating) R.string.wallet_pin_create_title else R.string.wallet_pin_enter_title)) },
        text = {
            Column {
                if (creating) {
                    Text(stringResource(R.string.wallet_pin_create_hint), style = MaterialTheme.typography.bodyMedium)
                    Spacer(Modifier.height(12.dp))
                }
                PinField(pin, { pin = it }, stringResource(R.string.wallet_pin_label))
                if (creating) {
                    Spacer(Modifier.height(8.dp))
                    PinField(confirm, { confirm = it }, stringResource(R.string.wallet_pin_confirm_label))
                }
                error?.let {
                    Spacer(Modifier.height(8.dp))
                    Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
                }
            }
        },
        confirmButton = {
            TextButton(onClick = { submit() }, enabled = canSubmit) {
                if (busy) CircularProgressIndicator(modifier = Modifier.size(18.dp), strokeWidth = 2.dp) else Text(stringResource(R.string.wallet_pin_confirm_action))
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss, enabled = !busy) { Text(stringResource(R.string.common_cancel)) }
        },
    )
}

@Composable
internal fun PinField(value: String, onChange: (String) -> Unit, label: String) {
    OutlinedTextField(
        value = value,
        onValueChange = { input -> onChange(input.filter { it.isDigit() }.take(4)) },
        label = { Text(label) },
        singleLine = true,
        visualTransformation = PasswordVisualTransformation(),
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
        modifier = Modifier.fillMaxWidth(),
    )
}
