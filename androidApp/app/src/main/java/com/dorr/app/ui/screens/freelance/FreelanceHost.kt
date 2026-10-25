package com.dorr.app.ui.screens.freelance

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
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
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
        "agreement", "invites" -> "show:" + parts[1]
        "dispute", "dispute-new" -> "agreement:" + parts[1]
        else -> "list"
    }
    BackHandler {
        if (page == "list") onExit() else page = back()
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
                onOffers = { page = "offers:" + page.removePrefix("show:") },
                onOpenOffer = { page = "offer:" + page.removePrefix("show:") + ":" + it },
                onAgreement = { page = "agreement:" + page.removePrefix("show:") },
                onInvitations = { page = "invites:" + page.removePrefix("show:") },
            )
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
internal fun Chip(text: String, selected: Boolean, onClick: () -> Unit) {
    val accent = settingsAccent()
    Text(
        text, color = if (selected) Color.White else settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
        modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(if (selected) accent else accent.copy(alpha = 0.08f))
            .clickable(onClick = onClick).padding(horizontal = 14.dp, vertical = 7.dp),
    )
}

@Composable
private fun ProjectList(version: Int, onExit: () -> Unit, onNew: () -> Unit, onOpen: (String) -> Unit, onFavorites: () -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    var filter by rememberSaveable { mutableStateOf<String?>(null) }
    val rows = remember { mutableStateListOf<FreelanceProjectDto>() }
    var page by remember { mutableStateOf(1) }
    var hasMore by remember { mutableStateOf(false) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }

    suspend fun load(next: Int) {
        loading = true
        try {
            val envelope = ApiClient.freelance.projects(auth(), status = filter, page = next)
            if (next == 1) rows.clear()
            rows.addAll(envelope.data.orEmpty())
            page = next
            hasMore = envelope.pagination?.hasMorePages == true
            error = null
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message(generic)
        }
        loading = false
    }
    LaunchedEffect(filter, reconnect, version) { load(1) }

    Column(Modifier.fillMaxSize()) {
        SubHeader(stringResource(R.string.fl_my_projects), onExit)
        Row(Modifier.horizontalScroll(rememberScrollState()).padding(horizontal = 14.dp, vertical = 4.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            FILTERS.forEach { f -> Chip(filterLabel(f), selected = filter == f) { filter = f } }
        }
        Box(Modifier.padding(horizontal = 16.dp)) { TextAction("♥  " + stringResource(R.string.fl_favorites)) { onFavorites() } }
        LazyColumn(
            Modifier.weight(1f).fillMaxWidth(),
            contentPadding = androidx.compose.foundation.layout.PaddingValues(horizontal = 16.dp, vertical = 10.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            if (!loading && rows.isEmpty() && error == null) item {
                Column(Modifier.fillMaxWidth().padding(top = 60.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                    PinkIcon(Icons.Rounded.Work, box = 64.dp)
                    Spacer(Modifier.height(14.dp))
                    Text(stringResource(R.string.fl_empty_title), color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.Bold)
                    Text(stringResource(R.string.fl_empty_text), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.padding(top = 6.dp))
                }
            }
            items(rows, key = { it.uuid }) { p -> ProjectCard(p) { onOpen(p.uuid) } }
            if (hasMore) item {
                TextButton(onClick = { scope.launch { load(page + 1) } }, modifier = Modifier.fillMaxWidth()) {
                    Text(stringResource(R.string.fl_show_more), color = settingsAccent(), fontWeight = FontWeight.Bold)
                }
            }
            if (loading) item { Box(Modifier.fillMaxWidth().padding(12.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(24.dp)) } }
            error?.let { item { Text(it, color = Color(0xFFDC2626), fontSize = 13.sp) } }
        }
        SettingsPrimaryButton(text = stringResource(R.string.fl_new_project), modifier = Modifier.padding(16.dp), onClick = onNew)
    }
}

@Composable
private fun ProjectCard(p: FreelanceProjectDto, onClick: () -> Unit) {
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp)).clickable(onClick = onClick).padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(
                p.title?.takeIf { it.isNotBlank() } ?: stringResource(R.string.fl_untitled), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.Bold,
                maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f),
            )
            Spacer(Modifier.width(8.dp))
            StatusPill(p.status)
        }
        p.specialty?.let { Text(it.name, color = settingsMut(), fontSize = 12.sp) }
        budgetText(p)?.let { Text(it, color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold) }
    }
}

@Composable
private fun budgetText(p: FreelanceProjectDto): String? {
    val b = p.budget
    if (b.minMinor == null) return null
    return if (b.type == "range" && b.maxMinor != null) "‎${money(b.minMinor)} – ${money(b.maxMinor)} ${b.currency}" else "‎${money(b.minMinor)} ${b.currency}"
}

// ---------------------------------------------------------------- one project

@Composable
private fun ProjectDetails(uuid: String, onBack: () -> Unit, onEdit: (String) -> Unit, onGone: () -> Unit, onOffers: () -> Unit, onOpenOffer: (String) -> Unit, onAgreement: () -> Unit, onInvitations: () -> Unit = {}) {
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    var project by remember { mutableStateOf<FreelanceProjectDto?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var confirm by remember { mutableStateOf<String?>(null) }

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

    val sentText = stringResource(R.string.fl_sent_for_review)
    Column(Modifier.fillMaxSize()) {
        SubHeader(stringResource(R.string.fl_project), onBack)
        val p = project
        if (p == null) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                if (error != null) Text(error.orEmpty(), color = settingsMut(), fontSize = 14.sp, modifier = Modifier.padding(24.dp))
                else CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(28.dp))
            }
            return@Column
        }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Card {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(p.title?.takeIf { it.isNotBlank() } ?: stringResource(R.string.fl_untitled), color = settingsInk(), fontSize = 17.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                    StatusPill(p.status)
                }
                if (!p.statusReason.isNullOrBlank()) {
                    Text(stringResource(R.string.fl_reviewer_note, p.statusReason), color = statusColor(p.status), fontSize = 13.sp)
                }
                if (p.status == "pending_review") Text(stringResource(R.string.fl_under_review_hint), color = settingsMut(), fontSize = 12.sp)
                if (p.status == "rejected") Text(stringResource(R.string.fl_rejected_hint), color = settingsMut(), fontSize = 12.sp)
            }
            Card {
                Field(stringResource(R.string.fl_description), p.description)
                Field(stringResource(R.string.fl_specialty), p.specialty?.name)
                Field(stringResource(R.string.fl_skills), p.skills.joinToString("، ") { it.name }.ifBlank { null })
                Field(stringResource(R.string.fl_budget), budgetText(p))
                Field(stringResource(R.string.fl_duration), p.durationDays?.let { stringResource(R.string.fl_days, it) })
                Field(stringResource(R.string.fl_visibility), stringResource(if (p.visibility == "private") R.string.fl_private else R.string.fl_public))
                if (p.questions.isNotEmpty()) Field(stringResource(R.string.fl_questions), p.questions.mapIndexed { i, q -> "${i + 1}. $q" }.joinToString("\n"))
            }
            // FL-401: the agreement with the chosen freelancer.
            p.agreement?.let { ag ->
                Card {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Label(stringResource(R.string.fl_agreement))
                        Spacer(Modifier.weight(1f))
                        Text(agreementStatusLabel(ag.status), color = agreementStatusColor(ag.status), fontSize = 12.sp, fontWeight = FontWeight.Bold)
                    }
                    TextAction(stringResource(if (ag.status == "draft") R.string.fl_ag_open_draft else R.string.fl_ag_open)) { onAgreement() }
                }
            }
            // FL-205: a private project is filled by invitation.
            p.invitations?.takeIf { p.status == "published" }?.let { inv ->
                Card {
                    Label(stringResource(R.string.fl_inv_title))
                    Text(stringResource(R.string.fl_inv_summary, inv.sent, inv.accepted, inv.declined), color = settingsMut(), fontSize = 13.sp)
                    TextAction(stringResource(if (inv.canInvite) R.string.fl_inv_manage else R.string.fl_inv_see)) { onInvitations() }
                }
            }
            if (p.status == "published" || p.status == "awarded") Card {
                Label(stringResource(R.string.fl_offers) + if (p.offers.count > 0) " (${p.offers.count})" else "")
                Text(
                    when {
                        p.status == "awarded" -> stringResource(R.string.fl_offers_closed_chosen)
                        p.offers.accepting -> stringResource(R.string.fl_offers_open, p.offers.deadlineAt?.take(10).orEmpty())
                        else -> stringResource(when (p.offers.closeReason) { "client" -> R.string.fl_offers_closed_client; "admin" -> R.string.fl_offers_closed_admin; "max_offers" -> R.string.fl_offers_closed_full; else -> R.string.fl_offers_closed_deadline })
                    },
                    color = if (p.status == "awarded") Color(0xFF7C3AED) else if (p.offers.accepting) Color(0xFF16A34A) else Color(0xFFD97706), fontSize = 13.sp, fontWeight = FontWeight.SemiBold,
                )
                // FL-305 / FL-309: compare them and choose one.
                p.offers.chosen?.let { chosen -> TextAction(stringResource(R.string.fl_see_chosen)) { onOpenOffer(chosen) } }
                if (p.offers.count > 0) TextAction(stringResource(if (p.status == "published") R.string.fl_compare_choose else R.string.fl_see_offers)) { onOffers() }
                if (p.offers.canClose) TextAction(stringResource(R.string.fl_offers_close)) { act({ ApiClient.freelance.closeOffers(auth(), p.uuid).data }) }
                if (p.offers.canReopen) TextAction(stringResource(R.string.fl_offers_reopen, p.offers.reopensLeft)) { act({ ApiClient.freelance.reopenOffers(auth(), p.uuid).data }) }
            }
            if (p.attachments.isNotEmpty()) Card {
                Label(stringResource(R.string.fl_files))
                p.attachments.forEach { a ->
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Icon(Icons.Rounded.Description, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
                        Text(a.name, color = settingsInk(), fontSize = 13.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                }
            }
            if (p.missing.isNotEmpty()) {
                Text(stringResource(R.string.fl_missing, p.missing.map { missingLabel(it) }.joinToString("، ")), color = Color(0xFFD97706), fontSize = 13.sp)
            }
            notice?.let { Text(it, color = Color(0xFF16A34A), fontSize = 13.sp, fontWeight = FontWeight.Bold) }
            error?.let { Text(it, color = Color(0xFFDC2626), fontSize = 13.sp) }

            if (p.can.submit) SettingsPrimaryButton(
                text = stringResource(if (p.status == "changes_requested" || p.status == "rejected") R.string.fl_resubmit else R.string.fl_submit),
                enabled = p.missing.isEmpty(), loading = busy,
                onClick = { act({ ApiClient.freelance.submit(auth(), p.uuid).data }, successNotice = sentText) },
            )
            if (p.can.edit) TextAction(stringResource(R.string.fl_edit)) { onEdit(p.uuid) }
            if (p.can.withdraw) TextAction(stringResource(R.string.fl_withdraw)) { act({ ApiClient.freelance.withdraw(auth(), p.uuid).data }) }
            if (p.can.cancel) TextAction(stringResource(R.string.fl_cancel_project), danger = true) { confirm = "cancel" }
            if (p.can.delete) TextAction(stringResource(R.string.fl_delete_draft), danger = true) { confirm = "delete" }

            p.timeline?.takeIf { it.isNotEmpty() }?.let { events ->
                Card {
                    Label(stringResource(R.string.fl_history))
                    events.forEach { e ->
                        Text("${eventLabel(e.type)}  ·  ‎${e.at?.take(16)?.replace('T', ' ').orEmpty()}", color = settingsMut(), fontSize = 12.sp)
                    }
                }
            }
            Spacer(Modifier.height(20.dp))
        }
    }

    when (confirm) {
        "cancel" -> ConfirmDialog(
            icon = Icons.Rounded.Close, title = stringResource(R.string.fl_cancel_project), message = stringResource(R.string.fl_cancel_confirm),
            loading = busy, onDismiss = { confirm = null },
            onConfirm = { act({ ApiClient.freelance.cancel(auth(), uuid, FreelanceCancelRequest(null)).data }, after = { confirm = null }) },
        )
        "delete" -> ConfirmDialog(
            icon = Icons.Rounded.DeleteForever, title = stringResource(R.string.fl_delete_draft), message = stringResource(R.string.fl_delete_confirm),
            loading = busy, onDismiss = { confirm = null },
            onConfirm = { act({ ApiClient.freelance.delete(auth(), uuid); null }, after = { confirm = null; onGone() }) },
        )
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
internal fun Card(content: @Composable () -> Unit) {
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp)).padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) { content() }
}

@Composable
internal fun Label(text: String) {
    Text(text, color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
}

@Composable
internal fun Field(label: String, value: String?) {
    Column {
        Text(label, color = settingsMut(), fontSize = 12.sp)
        Text(value?.takeIf { it.isNotBlank() } ?: "—", color = settingsInk(), fontSize = 14.sp)
    }
}

@Composable
internal fun TextAction(text: String, danger: Boolean = false, onClick: () -> Unit) {
    Text(
        text, color = if (danger) Color(0xFFDC2626) else settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold,
        modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable(onClick = onClick).padding(vertical = 10.dp),
    )
}

// ---------------------------------------------------------------- the form

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
    var specialty by remember { mutableStateOf<FreelanceNamedDto?>(null) }
    val skills = remember { mutableStateListOf<FreelanceNamedDto>() }
    var budgetType by rememberSaveable { mutableStateOf("fixed") }
    var budgetMin by rememberSaveable { mutableStateOf("") }
    var budgetMax by rememberSaveable { mutableStateOf("") }
    var duration by rememberSaveable { mutableStateOf("") }
    var visibility by rememberSaveable { mutableStateOf("public") }
    val questions = remember { mutableStateListOf<String>() }
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
        return FreelanceProjectRequest(
            title = title.trim().ifBlank { null },
            description = description.trim().ifBlank { null },
            specialtyId = specialty?.id,
            skillIds = skills.map { it.id },
            budgetType = budgetType,
            budgetMinMinor = min,
            budgetMaxMinor = max,
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
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                if (error != null) Text(error.orEmpty(), color = settingsMut(), modifier = Modifier.padding(24.dp)) else CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(28.dp))
            }
            return@Column
        }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Card {
                DorrTextField(title, { title = it.take(120) }, label = stringResource(R.string.fl_title), placeholder = stringResource(R.string.fl_title_hint), modifier = Modifier.fillMaxWidth())
                DorrTextField(description, { description = it.take(5000) }, label = stringResource(R.string.fl_description), placeholder = stringResource(R.string.fl_description_hint), singleLine = false, minLines = 5, modifier = Modifier.fillMaxWidth())
            }
            Card {
                Label(stringResource(R.string.fl_specialty))
                DorrTextField(specialty?.name.orEmpty(), {}, placeholder = stringResource(R.string.fl_pick_specialty), onClick = { pickSpecialty = true }, modifier = Modifier.fillMaxWidth())
                Label(stringResource(R.string.fl_skills))
                SkillPicker(skills)
            }
            Card {
                Label(stringResource(R.string.fl_budget))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Chip(stringResource(R.string.fl_budget_fixed), budgetType == "fixed") { budgetType = "fixed" }
                    Chip(stringResource(R.string.fl_budget_range), budgetType == "range") { budgetType = "range" }
                }
                val currency = rules?.currency.orEmpty()
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    DorrTextField(
                        budgetMin, { budgetMin = it }, label = stringResource(if (budgetType == "range") R.string.fl_budget_from else R.string.fl_budget_amount, currency),
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.weight(1f),
                    )
                    if (budgetType == "range") DorrTextField(
                        budgetMax, { budgetMax = it }, label = stringResource(R.string.fl_budget_to, currency),
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
            }
            Card {
                DorrTextField(duration, { duration = it.filter(Char::isDigit).take(3) }, label = stringResource(R.string.fl_duration_days), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth())
                Label(stringResource(R.string.fl_visibility))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Chip(stringResource(R.string.fl_public), visibility == "public") { visibility = "public" }
                    Chip(stringResource(R.string.fl_private), visibility == "private") { visibility = "private" }
                }
                Text(stringResource(if (visibility == "private") R.string.fl_private_hint else R.string.fl_public_hint), color = settingsMut(), fontSize = 12.sp)
            }
            // FL-307: questions every freelancer answers with their offer.
            Card {
                Label(stringResource(R.string.fl_questions))
                Text(stringResource(R.string.fl_questions_hint), color = settingsMut(), fontSize = 12.sp)
                questions.forEachIndexed { i, q ->
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        DorrTextField(q, { questions[i] = it.take(300) }, placeholder = stringResource(R.string.fl_question_n, i + 1), modifier = Modifier.weight(1f))
                        Icon(Icons.Rounded.Close, stringResource(R.string.fl_remove), tint = settingsMut(), modifier = Modifier.size(30.dp).clip(RoundedCornerShape(50)).clickable { questions.removeAt(i) }.padding(6.dp))
                    }
                }
                if (questions.size < 5) TextAction(stringResource(R.string.fl_add_question)) { questions.add("") }
            }
            Card {
                Label(stringResource(R.string.fl_files))
                attachments.forEach { a ->
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
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
                Row(
                    Modifier.clip(RoundedCornerShape(12.dp)).clickable(enabled = !uploading) { picker.launch("*/*") }.padding(vertical = 6.dp),
                    verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    if (uploading) CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(18.dp))
                    else Icon(Icons.Rounded.AttachFile, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
                    Text(stringResource(R.string.fl_add_file), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
                }
                Text(stringResource(R.string.fl_files_hint), color = settingsMut(), fontSize = 11.sp)
            }
            error?.let { Text(it, color = Color(0xFFDC2626), fontSize = 13.sp) }
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

/** Picked skills as removable pills, and a search over the active catalog (400 ms after the last key). */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun SkillPicker(selected: MutableList<FreelanceNamedDto>) {
    var query by remember { mutableStateOf("") }
    var results by remember { mutableStateOf(emptyList<FreelanceNamedDto>()) }
    LaunchedEffect(query) {
        if (query.trim().length < 2) {
            results = emptyList()
            return@LaunchedEffect
        }
        delay(400)
        results = runCatching { ApiClient.freelance.skills(auth(), query.trim()).data.orEmpty().map { FreelanceNamedDto(it.id, it.name) } }.getOrDefault(emptyList())
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
        DorrTextField(query, { query = it }, placeholder = stringResource(R.string.fl_search_skills), modifier = Modifier.fillMaxWidth())
        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            results.filter { r -> selected.none { it.id == r.id } }.take(12).forEach { r ->
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
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.fl_pick_specialty), fontWeight = FontWeight.Bold) },
        text = {
            val nodes = tree
            if (nodes == null) {
                Box(Modifier.fillMaxWidth().padding(20.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(24.dp)) }
            } else {
                LazyColumn(Modifier.heightIn(max = 420.dp)) {
                    nodes.forEach { parent ->
                        if (parent.children.isEmpty()) {
                            item(key = "p${parent.id}") { PickRow(parent.name, bold = true) { onPick(FreelanceNamedDto(parent.id, parent.name)) } }
                        } else {
                            item(key = "h${parent.id}") { Text(parent.name, color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 10.dp, bottom = 2.dp)) }
                            items(parent.children, key = { "c${it.id}" }) { child -> PickRow(child.name, bold = false) { onPick(FreelanceNamedDto(child.id, child.name)) } }
                        }
                    }
                }
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.fl_close), color = settingsAccent()) } },
    )
}

@Composable
private fun PickRow(text: String, bold: Boolean, onClick: () -> Unit) {
    Text(
        text, color = settingsInk(), fontSize = 14.sp, fontWeight = if (bold) FontWeight.Bold else FontWeight.Normal,
        modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).clickable(onClick = onClick).padding(vertical = 10.dp, horizontal = 6.dp),
    )
}
