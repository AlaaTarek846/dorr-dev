package com.dorr.app.ui.screens.freelance

import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Loyalty
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.FlLoyaltyCardDto
import com.dorr.app.network.FlLoyaltyViewDto
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.screens.profile.SubHeader
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.screens.profile.settingsSurface
import kotlinx.coroutines.CancellationException
import java.util.Locale

/*
 * FL-L09 (part B), the client's side of the loyalty program: a freelancer rewards clients who come back with a discount that grows with the
 * jobs finished together. The server applies it to a project offer, a ready-made service and a consultation; here the client sees it — "My
 * discounts" (every freelancer they worked with who runs a program), the badge and the struck-through usual price wherever a price shows, and
 * a card with the ladder and the progress to the next step on a service, a consultation and a profile. Read on open and when the connection
 * returns — never on a timer.
 */

private val LOYC_GREEN = Color(0xFF16A34A)

/** 1000 basis points → "10", 1250 → "12.5". */
internal fun loycPercent(bp: Int): String = if (bp % 100 == 0) (bp / 100).toString() else String.format(Locale.US, "%.1f", bp / 100.0)

/** A small green pill: "10% off". */
@Composable
internal fun LoyaltyBadge(bp: Int, modifier: Modifier = Modifier) {
    Row(
        modifier.clip(RoundedCornerShape(999.dp)).background(LOYC_GREEN.copy(alpha = if (settingsNight()) 0.22f else 0.12f)).padding(horizontal = 8.dp, vertical = 3.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        Icon(Icons.Rounded.Loyalty, null, tint = LOYC_GREEN, modifier = Modifier.size(12.dp))
        Text(stringResource(R.string.loyc_badge, loycPercent(bp)), color = LOYC_GREEN, fontSize = 11.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
    }
}

/** The price I pay, and (when a loyalty step took something off) the usual one struck through beside it. */
@Composable
internal fun LoyaltyPrice(price: String, list: String?, color: Color, size: TextUnit = 15.sp, modifier: Modifier = Modifier) {
    Row(modifier, verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(price, color = color, fontSize = size, fontWeight = FontWeight.Black)
        if (list != null) Text(list, color = settingsMut(), fontSize = (size.value * 0.78f).sp, textDecoration = TextDecoration.LineThrough)
    }
}

/** Under a price: the badge and the usual price struck through (nothing when there is no discount). */
@Composable
internal fun LoyaltyWasLine(bp: Int?, list: String?, modifier: Modifier = Modifier) {
    if (bp == null || list == null) return
    Row(modifier, verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        LoyaltyBadge(bp)
        Text(list, color = settingsMut(), fontSize = 12.sp, textDecoration = TextDecoration.LineThrough)
    }
}

/** "Includes your loyalty discount of 10%." in the green notice style, for a sheet that confirms a price. */
@Composable
internal fun LoyaltyOfferNote(bp: Int?) {
    if (bp == null) return
    FlNotice(stringResource(R.string.loyc_offer_note, loycPercent(bp)), Icons.Rounded.Loyalty, LOYC_GREEN)
}

/** The discount in a few words under a length's price, on a chip that is filled (white) or not (green). */
@Composable
internal fun LoyaltyMini(bp: Int?, onAccent: Boolean) {
    if (bp == null) return
    Text(stringResource(R.string.loyc_badge, loycPercent(bp)), color = if (onAccent) Color.White else LOYC_GREEN, fontSize = 10.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
}

/** A line of a price breakdown: "Loyalty discount 10%  −50.00 SAR", in green. */
@Composable
internal fun LoyaltyDiscountLine(bp: Int?, saving: String) {
    if (bp == null) return
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Text(stringResource(R.string.loyc_line_discount, loycPercent(bp)), color = LOYC_GREEN, fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
        Text("−$saving", color = LOYC_GREEN, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
    }
}

/** The bar toward the next step (0–1), filling in when it shows. */
@Composable
private fun LoycBar(progress: Float) {
    val shown by animateFloatAsState(progress.coerceIn(0f, 1f), tween(600), label = "loyc-bar")
    Box(Modifier.fillMaxWidth().height(8.dp).clip(CircleShape).background(LOYC_GREEN.copy(alpha = 0.14f))) {
        Box(Modifier.fillMaxHeight().fillMaxWidth(shown.coerceAtLeast(0.04f)).clip(CircleShape).background(LOYC_GREEN))
    }
}

/** The steps as chips: the ones I reached are filled. */
@Composable
private fun LoycLadder(view: FlLoyaltyViewDto) {
    Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        view.tiers.forEach { t ->
            val on = t.reached
            Row(
                Modifier.clip(RoundedCornerShape(12.dp)).background(if (on) LOYC_GREEN else LOYC_GREEN.copy(alpha = 0.08f)).padding(horizontal = 10.dp, vertical = 6.dp),
                verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(5.dp),
            ) {
                if (on) Icon(Icons.Rounded.CheckCircle, null, tint = Color.White, modifier = Modifier.size(13.dp))
                Column {
                    Text(stringResource(R.string.loyc_step_percent, loycPercent(t.discountBp)), color = if (on) Color.White else LOYC_GREEN, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
                    Text(stringResource(R.string.loyc_step_after, t.after), color = if (on) Color.White.copy(alpha = 0.9f) else settingsMut(), fontSize = 10.5.sp)
                }
            }
        }
    }
}

/** What I hold now and what is next, as one or two lines. */
@Composable
private fun loycStatus(view: FlLoyaltyViewDto): String {
    val current = view.current
    val next = view.next
    return when {
        current != null && next == null -> stringResource(R.string.loyc_status_top, loycPercent(current.discountBp))
        current != null && next != null -> stringResource(R.string.loyc_status_current, loycPercent(current.discountBp))
        next != null && view.works == 0 -> stringResource(R.string.loyc_status_start, next.after, loycPercent(next.discountBp))
        else -> stringResource(R.string.loyc_status_none)
    }
}

/**
 * The program as a card — on a service, a consultation booking and a profile: what I get, how far I am from the next step and the whole
 * ladder. Nothing is drawn when the freelancer runs no program.
 */
@Composable
internal fun LoyaltyCard(view: FlLoyaltyViewDto?, modifier: Modifier = Modifier) {
    if (view == null || view.tiers.isEmpty()) return
    Column(
        modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(LOYC_GREEN.copy(alpha = if (settingsNight()) 0.18f else 0.08f)).padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Box(Modifier.size(38.dp).clip(RoundedCornerShape(12.dp)).background(LOYC_GREEN.copy(alpha = 0.18f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Loyalty, null, tint = LOYC_GREEN, modifier = Modifier.size(21.dp))
            }
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.loyc_card_title), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                Text(loycStatus(view), color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp)
            }
            view.current?.let { LoyaltyBadge(it.discountBp) }
        }
        view.next?.let { next ->
            LoycBar(view.progress.toFloat())
            Text(stringResource(R.string.loyc_next_line, next.remaining ?: (next.after - view.works).coerceAtLeast(1), loycPercent(next.discountBp)), color = LOYC_GREEN, fontSize = 12.sp, fontWeight = FontWeight.Bold)
        }
        LoycLadder(view)
        Text(stringResource(R.string.loyc_card_hint), color = settingsMut(), fontSize = 11.sp, lineHeight = 16.sp)
    }
}

// ==================================================================== My discounts

@Composable
internal fun LoyaltyPage(onBack: () -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val reconnect = collectReconnectTick()
    var cards by remember { mutableStateOf<List<FlLoyaltyCardDto>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(reconnect) {
        try {
            cards = ApiClient.freelanceLoyalty.mine(auth()).data.orEmpty()
            error = null
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message(generic)
        }
    }

    Column(Modifier.fillMaxSize()) {
        FlPageHeader(stringResource(R.string.loyc_title), onBack)
        val rows = cards
        if (rows == null) {
            Box(Modifier.weight(1f)) { if (error == null) FlSkeletonList() else FlLoading(error) }
        } else LazyColumn(
            Modifier.weight(1f).fillMaxWidth(),
            contentPadding = PaddingValues(horizontal = 16.dp, vertical = 10.dp), verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            item { FlIntro(Icons.Rounded.Loyalty, stringResource(R.string.loyc_intro_title), stringResource(R.string.loyc_intro_body)) }
            if (rows.isEmpty()) item {
                com.dorr.app.ui.screens.wallet.WaEmpty(
                    Icons.Rounded.Loyalty, com.dorr.app.ui.screens.wallet.Tone.Green,
                    stringResource(R.string.loyc_empty_title), stringResource(R.string.loyc_empty_body),
                )
            }
            items(rows, key = { it.freelancer.uuid.orEmpty() }) { c -> LoycFreelancerCard(c) }
        }
    }
}

@Composable
private fun LoycFreelancerCard(c: FlLoyaltyCardDto) {
    val view = c.view()
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).padding(14.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            FlAvatar(c.freelancer.avatarUrl, c.freelancer.name, 44)
            Column(Modifier.weight(1f)) {
                Text(c.freelancer.name.orEmpty(), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(stringResource(R.string.loyc_works_n, c.works), color = settingsMut(), fontSize = 12.sp)
            }
            val current = c.current
            if (current != null) LoyaltyBadge(current.discountBp)
            else Text(stringResource(R.string.loyc_no_discount), color = settingsMut(), fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold)
        }
        c.next?.let { next ->
            Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                LoycBar(c.progress.toFloat())
                Text(stringResource(R.string.loyc_next_line, next.remaining ?: (next.after - c.works).coerceAtLeast(1), loycPercent(next.discountBp)), color = LOYC_GREEN, fontSize = 12.sp, fontWeight = FontWeight.Bold)
            }
        } ?: Text(stringResource(R.string.loyc_status_top, loycPercent(c.current?.discountBp ?: 0)), color = LOYC_GREEN, fontSize = 12.sp, fontWeight = FontWeight.Bold)
        LoycLadder(view)
    }
}
