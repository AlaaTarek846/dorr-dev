package com.dorr.app.ui.screens.aichat

import android.content.Intent
import android.net.Uri
import android.widget.Toast
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
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.material3.Icon
import androidx.compose.material.icons.rounded.Public
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.Icons
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalClipboardManager
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.AiSiteProjectDto
import com.dorr.app.network.AiSiteVersionDto
import com.dorr.app.network.ApiClient
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.chat.chatAuth
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withTimeoutOrNull

@Composable
private fun DetailHero(p: AiSiteProjectDto) {
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(26.dp)).background(siteGradient()).padding(20.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(56.dp).clip(RoundedCornerShape(18.dp)).background(Color.White.copy(alpha = 0.2f)), contentAlignment = Alignment.Center) {
                Text(p.title.trim().take(1).uppercase(), color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 24.sp)
            }
            Spacer(Modifier.width(14.dp))
            Column(Modifier.weight(1f)) {
                Text(p.title, color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 20.sp, maxLines = 2)
                Spacer(Modifier.height(6.dp))
                SiteStatusChip(p.status, p.isDisabled, onDark = true)
            }
        }
        p.generationsLeft?.let {
            Spacer(Modifier.height(14.dp))
            Text(stringResource(R.string.ai_sites_generations_left, it), color = Color.White.copy(alpha = 0.88f), fontSize = 12.5.sp)
        }
    }
}

@Composable
private fun BuildingCard() {
    val c = sitePalette()
    SiteCard(Modifier.padding(top = 14.dp)) {
        Text(stringResource(R.string.ai_sites_building_title), color = c.ink, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp)
        Spacer(Modifier.height(10.dp))
        LinearProgressIndicator(
            color = Ai.Red,
            trackColor = Ai.Red.copy(alpha = 0.15f),
            modifier = Modifier.fillMaxWidth().height(6.dp).clip(RoundedCornerShape(50)),
        )
        Spacer(Modifier.height(10.dp))
        Text(stringResource(R.string.ai_sites_building), color = c.mut, fontSize = 12.5.sp, lineHeight = 19.sp)
    }
}

@Composable
private fun VersionRow(v: AiSiteVersionDto, canRestore: Boolean, busy: Boolean, onRestore: () -> Unit) {
    val c = sitePalette()
    Row(Modifier.fillMaxWidth().padding(vertical = 7.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(
            Modifier.size(34.dp).clip(CircleShape).background(if (v.isCurrent) Ai.Red else Ai.Red.copy(alpha = 0.1f)),
            contentAlignment = Alignment.Center,
        ) { Text("${v.number}", color = if (v.isCurrent) Color.White else Ai.Red, fontWeight = FontWeight.ExtraBold, fontSize = 13.sp) }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(stringResource(R.string.ai_sites_version_n, v.number), color = c.ink, fontWeight = FontWeight.Bold, fontSize = 13.5.sp)
                if (v.isCurrent) {
                    Box(Modifier.clip(RoundedCornerShape(50)).background(c.success.copy(alpha = 0.14f)).padding(horizontal = 8.dp, vertical = 2.dp)) {
                        Text(stringResource(R.string.ai_sites_current), color = c.success, fontSize = 10.5.sp, fontWeight = FontWeight.Bold)
                    }
                }
            }
            v.instruction?.let { Text(it, color = c.mut, fontSize = 11.5.sp, maxLines = 2, modifier = Modifier.padding(top = 2.dp)) }
            if (v.status == "failed") Text(stringResource(R.string.ai_sites_status_failed), color = c.danger, fontSize = 11.5.sp, modifier = Modifier.padding(top = 2.dp))
        }
        if (canRestore) {
            Box(
                Modifier.clip(RoundedCornerShape(12.dp)).background(Ai.Red.copy(alpha = 0.1f)).clickable(enabled = !busy, onClick = onRestore)
                    .padding(horizontal = 12.dp, vertical = 8.dp),
            ) { Text(stringResource(R.string.ai_sites_restore), color = Ai.Red, fontWeight = FontWeight.Bold, fontSize = 12.sp) }
        }
    }
}

/** Edit / versions / publishing as three tabs, so the page is no longer one long scroll of cards. */
@Composable
private fun SiteTabs(selected: Int, onSelect: (Int) -> Unit) {
    val c = sitePalette()
    val items = listOf(
        Icons.Rounded.Edit to R.string.ai_sites_tab_edit,
        Icons.Rounded.History to R.string.ai_sites_tab_versions,
        Icons.Rounded.Public to R.string.ai_sites_tab_publish,
    )
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(c.surface).border(1.dp, c.line, RoundedCornerShape(18.dp)).padding(5.dp),
        horizontalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        items.forEachIndexed { index, (icon, label) ->
            val on = index == selected
            Row(
                Modifier.weight(1f).clip(RoundedCornerShape(14.dp))
                    .then(if (on) Modifier.background(siteGradient()) else Modifier)
                    .clickable { onSelect(index) }.padding(vertical = 11.dp),
                horizontalArrangement = Arrangement.Center,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(icon, contentDescription = null, tint = if (on) Color.White else c.mut, modifier = Modifier.size(17.dp))
                Spacer(Modifier.width(6.dp))
                Text(stringResource(label), color = if (on) Color.White else c.mut, fontSize = 12.5.sp, fontWeight = if (on) FontWeight.ExtraBold else FontWeight.SemiBold, maxLines = 1)
            }
        }
    }
}

@Composable
internal fun SitesDetail(night: Boolean, id: Int, onDeleted: () -> Unit) {
    val c = sitePalette()
    val context = LocalContext.current
    val clipboard = LocalClipboardManager.current
    val scope = rememberCoroutineScope()
    val copiedText = stringResource(R.string.ai_sites_link_copied)

    var project by remember { mutableStateOf<AiSiteProjectDto?>(null) }
    var instruction by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var confirmDelete by remember { mutableStateOf(false) }
    var editsBlocked by remember { mutableStateOf(false) }
    var tab by rememberSaveable { mutableIntStateOf(0) }

    suspend fun refresh() {
        runCatching { ApiClient.aiSites.project(chatAuth(), id).data }.onSuccess { project = it }
    }

    fun act(block: suspend () -> AiSiteProjectDto?) {
        busy = true
        error = null
        scope.launch {
            runCatching { block() }.fold(
                onSuccess = { updated -> if (updated != null) project = updated },
                onFailure = { error = it.apiFailure().message },
            )
            busy = false
        }
    }

    // True while the server is still building: the project or any of its versions is not settled yet.
    val building = project.let { current ->
        current == null || current.status == "generating" ||
            current.versionList.any { it.status == "processing" || it.status == "pending" }
    }

    // Keyed on `building` so polling starts again by itself when an edit or retry flips the project to
    // "generating" after the screen was already idle (a loop keyed only on the id ended once and never resumed).
    LaunchedEffect(id, building) {
        if (project == null) refresh()
        while (building) {
            delay(3000)
            withTimeoutOrNull(15_000) { refresh() }
        }
    }

    val p = project
    if (p == null) {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Ai.Red) }
        return
    }

    val hasSite = p.versionList.any { it.isCurrent }
    val idle = !p.isDisabled && p.status != "generating"

    Column(Modifier.fillMaxSize().imePadding().verticalScroll(rememberScrollState()).padding(horizontal = 16.dp).navigationBarsPadding().padding(bottom = 28.dp)) {
        DetailHero(p)

        if (p.status == "generating") BuildingCard()

        (p.lastErrorMessage ?: p.lastError)?.let { message ->
            Row(
                Modifier.fillMaxWidth().padding(top = 14.dp).clip(RoundedCornerShape(16.dp)).background(c.danger.copy(alpha = 0.1f))
                    .border(1.dp, c.danger.copy(alpha = 0.3f), RoundedCornerShape(16.dp)).padding(14.dp),
            ) { Text(message, color = c.danger, fontSize = 12.5.sp, lineHeight = 19.sp) }
        }
        error?.let { Text(it, color = c.danger, fontSize = 12.5.sp, modifier = Modifier.padding(top = 10.dp)) }

        p.previewUrl?.let { url ->
            Spacer(Modifier.height(14.dp))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Box(Modifier.weight(1f)) {
                    PrimaryButton(stringResource(R.string.ai_sites_open)) {
                        runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url))) }
                    }
                }
                SecondaryButton(stringResource(R.string.ai_sites_copy_link), Modifier.weight(0.7f)) {
                    clipboard.setText(AnnotatedString(url))
                    Toast.makeText(context, copiedText, Toast.LENGTH_SHORT).show()
                }
            }
        }

        if (p.status == "failed" && !hasSite) {
            Spacer(Modifier.height(12.dp))
            PrimaryButton(stringResource(R.string.ai_sites_retry), enabled = !busy) { act { ApiClient.aiSites.retry(chatAuth(), id).data } }
        }

        val tabbed = hasSite && !p.isDisabled
        val section = if (tabbed) tab else 1

        if (tabbed) {
            Spacer(Modifier.height(16.dp))
            SiteTabs(selected = tab, onSelect = { tab = it })
        }

        when (section) {
            0 -> {
                if (idle) {
                    Spacer(Modifier.height(14.dp))
                    SiteCard {
                        SiteSectionTitle(stringResource(R.string.ai_sites_edit_title), stringResource(R.string.ai_sites_edit_sub))
                        Spacer(Modifier.height(8.dp))
                        if (editsBlocked) {
                            Text(stringResource(R.string.ai_sites_packs_blocked_hint), color = c.danger, fontSize = 12.sp, lineHeight = 18.sp)
                            Spacer(Modifier.height(6.dp))
                        }
                        SiteField(stringResource(R.string.ai_sites_edit_hint), instruction, { instruction = it }, singleLine = false, minLines = 3)
                        Spacer(Modifier.height(6.dp))
                        PrimaryButton(stringResource(R.string.ai_sites_edit_send), enabled = !busy && !editsBlocked && instruction.trim().length >= 3) {
                            val text = instruction.trim()
                            instruction = ""
                            act { ApiClient.aiSites.edit(chatAuth(), id, text).data }
                        }
                    }
                    Spacer(Modifier.height(14.dp))
                    SiteEditPacksCard(night, p.id, reloadKey = p.versionList.joinToString { "${it.id}${it.status}" }, onBought = { scope.launch { refresh() } }) { editsBlocked = it }
                }
            }

            1 -> if (p.versionList.isNotEmpty()) {
                Spacer(Modifier.height(14.dp))
                SiteCard {
                    SiteSectionTitle(stringResource(R.string.ai_sites_versions))
                    Spacer(Modifier.height(4.dp))
                    p.versionList.forEach { v ->
                        VersionRow(v, canRestore = !v.isCurrent && v.status == "completed" && idle, busy = busy) {
                            act { ApiClient.aiSites.restore(chatAuth(), id, v.number).data }
                        }
                    }
                }
            }

            else -> {
                Spacer(Modifier.height(14.dp))
                SiteSourceCard(night, p.id)
                Spacer(Modifier.height(14.dp))
                SiteHostingCard(night, c.ink, c.mut, c.surface, p.id)
            }
        }

        Spacer(Modifier.height(20.dp))
        SecondaryButton(stringResource(R.string.ai_sites_delete), Modifier.fillMaxWidth(), color = c.danger) { confirmDelete = true }
    }

    if (confirmDelete) {
        AiConfirmDialog(
            night = night,
            title = stringResource(R.string.ai_sites_delete),
            message = stringResource(R.string.ai_sites_delete_confirm),
            onConfirm = {
                confirmDelete = false
                scope.launch {
                    runCatching { ApiClient.aiSites.delete(chatAuth(), id) }.onSuccess { onDeleted() }
                }
            },
            onDismiss = { confirmDelete = false },
        )
    }
}
