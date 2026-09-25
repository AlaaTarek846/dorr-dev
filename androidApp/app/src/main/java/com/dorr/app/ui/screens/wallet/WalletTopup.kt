package com.dorr.app.ui.screens.wallet

import android.annotation.SuppressLint
import android.content.Intent
import android.net.Uri
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.slideInVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.border
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AccountBalance
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.CardGiftcard
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.CreditCard
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.OpenInNew
import androidx.compose.material.icons.rounded.Phone
import androidx.compose.material.icons.rounded.Receipt
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Science
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ConfirmOtpRequest
import com.dorr.app.network.PaymentMethodDto
import com.dorr.app.network.TopupPaymentDto
import com.dorr.app.network.TopupQuoteDto
import com.dorr.app.network.TopupRequest
import com.dorr.app.network.apiFailure
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.util.UUID

private val QUICK_AMOUNTS = listOf(50, 100, 200, 500)

private fun methodLook(gateway: String): Pair<ImageVector, Tone> = when (gateway) {
    "myfatoorah" -> Icons.Rounded.CreditCard to Tone.Blue
    "arb" -> Icons.Rounded.AccountBalance to Tone.Green
    "urpay" -> Icons.Rounded.Phone to Tone.Pink
    "sandbox" -> Icons.Rounded.Science to Tone.Amber
    else -> Icons.Rounded.CreditCard to Tone.Gray
}

/**
 * Top up: amount, payment method (gateways without credentials are listed as "coming soon"), live
 * quote, PIN, hand-off to the gateway, then a waiting / OTP / paid / failed result page. The
 * fake sandbox bank runs in an in-app browser layer; real gateways open in the phone's browser.
 */
@Composable
/** The `initial*` parameters only seed the screen (previews / screenshot tests). */
fun WalletTopup(initialMethods: List<PaymentMethodDto>? = null, initialAmount: String = "", initialQuote: TopupQuoteDto? = null) {
    val host = LocalWallet.current
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val currency = host.balance?.currencyCode.orEmpty()
    val genericError = stringResource(R.string.wa_error_generic)
    val networkError = stringResource(R.string.wa_error_network)
    val comingSoonText = stringResource(R.string.wa_method_coming_soon_toast)
    val pinSubtitle = stringResource(R.string.wa_pin_topup_sub)

    var methods by remember { mutableStateOf(initialMethods) }
    var methodsFailed by remember { mutableStateOf(false) }
    var methodId by remember { mutableStateOf(initialMethods?.firstOrNull { !it.comingSoon }?.id) }
    var amountText by remember { mutableStateOf(initialAmount) }
    var quote by remember { mutableStateOf(initialQuote) }
    var quoteError by remember { mutableStateOf<String?>(null) }
    var screenError by remember { mutableStateOf<String?>(null) }
    var checkingPin by remember { mutableStateOf(false) }

    // Same key on a retry of the *same* request, so a dropped connection can't double-charge.
    var idempotencyKey by remember { mutableStateOf(UUID.randomUUID().toString()) }
    // Kept in memory only for this attempt (the OTP step needs it again); dropped when it settles.
    var attemptPin by remember { mutableStateOf<String?>(null) }
    var payment by remember { mutableStateOf<TopupPaymentDto?>(null) }
    var gateway by remember { mutableStateOf("") }
    var browserUrl by remember { mutableStateOf<String?>(null) }
    var otp by remember { mutableStateOf("") }
    var otpBusy by remember { mutableStateOf(false) }

    val amountMinor = parseAmountToMinor(amountText)

    LaunchedEffect(Unit) {
        if (host.balance == null) host.refreshBalance()
        if (initialMethods != null) return@LaunchedEffect
        runCatching { ApiClient.wallet.paymentMethods(walletAuth()).data.orEmpty() }
            .onSuccess { list ->
                methods = list
                methodId = list.firstOrNull { !it.comingSoon }?.id
            }
            .onFailure { methodsFailed = true }
    }

    // Live quote, debounced so typing "100" doesn't fire three requests.
    LaunchedEffect(amountMinor, methodId) {
        if (initialQuote == null) quote = null
        quoteError = null
        val id = methodId
        if (amountMinor == null || id == null) return@LaunchedEffect
        delay(400)
        runCatching { ApiClient.wallet.quote(walletAuth(), TopupRequest(id, amountMinor)).data }
            .onSuccess { quote = it }
            .onFailure { quoteError = it.apiFailure().message ?: networkError }
    }

    // After the hand-off, wait for the server to confirm.
    LaunchedEffect(payment?.uuid) {
        val uuid = payment?.uuid ?: return@LaunchedEffect
        repeat(100) {
            if (payment?.status != "pending") return@LaunchedEffect
            delay(2000)
            runCatching { ApiClient.wallet.topup(walletAuth(), uuid).data }.onSuccess { latest ->
                if (latest != null) {
                    if (latest.status == "paid" && payment?.status != "paid") {
                        host.refreshBalance()
                        browserUrl = null
                    }
                    payment = latest
                }
            }
        }
    }

    val current = payment
    if (current != null) {
        Box(Modifier.fillMaxSize()) {
            TopupStage(
                payment = current, gateway = gateway, currency = currency, host = host,
                otp = otp, onOtp = { otp = it.filter(Char::isDigit).take(8) }, otpBusy = otpBusy, error = screenError,
                onConfirmOtp = {
                    val pin = attemptPin ?: return@TopupStage
                    otpBusy = true
                    screenError = null
                    scope.launch {
                        runCatching { ApiClient.wallet.confirmTopup(walletAuth(), pin, current.uuid, ConfirmOtpRequest(otp)).data }
                            .onSuccess { updated ->
                                if (updated != null) {
                                    payment = updated
                                    if (updated.status == "paid") host.refreshBalance()
                                }
                            }
                            .onFailure { screenError = it.apiFailure().message ?: networkError }
                        otpBusy = false
                    }
                },
                onReopen = {
                    current.redirectUrl?.let { url ->
                        if (gateway == "sandbox") browserUrl = url else context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
                    }
                },
                onDone = { attemptPin = null; host.pop() },
                onStatement = { attemptPin = null; host.pop(); host.push(WaRoute.History) },
                onRetry = { attemptPin = null; payment = null; otp = ""; screenError = null; browserUrl = null; idempotencyKey = UUID.randomUUID().toString() },
                onCancel = { attemptPin = null; payment = null; otp = ""; screenError = null; browserUrl = null },
                onBack = { host.pop() },
            )
            GatewayLayer(url = browserUrl, onClose = { browserUrl = null })
        }
        return
    }

    val paying = methods?.find { it.id == methodId }
    WaPage(
        title = stringResource(R.string.wa_topup_title),
        onBack = { host.pop() },
        cta = {
            WaButton(
                text = if (amountMinor != null && quote != null) stringResource(R.string.wa_pay_amount, money(amountMinor), currency) else stringResource(R.string.wa_continue),
                onClick = {
                    screenError = null
                    host.requestPin(
                        subtitle = pinSubtitle,
                        onError = { screenError = it },
                        onSubmit = { pin ->
                            val id = methodId
                            val minor = amountMinor
                            if (id == null || minor == null) return@requestPin PinOutcome.Close(genericError)
                            try {
                                val started = ApiClient.wallet.startTopup(walletAuth(), pin, idempotencyKey, TopupRequest(id, minor)).data
                                idempotencyKey = UUID.randomUUID().toString()
                                if (started == null) return@requestPin PinOutcome.Close(genericError)
                                attemptPin = pin
                                gateway = paying?.gateway.orEmpty()
                                payment = started
                                started.redirectUrl?.let { url ->
                                    if (gateway == "sandbox") browserUrl = url else context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
                                }
                                PinOutcome.Done
                            } catch (e: CancellationException) {
                                throw e
                            } catch (e: Exception) {
                                val failure = e.apiFailure()
                                // No HTTP answer: keep the key so a retry is safe.
                                if (failure.httpStatus == null) return@requestPin PinOutcome.Retry(networkError)
                                idempotencyKey = UUID.randomUUID().toString()
                                val message = failure.message ?: genericError
                                if (failure.errorCode in PIN_ERROR_CODES) PinOutcome.Retry(message) else PinOutcome.Close(message)
                            }
                        },
                    )
                },
                enabled = quote != null && !checkingPin,
                icon = Icons.Rounded.Lock,
            )
            WaCtaHint(stringResource(R.string.wa_secure_payments), Icons.Rounded.Shield)
        },
    ) {
        Box(Modifier.waRise(0)) {
            WaAmountEntry(
                label = stringResource(R.string.wa_topup_how_much), amountText = amountText, onAmountChange = { amountText = it },
                currency = currency, quick = QUICK_AMOUNTS, invalid = amountText.isNotBlank() && amountMinor == null,
            )
        }

        WaSectionTitle(stringResource(R.string.wa_pay_method), Modifier.waRise(1))
        Column(Modifier.waRise(2)) {
            when {
                methods == null && !methodsFailed -> repeat(2) { WaSkeleton(Modifier.fillMaxWidth().height(70.dp).padding(bottom = 10.dp), RoundedCornerShape(20.dp)) }
                methodsFailed -> WaError(networkError)
                methods.orEmpty().isEmpty() -> WaCard(Modifier.fillMaxWidth()) {
                    WaEmpty(Icons.Rounded.CreditCard, Tone.Gray, stringResource(R.string.wa_no_methods), stringResource(R.string.wa_no_methods_text))
                }
                else -> methods.orEmpty().forEach { method ->
                    MethodCard(method, selected = method.id == methodId) {
                        if (method.comingSoon) host.showToast(comingSoonText) else methodId = method.id
                    }
                }
            }
        }

        quote?.let { QuoteCard(it, currency) }
        quoteError?.let { WaError(it) }
        WaError(screenError)
    }
}


@Composable
private fun MethodCard(method: PaymentMethodDto, selected: Boolean, onClick: () -> Unit) {
    val (icon, tone) = methodLook(method.gateway)
    val source = remember { MutableInteractionSource() }
    val pressScale by rememberPressScale(source, 0.98f)
    val soon = method.comingSoon
    Row(
        Modifier
            .fillMaxWidth()
            .padding(bottom = 10.dp)
            .scale(pressScale)
            .alpha(if (soon) 0.72f else 1f)
            .shadow(8.dp, RoundedCornerShape(20.dp), ambientColor = Color(0x0D111928), spotColor = Color(0x14111928))
            .clip(RoundedCornerShape(20.dp))
            .background(if (selected) Color(0xFFFFF6F7) else if (soon) Color(0xFFF7F8FA) else Color.White)
            .border(2.dp, if (selected) Wa.Red else Color.Transparent, RoundedCornerShape(20.dp))
            .clickable(interactionSource = source, indication = null, onClick = onClick)
            .padding(horizontal = 14.dp, vertical = 13.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        WaIconWell(icon, if (soon) Tone.Gray else tone)
        Column(Modifier.weight(1f)) {
            Text(method.name ?: method.code, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, color = Wa.Ink)
            Text(
                stringResource(if (soon) R.string.wa_method_soon_sub else if (method.gateway == "sandbox") R.string.wa_method_sandbox_sub else R.string.wa_method_secure_sub),
                fontSize = 11.5.sp, color = Wa.Mut, modifier = Modifier.padding(top = 2.dp),
            )
        }
        if (soon) {
            Text(
                stringResource(R.string.wa_soon_badge), color = Color(0xFFB45309), fontSize = 11.sp, fontWeight = FontWeight.ExtraBold,
                modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(Color(0xFFFEF3C7)).padding(horizontal = 11.dp, vertical = 4.dp),
            )
        } else {
            Box(
                Modifier.size(24.dp).clip(CircleShape).background(if (selected) Wa.Red else Color.Transparent).border(2.dp, if (selected) Wa.Red else Color(0xFFD1D5DB), CircleShape),
                contentAlignment = Alignment.Center,
            ) { if (selected) Icon(Icons.Rounded.Check, null, tint = Color.White, modifier = Modifier.size(14.dp)) }
        }
    }
}

@Composable
private fun QuoteRow(icon: ImageVector, label: String, value: String, total: Boolean = false) {
    Row(
        Modifier
            .fillMaxWidth()
            .padding(top = if (total) 6.dp else 0.dp)
            .let { if (total) it.clip(RoundedCornerShape(16.dp)).background(Brush.linearGradient(listOf(Color(0xFFFDE8EC), Color(0xFFFFF5F6)))).padding(12.dp) else it.padding(vertical = 7.dp) },
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(Modifier.weight(1f), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Icon(icon, null, tint = if (total) Wa.Ink else Wa.Mut, modifier = Modifier.size(16.dp))
            Text(label, color = if (total) Wa.Ink else Wa.Mut, fontSize = 14.sp, fontWeight = if (total) FontWeight.ExtraBold else FontWeight.Normal)
        }
        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
            Text(value, fontWeight = FontWeight.ExtraBold, fontSize = if (total) 18.sp else 14.sp, color = if (total) Wa.Red else Wa.Ink)
        }
    }
}

@Composable
private fun QuoteCard(quote: TopupQuoteDto, currency: String) {
    WaSectionTitle(stringResource(R.string.wa_quote_title))
    WaCard(Modifier.fillMaxWidth(), padding = 16.dp) {
        QuoteRow(Icons.Rounded.CreditCard, stringResource(R.string.wa_quote_you_pay), money(quote.paidAmountMinor) + " " + currency)
        if (quote.feeMinor > 0) QuoteRow(Icons.Rounded.Receipt, stringResource(R.string.wa_quote_fee), "− " + money(quote.feeMinor) + " " + currency)
        if (quote.bonusMinor > 0) {
            QuoteRow(Icons.Rounded.CardGiftcard, stringResource(R.string.wa_quote_bonus), "+ " + money(quote.bonusMinor) + " " + currency)
            Row(Modifier.padding(top = 6.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Icon(Icons.Rounded.AutoAwesome, null, tint = Wa.Mut, modifier = Modifier.size(14.dp).padding(top = 2.dp))
                Text(stringResource(R.string.wa_quote_bonus_note), color = Wa.Mut, fontSize = 11.5.sp, lineHeight = 20.sp)
            }
        }
        QuoteRow(Icons.Rounded.AccountBalanceWallet, stringResource(R.string.wa_quote_you_get), money(quote.totalCreditedMinor) + " " + currency, total = true)
    }
}

// ------------------------------------------------------------------------------- result pages

@Composable
internal fun TopupStage(
    payment: TopupPaymentDto, gateway: String, currency: String, host: WalletHost,
    otp: String, onOtp: (String) -> Unit, otpBusy: Boolean, error: String?,
    onConfirmOtp: () -> Unit, onReopen: () -> Unit, onDone: () -> Unit, onStatement: () -> Unit,
    onRetry: () -> Unit, onCancel: () -> Unit, onBack: () -> Unit,
) {
    WaPage(title = stringResource(R.string.wa_topup_title), onBack = onBack, scroll = false) {
        Box(Modifier.fillMaxSize()) {
            when {
                payment.status == "paid" -> {
                    WaConfetti()
                    val credited = payment.amountMinor + payment.bonusMinor - payment.feeMinor
                    WaStatusColumn() {
                        WaSeal()
                        WaStatusTitle(stringResource(R.string.wa_topup_done_title))
                        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                            Row(verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                Text("+ " + money(credited), fontSize = 34.sp, fontWeight = FontWeight.ExtraBold, color = Wa.Ink, letterSpacing = (-0.5).sp)
                                Text(currency, fontSize = 15.sp, fontWeight = FontWeight.Bold, color = Wa.Mut, modifier = Modifier.padding(bottom = 6.dp))
                            }
                        }
                        WaCard(Modifier.fillMaxWidth().padding(top = 16.dp, bottom = 6.dp), padding = 14.dp) {
                            WaKeyValue(stringResource(R.string.wa_recap_paid), money(payment.amountMinor) + " " + currency, ltr = true)
                            if (payment.feeMinor > 0) { WaDivider(); WaKeyValue(stringResource(R.string.wa_quote_fee), "− " + money(payment.feeMinor) + " " + currency, ltr = true) }
                            if (payment.bonusMinor > 0) { WaDivider(); WaKeyValue(stringResource(R.string.wa_recap_bonus), "+ " + money(payment.bonusMinor) + " " + currency, ltr = true) }
                            WaDivider()
                            WaKeyValue(stringResource(R.string.wa_recap_balance_now), host.balance?.let { money(it.totalMinor) + " " + currency } ?: "…", ltr = true)
                        }
                        Spacer(Modifier.height(12.dp))
                        WaButton(stringResource(R.string.wa_done), onDone)
                        WaButton(stringResource(R.string.wa_view_statement), onStatement, style = WaButtonStyle.Quiet)
                    }
                }
                payment.status == "pending" && payment.requiresOtp -> WaStatusColumn() {
                    WaPulse(Icons.Rounded.Phone)
                    WaStatusTitle(stringResource(R.string.wa_otp_title))
                    WaStatusText(stringResource(R.string.wa_otp_text))
                    Spacer(Modifier.height(16.dp))
                    OtpField(otp, onOtp)
                    WaError(error)
                    Spacer(Modifier.height(12.dp))
                    WaButton(stringResource(R.string.wa_otp_confirm), onConfirmOtp, enabled = otp.length >= 4, loading = otpBusy)
                    WaButton(stringResource(R.string.wa_cancel), onCancel, style = WaButtonStyle.Quiet)
                }
                payment.status == "pending" -> WaStatusColumn() {
                    WaPulse(if (gateway == "sandbox") Icons.Rounded.Science else Icons.Rounded.CreditCard)
                    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(top = 18.dp, bottom = 6.dp)) {
                        Text(stringResource(R.string.wa_waiting_title), fontSize = 22.sp, fontWeight = FontWeight.ExtraBold, color = Wa.Ink)
                        WaLoadingDots()
                    }
                    WaStatusText(stringResource(R.string.wa_waiting_text))
                    Spacer(Modifier.height(12.dp))
                    if (payment.redirectUrl != null) WaButton(stringResource(R.string.wa_open_payment_page), onReopen, style = WaButtonStyle.Ghost, icon = Icons.Rounded.OpenInNew)
                    WaButton(stringResource(R.string.wa_cancel), onCancel, style = WaButtonStyle.Quiet)
                }
                else -> WaStatusColumn() {
                    WaSeal(bad = true)
                    WaStatusTitle(stringResource(R.string.wa_topup_failed_title))
                    WaStatusText(stringResource(R.string.wa_topup_failed_text))
                    Spacer(Modifier.height(12.dp))
                    WaButton(stringResource(R.string.wa_try_again), onRetry, icon = Icons.Rounded.Refresh)
                    WaButton(stringResource(R.string.wa_back_to_wallet), onBack, style = WaButtonStyle.Quiet)
                }
            }
        }
    }
}


@Composable
private fun OtpField(value: String, onChange: (String) -> Unit) {
    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
        BasicTextField(
            value = value, onValueChange = onChange, singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
            textStyle = TextStyle(fontSize = 26.sp, fontWeight = FontWeight.ExtraBold, color = Wa.Ink, textAlign = TextAlign.Center, letterSpacing = 10.sp),
            cursorBrush = SolidColor(Wa.Red),
            modifier = Modifier.fillMaxWidth(0.8f),
            decorationBox = { inner ->
                Box(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Color.White).border(2.dp, if (value.isEmpty()) Wa.Line else Wa.Red, RoundedCornerShape(16.dp)).padding(14.dp),
                    contentAlignment = Alignment.Center,
                ) {
                    if (value.isEmpty()) Text("••••••", fontSize = 26.sp, color = Color(0xFFD1D5DB), letterSpacing = 10.sp)
                    inner()
                }
            },
        )
    }
}

/** The fake bank (sandbox) inside the phone, like an in-app browser sliding up over the page. */
@SuppressLint("SetJavaScriptEnabled")
@Composable
private fun GatewayLayer(url: String?, onClose: () -> Unit) {
    var last by remember { mutableStateOf("") }
    if (url != null) last = url
    AnimatedVisibility(visible = url != null, enter = slideInVertically(tween(380)) { it } + fadeIn(tween(200))) {
        Column(Modifier.fillMaxSize().background(Color.White)) {
            Row(Modifier.fillMaxWidth().padding(horizontal = 14.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                Row(Modifier.weight(1f), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    Icon(Icons.Rounded.Lock, null, tint = Wa.Ink, modifier = Modifier.size(16.dp))
                    Text(stringResource(R.string.wa_secure_pay), fontWeight = FontWeight.ExtraBold, color = Wa.Ink)
                }
                WaCircleButton(Icons.Rounded.Close, onClose)
            }
            AndroidView(
                modifier = Modifier.fillMaxSize(),
                factory = { ctx ->
                    WebView(ctx).apply {
                        settings.javaScriptEnabled = true
                        webViewClient = WebViewClient()
                        loadUrl(last)
                    }
                },
            )
        }
    }
}
