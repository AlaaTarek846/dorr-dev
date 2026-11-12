package com.dorr.freelance.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
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
import androidx.compose.material.icons.outlined.Explore
import androidx.compose.material.icons.outlined.Forum
import androidx.compose.material.icons.outlined.Home
import androidx.compose.material.icons.outlined.Person
import androidx.compose.material.icons.outlined.WorkOutline
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Explore
import androidx.compose.material.icons.rounded.Forum
import androidx.compose.material.icons.rounded.Home
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Work
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.freelance.R
import com.dorr.freelance.net.CommunityHub
import com.dorr.freelance.net.HomeHub
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.Session
import com.dorr.freelance.ui.kit.PinkBackdrop
import com.dorr.freelance.ui.kit.settingsAccent

/** The tabs of the bottom bar, by name (the community's comes and goes with its country switch, so a number would move). */
object Tabs {
    const val HOME = "home"
    const val OPPORTUNITIES = "opportunities"
    const val WORK = "work"
    const val COMMUNITY = "community"
    const val ME = "me"
}

/**
 * What the signed-in frame shows: the tab that is open (kept while a page is open over the tabs, so the tab the page was opened from
 * stays lit), whether a full-screen layer (the test payment page) has hidden the header and the bar, and the part of a tab a shortcut
 * asked to open (taken once by that tab).
 */
object ShellState {
    var tab by mutableStateOf(Tabs.HOME)
    var immersive by mutableStateOf(false)
    /** Asked from a page over the tabs: open the «Me» tab straight on «My profile». The tab takes it once. */
    var openProfileHub by mutableStateOf(false)
    /** A part of «Opportunities» (`projects` / `invitations`) or «My work» (`projects` / `offers` / `orders` / `consultations`) to open. */
    var segment by mutableStateOf<String?>(null)

    fun open(tab: String, segment: String? = null) {
        this.segment = segment
        this.tab = tab
    }
}

/** The tabs shown now: the community's only while it is on in my country. */
@Composable
fun shellTabs(): List<BottomTab> {
    val home by HomeHub.home.collectAsState()
    val community by CommunityHub.available.collectAsState()
    val attention = home?.attention
    val withCommunity = home?.on("community") == true || community == true
    return buildList {
        add(BottomTab(Tabs.HOME, R.string.tab_home, Icons.Outlined.Home, Icons.Rounded.Home))
        add(BottomTab(Tabs.OPPORTUNITIES, R.string.tab_opportunities, Icons.Outlined.Explore, Icons.Rounded.Explore, badge = attention?.invitationsPending ?: 0))
        add(BottomTab(Tabs.WORK, R.string.tab_work, Icons.Outlined.WorkOutline, Icons.Rounded.Work, badge = attention?.work ?: 0))
        if (withCommunity) add(BottomTab(Tabs.COMMUNITY, R.string.tab_community, Icons.Outlined.Forum, Icons.Rounded.Forum))
        add(BottomTab(Tabs.ME, R.string.tab_me, Icons.Outlined.Person, Icons.Rounded.Person))
    }
}

/**
 * The frame of every page once signed in: the header on top (photo, greeting, wallet, bell) and the bottom bar under the page. The bar
 * steps aside while the keyboard is open so the fields and the save button keep their room; while it shows, the page below it no longer
 * pads for the phone's navigation bar (the bar already covers it).
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun AppShell(
    onSelectTab: (String) -> Unit,
    onOpenWallet: () -> Unit,
    onOpenNotifications: () -> Unit,
    showHeader: Boolean = true,
    content: @Composable () -> Unit,
) {
    val chrome = !ShellState.immersive
    val showBar = chrome && !WindowInsets.isImeVisible
    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.matchParentSize())
        Column(Modifier.fillMaxSize()) {
            if (chrome && showHeader) AppHeader(onOpenWallet = onOpenWallet, onOpenNotifications = onOpenNotifications, onOpenMe = { onSelectTab(Tabs.ME) })
            Box(
                Modifier
                    .weight(1f)
                    .fillMaxWidth()
                    .then(if (showBar) Modifier.consumeWindowInsets(WindowInsets.navigationBars) else Modifier),
            ) { content() }
            if (showBar) BottomBar(tabs = shellTabs(), current = ShellState.tab, onSelect = onSelectTab)
        }
    }
}

/**
 * The header of the tabs: the photo (a tap opens «Me»), a greeting for the time of day and the name with the verified tick, then the wallet
 * and the bell with the number of unread notices.
 */
@Composable
fun AppHeader(onOpenWallet: () -> Unit, onOpenNotifications: () -> Unit, onOpenMe: () -> Unit = {}) {
    val me by Session.me.collectAsState()
    val unread by Realtime.unread.collectAsState()
    val accent = settingsAccent()
    val onAccent = Color.White
    val shape = RoundedCornerShape(bottomStart = 24.dp, bottomEnd = 24.dp)
    val hour = java.time.LocalTime.now().hour
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clip(shape)
            .background(Brush.linearGradient(listOf(accent, Color(accent.red * 0.74f, accent.green * 0.74f, accent.blue * 0.74f))))
            .padding(horizontal = 16.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.clip(CircleShape).border(2.dp, onAccent.copy(alpha = 0.55f), CircleShape).clickable(onClick = onOpenMe).padding(2.dp)) {
            AvatarImage(url = me?.avatarUrl, size = 40.dp, onBrand = true)
        }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f).padding(end = 8.dp)) {
            Text(
                stringResource(if (hour < 12) R.string.home_greet_morning else R.string.home_greet_evening),
                color = onAccent.copy(alpha = 0.86f), fontSize = 11.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis,
            )
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    me?.name?.takeIf { it.isNotBlank() } ?: stringResource(R.string.account_default_user),
                    color = onAccent, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.weight(1f, fill = false),
                )
                if (me?.status == "verified") {
                    Spacer(Modifier.width(5.dp))
                    Box(Modifier.size(15.dp).clip(CircleShape).background(onAccent), contentAlignment = Alignment.Center) {
                        Icon(Icons.Rounded.Check, contentDescription = stringResource(R.string.account_verified), tint = accent, modifier = Modifier.size(10.dp))
                    }
                }
            }
        }
        HeaderButton(Icons.Rounded.AccountBalanceWallet, stringResource(R.string.wl_title), onClick = onOpenWallet)
        Spacer(Modifier.width(8.dp))
        HeaderButton(Icons.Rounded.Notifications, stringResource(R.string.notifications_title), count = unread, onClick = onOpenNotifications)
    }
}

/** A round button on the header's colour, with a small count in its corner when there is one. */
@Composable
private fun HeaderButton(icon: ImageVector, description: String, count: Int = 0, onClick: () -> Unit) {
    val accent = settingsAccent()
    Box(Modifier.size(40.dp), contentAlignment = Alignment.Center) {
        Box(Modifier.size(38.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.18f)).clickable(onClick = onClick), contentAlignment = Alignment.Center) {
            Icon(icon, contentDescription = description, tint = Color.White, modifier = Modifier.size(19.dp))
        }
        if (count > 0) CountBadge(count, background = Color.White, ink = accent, edge = accent, modifier = Modifier.align(Alignment.TopEnd))
    }
}

/** A small round count (9+ above nine). */
@Composable
fun CountBadge(count: Int, background: Color, ink: Color, edge: Color? = null, modifier: Modifier = Modifier) {
    Box(
        modifier
            .size(if (count > 9) 20.dp else 17.dp)
            .clip(CircleShape)
            .background(background)
            .then(if (edge != null) Modifier.border(1.5.dp, edge, CircleShape) else Modifier),
        contentAlignment = Alignment.Center,
    ) { Text(if (count > 9) "9+" else count.toString(), color = ink, fontSize = 9.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1) }
}
