package com.dorr.app.ui.screens.wallet

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.keyframes
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
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
import androidx.compose.material.icons.rounded.ContentCopy
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.NorthEast
import androidx.compose.material.icons.rounded.PauseCircle
import androidx.compose.material.icons.rounded.Public
import androidx.compose.material.icons.rounded.QrCode2
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material.icons.rounded.VisibilityOff
import androidx.compose.material.icons.rounded.Warning
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
import com.dorr.app.network.WalletBalanceDto
import com.dorr.app.network.WalletTransactionDto
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** English/Arabic name of a wallet's country from its ISO code. */
@Composable
internal fun countryName(code: String?): String {
    if (code.isNullOrBlank()) return ""
    val locale = androidx.compose.ui.platform.LocalConfiguration.current.locales[0] ?: java.util.Locale.getDefault()
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
    var failure by remember { mutableStateOf<String?>(null) }
    var loading by remember { mutableStateOf(true) }
    val networkError = stringResource(R.string.wa_error_network)
    val spin = remember { Animatable(0f) }

    fun load() {
        scope.launch {
            loading = true
            failure = null
            val ok = host.refreshBalance()
            val rows = runCatching { ApiClient.wallet.transactions(walletAuth(), page = 1, perPage = 5).data.orEmpty() }.getOrNull()
            if (!ok) failure = networkError
            if (rows != null) recent = rows else if (recent == null) recent = emptyList()
            loading = false
        }
    }

    LaunchedEffect(Unit) { load() }
    LaunchedEffect(loading) {
        if (loading) {
            while (true) { spin.snapTo(0f); spin.animateTo(360f, tween(800, easing = LinearEasing)) }
        } else spin.snapTo(0f)
    }

    WaPage(
        title = stringResource(R.string.wa_wallet),
        onBack = { host.pop() },
        actions = {
            WaCircleButton(if (host.hideBalance) Icons.Rounded.VisibilityOff else Icons.Rounded.Visibility, { host.hideBalance = !host.hideBalance })
            Box(Modifier.rotate(spin.value)) { WaCircleButton(Icons.Rounded.Refresh, { load() }) }
        },
    ) {
        val balance = host.balance

        Box(Modifier.waRise(0)) {
            when {
                balance != null -> WaHeroCard(balance, host)
                failure != null -> WaCard(Modifier.fillMaxWidth()) {
                    WaEmpty(Icons.Rounded.Warning, Tone.Gray, stringResource(R.string.wa_load_failed), failure.orEmpty(), action = {
                        WaButton(stringResource(R.string.wa_retry), { load() }, style = WaButtonStyle.Ghost, icon = Icons.Rounded.Refresh, modifier = Modifier.padding(horizontal = 50.dp))
                    })
                }
                else -> WaSkeleton(Modifier.fillMaxWidth().height(196.dp), RoundedCornerShape(28.dp))
            }
        }

        if (balance?.walletNumber != null) MyNumberCard(balance, host)

        Row(Modifier.fillMaxWidth().padding(top = 18.dp, bottom = 6.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            HomeAction(Icons.Rounded.Add, Tone.Red, stringResource(R.string.wa_action_topup), 1, Modifier.weight(1f)) { host.push(WaRoute.Topup) }
            HomeAction(Icons.Rounded.NorthEast, Tone.Blue, stringResource(R.string.wa_action_transfer), 2, Modifier.weight(1f)) { host.push(WaRoute.Transfer) }
            HomeAction(Icons.Rounded.History, Tone.Amber, stringResource(R.string.wa_action_history), 3, Modifier.weight(1f)) { host.push(WaRoute.History) }
            HomeAction(Icons.Rounded.Lock, Tone.Gray, stringResource(R.string.wa_action_pin), 4, Modifier.weight(1f)) { host.push(WaRoute.PinSettings) }
        }

        balance?.otherWallets?.takeIf { it.isNotEmpty() }?.let { others ->
            WaCard(Modifier.fillMaxWidth().padding(top = 8.dp).waRise(4), padding = 16.dp) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Icon(Icons.Rounded.Public, null, tint = Wa.Ink, modifier = Modifier.size(16.dp))
                    Text(stringResource(R.string.wa_other_wallets), fontWeight = FontWeight.ExtraBold, fontSize = 14.sp, color = Wa.Ink)
                }
                others.forEach { other ->
                    WaKeyValue(other.countryCode.orEmpty(), money(other.totalMinor) + " " + other.currencyCode.orEmpty(), ltr = true)
                }
                Text(stringResource(R.string.wa_other_wallets_note), color = Wa.Mut, fontSize = 11.5.sp, lineHeight = 20.sp)
            }
        }

        WaSectionTitle(stringResource(R.string.wa_recent), Modifier.waRise(5)) {
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
private fun HomeAction(icon: ImageVector, tone: Tone, label: String, index: Int, modifier: Modifier, onClick: () -> Unit) {
    val source = remember { MutableInteractionSource() }
    val scale by rememberPressScale(source, 0.93f)
    Column(
        modifier
            .waRise(index)
            .scale(scale)
            .shadow(8.dp, RoundedCornerShape(20.dp), ambientColor = Color(0x14111928), spotColor = Color(0x1F111928))
            .clip(RoundedCornerShape(20.dp))
            .background(Color.White)
            .clickable(interactionSource = source, indication = null, onClick = onClick)
            .padding(top = 13.dp, bottom = 11.dp, start = 4.dp, end = 4.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        WaIconWell(icon, tone)
        Text(label, fontSize = 12.sp, fontWeight = FontWeight.Bold, color = Wa.Ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

/** The red gradient balance card: glass chips, the big count-up number, and the two balance parts. */
@Composable
private fun WaHeroCard(balance: WalletBalanceDto, host: WalletHost) {
    val currency = balance.currencyCode.orEmpty()
    val hidden = host.hideBalance

    // Count up from what was last shown to the current total.
    val inspecting = LocalInspectionMode.current
    val shown = remember { Animatable(if (inspecting) balance.totalMinor.toFloat() else host.shownTotal.toFloat()) }
    LaunchedEffect(balance.totalMinor) {
        shown.animateTo(balance.totalMinor.toFloat(), tween(900))
        host.shownTotal = balance.totalMinor
    }

    val transition = rememberInfiniteTransition(label = "shine")
    val shine by transition.animateFloat(
        initialValue = 0f, targetValue = 1f,
        animationSpec = infiniteRepeatable(keyframes { durationMillis = 6000; 0f at 1400; 1f at 3200; 1f at 6000 }, RepeatMode.Restart),
        label = "shineX",
    )

    Box(
        Modifier
            .fillMaxWidth()
            .shadow(22.dp, RoundedCornerShape(28.dp), ambientColor = Color(0x66E50914), spotColor = Color(0x99E50914))
            .clip(RoundedCornerShape(28.dp))
            .background(Wa.HeroBrush),
    ) {
        // Soft decorative discs.
        Box(Modifier.size(190.dp).offset(x = 60.dp, y = (-70).dp).align(Alignment.TopEnd).background(Color(0x17FFFFFF), CircleShape))
        Box(Modifier.size(130.dp).offset(x = (-30).dp, y = 60.dp).align(Alignment.BottomStart).background(Color(0x12FFFFFF), CircleShape))
        // A slow diagonal shine across the card.
        Box(
            Modifier
                .fillMaxSize()
                .graphicsLayer { translationX = (-0.6f + shine * 2.2f) * size.width }
                .background(Brush.horizontalGradient(listOf(Color.Transparent, Color(0x33FFFFFF), Color.Transparent)), RoundedCornerShape(0.dp))
                .width(120.dp),
        )

        Column(Modifier.padding(start = 20.dp, end = 20.dp, top = 20.dp, bottom = 18.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                WaGlassChip("${balance.countryCode.orEmpty()} · $currency", Icons.Rounded.Public)
                WaGlassChip(stringResource(R.string.wa_my_wallet), Icons.Rounded.AccountBalanceWallet)
            }
            Spacer(Modifier.height(18.dp))
            Text(stringResource(R.string.wa_total_balance), color = Color.White.copy(alpha = 0.85f), fontSize = 13.sp)
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                Row(Modifier.padding(top = 2.dp, bottom = 16.dp), verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(
                        if (hidden) "******" else money(shown.value.toLong()),
                        color = Color.White, fontSize = 40.sp, fontWeight = FontWeight.ExtraBold, letterSpacing = (-1).sp,
                    )
                    Text(currency, color = Color.White.copy(alpha = 0.85f), fontSize = 15.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(bottom = 7.dp))
                }
            }
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                HeroPart(Icons.Rounded.AccountBalance, stringResource(R.string.wa_withdrawable), if (hidden) "****" else money(balance.withdrawableMinor), Modifier.weight(1f)) { host.openSheet(WaSheet.Explain) }
                HeroPart(Icons.Rounded.CardGiftcard, stringResource(R.string.wa_spend_only), if (hidden) "****" else money(balance.spendOnlyMinor), Modifier.weight(1f)) { host.openSheet(WaSheet.Explain) }
            }
            if (balance.heldMinor > 0) {
                Row(Modifier.padding(top = 10.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Icon(Icons.Rounded.PauseCircle, null, tint = Color.White.copy(alpha = 0.92f), modifier = Modifier.size(16.dp))
                    Text(
                        stringResource(R.string.wa_held_temporarily) + " " + (if (hidden) "****" else money(balance.heldMinor)) + " " + currency,
                        color = Color.White.copy(alpha = 0.92f), fontSize = 12.sp,
                    )
                }
            }
        }
    }
}

@Composable
private fun HeroPart(icon: ImageVector, label: String, value: String, modifier: Modifier, onClick: () -> Unit) {
    val source = remember { MutableInteractionSource() }
    val scale by rememberPressScale(source, 0.96f)
    Column(
        modifier
            .scale(scale)
            .clip(RoundedCornerShape(16.dp))
            .background(Color(0x26FFFFFF))
            .clickable(interactionSource = source, indication = null, onClick = onClick)
            .padding(horizontal = 12.dp, vertical = 10.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(5.dp)) {
            Icon(icon, null, tint = Color.White.copy(alpha = 0.85f), modifier = Modifier.size(14.dp))
            Text(label, color = Color.White.copy(alpha = 0.85f), fontSize = 11.sp, maxLines = 1)
        }
        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
            Text(value, color = Color.White, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 3.dp), maxLines = 1)
        }
    }
}

/** "Your wallet number" with copy and QR — the number others send to. */
@Composable
private fun MyNumberCard(balance: WalletBalanceDto, host: WalletHost) {
    val context = LocalContext.current
    val number = balance.walletNumberFormatted ?: groupWalletNumber(balance.walletNumber.orEmpty())
    val copiedText = stringResource(R.string.wa_number_copied)
    var copied by remember { mutableStateOf(false) }

    LaunchedEffect(copied) {
        if (copied) {
            delay(1600)
            copied = false
        }
    }

    Row(
        Modifier
            .fillMaxWidth()
            .padding(top = 12.dp)
            .waRise(1)
            .shadow(8.dp, RoundedCornerShape(20.dp), ambientColor = Color(0x0D111928), spotColor = Color(0x14111928))
            .clip(RoundedCornerShape(20.dp))
            .background(Color.White)
            .padding(horizontal = 14.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        WaIconWell(Icons.Rounded.AccountBalanceWallet, Tone.Blue)
        Column(Modifier.weight(1f)) {
            Text(
                stringResource(R.string.wa_my_number_caption, countryName(balance.countryCode)),
                color = Wa.Mut, fontSize = 11.5.sp, lineHeight = 18.sp,
            )
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                Text(number, color = Wa.Ink, fontSize = 19.sp, fontWeight = FontWeight.ExtraBold, letterSpacing = 1.5.sp, modifier = Modifier.padding(top = 3.dp))
            }
        }
        WaCircleButton(Icons.Rounded.QrCode2, { host.push(WaRoute.MyQr) })
        WaCircleButton(
            if (copied) Icons.Rounded.Check else Icons.Rounded.ContentCopy,
            {
                val clipboard = context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
                clipboard.setPrimaryClip(ClipData.newPlainText("wallet", number))
                copied = true
                host.showToast(copiedText)
            },
            tint = if (copied) Color.White else Wa.Ink,
            background = if (copied) Wa.Green else Color.White,
        )
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
                        modifier = Modifier.clip(RoundedCornerShape(7.dp)).background(Color(0xFFFDE8EC)).padding(horizontal = 7.dp, vertical = 2.dp),
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
