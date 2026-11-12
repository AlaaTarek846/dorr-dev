package com.dorr.freelance.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.AddAlert
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.CloudOff
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Contacts
import androidx.compose.material.icons.rounded.DeleteOutline
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.EditNote
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material.icons.rounded.EventBusy
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.Label
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Mail
import androidx.compose.material.icons.rounded.NotificationsActive
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.Save
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.SearchOff
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.StarBorder
import androidx.compose.material.icons.rounded.StickyNote2
import androidx.compose.material.icons.rounded.Work
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TimePicker
import androidx.compose.material3.TimePickerDefaults
import androidx.compose.material3.rememberTimePickerState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.AppLocale
import com.dorr.freelance.net.CrmBody
import com.dorr.freelance.net.CrmClientDto
import com.dorr.freelance.net.CrmClientPageDto
import com.dorr.freelance.net.CrmHistoryDto
import com.dorr.freelance.net.CrmLeadDto
import com.dorr.freelance.net.CrmLockedDto
import com.dorr.freelance.net.CrmNoteDto
import com.dorr.freelance.net.CrmNoteRequest
import com.dorr.freelance.net.CrmOverviewDto
import com.dorr.freelance.net.CrmReminderDoneRequest
import com.dorr.freelance.net.CrmReminderDto
import com.dorr.freelance.net.CrmReminderRequest
import com.dorr.freelance.net.CrmUpgradeDto
import com.dorr.freelance.net.PagedRows
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.crmLocked
import com.dorr.freelance.net.errorText
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import com.dorr.freelance.ui.components.DateField
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.EmptyState
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.Page
import com.dorr.freelance.ui.components.PagedFooter
import com.dorr.freelance.ui.components.SkeletonDetail
import com.dorr.freelance.ui.components.SkeletonList
import com.dorr.freelance.ui.kit.AccountDark
import com.dorr.freelance.ui.kit.AlertDialog
import com.dorr.freelance.ui.kit.ConfirmDialog
import com.dorr.freelance.ui.kit.Corners
import com.dorr.freelance.ui.kit.DialogButton
import com.dorr.freelance.ui.kit.FilterChips
import com.dorr.freelance.ui.kit.FilterOption
import com.dorr.freelance.ui.kit.HeroNumber
import com.dorr.freelance.ui.kit.PillChip
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.StoreHero
import com.dorr.freelance.ui.kit.Tints
import com.dorr.freelance.ui.kit.appCard
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsNight
import com.dorr.freelance.ui.kit.settingsSurface
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.time.Instant
import java.time.LocalDate
import java.time.LocalTime
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import java.util.Locale

/*
 * FS-703: «My clients», the freelancer's private CRM. Two pages: the list (a head with the numbers, the follow-ups due, a search, five tabs with
 * their counts, a card per client with the star) and one client (the numbers, the label, notes, follow-up reminders, the work we did together and
 * what is still waiting). What a plan opens is the server's (`level`: 0 none, 1 clients + star + notes, 2 + labels and reminders): a plan with none
 * sees what the page is and the plan that opens it, level 1 sees the rest locked with the plan's name. Read when a page opens, when the
 * connection returns and when a notice arrives (a follow-up falling due is a push that opens the client) — never on a timer.
 */

private val CRM_TABS = listOf("all", "active", "past", "leads", "starred")
private val CrmGreen = Color(0xFF16A34A)
private val CrmRed = Color(0xFFDC2626)
private val CrmAmber = Color(0xFFD97706)

// ---------------------------------------------------------------- the list

/**
 * The list: the numbers on top, the follow-ups due (level 2, overdue in red), a name search (read 400 ms after the last key), the tabs with
 * their counts and a card per client. Pages fill in while scrolling. A star is changed on the spot and put back if the server refuses.
 */
@Composable
fun CrmScreen(onBack: () -> Unit, onOpenClient: (String) -> Unit, onOpenPlans: () -> Unit) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val haptic = LocalHapticFeedback.current
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var tab by rememberSaveable { mutableStateOf("all") }
    var typed by rememberSaveable { mutableStateOf("") }
    var query by rememberSaveable { mutableStateOf("") }
    var frame by remember { mutableStateOf<CrmOverviewDto?>(null) }
    var toast by remember { mutableStateOf<String?>(null) }

    // The search is read once the typing pauses, not on every letter.
    LaunchedEffect(typed) {
        val wanted = typed.trim()
        if (wanted != query) {
            delay(400)
            query = wanted
        }
    }

    val paged = remember(tab, query) {
        PagedRows<CrmClientDto>(20, { it.uuid }, { page ->
            when (val r = apiCall { ApiClient.crm.clients(tab, query.ifEmpty { null }, page, 20) }) {
                is ApiResult.Ok -> {
                    frame = r.data
                    ApiResult.Ok(r.data.clients, r.message)
                }
                is ApiResult.Failed -> r
                is ApiResult.Offline -> r
            }
        })
    }
    LaunchedEffect(tab, query, reconnect) { paged.reload() }
    LaunchedEffect(tab, query) { Realtime.changed.collect { paged.reload() } }

    fun toggleStar(c: CrmClientDto) {
        val next = !c.starred
        haptic.performHapticFeedback(HapticFeedbackType.TextHandleMove)
        paged.update({ it.uuid == c.uuid }) { it.copy(starred = next) }
        scope.launch {
            toast = null
            when (val r = apiCall { ApiClient.crm.update(c.uuid, CrmBody.star(next)) }) {
                is ApiResult.Ok -> {
                    frame = frame?.let { f -> f.copy(counts = f.counts.copy(starred = (f.counts.starred + if (next) 1 else -1).coerceAtLeast(0))) }
                    if (tab == "starred" && !next) paged.rows.removeAll { it.uuid == c.uuid }
                }
                else -> {
                    paged.update({ it.uuid == c.uuid }) { it.copy(starred = !next) }
                    toast = r.errorText(offlineText) ?: genericError
                    if ((r as? ApiResult.Failed)?.error?.crmLocked() != null) paged.reload()
                }
            }
        }
    }

    Page(title = stringResource(R.string.crm_title), onBack = onBack, onNearEnd = { scope.launch { paged.loadMore() } }) {
        val f = frame
        val failure = paged.failure
        when {
            f == null && failure != null -> CrmFailure(failure.errorText(offlineText) ?: genericError) { scope.launch { paged.retry(); paged.reload() } }
            f == null -> SkeletonList()
            f.locked -> CrmLocked(f.upgrade, onOpenPlans)
            else -> {
                val counts = f.counts
                StoreHero(
                    icon = Icons.Rounded.Contacts,
                    title = stringResource(R.string.crm_title),
                    body = stringResource(R.string.crm_hero_body),
                    numbers = listOf(
                        HeroNumber(counts.all.toString(), stringResource(R.string.crm_hero_total)),
                        HeroNumber(counts.active.toString(), stringResource(R.string.crm_hero_active)),
                        HeroNumber(counts.past.toString(), stringResource(R.string.crm_hero_past)),
                        HeroNumber(counts.leads.toString(), stringResource(R.string.crm_hero_leads)),
                    ),
                )
                if (f.level >= 2 && f.due.isNotEmpty()) CrmDueCard(f.due, onOpenClient)

                DorrTextField(
                    value = typed, onValueChange = { typed = it.take(100) }, modifier = Modifier.fillMaxWidth().widthIn(max = 520.dp),
                    placeholder = stringResource(R.string.crm_search_hint), icon = Icons.Rounded.Search,
                    trailing = if (typed.isNotEmpty()) {
                        { Icon(Icons.Rounded.Close, stringResource(R.string.crm_search_clear), tint = settingsMut(), modifier = Modifier.clickable { typed = "" }) }
                    } else null,
                )
                FilterChips(
                    CRM_TABS.map { FilterOption(it, stringResource(R.string.crm_tab_count, crmTabName(it), crmTabCount(it, counts.all, counts.active, counts.past, counts.leads, counts.starred))) },
                    tab, { if (it != tab) { haptic.performHapticFeedback(HapticFeedbackType.TextHandleMove); tab = it } },
                )

                when {
                    !paged.loaded && paged.failure == null -> SkeletonList()
                    paged.loaded && paged.rows.isEmpty() && paged.failure == null -> CrmEmpty(tab, query.isNotEmpty())
                    else -> paged.rows.forEach { c -> key(c.uuid) { CrmClientCard(c, f, onClick = { onOpenClient(c.uuid) }, onStar = { toggleStar(c) }) } }
                }
                PagedFooter(paged) { scope.launch { paged.loadMore() } }

                // The basic level sees what the next plan adds.
                val next = f.upgrade
                if (f.level < 2 && next != null) {
                    InfoCard(title = stringResource(R.string.crm_more_title), icon = Icons.Rounded.NotificationsActive) {
                        Text(stringResource(R.string.crm_more_body, next.name ?: next.slug), color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp)
                        SettingsPrimaryButton(text = stringResource(R.string.crm_unlock, next.name ?: next.slug), onClick = onOpenPlans)
                    }
                }
            }
        }
        ErrorBanner(toast ?: if (frame != null) pagedFailureText(paged, offlineText, genericError) else null, Modifier.fillMaxWidth())
    }
}

/** The text of a failed page once the list is already on screen (the first read's failure has its own state). */
private fun pagedFailureText(paged: PagedRows<*>, offline: String, generic: String): String? = paged.failure?.let { it.errorText(offline) ?: generic }

@Composable
private fun crmTabName(tab: String): String = stringResource(
    when (tab) {
        "active" -> R.string.crm_tab_active
        "past" -> R.string.crm_tab_past
        "leads" -> R.string.crm_tab_leads
        "starred" -> R.string.crm_tab_starred
        else -> R.string.crm_tab_all
    },
)

private fun crmTabCount(tab: String, all: Int, active: Int, past: Int, leads: Int, starred: Int): Int = when (tab) {
    "active" -> active
    "past" -> past
    "leads" -> leads
    "starred" -> starred
    else -> all
}

@Composable
private fun CrmEmpty(tab: String, searching: Boolean) {
    val (icon, title, body) = when {
        searching -> Triple(Icons.Rounded.SearchOff, R.string.crm_empty_search_title, R.string.crm_empty_search_body)
        tab == "active" -> Triple(Icons.Rounded.Work, R.string.crm_empty_active_title, R.string.crm_empty_active_body)
        tab == "past" -> Triple(Icons.Rounded.History, R.string.crm_empty_past_title, R.string.crm_empty_past_body)
        tab == "leads" -> Triple(Icons.Rounded.HourglassTop, R.string.crm_empty_leads_title, R.string.crm_empty_leads_body)
        tab == "starred" -> Triple(Icons.Rounded.StarBorder, R.string.crm_empty_starred_title, R.string.crm_empty_starred_body)
        else -> Triple(Icons.Rounded.Contacts, R.string.crm_empty_all_title, R.string.crm_empty_all_body)
    }
    EmptyState(icon, stringResource(title), stringResource(body), Modifier.padding(top = 24.dp))
}

/** A failed first read: the reason and a way to try again (offline included). */
@Composable
private fun CrmFailure(text: String, onRetry: () -> Unit) {
    Column(Modifier.fillMaxWidth().widthIn(max = 520.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(16.dp)) {
        EmptyState(Icons.Rounded.CloudOff, stringResource(R.string.crm_title), text, Modifier.padding(top = 50.dp))
        SettingsPrimaryButton(text = stringResource(R.string.common_retry), onClick = onRetry)
    }
}

/** A plan without the CRM: what it is, and the plan that opens it (or that plans are not sold here). */
@Composable
private fun CrmLocked(upgrade: CrmUpgradeDto?, onOpenPlans: () -> Unit) {
    InfoCard {
        Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Box(Modifier.size(64.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Lock, null, tint = settingsAccent(), modifier = Modifier.size(30.dp))
            }
            Text(stringResource(R.string.crm_locked_title), color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center)
            Text(stringResource(R.string.crm_locked_body), color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp, textAlign = TextAlign.Center)
        }
        Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf(R.string.crm_locked_b1, R.string.crm_locked_b2, R.string.crm_locked_b3, R.string.crm_locked_b4).forEach { CrmPerk(stringResource(it)) }
        }
        if (upgrade != null) {
            SettingsPrimaryButton(text = stringResource(R.string.crm_unlock, upgrade.name ?: upgrade.slug), onClick = onOpenPlans)
        } else {
            Text(stringResource(R.string.crm_no_plans), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth())
        }
    }
}

@Composable
private fun CrmPerk(text: String) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        Icon(Icons.Rounded.CheckCircle, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
        Text(text, color = settingsInk(), fontSize = 13.sp)
    }
}

/** The follow-ups that wait (the soonest first): the overdue ones in red. Four show; the rest open on a tap. */
@Composable
private fun CrmDueCard(due: List<CrmReminderDto>, onOpenClient: (String) -> Unit) {
    var all by rememberSaveable { mutableStateOf(false) }
    val shown = if (all) due else due.take(4)
    InfoCard(title = stringResource(R.string.crm_due_title), icon = Icons.Rounded.NotificationsActive) {
        Text(stringResource(R.string.crm_due_sub), color = settingsMut(), fontSize = 12.sp)
        shown.forEach { r ->
            val overdue = crmOverdue(r)
            val tone = if (overdue) CrmRed else settingsAccent()
            val client = r.client
            Row(
                Modifier.fillMaxWidth().clip(Corners.small).then(if (client?.uuid != null) Modifier.clickable(role = Role.Button) { onOpenClient(client.uuid) } else Modifier).padding(vertical = 4.dp),
                verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                Box(Modifier.size(36.dp).clip(CircleShape).background(tone.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                    Icon(if (overdue) Icons.Rounded.EventBusy else Icons.Rounded.Event, null, tint = tone, modifier = Modifier.size(19.dp))
                }
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(r.title, color = settingsInk(), fontSize = 13.5.sp, fontWeight = FontWeight.SemiBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                    // The name and the moment each in their own bidi isolate (FSI / LRI … PDI): a Latin name next to a date in an Arabic line
                    // otherwise splits the date's digits around the name.
                    val meta = listOfNotNull(
                        client?.name?.takeIf { it.isNotBlank() }?.let { "⁨$it⁩" },
                        crmDateTime(r.dueAt).takeIf { it.isNotBlank() }?.let { "⁦$it⁩" },
                    ).joinToString(" · ")
                    Text(meta, color = if (overdue) CrmRed else settingsMut(), fontSize = 11.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
                if (overdue) SvcPill(stringResource(R.string.crm_overdue), SvcTone(CrmRed, CrmRed.copy(alpha = 0.12f)))
            }
        }
        if (due.size > 4) {
            Text(
                if (all) stringResource(R.string.crm_due_less) else stringResource(R.string.crm_due_more, due.size - 4),
                color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(RoundedCornerShape(10.dp)).clickable(role = Role.Button) { all = !all }.padding(vertical = 6.dp, horizontal = 4.dp),
            )
        }
    }
}

/** One client: the name and stage (and the label, from level 2), the star, the numbers, what waits and when we last did something. */
@Composable
private fun CrmClientCard(c: CrmClientDto, f: CrmOverviewDto, onClick: () -> Unit, onStar: () -> Unit) {
    val open = stringResource(R.string.crm_open_client, c.name)
    Column(
        Modifier.fillMaxWidth().widthIn(max = 520.dp).appCard(Corners.card).clickable(onClickLabel = open, onClick = onClick).padding(start = 14.dp, top = 12.dp, end = 6.dp, bottom = 14.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            CrmAvatar(c.name)
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(5.dp)) {
                Text(c.name, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                    SvcPill(crmStageName(c.stage), crmStageTone(c.stage))
                    if (f.level >= 2) c.label?.takeIf { it.isNotBlank() }?.let { SvcPill(crmLabelName(it), crmLabelTone(it)) }
                }
            }
            CrmStarButton(c.starred, onStar)
        }
        Column(Modifier.padding(end = 8.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Row(horizontalArrangement = Arrangement.spacedBy(14.dp), verticalAlignment = Alignment.CenterVertically) {
                CrmFact(Icons.Rounded.Work, pluralStringResource(R.plurals.crm_n_projects, c.projects, c.projects))
                if (c.earnedMinor > 0) CrmFact(Icons.Rounded.Payments, stringResource(R.string.crm_fact_earned, svcMoney(c.earnedMinor, f.decimals, f.currency)), strong = true, ltr = true)
            }
            val second = c.rating != null || c.notesCount > 0 || c.remindersCount > 0
            if (second) Row(horizontalArrangement = Arrangement.spacedBy(14.dp), verticalAlignment = Alignment.CenterVertically) {
                c.rating?.let { CrmFact(Icons.Rounded.Star, String.format(Locale.US, "%.1f", it), tint = Tints.amber) }
                if (c.notesCount > 0) CrmFact(Icons.Rounded.StickyNote2, pluralStringResource(R.plurals.crm_n_notes, c.notesCount, c.notesCount))
                if (c.remindersCount > 0) CrmFact(Icons.Rounded.NotificationsActive, pluralStringResource(R.plurals.crm_n_reminders, c.remindersCount, c.remindersCount))
            }
            c.nextDueAt?.takeIf { it.isNotBlank() }?.let { due ->
                val late = crmInstant(due)?.isBefore(Instant.now()) == true
                CrmFact(if (late) Icons.Rounded.EventBusy else Icons.Rounded.Event, stringResource(R.string.crm_next_due, crmDateTime(due)), tint = if (late) CrmRed else null, strong = late)
            }
            if (c.waitingOffer || c.invitedYou) Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                if (c.waitingOffer) SvcPill(stringResource(R.string.crm_waiting_offer), SvcTone(CrmAmber, CrmAmber.copy(alpha = 0.12f)))
                if (c.invitedYou) SvcPill(stringResource(R.string.crm_invited_you), SvcTone(Tints.blue, Tints.blue.copy(alpha = 0.12f)))
            }
            c.lastActivityAt?.takeIf { it.isNotBlank() }?.let { Text(stringResource(R.string.crm_last_activity, crmDate(it)), color = settingsMut(), fontSize = 11.sp) }
        }
    }
}

/** A small fact: an icon and a short text; money is kept left-to-right in either language. */
@Composable
private fun CrmFact(icon: androidx.compose.ui.graphics.vector.ImageVector, text: String, tint: Color? = null, strong: Boolean = false, ltr: Boolean = false) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
        Icon(icon, null, tint = tint ?: if (strong) settingsAccent() else settingsMut(), modifier = Modifier.size(15.dp))
        CompositionLocalProvider(LocalLayoutDirection provides if (ltr) LayoutDirection.Ltr else LocalLayoutDirection.current) {
            Text(text, color = tint ?: if (strong) settingsInk() else settingsMut(), fontSize = 12.5.sp, fontWeight = if (strong) FontWeight.ExtraBold else FontWeight.SemiBold, maxLines = 1)
        }
    }
}

/** The star: 44 dp to tap, named for TalkBack by what a tap does. */
@Composable
private fun CrmStarButton(starred: Boolean, onClick: () -> Unit) {
    val description = stringResource(if (starred) R.string.crm_star_on else R.string.crm_star_off)
    Box(Modifier.size(44.dp).clip(CircleShape).clickable(onClickLabel = description, role = Role.Button, onClick = onClick), contentAlignment = Alignment.Center) {
        Icon(if (starred) Icons.Rounded.Star else Icons.Rounded.StarBorder, description, tint = if (starred) Tints.amber else settingsMut(), modifier = Modifier.size(24.dp))
    }
}

/** The client's first letter on a soft disc — a client has no picture here. */
@Composable
private fun CrmAvatar(name: String?, size: Dp = 40.dp) {
    Box(Modifier.size(size).clip(CircleShape).background(settingsAccent().copy(alpha = 0.16f)), contentAlignment = Alignment.Center) {
        Text(name?.trim()?.firstOrNull()?.uppercase() ?: "?", color = settingsAccent(), fontSize = (size.value * 0.4f).sp, fontWeight = FontWeight.ExtraBold)
    }
}

@Composable
private fun crmStageName(stage: String): String = stringResource(
    when (stage) {
        "active" -> R.string.crm_stage_active
        "past" -> R.string.crm_stage_past
        else -> R.string.crm_stage_lead
    },
)

@Composable
private fun crmStageTone(stage: String): SvcTone = when (stage) {
    "active" -> SvcTone(CrmGreen, CrmGreen.copy(alpha = 0.12f))
    "past" -> settingsMut().let { SvcTone(it, it.copy(alpha = 0.12f)) }
    else -> SvcTone(CrmAmber, CrmAmber.copy(alpha = 0.12f))
}

@Composable
private fun crmLabelName(label: String): String = stringResource(
    when (label) {
        "vip" -> R.string.crm_label_vip
        "regular" -> R.string.crm_label_regular
        "lead" -> R.string.crm_label_lead
        "paused" -> R.string.crm_label_paused
        else -> R.string.crm_label_none
    },
)

@Composable
private fun crmLabelTone(label: String): SvcTone {
    val c = when (label) {
        "vip" -> CrmAmber
        "regular" -> CrmGreen
        "lead" -> Tints.blue
        else -> settingsMut()
    }
    return SvcTone(c, c.copy(alpha = 0.12f))
}

// ---------------------------------------------------------------- dates

/** The server writes `2026-12-12T10:00:00+00:00` (or `Z`): both read. */
private fun crmInstant(iso: String?): Instant? {
    if (iso.isNullOrBlank()) return null
    return runCatching { OffsetDateTime.parse(iso).toInstant() }.getOrNull() ?: runCatching { Instant.parse(iso) }.getOrNull()
}

/** «12 Dec 2026» in the app's language (Latin digits), in the phone's time zone. */
private fun crmDate(iso: String?): String = crmInstant(iso)?.let {
    DateTimeFormatter.ofLocalizedDate(FormatStyle.MEDIUM).withLocale(AppLocale.java()).format(it.atZone(ZoneId.systemDefault()))
}.orEmpty()

/** The date and the hour (`12 Dec 2026 · 09:30`). */
private fun crmDateTime(iso: String?): String {
    val at = crmInstant(iso) ?: return ""
    return crmDate(iso) + " · " + DateTimeFormatter.ofPattern("HH:mm", Locale.US).format(at.atZone(ZoneId.systemDefault()))
}

/** Waiting and past its time: the server says so when it answers, and the phone's clock keeps saying so while the page stays open. */
private fun crmOverdue(r: CrmReminderDto): Boolean = r.doneAt == null && (r.overdue || crmInstant(r.dueAt)?.isBefore(Instant.now()) == true)

// ---------------------------------------------------------------- one client

private class NoteSheetState(val note: CrmNoteDto?)

/**
 * One client: the numbers, the star, the label (level 2; locked with the plan's name at level 1), what is still waiting, the follow-ups
 * (level 2), the private notes and the work we did together (a project opens its agreement). Every change answers with the whole client, so the
 * page repaints from it; a refusal's own message shows (in the sheet while one is open).
 */
@Composable
fun CrmClientScreen(
    uuid: String,
    onBack: () -> Unit,
    onOpenAgreement: (String) -> Unit,
    onOpenProject: (String) -> Unit,
    onOpenPlans: () -> Unit,
) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val haptic = LocalHapticFeedback.current
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val savedText = stringResource(R.string.crm_saved_ok)
    var page by remember { mutableStateOf<CrmClientPageDto?>(null) }
    var locked by remember { mutableStateOf<CrmLockedDto?>(null) }
    var failure by remember { mutableStateOf<String?>(null) }
    var toast by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var noteSheet by remember { mutableStateOf<NoteSheetState?>(null) }
    var reminderSheet by remember { mutableStateOf(false) }
    var sheetError by remember { mutableStateOf<String?>(null) }
    var deleteNote by remember { mutableStateOf<CrmNoteDto?>(null) }
    var deleteReminder by remember { mutableStateOf<CrmReminderDto?>(null) }

    suspend fun load() {
        when (val r = apiCall { ApiClient.crm.client(uuid) }) {
            is ApiResult.Ok -> {
                page = r.data
                locked = null
                failure = null
            }
            else -> {
                val refused = (r as? ApiResult.Failed)?.error?.crmLocked()
                if (refused != null) {
                    locked = refused
                    page = null
                } else if (page == null) {
                    failure = r.errorText(offlineText) ?: genericError
                }
            }
        }
    }
    LaunchedEffect(reconnect) { load() }
    LaunchedEffect(Unit) { Realtime.changed.collect { load() } }

    /** Runs one change; null when it went through (the page is the answer), else the text to show. */
    suspend fun change(call: suspend () -> ApiResult<CrmClientPageDto>): String? {
        busy = true
        val r = call()
        busy = false
        return when (r) {
            is ApiResult.Ok -> {
                page = r.data
                null
            }
            is ApiResult.Failed -> {
                // The plan changed under the page (a downgrade): read again, so the page shows what it opens now.
                if (r.error.crmLocked() != null) load()
                r.error.displayMessage.ifBlank { genericError }
            }
            is ApiResult.Offline -> offlineText
        }
    }

    fun send(call: suspend () -> ApiResult<CrmClientPageDto>, done: (String?) -> Unit = { toast = it }) {
        if (busy) return
        toast = null
        scope.launch { done(change(call)) }
    }

    Page(title = stringResource(R.string.crm_client_title), onBack = onBack) {
        val p = page
        val c = p?.client
        val refused = locked
        when {
            p == null && refused != null -> CrmLocked(refused.upgrade, onOpenPlans)
            p == null && failure != null -> CrmFailure(failure.orEmpty()) { scope.launch { failure = null; load() } }
            p == null || c == null -> SkeletonDetail()
            else -> {
                CrmClientHead(c, p) {
                    haptic.performHapticFeedback(HapticFeedbackType.TextHandleMove)
                    send({ apiCall { ApiClient.crm.update(uuid, CrmBody.star(!c.starred)) } })
                }
                CrmLabelCard(c, p, busy) { label -> send({ apiCall { ApiClient.crm.update(uuid, CrmBody.label(label)) } }) }
                if (c.leads.isNotEmpty()) CrmLeadsCard(c.leads, onOpenProject)
                if (p.level >= 2) {
                    CrmRemindersCard(
                        c.reminders, busy, onAdd = { sheetError = null; reminderSheet = true },
                        onToggle = { r -> send({ apiCall { ApiClient.crm.finishReminder(uuid, r.uuid, CrmReminderDoneRequest(r.doneAt == null)) } }) },
                        onDelete = { deleteReminder = it },
                    )
                } else {
                    CrmRemindersLocked(p.upgrade, onOpenPlans)
                }
                CrmNotesCard(
                    c.notes, p.limits.notesMax, busy, onAdd = { sheetError = null; noteSheet = NoteSheetState(null) },
                    onEdit = { sheetError = null; noteSheet = NoteSheetState(it) }, onDelete = { deleteNote = it },
                )
                CrmHistoryCard(c.history, p, onOpenAgreement)
            }
        }
        ErrorBanner(toast, Modifier.fillMaxWidth())
    }

    noteSheet?.let { state ->
        NoteSheet(
            existing = state.note, busy = busy, error = sheetError,
            onSave = { body ->
                send({ apiCall { if (state.note == null) ApiClient.crm.addNote(uuid, CrmNoteRequest(body)) else ApiClient.crm.editNote(uuid, state.note.uuid, CrmNoteRequest(body)) } }) { err ->
                    if (err == null) {
                        noteSheet = null
                        toast = savedText
                    } else {
                        sheetError = err
                    }
                }
            },
            onDismiss = { noteSheet = null },
        )
    }
    if (reminderSheet) {
        ReminderSheet(
            busy = busy, error = sheetError,
            onSave = { title, dueAt ->
                send({ apiCall { ApiClient.crm.addReminder(uuid, CrmReminderRequest(title, dueAt)) } }) { err ->
                    if (err == null) {
                        reminderSheet = false
                        toast = savedText
                    } else {
                        sheetError = err
                    }
                }
            },
            onDismiss = { reminderSheet = false },
        )
    }
    deleteNote?.let { n ->
        ConfirmDialog(
            icon = Icons.Rounded.DeleteOutline, title = stringResource(R.string.crm_note_delete), message = stringResource(R.string.crm_note_delete_confirm),
            confirmLabel = stringResource(R.string.common_delete), loading = busy, onDismiss = { deleteNote = null },
            onConfirm = { send({ apiCall { ApiClient.crm.deleteNote(uuid, n.uuid) } }) { err -> deleteNote = null; toast = err } },
        )
    }
    deleteReminder?.let { r ->
        ConfirmDialog(
            icon = Icons.Rounded.DeleteOutline, title = stringResource(R.string.crm_rem_delete), message = stringResource(R.string.crm_rem_delete_confirm),
            confirmLabel = stringResource(R.string.common_delete), loading = busy, onDismiss = { deleteReminder = null },
            onConfirm = { send({ apiCall { ApiClient.crm.deleteReminder(uuid, r.uuid) } }) { err -> deleteReminder = null; toast = err } },
        )
    }
}

/** The top of the page: who, the stage and label, the star, the three numbers, what waits, and that only I see all this. */
@Composable
private fun CrmClientHead(c: CrmClientDto, p: CrmClientPageDto, onStar: () -> Unit) {
    Column(
        Modifier.fillMaxWidth().widthIn(max = 520.dp).settingsSurface(RoundedCornerShape(22.dp)).padding(start = 16.dp, top = 14.dp, end = 8.dp, bottom = 16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            CrmAvatar(c.name, 52.dp)
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Text(c.name, color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, lineHeight = 24.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                    SvcPill(crmStageName(c.stage), crmStageTone(c.stage))
                    if (p.level >= 2) c.label?.takeIf { it.isNotBlank() }?.let { SvcPill(crmLabelName(it), crmLabelTone(it)) }
                }
            }
            CrmStarButton(c.starred, onStar)
        }
        Row(Modifier.padding(end = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            CrmStat(c.projects.toString(), stringResource(R.string.crm_stat_projects), Modifier.weight(1f))
            CrmStat(svcMoney(c.earnedMinor, p.decimals, p.currency), stringResource(R.string.crm_stat_earned), Modifier.weight(1.8f))
            CrmStat(c.rating?.let { String.format(Locale.US, "%.1f", it) } ?: "—", stringResource(R.string.crm_stat_rating), Modifier.weight(1f))
        }
        if (c.waitingOffer || c.invitedYou) Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            if (c.waitingOffer) SvcPill(stringResource(R.string.crm_waiting_offer), SvcTone(CrmAmber, CrmAmber.copy(alpha = 0.12f)))
            if (c.invitedYou) SvcPill(stringResource(R.string.crm_invited_you), SvcTone(Tints.blue, Tints.blue.copy(alpha = 0.12f)))
        }
        Row(Modifier.padding(end = 8.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            Icon(Icons.Rounded.Lock, null, tint = settingsMut(), modifier = Modifier.size(14.dp))
            Text(stringResource(R.string.crm_private_note), color = settingsMut(), fontSize = 11.5.sp, lineHeight = 16.sp)
        }
    }
}

@Composable
private fun CrmStat(value: String, label: String, modifier: Modifier = Modifier) {
    Column(modifier.clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.07f)).padding(horizontal = 10.dp, vertical = 10.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
            Text(value, color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        Text(label, color = settingsMut(), fontSize = 11.sp, lineHeight = 14.sp, maxLines = 2)
    }
}

/** The label: chosen from the plan's labels at level 2; at level 1 a locked line that names the plan, with no action. */
@Composable
private fun CrmLabelCard(c: CrmClientDto, p: CrmClientPageDto, busy: Boolean, onPick: (String?) -> Unit) {
    val haptic = LocalHapticFeedback.current
    // The chip fills at the tap and not when the server has answered: on a slow line a tap that waits for the answer looks like a tap that did nothing.
    var pending by remember(c.label) { mutableStateOf<String?>(null) }
    LaunchedEffect(busy) { if (!busy) pending = null }
    InfoCard(title = stringResource(R.string.crm_label_title), icon = Icons.Rounded.Label) {
        if (p.level >= 2) {
            Text(stringResource(R.string.crm_label_sub), color = settingsMut(), fontSize = 12.sp)
            val shown = pending ?: c.label.orEmpty()
            FilterChips(
                listOf(FilterOption("", stringResource(R.string.crm_label_none))) + p.labels.map { FilterOption(it, crmLabelName(it)) },
                shown,
                { key ->
                    if (!busy && key != shown) {
                        haptic.performHapticFeedback(HapticFeedbackType.TextHandleMove)
                        pending = key
                        onPick(key.ifEmpty { null })
                    }
                },
            )
        } else {
            val plan = p.upgrade?.let { it.name ?: it.slug }
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Icon(Icons.Rounded.Lock, null, tint = settingsMut(), modifier = Modifier.size(18.dp))
                Text(
                    if (plan != null) stringResource(R.string.crm_label_locked, plan) else stringResource(R.string.crm_label_locked_plain),
                    color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp,
                )
            }
        }
    }
}

/** Offers of mine still waiting on him and his invitations to me: a tap opens the project. */
@Composable
private fun CrmLeadsCard(leads: List<CrmLeadDto>, onOpenProject: (String) -> Unit) {
    InfoCard(title = stringResource(R.string.crm_leads_title), icon = Icons.Rounded.HourglassTop) {
        Text(stringResource(R.string.crm_leads_sub), color = settingsMut(), fontSize = 12.sp)
        leads.forEach { l ->
            val offer = l.kind == "offer"
            Row(
                Modifier.fillMaxWidth().clip(Corners.small).then(if (l.project != null) Modifier.clickable(role = Role.Button) { onOpenProject(l.project) } else Modifier).padding(vertical = 4.dp),
                verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                Box(Modifier.size(36.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                    Icon(if (offer) Icons.AutoMirrored.Rounded.Send else Icons.Rounded.Mail, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
                }
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(l.title?.takeIf { it.isNotBlank() } ?: stringResource(R.string.crm_untitled), color = settingsInk(), fontSize = 13.5.sp, fontWeight = FontWeight.SemiBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                    Text(
                        listOfNotNull(stringResource(if (offer) R.string.crm_lead_offer else R.string.crm_lead_invitation), crmDate(l.at).takeIf { it.isNotBlank() }).joinToString(" · "),
                        color = settingsMut(), fontSize = 11.5.sp,
                    )
                }
                SvcPill(
                    stringResource(
                        when (l.status) {
                            "viewed" -> R.string.crm_lead_viewed
                            "shortlisted" -> R.string.crm_lead_shortlisted
                            "pending" -> R.string.crm_lead_pending
                            else -> R.string.crm_lead_submitted
                        },
                    ),
                    SvcTone(CrmAmber, CrmAmber.copy(alpha = 0.12f)),
                )
            }
        }
    }
}

/** Level 2: the follow-ups (the open ones soonest first, then the last few done) with done / reopen and delete on each. */
@Composable
private fun CrmRemindersCard(
    reminders: List<CrmReminderDto>,
    busy: Boolean,
    onAdd: () -> Unit,
    onToggle: (CrmReminderDto) -> Unit,
    onDelete: (CrmReminderDto) -> Unit,
) {
    InfoCard(title = stringResource(R.string.crm_rem_title), icon = Icons.Rounded.NotificationsActive) {
        Text(stringResource(R.string.crm_rem_sub), color = settingsMut(), fontSize = 12.sp)
        if (reminders.isEmpty()) Text(stringResource(R.string.crm_rem_empty), color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp)
        reminders.forEach { r -> key(r.uuid) { CrmReminderRow(r, busy, { onToggle(r) }, { onDelete(r) }) } }
        ActionButton(stringResource(R.string.crm_rem_add), Icons.Rounded.AddAlert, Tone.Soft, enabled = !busy, onClick = onAdd)
    }
}

@Composable
private fun CrmReminderRow(r: CrmReminderDto, busy: Boolean, onToggle: () -> Unit, onDelete: () -> Unit) {
    val done = r.doneAt != null
    val overdue = crmOverdue(r)
    val toggleText = stringResource(if (done) R.string.crm_rem_reopen else R.string.crm_rem_mark_done)
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(2.dp)) {
        Box(Modifier.size(44.dp).clip(CircleShape).clickable(enabled = !busy, onClickLabel = toggleText, role = Role.Button, onClick = onToggle), contentAlignment = Alignment.Center) {
            Icon(if (done) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, toggleText, tint = if (done) CrmGreen else if (overdue) CrmRed else settingsMut(), modifier = Modifier.size(24.dp))
        }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(
                r.title, color = if (done) settingsMut() else settingsInk(), fontSize = 13.5.sp, fontWeight = FontWeight.SemiBold,
                textDecoration = if (done) TextDecoration.LineThrough else null, maxLines = 3, overflow = TextOverflow.Ellipsis,
            )
            Text(
                if (done) stringResource(R.string.crm_rem_done_on, crmDate(r.doneAt)) else crmDateTime(r.dueAt),
                color = if (overdue) CrmRed else settingsMut(), fontSize = 11.5.sp, fontWeight = if (overdue) FontWeight.Bold else FontWeight.Normal,
            )
        }
        if (overdue) SvcPill(stringResource(R.string.crm_overdue), SvcTone(CrmRed, CrmRed.copy(alpha = 0.12f)))
        val deleteText = stringResource(R.string.crm_rem_delete)
        Box(Modifier.size(44.dp).clip(CircleShape).clickable(enabled = !busy, onClickLabel = deleteText, role = Role.Button, onClick = onDelete), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.DeleteOutline, deleteText, tint = settingsMut(), modifier = Modifier.size(22.dp))
        }
    }
}

/** Level 1: a teaser for the reminders, naming the plan that opens them. */
@Composable
private fun CrmRemindersLocked(upgrade: CrmUpgradeDto?, onOpenPlans: () -> Unit) {
    InfoCard(title = stringResource(R.string.crm_rem_locked_title), icon = Icons.Rounded.NotificationsActive) {
        val plan = upgrade?.let { it.name ?: it.slug }
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Icon(Icons.Rounded.Lock, null, tint = settingsMut(), modifier = Modifier.size(18.dp))
            Text(
                if (plan != null) stringResource(R.string.crm_rem_locked_body, plan) else stringResource(R.string.crm_rem_locked_plain),
                color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp,
            )
        }
        if (plan != null) SettingsPrimaryButton(text = stringResource(R.string.crm_unlock, plan), onClick = onOpenPlans)
    }
}

/** The private notes, the newest first, with the count against the plan's limit; the add button stops at the limit. */
@Composable
private fun CrmNotesCard(
    notes: List<CrmNoteDto>,
    max: Int,
    busy: Boolean,
    onAdd: () -> Unit,
    onEdit: (CrmNoteDto) -> Unit,
    onDelete: (CrmNoteDto) -> Unit,
) {
    val full = notes.size >= max
    InfoCard(title = stringResource(R.string.crm_notes_title), icon = Icons.Rounded.StickyNote2) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Text(stringResource(R.string.crm_notes_sub), color = settingsMut(), fontSize = 12.sp, modifier = Modifier.weight(1f))
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                Text(stringResource(R.string.crm_notes_count, notes.size, max), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
            }
        }
        if (notes.isEmpty()) Text(stringResource(R.string.crm_notes_empty), color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp)
        notes.forEach { n -> key(n.uuid) { CrmNoteRow(n, busy, { onEdit(n) }, { onDelete(n) }) } }
        ActionButton(stringResource(R.string.crm_note_add), Icons.Rounded.Add, Tone.Soft, enabled = !busy && !full, onClick = onAdd)
        if (full) Text(stringResource(R.string.crm_notes_full_hint, max), color = CrmAmber, fontSize = 12.sp, lineHeight = 18.sp)
    }
}

@Composable
private fun CrmNoteRow(n: CrmNoteDto, busy: Boolean, onEdit: () -> Unit, onDelete: () -> Unit) {
    val edited = n.updatedAt != null && n.updatedAt != n.createdAt
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.06f)).padding(start = 12.dp, top = 12.dp, end = 4.dp, bottom = 2.dp),
        verticalArrangement = Arrangement.spacedBy(2.dp),
    ) {
        Text(n.body, color = settingsInk(), fontSize = 14.sp, lineHeight = 21.sp, modifier = Modifier.padding(end = 8.dp))
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(
                if (edited) stringResource(R.string.crm_note_edited, crmDate(n.updatedAt)) else crmDate(n.createdAt),
                color = settingsMut(), fontSize = 11.sp, modifier = Modifier.weight(1f),
            )
            val editText = stringResource(R.string.crm_note_edit)
            Box(Modifier.size(44.dp).clip(CircleShape).clickable(enabled = !busy, onClickLabel = editText, role = Role.Button, onClick = onEdit), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Edit, editText, tint = settingsMut(), modifier = Modifier.size(20.dp))
            }
            val deleteText = stringResource(R.string.crm_note_delete)
            Box(Modifier.size(44.dp).clip(CircleShape).clickable(enabled = !busy, onClickLabel = deleteText, role = Role.Button, onClick = onDelete), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.DeleteOutline, deleteText, tint = settingsMut(), modifier = Modifier.size(20.dp))
            }
        }
    }
}

/** The work we did together (the newest first): each opens its agreement. */
@Composable
private fun CrmHistoryCard(history: List<CrmHistoryDto>, p: CrmClientPageDto, onOpenAgreement: (String) -> Unit) {
    InfoCard(title = stringResource(R.string.crm_hist_title), icon = Icons.Rounded.History) {
        Text(stringResource(R.string.crm_hist_sub), color = settingsMut(), fontSize = 12.sp)
        if (history.isEmpty()) {
            Text(stringResource(R.string.crm_hist_empty), color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp)
            return@InfoCard
        }
        history.forEachIndexed { i, h ->
            if (i > 0) Box(Modifier.fillMaxWidth().height(1.dp).background(if (settingsNight()) AccountDark.line else Color(0xFFEEF0F3)))
            val done = h.status == "completed"
            Column(
                Modifier.fillMaxWidth().clip(Corners.small).then(if (h.project != null) Modifier.clickable(role = Role.Button) { onOpenAgreement(h.project) } else Modifier).padding(vertical = 4.dp),
                verticalArrangement = Arrangement.spacedBy(6.dp),
            ) {
                Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(h.title?.takeIf { it.isNotBlank() } ?: stringResource(R.string.crm_untitled), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold, maxLines = 2, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                    SvcPill(stringResource(if (done) R.string.crm_hist_completed else R.string.crm_hist_active), if (done) SvcTone(CrmGreen, CrmGreen.copy(alpha = 0.12f)) else SvcTone(settingsAccent(), settingsAccent().copy(alpha = 0.12f)))
                }
                Row(horizontalArrangement = Arrangement.spacedBy(14.dp), verticalAlignment = Alignment.CenterVertically) {
                    CrmFact(Icons.Rounded.Payments, stringResource(R.string.crm_hist_price, svcMoney(h.priceMinor, p.decimals, p.currency)), ltr = true)
                    if (h.earnedMinor > 0) CrmFact(Icons.Rounded.CheckCircle, stringResource(R.string.crm_hist_earned, svcMoney(h.earnedMinor, p.decimals, p.currency)), strong = true, ltr = true)
                }
                val dates = listOfNotNull(
                    crmDate(h.startedAt).takeIf { it.isNotBlank() }?.let { stringResource(R.string.crm_hist_started, it) },
                    crmDate(h.completedAt).takeIf { it.isNotBlank() }?.let { stringResource(R.string.crm_hist_finished, it) },
                    if (h.service) stringResource(R.string.crm_hist_service) else null,
                )
                if (dates.isNotEmpty()) Text(dates.joinToString(" · "), color = settingsMut(), fontSize = 11.5.sp)
            }
        }
    }
}

// ---------------------------------------------------------------- sheets

/** A note: up to 1000 characters with a counter; a refusal's text (the notes are full) stays in the sheet. */
@Composable
private fun NoteSheet(existing: CrmNoteDto?, busy: Boolean, error: String?, onSave: (String) -> Unit, onDismiss: () -> Unit) {
    var text by rememberSaveable { mutableStateOf(existing?.body.orEmpty()) }
    FormSheet(
        icon = Icons.Rounded.EditNote,
        title = stringResource(if (existing == null) R.string.crm_note_sheet_new else R.string.crm_note_sheet_edit),
        body = stringResource(R.string.crm_note_sheet_body),
        onDismiss = { if (!busy) onDismiss() },
        // The save button stays above the keyboard while the note is typed.
        footer = {
            ActionButton(stringResource(R.string.crm_note_save), Icons.Rounded.Save, Tone.Primary, enabled = text.isNotBlank() && !busy, loading = busy) { onSave(text.trim()) }
        },
    ) {
        DorrTextField(
            text, { text = it.take(1000) }, Modifier.fillMaxWidth(),
            label = stringResource(R.string.crm_note_field), placeholder = stringResource(R.string.crm_note_hint), singleLine = false, minLines = 5,
        )
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(error.orEmpty(), color = CrmRed, fontSize = 12.sp, lineHeight = 17.sp, modifier = Modifier.weight(1f))
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                Text("${text.length} / 1000", color = settingsMut(), fontSize = 12.sp)
            }
        }
    }
}

/**
 * A follow-up: what to follow up, and when — the date and the hour (quick picks for tomorrow, a week and a month at 9:00). The time must be
 * in the future; the server's own limits (a year ahead, the open reminders' cap) come back as its message, in the sheet.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ReminderSheet(busy: Boolean, error: String?, onSave: (String, String) -> Unit, onDismiss: () -> Unit) {
    var title by rememberSaveable { mutableStateOf("") }
    var date by rememberSaveable { mutableStateOf(LocalDate.now().plusDays(1).toString()) }
    var time by rememberSaveable { mutableStateOf("09:00") }
    var pastError by remember { mutableStateOf(false) }
    var picking by remember { mutableStateOf(false) }

    FormSheet(
        icon = Icons.Rounded.AddAlert,
        title = stringResource(R.string.crm_rem_sheet_title),
        body = stringResource(R.string.crm_rem_sheet_body),
        onDismiss = { if (!busy) onDismiss() },
        // The save button stays above the keyboard while the title is typed.
        footer = {
            val problem = if (pastError) stringResource(R.string.crm_rem_past) else error
            if (problem != null) Text(problem, color = CrmRed, fontSize = 12.sp, lineHeight = 17.sp)
            ActionButton(stringResource(R.string.crm_rem_save), Icons.Rounded.Save, Tone.Primary, enabled = title.isNotBlank() && date.isNotEmpty() && !busy, loading = busy) {
                val day = runCatching { LocalDate.parse(date) }.getOrNull()
                val hour = runCatching { LocalTime.parse(time) }.getOrNull()
                if (day != null && hour != null) {
                    val at = ZonedDateTime.of(day, hour, ZoneId.systemDefault())
                    if (at.isAfter(ZonedDateTime.now())) onSave(title.trim(), at.format(DateTimeFormatter.ISO_OFFSET_DATE_TIME)) else pastError = true
                }
            }
        },
    ) {
        // A one-line thought: the keyboard's own key says "done" and puts the keyboard away (a new line is of no use in a title).
        DorrTextField(
            title, { title = it.replace("\n", " ").take(120) }, Modifier.fillMaxWidth(),
            label = stringResource(R.string.crm_rem_field_title), placeholder = stringResource(R.string.crm_rem_field_title_ph), singleLine = false, minLines = 2,
            keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Sentences, imeAction = ImeAction.Done),
        )
        Text(stringResource(R.string.crm_rem_quick), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
        Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf(R.string.crm_quick_tomorrow to 1L, R.string.crm_quick_week to 7L, R.string.crm_quick_month to 30L).forEach { (label, days) ->
                val day = LocalDate.now().plusDays(days).toString()
                PillChip(stringResource(label), date == day && time == "09:00") {
                    date = day
                    time = "09:00"
                    pastError = false
                }
            }
        }
        DateField(date, { date = it; pastError = false }, stringResource(R.string.crm_rem_field_date), Modifier.fillMaxWidth())
        DorrTextField(
            value = time, onValueChange = {}, label = stringResource(R.string.crm_rem_field_time), icon = Icons.Rounded.Schedule,
            onClick = { picking = true }, modifier = Modifier.fillMaxWidth(),
        )
    }

    if (picking) {
        val parts = time.split(":")
        val state = rememberTimePickerState(initialHour = parts.getOrNull(0)?.toIntOrNull() ?: 9, initialMinute = parts.getOrNull(1)?.toIntOrNull() ?: 0, is24Hour = true)
        AlertDialog(
            onDismissRequest = { picking = false },
            title = { Text(stringResource(R.string.crm_rem_pick_time), fontWeight = FontWeight.ExtraBold) },
            text = {
                TimePicker(
                    state = state,
                    colors = TimePickerDefaults.colors(selectorColor = settingsAccent(), timeSelectorSelectedContainerColor = settingsAccent().copy(alpha = 0.16f), timeSelectorSelectedContentColor = settingsAccent()),
                )
            },
            confirmButton = {
                DialogButton(onClick = {
                    time = String.format(Locale.US, "%02d:%02d", state.hour, state.minute)
                    pastError = false
                    picking = false
                }) { Text(stringResource(R.string.common_ok), fontWeight = FontWeight.Bold) }
            },
            dismissButton = { DialogButton(onClick = { picking = false }) { Text(stringResource(R.string.common_cancel)) } },
        )
    }
}
