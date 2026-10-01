package com.dorr.app.ui.screens

import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.sp
import com.dorr.app.network.ApiClient
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.screens.wallet.formatMinor
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.AddCircle
import androidx.compose.material.icons.rounded.BarChart
import androidx.compose.material.icons.rounded.Build
import androidx.compose.material.icons.rounded.DirectionsCar
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.PhoneInTalk
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.dorr.app.R
import com.dorr.app.network.AuthSession
import com.dorr.app.network.ServiceDto
import com.dorr.app.ui.components.HeroBannerSlider
import com.dorr.app.ui.components.ServicesSection
import com.dorr.app.ui.components.StatChip
import com.dorr.app.ui.screens.profile.PinkBackdrop
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.theme.AppColors

@Composable
fun HomeScreen(
    onOpenAccount: () -> Unit,
    onOpenNotifications: () -> Unit,
    onOpenWallet: () -> Unit,
    onOpenServices: () -> Unit,
    onOpenService: (ServiceDto, Color) -> Unit,
    onOpenChat: () -> Unit = {},
) {
    Box(Modifier.fillMaxSize()) {
    PinkBackdrop(Modifier.matchParentSize())
    LazyColumn(modifier = Modifier.fillMaxSize()) {
        item {
            HomeHeader(
                onOpenAccount = onOpenAccount,
                onOpenNotifications = onOpenNotifications,
                onOpenWallet = onOpenWallet,
                onOpenChat = onOpenChat,
            )
        }
        item { Spacer(Modifier.height(14.dp)) }
        item {
            HeroBannerSlider(
                slides = listOf(
                    stringResource(R.string.banner_slide_welcome),
                    stringResource(R.string.banner_slide_offers),
                    stringResource(R.string.banner_slide_book),
                ),
                modifier = Modifier.padding(horizontal = 20.dp),
            )
        }
        item {
            HomeWalletCard(
                onOpenWallet = onOpenWallet,
                modifier = Modifier.padding(horizontal = 20.dp),
            )
        }
        item { Spacer(Modifier.height(12.dp)) }
        item {
            ServicesSection(
                onViewAll = onOpenServices,
                onOpenService = onOpenService,
                modifier = Modifier.padding(horizontal = 20.dp),
            )
        }
        item { Spacer(Modifier.height(24.dp)) }
        item { QuickActionsRow(modifier = Modifier.padding(horizontal = 20.dp)) }
        item { Spacer(Modifier.height(20.dp)) }
    }
    }
}

@Composable
private fun HomeHeader(onOpenAccount: () -> Unit, onOpenNotifications: () -> Unit, onOpenWallet: () -> Unit, onOpenChat: () -> Unit) {
    var hasUnread by remember { mutableStateOf(false) }
    val reconnectTick = collectReconnectTick()
    LaunchedEffect(reconnectTick) {
        hasUnread = runCatching {
            ApiClient.notifications.unreadCount("Bearer ${AuthSession.token.orEmpty()}").data?.count ?: 0
        }.getOrDefault(0) > 0
    }
    val night = settingsNight()
    val accent = if (night) AccountDark.accent else settingsAccent()
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 20.dp, vertical = 14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            modifier = Modifier
                .size(34.dp)
                .then(if (night) Modifier else Modifier.shadow(6.dp, CircleShape, spotColor = settingsAccent().copy(alpha = 0.08f)))
                .clip(CircleShape)
                .background(settingsCard())
                .then(if (night) Modifier.border(1.dp, AccountDark.line, CircleShape) else Modifier)
                .clickable(onClick = onOpenAccount),
            contentAlignment = Alignment.Center,
        ) {
            Icon(Icons.Rounded.Person, contentDescription = null, tint = accent, modifier = Modifier.size(18.dp))
        }
        Spacer(Modifier.width(10.dp))
        Text(
            text = stringResource(R.string.home_greeting, AuthSession.user?.name ?: stringResource(R.string.account_default_user)),
            color = accent,
            fontSize = 18.sp,
            fontWeight = FontWeight.ExtraBold,
            // End padding keeps a long name (cut with "...") clear of the wallet icon; it follows RTL.
            modifier = Modifier.weight(1f).padding(end = 10.dp),
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
        )
        Box(
            modifier = Modifier
                .size(34.dp)
                .then(if (night) Modifier else Modifier.shadow(6.dp, CircleShape, spotColor = settingsAccent().copy(alpha = 0.08f)))
                .clip(CircleShape)
                .background(settingsCard())
                .then(if (night) Modifier.border(1.dp, AccountDark.line, CircleShape) else Modifier)
                .clickable(onClick = onOpenWallet),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                Icons.Rounded.AccountBalanceWallet,
                contentDescription = stringResource(R.string.wallet_title),
                tint = accent,
                modifier = Modifier.size(18.dp),
            )
        }
        Spacer(Modifier.width(8.dp))
        com.dorr.app.ui.screens.chat.HomeChatButton(onClick = onOpenChat)
        Spacer(Modifier.width(8.dp))
        Box {
            Box(
                modifier = Modifier
                    .size(34.dp)
                    .then(if (night) Modifier else Modifier.shadow(6.dp, CircleShape, spotColor = settingsAccent().copy(alpha = 0.08f)))
                    .clip(CircleShape)
                    .background(settingsCard())
                    .then(if (night) Modifier.border(1.dp, AccountDark.line, CircleShape) else Modifier)
                    .clickable(onClick = onOpenNotifications),
                contentAlignment = Alignment.Center,
            ) {
                Icon(Icons.Rounded.Notifications, contentDescription = null, tint = accent, modifier = Modifier.size(18.dp))
            }
            if (hasUnread) {
                Box(
                    modifier = Modifier
                        .align(Alignment.TopEnd)
                        .padding(5.dp)
                        .size(8.dp)
                        .background(settingsAccent(), CircleShape)
                        .border(1.5.dp, settingsCard(), CircleShape),
                )
            }
        }
    }
}

@Composable
private fun HomeWalletCard(onOpenWallet: () -> Unit, modifier: Modifier = Modifier) {
    var balanceText by remember { mutableStateOf("0.00") }
    var currencyText by remember { mutableStateOf("") }
    val reconnectTick = collectReconnectTick()
    LaunchedEffect(reconnectTick) {
        runCatching { ApiClient.wallet.balance("Bearer ${AuthSession.token.orEmpty()}").data }.onSuccess { dto ->
            dto?.let {
                balanceText = formatMinor(it.totalMinor, null)
                currencyText = it.currencySymbol ?: it.currencyCode.orEmpty()
            }
        }
    }
    val night = settingsNight()
    val cardShape = RoundedCornerShape(20.dp)
    Row(
        modifier = modifier
            .fillMaxWidth()
            .padding(vertical = 12.dp)
            .then(if (night) Modifier else Modifier.shadow(10.dp, cardShape, spotColor = settingsAccent().copy(alpha = 0.08f)))
            .clip(cardShape)
            .background(settingsCard())
            .then(if (night) Modifier.border(1.dp, AccountDark.line, cardShape) else Modifier)
            .clickable(onClick = onOpenWallet)
            .padding(12.dp, 14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            modifier = Modifier
                .size(42.dp)
                .clip(RoundedCornerShape(15.dp))
                .background(if (night) AccountDark.well else settingsAccent().copy(alpha = 0.14f)),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                Icons.Rounded.AccountBalanceWallet,
                contentDescription = null,
                tint = if (night) AccountDark.accent else settingsAccent(),
                modifier = Modifier.size(22.dp),
            )
        }
        Spacer(Modifier.width(12.dp))
        Column(modifier = Modifier.weight(1f)) {
            Text(
                stringResource(R.string.home_wallet_balance),
                fontSize = 12.sp,
                fontWeight = FontWeight.SemiBold,
                color = if (night) AccountDark.mut else AppColors.textSecondary,
            )
            Row(verticalAlignment = Alignment.Bottom) {
                Text(
                    balanceText,
                    fontSize = 20.sp,
                    fontWeight = FontWeight.ExtraBold,
                    color = settingsInk(),
                    modifier = Modifier.alignByBaseline(),
                )
                if (currencyText.isNotBlank()) {
                    Spacer(Modifier.width(4.dp))
                    Text(
                        currencyText,
                        fontSize = 12.sp,
                        fontWeight = FontWeight.Bold,
                        color = if (night) AccountDark.mut else AppColors.textSecondary,
                        modifier = Modifier.alignByBaseline(),
                    )
                }
            }
        }
        Box(
            modifier = Modifier
                .shadow(8.dp, RoundedCornerShape(50), spotColor = settingsAccent().copy(alpha = 0.25f))
                .clip(RoundedCornerShape(50))
                .background(settingsAccent())
                .clickable(onClick = onOpenWallet)
                .padding(horizontal = 14.dp, vertical = 8.dp),
            contentAlignment = Alignment.Center,
        ) {
            Text(
                stringResource(R.string.home_wallet_topup),
                color = Color.White,
                fontSize = 12.sp,
                fontWeight = FontWeight.ExtraBold,
            )
        }
    }
}

@Composable
private fun QuickActionsRow(modifier: Modifier = Modifier) {
    Row(modifier = modifier, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        QuickActionCard(
            icon = Icons.Rounded.PhoneInTalk,
            label = stringResource(R.string.home_call),
            modifier = Modifier.weight(1f),
        )
        QuickActionCard(
            icon = Icons.Rounded.BarChart,
            label = stringResource(R.string.home_history),
            modifier = Modifier.weight(1f),
        )
    }
}

@Composable
private fun QuickActionCard(icon: ImageVector, label: String, modifier: Modifier = Modifier) {
    val night = settingsNight()
    val shape = RoundedCornerShape(14.dp)
    Column(
        modifier = modifier
            .then(if (night) Modifier else Modifier.shadow(6.dp, shape, spotColor = settingsAccent().copy(alpha = 0.07f)))
            .clip(shape)
            .background(settingsCard())
            .then(if (night) Modifier.border(1.dp, AccountDark.line, shape) else Modifier)
            .clickable { /* placeholder */ }
            .padding(12.dp)
            .heightIn(min = 96.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        Box(
            modifier = Modifier
                .size(34.dp)
                .clip(RoundedCornerShape(10.dp))
                .background(if (night) AccountDark.well else settingsAccent().copy(alpha = 0.14f)),
            contentAlignment = Alignment.Center,
        ) {
            Icon(icon, contentDescription = null, tint = if (night) AccountDark.accent else settingsAccent(), modifier = Modifier.size(18.dp))
        }
        Spacer(Modifier.height(10.dp))
        Text(
            label,
            fontSize = 13.sp,
            fontWeight = FontWeight.Bold,
            color = settingsInk(),
        )
    }
}
