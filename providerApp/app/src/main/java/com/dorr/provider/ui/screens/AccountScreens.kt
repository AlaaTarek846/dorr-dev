package com.dorr.provider.ui.screens

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
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
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
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Storefront
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Badge
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.LocalShipping
import androidx.compose.material.icons.rounded.Store
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.ExitToApp
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.Language
import androidx.compose.material.icons.rounded.Logout
import androidx.compose.material.icons.rounded.MarkEmailUnread
import androidx.compose.material.icons.rounded.Policy
import androidx.compose.material.icons.rounded.Settings
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.provider.BuildConfig
import com.dorr.provider.R
import com.dorr.provider.net.ApiClient
import com.dorr.provider.net.LogoutRequest
import com.dorr.provider.net.MembershipDto
import com.dorr.provider.net.Realtime
import com.dorr.provider.net.Session
import com.dorr.provider.net.Workspace
import com.dorr.provider.net.apiDone
import com.dorr.provider.net.can
import com.dorr.provider.ui.components.AvatarImage
import com.dorr.provider.ui.components.ErrorBanner
import com.dorr.provider.ui.components.ShellState
import com.dorr.provider.ui.components.TAB_HOME
import com.dorr.provider.ui.kit.AccountDark
import com.dorr.provider.ui.kit.ConfirmDialog
import com.dorr.provider.ui.kit.MenuEntry
import com.dorr.provider.ui.kit.MenuRow
import com.dorr.provider.ui.kit.PinkBackdrop
import com.dorr.provider.ui.kit.PinkIcon
import com.dorr.provider.ui.kit.SettingsScreenTitle
import com.dorr.provider.ui.kit.settingsAccent
import com.dorr.provider.ui.kit.settingsCard
import com.dorr.provider.ui.kit.settingsInk
import com.dorr.provider.ui.kit.settingsMut
import com.dorr.provider.ui.kit.settingsNight
import kotlinx.coroutines.launch

/** The person at the top of the Account tab: the photo, the name and the phone. */
@Composable
private fun MeHeader() {
    val me by Session.me.collectAsState()
    val dark = settingsNight()
    val accent = settingsAccent()
    Row(Modifier.fillMaxWidth().padding(top = 4.dp, bottom = 12.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(
            Modifier.size(56.dp)
                .then(if (dark) Modifier else Modifier.shadow(6.dp, CircleShape, spotColor = accent.copy(alpha = 0.1f)))
                .clip(CircleShape).background(settingsCard())
                .then(if (dark) Modifier.border(1.dp, AccountDark.line, CircleShape) else Modifier),
            contentAlignment = Alignment.Center,
        ) { AvatarImage(url = me?.avatarUrl, size = 56.dp) }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(
                me?.name?.takeIf { it.isNotBlank() } ?: stringResource(R.string.account_default_user),
                color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis,
            )
            // A phone number keeps its left-to-right order inside Arabic text.
            Text("‎" + me?.phone.orEmpty(), color = settingsMut(), fontSize = 12.sp, maxLines = 1)
        }
    }
}

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

/**
 * One establishment I hold a seat in, in the look of a menu row: its logo, its name, my place in it (owner / member, exclusive),
 * and a tick on the one the app works in now. A tap works in it from now on.
 */
@Composable
private fun EstablishmentRow(seat: MembershipDto, current: Boolean, onClick: () -> Unit) {
    val dark = settingsNight()
    val accent = settingsAccent()
    val shape = RoundedCornerShape(14.dp)
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = 4.dp)
            .then(if (dark) Modifier else Modifier.shadow(6.dp, shape, spotColor = accent.copy(alpha = 0.07f)))
            .clip(shape)
            .background(settingsCard())
            .border(if (current) 1.5.dp else 1.dp, if (current) accent else if (dark) AccountDark.line else Color.Transparent, shape)
            .clickable(onClick = onClick)
            .padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        AvatarImage(url = seat.establishment?.logo, size = 34.dp)
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(seat.establishment?.name.orEmpty(), fontSize = 13.5.sp, fontWeight = FontWeight.Bold, color = settingsInk(), maxLines = 1, overflow = TextOverflow.Ellipsis)
            val role = stringResource(if (seat.isOwner) R.string.acc_role_owner else R.string.acc_role_member)
            val line = if (seat.isExclusive) role + " · " + stringResource(R.string.ws_exclusive) else role
            Text(line, fontSize = 11.sp, color = settingsInk().copy(alpha = 0.65f))
        }
        if (current) {
            Box(Modifier.size(20.dp).clip(CircleShape).background(accent), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Check, contentDescription = stringResource(R.string.acc_current), tint = Color.White, modifier = Modifier.size(13.dp))
            }
        }
    }
}

/** A page of menu rows under a title with a back circle — the same look as the settings page. */
@Composable
private fun AccountListPage(title: String, onBack: () -> Unit, entries: List<MenuEntry>) {
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 14.dp).padding(bottom = 16.dp)) {
        Row(Modifier.fillMaxWidth().padding(top = 14.dp, bottom = 12.dp), verticalAlignment = Alignment.CenterVertically) {
            SettingsScreenTitle(title, Modifier.weight(1f))
            AccountCircle(Icons.AutoMirrored.Rounded.ArrowBack, stringResource(R.string.common_back), flip = true, onClick = onBack)
        }
        entries.forEachIndexed { index, entry -> AnimatedRow(index) { MenuRow(entry) } }
    }
}

private const val PAGE_LIST = 0
private const val PAGE_SETTINGS = 1
private const val PAGE_LEGAL = 2
private const val PAGE_PRIVACY = 3
private const val PAGE_TERMS = 4

/** How deep a page sits under the Account list; going deeper slides forward, shallower slides back. */
private fun depthOf(page: Int): Int = when (page) {
    PAGE_LIST -> 0
    PAGE_SETTINGS -> 1
    PAGE_LEGAL -> 2
    else -> 3
}

/**
 * My account: who I am, **the establishments I work in** (switching between them, §14 — the choice becomes the `X-Establishment`
 * of every request), the invitations, creating my own establishment, leaving one I am a member of, the papers (owner), the
 * settings (language, policies, about) and signing out — the DORR app's pages.
 */
@Composable
fun AccountScreen(actions: MainActions) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val workspace by Workspace.state.collectAsState()
    val currentUuid by Session.establishment.collectAsState()
    val seat by Session.membership.collectAsState()

    var page by rememberSaveable { mutableStateOf(PAGE_LIST) }
    var showLogout by remember { mutableStateOf(false) }
    var showLeave by remember { mutableStateOf(false) }
    var showLanguage by remember { mutableStateOf(false) }
    var showAbout by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    BackHandler(enabled = page == PAGE_PRIVACY || page == PAGE_TERMS) { page = PAGE_LEGAL }
    BackHandler(enabled = page == PAGE_LEGAL) { page = PAGE_SETTINGS }
    BackHandler(enabled = page == PAGE_SETTINGS) { page = PAGE_LIST }

    fun signOut() {
        busy = true
        scope.launch {
            // The token is revoked on the server; the phone forgets it whatever the answer.
            apiCall { ApiClient.auth.logout(LogoutRequest(playerId = Realtime.pushId())) }
            Session.clear()
        }
    }

    fun choose(uuid: String?) {
        if (uuid == null || uuid == currentUuid) return
        Session.chooseEstablishment(uuid)
        ShellState.tab = TAB_HOME
    }

    /** Leaves the establishment the app works in now (not my own): the gate then picks where I work next. */
    fun leave() {
        val membership = seat ?: return
        busy = true
        error = null
        scope.launch {
            val failed = apiDone(offlineText, genericError) { ApiClient.workspace.leave(membership.uuid) }
            busy = false
            showLeave = false
            if (failed != null) {
                error = failed
                return@launch
            }
            Session.chooseEstablishment(null)
            Workspace.reload()
            actions.openWorkspace()
        }
    }

    AnimatedContent(
        targetState = page,
        label = "accountPage",
        transitionSpec = {
            val sign = if (depthOf(targetState) >= depthOf(initialState)) 1 else -1
            ContentTransform(
                slideInHorizontally(tween(340, easing = FastOutSlowInEasing)) { sign * it / 5 } + fadeIn(tween(280)),
                slideOutHorizontally(tween(300, easing = FastOutSlowInEasing)) { -sign * it / 5 } + fadeOut(tween(220)),
                sizeTransform = null,
            )
        },
    ) { shown ->
        when (shown) {
            PAGE_PRIVACY -> PrivacyPolicyScreen(onBack = { page = PAGE_LEGAL })
            PAGE_TERMS -> TermsConditionsScreen(onBack = { page = PAGE_LEGAL })
            PAGE_LEGAL -> AccountListPage(
                title = stringResource(R.string.account_legal),
                onBack = { page = PAGE_SETTINGS },
                entries = listOf(
                    MenuEntry(Icons.Rounded.Shield, R.string.account_privacy, R.string.account_privacy_sub, onClick = { page = PAGE_PRIVACY }),
                    MenuEntry(Icons.Rounded.Description, R.string.account_terms, R.string.account_terms_sub, onClick = { page = PAGE_TERMS }),
                ),
            )
            PAGE_SETTINGS -> AccountListPage(
                title = stringResource(R.string.account_settings),
                onBack = { page = PAGE_LIST },
                entries = listOf(
                    MenuEntry(Icons.Rounded.Language, R.string.account_language, R.string.account_language_sub, onClick = { showLanguage = true }),
                    MenuEntry(Icons.Rounded.Policy, R.string.account_legal, R.string.account_legal_sub, onClick = { page = PAGE_LEGAL }),
                    MenuEntry(Icons.Rounded.Info, R.string.account_about, R.string.account_about_sub, onClick = { showAbout = true }),
                ),
            )
            else -> Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 14.dp).padding(bottom = 16.dp)) {
                Row(Modifier.fillMaxWidth().padding(top = 14.dp, bottom = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                    SettingsScreenTitle(stringResource(R.string.tab_account), Modifier.weight(1f))
                    AccountCircle(Icons.Rounded.Settings, stringResource(R.string.account_settings)) { page = PAGE_SETTINGS }
                }
                MeHeader()

                // The establishments I work in (§14): the one in use carries the tick; a tap switches to another.
                val seats = workspace?.memberships.orEmpty()
                if (seats.isNotEmpty()) {
                    Text(
                        stringResource(if (seats.size > 1) R.string.acc_establishments_switch else R.string.acc_establishment),
                        color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 4.dp, bottom = 2.dp),
                    )
                    seats.forEachIndexed { index, membership ->
                        AnimatedRow(index) {
                            EstablishmentRow(membership, current = membership.establishment?.uuid == currentUuid) { choose(membership.establishment?.uuid) }
                        }
                    }
                    Spacer(Modifier.height(10.dp))
                }

                val invitations = workspace?.invitations.orEmpty()
                buildList<MenuEntry> {
                    if (invitations.isNotEmpty()) add(MenuEntry(Icons.Rounded.MarkEmailUnread, R.string.ws_invitations_title, R.string.acc_invitations_sub, onClick = actions.openInvitations))
                    if (seat?.isOwner == true) add(MenuEntry(Icons.Rounded.Description, R.string.pd_title, R.string.acc_papers_sub, onClick = actions.openDocuments))
                    // Running the establishment (C-03), each by its permission (§17); the server checks it again.
                    if (seat.can("branches.manage")) add(MenuEntry(Icons.Rounded.Store, R.string.br_title, R.string.acc_branches_sub, onClick = actions.openBranches))
                    if (seat.can("members.manage")) add(MenuEntry(Icons.Rounded.Groups, R.string.tm_title, R.string.acc_team_sub, onClick = actions.openTeam))
                    if (seat.can("fleet.vehicles")) add(MenuEntry(Icons.Rounded.LocalShipping, R.string.vh_title, R.string.acc_vehicles_sub, onClick = actions.openVehicles))
                    // My own driving licence, when I work as a driver here.
                    seat?.takeIf { it.can("driving.work") }?.let { mine -> add(MenuEntry(Icons.Rounded.Badge, R.string.drv_docs_title, R.string.acc_my_licence_sub, onClick = { actions.openDriverDocs(mine.uuid) })) }
                    if (workspace?.ownsEstablishment == false) add(MenuEntry(Icons.Rounded.Storefront, R.string.ws_create_own_title, R.string.acc_create_own_sub, onClick = actions.openOnboarding))
                    add(MenuEntry(Icons.Rounded.Settings, R.string.account_settings, R.string.account_settings_sub, onClick = { page = PAGE_SETTINGS }))
                    // Leaving is for a member; my own establishment is never "left" (§14: the driver unlinks any time).
                    if (seat != null && seat?.isOwner == false) add(MenuEntry(Icons.Rounded.ExitToApp, R.string.acc_leave, R.string.acc_leave_sub, danger = true, onClick = { showLeave = true }))
                    add(MenuEntry(Icons.Rounded.Logout, R.string.account_logout, R.string.account_logout_sub, danger = true, onClick = { showLogout = true }))
                }.forEachIndexed { index, entry -> AnimatedRow(index + seats.size) { MenuRow(entry) } }
                ErrorBanner(error, Modifier.fillMaxWidth())
            }
        }
    }

    if (showLanguage) LanguageDialog(onDismiss = { showLanguage = false })
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
    if (showLeave) {
        ConfirmDialog(
            icon = Icons.Rounded.ExitToApp,
            title = stringResource(R.string.acc_leave),
            message = stringResource(R.string.acc_leave_message, seat?.establishment?.name.orEmpty()),
            confirmLabel = stringResource(R.string.acc_leave_confirm),
            loading = busy,
            onConfirm = ::leave,
            onDismiss = { if (!busy) showLeave = false },
        )
    }
    if (showAbout) AboutDialog(onDismiss = { showAbout = false })
}

@Composable
private fun AboutDialog(onDismiss: () -> Unit) {
    com.dorr.provider.ui.locale.LocaleAwareDialog(
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
                PinkBackdrop(Modifier.matchParentSize())
                Column(Modifier.padding(start = 16.dp, end = 16.dp, top = 18.dp, bottom = 16.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(bottom = 12.dp)) {
                        PinkIcon(Icons.Rounded.Info)
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
