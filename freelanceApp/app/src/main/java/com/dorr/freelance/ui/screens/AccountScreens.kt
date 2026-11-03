package com.dorr.freelance.ui.screens

import androidx.compose.foundation.layout.widthIn
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
import androidx.compose.material.icons.rounded.Palette
import androidx.compose.material.icons.rounded.TextFields
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.School
import androidx.compose.material.icons.rounded.Verified
import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.slideOutHorizontally
import androidx.compose.animation.fadeOut
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.ContentTransform
import androidx.compose.animation.AnimatedContent
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
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.Policy
import androidx.compose.material.icons.rounded.PersonAdd
import androidx.compose.material.icons.rounded.Security
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material.icons.rounded.ConfirmationNumber
import androidx.compose.material.icons.rounded.Help
import androidx.compose.material.icons.rounded.SupportAgent
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Settings
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.WorkspacePremium
import androidx.compose.material.icons.rounded.Work
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.Insights
import androidx.compose.material.icons.rounded.LocalOffer
import androidx.compose.material.icons.rounded.Loyalty
import androidx.compose.material.icons.rounded.Quiz
import androidx.compose.material.icons.rounded.Speed
import androidx.compose.material.icons.rounded.Storefront
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.style.TextOverflow
import com.dorr.core.network.ApiResult
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.ui.kit.AccountDark
import com.dorr.freelance.ui.kit.settingsCard
import com.dorr.freelance.ui.kit.settingsNight
import com.dorr.freelance.ui.kit.settingsSurface
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
import com.dorr.freelance.ui.kit.SettingsScreenTitle
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.locale.LocalAppLanguage
import kotlinx.coroutines.launch

/** The round header button (settings / back): a card-coloured circle with a small icon. */
@Composable
private fun AccountCircle(icon: ImageVector, description: String, flip: Boolean = false, onClick: () -> Unit) {
    val dark = settingsNight()
    Box(
        Modifier.size(34.dp)
            .then(if (dark) Modifier else Modifier.shadow(6.dp, CircleShape, ambientColor = Color(0x14001B53), spotColor = Color(0x14001B53)))
            .clip(CircleShape).background(settingsCard())
            .then(if (dark) Modifier.border(1.dp, AccountDark.line, CircleShape) else Modifier)
            .clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Icon(icon, contentDescription = description, tint = if (dark) AccountDark.accent else settingsAccent(), modifier = Modifier.size(16.dp).then(if (flip) Modifier.graphicsLayer { scaleX = -1f } else Modifier)) }
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
    val accent = settingsAccent()
    val deep = Color(accent.red * 0.62f, accent.green * 0.62f, accent.blue * 0.62f)
    var balanceText by remember { mutableStateOf("0.00") }
    var currencyText by remember { mutableStateOf("") }
    val reconnect = collectReconnectTick()
    LaunchedEffect(reconnect) {
        (apiCall { ApiClient.wallet.balance() } as? ApiResult.Ok)?.data?.let {
            balanceText = formatMinor(it.totalMinor, null)
            currencyText = it.currencySymbol ?: it.currencyCode.orEmpty()
        }
    }
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(22.dp))
            .background(androidx.compose.ui.graphics.Brush.linearGradient(listOf(accent, deep)))
            .clickable(onClick = onOpenWallet).padding(16.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(44.dp).clip(RoundedCornerShape(14.dp)).background(Color.White.copy(alpha = 0.18f)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.AccountBalanceWallet, null, tint = Color.White, modifier = Modifier.size(22.dp))
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(stringResource(R.string.home_wallet_balance), fontSize = 12.sp, fontWeight = FontWeight.SemiBold, color = Color.White.copy(alpha = 0.85f))
            Row(verticalAlignment = Alignment.Bottom) {
                Text("\u200E" + balanceText, fontSize = 24.sp, fontWeight = FontWeight.Black, color = Color.White)
                if (currencyText.isNotBlank()) {
                    Spacer(Modifier.width(6.dp))
                    Text(currencyText, fontSize = 12.sp, fontWeight = FontWeight.Bold, color = Color.White.copy(alpha = 0.85f), modifier = Modifier.padding(bottom = 4.dp))
                }
            }
        }
        Text(
            stringResource(R.string.home_wallet_topup), color = accent, fontSize = 12.sp, fontWeight = FontWeight.ExtraBold,
            modifier = Modifier.clip(RoundedCornerShape(50)).background(Color.White).clickable(onClick = onOpenWallet).padding(horizontal = 14.dp, vertical = 8.dp),
        )
    }
}

/** Three small tiles of what the freelancer has built: the profile completeness, the works and the skills. */
@Composable
private fun AccountStats() {
    val me by Session.me.collectAsState()
    var works by remember { mutableStateOf<Int?>(null) }
    var skills by remember { mutableStateOf<Int?>(null) }
    val reconnect = collectReconnectTick()
    LaunchedEffect(reconnect) {
        (apiCall { ApiClient.portfolio.list() } as? ApiResult.Ok)?.data?.let { works = it.size }
        (apiCall { ApiClient.lists.mySkills() } as? ApiResult.Ok)?.data?.let { skills = it.size }
    }
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        com.dorr.freelance.ui.kit.StatTile(Icons.Rounded.CheckCircle, "${me?.completeness?.percent ?: 0}%", stringResource(R.string.stat_completeness), com.dorr.freelance.ui.kit.Tints.blue, Modifier.weight(1f))
        com.dorr.freelance.ui.kit.StatTile(Icons.Rounded.Work, works?.toString() ?: "-", stringResource(R.string.stat_works), com.dorr.freelance.ui.kit.Tints.amber, Modifier.weight(1f))
        com.dorr.freelance.ui.kit.StatTile(Icons.Rounded.Bolt, skills?.toString() ?: "-", stringResource(R.string.stat_skills), com.dorr.freelance.ui.kit.Tints.violet, Modifier.weight(1f))
    }
}

/**
 * The head of «Me»: my photo inside a ring that fills with how complete my profile is, my name with the verified tick, my headline (or phone)
 * and status, then «Edit my profile» and «Improve my profile».
 */
@Composable
private fun MeHero(onEdit: () -> Unit, onCoach: () -> Unit) {
    val me by Session.me.collectAsState()
    val accent = settingsAccent()
    val percent = (me?.completeness?.percent ?: 0).coerceIn(0, 100)
    val track = settingsMut().copy(alpha = 0.18f)
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(22.dp)).padding(16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(84.dp), contentAlignment = Alignment.Center) {
                androidx.compose.foundation.Canvas(Modifier.size(84.dp)) {
                    val stroke = androidx.compose.ui.graphics.drawscope.Stroke(width = 5.dp.toPx(), cap = androidx.compose.ui.graphics.StrokeCap.Round)
                    drawArc(track, 0f, 360f, false, style = stroke)
                    drawArc(accent, -90f, 360f * percent / 100f, false, style = stroke)
                }
                AvatarImage(url = me?.avatarUrl, size = 68.dp)
            }
            Spacer(Modifier.width(14.dp))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        me?.name?.takeIf { it.isNotBlank() } ?: stringResource(R.string.account_default_user),
                        color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.Black, maxLines = 1, overflow = TextOverflow.Ellipsis,
                        modifier = Modifier.weight(1f, fill = false),
                    )
                    if (me?.status == "verified") {
                        Spacer(Modifier.width(6.dp))
                        Box(Modifier.size(17.dp).clip(CircleShape).background(accent), contentAlignment = Alignment.Center) {
                            Icon(Icons.Rounded.Check, contentDescription = stringResource(R.string.account_verified), tint = Color.White, modifier = Modifier.size(11.dp))
                        }
                    }
                }
                Text(
                    me?.headline?.takeIf { it.isNotBlank() } ?: ("\u200E" + me?.phone.orEmpty()),
                    color = settingsMut(), fontSize = 12.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis,
                )
                com.dorr.freelance.ui.kit.StatusPill(statusLabel(me?.status), if (me?.status == "verified") com.dorr.freelance.ui.kit.Tints.green else accent)
            }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Row(
                Modifier.weight(1f).height(42.dp).clip(RoundedCornerShape(14.dp)).background(accent).clickable(onClick = onEdit),
                horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.Person, null, tint = Color.White, modifier = Modifier.size(17.dp))
                Spacer(Modifier.width(6.dp))
                Text(stringResource(R.string.me_edit_profile), color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
            }
            Row(
                Modifier.weight(1f).height(42.dp).clip(RoundedCornerShape(14.dp)).border(1.dp, accent.copy(alpha = 0.4f), RoundedCornerShape(14.dp)).clickable(onClick = onCoach),
                horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.AutoAwesome, null, tint = accent, modifier = Modifier.size(17.dp))
                Spacer(Modifier.width(6.dp))
                Text(stringResource(R.string.me_improve, percent), color = accent, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
            }
        }
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

/** A group of rows under its small title; the rows slide in one after the other, counting on from [startIndex]. */
@Composable
private fun MeSection(title: Int, startIndex: Int, entries: List<MenuEntry>) {
    if (entries.isEmpty()) return
    Text(
        stringResource(title), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.ExtraBold,
        modifier = Modifier.padding(top = 18.dp, bottom = 8.dp, start = 4.dp),
    )
    AnimatedRow(startIndex) { com.dorr.freelance.ui.kit.MenuGroup(entries) }
}

/** My profile, a page under the Account tab: the personal data and the lists that make the profile, under a title with a back circle. */
@Composable
private fun ProfileHubPage(actions: MainActions, onBack: () -> Unit) {
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 14.dp).padding(bottom = 16.dp)) {
        Row(Modifier.fillMaxWidth().padding(top = 14.dp, bottom = 12.dp), verticalAlignment = Alignment.CenterVertically) {
            SettingsScreenTitle(stringResource(R.string.tab_profile), Modifier.weight(1f))
            AccountCircle(Icons.AutoMirrored.Rounded.ArrowBack, stringResource(R.string.common_back), flip = true, onClick = onBack)
        }
        listOf(
            MenuEntry(Icons.Rounded.Person, R.string.account_personal_data, R.string.account_personal_data_sub, onClick = actions.openProfile),
            MenuEntry(Icons.Rounded.Category, R.string.home_open_specialties, R.string.hub_specialties_sub, onClick = actions.openSpecialties),
            MenuEntry(Icons.Rounded.Bolt, R.string.home_open_skills, R.string.hub_skills_sub, onClick = actions.openSkills),
            MenuEntry(Icons.Rounded.Build, R.string.home_open_experiences, R.string.hub_experiences_sub, onClick = actions.openExperiences),
            MenuEntry(Icons.Rounded.School, R.string.home_open_certificates, R.string.hub_certificates_sub, onClick = actions.openCertificates),
            MenuEntry(Icons.Rounded.Work, R.string.portfolio_title, R.string.hub_portfolio_sub, onClick = actions.openPortfolio),
            MenuEntry(Icons.Rounded.EventAvailable, R.string.availability_title, R.string.availability_sub, onClick = actions.openAvailability),
            MenuEntry(Icons.Rounded.Badge, R.string.home_open_verification, R.string.hub_verification_sub, onClick = actions.openVerification),
        ).let { com.dorr.freelance.ui.kit.MenuGroup(it) }
    }
}

private const val ACCOUNT_RATE = 1
private const val ACCOUNT_SUPPORT = 2
private const val ACCOUNT_QUICK_CHAT = 3
private const val ACCOUNT_TICKET = 4
private const val ACCOUNT_INVITE = 5
private const val ACCOUNT_PROFILE = 6

/** Which page of the Account tab is drawn: [account] is an `ACCOUNT_*` page (0 = none) and [settings] is `settingsPage` (-1 = settings closed). */
private data class AccountShown(val account: Int, val settings: Int) {
    /** How deep under the Account list the page is; going to a deeper one slides forward, to a shallower one back. */
    fun depth(): Int = when {
        account == ACCOUNT_QUICK_CHAT || account == ACCOUNT_TICKET -> 2
        account != 0 -> 1
        settings == -1 -> 0
        settings == 0 -> 1
        settings in 5..6 -> 3
        else -> 2
    }
}

/** A page of menu rows under a title with a back circle (the support menu) — the same look as the settings page. */
@Composable
private fun AccountListPage(title: String, onBack: () -> Unit, entries: List<MenuEntry>) {
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 14.dp).padding(bottom = 16.dp)) {
        Row(Modifier.fillMaxWidth().padding(top = 14.dp, bottom = 12.dp), verticalAlignment = Alignment.CenterVertically) {
            SettingsScreenTitle(title, Modifier.weight(1f))
            AccountCircle(Icons.AutoMirrored.Rounded.ArrowBack, stringResource(R.string.common_back), flip = true, onClick = onBack)
        }
        AnimatedRow(0) { com.dorr.freelance.ui.kit.MenuGroup(entries) }
    }
}

/** My account: the settings (language, appearance, font, contact, about), rating the app, support, signing out and deleting the account — the DORR app's pages. */
@Composable
fun AccountScreen(actions: MainActions) {
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
    // 0 = the settings list, 1 = colours and mode, 2 = the font (each a page of its own, as in the DORR app).
    var settingsPage by rememberSaveable { mutableStateOf(0) }
    var showLanguage by remember { mutableStateOf(false) }
    // 3 = notifications, 4 = policies & terms (5 privacy, 6 terms under it), 7 = security (devices and the log)
    BackHandler(enabled = settingsOpen && settingsPage in 5..6) { settingsPage = 4 }
    BackHandler(enabled = settingsOpen && (settingsPage in 1..4 || settingsPage == 7)) { settingsPage = 0 }
    BackHandler(enabled = settingsOpen && settingsPage == 0) { settingsOpen = false }
    // The pages under the main list (0 = none): rate the app, the support menu, the quick chat and the tickets.
    var accountPage by rememberSaveable { mutableStateOf(0) }
    LaunchedEffect(com.dorr.freelance.ui.components.ShellState.openProfileHub) {
        if (com.dorr.freelance.ui.components.ShellState.openProfileHub) {
            accountPage = ACCOUNT_PROFILE
            com.dorr.freelance.ui.components.ShellState.openProfileHub = false
        }
    }
    var showContact by remember { mutableStateOf(false) }
    var showFaq by remember { mutableStateOf(false) }
    BackHandler(enabled = accountPage == ACCOUNT_RATE || accountPage == ACCOUNT_SUPPORT || accountPage == ACCOUNT_INVITE || accountPage == ACCOUNT_PROFILE) { accountPage = 0 }

    // One page at a time, sliding like the DORR app's profile pages: forward comes in from the trailing edge, back from the leading one.
    val shownNow = AccountShown(accountPage, if (settingsOpen) settingsPage else -1)
    AnimatedContent(
        targetState = shownNow,
        label = "accountPage",
        transitionSpec = {
            val forward = targetState.depth() >= initialState.depth()
            val sign = if (forward) 1 else -1
            ContentTransform(
                slideInHorizontally(tween(340, easing = FastOutSlowInEasing)) { sign * it / 5 } + fadeIn(tween(280)),
                slideOutHorizontally(tween(300, easing = FastOutSlowInEasing)) { -sign * it / 5 } + fadeOut(tween(220)),
                sizeTransform = null,
            )
        },
    ) { shown ->
    if (shown.account == ACCOUNT_RATE) {
        RateAppScreen(onBack = { accountPage = 0 })
    } else if (shown.account == ACCOUNT_PROFILE) {
        ProfileHubPage(actions, onBack = { accountPage = 0 })
    } else if (shown.account == ACCOUNT_INVITE) {
        InviteFriendsScreen(onBack = { accountPage = 0 })
    } else if (shown.account == ACCOUNT_SUPPORT) {
        AccountListPage(
            title = stringResource(R.string.support_title),
            onBack = { accountPage = 0 },
            entries = listOf(
                MenuEntry(Icons.Rounded.SupportAgent, R.string.support_quick_chat_title, R.string.support_quick_chat_sub, onClick = { accountPage = ACCOUNT_QUICK_CHAT }),
                MenuEntry(Icons.Rounded.ConfirmationNumber, R.string.support_ticket_title, R.string.support_ticket_sub, onClick = { accountPage = ACCOUNT_TICKET }),
                MenuEntry(Icons.Rounded.Help, R.string.account_faqs, R.string.account_faqs_sub, onClick = { showFaq = true }),
            ),
        )
    } else if (shown.account == ACCOUNT_QUICK_CHAT) {
        // The guided help that ends in "solved" or a ticket (the ticket screen hosts both).
        SupportTicketScreen(onBack = { accountPage = ACCOUNT_SUPPORT }, startQuickChat = true)
    } else if (shown.account == ACCOUNT_TICKET) {
        SupportTicketScreen(onBack = { accountPage = ACCOUNT_SUPPORT })
    } else if (shown.settings == 1) {
        AppearanceScreen(onBack = { settingsPage = 0 })
    } else if (shown.settings == 2) {
        AppearanceFontScreen(onBack = { settingsPage = 0 })
    } else if (shown.settings == 3) {
        NotificationSettingsScreen(onBack = { settingsPage = 0 })
    } else if (shown.settings == 7) {
        SecurityScreen(onBack = { settingsPage = 0 })
    } else if (shown.settings == 4) {
        AccountListPage(
            title = stringResource(R.string.account_legal),
            onBack = { settingsPage = 0 },
            entries = listOf(
                MenuEntry(Icons.Rounded.Shield, R.string.account_privacy, R.string.account_privacy_sub, onClick = { settingsPage = 5 }),
                MenuEntry(Icons.Rounded.Description, R.string.account_terms, R.string.account_terms_sub, onClick = { settingsPage = 6 }),
            ),
        )
    } else if (shown.settings == 5) {
        PrivacyPolicyScreen(onBack = { settingsPage = 4 })
    } else if (shown.settings == 6) {
        TermsConditionsScreen(onBack = { settingsPage = 4 })
    } else if (shown.settings == 0) {
        // The settings page, as in the DORR app: its own title with a back circle and the rows below.
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 14.dp).padding(bottom = 16.dp)) {
            Row(Modifier.fillMaxWidth().padding(top = 14.dp, bottom = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                SettingsScreenTitle(stringResource(R.string.account_settings), Modifier.weight(1f))
                AccountCircle(Icons.AutoMirrored.Rounded.ArrowBack, stringResource(R.string.common_back), flip = true) { settingsOpen = false }
            }
            MeSection(R.string.me_settings_prefs, 0, listOf(
                MenuEntry(Icons.Rounded.Notifications, R.string.account_notifications, R.string.account_notifications_sub, onClick = { settingsPage = 3 }),
                MenuEntry(Icons.Rounded.Security, R.string.security_title, R.string.security_sub, onClick = { settingsPage = 7 }),
                MenuEntry(Icons.Rounded.Language, R.string.account_language, R.string.account_language_sub, onClick = { showLanguage = true }),
                MenuEntry(Icons.Rounded.Palette, R.string.appearance_title, R.string.appearance_sub, onClick = { settingsPage = 1 }),
                MenuEntry(Icons.Rounded.TextFields, R.string.appearance_font, R.string.appearance_font_sub, onClick = { settingsPage = 2 }),
            ))
            MeSection(R.string.me_settings_about, 1, listOf(
                MenuEntry(Icons.Rounded.Call, R.string.account_contact_us, R.string.account_contact_us_sub, onClick = { showContact = true }),
                MenuEntry(Icons.Rounded.Info, R.string.account_about, R.string.account_about_sub, onClick = { showAbout = true }),
                MenuEntry(Icons.Rounded.Policy, R.string.account_legal, R.string.account_legal_sub, onClick = { settingsPage = 4 }),
            ))
            MeSection(R.string.me_settings_account, 2, listOf(
                MenuEntry(Icons.Rounded.DeleteForever, R.string.account_delete, R.string.account_delete_sub, danger = true, onClick = { showDelete = true }),
            ))
            ErrorBanner(error, Modifier.fillMaxWidth())
        }
    } else {
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 14.dp).padding(bottom = 16.dp)) {
            Row(Modifier.fillMaxWidth().padding(top = 14.dp, bottom = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                SettingsScreenTitle(stringResource(R.string.tab_me), Modifier.weight(1f))
                AccountCircle(Icons.Rounded.Settings, stringResource(R.string.account_settings)) { settingsOpen = true }
            }
            MeHero(onEdit = { accountPage = ACCOUNT_PROFILE }, onCoach = actions.openCoach)
            Spacer(Modifier.height(12.dp))
            AccountWalletCard(onOpenWallet = actions.openWallet)
            Spacer(Modifier.height(12.dp))
            AccountStats()
            // The rows in three groups (the redesign): my professional profile, my business and money, the app.
            val home by com.dorr.freelance.net.HomeHub.home.collectAsState()
            val on = { feature: String -> home?.on(feature) != false }
            MeSection(R.string.me_section_profile, 0, listOf(
                MenuEntry(Icons.Rounded.Person, R.string.tab_profile, R.string.account_profile_sub, onClick = { accountPage = ACCOUNT_PROFILE }),
                MenuEntry(Icons.Rounded.Work, R.string.portfolio_title, R.string.hub_portfolio_sub, onClick = actions.openPortfolio),
                MenuEntry(Icons.Rounded.AutoAwesome, R.string.coach_title, R.string.me_coach_sub, onClick = actions.openCoach),
                MenuEntry(Icons.Rounded.Badge, R.string.home_open_verification, R.string.hub_verification_sub, onClick = actions.openVerification),
            ) + listOfNotNull(
                if (on("skill_tests")) MenuEntry(Icons.Rounded.Quiz, R.string.skt_home_card, R.string.me_skill_tests_sub, onClick = actions.openSkillTests) else null,
            ))
            MeSection(R.string.me_section_business, 1, listOfNotNull(
                if (on("services")) MenuEntry(Icons.Rounded.Storefront, R.string.svc_home_card, R.string.me_services_sub, onClick = actions.openServices) else null,
                if (on("special_offers")) MenuEntry(Icons.Rounded.LocalOffer, R.string.spo_home_card, R.string.me_special_offers_sub, onClick = actions.openSpecialOffers) else null,
                if (on("loyalty")) MenuEntry(Icons.Rounded.Loyalty, R.string.loy_home_card, R.string.me_loyalty_sub, onClick = actions.openLoyalty) else null,
                if (on("subscriptions")) MenuEntry(Icons.Rounded.WorkspacePremium, R.string.plans_title, R.string.plans_sub, onClick = actions.openPlans) else null,
                MenuEntry(Icons.Rounded.Insights, R.string.insights_home_card, R.string.me_insights_sub, onClick = actions.openInsights),
                MenuEntry(Icons.Rounded.EventAvailable, R.string.availability_title, R.string.availability_sub, onClick = actions.openAvailability),
                MenuEntry(Icons.Rounded.Speed, R.string.cap_home_card, R.string.me_capacity_sub, onClick = actions.openCapacity),
            ))
            MeSection(R.string.me_section_app, 2, listOf(
                MenuEntry(Icons.Rounded.Settings, R.string.account_settings, R.string.account_settings_sub, onClick = { settingsOpen = true }),
                MenuEntry(Icons.Rounded.SupportAgent, R.string.support_title, R.string.support_sub, onClick = { accountPage = ACCOUNT_SUPPORT }),
                MenuEntry(Icons.Rounded.PersonAdd, R.string.invite_title, R.string.invite_sub, onClick = { accountPage = ACCOUNT_INVITE }),
                MenuEntry(Icons.Rounded.Star, R.string.rate_title, R.string.rate_sub, onClick = { accountPage = ACCOUNT_RATE }),
                MenuEntry(Icons.Rounded.Logout, R.string.account_logout, R.string.account_logout_sub, danger = true, onClick = { showLogout = true }),
            ))
        }
    }
    }

    if (showLanguage) LanguageDialog(onDismiss = { showLanguage = false })
    if (showContact) ContactUsSheet(onDismiss = { showContact = false })
    if (showFaq) FaqSheet(onDismiss = { showFaq = false })
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
    if (showAbout) AboutDialog(onDismiss = { showAbout = false })
}

/** "About the app": the same card as the user app - a pink backdrop, the app name with its icon, the version, a line about the app and a close button. */
@Composable
private fun AboutDialog(onDismiss: () -> Unit) {
    com.dorr.freelance.ui.locale.LocaleAwareDialog(
        onDismissRequest = onDismiss,
        properties = androidx.compose.ui.window.DialogProperties(usePlatformDefaultWidth = false),
    ) {
        Box(Modifier.fillMaxSize().background(Color.Black.copy(alpha = 0.45f)).clickable(onClick = onDismiss), contentAlignment = Alignment.Center) {
            Box(
                Modifier.padding(horizontal = 24.dp).widthIn(max = 300.dp).fillMaxWidth()
                    .shadow(16.dp, RoundedCornerShape(20.dp), ambientColor = Color(0x24001B53), spotColor = Color(0x24001B53))
                    .clip(RoundedCornerShape(20.dp))
                    .clickable(interactionSource = remember { androidx.compose.foundation.interaction.MutableInteractionSource() }, indication = null, onClick = {}),
            ) {
                com.dorr.freelance.ui.kit.PinkBackdrop(Modifier.matchParentSize())
                Column(Modifier.padding(start = 16.dp, end = 16.dp, top = 18.dp, bottom = 16.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(bottom = 12.dp)) {
                        com.dorr.freelance.ui.kit.PinkIcon(Icons.Rounded.Info)
                        Spacer(Modifier.width(10.dp))
                        Text(stringResource(R.string.app_name), color = if (settingsNight()) AccountDark.accent else settingsAccent(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
                    }
                    Text(stringResource(R.string.about_version, BuildConfig.VERSION_NAME), color = settingsMut(), fontSize = 13.sp, lineHeight = 22.sp)
                    Spacer(Modifier.height(22.dp))
                    Text(stringResource(R.string.about_description), color = settingsMut(), fontSize = 13.sp, lineHeight = 22.sp, modifier = Modifier.padding(bottom = 14.dp))
                    Box(
                        Modifier.fillMaxWidth().height(44.dp)
                            .shadow(8.dp, RoundedCornerShape(14.dp), ambientColor = Color(0x38001B53), spotColor = Color(0x38001B53))
                            .clip(RoundedCornerShape(14.dp)).background(settingsAccent()).clickable(onClick = onDismiss),
                        contentAlignment = Alignment.Center,
                    ) { Text(stringResource(R.string.common_close), color = Color.White, fontSize = 15.sp, fontWeight = FontWeight.Bold) }
                }
            }
        }
    }
}
