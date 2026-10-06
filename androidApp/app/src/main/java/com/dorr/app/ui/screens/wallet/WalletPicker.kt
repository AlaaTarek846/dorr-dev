package com.dorr.app.ui.screens.wallet

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.MyLocation
import androidx.compose.material.icons.rounded.SwapHoriz
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.AuthSession
import com.dorr.app.network.WalletBalanceDto
import com.dorr.app.network.WalletCountry
import kotlinx.coroutines.launch

/*
 * Which of my wallets I'm using (one per country, docs/wallet-plan.md §7). The one of where I am
 * opens by default; my other wallets (a Saudi one while I'm in Egypt…) are a tap away — chosen,
 * it shows its own number and QR, receives, and sends to its own country's numbers and wallets.
 */

/** A country's flag (the same CDN the phone fields use). */
@Composable
internal fun WalletFlag(code: String?, modifier: Modifier = Modifier) {
    if (code.isNullOrBlank()) return
    AsyncImage(
        model = "https://flagcdn.com/w80/${code.lowercase()}.png", contentDescription = null, contentScale = ContentScale.Crop,
        modifier = modifier.size(width = 26.dp, height = 19.dp).clip(RoundedCornerShape(4.dp)),
    )
}

/**
 * "Wallet: 🇪🇬 Egypt · EGP — change". When it isn't the wallet of where I am, a note says what
 * that means: it pays its own country's numbers and wallets only.
 */
@Composable
internal fun WalletPickerCard(balance: WalletBalanceDto, modifier: Modifier = Modifier) {
    var open by remember { mutableStateOf(false) }
    val source = remember { MutableInteractionSource() }
    val press by rememberPressScale(source, 0.98f)
    val current = balance.countryCode?.uppercase()
    val here = WalletCountry.here?.uppercase()
    val away = current != null && here != null && current != here

    Column(modifier.fillMaxWidth()) {
        Row(
            Modifier.fillMaxWidth().scale(press).clip(RoundedCornerShape(18.dp)).background(Wa.Surface)
                .border(1.dp, if (away) Wa.Red.copy(alpha = 0.35f) else Wa.Line, RoundedCornerShape(18.dp))
                .clickable(interactionSource = source, indication = null) { open = true }.padding(horizontal = 14.dp, vertical = 11.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            WalletFlag(current)
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.wa_wallet_of, countryName(current)), color = Wa.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(
                    if (away) stringResource(R.string.wa_wallet_away, countryName(here)) else stringResource(R.string.wa_wallet_here_sub, balance.currencyCode.orEmpty()),
                    color = if (away) Wa.Red else Wa.Mut, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis,
                )
            }
            Row(
                Modifier.clip(CircleShape).background(Wa.Red.copy(alpha = 0.1f)).padding(horizontal = 10.dp, vertical = 6.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.SwapHoriz, null, tint = Wa.Red, modifier = Modifier.size(16.dp))
                Spacer(Modifier.width(4.dp))
                Text(stringResource(R.string.wa_wallet_change), color = Wa.Red, fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold)
            }
        }
        AnimatedVisibility(away) {
            WaNote(stringResource(R.string.wa_wallet_away_note, countryName(current)), icon = Icons.Rounded.Info, modifier = Modifier.padding(top = 8.dp))
        }
    }
    if (open) WalletPickerSheet(balance) { open = false }
}

/** My wallets: the one of where I am first, then the others (with their balances), then my number's country. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun WalletPickerSheet(balance: WalletBalanceDto, onDismiss: () -> Unit) {
    val host = LocalWallet.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val current = balance.countryCode?.uppercase()
    val here = WalletCountry.here?.uppercase()
    val others = balance.otherWallets.orEmpty().associateBy { it.countryCode?.uppercase() }
    val phoneCountry = AuthSession.user?.country?.code?.uppercase()
    val options = listOfNotNull(here, current, *others.keys.toTypedArray(), phoneCountry).distinct()

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Text(stringResource(R.string.wa_wallet_pick_title), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.wa_wallet_pick_sub), color = Wa.Mut, fontSize = 12.5.sp, lineHeight = 19.sp)
            Spacer(Modifier.height(12.dp))
            options.forEachIndexed { i, code ->
                val on = code == current
                val other = others[code]
                val amount = when {
                    on -> money(balance.totalMinor) + " " + balance.currencyCode.orEmpty()
                    other != null -> money(other.totalMinor) + " " + other.currencyCode.orEmpty()
                    else -> stringResource(R.string.wa_wallet_new)
                }
                Row(
                    Modifier.fillMaxWidth().padding(bottom = 8.dp).waRise(i).clip(RoundedCornerShape(18.dp))
                        .background(if (on) Wa.Red.copy(alpha = 0.08f) else Wa.Field)
                        .border(1.5.dp, if (on) Wa.Red else Color.Transparent, RoundedCornerShape(18.dp))
                        .clickable {
                            if (!on) {
                                WalletCountry.select(context, code)
                                scope.launch { host.refreshBalance() }
                            }
                            onDismiss()
                        }.padding(14.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    WalletFlag(code)
                    Spacer(Modifier.width(12.dp))
                    Column(Modifier.weight(1f)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(countryName(code), color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                            if (code == here) {
                                Spacer(Modifier.width(6.dp))
                                Icon(Icons.Rounded.MyLocation, null, tint = Wa.Mut, modifier = Modifier.size(13.dp))
                                Text(" " + stringResource(R.string.wa_wallet_you_are_here), color = Wa.Mut, fontSize = 11.sp)
                            }
                        }
                        Text(amount, color = Wa.Mut, fontSize = 12.5.sp)
                    }
                    if (on) Icon(Icons.Rounded.CheckCircle, null, tint = Wa.Red, modifier = Modifier.size(22.dp))
                }
            }
            Box(Modifier.fillMaxWidth().padding(top = 4.dp)) {
                Text(stringResource(R.string.wa_wallet_rule), color = Wa.Soft, fontSize = 11.5.sp, lineHeight = 18.sp)
            }
        }
    }
}
