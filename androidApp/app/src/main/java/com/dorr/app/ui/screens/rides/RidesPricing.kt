package com.dorr.app.ui.screens.rides

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.shrinkVertically
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.CreditCard
import androidx.compose.material.icons.rounded.ExpandMore
import androidx.compose.material.icons.rounded.Favorite
import androidx.compose.material.icons.rounded.Gavel
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.HourglassBottom
import androidx.compose.material.icons.rounded.LocalOffer
import androidx.compose.material.icons.rounded.LocalTaxi
import androidx.compose.material.icons.rounded.Policy
import androidx.compose.material.icons.rounded.Public
import androidx.compose.material.icons.rounded.Receipt
import androidx.compose.material.icons.rounded.ReceiptLong
import androidx.compose.material.icons.rounded.Savings
import androidx.compose.material.icons.rounded.Undo
import androidx.compose.material.icons.rounded.VolunteerActivism
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.network.ApiClient
import com.dorr.app.network.RideCurrencyDto
import com.dorr.app.network.RideDto
import com.dorr.app.network.RideLineDto
import com.dorr.app.network.RideQuoteDto
import com.dorr.app.network.RideTermDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.locale.LocaleAwareBottomSheet
import com.dorr.app.ui.screens.profile.HtmlContent
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.theme.AppColors
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.util.UUID

/*
 * The money around a ride (rides_tasks batch 2, Wireflow م11 / م12 and step 7):
 *  - a promo code on the booking sheet (R-45), priced by the server into its own green line;
 *  - "Price details": every line — service fee (R-47), discount, tax, payment-method fee (R-48);
 *  - "Cancellation & refund" read before confirming (R-52, R-53);
 *  - the optional tip after the ride (R-144): 5 / 10 / 20 or another amount, all of it the captain's.
 */

/** A line's icon and colour, the same on the booking sheet and the receipt. */
private fun lineIcon(code: String): ImageVector = when (code) {
    "fare", "rate_card" -> Icons.Rounded.LocalTaxi
    "waiting", "stops_wait" -> Icons.Rounded.HourglassBottom
    "customer_fee" -> Icons.Rounded.ReceiptLong
    "discount" -> Icons.Rounded.LocalOffer
    "tax" -> Icons.Rounded.Receipt
    "payment_method_fee" -> Icons.Rounded.CreditCard
    "fx_fee" -> Icons.Rounded.Public
    "previous_balance" -> Icons.Rounded.History
    "tip" -> Icons.Rounded.VolunteerActivism
    else -> Icons.Rounded.Receipt
}

/** One price line: icon, label, amount — a discount in green with its minus sign. */
@Composable
internal fun PriceLineRow(line: RideLineDto, currency: RideCurrencyDto?) {
    val discount = line.amountMinor < 0
    val tint = when {
        discount -> AppColors.success
        line.code == "tip" -> AppColors.brandOrange
        else -> settingsMut()
    }
    Row(Modifier.fillMaxWidth().padding(vertical = 3.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(lineIcon(line.code), null, tint = tint, modifier = Modifier.size(16.dp))
        Spacer(Modifier.width(8.dp))
        Text(line.label ?: line.code, color = if (discount) AppColors.success else settingsMut(), fontSize = 13.sp, modifier = Modifier.weight(1f))
        Text(
            (if (discount) "− " else "") + money(kotlin.math.abs(line.amountMinor), currency),
            color = if (discount) AppColors.success else settingsInk(), fontSize = 13.sp,
            fontWeight = if (discount) FontWeight.Bold else FontWeight.Normal,
        )
    }
}

/** A row that opens / closes what's under it, with a turning chevron. */
@Composable
private fun Expander(icon: ImageVector, title: String, trailing: String?, open: Boolean, onToggle: () -> Unit) {
    val turn by animateFloatAsState(if (open) 180f else 0f, tween(250), label = "chevron")
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable(onClick = onToggle).padding(vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(30.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = settingsAccent(), modifier = Modifier.size(17.dp))
        }
        Spacer(Modifier.width(10.dp))
        Text(title, color = settingsInk(), fontWeight = FontWeight.SemiBold, fontSize = 14.sp, modifier = Modifier.weight(1f))
        trailing?.let { Text(it, color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 14.sp) }
        Icon(Icons.Rounded.ExpandMore, null, tint = settingsMut(), modifier = Modifier.padding(start = 4.dp).rotate(turn))
    }
}

/** What the chosen way of paying adds (R-48): 0 for the wallet. */
internal fun RideQuoteDto.paymentFee(method: String): Long = paymentFees?.get(method) ?: 0L

/** R-45: the promo code field — closed to one line until tapped; applied = a green ticket with ✕. */
@Composable
internal fun PromoCodeRow(state: RidesState) {
    val quote = state.selected ?: return
    if (!state.feature("coupons") || quote.bid != null || quote.openDestination) return
    var code by remember { mutableStateOf(state.couponCode.orEmpty()) }
    val applied = quote.coupon

    AnimatedContent(
        targetState = applied?.code,
        transitionSpec = { (fadeIn(tween(220)) + scaleIn(initialScale = 0.92f)) togetherWith fadeOut(tween(150)) },
        label = "promo",
    ) { appliedCode ->
        if (appliedCode != null && applied != null) {
            // The ticket: the code and what it took off — or why it didn't apply to this category.
            val ok = applied.applied
            val tint = if (ok) AppColors.success else AppColors.warning
            Row(
                Modifier.fillMaxWidth().padding(vertical = 4.dp).clip(RoundedCornerShape(14.dp))
                    .border(BorderStroke(1.dp, tint.copy(alpha = 0.5f)), RoundedCornerShape(14.dp))
                    .background(tint.copy(alpha = 0.08f)).padding(horizontal = 12.dp, vertical = 10.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(if (ok) Icons.Rounded.CheckCircle else Icons.Rounded.LocalOffer, null, tint = tint, modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(appliedCode, color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 14.sp)
                    Text(
                        if (ok) stringResource(R.string.rd_promo_saved, money(applied.discountMinor, quote.currency)) else applied.reason.orEmpty(),
                        color = tint, fontSize = 12.sp, fontWeight = FontWeight.SemiBold,
                    )
                }
                Icon(
                    Icons.Rounded.Close, stringResource(R.string.rd_promo_remove), tint = settingsMut(),
                    modifier = Modifier.size(28.dp).clip(CircleShape).clickable {
                        state.couponCode = null
                        state.couponError = null
                        code = ""
                        loadQuotes(state, reprice = true)
                    }.padding(4.dp),
                )
            }
        } else {
            Column(Modifier.fillMaxWidth()) {
                // O-38a: one input — the ticket icon, the code, and "Apply" inside its own edge.
                val accent = settingsAccent()
                val bad = state.couponError != null
                Row(
                    Modifier.fillMaxWidth().padding(bottom = 4.dp).clip(RoundedCornerShape(999.dp))
                        .background(settingsBackground())
                        .border(BorderStroke(if (bad) 1.5.dp else 1.dp, if (bad) AppColors.danger else accent.copy(alpha = 0.3f)), RoundedCornerShape(999.dp))
                        .padding(start = 14.dp, end = 5.dp, top = 5.dp, bottom = 5.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Rounded.LocalOffer, null, tint = if (bad) AppColors.danger else accent, modifier = Modifier.size(20.dp))
                    Spacer(Modifier.width(10.dp))
                    BasicTextField(
                        value = code,
                        onValueChange = { code = it.uppercase().filter { c -> !c.isWhitespace() }.take(40); state.couponError = null },
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Characters),
                        textStyle = TextStyle(color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 15.sp, letterSpacing = 1.sp),
                        cursorBrush = SolidColor(accent),
                        modifier = Modifier.weight(1f),
                        decorationBox = { inner ->
                            Box(contentAlignment = Alignment.CenterStart) {
                                if (code.isEmpty()) Text(stringResource(R.string.rd_promo_hint), color = settingsMut(), fontSize = 14.sp)
                                inner()
                            }
                        },
                    )
                    Box(
                        Modifier.clip(RoundedCornerShape(999.dp)).background(if (code.isNotBlank()) accent else accent.copy(alpha = 0.35f))
                            .clickable(enabled = code.isNotBlank() && !state.busy) {
                                state.couponCode = code
                                loadQuotes(state, reprice = true)
                            }.padding(horizontal = 18.dp, vertical = 10.dp),
                    ) { Text(stringResource(R.string.rd_promo_apply), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 14.sp) }
                }
                AnimatedVisibility(state.couponError != null) {
                    Text(state.couponError.orEmpty(), color = AppColors.danger, fontSize = 12.sp, modifier = Modifier.padding(start = 4.dp, bottom = 4.dp))
                }
            }
        }
    }
}

/** "Price details" (Wireflow م11): every line for the chosen category and way of paying. */
@Composable
internal fun PriceDetails(quote: RideQuoteDto, method: String) {
    if (quote.bid != null || quote.lines.none { it.code != "rate_card" }) return
    var open by remember { mutableStateOf(false) }
    val fee = quote.paymentFee(method)
    val methodLabel = stringResource(R.string.rd_payment_fee_line)
    val lines = buildList {
        addAll(quote.lines.filter { it.code != "total" })
        // R-48: the chosen method's fee joins the lines at confirmation — shown here before it does.
        if (fee > 0) add(RideLineDto("payment_method_fee", methodLabel, fee))
    }
    val total = quote.totalMinor?.plus(fee)
    val shown = total?.let { money(it, quote.currency) } ?: quote.estimate?.let { "${money(it.minMinor + fee, quote.currency)}–${money(it.maxMinor + fee, quote.currency)}" }

    Column(Modifier.fillMaxWidth()) {
        Expander(Icons.Rounded.ReceiptLong, stringResource(R.string.rd_price_details), shown, open) { open = !open }
        AnimatedVisibility(open, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
            Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsBackground()).padding(12.dp)) {
                lines.forEach { PriceLineRow(it, quote.currency) }
                if (shown != null) {
                    Box(Modifier.fillMaxWidth().padding(vertical = 6.dp).height(1.dp).background(settingsInk().copy(alpha = 0.08f)))
                    Row(Modifier.fillMaxWidth()) {
                        Text(stringResource(if (total != null) R.string.rd_total else R.string.rd_estimate), color = settingsInk(), fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                        Text(shown, color = settingsInk(), fontWeight = FontWeight.ExtraBold)
                    }
                }
                Text(stringResource(R.string.rd_no_new_fees), color = settingsMut(), fontSize = 11.sp, modifier = Modifier.padding(top = 6.dp))
            }
        }
    }
}

/** "Cancellation & refund" (Wireflow م12, R-52, R-53): the rules this very price comes with. */
@Composable
internal fun PolicySummary(quote: RideQuoteDto, method: String) {
    val summary = quote.policySummary ?: return
    var open by remember { mutableStateOf(false) }
    Column(Modifier.fillMaxWidth()) {
        Expander(Icons.Rounded.Policy, stringResource(R.string.rd_policy_title), null, open) { open = !open }
        AnimatedVisibility(open, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
            Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsBackground()).padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                summary.items.forEach { item ->
                    Row(verticalAlignment = Alignment.Top) {
                        Icon(
                            if (item.code == "no_show") Icons.Rounded.HourglassBottom else Icons.Rounded.Close, null,
                            tint = settingsAccent(), modifier = Modifier.size(16.dp).padding(top = 2.dp),
                        )
                        Spacer(Modifier.width(8.dp))
                        Text(item.text, color = settingsInk(), fontSize = 13.sp, lineHeight = 18.sp)
                    }
                }
                summary.refund[method]?.let { refund ->
                    Row(
                        Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).background(AppColors.success.copy(alpha = 0.08f)).padding(10.dp),
                        verticalAlignment = Alignment.Top,
                    ) {
                        Icon(Icons.Rounded.Undo, null, tint = AppColors.success, modifier = Modifier.size(16.dp).padding(top = 2.dp))
                        Spacer(Modifier.width(8.dp))
                        Text(refund, color = settingsInk(), fontSize = 12.sp, lineHeight = 17.sp)
                    }
                }
            }
        }
    }
}

/**
 * R-179: before confirming, the terms in force for booking a ride — the rides service's own, else the
 * general ones — each opening in a sheet. Reading them never blocks the booking (consent is R-180..).
 */
@Composable
internal fun TermsNotice(quote: RideQuoteDto) {
    if (quote.terms.isEmpty()) return
    var open by remember { mutableStateOf<RideTermDto?>(null) }
    Row(Modifier.fillMaxWidth().padding(top = 8.dp), verticalAlignment = Alignment.Top) {
        Icon(Icons.Rounded.Gavel, null, tint = settingsMut(), modifier = Modifier.size(15.dp).padding(top = 2.dp))
        Spacer(Modifier.width(6.dp))
        Column {
            Text(stringResource(R.string.rd_terms_notice), color = settingsMut(), fontSize = 12.sp)
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                quote.terms.forEach { term ->
                    Text(
                        term.title, color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold,
                        textDecoration = TextDecoration.Underline,
                        modifier = Modifier.clip(RoundedCornerShape(6.dp)).clickable { open = term }.padding(vertical = 2.dp),
                    )
                }
            }
        }
    }
    open?.let { term -> TermsSheet(term) { open = null } }
}

@Composable
private fun TermsSheet(term: RideTermDto, onClose: () -> Unit) {
    var html by remember(term) { mutableStateOf<String?>(null) }
    var failed by remember(term) { mutableStateOf(false) }
    LaunchedEffect(term) {
        runCatching { ApiClient.content.getLegalPage(type = term.type, serviceId = term.serviceId, fallback = 1).data?.content }
            .onSuccess { html = it.orEmpty() }
            .onFailure { failed = true }
    }
    val updated = remember(term.updatedAt) {
        term.updatedAt?.let { runCatching { java.time.OffsetDateTime.parse(it).toLocalDate().format(java.time.format.DateTimeFormatter.ofLocalizedDate(java.time.format.FormatStyle.MEDIUM)) }.getOrNull() }
    }
    LocaleAwareBottomSheet(onDismissRequest = onClose, containerColor = settingsCard(), shape = RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 24.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(38.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
                    Icon(if (term.type == "privacy") Icons.Rounded.Policy else Icons.Rounded.Gavel, null, tint = settingsAccent(), modifier = Modifier.size(20.dp))
                }
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(term.title, color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 17.sp)
                    Text(
                        listOfNotNull(
                            stringResource(if (term.scope == "service") R.string.rd_terms_scope_service else R.string.rd_terms_scope_general),
                            updated?.let { stringResource(R.string.rd_terms_updated, it) },
                        ).joinToString(" · "),
                        color = settingsMut(), fontSize = 12.sp,
                    )
                }
            }
            Spacer(Modifier.height(12.dp))
            AnimatedContent(targetState = html to failed, transitionSpec = { fadeIn(tween(250)) togetherWith fadeOut(tween(150)) }, label = "terms") { (body, error) ->
                when {
                    error -> Text(stringResource(R.string.content_error), color = AppColors.danger, fontSize = 13.sp)
                    body == null -> Box(Modifier.fillMaxWidth().height(160.dp), contentAlignment = Alignment.Center) {
                        androidx.compose.material3.CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(28.dp))
                    }
                    else -> Column(Modifier.fillMaxWidth().heightIn(max = 520.dp).verticalScroll(rememberScrollState())) {
                        HtmlContent(body, settingsInk(), settingsAccent())
                    }
                }
            }
        }
    }
}

/** The old price struck through above the new one, when a promo code took something off. */
@Composable
internal fun StruckPrice(quote: RideQuoteDto) {
    val off = quote.coupon?.takeIf { it.applied }?.discountMinor ?: return
    val total = quote.totalMinor ?: return
    Text(money(total + off, quote.currency), color = settingsMut(), fontSize = 11.sp, textDecoration = TextDecoration.LineThrough)
}

/**
 * R-144 (Wireflow step 7): "Tip (optional): 5 — 10 — 20" under the rating — or another amount. Paid
 * from the wallet, all of it the captain's; once given it turns into a thank-you.
 */
@Composable
internal fun TipSection(ride: RideDto, onTipped: (RideDto) -> Unit) {
    val tip = ride.tip ?: return
    val scope = rememberCoroutineScope()
    var chosen by remember { mutableStateOf<Long?>(null) }
    var other by remember { mutableStateOf(false) }
    var typed by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val key = remember(ride.uuid) { UUID.randomUUID().toString() }
    val places = ride.currency?.decimals ?: 2
    val unit = remember(places) { var u = 1L; repeat(places) { u *= 10 }; u }
    val amount = if (other) typed.toDoubleOrNull()?.let { (it * unit).toLong() } else chosen

    Column(
        Modifier.fillMaxWidth().padding(vertical = 8.dp).clip(RoundedCornerShape(16.dp))
            .background(AppColors.brandOrange.copy(alpha = 0.07f)).padding(12.dp),
    ) {
        AnimatedContent(targetState = tip.amountMinor, transitionSpec = { (fadeIn(tween(300)) + scaleIn(spring(Spring.DampingRatioMediumBouncy))) togetherWith fadeOut() }, label = "tip") { given ->
            if (given != null) {
                // Thank-you: a heart that pops, and the amount.
                val pop = remember { Animatable(0.3f) }
                LaunchedEffect(given) { pop.animateTo(1f, spring(Spring.DampingRatioHighBouncy, Spring.StiffnessLow)) }
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Rounded.Favorite, null, tint = AppColors.brandOrange, modifier = Modifier.size(30.dp).scale(pop.value))
                    Spacer(Modifier.width(10.dp))
                    Column {
                        Text(stringResource(R.string.rd_tip_given, money(given, ride.currency)), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 14.sp)
                        Text(stringResource(R.string.rd_tip_all_driver), color = settingsMut(), fontSize = 12.sp)
                    }
                }
            } else if (tip.allowed) {
                Column {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Rounded.Savings, null, tint = AppColors.brandOrange, modifier = Modifier.size(20.dp))
                        Spacer(Modifier.width(8.dp))
                        Text(stringResource(R.string.rd_tip_title, ride.driver?.name.orEmpty()), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 14.sp, modifier = Modifier.weight(1f))
                    }
                    Text(stringResource(R.string.rd_tip_hint), color = settingsMut(), fontSize = 12.sp, modifier = Modifier.padding(top = 2.dp, bottom = 8.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        tip.presetsMinor.forEach { preset ->
                            TipChip(money(preset, ride.currency), !other && chosen == preset, Modifier.weight(1f)) { other = false; chosen = if (chosen == preset) null else preset; error = null }
                        }
                        TipChip(stringResource(R.string.rd_tip_other), other, Modifier.weight(1f)) { other = !other; chosen = null; error = null }
                    }
                    AnimatedVisibility(other, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
                        DorrTextField(
                            value = typed, onValueChange = { v -> typed = v.filter { it.isDigit() || it == '.' }.take(8); error = null },
                            placeholder = stringResource(R.string.rd_tip_amount_hint, money(tip.maxMinor, ride.currency)), icon = Icons.Rounded.Savings,
                            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                            modifier = Modifier.fillMaxWidth().padding(top = 8.dp),
                        )
                    }
                    error?.let { Text(it, color = AppColors.danger, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp)) }
                    AnimatedVisibility(amount != null && amount > 0) {
                        Box(Modifier.padding(top = 10.dp)) {
                            AccentButton(stringResource(R.string.rd_tip_send, money(amount ?: 0, ride.currency)), busy = busy, color = AppColors.brandOrange) {
                                val value = amount ?: return@AccentButton
                                busy = true
                                scope.launch {
                                    runCatching { ApiClient.rides.tip(ridesAuth(), key, ride.uuid, JsonObject().apply { addProperty("amount_minor", value) }).data }
                                        .onSuccess { fresh -> fresh?.let(onTipped) }
                                        .onFailure { error = it.apiFailure().message }
                                    busy = false
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun TipChip(label: String, selected: Boolean, modifier: Modifier, onClick: () -> Unit) {
    val grow by animateFloatAsState(if (selected) 1.06f else 1f, spring(Spring.DampingRatioMediumBouncy), label = "chip")
    Box(
        modifier.scale(grow).clip(RoundedCornerShape(12.dp))
            .border(BorderStroke(if (selected) 2.dp else 1.dp, if (selected) AppColors.brandOrange else settingsInk().copy(alpha = 0.1f)), RoundedCornerShape(12.dp))
            .background(if (selected) AppColors.brandOrange.copy(alpha = 0.12f) else Color.Transparent)
            .clickable(onClick = onClick).padding(vertical = 10.dp),
        contentAlignment = Alignment.Center,
    ) {
        Text(label, color = settingsInk(), fontWeight = if (selected) FontWeight.Bold else FontWeight.Medium, fontSize = 13.sp, textAlign = TextAlign.Center, maxLines = 1)
    }
}
