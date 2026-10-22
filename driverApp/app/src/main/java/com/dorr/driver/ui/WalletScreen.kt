package com.dorr.driver.ui

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AccountBalance
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.CardGiftcard
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.ContentCopy
import androidx.compose.material.icons.rounded.CreditCard
import androidx.compose.material.icons.rounded.DeleteOutline
import androidx.compose.material.icons.rounded.DirectionsCar
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.LocalOffer
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.NorthEast
import androidx.compose.material.icons.rounded.Numbers
import androidx.compose.material.icons.rounded.Paid
import androidx.compose.material.icons.rounded.PauseCircle
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.Percent
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.PhoneAndroid
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.Receipt
import androidx.compose.material.icons.rounded.Replay
import androidx.compose.material.icons.rounded.RequestQuote
import androidx.compose.material.icons.rounded.Share
import androidx.compose.material.icons.rounded.ShoppingBag
import androidx.compose.material.icons.rounded.SouthWest
import androidx.compose.material.icons.rounded.Tune
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material.icons.rounded.VisibilityOff
import androidx.compose.material.icons.rounded.VolunteerActivism
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.CoreHttp
import com.dorr.core.network.apiCall
import com.dorr.driver.R
import com.dorr.driver.live.DriverLive
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.WalletDto
import com.dorr.driver.net.WalletPinDto
import com.dorr.driver.net.WalletRowDto
import com.dorr.driver.net.WithdrawalDto
import com.dorr.driver.net.WithdrawalMethodDto
import com.dorr.driver.ui.kit.Banner
import com.dorr.driver.ui.kit.BannerKind
import com.dorr.driver.ui.kit.Chip
import com.dorr.driver.ui.kit.DorrTextField
import com.dorr.driver.ui.kit.HeroBadge
import com.dorr.driver.ui.kit.PrimaryButton
import com.dorr.driver.ui.kit.RoundIconButton
import com.dorr.driver.ui.kit.ScreenScaffold
import com.dorr.driver.ui.kit.Segmented
import com.dorr.driver.ui.kit.TileIcon
import com.dorr.driver.ui.kit.bounceClick
import com.dorr.driver.ui.kit.staggerIn
import com.dorr.driver.ui.theme.Pal
import com.google.gson.JsonObject
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.time.LocalDate
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import java.util.Locale

/**
 * The captain's wallet, in the DORR app's wallet design — the same wallet as the rider's, with their earnings in it:
 * the balance card (the wallet number to copy or share, the total, what can be withdrawn and what is for services
 * only, what is held), the four actions, then the statement or the withdrawals. A negative balance is what they owe
 * the app from cash rides. It follows the server through real-time: a settlement, a payout decision or any movement
 * shows up by itself, no refreshing.
 */
@Composable
fun WalletScreen(onBack: (() -> Unit)?) {
    val scope = rememberCoroutineScope()
    var wallet by remember { mutableStateOf<WalletDto?>(null) }
    var rows by remember { mutableStateOf<List<WalletRowDto>?>(null) }
    var withdrawals by remember { mutableStateOf<List<WithdrawalDto>?>(null) }
    var tab by rememberSaveable { mutableIntStateOf(0) }
    var hidden by rememberSaveable { mutableStateOf(false) }
    var page by remember { mutableIntStateOf(1) }
    var more by remember { mutableStateOf(true) }
    var loadingMore by remember { mutableStateOf(false) }
    var withdrawOpen by remember { mutableStateOf(false) }
    var topupOpen by remember { mutableStateOf(false) }
    var filter by remember { mutableStateOf(StatementFilter()) }
    var detailId by remember { mutableStateOf<String?>(null) }
    // D-210, D-216..D-228: the last confirmed balance when offline, the payout safeguards, the statements.
    val context = LocalContext.current
    var cachedAt by remember { mutableStateOf<Long?>(null) }
    var payout by remember { mutableStateOf<com.dorr.driver.net.PayoutStatusDto?>(null) }
    var intlOpen by remember { mutableStateOf(false) }
    var invoicesOpen by remember { mutableStateOf(false) }
    suspend fun loadWallet() {
        when (val r = apiCall { DriverBackend.api.wallet() }) {
            is ApiResult.Ok -> { wallet = r.data; cachedAt = null; WalletCache.save(context, r.data) }
            else -> if (wallet == null) WalletCache.load(context)?.let { (w, at) -> wallet = w; cachedAt = at }
        }
        payout = (apiCall { DriverBackend.api.payoutStatus() } as? ApiResult.Ok)?.data ?: payout
    }
    val list = rememberLazyListState()
    if (onBack != null) BackHandler(onBack = onBack)

    // The statement, narrowed by the filters (D-213); the same filters apply to "show more".
    suspend fun loadRows() {
        (apiCall { DriverBackend.api.walletTransactions(1, 25, filter.type, filter.direction, filter.from, filter.to) } as? ApiResult.Ok)
            ?.let { rows = it.data; page = 1; more = it.data.size >= 25 }
        if (rows == null) rows = emptyList()
    }
    suspend fun load() {
        loadWallet()
        loadRows()
        (apiCall { DriverBackend.api.withdrawals(1) } as? ApiResult.Ok)?.let { withdrawals = it.data }
    }
    LaunchedEffect(Unit) {
        loadWallet()
        withdrawals = (apiCall { DriverBackend.api.withdrawals(1) } as? ApiResult.Ok)?.data.orEmpty()
        // A settlement, a payout decision, a transfer: the wallet reloads by itself.
        DriverLive.events.collect { e -> if (e.name.startsWith("wallet") || e.name.contains("withdrawal") || e.name.contains("earnings") || e.name.startsWith("driver.payout")) load() }
    }
    LaunchedEffect(filter) { loadRows() }

    val w = wallet
    val owing = (w?.totalMinor ?: 0) < 0
    // Where the tabs sit in the list, for the actions that jump to them.
    val tabsIndex = if (owing) 3 else 2
    fun showTab(which: Int) {
        tab = which
        scope.launch { list.animateScrollToItem(tabsIndex) }
    }

    ScreenScaffold(
        stringResource(R.string.wl_title), onBack,
        actions = {
            RoundIconButton(if (hidden) Icons.Rounded.VisibilityOff else Icons.Rounded.Visibility, stringResource(R.string.wl_hide), onClick = { hidden = !hidden })
        },
    ) {
        LazyColumn(Modifier.fillMaxSize(), state = list, contentPadding = PaddingValues(bottom = 28.dp)) {
            item(key = "hero") {
                Box(Modifier.padding(horizontal = 16.dp).padding(top = 4.dp).staggerIn(0)) {
                    if (w != null) WalletHero(w, hidden) else HeroSkeleton()
                }
            }
            item(key = "payout") { PayoutBanners(payout, cachedAt, onInternational = { intlOpen = true }, onInvoices = { invoicesOpen = true }) }
            if (owing) {
                item(key = "owing") { Banner(stringResource(R.string.wl_owing), Modifier.padding(horizontal = 16.dp).padding(top = 10.dp), kind = BannerKind.Warning) }
            }
            item(key = "actions") {
                Row(Modifier.fillMaxWidth().padding(horizontal = 16.dp).padding(top = 12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    WalletAction(Icons.Rounded.Add, stringResource(R.string.wl_topup), 1, Modifier.weight(1f), enabled = w != null) { topupOpen = true }
                    WalletAction(Icons.Rounded.AccountBalance, stringResource(R.string.wl_withdraw), 2, Modifier.weight(1f), enabled = (w?.withdrawableMinor ?: 0) > 0) { withdrawOpen = true }
                    WalletAction(Icons.Rounded.History, stringResource(R.string.wl_tab_statement), 3, Modifier.weight(1f)) { showTab(0) }
                    WalletAction(Icons.Rounded.Payments, stringResource(R.string.wl_tab_withdrawals), 4, Modifier.weight(1f)) { showTab(1) }
                }
            }
            item(key = "tabs") {
                Segmented(
                    listOf("0" to stringResource(R.string.wl_tab_statement), "1" to stringResource(R.string.wl_tab_withdrawals)),
                    selected = tab.toString(), onSelect = { tab = it.toInt() },
                    modifier = Modifier.padding(horizontal = 16.dp).padding(top = 18.dp, bottom = 12.dp).staggerIn(5),
                )
            }

            if (tab == 0) {
                item(key = "filters") { StatementFilters(filter) { filter = it } }
                val shown = rows
                when {
                    shown == null -> item(key = "loading") { Loading() }
                    shown.isEmpty() -> item(key = "empty") {
                        WalletEmpty(
                            Icons.Rounded.Receipt, stringResource(if (filter.active) R.string.wl_empty_filtered else R.string.wl_empty),
                            action = if (filter.active || w == null) null else ({ topupOpen = true }),
                        )
                    }
                    else -> {
                        itemsIndexed(shown, key = { _, r -> r.uuid }) { i, r ->
                            GroupItem(i, shown.lastIndex) { TxRow(r, w) { detailId = r.uuid } }
                        }
                        if (more) item(key = "more") {
                            Box(Modifier.fillMaxWidth().padding(top = 12.dp), contentAlignment = Alignment.Center) {
                                if (loadingMore) {
                                    CircularProgressIndicator(Modifier.size(24.dp), strokeWidth = 2.dp, color = Pal.accent)
                                } else {
                                    Text(
                                        stringResource(R.string.wl_more), color = Pal.accent, fontWeight = FontWeight.Bold, fontSize = 13.sp,
                                        modifier = Modifier.clip(RoundedCornerShape(12.dp)).bounceClick {
                                            loadingMore = true
                                            scope.launch {
                                                val next = (apiCall { DriverBackend.api.walletTransactions(page + 1, 25, filter.type, filter.direction, filter.from, filter.to) } as? ApiResult.Ok)?.data.orEmpty()
                                                if (next.isNotEmpty()) { rows = shown + next; page += 1 }
                                                more = next.size >= 25
                                                loadingMore = false
                                            }
                                        }.padding(horizontal = 16.dp, vertical = 10.dp),
                                    )
                                }
                            }
                        }
                    }
                }
            } else {
                val shown = withdrawals
                when {
                    shown == null -> item(key = "w-loading") { Loading() }
                    shown.isEmpty() -> item(key = "w-empty") { WalletEmpty(Icons.Rounded.Payments, stringResource(R.string.wl_no_withdrawals), action = null) }
                    else -> itemsIndexed(shown, key = { _, d -> "w${d.id}" }) { i, d -> GroupItem(i, shown.lastIndex) { WithdrawalRow(d, w) } }
                }
            }
        }
    }
    if (intlOpen) InternationalDialog(onClose = { intlOpen = false }, onSent = { intlOpen = false; scope.launch { loadWallet() } })
    if (invoicesOpen) InvoicesScreen(w, onClose = { invoicesOpen = false })
    if (topupOpen && w != null) TopupSheet(w, onClose = { topupOpen = false }, onDone = { topupOpen = false; scope.launch { load() } })
    detailId?.let { id -> TransactionDetailSheet(id, w, onClose = { detailId = null }) }
    if (withdrawOpen && w != null) {
        WithdrawSheet(w, onClose = { withdrawOpen = false }, onDone = {
            withdrawOpen = false
            tab = 1
            scope.launch { load() }
        })
    }
}

// ---------------------------------------------------------------- the balance card

/** The balance card of the DORR app's wallet: number + copy / share, the total chip, the amount, the two parts, what is held. */
@Composable
private fun WalletHero(w: WalletDto, hidden: Boolean) {
    val context = LocalContext.current
    val number = w.walletNumber.orEmpty()
    val currency = w.currencySymbol ?: w.currencyCode.orEmpty()
    val shareText = stringResource(R.string.wl_share_text, number)
    var copied by remember { mutableStateOf(false) }
    LaunchedEffect(copied) {
        if (copied) {
            delay(1600)
            copied = false
        }
    }
    // The total counts up to its new value.
    val shown = remember { Animatable(0f) }
    LaunchedEffect(w.totalMinor) { shown.animateTo(w.totalMinor.toFloat(), tween(900)) }

    val brand = Pal.accent
    val brush = Brush.linearGradient(listOf(brand, brand.darker(0.78f), brand.darker(0.45f)))
    val shape = RoundedCornerShape(24.dp)
    Box(
        Modifier.fillMaxWidth()
            .shadow(14.dp, shape, ambientColor = brand.copy(alpha = 0.22f), spotColor = brand.copy(alpha = 0.32f))
            .clip(shape).background(brush),
    ) {
        Box(Modifier.size(150.dp).offset(x = (-30).dp, y = 50.dp).align(Alignment.BottomStart).background(Color(0x10FFFFFF), CircleShape))
        Column(Modifier.padding(horizontal = 14.dp, vertical = 12.dp)) {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.wl_number_label), color = Color.White.copy(alpha = 0.68f), fontSize = 11.sp, fontWeight = FontWeight.Medium, maxLines = 1)
                    if (number.isNotBlank()) {
                        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                            Text(number, color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 2.dp))
                        }
                    }
                }
                if (number.isNotBlank()) {
                    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                        Row(horizontalArrangement = Arrangement.spacedBy(7.dp)) {
                            HeroCircle(if (copied) Icons.Rounded.Check else Icons.Rounded.ContentCopy) {
                                val clipboard = context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
                                clipboard.setPrimaryClip(ClipData.newPlainText("wallet", number))
                                copied = true
                            }
                            HeroCircle(Icons.Rounded.Share) {
                                val send = Intent(Intent.ACTION_SEND).apply { type = "text/plain"; putExtra(Intent.EXTRA_TEXT, shareText) }
                                context.startActivity(Intent.createChooser(send, null))
                            }
                        }
                    }
                }
            }
            AnimatedVisibility(copied) {
                Text(stringResource(R.string.wl_copied), color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 4.dp))
            }

            Box(Modifier.fillMaxWidth().padding(vertical = 10.dp).height(1.dp).background(Color.White.copy(alpha = 0.16f)))

            Row(
                Modifier.clip(RoundedCornerShape(50)).background(Color.White.copy(alpha = 0.12f))
                    .border(1.dp, Color.White.copy(alpha = 0.16f), RoundedCornerShape(50)).padding(horizontal = 9.dp, vertical = 4.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.AccountBalanceWallet, null, tint = Color.White.copy(alpha = 0.9f), modifier = Modifier.size(12.dp))
                Spacer(Modifier.width(5.dp))
                Text(stringResource(R.string.wl_balance), color = Color.White.copy(alpha = 0.92f), fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
            }
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                Row(Modifier.padding(top = 6.dp, bottom = 10.dp), verticalAlignment = Alignment.Bottom) {
                    Text(
                        if (hidden) "******" else figure(shown.value.toLong()), color = Color.White, fontSize = 28.sp,
                        fontWeight = FontWeight.ExtraBold, letterSpacing = (-0.3).sp, maxLines = 1,
                    )
                    if (currency.isNotBlank()) {
                        Spacer(Modifier.width(8.dp))
                        Text(currency, color = Color.White.copy(alpha = 0.82f), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(bottom = 3.dp))
                    }
                }
            }

            // The glass bar: what can be withdrawn | what is for the app's services only.
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Color.White.copy(alpha = 0.12f)).padding(vertical = 9.dp, horizontal = 4.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                HeroPart(Icons.Rounded.Check, stringResource(R.string.wl_withdrawable), if (hidden) "****" else "${figure(w.withdrawableMinor)} $currency", Modifier.weight(1f))
                Box(Modifier.width(1.dp).height(34.dp).background(Color.White.copy(alpha = 0.22f)))
                HeroPart(Icons.Rounded.CardGiftcard, stringResource(R.string.wl_spend_only), if (hidden) "****" else "${figure(w.spendOnlyMinor)} $currency", Modifier.weight(1f))
            }
            if (w.heldMinor > 0) {
                Row(Modifier.padding(top = 10.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    Icon(Icons.Rounded.PauseCircle, null, tint = Color.White.copy(alpha = 0.92f), modifier = Modifier.size(16.dp))
                    Text(
                        stringResource(R.string.wl_held) + " " + (if (hidden) "****" else figure(w.heldMinor)) + " " + currency,
                        color = Color.White.copy(alpha = 0.92f), fontSize = 12.sp,
                    )
                }
            }
        }
    }
}

/** The colour, darker (the card's gradient runs from the brand colour to a deep shade of it). */
private fun Color.darker(factor: Float) = Color(red * factor, green * factor, blue * factor, alpha)

@Composable
private fun HeroCircle(icon: ImageVector, onClick: () -> Unit) {
    Box(
        Modifier.size(32.dp).clip(CircleShape).border(1.dp, Color.White.copy(alpha = 0.40f), CircleShape).bounceClick(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Icon(icon, null, tint = Color.White, modifier = Modifier.size(16.dp)) }
}

/** One half of the glass bar: icon + label, the amount under it. */
@Composable
private fun HeroPart(icon: ImageVector, label: String, value: String, modifier: Modifier) {
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    Column(modifier.padding(horizontal = 10.dp)) {
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

/** Until the wallet answers: the card's shape, breathing. */
@Composable
private fun HeroSkeleton() {
    val pulse = rememberInfiniteTransition(label = "hero-skeleton")
    val a by pulse.animateFloat(0.45f, 0.9f, infiniteRepeatable(tween(800), RepeatMode.Reverse), label = "hero-skeleton-alpha")
    Box(Modifier.fillMaxWidth().height(186.dp).alpha(a).clip(RoundedCornerShape(24.dp)).background(Pal.well))
}

// ---------------------------------------------------------------- actions, lists

/** One of the four tiles under the card: an icon in the brand colour over its name, in a thin brand-tinted frame. */
@Composable
private fun WalletAction(icon: ImageVector, label: String, index: Int, modifier: Modifier, enabled: Boolean = true, onClick: () -> Unit) {
    val shape = RoundedCornerShape(16.dp)
    Column(
        modifier.staggerIn(index).alpha(if (enabled) 1f else 0.45f).clip(shape).background(Pal.card)
            .border(1.dp, Pal.accent.copy(alpha = 0.3f), shape).bounceClick(enabled = enabled, onClick = onClick)
            .padding(top = 10.dp, bottom = 8.dp, start = 2.dp, end = 2.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Icon(icon, null, tint = Pal.accent, modifier = Modifier.size(22.dp))
        Text(label, fontSize = 11.sp, fontWeight = FontWeight.Bold, color = Pal.ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

/** A row of one card made of many rows: rounded at its two ends, a hairline between rows. */
@Composable
private fun GroupItem(index: Int, last: Int, content: @Composable () -> Unit) {
    val top = if (index == 0) 22.dp else 0.dp
    val bottom = if (index == last) 22.dp else 0.dp
    Column(
        Modifier.padding(horizontal = 16.dp).fillMaxWidth().staggerIn(index.coerceAtMost(6))
            .clip(RoundedCornerShape(topStart = top, topEnd = top, bottomStart = bottom, bottomEnd = bottom)).background(Pal.card),
    ) {
        if (index > 0) Box(Modifier.padding(horizontal = 14.dp).fillMaxWidth().height(1.dp).background(Pal.line.copy(alpha = 0.7f)))
        content()
    }
}

/** A statement row, as in the DORR app: an icon well for the kind, the kind and its note, the signed amount, the day and time. */
@Composable
private fun TxRow(r: WalletRowDto, w: WalletDto?, onClick: () -> Unit) {
    val credit = r.direction == "credit"
    val currency = w?.currencySymbol ?: w?.currencyCode.orEmpty()
    Row(
        Modifier.fillMaxWidth().bounceClick(onClick = onClick).padding(horizontal = 14.dp, vertical = 13.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        IconWell(txIcon(r.type), 36.dp)
        Column(Modifier.weight(1f)) {
            Text(r.typeLabel, fontSize = 13.sp, fontWeight = FontWeight.Bold, color = Pal.ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
            r.note?.takeIf { it.isNotBlank() && it != r.typeLabel }?.let {
                Text(it, color = Pal.mut, fontSize = 11.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 2.dp))
            }
        }
        Column(horizontalAlignment = Alignment.End) {
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                Row(verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text((if (credit) "+ " else "- ") + figure(r.amountMinor), fontSize = 13.sp, fontWeight = FontWeight.Bold, color = if (credit) Pal.success else Pal.ink)
                    if (currency.isNotBlank()) Text(currency, color = Pal.mut, fontSize = 11.sp, modifier = Modifier.padding(bottom = 1.dp))
                }
            }
            Text(dayAndTime(LocalContext.current, r.createdAt), color = Pal.faint, fontSize = 11.sp, modifier = Modifier.padding(top = 2.dp))
        }
    }
}

@Composable
private fun WithdrawalRow(d: WithdrawalDto, w: WalletDto?) {
    val color = when (d.status) { "approved" -> Pal.success; "rejected" -> Pal.danger; else -> Pal.warning }
    Column(Modifier.fillMaxWidth().padding(horizontal = 14.dp, vertical = 13.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            IconWell(if (d.method?.type == "mobile_wallet") Icons.Rounded.PhoneAndroid else Icons.Rounded.AccountBalance, 36.dp)
            Column(Modifier.weight(1f)) {
                Text(amount(d.amountMinor, w), fontWeight = FontWeight.ExtraBold, fontSize = 15.sp, color = Pal.ink)
                Text(
                    listOfNotNull(d.method?.label, d.method?.display).joinToString(" · ").ifBlank { whenText(d.createdAt) },
                    fontSize = 11.5.sp, color = Pal.mut, maxLines = 1, overflow = TextOverflow.Ellipsis,
                )
            }
            Column(horizontalAlignment = Alignment.End) {
                Text(
                    stringResource(when (d.status) { "approved" -> R.string.wl_st_approved; "rejected" -> R.string.wl_st_rejected; else -> R.string.wl_st_pending }),
                    color = color, fontSize = 11.sp, fontWeight = FontWeight.Bold,
                    modifier = Modifier.clip(CircleShape).background(color.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 4.dp),
                )
                Text(dayAndTime(LocalContext.current, d.createdAt), color = Pal.faint, fontSize = 11.sp, modifier = Modifier.padding(top = 4.dp))
            }
        }
        d.rejectionReason?.let { Banner(it, Modifier.padding(top = 10.dp)) }
    }
}

/** An icon on a soft brand-coloured tile (the app's colours; the icon alone says what it is). */
@Composable
private fun IconWell(icon: ImageVector, size: Dp) {
    Box(Modifier.size(size).clip(RoundedCornerShape(size * 0.34f)).background(Pal.well), contentAlignment = Alignment.Center) {
        Icon(icon, null, tint = Pal.accent, modifier = Modifier.size(size * 0.45f))
    }
}

/** Nothing here yet: a floating icon, the words, and — on the statement — the way to top up. */
@Composable
private fun WalletEmpty(icon: ImageVector, text: String, action: (() -> Unit)?) {
    val float = rememberInfiniteTransition(label = "empty-float")
    val y by float.animateFloat(-5f, 5f, infiniteRepeatable(tween(1600), RepeatMode.Reverse), label = "empty-y")
    Column(
        Modifier.padding(horizontal = 16.dp).fillMaxWidth().clip(RoundedCornerShape(22.dp)).background(Pal.card).padding(vertical = 28.dp, horizontal = 24.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        HeroBadge(icon, Modifier.graphicsLayer { translationY = y * density })
        Text(text, color = Pal.mut, fontSize = 14.sp, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 14.dp))
        if (action != null) {
            Box(Modifier.padding(top = 16.dp).padding(horizontal = 24.dp)) {
                PrimaryButton(stringResource(R.string.wl_topup), icon = Icons.Rounded.Add, onClick = action)
            }
        }
    }
}

@Composable
private fun Loading() {
    Box(Modifier.fillMaxWidth().padding(32.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Pal.accent) }
}

private fun txIcon(type: String): ImageVector = when (type) {
    "topup" -> Icons.Rounded.Add
    "topup_fee", "transfer_fee" -> Icons.Rounded.Receipt
    "topup_bonus", "reward" -> Icons.Rounded.CardGiftcard
    "transfer_out" -> Icons.Rounded.NorthEast
    "transfer_in" -> Icons.Rounded.SouthWest
    "withdrawal" -> Icons.Rounded.AccountBalance
    "refund", "reversal" -> Icons.Rounded.Replay
    "penalty" -> Icons.Rounded.ErrorOutline
    "service_payment" -> Icons.Rounded.ShoppingBag
    "service_earning" -> Icons.Rounded.DirectionsCar
    "commission" -> Icons.Rounded.Percent
    "tax" -> Icons.Rounded.RequestQuote
    "tip" -> Icons.Rounded.VolunteerActivism
    "discount_compensation" -> Icons.Rounded.LocalOffer
    "debt_settlement" -> Icons.Rounded.Paid
    "manual_adjustment" -> Icons.Rounded.Tune
    else -> Icons.Rounded.Receipt
}

// ---------------------------------------------------------------- withdrawing

/** O-35: the withdrawal sheet, opened from the wallet copied from the DORR app (the captain's own action there). */
@Composable
internal fun DriverWithdraw(onClose: () -> Unit, onDone: () -> Unit) {
    var w by remember { mutableStateOf<WalletDto?>(null) }
    LaunchedEffect(Unit) { (apiCall { DriverBackend.api.wallet() } as? ApiResult.Ok)?.let { w = it.data } }
    w?.let { WithdrawSheet(it, onClose = onClose, onDone = onDone) }
}

/** Withdraw: pick (or add) where to, the amount, then the wallet PIN — made first if there isn't one yet. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun WithdrawSheet(w: WalletDto, onClose: () -> Unit, onDone: () -> Unit) {
    val context = LocalContext.current
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
    // D-219: the account is in someone else's name — the captain's word for it; D-223: abroad needs approval.
    var askAck by remember { mutableStateOf(false) }
    var ack by remember { mutableStateOf(false) }
    var askIntl by remember { mutableStateOf(false) }

    suspend fun load() {
        methods = (apiCall { DriverBackend.api.withdrawalMethods() } as? ApiResult.Ok)?.data.orEmpty()
        pinInfo = (apiCall { DriverBackend.api.walletPin() } as? ApiResult.Ok)?.data
        if (methodId == null) methodId = methods?.firstOrNull { it.isFavorite }?.id ?: methods?.firstOrNull()?.id
        if (methods.isNullOrEmpty()) adding = true
    }
    LaunchedEffect(Unit) { load() }

    val minor = amountText.replace(',', '.').toDoubleOrNull()?.let { Math.round(it * 100) } ?: 0L
    val tooMuch = minor > w.withdrawableMinor
    val needsPinSetup = pinInfo?.hasPin == false
    val pinsDiffer = needsPinSetup && pinConfirm.length == 4 && pin != pinConfirm

    ModalBottomSheet(onDismissRequest = { if (!busy) onClose() }, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = Pal.card) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 18.dp).padding(bottom = 18.dp).navigationBarsPadding().imePadding(),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(stringResource(if (adding) R.string.wl_add_method else R.string.wl_withdraw), color = Pal.ink, fontWeight = FontWeight.ExtraBold, fontSize = 19.sp)
            Text(stringResource(R.string.wl_can_withdraw, amount(w.withdrawableMinor, w)), fontSize = 13.sp, color = Pal.mut)
            val list = methods
            if (list == null || pinInfo == null) {
                Loading()
                return@Column
            }
            if (!adding) {
                Text(stringResource(R.string.wl_to), fontSize = 13.sp, fontWeight = FontWeight.Bold, color = Pal.ink)
                list.forEach { m ->
                    val on = m.id == methodId
                    val shape = RoundedCornerShape(16.dp)
                    Row(
                        Modifier.fillMaxWidth().clip(shape).background(if (on) Pal.well else Pal.fieldFill)
                            .border(if (on) 1.5.dp else 1.dp, if (on) Pal.accent else Pal.fieldLine, shape)
                            .bounceClick { methodId = m.id }.padding(12.dp),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(10.dp),
                    ) {
                        TileIcon(if (m.type == "bank") Icons.Rounded.AccountBalance else Icons.Rounded.PhoneAndroid, Pal.accent, 38.dp)
                        Column(Modifier.weight(1f)) {
                            Text(m.label ?: stringResource(if (m.type == "bank") R.string.wl_bank else R.string.wl_mobile), color = Pal.ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                            Text(m.display.orEmpty(), fontSize = 12.sp, color = Pal.mut)
                        }
                        Icon(if (on) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (on) Pal.accent else Pal.line, modifier = Modifier.size(22.dp))
                        Box(
                            Modifier.size(30.dp).clip(CircleShape).bounceClick {
                                scope.launch { apiCall { DriverBackend.api.deleteWithdrawalMethod(m.id) }; if (methodId == m.id) methodId = null; load() }
                            },
                            contentAlignment = Alignment.Center,
                        ) { Icon(Icons.Rounded.DeleteOutline, null, tint = Pal.faint, modifier = Modifier.size(18.dp)) }
                    }
                }
                Row(
                    Modifier.clip(RoundedCornerShape(12.dp)).bounceClick { adding = true }.padding(vertical = 6.dp, horizontal = 4.dp),
                    verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    Icon(Icons.Rounded.Add, null, tint = Pal.accent, modifier = Modifier.size(18.dp))
                    Text(stringResource(R.string.wl_add_method), color = Pal.accent, fontWeight = FontWeight.Bold, fontSize = 13.sp)
                }
                DorrTextField(
                    value = amountText, onValueChange = { amountText = it.filter { c -> c.isDigit() || c == '.' || c == ',' }.take(10) },
                    label = stringResource(R.string.wl_amount, w.currencySymbol ?: ""), placeholder = "0.00", icon = Icons.Rounded.Payments, error = tooMuch,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                    trailing = { Chip(stringResource(R.string.wl_all), onClick = { amountText = String.format(Locale.US, "%.2f", w.withdrawableMinor / 100.0) }) },
                )
                Banner(if (tooMuch) stringResource(R.string.wl_too_much) else null)
            } else {
                Segmented(listOf("bank" to stringResource(R.string.wl_bank), "mobile_wallet" to stringResource(R.string.wl_mobile)), selected = type, onSelect = { type = it })
                DorrTextField(value = label, onValueChange = { label = it.take(60) }, label = stringResource(R.string.wl_label), icon = Icons.Rounded.Edit)
                if (type == "bank") {
                    DorrTextField(value = bankName, onValueChange = { bankName = it.take(100) }, label = stringResource(R.string.wl_bank_name), icon = Icons.Rounded.AccountBalance)
                    DorrTextField(value = holder, onValueChange = { holder = it.take(100) }, label = stringResource(R.string.wl_holder), icon = Icons.Rounded.Person)
                    DorrTextField(
                        value = iban, onValueChange = { iban = it.filter { c -> c.isLetterOrDigit() }.uppercase().take(34) }, label = "IBAN", placeholder = "SA00 0000 0000 0000 0000 0000",
                        icon = Icons.Rounded.CreditCard, error = iban.isNotEmpty() && iban.length < 15,
                    )
                } else {
                    DorrTextField(value = provider, onValueChange = { provider = it.take(100) }, label = stringResource(R.string.wl_provider), icon = Icons.Rounded.PhoneAndroid)
                    DorrTextField(
                        value = number, onValueChange = { number = it.filter { c -> c.isDigit() }.take(20) }, label = stringResource(R.string.wl_number), icon = Icons.Rounded.Numbers,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), error = number.isNotEmpty() && number.length < 6,
                    )
                }
                if (askAck) {
                    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(Pal.warning.copy(alpha = 0.1f)).bounceClick { ack = !ack }.padding(10.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(if (ack) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (ack) Pal.accent else Pal.mut, modifier = Modifier.size(20.dp))
                        Spacer(Modifier.width(8.dp))
                        Text(stringResource(R.string.wx_third_party_ack), color = Pal.ink, fontSize = 12.5.sp)
                    }
                }
                if (askIntl) {
                    Text(stringResource(R.string.wx_intl_needed), color = Pal.accent, fontWeight = FontWeight.Bold, fontSize = 13.sp,
                        modifier = Modifier.clip(RoundedCornerShape(12.dp)).bounceClick { askIntl = false; onClose(); }.padding(vertical = 6.dp, horizontal = 4.dp))
                }
                if (list.isNotEmpty()) {
                    Text(
                        stringResource(R.string.cancel), color = Pal.mut, fontWeight = FontWeight.Bold, fontSize = 13.sp,
                        modifier = Modifier.clip(RoundedCornerShape(12.dp)).bounceClick { adding = false }.padding(vertical = 6.dp, horizontal = 4.dp),
                    )
                }
            }

            // The wallet PIN: made once, asked for each time money moves.
            if (needsPinSetup) Text(stringResource(R.string.wl_make_pin), color = Pal.ink, fontWeight = FontWeight.Bold, fontSize = 13.sp)
            PinField(pin, stringResource(R.string.wl_pin)) { pin = it }
            if (needsPinSetup) PinField(pinConfirm, stringResource(R.string.wl_pin_confirm), error = pinsDiffer) { pinConfirm = it }
            Banner(error)

            val ready = if (adding) {
                pin.length == 4 && (!needsPinSetup || pin == pinConfirm) &&
                    if (type == "bank") bankName.isNotBlank() && holder.isNotBlank() && iban.length >= 15 else provider.isNotBlank() && number.length >= 6
            } else {
                pin.length == 4 && (!needsPinSetup || pin == pinConfirm) && methodId != null && minor > 0 && !tooMuch
            }
            PrimaryButton(
                stringResource(if (adding) R.string.wl_save_method else R.string.wl_send_request), enabled = ready, busy = busy,
                icon = if (adding) Icons.Rounded.Check else Icons.Rounded.AccountBalance,
            ) {
                busy = true
                error = null
                scope.launch {
                    if (needsPinSetup) {
                        val made = apiCall { DriverBackend.api.createWalletPin(JsonObject().apply { addProperty("pin", pin); addProperty("pin_confirmation", pinConfirm) }) }
                        if (made !is ApiResult.Ok) {
                            error = (made as? ApiResult.Failed)?.error?.displayMessage ?: context.getString(R.string.error_offline)
                            busy = false
                            return@launch
                        }
                        pinInfo = pinInfo?.copy(hasPin = true)
                    }
                    if (adding) {
                        val body = JsonObject().apply {
                            addProperty("type", type)
                            if (ack) addProperty("acknowledge_third_party", true)
                            if (label.isNotBlank()) addProperty("label", label.trim())
                            add("data", JsonObject().apply {
                                if (type == "bank") { addProperty("bank_name", bankName.trim()); addProperty("account_holder", holder.trim()); addProperty("iban", iban.trim()) }
                                else { addProperty("provider_name", provider.trim()); addProperty("number", number.trim()) }
                            })
                        }
                        when (val r = apiCall { DriverBackend.api.addWithdrawalMethod(pin, body) }) {
                            is ApiResult.Ok -> { methodId = r.data.id; adding = false; pin = ""; pinConfirm = ""; askAck = false; ack = false; load() }
                            is ApiResult.Failed -> {
                                error = r.error.displayMessage
                                if (r.error.errorCode == "rides_payout_third_party_ack") askAck = true
                                if (r.error.errorCode == "rides_payout_international_off") askIntl = true
                            }
                            is ApiResult.Offline -> error = context.getString(R.string.error_offline)
                        }
                    } else {
                        val body = JsonObject().apply { addProperty("withdrawal_method_id", methodId); addProperty("amount_minor", minor) }
                        when (val r = apiCall { DriverBackend.api.withdraw(pin, CoreHttp.newIdempotencyKey(), body) }) {
                            is ApiResult.Ok -> onDone()
                            is ApiResult.Failed -> error = r.error.displayMessage
                            is ApiResult.Offline -> error = context.getString(R.string.error_offline)
                        }
                    }
                    busy = false
                }
            }
        }
    }
}

@Composable
private fun PinField(value: String, label: String, error: Boolean = false, onChange: (String) -> Unit) {
    DorrTextField(
        value = value, onValueChange = { onChange(it.filter { c -> c.isDigit() }.take(4)) }, label = label, placeholder = "••••",
        icon = Icons.Rounded.Lock, password = true, error = error,
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
    )
}

// ---------------------------------------------------------------- formatting

/** "125.50" from minor units (two decimals), without the currency. */
private fun figure(minor: Long): String = String.format(Locale.getDefault(), "%.2f", minor / 100.0)

/** "125.50 ر.س" from minor units (the wallet's currency; two decimals). */
internal fun amount(minor: Long, w: WalletDto?): String =
    "${String.format(Locale.getDefault(), "%.2f", minor / 100.0)} ${w?.currencySymbol ?: w?.currencyCode.orEmpty()}".trim()

internal fun whenText(iso: String?): String {
    val at = iso?.let { runCatching { OffsetDateTime.parse(it) }.getOrNull() } ?: return ""
    return at.atZoneSameInstant(ZoneId.systemDefault()).format(DateTimeFormatter.ofLocalizedDateTime(FormatStyle.MEDIUM, FormatStyle.SHORT).withLocale(Locale.getDefault()))
}

/** "Today, 14:05", "Yesterday, 09:10", else "12 Oct, 18:30" — in the phone's time zone. */
private fun dayAndTime(context: Context, iso: String?): String {
    val at = iso?.let { runCatching { OffsetDateTime.parse(it) }.getOrNull() }?.atZoneSameInstant(ZoneId.systemDefault()) ?: return ""
    val time = at.format(DateTimeFormatter.ofPattern("HH:mm", Locale.US))
    val today = LocalDate.now()
    val day = when (at.toLocalDate()) {
        today -> context.getString(R.string.wl_day_today)
        today.minusDays(1) -> context.getString(R.string.wl_day_yesterday)
        else -> at.format(DateTimeFormatter.ofPattern("d MMM", Locale.getDefault()))
    }
    return day + context.getString(R.string.list_sep) + time
}
