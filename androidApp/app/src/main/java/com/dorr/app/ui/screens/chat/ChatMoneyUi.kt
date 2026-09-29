package com.dorr.app.ui.screens.chat

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.Canvas
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
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Block
import androidx.compose.material.icons.rounded.CallSplit
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.EditNote
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.RequestQuote
import androidx.compose.material.icons.rounded.Send
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.graphicsLayer
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
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.MemberDto
import com.dorr.app.network.MessageDto
import com.dorr.app.network.PaymentDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.wallet.PadResult
import com.dorr.app.ui.screens.wallet.WaPinPad
import com.dorr.app.ui.screens.wallet.formatMinor
import com.dorr.app.ui.screens.wallet.parseAmountToMinor
import com.dorr.app.ui.theme.CairoFontFamily
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

private val MoneyGreen = listOf(Color(0xFF10B981), Color(0xFF047857))
private val MoneyDone get() = Ch.Success

private fun PaymentDto.money(minor: Long) = formatMinor(minor, currencySymbol ?: currency)

// =============================================================================== money request card

/**
 * "Send me 50 · Dinner 🍕": a green card with the amount counting up when it appears, a status
 * chip, and — for the other person — "Pay" (PIN) and "Decline". When paid a stamp pops in.
 */
@Composable
fun MoneyRequestCard(m: UiMessage, mine: Boolean, onPay: () -> Unit, onDecline: () -> Unit, onCancel: () -> Unit, footer: @Composable () -> Unit) {
    val p = m.dto.payment ?: return
    val shown = remember { Animatable(0f) }
    LaunchedEffect(p.amountMinor) { shown.animateTo(1f, tween(700, easing = FastOutSlowInEasing)) }
    Column(Modifier.width(262.dp).padding(4.dp).clip(RoundedCornerShape(18.dp)).background(Brush.linearGradient(MoneyGreen)).padding(14.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(34.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.2f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.RequestQuote, null, tint = Color.White, modifier = Modifier.size(19.dp))
            }
            Spacer(Modifier.width(8.dp))
            Text(
                stringResource(if (p.isRequester) R.string.ch_money_you_requested else R.string.ch_money_requested_from_you),
                color = Color.White.copy(alpha = 0.9f), fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f),
            )
            StatusChip(p.status)
        }
        androidx.compose.runtime.CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
            Text(
                p.money((p.amountMinor * shown.value).toLong()), color = Color.White, fontSize = 30.sp, fontWeight = FontWeight.ExtraBold,
                letterSpacing = (-0.5).sp, modifier = Modifier.padding(top = 10.dp),
            )
        }
        m.dto.body?.takeIf { it.isNotBlank() }?.let { Text(it, color = Color.White.copy(alpha = 0.92f), fontSize = 14.sp, modifier = Modifier.padding(top = 2.dp)) }

        AnimatedVisibility(p.canPay) {
            Row(Modifier.padding(top = 12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                MoneyButton(stringResource(R.string.ch_money_pay), Icons.Rounded.Lock, filled = true, modifier = Modifier.weight(1f), onClick = onPay)
                MoneyButton(stringResource(R.string.ch_money_decline), Icons.Rounded.Close, filled = false, modifier = Modifier.weight(0.8f), onClick = onDecline)
            }
        }
        AnimatedVisibility(p.canCancel) {
            Text(
                stringResource(R.string.ch_money_cancel), color = Color.White.copy(alpha = 0.85f), fontWeight = FontWeight.Bold, fontSize = 13.sp,
                modifier = Modifier.padding(top = 10.dp).clip(RoundedCornerShape(10.dp)).clickable(onClick = onCancel).padding(horizontal = 6.dp, vertical = 4.dp),
            )
        }
        Box(Modifier.fillMaxWidth().padding(top = 6.dp)) { Box(Modifier.align(Alignment.CenterEnd)) { footer() } }
    }
}

// =============================================================================== bill split card

/**
 * A shared bill: the total, a ring that fills as shares are paid, each person with their share
 * and a tick / clock / ✕, and "Pay my share" for me when mine is pending.
 */
@Composable
fun BillSplitCard(m: UiMessage, mine: Boolean, onPay: () -> Unit, onDecline: () -> Unit, onCancel: () -> Unit, footer: @Composable () -> Unit) {
    val p = m.dto.payment ?: return
    val ink = if (mine) Ch.OutText else Ch.InText
    val progress by animateFloatAsState(if (p.totalMinor > 0) p.paidMinor.toFloat() / p.totalMinor else 0f, spring(dampingRatio = 0.8f, stiffness = 120f), label = "splitRing")
    val ringColor by animateColorAsState(if (p.status == "settled") MoneyDone else Color(0xFF10B981), label = "splitColor")
    Column(Modifier.width(278.dp).padding(horizontal = 12.dp, vertical = 10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(58.dp), contentAlignment = Alignment.Center) {
                Canvas(Modifier.size(58.dp)) {
                    drawArc(ink.copy(alpha = 0.14f), 0f, 360f, false, style = Stroke(6.dp.toPx()))
                    drawArc(ringColor, -90f, 360f * progress, false, style = Stroke(6.dp.toPx(), cap = StrokeCap.Round))
                }
                AnimatedContent(p.status == "settled", label = "splitIcon", transitionSpec = { scaleIn(spring(dampingRatio = 0.4f)) togetherWith scaleOut() }) { done ->
                    Icon(if (done) Icons.Rounded.CheckCircle else Icons.Rounded.CallSplit, null, tint = if (done) MoneyDone else ink, modifier = Modifier.size(24.dp))
                }
            }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(m.dto.body?.takeIf { it.isNotBlank() } ?: stringResource(R.string.ch_split_title), color = ink, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
                androidx.compose.runtime.CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    Text(p.money(p.totalMinor), color = ink, fontWeight = FontWeight.ExtraBold, fontSize = 19.sp)
                }
                Text(stringResource(R.string.ch_split_collected, p.money(p.paidMinor)), color = ink.copy(alpha = 0.7f), fontSize = 11.5.sp)
            }
        }
        Spacer(Modifier.height(8.dp))
        p.shares.forEachIndexed { i, share ->
            Row(Modifier.fillMaxWidth().chStagger(i).padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                ChAvatar(share.profile?.avatar, share.profile?.name, share.profile?.key, size = 28.dp)
                Spacer(Modifier.width(8.dp))
                Text(
                    if (share.profile?.isMe == true) stringResource(R.string.ch_you) else share.profile?.name.orEmpty(),
                    color = ink, fontSize = 13.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f),
                )
                androidx.compose.runtime.CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    Text(p.money(share.amountMinor), color = ink.copy(alpha = 0.85f), fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
                }
                Spacer(Modifier.width(6.dp))
                ShareIcon(share.status, ink)
            }
        }
        AnimatedVisibility(p.canPay) {
            Row(Modifier.padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Row(
                    Modifier.weight(1f).clip(RoundedCornerShape(14.dp)).background(Brush.linearGradient(MoneyGreen)).clickable(onClick = onPay).padding(vertical = 11.dp),
                    horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Rounded.Lock, null, tint = Color.White, modifier = Modifier.size(16.dp))
                    Spacer(Modifier.width(6.dp))
                    Text(stringResource(R.string.ch_split_pay_mine, p.money(p.myShare?.amountMinor ?: 0)), color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 13.5.sp)
                }
                Box(
                    Modifier.size(42.dp).clip(RoundedCornerShape(14.dp)).background(ink.copy(alpha = 0.1f)).clickable(onClick = onDecline),
                    contentAlignment = Alignment.Center,
                ) { Icon(Icons.Rounded.Close, null, tint = ink, modifier = Modifier.size(18.dp)) }
            }
        }
        AnimatedVisibility(p.canCancel) {
            Text(
                stringResource(R.string.ch_split_close), color = ink.copy(alpha = 0.75f), fontWeight = FontWeight.Bold, fontSize = 12.5.sp,
                modifier = Modifier.padding(top = 8.dp).clip(RoundedCornerShape(10.dp)).clickable(onClick = onCancel).padding(horizontal = 4.dp, vertical = 4.dp),
            )
        }
        Box(Modifier.fillMaxWidth().padding(top = 4.dp)) { Box(Modifier.align(Alignment.CenterEnd)) { footer() } }
    }
}

@Composable
private fun ShareIcon(status: String, ink: Color) {
    AnimatedContent(status, label = "share", transitionSpec = { scaleIn(spring(dampingRatio = 0.4f)) togetherWith scaleOut() }) { s ->
        when (s) {
            "paid" -> Icon(Icons.Rounded.CheckCircle, null, tint = MoneyDone, modifier = Modifier.size(18.dp))
            "owner" -> Icon(Icons.Rounded.Star, null, tint = Color(0xFFF59E0B), modifier = Modifier.size(18.dp))
            "declined" -> Icon(Icons.Rounded.Block, null, tint = Ch.Danger, modifier = Modifier.size(18.dp))
            else -> {
                val spin = rememberInfiniteTransition(label = "sharePending")
                val a by spin.animateFloat(0f, 1f, infiniteRepeatable(tween(1400, easing = LinearEasing), RepeatMode.Reverse), label = "sharePulse")
                Icon(Icons.Rounded.HourglassTop, null, tint = ink.copy(alpha = 0.4f + 0.4f * a), modifier = Modifier.size(18.dp))
            }
        }
    }
}

@Composable
private fun StatusChip(status: String) {
    val (label, color) = when (status) {
        "paid", "settled" -> R.string.ch_money_status_paid to Color.White
        "declined" -> R.string.ch_money_status_declined to Color(0xFFFECACA)
        "cancelled" -> R.string.ch_money_status_cancelled to Color.White.copy(alpha = 0.7f)
        else -> R.string.ch_money_status_pending to Color.White.copy(alpha = 0.9f)
    }
    val pop = remember { Animatable(1f) }
    LaunchedEffect(status) {
        if (status == "paid") {
            pop.snapTo(0.4f)
            pop.animateTo(1f, spring(dampingRatio = 0.35f, stiffness = 400f))
        }
    }
    Row(
        Modifier.scale(pop.value).clip(RoundedCornerShape(10.dp)).background(Color.Black.copy(alpha = 0.18f)).padding(horizontal = 8.dp, vertical = 3.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (status == "paid") {
            Icon(Icons.Rounded.Check, null, tint = color, modifier = Modifier.size(13.dp))
            Spacer(Modifier.width(3.dp))
        }
        Text(stringResource(label), color = color, fontSize = 11.sp, fontWeight = FontWeight.ExtraBold)
    }
}

@Composable
private fun MoneyButton(text: String, icon: ImageVector, filled: Boolean, modifier: Modifier, onClick: () -> Unit) {
    val press = remember { androidx.compose.foundation.interaction.MutableInteractionSource() }
    val scale by com.dorr.app.ui.screens.wallet.rememberPressScale(press, 0.93f)
    Row(
        modifier.scale(scale).clip(RoundedCornerShape(14.dp))
            .then(if (filled) Modifier.background(Color.White) else Modifier.border(1.5.dp, Color.White.copy(alpha = 0.7f), RoundedCornerShape(14.dp)))
            .clickable(interactionSource = press, indication = null, onClick = onClick).padding(vertical = 10.dp),
        horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = if (filled) MoneyGreen.last() else Color.White, modifier = Modifier.size(16.dp))
        Spacer(Modifier.width(5.dp))
        Text(text, color = if (filled) MoneyGreen.last() else Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 13.5.sp)
    }
}

// =============================================================================== paying with the PIN

/**
 * "Pay 25.00 SAR to Alice": the wallet PIN pad, then a real transfer. A wrong PIN shakes; a
 * lock shows its countdown; anything else (balance, limits) closes with the reason.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ChatPaySheet(message: MessageDto, onDismiss: () -> Unit, onPaid: (MessageDto) -> Unit) {
    val host = LocalChat.current
    val p = message.payment ?: return
    var done by remember { mutableStateOf(false) }
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        AnimatedContent(done, label = "payDone", transitionSpec = { (fadeIn(tween(220)) + scaleIn(initialScale = 0.9f)) togetherWith fadeOut(tween(150)) }) { finished ->
            if (finished) {
                PaidCelebration(p.money(p.due), message.sender?.name.orEmpty())
            } else {
                Column(Modifier.fillMaxWidth().heightIn(min = 520.dp).padding(horizontal = 12.dp).padding(bottom = 16.dp)) {
                    WaPinPad(
                        title = stringResource(R.string.ch_money_pay_title, p.money(p.due)),
                        sub = stringResource(R.string.ch_money_pay_to, message.sender?.name.orEmpty()),
                        icon = Icons.Rounded.Payments,
                        modifier = Modifier.fillMaxWidth().height(500.dp),
                        onComplete = { pin ->
                            try {
                                val paid = ApiClient.chat.payRequest(chatAuth(), pin, message.id).data
                                done = true
                                paid?.let(onPaid)
                                host.scope.launchDelayed(1600) { onDismiss() }
                                PadResult.Ok
                            } catch (e: Exception) {
                                val failure = e.apiFailure()
                                // PIN problems stay on the pad (shake / countdown); the rest closes with the reason.
                                if (failure.errorCode?.startsWith("wallet_pin") == true) throw e
                                failure.message?.let { host.showToast(it) }
                                onDismiss()
                                PadResult.Ok
                            }
                        },
                    )
                }
            }
        }
    }
}

private fun kotlinx.coroutines.CoroutineScope.launchDelayed(ms: Long, block: () -> Unit) {
    launch { delay(ms); block() }
}

/** A tick that pops, a burst of rings, "Paid 25.00 SAR to Alice". */
@Composable
private fun PaidCelebration(amount: String, to: String) {
    val pop = remember { Animatable(0f) }
    val burst = remember { Animatable(0f) }
    LaunchedEffect(Unit) {
        kotlinx.coroutines.coroutineScope {
            launch { pop.animateTo(1f, spring(dampingRatio = 0.4f, stiffness = 300f)) }
            launch { burst.animateTo(1f, tween(900, easing = FastOutSlowInEasing)) }
        }
    }
    Column(Modifier.fillMaxWidth().height(360.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
        Box(Modifier.size(170.dp), contentAlignment = Alignment.Center) {
            Canvas(Modifier.size(170.dp)) {
                repeat(3) { i ->
                    val t = (burst.value - i * 0.15f).coerceIn(0f, 1f)
                    drawCircle(MoneyDone.copy(alpha = 0.35f * (1f - t)), radius = size.minDimension / 2 * (0.35f + 0.65f * t), style = Stroke(3.dp.toPx()))
                }
                repeat(10) { i ->
                    val angle = Math.toRadians(i * 36.0)
                    val r = size.minDimension / 2 * (0.45f + 0.45f * burst.value)
                    val c = Offset(size.width / 2 + (r * kotlin.math.cos(angle)).toFloat(), size.height / 2 + (r * kotlin.math.sin(angle)).toFloat())
                    drawCircle(listOf(Color(0xFF10B981), Color(0xFFF59E0B), Color(0xFFF2202C))[i % 3].copy(alpha = 1f - burst.value), radius = 4.dp.toPx(), center = c)
                }
            }
            Box(
                Modifier.size(84.dp).scale(pop.value).clip(CircleShape).background(Brush.linearGradient(MoneyGreen)),
                contentAlignment = Alignment.Center,
            ) { Icon(Icons.Rounded.Check, null, tint = Color.White, modifier = Modifier.size(46.dp)) }
        }
        Text(stringResource(R.string.ch_money_paid_title), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 20.sp)
        androidx.compose.runtime.CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
            Text(amount, color = MoneyDone, fontWeight = FontWeight.ExtraBold, fontSize = 26.sp, modifier = Modifier.padding(top = 4.dp))
        }
        Text(stringResource(R.string.ch_money_pay_to, to), color = Ch.Mut, fontSize = 13.5.sp)
    }
}

// =============================================================================== composing

/** Ask the other person for an amount, with an optional note ("Dinner 🍕"). */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun RequestMoneySheet(peerName: String, onDismiss: () -> Unit, onSend: (amountMinor: Long, note: String?) -> Unit) {
    var amount by remember { mutableStateOf("") }
    var note by remember { mutableStateOf("") }
    val minor = parseAmountToMinor(amount)
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().imePadding().padding(horizontal = 22.dp).padding(bottom = 28.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Box(Modifier.size(58.dp).clip(CircleShape).background(Brush.linearGradient(MoneyGreen)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.RequestQuote, null, tint = Color.White, modifier = Modifier.size(30.dp))
            }
            Spacer(Modifier.height(10.dp))
            Text(stringResource(R.string.ch_money_request_from, peerName), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center)
            Spacer(Modifier.height(16.dp))
            AmountField(amount) { amount = it }
            Spacer(Modifier.height(12.dp))
            NoteField(note, stringResource(R.string.ch_money_note_hint)) { note = it.take(200) }
            Spacer(Modifier.height(18.dp))
            ChPrimaryButton(stringResource(R.string.ch_money_send_request), icon = Icons.Rounded.Send, modifier = Modifier.fillMaxWidth(), enabled = minor != null) {
                minor?.let { onSend(it, note.trim().ifEmpty { null }) }
                onDismiss()
            }
        }
    }
}

/**
 * Split a bill in a group: the total, who's in (everyone ticked to start, me included), equally
 * or by hand. The per-person amount updates as you tick people; custom amounts must add up.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SplitBillSheet(members: List<MemberDto>, onDismiss: () -> Unit, onSend: (totalMinor: Long, note: String?, equal: Boolean, shares: Map<Int, Long>) -> Unit) {
    var amount by remember { mutableStateOf("") }
    var note by remember { mutableStateOf("") }
    var equal by remember { mutableStateOf(true) }
    val picked = remember { mutableStateListOf<Int>().apply { addAll(members.map { it.participantId }) } }
    val custom = remember { mutableStateMapOf<Int, String>() }
    val total = parseAmountToMinor(amount) ?: 0L
    val perHead = if (picked.isNotEmpty()) total / picked.size else 0L
    val customSum = picked.sumOf { parseAmountToMinor(custom[it].orEmpty()) ?: 0L }
    val ready = total > 0 && picked.size >= 2 && (equal || customSum == total)

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().imePadding().heightIn(max = 680.dp).verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(44.dp).clip(CircleShape).background(Brush.linearGradient(MoneyGreen)), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.CallSplit, null, tint = Color.White, modifier = Modifier.size(24.dp))
                }
                Spacer(Modifier.width(12.dp))
                Text(stringResource(R.string.ch_split_new), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            }
            Spacer(Modifier.height(14.dp))
            AmountField(amount) { amount = it }
            Spacer(Modifier.height(10.dp))
            NoteField(note, stringResource(R.string.ch_split_note_hint)) { note = it.take(200) }
            Spacer(Modifier.height(14.dp))

            // Equally / by hand.
            Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Ch.SurfaceMuted).padding(4.dp)) {
                listOf(true to R.string.ch_split_equal, false to R.string.ch_split_custom).forEach { (isEqual, label) ->
                    val on = equal == isEqual
                    val bg by animateColorAsState(if (on) Ch.Surface else Color.Transparent, label = "splitMode")
                    Box(
                        Modifier.weight(1f).clip(RoundedCornerShape(12.dp)).background(bg).clickable { equal = isEqual }.padding(vertical = 10.dp),
                        contentAlignment = Alignment.Center,
                    ) { Text(stringResource(label), color = if (on) Ch.Ink else Ch.Mut, fontWeight = FontWeight.ExtraBold, fontSize = 13.5.sp) }
                }
            }
            Spacer(Modifier.height(10.dp))

            members.forEachIndexed { i, member ->
                val on = member.participantId in picked
                Row(
                    Modifier.fillMaxWidth().chStagger(i).padding(vertical = 3.dp).clip(RoundedCornerShape(16.dp))
                        .clickable { if (on) picked.remove(member.participantId) else picked.add(member.participantId) }.padding(horizontal = 6.dp, vertical = 7.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    val tick by animateFloatAsState(if (on) 1f else 0f, spring(dampingRatio = 0.5f), label = "splitTick")
                    Box(Modifier.size(24.dp).clip(CircleShape).background(MoneyDone.copy(alpha = tick)).border(2.dp, if (on) MoneyDone else Ch.Soft, CircleShape), contentAlignment = Alignment.Center) {
                        Icon(Icons.Rounded.Check, null, tint = Color.White, modifier = Modifier.size(15.dp).scale(tick))
                    }
                    Spacer(Modifier.width(10.dp))
                    ChAvatar(member.profile?.avatar, member.profile?.name, member.profile?.key, size = 34.dp)
                    Spacer(Modifier.width(8.dp))
                    Text(
                        if (member.profile?.isMe == true) stringResource(R.string.ch_you) else member.profile?.name.orEmpty(),
                        color = Ch.Ink, fontWeight = FontWeight.SemiBold, fontSize = 14.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f),
                    )
                    AnimatedContent(Triple(on, equal, perHead), label = "splitShare") { (isOn, isEqual, head) ->
                        when {
                            !isOn -> Spacer(Modifier.width(1.dp))
                            isEqual -> androidx.compose.runtime.CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                                Text(formatMinor(head, null), color = MoneyDone, fontWeight = FontWeight.ExtraBold, fontSize = 13.5.sp)
                            }
                            else -> Box(Modifier.width(104.dp)) {
                                ChField(
                                    custom[member.participantId].orEmpty(), { custom[member.participantId] = it.filter { c -> c.isDigit() || c == '.' || c == ',' }.take(10) },
                                    "0.00", keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), fontSize = 13.5.sp, bold = true, center = true,
                                )
                            }
                        }
                    }
                }
            }
            if (!equal && total > 0) {
                val left = total - customSum
                Text(
                    if (left == 0L) stringResource(R.string.ch_split_adds_up) else stringResource(R.string.ch_split_left, formatMinor(left, null)),
                    color = if (left == 0L) MoneyDone else Ch.Danger, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 6.dp, start = 6.dp),
                )
            }
            Spacer(Modifier.height(16.dp))
            ChPrimaryButton(stringResource(R.string.ch_split_send), icon = Icons.Rounded.Send, modifier = Modifier.fillMaxWidth(), enabled = ready) {
                val shares = if (equal) emptyMap() else picked.associateWith { parseAmountToMinor(custom[it].orEmpty()) ?: 0L }
                onSend(total, note.trim().ifEmpty { null }, equal, if (equal) picked.associateWith { 0L } else shares)
                onDismiss()
            }
        }
    }
}

/** A big centred amount field, digits only, with the decimal point. */
@Composable
private fun AmountField(value: String, onChange: (String) -> Unit) {
    val focus = remember { androidx.compose.ui.focus.FocusRequester() }
    LaunchedEffect(Unit) { runCatching { focus.requestFocus() } }
    // The system field look (fill + hairline), sized for a big amount.
    Box(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(22.dp)).background(Ch.FieldFill).border(1.dp, Ch.FieldLine, RoundedCornerShape(22.dp)).padding(vertical = 16.dp),
        contentAlignment = Alignment.Center,
    ) {
        if (value.isEmpty()) Text("0.00", color = Ch.Soft, fontSize = 34.sp, fontWeight = FontWeight.ExtraBold)
        BasicTextField(
            value, { onChange(it.replace(',', '.').filter { c -> c.isDigit() || c == '.' }.take(12)) }, singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
            textStyle = TextStyle(color = Ch.Ink, fontSize = 34.sp, fontWeight = FontWeight.ExtraBold, fontFamily = CairoFontFamily, textAlign = TextAlign.Center),
            cursorBrush = SolidColor(MoneyDone), modifier = Modifier.fillMaxWidth().focusRequester(focus),
        )
    }
}

@Composable
private fun NoteField(value: String, hint: String, onChange: (String) -> Unit) {
    ChField(value, onChange, hint, icon = Icons.Rounded.EditNote)
}
