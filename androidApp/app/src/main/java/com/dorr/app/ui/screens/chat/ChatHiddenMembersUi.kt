package com.dorr.app.ui.screens.chat

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.VisibilityOff
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
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.HiddenMembersPriceDto
import com.dorr.app.ui.screens.wallet.CheckoutLauncher
import com.dorr.app.ui.screens.wallet.CheckoutRequest
import com.dorr.app.ui.screens.wallet.formatMinor

/**
 * Hidden members — a paid group setting, bought once on the wallet's payment screen: members'
 * numbers hidden from each other, no private chats from the group, names still shown. A failed
 * or cancelled payment leaves an ordinary group; the server tells everyone when it's on
 * (`chat.conversation.updated`), so nothing here waits or asks again.
 */
internal object HiddenMembers {
    private const val PURPOSE = "chat_group_hidden_members"

    fun pay(groupId: String, onPaid: () -> Unit) {
        CheckoutLauncher.open(CheckoutRequest(PURPOSE, mapOf("group_id" to groupId))) { onPaid() }
    }
}

/** "50.00 EGP" where it's sold, else null (the option isn't shown). */
internal fun HiddenMembersPriceDto.label(): String? =
    amountMinor?.takeIf { available && it > 0 }?.let { formatMinor(it, currencySymbol ?: currencyCode) }

/** Its price where I am — asked once when the screen opens. */
@Composable
internal fun rememberHiddenMembersPrice(): HiddenMembersPriceDto? {
    var price by remember { mutableStateOf<HiddenMembersPriceDto?>(null) }
    LaunchedEffect(Unit) { price = runCatching { ApiClient.chat.hiddenMembersPrice(chatAuth()).data }.getOrNull() }
    return price
}

/** The choice on the new-group page: a card with the price, and what happens if paying fails. */
@Composable
internal fun HiddenMembersChoice(price: String, checked: Boolean, onChange: (Boolean) -> Unit) {
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp))
            .background(if (checked) Ch.Red.copy(alpha = 0.06f) else Ch.Surface)
            .border(1.dp, if (checked) Ch.Red.copy(alpha = 0.35f) else Ch.Line, RoundedCornerShape(18.dp))
            .clickable { onChange(!checked) }
            .padding(14.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(36.dp).clip(RoundedCornerShape(12.dp)).background(Ch.Red.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.VisibilityOff, null, tint = Ch.Red, modifier = Modifier.size(19.dp))
            }
            Spacer(Modifier.width(12.dp))
            Text(stringResource(R.string.ch_hidden_members), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp, modifier = Modifier.weight(1f))
            ChSwitch(checked)
        }
        Text(stringResource(R.string.ch_hidden_members_sub, price), color = Ch.Mut, fontSize = 12.5.sp, lineHeight = 17.sp, modifier = Modifier.padding(top = 8.dp))
        AnimatedVisibility(checked, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
            Text(stringResource(R.string.ch_hidden_members_fail_note), color = Ch.Soft, fontSize = 11.5.sp, lineHeight = 16.sp, modifier = Modifier.padding(top = 6.dp))
        }
    }
}
