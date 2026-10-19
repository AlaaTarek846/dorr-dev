package com.dorr.app.ui.screens.rides

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowLeft
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.CreditCard
import androidx.compose.material.icons.rounded.LocalOffer
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.RideQuoteDto
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.theme.AppColors

/** The price the rider is asked for with the chosen category (fixed, the estimate's top, or their own offer). */
private fun RideQuoteDto.askedMinor(riderPrice: Long?): Long =
    bid?.let { riderPrice ?: it.suggestedMinor } ?: totalMinor ?: estimate?.maxMinor ?: holdMinor ?: 0L

/**
 * O-37, O-38a, O-38b: one row on the booking sheet — how I pay (cash by default, the wallet's balance
 * with it when there is one) and the coupon — that opens [PaymentSheet] to change either, any time.
 */
@Composable
internal fun PaymentRow(state: RidesState, quote: RideQuoteDto) {
    var open by remember { mutableStateOf(false) }
    val accent = settingsAccent()
    val asked = quote.askedMinor(state.riderPrice)
    val walletPart = if (state.payment == "cash" && state.useWallet) minOf(quote.walletAvailableMinor, asked) else 0L

    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(settingsBackground())
            .border(BorderStroke(1.dp, accent.copy(alpha = 0.18f)), RoundedCornerShape(16.dp))
            .pressable { open = true }.padding(horizontal = 12.dp, vertical = 11.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(38.dp).clip(RoundedCornerShape(12.dp)).background(accent.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
            Icon(methodIcon(state.payment), null, tint = accent, modifier = Modifier.size(20.dp))
        }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(stringResource(R.string.rd_pay_with), color = settingsMut(), fontSize = 11.sp)
            Text(
                when {
                    walletPart > 0 && walletPart >= asked -> stringResource(R.string.rd_pay_wallet_covers, money(walletPart, quote.currency))
                    walletPart > 0 -> stringResource(R.string.rd_pay_wallet_plus_cash, money(walletPart, quote.currency), money(asked - walletPart, quote.currency))
                    else -> methodName(state.payment)
                },
                color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 14.sp,
            )
            quote.coupon?.takeIf { it.applied }?.let {
                Text(stringResource(R.string.rd_promo_saved, money(it.discountMinor, quote.currency)), color = AppColors.success, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
            }
        }
        Text(stringResource(R.string.rd_change), color = accent, fontWeight = FontWeight.Bold, fontSize = 13.sp)
        Icon(Icons.AutoMirrored.Rounded.KeyboardArrowLeft, null, tint = accent, modifier = Modifier.size(18.dp))
    }

    if (open) PaymentSheet(state, quote, onClose = { open = false })
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun PaymentSheet(state: RidesState, quote: RideQuoteDto, onClose: () -> Unit) {
    val accent = settingsAccent()
    val asked = quote.askedMinor(state.riderPrice)
    val wallet = quote.walletAvailableMinor
    val fee = { m: String -> quote.paymentFee(m).takeIf { it > 0 }?.let { "+" + money(it, quote.currency) } }

    ModalBottomSheet(onDismissRequest = onClose, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = settingsCard()) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 18.dp).padding(bottom = 18.dp).navigationBarsPadding()) {
            Text(stringResource(R.string.rd_pay_title), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
            Spacer(Modifier.height(12.dp))

            // Cash first — the default. With money in the wallet, it goes first and the rest is cash.
            if (state.feature("cash") && quote.category.cashAllowed != false) {
                MethodOption(Icons.Rounded.Payments, methodName("cash"), null, fee("cash"), state.payment == "cash") { state.payment = "cash" }
                AnimatedVisibility(state.payment == "cash" && wallet > 0 && state.feature("wallet"), enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
                    val part = minOf(wallet, asked)
                    Row(
                        Modifier.fillMaxWidth().padding(start = 12.dp, top = 2.dp, bottom = 6.dp).clip(RoundedCornerShape(14.dp))
                            .background(AppColors.success.copy(alpha = 0.08f)).padding(horizontal = 12.dp, vertical = 8.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(Icons.Rounded.AccountBalanceWallet, null, tint = AppColors.success, modifier = Modifier.size(20.dp))
                        Spacer(Modifier.width(8.dp))
                        Column(Modifier.weight(1f)) {
                            Text(stringResource(R.string.rd_use_wallet_too, money(wallet, quote.currency)), color = settingsInk(), fontWeight = FontWeight.SemiBold, fontSize = 13.sp)
                            if (state.useWallet) {
                                Text(
                                    if (part >= asked) stringResource(R.string.rd_pay_wallet_covers, money(part, quote.currency))
                                    else stringResource(R.string.rd_pay_wallet_plus_cash, money(part, quote.currency), money(asked - part, quote.currency)),
                                    color = AppColors.success, fontSize = 12.sp,
                                )
                            }
                        }
                        Switch(
                            checked = state.useWallet, onCheckedChange = { state.useWallet = it },
                            colors = SwitchDefaults.colors(checkedThumbColor = Color.White, checkedTrackColor = AppColors.success),
                        )
                    }
                }
            }
            // The wallet alone: shown when it holds money.
            if (state.feature("wallet") && wallet > 0) {
                MethodOption(Icons.Rounded.AccountBalanceWallet, methodName("wallet"), stringResource(R.string.rd_wallet_free, money(wallet, quote.currency)), fee("wallet"), state.payment == "wallet") { state.payment = "wallet" }
            }
            if (state.feature("card")) {
                MethodOption(Icons.Rounded.CreditCard, methodName("card"), null, fee("card"), state.payment == "card") { state.payment = "card" }
            }

            // The coupon, in the same sheet — applied whenever, the prices follow.
            Spacer(Modifier.height(14.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.LocalOffer, null, tint = accent, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(6.dp))
                Text(stringResource(R.string.rd_promo_title), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 14.sp)
            }
            Spacer(Modifier.height(8.dp))
            PromoCodeRow(state)

            Spacer(Modifier.height(14.dp))
            AccentButton(stringResource(R.string.rd_done)) { onClose() }
        }
    }
}

@Composable
private fun MethodOption(icon: ImageVector, title: String, subtitle: String?, fee: String?, selected: Boolean, onClick: () -> Unit) {
    val accent = settingsAccent()
    Row(
        Modifier.fillMaxWidth().padding(vertical = 4.dp).clip(RoundedCornerShape(16.dp))
            .border(BorderStroke(if (selected) 2.dp else 1.dp, if (selected) accent else settingsInk().copy(alpha = 0.1f)), RoundedCornerShape(16.dp))
            .background(if (selected) accent.copy(alpha = 0.06f) else Color.Transparent)
            .pressable(onClick = onClick).padding(horizontal = 12.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Box(Modifier.size(38.dp).clip(RoundedCornerShape(12.dp)).background(accent.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = accent, modifier = Modifier.size(20.dp))
        }
        Column(Modifier.weight(1f)) {
            Text(title, color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 15.sp)
            subtitle?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
            fee?.let { Text(it, color = AppColors.warning, fontSize = 11.sp, fontWeight = FontWeight.Bold) }
        }
        Icon(if (selected) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (selected) accent else settingsMut(), modifier = Modifier.size(22.dp))
    }
}

private fun methodIcon(method: String): ImageVector = when (method) {
    "wallet" -> Icons.Rounded.AccountBalanceWallet
    "card" -> Icons.Rounded.CreditCard
    else -> Icons.Rounded.Payments
}

@Composable
private fun methodName(method: String): String = stringResource(
    when (method) {
        "wallet" -> R.string.rd_pay_wallet
        "card" -> R.string.rd_pay_card
        else -> R.string.rd_pay_cash
    },
)
