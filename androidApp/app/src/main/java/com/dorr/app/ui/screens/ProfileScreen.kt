package com.dorr.app.ui.screens

import android.widget.Toast
import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.ContentTransform
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideOutHorizontally
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.material.icons.outlined.Paid
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.DirectionsCar
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material.icons.rounded.LocationOn
import androidx.compose.material.icons.rounded.Star
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.DialogProperties
import coil.compose.AsyncImage
import com.dorr.app.network.LanguageDto
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.screens.profile.PinkBackdrop
import com.dorr.app.ui.screens.profile.PinkIcon
import com.dorr.app.ui.screens.wallet.formatMinor
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Build
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.Chat
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.ConfirmationNumber
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.Palette
import androidx.compose.material.icons.rounded.Policy
import androidx.compose.material.icons.rounded.DeleteForever
import androidx.compose.material.icons.rounded.Help
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.Language
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Logout
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Settings
import androidx.compose.material.icons.rounded.Share
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material.icons.rounded.SupportAgent
import androidx.compose.material.icons.rounded.TextFields
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.UserDto
import com.dorr.app.network.serverMessage
import com.dorr.app.ui.locale.LocalAppLanguage
import com.dorr.app.ui.locale.LocaleAwareDialog
import kotlinx.coroutines.launch
import com.dorr.app.ui.screens.profile.AddressesScreen
import com.dorr.app.ui.screens.profile.AppearanceFontScreen
import com.dorr.app.ui.screens.profile.AppearanceScreen
import com.dorr.app.ui.screens.profile.ContactUsSheet
import com.dorr.app.ui.screens.profile.FaqSheet
import com.dorr.app.ui.screens.profile.InviteFriendsScreen
import com.dorr.app.ui.screens.profile.NotificationSettingsScreen
import com.dorr.app.ui.screens.profile.RateAppScreen
import com.dorr.app.ui.screens.profile.SupportChatScreen
import com.dorr.app.ui.screens.profile.SupportTicketScreen
import com.dorr.app.ui.screens.profile.PersonalDataScreen
import com.dorr.app.ui.screens.profile.PrivacyPolicyScreen
import com.dorr.app.ui.screens.profile.TermsConditionsScreen
import com.dorr.app.ui.screens.profile.SettingsScreenTitle
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.theme.AppColors
import com.dorr.app.ui.theme.LocalAppearance
import com.dorr.app.ui.theme.LocalThemeState
import com.dorr.app.ui.theme.appearanceColor


private enum class ProfileSub {
    NONE, PERSONAL_DATA, NOTIFICATIONS,
    PRIVACY, TERMS, LEGAL, ADDRESSES, SETTINGS, APPEARANCE, FONT,
    INVITE, RATE, SUPPORT, TICKET, QUICK_CHAT,
}

private data class MenuEntry(
    val icon: ImageVector,
    val label: Int,
    val subtitle: Int? = null,
    val danger: Boolean = false,
    val trailing: (@Composable () -> Unit)? = null,
    val onClick: () -> Unit = {},
)


@Composable
fun ProfileScreen(
    onLogout: () -> Unit,
    onOpenWallet: () -> Unit,
    onAccountDeleted: () -> Unit = {},
) {
    var subScreen by remember { mutableStateOf(ProfileSub.NONE) }
    // A tapped support notification: open that ticket.
    var openTicketId by remember { mutableStateOf<Int?>(null) }
    val isAtRoot = subScreen == ProfileSub.NONE

    // A tapped support notification (a reply, a status change) lands on that ticket.
    LaunchedEffect(Unit) {
        com.dorr.app.chat.ChatPush.deepLink.collect { link ->
            if (link is com.dorr.app.chat.ChatDeepLink.Support) {
                openTicketId = link.ticketId
                subScreen = ProfileSub.TICKET
                com.dorr.app.chat.ChatPush.consumeDeepLink()
            }
        }
    }

    // System back = the same step as each sub-screen's own back arrow. Sub-screens with inner
    // steps (personal data, addresses form, privacy policy) register their own handlers after this one.
    BackHandler(enabled = !isAtRoot) {
        subScreen = when (subScreen) {
            ProfileSub.NOTIFICATIONS, ProfileSub.APPEARANCE, ProfileSub.FONT,
            ProfileSub.LEGAL -> ProfileSub.SETTINGS
            ProfileSub.INVITE, ProfileSub.RATE, ProfileSub.SUPPORT -> ProfileSub.NONE
            ProfileSub.TICKET, ProfileSub.QUICK_CHAT -> ProfileSub.SUPPORT
            ProfileSub.PRIVACY, ProfileSub.TERMS -> ProfileSub.LEGAL
            else -> ProfileSub.NONE
        }
    }
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    var isDeleting by remember { mutableStateOf(false) }

    val deleteFailedMessage = stringResource(R.string.account_delete_failed)

    /**
     * DELETE /api/mobile/v1/profile/account. The account is soft-deleted and every
     * Sanctum token is revoked server-side, so `onAccountDeleted` clears the local
     * session and routes to Login *without* calling the logout endpoint — that token
     * is already gone, so the request would 401 and fire the "session expired" notice.
     * Logging in again with the same phone restores the account server-side
     * (MobileAuthController::requestOtp), so this is recoverable, not destructive.
     */
    fun deleteAccount() {
        if (isDeleting) return
        isDeleting = true
        scope.launch {
            try {
                ApiClient.profile.deleteAccount("Bearer ${AuthSession.token.orEmpty()}")
                onAccountDeleted()
            } catch (e: Exception) {
                Toast.makeText(
                    context,
                    e.serverMessage() ?: deleteFailedMessage,
                    Toast.LENGTH_LONG,
                ).show()
            } finally {
                isDeleting = false
            }
        }
    }

    AnimatedContent(
        targetState = subScreen,
        label = "profileSub",
        transitionSpec = {
            if (targetState == ProfileSub.NONE) {
                // Popping back → slide in from leading edge, slide out to trailing edge
                val enter = slideInHorizontally(tween(340, easing = FastOutSlowInEasing)) { -it / 5 } +
                    fadeIn(tween(280))
                val exit = slideOutHorizontally(tween(300, easing = FastOutSlowInEasing)) { it / 5 } +
                    fadeOut(tween(220))
                ContentTransform(enter, exit, sizeTransform = null)
            } else {
                // Pushing forward → slide in from trailing edge, slide out to leading edge
                val enter = slideInHorizontally(tween(340, easing = FastOutSlowInEasing)) { it / 5 } +
                    fadeIn(tween(280))
                val exit = slideOutHorizontally(tween(300, easing = FastOutSlowInEasing)) { -it / 5 } +
                    fadeOut(tween(220))
                ContentTransform(enter, exit, sizeTransform = null)
            }
        },
    ) { currentSub ->
        when (currentSub) {
            ProfileSub.PERSONAL_DATA -> {
                val subContext = LocalContext.current
                PersonalDataScreen(
                    onBack = { subScreen = ProfileSub.NONE },
                    onSaved = { message -> Toast.makeText(subContext, message, Toast.LENGTH_SHORT).show() },
                )
            }
            ProfileSub.NOTIFICATIONS -> NotificationSettingsScreen(onBack = { subScreen = ProfileSub.SETTINGS })
            ProfileSub.PRIVACY -> PrivacyPolicyScreen(onBack = { subScreen = ProfileSub.LEGAL })
            ProfileSub.TERMS -> TermsConditionsScreen(onBack = { subScreen = ProfileSub.LEGAL })
            ProfileSub.LEGAL -> LegalMenuScreen(
                onBack = { subScreen = ProfileSub.SETTINGS },
                onOpenPrivacy = { subScreen = ProfileSub.PRIVACY },
                onOpenTerms = { subScreen = ProfileSub.TERMS },
            )
            ProfileSub.ADDRESSES -> AddressesScreen(onBack = { subScreen = ProfileSub.NONE })
            ProfileSub.SETTINGS -> SettingsMenuScreen(
                onBack = { subScreen = ProfileSub.NONE },
                onOpenNotifications = { subScreen = ProfileSub.NOTIFICATIONS },
                onOpenLegal = { subScreen = ProfileSub.LEGAL },
                onOpenAppearance = { subScreen = ProfileSub.APPEARANCE },
                onOpenFont = { subScreen = ProfileSub.FONT },
                onDeleteAccount = ::deleteAccount,
                isDeleting = isDeleting,
            )
            ProfileSub.INVITE -> InviteFriendsScreen(onBack = { subScreen = ProfileSub.NONE })
            ProfileSub.RATE -> RateAppScreen(onBack = { subScreen = ProfileSub.NONE })
            ProfileSub.SUPPORT -> SupportMenuScreen(
                onBack = { subScreen = ProfileSub.NONE },
                onOpenTicket = { subScreen = ProfileSub.TICKET },
                onOpenQuickChat = { subScreen = ProfileSub.QUICK_CHAT },
            )
            // The quick chat: guided help that ends in "solved" or a ticket (the ticket screen hosts both).
            ProfileSub.QUICK_CHAT -> SupportTicketScreen(
                onBack = { subScreen = ProfileSub.SUPPORT },
                startQuickChat = true,
            )
            ProfileSub.TICKET -> SupportTicketScreen(
                onBack = { subScreen = ProfileSub.SUPPORT },
                openTicketId = openTicketId,
                onTicketOpened = { openTicketId = null },
            )
            ProfileSub.APPEARANCE -> AppearanceScreen(onBack = { subScreen = ProfileSub.SETTINGS })
            ProfileSub.FONT -> AppearanceFontScreen(onBack = { subScreen = ProfileSub.SETTINGS })
            ProfileSub.NONE -> ProfileMenuScreen(
                onOpenPersonalData = { subScreen = ProfileSub.PERSONAL_DATA },
                onOpenSettings = { subScreen = ProfileSub.SETTINGS },
                onOpenAddresses = { subScreen = ProfileSub.ADDRESSES },
                onOpenInvite = { subScreen = ProfileSub.INVITE },
                onOpenRate = { subScreen = ProfileSub.RATE },
                onOpenSupport = { subScreen = ProfileSub.SUPPORT },
                onOpenWallet = onOpenWallet,
                onLogout = onLogout,
            )
        }
    }
}

/** Dark palette. Once appearance is loaded these follow the dark tokens; otherwise the app chrome. */
internal object AccountDark {
    private val fallbackBg = Color(0xFF101216)
    private val fallbackCard = Color(0xFF1A1D24)
    private val fallbackWell = Color(0xFF2C2226)
    private val fallbackLine = Color(0xFF2C313A)
    private val fallbackInk = Color(0xFFF4F5F7)
    private val fallbackMut = Color(0xFF9AA1AC)
    private val fallbackAccent = Color(0xFFFA7552)
    private val fallbackChevron = Color(0xFF8B93A0)

    val bg: Color @Composable get() = appearanceColor("background", fallbackBg, night = true)
    val card: Color @Composable get() = appearanceColor("surface", fallbackCard, night = true)
    val well: Color @Composable get() = fallbackWell
    val line: Color @Composable get() = appearanceColor("border", fallbackLine, night = true)
    val ink: Color @Composable get() = appearanceColor("textPrimary", fallbackInk, night = true)
    val mut: Color @Composable get() = appearanceColor("textSecondary", fallbackMut, night = true)
    val accent: Color @Composable get() = appearanceColor("primary", fallbackAccent, night = true)
    val chevron: Color @Composable get() = fallbackChevron
}


/**
 * Profile picture from the cached session user, falling back to the placeholder
 * icon when the account has no avatar (or the image can't be reached).
 *
 * Fills the space it is given and relies on the caller's circle clip, so the
 * avatar is always cropped to a circle.
 */
@Composable
private fun AccountAvatar(user: UserDto?, dark: Boolean, size: Dp) {
    // The server keeps a fixed file name per account (…/{userId}/avatar.jpg), so
    // re-uploading returns the same URL — updated_at busts the Coil cache.
    val avatarUrl = remember(user?.avatar, user?.updatedAt) {
        ApiClient.mediaUrl(user?.avatar)?.let { url -> "$url?v=${user?.updatedAt ?: "0"}" }
    }
    var failed by remember(avatarUrl) { mutableStateOf(false) }

    if (avatarUrl != null && !failed) {
        AsyncImage(
            model = avatarUrl,
            contentDescription = null,
            contentScale = ContentScale.Crop,
            onError = { failed = true },
            modifier = Modifier.fillMaxSize(),
        )
    } else {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            Icon(
                Icons.Rounded.Person,
                contentDescription = null,
                tint = if (dark) AccountDark.accent else settingsAccent(),
                modifier = Modifier.size(size / 2),
            )
        }
    }
}

@Composable
private fun Modifier.accountBackdrop(dark: Boolean): Modifier {
    val loaded = LocalAppearance.current.snapshot != null
    val base = if (dark) AccountDark.bg else appearanceColor("background", Color.White, night = false)
    val glow = if (dark) AccountDark.accent else appearanceColor("primary", settingsAccent(), night = false)
    return drawBehind {
        if (dark || loaded) {
            drawRect(base)
            drawRect(
                Brush.radialGradient(
                    colors = listOf(glow.copy(alpha = 0.35f), glow.copy(alpha = 0.10f), Color.Transparent),
                    center = Offset(size.width * 0.5f, size.height * -0.08f),
                    radius = size.width * 0.85f,
                ),
            )
            drawRect(
                Brush.radialGradient(
                    colors = listOf(glow.copy(alpha = 0.20f), Color.Transparent),
                    center = Offset(size.width * 1.05f, size.height * 0.02f),
                    radius = size.width * 0.55f,
                ),
            )
        } else {
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
    }
}

@Composable
private fun ProfileMenuScreen(
    onOpenPersonalData: () -> Unit,
    onOpenSettings: () -> Unit,
    onOpenAddresses: () -> Unit,
    onOpenInvite: () -> Unit,
    onOpenRate: () -> Unit,
    onOpenSupport: () -> Unit,
    onOpenWallet: () -> Unit,
    onLogout: () -> Unit,
) {
    var showLogoutConfirm by remember { mutableStateOf(false) }
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    val dark = LocalThemeState.current.isDark ?: isSystemInDarkTheme()

    val menuItems = listOf(
        MenuEntry(Icons.Rounded.Person, R.string.account_personal_data, R.string.account_personal_data_sub, onClick = onOpenPersonalData),
        MenuEntry(Icons.Rounded.Settings, R.string.account_settings, R.string.account_settings_sub, onClick = onOpenSettings),
        MenuEntry(Icons.Rounded.LocationOn, R.string.account_places, R.string.account_places_sub, onClick = onOpenAddresses),
        MenuEntry(Icons.Rounded.Share, R.string.invite_title, R.string.invite_sub, onClick = onOpenInvite),
        MenuEntry(Icons.Rounded.Star, R.string.rate_title, R.string.rate_sub, onClick = onOpenRate),
        MenuEntry(Icons.Rounded.SupportAgent, R.string.support_title, R.string.support_sub, onClick = onOpenSupport),
        MenuEntry(Icons.Rounded.Logout, R.string.account_logout, R.string.account_logout_sub, danger = true) { showLogoutConfirm = true },
    )

    LazyColumn(
        modifier = Modifier
            .fillMaxSize()
            .accountBackdrop(dark)
            .padding(horizontal = 14.dp),
        contentPadding = PaddingValues(bottom = 100.dp),
    ) {
        item {
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(top = 14.dp, bottom = 12.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                SettingsScreenTitle(
                    stringResource(R.string.account_title),
                    modifier = Modifier.weight(1f),
                )
                Box(
                    modifier = Modifier
                        .size(34.dp)
                        .then(
                            if (dark) Modifier
                            else Modifier.shadow(6.dp, CircleShape, ambientColor = Color(0x14001B53), spotColor = Color(0x14001B53)),
                        )
                        .clip(CircleShape)
                        .background(settingsCard())
                        .then(if (dark) Modifier.border(1.dp, AccountDark.line, CircleShape) else Modifier)
                        .clickable(onClick = onOpenSettings),
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(
                        Icons.Rounded.Settings,
                        contentDescription = stringResource(R.string.account_settings),
                        tint = if (dark) AccountDark.accent else settingsAccent(),
                        modifier = Modifier.size(16.dp),
                    )
                }
            }
        }
        item {
            // Keyed on the session: AuthSession.user is a plain var, so without
            // this the row would keep rendering the previous account's avatar.
            val sessionVersion by AuthSession.sessionVersion.collectAsState()
            val user = remember(sessionVersion) { AuthSession.user }
            Row(
                modifier = Modifier.fillMaxWidth(),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Box(
                    modifier = Modifier
                        .size(56.dp)
                        .then(
                            if (dark) Modifier
                            else Modifier.shadow(6.dp, CircleShape, spotColor = settingsAccent().copy(alpha = 0.1f)),
                        )
                        .clip(CircleShape)
                        .background(settingsCard())
                        .then(if (dark) Modifier.border(1.dp, AccountDark.line, CircleShape) else Modifier),
                    contentAlignment = Alignment.Center,
                ) {
                    AccountAvatar(user = user, dark = dark, size = 56.dp)
                }
                Spacer(Modifier.width(10.dp))
                Column(modifier = Modifier.weight(1f)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(
                            text = user?.takeIf { !it.name.isNullOrBlank() }?.name ?: stringResource(R.string.account_default_user),
                            fontSize = 16.sp,
                            fontWeight = FontWeight.Bold,
                            color = settingsInk(),
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                            modifier = Modifier.weight(1f, fill = false),
                        )
                        Spacer(Modifier.width(6.dp))
                        Box(
                            modifier = Modifier
                                .size(15.dp)
                                .clip(CircleShape)
                                .background(settingsAccent()),
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
                                color = settingsMut(),
                                maxLines = 1,
                                overflow = TextOverflow.Ellipsis,
                            )
                        }
                    }
                    Row(
                        modifier = Modifier
                            .padding(top = 4.dp)
                            .clip(RoundedCornerShape(50))
                            .background(if (dark) AccountDark.well else settingsAccent().copy(alpha = 0.14f))
                            .padding(horizontal = 8.dp, vertical = 2.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(Icons.Rounded.Star, contentDescription = null, tint = if (dark) AccountDark.accent else settingsAccent(), modifier = Modifier.size(12.dp))
                        Spacer(Modifier.width(5.dp))
                        Text(
                            stringResource(R.string.account_premium),
                            fontSize = 11.sp,
                            fontWeight = FontWeight.SemiBold,
                            color = if (dark) AccountDark.accent else settingsAccent(),
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
                    iconTint = if (dark) Color(0xFFA8C7FF) else Color(0xFF2563EB),
                    iconBg = if (dark) Color(0xFF1C2838) else Color(0xFFEFF6FF),
                    value = "12",
                    label = stringResource(R.string.account_stat_orders),
                    modifier = Modifier.weight(1f),
                    dark = dark,
                )
                AccountStat(
                    icon = Icons.Rounded.Star,
                    iconTint = if (dark) Color(0xFFF6C56B) else Color(0xFFD97706),
                    iconBg = if (dark) Color(0xFF2C2618) else Color(0xFFFEF3C7),
                    value = "4.9",
                    label = stringResource(R.string.account_stat_rating),
                    modifier = Modifier.weight(1f),
                    dark = dark,
                )
                AccountStat(
                    icon = Icons.Rounded.Event,
                    iconTint = if (dark) Color(0xFFA8C7FF) else Color(0xFF2563EB),
                    iconBg = if (dark) Color(0xFF1C2838) else Color(0xFFEFF6FF),
                    value = "3",
                    label = stringResource(R.string.account_stat_active),
                    modifier = Modifier.weight(1f),
                    dark = dark,
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
                MenuRow(entry, mirrorChevron = rtl, dark = dark)
            }
        }
    }

    if (showLogoutConfirm) {
        ConfirmDialog(
            icon = Icons.Rounded.Logout,
            title = stringResource(R.string.account_logout),
            message = stringResource(R.string.logout_confirm_message),
            confirmLabel = stringResource(R.string.common_yes),
            onConfirm = { showLogoutConfirm = false; onLogout() },
            onDismiss = { showLogoutConfirm = false },
        )
    }
}

@Composable
private fun AccountListScreen(
    title: String,
    onBack: () -> Unit,
    items: List<MenuEntry>,
) {
    val isDark = LocalThemeState.current.isDark ?: isSystemInDarkTheme()
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    Column(
        modifier = Modifier
            .fillMaxSize()
            .accountBackdrop(isDark),
    ) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = 14.dp)
            .padding(top = 14.dp, bottom = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
        ) {
            SettingsScreenTitle(title, Modifier.weight(1f))
            Box(
                modifier = Modifier
                    .size(34.dp)
                    .then(
                        if (isDark) Modifier
                        else Modifier.shadow(6.dp, CircleShape, ambientColor = Color(0x14001B53), spotColor = Color(0x14001B53)),
                    )
                    .clip(CircleShape)
                    .background(settingsCard())
                    .then(if (isDark) Modifier.border(1.dp, AccountDark.line, CircleShape) else Modifier)
                    .clickable(onClick = onBack),
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    Icons.AutoMirrored.Rounded.ArrowBack,
                    contentDescription = stringResource(R.string.common_back),
                    tint = settingsAccent(),
                    modifier = Modifier
                        .size(16.dp)
                        .graphicsLayer { scaleX = -1f },
                )
            }
        }
        LazyColumn(
            modifier = Modifier
                .weight(1f)
                .padding(horizontal = 14.dp),
            contentPadding = PaddingValues(bottom = 16.dp),
        ) {
            itemsIndexed(items) { index, entry ->
                var visible by remember { mutableStateOf(false) }
                LaunchedEffect(Unit) { visible = true }
                AnimatedVisibility(
                    visible = visible,
                    enter = fadeIn(tween(350, delayMillis = index * 40)) +
                        slideInHorizontally(tween(350, delayMillis = index * 40)) { fullWidth -> -fullWidth / 3 },
                ) {
                    MenuRow(entry, mirrorChevron = rtl, dark = isDark)
                }
            }
        }
    }
}

@Composable
private fun SettingsMenuScreen(
    onBack: () -> Unit,
    onOpenNotifications: () -> Unit,
    onOpenLegal: () -> Unit,
    onOpenAppearance: () -> Unit,
    onOpenFont: () -> Unit,
    onDeleteAccount: () -> Unit,
    isDeleting: Boolean,
) {
    var showContactSheet by remember { mutableStateOf(false) }
    var showAboutDialog by remember { mutableStateOf(false) }
    var showLanguageDialog by remember { mutableStateOf(false) }
    var showDeleteConfirm by remember { mutableStateOf(false) }

    AccountListScreen(
        title = stringResource(R.string.account_settings),
        onBack = onBack,
        items = listOf(
            MenuEntry(Icons.Rounded.Notifications, R.string.account_notifications, R.string.account_notifications_sub, onClick = onOpenNotifications),
            MenuEntry(Icons.Rounded.Language, R.string.account_language, R.string.account_language_sub) { showLanguageDialog = true },
            MenuEntry(Icons.Rounded.Palette, R.string.appearance_title, R.string.appearance_sub, onClick = onOpenAppearance),
            MenuEntry(Icons.Rounded.TextFields, R.string.appearance_font, R.string.appearance_font_sub, onClick = onOpenFont),
            MenuEntry(Icons.Rounded.Call, R.string.account_contact_us, R.string.account_contact_us_sub) { showContactSheet = true },
            MenuEntry(Icons.Rounded.Info, R.string.account_about, R.string.account_about_sub) { showAboutDialog = true },
            MenuEntry(Icons.Rounded.Policy, R.string.account_legal, R.string.account_legal_sub, onClick = onOpenLegal),
            MenuEntry(Icons.Rounded.DeleteForever, R.string.account_delete, R.string.account_delete_sub, danger = true) { showDeleteConfirm = true },
        ),
    )

    if (showContactSheet) ContactUsSheet(onDismiss = { showContactSheet = false })
    if (showAboutDialog) AboutDialog(onDismiss = { showAboutDialog = false })
    if (showLanguageDialog) LanguageDialog(onDismiss = { showLanguageDialog = false })
    if (showDeleteConfirm) {
        ConfirmDialog(
            icon = Icons.Rounded.DeleteForever,
            title = stringResource(R.string.account_delete),
            message = stringResource(R.string.delete_confirm_message),
            loading = isDeleting,
            onConfirm = { onDeleteAccount() },
            onDismiss = { if (!isDeleting) showDeleteConfirm = false },
        )
    }
}

@Composable
private fun SupportMenuScreen(
    onBack: () -> Unit,
    onOpenTicket: () -> Unit,
    onOpenQuickChat: () -> Unit,
) {
    var showFaqSheet by remember { mutableStateOf(false) }
    AccountListScreen(
        title = stringResource(R.string.support_title),
        onBack = onBack,
        items = listOf(
            MenuEntry(Icons.Rounded.SupportAgent, R.string.support_quick_chat_title, R.string.support_quick_chat_sub, onClick = onOpenQuickChat),
            MenuEntry(Icons.Rounded.ConfirmationNumber, R.string.support_ticket_title, R.string.support_ticket_sub, onClick = onOpenTicket),
            MenuEntry(Icons.Rounded.Help, R.string.account_faqs, R.string.account_faqs_sub) { showFaqSheet = true },
        ),
    )
    if (showFaqSheet) FaqSheet(onDismiss = { showFaqSheet = false })
}

@Composable
private fun LegalMenuScreen(
    onBack: () -> Unit,
    onOpenPrivacy: () -> Unit,
    onOpenTerms: () -> Unit,
) {
    AccountListScreen(
        title = stringResource(R.string.account_legal),
        onBack = onBack,
        items = listOf(
            MenuEntry(Icons.Rounded.Shield, R.string.account_privacy, R.string.account_privacy_sub, onClick = onOpenPrivacy),
            MenuEntry(Icons.Rounded.Description, R.string.account_terms, R.string.account_terms_sub, onClick = onOpenTerms),
        ),
    )
}

@Composable
private fun ProfileWalletCard(onOpenWallet: () -> Unit) {
    val dark = LocalThemeState.current.isDark ?: isSystemInDarkTheme()
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
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .then(
                if (dark) Modifier
                else Modifier.shadow(6.dp, RoundedCornerShape(16.dp), spotColor = settingsAccent().copy(alpha = 0.07f)),
            )
            .clip(RoundedCornerShape(16.dp))
            .background(settingsCard())
            .then(if (dark) Modifier.border(1.dp, AccountDark.line, RoundedCornerShape(16.dp)) else Modifier)
            .clickable(onClick = onOpenWallet)
            .padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(modifier = Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(
                    Icons.Rounded.AccountBalanceWallet,
                    contentDescription = null,
                    tint = if (dark) AccountDark.accent else settingsAccent(),
                    modifier = Modifier.size(16.dp),
                )
                Spacer(Modifier.width(5.dp))
                Text(
                    stringResource(R.string.home_wallet_balance),
                    fontSize = 12.sp,
                    fontWeight = FontWeight.Bold,
                    color = settingsMut(),
                )
            }
            Spacer(Modifier.height(4.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(
                    Icons.Outlined.Paid,
                    contentDescription = null,
                    tint = if (dark) AccountDark.accent else settingsAccent(),
                    modifier = Modifier.size(18.dp),
                )
                Spacer(Modifier.width(6.dp))
                Text(
                    balanceText,
                    fontSize = 22.sp,
                    fontWeight = FontWeight.ExtraBold,
                    color = settingsInk(),
                )
                if (currencyText.isNotBlank()) {
                    Spacer(Modifier.width(6.dp))
                    Text(
                        currencyText,
                        fontSize = 12.sp,
                        fontWeight = FontWeight.SemiBold,
                        color = settingsMut(),
                    )
                }
            }
        }
        Box(
            modifier = Modifier
                .shadow(6.dp, RoundedCornerShape(50), spotColor = settingsAccent().copy(alpha = 0.18f))
                .clip(RoundedCornerShape(50))
                .background(settingsAccent())
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
    dark: Boolean = false,
) {
    Row(
        modifier = modifier
            .then(
                if (dark) Modifier
                else Modifier.shadow(4.dp, RoundedCornerShape(12.dp), spotColor = Color(0xFF111928).copy(alpha = 0.04f)),
            )
            .clip(RoundedCornerShape(12.dp))
            .background(settingsCard())
            .border(1.dp, if (dark) AccountDark.line else AppColors.divider, RoundedCornerShape(12.dp))
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
                color = settingsInk(),
                maxLines = 1,
            )
            Text(
                label,
                fontSize = 9.sp,
                fontWeight = FontWeight.Medium,
                color = settingsMut(),
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )
        }
    }
}

@Composable
private fun MenuRow(entry: MenuEntry, mirrorChevron: Boolean = false, dark: Boolean = false) {
    val shape = RoundedCornerShape(14.dp)
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = 4.dp)
            .then(
                if (dark) Modifier
                else Modifier.shadow(6.dp, shape, spotColor = settingsAccent().copy(alpha = 0.07f)),
            )
            .clip(shape)
            .background(settingsCard())
            .then(if (dark) Modifier.border(1.dp, AccountDark.line, shape) else Modifier)
            .clickable(onClick = entry.onClick)
            .padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            modifier = Modifier
                .size(34.dp)
                .clip(RoundedCornerShape(10.dp))
                .background(
                    when {
                        dark -> AccountDark.well
                        entry.danger -> settingsAccent().copy(alpha = 0.14f)
                        else -> settingsAccent().copy(alpha = 0.14f)
                    },
                ),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                entry.icon,
                contentDescription = null,
                tint = if (entry.danger) settingsAccent() else settingsAccent(),
                modifier = Modifier.size(18.dp),
            )
        }
        Spacer(Modifier.width(10.dp))
        Column(modifier = Modifier.weight(1f)) {
            Text(
                stringResource(entry.label),
                fontSize = 13.5.sp,
                fontWeight = FontWeight.Bold,
                color = if (entry.danger) settingsAccent() else settingsInk(),
            )
            entry.subtitle?.let { sub ->
                Text(
                    stringResource(sub),
                    fontSize = 11.sp,
                    color = if (entry.danger) AppColors.otpGlowDeep else settingsInk().copy(alpha = 0.65f),
                )
            }
        }
        if (entry.trailing != null) {
            entry.trailing.invoke()
        } else {
            Icon(
                Icons.Rounded.ChevronRight,
                contentDescription = null,
                tint = if (dark) AccountDark.chevron else settingsAccent().copy(alpha = 0.55f),
                modifier = Modifier
                    .size(16.dp)
                    .graphicsLayer { if (mirrorChevron) scaleX = -1f },
            )
        }
    }
}

@Composable
private fun SettingsToggle(on: Boolean, dark: Boolean = false) {
    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
        Box(
            modifier = Modifier
                .size(width = 42.dp, height = 24.dp)
                .clip(RoundedCornerShape(12.dp))
                .background(if (on) settingsAccent() else if (dark) Color(0xFF3A3F48) else Color(0xFFE5E7EB)),
        ) {
            Box(
                modifier = Modifier
                    .padding(2.dp)
                    .size(20.dp)
                    .offset(x = if (on) 18.dp else 0.dp)
                    .shadow(1.dp, CircleShape)
                    .clip(CircleShape)
                    .background(Color.White),
            )
        }
    }
}

@Composable
internal fun ConfirmDialog(
    icon: ImageVector,
    title: String,
    message: String,
    onConfirm: () -> Unit,
    onDismiss: () -> Unit,
    loading: Boolean = false,
    confirmLabel: String = stringResource(R.string.addr_yes),
) {
    val dark = LocalThemeState.current.isDark ?: isSystemInDarkTheme()
    LocaleAwareDialog(
        onDismissRequest = { if (!loading) onDismiss() },
        properties = DialogProperties(usePlatformDefaultWidth = false),
    ) {
        Box(
            modifier = Modifier
                .fillMaxSize()
                .background(Color.Black.copy(alpha = 0.45f))
                .clickable(enabled = !loading, onClick = onDismiss),
            contentAlignment = Alignment.Center,
        ) {
            Box(
                modifier = Modifier
                    .padding(horizontal = 24.dp)
                    .widthIn(max = 300.dp)
                    .fillMaxWidth()
                    .shadow(16.dp, RoundedCornerShape(20.dp), ambientColor = Color(0x24001B53), spotColor = Color(0x24001B53))
                    .clip(RoundedCornerShape(20.dp))
                    .clickable(
                        interactionSource = remember { MutableInteractionSource() },
                        indication = null,
                        onClick = {},
                    ),
            ) {
                PinkBackdrop(Modifier.matchParentSize())
                Column(
                    modifier = Modifier.padding(horizontal = 16.dp, vertical = 18.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                ) {
                    Box(
                        modifier = Modifier
                            .padding(bottom = 12.dp)
                            .size(56.dp)
                            .then(
                                if (dark) Modifier
                                else Modifier.shadow(8.dp, RoundedCornerShape(18.dp), ambientColor = Color(0x1A001B53), spotColor = Color(0x1A001B53)),
                            )
                            .clip(RoundedCornerShape(18.dp))
                            .background(settingsCard())
                            .then(if (dark) Modifier.border(1.dp, AccountDark.line, RoundedCornerShape(18.dp)) else Modifier),
                        contentAlignment = Alignment.Center,
                    ) {
                        Icon(icon, contentDescription = null, tint = if (dark) AccountDark.accent else settingsAccent(), modifier = Modifier.size(26.dp))
                    }
                    Text(
                        title,
                        color = if (dark) AccountDark.accent else settingsAccent(),
                        fontSize = 18.sp,
                        fontWeight = FontWeight.ExtraBold,
                        textAlign = TextAlign.Center,
                    )
                    Spacer(Modifier.height(4.dp))
                    Text(
                        message,
                        color = settingsMut(),
                        fontSize = 13.sp,
                        fontWeight = FontWeight.Bold,
                        textAlign = TextAlign.Center,
                        lineHeight = 20.sp,
                    )
                    Spacer(Modifier.height(16.dp))
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.spacedBy(8.dp),
                    ) {
                        Box(
                            modifier = Modifier
                                .weight(1f)
                                .height(46.dp)
                                .shadow(8.dp, RoundedCornerShape(14.dp), ambientColor = Color(0x38001B53), spotColor = Color(0x38001B53))
                                .clip(RoundedCornerShape(14.dp))
                                .background(
                                    if (loading) settingsAccent().copy(alpha = 0.6f) else settingsAccent(),
                                )
                                .clickable(enabled = !loading, onClick = onConfirm),
                            contentAlignment = Alignment.Center,
                        ) {
                            if (loading) {
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    CircularProgressIndicator(
                                        color = Color.White,
                                        strokeWidth = 2.dp,
                                        modifier = Modifier.size(17.dp),
                                    )
                                    Spacer(Modifier.width(8.dp))
                                    Text(
                                        stringResource(R.string.account_delete_in_progress),
                                        color = Color.White,
                                        fontSize = 14.sp,
                                        fontWeight = FontWeight.Bold,
                                    )
                                }
                            } else {
                                Text(confirmLabel, color = Color.White, fontSize = 15.sp, fontWeight = FontWeight.Bold)
                            }
                        }
                        Box(
                            modifier = Modifier
                                .weight(1f)
                                .height(46.dp)
                                .clip(RoundedCornerShape(14.dp))
                                .background(settingsCard())
                                .border(1.5.dp, if (dark) AccountDark.line else AppColors.otpPinkBorder, RoundedCornerShape(14.dp))
                                .clickable(enabled = !loading, onClick = onDismiss),
                            contentAlignment = Alignment.Center,
                        ) {
                            Text(
                                stringResource(R.string.common_cancel),
                                color = (if (dark) AccountDark.accent else settingsAccent()).copy(alpha = if (loading) 0.5f else 1f),
                                fontSize = 15.sp,
                                fontWeight = FontWeight.Bold,
                            )
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun AboutDialog(onDismiss: () -> Unit) {
    val dark = LocalThemeState.current.isDark ?: isSystemInDarkTheme()
    LocaleAwareDialog(
        onDismissRequest = onDismiss,
        properties = DialogProperties(usePlatformDefaultWidth = false),
    ) {
        Box(
            modifier = Modifier
                .fillMaxSize()
                .background(Color.Black.copy(alpha = 0.45f))
                .clickable(onClick = onDismiss),
            contentAlignment = Alignment.Center,
        ) {
            Box(
                modifier = Modifier
                    .padding(horizontal = 24.dp)
                    .widthIn(max = 300.dp)
                    .fillMaxWidth()
                    .shadow(16.dp, RoundedCornerShape(20.dp), ambientColor = Color(0x24001B53), spotColor = Color(0x24001B53))
                    .clip(RoundedCornerShape(20.dp))
                    .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null, onClick = {}),
            ) {
                PinkBackdrop(Modifier.matchParentSize())
                Column(Modifier.padding(start = 16.dp, end = 16.dp, top = 18.dp, bottom = 16.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(bottom = 12.dp)) {
                        PinkIcon(Icons.Rounded.Info)
                        Spacer(Modifier.width(10.dp))
                        Text(
                            stringResource(R.string.app_name),
                            color = if (dark) AccountDark.accent else settingsAccent(),
                            fontSize = 18.sp,
                            fontWeight = FontWeight.ExtraBold,
                        )
                    }
                    Text(
                        stringResource(R.string.about_version),
                        color = settingsMut(),
                        fontSize = 13.sp,
                        lineHeight = 22.sp,
                    )
                    Spacer(Modifier.height(22.dp))
                    Text(
                        stringResource(R.string.about_description),
                        color = settingsMut(),
                        fontSize = 13.sp,
                        lineHeight = 22.sp,
                        modifier = Modifier.padding(bottom = 14.dp),
                    )
                    Box(
                        modifier = Modifier
                            .fillMaxWidth()
                            .height(44.dp)
                            .shadow(8.dp, RoundedCornerShape(14.dp), ambientColor = Color(0x38001B53), spotColor = Color(0x38001B53))
                            .clip(RoundedCornerShape(14.dp))
                            .background(settingsAccent())
                            .clickable(onClick = onDismiss),
                        contentAlignment = Alignment.Center,
                    ) {
                        Text(stringResource(R.string.common_close), color = Color.White, fontSize = 15.sp, fontWeight = FontWeight.Bold)
                    }
                }
            }
        }
    }
}

// Switches the app locale. LocalAppLanguage set() persists the choice and
// LocalizedApp (MainActivity) re-wraps the composition in a locale-aware
// Context, so every stringResource call and the RTL/LTR layout follow it
// immediately — no activity recreation needed.
@Composable
private fun LanguageDialog(onDismiss: () -> Unit) {
    val dark = LocalThemeState.current.isDark ?: isSystemInDarkTheme()
    val appLanguage = LocalAppLanguage.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val downloadFailed = stringResource(R.string.language_download_failed)
    var languages by remember { mutableStateOf<List<LanguageDto>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    // Code of the language whose strings are being downloaded; the other choices wait for it.
    var downloading by remember { mutableStateOf<String?>(null) }
    val reconnectTick = collectReconnectTick()
    LaunchedEffect(reconnectTick) {
        languages = runCatching { ApiClient.languages.list().data.orEmpty() }.getOrDefault(emptyList())
        loading = false
        appLanguage.syncWith(context, languages)
    }
    LocaleAwareDialog(
        onDismissRequest = onDismiss,
        properties = DialogProperties(usePlatformDefaultWidth = false),
    ) {
        Box(
            modifier = Modifier
                .fillMaxSize()
                .background(Color.Black.copy(alpha = 0.45f))
                .clickable(onClick = onDismiss),
            contentAlignment = Alignment.Center,
        ) {
            Box(
                modifier = Modifier
                    .padding(horizontal = 24.dp)
                    .widthIn(max = 300.dp)
                    .fillMaxWidth()
                    .clip(RoundedCornerShape(20.dp))
                    .shadow(16.dp, RoundedCornerShape(20.dp), ambientColor = Color(0x24001B53), spotColor = Color(0x24001B53))
                    .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null, onClick = {}),
            ) {
                PinkBackdrop(Modifier.matchParentSize())
                Column(Modifier.padding(horizontal = 16.dp, vertical = 18.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(bottom = 12.dp)) {
                        PinkIcon(Icons.Rounded.Language)
                        Spacer(Modifier.width(10.dp))
                        Text(
                            stringResource(R.string.language_title),
                            color = if (dark) AccountDark.accent else settingsAccent(),
                            fontSize = 18.sp,
                            fontWeight = FontWeight.ExtraBold,
                        )
                    }
                    if (loading && languages.isEmpty()) {
                        Text(
                            stringResource(R.string.language_loading),
                            color = settingsMut(),
                            fontSize = 13.sp,
                            modifier = Modifier.padding(bottom = 14.dp),
                        )
                    } else {
                        val shown = languages.ifEmpty {
                            listOf(
                                LanguageDto(id = 0, code = "ar", name = stringResource(R.string.language_arabic), direction = "rtl"),
                                LanguageDto(id = 0, code = "en", name = stringResource(R.string.language_english), direction = "ltr"),
                            )
                        }
                        Column(verticalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.padding(bottom = 14.dp)) {
                            shown.forEach { language ->
                                val code = language.code.lowercase()
                                LanguageOption(
                                    name = language.name,
                                    flag = languageFlagCode(language),
                                    selected = code == appLanguage.code.lowercase(),
                                    onClick = {
                                        if (downloading == null && code != appLanguage.code.lowercase()) {
                                            scope.launch {
                                                downloading = code
                                                val switched = appLanguage.choose(context, code)
                                                downloading = null
                                                if (!switched) Toast.makeText(context, downloadFailed, Toast.LENGTH_SHORT).show()
                                            }
                                        }
                                    },
                                    dark = dark,
                                    loading = downloading == code,
                                )
                            }
                        }
                    }
                    Box(
                        modifier = Modifier
                            .fillMaxWidth()
                            .height(44.dp)
                            .shadow(8.dp, RoundedCornerShape(14.dp), ambientColor = Color(0x38001B53), spotColor = Color(0x38001B53))
                            .clip(RoundedCornerShape(14.dp))
                            .background(settingsAccent())
                            .clickable(onClick = onDismiss),
                        contentAlignment = Alignment.Center,
                    ) {
                        Text(stringResource(R.string.common_close), color = Color.White, fontSize = 15.sp, fontWeight = FontWeight.Bold)
                    }
                }
            }
        }
    }
}

@Composable
private fun LanguageOption(
    name: String,
    flag: String,
    selected: Boolean,
    onClick: () -> Unit,
    dark: Boolean = false,
    loading: Boolean = false,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(14.dp))
            .background(
                when {
                    dark && selected -> AccountDark.well
                    dark -> AccountDark.card
                    selected -> settingsAccent().copy(alpha = 0.14f)
                    else -> Color(0xFFFBF7F8)
                },
            )
            .border(1.5.dp, if (selected) settingsAccent() else if (dark) AccountDark.line else Color.Transparent, RoundedCornerShape(14.dp))
            .clickable(onClick = onClick)
            .padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            modifier = Modifier
                .size(34.dp)
                .clip(RoundedCornerShape(10.dp))
                .background(settingsBackground()),
            contentAlignment = Alignment.Center,
        ) {
            AsyncImage(
                model = "https://flagcdn.com/w40/$flag.png",
                contentDescription = null,
                contentScale = ContentScale.Crop,
                modifier = Modifier.size(width = 22.dp, height = 16.dp).clip(RoundedCornerShape(3.dp)),
            )
        }
        Spacer(Modifier.width(10.dp))
        Text(name, fontSize = 14.sp, fontWeight = FontWeight.Bold, color = settingsInk(), modifier = Modifier.weight(1f))
        if (loading) {
            CircularProgressIndicator(
                color = settingsAccent(),
                strokeWidth = 2.dp,
                modifier = Modifier.size(18.dp),
            )
        } else {
            Box(
                modifier = Modifier
                    .size(18.dp)
                    .border(2.dp, if (selected) settingsAccent() else if (dark) AccountDark.chevron else Color(0xFFF8BCA9), CircleShape),
                contentAlignment = Alignment.Center,
            ) {
                if (selected) {
                    Box(Modifier.size(10.dp).clip(CircleShape).background(settingsAccent()))
                }
            }
        }
    }
}

private fun languageFlagCode(language: LanguageDto): String {
    val fromFlag = language.flag?.code?.trim()?.lowercase().orEmpty()
    if (fromFlag.length == 2) return fromFlag
    return when (language.code.lowercase()) {
        "ar" -> "sa"
        "en" -> "us"
        else -> language.code.lowercase().take(2)
    }
}
