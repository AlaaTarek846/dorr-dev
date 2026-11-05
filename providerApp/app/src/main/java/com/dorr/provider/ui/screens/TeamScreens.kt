package com.dorr.provider.ui.screens

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckBox
import androidx.compose.material.icons.rounded.CheckBoxOutlineBlank
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.PersonAdd
import androidx.compose.material.icons.rounded.PersonRemove
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.provider.R
import com.dorr.provider.net.ApiClient
import com.dorr.provider.net.BranchDto
import com.dorr.provider.net.CountryDto
import com.dorr.provider.net.MemberDto
import com.dorr.provider.net.MemberEventDto
import com.dorr.provider.net.PagedRows
import com.dorr.provider.net.Session
import com.dorr.provider.net.WorkspaceOptionsDto
import com.dorr.provider.net.apiDone
import com.dorr.provider.net.can
import com.dorr.provider.net.collectReconnectTick
import com.dorr.provider.net.errorText
import com.dorr.provider.net.formattedDial
import com.dorr.provider.net.jsonBody
import com.dorr.provider.ui.components.AvatarImage
import com.dorr.provider.ui.components.Card
import com.dorr.provider.ui.components.ChipRow
import com.dorr.provider.ui.components.DorrTextField
import com.dorr.provider.ui.components.EmptyState
import com.dorr.provider.ui.components.ErrorBanner
import com.dorr.provider.ui.components.FieldLabel
import com.dorr.provider.ui.components.FormIntro
import com.dorr.provider.ui.components.Page
import com.dorr.provider.ui.components.PagedFooter
import com.dorr.provider.ui.components.PhoneField
import com.dorr.provider.ui.kit.AccountDark
import com.dorr.provider.ui.kit.ConfirmDialog
import com.dorr.provider.ui.kit.RedToggle
import com.dorr.provider.ui.kit.SettingsPrimaryButton
import com.dorr.provider.ui.kit.settingsAccent
import com.dorr.provider.ui.kit.settingsInk
import com.dorr.provider.ui.kit.settingsMut
import com.dorr.provider.ui.kit.settingsNight
import com.google.gson.JsonArray
import com.google.gson.JsonNull
import com.google.gson.JsonObject
import kotlinx.coroutines.launch

/**
 * The team (C-03, `members.manage`): who works in the establishment (owner first, invitations not answered yet marked), inviting
 * someone by phone with exactly the permissions they get (§17), changing or removing a member, a driver's licence, and the log of
 * every change. Read once on open and when the connection returns.
 */
@Composable
fun TeamScreen(onBack: () -> Unit, onOpenDriverDocs: (String) -> Unit) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var members by remember { mutableStateOf<List<MemberDto>?>(null) }
    var options by remember { mutableStateOf<WorkspaceOptionsDto?>(null) }
    var branches by remember { mutableStateOf<List<BranchDto>>(emptyList()) }
    var error by remember { mutableStateOf<String?>(null) }
    // 0 = the list; 1 = a new invitation; 2 = editing [editingUuid]; 3 = the log.
    var page by rememberSaveable { mutableStateOf(0) }
    var editingUuid by rememberSaveable { mutableStateOf<String?>(null) }

    suspend fun reload() {
        when (val result = apiCall { ApiClient.manage.members() }) {
            is ApiResult.Ok -> {
                members = result.data
                error = null
            }
            else -> error = result.errorText(offlineText) ?: genericError
        }
    }
    LaunchedEffect(reconnect) {
        reload()
        if (options == null) (apiCall { ApiClient.workspace.options(Session.me.value?.countryId) } as? ApiResult.Ok)?.data?.let { options = it }
        (apiCall { ApiClient.manage.branches() } as? ApiResult.Ok)?.data?.let { branches = it }
    }

    BackHandler(enabled = page != 0) { page = 0 }
    when (page) {
        1, 2 -> {
            val member = if (page == 2) members?.firstOrNull { it.uuid == editingUuid } else null
            MemberForm(
                member = member, options = options, branches = branches,
                onBack = { page = 0 },
                onSaved = { scope.launch { reload(); page = 0 } },
                onOpenDriverDocs = onOpenDriverDocs,
            )
            return
        }
        3 -> {
            TeamLog(onBack = { page = 0 })
            return
        }
    }

    val list = members
    Page(title = stringResource(R.string.tm_title), onBack = onBack) {
        when {
            list == null -> if (error == null) CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.5.dp, modifier = Modifier.padding(top = 40.dp).size(28.dp))
            list.isEmpty() -> EmptyState(Icons.Rounded.Groups, stringResource(R.string.tm_empty_title), stringResource(R.string.tm_empty_body), Modifier.padding(top = 30.dp))
            else -> list.forEach { member -> MemberRow(member) { if (!member.isOwner) { editingUuid = member.uuid; page = 2 } } }
        }
        SettingsPrimaryButton(text = stringResource(R.string.tm_invite), onClick = { page = 1 })
        Text(
            stringResource(R.string.tm_log), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold,
            modifier = Modifier.clickable { page = 3 },
        )
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}

@Composable
private fun MemberRow(member: MemberDto, onClick: () -> Unit) {
    Card {
        Row(Modifier.fillMaxWidth().clickable(onClick = onClick), verticalAlignment = Alignment.CenterVertically) {
            AvatarImage(url = member.person?.avatar, size = 42.dp)
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(
                    member.person?.name?.takeIf { it.isNotBlank() } ?: ("‎" + (member.person?.phone ?: member.phone).orEmpty()),
                    color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold,
                )
                val line = when {
                    member.isOwner -> stringResource(R.string.acc_role_owner)
                    else -> stringResource(R.string.tm_permissions_count, member.permissions.size)
                }
                Text(line, color = settingsMut(), fontSize = 12.sp)
            }
            Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(4.dp)) {
                if (member.status == "invited") StatusPill(stringResource(R.string.tm_invited), Amber)
                if (member.isExclusive) StatusPill(stringResource(R.string.ws_exclusive), settingsAccent())
            }
        }
    }
}

/**
 * Inviting someone ([member] null) or changing a member: a ready-made template (branch manager, quoter, driver) ticks its
 * permissions, which can then be ticked or unticked one by one — only the ones I hold myself (the server refuses the rest
 * anyway). Then the branches (all, or some), exclusivity (set when inviting; afterwards it can only be dropped), and an optional
 * limit above which an offer waits for the approver.
 */
@Composable
private fun MemberForm(
    member: MemberDto?,
    options: WorkspaceOptionsDto?,
    branches: List<BranchDto>,
    onBack: () -> Unit,
    onSaved: () -> Unit,
    onOpenDriverDocs: (String) -> Unit,
) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val mySeat by Session.membership.collectAsState()
    val inviting = member == null

    // The phone (inviting only), in the sign-in's own field.
    var countries by remember { mutableStateOf<List<CountryDto>>(emptyList()) }
    var country by remember { mutableStateOf<CountryDto?>(null) }
    var menuExpanded by remember { mutableStateOf(false) }
    var phone by rememberSaveable { mutableStateOf("") }
    LaunchedEffect(inviting) {
        if (!inviting) return@LaunchedEffect
        val listed = (apiCall { ApiClient.general.countries() } as? ApiResult.Ok)?.data.orEmpty()
        countries = listed
        country = listed.firstOrNull { it.id == Session.me.value?.countryId } ?: listed.firstOrNull { it.isDefault } ?: listed.firstOrNull()
    }

    val picked = remember { mutableStateListOf<String>().apply { addAll(member?.permissions.orEmpty()) } }
    var allBranches by rememberSaveable { mutableStateOf(member?.allBranches ?: true) }
    val pickedBranches = remember { mutableStateListOf<Long>().apply { addAll(member?.branchIds.orEmpty()) } }
    var exclusive by rememberSaveable { mutableStateOf(member?.isExclusive ?: false) }
    var limit by rememberSaveable { mutableStateOf(member?.offerLimitMinor?.let { formatMinor(it, null).replace("‎", "") }.orEmpty()) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var removing by remember { mutableStateOf(false) }

    fun holds(key: String) = mySeat.can(key)

    val phoneLength = country?.phoneLength ?: 0
    val phoneOk = !inviting || (country != null && phone.isNotBlank() && (phoneLength == 0 || phone.length == phoneLength))
    val limitMinor = limit.takeIf { it.isNotBlank() }?.let(::parseMinor)
    val limitOk = limit.isBlank() || limitMinor != null
    val branchesOk = allBranches || pickedBranches.isNotEmpty()
    val ready = phoneOk && limitOk && branchesOk

    fun save() {
        busy = true
        error = null
        val body = JsonObject().apply {
            if (inviting) {
                addProperty("dial_code", country?.formattedDial().orEmpty())
                addProperty("phone", phone)
            }
            add("permissions", JsonArray().apply { picked.forEach { add(it) } })
            if (allBranches) add("branch_ids", JsonNull.INSTANCE) else add("branch_ids", JsonArray().apply { pickedBranches.forEach { add(it) } })
            // Exclusivity is agreed when inviting; afterwards it can only be dropped (MembershipService::update).
            if (inviting || !exclusive) addProperty("is_exclusive", exclusive)
            if (limitMinor != null) addProperty("offer_limit_minor", limitMinor) else add("offer_limit_minor", JsonNull.INSTANCE)
        }
        scope.launch {
            val result = if (member == null) apiCall { ApiClient.manage.invite(jsonBody(body)) } else apiCall { ApiClient.manage.updateMember(member.uuid, jsonBody(body)) }
            busy = false
            if (result is ApiResult.Ok) onSaved() else error = result.errorText(offlineText) ?: genericError
        }
    }

    fun remove() {
        val target = member ?: return
        busy = true
        scope.launch {
            val failed = apiDone(offlineText, genericError) { ApiClient.manage.removeMember(target.uuid) }
            busy = false
            removing = false
            if (failed == null) onSaved() else error = failed
        }
    }

    Page(title = stringResource(if (inviting) R.string.tm_invite else R.string.tm_edit), onBack = onBack) {
        FormIntro(Icons.Rounded.PersonAdd, stringResource(if (inviting) R.string.tm_invite else R.string.tm_edit), stringResource(R.string.tm_form_body))

        if (inviting) {
            PhoneField(
                countries = countries,
                selectedCountryId = country?.id,
                flagCode = country?.flag?.code?.takeIf { it.isNotBlank() } ?: country?.code.orEmpty(),
                dialCode = country?.formattedDial().orEmpty(),
                menuExpanded = menuExpanded,
                onMenuExpandedChange = { menuExpanded = it },
                onCountrySelected = { country = it; menuExpanded = false },
                phoneStartsWith = country?.phoneStartsWith.orEmpty(),
                phoneLength = if (phoneLength > 0) phoneLength else 15,
                phone = phone,
                onPhoneChange = { phone = it },
                canSubmit = false,
                onSubmit = {},
            )
        } else {
            Card {
                Text(member?.person?.name ?: "", color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                Text("‎" + (member?.person?.phone ?: member?.phone).orEmpty(), color = settingsMut(), fontSize = 12.sp)
                // A driver's licence, uploaded by them or by someone with fleet.drivers.
                member?.takeIf { "driving.work" in it.permissions }?.let { driver ->
                    Text(
                        stringResource(R.string.drv_docs_title), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold,
                        modifier = Modifier.clickable { onOpenDriverDocs(driver.uuid) },
                    )
                }
            }
        }

        // Ready-made sets: a tap ticks exactly the template's keys I may give.
        val templates = options?.permissionTemplates.orEmpty()
        if (templates.isNotEmpty()) {
            FieldLabel(stringResource(R.string.tm_templates))
            ChipRow(
                options = templates.keys.map { it to templateLabel(it) },
                selected = templates.filter { (_, keys) -> keys.filter(::holds).toSet() == picked.toSet() && keys.isNotEmpty() }.keys,
                onToggle = { key ->
                    picked.clear()
                    picked.addAll(templates[key].orEmpty().filter(::holds))
                },
            )
        }

        FieldLabel(stringResource(R.string.tm_permissions))
        Text(stringResource(R.string.tm_permissions_hint), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp, modifier = Modifier.fillMaxWidth())
        options?.permissionGroups.orEmpty().forEach { (group, keys) ->
            Card {
                Text(permissionGroupLabel(group), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                keys.forEach { key ->
                    PermissionRow(key, checked = key in picked, enabled = holds(key)) {
                        if (key in picked) picked.remove(key) else picked.add(key)
                    }
                }
            }
        }
        if (options == null) CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.5.dp, modifier = Modifier.size(24.dp))

        // Branches: all, or the chosen ones.
        Card {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.tm_all_branches), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                    Text(stringResource(R.string.tm_all_branches_hint), color = settingsMut(), fontSize = 12.sp)
                }
                RedToggle(on = allBranches) { allBranches = !allBranches }
            }
            if (!allBranches) {
                ChipRow(
                    options = branches.map { it.id.toString() to it.name.orEmpty() },
                    selected = pickedBranches.map { it.toString() }.toSet(),
                    onToggle = { id -> id.toLongOrNull()?.let { if (it in pickedBranches) pickedBranches.remove(it) else pickedBranches.add(it) } },
                )
                if (pickedBranches.isEmpty()) Text(stringResource(R.string.tm_pick_branch), color = Red, fontSize = 12.sp)
            }
        }

        // Exclusivity, said plainly (§14): set when inviting; a member's exclusivity can only be dropped.
        if (inviting || member?.isExclusive == true) {
            Card {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Rounded.Lock, contentDescription = null, tint = Amber, modifier = Modifier.size(20.dp))
                    Spacer(Modifier.width(8.dp))
                    Text(stringResource(R.string.ws_exclusive), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                    RedToggle(on = exclusive) { if (inviting || exclusive) exclusive = !exclusive }
                }
                Text(stringResource(R.string.tm_exclusive_hint), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp)
                if (!inviting) Text(stringResource(R.string.tm_exclusive_drop_only), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp)
            }
        }

        // The highest offer this person sends without approval (only matters with offers.send).
        if ("offers.send" in picked) {
            DorrTextField(
                limit, { limit = it.filter { c -> c.isDigit() || c == '.' || c == ',' }.take(12) },
                label = stringResource(R.string.tm_offer_limit), placeholder = stringResource(R.string.tm_offer_limit_hint),
                icon = Icons.Rounded.Payments, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                error = !limitOk, modifier = Modifier.fillMaxWidth(),
            )
        }

        SettingsPrimaryButton(text = stringResource(if (inviting) R.string.tm_send_invite else R.string.common_save), enabled = ready, loading = busy, onClick = ::save)
        if (!inviting) {
            Row(Modifier.clickable(enabled = !busy) { removing = true }, verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.PersonRemove, contentDescription = null, tint = Red, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(6.dp))
                Text(stringResource(if (member?.status == "invited") R.string.tm_cancel_invite else R.string.tm_remove), color = Red, fontSize = 14.sp, fontWeight = FontWeight.Bold)
            }
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }

    if (removing) {
        ConfirmDialog(
            icon = Icons.Rounded.PersonRemove,
            title = stringResource(R.string.tm_remove),
            message = stringResource(R.string.tm_remove_message),
            confirmLabel = stringResource(R.string.tm_remove_confirm),
            loading = busy,
            onConfirm = ::remove,
            onDismiss = { if (!busy) removing = false },
        )
    }
}

/** One permission: a tick box, its name and what it lets the person do; a key I don't hold is shown but can't be ticked. */
@Composable
private fun PermissionRow(key: String, checked: Boolean, enabled: Boolean, onToggle: () -> Unit) {
    val night = settingsNight()
    val accent = settingsAccent()
    Row(
        Modifier.fillMaxWidth().clickable(enabled = enabled, onClick = onToggle).padding(vertical = 2.dp),
        verticalAlignment = Alignment.Top,
    ) {
        Icon(
            if (checked) Icons.Rounded.CheckBox else Icons.Rounded.CheckBoxOutlineBlank, contentDescription = null,
            tint = when {
                !enabled -> settingsMut().copy(alpha = 0.4f)
                checked -> accent
                night -> AccountDark.line
                else -> settingsMut()
            },
            modifier = Modifier.size(22.dp),
        )
        Spacer(Modifier.width(8.dp))
        Column(Modifier.weight(1f)) {
            Text(permissionLabel(key), color = settingsInk().copy(alpha = if (enabled) 1f else 0.5f), fontSize = 13.sp, fontWeight = FontWeight.Bold)
            Text(
                if (enabled) permissionHint(key) else stringResource(R.string.tm_not_yours),
                color = settingsMut(), fontSize = 11.sp, lineHeight = 16.sp,
            )
        }
    }
}

/** The team's log (REQ-002): who was invited, joined, changed, removed or left — newest first, page by page. */
@Composable
private fun TeamLog(onBack: () -> Unit) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val paged = remember { PagedRows<MemberEventDto>(30, { listOf(it.createdAt, it.action, it.member?.uuid, it.actor?.name) }) { page -> apiCall { ApiClient.manage.memberEvents(page) } } }
    LaunchedEffect(reconnect) { paged.reload() }

    Page(title = stringResource(R.string.tm_log), onBack = onBack, onNearEnd = { scope.launch { paged.loadMore() } }) {
        when {
            !paged.loaded -> CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.5.dp, modifier = Modifier.padding(top = 40.dp).size(28.dp))
            paged.rows.isEmpty() -> EmptyState(Icons.Rounded.History, stringResource(R.string.tm_log_empty), "", Modifier.padding(top = 30.dp))
            else -> paged.rows.forEach { event ->
                // What happened to whom, then by whom and when.
                val who = event.member?.let { it.name?.takeIf { n -> n.isNotBlank() } ?: it.phone?.let { p -> "\u200E$p" } }
                val by = event.actor?.let { it.name?.takeIf { n -> n.isNotBlank() } ?: it.phone?.let { p -> "\u200E$p" } }
                RowCard(
                    Icons.Rounded.History,
                    listOfNotNull(eventLabel(event.action), who).joinToString(": "),
                    listOfNotNull(by?.let { stringResource(R.string.tm_ev_by, it) }, dateTimeText(event.createdAt)).joinToString(" · "),
                    onClick = null,
                )
            }
        }
        PagedFooter(paged, onRetry = { paged.retry(); scope.launch { paged.loadMore() } })
        paged.failure?.let { ErrorBanner(it.errorText(offlineText), Modifier.fillMaxWidth()) }
    }
}

@Composable
private fun eventLabel(action: String?): String = when (action) {
    "invited" -> stringResource(R.string.tm_ev_invited)
    "joined" -> stringResource(R.string.tm_ev_joined)
    "declined" -> stringResource(R.string.tm_ev_declined)
    "permissions_changed" -> stringResource(R.string.tm_ev_changed)
    "removed" -> stringResource(R.string.tm_ev_removed)
    "left" -> stringResource(R.string.tm_ev_left)
    else -> action.orEmpty()
}
