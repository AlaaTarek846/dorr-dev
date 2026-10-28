package com.dorr.freelance.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.Badge
import androidx.compose.material.icons.rounded.Bolt
import androidx.compose.material.icons.rounded.Build
import androidx.compose.material.icons.rounded.Category
import androidx.compose.material.icons.rounded.DeleteForever
import androidx.compose.material.icons.rounded.EventAvailable
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.Language
import androidx.compose.material.icons.rounded.Logout
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.School
import androidx.compose.material.icons.rounded.Verified
import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.slideInHorizontally
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.outlined.Paid
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Settings
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.Work
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.style.TextOverflow
import com.dorr.core.network.ApiResult
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.ui.kit.AccountDark
import com.dorr.freelance.ui.kit.settingsCard
import com.dorr.freelance.ui.kit.settingsNight
import com.dorr.freelance.ui.wallet.formatMinor
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.apiCall
import com.dorr.freelance.BuildConfig
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.Session
import com.dorr.freelance.net.apiDone
import com.dorr.freelance.ui.components.AvatarImage
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.kit.ConfirmDialog
import com.dorr.freelance.ui.kit.MenuEntry
import com.dorr.freelance.ui.kit.MenuRow
import com.dorr.freelance.ui.kit.SettingsScreenTitle
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.locale.LocalAppLanguage
import kotlinx.coroutines.launch

/** The person at the top of a menu tab: the photo, the name (with the verified tick), the phone and the account status. */
@Composable
private fun MeHeader(withBadges: Boolean = false) {
    val me by Session.me.collectAsState()
    val dark = settingsNight()
    val accent = settingsAccent()
    Row(Modifier.fillMaxWidth().padding(top = 4.dp, bottom = if (withBadges) 0.dp else 12.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(
            Modifier.size(56.dp)
                .then(if (dark) Modifier else Modifier.shadow(6.dp, CircleShape, spotColor = accent.copy(alpha = 0.1f)))
                .clip(CircleShape).background(settingsCard())
                .then(if (dark) Modifier.border(1.dp, AccountDark.line, CircleShape) else Modifier),
            contentAlignment = Alignment.Center,
        ) { AvatarImage(url = me?.avatarUrl, size = 56.dp) }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    me?.name?.takeIf { it.isNotBlank() } ?: stringResource(R.string.account_default_user),
                    color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.weight(1f, fill = false),
                )
                if (withBadges && me?.status == "verified") {
                    Spacer(Modifier.width(6.dp))
                    Box(Modifier.size(15.dp).clip(CircleShape).background(accent), contentAlignment = Alignment.Center) {
                        Icon(Icons.Rounded.Check, contentDescription = stringResource(R.string.account_verified), tint = Color.White, modifier = Modifier.size(10.dp))
                    }
                }
            }
            // A phone number keeps its left-to-right order inside Arabic text.
            Text("‎" + me?.phone.orEmpty(), color = settingsMut(), fontSize = 12.sp, maxLines = 1)
            if (withBadges) {
                Row(
                    Modifier.padding(top = 4.dp).clip(RoundedCornerShape(50))
                        .background(if (dark) AccountDark.well else accent.copy(alpha = 0.14f)).padding(horizontal = 8.dp, vertical = 2.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Rounded.Star, contentDescription = null, tint = if (dark) AccountDark.accent else accent, modifier = Modifier.size(12.dp))
                    Spacer(Modifier.width(5.dp))
                    Text(statusLabel(me?.status), fontSize = 11.sp, fontWeight = FontWeight.SemiBold, color = if (dark) AccountDark.accent else accent)
                }
            } else {
                Text(statusLabel(me?.status), color = accent, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

/** The round header button (settings / back): a card-coloured circle with a small icon. */
@Composable
private fun AccountCircle(icon: ImageVector, description: String, onClick: () -> Unit) {
    val dark = settingsNight()
    Box(
        Modifier.size(34.dp)
            .then(if (dark) Modifier else Modifier.shadow(6.dp, CircleShape, ambientColor = Color(0x14001B53), spotColor = Color(0x14001B53)))
            .clip(CircleShape).background(settingsCard())
            .then(if (dark) Modifier.border(1.dp, AccountDark.line, CircleShape) else Modifier)
            .clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Icon(icon, contentDescription = description, tint = if (dark) AccountDark.accent else settingsAccent(), modifier = Modifier.size(16.dp)) }
}

/** A row that slides in from the side, one after the other. */
@Composable
private fun AnimatedRow(index: Int, content: @Composable () -> Unit) {
    var visible by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { visible = true }
    AnimatedVisibility(
        visible = visible,
        enter = fadeIn(tween(350, delayMillis = index * 40)) + slideInHorizontally(tween(350, delayMillis = index * 40)) { full -> -full / 3 },
    ) { content() }
}

/** The balance in a card with a "top up" pill: one request when the page opens and when the connection returns. */
@Composable
private fun AccountWalletCard(onOpenWallet: () -> Unit) {
    val dark = settingsNight()
    val accent = settingsAccent()
    var balanceText by remember { mutableStateOf("0.00") }
    var currencyText by remember { mutableStateOf("") }
    val reconnect = collectReconnectTick()
    LaunchedEffect(reconnect) {
        (apiCall { ApiClient.wallet.balance() } as? ApiResult.Ok)?.data?.let {
            balanceText = formatMinor(it.totalMinor, null)
            currencyText = it.currencySymbol ?: it.currencyCode.orEmpty()
        }
    }
    val shape = RoundedCornerShape(16.dp)
    Row(
        Modifier.fillMaxWidth()
            .then(if (dark) Modifier else Modifier.shadow(6.dp, shape, spotColor = accent.copy(alpha = 0.07f)))
            .clip(shape).background(settingsCard())
            .then(if (dark) Modifier.border(1.dp, AccountDark.line, shape) else Modifier)
            .clickable(onClick = onOpenWallet).padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.AccountBalanceWallet, null, tint = if (dark) AccountDark.accent else accent, modifier = Modifier.size(16.dp))
                Spacer(Modifier.width(5.dp))
                Text(stringResource(R.string.home_wallet_balance), fontSize = 12.sp, fontWeight = FontWeight.Bold, color = settingsMut())
            }
            Spacer(Modifier.height(4.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Outlined.Paid, null, tint = if (dark) AccountDark.accent else accent, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(6.dp))
                Text(balanceText, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold, color = settingsInk())
                if (currencyText.isNotBlank()) {
                    Spacer(Modifier.width(6.dp))
                    Text(currencyText, fontSize = 12.sp, fontWeight = FontWeight.SemiBold, color = settingsMut())
                }
            }
        }
        Box(
            Modifier.shadow(6.dp, RoundedCornerShape(50), spotColor = accent.copy(alpha = 0.18f)).clip(RoundedCornerShape(50))
                .background(accent).clickable(onClick = onOpenWallet).padding(horizontal = 12.dp, vertical = 8.dp),
            contentAlignment = Alignment.Center,
        ) { Text(stringResource(R.string.home_wallet_topup), color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold) }
    }
}

/** Three small tiles of what the freelancer has built: the profile completeness, the works and the skills. */
@Composable
private fun AccountStats() {
    val me by Session.me.collectAsState()
    val dark = settingsNight()
    var works by remember { mutableStateOf<Int?>(null) }
    var skills by remember { mutableStateOf<Int?>(null) }
    val reconnect = collectReconnectTick()
    LaunchedEffect(reconnect) {
        (apiCall { ApiClient.portfolio.list() } as? ApiResult.Ok)?.data?.let { works = it.size }
        (apiCall { ApiClient.lists.mySkills() } as? ApiResult.Ok)?.data?.let { skills = it.size }
    }
    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        AccountStat(Icons.Rounded.CheckCircle, if (dark) Color(0xFFA8C7FF) else Color(0xFF2563EB), if (dark) Color(0xFF1C2838) else Color(0xFFEFF6FF), "${me?.completeness?.percent ?: 0}%", stringResource(R.string.stat_completeness), Modifier.weight(1f))
        AccountStat(Icons.Rounded.Work, if (dark) Color(0xFFF6C56B) else Color(0xFFD97706), if (dark) Color(0xFF2C2618) else Color(0xFFFEF3C7), works?.toString() ?: "-", stringResource(R.string.stat_works), Modifier.weight(1f))
        AccountStat(Icons.Rounded.Bolt, if (dark) Color(0xFFA8C7FF) else Color(0xFF2563EB), if (dark) Color(0xFF1C2838) else Color(0xFFEFF6FF), skills?.toString() ?: "-", stringResource(R.string.stat_skills), Modifier.weight(1f))
    }
}

@Composable
private fun AccountStat(icon: ImageVector, iconTint: Color, iconBg: Color, value: String, label: String, modifier: Modifier = Modifier) {
    val dark = settingsNight()
    val shape = RoundedCornerShape(12.dp)
    Row(
        modifier
            .then(if (dark) Modifier else Modifier.shadow(4.dp, shape, spotColor = Color(0xFF111928).copy(alpha = 0.04f)))
            .clip(shape).background(settingsCard())
            .border(1.dp, if (dark) AccountDark.line else Color(0xFFE5E7EB), shape)
            .padding(8.dp, 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(34.dp).clip(RoundedCornerShape(10.dp)).background(iconBg), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = iconTint, modifier = Modifier.size(18.dp))
        }
        Spacer(Modifier.width(6.dp))
        Column(Modifier.weight(1f)) {
            Text(value, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, color = settingsInk(), maxLines = 1)
            Text(label, fontSize = 9.sp, fontWeight = FontWeight.Medium, color = settingsMut(), maxLines = 2, overflow = TextOverflow.Ellipsis)
        }
    }
}

/** My profile: the personal data and the lists that make the profile (the DORR app account menu, with the freelancer's pages). */
@Composable
fun ProfileHubScreen(actions: MainActions) {
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 14.dp).padding(bottom = 16.dp)) {
        Column(Modifier.padding(top = 14.dp)) {
            SettingsScreenTitle(stringResource(R.string.tab_profile))
        }
        Spacer(Modifier.height(10.dp))
        MeHeader()
        listOf(
            MenuEntry(Icons.Rounded.Person, R.string.account_personal_data, R.string.account_personal_data_sub, onClick = actions.openProfile),
            MenuEntry(Icons.Rounded.Category, R.string.home_open_specialties, R.string.hub_specialties_sub, onClick = actions.openSpecialties),
            MenuEntry(Icons.Rounded.Bolt, R.string.home_open_skills, R.string.hub_skills_sub, onClick = actions.openSkills),
            MenuEntry(Icons.Rounded.Build, R.string.home_open_experiences, R.string.hub_experiences_sub, onClick = actions.openExperiences),
            MenuEntry(Icons.Rounded.School, R.string.home_open_certificates, R.string.hub_certificates_sub, onClick = actions.openCertificates),
            MenuEntry(Icons.Rounded.Work, R.string.portfolio_title, R.string.hub_portfolio_sub, onClick = actions.openPortfolio),
            MenuEntry(Icons.Rounded.EventAvailable, R.string.availability_title, R.string.availability_sub, onClick = actions.openAvailability),
            MenuEntry(Icons.Rounded.Badge, R.string.home_open_verification, R.string.hub_verification_sub, onClick = actions.openVerification),
        ).forEach { MenuRow(it) }
    }
}

/** My account: the settings (language, about), signing out and deleting the account — the DORR app settings page. */
@Composable
fun AccountScreen(actions: MainActions) {
    val language = LocalAppLanguage.current
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var showLogout by remember { mutableStateOf(false) }
    var showDelete by remember { mutableStateOf(false) }
    var showAbout by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    fun signOut() {
        busy = true
        scope.launch {
            // The token is revoked on the server; the phone forgets it whatever the answer.
            apiCall { ApiClient.auth.logout(com.dorr.freelance.net.LogoutRequest(playerId = com.dorr.freelance.net.Realtime.pushId())) }
            Session.clear()
        }
    }

    fun deleteAccount() {
        busy = true
        error = null
        scope.launch {
            val failed = apiDone(offlineText, genericError) { ApiClient.account.delete() }
            busy = false
            showDelete = false
            if (failed == null) Session.clear() else error = failed
        }
    }

    var settingsOpen by rememberSaveable { mutableStateOf(false) }
    BackHandler(enabled = settingsOpen) { settingsOpen = false }

    if (settingsOpen) {
        // The settings page, as in the DORR app: its own title with a back circle and the rows below.
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 14.dp).padding(bottom = 16.dp)) {
            Row(Modifier.fillMaxWidth().padding(top = 14.dp, bottom = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                SettingsScreenTitle(stringResource(R.string.account_settings), Modifier.weight(1f))
                AccountCircle(Icons.AutoMirrored.Rounded.ArrowBack, stringResource(R.string.common_back)) { settingsOpen = false }
            }
            listOf(
                MenuEntry(Icons.Rounded.Language, R.string.account_language, R.string.account_language_sub, onClick = { language.toggle() }),
                MenuEntry(Icons.Rounded.Info, R.string.account_about, R.string.account_about_sub, onClick = { showAbout = true }),
                MenuEntry(Icons.Rounded.DeleteForever, R.string.account_delete, R.string.account_delete_sub, danger = true, onClick = { showDelete = true }),
            ).forEachIndexed { index, entry -> AnimatedRow(index) { MenuRow(entry) } }
            ErrorBanner(error, Modifier.fillMaxWidth())
        }
    } else {
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 14.dp).padding(bottom = 16.dp)) {
            Row(Modifier.fillMaxWidth().padding(top = 14.dp, bottom = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                SettingsScreenTitle(stringResource(R.string.tab_account), Modifier.weight(1f))
                AccountCircle(Icons.Rounded.Settings, stringResource(R.string.account_settings)) { settingsOpen = true }
            }
            MeHeader(withBadges = true)
            Spacer(Modifier.height(12.dp))
            AccountWalletCard(onOpenWallet = actions.openWallet)
            Spacer(Modifier.height(10.dp))
            AccountStats()
            Spacer(Modifier.height(12.dp))
            listOf(
                MenuEntry(Icons.Rounded.Verified, R.string.home_open_verification, R.string.hub_verification_sub, onClick = actions.openVerification),
                MenuEntry(Icons.Rounded.Settings, R.string.account_settings, R.string.account_settings_sub, onClick = { settingsOpen = true }),
                MenuEntry(Icons.Rounded.Logout, R.string.account_logout, R.string.account_logout_sub, danger = true, onClick = { showLogout = true }),
            ).forEachIndexed { index, entry -> AnimatedRow(index) { MenuRow(entry) } }
        }
    }

    if (showLogout) {
        ConfirmDialog(
            icon = Icons.Rounded.Logout,
            title = stringResource(R.string.account_logout),
            message = stringResource(R.string.logout_confirm_message),
            confirmLabel = stringResource(R.string.common_yes),
            loading = busy,
            onConfirm = ::signOut,
            onDismiss = { if (!busy) showLogout = false },
        )
    }
    if (showDelete) {
        ConfirmDialog(
            icon = Icons.Rounded.DeleteForever,
            title = stringResource(R.string.account_delete),
            message = stringResource(R.string.delete_confirm_message),
            confirmLabel = stringResource(R.string.common_delete),
            loading = busy,
            onConfirm = ::deleteAccount,
            onDismiss = { if (!busy) showDelete = false },
        )
    }
    if (showAbout) {
        AlertDialog(
            onDismissRequest = { showAbout = false },
            title = { Text(stringResource(R.string.app_name), fontWeight = FontWeight.ExtraBold) },
            text = { Text(stringResource(R.string.about_body, BuildConfig.VERSION_NAME)) },
            confirmButton = { TextButton(onClick = { showAbout = false }) { Text(stringResource(R.string.common_ok), color = settingsAccent()) } },
        )
    }
}
