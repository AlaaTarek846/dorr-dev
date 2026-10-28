package com.dorr.driver.ui

import androidx.compose.foundation.background
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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Logout
import androidx.compose.material.icons.rounded.AssignmentInd
import androidx.compose.material.icons.rounded.DirectionsBoat
import androidx.compose.material.icons.rounded.PersonSearch
import androidx.compose.material.icons.rounded.School
import androidx.compose.material.icons.rounded.DirectionsBus
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material.icons.rounded.Flight
import androidx.compose.material.icons.rounded.Gavel
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.MilitaryTech
import androidx.compose.material.icons.rounded.SupportAgent
import androidx.compose.material.icons.rounded.Settings
import androidx.compose.material.icons.rounded.Tune
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.BuildConfig
import com.dorr.driver.R
import com.dorr.driver.duty.Duty
import com.dorr.driver.live.DriverLive
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.DriverDto
import com.dorr.driver.net.EarningsDto
import com.dorr.driver.net.PresenceDto
import com.dorr.driver.ui.kit.Chip
import com.dorr.driver.ui.kit.ListRow
import com.dorr.driver.ui.kit.OutlineButton
import com.dorr.driver.ui.kit.PinkBackdrop
import com.dorr.driver.ui.kit.RowDivider
import com.dorr.driver.ui.kit.ScreenHeader
import com.dorr.driver.ui.kit.SectionLabel
import com.dorr.driver.ui.kit.Segmented
import com.dorr.driver.ui.kit.chooseLanguage
import com.dorr.driver.ui.kit.staggerIn
import com.dorr.driver.ui.kit.surface
import com.dorr.driver.ui.theme.DriverPrefs
import com.dorr.driver.ui.theme.Pal
import kotlinx.coroutines.launch

/**
 * The fourth tab: who I am (name, rating, account status), everything that is about how I drive,
 * how the app looks and speaks (dark / light, Arabic / English), and signing out.
 */
@Composable
fun AccountScreen(
    onOpenSignup: () -> Unit,
    onOpenPreferences: () -> Unit,
    onOpenScheduled: () -> Unit,
    onOpenAirport: () -> Unit,
    onOpenCases: () -> Unit,
    onOpenSafety: () -> Unit,
    onOpenNotifications: () -> Unit,
    onSignedOut: () -> Unit,
    onOpenBus: () -> Unit = {},
    onOpenMarine: () -> Unit = {},
    onOpenHire: () -> Unit = {},
    onOpenLessons: () -> Unit = {},
    onOpenWithMe: () -> Unit = {},
    onOpenRank: () -> Unit = {},
    onOpenSupport: () -> Unit = {},
    onOpenSettings: () -> Unit = {},
    onOpenDriverAi: () -> Unit = {},
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var driver by remember { mutableStateOf<DriverDto?>(null) }
    var earnings by remember { mutableStateOf<EarningsDto?>(null) }
    var presence by remember { mutableStateOf<PresenceDto?>(null) }
    var confirmLogout by remember { mutableStateOf(false) }
    val unread by DriverLive.unread.collectAsState()

    LaunchedEffect(Unit) {
        (apiCall { DriverBackend.api.me() } as? ApiResult.Ok)?.let { driver = it.data }
        (apiCall { DriverBackend.api.earnings() } as? ApiResult.Ok)?.let { earnings = it.data }
        (apiCall { DriverBackend.api.presence() } as? ApiResult.Ok)?.let { presence = it.data }
    }

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.matchParentSize())
        Column(Modifier.fillMaxSize()) {
            ScreenHeader(stringResource(R.string.acct_title), onBack = null, actions = { NotificationBell(onOpenNotifications) })
            Column(
                Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = 16.dp),
                verticalArrangement = Arrangement.spacedBy(14.dp),
            ) {
                ProfileCard(driver, earnings, Modifier.staggerIn(0))

                // What stops the captain from working is the first thing to fix, so it sits on top.
                if (presence != null && !presence!!.canGoOnline && presence!!.reason != null && presence!!.reason != "driving_limit") {
                    Column(Modifier.fillMaxWidth().surface().staggerIn(1)) {
                        ListRow(
                            Icons.Rounded.AssignmentInd, stringResource(R.string.home_cannot_work),
                            subtitle = driver?.statusReason ?: problemText(context, presence!!.reason),
                            tint = Pal.warning, onClick = onOpenSignup,
                        )
                    }
                }

                SectionLabel(stringResource(R.string.acct_driving))
                Column(Modifier.fillMaxWidth().surface().staggerIn(2)) {
                    ListRow(Icons.Rounded.AssignmentInd, stringResource(R.string.acct_signup), subtitle = stringResource(R.string.acct_signup_hint), onClick = onOpenSignup)
                    RowDivider()
                    // D-304..D-318: settings and the account.
                    ListRow(Icons.Rounded.Settings, stringResource(R.string.st_title), subtitle = stringResource(R.string.st_home_hint), onClick = onOpenSettings)
                    RowDivider()
                    // D-285..D-297: support, the help centre and lost items.
                    ListRow(Icons.Rounded.SupportAgent, stringResource(R.string.sp_title), subtitle = stringResource(R.string.sp_home_hint), tint = Pal.accent, onClick = onOpenSupport)
                    RowDivider()
                    // Block 06: DORR Driver AI — where the work is, clashes with my bookings, my daily goal.
                    ListRow(Icons.Rounded.AutoAwesome, stringResource(R.string.dai_title), subtitle = stringResource(R.string.dai_home_hint), tint = androidx.compose.ui.graphics.Color(0xFF6D5BFF), onClick = onOpenDriverAi)
                    RowDivider()
                    // D-239..D-259: my rank and performance.
                    ListRow(Icons.Rounded.MilitaryTech, stringResource(R.string.rk_title), subtitle = stringResource(R.string.rk_home_hint), tint = Pal.warning, onClick = onOpenRank)
                    RowDivider()
                    ListRow(Icons.Rounded.Tune, stringResource(R.string.pf_title), subtitle = stringResource(R.string.pf_home_hint), onClick = onOpenPreferences)
                    RowDivider()
                    ListRow(Icons.Rounded.Event, stringResource(R.string.sch_title), subtitle = stringResource(R.string.sch_home_hint), tint = Pal.orange, onClick = onOpenScheduled)
                    RowDivider()
                    // O-20, O-21: the captain's bus trips (a bus or minibus registered).
                    // O-30: «أنا معك» — riders on my way.
                    ListRow(Icons.Rounded.Groups, stringResource(R.string.wm_title), subtitle = stringResource(R.string.wm_home_hint), tint = Pal.success, onClick = onOpenWithMe)
                    RowDivider()
                    ListRow(Icons.Rounded.DirectionsBus, stringResource(R.string.bus_my_trips), subtitle = stringResource(R.string.bus_home_hint), onClick = onOpenBus)
                    RowDivider()
                    // O-26c: find a driver — drive a rider's own car, by the hour or the day.
                    ListRow(Icons.Rounded.PersonSearch, stringResource(R.string.hj_title), subtitle = stringResource(R.string.hj_home_hint), tint = androidx.compose.ui.graphics.Color(0xFF00897B), onClick = onOpenHire)
                    RowDivider()
                    // O-26b: driving lessons — I teach: lesson packs and the lessons asked for.
                    ListRow(Icons.Rounded.School, stringResource(R.string.tr_title), subtitle = stringResource(R.string.tr_home_hint), tint = androidx.compose.ui.graphics.Color(0xFF7B1FA2), onClick = onOpenLessons)
                    RowDivider()
                    // O-25: the captain's boat — sea trips and yacht hire (a boat or a yacht registered).
                    ListRow(Icons.Rounded.DirectionsBoat, stringResource(R.string.mr_title), subtitle = stringResource(R.string.mr_home_hint), tint = androidx.compose.ui.graphics.Color(0xFF0288D1), onClick = onOpenMarine)
                    RowDivider()
                    ListRow(Icons.Rounded.Flight, stringResource(R.string.air_title), subtitle = stringResource(R.string.air_home_hint), onClick = onOpenAirport)
                    RowDivider()
                    ListRow(Icons.Rounded.Gavel, stringResource(R.string.cs_title), subtitle = stringResource(R.string.cs_home_hint), tint = Pal.warning, onClick = onOpenCases)
                    RowDivider()
                    ListRow(Icons.Rounded.Shield, stringResource(R.string.sc_title), subtitle = stringResource(R.string.sc_home_hint), tint = Pal.danger, onClick = onOpenSafety)
                }

                SectionLabel(stringResource(R.string.acct_app))
                Column(Modifier.fillMaxWidth().surface().padding(14.dp).staggerIn(3), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text(stringResource(R.string.acct_appearance), color = Pal.ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                    Segmented(
                        listOf(
                            DriverPrefs.MODE_SYSTEM to stringResource(R.string.acct_mode_system),
                            DriverPrefs.MODE_LIGHT to stringResource(R.string.acct_mode_light),
                            DriverPrefs.MODE_DARK to stringResource(R.string.acct_mode_dark),
                        ),
                        selected = DriverPrefs.darkMode,
                        onSelect = DriverPrefs::chooseDarkMode,
                    )
                    Spacer(Modifier.height(4.dp))
                    Text(stringResource(R.string.acct_language), color = Pal.ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                    Segmented(
                        listOf(
                            DriverPrefs.LANG_SYSTEM to stringResource(R.string.acct_lang_system),
                            DriverPrefs.LANG_AR to stringResource(R.string.acct_lang_ar),
                            DriverPrefs.LANG_EN to stringResource(R.string.acct_lang_en),
                        ),
                        selected = DriverPrefs.language,
                        onSelect = { chooseLanguage(context, it) },
                    )
                }
                Column(Modifier.fillMaxWidth().surface().staggerIn(4)) {
                    ListRow(
                        Icons.Rounded.Notifications, stringResource(R.string.nt_title), subtitle = stringResource(R.string.acct_notifications_hint),
                        badge = if (unread > 0) (if (unread > 99) "99+" else unread.toString()) else null, onClick = onOpenNotifications,
                    )
                }

                OutlineButton(stringResource(R.string.home_logout), color = Pal.danger, modifier = Modifier.fillMaxWidth().staggerIn(5)) { confirmLogout = true }
                Text(
                    stringResource(R.string.acct_version, BuildConfig.VERSION_NAME), color = Pal.faint, fontSize = 11.sp,
                    modifier = Modifier.align(Alignment.CenterHorizontally).padding(bottom = 16.dp),
                )
            }
        }
    }

    if (confirmLogout) {
        AlertDialog(
            onDismissRequest = { confirmLogout = false },
            containerColor = Pal.card,
            icon = { Icon(Icons.AutoMirrored.Rounded.Logout, null, tint = Pal.danger) },
            title = { Text(stringResource(R.string.acct_logout_title), color = Pal.ink, fontWeight = FontWeight.Bold) },
            text = { Text(stringResource(R.string.acct_logout_message), color = Pal.mut) },
            confirmButton = {
                TextButton(onClick = {
                    confirmLogout = false
                    scope.launch {
                        Duty.stop(context)
                        apiCall { DriverBackend.api.goOffline() }
                        com.dorr.driver.live.DriverPush.signedOut()
                        apiCall { DriverBackend.api.logout() }
                        DriverLive.stop()
                        DriverBackend.session.clear()
                        onSignedOut()
                    }
                }) { Text(stringResource(R.string.home_logout), color = Pal.danger, fontWeight = FontWeight.Bold) }
            },
            dismissButton = { TextButton(onClick = { confirmLogout = false }) { Text(stringResource(R.string.cancel), color = Pal.mut) } },
        )
    }
}

@Composable
private fun ProfileCard(driver: DriverDto?, earnings: EarningsDto?, modifier: Modifier = Modifier) {
    val stats = earnings?.profile
    Column(modifier.fillMaxWidth().surface(RoundedCornerShape(24.dp), elevation = 8.dp).padding(18.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(14.dp)) {
            Box(Modifier.size(64.dp).clip(CircleShape).background(Pal.well), contentAlignment = Alignment.Center) {
                if (driver?.avatarUrl != null) {
                    AsyncImage(driver.avatarUrl, null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                } else {
                    Text(
                        (driver?.name ?: driver?.phone ?: "?").trim().take(1).uppercase(),
                        color = Pal.accent, fontSize = 26.sp, fontWeight = FontWeight.ExtraBold,
                    )
                }
            }
            Column(Modifier.weight(1f)) {
                Text(driver?.name ?: driver?.phone ?: "…", color = Pal.ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
                // The number reads left to right in Arabic too.
                if (driver?.name != null) Text(driver.phone, color = Pal.mut, fontSize = 13.sp, modifier = Modifier.padding(top = 2.dp))
            }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
            val status = statusOf(driver?.status)
            if (status != null) Chip(stringResource(status.first), selected = false, tint = status.second)
            stats?.rating?.let { Chip(String.format("★ %.1f", it), tint = Pal.warning) }
            if ((stats?.tripsCount ?: 0) > 0) Chip(stringResource(R.string.acct_trips_total) + " " + stats!!.tripsCount, tint = Pal.accent)
        }
    }
}

/** The account state as a label and a colour (the words stay the ones the sign-up screen already uses). */
private fun statusOf(status: String?): Pair<Int, androidx.compose.ui.graphics.Color>? = when (status) {
    "active" -> R.string.status_active to Pal.success
    "pending_documents" -> R.string.status_pending_documents to Pal.warning
    "under_review" -> R.string.status_under_review to Pal.warning
    "suspended" -> R.string.status_suspended to Pal.danger
    "restricted" -> R.string.status_restricted to Pal.danger
    "blocked" -> R.string.status_blocked to Pal.danger
    else -> null
}
