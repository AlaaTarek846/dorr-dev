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
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AddLink
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.ContentCopy
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
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
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
import kotlinx.coroutines.launch

/*
 * Call links (WhatsApp list 72): make a link for a voice or video call, send it anywhere, and
 * whoever opens it in DORR joins the same call — nobody is rung. I hear when someone joins.
 */

/** On the calls page: "Create call link", then my links that still work. */
@Composable
internal fun CallLinksCard() {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var links by remember { mutableStateOf<List<CallLinkDto>>(emptyList()) }
    var picking by remember { mutableStateOf(false) }
    var showing by remember { mutableStateOf<CallLinkDto?>(null) }
    val networkError = stringResource(R.string.ch_error_network)
    LaunchedEffect(Unit) { links = runCatching { ApiClient.chat.callLinks(chatAuth()).data }.getOrNull().orEmpty() }

    fun create(type: String) {
        scope.launch {
            try {
                val made = ApiClient.chat.createCallLink(chatAuth(), mapOf("type" to type)).data ?: return@launch
                links = listOf(made) + links
                showing = made
            } catch (e: Exception) {
                host.showToast(e.apiFailure().message ?: networkError)
            }
        }
    }

    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Ch.Surface).padding(vertical = 4.dp)) {
        LinkRow(Icons.Rounded.AddLink, stringResource(R.string.cl_create), stringResource(R.string.cl_create_sub)) { picking = true }
        links.forEach { l ->
            LinkRow(
                if (l.type == "video") Icons.Rounded.Videocam else Icons.Rounded.Call,
                stringResource(if (l.type == "video") R.string.cl_title_video else R.string.cl_title_voice),
                l.link,
            ) { showing = l }
        }
    }

    if (picking) ChoiceSheet(
        stringResource(R.string.cl_pick_type),
        listOf(stringResource(R.string.cl_voice) to { create("audio") }, stringResource(R.string.cl_video) to { create("video") }),
        subtitle = stringResource(R.string.cl_create_sub),
        onDismiss = { picking = false },
    )
    showing?.let { l ->
        CallLinkShareSheet(l, onCancelled = { links = links.filterNot { it.token == l.token } }) { showing = null }
    }
}

@Composable
private fun LinkRow(icon: ImageVector, title: String, sub: String?, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 14.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(44.dp).clip(CircleShape).background(Ch.Red.copy(alpha = 0.08f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = Ch.Red, modifier = Modifier.size(22.dp))
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(title, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            sub?.let { Text(it, color = Ch.Mut, fontSize = 12.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis) }
        }
    }
}

/** My link: copy, share, join now, cancel it. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun CallLinkShareSheet(link: CallLinkDto, onCancelled: () -> Unit, onDismiss: () -> Unit) {
    val host = LocalChat.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val copied = stringResource(R.string.ch_link_copied)
    val cancelled = stringResource(R.string.cl_cancelled)
    val networkError = stringResource(R.string.ch_error_network)
    val shareText = stringResource(R.string.cl_share_text, link.link)
    val join = rememberCallLinkJoiner(onDismiss)

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
            LinkRow(Icons.Rounded.ContentCopy, stringResource(R.string.ch_copy_link), null) {
                (context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager).setPrimaryClip(ClipData.newPlainText("call", link.link))
                host.showToast(copied)
            }
            LinkRow(Icons.Rounded.Share, stringResource(R.string.ch_share_link), null) {
                context.startActivity(Intent.createChooser(Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(Intent.EXTRA_TEXT, shareText), null))
            }
            LinkRow(if (link.type == "video") Icons.Rounded.Videocam else Icons.Rounded.Call, stringResource(R.string.cl_join), null) { join(link) }
            LinkRow(Icons.Rounded.LinkOff, stringResource(R.string.cl_cancel), null) {
                scope.launch {
                    try {
                        ApiClient.chat.revokeCallLink(chatAuth(), link.token)
                        onCancelled()
                        host.showToast(cancelled)
                        onDismiss()
                    } catch (e: Exception) {
                        host.showToast(e.apiFailure().message ?: networkError)
                    }
                }
            }
        }
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
