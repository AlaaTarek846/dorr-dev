package com.dorr.app.ui.screens.wallet

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ConfirmOtpRequest
import com.dorr.app.network.PaymentMethodDto
import com.dorr.app.network.TopupPaymentDto
import com.dorr.app.network.TopupQuoteDto
import com.dorr.app.network.TopupRequest
import com.dorr.app.network.WalletBalanceDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.theme.AppColors
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.util.UUID

/**
 * Online top-up: amount → payment method → live quote (fee/bonus) → PIN →
 * gateway. The wallet is only ever credited by the server after the gateway
 * confirms, so this screen never assumes success: after handing off to the
 * gateway it polls the payment's status.
 */
@Composable
fun TopupScreen(balance: WalletBalanceDto?, onDone: () -> Unit) {
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val currency = balance?.currencyCode
    val genericError = stringResource(R.string.wallet_error_generic)
    val networkError = stringResource(R.string.wallet_error_network)
    val comingSoonText = stringResource(R.string.wallet_method_coming_soon_toast)

    var methods by remember { mutableStateOf<List<PaymentMethodDto>?>(null) }
    var methodsFailed by remember { mutableStateOf(false) }
    var selectedMethodId by remember { mutableStateOf<Int?>(null) }
    var amountText by remember { mutableStateOf("") }
    var quote by remember { mutableStateOf<TopupQuoteDto?>(null) }
    var quoteError by remember { mutableStateOf<String?>(null) }
    var screenError by remember { mutableStateOf<String?>(null) }

    var showPin by remember { mutableStateOf(false) }
    var hasPin by remember { mutableStateOf(true) }
    var checkingPin by remember { mutableStateOf(false) }

    // Same key on a retry of the *same* request, so a dropped connection can't
    // double-charge; a fresh one after the server has answered either way.
    var idempotencyKey by remember { mutableStateOf(UUID.randomUUID().toString()) }
    // The PIN is kept in memory only for the life of this one attempt (the OTP
    // confirm step needs it again) and dropped as soon as the payment settles.
    var attemptPin by remember { mutableStateOf<String?>(null) }
    var payment by remember { mutableStateOf<TopupPaymentDto?>(null) }
    var otp by remember { mutableStateOf("") }
    var otpBusy by remember { mutableStateOf(false) }

    val amountMinor = parseAmountToMinor(amountText)

    LaunchedEffect(Unit) {
        runCatching { ApiClient.wallet.paymentMethods(walletAuth()).data.orEmpty() }
            .onSuccess { list ->
                methods = list
                selectedMethodId = list.firstOrNull { !it.comingSoon }?.id
            }
            .onFailure { methodsFailed = true }
    }

    // Live quote, debounced so typing "100" doesn't fire three requests.
    LaunchedEffect(amountMinor, selectedMethodId) {
        quote = null
        quoteError = null
        val methodId = selectedMethodId
        if (amountMinor == null || methodId == null) return@LaunchedEffect
        delay(400)
        runCatching { ApiClient.wallet.quote(walletAuth(), TopupRequest(methodId, amountMinor)).data }
            .onSuccess { quote = it }
            .onFailure { quoteError = it.apiFailure().message ?: networkError }
    }

    // After the hand-off to the gateway, wait for the server to confirm.
    LaunchedEffect(payment?.uuid) {
        val uuid = payment?.uuid ?: return@LaunchedEffect
        repeat(60) {
            if (payment?.status != "pending") return@LaunchedEffect
            delay(3000)
            runCatching { ApiClient.wallet.topup(walletAuth(), uuid).data }
                .onSuccess { latest -> if (latest != null) payment = latest }
        }
    }

    val current = payment
    if (current != null) {
        PaymentStatus(
            payment = current,
            currency = currency,
            otp = otp,
            onOtpChange = { otp = it },
            otpBusy = otpBusy,
            error = screenError,
            onConfirmOtp = {
                val pin = attemptPin ?: return@PaymentStatus
                otpBusy = true
                screenError = null
                scope.launch {
                    runCatching { ApiClient.wallet.confirmTopup(walletAuth(), pin, current.uuid, ConfirmOtpRequest(otp)).data }
                        .onSuccess { updated -> if (updated != null) payment = updated }
                        .onFailure { screenError = it.apiFailure().message ?: networkError }
                    otpBusy = false
                }
            },
            onOpenGateway = { current.redirectUrl?.let { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(it))) } },
            onDone = { attemptPin = null; onDone() },
            onRetry = { attemptPin = null; payment = null; otp = ""; screenError = null },
        )
        return
    }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(20.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp),
    ) {
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

        Text(stringResource(R.string.wallet_topup_method), style = MaterialTheme.typography.titleSmall)
        when {
            methods == null && !methodsFailed -> CircularProgressIndicator()
            methodsFailed -> Text(networkError, color = MaterialTheme.colorScheme.error)
            methods.orEmpty().isEmpty() -> Text(stringResource(R.string.wallet_topup_no_methods), color = AppColors.textSecondary)
            else -> methods.orEmpty().forEach { method ->
                MethodRow(method, selected = method.id == selectedMethodId) {
                    if (method.comingSoon) {
                        android.widget.Toast.makeText(context, comingSoonText, android.widget.Toast.LENGTH_SHORT).show()
                    } else {
                        selectedMethodId = method.id
                    }
                }
            }
        }

        quote?.let { QuoteCard(it, currency) }
        quoteError?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyMedium) }
        screenError?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyMedium) }

        Button(
            onClick = {
                screenError = null
                checkingPin = true
                scope.launch {
                    // Ask the server whether a PIN exists so a first-time user
                    // gets the create form instead of a "wrong PIN" error.
                    hasPin = runCatching { ApiClient.wallet.pinStatus(walletAuth()).data?.hasPin }.getOrNull() ?: true
                    checkingPin = false
                    showPin = true
                }
            },
            enabled = quote != null && !checkingPin,
            modifier = Modifier
                .fillMaxWidth()
                .height(50.dp),
        ) {
            Text(stringResource(R.string.wallet_topup_pay))
        }
    }

    if (showPin) {
        val methodId = selectedMethodId
        val minor = amountMinor
        WalletPinDialog(
            hasPin = hasPin,
            onDismiss = { showPin = false },
            onSubmit = { pin ->
                if (methodId == null || minor == null) return@WalletPinDialog PinOutcome.Close(genericError)
                try {
                    val started = ApiClient.wallet.startTopup(walletAuth(), pin, idempotencyKey, TopupRequest(methodId, minor)).data
                    idempotencyKey = UUID.randomUUID().toString()
                    if (started == null) return@WalletPinDialog PinOutcome.Close(genericError)
                    attemptPin = pin
                    payment = started
                    started.redirectUrl?.let { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(it))) }
                    PinOutcome.Done
                } catch (e: Exception) {
                    if (e is kotlinx.coroutines.CancellationException) throw e
                    val failure = e.apiFailure()
                    // Network failure (no HTTP answer): keep the key so a retry is safe.
                    if (failure.httpStatus == null) return@WalletPinDialog PinOutcome.Retry(networkError)
                    idempotencyKey = UUID.randomUUID().toString()
                    val message = failure.message ?: genericError
                    if (failure.errorCode in PIN_ERROR_CODES) PinOutcome.Retry(message) else {
                        screenError = message
                        PinOutcome.Close(message)
                    }
                }
            },
        )
    }
}

@Composable
private fun MethodRow(method: PaymentMethodDto, selected: Boolean, onSelect: () -> Unit) {
    Card(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onSelect),
        shape = RoundedCornerShape(14.dp),
        border = BorderStroke(if (selected) 2.dp else 1.dp, if (selected) AppColors.primary else AppColors.textSecondary.copy(alpha = 0.3f)),
    ) {
        Row(modifier = Modifier.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            RadioButton(selected = selected, onClick = onSelect, enabled = !method.comingSoon)
            Text(
                method.name ?: method.code,
                style = MaterialTheme.typography.bodyLarge,
                color = if (method.comingSoon) AppColors.textSecondary else MaterialTheme.colorScheme.onSurface,
                modifier = Modifier.weight(1f),
            )
            if (method.comingSoon) {
                Text(stringResource(R.string.wallet_method_coming_soon), color = AppColors.textSecondary, style = MaterialTheme.typography.labelMedium)
            }
        }
    }
}

@Composable
private fun QuoteCard(quote: TopupQuoteDto, currency: String?) {
    Card(shape = RoundedCornerShape(14.dp), modifier = Modifier.fillMaxWidth()) {
        Column(modifier = Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            QuoteLine(stringResource(R.string.wallet_quote_you_pay), formatMinor(quote.paidAmountMinor, currency))
            if (quote.feeMinor > 0) {
                QuoteLine(stringResource(R.string.wallet_quote_fee), "- " + formatMinor(quote.feeMinor, currency))
            }
            if (quote.bonusMinor > 0) {
                QuoteLine(stringResource(R.string.wallet_quote_bonus), "+ " + formatMinor(quote.bonusMinor, currency))
                Text(
                    stringResource(R.string.wallet_quote_bonus_note),
                    style = MaterialTheme.typography.bodySmall,
                    color = AppColors.textSecondary,
                )
            }
            QuoteLine(stringResource(R.string.wallet_quote_you_get), formatMinor(quote.totalCreditedMinor, currency), bold = true)
        }
    }
}

@Composable
private fun QuoteLine(label: String, value: String, bold: Boolean = false) {
    Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
        Text(label, fontWeight = if (bold) FontWeight.Bold else FontWeight.Normal)
        Text(value, fontWeight = if (bold) FontWeight.Bold else FontWeight.Normal)
    }
}

/** Shown once the payment exists: waiting / OTP / paid / failed. */
@Composable
private fun PaymentStatus(
    payment: TopupPaymentDto,
    currency: String?,
    otp: String,
    onOtpChange: (String) -> Unit,
    otpBusy: Boolean,
    error: String?,
    onConfirmOtp: () -> Unit,
    onOpenGateway: () -> Unit,
    onDone: () -> Unit,
    onRetry: () -> Unit,
) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(24.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp, Alignment.CenterVertically),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        when (payment.status) {
            "paid" -> {
                Text(stringResource(R.string.wallet_topup_success), style = MaterialTheme.typography.headlineSmall)
                Text(formatMinor(payment.amountMinor + payment.bonusMinor - payment.feeMinor, currency), style = MaterialTheme.typography.titleLarge)
                Button(onClick = onDone, modifier = Modifier.fillMaxWidth()) { Text(stringResource(R.string.wallet_topup_done)) }
            }
            "pending" -> {
                if (payment.requiresOtp) {
                    Text(stringResource(R.string.wallet_topup_otp_title), style = MaterialTheme.typography.titleLarge)
                    Text(stringResource(R.string.wallet_topup_otp_hint), color = AppColors.textSecondary)
                    OutlinedTextField(
                        value = otp,
                        onValueChange = { onOtpChange(it.filter(Char::isDigit).take(8)) },
                        label = { Text(stringResource(R.string.wallet_topup_otp_label)) },
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
                        modifier = Modifier.fillMaxWidth(),
                    )
                    error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                    Button(onClick = onConfirmOtp, enabled = otp.length >= 4 && !otpBusy, modifier = Modifier.fillMaxWidth()) {
                        Text(stringResource(R.string.wallet_topup_otp_confirm))
                    }
                } else {
                    CircularProgressIndicator(modifier = Modifier.size(40.dp))
                    Text(stringResource(R.string.wallet_topup_waiting), style = MaterialTheme.typography.titleMedium)
                    Text(stringResource(R.string.wallet_topup_waiting_hint), color = AppColors.textSecondary)
                    if (payment.redirectUrl != null) {
                        OutlinedButton(onClick = onOpenGateway, modifier = Modifier.fillMaxWidth()) {
                            Text(stringResource(R.string.wallet_topup_reopen))
                        }
                    }
                }
                Spacer(Modifier.height(8.dp))
                OutlinedButton(onClick = onRetry, modifier = Modifier.fillMaxWidth()) { Text(stringResource(R.string.common_cancel)) }
            }
            else -> {
                Text(stringResource(R.string.wallet_topup_failed), style = MaterialTheme.typography.headlineSmall)
                Text(stringResource(R.string.wallet_topup_failed_hint), color = AppColors.textSecondary)
                Button(onClick = onRetry, modifier = Modifier.fillMaxWidth()) { Text(stringResource(R.string.wallet_topup_retry)) }
            }
        }
    }
}
