package com.dorr.driver.ui

import androidx.activity.compose.BackHandler
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
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
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.AccountBalance
import androidx.compose.material.icons.filled.ArrowDownward
import androidx.compose.material.icons.filled.ArrowUpward
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.PhoneAndroid
import androidx.compose.material.icons.filled.WarningAmber
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.CoreHttp
import com.dorr.core.network.apiCall
import com.dorr.core.ui.DorrBrand
import com.dorr.driver.R
import com.dorr.driver.live.DriverLive
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.WalletDto
import com.dorr.driver.net.WalletPinDto
import com.dorr.driver.net.WalletRowDto
import com.dorr.driver.net.WithdrawalDto
import com.dorr.driver.net.WithdrawalMethodDto
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import java.util.Locale

/**
 * The captain's wallet — the same wallet as the rider's, with their earnings in it: the balance (a
 * negative one is what they owe the app from cash rides), the statement, and withdrawing to a bank or a
 * mobile wallet with the wallet PIN. It follows the server through real-time: a settlement, a payout
 * decision or any movement shows up by itself, no refreshing.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun WalletScreen(onBack: () -> Unit) {
    val scope = rememberCoroutineScope()
    var wallet by remember { mutableStateOf<WalletDto?>(null) }
    var rows by remember { mutableStateOf<List<WalletRowDto>>(emptyList()) }
    var withdrawals by remember { mutableStateOf<List<WithdrawalDto>>(emptyList()) }
    var tab by remember { mutableIntStateOf(0) }
    var page by remember { mutableIntStateOf(1) }
    var more by remember { mutableStateOf(true) }
    var loadingMore by remember { mutableStateOf(false) }
    var withdrawOpen by remember { mutableStateOf(false) }
    var topupOpen by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var filter by remember { mutableStateOf(StatementFilter()) }
    var detailId by remember { mutableStateOf<String?>(null) }
    BackHandler(onBack = onBack)

    // The statement, narrowed by the filters (D-213); the same filters apply to "show more".
    suspend fun loadRows() {
        (apiCall { DriverBackend.api.walletTransactions(1, 25, filter.type, filter.direction, filter.from, filter.to) } as? ApiResult.Ok)
            ?.let { rows = it.data; page = 1; more = it.data.size >= 25 }
    }
    suspend fun load() {
        (apiCall { DriverBackend.api.wallet() } as? ApiResult.Ok)?.let { wallet = it.data }
        loadRows()
        (apiCall { DriverBackend.api.withdrawals(1) } as? ApiResult.Ok)?.let { withdrawals = it.data }
    }
    LaunchedEffect(Unit) {
        (apiCall { DriverBackend.api.wallet() } as? ApiResult.Ok)?.let { wallet = it.data }
        (apiCall { DriverBackend.api.withdrawals(1) } as? ApiResult.Ok)?.let { withdrawals = it.data }
        // A settlement, a payout decision, a transfer: the wallet reloads by itself.
        DriverLive.events.collect { e -> if (e.name.startsWith("wallet") || e.name.contains("withdrawal") || e.name.contains("earnings")) load() }
    }
    LaunchedEffect(filter) { loadRows() }

    val w = wallet
    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        Column(Modifier.fillMaxWidth().background(DorrBrand.navy).statusBarsPadding().padding(bottom = 20.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(8.dp)) {
                IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, null, tint = Color.White) }
                Text(stringResource(R.string.wl_title), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 18.sp)
            }
            val shown by animateFloatAsState((w?.totalMinor ?: 0).toFloat(), tween(600), label = "balance")
            Text(stringResource(R.string.wl_balance), color = Color.White.copy(alpha = 0.7f), fontSize = 13.sp, modifier = Modifier.padding(start = 20.dp))
            Text(
                amount(shown.toLong(), w), color = if ((w?.totalMinor ?: 0) < 0) Color(0xFFFFB4AB) else Color.White,
                fontWeight = FontWeight.ExtraBold, fontSize = 36.sp, modifier = Modifier.padding(start = 20.dp, top = 2.dp),
            )
            Row(Modifier.padding(horizontal = 16.dp).padding(top = 12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Chip(stringResource(R.string.wl_withdrawable), amount(w?.withdrawableMinor ?: 0, w))
                Chip(stringResource(R.string.wl_held), amount(w?.heldMinor ?: 0, w))
                if ((w?.spendOnlyMinor ?: 0) != 0L) Chip(stringResource(R.string.wl_spend_only), amount(w?.spendOnlyMinor ?: 0, w))
            }
        }
        if ((w?.totalMinor ?: 0) < 0) {
            Row(
                Modifier.fillMaxWidth().padding(16.dp).clip(RoundedCornerShape(16.dp)).background(DorrBrand.danger.copy(alpha = 0.1f)).padding(14.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Filled.WarningAmber, null, tint = DorrBrand.danger)
                Spacer(Modifier.width(10.dp))
                Text(stringResource(R.string.wl_owing), fontSize = 13.sp, modifier = Modifier.weight(1f))
            }
        }
        Row(Modifier.padding(horizontal = 16.dp).padding(top = 14.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Box(Modifier.weight(1f)) { BigButton(stringResource(R.string.wl_topup), color = DorrBrand.success, enabled = w != null) { topupOpen = true } }
            Box(Modifier.weight(1f)) { BigButton(stringResource(R.string.wl_withdraw), color = DorrBrand.orange, enabled = (w?.withdrawableMinor ?: 0) > 0) { withdrawOpen = true } }
        }
        Row(Modifier.padding(16.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf(R.string.wl_tab_statement, R.string.wl_tab_withdrawals).forEachIndexed { i, label ->
                val bg by animateColorAsState(if (tab == i) DorrBrand.navy else DorrBrand.navy.copy(alpha = 0.07f), tween(200), label = "tab")
                Text(
                    stringResource(label), color = if (tab == i) Color.White else DorrBrand.navy, fontWeight = FontWeight.SemiBold,
                    modifier = Modifier.clip(CircleShape).background(bg).clickable { tab = i }.padding(horizontal = 16.dp, vertical = 8.dp),
                )
            }
        }
        if (tab == 0) StatementFilters(filter) { filter = it }
        if (tab == 0) {
            if (rows.isEmpty()) EmptyHint(stringResource(if (filter.active) R.string.wl_empty_filtered else R.string.wl_empty))
            else LazyColumn(Modifier.fillMaxSize().padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                items(rows, key = { it.uuid }) { r -> TransactionRow(r, w) { detailId = r.uuid } }
                if (more) item {
                    Box(Modifier.fillMaxWidth().padding(8.dp), contentAlignment = Alignment.Center) {
                        if (loadingMore) CircularProgressIndicator(Modifier.size(24.dp), strokeWidth = 2.dp, color = DorrBrand.orange)
                        else Text(
                            stringResource(R.string.wl_more), color = DorrBrand.orange, fontWeight = FontWeight.SemiBold,
                            modifier = Modifier.clip(RoundedCornerShape(12.dp)).clickable {
                                loadingMore = true
                                scope.launch {
                                    val next = (apiCall { DriverBackend.api.walletTransactions(page + 1, 25, filter.type, filter.direction, filter.from, filter.to) } as? ApiResult.Ok)?.data.orEmpty()
                                    if (next.isNotEmpty()) { rows = rows + next; page += 1 }
                                    more = next.size >= 25
                                    loadingMore = false
                                }
                            }.padding(12.dp),
                        )
                    }
                }
                item { Spacer(Modifier.height(16.dp)) }
            }
        } else {
            if (withdrawals.isEmpty()) EmptyHint(stringResource(R.string.wl_no_withdrawals))
            else LazyColumn(Modifier.fillMaxSize().padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                items(withdrawals, key = { it.id }) { WithdrawalRow(it, w) }
                item { Spacer(Modifier.height(16.dp)) }
            }
        }
    }
    error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    if (topupOpen && w != null) TopupSheet(w, onClose = { topupOpen = false }, onDone = { topupOpen = false; scope.launch { load() } })
    detailId?.let { id -> TransactionDetailSheet(id, w, onClose = { detailId = null }) }
    if (withdrawOpen && w != null) WithdrawSheet(w, onClose = { withdrawOpen = false }, onDone = { withdrawOpen = false; tab = 1; scope.launch { load() } })
}

@Composable
private fun EmptyHint(text: String) {
    Box(Modifier.fillMaxWidth().padding(32.dp), contentAlignment = Alignment.Center) {
        Text(text, color = MaterialTheme.colorScheme.onBackground.copy(alpha = 0.55f), textAlign = TextAlign.Center)
    }
}

@Composable
private fun Chip(label: String, value: String) {
    Column(Modifier.clip(RoundedCornerShape(14.dp)).background(Color.White.copy(alpha = 0.12f)).padding(horizontal = 12.dp, vertical = 8.dp)) {
        Text(label, color = Color.White.copy(alpha = 0.7f), fontSize = 11.sp)
        Text(value, color = Color.White, fontWeight = FontWeight.Bold, fontSize = 14.sp)
    }
}

@Composable
private fun TransactionRow(r: WalletRowDto, w: WalletDto?, onClick: () -> Unit) {
    val credit = r.direction == "credit"
    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(MaterialTheme.colorScheme.surface).clickable(onClick = onClick).padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(40.dp).clip(CircleShape).background((if (credit) DorrBrand.success else DorrBrand.danger).copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
            Icon(if (credit) Icons.Filled.ArrowDownward else Icons.Filled.ArrowUpward, null, tint = if (credit) DorrBrand.success else DorrBrand.danger, modifier = Modifier.size(20.dp))
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(r.typeLabel, fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
            val sub = listOfNotNull(r.note, whenText(r.createdAt)).joinToString(" · ")
            if (sub.isNotBlank()) Text(sub, fontSize = 11.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.55f), maxLines = 2)
        }
        Text((if (credit) "+" else "−") + amount(r.amountMinor, w), fontWeight = FontWeight.ExtraBold, color = if (credit) DorrBrand.success else DorrBrand.danger, fontSize = 15.sp)
    }
}

@Composable
private fun WithdrawalRow(d: WithdrawalDto, w: WalletDto?) {
    val color = when (d.status) { "approved" -> DorrBrand.success; "rejected" -> DorrBrand.danger; else -> DorrBrand.warning }
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(MaterialTheme.colorScheme.surface).padding(14.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(amount(d.amountMinor, w), fontWeight = FontWeight.ExtraBold, fontSize = 17.sp, modifier = Modifier.weight(1f))
            Text(
                stringResource(when (d.status) { "approved" -> R.string.wl_st_approved; "rejected" -> R.string.wl_st_rejected; else -> R.string.wl_st_pending }),
                color = color, fontSize = 11.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(CircleShape).background(color.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 4.dp),
            )
        }
        Text(listOfNotNull(d.method?.label, d.method?.display).joinToString(" · "), fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
        whenText(d.createdAt).takeIf { it.isNotBlank() }?.let { Text(it, fontSize = 11.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.45f)) }
        d.rejectionReason?.let { Text(it, fontSize = 12.sp, color = DorrBrand.danger, modifier = Modifier.padding(top = 4.dp)) }
    }
}

/** Withdraw: pick (or add) where to, the amount, then the wallet PIN — made first if there isn't one yet. */
@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
private fun WithdrawSheet(w: WalletDto, onClose: () -> Unit, onDone: () -> Unit) {
    val scope = rememberCoroutineScope()
    var methods by remember { mutableStateOf<List<WithdrawalMethodDto>?>(null) }
    var pinInfo by remember { mutableStateOf<WalletPinDto?>(null) }
    var methodId by remember { mutableStateOf<Int?>(null) }
    var adding by remember { mutableStateOf(false) }
    var amountText by remember { mutableStateOf("") }
    var pin by remember { mutableStateOf("") }
    var pinConfirm by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    // The new method's fields.
    var type by remember { mutableStateOf("bank") }
    var label by remember { mutableStateOf("") }
    var bankName by remember { mutableStateOf("") }
    var holder by remember { mutableStateOf("") }
    var iban by remember { mutableStateOf("") }
    var provider by remember { mutableStateOf("") }
    var number by remember { mutableStateOf("") }

    suspend fun load() {
        methods = (apiCall { DriverBackend.api.withdrawalMethods() } as? ApiResult.Ok)?.data.orEmpty()
        pinInfo = (apiCall { DriverBackend.api.walletPin() } as? ApiResult.Ok)?.data
        if (methodId == null) methodId = methods?.firstOrNull { it.isFavorite }?.id ?: methods?.firstOrNull()?.id
        if (methods.isNullOrEmpty()) adding = true
    }
    LaunchedEffect(Unit) { load() }

    val minor = amountText.replace(',', '.').toDoubleOrNull()?.let { Math.round(it * 100) } ?: 0L
    val needsPinSetup = pinInfo?.hasPin == false

    ModalBottomSheet(onDismissRequest = onClose, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        Column(Modifier.padding(horizontal = 20.dp).padding(bottom = 28.dp).imePadding(), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(stringResource(R.string.wl_withdraw), fontWeight = FontWeight.ExtraBold, fontSize = 19.sp)
            Text(stringResource(R.string.wl_can_withdraw, amount(w.withdrawableMinor, w)), fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.65f))
            val list = methods
            if (list == null || pinInfo == null) {
                Box(Modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = DorrBrand.orange) }
                return@Column
            }
            if (!adding) {
                Text(stringResource(R.string.wl_to), fontSize = 12.sp)
                list.forEach { m ->
                    val on = m.id == methodId
                    Row(
                        Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).border(if (on) 2.dp else 1.dp, if (on) DorrBrand.orange else MaterialTheme.colorScheme.onSurface.copy(alpha = 0.12f), RoundedCornerShape(14.dp))
                            .clickable { methodId = m.id }.padding(12.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(if (m.type == "bank") Icons.Filled.AccountBalance else Icons.Filled.PhoneAndroid, null, tint = DorrBrand.navy)
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text(m.label ?: stringResource(if (m.type == "bank") R.string.wl_bank else R.string.wl_mobile), fontWeight = FontWeight.SemiBold)
                            Text(m.display.orEmpty(), fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
                        }
                        Icon(Icons.Filled.Close, null, modifier = Modifier.size(18.dp).clip(CircleShape).clickable {
                            scope.launch { apiCall { DriverBackend.api.deleteWithdrawalMethod(m.id) }; if (methodId == m.id) methodId = null; load() }
                        })
                    }
                }
                Text(stringResource(R.string.wl_add_method), color = DorrBrand.orange, fontWeight = FontWeight.SemiBold, modifier = Modifier.clip(RoundedCornerShape(10.dp)).clickable { adding = true }.padding(8.dp))
                OutlinedTextField(amountText, { amountText = it.filter { c -> c.isDigit() || c == '.' || c == ',' }.take(10) }, label = { Text(stringResource(R.string.wl_amount, w.currencySymbol ?: "")) }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.fillMaxWidth())
            } else {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    listOf("bank" to R.string.wl_bank, "mobile_wallet" to R.string.wl_mobile).forEach { (key, name) ->
                        val on = key == type
                        Text(stringResource(name), color = if (on) Color.White else DorrBrand.navy, modifier = Modifier.clip(CircleShape).background(if (on) DorrBrand.navy else DorrBrand.navy.copy(alpha = 0.07f)).clickable { type = key }.padding(horizontal = 16.dp, vertical = 8.dp))
                    }
                }
                OutlinedTextField(label, { label = it.take(60) }, label = { Text(stringResource(R.string.wl_label)) }, singleLine = true, modifier = Modifier.fillMaxWidth())
                if (type == "bank") {
                    OutlinedTextField(bankName, { bankName = it.take(100) }, label = { Text(stringResource(R.string.wl_bank_name)) }, singleLine = true, modifier = Modifier.fillMaxWidth())
                    OutlinedTextField(holder, { holder = it.take(100) }, label = { Text(stringResource(R.string.wl_holder)) }, singleLine = true, modifier = Modifier.fillMaxWidth())
                    OutlinedTextField(iban, { iban = it.filter { c -> c.isLetterOrDigit() }.take(34) }, label = { Text("IBAN") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                } else {
                    OutlinedTextField(provider, { provider = it.take(100) }, label = { Text(stringResource(R.string.wl_provider)) }, singleLine = true, modifier = Modifier.fillMaxWidth())
                    OutlinedTextField(number, { number = it.filter { c -> c.isDigit() }.take(20) }, label = { Text(stringResource(R.string.wl_number)) }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth())
                }
            }

            // The wallet PIN: made once, asked for each time money moves.
            if (needsPinSetup) {
                Text(stringResource(R.string.wl_make_pin), fontWeight = FontWeight.SemiBold)
                PinField(pin, stringResource(R.string.wl_pin)) { pin = it }
                PinField(pinConfirm, stringResource(R.string.wl_pin_confirm)) { pinConfirm = it }
            } else {
                PinField(pin, stringResource(R.string.wl_pin)) { pin = it }
            }
            error?.let { Text(it, color = MaterialTheme.colorScheme.error, fontSize = 13.sp) }

            val ready = if (adding) {
                pin.length == 4 && (!needsPinSetup || pin == pinConfirm) &&
                    if (type == "bank") bankName.isNotBlank() && holder.isNotBlank() && iban.length >= 15 else provider.isNotBlank() && number.length >= 6
            } else {
                pin.length == 4 && (!needsPinSetup || pin == pinConfirm) && methodId != null && minor > 0 && minor <= w.withdrawableMinor
            }
            BigButton(stringResource(if (adding) R.string.wl_save_method else R.string.wl_send_request), color = DorrBrand.navy, enabled = ready, busy = busy) {
                busy = true
                error = null
                scope.launch {
                    if (needsPinSetup) {
                        val made = apiCall { DriverBackend.api.createWalletPin(JsonObject().apply { addProperty("pin", pin); addProperty("pin_confirmation", pinConfirm) }) }
                        if (made !is ApiResult.Ok) {
                            error = (made as? ApiResult.Failed)?.error?.displayMessage
                            busy = false
                            return@launch
                        }
                        pinInfo = pinInfo?.copy(hasPin = true)
                    }
                    if (adding) {
                        val body = JsonObject().apply {
                            addProperty("type", type)
                            if (label.isNotBlank()) addProperty("label", label.trim())
                            add("data", JsonObject().apply {
                                if (type == "bank") { addProperty("bank_name", bankName.trim()); addProperty("account_holder", holder.trim()); addProperty("iban", iban.trim()) }
                                else { addProperty("provider_name", provider.trim()); addProperty("number", number.trim()) }
                            })
                        }
                        when (val r = apiCall { DriverBackend.api.addWithdrawalMethod(pin, body) }) {
                            is ApiResult.Ok -> { methodId = r.data.id; adding = false; pin = ""; pinConfirm = ""; load() }
                            is ApiResult.Failed -> error = r.error.displayMessage
                            is ApiResult.Offline -> error = null
                        }
                    } else {
                        val body = JsonObject().apply { addProperty("withdrawal_method_id", methodId); addProperty("amount_minor", minor) }
                        when (val r = apiCall { DriverBackend.api.withdraw(pin, CoreHttp.newIdempotencyKey(), body) }) {
                            is ApiResult.Ok -> onDone()
                            is ApiResult.Failed -> error = r.error.displayMessage
                            is ApiResult.Offline -> error = null
                        }
                    }
                    busy = false
                }
            }
        }
    }
}

@Composable
private fun PinField(value: String, label: String, onChange: (String) -> Unit) {
    OutlinedTextField(
        value, { onChange(it.filter { c -> c.isDigit() }.take(4)) }, label = { Text(label) }, singleLine = true,
        visualTransformation = PasswordVisualTransformation(), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
        modifier = Modifier.fillMaxWidth(),
    )
}

/** "125.50 ر.س" from minor units (the wallet's currency; two decimals). */
internal fun amount(minor: Long, w: WalletDto?): String =
    "${String.format(Locale.getDefault(), "%.2f", minor / 100.0)} ${w?.currencySymbol ?: w?.currencyCode.orEmpty()}".trim()

internal fun whenText(iso: String?): String {
    val at = iso?.let { runCatching { OffsetDateTime.parse(it) }.getOrNull() } ?: return ""
    return at.atZoneSameInstant(ZoneId.systemDefault()).format(DateTimeFormatter.ofLocalizedDateTime(FormatStyle.MEDIUM, FormatStyle.SHORT).withLocale(Locale.getDefault()))
}
