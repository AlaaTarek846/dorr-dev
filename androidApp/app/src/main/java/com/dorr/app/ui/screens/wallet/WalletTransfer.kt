package com.dorr.app.ui.screens.wallet

import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Phone
import androidx.compose.material.icons.rounded.Public
import androidx.compose.material.icons.rounded.QrCodeScanner
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.TransferLookupRequest
import com.dorr.app.network.TransferRecipientDto
import com.dorr.app.network.apiFailure
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

private enum class TransferMode { PHONE, WALLET }

/** Arabic-Indic / Persian digits → ASCII, everything else that isn't a digit dropped. */
internal fun asciiDigits(text: String): String = buildString {
    for (c in text) {
        when (c) {
            in '0'..'9' -> append(c)
            in '٠'..'٩' -> append('0' + (c - '٠'))
            in '۰'..'۹' -> append('0' + (c - '۰'))
        }
    }
}

/**
 * What was typed → the national number this country expects (dial code and a leading 0 dropped).
 * Mirrors the server's rule — the server stays the judge, this is live feedback while typing.
 */
internal fun nationalPhone(raw: String, dialCode: String?, length: Int?): String {
    var digits = asciiDigits(raw)
    val dial = dialCode.orEmpty().trimStart('+')
    if (length != null && dial.isNotEmpty() && digits.length == dial.length + length && digits.startsWith(dial)) digits = digits.substring(dial.length)
    if (length != null && digits.length == length + 1 && digits.startsWith("0")) digits = digits.substring(1)
    return digits
}

internal enum class FieldState { Empty, Typing, Ok, Bad }

internal fun phoneState(digits: String, length: Int?, prefix: String?): FieldState = when {
    digits.isEmpty() -> FieldState.Empty
    !prefix.isNullOrEmpty() && digits.length >= prefix.length && !digits.startsWith(prefix) -> FieldState.Bad
    length != null && digits.length > length -> FieldState.Bad
    length != null && digits.length < length -> FieldState.Typing
    else -> FieldState.Ok
}

/** 11 digits, the last one a Luhn check digit — catches a mistyped number before asking the server. */
internal fun isValidWalletNumber(digits: String): Boolean {
    if (digits.length != 11 || !digits.all { it.isDigit() }) return false
    var sum = 0
    digits.reversed().forEachIndexed { index, c ->
        var n = c - '0'
        if (index % 2 == 1) {
            n *= 2
            if (n > 9) n -= 9
        }
        sum += n
    }
    return sum % 10 == 0
}

/**
 * Step 1 of a transfer: find the recipient by phone or wallet number (or scan their QR). Nothing
 * moves here — the next page shows who it is, masked, before any amount is chosen.
 */
@Composable
fun WalletTransfer() {
    val host = LocalWallet.current
    val scope = rememberCoroutineScope()
    val balance = host.balance
    val networkError = stringResource(R.string.wa_error_network)
    val country = countryName(balance?.countryCode)

    var mode by remember { mutableStateOf(TransferMode.PHONE) }
    var phoneRaw by remember { mutableStateOf("") }
    var walletRaw by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    val national = nationalPhone(phoneRaw, balance?.dialCode, balance?.phoneLength)
    val phoneOk = phoneState(national, balance?.phoneLength, balance?.phoneStartsWith)
    val walletDigits = asciiDigits(walletRaw).take(11)
    val walletOk = when {
        walletDigits.isEmpty() -> FieldState.Empty
        walletDigits.length < 11 -> FieldState.Typing
        isValidWalletNumber(walletDigits) -> FieldState.Ok
        else -> FieldState.Bad
    }
    val ready = if (mode == TransferMode.PHONE) phoneOk == FieldState.Ok else walletOk == FieldState.Ok

    val phoneHint = buildString {
        append(stringResource(R.string.wa_phone_hint_country, country))
        val parts = listOfNotNull(
            balance?.phoneLength?.let { stringResource(R.string.wa_phone_digits, it) },
            balance?.phoneStartsWith?.takeIf { it.isNotBlank() }?.let { stringResource(R.string.wa_phone_starts, it) },
        )
        if (parts.isNotEmpty()) append(": ").append(parts.joinToString(" "))
    }

    WaPage(
        title = stringResource(R.string.wa_transfer_title),
        onBack = { host.pop() },
        cta = {
            WaButton(
                stringResource(R.string.wa_next),
                onClick = {
                    error = null
                    busy = true
                    scope.launch {
                        try {
                            val request = if (mode == TransferMode.PHONE) TransferLookupRequest("phone", phone = national)
                            else TransferLookupRequest("wallet", walletNumber = walletDigits)
                            val who = ApiClient.wallet.transferLookup(walletAuth(), request).data
                            if (who != null) host.push(WaRoute.TransferConfirm(who)) else error = networkError
                        } catch (e: CancellationException) {
                            throw e
                        } catch (e: Exception) {
                            val failure = e.apiFailure()
                            error = if (failure.httpStatus == null) networkError else failure.message ?: networkError
                        }
                        busy = false
                    }
                },
                enabled = ready, loading = busy,
                icon = null,
            )
            WaCtaHint(stringResource(R.string.wa_transfer_review_hint), Icons.Rounded.Shield)
        },
    ) {
        // Country banner.
        WaCard(Modifier.fillMaxWidth().waRise(0), padding = 12.dp) {
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) {
                WaIconWell(Icons.Rounded.Public, Tone.Blue)
                Column {
                    Text(stringResource(R.string.wa_transfer_banner_title, country), fontWeight = FontWeight.ExtraBold, fontSize = 14.sp, color = Wa.Ink)
                    Text(stringResource(R.string.wa_transfer_banner_text, balance?.currencyCode.orEmpty(), country), color = Wa.Mut, fontSize = 12.sp, lineHeight = 20.sp, modifier = Modifier.padding(top = 2.dp))
                }
            }
        }

        // Scan call-to-action.
        Spacer(Modifier.height(12.dp))
        ScanCta(Modifier.waRise(1)) { host.push(WaRoute.Scanner) }

        Row(Modifier.fillMaxWidth().padding(top = 12.dp, bottom = 12.dp).waRise(1), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Box(Modifier.weight(1f).height(1.dp).background(Wa.Line))
            Text(stringResource(R.string.wa_or_manual), color = Wa.Soft, fontSize = 12.sp, fontWeight = FontWeight.Bold)
            Box(Modifier.weight(1f).height(1.dp).background(Wa.Line))
        }

        Segmented(
            options = listOf(stringResource(R.string.wa_by_phone) to Icons.Rounded.Phone, stringResource(R.string.wa_by_wallet) to Icons.Rounded.AccountBalanceWallet),
            selected = mode.ordinal,
            modifier = Modifier.waRise(1),
        ) { mode = TransferMode.entries[it]; error = null }

        Spacer(Modifier.height(12.dp))
        if (mode == TransferMode.PHONE) {
            InputCard(
                label = stringResource(R.string.wa_recipient_phone),
                prefix = balance?.dialCode.orEmpty(),
                prefixIcon = Icons.Rounded.Phone,
                value = phoneRaw,
                onChange = { phoneRaw = it.take(20) },
                placeholder = balance?.phoneStartsWith?.let { it + "X".repeat(((balance.phoneLength ?: 9) - it.length).coerceAtLeast(0)) } ?: "5XXXXXXXX",
                state = phoneOk,
                hint = if (phoneOk == FieldState.Bad) phoneHint + " — " + stringResource(R.string.wa_phone_mismatch) else phoneHint,
                keyboard = KeyboardType.Phone,
                modifier = Modifier.waRise(2),
            )
        } else {
            InputCard(
                label = stringResource(R.string.wa_wallet_number),
                prefix = null,
                prefixIcon = Icons.Rounded.AccountBalanceWallet,
                value = groupWalletNumber(walletDigits),
                onChange = { walletRaw = asciiDigits(it).take(11) },
                placeholder = "000 0000 0000",
                state = walletOk,
                hint = if (walletOk == FieldState.Bad) stringResource(R.string.wa_wallet_invalid) else stringResource(R.string.wa_wallet_hint, country),
                keyboard = KeyboardType.Number,
                modifier = Modifier.waRise(2),
            )
        }
        WaError(error)
    }
}

/** The red "scan a QR code" card on top of the transfer form. */
@Composable
private fun ScanCta(modifier: Modifier, onClick: () -> Unit) {
    val source = remember { MutableInteractionSource() }
    val pressScale by rememberPressScale(source, 0.98f)
    Row(
        modifier
            .fillMaxWidth()
            .scale(pressScale)
            .shadow(14.dp, RoundedCornerShape(22.dp), ambientColor = Color(0x47E50914), spotColor = Color(0x47E50914))
            .clip(RoundedCornerShape(22.dp))
            .background(Wa.ButtonBrush)
            .clickable(interactionSource = source, indication = null, onClick = onClick)
            .padding(horizontal = 16.dp, vertical = 14.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Box(Modifier.size(44.dp).clip(RoundedCornerShape(15.dp)).background(Color(0xF2FFFFFF)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.QrCodeScanner, null, tint = Wa.Red, modifier = Modifier.size(22.dp))
        }
        Column(Modifier.weight(1f)) {
            Text(stringResource(R.string.wa_scan_cta_title), color = Color.White, fontSize = 15.5.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.wa_scan_cta_text), color = Color.White.copy(alpha = 0.9f), fontSize = 12.sp, lineHeight = 19.sp, modifier = Modifier.padding(top = 2.dp))
        }
        Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, null, tint = Color.White.copy(alpha = 0.9f), modifier = Modifier.size(18.dp))
    }
}

/** Two-way switch with a white pill that slides between the options (`wa-seg`). */
@Composable
private fun Segmented(options: List<Pair<String, androidx.compose.ui.graphics.vector.ImageVector>>, selected: Int, modifier: Modifier = Modifier, onSelect: (Int) -> Unit) {
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    val fraction by animateFloatAsState(selected.toFloat(), tween(280), label = "seg")
    BoxWithConstraints(
        modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(18.dp))
            .background(Color(0xFFECEEF2))
            .padding(4.dp),
    ) {
        val tabWidth = maxWidth / options.size
        val tabPx = with(LocalDensity.current) { tabWidth.toPx() }
        Box(
            Modifier
                .align(Alignment.CenterStart)
                .width(tabWidth)
                .height(44.dp)
                .graphicsLayer { translationX = (if (rtl) -1 else 1) * fraction * tabPx }
                .shadow(6.dp, RoundedCornerShape(14.dp), ambientColor = Color(0x1A111928), spotColor = Color(0x26111928))
                .clip(RoundedCornerShape(14.dp))
                .background(Color.White),
        )
        Row(Modifier.fillMaxWidth()) {
            options.forEachIndexed { index, (label, icon) ->
                val on = index == selected
                val tint by animateColorAsState(if (on) Wa.Red else Wa.Mut, label = "segTint")
                Row(
                    Modifier
                        .weight(1f)
                        .height(44.dp)
                        .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null) { onSelect(index) },
                    horizontalArrangement = Arrangement.spacedBy(6.dp, Alignment.CenterHorizontally),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(icon, null, tint = tint, modifier = Modifier.size(16.dp))
                    Text(label, color = tint, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                }
            }
        }
    }
}

/** A labelled input with a state icon (✓ / ✕) and a hint that turns red when wrong. */
@Composable
private fun InputCard(
    label: String,
    prefix: String?,
    prefixIcon: androidx.compose.ui.graphics.vector.ImageVector,
    value: String,
    onChange: (String) -> Unit,
    placeholder: String,
    state: FieldState,
    hint: String,
    keyboard: KeyboardType,
    modifier: Modifier = Modifier,
) {
    var focused by remember { mutableStateOf(false) }
    val border by animateColorAsState(
        when {
            state == FieldState.Ok -> Wa.Green
            state == FieldState.Bad -> Wa.Danger
            focused -> Color(0x73E50914)
            else -> Color.Transparent
        },
        label = "inputBorder",
    )
    WaCard(modifier.fillMaxWidth(), padding = 16.dp) {
        Text(label, color = Wa.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(bottom = 8.dp))
        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
            Row(
                Modifier
                    .fillMaxWidth()
                    .height(50.dp)
                    .clip(RoundedCornerShape(15.dp))
                    .background(if (focused) Color.White else Wa.Field)
                    .border(2.dp, border, RoundedCornerShape(15.dp))
                    .padding(horizontal = 14.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                Icon(prefixIcon, null, tint = Wa.Soft, modifier = Modifier.size(16.dp))
                if (!prefix.isNullOrEmpty()) {
                    Text(prefix, color = Wa.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp)
                    Box(Modifier.width(1.dp).fillMaxHeight(0.5f).background(Wa.Line))
                }
                BasicTextField(
                    value = value, onValueChange = onChange, singleLine = true,
                    textStyle = TextStyle(fontSize = 16.sp, fontWeight = FontWeight.Bold, color = Wa.Ink),
                    keyboardOptions = KeyboardOptions(keyboardType = keyboard),
                    cursorBrush = SolidColor(Wa.Red),
                    modifier = Modifier.weight(1f).onFocusChanged { focused = it.isFocused },
                    decorationBox = { inner ->
                        Box(contentAlignment = Alignment.CenterStart) {
                            if (value.isEmpty()) Text(placeholder, color = Color(0xFFD1D5DB), fontSize = 16.sp, fontWeight = FontWeight.Bold)
                            inner()
                        }
                    },
                )
                when (state) {
                    FieldState.Ok -> Box(Modifier.size(22.dp).clip(CircleShape).background(Wa.Green), contentAlignment = Alignment.Center) { Icon(Icons.Rounded.Check, null, tint = Color.White, modifier = Modifier.size(14.dp)) }
                    FieldState.Bad -> Box(Modifier.size(22.dp).clip(CircleShape).background(Wa.Danger), contentAlignment = Alignment.Center) { Icon(Icons.Rounded.Close, null, tint = Color.White, modifier = Modifier.size(14.dp)) }
                    else -> Unit
                }
            }
        }
        Text(hint, color = if (state == FieldState.Bad) Wa.Danger else Wa.Mut, fontSize = 12.sp, lineHeight = 20.sp, textAlign = TextAlign.Start, modifier = Modifier.padding(top = 8.dp, start = 2.dp))
    }
}
