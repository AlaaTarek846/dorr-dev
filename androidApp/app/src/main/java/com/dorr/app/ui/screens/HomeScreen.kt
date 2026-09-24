package com.dorr.app.ui.screens

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
        item { Spacer(Modifier.height(16.dp)) }
        item {
            HeroBannerSlider(
                slides = listOf("Welcome", "Special offers", "Book a service"),
                modifier = Modifier.padding(horizontal = 20.dp),
            )
        }
        item { Spacer(Modifier.height(24.dp)) }
        item { ServicesSection(onViewAll = onOpenServices, modifier = Modifier.padding(horizontal = 20.dp)) }
        item { Spacer(Modifier.height(24.dp)) }
        item { QuickActionsRow(modifier = Modifier.padding(horizontal = 20.dp)) }
        item { Spacer(Modifier.height(20.dp)) }
    }
}

@Composable
private fun HomeHeader(onOpenAccount: () -> Unit, onOpenNotifications: () -> Unit, onOpenWallet: () -> Unit) {
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(bottomStart = 28.dp, bottomEnd = 28.dp))
            .background(AppColors.headerBackground)
            .padding(20.dp, 16.dp, 20.dp, 24.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(
                modifier = Modifier
                    .size(48.dp)
                    .clip(CircleShape)
                    .background(Color.White.copy(alpha = 0.2f))
                    .clickable(onClick = onOpenAccount),
                contentAlignment = Alignment.Center,
            ) {
                Icon(Icons.Rounded.Person, contentDescription = null, tint = Color.White)
            }
            Spacer(Modifier.width(14.dp))
            Text(
                text = stringResource(R.string.home_greeting, stringResource(R.string.account_default_user)),
                color = MaterialTheme.colorScheme.primary,
                style = MaterialTheme.typography.titleLarge,
                fontWeight = FontWeight.Bold,
                modifier = Modifier.weight(1f),
                maxLines = 1,
            )
            IconButton(
                onClick = onOpenWallet,
                modifier = Modifier
                    .size(42.dp)
                    .clip(RoundedCornerShape(14.dp))
                    .background(Color.White.copy(alpha = 0.15f)),
            ) {
                Icon(
                    Icons.Rounded.AccountBalanceWallet,
                    contentDescription = stringResource(R.string.wallet_title),
                    tint = Color.White,
                )
            }
            Spacer(Modifier.width(8.dp))
            Box {
                IconButton(
                    onClick = onOpenNotifications,
                    modifier = Modifier
                        .size(42.dp)
                        .clip(RoundedCornerShape(14.dp))
                        .background(Color.White.copy(alpha = 0.15f)),
                ) {
                    Icon(Icons.Rounded.Notifications, contentDescription = null, tint = Color.White)
                }
                // Static placeholder — wire to the real unread count once
                // notifications come from a backend instead of sample data.
                Box(
                    modifier = Modifier
                        .align(Alignment.TopEnd)
                        .padding(6.dp)
                        .size(10.dp)
                        .background(AppColors.danger, CircleShape)
                        .border(1.5.dp, Color.White, CircleShape),
                )
            }
        }
        Spacer(Modifier.height(18.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            StatChip(
                icon = Icons.Rounded.Build,
                value = "0",
                label = stringResource(R.string.home_active_jobs),
                modifier = Modifier.weight(1f),
            )
            StatChip(
                icon = Icons.Rounded.DirectionsCar,
                value = "0",
                label = stringResource(R.string.home_my_items),
                modifier = Modifier.weight(1f),
            )
            StatChip(
                icon = Icons.Rounded.AddCircle,
                value = "+",
                label = stringResource(R.string.home_new_request),
                modifier = Modifier.weight(1f),
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
            color = AppColors.primary,
            modifier = Modifier.weight(1f),
        )
        QuickActionCard(
            icon = Icons.Rounded.BarChart,
            label = stringResource(R.string.home_history),
            color = AppColors.accent,
            modifier = Modifier.weight(1f),
        )
    }
}

@Composable
private fun QuickActionCard(icon: ImageVector, label: String, color: Color, modifier: Modifier = Modifier) {
    Column(
        modifier = modifier
            .clip(RoundedCornerShape(16.dp))
            .background(color.copy(alpha = 0.08f))
            .clickable { /* placeholder */ }
            .padding(vertical = 12.dp, horizontal = 6.dp)
            .height(96.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        Icon(icon, contentDescription = null, tint = color)
        Spacer(Modifier.height(6.dp))
        Text(label, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
    }
}
