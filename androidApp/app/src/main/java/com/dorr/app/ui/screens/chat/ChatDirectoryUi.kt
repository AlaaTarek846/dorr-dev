package com.dorr.app.ui.screens.chat

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.spring
import androidx.compose.animation.animateColorAsState
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
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Category
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
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
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.CategoryDto
import com.dorr.app.network.VerificationDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.portals.PlanCard
import com.dorr.app.ui.screens.wallet.CheckoutLauncher
import com.dorr.app.ui.screens.wallet.CheckoutRequest
import java.text.DateFormat
import java.time.OffsetDateTime
import java.util.Date

/** The ✔ next to a verified channel's name (like WhatsApp's). */
@Composable
fun VerifiedBadge(size: Dp = 16.dp, tint: Color = VerifiedGreen) {
    Icon(Icons.Rounded.Verified, stringResource(R.string.ch_verified), tint = tint, modifier = Modifier.size(size))
}

val VerifiedGreen = Color(0xFF1FAA59)

/** A category as a chip (its icon + name) — channel directory filters and the create sheet. */
@Composable
internal fun ChCategoryChip(icon: String?, label: String, selected: Boolean, onClick: () -> Unit) {
    val bg by animateColorAsState(if (selected) Ch.Red else Ch.Surface, label = "catChip")
    Row(
        Modifier.clip(RoundedCornerShape(50)).background(bg).border(1.dp, if (selected) Color.Transparent else Ch.Line, RoundedCornerShape(50))
            .clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 7.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (icon != null) {
            AsyncImage(icon, null, Modifier.size(18.dp))
            Spacer(Modifier.width(6.dp))
        }
        Text(label, color = if (selected) Color.White else Ch.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold)
    }
}

/** A row of category chips; `null` = none / all. */
@Composable
internal fun ChCategoryChips(categories: List<CategoryDto>, selected: Int?, allLabel: String, onPick: (Int?) -> Unit) {
    if (categories.isEmpty()) return
    LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        item { ChCategoryChip(null, allLabel, selected == null) { onPick(null) } }
        items(categories, key = { it.id }) { c -> ChCategoryChip(c.icon, c.name.orEmpty(), selected == c.id) { onPick(if (selected == c.id) null else c.id) } }
    }
}

/** A directory section header: the category's icon and name. */
@Composable
internal fun ChCategoryHeader(category: CategoryDto?) {
    Row(Modifier.fillMaxWidth().padding(top = 10.dp, bottom = 2.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(26.dp).clip(RoundedCornerShape(8.dp)).background(Ch.Red.copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
            val icon = category?.icon
            if (icon != null) AsyncImage(icon, null, Modifier.size(19.dp)) else Icon(Icons.Rounded.Category, null, tint = Ch.Red, modifier = Modifier.size(17.dp))
        }
        Spacer(Modifier.width(8.dp))
        Text(category?.name ?: stringResource(R.string.ch_channels_other), color = Ch.Ink, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
    }
}

/**
 * "Verify my channel" (owner): where its ✔ stands and the packages to buy it with — paid on the one
 * payment screen. Like WhatsApp's Meta Verified: the ✔ stays while a paid period runs.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ChannelVerificationSheet(conversationId: String, onDismiss: () -> Unit, onPaid: () -> Unit) {
    var data by remember { mutableStateOf<VerificationDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var chosen by remember { mutableStateOf<Int?>(null) }
    LaunchedEffect(Unit) {
        runCatching { ApiClient.discover.channelVerification(chatAuth(), conversationId).data }
            .onSuccess { data = it; chosen = it?.packages?.firstOrNull()?.id }
            .onFailure { error = it.apiFailure().message }
    }
    val pop = remember { Animatable(0.4f) }
    LaunchedEffect(Unit) { pop.animateTo(1f, spring(dampingRatio = 0.4f, stiffness = 260f)) }

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 18.dp).navigationBarsPadding(),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Box(Modifier.size(84.dp).scale(pop.value).clip(CircleShape).background(VerifiedGreen.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                VerifiedBadge(size = 52.dp)
            }
            Spacer(Modifier.height(10.dp))
            Text(stringResource(R.string.ch_verify_title), color = Ch.Ink, fontSize = 20.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.ch_verify_sub), color = Ch.Mut, fontSize = 13.sp, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 4.dp))
            Spacer(Modifier.height(12.dp))
            val d = data
            if (d != null) {
                val status = when {
                    d.verifiedByAdmin -> stringResource(R.string.ch_verified_by_dorr)
                    d.isVerified -> stringResource(R.string.ch_verified_until, shortDate(d.verifiedUntil))
                    else -> stringResource(R.string.ch_not_verified)
                }
                Text(
                    status, color = if (d.isVerified) VerifiedGreen else Ch.Mut, fontSize = 13.sp, fontWeight = FontWeight.Bold,
                    modifier = Modifier.clip(RoundedCornerShape(12.dp)).background((if (d.isVerified) VerifiedGreen else Ch.Mut).copy(alpha = 0.1f)).padding(horizontal = 12.dp, vertical = 6.dp),
                )
                Spacer(Modifier.height(14.dp))
                if (d.packages.isEmpty()) {
                    Text(stringResource(R.string.ch_verify_no_plans), color = Ch.Mut, fontSize = 13.sp, textAlign = TextAlign.Center)
                } else {
                    d.packages.forEach { p -> PlanCard(p, selected = chosen == p.id, modifier = Modifier.padding(bottom = 10.dp)) { chosen = p.id } }
                    Spacer(Modifier.height(6.dp))
                    ChPrimaryButton(stringResource(R.string.ch_verify_continue), icon = Icons.Rounded.Verified, modifier = Modifier.fillMaxWidth(), enabled = chosen != null) {
                        val id = chosen ?: return@ChPrimaryButton
                        // The payment screen draws over the app, not over this sheet's window: close it first.
                        onDismiss()
                        CheckoutLauncher.open(CheckoutRequest("chat_channel_verification", mapOf("channel_id" to conversationId, "package_id" to id))) { onPaid() }
                    }
                }
            } else if (error != null) {
                Text(error.orEmpty(), color = Ch.Danger, fontSize = 13.sp)
            } else {
                com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().height(150.dp), RoundedCornerShape(20.dp))
            }
        }
    }
}

private fun shortDate(iso: String?): String = runCatching {
    DateFormat.getDateInstance(DateFormat.MEDIUM).format(Date.from(OffsetDateTime.parse(iso).toInstant()))
}.getOrDefault("")
