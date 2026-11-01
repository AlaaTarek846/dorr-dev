package com.dorr.freelance.ui.screens

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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.Badge
import androidx.compose.material.icons.rounded.Bolt
import androidx.compose.material.icons.rounded.Build
import androidx.compose.material.icons.rounded.Category
import androidx.compose.material.icons.rounded.EventBusy
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.School
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material.icons.rounded.Work
import androidx.compose.material.icons.rounded.Storefront
import androidx.compose.material.icons.rounded.Inbox
import androidx.compose.material.icons.rounded.Quiz
import androidx.compose.material.icons.rounded.Speed
import androidx.compose.material.icons.rounded.Insights
import androidx.compose.material.icons.rounded.WorkspacePremium
import androidx.compose.material.icons.rounded.VideoChat
import androidx.compose.material.icons.rounded.EventAvailable
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material3.Icon
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.freelance.R
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.Session
import com.dorr.freelance.ui.components.AvatarImage
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.LanguageSwitch
import com.dorr.freelance.ui.kit.AccountDark
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsCard
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsNight
import com.dorr.freelance.ui.kit.settingsSurface

/**
 * The Home tab, in the look of the DORR app home (under the shared header of [com.dorr.freelance.ui.components.AppShell]: cards, a grid of
 * shortcuts) but with what a freelancer needs: how complete the profile is, where verification stands, the shortcuts to
 * the profile lists and what is still missing.
 */
@Composable
fun HomeScreen(actions: MainActions, failed: String?, onOpenPortfolio: () -> Unit, onOpenProfileTab: () -> Unit) {
    val me by Session.me.collectAsState()
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState())) {
        Spacer(Modifier.height(14.dp))
        Column(Modifier.padding(horizontal = 20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            val profile = me
            if (profile != null) {
                // FL-708: shown only while a warning / restriction is active or its appeal waits.
                SanctionsCard()
                if (profile.availability?.canReceiveOffers == false) PausedCard(onOpen = actions.openAvailability)
                WaitingOrdersBanner(actions.openServiceOrders)
                // FL-L07: the next consultation, when one is booked.
                NextConsultationBanner(actions.openConsultations)
                // FL-L11: how complete the profile is and the next steps worth taking (the server ranks them).
                CoachCard(
                    fallbackPercent = profile.completeness?.percent ?: 0, onAll = actions.openCoach,
                    onOpen = { action ->
                        when (action) {
                            "skills" -> actions.openSkills()
                            "specialties" -> actions.openSpecialties()
                            "portfolio" -> onOpenPortfolio()
                            "verification" -> actions.openVerification()
                            "services" -> actions.openServices()
                            else -> actions.openProfile()
                        }
                    },
                )
                VerificationCard(profile.status, profile.statusReason, onOpen = actions.openVerification)

                Text(stringResource(R.string.home_quick_title), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    QuickCard(Icons.Rounded.Category, stringResource(R.string.home_open_specialties), Modifier.weight(1f), actions.openSpecialties)
                    QuickCard(Icons.Rounded.Bolt, stringResource(R.string.home_open_skills), Modifier.weight(1f), actions.openSkills)
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    QuickCard(Icons.Rounded.Build, stringResource(R.string.home_open_experiences), Modifier.weight(1f), actions.openExperiences)
                    QuickCard(Icons.Rounded.School, stringResource(R.string.home_open_certificates), Modifier.weight(1f), actions.openCertificates)
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    QuickCard(Icons.Rounded.Work, stringResource(R.string.home_open_portfolio), Modifier.weight(1f), onOpenPortfolio)
                    QuickCard(Icons.Rounded.AccountBalanceWallet, stringResource(R.string.wl_title), Modifier.weight(1f), actions.openWallet)
                }
                // FL-L04: my ready-made services and the orders on them.
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    QuickCard(Icons.Rounded.Storefront, stringResource(R.string.svc_home_card), Modifier.weight(1f), actions.openServices)
                    QuickCard(Icons.Rounded.Inbox, stringResource(R.string.svc_orders_home_card), Modifier.weight(1f), actions.openServiceOrders)
                }
                // FL-L01 phase 4: my numbers, and my plan beside them.
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    QuickCard(Icons.Rounded.Insights, stringResource(R.string.insights_home_card), Modifier.weight(1f), actions.openInsights)
                    QuickCard(Icons.Rounded.WorkspacePremium, stringResource(R.string.plans_title), Modifier.weight(1f), actions.openPlans)
                }
                // FL-L08: prove a skill with a test and carry its badge.
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    QuickCard(Icons.Rounded.Quiz, stringResource(R.string.skt_home_card), Modifier.weight(1f), actions.openSkillTests)
                    // FL-L10: how many projects I take at once, and who waits.
                    QuickCard(Icons.Rounded.Speed, stringResource(R.string.cap_home_card), Modifier.weight(1f), actions.openCapacity)
                }
                // FL-L07: paid consultations, and the hours I keep for them.
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    QuickCard(Icons.Rounded.VideoChat, stringResource(R.string.cns_home_card), Modifier.weight(1f), actions.openConsultations)
                    QuickCard(Icons.Rounded.EventAvailable, stringResource(R.string.availability_title), Modifier.weight(1f), actions.openAvailability)
                }

            }
            ErrorBanner(failed)
            Spacer(Modifier.height(20.dp))
        }
    }
}

@Composable
private fun VerificationCard(status: String?, reason: String?, onOpen: () -> Unit) {
    val verified = status == "verified"
    Row(
        Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).clickable(onClick = onOpen).padding(16.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Icon(if (verified) Icons.Rounded.Verified else Icons.Rounded.Badge, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(28.dp))
        Column(Modifier.weight(1f)) {
            Text(stringResource(R.string.home_open_verification), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
            Text(statusLabel(status), color = settingsMut(), fontSize = 12.sp)
            // Why the account was put on hold, as staff wrote it.
            if (status == "suspended") reason?.takeIf { it.isNotBlank() }?.let { Text(stringResource(R.string.status_reason_line, it), color = settingsMut(), fontSize = 12.sp) }
        }
    }
}

@Composable
private fun QuickCard(icon: ImageVector, label: String, modifier: Modifier, onClick: () -> Unit) {
    val night = settingsNight()
    val shape = RoundedCornerShape(14.dp)
    Column(
        modifier = modifier
            .then(if (night) Modifier else Modifier.shadow(6.dp, shape, spotColor = settingsAccent().copy(alpha = 0.07f)))
            .clip(shape)
            .background(settingsCard())
            .then(if (night) Modifier.border(1.dp, AccountDark.line, shape) else Modifier)
            .clickable(onClick = onClick)
            .padding(12.dp)
            .heightIn(min = 84.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        Box(
            Modifier.size(34.dp).clip(RoundedCornerShape(10.dp)).background(if (night) AccountDark.well else settingsAccent().copy(alpha = 0.14f)),
            contentAlignment = Alignment.Center,
        ) { Icon(icon, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(18.dp)) }
        Spacer(Modifier.height(8.dp))
        Text(label, fontSize = 13.sp, fontWeight = FontWeight.Bold, color = settingsInk())
    }
}

/** The account status in the app language; an unknown one is shown as the server sent it. */
@Composable
internal fun statusLabel(status: String?): String = when (status) {
    "registered" -> stringResource(R.string.status_registered)
    "profile_ready" -> stringResource(R.string.status_profile_ready)
    "verification_submitted" -> stringResource(R.string.status_verification_submitted)
    "verified" -> stringResource(R.string.status_verified)
    "verification_rejected" -> stringResource(R.string.status_verification_rejected)
    "changes_requested" -> stringResource(R.string.status_changes_requested)
    "suspended" -> stringResource(R.string.status_suspended)
    "blocked" -> stringResource(R.string.status_blocked)
    else -> status.orEmpty()
}

/** A short notice on the home while the freelancer is not taking new offers (paused or on time off); a tap opens the availability page. */
@Composable
private fun PausedCard(onOpen: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).clickable(onClick = onOpen).padding(16.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Icon(Icons.Rounded.EventBusy, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(22.dp))
        Text(stringResource(R.string.availability_paused_banner), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
    }
}

/**
 * FL-L04: the orders waiting for my answer, on top of the home — shown only when there are some. Read when the home opens, when the
 * connection returns and when a notice arrives (a new order is one), never on a timer.
 */
@Composable
private fun NextConsultationBanner(onOpen: () -> Unit) {
    val reconnect = com.dorr.freelance.net.collectReconnectTick()
    var next by androidx.compose.runtime.remember { androidx.compose.runtime.mutableStateOf<com.dorr.freelance.net.ConsultationDto?>(null) }
    suspend fun load() {
        val r = com.dorr.core.network.apiCall { com.dorr.freelance.net.ApiClient.consultations.list(tab = "upcoming", perPage = 10) }
        // A request waiting for my answer comes first.
        if (r is com.dorr.core.network.ApiResult.Ok) next = r.data.firstOrNull { it.phase == "pending" } ?: r.data.firstOrNull()
    }
    androidx.compose.runtime.LaunchedEffect(reconnect) { load() }
    androidx.compose.runtime.LaunchedEffect(Unit) { com.dorr.freelance.net.Realtime.changed.collect { load() } }
    next?.let { c ->
        val at = runCatching { java.time.OffsetDateTime.parse(c.startsAt).atZoneSameInstant(java.time.ZoneId.systemDefault()) }.getOrNull()
        val time = at?.let { java.time.format.DateTimeFormatter.ofPattern("HH:mm", java.util.Locale.US).format(it) }.orEmpty()
        val day = at?.let { java.time.format.DateTimeFormatter.ofPattern("EEEE d MMM", com.dorr.freelance.net.AppLocale.java()).format(it) }.orEmpty()
        Row(
            Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).clickable(onClick = onOpen).padding(14.dp),
            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Box(Modifier.size(42.dp).clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.VideoChat, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(22.dp))
            }
            Column(Modifier.weight(1f)) {
                Text(stringResource(if (c.phase == "pending") R.string.cns_pending_home else R.string.cns_next_banner, c.client?.name.orEmpty()), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
                Text("$day · \u200E$time", color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
            }
            Icon(Icons.Rounded.ChevronRight, contentDescription = null, tint = settingsMut(), modifier = Modifier.size(20.dp))
        }
    }
}

@Composable
private fun WaitingOrdersBanner(onOpen: () -> Unit) {
    val reconnect = com.dorr.freelance.net.collectReconnectTick()
    var waiting by androidx.compose.runtime.remember { androidx.compose.runtime.mutableStateOf(0) }
    suspend fun load() {
        val r = com.dorr.core.network.apiCall { com.dorr.freelance.net.ApiClient.services.orders(status = "awaiting", perPage = 50) }
        if (r is com.dorr.core.network.ApiResult.Ok) waiting = r.data.size
    }
    androidx.compose.runtime.LaunchedEffect(reconnect) { load() }
    androidx.compose.runtime.LaunchedEffect(Unit) { com.dorr.freelance.net.Realtime.changed.collect { load() } }
    if (waiting > 0) OrdersBanner(waiting, onOpen)
}
