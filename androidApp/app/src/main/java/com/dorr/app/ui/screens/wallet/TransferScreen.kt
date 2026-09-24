package com.dorr.app.ui.screens.wallet

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Tab
import androidx.compose.material3.TabRow
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.TransferLookupRequest
import com.dorr.app.network.TransferRecipientDto
import com.dorr.app.network.TransferRequest
import com.dorr.app.network.WalletBalanceDto
import com.dorr.app.network.WalletTransactionDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.theme.AppColors
import kotlinx.coroutines.launch
import java.util.UUID

private enum class TransferMode(val api: String) { PHONE("phone"), WALLET("wallet") }

/** Arabic-Indic / Persian digits → ASCII, everything else that isn't a digit dropped. */
internal fun asciiDigits(text: String): String = buildString {
    for (c in text) {
        when (c) {
            in '0'..'9' -> append(c)
            in '٠'..'٩' -> append('0' + (c - '٠'))
            in '۰'..'۹' -> append('0' + (c - '۰'))
        }
    }
}

/**
 * What was typed → the national number this country expects (dial code and a
 * leading 0 dropped), or null if it doesn't fit the country's phone shape.
 * Mirrors the server's rule — the server stays the judge, this is live feedback.
 */
internal fun nationalPhone(raw: String, dialCode: String?, length: Int?, prefix: String?): String? {
    var digits = asciiDigits(raw)
    val dial = dialCode.orEmpty().trimStart('+')
    if (length != null && dial.isNotEmpty() && digits.length == dial.length + length && digits.startsWith(dial)) {
        digits = digits.substring(dial.length)
    }
    if (length != null && digits.length == length + 1 && digits.startsWith("0")) digits = digits.substring(1)
    if (length != null && digits.length != length) return null
    if (!prefix.isNullOrEmpty() && !digits.startsWith(prefix)) return null
    return digits
}

/** 11 digits, the last one a Luhn check digit — catches a mistyped number before asking the server. */
internal fun isValidWalletNumber(digits: String): Boolean {
    if (digits.length != 11 || !digits.all { it.isDigit() }) return false
    var sum = 0
    // Walk right to left; the check digit sits in an even position, so every second digit doubles from the left of it.
    digits.reversed().forEachIndexed { index, c ->
        var n = c - '0'
        if (index % 2 == 1) {
            n *= 2
            if (n > 9) n -= 9
        }
        sum += n
    }
    return sum % 10 == 0
}

/** "12345678901" → "123 4567 8901". */
internal fun groupWalletNumber(digits: String): String =
    if (digits.length == 11) "${digits.substring(0, 3)} ${digits.substring(3, 7)} ${digits.substring(7)}" else digits

/**
 * Send money to another user in the same country, in two steps: find the
 * recipient (by phone or by wallet number) and see who they are — first letter
 * of each name, the rest hidden — *before* choosing an amount and paying with
 * the PIN. What the recipient receives is spend-only.
 */
@Composable
fun TransferScreen(balance: WalletBalanceDto?, onDone: () -> Unit) {
    val scope = rememberCoroutineScope()
    val genericError = stringResource(R.string.wallet_error_generic)
    val networkError = stringResource(R.string.wallet_error_network)
    val expiredError = stringResource(R.string.wallet_transfer_expired)

    var mode by remember { mutableStateOf(TransferMode.PHONE) }
    var phone by remember { mutableStateOf("") }
    var walletNumber by remember { mutableStateOf("") }
    var recipient by remember { mutableStateOf<TransferRecipientDto?>(null) }
    var looking by remember { mutableStateOf(false) }
    var amountText by remember { mutableStateOf("") }
    var screenError by remember { mutableStateOf<String?>(null) }
    var showPin by remember { mutableStateOf(false) }
    var hasPin by remember { mutableStateOf(true) }
    var checkingPin by remember { mutableStateOf(false) }
    var sent by remember { mutableStateOf<WalletTransactionDto?>(null) }
    // Same key on a retry of the *same* request (dropped connection), a fresh
    // one once the server has answered either way.
    var idempotencyKey by remember { mutableStateOf(UUID.randomUUID().toString()) }

    val amountMinor = parseAmountToMinor(amountText)
    val currency = balance?.currencyCode

    val done = sent
    if (done != null) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(24.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp, Alignment.CenterVertically),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(stringResource(R.string.wallet_transfer_success), style = MaterialTheme.typography.headlineSmall)
            Text(formatMinor(done.amountMinor, currency), style = MaterialTheme.typography.titleLarge)
            done.counterparty?.let { Text(listOfNotNull(it.name, it.phone).joinToString("  "), color = AppColors.textSecondary) }
            Button(onClick = onDone, modifier = Modifier.fillMaxWidth()) { Text(stringResource(R.string.wallet_topup_done)) }
        }
        return
    }

    val found = recipient

    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(20.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        if (found == null) {
            // ---- Step 1: who? ----
            Text(
                stringResource(R.string.wallet_transfer_country_note, currency.orEmpty()),
                color = AppColors.textSecondary,
                style = MaterialTheme.typography.bodyMedium,
            )

            TabRow(selectedTabIndex = mode.ordinal) {
                Tab(
                    selected = mode == TransferMode.PHONE,
                    onClick = { mode = TransferMode.PHONE; screenError = null },
                    text = { Text(stringResource(R.string.wallet_transfer_mode_phone)) },
                )
                Tab(
                    selected = mode == TransferMode.WALLET,
                    onClick = { mode = TransferMode.WALLET; screenError = null },
                    text = { Text(stringResource(R.string.wallet_transfer_mode_wallet)) },
                )
            }

            val national = nationalPhone(phone, balance?.dialCode, balance?.phoneLength, balance?.phoneStartsWith)
            val walletDigits = asciiDigits(walletNumber)
            val ready: Boolean

            if (mode == TransferMode.PHONE) {
                val invalid = phone.isNotBlank() && national == null
                ready = national != null
                OutlinedTextField(
                    value = phone,
                    onValueChange = { phone = it.take(20) },
                    label = { Text(stringResource(R.string.wallet_transfer_recipient)) },
                    prefix = { Text(balance?.dialCode.orEmpty()) },
                    isError = invalid,
                    supportingText = {
                        if (invalid) {
                            Text(
                                stringResource(
                                    R.string.wallet_transfer_phone_invalid,
                                    balance?.countryCode.orEmpty(),
                                    balance?.phoneLength ?: 0,
                                    balance?.phoneStartsWith.orEmpty(),
                                ),
                            )
                        }
                    },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
                    modifier = Modifier.fillMaxWidth(),
                )
            } else {
                val complete = walletDigits.length == 11
                val invalid = complete && !isValidWalletNumber(walletDigits)
                ready = complete && !invalid
                OutlinedTextField(
                    value = groupWalletNumber(walletDigits),
                    onValueChange = { walletNumber = asciiDigits(it).take(11) },
                    label = { Text(stringResource(R.string.wallet_transfer_wallet_number)) },
                    isError = invalid,
                    supportingText = {
                        if (invalid) Text(stringResource(R.string.wallet_transfer_wallet_invalid))
                    },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                    modifier = Modifier.fillMaxWidth(),
                )
            }

            screenError?.let { Text(it, color = MaterialTheme.colorScheme.error) }

            Button(
                onClick = {
                    screenError = null
                    looking = true
                    scope.launch {
                        try {
                            val request = if (mode == TransferMode.PHONE) {
                                TransferLookupRequest(mode = mode.api, phone = national)
                            } else {
                                TransferLookupRequest(mode = mode.api, walletNumber = walletDigits)
                            }
                            val result = ApiClient.wallet.transferLookup(walletAuth(), request).data
                            if (result == null) screenError = genericError else recipient = result
                        } catch (e: Exception) {
                            if (e is kotlinx.coroutines.CancellationException) throw e
                            val failure = e.apiFailure()
                            screenError = if (failure.httpStatus == null) networkError else failure.message ?: genericError
                        }
                        looking = false
                    }
                },
                enabled = ready && !looking,
                modifier = Modifier
                    .fillMaxWidth()
                    .height(50.dp),
            ) {
                Text(stringResource(R.string.wallet_transfer_next))
            }
            Text(
                stringResource(R.string.wallet_transfer_verify_note),
                color = AppColors.textSecondary,
                style = MaterialTheme.typography.bodySmall,
            )
        } else {
            // ---- Step 2: is this who you mean? how much? ----
            Text(stringResource(R.string.wallet_transfer_confirm_title), style = MaterialTheme.typography.titleMedium)
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(found.nameMasked, style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold)
                // Phone in full when addressed by phone; the wallet number when addressed by it.
                Text(
                    found.phone?.let { if (it.startsWith("+")) it else "${balance?.dialCode.orEmpty()}$it" }
                        ?: groupWalletNumber(found.walletNumber.orEmpty()),
                    style = MaterialTheme.typography.titleMedium,
                )
                Text(
                    listOfNotNull(found.countryCode, found.currencyCode).joinToString(" · "),
                    color = AppColors.textSecondary,
                )
            }

            OutlinedTextField(
                value = amountText,
                onValueChange = { amountText = it.filter { c -> c.isDigit() || c == '.' } },
                label = { Text(stringResource(R.string.wallet_topup_amount)) },
                suffix = { Text(currency.orEmpty()) },
                isError = amountText.isNotBlank() && amountMinor == null,
                supportingText = {
                    if (amountText.isNotBlank() && amountMinor == null) Text(stringResource(R.string.wallet_topup_amount_invalid))
                },
                singleLine = true,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                modifier = Modifier.fillMaxWidth(),
            )
            balance?.let {
                Text(
                    stringResource(R.string.wallet_transfer_available, formatMinor(it.totalMinor - it.heldMinor, currency)),
                    color = AppColors.textSecondary,
                    style = MaterialTheme.typography.bodySmall,
                )
            }
            Text(
                stringResource(R.string.wallet_transfer_spend_only_note),
                color = AppColors.textSecondary,
                style = MaterialTheme.typography.bodySmall,
            )
            screenError?.let { Text(it, color = MaterialTheme.colorScheme.error) }

            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                OutlinedButton(
                    onClick = { recipient = null; amountText = ""; screenError = null },
                    modifier = Modifier.height(50.dp),
                ) {
                    Text(stringResource(R.string.wallet_transfer_change_recipient))
                }
                Button(
                    onClick = {
                        screenError = null
                        checkingPin = true
                        scope.launch {
                            hasPin = runCatching { ApiClient.wallet.pinStatus(walletAuth()).data?.hasPin }.getOrNull() ?: true
                            checkingPin = false
                            showPin = true
                        }
                    },
                    enabled = amountMinor != null && !checkingPin,
                    modifier = Modifier
                        .weight(1f)
                        .height(50.dp),
                ) {
                    Text(stringResource(R.string.wallet_transfer_send))
                }
            }
        }
    }

    if (showPin && found != null) {
        val minor = amountMinor
        WalletPinDialog(
            hasPin = hasPin,
            onDismiss = { showPin = false },
            onSubmit = { pin ->
                if (minor == null) return@WalletPinDialog PinOutcome.Close(genericError)
                try {
                    val result = ApiClient.wallet.transfer(
                        walletAuth(), pin, idempotencyKey,
                        TransferRequest(recipientToken = found.recipientToken, amountMinor = minor),
                    ).data
                    idempotencyKey = UUID.randomUUID().toString()
                    if (result == null) return@WalletPinDialog PinOutcome.Close(genericError)
                    sent = result
                    PinOutcome.Done
                } catch (e: Exception) {
                    if (e is kotlinx.coroutines.CancellationException) throw e
                    val failure = e.apiFailure()
                    // No HTTP answer: keep the key so a retry can't send twice.
                    if (failure.httpStatus == null) return@WalletPinDialog PinOutcome.Retry(networkError)
                    idempotencyKey = UUID.randomUUID().toString()
                    val message = failure.message ?: genericError
                    when {
                        failure.errorCode in PIN_ERROR_CODES -> PinOutcome.Retry(message)
                        // The 10-minute confirmation ran out: go back and look the person up again.
                        failure.errorCode == "transfer_recipient_expired" -> {
                            showPin = false
                            recipient = null
                            screenError = expiredError
                            PinOutcome.Close(expiredError)
                        }
                        else -> {
                            screenError = message
                            PinOutcome.Close(message)
                        }
                    }
                }
            },
        )
    }
}
