package com.dorr.provider.ui.components

import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.consumeWindowInsets
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.isImeVisible
import androidx.compose.foundation.layout.navigationBars
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.outlined.Home
import androidx.compose.material.icons.outlined.Person
import androidx.compose.material.icons.outlined.Settings
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.Home
import androidx.compose.material.icons.rounded.MarkEmailUnread
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.Settings
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.provider.R
import com.dorr.provider.net.Realtime
import com.dorr.provider.net.Session
import com.dorr.provider.net.can
import androidx.compose.material.icons.outlined.AccountBalanceWallet
import com.dorr.provider.ui.kit.PinkBackdrop
import com.dorr.provider.ui.kit.settingsAccent

/**
 * What the signed-in frame shows: the tab that is open (kept while a page is open over the tabs, so the tab the page was
 * opened from stays lit) and whether a full-screen layer (the test payment page) has hidden the header and the bar.
 * [tab] is one of [TAB_HOME], [TAB_WALLET], [TAB_ACCOUNT] — not a position, since the wallet tab shows only with `wallet.view`.
 */
object ShellState {
    var tab by mutableIntStateOf(TAB_HOME)
    var immersive by mutableStateOf(false)
}

const val TAB_HOME = 0
const val TAB_WALLET = 1
const val TAB_ACCOUNT = 2

private val allTabs = listOf(
    TAB_HOME to BottomTab(R.string.tab_home, Icons.Outlined.Home, Icons.Rounded.Home),
    TAB_WALLET to BottomTab(R.string.wl_title, Icons.Outlined.AccountBalanceWallet, Icons.Rounded.AccountBalanceWallet),
    TAB_ACCOUNT to BottomTab(R.string.tab_account, Icons.Outlined.Settings, Icons.Rounded.Settings),
)

/** The tabs my seat may see (§17): the wallet only with `wallet.view` (the owner holds every permission). */
@Composable
fun visibleTabs(): List<Pair<Int, BottomTab>> {
    val seat by Session.membership.collectAsState()
    return allTabs.filter { (key, _) -> key != TAB_WALLET || seat.can("wallet.view") }
}

/**
 * The frame of every page once signed in: the home header on top (photo, name, wallet, bell) and the bottom bar under the
 * page. The bar steps aside while the keyboard is open so the fields and the save button keep their room; while it shows,
 * the page below it no longer pads for the phone's navigation bar (the bar already covers it).
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun AppShell(
    onSelectTab: (Int) -> Unit,
    onOpenWallet: () -> Unit,
    onOpenNotifications: () -> Unit,
    onOpenInvitations: () -> Unit = {},
    showHeader: Boolean = true,
    content: @Composable () -> Unit,
) {
    val chrome = !ShellState.immersive
    val showBar = chrome && !WindowInsets.isImeVisible
    val tabs = visibleTabs()
    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.matchParentSize())
        Column(Modifier.fillMaxSize()) {
            if (chrome && showHeader) AppHeader(onOpenWallet = onOpenWallet, onOpenNotifications = onOpenNotifications, onOpenInvitations = onOpenInvitations)
            Box(
                Modifier
                    .weight(1f)
                    .fillMaxWidth()
                    .then(if (showBar) Modifier.consumeWindowInsets(WindowInsets.navigationBars) else Modifier),
            ) { content() }
            if (showBar) {
                BottomBar(
                    tabs = tabs.map { it.second },
                    current = tabs.indexOfFirst { it.first == ShellState.tab }.coerceAtLeast(0),
                    onSelect = { index -> onSelectTab(tabs[index].first) },
                )
            }
        }
    }
}

/**
 * The DORR-app home header: the photo and name on the app colour (with the establishment I work in under it), then the invitations,
 * the wallet (with `wallet.view`) and the bell (with the unread dot).
 */
@Composable
fun AppHeader(onOpenWallet: () -> Unit, onOpenNotifications: () -> Unit, onOpenInvitations: () -> Unit = {}) {
    val me by Session.me.collectAsState()
    val seat by Session.membership.collectAsState()
    val unread by Realtime.unread.collectAsState()
    val accent = settingsAccent()
    val onAccent = Color.White
    val shape = RoundedCornerShape(bottomStart = 22.dp, bottomEnd = 22.dp)
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clip(shape)
            .background(Brush.linearGradient(listOf(accent, Color(accent.red * 0.78f, accent.green * 0.78f, accent.blue * 0.78f))))
            .padding(horizontal = 14.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        AvatarImage(url = me?.avatarUrl, size = 40.dp, onBrand = true)
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f).padding(end = 8.dp)) {
            Text(stringResource(R.string.home_welcome), color = onAccent.copy(alpha = 0.86f), fontSize = 11.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(
                me?.name?.takeIf { it.isNotBlank() } ?: stringResource(R.string.account_default_user),
                color = onAccent, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis,
            )
            seat?.establishment?.name?.takeIf { it.isNotBlank() }?.let {
                Text(it, color = onAccent.copy(alpha = 0.86f), fontSize = 11.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
        }
        // The invitations to join an establishment, next to the wallet.
        Box(Modifier.size(34.dp).clip(CircleShape).background(onAccent.copy(alpha = 0.18f)).clickable(onClick = onOpenInvitations), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.MarkEmailUnread, contentDescription = stringResource(R.string.ws_invitations_title), tint = onAccent, modifier = Modifier.size(17.dp))
        }
        Spacer(Modifier.width(8.dp))
        // The wallet, one tap from any page (as in the DORR app's header) — only for a seat that may see it.
        if (seat.can("wallet.view")) {
            Box(Modifier.size(34.dp).clip(CircleShape).background(onAccent.copy(alpha = 0.18f)).clickable(onClick = onOpenWallet), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.AccountBalanceWallet, contentDescription = stringResource(R.string.wl_title), tint = onAccent, modifier = Modifier.size(17.dp))
            }
            Spacer(Modifier.width(8.dp))
        }
        Box(Modifier.size(34.dp).clip(CircleShape).background(onAccent.copy(alpha = 0.18f)).clickable(onClick = onOpenNotifications), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.Notifications, contentDescription = stringResource(R.string.notifications_title), tint = onAccent, modifier = Modifier.size(18.dp))
            if (unread > 0) {
                Box(Modifier.align(Alignment.TopEnd).padding(5.dp).size(9.dp).background(onAccent, CircleShape).border(1.5.dp, accent, CircleShape))
            }
        }
    }
}
