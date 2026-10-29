package com.dorr.app.ui.screens.freelance

import androidx.compose.foundation.layout.wrapContentSize
import android.content.Context
import android.net.Uri
import android.provider.OpenableColumns
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.AttachFile
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.DeleteForever
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.Work
import androidx.compose.material.icons.rounded.*
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.listSaver
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.platform.LocalSoftwareKeyboardController
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.FreelanceBudgetRulesDto
import com.dorr.app.network.FreelanceCancelRequest
import com.dorr.app.network.FreelanceCostCapRequest
import com.dorr.app.network.FreelanceIssueDto
import com.dorr.app.network.FreelancePreviewDto
import com.dorr.app.network.FreelanceNamedDto
import com.dorr.app.network.FreelanceProjectDto
import com.dorr.app.network.FreelanceProjectRequest
import com.dorr.app.network.FreelanceSpecialtyNodeDto
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.network.serverMessage
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.ConfirmDialog
import com.dorr.app.ui.screens.profile.PinkBackdrop
import com.dorr.app.ui.screens.profile.PinkIcon
import com.dorr.app.ui.screens.profile.SettingsPrimaryButton
import com.dorr.app.ui.screens.profile.SubHeader
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsSurface
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.util.Locale

internal fun auth() = "Bearer ${AuthSession.token.orEmpty()}"

/** "1234.50" from minor units (the country's currency has two decimals). */
internal fun money(minor: Long?): String = minor?.let { String.format(Locale.US, "%d.%02d", it / 100, it % 100) }.orEmpty()

/** Minor units from what the client typed ("1500", "1500.5"), or null. */
internal fun parseMoney(text: String): Long? {
    val clean = text.trim().replace(',', '.')
    if (!Regex("""^\d{1,9}(\.\d{1,2})?$""").matches(clean)) return null
    val parts = clean.split('.')
    return parts[0].toLong() * 100 + (parts.getOrNull(1)?.padEnd(2, '0')?.toLong() ?: 0)
}

/** The server's message when it said one, else the generic one. */
internal fun Throwable.message(fallback: String): String = serverMessage() ?: fallback

/**
 * Freelance work for the client (FL-201 / FL-202): my projects, the project form (a draft saved as it is typed, its files) and one
 * project with what can be done now (send for review, take it back, cancel, delete). Every project is reviewed before it is
 * published. Nothing is asked on a timer: each page reads once when it opens and when the connection comes back.
 */
@Composable
fun FreelanceHost(onExit: () -> Unit) {
    // "list", "new", "edit:<uuid>", "show:<uuid>", "offers:<p>", "offer:<p>:<o>", "compare:<p>:<o,o,o>", "freelancer:<p>:<o>", "chat:<p>:<o>:<room>"
    var page by rememberSaveable { mutableStateOf("list") }
    var listVersion by remember { mutableStateOf(0) }
    val parts = page.split(':')
    fun back(): String = when (parts[0]) {
        "edit", "offers" -> "show:" + parts[1]
        "offer", "compare" -> "offers:" + parts[1]
        "chat" -> "offer:" + parts[1] + ":" + parts[2]
        "freelancer" -> "offer:" + parts[1] + ":" + parts[2]
        "favorites" -> "list"
        "agreement", "invites", "preview" -> "show:" + parts[1]
        "dispute", "dispute-new" -> "agreement:" + parts[1]
        else -> "list"
    }
    BackHandler {
        if (page == "list") onExit() else page = back()
    }
    // A tapped notification names the page it belongs to (also when this screen is already open).
    val linked = FreelanceLink.pending
    LaunchedEffect(linked) {
        if (linked != null) {
            page = linked
            FreelanceLink.pending = null
        }
    }

    Box(Modifier.fillMaxSize().navigationBarsPadding().imePadding()) {
        PinkBackdrop(Modifier.matchParentSize())
        when {
            page == "new" -> ProjectForm(uuid = null, onBack = { page = "list" }, onSaved = { page = "show:$it"; listVersion++ })
            page.startsWith("edit:") -> ProjectForm(uuid = page.removePrefix("edit:"), onBack = { page = "show:" + page.removePrefix("edit:") }, onSaved = { page = "show:$it"; listVersion++ })
            page.startsWith("show:") -> ProjectDetails(
                uuid = page.removePrefix("show:"),
                onBack = { page = "list" },
                onEdit = { page = "edit:$it" },
                onGone = { page = "list"; listVersion++ },
                onPreview = { page = "preview:" + page.removePrefix("show:") },
                onCopied = { listVersion++; page = "show:$it" },
                onOffers ={ page = "offers:" + page.removePrefix("show:") },
                onOpenOffer = { page = "offer:" + page.removePrefix("show:") + ":" + it },
                onAgreement = { page = "agreement:" + page.removePrefix("show:") },
                onInvitations = { page = "invites:" + page.removePrefix("show:") },
            )
            parts[0] == "preview" -> PreviewPage(parts[1], onBack = { page = back() }, onEdit = { page = "edit:${parts[1]}" }, onSent = { listVersion++; page = "show:${parts[1]}" })
            parts[0] == "invites" -> InvitationsPage(parts[1], onBack = { page = back() }, onOpenOffer = { page = "offer:${parts[1]}:$it" }, onMadePublic = { listVersion++; page = "show:${parts[1]}" })
            parts[0] == "offers" -> OffersPage(parts[1], onBack = { page = back() }, onOpen = { page = "offer:${parts[1]}:$it" }, onCompare = { page = "compare:${parts[1]}:" + it.joinToString(",") })
            parts[0] == "compare" -> ComparePage(parts[1], parts[2].split(','), onBack = { page = back() }, onOpen = { page = "offer:${parts[1]}:$it" })
            parts[0] == "offer" -> OfferPage(parts[1], parts[2], onBack = { page = back() }, onProfile = { page = "freelancer:${parts[1]}:${parts[2]}" }, onChat = { page = "chat:${parts[1]}:${parts[2]}:$it" }, onChosen = { listVersion++; page = "agreement:${parts[1]}" })
            parts[0] == "agreement" -> AgreementPage(parts[1], onBack = { page = back() }, onEnded = { listVersion++ }, onDispute = { m, d -> page = if (d != null) "dispute:${parts[1]}:$d" else "dispute-new:${parts[1]}:$m" })
            parts[0] == "dispute" -> DisputePage(parts[1], null, parts[2], onBack = { page = back() }, onOpened = {})
            parts[0] == "dispute-new" -> DisputePage(parts[1], parts[2], null, onBack = { page = back() }, onOpened = { page = "dispute:${parts[1]}:$it" })
            parts[0] == "favorites" -> FavoritesPage(onBack = { page = "list" })
            parts[0] == "chat" -> ChatPage(parts[3], onBack = { page = back() })
            parts[0] == "freelancer" -> FreelancerProfilePage(parts[1], parts[2], onBack = { page = back() })
            else -> ProjectList(version = listVersion, onExit = onExit, onNew = { page = "new" }, onOpen = { page = "show:$it" }, onFavorites = { page = "favorites" })
        }
    }
}

// ---------------------------------------------------------------- my projects

private val FILTERS = listOf(null, "draft", "pending_review", "changes_requested", "published", "awarded", "in_progress", "rejected", "cancelled")

@Composable
private fun filterLabel(status: String?): String = when (status) {
    null -> stringResource(R.string.fl_filter_all)
    else -> statusLabel(status)
}

@Composable
private fun statusLabel(status: String): String = when (status) {
    "draft" -> stringResource(R.string.fl_status_draft)
    "pending_review" -> stringResource(R.string.fl_status_pending_review)
    "changes_requested" -> stringResource(R.string.fl_status_changes_requested)
    "rejected" -> stringResource(R.string.fl_status_rejected)
    "published" -> stringResource(R.string.fl_status_published)
    "awarded" -> stringResource(R.string.fl_status_awarded)
    "in_progress" -> stringResource(R.string.fl_status_in_progress)
    "closed" -> stringResource(R.string.fl_status_closed)
    else -> stringResource(R.string.fl_status_cancelled)
}

private fun statusColor(status: String): Color = when (status) {
    "published" -> Color(0xFF16A34A)
    "awarded" -> Color(0xFF7C3AED)
    "in_progress" -> Color(0xFF0EA5E9)
    "pending_review" -> Color(0xFFD97706)
    "changes_requested" -> Color(0xFF2563EB)
    "rejected", "cancelled" -> Color(0xFFDC2626)
    else -> Color(0xFF6B7280)
}

@Composable
private fun StatusPill(status: String) {
    val color = statusColor(status)
    Text(
        statusLabel(status), color = color, fontSize = 11.sp, fontWeight = FontWeight.Bold,
        modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(color.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 3.dp),
    )
}

@Composable
internal fun Chip(text: String, selected: Boolean, icon: androidx.compose.ui.graphics.vector.ImageVector? = null, onClick: () -> Unit) {
    val accent = settingsAccent()
    val ink = if (selected) Color.White else settingsInk()
    Row(
        Modifier.clip(RoundedCornerShape(999.dp)).background(if (selected) accent else accent.copy(alpha = 0.08f))
            .clickable(onClick = onClick).padding(horizontal = 14.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        if (icon != null) Icon(icon, null, tint = if (selected) Color.White else settingsAccent(), modifier = Modifier.size(16.dp))
        Text(text, color = ink, fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 1, softWrap = false)
    }
}

private fun filterIcon(status: String?): androidx.compose.ui.graphics.vector.ImageVector = when (status) {
    null -> Icons.Rounded.Apps
    "draft" -> Icons.Rounded.EditNote
    "pending_review" -> Icons.Rounded.HourglassTop
    "changes_requested" -> Icons.Rounded.Edit
    "published" -> Icons.Rounded.Public
    "awarded" -> Icons.Rounded.Handshake
    "in_progress" -> Icons.Rounded.Autorenew
    "rejected" -> Icons.Rounded.Block
    else -> Icons.Rounded.Cancel
}

@Composable
private fun ProjectList(version: Int, onExit: () -> Unit, onNew: () -> Unit, onOpen: (String) -> Unit, onFavorites: () -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    var filter by rememberSaveable { mutableStateOf<String?>(null) }
    val listState = rememberLazyListState()
    // A new filter is a new list (it starts from the first page); the next pages come while I scroll.
    val paged = remember(filter) { PagedRows<FreelanceProjectDto>({ it.uuid }, { page -> ApiClient.freelance.projects(auth(), status = filter, page = page) }) }
    val rows = paged.rows
    val loading = !paged.loaded
    val error = paged.error
    LaunchedEffect(paged, reconnect, version) { paged.reload(generic) }
    listState.NearEnd(rows.size) { scope.launch { paged.loadMore(generic) } }

    Column(Modifier.fillMaxSize()) {
        SubHeader(stringResource(R.string.fl_my_projects), onExit)
        Row(Modifier.horizontalScroll(rememberScrollState()).padding(horizontal = 14.dp, vertical = 4.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Chip(stringResource(R.string.fl_favorites), selected = false, icon = Icons.Rounded.Favorite) { onFavorites() }
            FILTERS.forEach { f -> Chip(filterLabel(f), selected = filter == f, icon = filterIcon(f)) { filter = f } }
        }
        if (loading && error == null) {
            Box(Modifier.weight(1f)) { FlSkeletonList() }
        } else LazyColumn(
            Modifier.weight(1f).fillMaxWidth(), state = listState,
            contentPadding = androidx.compose.foundation.layout.PaddingValues(horizontal = 16.dp, vertical = 10.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            // FL-708: shown only while a warning / restriction is active or its appeal waits.
            item { SanctionsCard(version) }
            if (!loading && rows.isEmpty() && error == null) item {
                com.dorr.app.ui.screens.wallet.WaEmpty(
                    Icons.Rounded.Work, com.dorr.app.ui.screens.wallet.Tone.Blue,
                    stringResource(R.string.fl_empty_title), stringResource(R.string.fl_empty_text),
                )
            }
            items(rows, key = { it.uuid }) { p -> ProjectCard(p) { onOpen(p.uuid) } }
            item { PagedSpinner(paged) }
            error?.let { item { TextButton(onClick = { paged.clearError(); scope.launch { paged.loadMore(generic) } }, modifier = Modifier.fillMaxWidth()) { Text(it, color = Color(0xFFDC2626), fontSize = 13.sp) } } }
        }
        SettingsPrimaryButton(text = stringResource(R.string.fl_new_project), modifier = Modifier.padding(16.dp), onClick = onNew)
    }
}

@Composable
private fun ProjectCard(p: FreelanceProjectDto, onClick: () -> Unit) {
    val color = statusColor(p.status)
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).clickable(onClick = onClick).padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Box(Modifier.size(44.dp).clip(RoundedCornerShape(14.dp)).background(color.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Work, null, tint = color, modifier = Modifier.size(22.dp))
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text(
                    p.title?.takeIf { it.isNotBlank() } ?: stringResource(R.string.fl_untitled), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold,
                    maxLines = 2, overflow = TextOverflow.Ellipsis,
                )
                p.specialty?.let { Text(it.name, color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis) }
            }
            StatusPill(p.status)
        }
        budgetText(p)?.let { b ->
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Icon(Icons.Rounded.Payments, null, tint = settingsAccent(), modifier = Modifier.size(16.dp))
                Text(b, color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
            }
        }
        // FL-211: where the work stands, and what is due from me.
        p.progress?.let { g ->
            ProgressBar(g.approved, g.total)
            Text(
                listOfNotNull(
                    stringResource(R.string.fl_progress_parts, g.approved, g.total),
                    if (g.inReview > 0) stringResource(R.string.fl_progress_review, g.inReview) else null,
                ).joinToString("  ·  "),
                color = settingsMut(), fontSize = 12.sp,
            )
        }
        p.nextAction?.let { NextActionLine(it) }
    }
}

@Composable
private fun ProgressBar(done: Int, total: Int) {
    val accent = settingsAccent()
    Box(Modifier.fillMaxWidth().height(6.dp).clip(RoundedCornerShape(999.dp)).background(accent.copy(alpha = 0.15f))) {
        if (total > 0 && done > 0) Box(Modifier.fillMaxWidth((done.toFloat() / total).coerceAtMost(1f)).height(6.dp).clip(RoundedCornerShape(999.dp)).background(accent))
    }
}

@Composable
private fun NextActionLine(code: String) {
    FlNotice(stringResource(R.string.fl_next_label, nextActionLabel(code)), Icons.Rounded.NotificationsActive, Color(0xFFD97706))
}

@Composable
private fun nextActionLabel(code: String): String = stringResource(
    when (code) {
        "finish_draft" -> R.string.fl_next_finish_draft
        "submit" -> R.string.fl_next_submit
        "fix_project" -> R.string.fl_next_fix_project
        "review_offers" -> R.string.fl_next_review_offers
        "send_agreement" -> R.string.fl_next_send_agreement
        "review_delivery" -> R.string.fl_next_review_delivery
        "fund_milestone" -> R.string.fl_next_fund_milestone
        else -> R.string.fl_next_dispute
    },
)

@Composable
private fun budgetText(p: FreelanceProjectDto): String? {
    val b = p.budget
    if (b.minMinor == null) return null
    return if (b.type == "range" && b.maxMinor != null) "‎${money(b.minMinor)} – ${money(b.maxMinor)} ${b.currency}" else "‎${money(b.minMinor)} ${b.currency}"
}

// ---------------------------------------------------------------- one project

@Composable
private fun ProjectDetails(uuid: String, onBack: () -> Unit, onEdit: (String) -> Unit, onGone: () -> Unit, onPreview: () -> Unit, onCopied: (String) -> Unit, onOffers: () -> Unit, onOpenOffer: (String) -> Unit, onAgreement: () -> Unit, onInvitations: () -> Unit = {}) {
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    var project by remember(uuid) { mutableStateOf<FreelanceProjectDto?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var confirm by remember { mutableStateOf<String?>(null) }
    // FL-607: why the project is cancelled.
    var cancelCode by remember { mutableStateOf<String?>(null) }
    var cancelNote by remember { mutableStateOf("") }
    var capDialog by remember { mutableStateOf(false) }
    var capError by remember { mutableStateOf<String?>(null) }
    val capFailed = stringResource(R.string.fl_cost_cap_failed)

    LaunchedEffect(uuid, reconnect) {
        try {
            project = ApiClient.freelance.project(auth(), uuid).data
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message(generic)
        }
    }

    fun act(block: suspend () -> FreelanceProjectDto?, after: (() -> Unit)? = null, successNotice: String? = null) {
        busy = true
        error = null
        notice = null
        scope.launch {
            try {
                val result = block()
                if (result != null) project = result
                notice = successNotice
                after?.invoke()
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                error = e.message(generic)
            }
            busy = false
        }
    }

    /** The limit changes (or goes); a refusal is told inside the dialog, not under the page. */
    fun changeCap(block: suspend () -> FreelanceProjectDto?) {
        busy = true
        capError = null
        scope.launch {
            try {
                block()?.let { project = it }
                capDialog = false
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                capError = e.message(capFailed)
            }
            busy = false
        }
    }

    Column(Modifier.fillMaxSize()) {
        SubHeader(stringResource(R.string.fl_project), onBack)
        val p = project
        if (p == null) {
            FlLoading(error)
            return@Column
        }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Card {
                Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    Box(Modifier.size(48.dp).clip(RoundedCornerShape(15.dp)).background(statusColor(p.status).copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                        Icon(Icons.Rounded.Work, null, tint = statusColor(p.status), modifier = Modifier.size(24.dp))
                    }
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text(p.title?.takeIf { it.isNotBlank() } ?: stringResource(R.string.fl_untitled), color = settingsInk(), fontSize = 17.sp, fontWeight = FontWeight.ExtraBold)
                        Row { StatusPill(p.status) }
                    }
                }
                if (!p.statusReason.isNullOrBlank()) FlNotice(stringResource(R.string.fl_reviewer_note, p.statusReason), Icons.Rounded.Info, statusColor(p.status))
                if (p.status == "pending_review") FlNotice(stringResource(R.string.fl_under_review_hint), Icons.Rounded.HourglassTop, Color(0xFFD97706))
                if (p.status == "rejected") FlNotice(stringResource(R.string.fl_rejected_hint), Icons.Rounded.Block, Color(0xFFDC2626))
                p.nextAction?.let { NextActionLine(it) }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    FlStat(Icons.Rounded.Payments, stringResource(R.string.fl_budget), budgetText(p) ?: "—", Modifier.weight(1.4f))
                    FlStat(Icons.Rounded.Schedule, stringResource(R.string.fl_duration), p.durationDays?.let { stringResource(R.string.fl_days, it) } ?: "—", Modifier.weight(1f))
                    FlStat(Icons.Rounded.RequestQuote, stringResource(R.string.fl_offers), p.offers.count.toString(), Modifier.weight(0.8f))
                }
            }
            // FL-211: the stages of the work, each with where it stands.
            p.milestones?.takeIf { it.isNotEmpty() }?.let { stages ->
                Card(Icons.Rounded.Flag, stringResource(R.string.fl_stages)) {
                    p.progress?.let { g ->
                        ProgressBar(g.approved, g.total)
                        Text(
                            stringResource(R.string.fl_progress_parts, g.approved, g.total) + "  ·  " +
                                stringResource(R.string.fl_progress_money, "‎" + money(g.approvedMinor), money(g.priceMinor), p.budget.currency),
                            color = settingsMut(), fontSize = 12.sp,
                        )
                    }
                    stages.forEach { m ->
                        val mc = milestoneStatusColor(m.status)
                        Row(
                            Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(mc.copy(alpha = 0.07f)).padding(horizontal = 12.dp, vertical = 10.dp),
                            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp),
                        ) {
                            Text(m.position.toString(), color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.size(24.dp).clip(RoundedCornerShape(50)).background(mc).wrapContentSize(Alignment.Center))
                            Column(Modifier.weight(1f)) {
                                Text(m.title, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                                Text(
                                    "‎${money(m.amountMinor)} ${p.budget.currency}" + (m.durationDays?.let { "  ·  " + stringResource(R.string.fl_days, it) } ?: ""),
                                    color = settingsMut(), fontSize = 12.sp,
                                )
                            }
                            Text(milestoneStatusLabel(m.status), color = mc, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                        }
                    }
                }
            }
            // FL-401: the agreement with the chosen freelancer.
            p.agreement?.let { ag ->
                Card(Icons.Rounded.Handshake, stringResource(R.string.fl_agreement)) {
                    Text(agreementStatusLabel(ag.status), color = agreementStatusColor(ag.status), fontSize = 13.sp, fontWeight = FontWeight.Bold)
                    FlAction(stringResource(if (ag.status == "draft") R.string.fl_ag_open_draft else R.string.fl_ag_open), Icons.Rounded.Handshake, FlTone.Primary, onClick = onAgreement)
                }
            }
            // FL-205: a private project is filled by invitation.
            p.invitations?.takeIf { p.status == "published" }?.let { inv ->
                Card(Icons.Rounded.MarkEmailUnread, stringResource(R.string.fl_inv_title)) {
                    Text(stringResource(R.string.fl_inv_summary, inv.sent, inv.accepted, inv.declined), color = settingsMut(), fontSize = 13.sp)
                    FlAction(stringResource(if (inv.canInvite) R.string.fl_inv_manage else R.string.fl_inv_see), Icons.Rounded.PersonAdd, onClick = onInvitations)
                }
            }
            if (p.status == "published" || p.status == "awarded") Card(Icons.Rounded.RequestQuote, stringResource(R.string.fl_offers) + if (p.offers.count > 0) " (${p.offers.count})" else "") {
                FlNotice(
                    when {
                        p.status == "awarded" -> stringResource(R.string.fl_offers_closed_chosen)
                        p.offers.accepting -> stringResource(R.string.fl_offers_open, p.offers.deadlineAt?.take(10).orEmpty())
                        else -> stringResource(when (p.offers.closeReason) { "client" -> R.string.fl_offers_closed_client; "admin" -> R.string.fl_offers_closed_admin; "max_offers" -> R.string.fl_offers_closed_full; else -> R.string.fl_offers_closed_deadline })
                    },
                    if (p.offers.accepting) Icons.Rounded.CheckCircle else Icons.Rounded.Lock,
                    if (p.status == "awarded") Color(0xFF7C3AED) else if (p.offers.accepting) Color(0xFF16A34A) else Color(0xFFD97706),
                )
                // FL-305 / FL-309: compare them and choose one.
                p.offers.chosen?.let { chosen -> FlAction(stringResource(R.string.fl_see_chosen), Icons.Rounded.Star, FlTone.Primary) { onOpenOffer(chosen) } }
                if (p.offers.count > 0) FlAction(stringResource(if (p.status == "published") R.string.fl_compare_choose else R.string.fl_see_offers), Icons.Rounded.CompareArrows, onClick = onOffers)
                if (p.offers.canClose) FlAction(stringResource(R.string.fl_offers_close), Icons.Rounded.Lock, FlTone.Warn) { act({ ApiClient.freelance.closeOffers(auth(), p.uuid).data }) }
                if (p.offers.canReopen) FlAction(stringResource(R.string.fl_offers_reopen, p.offers.reopensLeft), Icons.Rounded.LockOpen) { act({ ApiClient.freelance.reopenOffers(auth(), p.uuid).data }) }
            }
            Card(Icons.Rounded.Article, stringResource(R.string.fl_description)) { ProjectSummary(p) }
            if (p.attachments.isNotEmpty()) Card(Icons.Rounded.AttachFile, stringResource(R.string.fl_files)) {
                p.attachments.forEach { a ->
                    Row(
                        Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.07f)).padding(horizontal = 12.dp, vertical = 9.dp),
                        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp),
                    ) {
                        Icon(Icons.Rounded.Description, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
                        Text(a.name, color = settingsInk(), fontSize = 13.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                }
            }
            if (p.missing.isNotEmpty()) FlNotice(stringResource(R.string.fl_missing, p.missing.map { missingLabel(it) }.joinToString("، ")), Icons.Rounded.Warning, Color(0xFFD97706))
            notice?.let { FlNotice(it, Icons.Rounded.CheckCircle, Color(0xFF16A34A)) }
            error?.let { FlNotice(it, Icons.Rounded.ErrorOutline, Color(0xFFDC2626)) }

            // FL-210: a last look before it goes out (it also says what doesn't add up).
            if (p.can.submit) FlAction(stringResource(R.string.fl_review_and_send), Icons.Rounded.Send, FlTone.Primary, enabled = p.missing.isEmpty(), loading = busy, onClick = onPreview)
            if (p.can.edit) FlAction(stringResource(R.string.fl_edit), Icons.Rounded.Edit) { onEdit(p.uuid) }
            if (p.can.setCostCap) FlAction(stringResource(if (p.maxCostMinor == null) R.string.fl_cost_cap_set else R.string.fl_cost_cap_change), Icons.Rounded.Savings) { capError = null; capDialog = true }
            // FL-209: ask for it again from this one.
            if (p.can.duplicate) FlAction(stringResource(R.string.fl_copy_project), Icons.Rounded.ContentCopy) {
                busy = true
                error = null
                scope.launch {
                    try {
                        ApiClient.freelance.duplicate(auth(), p.uuid).data?.let { onCopied(it.uuid) }
                    } catch (e: CancellationException) {
                        throw e
                    } catch (e: Exception) {
                        error = e.message(generic)
                    }
                    busy = false
                }
            }
            if (p.can.withdraw) FlAction(stringResource(R.string.fl_withdraw), Icons.Rounded.Undo, FlTone.Warn) { act({ ApiClient.freelance.withdraw(auth(), p.uuid).data }) }
            if (p.can.cancel) FlAction(stringResource(R.string.fl_cancel_project), Icons.Rounded.Cancel, FlTone.Danger) { cancelCode = null; cancelNote = ""; confirm = "cancel" }
            if (p.can.delete) FlAction(stringResource(R.string.fl_delete_draft), Icons.Rounded.DeleteForever, FlTone.Danger) { confirm = "delete" }

            p.timeline?.takeIf { it.isNotEmpty() }?.let { events ->
                Card(Icons.Rounded.History, stringResource(R.string.fl_history)) {
                    events.forEach { e ->
                        Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Box(Modifier.padding(top = 6.dp).size(7.dp).clip(RoundedCornerShape(50)).background(settingsAccent()))
                            Column {
                                Text(eventLabel(e.type), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
                                // The phone's own time zone (khaled1), in the new timeline layout (dev-test).
                                Text("‎${com.dorr.app.ui.screens.rides.localDateTime(e.at)}", color = settingsMut(), fontSize = 11.5.sp)
                            }
                        }
                    }
                }
            }
            Spacer(Modifier.height(20.dp))
        }
    }

    when (confirm) {
        "cancel" -> FlSheet(Icons.Rounded.Cancel, stringResource(R.string.fl_cancel_project), stringResource(R.string.fl_cancel_confirm), onDismiss = { if (!busy) confirm = null }) {
            CancelReasonPicker(cancelCode, cancelNote, { cancelCode = it }, { cancelNote = it })
            FlAction(stringResource(R.string.fl_cancel_project), Icons.Rounded.Cancel, FlTone.Danger, enabled = cancelReasonReady(cancelCode, cancelNote), loading = busy) {
                act({ ApiClient.freelance.cancel(auth(), uuid, FreelanceCancelRequest(cancelNote.trim().ifEmpty { null }, cancelCode)).data }, after = { confirm = null })
            }
            FlAction(stringResource(R.string.fl_not_now), null) { confirm = null }
        }
        "delete" -> ConfirmDialog(
            icon = Icons.Rounded.DeleteForever, title = stringResource(R.string.fl_delete_draft), message = stringResource(R.string.fl_delete_confirm),
            loading = busy, onDismiss = { confirm = null },
            onConfirm = { act({ ApiClient.freelance.delete(auth(), uuid); null }, after = { confirm = null; onGone() }) },
        )
    }
    if (capDialog) project?.let { p ->
        CostCapDialog(
            current = p.maxCostMinor, currency = p.budget.currency, busy = busy, error = capError, onDismiss = { capDialog = false },
            onSave = { v -> changeCap { ApiClient.freelance.setCostCap(auth(), uuid, FreelanceCostCapRequest(v)).data } },
            onRemove = { changeCap { ApiClient.freelance.removeCostCap(auth(), uuid).data } },
        )
    }
}

/** What the project says, as the freelancers read it (the details page and the last look share it). */
@Composable
private fun ProjectSummary(p: FreelanceProjectDto) {
    Field(stringResource(R.string.fl_description), p.description)
    Field(stringResource(R.string.fl_specialty), p.specialty?.name)
    Field(stringResource(R.string.fl_skills), p.skills.joinToString("، ") { it.name }.ifBlank { null })
    Field(stringResource(R.string.fl_budget), budgetText(p))
    if (p.maxCostMinor != null || p.can.setCostCap) {
        Field(
            stringResource(R.string.fl_cost_cap),
            p.maxCostMinor?.let { stringResource(R.string.fl_cost_cap_value, "‎" + money(it), p.budget.currency) } ?: stringResource(R.string.fl_cost_cap_none),
        )
    }
    Field(stringResource(R.string.fl_duration), p.durationDays?.let { stringResource(R.string.fl_days, it) })
    Field(stringResource(R.string.fl_visibility), stringResource(if (p.visibility == "private") R.string.fl_private else R.string.fl_public))
    if (p.questions.isNotEmpty()) Field(stringResource(R.string.fl_questions), p.questions.mapIndexed { i, q -> "${i + 1}. $q" }.joinToString("\n"))
}

/** The most the client will pay in all (FL-210): typed once, lifted with one tap. */
@Composable
private fun CostCapDialog(current: Long?, currency: String, busy: Boolean, error: String?, onDismiss: () -> Unit, onSave: (Long) -> Unit, onRemove: () -> Unit) {
    var text by rememberSaveable { mutableStateOf(money(current)) }
    var localError by remember { mutableStateOf<String?>(null) }
    val invalid = stringResource(R.string.fl_budget_invalid)
    val focus = LocalFocusManager.current
    val keyboard = LocalSoftwareKeyboardController.current
    FlSheet(Icons.Rounded.Savings, stringResource(R.string.fl_cost_cap), stringResource(R.string.fl_cost_cap_hint), onDismiss = { if (!busy) onDismiss() }) {
        DorrTextField(text, { text = it }, label = stringResource(R.string.fl_cost_cap_field, currency), icon = Icons.Rounded.Payments, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.fillMaxWidth())
        (localError ?: error)?.let { FlNotice(it, Icons.Rounded.ErrorOutline, Color(0xFFDC2626)) }
        FlAction(stringResource(R.string.fl_cost_cap_save), Icons.Rounded.Check, FlTone.Primary, enabled = !busy) {
            val v = parseMoney(text)
            if (v == null || v <= 0) localError = invalid else {
                localError = null
                focus.clearFocus()
                keyboard?.hide()
                onSave(v)
            }
        }
        if (current != null) FlAction(stringResource(R.string.fl_cost_cap_remove), Icons.Rounded.Delete, FlTone.Danger, enabled = !busy, onClick = onRemove)
    }
}

// ---------------------------------------------------------------- the last look (FL-210)

@Composable
private fun PreviewPage(uuid: String, onBack: () -> Unit, onEdit: () -> Unit, onSent: () -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    var preview by remember { mutableStateOf<FreelancePreviewDto?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var capDialog by remember { mutableStateOf(false) }
    var capError by remember { mutableStateOf<String?>(null) }
    val capFailed = stringResource(R.string.fl_cost_cap_failed)

    suspend fun load() {
        try {
            preview = ApiClient.freelance.preview(auth(), uuid).data
            error = null
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message(generic)
        }
    }
    LaunchedEffect(uuid, reconnect) { load() }

    /** The limit changes (or goes), then the look is taken again. */
    fun changeCap(block: suspend () -> Unit) {
        busy = true
        capError = null
        scope.launch {
            try {
                block()
                capDialog = false
                load()
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                capError = e.message(capFailed)
            }
            busy = false
        }
    }

    Column(Modifier.fillMaxSize()) {
        SubHeader(stringResource(R.string.fl_preview_title), onBack)
        val pv = preview
        if (pv == null) {
            FlLoading(error)
            return@Column
        }
        val p = pv.project
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            FlIntro(Icons.Rounded.FactCheck, p.title?.takeIf { it.isNotBlank() } ?: stringResource(R.string.fl_untitled), stringResource(R.string.fl_preview_intro))
            Card(Icons.Rounded.Article, stringResource(R.string.fl_project)) {
                ProjectSummary(p)
                if (p.attachments.isNotEmpty()) Field(stringResource(R.string.fl_files), p.attachments.joinToString("، ") { it.name })
            }
            val errors = pv.issues.filter { it.severity == "error" }
            val warnings = pv.issues.filter { it.severity != "error" }
            if (pv.issues.isEmpty()) FlNotice(stringResource(R.string.fl_preview_ok), Icons.Rounded.CheckCircle, Color(0xFF16A34A))
            if (errors.isNotEmpty()) IssuesCard(stringResource(R.string.fl_preview_errors), errors, Color(0xFFDC2626))
            if (warnings.isNotEmpty()) IssuesCard(stringResource(R.string.fl_preview_warnings), warnings, Color(0xFFD97706))
            error?.let { FlNotice(it, Icons.Rounded.ErrorOutline, Color(0xFFDC2626)) }
            // The limit is the one thing that can be fixed from here.
            if (p.can.setCostCap && pv.issues.any { it.field == "max_cost" }) FlAction(stringResource(R.string.fl_cost_cap_change), Icons.Rounded.Savings) { capError = null; capDialog = true }
            FlAction(
                stringResource(R.string.fl_preview_send), Icons.Rounded.Send, FlTone.Primary, enabled = pv.canSubmit, loading = busy,
                onClick = {
                    busy = true
                    error = null
                    scope.launch {
                        try {
                            ApiClient.freelance.submit(auth(), uuid)
                            onSent()
                        } catch (e: CancellationException) {
                            throw e
                        } catch (e: Exception) {
                            error = e.message(generic)
                        }
                        busy = false
                    }
                },
            )
            FlAction(stringResource(R.string.fl_preview_fix), Icons.Rounded.Edit, onClick = onEdit)
            Spacer(Modifier.height(20.dp))
        }
    }

    if (capDialog) preview?.project?.let { p ->
        CostCapDialog(
            current = p.maxCostMinor, currency = p.budget.currency, busy = busy, error = capError, onDismiss = { capDialog = false },
            onSave = { v -> changeCap { ApiClient.freelance.setCostCap(auth(), uuid, FreelanceCostCapRequest(v)) } },
            onRemove = { changeCap { ApiClient.freelance.removeCostCap(auth(), uuid) } },
        )
    }
}

@Composable
private fun IssuesCard(title: String, issues: List<FreelanceIssueDto>, color: Color) {
    Card(if (color == Color(0xFFDC2626)) Icons.Rounded.ErrorOutline else Icons.Rounded.Warning, title) {
        issues.forEach { i -> FlNotice(i.message, if (color == Color(0xFFDC2626)) Icons.Rounded.ErrorOutline else Icons.Rounded.Warning, color) }
    }
}

@Composable
private fun missingLabel(field: String): String = when (field) {
    "title" -> stringResource(R.string.fl_title)
    "description" -> stringResource(R.string.fl_description)
    "specialty" -> stringResource(R.string.fl_specialty)
    "skills" -> stringResource(R.string.fl_skills)
    "budget" -> stringResource(R.string.fl_budget)
    else -> stringResource(R.string.fl_duration)
}

@Composable
private fun eventLabel(type: String): String = when (type) {
    "project.created" -> stringResource(R.string.fl_ev_created)
    "project.submitted" -> stringResource(R.string.fl_ev_submitted)
    "project.resubmitted" -> stringResource(R.string.fl_ev_resubmitted)
    "project.withdrawn" -> stringResource(R.string.fl_ev_withdrawn)
    "project.cost_cap_set" -> stringResource(R.string.fl_ev_cost_cap)
    "project.completed" -> stringResource(R.string.fl_ev_completed)
    "project.invited" -> stringResource(R.string.fl_ev_invited)
    "project.made_public" -> stringResource(R.string.fl_ev_made_public)
    "project.cancelled" -> stringResource(R.string.fl_ev_cancelled)
    "project.edited_by_admin" -> stringResource(R.string.fl_ev_edited_by_admin)
    "project.offers_closed" -> stringResource(R.string.fl_ev_offers_closed)
    "project.offers_reopened" -> stringResource(R.string.fl_ev_offers_reopened)
    "project.awarded" -> stringResource(R.string.fl_ev_awarded)
    "project.started" -> stringResource(R.string.fl_ev_started)
    "project.reopened" -> stringResource(R.string.fl_ev_reopened)
    "project.published" -> stringResource(R.string.fl_status_published)
    "project.rejected" -> stringResource(R.string.fl_status_rejected)
    "project.changes_requested" -> stringResource(R.string.fl_status_changes_requested)
    else -> type
}

@Composable
internal fun Card(icon: androidx.compose.ui.graphics.vector.ImageVector? = null, title: String? = null, content: @Composable () -> Unit) {
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        if (title != null) {
            if (icon != null) FlHeading(icon, title) else Label(title)
        }
        content()
    }
}

@Composable
internal fun Label(text: String) {
    Text(text, color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
}

@Composable
internal fun Field(label: String, value: String?) {
    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Text(label, color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
        Text(value?.takeIf { it.isNotBlank() } ?: "—", color = settingsInk(), fontSize = 14.sp, lineHeight = 22.sp)
    }
}

@Composable
internal fun TextAction(text: String, danger: Boolean = false, icon: androidx.compose.ui.graphics.vector.ImageVector? = null, onClick: () -> Unit) {
    FlAction(text, icon, if (danger) FlTone.Danger else FlTone.Soft, onClick = onClick)
}

// ---------------------------------------------------------------- the form

private val NamedSaver = listSaver<FreelanceNamedDto?, Any>(
    save = { if (it == null) emptyList() else listOf(it.id, it.name) },
    restore = { if (it.isEmpty()) null else FreelanceNamedDto(it[0] as Int, it[1] as String) },
)

private val NamedListSaver = listSaver<androidx.compose.runtime.snapshots.SnapshotStateList<FreelanceNamedDto>, Any>(
    save = { list -> list.flatMap { listOf<Any>(it.id, it.name) } },
    restore = { flat -> mutableStateListOf<FreelanceNamedDto>().also { l -> flat.chunked(2).forEach { l.add(FreelanceNamedDto(it[0] as Int, it[1] as String)) } } },
)

private val TextListSaver = listSaver<androidx.compose.runtime.snapshots.SnapshotStateList<String>, String>(
    save = { it.toList() },
    restore = { saved -> mutableStateListOf<String>().also { it.addAll(saved) } },
)

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun ProjectForm(uuid: String?, onBack: () -> Unit, onSaved: (String) -> Unit) {
    val context = LocalContext.current
    val generic = stringResource(R.string.fl_error_generic)
    val badBudget = stringResource(R.string.fl_budget_invalid)
    val scope = rememberCoroutineScope()

    var current by remember { mutableStateOf(uuid) }
    var loaded by remember { mutableStateOf(uuid == null) }
    var title by rememberSaveable { mutableStateOf("") }
    var description by rememberSaveable { mutableStateOf("") }
    // Saveable: the system file picker can recreate the activity, and the choices must come back with the text fields.
    var specialty by rememberSaveable(stateSaver = NamedSaver) { mutableStateOf<FreelanceNamedDto?>(null) }
    val skills = rememberSaveable(saver = NamedListSaver) { mutableStateListOf<FreelanceNamedDto>() }
    var budgetType by rememberSaveable { mutableStateOf("fixed") }
    var budgetMin by rememberSaveable { mutableStateOf("") }
    var budgetMax by rememberSaveable { mutableStateOf("") }
    // FL-210: the most I pay in all (optional); a limit that was set and is emptied is lifted on save.
    var maxCost by rememberSaveable { mutableStateOf("") }
    var duration by rememberSaveable { mutableStateOf("") }
    var visibility by rememberSaveable { mutableStateOf("public") }
    val questions = rememberSaveable(saver = TextListSaver) { mutableStateListOf<String>() }
    var attachments by remember { mutableStateOf(emptyList<com.dorr.app.network.FreelanceAttachmentDto>()) }
    var rules by remember { mutableStateOf<FreelanceBudgetRulesDto?>(null) }
    var pickSpecialty by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var uploading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(uuid) {
        if (uuid == null) return@LaunchedEffect
        try {
            ApiClient.freelance.project(auth(), uuid).data?.let { p ->
                title = p.title.orEmpty()
                description = p.description.orEmpty()
                specialty = p.specialty
                skills.clear(); skills.addAll(p.skills)
                budgetType = p.budget.type
                budgetMin = money(p.budget.minMinor)
                budgetMax = money(p.budget.maxMinor)
                maxCost = money(p.maxCostMinor)
                duration = p.durationDays?.toString().orEmpty()
                visibility = p.visibility
                questions.clear(); questions.addAll(p.questions)
                attachments = p.attachments
            }
            loaded = true
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message(generic)
        }
    }
    // The budget limits follow the specialty (its own rule wins over the country's).
    LaunchedEffect(specialty?.id) {
        rules = runCatching { ApiClient.freelance.budgetRules(auth(), specialty?.id).data }.getOrNull()
    }

    fun request(): FreelanceProjectRequest? {
        val min = budgetMin.takeIf { it.isNotBlank() }?.let { parseMoney(it) ?: return null }
        val max = if (budgetType == "range") budgetMax.takeIf { it.isNotBlank() }?.let { parseMoney(it) ?: return null } else null
        val cap = maxCost.takeIf { it.isNotBlank() }?.let { parseMoney(it)?.takeIf { v -> v > 0 } ?: return null }
        return FreelanceProjectRequest(
            title = title.trim().ifBlank { null },
            description = description.trim().ifBlank { null },
            specialtyId = specialty?.id,
            skillIds = skills.map { it.id },
            budgetType = budgetType,
            budgetMinMinor = min,
            budgetMaxMinor = max,
            maxCostMinor = cap,
            durationDays = duration.toIntOrNull(),
            visibility = visibility,
            questions = questions.map { it.trim() }.filter { it.isNotEmpty() },
        )
    }

    /** Saves the draft; [then] gets the project's uuid (a new draft gets one on its first save). */
    fun save(then: (String) -> Unit) {
        val body = request() ?: run { error = badBudget; return }
        busy = true
        error = null
        scope.launch {
            try {
                val id = current
                val saved = if (id == null) ApiClient.freelance.create(auth(), body).data else ApiClient.freelance.update(auth(), id, body).data
                if (saved != null) {
                    current = saved.uuid
                    attachments = saved.attachments
                    // A blank field can't say "none" in the body (empty fields are left out), so lifting the limit is its own call.
                    if (maxCost.isBlank() && saved.maxCostMinor != null) ApiClient.freelance.removeCostCap(auth(), saved.uuid)
                    then(saved.uuid)
                }
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                error = e.message(generic)
            }
            busy = false
        }
    }

    val picker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        if (uri == null) return@rememberLauncherForActivityResult
        // A file goes on a saved draft: save first if this is a new one.
        save { id ->
            uploading = true
            scope.launch {
                try {
                    val part = filePart(context, uri) ?: throw IllegalStateException()
                    ApiClient.freelance.addAttachment(auth(), id, part).data?.let { attachments = it.attachments }
                } catch (e: CancellationException) {
                    throw e
                } catch (e: Exception) {
                    error = e.message(generic)
                }
                uploading = false
            }
        }
    }

    Column(Modifier.fillMaxSize()) {
        SubHeader(stringResource(if (uuid == null) R.string.fl_new_project else R.string.fl_edit), onBack)
        if (!loaded) {
            FlLoading(error)
            return@Column
        }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).imePadding().padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(18.dp)) {
            FlIntro(Icons.Rounded.Work, stringResource(R.string.fl_form_intro_title), stringResource(R.string.fl_form_intro))
            Column(verticalArrangement = Arrangement.spacedBy(14.dp)) {
                DorrTextField(title, { title = it.take(120) }, label = stringResource(R.string.fl_title), placeholder = stringResource(R.string.fl_title_hint), icon = Icons.Rounded.Title, modifier = Modifier.fillMaxWidth())
                DorrTextField(description, { description = it.take(5000) }, label = stringResource(R.string.fl_description), placeholder = stringResource(R.string.fl_description_hint), icon = Icons.Rounded.Notes, singleLine = false, minLines = 5, modifier = Modifier.fillMaxWidth())
            }
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                FlHeading(Icons.Rounded.Category, stringResource(R.string.fl_specialty))
                DorrTextField(specialty?.name.orEmpty(), {}, placeholder = stringResource(R.string.fl_pick_specialty), icon = Icons.Rounded.Category, onClick = { pickSpecialty = true }, modifier = Modifier.fillMaxWidth())
                FlHeading(Icons.Rounded.Bolt, stringResource(R.string.fl_skills))
                SkillPicker(skills, specialty?.id)
            }
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                FlHeading(Icons.Rounded.Payments, stringResource(R.string.fl_budget))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Chip(stringResource(R.string.fl_budget_fixed), budgetType == "fixed", Icons.Rounded.Lock) { budgetType = "fixed" }
                    Chip(stringResource(R.string.fl_budget_range), budgetType == "range", Icons.Rounded.SyncAlt) { budgetType = "range" }
                }
                val currency = rules?.currency.orEmpty()
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    DorrTextField(
                        budgetMin, { budgetMin = it }, label = stringResource(if (budgetType == "range") R.string.fl_budget_from else R.string.fl_budget_amount, currency), icon = Icons.Rounded.Payments,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.weight(1f),
                    )
                    if (budgetType == "range") DorrTextField(
                        budgetMax, { budgetMax = it }, label = stringResource(R.string.fl_budget_to, currency), icon = Icons.Rounded.Payments,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.weight(1f),
                    )
                }
                rules?.let { r ->
                    val parts = listOfNotNull(
                        r.minMinor?.let { stringResource(R.string.fl_budget_min_rule, money(it), r.currency) },
                        r.maxMinor?.let { stringResource(R.string.fl_budget_max_rule, money(it), r.currency) },
                    )
                    if (parts.isNotEmpty()) Text("‎" + parts.joinToString(" · "), color = settingsMut(), fontSize = 12.sp)
                }
                // FL-210: the most I will pay in all.
                DorrTextField(
                    maxCost, { maxCost = it }, label = stringResource(R.string.fl_cost_cap_field, currency), icon = Icons.Rounded.Savings,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.fillMaxWidth(),
                )
                Text(stringResource(R.string.fl_cost_cap_hint), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp)
            }
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                DorrTextField(duration, { duration = it.filter(Char::isDigit).take(3) }, label = stringResource(R.string.fl_duration_days), icon = Icons.Rounded.Schedule, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth())
                FlHeading(Icons.Rounded.Visibility, stringResource(R.string.fl_visibility))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Chip(stringResource(R.string.fl_public), visibility == "public", Icons.Rounded.Public) { visibility = "public" }
                    Chip(stringResource(R.string.fl_private), visibility == "private", Icons.Rounded.Lock) { visibility = "private" }
                }
                FlNotice(stringResource(if (visibility == "private") R.string.fl_private_hint else R.string.fl_public_hint), Icons.Rounded.Info, settingsAccent())
            }
            // FL-307: questions every freelancer answers with their offer.
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                FlHeading(Icons.Rounded.QuestionAnswer, stringResource(R.string.fl_questions), stringResource(R.string.fl_questions_hint))
                questions.forEachIndexed { i, q ->
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        DorrTextField(q, { questions[i] = it.take(300) }, placeholder = stringResource(R.string.fl_question_n, i + 1), icon = Icons.Rounded.QuestionAnswer, modifier = Modifier.weight(1f))
                        Icon(Icons.Rounded.Close, stringResource(R.string.fl_remove), tint = settingsMut(), modifier = Modifier.size(34.dp).clip(RoundedCornerShape(50)).clickable { questions.removeAt(i) }.padding(7.dp))
                    }
                }
                if (questions.size < 5) FlAction(stringResource(R.string.fl_add_question), Icons.Rounded.Add) { questions.add("") }
            }
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                FlHeading(Icons.Rounded.AttachFile, stringResource(R.string.fl_files), stringResource(R.string.fl_files_hint))
                attachments.forEach { a ->
                    Row(
                        Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.07f)).padding(horizontal = 12.dp, vertical = 8.dp),
                        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp),
                    ) {
                        Icon(Icons.Rounded.Description, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
                        Text(a.name, color = settingsInk(), fontSize = 13.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                        Icon(
                            Icons.Rounded.Close, stringResource(R.string.fl_remove), tint = settingsMut(),
                            modifier = Modifier.size(28.dp).clip(RoundedCornerShape(50)).clickable {
                                val id = current ?: return@clickable
                                scope.launch {
                                    try {
                                        ApiClient.freelance.removeAttachment(auth(), id, a.id).data?.let { attachments = it.attachments }
                                    } catch (e: CancellationException) {
                                        throw e
                                    } catch (e: Exception) {
                                        error = e.message(generic)
                                    }
                                }
                            }.padding(5.dp),
                        )
                    }
                }
                FlAction(stringResource(R.string.fl_add_file), Icons.Rounded.AttachFile, loading = uploading) { picker.launch("*/*") }
            }
            error?.let { FlNotice(it, Icons.Rounded.ErrorOutline, Color(0xFFDC2626)) }
            Spacer(Modifier.height(8.dp))
        }
        SettingsPrimaryButton(text = stringResource(R.string.fl_save_draft), loading = busy, modifier = Modifier.padding(16.dp), onClick = { save(onSaved) })
    }

    if (pickSpecialty) SpecialtyDialog(onDismiss = { pickSpecialty = false }) { picked ->
        specialty = picked
        pickSpecialty = false
    }
}

/** The file as a multipart part named `file`, with its own name and type. */
private fun filePart(context: Context, uri: Uri): MultipartBody.Part? {
    val resolver = context.contentResolver
    val name = resolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME), null, null, null)?.use { c -> if (c.moveToFirst()) c.getString(0) else null } ?: "file"
    val type = resolver.getType(uri) ?: "application/octet-stream"
    val bytes = resolver.openInputStream(uri)?.use { it.readBytes() } ?: return null
    return MultipartBody.Part.createFormData("file", name, bytes.toRequestBody(type.toMediaTypeOrNull()))
}

/**
 * Picked skills as removable pills, and under them the skills: with nothing typed, the ones of the project's specialty (as soon as one is
 * chosen); from two letters on, a search over the whole active catalog (400 ms after the last key).
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun SkillPicker(selected: MutableList<FreelanceNamedDto>, specialtyId: Int?) {
    var query by remember { mutableStateOf("") }
    var results by remember { mutableStateOf(emptyList<FreelanceNamedDto>()) }
    LaunchedEffect(query, specialtyId) {
        val term = query.trim()
        if (term.length < 2) {
            if (term.isNotEmpty() || specialtyId == null) {
                results = emptyList()
                return@LaunchedEffect
            }
            results = runCatching { ApiClient.freelance.skills(auth(), null, specialtyId = specialtyId).data.orEmpty().map { FreelanceNamedDto(it.id, it.name) } }.getOrDefault(emptyList())
            return@LaunchedEffect
        }
        delay(400)
        results = runCatching { ApiClient.freelance.skills(auth(), term).data.orEmpty().map { FreelanceNamedDto(it.id, it.name) } }.getOrDefault(emptyList())
    }
    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        selected.forEach { s ->
            Row(
                Modifier.clip(RoundedCornerShape(999.dp)).background(settingsAccent()).clickable { selected.remove(s) }.padding(horizontal = 12.dp, vertical = 6.dp),
                verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp),
            ) {
                Text(s.name, color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                Icon(Icons.Rounded.Close, null, tint = Color.White, modifier = Modifier.size(14.dp))
            }
        }
    }
    if (selected.size < 10) {
        DorrTextField(query, { query = it }, placeholder = stringResource(R.string.fl_search_skills), icon = Icons.Rounded.Search, modifier = Modifier.fillMaxWidth())
        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            results.filter { r -> selected.none { it.id == r.id } }.take(20).forEach { r ->
                Row(
                    Modifier.clip(RoundedCornerShape(999.dp)).border(1.dp, settingsAccent().copy(alpha = 0.4f), RoundedCornerShape(999.dp))
                        .clickable { selected.add(r); query = "" }.padding(horizontal = 12.dp, vertical = 6.dp),
                    verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp),
                ) {
                    Icon(Icons.Rounded.Add, null, tint = settingsAccent(), modifier = Modifier.size(14.dp))
                    Text(r.name, color = settingsInk(), fontSize = 12.sp)
                }
            }
        }
    }
}

/** The specialty tree, read once: a parent is a heading, its children are picked (a parent without children is picked itself). */
@Composable
private fun SpecialtyDialog(onDismiss: () -> Unit, onPick: (FreelanceNamedDto) -> Unit) {
    var tree by remember { mutableStateOf<List<FreelanceSpecialtyNodeDto>?>(null) }
    LaunchedEffect(Unit) { tree = runCatching { ApiClient.freelance.specialties(auth()).data.orEmpty() }.getOrDefault(emptyList()) }
    FlSheet(Icons.Rounded.Category, stringResource(R.string.fl_pick_specialty), "", onDismiss = onDismiss) {
        val nodes = tree
        if (nodes == null) {
            repeat(5) { com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().height(40.dp), RoundedCornerShape(12.dp)) }
        } else {
            nodes.forEach { parent ->
                if (parent.children.isEmpty()) {
                    PickRow(parent.name, bold = true) { onPick(FreelanceNamedDto(parent.id, parent.name)) }
                } else {
                    Text(parent.name, color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 6.dp))
                    parent.children.forEach { child -> PickRow(child.name, bold = false) { onPick(FreelanceNamedDto(child.id, child.name)) } }
                }
            }
        }
    }
}

@Composable
private fun PickRow(text: String, bold: Boolean, onClick: () -> Unit) {
    Text(
        text, color = settingsInk(), fontSize = 14.sp, fontWeight = if (bold) FontWeight.Bold else FontWeight.Normal,
        modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.06f)).clickable(onClick = onClick).padding(vertical = 12.dp, horizontal = 12.dp),
    )
}
