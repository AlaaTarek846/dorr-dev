@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.dorr.app.ui.screens.watchearn

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.shrinkVertically
import androidx.compose.animation.togetherWith
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.CreditCard
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.LockOpen
import androidx.compose.material.icons.rounded.Savings
import androidx.compose.material.icons.rounded.SouthWest
import androidx.compose.material.icons.rounded.Undo
import androidx.compose.material.icons.rounded.Warning
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.WeAdWalletDto
import com.dorr.app.network.WeAmountRequest
import com.dorr.app.network.WeBucketsDto
import com.dorr.app.network.WeCurrencyDto
import com.dorr.app.network.WeEntryDto
import com.dorr.app.network.WeMoveRequest
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.chat.ChSheet
import com.dorr.app.ui.screens.wallet.CheckoutLauncher
import com.dorr.app.ui.screens.wallet.CheckoutRequest
import com.dorr.app.ui.screens.wallet.PadResult
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaDivider
import com.dorr.app.ui.screens.wallet.WaEmpty
import com.dorr.app.ui.screens.wallet.WaNote
import com.dorr.app.ui.screens.wallet.WaPage
import com.dorr.app.ui.screens.wallet.WaPinPad
import com.dorr.app.ui.screens.wallet.WaSectionTitle
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.wallet.waRise
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.util.UUID

/**
 * The ad wallet (D12–D14): the big total, what is free and what ads hold — each split by bucket in the same three
 * colours — the ads holding money, moving money in from the wallet (previewed, then the wallet PIN) and back out
 * (previewed, the free balance only), and the statement. [fundMinor] opens the fund sheet with that amount and,
 * once funded, goes back to the page that asked (the wizard, a budget top-up).
 */
@Composable
fun AdWallet(fundMinor: Long?) {
    val host = LocalWe.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.we_error_generic)
    var wallet by remember { mutableStateOf<WeAdWalletDto?>(null) }
    var failed by remember { mutableStateOf(false) }
    var reload by remember { mutableIntStateOf(0) }
    val entries = remember { mutableStateListOf<WeEntryDto>() }
    var nextCursor by remember { mutableStateOf<String?>(null) }
    var loadingMore by remember { mutableStateOf(false) }
    var fundOpen by remember { mutableStateOf(fundMinor != null) }
    var returnOpen by remember { mutableStateOf(false) }
    var topupOpen by remember { mutableStateOf(false) }
    val toppedUp = stringResource(R.string.we_topup_paid)

    fun show(fresh: WeAdWalletDto) {
        wallet = fresh
        entries.clear()
        entries.addAll(fresh.entries)
        nextCursor = fresh.nextCursor
    }

    LaunchedEffect(reload) {
        runCatching { ApiClient.watchEarn.adWallet(weAuth()).data }
            .onSuccess { if (it != null) { show(it); failed = false } else failed = wallet == null }
            .onFailure { failed = wallet == null; host.showToast(it.weMessage(generic), error = true) }
    }

    WaPage(title = stringResource(R.string.we_ad_wallet), onBack = { host.pop() }) {
        val w = wallet
        if (w == null) {
            if (failed) {
                WaEmpty(
                    Icons.Rounded.Warning, Tone.Red, stringResource(R.string.we_load_failed), stringResource(R.string.we_load_failed_hint),
                    action = { WaButton(stringResource(R.string.we_retry), { reload++ }, modifier = Modifier.padding(horizontal = 40.dp)) },
                )
            } else {
                Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    WaSkeleton(Modifier.fillMaxWidth().height(150.dp), RoundedCornerShape(24.dp))
                    WaSkeleton(Modifier.fillMaxWidth().height(180.dp), RoundedCornerShape(22.dp))
                }
            }
            return@WaPage
        }
        val currency = w.currency

        TotalCard(w, Modifier.waRise(0))
        Spacer(Modifier.height(12.dp))
        Row(Modifier.fillMaxWidth().waRise(1), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            WaButton(stringResource(R.string.we_fund_from_wallet), { fundOpen = true }, icon = Icons.Rounded.Add, compact = true, modifier = Modifier.weight(1f))
            WaButton(stringResource(R.string.we_return_to_wallet), { returnOpen = true }, icon = Icons.Rounded.Undo, style = WaButtonStyle.Ghost, compact = true, enabled = w.free.totalMinor > 0, modifier = Modifier.weight(1f))
        }
        Spacer(Modifier.height(10.dp))
        WaButton(
            stringResource(R.string.we_topup_card), { topupOpen = true },
            icon = Icons.Rounded.CreditCard, style = WaButtonStyle.Ghost, compact = true,
            modifier = Modifier.waRise(1),
        )

        WaSectionTitle(stringResource(R.string.we_free), Modifier.waRise(2), topPadding = 18.dp) {
            WeLtr { Text(weMoney(w.free.totalMinor, currency), color = We.Withdrawable, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold) }
        }
        WaCard(Modifier.fillMaxWidth().waRise(2), padding = 14.dp) { WeBucketSplit(w.free, currency) }

        WaSectionTitle(stringResource(R.string.we_locked_in_ads), Modifier.waRise(3)) {
            WeLtr { Text(weMoney(w.locked.totalMinor, currency), color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold) }
        }
        WaCard(Modifier.fillMaxWidth().waRise(3), padding = 14.dp) {
            WeBucketSplit(w.locked, currency)
            if (w.ads.isNotEmpty()) {
                Spacer(Modifier.height(8.dp))
                WaDivider()
                w.ads.forEach { ad ->
                    Row(
                        Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable { host.push(WeRoute.Stats(ad.id)) }.padding(vertical = 10.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(Icons.Rounded.Lock, null, tint = Wa.Mut, modifier = Modifier.size(16.dp))
                        Spacer(Modifier.width(8.dp))
                        Column(Modifier.weight(1f)) {
                            Text(ad.title, color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            WeStatusBadge(ad.status, modifier = Modifier.padding(top = 3.dp))
                        }
                        WeLtr { Text(weMoney(ad.lockedMinor, currency), color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold) }
                        Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, null, tint = Wa.Soft, modifier = Modifier.size(18.dp))
                    }
                }
            }
            Spacer(Modifier.height(6.dp))
            Text(stringResource(R.string.we_locked_note), color = Wa.Mut, fontSize = 11.5.sp, lineHeight = 18.sp)
        }

        WaSectionTitle(stringResource(R.string.we_statement), Modifier.waRise(4))
        WaCard(Modifier.fillMaxWidth().waRise(4)) {
            if (entries.isEmpty()) {
                WaEmpty(Icons.Rounded.Savings, Tone.Green, stringResource(R.string.we_no_entries), stringResource(R.string.we_no_entries_text))
            } else {
                Column(Modifier.padding(horizontal = 14.dp, vertical = 4.dp)) {
                    entries.forEachIndexed { i, e ->
                        EntryRow(e, currency)
                        if (i < entries.lastIndex) WaDivider()
                    }
                }
            }
        }
        val cursor = nextCursor
        if (cursor != null) {
            Spacer(Modifier.height(10.dp))
            WaButton(
                stringResource(R.string.we_load_more),
                onClick = {
                    loadingMore = true
                    scope.launch {
                        runCatching { ApiClient.watchEarn.adWallet(weAuth(), cursor).data }.getOrNull()?.let { page ->
                            page.entries.forEach { n -> if (entries.none { it.id == n.id }) entries.add(n) }
                            nextCursor = page.nextCursor
                        }
                        loadingMore = false
                    }
                },
                style = WaButtonStyle.Ghost, loading = loadingMore, compact = true,
            )
        }
    }

    val w = wallet
    if (fundOpen && w != null) {
        FundSheet(
            wallet = w,
            initialMinor = fundMinor,
            onDismiss = { fundOpen = false },
            onDone = { fresh ->
                fundOpen = false
                show(fresh)
                // Opened to fund something (the wizard, more budget): back to it.
                if (fundMinor != null) host.pop()
            },
        )
    }
    if (returnOpen && w != null) {
        ReturnSheet(
            wallet = w,
            onDismiss = { returnOpen = false },
            onDone = { fresh ->
                returnOpen = false
                show(fresh)
            },
        )
    }
    if (topupOpen && w != null) {
        TopupSheet(
            currency = w.currency,
            onDismiss = { topupOpen = false },
            onPay = { minor ->
                topupOpen = false
                // The app's one payment screen prices it and takes the card (or the wallet); once paid, read the ad wallet again.
                CheckoutLauncher.open(CheckoutRequest("watch_earn_ad_wallet", mapOf("amount_minor" to minor))) {
                    host.showToast(toppedUp)
                    reload++
                }
            },
        )
    }
}

/** The big total, counting up, on the brand gradient, with free / locked under it. */
@Composable
private fun TotalCard(w: WeAdWalletDto, modifier: Modifier) {
    val shape = RoundedCornerShape(24.dp)
    Box(
        modifier
            .fillMaxWidth()
            .shadow(14.dp, shape, ambientColor = Wa.Red.copy(alpha = 0.22f), spotColor = Wa.Red.copy(alpha = 0.32f))
            .clip(shape)
            .background(Wa.HeroBrush),
    ) {
        Box(Modifier.size(140.dp).offset(x = (-30).dp, y = 50.dp).align(Alignment.BottomStart).background(Color(0x12FFFFFF), CircleShape))
        Column(Modifier.padding(16.dp)) {
            Text(stringResource(R.string.we_ad_wallet_total), color = Color.White.copy(alpha = 0.82f), fontSize = 12.sp)
            WeCountUp(w.totalMinor, { weMoney(it, w.currency) }, color = Color.White, fontSize = 30.sp, modifier = Modifier.padding(top = 2.dp))
            Spacer(Modifier.height(12.dp))
            Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Color.White.copy(alpha = 0.12f)).padding(10.dp)) {
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.we_free), color = Color.White.copy(alpha = 0.85f), fontSize = 11.sp)
                    WeLtr { Text(weMoney(w.free.totalMinor, w.currency), color = Color.White, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold) }
                }
                Box(Modifier.width(1.dp).height(34.dp).background(Color.White.copy(alpha = 0.22f)))
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.we_locked_in_ads), color = Color.White.copy(alpha = 0.85f), fontSize = 11.sp)
                    WeLtr { Text(weMoney(w.locked.totalMinor, w.currency), color = Color.White, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold) }
                }
            }
        }
    }
}

private fun entryMeta(type: String): Triple<ImageVector, Color, Int> = when (type) {
    "fund" -> Triple(Icons.Rounded.SouthWest, We.Withdrawable, R.string.we_entry_fund)
    "topup" -> Triple(Icons.Rounded.Add, We.Withdrawable, R.string.we_entry_topup)
    "return" -> Triple(Icons.Rounded.Undo, We.SpendOnly, R.string.we_entry_return)
    "reserve" -> Triple(Icons.Rounded.Lock, We.Reward, R.string.we_entry_reserve)
    "release" -> Triple(Icons.Rounded.LockOpen, We.Withdrawable, R.string.we_entry_release)
    else -> Triple(Icons.Rounded.Info, Color(0xFF6B7280), R.string.we_entry_other)
}

/** A statement line: what happened, the ad, by bucket, the signed amount and the free balance after it. */
@Composable
private fun EntryRow(e: WeEntryDto, currency: WeCurrencyDto) {
    val (icon, tint, label) = entryMeta(e.type)
    // Money into the free balance is +, money out of it (back to the wallet, reserved for an ad) is −.
    val credit = e.type == "fund" || e.type == "topup" || e.type == "release"
    Row(Modifier.fillMaxWidth().padding(vertical = 12.dp), verticalAlignment = Alignment.Top) {
        Box(Modifier.size(38.dp).clip(RoundedCornerShape(12.dp)).background(tint.copy(alpha = 0.13f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = tint, modifier = Modifier.size(18.dp))
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(stringResource(label), color = Wa.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.Bold)
            e.ad?.title?.let { Text(it, color = Wa.Mut, fontSize = 11.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis) }
            WeBucketChips(e.byBucket, currency, Modifier.padding(top = 5.dp))
            Text(weDateTime(e.createdAt), color = Wa.Soft, fontSize = 10.5.sp, modifier = Modifier.padding(top = 4.dp))
        }
        Column(horizontalAlignment = Alignment.End) {
            WeLtr {
                Text(
                    (if (credit) "+ " else "− ") + weMoney(e.amountMinor, currency),
                    color = if (credit) We.Withdrawable else Wa.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.ExtraBold,
                )
            }
            Text(stringResource(R.string.we_free_after), color = Wa.Soft, fontSize = 10.sp, modifier = Modifier.padding(top = 4.dp))
            WeLtr { Text(weMoney(e.freeAfterMinor, currency), color = Wa.Mut, fontSize = 11.sp, fontWeight = FontWeight.Bold) }
        }
    }
}

// ------------------------------------------------------------------------------- fund

/**
 * «Move from the wallet»: the amount → what each bucket gives (against the wallet's balance by bucket) → the wallet
 * PIN → done. The idempotency key is made once per attempt: kept on a dropped connection so a retry can't move it
 * twice, made anew after any answer or a new amount.
 */
@Composable
private fun FundSheet(wallet: WeAdWalletDto, initialMinor: Long?, onDismiss: () -> Unit, onDone: (WeAdWalletDto) -> Unit) {
    val host = LocalWe.current
    val generic = stringResource(R.string.we_error_generic)
    val networkError = stringResource(R.string.we_error_network)
    val funded = stringResource(R.string.we_funded)
    val currency = wallet.currency
    var text by remember { mutableStateOf(initialMinor?.let { weAmountInput(it, currency.decimals) }.orEmpty()) }
    val amount = weParseAmount(text, currency.decimals)
    var preview by remember { mutableStateOf<WeBucketsDto?>(null) }
    var previewing by remember { mutableStateOf(false) }
    var step by remember { mutableIntStateOf(0) }
    var key by remember(amount) { mutableStateOf(UUID.randomUUID().toString()) }
    val available = wallet.mainWallet.totalMinor
    val tooMuch = amount != null && amount > available

    // What each bucket gives, asked a moment after the amount settles.
    LaunchedEffect(amount) {
        preview = null
        if (amount == null || tooMuch) return@LaunchedEffect
        delay(400)
        previewing = true
        preview = runCatching { ApiClient.watchEarn.fundPreview(weAuth(), WeAmountRequest(amount)).data?.take }.getOrNull()
        previewing = false
    }

    ChSheet(onDismissRequest = onDismiss, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        AnimatedContent(step, transitionSpec = { (fadeIn(tween(220)) + scaleIn(initialScale = 0.94f)) togetherWith fadeOut(tween(150)) }, label = "fundStep") { now ->
            if (now == 1 && amount != null) {
                Column(Modifier.fillMaxWidth().heightIn(min = 520.dp).padding(horizontal = 12.dp).padding(bottom = 16.dp)) {
                    WaPinPad(
                        title = stringResource(R.string.we_fund_pin_title, weMoney(amount, currency)),
                        sub = stringResource(R.string.we_fund_pin_sub),
                        modifier = Modifier.fillMaxWidth().height(500.dp),
                        onComplete = { pin ->
                            try {
                                val fresh = ApiClient.watchEarn.fund(weAuth(), pin, WeMoveRequest(amount, key)).data
                                key = UUID.randomUUID().toString()
                                host.showToast(funded)
                                if (fresh != null) onDone(fresh) else onDismiss()
                                PadResult.Ok
                            } catch (e: CancellationException) {
                                throw e
                            } catch (e: Exception) {
                                val failure = e.apiFailure()
                                // A PIN problem: the pad shows it (and a lock's countdown) and stays.
                                if (failure.errorCode?.startsWith("wallet_pin") == true) {
                                    key = UUID.randomUUID().toString()
                                    throw e
                                }
                                // No answer at all: same key, so trying again can't move the money twice.
                                if (failure.httpStatus == null) return@WaPinPad PadResult.Error(networkError)
                                key = UUID.randomUUID().toString()
                                host.showToast(failure.message ?: generic, error = true)
                                step = 0
                                PadResult.Ok
                            }
                        },
                    )
                }
                return@AnimatedContent
            }
            Column(
                Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).imePadding().navigationBarsPadding()
                    .padding(horizontal = 20.dp).padding(bottom = 20.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                Text(stringResource(R.string.we_fund_from_wallet), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
                AmountField(text, { text = it }, currency, invalid = (text.isNotBlank() && amount == null) || tooMuch)
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(stringResource(R.string.we_wallet_available), color = Wa.Mut, fontSize = 12.5.sp, modifier = Modifier.weight(1f))
                    WeLtr { Text(weMoney(available, currency), color = if (tooMuch) Wa.Danger else Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold) }
                }
                // Each bucket: what the wallet has, and what this move takes from it.
                WaCard(Modifier.fillMaxWidth(), padding = 14.dp) {
                    Row {
                        Text(stringResource(R.string.we_bucket), color = Wa.Soft, fontSize = 11.sp, modifier = Modifier.weight(1f))
                        Text(stringResource(R.string.we_in_wallet), color = Wa.Soft, fontSize = 11.sp, modifier = Modifier.width(92.dp))
                        Text(stringResource(R.string.we_takes), color = Wa.Soft, fontSize = 11.sp, modifier = Modifier.width(84.dp))
                    }
                    WeBucket.entries.forEach { b ->
                        Row(Modifier.fillMaxWidth().padding(vertical = 7.dp), verticalAlignment = Alignment.CenterVertically) {
                            WeDot(b.color)
                            Spacer(Modifier.width(7.dp))
                            Text(stringResource(b.label), color = Wa.Ink, fontSize = 12.5.sp, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                            WeLtr { Text(weAmount(b.of(wallet.mainWallet), currency.decimals), color = Wa.Mut, fontSize = 12.sp, modifier = Modifier.width(92.dp)) }
                            val take = preview?.let { b.of(it) }
                            Box(Modifier.width(84.dp)) {
                                when {
                                    take != null -> WeLtr { Text(weAmount(take, currency.decimals), color = if (take > 0) b.color else Wa.Soft, fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold) }
                                    previewing -> WaSkeleton(Modifier.width(50.dp).height(12.dp))
                                    else -> Text("—", color = Wa.Soft, fontSize = 12.sp)
                                }
                            }
                        }
                    }
                }
                AnimatedVisibility(tooMuch, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
                    WaNote(stringResource(R.string.we_fund_too_much), icon = Icons.Rounded.Warning)
                }
                WaNote(stringResource(R.string.we_fund_note), icon = Icons.Rounded.Info)
                WaButton(
                    stringResource(R.string.we_continue),
                    { step = 1 },
                    enabled = amount != null && !tooMuch && preview != null,
                    icon = Icons.Rounded.Lock,
                )
            }
        }
    }
}

// ------------------------------------------------------------------------------- return

/** «Back to the wallet»: the free balance only (max = free); the preview shows what each bucket gets back. */
@Composable
private fun ReturnSheet(wallet: WeAdWalletDto, onDismiss: () -> Unit, onDone: (WeAdWalletDto) -> Unit) {
    val host = LocalWe.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.we_error_generic)
    val networkError = stringResource(R.string.we_error_network)
    val returned = stringResource(R.string.we_returned)
    val currency = wallet.currency
    val free = wallet.free.totalMinor
    var text by remember { mutableStateOf("") }
    val amount = weParseAmount(text, currency.decimals)
    val tooMuch = amount != null && amount > free
    var preview by remember { mutableStateOf<WeBucketsDto?>(null) }
    var busy by remember { mutableStateOf(false) }
    var key by remember(amount) { mutableStateOf(UUID.randomUUID().toString()) }

    LaunchedEffect(amount) {
        preview = null
        if (amount == null || tooMuch) return@LaunchedEffect
        delay(400)
        preview = runCatching { ApiClient.watchEarn.returnPreview(weAuth(), WeAmountRequest(amount)).data?.give }.getOrNull()
    }

    ChSheet(onDismissRequest = onDismiss, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).imePadding().navigationBarsPadding()
                .padding(horizontal = 20.dp).padding(bottom = 20.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(stringResource(R.string.we_return_to_wallet), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            AmountField(text, { text = it }, currency, invalid = (text.isNotBlank() && amount == null) || tooMuch)
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(stringResource(R.string.we_free), color = Wa.Mut, fontSize = 12.5.sp, modifier = Modifier.weight(1f))
                WeLtr {
                    Text(
                        weMoney(free, currency), color = We.Withdrawable, fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold,
                        modifier = Modifier.clip(RoundedCornerShape(8.dp)).clickable { text = weAmountInput(free, currency.decimals) }.padding(4.dp),
                    )
                }
            }
            AnimatedVisibility(preview != null, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
                WaCard(Modifier.fillMaxWidth(), padding = 14.dp) {
                    Text(stringResource(R.string.we_return_goes), color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(bottom = 6.dp))
                    preview?.let { WeBucketSplit(it, currency) }
                }
            }
            AnimatedVisibility(tooMuch, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
                WaNote(stringResource(R.string.we_return_too_much), icon = Icons.Rounded.Warning)
            }
            WaNote(stringResource(R.string.we_locked_note), icon = Icons.Rounded.Info)
            WaButton(
                stringResource(R.string.we_confirm_return),
                onClick = {
                    val minor = amount ?: return@WaButton
                    busy = true
                    scope.launch {
                        try {
                            val fresh = ApiClient.watchEarn.returnToWallet(weAuth(), WeMoveRequest(minor, key)).data
                            key = UUID.randomUUID().toString()
                            host.showToast(returned)
                            if (fresh != null) onDone(fresh) else onDismiss()
                        } catch (e: CancellationException) {
                            throw e
                        } catch (e: Exception) {
                            val failure = e.apiFailure()
                            if (failure.httpStatus == null) {
                                host.showToast(networkError, error = true)
                            } else {
                                key = UUID.randomUUID().toString()
                                host.showToast(failure.message ?: generic, error = true)
                            }
                        }
                        busy = false
                    }
                },
                enabled = amount != null && !tooMuch && preview != null,
                loading = busy,
                icon = Icons.Rounded.Undo,
            )
        }
    }
}

// ------------------------------------------------------------------------------- top up by card

/**
 * «Top up by card»: an amount, then the app's payment screen (`wallet/checkouts`, purpose `watch_earn_ad_wallet`).
 * Paid by a gateway, it lands in the ad wallet as withdrawable; paid from the wallet, it moves like a fund.
 */
@Composable
private fun TopupSheet(currency: WeCurrencyDto, onDismiss: () -> Unit, onPay: (Long) -> Unit) {
    var text by remember { mutableStateOf("") }
    val amount = weParseAmount(text, currency.decimals)?.takeIf { it > 0 }
    ChSheet(onDismissRequest = onDismiss, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).imePadding().navigationBarsPadding()
                .padding(horizontal = 20.dp).padding(bottom = 20.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(stringResource(R.string.we_topup_card), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            AmountField(text, { text = it }, currency, invalid = text.isNotBlank() && amount == null)
            WaNote(stringResource(R.string.we_topup_card_note), icon = Icons.Rounded.Info)
            WaButton(
                stringResource(R.string.we_topup_continue),
                { amount?.let(onPay) },
                enabled = amount != null,
                icon = Icons.Rounded.CreditCard,
            )
        }
    }
}

@Composable
private fun AmountField(text: String, onChange: (String) -> Unit, currency: WeCurrencyDto, invalid: Boolean) {
    DorrTextField(
        value = text,
        onValueChange = { onChange(it.filter { ch -> ch.isDigit() || ch == '.' || ch == ',' }) },
        label = stringResource(R.string.we_amount),
        placeholder = "0",
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
        error = invalid,
        trailing = { Text(currency.code.orEmpty(), color = Wa.Mut, fontSize = 13.sp, fontWeight = FontWeight.Bold) },
    )
}
