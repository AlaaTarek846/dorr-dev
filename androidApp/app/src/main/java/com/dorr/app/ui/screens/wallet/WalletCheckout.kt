package com.dorr.app.ui.screens.wallet

import android.content.Intent
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.border
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
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.systemBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.CreditCard
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.OpenInNew
import androidx.compose.material.icons.rounded.Phone
import androidx.compose.material.icons.rounded.Receipt
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.CheckoutCreateRequest
import com.dorr.app.network.CheckoutDto
import com.dorr.app.network.CheckoutMethodDto
import com.dorr.app.network.CheckoutPayRequest
import com.dorr.app.network.ConfirmOtpRequest
import com.dorr.app.network.apiFailure
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.util.UUID

// =============================================================================== launching it

/** Something to pay for: a purpose the server knows, and what it's about (the server prices it). */
data class CheckoutRequest(val purpose: String, val reference: Map<String, Any?>)

/**
 * The one payment screen, opened from anywhere in the app (a portal's listing, a channel's
 * verification, later any service): `CheckoutLauncher.open(request) { /* paid */ }`. It slides up
 * over the current screen (CheckoutOverlay, in MainScreen) and calls back once paid.
 */
object CheckoutLauncher {
    internal var pending by mutableStateOf<Pair<CheckoutRequest, () -> Unit>?>(null)
        private set

    fun open(request: CheckoutRequest, onPaid: () -> Unit = {}) {
        pending = request to onPaid
    }

    internal fun close() {
        pending = null
    }
}

/** Drawn once, over the main screen: the payment screen while one is open. */
@Composable
fun CheckoutOverlay() {
    val current = CheckoutLauncher.pending
    var shown by remember { mutableStateOf(current) }
    if (current != null) shown = current
    AnimatedVisibility(
        visible = current != null,
        enter = slideInVertically(tween(420, easing = FastOutSlowInEasing)) { it } + fadeIn(tween(320)),
        exit = slideOutVertically(tween(340, easing = FastOutSlowInEasing)) { it } + fadeOut(tween(260)),
    ) {
        val (request, onPaid) = shown ?: return@AnimatedVisibility
        // A new request is a new visit (its own page stack, its own state).
        androidx.compose.runtime.key(request) {
            CheckoutScreen(request, onExit = { CheckoutLauncher.close() }, onPaid = { onPaid(); CheckoutLauncher.close() })
        }
    }
}

/**
 * A wallet visit that starts on the payment page instead of the wallet's home (no PIN gate — the
 * PIN is asked when paying). Topping up from here pushes the top-up page on the same stack, so
 * finishing the top-up lands back on this payment page.
 */
@Composable
fun CheckoutScreen(request: CheckoutRequest, onExit: () -> Unit, onPaid: () -> Unit) {
    val currentOnExit by rememberUpdatedState(onExit)
    val currentOnPaid by rememberUpdatedState(onPaid)
    val scope = rememberCoroutineScope()
    val state = remember { CheckoutState(request) }
    val host = remember { WalletHost(scope, onExit = { currentOnExit() }, start = WaRoute.Checkout(state)) }
    androidx.compose.runtime.SideEffect {
        host.onExit = { currentOnExit() }
        state.onPaid = { currentOnPaid() }
    }
    CompositionLocalProvider(LocalWallet provides host) {
        Box(Modifier.fillMaxSize().background(Wa.Bg).systemBarsPadding().imePadding()) {
            BackHandler { host.pop() }
            WalletPagesFor(host)
            WaSheetHost(host)
            WaToastHost(host)
        }
    }
}

// =============================================================================== the page

/** Survives leaving the page (to top up) and coming back: it lives in the route, not the page. */
@Stable
class CheckoutState(val request: CheckoutRequest) {
    var checkout by mutableStateOf<CheckoutDto?>(null)
    var loadError by mutableStateOf<String?>(null)
    /** "wallet" or a payment method id. */
    var choice by mutableStateOf<String?>(null)
    var error by mutableStateOf<String?>(null)
    var busy by mutableStateOf(false)
    var idempotencyKey by mutableStateOf(UUID.randomUUID().toString())
    /** Kept in memory for this attempt only (the OTP step needs it again). */
    var attemptPin: String? = null
    var otp by mutableStateOf("")
    var browserUrl by mutableStateOf<String?>(null)
    var onPaid: () -> Unit = {}
}

@Composable
internal fun WalletCheckout(state: CheckoutState) {
    val host = LocalWallet.current
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val genericError = stringResource(R.string.wa_error_generic)
    val networkError = stringResource(R.string.wa_error_network)
    val pinSubtitle = stringResource(R.string.wa_checkout_pin_sub)
    val checkout = state.checkout

    // First look, and again each time the page comes back (after a top-up).
    LaunchedEffect(Unit) {
        host.refreshBalance()
        val existing = state.checkout
        runCatching {
            if (existing == null) ApiClient.wallet.createCheckout(walletAuth(), CheckoutCreateRequest(state.request.purpose, state.request.reference)).data
            else ApiClient.wallet.checkout(walletAuth(), existing.id).data
        }.onSuccess { fresh ->
            if (fresh != null) {
                state.checkout = fresh
                state.loadError = null
                // Pick the wallet when it covers it, else the first method that can take it.
                if (state.choice == null || (state.choice == "wallet" && !fresh.wallet.enough)) {
                    state.choice = if (fresh.wallet.enough) "wallet" else fresh.paymentMethods.firstOrNull { it.available && !it.comingSoon }?.id?.toString() ?: "wallet"
                }
            }
        }.onFailure { state.loadError = it.apiFailure().message ?: networkError }
    }

    // Handed to a gateway: wait for the server to settle it.
    LaunchedEffect(checkout?.payment?.uuid, checkout?.status) {
        val current = state.checkout ?: return@LaunchedEffect
        if (current.status != "pending" || current.payment == null || current.payment.status != "pending") return@LaunchedEffect
        repeat(150) {
            delay(2000)
            val fresh = runCatching { ApiClient.wallet.checkout(walletAuth(), current.id).data }.getOrNull() ?: return@repeat
            state.checkout = fresh
            if (fresh.status != "pending" || fresh.payment?.status != "pending") {
                state.browserUrl = null
                host.refreshBalance()
                return@LaunchedEffect
            }
        }
    }

    when {
        checkout == null -> WaPage(title = stringResource(R.string.wa_checkout_title), onBack = { host.pop() }) {
            if (state.loadError != null) {
                WaError(state.loadError)
            } else {
                WaSkeleton(Modifier.fillMaxWidth().height(150.dp), RoundedCornerShape(22.dp))
                Spacer(Modifier.height(14.dp))
                repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(70.dp).padding(bottom = 10.dp), RoundedCornerShape(20.dp)) }
            }
        }
        checkout.status == "paid" -> CheckoutPaid(checkout, onDone = { state.onPaid() })
        checkout.payment != null && checkout.status == "pending" && checkout.payment.status == "pending" -> Box(Modifier.fillMaxSize()) {
            CheckoutWaiting(
                state = state,
                onConfirmOtp = {
                    val pin = state.attemptPin ?: return@CheckoutWaiting
                    val payment = checkout.payment
                    state.busy = true
                    state.error = null
                    scope.launch {
                        runCatching { ApiClient.wallet.confirmTopup(walletAuth(), pin, payment.uuid, ConfirmOtpRequest(state.otp)) }
                            .onSuccess { state.checkout = runCatching { ApiClient.wallet.checkout(walletAuth(), checkout.id).data }.getOrNull() ?: state.checkout }
                            .onFailure { state.error = it.apiFailure().message ?: networkError }
                        state.busy = false
                    }
                },
                onReopen = { checkout.payment.redirectUrl?.let { openGateway(context, state, it, checkout) } },
                onCancel = { host.pop() },
            )
            GatewayLayer(url = state.browserUrl, onClose = { state.browserUrl = null })
        }
        checkout.status == "expired" || checkout.status == "failed" || checkout.payment?.status in setOf("failed", "expired") -> CheckoutFailed(
            checkout = checkout,
            onRetry = {
                // A fresh payment page for the same thing.
                state.checkout = null
                state.choice = null
                state.error = null
                state.idempotencyKey = UUID.randomUUID().toString()
                scope.launch {
                    runCatching { ApiClient.wallet.createCheckout(walletAuth(), CheckoutCreateRequest(state.request.purpose, state.request.reference)).data }
                        .onSuccess { state.checkout = it }
                        .onFailure { state.loadError = it.apiFailure().message ?: networkError }
                }
            },
            onBack = { host.pop() },
        )
        else -> {
            val method = checkout.paymentMethods.firstOrNull { it.id.toString() == state.choice }
            val payWithWallet = state.choice == "wallet"
            val payable = checkout.payableMinor ?: checkout.amountMinor
            val charge = if (payWithWallet) payable else method?.chargeMinor ?: payable
            val canPay = (payWithWallet && checkout.wallet.enough) || (method != null && method.available && !method.comingSoon)
            WaPage(
                title = stringResource(R.string.wa_checkout_title),
                onBack = { host.pop() },
                cta = {
                    WaButton(
                        text = stringResource(R.string.wa_pay_amount, money(charge), checkout.currencyCode.orEmpty()),
                        enabled = canPay && !state.busy,
                        loading = state.busy,
                        icon = Icons.Rounded.Lock,
                        onClick = {
                            state.error = null
                            host.requestPin(
                                subtitle = pinSubtitle,
                                onError = { state.error = it },
                                onSubmit = { pin ->
                                    try {
                                        val paid = ApiClient.wallet.payCheckout(
                                            walletAuth(), pin,
                                            if (payWithWallet) null else state.idempotencyKey,
                                            checkout.id,
                                            if (payWithWallet) CheckoutPayRequest("wallet") else CheckoutPayRequest("gateway", method?.id),
                                        ).data ?: return@requestPin PinOutcome.Close(genericError)
                                        state.attemptPin = pin
                                        state.checkout = paid
                                        host.refreshBalance()
                                        paid.payment?.takeIf { paid.status == "pending" }?.redirectUrl?.let { openGateway(context, state, it, paid) }
                                        PinOutcome.Done
                                    } catch (e: CancellationException) {
                                        throw e
                                    } catch (e: Exception) {
                                        val failure = e.apiFailure()
                                        if (failure.httpStatus == null) return@requestPin PinOutcome.Retry(networkError)
                                        state.idempotencyKey = UUID.randomUUID().toString()
                                        val message = failure.message ?: genericError
                                        if (failure.errorCode in PIN_ERROR_CODES) PinOutcome.Retry(message) else PinOutcome.Close(message)
                                    }
                                },
                            )
                        },
                    )
                    WaCtaHint(stringResource(R.string.wa_secure_payments), Icons.Rounded.Shield)
                },
            ) {
                Box(Modifier.waRise(0)) { OrderCard(checkout) }
                Box(Modifier.waRise(1).padding(top = 12.dp)) { CouponBox(checkout) { state.checkout = it } }

                WaSectionTitle(stringResource(R.string.wa_checkout_from_wallet), Modifier.waRise(1))
                Box(Modifier.waRise(2)) {
                    WalletChoice(
                        checkout = checkout,
                        selected = payWithWallet,
                        onSelect = { state.choice = "wallet" },
                        onTopUp = { host.push(WaRoute.TopupAmount(checkout.wallet.shortfallMinor)) },
                    )
                }

                val methods = checkout.paymentMethods
                if (methods.isNotEmpty()) {
                    WaSectionTitle(stringResource(R.string.wa_checkout_other_ways), Modifier.waRise(3))
                    Column(Modifier.waRise(4)) {
                        methods.forEach { m ->
                            MethodChoice(m, checkout.currencyCode.orEmpty(), selected = state.choice == m.id.toString()) {
                                if (m.comingSoon || !m.available) host.showToast(context.getString(R.string.wa_method_coming_soon_toast))
                                else state.choice = m.id.toString()
                            }
                        }
                    }
                    if (method != null && method.chargeMinor != null && method.chargeMinor > payable) {
                        WaNote(stringResource(R.string.wa_checkout_fee_note, money(method.chargeMinor - payable), checkout.currencyCode.orEmpty()))
                    }
                }
                WaError(state.error)
            }
        }
    }
}

private fun openGateway(context: android.content.Context, state: CheckoutState, url: String, checkout: CheckoutDto) {
    val gateway = checkout.paymentMethods.firstOrNull { it.id.toString() == state.choice }?.gateway
    if (gateway == "sandbox") state.browserUrl = url
    else runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url))) }
}

// =============================================================================== pieces

@Composable
private fun OrderCard(checkout: CheckoutDto) {
    Box(
        Modifier.fillMaxWidth().clip(Wa.CardShape).background(Wa.HeroBrush).padding(20.dp),
    ) {
        Column {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(40.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.16f)), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.Receipt, null, tint = Color.White, modifier = Modifier.size(22.dp))
                }
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(checkout.title, color = Color.White, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                    checkout.subtitle?.let { Text(it, color = Color.White.copy(alpha = 0.78f), fontSize = 13.sp, fontWeight = FontWeight.SemiBold) }
                }
            }
            Spacer(Modifier.height(18.dp))
            Text(stringResource(R.string.wa_checkout_total), color = Color.White.copy(alpha = 0.7f), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                Row(verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    if (checkout.discountMinor > 0) {
                        Text(
                            money(checkout.amountMinor), color = Color.White.copy(alpha = 0.6f), fontSize = 16.sp, fontWeight = FontWeight.Bold,
                            textDecoration = androidx.compose.ui.text.style.TextDecoration.LineThrough, modifier = Modifier.padding(bottom = 7.dp),
                        )
                    }
                    Text(money(checkout.payableMinor ?: checkout.amountMinor), color = Color.White, fontSize = 34.sp, fontWeight = FontWeight.ExtraBold, letterSpacing = (-0.5).sp)
                    Text(checkout.currencyCode.orEmpty(), color = Color.White.copy(alpha = 0.8f), fontSize = 15.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(bottom = 6.dp))
                }
            }
        }
    }
}

@Composable
private fun WalletChoice(checkout: CheckoutDto, selected: Boolean, onSelect: () -> Unit, onTopUp: () -> Unit) {
    val currency = checkout.currencyCode.orEmpty()
    val enough = checkout.wallet.enough
    ChoiceRow(selected = selected, enabled = true, onClick = onSelect) {
        WaIconWell(Icons.Rounded.AccountBalanceWallet, if (enough) Tone.Green else Tone.Amber)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(stringResource(R.string.wa_checkout_wallet), color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.Bold)
            Text(
                stringResource(R.string.wa_checkout_balance, money(checkout.wallet.availableMinor), currency),
                color = if (enough) Wa.Mut else Wa.Danger, fontSize = 13.sp, fontWeight = FontWeight.SemiBold,
            )
            if (!enough) Text(stringResource(R.string.wa_checkout_short, money(checkout.wallet.shortfallMinor), currency), color = Wa.Mut, fontSize = 12.sp)
        }
        // Top up from here: the top-up page opens on this stack and comes back to this page.
        val source = remember { MutableInteractionSource() }
        val scale by rememberPressScale(source, 0.94f)
        Row(
            Modifier.scale(scale).clip(RoundedCornerShape(14.dp)).background(Wa.Red.copy(alpha = 0.12f))
                .clickable(interactionSource = source, indication = null, onClick = onTopUp)
                .padding(horizontal = 12.dp, vertical = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.Add, null, tint = Wa.Red, modifier = Modifier.size(16.dp))
            Spacer(Modifier.width(4.dp))
            Text(stringResource(R.string.wa_checkout_top_up), color = Wa.Red, fontSize = 13.sp, fontWeight = FontWeight.Bold)
        }
    }
}

@Composable
private fun MethodChoice(method: CheckoutMethodDto, currency: String, selected: Boolean, onClick: () -> Unit) {
    val usable = method.available && !method.comingSoon
    val (icon, tone) = methodLook(method.gateway)
    ChoiceRow(selected = selected, enabled = usable, onClick = onClick, modifier = Modifier.padding(bottom = 10.dp)) {
        if (method.logoUrl != null) {
            AsyncImage(method.logoUrl, null, Modifier.size(44.dp).clip(RoundedCornerShape(12.dp)).background(Color.White))
        } else {
            WaIconWell(icon, tone)
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(method.name ?: method.code, color = if (usable) Wa.Ink else Wa.Soft, fontSize = 15.sp, fontWeight = FontWeight.Bold)
            Text(
                when {
                    method.comingSoon -> stringResource(R.string.wa_method_soon_sub)
                    !method.available -> stringResource(R.string.wa_checkout_method_cant)
                    else -> stringResource(R.string.wa_checkout_charged, money(method.chargeMinor ?: 0), currency)
                },
                color = Wa.Mut, fontSize = 12.sp,
            )
        }
    }
}

@Composable
private fun ChoiceRow(selected: Boolean, enabled: Boolean, onClick: () -> Unit, modifier: Modifier = Modifier, content: @Composable androidx.compose.foundation.layout.RowScope.() -> Unit) {
    val border by animateColorAsState(if (selected) Wa.Red else Wa.Line, label = "choiceBorder")
    val source = remember { MutableInteractionSource() }
    val scale by rememberPressScale(source, 0.98f)
    Row(
        modifier.fillMaxWidth().scale(scale).clip(RoundedCornerShape(20.dp)).background(Wa.Surface)
            .border(if (selected) 2.dp else 1.dp, border, RoundedCornerShape(20.dp))
            .clickable(interactionSource = source, indication = null, onClick = onClick)
            .padding(14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(22.dp).clip(CircleShape).border(2.dp, if (selected) Wa.Red else Wa.Line, CircleShape), contentAlignment = Alignment.Center) {
            if (selected) Box(Modifier.size(12.dp).clip(CircleShape).background(Wa.Red))
        }
        Spacer(Modifier.width(12.dp))
        Row(Modifier.weight(1f).then(if (enabled) Modifier else Modifier), verticalAlignment = Alignment.CenterVertically) { content() }
    }
}

@Composable
private fun CheckoutWaiting(state: CheckoutState, onConfirmOtp: () -> Unit, onReopen: () -> Unit, onCancel: () -> Unit) {
    val payment = state.checkout?.payment ?: return
    WaPage(title = stringResource(R.string.wa_checkout_title), onBack = onCancel, scroll = false) {
        if (payment.requiresOtp) {
            WaStatusColumn {
                WaPulse(Icons.Rounded.Phone)
                WaStatusTitle(stringResource(R.string.wa_otp_title))
                WaStatusText(stringResource(R.string.wa_otp_text))
                Spacer(Modifier.height(16.dp))
                OtpField(state.otp) { state.otp = it.filter(Char::isDigit).take(8) }
                WaError(state.error)
                Spacer(Modifier.height(12.dp))
                WaButton(stringResource(R.string.wa_otp_confirm), onConfirmOtp, enabled = state.otp.length >= 4, loading = state.busy)
                WaButton(stringResource(R.string.wa_cancel), onCancel, style = WaButtonStyle.Quiet)
            }
        } else {
            WaStatusColumn {
                WaPulse(Icons.Rounded.CreditCard)
                Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(top = 18.dp, bottom = 6.dp)) {
                    Text(stringResource(R.string.wa_waiting_title), fontSize = 22.sp, fontWeight = FontWeight.ExtraBold, color = Wa.Ink)
                    WaLoadingDots()
                }
                WaStatusText(stringResource(R.string.wa_checkout_waiting_text))
                Spacer(Modifier.height(12.dp))
                if (payment.redirectUrl != null) WaButton(stringResource(R.string.wa_open_payment_page), onReopen, style = WaButtonStyle.Ghost, icon = Icons.Rounded.OpenInNew)
                WaButton(stringResource(R.string.wa_checkout_later), onCancel, style = WaButtonStyle.Quiet)
            }
        }
    }
}

@Composable
private fun CheckoutPaid(checkout: CheckoutDto, onDone: () -> Unit) {
    WaPage(title = stringResource(R.string.wa_checkout_title), onBack = onDone, scroll = false) {
        Box(Modifier.fillMaxSize()) {
            WaConfetti()
            WaStatusColumn {
                WaSeal()
                WaStatusTitle(stringResource(R.string.wa_checkout_paid_title))
                WaStatusText(checkout.title)
                WaCard(Modifier.fillMaxWidth().padding(top = 16.dp, bottom = 6.dp), padding = 14.dp) {
                    WaKeyValue(stringResource(R.string.wa_recap_paid), money(checkout.amountMinor) + " " + checkout.currencyCode.orEmpty(), ltr = true, bold = true)
                    checkout.subtitle?.let { WaDivider(); WaKeyValue(stringResource(R.string.wa_checkout_package), it) }
                    WaDivider()
                    WaKeyValue(
                        stringResource(R.string.wa_checkout_paid_with),
                        stringResource(if (checkout.paidVia == "gateway") R.string.wa_checkout_paid_card else R.string.wa_checkout_wallet),
                    )
                }
                Spacer(Modifier.height(12.dp))
                WaButton(stringResource(R.string.wa_done), onDone, icon = Icons.Rounded.CheckCircle)
            }
        }
    }
}

@Composable
private fun CheckoutFailed(checkout: CheckoutDto, onRetry: () -> Unit, onBack: () -> Unit) {
    WaPage(title = stringResource(R.string.wa_checkout_title), onBack = onBack, scroll = false) {
        WaStatusColumn {
            WaSeal(bad = true)
            WaStatusTitle(stringResource(if (checkout.status == "expired") R.string.wa_checkout_expired_title else R.string.wa_checkout_failed_title))
            WaStatusText(stringResource(if (checkout.status == "failed") R.string.wa_checkout_failed_in_wallet else R.string.wa_topup_failed_text))
            Spacer(Modifier.height(12.dp))
            WaButton(stringResource(R.string.wa_try_again), onRetry, icon = Icons.Rounded.Refresh)
            WaButton(stringResource(R.string.wa_cancel), onBack, style = WaButtonStyle.Quiet)
        }
    }
}

/** A coupon on this payment: type a code, or tap one of mine (a DORR Sports prize…). */
@Composable
private fun CouponBox(checkout: CheckoutDto, onChanged: (CheckoutDto) -> Unit) {
    var code by androidx.compose.runtime.remember { androidx.compose.runtime.mutableStateOf("") }
    var mine by androidx.compose.runtime.remember { androidx.compose.runtime.mutableStateOf<List<com.dorr.app.network.WalletCouponDto>>(emptyList()) }
    var busy by androidx.compose.runtime.remember { androidx.compose.runtime.mutableStateOf(false) }
    var error by androidx.compose.runtime.remember { androidx.compose.runtime.mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    LaunchedEffect(Unit) { mine = runCatching { ApiClient.wallet.coupons(walletAuth()).data }.getOrNull().orEmpty() }
    val apply: (String) -> Unit = { c ->
        busy = true
        error = null
        scope.launch {
            runCatching { ApiClient.wallet.applyCoupon(walletAuth(), checkout.id, com.dorr.app.network.CheckoutCouponRequest(c.trim())).data }
                .onSuccess { it?.let(onChanged); code = "" }
                .onFailure { error = it.apiFailure().message }
            busy = false
        }
    }
    val applied = checkout.coupon
    if (applied != null) {
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Wa.Green.copy(alpha = 0.10f)).padding(horizontal = 14.dp, vertical = 12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text("🎟️", fontSize = 20.sp)
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(applied.code.orEmpty(), color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                Text(stringResource(R.string.wa_coupon_saved, money(applied.discountMinor), checkout.currencyCode.orEmpty()), color = Wa.Green, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
            }
            Text(
                stringResource(R.string.wa_coupon_remove), color = Wa.Danger, fontSize = 13.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(CircleShape).clickable(enabled = !busy) {
                    busy = true
                    scope.launch { runCatching { ApiClient.wallet.removeCoupon(walletAuth(), checkout.id).data }.getOrNull()?.let(onChanged); busy = false }
                }.padding(8.dp),
            )
        }
        return
    }
    Column(Modifier.fillMaxWidth()) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            com.dorr.app.ui.components.DorrTextField(code, { code = it.take(40).uppercase() }, placeholder = stringResource(R.string.wa_coupon_hint), modifier = Modifier.weight(1f))
            Spacer(Modifier.width(8.dp))
            WaButton(stringResource(R.string.wa_coupon_apply), { apply(code) }, enabled = code.length >= 4 && !busy, loading = busy, style = WaButtonStyle.Ghost, modifier = Modifier.width(110.dp))
        }
        if (mine.isNotEmpty()) {
            Row(Modifier.padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                mine.take(3).forEach { c ->
                    Text(
                        "🎟️ " + if (c.kind == "percent") "${c.value}%" else money(c.value) + " " + c.currencyCode.orEmpty(),
                        color = Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold,
                        modifier = Modifier.clip(CircleShape).background(Wa.Surface).clickable { apply(c.code) }.padding(horizontal = 12.dp, vertical = 7.dp),
                    )
                }
            }
        }
        error?.let { Text(it, color = Wa.Danger, fontSize = 12.5.sp, modifier = Modifier.padding(top = 6.dp)) }
    }
}
