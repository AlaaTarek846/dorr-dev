package com.dorr.freelance.ui.screens

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import androidx.activity.compose.BackHandler
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.automirrored.rounded.List
import androidx.compose.material.icons.rounded.AccountBalance
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.CalendarToday
import androidx.compose.material.icons.rounded.CardGiftcard
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.ChevronLeft
import androidx.compose.material.icons.rounded.ContentCopy
import androidx.compose.material.icons.rounded.DeleteOutline
import androidx.compose.material.icons.rounded.Fingerprint
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.NorthEast
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.OpenInNew
import androidx.compose.material.icons.rounded.PauseCircle
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.Phone
import androidx.compose.material.icons.rounded.Receipt
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Settings
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material.icons.rounded.SouthWest
import androidx.compose.material.icons.rounded.Tag
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material.icons.rounded.VisibilityOff
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import com.dorr.core.network.ApiResult
import com.dorr.core.network.CoreHttp
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.OtpBody
import com.dorr.freelance.net.PaymentMethodDto
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.TopupPaymentDto
import com.dorr.freelance.net.TopupQuoteDto
import com.dorr.freelance.net.TopupRequest
import com.dorr.freelance.net.WalletBalanceDto
import com.dorr.freelance.net.WalletBiometric
import com.dorr.freelance.net.WalletTransactionDto
import com.dorr.freelance.net.WithdrawalDto
import com.dorr.freelance.net.WithdrawalMethodDto
import com.dorr.freelance.net.apiDone
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.net.findFragmentActivity
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.kit.RedToggle
import com.dorr.freelance.ui.locale.LocaleAwareBottomSheet
import com.dorr.freelance.ui.wallet.GatewayLayer
import com.dorr.freelance.ui.wallet.LocalWalletOrNull
import com.dorr.freelance.ui.wallet.MethodCard
import com.dorr.freelance.ui.wallet.OtpField
import com.dorr.freelance.ui.wallet.PadResult
import com.dorr.freelance.ui.wallet.QuoteCard
import com.dorr.freelance.ui.wallet.Tone
import com.dorr.freelance.ui.wallet.Wa
import com.dorr.freelance.ui.wallet.WaAmountEntry
import com.dorr.freelance.ui.wallet.WaButton
import com.dorr.freelance.ui.wallet.WaButtonStyle
import com.dorr.freelance.ui.wallet.WaCard
import com.dorr.freelance.ui.wallet.WaChip
import com.dorr.freelance.ui.wallet.WaConfetti
import com.dorr.freelance.ui.wallet.WaCtaHint
import com.dorr.freelance.ui.wallet.WaDivider
import com.dorr.freelance.ui.wallet.WaEmpty
import com.dorr.freelance.ui.wallet.WaIconWell
import com.dorr.freelance.ui.wallet.WaKeyValue
import com.dorr.freelance.ui.wallet.WaLoadingDots
import com.dorr.freelance.ui.wallet.WaNote
import com.dorr.freelance.ui.wallet.WaOutlineCircleButton
import com.dorr.freelance.ui.wallet.WaPage
import com.dorr.freelance.ui.wallet.WaPinPad
import com.dorr.freelance.ui.wallet.WaPulse
import com.dorr.freelance.ui.wallet.WaSeal
import com.dorr.freelance.ui.wallet.WaSectionTitle
import com.dorr.freelance.ui.wallet.WaSkeleton
import com.dorr.freelance.ui.wallet.WaStatusColumn
import com.dorr.freelance.ui.wallet.WaStatusText
import com.dorr.freelance.ui.wallet.WaStatusTitle
import com.dorr.freelance.ui.wallet.dayText
import com.dorr.freelance.ui.wallet.money
import com.dorr.freelance.ui.wallet.parseAmountToMinor
import com.dorr.freelance.ui.wallet.rememberPressScale
import com.dorr.freelance.ui.wallet.timeOf
import com.dorr.freelance.ui.wallet.txMeta
import com.dorr.freelance.ui.wallet.waRise
import com.dorr.freelance.ui.wallet.walletNight
import com.google.gson.JsonObject
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File

/**
 * The wallet once unlocked, in the DORR app's wallet look: the home (the gradient balance card, four actions, the latest
 * movements) and its pages — top up, withdraw, statement, settings. Back from a page goes home first. Nothing is asked on a
 * timer: a page reads once when it opens (and when the connection comes back), and a live notice reloads once.
 */
@Composable
fun WalletHomeHost(onBack: () -> Unit) {
    var page by rememberSaveable { mutableStateOf("home") }
    var balance by remember { mutableStateOf<WalletBalanceDto?>(null) }
    var hidden by rememberSaveable { mutableStateOf(false) }
    var sheetTx by remember { mutableStateOf<WalletTransactionDto?>(null) }
    var explain by remember { mutableStateOf(false) }
    val reconnect = collectReconnectTick()

    suspend fun reload() {
        (apiCall { ApiClient.wallet.balance() } as? ApiResult.Ok)?.data?.let { balance = it }
    }
    LaunchedEffect(reconnect) { reload() }
    LaunchedEffect(Unit) { Realtime.changed.collect { reload() } }
    LaunchedEffect(page) { if (page == "home") reload() }

    BackHandler(enabled = page != "home") { page = "home" }
    val home = { page = "home" }
    val currency = balance?.currencyCode.orEmpty()

    when (page) {
        "topup" -> WalletTopup(balance, hidden, onBack = home, onStatement = { page = "history" }, onPaid = { /* the home reloads */ })
        "withdraw" -> WalletWithdraw(balance, onBack = home)
        "history" -> WalletHistory(currency, onBack = home, onOpen = { sheetTx = it })
        "security" -> WalletSecurity(onBack = home)
        else -> WalletHome(
            balance = balance, hidden = hidden, onHide = { hidden = !hidden }, onBack = onBack,
            onRefresh = { /* re-read below */ }, onOpen = { page = it }, onTx = { sheetTx = it }, onExplain = { explain = true },
        )
    }

    sheetTx?.let { tx -> TxSheet(tx, currency) { sheetTx = null } }
    if (explain) ExplainSheet { explain = false }
}

@Composable
private fun WalletHome(
    balance: WalletBalanceDto?,
    hidden: Boolean,
    onHide: () -> Unit,
    onBack: () -> Unit,
    onRefresh: () -> Unit,
    onOpen: (String) -> Unit,
    onTx: (WalletTransactionDto) -> Unit,
    onExplain: () -> Unit,
) {
    val scope = rememberCoroutineScope()
    var recent by remember { mutableStateOf<List<WalletTransactionDto>?>(null) }
    val reconnect = collectReconnectTick()
    val currency = balance?.currencyCode.orEmpty()

    suspend fun load() {
        (apiCall { ApiClient.wallet.transactions(page = 1, perPage = 5) } as? ApiResult.Ok)?.data?.let { recent = it }
    }
    LaunchedEffect(reconnect, balance?.totalMinor) { load() }

    WaPage(
        title = stringResource(R.string.wa_my_wallet),
        onBack = onBack,
        outlined = true,
        actions = {
            WaOutlineCircleButton(if (hidden) Icons.Rounded.VisibilityOff else Icons.Rounded.Visibility, onHide)
            WaOutlineCircleButton(Icons.Rounded.Refresh, { onRefresh(); scope.launch { load() } })
        },
    ) {
        Box(Modifier.waRise(0)) {
            if (balance != null) HeroCard(balance, hidden, onExplain) else WaSkeleton(Modifier.fillMaxWidth().height(172.dp), RoundedCornerShape(24.dp))
        }

        Row(Modifier.fillMaxWidth().padding(top = 12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            HomeAction(stringResource(R.string.wa_action_topup), 1, Modifier.weight(1f), { onOpen("topup") }) {
                Icon(painterResource(R.drawable.ic_ms_add_card), null, tint = Wa.Red, modifier = Modifier.size(22.dp))
            }
            HomeAction(stringResource(R.string.wl_withdraw), 2, Modifier.weight(1f), { onOpen("withdraw") }) {
                Icon(Icons.Rounded.AccountBalance, null, tint = Wa.Red, modifier = Modifier.size(22.dp))
            }
            HomeAction(stringResource(R.string.wa_action_history), 3, Modifier.weight(1f), { onOpen("history") }) {
                Icon(painterResource(R.drawable.ic_ms_credit_card_clock), null, tint = Wa.Red, modifier = Modifier.size(22.dp))
            }
            HomeAction(stringResource(R.string.wa_action_pin), 4, Modifier.weight(1f), { onOpen("security") }) {
                Icon(Icons.Rounded.Settings, null, tint = Wa.Red, modifier = Modifier.size(22.dp))
            }
        }

        WaSectionTitle(stringResource(R.string.wa_recent), Modifier.waRise(5), topPadding = 12.dp) {
            Row(
                Modifier.clickable(interactionSource = remember { MutableInteractionSource() }, indication = null) { onOpen("history") },
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(stringResource(R.string.wa_view_all), color = Wa.Red, fontSize = 13.sp, fontWeight = FontWeight.Bold)
                Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, null, tint = Wa.Red, modifier = Modifier.size(16.dp))
            }
        }

        val rows = recent
        WaCard(Modifier.fillMaxWidth().waRise(6)) {
            when {
                rows == null -> Column(Modifier.padding(horizontal = 14.dp)) { repeat(3) { TxSkeleton() } }
                rows.isEmpty() -> WaEmpty(
                    Icons.Rounded.AutoAwesome, Tone.Red, stringResource(R.string.wa_no_tx), stringResource(R.string.wa_no_tx_hint),
                    action = { WaButton(stringResource(R.string.wa_topup_now), { onOpen("topup") }, icon = Icons.Rounded.Add, modifier = Modifier.padding(horizontal = 40.dp)) },
                )
                else -> Column(Modifier.padding(horizontal = 14.dp, vertical = 4.dp)) {
                    rows.forEachIndexed { index, tx ->
                        TxRow(tx, currency) { onTx(tx) }
                        if (index < rows.lastIndex) WaDivider()
                    }
                }
            }
        }
    }
}

@Composable
private fun HomeAction(label: String, index: Int, modifier: Modifier, onClick: () -> Unit, icon: @Composable () -> Unit) {
    val source = remember { MutableInteractionSource() }
    val scale by rememberPressScale(source, 0.93f)
    val shape = RoundedCornerShape(16.dp)
    Column(
        modifier
            .waRise(index)
            .scale(scale)
            .clip(shape)
            .background(Wa.Surface)
            .border(1.dp, Wa.Red.copy(alpha = 0.3f), shape)
            .clickable(interactionSource = source, indication = null, onClick = onClick)
            .padding(top = 10.dp, bottom = 8.dp, start = 2.dp, end = 2.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        icon()
        Text(label, fontSize = 11.sp, fontWeight = FontWeight.Bold, color = Wa.Ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

/** The balance card of the DORR app: the wallet number with copy, the total (counting up), then withdrawable | services only. */
@Composable
private fun HeroCard(balance: WalletBalanceDto, hidden: Boolean, onExplain: () -> Unit) {
    val context = LocalContext.current
    val host = LocalWalletOrNull.current
    val currencyCode = balance.currencyCode.orEmpty()
    val number = balance.walletNumberFormatted.orEmpty()
    val copiedText = stringResource(R.string.wa_number_copied)
    var copied by remember { mutableStateOf(false) }
    LaunchedEffect(copied) {
        if (copied) {
            kotlinx.coroutines.delay(1600)
            copied = false
        }
    }
    val shown = remember { Animatable(0f) }
    LaunchedEffect(balance.totalMinor) { shown.animateTo(balance.totalMinor.toFloat(), tween(900)) }

    val shape = RoundedCornerShape(24.dp)
    val barShape = RoundedCornerShape(18.dp)
    Box(
        Modifier
            .fillMaxWidth()
            .shadow(14.dp, shape, ambientColor = Wa.Red.copy(alpha = 0.22f), spotColor = Wa.Red.copy(alpha = 0.32f))
            .clip(shape)
            .background(Wa.HeroBrush),
    ) {
        Box(Modifier.size(150.dp).offset(x = (-30).dp, y = 50.dp).align(Alignment.BottomStart).background(Color(0x10FFFFFF), CircleShape))
        Column(Modifier.padding(horizontal = 14.dp, vertical = 12.dp)) {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.wa_wallet_number), color = Color.White.copy(alpha = 0.68f), fontSize = 11.sp, fontWeight = FontWeight.Medium, maxLines = 1)
                    if (number.isNotBlank()) {
                        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                            Text(number, color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 1, modifier = Modifier.padding(top = 2.dp))
                        }
                    }
                }
                if (number.isNotBlank()) {
                    HeroCircle(if (copied) Icons.Rounded.Check else Icons.Rounded.ContentCopy) {
                        val clipboard = context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
                        clipboard.setPrimaryClip(ClipData.newPlainText("wallet", number))
                        copied = true
                        host?.showToast(copiedText)
                    }
                }
            }
            Box(Modifier.fillMaxWidth().padding(top = 10.dp, bottom = 10.dp).height(1.dp).background(Color.White.copy(alpha = 0.16f)))
            Row(
                Modifier.clip(RoundedCornerShape(50)).background(Color.White.copy(alpha = 0.12f)).border(1.dp, Color.White.copy(alpha = 0.16f), RoundedCornerShape(50)).padding(horizontal = 9.dp, vertical = 4.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.AccountBalanceWallet, null, tint = Color.White.copy(alpha = 0.9f), modifier = Modifier.size(12.dp))
                Spacer(Modifier.width(5.dp))
                Text(stringResource(R.string.wa_total_balance), color = Color.White.copy(alpha = 0.92f), fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
            }
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                Row(Modifier.padding(top = 6.dp, bottom = 10.dp), verticalAlignment = Alignment.Bottom) {
                    Text(if (hidden) "******" else money(shown.value.toLong()), color = Color.White, fontSize = 28.sp, fontWeight = FontWeight.ExtraBold, letterSpacing = (-0.3).sp, maxLines = 1)
                    if (currencyCode.isNotBlank()) {
                        Spacer(Modifier.width(8.dp))
                        Text(currencyCode, color = Color.White.copy(alpha = 0.82f), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(bottom = 3.dp))
                    }
                }
            }
            Row(
                Modifier.fillMaxWidth().clip(barShape).background(Color.White.copy(alpha = 0.12f)).padding(vertical = 9.dp, horizontal = 4.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                HeroPart(Icons.Rounded.Check, stringResource(R.string.wa_withdrawable), if (hidden) "****" else "${money(balance.withdrawableMinor)} $currencyCode", Modifier.weight(1f), onExplain)
                Box(Modifier.width(1.dp).height(34.dp).background(Color.White.copy(alpha = 0.22f)))
                HeroPart(Icons.Rounded.CardGiftcard, stringResource(R.string.wa_spend_only), if (hidden) "****" else "${money(balance.spendOnlyMinor)} $currencyCode", Modifier.weight(1f), onExplain)
            }
            if (balance.heldMinor > 0) {
                Row(Modifier.padding(top = 10.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    Icon(Icons.Rounded.PauseCircle, null, tint = Color.White.copy(alpha = 0.92f), modifier = Modifier.size(16.dp))
                    Text(
                        stringResource(R.string.wa_held_temporarily) + " " + (if (hidden) "****" else money(balance.heldMinor)) + " " + currencyCode,
                        color = Color.White.copy(alpha = 0.92f), fontSize = 12.sp,
                    )
                }
            }
        }
    }
}

@Composable
private fun HeroCircle(icon: ImageVector, onClick: () -> Unit) {
    Box(
        Modifier.size(32.dp).clip(CircleShape).border(1.dp, Color.White.copy(alpha = 0.40f), CircleShape).clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Icon(icon, null, tint = Color.White, modifier = Modifier.size(16.dp)) }
}

@Composable
private fun HeroPart(icon: ImageVector, label: String, value: String, modifier: Modifier, onClick: () -> Unit) {
    val source = remember { MutableInteractionSource() }
    val scale by rememberPressScale(source, 0.96f)
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    Column(modifier.scale(scale).clickable(interactionSource = source, indication = null, onClick = onClick).padding(horizontal = 10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(icon, null, tint = Color.White.copy(alpha = 0.92f), modifier = Modifier.size(15.dp))
            Spacer(Modifier.width(6.dp))
            Text(label, color = Color.White.copy(alpha = 0.86f), fontSize = 11.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
            Text(
                value, color = Color.White, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1,
                textAlign = if (rtl) TextAlign.Right else TextAlign.Left, modifier = Modifier.fillMaxWidth().padding(top = 6.dp),
            )
        }
    }
}

// ---------------------------------------------------------------- movements

@Composable
private fun TxSkeleton() {
    Row(Modifier.fillMaxWidth().padding(vertical = 12.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        WaSkeleton(Modifier.size(44.dp), RoundedCornerShape(15.dp))
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            WaSkeleton(Modifier.fillMaxWidth(0.55f).height(12.dp))
            WaSkeleton(Modifier.fillMaxWidth(0.35f).height(10.dp))
        }
        WaSkeleton(Modifier.width(60.dp).height(14.dp))
    }
}

/** The home and statement row: a round brand-tinted icon, the type, and the signed amount with the day and time under it. */
@Composable
private fun TxRow(tx: WalletTransactionDto, currency: String, onClick: () -> Unit) {
    val (icon, _) = txMeta(tx.type)
    val credit = tx.direction == "credit"
    Row(
        Modifier.fillMaxWidth().clickable(interactionSource = remember { MutableInteractionSource() }, indication = null, onClick = onClick).padding(vertical = 13.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        WaIconWell(icon, Tone.Red, size = 36.dp, iconSize = 16.dp)
        Column(Modifier.weight(1f)) {
            Text(tx.typeLabel.ifBlank { tx.type }, fontSize = 13.sp, fontWeight = FontWeight.Bold, color = Wa.Ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
            if (tx.bucket == "spend_only") {
                Text(
                    stringResource(R.string.wa_services_only_badge), color = Wa.Red, fontSize = 10.sp, fontWeight = FontWeight.Bold,
                    modifier = Modifier.padding(top = 3.dp).clip(RoundedCornerShape(7.dp))
                        .background(if (walletNight()) com.dorr.freelance.ui.kit.AccountDark.well else Wa.Red.copy(alpha = 0.14f)).padding(horizontal = 7.dp, vertical = 2.dp),
                )
            }
        }
        Column(horizontalAlignment = Alignment.End) {
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                Row(verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text((if (credit) "+ " else "- ") + money(tx.amountMinor), fontSize = 13.sp, fontWeight = FontWeight.Bold, color = if (credit) Wa.Green else Wa.Ink)
                    if (currency.isNotBlank()) Text(currency, color = Wa.Mut, fontSize = 11.sp, modifier = Modifier.padding(bottom = 1.dp))
                }
            }
            Text(dayText(tx.createdAt) + "، " + timeOf(tx.createdAt), color = Wa.Soft, fontSize = 11.sp, modifier = Modifier.padding(top = 2.dp))
        }
    }
}

/** One movement, in a sheet: its kind, the signed amount, the day, the balance bucket, the balance after, the note, its number. */
@OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)
@Composable
private fun TxSheet(tx: WalletTransactionDto, currency: String, onClose: () -> Unit) {
    val (icon, _) = txMeta(tx.type)
    val credit = tx.direction == "credit"
    val spend = tx.bucket == "spend_only"
    LocaleAwareBottomSheet(onDismissRequest = onClose, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(start = 20.dp, end = 20.dp, bottom = 26.dp)) {
            Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
                WaIconWell(icon, Tone.Red, size = 60.dp, iconSize = 28.dp)
                Spacer(Modifier.height(10.dp))
                Text(tx.typeLabel.ifBlank { tx.type }, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp, color = Wa.Ink, textAlign = TextAlign.Center)
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    Text(
                        (if (credit) "+ " else "− ") + money(tx.amountMinor) + " " + currency,
                        fontWeight = FontWeight.ExtraBold, fontSize = 26.sp, color = if (credit) Wa.Green else Wa.Danger, modifier = Modifier.padding(top = 6.dp),
                    )
                }
            }
            Spacer(Modifier.height(14.dp))
            WaKeyValue(stringResource(R.string.wa_date), dayText(tx.createdAt) + " · " + timeOf(tx.createdAt), icon = Icons.Rounded.CalendarToday)
            WaDivider()
            WaKeyValue(
                stringResource(R.string.wa_bucket), stringResource(if (spend) R.string.wa_spend_only else R.string.wa_withdrawable),
                icon = if (spend) Icons.Rounded.CardGiftcard else Icons.Rounded.AccountBalance,
            )
            WaDivider()
            WaKeyValue(stringResource(R.string.wa_balance_after), money(tx.balanceAfterMinor) + " " + currency, ltr = true, icon = Icons.Rounded.AccountBalanceWallet)
            val note = tx.note
            if (!note.isNullOrBlank() && note != tx.typeLabel) {
                WaDivider()
                WaKeyValue(stringResource(R.string.wa_note), note, icon = Icons.Rounded.Notes)
            }
            WaDivider()
            WaKeyValue(stringResource(R.string.wa_tx_number), tx.uuid.take(8), valueColor = Wa.Soft, icon = Icons.Rounded.Tag)
            if (spend && credit) {
                Spacer(Modifier.height(12.dp))
                WaNote(stringResource(R.string.wa_spend_only_received_note))
            }
            Spacer(Modifier.height(14.dp))
            WaButton(stringResource(R.string.wa_close), onClick = onClose, style = WaButtonStyle.Ghost)
        }
    }
}

@OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)
@Composable
private fun ExplainSheet(onClose: () -> Unit) {
    LocaleAwareBottomSheet(onDismissRequest = onClose, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(start = 20.dp, end = 20.dp, bottom = 26.dp)) {
            Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
                WaIconWell(Icons.Rounded.AccountBalanceWallet, Tone.Red, size = 60.dp, iconSize = 28.dp)
                Spacer(Modifier.height(10.dp))
                Text(stringResource(R.string.wa_explain_title), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp, color = Wa.Ink)
            }
            ExplainRow(Icons.Rounded.AccountBalance, Tone.Green, stringResource(R.string.wa_withdrawable), stringResource(R.string.wa_explain_withdrawable))
            ExplainRow(Icons.Rounded.CardGiftcard, Tone.Pink, stringResource(R.string.wa_spend_only), stringResource(R.string.wa_explain_spend_only))
            ExplainRow(Icons.Rounded.PauseCircle, Tone.Amber, stringResource(R.string.wa_held), stringResource(R.string.wa_explain_held))
            Spacer(Modifier.height(8.dp))
            WaButton(stringResource(R.string.wa_understood), onClick = onClose)
        }
    }
}

@Composable
private fun ExplainRow(icon: ImageVector, tone: Tone, title: String, text: String) {
    Row(Modifier.fillMaxWidth().padding(vertical = 12.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        WaIconWell(icon, tone)
        Column {
            Text(title, fontWeight = FontWeight.ExtraBold, fontSize = 14.sp, color = Wa.Ink)
            Text(text, color = Wa.Mut, fontSize = 12.5.sp, lineHeight = 22.sp)
        }
    }
}

// ---------------------------------------------------------------- the statement

@Composable
private fun WalletHistory(currency: String, onBack: () -> Unit, onOpen: (WalletTransactionDto) -> Unit) {
    val reconnect = collectReconnectTick()
    var filter by rememberSaveable { mutableStateOf("all") }
    var rows by remember { mutableStateOf<List<WalletTransactionDto>>(emptyList()) }
    var page by remember { mutableIntStateOf(1) }
    var hasMore by remember { mutableStateOf(false) }
    var loading by remember { mutableStateOf(true) }

    LaunchedEffect(reconnect) { page = 1 }
    LaunchedEffect(filter, page, reconnect) {
        loading = true
        val result = apiCall { ApiClient.wallet.transactions(page = page, perPage = 15, direction = filter.takeIf { it == "credit" || it == "debit" }, bucket = filter.takeIf { it == "spend_only" }) }
        if (result is ApiResult.Ok) {
            rows = if (page == 1) result.data else rows + result.data
            hasMore = result.data.size >= 15
            loading = false
        }
    }

    WaPage(title = stringResource(R.string.wa_history_title), onBack = onBack) {
        Row(Modifier.horizontalScroll(rememberScrollState()).padding(bottom = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf(
                Triple("all", R.string.wa_filter_all, Icons.AutoMirrored.Rounded.List),
                Triple("credit", R.string.wa_filter_in, Icons.Rounded.SouthWest),
                Triple("debit", R.string.wa_filter_out, Icons.Rounded.NorthEast),
                Triple("spend_only", R.string.wa_spend_only, Icons.Rounded.CardGiftcard),
            ).forEach { (value, label, icon) ->
                WaChip(stringResource(label), icon, selected = filter == value) {
                    if (filter != value) {
                        filter = value
                        page = 1
                        rows = emptyList()
                    }
                }
            }
        }
        when {
            loading && rows.isEmpty() -> WaCard(Modifier.fillMaxWidth()) { Column(Modifier.padding(horizontal = 14.dp)) { repeat(5) { TxSkeleton() } } }
            rows.isEmpty() -> WaCard(Modifier.fillMaxWidth()) {
                WaEmpty(Icons.Rounded.History, Tone.Gray, stringResource(R.string.wa_history_empty_title), stringResource(R.string.wa_history_empty_text))
            }
            else -> {
                WaCard(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(horizontal = 14.dp, vertical = 4.dp)) {
                        rows.forEachIndexed { index, tx ->
                            TxRow(tx, currency) { onOpen(tx) }
                            if (index < rows.lastIndex) WaDivider()
                        }
                    }
                }
                if (hasMore) {
                    Spacer(Modifier.height(12.dp))
                    WaButton(stringResource(R.string.wa_show_more), { page += 1 }, style = WaButtonStyle.Ghost, loading = loading)
                }
            }
        }
    }
}

// ---------------------------------------------------------------- topping up

private val QUICK_AMOUNTS = listOf(50, 100, 200, 500)

/**
 * Top up, in the DORR app's look: the amount on the gradient card (with quick amounts and the expected balance), the payment
 * methods, the live quote in the bottom dock, the PIN on the pad, then the hand-off — the test bank in an in-app layer, a real
 * gateway in the browser — and a waiting / OTP / paid / failed page. The payment is checked when the page comes back to the
 * front, when the test bank layer closes, and on a live notice; never on a timer.
 */
@Composable
private fun WalletTopup(balance: WalletBalanceDto?, hidden: Boolean, onBack: () -> Unit, onStatement: () -> Unit, onPaid: () -> Unit) {
    val context = LocalContext.current
    val host = LocalWalletOrNull.current
    val scope = rememberCoroutineScope()
    val currency = balance?.currencyCode.orEmpty()
    val offlineText = stringResource(R.string.wa_error_network)
    val genericError = stringResource(R.string.wa_error_generic)
    val comingSoonText = stringResource(R.string.wa_method_coming_soon_toast)

    var methods by remember { mutableStateOf<List<PaymentMethodDto>?>(null) }
    var methodId by rememberSaveable { mutableStateOf<Int?>(null) }
    var amountText by rememberSaveable { mutableStateOf("") }
    var quote by remember { mutableStateOf<TopupQuoteDto?>(null) }
    // Same key on a retry of the *same* request, so a dropped connection can't double-charge.
    var key by rememberSaveable { mutableStateOf(CoreHttp.newIdempotencyKey()) }
    var askPin by remember { mutableStateOf(false) }
    // Kept in memory only for this attempt (the OTP step needs it again).
    var attemptPin by remember { mutableStateOf<String?>(null) }
    var payment by remember { mutableStateOf<TopupPaymentDto?>(null) }
    var gateway by remember { mutableStateOf("") }
    var browserUrl by remember { mutableStateOf<String?>(null) }
    var otp by remember { mutableStateOf("") }
    var otpBusy by remember { mutableStateOf(false) }
    var currentBalance by remember { mutableStateOf(balance) }

    val amountMinor = parseAmountToMinor(amountText)
    val reconnect = collectReconnectTick()
    LaunchedEffect(reconnect) {
        val result = apiCall { ApiClient.wallet.paymentMethods() }
        if (result is ApiResult.Ok) {
            methods = result.data
            if (methodId == null) methodId = result.data.firstOrNull { !it.comingSoon }?.id
        }
    }
    // The quote follows the amount and the method (after a short pause, so typing "100" is one request, not three).
    LaunchedEffect(amountMinor, methodId) {
        quote = null
        val id = methodId
        if (amountMinor == null || id == null) return@LaunchedEffect
        kotlinx.coroutines.delay(400)
        when (val result = apiCall { ApiClient.wallet.quote(TopupRequest(id, amountMinor)) }) {
            is ApiResult.Ok -> quote = result.data
            else -> host?.showError(result.errorText(offlineText) ?: genericError)
        }
        key = CoreHttp.newIdempotencyKey()
    }

    suspend fun check() {
        val uuid = payment?.uuid ?: return
        val result = apiCall { ApiClient.wallet.topup(uuid) }
        if (result is ApiResult.Ok) {
            if (result.data.status == "paid" && payment?.status != "paid") {
                browserUrl = null
                (apiCall { ApiClient.wallet.balance() } as? ApiResult.Ok)?.data?.let { currentBalance = it }
                onPaid()
            }
            payment = result.data
        }
    }
    val lifecycle = LocalLifecycleOwner.current.lifecycle
    DisposableEffect(lifecycle, payment?.uuid) {
        val observer = LifecycleEventObserver { _, event -> if (event == Lifecycle.Event.ON_RESUME && payment?.status == "pending") scope.launch { check() } }
        lifecycle.addObserver(observer)
        onDispose { lifecycle.removeObserver(observer) }
    }
    LaunchedEffect(payment?.uuid) { Realtime.changed.collect { check() } }

    val started = payment
    if (started != null) {
        Box(Modifier.fillMaxSize()) {
            TopupStage(
                payment = started, gateway = gateway, currency = currency, balanceNow = currentBalance,
                otp = otp, onOtp = { otp = it.filter(Char::isDigit).take(8) }, otpBusy = otpBusy,
                onConfirmOtp = {
                    val pin = attemptPin ?: return@TopupStage
                    otpBusy = true
                    scope.launch {
                        when (val result = apiCall { ApiClient.wallet.confirmTopup(pin, started.uuid, OtpBody(otp)) }) {
                            is ApiResult.Ok -> {
                                payment = result.data
                                if (result.data.status == "paid") {
                                    (apiCall { ApiClient.wallet.balance() } as? ApiResult.Ok)?.data?.let { currentBalance = it }
                                    onPaid()
                                }
                            }
                            else -> host?.showError(result.errorText(offlineText) ?: genericError)
                        }
                        otpBusy = false
                    }
                },
                onReopen = { started.redirectUrl?.let { url -> if (gateway == "sandbox") browserUrl = url else openUrl(context, url) } },
                onCheck = { scope.launch { check() } },
                onDone = { attemptPin = null; onBack() },
                onStatement = { attemptPin = null; onStatement() },
                onRetry = { attemptPin = null; payment = null; otp = ""; browserUrl = null; key = CoreHttp.newIdempotencyKey() },
                onCancel = { attemptPin = null; payment = null; otp = ""; browserUrl = null },
                onBack = onBack,
            )
            GatewayLayer(url = browserUrl, onClose = { browserUrl = null; scope.launch { check() } })
        }
        return
    }

    if (askPin) {
        BackHandler { askPin = false }
        WaPage(title = stringResource(R.string.wa_topup_title), onBack = { askPin = false }, scroll = false) {
            WaPinPad(title = stringResource(R.string.wa_pin_enter_title), sub = stringResource(R.string.wa_pin_topup_sub), onComplete = { pin ->
                val id = methodId
                val minor = amountMinor
                if (id == null || minor == null) return@WaPinPad PadResult.Error(genericError)
                when (val result = apiCall { ApiClient.wallet.startTopup(pin, key, TopupRequest(id, minor)) }) {
                    is ApiResult.Ok -> {
                        key = CoreHttp.newIdempotencyKey()
                        attemptPin = pin
                        gateway = methods?.find { it.id == id }?.gateway.orEmpty()
                        payment = result.data
                        askPin = false
                        result.data.redirectUrl?.takeIf { result.data.status == "pending" }?.let { url ->
                            if (gateway == "sandbox") browserUrl = url else openUrl(context, url)
                        }
                        PadResult.Ok
                    }
                    // No answer from the server: keep the key, so a retry is safe.
                    is ApiResult.Offline -> PadResult.Error(offlineText)
                    is ApiResult.Failed -> {
                        if (result.error.errorCode?.startsWith("wallet_pin") != true) key = CoreHttp.newIdempotencyKey()
                        padFailure(result, offlineText, genericError)
                    }
                }
            })
        }
        return
    }

    val paying = methods?.find { it.id == methodId }
    WaPage(
        title = stringResource(R.string.wa_topup_title),
        onBack = onBack,
        ctaDock = true,
        ctaReserve = 220.dp,
        cta = {
            QuoteCard(
                amountMinor = quote?.paidAmountMinor ?: amountMinor ?: 0L,
                feeMinor = quote?.feeMinor ?: 0L,
                bonusMinor = quote?.bonusMinor ?: 0L,
                currency = currency,
                methodName = paying?.name ?: paying?.code,
            )
            WaButton(
                text = stringResource(R.string.wa_continue_pay),
                onClick = { askPin = true },
                enabled = quote != null,
                icon = Icons.Rounded.ChevronLeft,
                iconAfter = true,
                spread = true,
            )
            WaCtaHint(stringResource(R.string.wa_secure_payments), Icons.Rounded.Shield)
        },
    ) {
        Box(Modifier.waRise(0)) {
            val added = quote?.totalCreditedMinor ?: amountMinor ?: 0L
            val expected = (currentBalance?.totalMinor ?: 0L) + added
            WaAmountEntry(
                label = stringResource(R.string.wa_topup_how_much),
                amountText = amountText,
                onAmountChange = { amountText = it },
                currency = currency,
                quick = QUICK_AMOUNTS,
                invalid = amountText.isNotBlank() && amountMinor == null,
                hero = true,
                subtitle = stringResource(R.string.wa_topup_how_much_sub),
                footerLabel = stringResource(R.string.wa_topup_expected),
                footerValue = if (hidden) "****" else money(expected) + " " + currency,
            )
        }
        Row(Modifier.fillMaxWidth().padding(top = 18.dp, bottom = 8.dp).waRise(1), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.wa_pay_method), color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold)
                Text(stringResource(R.string.wa_pay_method_sub), color = Wa.Mut, fontSize = 11.sp, modifier = Modifier.padding(top = 1.dp))
            }
            methods?.size?.let { n -> Text(stringResource(R.string.wa_pay_methods_n, n), color = Wa.Mut, fontSize = 11.sp) }
        }
        Column(Modifier.waRise(2)) {
            when {
                methods == null -> repeat(2) { WaSkeleton(Modifier.fillMaxWidth().height(56.dp).padding(bottom = 8.dp), RoundedCornerShape(16.dp)) }
                methods.orEmpty().isEmpty() -> WaCard(Modifier.fillMaxWidth()) {
                    WaEmpty(Icons.Rounded.Payments, Tone.Gray, stringResource(R.string.wa_no_methods), stringResource(R.string.wa_no_methods_text))
                }
                else -> methods.orEmpty().forEach { method ->
                    MethodCard(method, selected = method.id == methodId) {
                        if (method.comingSoon) host?.showToast(comingSoonText) else methodId = method.id
                    }
                }
            }
        }
    }
}

@Composable
private fun TopupStage(
    payment: TopupPaymentDto, gateway: String, currency: String, balanceNow: WalletBalanceDto?,
    otp: String, onOtp: (String) -> Unit, otpBusy: Boolean,
    onConfirmOtp: () -> Unit, onReopen: () -> Unit, onCheck: () -> Unit, onDone: () -> Unit, onStatement: () -> Unit,
    onRetry: () -> Unit, onCancel: () -> Unit, onBack: () -> Unit,
) {
    WaPage(title = stringResource(R.string.wa_topup_title), onBack = onBack, scroll = false) {
        Box(Modifier.fillMaxSize()) {
            when {
                payment.status == "paid" -> {
                    WaConfetti()
                    val credited = payment.amountMinor + payment.bonusMinor - payment.feeMinor
                    WaStatusColumn {
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
                            WaKeyValue(stringResource(R.string.wa_recap_balance_now), balanceNow?.let { money(it.totalMinor) + " " + currency } ?: "…", ltr = true)
                        }
                        Spacer(Modifier.height(12.dp))
                        WaButton(stringResource(R.string.wa_done), onDone)
                        WaButton(stringResource(R.string.wa_view_statement), onStatement, style = WaButtonStyle.Quiet)
                    }
                }
                payment.status == "pending" && payment.requiresOtp -> WaStatusColumn {
                    WaPulse(Icons.Rounded.Phone)
                    WaStatusTitle(stringResource(R.string.wa_otp_title))
                    WaStatusText(stringResource(R.string.wa_otp_text))
                    Spacer(Modifier.height(16.dp))
                    OtpField(otp, onOtp)
                    Spacer(Modifier.height(12.dp))
                    WaButton(stringResource(R.string.wa_otp_confirm), onConfirmOtp, enabled = otp.length >= 4, loading = otpBusy)
                    WaButton(stringResource(R.string.wa_cancel), onCancel, style = WaButtonStyle.Quiet)
                }
                payment.status == "pending" -> WaStatusColumn {
                    WaPulse(Icons.Rounded.Payments)
                    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(top = 18.dp, bottom = 6.dp)) {
                        Text(stringResource(R.string.wa_waiting_title), fontSize = 22.sp, fontWeight = FontWeight.ExtraBold, color = Wa.Ink)
                        WaLoadingDots()
                    }
                    WaStatusText(stringResource(R.string.wa_waiting_text))
                    Spacer(Modifier.height(12.dp))
                    if (payment.redirectUrl != null) WaButton(stringResource(R.string.wa_open_payment_page), onReopen, style = WaButtonStyle.Ghost, icon = Icons.Rounded.OpenInNew)
                    WaButton(stringResource(R.string.wl_check), onCheck, style = WaButtonStyle.Ghost, icon = Icons.Rounded.Refresh)
                    WaButton(stringResource(R.string.wa_cancel), onCancel, style = WaButtonStyle.Quiet)
                }
                else -> WaStatusColumn {
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

// ---------------------------------------------------------------- withdrawing

/**
 * Withdraw, in the wallet look: where to (a bank account or a mobile wallet — added with the PIN), the amount on the gradient
 * card (up to what can be withdrawn), the PIN on the pad, then the request — the money is held until an admin pays it out.
 * Under it, my requests with their status and the transfer receipt once paid.
 */
@Composable
private fun WalletWithdraw(balance: WalletBalanceDto?, onBack: () -> Unit) {
    val context = LocalContext.current
    val host = LocalWalletOrNull.current
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    val offlineText = stringResource(R.string.wa_error_network)
    val genericError = stringResource(R.string.wa_error_generic)
    val sentText = stringResource(R.string.wl_wd_sent)
    val tooMuchText = stringResource(R.string.wl_wd_too_much)
    // Its own copy of the balance: a request holds money, so what can be withdrawn changes on this page.
    var current by remember { mutableStateOf(balance) }
    val currency = current?.currencyCode.orEmpty()
    val withdrawable = current?.withdrawableMinor ?: 0L

    var methods by remember { mutableStateOf<List<WithdrawalMethodDto>?>(null) }
    val requests = remember { mutableStateListOf<WithdrawalDto>() }
    var methodId by rememberSaveable { mutableStateOf<Int?>(null) }
    var adding by rememberSaveable { mutableStateOf(false) }
    var type by rememberSaveable { mutableStateOf("bank") }
    var label by rememberSaveable { mutableStateOf("") }
    var bankName by rememberSaveable { mutableStateOf("") }
    var holder by rememberSaveable { mutableStateOf("") }
    var iban by rememberSaveable { mutableStateOf("") }
    var provider by rememberSaveable { mutableStateOf("") }
    var number by rememberSaveable { mutableStateOf("") }
    var amountText by rememberSaveable { mutableStateOf("") }
    var key by rememberSaveable { mutableStateOf(CoreHttp.newIdempotencyKey()) }
    // Which action the pad is open for: "method" (save the destination) or "withdraw" (send the request).
    var pinFor by remember { mutableStateOf<String?>(null) }

    suspend fun load() {
        // A failed read keeps what is shown (it must not look like "no destinations yet" and open the add form).
        (apiCall { ApiClient.wallet.withdrawalMethods() } as? ApiResult.Ok)?.data?.let { list ->
            methods = list
            if (methodId == null || list.none { it.id == methodId }) methodId = list.firstOrNull { it.isFavorite }?.id ?: list.firstOrNull()?.id
            if (list.isEmpty()) adding = true
        }
        (apiCall { ApiClient.wallet.withdrawals(1) } as? ApiResult.Ok)?.data?.let { requests.clear(); requests.addAll(it) }
        (apiCall { ApiClient.wallet.balance() } as? ApiResult.Ok)?.data?.let { current = it }
    }
    LaunchedEffect(reconnect) { load() }
    LaunchedEffect(Unit) { Realtime.changed.collect { load() } }

    val minor = parseAmountToMinor(amountText)
    val tooMuch = minor != null && minor > withdrawable
    // A different request is a different key (the same key with another amount would be refused as a conflict).
    LaunchedEffect(amountText, methodId) { key = CoreHttp.newIdempotencyKey() }

    val action = pinFor
    if (action != null) {
        BackHandler { pinFor = null }
        WaPage(title = stringResource(R.string.wl_wd_title), onBack = { pinFor = null }, scroll = false) {
            WaPinPad(title = stringResource(R.string.wa_pin_enter_title), sub = stringResource(if (action == "method") R.string.wl_wd_save else R.string.wl_wd_send), onComplete = { pin ->
                if (action == "method") {
                    val body = JsonObject().apply {
                        addProperty("type", type)
                        if (label.isNotBlank()) addProperty("label", label.trim())
                        add("data", JsonObject().apply {
                            if (type == "bank") {
                                addProperty("bank_name", bankName.trim()); addProperty("account_holder", holder.trim()); addProperty("iban", iban.trim())
                            } else {
                                addProperty("provider_name", provider.trim()); addProperty("number", number.trim())
                            }
                        })
                    }
                    when (val result = apiCall { ApiClient.wallet.addWithdrawalMethod(pin, body) }) {
                        is ApiResult.Ok -> {
                            methodId = result.data.id
                            adding = false
                            bankName = ""; holder = ""; iban = ""; provider = ""; number = ""; label = ""
                            pinFor = null
                            // The pad leaves now (and its coroutine with it): reload in the page's own scope.
                            scope.launch { load() }
                            PadResult.Ok
                        }
                        else -> padFailure(result, offlineText, genericError)
                    }
                } else {
                    val body = JsonObject().apply { addProperty("withdrawal_method_id", methodId); addProperty("amount_minor", minor) }
                    when (val result = apiCall { ApiClient.wallet.withdraw(pin, key, body) }) {
                        is ApiResult.Ok -> {
                            amountText = ""
                            key = CoreHttp.newIdempotencyKey()
                            pinFor = null
                            host?.showToast(sentText)
                            scope.launch { load() }
                            PadResult.Ok
                        }
                        else -> padFailure(result, offlineText, genericError)
                    }
                }
            })
        }
        return
    }

    val canSend = methodId != null && minor != null && !tooMuch
    val canSave = if (type == "bank") bankName.isNotBlank() && holder.isNotBlank() && iban.length >= 15 else provider.isNotBlank() && number.length >= 6
    WaPage(
        title = stringResource(R.string.wl_wd_title),
        onBack = onBack,
        cta = {
            if (adding) WaButton(stringResource(R.string.wl_wd_save), { pinFor = "method" }, enabled = canSave, icon = Icons.Rounded.Check)
            else WaButton(stringResource(R.string.wl_wd_send), { pinFor = "withdraw" }, enabled = canSend, icon = Icons.Rounded.AccountBalance)
            WaCtaHint(stringResource(R.string.wa_secure_payments), Icons.Rounded.Lock)
        },
    ) {
        if (!adding) {
            Box(Modifier.waRise(0)) {
                WaAmountEntry(
                    label = stringResource(R.string.wl_wd_title),
                    amountText = amountText,
                    onAmountChange = { amountText = it },
                    currency = currency,
                    invalid = amountText.isNotBlank() && minor == null,
                    hero = true,
                    subtitle = stringResource(R.string.wl_wd_can, money(withdrawable) + " " + currency),
                    footerLabel = stringResource(R.string.wa_withdrawable),
                    footerValue = money(withdrawable) + " " + currency,
                )
            }
            if (tooMuch) Text(tooMuchText, color = Wa.Danger, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp))
            Row(Modifier.fillMaxWidth().padding(top = 8.dp), horizontalArrangement = Arrangement.End) {
                WaChip(stringResource(R.string.wl_all), null, selected = minor == withdrawable && withdrawable > 0) {
                    amountText = String.format(java.util.Locale.US, "%d.%02d", withdrawable / 100, withdrawable % 100)
                }
            }
        }

        WaSectionTitle(stringResource(if (adding) R.string.wl_wd_add else R.string.wl_wd_to), topPadding = 14.dp)
        if (methods == null) repeat(2) { WaSkeleton(Modifier.fillMaxWidth().height(56.dp).padding(bottom = 8.dp), RoundedCornerShape(16.dp)) }
        if (adding) {
            Row(Modifier.padding(bottom = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                WaChip(stringResource(R.string.wl_bank), Icons.Rounded.AccountBalance, selected = type == "bank") { type = "bank" }
                WaChip(stringResource(R.string.wl_mobile), Icons.Rounded.Phone, selected = type == "mobile_wallet") { type = "mobile_wallet" }
            }
            WaCard(Modifier.fillMaxWidth(), padding = 16.dp) {
                Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    DorrTextField(label, { label = it.take(60) }, label = stringResource(R.string.wl_label), modifier = Modifier.fillMaxWidth())
                    if (type == "bank") {
                        DorrTextField(bankName, { bankName = it.take(100) }, label = stringResource(R.string.wl_bank_name), modifier = Modifier.fillMaxWidth())
                        DorrTextField(holder, { holder = it.take(100) }, label = stringResource(R.string.wl_holder), modifier = Modifier.fillMaxWidth())
                        DorrTextField(
                            iban, { iban = it.filter(Char::isLetterOrDigit).uppercase().take(34) }, label = "IBAN", placeholder = "SA00 0000 0000 0000 0000 0000",
                            error = iban.isNotEmpty() && iban.length < 15, modifier = Modifier.fillMaxWidth(),
                        )
                    } else {
                        DorrTextField(provider, { provider = it.take(100) }, label = stringResource(R.string.wl_provider), modifier = Modifier.fillMaxWidth())
                        DorrTextField(
                            number, { number = it.filter(Char::isDigit).take(20) }, label = stringResource(R.string.wl_number),
                            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), error = number.isNotEmpty() && number.length < 6, modifier = Modifier.fillMaxWidth(),
                        )
                    }
                }
            }
            if (methods?.isNotEmpty() == true) WaButton(stringResource(R.string.wl_cancel_add), { adding = false }, style = WaButtonStyle.Quiet)
        } else {
            methods?.forEach { m -> DestinationCard(m, selected = methodId == m.id, onClick = { methodId = m.id }, onDelete = {
                scope.launch {
                    apiDone(offlineText, genericError) { ApiClient.wallet.deleteWithdrawalMethod(m.id) }?.let { host?.showError(it) }
                    load()
                }
            }) }
            Row(
                Modifier.clip(RoundedCornerShape(12.dp)).clickable(interactionSource = remember { MutableInteractionSource() }, indication = null) { adding = true }.padding(vertical = 6.dp, horizontal = 4.dp),
                verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp),
            ) {
                Icon(Icons.Rounded.Add, null, tint = Wa.Red, modifier = Modifier.size(18.dp))
                Text(stringResource(R.string.wl_wd_add), color = Wa.Red, fontWeight = FontWeight.Bold, fontSize = 13.sp)
            }
        }

        WaSectionTitle(stringResource(R.string.wl_wd_requests), topPadding = 16.dp)
        WaCard(Modifier.fillMaxWidth()) {
            if (requests.isEmpty()) {
                WaEmpty(Icons.Rounded.Payments, Tone.Gray, stringResource(R.string.wl_wd_none), stringResource(R.string.wl_wd_can, money(withdrawable) + " " + currency))
            } else {
                Column(Modifier.padding(horizontal = 14.dp, vertical = 4.dp)) {
                    requests.forEachIndexed { index, r ->
                        WithdrawalRow(r, currency) {
                            scope.launch {
                                val file = withContext(Dispatchers.IO) { downloadReceipt(context, r.id) }
                                if (file == null || !openCachedFile(context, file)) host?.showError(genericError)
                            }
                        }
                        if (index < requests.lastIndex) WaDivider()
                    }
                }
            }
        }
    }
}

@Composable
private fun DestinationCard(m: WithdrawalMethodDto, selected: Boolean, onClick: () -> Unit, onDelete: () -> Unit) {
    val shape = RoundedCornerShape(16.dp)
    Row(
        Modifier
            .fillMaxWidth()
            .padding(bottom = 8.dp)
            .clip(shape)
            .background(if (selected) (if (walletNight()) com.dorr.freelance.ui.kit.AccountDark.well else Wa.Red.copy(alpha = 0.06f)) else Wa.Surface)
            .border(1.dp, if (selected) Wa.Red else Wa.Line, shape)
            .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null, onClick = onClick)
            .padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        WaIconWell(if (m.type == "bank") Icons.Rounded.AccountBalance else Icons.Rounded.Phone, Tone.Red, size = 36.dp, iconSize = 16.dp)
        Column(Modifier.weight(1f)) {
            Text(m.label?.takeIf { it.isNotBlank() } ?: m.display.orEmpty(), fontSize = 13.sp, fontWeight = FontWeight.Bold, color = Wa.Ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
            if (!m.label.isNullOrBlank()) {
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    Text(m.display.orEmpty(), fontSize = 11.sp, color = Wa.Mut, maxLines = 1, modifier = Modifier.padding(top = 2.dp))
                }
            }
        }
        Box(Modifier.size(30.dp).clip(CircleShape).clickable(onClick = onDelete), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.DeleteOutline, stringResource(R.string.wl_delete_method), tint = Wa.Soft, modifier = Modifier.size(18.dp))
        }
        Box(
            Modifier.size(20.dp).clip(CircleShape).background(if (selected) Wa.Red else Color.Transparent)
                .border(1.5.dp, if (selected) Wa.Red else if (walletNight()) Wa.Line else Color(0xFFD1D5DB), CircleShape),
            contentAlignment = Alignment.Center,
        ) { if (selected) Icon(Icons.Rounded.Check, null, tint = Color.White, modifier = Modifier.size(12.dp)) }
    }
}

@Composable
private fun WithdrawalRow(r: WithdrawalDto, currency: String, onReceipt: () -> Unit) {
    val (label, tint) = when (r.status) {
        "approved" -> stringResource(R.string.wl_wd_approved) to Wa.Green
        "rejected" -> stringResource(R.string.wl_wd_rejected) to Wa.Danger
        else -> stringResource(R.string.wl_wd_pending) to Color(0xFFD97706)
    }
    Row(Modifier.fillMaxWidth().padding(vertical = 12.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        WaIconWell(Icons.Rounded.AccountBalance, Tone.Red, size = 36.dp, iconSize = 16.dp)
        Column(Modifier.weight(1f)) {
            Text(r.method?.label?.takeIf { it.isNotBlank() } ?: r.method?.display.orEmpty(), fontSize = 13.sp, fontWeight = FontWeight.Bold, color = Wa.Ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(dayText(r.createdAt) + "، " + timeOf(r.createdAt), color = Wa.Soft, fontSize = 11.sp, modifier = Modifier.padding(top = 2.dp))
            r.rejectionReason?.takeIf { it.isNotBlank() }?.let { Text(it, color = Wa.Mut, fontSize = 11.sp, modifier = Modifier.padding(top = 2.dp)) }
            if (r.hasReceipt) {
                Text(
                    stringResource(R.string.wl_wd_receipt), color = Wa.Red, fontSize = 12.sp, fontWeight = FontWeight.Bold,
                    modifier = Modifier.padding(top = 4.dp).clickable(interactionSource = remember { MutableInteractionSource() }, indication = null, onClick = onReceipt),
                )
            }
        }
        Column(horizontalAlignment = Alignment.End) {
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                Text("- " + money(r.amountMinor) + " " + (r.currencyCode ?: currency), fontSize = 13.sp, fontWeight = FontWeight.Bold, color = Wa.Ink)
            }
            Text(
                label, color = tint, fontSize = 10.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.padding(top = 4.dp).clip(RoundedCornerShape(999.dp)).background(tint.copy(alpha = 0.12f)).padding(horizontal = 8.dp, vertical = 2.dp),
            )
        }
    }
}

/** Downloads a withdrawal's receipt (signed in, private) into the cache, to open it in a viewer. */
private suspend fun downloadReceipt(context: Context, id: Int): File? = runCatching {
    val response = ApiClient.wallet.receipt(id)
    if (!response.isSuccessful) return@runCatching null
    val type = response.body()?.contentType()?.toString().orEmpty().substringBefore(';')
    val ext = android.webkit.MimeTypeMap.getSingleton().getExtensionFromMimeType(type) ?: "pdf"
    val file = File(context.cacheDir, "proofs").apply { mkdirs() }.let { File(it, "receipt_$id.$ext") }
    response.body()?.byteStream()?.use { input -> file.outputStream().use { input.copyTo(it) } } ?: return@runCatching null
    file
}.getOrNull()
