package com.dorr.app.ui.screens.wallet

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
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
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AccountBalance
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.CardGiftcard
import androidx.compose.material.icons.rounded.PauseCircle
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.material.icons.rounded.Info
import com.dorr.app.R

/**
 * Bottom sheets over the wallet pages: dim backdrop, a panel that slides up with a grab handle.
 * Tapping the backdrop closes it, exactly like the preview.
 */
@Composable
fun WaSheetHost(host: WalletHost) {
    val open = host.sheet != null
    // Keep the last content while the panel slides away, so it doesn't collapse mid-animation.
    var lastSheet by remember { mutableStateOf<WaSheet?>(null) }
    if (host.sheet != null) lastSheet = host.sheet

    AnimatedVisibility(visible = open, enter = fadeIn(tween(250)), exit = fadeOut(tween(250))) {
        Box(
            Modifier
                .fillMaxSize()
                .background(Color(0x80111928))
                .clickable(interactionSource = MutableInteractionSource(), indication = null) { host.closeSheet() },
        )
    }
    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.BottomCenter) {
        AnimatedVisibility(
            visible = open,
            enter = slideInVertically(tween(380)) { it } + fadeIn(tween(200)),
            exit = slideOutVertically(tween(280)) { it } + fadeOut(tween(200)),
        ) {
            Column(
                Modifier
                    .fillMaxWidth()
                    .heightIn(max = 620.dp)
                    .clip(RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp))
                    .background(Color.White)
                    .clickable(interactionSource = MutableInteractionSource(), indication = null) {}
                    .navigationBarsPadding()
                    .verticalScroll(rememberScrollState())
                    .padding(start = 20.dp, end = 20.dp, top = 10.dp, bottom = 26.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                Box(Modifier.width(42.dp).height(5.dp).clip(RoundedCornerShape(3.dp)).background(Color(0xFFE5E7EB)))
                Spacer(Modifier.height(16.dp))
                when (val sheet = lastSheet) {
                    WaSheet.Explain -> ExplainSheet(host)
                    is WaSheet.Tx -> TxSheet(sheet.tx, host)
                    is WaSheet.Pin -> WaPinSheetContent(sheet, host)
                    null -> Unit
                }
            }
        }
    }
}

@Composable
private fun ExplainRow(icon: androidx.compose.ui.graphics.vector.ImageVector, tone: Tone, title: String, text: String) {
    Row(Modifier.fillMaxWidth().padding(vertical = 12.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        WaIconWell(icon, tone)
        Column {
            Text(title, fontWeight = FontWeight.ExtraBold, fontSize = 14.sp, color = Wa.Ink)
            Text(text, color = Wa.Mut, fontSize = 12.5.sp, lineHeight = 22.sp)
        }
    }
}

@Composable
private fun ExplainSheet(host: WalletHost) {
    Column(Modifier.fillMaxWidth()) {
        Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
            WaIconWell(Icons.Rounded.AccountBalanceWallet, Tone.Red, size = 60.dp, iconSize = 28.dp)
            Spacer(Modifier.height(10.dp))
            Text(stringResource(R.string.wa_explain_title), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp, color = Wa.Ink)
        }
        ExplainRow(Icons.Rounded.AccountBalance, Tone.Green, stringResource(R.string.wa_withdrawable), stringResource(R.string.wa_explain_withdrawable))
        ExplainRow(Icons.Rounded.CardGiftcard, Tone.Pink, stringResource(R.string.wa_spend_only), stringResource(R.string.wa_explain_spend_only))
        ExplainRow(Icons.Rounded.PauseCircle, Tone.Amber, stringResource(R.string.wa_held), stringResource(R.string.wa_explain_held))
        Spacer(Modifier.height(8.dp))
        WaButton(stringResource(R.string.wa_understood), onClick = { host.closeSheet() })
    }
}

@Composable
private fun TxSheet(tx: com.dorr.app.network.WalletTransactionDto, host: WalletHost) {
    val (icon, tone) = txMeta(tx.type)
    val credit = tx.direction == "credit"
    val spend = tx.bucket == "spend_only"
    val currency = host.balance?.currencyCode.orEmpty()

    Column(Modifier.fillMaxWidth()) {
        Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
            WaIconWell(icon, tone, size = 60.dp, iconSize = 28.dp)
            Spacer(Modifier.height(10.dp))
            Text(tx.typeLabel, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp, color = Wa.Ink, textAlign = TextAlign.Center)
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                Text(
                    (if (credit) "+ " else "− ") + money(tx.amountMinor) + " " + currency,
                    fontWeight = FontWeight.ExtraBold, fontSize = 26.sp, color = if (credit) Wa.Green else Wa.Danger,
                    modifier = Modifier.padding(top = 6.dp),
                )
            }
        }
        Spacer(Modifier.height(14.dp))
        WaKeyValue(stringResource(R.string.wa_date), dayText(tx.createdAt) + " · " + timeOf(tx.createdAt))
        WaDivider()
        WaKeyValue(stringResource(R.string.wa_bucket), stringResource(if (spend) R.string.wa_spend_only else R.string.wa_withdrawable))
        WaDivider()
        WaKeyValue(stringResource(R.string.wa_balance_after), money(tx.balanceAfterMinor) + " " + currency, ltr = true)
        tx.counterparty?.let { party ->
            WaDivider()
            WaKeyValue(
                stringResource(if (credit) R.string.wa_from else R.string.wa_to),
                // The masked phone is isolated so its dots and digits keep their order inside Arabic text.
                listOfNotNull(party.name, party.phone?.let { "\u2066" + it + "\u2069" }).joinToString("  "),
            )
        }
        val note = tx.note
        if (!note.isNullOrBlank() && note != tx.typeLabel) {
            WaDivider()
            WaKeyValue(stringResource(R.string.wa_note), note)
        }
        WaDivider()
        WaKeyValue(stringResource(R.string.wa_tx_number), tx.uuid.take(8), valueColor = Wa.Soft)
        if (spend && credit) {
            Spacer(Modifier.height(12.dp))
            WaNote(stringResource(R.string.wa_spend_only_received_note), icon = Icons.Rounded.Info)
        }
        Spacer(Modifier.height(14.dp))
        WaButton(stringResource(R.string.wa_close), onClick = { host.closeSheet() }, style = WaButtonStyle.Ghost)
    }
}

/** "اليوم" / "أمس" / a long date. */
@Composable
internal fun dayText(iso: String?): String = when (daysAgo(iso)) {
    0L -> stringResource(R.string.wa_today)
    1L -> stringResource(R.string.wa_yesterday)
    else -> longDate(iso)
}
