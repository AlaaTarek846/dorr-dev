package com.dorr.app.ui.screens

import android.widget.Toast
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.slideInHorizontally
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.CreditCard
import androidx.compose.material.icons.rounded.DirectionsCar
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material.icons.rounded.LocalOffer
import androidx.compose.material.icons.rounded.LocationOn
import androidx.compose.material.icons.rounded.Star
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.sp
import com.dorr.app.network.ApiClient
import com.dorr.app.ui.screens.wallet.formatMinor
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Build
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.DarkMode
import androidx.compose.material.icons.rounded.DeleteForever
import androidx.compose.material.icons.rounded.Help
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.Language
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Logout
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Settings
import com.dorr.app.ui.components.SettingsScaffold
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import com.dorr.app.R
import com.dorr.app.network.AuthSession
import com.dorr.app.ui.locale.LocalAppLanguage
import com.dorr.app.ui.screens.profile.AddressesScreen
import com.dorr.app.ui.screens.profile.ContactUsSheet
import com.dorr.app.ui.screens.profile.FaqSheet
import com.dorr.app.ui.screens.profile.NotificationSettingsScreen
import com.dorr.app.ui.screens.profile.PersonalDataScreen
import com.dorr.app.ui.screens.profile.PrivacyPolicyScreen
import com.dorr.app.ui.screens.wallet.WalletPinSettingsScreen
import com.dorr.app.ui.components.DorrLogo
import com.dorr.app.ui.theme.AppColors
import com.dorr.app.ui.theme.LocalThemeState

private enum class ProfileSub { NONE, PERSONAL_DATA, NOTIFICATIONS, WALLET_PIN, PRIVACY, ADDRESSES, SETTINGS }

private data class MenuEntry(
    val icon: ImageVector,
    val label: Int,
    val subtitle: Int? = null,
    val danger: Boolean = false,
    val trailing: (@Composable () -> Unit)? = null,
    val onClick: () -> Unit = {},
)

@Composable
fun ProfileScreen(onLogout: () -> Unit, onOpenWallet: () -> Unit) {
    var subScreen by remember { mutableStateOf(ProfileSub.NONE) }

    when (subScreen) {
        ProfileSub.PERSONAL_DATA -> {
            val context = LocalContext.current
            PersonalDataScreen(
                onBack = { subScreen = ProfileSub.NONE },
                onSaved = { message -> Toast.makeText(context, message, Toast.LENGTH_SHORT).show() },
            )
        }
        ProfileSub.NOTIFICATIONS -> NotificationSettingsScreen(onBack = { subScreen = ProfileSub.NONE })
        ProfileSub.WALLET_PIN -> {
            val context = LocalContext.current
            WalletPinSettingsScreen(
                onBack = { subScreen = ProfileSub.NONE },
                onSaved = { message -> Toast.makeText(context, message, Toast.LENGTH_SHORT).show() },
            )
        }
        ProfileSub.PRIVACY -> PrivacyPolicyScreen(onBack = { subScreen = ProfileSub.NONE })
        ProfileSub.ADDRESSES -> AddressesScreen(onBack = { subScreen = ProfileSub.NONE })
        ProfileSub.SETTINGS -> SettingsMenuScreen(
            onBack = { subScreen = ProfileSub.NONE },
            onLogout = onLogout,
            onOpenPersonalData = { subScreen = ProfileSub.PERSONAL_DATA },
            onOpenNotifications = { subScreen = ProfileSub.NOTIFICATIONS },
            onOpenWalletPin = { subScreen = ProfileSub.WALLET_PIN },
            onOpenPrivacy = { subScreen = ProfileSub.PRIVACY },
        )
        ProfileSub.NONE -> ProfileMenuScreen(
            onOpenPersonalData = { subScreen = ProfileSub.PERSONAL_DATA },
            onOpenSettings = { subScreen = ProfileSub.SETTINGS },
            onOpenAddresses = { subScreen = ProfileSub.ADDRESSES },
            onOpenWallet = onOpenWallet,
        )
    }
}

@Composable
private fun ProfileMenuScreen(
    onOpenPersonalData: () -> Unit,
    onOpenSettings: () -> Unit,
    onOpenAddresses: () -> Unit,
    onOpenWallet: () -> Unit,
) {
    val context = LocalContext.current
    val comingSoon = stringResource(R.string.services_coming_soon)
    fun toastComingSoon() = Toast.makeText(context, comingSoon, Toast.LENGTH_SHORT).show()

    var showFaqSheet by remember { mutableStateOf(false) }

    val menuItems = listOf(
        MenuEntry(Icons.Rounded.Person, R.string.account_personal_data, R.string.account_personal_data_sub, onClick = onOpenPersonalData),
        MenuEntry(Icons.Rounded.Settings, R.string.account_settings, R.string.account_settings_sub, onClick = onOpenSettings),
        MenuEntry(Icons.Rounded.LocationOn, R.string.account_places, R.string.account_places_sub, onClick = onOpenAddresses),
        MenuEntry(Icons.Rounded.CreditCard, R.string.account_payments, R.string.account_payments_sub, onClick = ::toastComingSoon),
        MenuEntry(Icons.Rounded.LocalOffer, R.string.account_promo, R.string.account_promo_sub, onClick = ::toastComingSoon),
        MenuEntry(Icons.Rounded.Help, R.string.account_help, R.string.account_help_sub) { showFaqSheet = true },
    )

    LazyColumn(
        modifier = Modifier
            .fillMaxSize()
            .drawBehind {
                // 1:1 port of the account tab's pink radial-gradient background.
                drawRect(Color.White)
                drawRect(
                    Brush.radialGradient(
                        colors = listOf(AppColors.otpGlowDeep, AppColors.otpGlowSoft, Color.Transparent),
                        center = Offset(size.width * -0.08f, size.height * -0.12f),
                        radius = size.width * 1.3f,
                    ),
                )
                drawRect(
                    Brush.radialGradient(
                        colors = listOf(AppColors.otpPinkBorder, Color.Transparent),
                        center = Offset(size.width * 0.5f, size.height * -0.18f),
                        radius = size.width * 1.1f,
                    ),
                )
                drawRect(
                    Brush.radialGradient(
                        colors = listOf(AppColors.otpGlowMist, Color.Transparent),
                        center = Offset(size.width * 1.12f, size.height * -0.08f),
                        radius = size.width * 0.9f,
                    ),
                )
            }
            .padding(horizontal = 14.dp),
        contentPadding = PaddingValues(bottom = 100.dp),
    ) {
        item {
            Text(
                stringResource(R.string.account_title),
                fontSize = 22.sp,
                fontWeight = FontWeight.ExtraBold,
                color = AppColors.waRed,
                modifier = Modifier.padding(top = 14.dp, bottom = 12.dp),
            )
        }
        item {
            val user = AuthSession.user
            Row(
                modifier = Modifier.fillMaxWidth(),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Box(
                    modifier = Modifier
                        .size(56.dp)
                        .shadow(6.dp, CircleShape, spotColor = AppColors.waRed.copy(alpha = 0.1f))
                        .clip(CircleShape)
                        .background(Color.White),
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(Icons.Rounded.Person, contentDescription = null, tint = AppColors.waRed, modifier = Modifier.size(28.dp))
                }
                Spacer(Modifier.width(10.dp))
                Column(modifier = Modifier.weight(1f)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(
                            text = user?.takeIf { !it.name.isNullOrBlank() }?.name ?: stringResource(R.string.account_default_user),
                            fontSize = 16.sp,
                            fontWeight = FontWeight.Bold,
                            color = AppColors.textPrimary,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                            modifier = Modifier.weight(1f, fill = false),
                        )
                        Spacer(Modifier.width(6.dp))
                        Box(
                            modifier = Modifier
                                .size(15.dp)
                                .clip(CircleShape)
                                .background(AppColors.waRed),
                            contentAlignment = Alignment.Center,
                        ) {
                            Icon(
                                Icons.Rounded.Check,
                                contentDescription = stringResource(R.string.account_verified),
                                tint = Color.White,
                                modifier = Modifier.size(10.dp),
                            )
                        }
                    }
                    user?.phone?.let { phone ->
                        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                            Text(
                                text = phone,
                                fontSize = 12.sp,
                                color = AppColors.textMuted,
                                maxLines = 1,
                                overflow = TextOverflow.Ellipsis,
                            )
                        }
                    }
                    Row(
                        modifier = Modifier
                            .padding(top = 4.dp)
                            .clip(RoundedCornerShape(50))
                            .background(Color(0xFFFDE8EC))
                            .padding(horizontal = 8.dp, vertical = 2.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(Icons.Rounded.Star, contentDescription = null, tint = AppColors.waRed, modifier = Modifier.size(12.dp))
                        Spacer(Modifier.width(5.dp))
                        Text(
                            stringResource(R.string.account_premium),
                            fontSize = 11.sp,
                            fontWeight = FontWeight.SemiBold,
                            color = AppColors.waRed,
                        )
                    }
                }
            }
            Spacer(Modifier.height(12.dp))
        }

        item {
            ProfileWalletCard(onOpenWallet = onOpenWallet)
            Spacer(Modifier.height(10.dp))
        }

        item {
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                AccountStat(
                    icon = Icons.Rounded.DirectionsCar,
                    iconTint = Color(0xFF2563EB),
                    iconBg = Color(0xFFEFF6FF),
                    value = "12",
                    label = stringResource(R.string.account_stat_orders),
                    modifier = Modifier.weight(1f),
                )
                AccountStat(
                    icon = Icons.Rounded.Star,
                    iconTint = Color(0xFFD97706),
                    iconBg = Color(0xFFFEF3C7),
                    value = "4.9",
                    label = stringResource(R.string.account_stat_rating),
                    modifier = Modifier.weight(1f),
                )
                AccountStat(
                    icon = Icons.Rounded.Event,
                    iconTint = Color(0xFF2563EB),
                    iconBg = Color(0xFFEFF6FF),
                    value = "3",
                    label = stringResource(R.string.account_stat_active),
                    modifier = Modifier.weight(1f),
                )
            }
            Spacer(Modifier.height(12.dp))
        }

        itemsIndexed(menuItems) { index, entry ->
            var visible by remember { mutableStateOf(false) }
            LaunchedEffect(Unit) { visible = true }
            AnimatedVisibility(
                visible = visible,
                enter = fadeIn(tween(350, delayMillis = index * 40)) +
                    slideInHorizontally(tween(350, delayMillis = index * 40)) { fullWidth -> -fullWidth / 3 },
            ) {
                MenuRow(entry)
            }
        }
    }

    if (showFaqSheet) FaqSheet(onDismiss = { showFaqSheet = false })
}

@Composable
private fun SettingsMenuScreen(
    onBack: () -> Unit,
    onLogout: () -> Unit,
    onOpenPersonalData: () -> Unit,
    onOpenNotifications: () -> Unit,
    onOpenWalletPin: () -> Unit,
    onOpenPrivacy: () -> Unit,
) {
    val themeState = LocalThemeState.current
    val isDark = themeState.isDark ?: isSystemInDarkTheme()

    var showLogoutConfirm by remember { mutableStateOf(false) }
    var showDeleteConfirm by remember { mutableStateOf(false) }
    var showFaqSheet by remember { mutableStateOf(false) }
    var showContactSheet by remember { mutableStateOf(false) }
    var showAboutDialog by remember { mutableStateOf(false) }
    var showLanguageDialog by remember { mutableStateOf(false) }

    val menuItems = listOf(
        MenuEntry(Icons.Rounded.Person, R.string.account_personal_data, R.string.account_personal_data_sub, onClick = onOpenPersonalData),
        MenuEntry(Icons.Rounded.Notifications, R.string.account_notifications, R.string.account_notifications_sub, onClick = onOpenNotifications),
        MenuEntry(Icons.Rounded.Lock, R.string.account_wallet_pin, R.string.account_wallet_pin_sub, onClick = onOpenWalletPin),
        MenuEntry(Icons.Rounded.Language, R.string.account_language, R.string.account_language_sub) { showLanguageDialog = true },
        MenuEntry(
            icon = Icons.Rounded.DarkMode,
            label = R.string.account_dark_mode,
            subtitle = R.string.account_dark_mode_sub,
            trailing = { Switch(checked = isDark, onCheckedChange = { themeState.isDark = it }) },
            onClick = { themeState.isDark = !isDark },
        ),
        MenuEntry(Icons.Rounded.Help, R.string.account_faqs, R.string.account_faqs_sub) { showFaqSheet = true },
        MenuEntry(Icons.Rounded.Call, R.string.account_contact_us, R.string.account_contact_us_sub) { showContactSheet = true },
        MenuEntry(Icons.Rounded.Info, R.string.account_about, R.string.account_about_sub) { showAboutDialog = true },
        MenuEntry(Icons.Rounded.Shield, R.string.account_privacy, R.string.account_privacy_sub, onClick = onOpenPrivacy),
        MenuEntry(Icons.Rounded.Logout, R.string.account_logout, R.string.account_logout_sub, danger = true) { showLogoutConfirm = true },
        MenuEntry(Icons.Rounded.DeleteForever, R.string.account_delete, R.string.account_delete_sub, danger = true) { showDeleteConfirm = true },
    )

    SettingsScaffold(title = stringResource(R.string.account_settings), onBack = onBack) { padding ->
        LazyColumn(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(horizontal = 14.dp),
            contentPadding = PaddingValues(top = 10.dp, bottom = 100.dp),
        ) {
            itemsIndexed(menuItems) { index, entry ->
                var visible by remember { mutableStateOf(false) }
                LaunchedEffect(Unit) { visible = true }
                AnimatedVisibility(
                    visible = visible,
                    enter = fadeIn(tween(350, delayMillis = index * 40)) +
                        slideInHorizontally(tween(350, delayMillis = index * 40)) { fullWidth -> -fullWidth / 3 },
                ) {
                    MenuRow(entry)
                }
            }
        }
    }

    if (showLogoutConfirm) {
        ConfirmDialog(
            title = stringResource(R.string.account_logout),
            message = stringResource(R.string.logout_confirm_message),
            onConfirm = { showLogoutConfirm = false; onLogout() },
            onDismiss = { showLogoutConfirm = false },
        )
    }
    if (showDeleteConfirm) {
        ConfirmDialog(
            title = stringResource(R.string.account_delete),
            message = stringResource(R.string.delete_confirm_message),
            onConfirm = { showDeleteConfirm = false; onLogout() },
            onDismiss = { showDeleteConfirm = false },
        )
    }
    if (showFaqSheet) FaqSheet(onDismiss = { showFaqSheet = false })
    if (showContactSheet) ContactUsSheet(onDismiss = { showContactSheet = false })
    if (showAboutDialog) AboutDialog(onDismiss = { showAboutDialog = false })
    if (showLanguageDialog) LanguageDialog(onDismiss = { showLanguageDialog = false })
}

@Composable
private fun ProfileWalletCard(onOpenWallet: () -> Unit) {
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
        modifier = Modifier
            .fillMaxWidth()
            .shadow(6.dp, RoundedCornerShape(16.dp), spotColor = AppColors.waRed.copy(alpha = 0.07f))
            .clip(RoundedCornerShape(16.dp))
            .background(Color.White)
            .clickable(onClick = onOpenWallet)
            .padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(modifier = Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(
                    Icons.Rounded.AccountBalanceWallet,
                    contentDescription = null,
                    tint = AppColors.waRed,
                    modifier = Modifier.size(16.dp),
                )
                Spacer(Modifier.width(5.dp))
                Text(
                    stringResource(R.string.home_wallet_balance),
                    fontSize = 12.sp,
                    fontWeight = FontWeight.Bold,
                    color = AppColors.textPrimary,
                )
            }
            Spacer(Modifier.height(4.dp))
            Row(verticalAlignment = Alignment.Bottom) {
                Text(
                    balanceText,
                    fontSize = 22.sp,
                    fontWeight = FontWeight.ExtraBold,
                    color = AppColors.textPrimary,
                    modifier = Modifier.alignByBaseline(),
                )
                if (currencyText.isNotBlank()) {
                    Spacer(Modifier.width(6.dp))
                    Text(
                        currencyText,
                        fontSize = 12.sp,
                        fontWeight = FontWeight.SemiBold,
                        color = AppColors.textSecondary,
                        modifier = Modifier.alignByBaseline(),
                    )
                }
            }
        }
        Box(
            modifier = Modifier
                .shadow(6.dp, RoundedCornerShape(50), spotColor = AppColors.waRed.copy(alpha = 0.18f))
                .clip(RoundedCornerShape(50))
                .background(AppColors.waRed)
                .clickable(onClick = onOpenWallet)
                .padding(horizontal = 12.dp, vertical = 8.dp),
            contentAlignment = Alignment.Center,
        ) {
            Text(
                stringResource(R.string.home_wallet_topup),
                color = Color.White,
                fontSize = 12.sp,
                fontWeight = FontWeight.Bold,
            )
        }
    }
}

@Composable
private fun AccountStat(
    icon: ImageVector,
    iconTint: Color,
    iconBg: Color,
    value: String,
    label: String,
    modifier: Modifier = Modifier,
) {
    Row(
        modifier = modifier
            .shadow(4.dp, RoundedCornerShape(12.dp), spotColor = Color(0xFF111928).copy(alpha = 0.04f))
            .clip(RoundedCornerShape(12.dp))
            .background(Color.White)
            .border(1.dp, AppColors.divider, RoundedCornerShape(12.dp))
            .padding(8.dp, 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            modifier = Modifier
                .size(34.dp)
                .clip(RoundedCornerShape(10.dp))
                .background(iconBg),
            contentAlignment = Alignment.Center,
        ) {
            Icon(icon, contentDescription = null, tint = iconTint, modifier = Modifier.size(18.dp))
        }
        Spacer(Modifier.width(6.dp))
        Column(modifier = Modifier.weight(1f)) {
            Text(
                value,
                fontSize = 15.sp,
                fontWeight = FontWeight.ExtraBold,
                color = AppColors.textPrimary,
                maxLines = 1,
            )
            Text(
                label,
                fontSize = 9.sp,
                fontWeight = FontWeight.Medium,
                color = AppColors.textMuted,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )
        }
    }
}

@Composable
private fun MenuRow(entry: MenuEntry) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = 4.dp)
            .shadow(6.dp, RoundedCornerShape(14.dp), spotColor = AppColors.waRed.copy(alpha = 0.07f))
            .clip(RoundedCornerShape(14.dp))
            .background(Color.White)
            .clickable(onClick = entry.onClick)
            .padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            modifier = Modifier
                .size(34.dp)
                .clip(RoundedCornerShape(10.dp))
                .background(Color(0xFFFDE8EC)),
            contentAlignment = Alignment.Center,
        ) {
            Icon(entry.icon, contentDescription = null, tint = AppColors.waRed, modifier = Modifier.size(18.dp))
        }
        Spacer(Modifier.width(10.dp))
        Column(modifier = Modifier.weight(1f)) {
            Text(
                stringResource(entry.label),
                fontSize = 13.5.sp,
                fontWeight = FontWeight.Bold,
                color = if (entry.danger) AppColors.waRed else AppColors.textPrimary,
            )
            entry.subtitle?.let { sub ->
                Text(
                    stringResource(sub),
                    fontSize = 11.sp,
                    color = if (entry.danger) AppColors.otpGlowDeep else AppColors.textMuted,
                )
            }
        }
        if (entry.trailing != null) {
            entry.trailing.invoke()
        } else {
            Icon(
                Icons.Rounded.ChevronRight,
                contentDescription = null,
                tint = AppColors.otpGlowDeep,
                modifier = Modifier.size(16.dp),
            )
        }
    }
}

@Composable
private fun ConfirmDialog(title: String, message: String, onConfirm: () -> Unit, onDismiss: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = { Text(message) },
        confirmButton = { TextButton(onClick = onConfirm) { Text(title) } },
        dismissButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.common_cancel)) } },
    )
}

@Composable
private fun AboutDialog(onDismiss: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        icon = { DorrLogo(width = 150.dp) },
        title = { Text(stringResource(R.string.app_name)) },
        text = {
            Column {
                Text("v1.0.0", color = AppColors.textSecondary)
                Spacer(Modifier.height(8.dp))
                Text(stringResource(R.string.about_description))
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.common_close)) } },
    )
}

// Switches the app locale. LocalAppLanguage set() persists the choice and
// LocalizedApp (MainActivity) re-wraps the composition in a locale-aware
// Context, so every stringResource call and the RTL/LTR layout follow it
// immediately — no activity recreation needed.
@Composable
private fun LanguageDialog(onDismiss: () -> Unit) {
    val appLanguage = LocalAppLanguage.current
    var selectedArabic by remember(appLanguage.code) { mutableStateOf(appLanguage.isArabic) }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.language_title)) },
        text = {
            Column {
                LanguageOption(
                    label = stringResource(R.string.language_arabic),
                    selected = selectedArabic,
                    onClick = { selectedArabic = true; appLanguage.set("ar") },
                )
                LanguageOption(
                    label = stringResource(R.string.language_english),
                    selected = !selectedArabic,
                    onClick = { selectedArabic = false; appLanguage.set("en") },
                )
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.common_close)) } },
    )
}

@Composable
private fun LanguageOption(label: String, selected: Boolean, onClick: () -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick)
            .padding(vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        RadioButton(selected = selected, onClick = onClick)
        Spacer(Modifier.width(8.dp))
        Text(label)
    }
}
