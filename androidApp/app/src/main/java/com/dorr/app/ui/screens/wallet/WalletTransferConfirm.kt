package com.dorr.app.ui.screens.wallet

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.NorthEast
import androidx.compose.material.icons.rounded.Phone
import androidx.compose.material.icons.rounded.Public
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.TransferRecipientDto
import com.dorr.app.network.TransferRequest
import com.dorr.app.network.WalletTransactionDto
import com.dorr.app.network.apiFailure
import kotlinx.coroutines.CancellationException
import java.util.UUID

/**
 * Step 2: *who is this?* — the recipient's name (first letter of each word, the rest hidden) and the
 * number that was typed, shown BEFORE any amount is chosen or any money moves — then the amount and
 * the PIN. The transfer only accepts the token the server issued at step 1, so what is on screen is
 * provably who gets paid.
 */
@Composable
fun WalletTransferConfirm(who: TransferRecipientDto) {
    val host = LocalWallet.current
    val balance = host.balance
    val currency = balance?.currencyCode.orEmpty()
    val genericError = stringResource(R.string.wa_error_generic)
    val networkError = stringResource(R.string.wa_error_network)
    val expiredText = stringResource(R.string.wa_transfer_expired)
    val pinSubtitle = stringResource(R.string.wa_pin_transfer_sub)

    var amountText by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var idempotencyKey by remember { mutableStateOf(UUID.randomUUID().toString()) }
    var sent by remember { mutableStateOf<WalletTransactionDto?>(null) }
    val amountMinor = parseAmountToMinor(amountText)

    val done = sent
    if (done != null) {
        WaPage(title = stringResource(R.string.wa_transfer_confirm_title), onBack = { host.pop() }, scroll = false) {
            Box(Modifier.fillMaxWidth().weight(1f)) {
                WaConfetti()
                WaStatusColumn {
                    WaSeal()
                    WaStatusTitle(stringResource(R.string.wa_transfer_done_title))
                    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                        Row(verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            Text(money(done.amountMinor), fontSize = 34.sp, fontWeight = FontWeight.ExtraBold, color = Wa.Ink, letterSpacing = (-0.5).sp)
                            Text(currency, fontSize = 15.sp, fontWeight = FontWeight.Bold, color = Wa.Mut, modifier = Modifier.padding(bottom = 6.dp))
                        }
                    }
                    Text(stringResource(R.string.wa_transfer_done_to, who.nameMasked), color = Wa.Mut, fontSize = 14.sp, modifier = Modifier.padding(top = 6.dp, bottom = 16.dp))
                    WaButton(stringResource(R.string.wa_done), onClick = { host.pop(); host.pop() }, modifier = Modifier.padding(horizontal = 20.dp))
                }
            }
        }
        return
    }

    WaPage(
        title = stringResource(R.string.wa_transfer_confirm_title),
        onBack = { host.pop() },
        cta = {
            WaButton(
                text = if (amountMinor != null) stringResource(R.string.wa_send_amount, money(amountMinor), currency) else stringResource(R.string.wa_send),
                onClick = {
                    error = null
                    host.requestPin(
                        subtitle = pinSubtitle,
                        onError = { error = it },
                        onSubmit = { pin ->
                            val minor = amountMinor ?: return@requestPin PinOutcome.Close(genericError)
                            try {
                                val result = ApiClient.wallet.transfer(walletAuth(), pin, idempotencyKey, TransferRequest(who.recipientToken, minor)).data
                                idempotencyKey = UUID.randomUUID().toString()
                                if (result == null) return@requestPin PinOutcome.Close(genericError)
                                sent = result
                                host.refreshBalance()
                                PinOutcome.Done
                            } catch (e: CancellationException) {
                                throw e
                            } catch (e: Exception) {
                                val failure = e.apiFailure()
                                // No HTTP answer: keep the key so a retry can't send twice.
                                if (failure.httpStatus == null) return@requestPin PinOutcome.Retry(networkError)
                                idempotencyKey = UUID.randomUUID().toString()
                                val message = failure.message ?: genericError
                                when {
                                    failure.errorCode in PIN_ERROR_CODES -> PinOutcome.Retry(message)
                                    // The 10-minute confirmation ran out: go back and look the person up again.
                                    failure.errorCode == "transfer_recipient_expired" -> {
                                        host.closeSheet()
                                        host.pop()
                                        host.showToast(expiredText)
                                        PinOutcome.Done
                                    }
                                    else -> PinOutcome.Close(message)
                                }
                            }
                        },
                    )
                },
                enabled = amountMinor != null,
                icon = Icons.Rounded.NorthEast,
            )
            WaCtaHint(stringResource(R.string.wa_protected_by_pin), Icons.Rounded.Shield)
        },
    ) {
        RecipientCard(who, Modifier.waRise(0))
        Spacer(Modifier.height(12.dp))
        WaAmountEntry(
            label = stringResource(R.string.wa_transfer_how_much), amountText = amountText, onAmountChange = { amountText = it },
            currency = currency, invalid = amountText.isNotBlank() && amountMinor == null, autoFocus = false,
            modifier = Modifier.waRise(1),
            below = {
                if (balance != null) {
                    Row(Modifier.padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Rounded.AccountBalanceWallet, null, tint = Wa.Mut, modifier = Modifier.size(15.dp))
                        Text(stringResource(R.string.wa_available), color = Wa.Mut, fontSize = 12.5.sp)
                        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                            Text(money(balance.totalMinor - balance.heldMinor) + " " + currency, color = Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold)
                        }
                    }
                }
            },
        )
        Spacer(Modifier.height(12.dp))
        WaNote(stringResource(R.string.wa_spend_only_transfer_note), Modifier.waRise(2), icon = Icons.Rounded.Info)
        WaError(error)
    }
}

/** The "is this who you mean?" card: verified badge, avatar, masked name, number, country chips. */
@Composable
private fun RecipientCard(who: TransferRecipientDto, modifier: Modifier = Modifier) {
    val initial = who.nameMasked.trim().firstOrNull()?.toString().orEmpty()
    val line = who.phone?.let { if (it.startsWith("+")) it else it } ?: groupWalletNumber(who.walletNumber.orEmpty())
    val lineIcon = if (who.phone != null) Icons.Rounded.Phone else Icons.Rounded.AccountBalanceWallet

    Box(
        modifier
            .fillMaxWidth()
            .shadow(10.dp, Wa.CardShape, ambientColor = Color(0x1A111928), spotColor = Color(0x26111928))
            .clip(Wa.CardShape)
            .background(Color.White),
    ) {
        Box(Modifier.fillMaxWidth().height(84.dp).background(Brush.linearGradient(listOf(Color(0xFFFDE8EC), Color(0xFFFFF5F6)))))
        Column(Modifier.fillMaxWidth().padding(start = 18.dp, end = 18.dp, top = 22.dp, bottom = 20.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Row(
                Modifier.clip(RoundedCornerShape(999.dp)).background(Color(0xFFE5F6EC)).padding(horizontal = 12.dp, vertical = 5.dp),
                verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp),
            ) {
                Icon(Icons.Rounded.Shield, null, tint = Color(0xFF15803D), modifier = Modifier.size(15.dp))
                Text(stringResource(R.string.wa_verify_badge), color = Color(0xFF15803D), fontSize = 11.5.sp, fontWeight = FontWeight.ExtraBold)
            }
            Spacer(Modifier.height(14.dp))
            Box(contentAlignment = Alignment.Center) {
                Box(Modifier.size(84.dp).border(3.dp, Color(0x33E50914), CircleShape))
                Box(
                    Modifier.size(72.dp).shadow(10.dp, CircleShape, ambientColor = Color(0x59E50914), spotColor = Color(0x59E50914)).clip(CircleShape).background(Wa.ButtonBrush),
                    contentAlignment = Alignment.Center,
                ) { Text(initial, color = Color.White, fontSize = 30.sp, fontWeight = FontWeight.ExtraBold) }
            }
            Text(who.nameMasked, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold, color = Wa.Ink, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 12.dp))
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                Row(
                    Modifier.padding(top = 10.dp).clip(RoundedCornerShape(14.dp)).background(Color(0xFFF3F4F6)).padding(horizontal = 14.dp, vertical = 8.dp),
                    verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    Text(line, color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, letterSpacing = 0.5.sp)
                    Icon(lineIcon, null, tint = Wa.Mut, modifier = Modifier.size(16.dp))
                }
            }
            Row(Modifier.padding(top = 12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                WaGlassChip(countryName(who.countryCode), Icons.Rounded.Public, dark = true)
                WaGlassChip(who.currencyCode.orEmpty(), Icons.Rounded.AccountBalanceWallet, dark = true)
            }
        }
    }
}
