package com.dorr.freelance.ui.wallet

import android.annotation.SuppressLint
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.slideInVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.fillMaxSize
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
import androidx.compose.material.icons.rounded.AccountBalance
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.CreditCard
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Phone
import androidx.compose.material.icons.rounded.Science
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
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import com.dorr.freelance.R
import com.dorr.freelance.net.PaymentMethodDto

/* The DORR app's top-up pieces (WalletTopup.kt), unchanged in look: the method cards, the quote strip, the OTP field and the
 * in-app layer for the test bank page. */

internal fun methodLook(gateway: String): Pair<ImageVector, Tone> = when (gateway) {
    "myfatoorah" -> Icons.Rounded.CreditCard to Tone.Blue
    "arb" -> Icons.Rounded.AccountBalance to Tone.Green
    "urpay" -> Icons.Rounded.Phone to Tone.Pink
    "sandbox" -> Icons.Rounded.Science to Tone.Amber
    else -> Icons.Rounded.CreditCard to Tone.Gray
}

@Composable
fun MethodCard(method: PaymentMethodDto, selected: Boolean, onClick: () -> Unit) {
    val (icon, tone) = methodLook(method.gateway)
    val source = remember { MutableInteractionSource() }
    val pressScale by rememberPressScale(source, 0.98f)
    val soon = method.comingSoon
    val shape = RoundedCornerShape(16.dp)
    Row(
        Modifier
            .fillMaxWidth()
            .padding(bottom = 8.dp)
            .scale(pressScale)
            .alpha(if (soon) 0.72f else 1f)
            .clip(shape)
            .background(
                when {
                    selected -> if (walletNight()) com.dorr.freelance.ui.kit.AccountDark.well else Wa.Red.copy(alpha = 0.06f)
                    soon -> if (walletNight()) Wa.Surface else Color(0xFFF7F8FA)
                    else -> Wa.Surface
                },
            )
            .border(1.dp, if (selected) Wa.Red else Wa.Line, shape)
            .clickable(interactionSource = source, indication = null, onClick = onClick)
            .padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        WaIconWell(icon, if (soon) Tone.Gray else tone, size = 36.dp, iconSize = 16.dp)
        Column(Modifier.weight(1f)) {
            Text(method.name ?: method.code, fontSize = 13.sp, fontWeight = FontWeight.Bold, color = Wa.Ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(
                stringResource(if (soon) R.string.wa_method_soon_sub else if (method.gateway == "sandbox") R.string.wa_method_sandbox_sub else R.string.wa_method_secure_sub),
                fontSize = 11.sp, color = Wa.Mut, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 2.dp),
            )
        }
        if (soon) {
            Text(
                stringResource(R.string.wa_soon_badge), color = Color(0xFFB45309), fontSize = 10.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(Color(0xFFFEF3C7)).padding(horizontal = 8.dp, vertical = 3.dp),
            )
        } else {
            Box(
                Modifier.size(20.dp).clip(CircleShape).background(if (selected) Wa.Red else Color.Transparent)
                    .border(1.5.dp, if (selected) Wa.Red else if (walletNight()) Wa.Line else Color(0xFFD1D5DB), CircleShape),
                contentAlignment = Alignment.Center,
            ) { if (selected) Icon(Icons.Rounded.Check, null, tint = Color.White, modifier = Modifier.size(12.dp)) }
        }
    }
}

@Composable
private fun RowScope.QuoteStat(label: String, value: String, valueColor: Color = Wa.Ink, ltr: Boolean = false) {
    Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally) {
        Text(label, color = Wa.Mut, fontSize = 10.sp, fontWeight = FontWeight.Medium, maxLines = 1, overflow = TextOverflow.Ellipsis)
        val valueMod = Modifier.padding(top = 2.dp)
        if (ltr) {
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                Text(value, color = valueColor, fontSize = 12.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = valueMod)
            }
        } else {
            Text(value, color = valueColor, fontSize = 12.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = valueMod)
        }
    }
}

@Composable
fun QuoteCard(amountMinor: Long, feeMinor: Long, bonusMinor: Long, currency: String, methodName: String?) {
    val free = feeMinor <= 0L
    val shape = RoundedCornerShape(18.dp)
    Column(Modifier.fillMaxWidth().padding(bottom = 12.dp)) {
        Row(
            Modifier
                .fillMaxWidth()
                .clip(shape)
                .background(if (walletNight()) com.dorr.freelance.ui.kit.AccountDark.well else Color.White)
                .border(1.dp, Wa.Line, shape)
                .padding(horizontal = 8.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            QuoteStat(stringResource(R.string.wa_quote_amount), money(amountMinor) + " " + currency, ltr = true)
            Box(Modifier.width(1.dp).height(22.dp).background(Wa.Line.copy(alpha = 0.7f)))
            QuoteStat(
                stringResource(R.string.wa_quote_fee_short),
                if (free) stringResource(R.string.wa_quote_fee_free) else "− " + money(feeMinor) + " " + currency,
                valueColor = if (free) Color(0xFF059669) else Wa.Ink,
            )
            Box(Modifier.width(1.dp).height(22.dp).background(Wa.Line.copy(alpha = 0.7f)))
            QuoteStat(stringResource(R.string.wa_pay_method), methodName ?: "—")
        }
        if (bonusMinor > 0) {
            Row(Modifier.padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.AutoAwesome, null, tint = Wa.Mut, modifier = Modifier.size(12.dp))
                Text(stringResource(R.string.wa_quote_bonus) + " + " + money(bonusMinor) + " " + currency, color = Wa.Mut, fontSize = 10.sp)
            }
        }
    }
}

@Composable
fun OtpField(value: String, onChange: (String) -> Unit) {
    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
        BasicTextField(
            value = value, onValueChange = onChange, singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
            textStyle = TextStyle(fontSize = 26.sp, fontWeight = FontWeight.ExtraBold, color = Wa.Ink, textAlign = TextAlign.Center, letterSpacing = 10.sp),
            cursorBrush = SolidColor(Wa.Red),
            modifier = Modifier.fillMaxWidth(0.8f),
            decorationBox = { inner ->
                Box(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Wa.Surface).border(2.dp, if (value.isEmpty()) Wa.Line else Wa.Red, RoundedCornerShape(16.dp)).padding(14.dp),
                    contentAlignment = Alignment.Center,
                ) {
                    if (value.isEmpty()) Text("••••", fontSize = 26.sp, color = if (walletNight()) Wa.Soft else Color(0xFFD1D5DB), letterSpacing = 10.sp)
                    inner()
                }
            },
        )
    }
}

/** The test bank (sandbox) inside the phone, like an in-app browser sliding up over the page; real gateways open in the browser. */
@SuppressLint("SetJavaScriptEnabled")
@Composable
fun GatewayLayer(url: String?, onClose: () -> Unit) {
    var last by remember { mutableStateOf("") }
    if (url != null) last = url

    // The test bank page is ours, so it is drawn in the app's own colours: they travel in the query string (six hex digits
    // each). A real gateway's URL is left untouched.
    val theme = listOf(
        "primary" to Wa.Red, "bg" to Wa.Bg, "surface" to Wa.Surface, "ink" to Wa.Ink,
        "mut" to Wa.Mut, "soft" to Wa.Soft, "line" to Wa.Line, "field" to Wa.Field,
    ).joinToString("&") { (name, color) -> "$name=" + String.format("%06x", color.toArgb() and 0xFFFFFF) }
    val lang = com.dorr.freelance.ui.locale.LocalAppLanguage.current.code
    val pageUrl = if ("/sandbox/" in last) last + (if ('?' in last) "&" else "?") + theme + "&lang=" + lang else last
    val pageBackground = Wa.Bg.toArgb()
    // Back closes the layer (not the page under it).
    androidx.activity.compose.BackHandler(enabled = url != null) { onClose() }
    // While it is open the payment page gets the whole screen: the app's header and bottom bar step aside.
    if (url != null) {
        androidx.compose.runtime.DisposableEffect(Unit) {
            com.dorr.freelance.ui.components.ShellState.immersive = true
            onDispose { com.dorr.freelance.ui.components.ShellState.immersive = false }
        }
    }
    AnimatedVisibility(visible = url != null, enter = slideInVertically(tween(380)) { it } + fadeIn(tween(200))) {
        // The layer takes every touch, so nothing reaches the page under it (its back button sits right below the close one).
        Column(
            Modifier.fillMaxSize().background(Wa.Bg)
                .clickable(interactionSource = remember { androidx.compose.foundation.interaction.MutableInteractionSource() }, indication = null) {},
        ) {
            Row(Modifier.fillMaxWidth().padding(horizontal = 14.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                Row(Modifier.weight(1f), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    Icon(Icons.Rounded.Lock, null, tint = Wa.Ink, modifier = Modifier.size(16.dp))
                    Text(stringResource(R.string.wa_secure_pay), fontWeight = FontWeight.ExtraBold, color = Wa.Ink)
                }
                WaCircleButton(Icons.Rounded.Close, onClose)
            }
            AndroidView(
                modifier = Modifier.fillMaxSize(),
                factory = { ctx ->
                    WebView(ctx).apply {
                        settings.javaScriptEnabled = true
                        setBackgroundColor(pageBackground)
                        webViewClient = WebViewClient()
                        loadUrl(pageUrl)
                    }
                },
            )
        }
    }
}
