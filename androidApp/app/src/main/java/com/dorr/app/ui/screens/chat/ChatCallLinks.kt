package com.dorr.app.ui.screens.chat

import android.Manifest
import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.spring
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AddLink
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.ContentCopy
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.Link
import androidx.compose.material.icons.rounded.LinkOff
import androidx.compose.material.icons.rounded.SentimentDissatisfied
import androidx.compose.material.icons.rounded.Share
import androidx.compose.material.icons.rounded.Videocam
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshotFlow
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import com.dorr.app.R
import com.dorr.app.chat.CallController
import com.dorr.app.chat.CallPhase
import com.dorr.app.network.ApiClient
import com.dorr.app.network.CallLinkDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.AccountDark
import com.dorr.app.ui.screens.ConfirmDialog
import com.dorr.app.ui.screens.profile.PinkIcon
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.screens.profile.settingsSurface
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.filter
import kotlinx.coroutines.launch

/*
 * Call links (WhatsApp list 72): make a link for a voice or video call, send it anywhere, and
 * whoever opens it in DORR joins the same call — nobody is rung. I hear when someone joins.
 */

/** On the calls page: create a link, or open the log of links that still work. */
@Composable
internal fun CallLinksCard() {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var links by remember { mutableStateOf<List<CallLinkDto>>(emptyList()) }
    var page by remember { mutableIntStateOf(1) }
    var hasMore by remember { mutableStateOf(false) }
    var loadingMore by remember { mutableStateOf(false) }
    var total by remember { mutableIntStateOf(0) }
    var picking by remember { mutableStateOf(false) }
    var logOpen by remember { mutableStateOf(false) }
    var showing by remember { mutableStateOf<CallLinkDto?>(null) }
    var toDelete by remember { mutableStateOf<CallLinkDto?>(null) }
    var busy by remember { mutableStateOf(false) }
    val networkError = stringResource(R.string.ch_error_network)
    val cancelled = stringResource(R.string.cl_cancelled)

    suspend fun loadPage(reset: Boolean) {
        if (!reset && (loadingMore || !hasMore)) return
        if (!reset) loadingMore = true
        val next = if (reset) 1 else page + 1
        val envelope = runCatching { ApiClient.chat.callLinks(chatAuth(), page = next, perPage = 15) }.getOrNull()
        val chunk = envelope?.data.orEmpty()
        links = if (reset) chunk else {
            val known = links.map { it.token }.toSet()
            links + chunk.filter { it.token !in known }
        }
        page = next
        hasMore = envelope?.pagination?.hasMorePages == true
        total = envelope?.pagination?.total ?: if (reset) chunk.size else maxOf(total, links.size)
        if (!reset) loadingMore = false
    }

    LaunchedEffect(Unit) { loadPage(reset = true) }

    fun create(type: String) {
        scope.launch {
            try {
                val made = ApiClient.chat.createCallLink(chatAuth(), mapOf("type" to type)).data ?: return@launch
                links = listOf(made) + links.filterNot { it.token == made.token }
                total += 1
                showing = made
            } catch (e: Exception) {
                host.showToast(e.apiFailure().message ?: networkError)
            }
        }
    }

    fun revoke(link: CallLinkDto) {
        busy = true
        scope.launch {
            try {
                ApiClient.chat.revokeCallLink(chatAuth(), link.token)
                links = links.filterNot { it.token == link.token }
                total = (total - 1).coerceAtLeast(0)
                host.showToast(cancelled)
                if (showing?.token == link.token) showing = null
            } catch (e: Exception) {
                host.showToast(e.apiFailure().message ?: networkError)
            }
            busy = false
            toDelete = null
        }
    }

    PrivacySettingsRow(Icons.Rounded.AddLink, stringResource(R.string.cl_create), subtitle = stringResource(R.string.cl_create_sub)) { picking = true }
    PrivacySettingsRow(
        Icons.Rounded.History,
        stringResource(R.string.cl_log_title),
        trailing = total.toString(),
        last = true,
    ) { logOpen = true }

    if (picking) CallLinkTypeSheet(onPick = { create(it) }, onDismiss = { picking = false })
    showing?.let { l ->
        CallLinkShareSheet(l, onCancelled = {
            links = links.filterNot { it.token == l.token }
            total = (total - 1).coerceAtLeast(0)
        }) { showing = null }
    }
    if (logOpen && showing == null) {
        CallLinksLogSheet(
            links = links,
            total = total,
            loadingMore = loadingMore,
            hasMore = hasMore,
            onLoadMore = { scope.launch { loadPage(reset = false) } },
            onDismiss = { logOpen = false },
            onOpen = { showing = it },
            onDelete = { toDelete = it },
        )
    }
    toDelete?.let { link ->
        ConfirmDialog(
            icon = Icons.Rounded.Delete,
            title = stringResource(R.string.cl_delete_title),
            message = stringResource(R.string.cl_delete_sub),
            confirmLabel = stringResource(R.string.ch_delete),
            loading = busy,
            onConfirm = { revoke(link) },
            onDismiss = { if (!busy) toDelete = null },
        )
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun CallLinksLogSheet(
    links: List<CallLinkDto>,
    total: Int,
    loadingMore: Boolean,
    hasMore: Boolean,
    onLoadMore: () -> Unit,
    onDismiss: () -> Unit,
    onOpen: (CallLinkDto) -> Unit,
    onDelete: (CallLinkDto) -> Unit,
) {
    val listState = rememberLazyListState()
    val dark = settingsNight()
    LaunchedEffect(listState, links.size, hasMore) {
        snapshotFlow {
            val info = listState.layoutInfo
            val last = info.visibleItemsInfo.lastOrNull()?.index ?: -1
            info.totalItemsCount > 0 && last >= info.totalItemsCount - 3
        }.distinctUntilChanged().filter { it }.collect { onLoadMore() }
    }
    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text(
                    stringResource(R.string.cl_log_title),
                    color = Ch.Ink,
                    fontSize = 18.sp,
                    fontWeight = FontWeight.ExtraBold,
                    modifier = Modifier.weight(1f),
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
                Text(total.toString(), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            }
            Spacer(Modifier.height(14.dp))
            if (links.isEmpty()) {
                Text(stringResource(R.string.cl_log_empty), color = Ch.Mut, fontSize = 13.sp)
            } else {
                LazyColumn(
                    state = listState,
                    modifier = Modifier
                        .fillMaxWidth()
                        .height(380.dp)
                        .settingsSurface(RoundedCornerShape(18.dp), 8.dp)
                        .padding(horizontal = 8.dp, vertical = 4.dp),
                ) {
                    itemsIndexed(links, key = { _, l -> l.token }) { i, l ->
                        Row(
                            Modifier.fillMaxWidth().clickable { onOpen(l) }.padding(horizontal = 4.dp, vertical = 12.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            PinkIcon(if (l.type == "video") Icons.Rounded.Videocam else Icons.Rounded.Call)
                            Spacer(Modifier.width(10.dp))
                            Column(Modifier.weight(1f).padding(end = 10.dp)) {
                                Text(
                                    stringResource(if (l.type == "video") R.string.cl_title_video else R.string.cl_title_voice),
                                    color = settingsInk(),
                                    fontWeight = FontWeight.Bold,
                                    fontSize = 13.5.sp,
                                    maxLines = 1,
                                    overflow = TextOverflow.Ellipsis,
                                )
                                Text(
                                    shortCallLink(l),
                                    color = settingsMut(),
                                    fontSize = 11.sp,
                                    maxLines = 1,
                                    overflow = TextOverflow.Ellipsis,
                                    modifier = Modifier.fillMaxWidth().padding(top = 2.dp),
                                )
                            }
                            Box(
                                Modifier.size(36.dp).clip(CircleShape).background(Ch.Danger.copy(alpha = 0.12f)).clickable { onDelete(l) },
                                contentAlignment = Alignment.Center,
                            ) {
                                Icon(Icons.Rounded.Delete, null, tint = Ch.Danger, modifier = Modifier.size(18.dp))
                            }
                        }
                        if (i != links.lastIndex) {
                            Box(Modifier.fillMaxWidth().height(1.dp).background(if (dark) AccountDark.line else Color(0xFFF6E4E8)))
                        }
                    }
                    if (loadingMore) item(key = "more") {
                        Box(Modifier.fillMaxWidth().padding(12.dp), contentAlignment = Alignment.Center) {
                            CircularProgressIndicator(color = Ch.Red, strokeWidth = 2.dp, modifier = Modifier.size(22.dp))
                        }
                    }
                }
            }
        }
    }
}

private fun shortCallLink(link: CallLinkDto): String = "/call/${link.token}"

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun CallLinkTypeSheet(onPick: (String) -> Unit, onDismiss: () -> Unit) {
    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Text(stringResource(R.string.cl_pick_type), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.cl_create_sub), color = Ch.Mut, fontSize = 13.5.sp, modifier = Modifier.padding(top = 4.dp))
            Spacer(Modifier.height(14.dp))
            CallTypePill(Icons.Rounded.Call, stringResource(R.string.cl_voice)) { onPick("audio"); onDismiss() }
            Spacer(Modifier.height(10.dp))
            CallTypePill(Icons.Rounded.Videocam, stringResource(R.string.cl_video)) { onPick("video"); onDismiss() }
        }
    }
}

@Composable
private fun CallTypePill(icon: ImageVector, label: String, onClick: () -> Unit) {
    Row(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(18.dp))
            .background(Ch.SurfaceMuted)
            .clickable(onClick = onClick)
            .padding(horizontal = 14.dp, vertical = 14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(label, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp, modifier = Modifier.weight(1f))
        Box(Modifier.size(44.dp).clip(CircleShape).background(Ch.Red.copy(alpha = 0.08f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = Ch.Red, modifier = Modifier.size(22.dp))
        }
    }
}

@Composable
private fun LinkRow(icon: ImageVector, title: String, sub: String?, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 14.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(title, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            sub?.let { Text(it, color = Ch.Mut, fontSize = 12.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis) }
        }
        Spacer(Modifier.width(12.dp))
        Box(Modifier.size(44.dp).clip(CircleShape).background(Ch.Red.copy(alpha = 0.08f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = Ch.Red, modifier = Modifier.size(22.dp))
        }
    }
}

/** My link: copy, share, join now, cancel it. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun CallLinkShareSheet(link: CallLinkDto, onCancelled: () -> Unit, onDismiss: () -> Unit) {
    val host = LocalChat.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val copied = stringResource(R.string.ch_link_copied)
    val networkError = stringResource(R.string.ch_error_network)
    val shareText = stringResource(R.string.cl_share_text, link.link)
    val join = rememberCallLinkJoiner(onDismiss)
    var confirming by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }

    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Text(stringResource(if (link.type == "video") R.string.cl_title_video else R.string.cl_title_voice), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(12.dp))
            Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Ch.SurfaceMuted).padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.Link, null, tint = Ch.Red, modifier = Modifier.size(22.dp))
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(link.link, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 13.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    link.expiresAt?.let { Text(stringResource(R.string.cl_works_until, scheduleLabel(it)), color = Ch.Mut, fontSize = 12.sp) }
                }
            }
            Spacer(Modifier.height(10.dp))
            LinkRow(Icons.Rounded.ContentCopy, stringResource(R.string.cl_copy_link), null) {
                (context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager).setPrimaryClip(ClipData.newPlainText("call", link.link))
                host.showToast(copied)
            }
            LinkRow(Icons.Rounded.Share, stringResource(R.string.cl_share_link), null) {
                context.startActivity(Intent.createChooser(Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(Intent.EXTRA_TEXT, shareText), null))
            }
            LinkRow(if (link.type == "video") Icons.Rounded.Videocam else Icons.Rounded.Call, stringResource(R.string.cl_join), null) { join(link) }
            LinkRow(Icons.Rounded.LinkOff, stringResource(R.string.cl_cancel), null) { confirming = true }
        }
    }
    if (confirming) {
        ConfirmDialog(
            icon = Icons.Rounded.LinkOff,
            title = stringResource(R.string.cl_cancel),
            message = stringResource(R.string.cl_delete_sub),
            confirmLabel = stringResource(R.string.cl_cancel),
            loading = busy,
            onConfirm = {
                busy = true
                scope.launch {
                    try {
                        ApiClient.chat.revokeCallLink(chatAuth(), link.token)
                        onCancelled()
                        confirming = false
                        onDismiss()
                    } catch (e: Exception) {
                        host.showToast(e.apiFailure().message ?: networkError)
                    }
                    busy = false
                }
            },
            onDismiss = { if (!busy) confirming = false },
        )
    }
}

/** A call link someone sent me (or mine, opened from a notification): who made it, then "Join". */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CallLinkSheet(token: String, onDismiss: () -> Unit) {
    var link by remember { mutableStateOf<CallLinkDto?>(null) }
    var failed by remember { mutableStateOf<String?>(null) }
    val join = rememberCallLinkJoiner(onDismiss)
    LaunchedEffect(token) {
        try {
            link = ApiClient.chat.callLink(chatAuth(), token).data
        } catch (e: Exception) {
            failed = e.apiFailure().message ?: ""
        }
    }
    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 24.dp).padding(bottom = 30.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            val l = link
            when {
                failed != null -> {
                    Icon(Icons.Rounded.SentimentDissatisfied, null, tint = Ch.Mut, modifier = Modifier.size(56.dp))
                    Spacer(Modifier.height(12.dp))
                    Text(failed.orEmpty().ifBlank { stringResource(R.string.ch_error_network) }, color = Ch.Ink, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center)
                }
                l == null -> Box(Modifier.height(180.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(28.dp), color = Ch.Red, strokeWidth = 2.5.dp) }
                else -> {
                    val pop = remember { Animatable(0.6f) }
                    LaunchedEffect(Unit) { pop.animateTo(1f, spring(dampingRatio = 0.45f, stiffness = 300f)) }
                    Box(Modifier.scale(pop.value)) { ChAvatar(l.owner?.avatar, l.owner?.name, l.owner?.key, size = 92.dp) }
                    Spacer(Modifier.height(12.dp))
                    Text(stringResource(if (l.type == "video") R.string.cl_title_video else R.string.cl_title_voice), color = Ch.Ink, fontSize = 20.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center)
                    l.owner?.name?.let { Text(stringResource(R.string.cl_from, it), color = Ch.Mut, fontSize = 14.sp, modifier = Modifier.padding(top = 4.dp)) }
                    Spacer(Modifier.height(20.dp))
                    ChPrimaryButton(stringResource(R.string.cl_join), icon = if (l.type == "video") Icons.Rounded.Videocam else Icons.Rounded.Call, modifier = Modifier.fillMaxWidth()) { join(l) }
                }
            }
        }
    }
}

/**
 * Join a call link: the microphone (and the camera for video) first, then into the room. Busy in
 * another call: say so instead.
 */
@Composable
private fun rememberCallLinkJoiner(onDone: () -> Unit): (CallLinkDto) -> Unit {
    val host = LocalChat.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val networkError = stringResource(R.string.ch_error_network)
    val busy = stringResource(R.string.cl_in_call)
    var pending by remember { mutableStateOf<CallLinkDto?>(null) }

    fun go(l: CallLinkDto) {
        if (CallController.phase != null && CallController.phase != CallPhase.Ended) {
            host.showToast(busy)
            return
        }
        scope.launch {
            try {
                val res = ApiClient.chat.joinCallLink(chatAuth(), l.token).data ?: return@launch
                val owner = res.link.owner
                // My own link: the call is named after the link; someone else's: after them.
                val title = if (res.link.isMine) context.getString(if (l.type == "video") R.string.cl_title_video else R.string.cl_title_voice) else owner?.name.orEmpty()
                CallController.joinLink(l.token, res.join, l.type == "video", title, owner?.avatar)
                onDone()
            } catch (e: Exception) {
                host.showToast(e.apiFailure().message ?: networkError)
            }
        }
    }

    val ask = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { granted ->
        val l = pending
        pending = null
        if (l != null && granted[Manifest.permission.RECORD_AUDIO] == true) go(l)
    }
    return { l ->
        val needed = buildList {
            add(Manifest.permission.RECORD_AUDIO)
            if (l.type == "video") add(Manifest.permission.CAMERA)
        }.filter { ContextCompat.checkSelfPermission(context, it) != PackageManager.PERMISSION_GRANTED }
        if (needed.isEmpty()) go(l) else {
            pending = l
            ask.launch(needed.toTypedArray())
        }
    }
}
