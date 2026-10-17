package com.dorr.app.ui.screens.wallet

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.keyframes
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
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
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowLeft
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.AccountBalance
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.CardGiftcard
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.outlined.QrCode2
import androidx.compose.material.icons.rounded.ContentCopy
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.NorthEast
import androidx.compose.material.icons.rounded.PauseCircle
import androidx.compose.material.icons.rounded.Public
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Settings
import androidx.compose.material.icons.rounded.Share
import androidx.compose.material.icons.rounded.SwapHoriz
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material.icons.rounded.VisibilityOff
import androidx.compose.material3.Icon
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
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.rotate
import androidx.compose.ui.draw.scale
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalInspectionMode
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.runtime.CompositionLocalProvider
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AppLocale
import com.dorr.app.network.WalletBalanceDto
import com.dorr.app.network.WalletCountry
import com.dorr.app.network.WalletTransactionDto
import com.dorr.app.network.collectReconnectTick
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** English/Arabic name of a wallet's country from its ISO code. */
@Composable
internal fun countryName(code: String?): String {
    if (code.isNullOrBlank()) return ""
    val locale = androidx.compose.ui.platform.LocalConfiguration.current.locales[0] ?: AppLocale.java()
    if (locale.language == "ar") ARABIC_COUNTRY_NAMES[code.uppercase()]?.let { return it }
    return java.util.Locale("", code).getDisplayCountry(locale).ifBlank { code }
}

/** The short names people actually say ("السعودية", not "المملكة العربية السعودية"). */
private val ARABIC_COUNTRY_NAMES = mapOf(
    "SA" to "السعودية", "EG" to "مصر", "AE" to "الإمارات", "KW" to "الكويت", "QA" to "قطر", "BH" to "البحرين", "OM" to "عُمان",
    "JO" to "الأردن", "IQ" to "العراق", "LB" to "لبنان", "MA" to "المغرب", "DZ" to "الجزائر", "TN" to "تونس", "LY" to "ليبيا",
    "SD" to "السودان", "YE" to "اليمن", "SY" to "سوريا", "PS" to "فلسطين",
)

@Composable
/** [initialRecent] only seeds the list (previews / screenshot tests); the real screen loads its own. */
fun WalletHome(initialRecent: List<WalletTransactionDto>? = null) {
    val host = LocalWallet.current
    val scope = rememberCoroutineScope()
    var recent by remember { mutableStateOf(initialRecent) }
    var loading by remember { mutableStateOf(true) }
    val spin = remember { Animatable(0f) }

    fun load() {
        scope.launch {
            loading = true
            // No error card here on purpose: no-internet is covered by the
            // app-wide offline screen, and a reconnect auto-reloads through
            // the tick below — so a failed load just keeps the skeleton until
            // the next attempt (reconnect or the header refresh button).
            host.refreshBalance()
            val rows = runCatching { ApiClient.wallet.transactions(walletAuth(), page = 1, perPage = 5).data.orEmpty() }.getOrNull()
            if (rows != null) recent = rows else if (recent == null) recent = emptyList()
            loading = false
        }
    }

    // Reloads on reconnect and when I switch to another of my wallets.
    LaunchedEffect(collectReconnectTick(), com.dorr.app.network.WalletCountry.selected) { load() }
    LaunchedEffect(loading) {
        if (loading) {
            while (true) { spin.snapTo(0f); spin.animateTo(360f, tween(800, easing = LinearEasing)) }
        } else spin.snapTo(0f)
    }

    WaPage(
        title = stringResource(R.string.wa_my_wallet),
        onBack = { host.pop() },
        outlined = true,
        actions = {
            WaOutlineCircleButton(if (host.hideBalance) Icons.Rounded.VisibilityOff else Icons.Rounded.Visibility, { host.hideBalance = !host.hideBalance })
            Box(Modifier.rotate(spin.value)) { WaOutlineCircleButton(Icons.Rounded.Refresh, { load() }) }
        },
    ) {
        val balance = host.balance

        Box(Modifier.waRise(0)) {
            if (balance != null) {
                WaHeroCard(balance, host)
            } else {
                WaSkeleton(Modifier.fillMaxWidth().height(172.dp), RoundedCornerShape(24.dp))
            }
        }
        if (balance != null) {
            val current = balance.countryCode?.uppercase()
            val here = WalletCountry.here?.uppercase()
            if (current != null && here != null && current != here) {
                WaNote(stringResource(R.string.wa_wallet_away_note, countryName(current)), icon = Icons.Rounded.Info, modifier = Modifier.padding(top = 8.dp))
            }
        }

        Row(Modifier.fillMaxWidth().padding(top = 12.dp, bottom = 0.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            HomeAction(stringResource(R.string.wa_action_topup), 1, Modifier.weight(1f), { host.push(WaRoute.Topup) }) {
                Icon(painterResource(R.drawable.ic_ms_add_card), null, tint = Wa.Red, modifier = Modifier.size(22.dp))
            }
            HomeAction(Icons.Rounded.NorthEast, stringResource(R.string.wa_action_transfer), 2, Modifier.weight(1f)) { host.push(WaRoute.Transfer) }
            HomeAction(stringResource(R.string.wa_action_history), 3, Modifier.weight(1f), { host.push(WaRoute.History) }) {
                Icon(painterResource(R.drawable.ic_ms_credit_card_clock), null, tint = Wa.Red, modifier = Modifier.size(22.dp))
            }
            HomeAction(Icons.Rounded.Settings, stringResource(R.string.wa_action_pin), 4, Modifier.weight(1f)) { host.push(WaRoute.PinSettings) }
        }

        balance?.otherWallets?.takeIf { it.isNotEmpty() }?.let { others ->
            WaCard(Modifier.fillMaxWidth().padding(top = 8.dp).waRise(4), padding = 16.dp) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Icon(Icons.Rounded.Public, null, tint = Wa.Ink, modifier = Modifier.size(16.dp))
                    Text(stringResource(R.string.wa_other_wallets), fontWeight = FontWeight.ExtraBold, fontSize = 14.sp, color = Wa.Ink)
                }
                val context = LocalContext.current
                others.forEach { other ->
                    // A tap switches to that wallet: its number, QR and transfers (to its own country).
                    Row(
                        Modifier.fillMaxWidth().padding(top = 10.dp).clip(RoundedCornerShape(14.dp)).background(Wa.Field)
                            .clickable { com.dorr.app.network.WalletCountry.select(context, other.countryCode) }.padding(horizontal = 12.dp, vertical = 10.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        WalletFlag(other.countryCode)
                        Spacer(Modifier.width(10.dp))
                        Text(countryName(other.countryCode), color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                            Text(money(other.totalMinor) + " " + other.currencyCode.orEmpty(), color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                        }
                        Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, null, tint = Wa.Soft, modifier = Modifier.size(18.dp))
                    }
                }
                Text(stringResource(R.string.wa_other_wallets_note), color = Wa.Mut, fontSize = 11.5.sp, lineHeight = 20.sp)
            }
        }

        WaSectionTitle(stringResource(R.string.wa_recent), Modifier.waRise(5), topPadding = 12.dp) {
            Row(
                Modifier.clickable(interactionSource = remember { MutableInteractionSource() }, indication = null) { host.push(WaRoute.History) },
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(stringResource(R.string.wa_view_all), color = Wa.Red, fontSize = 13.sp, fontWeight = FontWeight.Bold)
                Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, null, tint = Wa.Red, modifier = Modifier.size(16.dp))
            }
        }

        val rows = recent
        WaCard(Modifier.fillMaxWidth().waRise(6)) {
            when {
                rows == null -> Column(Modifier.padding(horizontal = 14.dp)) { repeat(3) { WaTxSkeleton() } }
                rows.isEmpty() -> WaEmpty(
                    Icons.Rounded.AutoAwesome, Tone.Red, stringResource(R.string.wa_no_tx), stringResource(R.string.wa_no_tx_hint),
                    action = { WaButton(stringResource(R.string.wa_topup_now), { host.push(WaRoute.Topup) }, icon = Icons.Rounded.Add, modifier = Modifier.padding(horizontal = 40.dp)) },
                )
                else -> Column(Modifier.padding(horizontal = 14.dp, vertical = 4.dp)) {
                    rows.forEachIndexed { index, tx ->
                        WaTxRow(tx, host, withDay = false)
                        if (index < rows.lastIndex) WaDivider()
                    }
                }
            }
        }
    }
}

@Composable
private fun HomeAction(icon: ImageVector, label: String, index: Int, modifier: Modifier, onClick: () -> Unit) {
    HomeAction(label, index, modifier, onClick) {
        Icon(icon, null, tint = Wa.Red, modifier = Modifier.size(22.dp))
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

/** The balance card — same order as the reference: number + actions, total chip, amount, then the two parts. */
@Composable
private fun WaHeroCard(balance: WalletBalanceDto, host: WalletHost) {
    val context = LocalContext.current
    val currencyCode = balance.currencyCode.orEmpty()
    val hidden = host.hideBalance
    val number = balance.walletNumberFormatted ?: groupWalletNumber(balance.walletNumber.orEmpty())
    var pickWallet by remember { mutableStateOf(false) }
    val copiedText = stringResource(R.string.wa_number_copied)
    val shareText = stringResource(R.string.wa_qr_share_text, number)
    var copied by remember { mutableStateOf(false) }
    LaunchedEffect(copied) {
        if (copied) {
            delay(1600)
            copied = false
        }
    }

    val inspecting = LocalInspectionMode.current
    val shown = remember { Animatable(if (inspecting) balance.totalMinor.toFloat() else host.shownTotal.toFloat()) }
    LaunchedEffect(balance.totalMinor) {
        shown.animateTo(balance.totalMinor.toFloat(), tween(900))
        host.shownTotal = balance.totalMinor
    }

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
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text(
                            stringResource(R.string.wa_wallet_number),
                            color = Color.White.copy(alpha = 0.68f),
                            fontSize = 11.sp,
                            fontWeight = FontWeight.Medium,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                        )
                        WalletFlag(balance.countryCode, Modifier.size(width = 16.dp, height = 11.dp))
                    }
                    if (number.isNotBlank()) {
                        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                            Text(
                                number,
                                color = Color.White,
                                fontSize = 13.sp,
                                fontWeight = FontWeight.Bold,
                                maxLines = 1,
                                overflow = TextOverflow.Ellipsis,
                                modifier = Modifier.padding(top = 2.dp),
                            )
                        }
                    }
                }
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    Row(horizontalArrangement = Arrangement.spacedBy(7.dp)) {
                        HeroCircle(if (copied) Icons.Rounded.Check else Icons.Rounded.ContentCopy) {
                            val clipboard = context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
                            clipboard.setPrimaryClip(ClipData.newPlainText("wallet", number))
                            copied = true
                            host.showToast(copiedText)
                        }
                        HeroCircle(Icons.Outlined.QrCode2) { host.push(WaRoute.MyQr) }
                        HeroCircle(Icons.Rounded.Share) {
                            val send = Intent(Intent.ACTION_SEND).apply { type = "text/plain"; putExtra(Intent.EXTRA_TEXT, shareText) }
                            context.startActivity(Intent.createChooser(send, null))
                        }
                        HeroCircle(Icons.Rounded.SwapHoriz) { pickWallet = true }
                    }
                }
            }

            Box(Modifier.fillMaxWidth().padding(top = 10.dp, bottom = 10.dp).height(1.dp).background(Color.White.copy(alpha = 0.16f)))

            Row(
                Modifier
                    .clip(RoundedCornerShape(50))
                    .background(Color.White.copy(alpha = 0.12f))
                    .border(1.dp, Color.White.copy(alpha = 0.16f), RoundedCornerShape(50))
                    .padding(horizontal = 9.dp, vertical = 4.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.AccountBalanceWallet, null, tint = Color.White.copy(alpha = 0.9f), modifier = Modifier.size(12.dp))
                Spacer(Modifier.width(5.dp))
                Text(stringResource(R.string.wa_total_balance), color = Color.White.copy(alpha = 0.92f), fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
            }
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                Row(
                    Modifier.padding(top = 6.dp, bottom = 10.dp),
                    verticalAlignment = Alignment.Bottom,
                ) {
                    Text(
                        if (hidden) "******" else money(shown.value.toLong()),
                        color = Color.White, fontSize = 28.sp, fontWeight = FontWeight.ExtraBold, letterSpacing = (-0.3).sp,
                        maxLines = 1,
                    )
                    if (currencyCode.isNotBlank()) {
                        Spacer(Modifier.width(8.dp))
                        Text(currencyCode, color = Color.White.copy(alpha = 0.82f), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(bottom = 3.dp))
                    }
                }
            }

            Row(
                Modifier
                    .fillMaxWidth()
                    .clip(barShape)
                    .background(Color.White.copy(alpha = 0.12f))
                    .padding(vertical = 9.dp, horizontal = 4.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                HeroPart(
                    Icons.Rounded.Check,
                    stringResource(R.string.wa_withdrawable),
                    if (hidden) "****" else "${money(balance.withdrawableMinor)} $currencyCode",
                    Modifier.weight(1f),
                ) { host.openSheet(WaSheet.Explain) }
                Box(Modifier.width(1.dp).height(34.dp).background(Color.White.copy(alpha = 0.22f)))
                HeroPart(
                    Icons.Rounded.CardGiftcard,
                    stringResource(R.string.wa_spend_only),
                    if (hidden) "****" else "${money(balance.spendOnlyMinor)} $currencyCode",
                    Modifier.weight(1f),
                ) { host.openSheet(WaSheet.Explain) }
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
    if (pickWallet) WalletPickerSheet(balance) { pickWallet = false }
}

@Composable
private fun HeroCircle(icon: ImageVector, onClick: () -> Unit) {
    Box(
        Modifier
            .size(32.dp)
            .clip(CircleShape)
            .border(1.dp, Color.White.copy(alpha = 0.40f), CircleShape)
            .clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        Icon(icon, null, tint = Color.White, modifier = Modifier.size(16.dp))
    }
}

/** One half of the glass bar: icon + label, amount under it. */
@Composable
private fun HeroPart(icon: ImageVector, label: String, value: String, modifier: Modifier, onClick: () -> Unit) {
    val source = remember { MutableInteractionSource() }
    val scale by rememberPressScale(source, 0.96f)
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    Column(
        modifier
            .scale(scale)
            .clickable(interactionSource = source, indication = null, onClick = onClick)
            .padding(horizontal = 10.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(icon, null, tint = Color.White.copy(alpha = 0.92f), modifier = Modifier.size(15.dp))
            Spacer(Modifier.width(6.dp))
            Text(label, color = Color.White.copy(alpha = 0.86f), fontSize = 11.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
            Text(
                value, color = Color.White, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1,
                textAlign = if (rtl) TextAlign.Right else TextAlign.Left,
                modifier = Modifier.fillMaxWidth().padding(top = 6.dp),
            )
        }
    }
}

// ------------------------------------------------------------------------------- transactions

@Composable
internal fun WaTxSkeleton() {
    Row(Modifier.fillMaxWidth().padding(vertical = 12.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        WaSkeleton(Modifier.size(44.dp), RoundedCornerShape(15.dp))
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            WaSkeleton(Modifier.fillMaxWidth(0.55f).height(12.dp))
            WaSkeleton(Modifier.fillMaxWidth(0.35f).height(10.dp))
        }
        WaSkeleton(Modifier.width(60.dp).height(14.dp))
    }
}

/** One ledger row: tinted icon, title (+ "services only" badge), who/when, signed amount. */
@Composable
internal fun WaTxRow(tx: WalletTransactionDto, host: WalletHost, withDay: Boolean) {
    if (!withDay) {
        WaTxRowCompact(tx, host)
        return
    }
    val (icon, tone) = txMeta(tx.type)
    val credit = tx.direction == "credit"
    val party = tx.counterparty?.let { it.name?.takeIf(String::isNotBlank) ?: it.phone }
    val note = tx.note?.takeIf { it.isNotBlank() && it != tx.typeLabel }
    val subtitle = party ?: note ?: if (withDay) timeOf(tx.createdAt) else dayText(tx.createdAt) + " · " + timeOf(tx.createdAt)
    val currency = host.balance?.currencyCode.orEmpty()

    Row(
        Modifier
            .fillMaxWidth()
            .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null) { host.openSheet(WaSheet.Tx(tx)) }
            .padding(vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        WaIconWell(icon, tone)
        Column(Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Text(tx.typeLabel, fontSize = 14.sp, fontWeight = FontWeight.Bold, color = Wa.Ink, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false))
                if (tx.bucket == "spend_only") {
                    Text(
                        stringResource(R.string.wa_services_only_badge),
                        color = Wa.Red, fontSize = 10.sp, fontWeight = FontWeight.Bold,
                        modifier = Modifier.clip(RoundedCornerShape(7.dp)).background(if (walletNight()) com.dorr.app.ui.screens.AccountDark.well else Wa.Red.copy(alpha = 0.14f)).padding(horizontal = 7.dp, vertical = 2.dp),
                    )
                }
            }
            Text(subtitle, color = Wa.Mut, fontSize = 11.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 2.dp))
        }
        Column(horizontalAlignment = Alignment.End) {
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                Text((if (credit) "+ " else "− ") + money(tx.amountMinor), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, color = if (credit) Wa.Green else Wa.Danger)
            }
            Text(if (withDay) timeOf(tx.createdAt) else currency, color = Wa.Soft, fontSize = 11.sp, textAlign = TextAlign.End, modifier = Modifier.padding(top = 2.dp))
        }
    }
}

/**
 * The row used in the home list: a round brand-tinted icon, the type and its note, and at the end the
 * signed amount with the currency and the day/time stacked under it.
 */
@Composable
private fun WaTxRowCompact(tx: WalletTransactionDto, host: WalletHost) {
    val (icon, _) = txMeta(tx.type)
    val credit = tx.direction == "credit"
    val currency = host.balance?.currencyCode.orEmpty()

    Row(
        Modifier
            .fillMaxWidth()
            .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null) { host.openSheet(WaSheet.Tx(tx)) }
            .padding(vertical = 13.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        WaIconWell(icon, Tone.Red, size = 36.dp, iconSize = 16.dp)
        Column(Modifier.weight(1f)) {
            Text(tx.typeLabel, fontSize = 13.sp, fontWeight = FontWeight.Bold, color = Wa.Ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
            if (tx.bucket == "spend_only") {
                Text(
                    stringResource(R.string.wa_services_only_badge),
                    color = Wa.Red, fontSize = 10.sp, fontWeight = FontWeight.Bold,
                    modifier = Modifier
                        .padding(top = 3.dp)
                        .clip(RoundedCornerShape(7.dp))
                        .background(if (walletNight()) com.dorr.app.ui.screens.AccountDark.well else Wa.Red.copy(alpha = 0.14f))
                        .padding(horizontal = 7.dp, vertical = 2.dp),
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
