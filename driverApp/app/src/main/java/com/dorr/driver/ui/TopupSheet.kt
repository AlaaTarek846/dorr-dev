package com.dorr.driver.ui

import android.content.Intent
import android.net.Uri
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.spring
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.CreditCard
import androidx.compose.material.icons.filled.ErrorOutline
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import coil.compose.AsyncImage
import com.dorr.core.network.ApiResult
import com.dorr.core.network.CoreHttp
import com.dorr.core.network.apiCall
import com.dorr.driver.ui.theme.DorrBrand
import com.dorr.driver.ui.kit.DorrField
import com.dorr.driver.R
import com.dorr.driver.live.DriverLive
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.PaymentMethodDto
import com.dorr.driver.net.TopupDto
import com.dorr.driver.net.TopupQuoteDto
import com.dorr.driver.net.WalletDto
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.util.Locale

/**
 * Top up the wallet (the captain whose cash rides left a debt, or who wants a balance for commissions).
 * Pick how, the amount, see the fee and the bonus, enter the wallet PIN → the gateway's page opens; the
 * result arrives as a real-time event (`wallet.topup.paid / failed`) — nothing asks the server on a timer.
 * Coming back from the gateway's page checks the payment once.
 */
@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
internal fun TopupSheet(w: WalletDto, onClose: () -> Unit, onDone: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var methods by remember { mutableStateOf<List<PaymentMethodDto>?>(null) }
    var methodId by remember { mutableStateOf<Int?>(null) }
    var amountText by remember { mutableStateOf("") }
    var quote by remember { mutableStateOf<TopupQuoteDto?>(null) }
    var pin by remember { mutableStateOf("") }
    var otp by remember { mutableStateOf("") }
    var payment by remember { mutableStateOf<TopupDto?>(null) }
    var result by remember { mutableStateOf<String?>(null) }   // "paid" | "failed"
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val idempotencyKey = remember { CoreHttp.newIdempotencyKey() }

    val minor = amountText.replace(',', '.').toDoubleOrNull()?.let { Math.round(it * 100) } ?: 0L
    val owed = if (w.totalMinor < 0) -w.totalMinor else 0L

    LaunchedEffect(Unit) {
        methods = (apiCall { DriverBackend.api.paymentMethods() } as? ApiResult.Ok)?.data.orEmpty().filter { !it.comingSoon }
        methodId = methods?.firstOrNull()?.id
        if (owed > 0) amountText = String.format(Locale.US, "%.2f", owed / 100.0)
    }
    // The fee and the bonus for what they typed (one quote per change, after a short pause — not a loop).
    LaunchedEffect(methodId, minor) {
        quote = null
        val id = methodId ?: return@LaunchedEffect
        if (minor <= 0) return@LaunchedEffect
        kotlinx.coroutines.delay(400)
        quote = (apiCall { DriverBackend.api.topupQuote(JsonObject().apply { addProperty("payment_method_id", id); addProperty("amount_minor", minor) }) } as? ApiResult.Ok)?.data
    }
    // The gateway tells the server, the server tells us: paid or failed, as it happens.
    LaunchedEffect(payment?.uuid) {
        if (payment == null) return@LaunchedEffect
        DriverLive.events.collect { e ->
            when (e.name) {
                "wallet.topup.paid" -> result = "paid"
                "wallet.topup.failed" -> result = "failed"
            }
        }
    }
    // Back from the gateway's page: look once.
    val owner = LocalLifecycleOwner.current
    DisposableEffect(owner, payment?.uuid) {
        val observer = LifecycleEventObserver { _, event ->
            val uuid = payment?.uuid
            if (event == Lifecycle.Event.ON_RESUME && uuid != null && result == null) {
                scope.launch {
                    when ((apiCall { DriverBackend.api.topupStatus(uuid) } as? ApiResult.Ok)?.data?.status) {
                        "paid", "completed", "success", "succeeded" -> result = "paid"
                        "failed", "expired", "cancelled", "canceled" -> result = "failed"
                    }
                }
            }
        }
        owner.lifecycle.addObserver(observer)
        onDispose { owner.lifecycle.removeObserver(observer) }
    }

    ModalBottomSheet(onDismissRequest = { if (result == "paid") onDone() else onClose() }, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        Column(Modifier.padding(horizontal = 20.dp).padding(bottom = 28.dp).imePadding(), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(stringResource(R.string.wl_topup), fontWeight = FontWeight.ExtraBold, fontSize = 19.sp)
            AnimatedContent(
                targetState = result ?: if (payment != null) "waiting" else "form",
                transitionSpec = { (scaleIn(spring(dampingRatio = Spring.DampingRatioMediumBouncy)) + fadeIn()) togetherWith fadeOut() },
                label = "topup-state",
            ) { state ->
                Column(verticalArrangement = Arrangement.spacedBy(10.dp), modifier = Modifier.fillMaxWidth()) {
                    when (state) {
                        "paid" -> {
                            Column(Modifier.fillMaxWidth().padding(vertical = 16.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                                Icon(Icons.Filled.CheckCircle, null, tint = DorrBrand.success, modifier = Modifier.size(72.dp))
                                Text(stringResource(R.string.wl_topup_paid), fontWeight = FontWeight.ExtraBold, fontSize = 20.sp, textAlign = TextAlign.Center)
                            }
                            BigButton(stringResource(R.string.sc_done), color = DorrBrand.navy, onClick = onDone)
                        }
                        "failed" -> {
                            Column(Modifier.fillMaxWidth().padding(vertical = 16.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                                Icon(Icons.Filled.ErrorOutline, null, tint = DorrBrand.danger, modifier = Modifier.size(72.dp))
                                Text(stringResource(R.string.wl_topup_failed), fontWeight = FontWeight.Bold, fontSize = 18.sp, textAlign = TextAlign.Center)
                            }
                            BigButton(stringResource(R.string.wl_try_again), color = DorrBrand.orange) { payment = null; result = null; otp = "" }
                        }
                        "waiting" -> {
                            Column(Modifier.fillMaxWidth().padding(vertical = 12.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                                CircularProgressIndicator(color = DorrBrand.orange)
                                Spacer(Modifier.height(10.dp))
                                Text(stringResource(R.string.wl_topup_waiting), textAlign = TextAlign.Center, fontWeight = FontWeight.SemiBold)
                            }
                            payment?.redirectUrl?.let { url ->
                                BigButton(stringResource(R.string.wl_open_payment), color = DorrBrand.navy) { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url))) }
                            }
                            if (payment?.requiresOtp == true) {
                                DorrField(otp, { otp = it.filter { c -> c.isDigit() }.take(8) }, label = { Text(stringResource(R.string.wl_otp)) }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth())
                                error?.let { Text(it, color = MaterialTheme.colorScheme.error, fontSize = 13.sp) }
                                BigButton(stringResource(R.string.wl_otp_confirm), color = DorrBrand.navy, enabled = otp.length >= 4, busy = busy) {
                                    busy = true
                                    scope.launch {
                                        when (val r = apiCall { DriverBackend.api.confirmTopup(pin, payment!!.uuid, JsonObject().apply { addProperty("otp", otp) }) }) {
                                            is ApiResult.Ok -> if (r.data.status != "pending") result = if (r.data.status in setOf("failed", "expired")) "failed" else "paid"
                                            is ApiResult.Failed -> error = r.error.displayMessage
                                            is ApiResult.Offline -> error = null
                                        }
                                        busy = false
                                    }
                                }
                            }
                        }
                        else -> {
                            val list = methods
                            if (list == null) {
                                Box(Modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = DorrBrand.orange) }
                            } else if (list.isEmpty()) {
                                Text(stringResource(R.string.wl_no_gateways), color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
                            } else {
                                if (owed > 0) Text(stringResource(R.string.wl_you_owe, String.format(Locale.getDefault(), "%.2f", owed / 100.0) + " " + (w.currencySymbol ?: "")), color = DorrBrand.danger, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
                                Text(stringResource(R.string.wl_pay_with), fontSize = 12.sp)
                                list.forEach { m ->
                                    val on = m.id == methodId
                                    Row(
                                        Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).border(if (on) 2.dp else 1.dp, if (on) DorrBrand.orange else MaterialTheme.colorScheme.onSurface.copy(alpha = 0.12f), RoundedCornerShape(14.dp))
                                            .clickable { methodId = m.id }.padding(12.dp),
                                        verticalAlignment = Alignment.CenterVertically,
                                    ) {
                                        if (m.logoUrl != null) AsyncImage(m.logoUrl, null, Modifier.size(30.dp)) else Icon(Icons.Filled.CreditCard, null, tint = DorrBrand.navy)
                                        Spacer(Modifier.width(10.dp))
                                        Text(m.name ?: m.gateway.orEmpty(), fontWeight = FontWeight.SemiBold)
                                    }
                                }
                                DorrField(
                                    amountText, { amountText = it.filter { c -> c.isDigit() || c == '.' || c == ',' }.take(10) },
                                    label = { Text(stringResource(R.string.wl_amount, w.currencySymbol ?: "")) }, singleLine = true,
                                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.fillMaxWidth(),
                                )
                                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                    listOf(50, 100, 200, 500).forEach { v ->
                                        Text("$v", modifier = Modifier.clip(CircleShape).background(DorrBrand.navy.copy(alpha = 0.07f)).clickable { amountText = v.toString() }.padding(horizontal = 16.dp, vertical = 8.dp))
                                    }
                                }
                                quote?.let { q ->
                                    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(DorrBrand.navy.copy(alpha = 0.05f)).padding(12.dp)) {
                                        QuoteLine(stringResource(R.string.wl_q_paid), q.paidAmountMinor, w)
                                        if (q.feeMinor > 0) QuoteLine(stringResource(R.string.wl_q_fee), q.feeMinor, w)
                                        if (q.bonusMinor > 0) QuoteLine(stringResource(R.string.wl_q_bonus), q.bonusMinor, w, DorrBrand.success)
                                        QuoteLine(stringResource(R.string.wl_q_total), q.totalCreditedMinor, w, bold = true)
                                    }
                                }
                                DorrField(
                                    pin, { pin = it.filter { c -> c.isDigit() }.take(4) }, label = { Text(stringResource(R.string.wl_pin)) }, singleLine = true,
                                    visualTransformation = PasswordVisualTransformation(), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword), modifier = Modifier.fillMaxWidth(),
                                )
                                error?.let { Text(it, color = MaterialTheme.colorScheme.error, fontSize = 13.sp) }
                                BigButton(stringResource(R.string.wl_pay_now), color = DorrBrand.success, enabled = methodId != null && minor > 0 && pin.length == 4, busy = busy) {
                                    busy = true
                                    error = null
                                    scope.launch {
                                        val body = JsonObject().apply { addProperty("payment_method_id", methodId); addProperty("amount_minor", minor) }
                                        when (val r = apiCall { DriverBackend.api.topup(pin, idempotencyKey, body) }) {
                                            is ApiResult.Ok -> {
                                                payment = r.data
                                                r.data.redirectUrl?.let { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(it))) }
                                            }
                                            is ApiResult.Failed -> error = r.error.displayMessage
                                            is ApiResult.Offline -> error = null
                                        }
                                        busy = false
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun QuoteLine(label: String, minor: Long, w: WalletDto, color: Color = MaterialTheme.colorScheme.onSurface, bold: Boolean = false) {
    Row(Modifier.fillMaxWidth().padding(vertical = 2.dp)) {
        Text(label, fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.65f), modifier = Modifier.weight(1f))
        Text("${String.format(Locale.getDefault(), "%.2f", minor / 100.0)} ${w.currencySymbol.orEmpty()}".trim(), fontSize = 13.sp, color = color, fontWeight = if (bold) FontWeight.ExtraBold else FontWeight.SemiBold)
    }
}
