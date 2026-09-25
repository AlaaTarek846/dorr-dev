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
import com.dorr.app.ui.components.HeroBannerSlider
import com.dorr.app.ui.components.ServicesSection
import com.dorr.app.ui.components.StatChip
import com.dorr.app.ui.theme.AppColors

@Composable
fun HomeScreen(
    onOpenAccount: () -> Unit,
    onOpenNotifications: () -> Unit,
    onOpenWallet: () -> Unit,
    onOpenServices: () -> Unit,
) {
    LazyColumn(modifier = Modifier.fillMaxSize()) {
        item {
            HomeHeader(
                onOpenAccount = onOpenAccount,
                onOpenNotifications = onOpenNotifications,
                onOpenWallet = onOpenWallet,
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
        item { ServicesSection(onViewAll = onOpenServices, modifier = Modifier.padding(horizontal = 20.dp)) }
        item { Spacer(Modifier.height(24.dp)) }
        item { QuickActionsRow(modifier = Modifier.padding(horizontal = 20.dp)) }
        item { Spacer(Modifier.height(20.dp)) }
    }
}

@Composable
private fun HomeHeader(onOpenAccount: () -> Unit, onOpenNotifications: () -> Unit, onOpenWallet: () -> Unit) {
    var hasUnread by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) {
        hasUnread = runCatching {
            ApiClient.notifications.unreadCount("Bearer ${AuthSession.token.orEmpty()}").data?.count ?: 0
        }.getOrDefault(0) > 0
    }
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 20.dp, vertical = 14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            modifier = Modifier
                .size(34.dp)
                .shadow(6.dp, CircleShape, spotColor = AppColors.waRed.copy(alpha = 0.08f))
                .clip(CircleShape)
                .background(Color.White)
                .clickable(onClick = onOpenAccount),
            contentAlignment = Alignment.Center,
        ) {
            Icon(Icons.Rounded.Person, contentDescription = null, tint = AppColors.waRed, modifier = Modifier.size(18.dp))
        }
        Spacer(Modifier.width(10.dp))
        Text(
            text = stringResource(R.string.home_greeting, AuthSession.user?.name ?: stringResource(R.string.account_default_user)),
            color = AppColors.waRed,
            fontSize = 18.sp,
            fontWeight = FontWeight.ExtraBold,
            modifier = Modifier.weight(1f),
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
        )
        Box(
            modifier = Modifier
                .size(34.dp)
                .shadow(6.dp, CircleShape, spotColor = AppColors.waRed.copy(alpha = 0.08f))
                .clip(CircleShape)
                .background(Color.White)
                .clickable(onClick = onOpenWallet),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                Icons.Rounded.AccountBalanceWallet,
                contentDescription = stringResource(R.string.wallet_title),
                tint = AppColors.waRed,
                modifier = Modifier.size(18.dp),
            )
        }
        Spacer(Modifier.width(8.dp))
        Box {
            Box(
                modifier = Modifier
                    .size(34.dp)
                    .shadow(6.dp, CircleShape, spotColor = AppColors.waRed.copy(alpha = 0.08f))
                    .clip(CircleShape)
                    .background(Color.White)
                    .clickable(onClick = onOpenNotifications),
                contentAlignment = Alignment.Center,
            ) {
                Icon(Icons.Rounded.Notifications, contentDescription = null, tint = AppColors.waRed, modifier = Modifier.size(18.dp))
            }
            if (hasUnread) {
                Box(
                    modifier = Modifier
                        .align(Alignment.TopEnd)
                        .padding(5.dp)
                        .size(8.dp)
                        .background(AppColors.waRed, CircleShape)
                        .border(1.5.dp, Color.White, CircleShape),
                )
            }
        }
    }
}

@Composable
private fun HomeWalletCard(onOpenWallet: () -> Unit, modifier: Modifier = Modifier) {
    var balanceText by remember { mutableStateOf("0.00") }
    var currencyText by remember { mutableStateOf("") }
    LaunchedEffect(Unit) {
        runCatching { ApiClient.wallet.balance("Bearer ${AuthSession.token.orEmpty()}").data }.onSuccess { dto ->
            dto?.let {
                balanceText = formatMinor(it.totalMinor, null)
                currencyText = it.currencySymbol ?: it.currencyCode.orEmpty()
            }
        }
    }
    Row(
        modifier = modifier
            .fillMaxWidth()
            .padding(vertical = 12.dp)
            .shadow(10.dp, RoundedCornerShape(20.dp), spotColor = AppColors.waRed.copy(alpha = 0.08f))
            .clip(RoundedCornerShape(20.dp))
            .background(Color.White)
            .clickable(onClick = onOpenWallet)
            .padding(12.dp, 14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            modifier = Modifier
                .size(42.dp)
                .clip(RoundedCornerShape(15.dp))
                .background(Color(0xFFFDE8EC)),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                Icons.Rounded.AccountBalanceWallet,
                contentDescription = null,
                tint = AppColors.waRed,
                modifier = Modifier.size(22.dp),
            )
        }
        Spacer(Modifier.width(12.dp))
        Column(modifier = Modifier.weight(1f)) {
            Text(
                stringResource(R.string.home_wallet_balance),
                fontSize = 12.sp,
                fontWeight = FontWeight.SemiBold,
                color = AppColors.textSecondary,
            )
            Row(verticalAlignment = Alignment.Bottom) {
                Text(
                    balanceText,
                    fontSize = 20.sp,
                    fontWeight = FontWeight.ExtraBold,
                    color = AppColors.textPrimary,
                    modifier = Modifier.alignByBaseline(),
                )
                if (currencyText.isNotBlank()) {
                    Spacer(Modifier.width(4.dp))
                    Text(
                        currencyText,
                        fontSize = 12.sp,
                        fontWeight = FontWeight.Bold,
                        color = AppColors.textSecondary,
                        modifier = Modifier.alignByBaseline(),
                    )
                }
            }
        }
        Box(
            modifier = Modifier
                .shadow(8.dp, RoundedCornerShape(50), spotColor = AppColors.waRed.copy(alpha = 0.25f))
                .clip(RoundedCornerShape(50))
                .background(AppColors.waRed)
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
    Column(
        modifier = modifier
            .shadow(6.dp, RoundedCornerShape(14.dp), spotColor = AppColors.waRed.copy(alpha = 0.07f))
            .clip(RoundedCornerShape(14.dp))
            .background(Color.White)
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
                .background(Color(0xFFFDE8EC)),
            contentAlignment = Alignment.Center,
        ) {
            Icon(icon, contentDescription = null, tint = AppColors.waRed, modifier = Modifier.size(18.dp))
        }
        Spacer(Modifier.height(10.dp))
        Text(
            label,
            fontSize = 13.sp,
            fontWeight = FontWeight.Bold,
            color = AppColors.textPrimary,
        )
    }
}
