@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.dorr.app.ui.screens.watchearn

import androidx.annotation.StringRes
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateContentSize
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyListScope
import androidx.compose.foundation.lazy.LazyListState
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.ChatBubbleOutline
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Flag
import androidx.compose.material.icons.rounded.MoreVert
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshotFlow
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.chat.ChatEvent
import com.dorr.app.chat.ChatRealtime
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.WeCommentDto
import com.dorr.app.network.WeCommentRequest
import com.dorr.app.network.WeReactionRequest
import com.dorr.app.network.WeReportRequest
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.locale.LocaleAwareDialog
import com.dorr.app.ui.screens.chat.ChSheet
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.google.gson.Gson
import com.google.gson.JsonElement
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.launch

/** Reasons a viewer reports an ad with (AdReport::REASONS). */
private val AdReportReasons = listOf(
    "misleading" to R.string.we_reason_misleading,
    "offensive" to R.string.we_reason_offensive,
    "adult" to R.string.we_reason_adult,
    "violence" to R.string.we_reason_violence,
    "scam" to R.string.we_reason_scam,
    "other" to R.string.we_reason_other,
)

/** Reasons the advertiser reports a comment with (CommentReport::REASONS). */
private val CommentReportReasons = listOf(
    "offensive" to R.string.we_reason_offensive,
    "spam" to R.string.we_reason_spam,
    "misleading" to R.string.we_reason_misleading,
    "harassment" to R.string.we_reason_harassment,
    "other" to R.string.we_reason_other,
)

/** emoji → count from a real-time payload (`{}` or an empty PHP `[]`). */
private fun reactionsOf(element: JsonElement?): Map<String, Int>? {
    if (element == null || element.isJsonNull) return null
    if (element.isJsonArray) return emptyMap()
    if (!element.isJsonObject) return null
    return element.asJsonObject.entrySet().associate { (k, v) -> k to (runCatching { v.asInt }.getOrNull() ?: 0) }
}

/**
 * One ad's comments, live (WE-033) — shared by the comments sheet and the ad's page: the list (cursor pages), the
 * reaction counts with my own, and the composer's text. [rememberWeComments] loads it once and, while it shows,
 * listens to `private-watch-earn.ad.{id}`: a new comment comes in at the top, a deleted one goes, the counts follow.
 */
@Stable
class WeComments internal constructor(val adId: String, private val host: WeHost, private val scope: CoroutineScope) {
    val comments = mutableStateListOf<WeCommentDto>()
    var nextCursor by mutableStateOf<String?>(null)
        private set
    var loaded by mutableStateOf(false)
        private set
    var loadingMore by mutableStateOf(false)
        private set
    var reactions by mutableStateOf<Map<String, Int>>(emptyMap())
        private set
    var myReaction by mutableStateOf<String?>(null)
        private set
    var text by mutableStateOf("")
    var sending by mutableStateOf(false)
        private set

    /** My comment waiting for the «delete?» answer. */
    var deleteFor by mutableStateOf<WeCommentDto?>(null)

    internal var generic = ""

    /** The first comment or reaction (it earns the interaction reward while the window is open). */
    internal var onInteracted: () -> Unit = {}

    internal suspend fun load() {
        runCatching { ApiClient.watchEarn.comments(weAuth(), adId).data }
            .onSuccess { page ->
                if (page != null) {
                    comments.clear()
                    comments.addAll(page.comments)
                    nextCursor = page.nextCursor
                    reactions = page.reactions
                    myReaction = page.myReaction
                }
            }
            .onFailure { host.showToast(it.weMessage(generic), error = true) }
        loaded = true
    }

    internal suspend fun loadMore() {
        val cursor = nextCursor ?: return
        if (loadingMore) return
        loadingMore = true
        runCatching { ApiClient.watchEarn.comments(weAuth(), adId, cursor).data }.getOrNull()?.let { page ->
            page.comments.forEach { c -> if (comments.none { it.id == c.id }) comments.add(c) }
            nextCursor = page.nextCursor
        }
        loadingMore = false
    }

    internal fun onEvent(event: ChatEvent, gson: Gson) {
        val data = event.data
        if (data.get("ad_id")?.takeIf { it.isJsonPrimitive }?.asString != adId) return
        when (event.name) {
            "watch_earn.comment" -> {
                val c = runCatching { gson.fromJson(data.get("comment"), WeCommentDto::class.java) }.getOrNull() ?: return
                if (comments.none { it.id == c.id }) comments.add(0, c)
            }
            "watch_earn.comment_deleted" -> {
                val id = data.get("comment_id")?.takeIf { it.isJsonPrimitive }?.let { runCatching { it.asLong }.getOrNull() } ?: return
                comments.removeAll { it.id == id }
            }
            "watch_earn.reactions" -> reactionsOf(data.get("reactions"))?.let { reactions = it }
            else -> Unit
        }
    }

    /** A tap sets my reaction; a tap on mine takes it back. */
    fun react(emoji: String) {
        scope.launch {
            try {
                val before = myReaction
                val result = if (before == emoji) ApiClient.watchEarn.unreact(weAuth(), adId).data else ApiClient.watchEarn.react(weAuth(), adId, WeReactionRequest(emoji)).data
                if (result != null) {
                    reactions = result.reactions
                    myReaction = result.myReaction
                    if (before == null && result.myReaction != null) onInteracted()
                }
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                host.showToast(e.weMessage(generic), error = true)
            }
        }
    }

    /** Sends the composer's text; [onSent] runs once it is on the list (to scroll to it). */
    fun send(onSent: suspend () -> Unit = {}) {
        val body = text.trim()
        if (body.isEmpty() || sending) return
        sending = true
        scope.launch {
            try {
                val c = ApiClient.watchEarn.comment(weAuth(), adId, WeCommentRequest(body)).data
                text = ""
                if (c != null && comments.none { it.id == c.id }) comments.add(0, c)
                onInteracted()
                sending = false
                onSent()
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                host.showToast(e.weMessage(generic), error = true)
            }
            sending = false
        }
    }

    fun delete(c: WeCommentDto) {
        deleteFor = null
        scope.launch {
            try {
                val response = ApiClient.watchEarn.deleteComment(weAuth(), adId, c.id)
                if (!response.isSuccessful) throw retrofit2.HttpException(response)
                comments.removeAll { it.id == c.id }
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                host.showToast(e.weMessage(generic), error = true)
            }
        }
    }
}

/**
 * The comments of [adId], loaded once and live while the caller shows: no timer, only what the server pushes. The
 * next page comes when the end of [listState] scrolls into view.
 */
@Composable
fun rememberWeComments(adId: String, listState: LazyListState, onInteracted: () -> Unit = {}): WeComments {
    val host = LocalWe.current
    val scope = rememberCoroutineScope()
    val state = remember(adId) { WeComments(adId, host, scope) }
    state.generic = stringResource(R.string.we_error_generic)
    state.onInteracted = onInteracted

    LaunchedEffect(state) { state.load() }
    DisposableEffect(adId) {
        ChatRealtime.watchWatchEarnAd(adId)
        onDispose { ChatRealtime.unwatchWatchEarnAd(adId) }
    }
    LaunchedEffect(state) {
        val gson = Gson()
        ChatRealtime.events.collect { state.onEvent(it, gson) }
    }
    // The next page when the end of the list comes into view (the person scrolled — not a timer).
    LaunchedEffect(listState, state, state.nextCursor) {
        snapshotFlow { listState.layoutInfo.visibleItemsInfo.lastOrNull()?.index to listState.layoutInfo.totalItemsCount }
            .collect { (last, total) ->
                if (state.nextCursor != null && !state.loadingMore && last != null && total > 0 && last >= total - 2) state.loadMore()
            }
    }
    return state
}

/** The reaction counts; mine is outlined. A tap sets it, a tap on mine takes it back. */
@Composable
fun WeReactionCounts(state: WeComments, modifier: Modifier = Modifier) {
    Row(modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        WeQuickReactions.forEach { emoji ->
            val mine = state.myReaction == emoji
            val count = state.reactions[emoji] ?: 0
            Row(
                Modifier.weight(1f).clip(RoundedCornerShape(14.dp))
                    .background(if (mine) Wa.Red.copy(alpha = 0.14f) else Wa.Field)
                    .border(1.dp, if (mine) Wa.Red.copy(alpha = 0.5f) else Color.Transparent, RoundedCornerShape(14.dp))
                    .clickable { state.react(emoji) }
                    .padding(vertical = 7.dp)
                    .animateContentSize(),
                horizontalArrangement = Arrangement.Center,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(emoji, fontSize = 17.sp)
                if (count > 0) {
                    Spacer(Modifier.width(3.dp))
                    Text(count.toString(), color = if (mine) Wa.Red else Wa.Mut, fontSize = 11.sp, fontWeight = FontWeight.Bold)
                }
            }
        }
    }
}

/**
 * The comments as list items: skeletons while loading, the empty state, the rows, and the next page's spinner.
 * Long-press my comment to delete it; ⋮ on someone else's reports the ad (when [onReportAd] is given).
 */
fun LazyListScope.weCommentItems(state: WeComments, onReportAd: (() -> Unit)?) {
    val me = AuthSession.user?.id
    when {
        !state.loaded -> items(3) { WaSkeleton(Modifier.fillMaxWidth().padding(vertical = 5.dp).height(48.dp)) }
        state.comments.isEmpty() -> item(key = "we_no_comments") {
            Column(Modifier.fillMaxWidth().padding(vertical = 30.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                Icon(Icons.Rounded.ChatBubbleOutline, null, tint = Wa.Soft, modifier = Modifier.size(34.dp))
                Spacer(Modifier.height(8.dp))
                Text(stringResource(R.string.we_no_comments), color = Wa.Mut, fontSize = 13.sp, textAlign = TextAlign.Center)
            }
        }
        else -> {
            items(state.comments, key = { it.id }) { c ->
                val mine = me != null && c.user?.id == me
                WeCommentRow(
                    comment = c,
                    modifier = Modifier.animateItem(fadeInSpec = tween(240), placementSpec = spring(dampingRatio = 0.8f, stiffness = 380f)),
                    onLongPress = if (mine) ({ state.deleteFor = c }) else null,
                    menuLabel = if (!mine && onReportAd != null) R.string.we_report_ad else null,
                    onMenu = if (!mine && onReportAd != null) onReportAd else null,
                )
            }
            if (state.loadingMore) {
                item(key = "we_comments_more") {
                    Box(Modifier.fillMaxWidth().padding(12.dp), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator(color = Wa.Red, strokeWidth = 2.dp, modifier = Modifier.size(22.dp))
                    }
                }
            }
        }
    }
}

/** «Write a comment» and its send button, which pops in once there is text. */
@Composable
fun WeCommentComposer(state: WeComments, modifier: Modifier = Modifier, onSent: suspend () -> Unit = {}) {
    Row(modifier, verticalAlignment = Alignment.CenterVertically) {
        DorrTextField(
            value = state.text,
            onValueChange = { if (it.length <= 1000) state.text = it },
            placeholder = stringResource(R.string.we_write_comment),
            singleLine = false,
            keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Sentences),
            modifier = Modifier.weight(1f),
        )
        AnimatedVisibility(state.text.isNotBlank(), enter = scaleIn(spring(dampingRatio = 0.45f)) + fadeIn(), exit = scaleOut() + fadeOut()) {
            Box(
                Modifier.padding(start = 8.dp).size(46.dp).clip(CircleShape).background(Wa.ButtonBrush).clickable { state.send(onSent) },
                contentAlignment = Alignment.Center,
            ) {
                if (state.sending) CircularProgressIndicator(color = Color.White, strokeWidth = 2.dp, modifier = Modifier.size(20.dp))
                else Icon(Icons.AutoMirrored.Rounded.Send, stringResource(R.string.we_send), tint = Color.White, modifier = Modifier.size(20.dp))
            }
        }
    }
}

/** «Delete this comment?» for the comment long-pressed in [state]. */
@Composable
fun WeDeleteCommentDialog(state: WeComments) {
    val c = state.deleteFor ?: return
    WeConfirmDialog(
        title = stringResource(R.string.we_delete_comment_title),
        text = stringResource(R.string.we_delete_comment_text),
        confirm = stringResource(R.string.we_delete),
        danger = true,
        onDismiss = { state.deleteFor = null },
        onConfirm = { state.delete(c) },
    )
}

/**
 * One ad's comments in a bottom sheet: the reaction counts, the live list and the composer ([WeComments]).
 * Long-press my comment to delete it; ⋮ on someone else's reports the ad.
 */
@Composable
fun WatchEarnCommentsSheet(
    adId: String,
    onDismiss: () -> Unit,
    onInteracted: () -> Unit = {},
    onReportAd: (() -> Unit)? = null,
) {
    val listState = rememberLazyListState()
    val state = rememberWeComments(adId, listState, onInteracted)

    ChSheet(onDismissRequest = onDismiss, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().imePadding().navigationBarsPadding().padding(horizontal = 16.dp).padding(bottom = 12.dp)) {
            Text(stringResource(R.string.we_comments), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(10.dp))
            WeReactionCounts(state)
            Spacer(Modifier.height(10.dp))
            Box(Modifier.fillMaxWidth().heightIn(min = 160.dp, max = 420.dp)) {
                LazyColumn(state = listState) { weCommentItems(state, onReportAd) }
            }
            Spacer(Modifier.height(8.dp))
            WeCommentComposer(state) { listState.animateScrollToItem(0) }
        }
    }

    WeDeleteCommentDialog(state)
}

/** One comment: avatar, name, when, the text — long-press (mine) or ⋮ (someone else's) when given. */
@OptIn(ExperimentalFoundationApi::class)
@Composable
fun WeCommentRow(
    comment: WeCommentDto,
    modifier: Modifier = Modifier,
    onLongPress: (() -> Unit)? = null,
    @StringRes menuLabel: Int? = null,
    onMenu: (() -> Unit)? = null,
) {
    var menu by remember { mutableStateOf(false) }
    Row(
        modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(14.dp))
            .combinedClickable(onClick = {}, onLongClick = onLongPress)
            .padding(vertical = 8.dp, horizontal = 2.dp),
        verticalAlignment = Alignment.Top,
    ) {
        Box(Modifier.size(36.dp).clip(CircleShape).background(Wa.Red.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
            val url = ApiClient.mediaUrl(comment.user?.avatar)
            if (url != null) AsyncImage(url, null, contentScale = ContentScale.Crop, modifier = Modifier.size(36.dp))
            else {
                val initial = comment.user?.name?.trim()?.firstOrNull()?.toString()
                if (initial != null) Text(initial, color = Wa.Red, fontWeight = FontWeight.ExtraBold, fontSize = 14.sp)
                else Icon(Icons.Rounded.Person, null, tint = Wa.Red, modifier = Modifier.size(18.dp))
            }
        }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(comment.user?.name.orEmpty(), color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f, fill = false))
                Spacer(Modifier.width(8.dp))
                Text(weDateTime(comment.createdAt), color = Wa.Soft, fontSize = 10.5.sp)
            }
            Text(comment.body, color = Wa.Ink, fontSize = 13.5.sp, lineHeight = 20.sp, modifier = Modifier.padding(top = 2.dp))
        }
        if (onMenu != null && menuLabel != null) {
            Box {
                Icon(
                    Icons.Rounded.MoreVert, stringResource(R.string.we_more), tint = Wa.Mut,
                    modifier = Modifier.size(32.dp).clip(CircleShape).clickable { menu = true }.padding(6.dp),
                )
                DropdownMenu(menu, onDismissRequest = { menu = false }, containerColor = Wa.Surface, shape = RoundedCornerShape(16.dp)) {
                    DropdownMenuItem(
                        text = { Text(stringResource(menuLabel), color = Wa.Ink) },
                        leadingIcon = { Icon(Icons.Rounded.Flag, null, tint = Wa.Danger) },
                        onClick = { menu = false; onMenu() },
                    )
                }
            }
        }
    }
}

// ------------------------------------------------------------------------------- report sheets

/** The viewer reports the ad (one report per viewer). [onReported] gets the server's message. */
@Composable
fun WeReportAdSheet(adId: String, onDismiss: () -> Unit, onReported: (String) -> Unit) {
    WeReasonSheet(
        title = stringResource(R.string.we_report_ad),
        reasons = AdReportReasons,
        onDismiss = onDismiss,
        submit = { reason, note -> ApiClient.watchEarn.reportAd(weAuth(), adId, WeReportRequest(reason, note)).message },
        onDone = onReported,
    )
}

/** The advertiser reports a comment on their ad — they can't delete it (D21). */
@Composable
fun WeReportCommentSheet(adId: String, commentId: Long, onDismiss: () -> Unit, onReported: (String) -> Unit) {
    WeReasonSheet(
        title = stringResource(R.string.we_report_comment),
        reasons = CommentReportReasons,
        onDismiss = onDismiss,
        submit = { reason, note -> ApiClient.watchEarn.reportComment(weAuth(), adId, commentId, WeReportRequest(reason, note)).message },
        onDone = onReported,
    )
}

@Composable
private fun WeReasonSheet(
    title: String,
    reasons: List<Pair<String, Int>>,
    onDismiss: () -> Unit,
    submit: suspend (reason: String, note: String?) -> String,
    onDone: (String) -> Unit,
) {
    val host = LocalWe.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.we_error_generic)
    var reason by remember { mutableStateOf<String?>(null) }
    var note by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    ChSheet(onDismissRequest = onDismiss, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).imePadding().navigationBarsPadding()
                .padding(horizontal = 20.dp).padding(bottom = 20.dp),
        ) {
            Text(title, color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.we_report_hint), color = Wa.Mut, fontSize = 12.5.sp, modifier = Modifier.padding(top = 4.dp, bottom = 12.dp))
            reasons.forEach { (key, label) ->
                val picked = reason == key
                Row(
                    Modifier.fillMaxWidth().padding(vertical = 4.dp).clip(RoundedCornerShape(14.dp))
                        .background(if (picked) Wa.Red.copy(alpha = 0.1f) else Wa.Field)
                        .border(1.dp, if (picked) Wa.Red.copy(alpha = 0.5f) else Color.Transparent, RoundedCornerShape(14.dp))
                        .clickable { reason = key }
                        .padding(horizontal = 14.dp, vertical = 12.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Box(
                        Modifier.size(18.dp).clip(CircleShape).border(2.dp, if (picked) Wa.Red else Wa.Soft, CircleShape),
                        contentAlignment = Alignment.Center,
                    ) { androidx.compose.animation.AnimatedVisibility(picked, enter = scaleIn(), exit = scaleOut()) { Box(Modifier.size(9.dp).clip(CircleShape).background(Wa.Red)) } }
                    Spacer(Modifier.width(12.dp))
                    Text(stringResource(label), color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.Bold)
                }
            }
            Spacer(Modifier.height(8.dp))
            DorrTextField(
                value = note,
                onValueChange = { if (it.length <= 500) note = it },
                placeholder = stringResource(R.string.we_report_note),
                singleLine = false,
                minLines = 2,
            )
            Spacer(Modifier.height(14.dp))
            WaButton(
                stringResource(R.string.we_send_report),
                onClick = {
                    val r = reason ?: return@WaButton
                    busy = true
                    scope.launch {
                        try {
                            val message = submit(r, note.trim().ifBlank { null })
                            onDone(message)
                        } catch (e: CancellationException) {
                            throw e
                        } catch (e: Exception) {
                            host.showToast(e.weMessage(generic), error = true)
                        }
                        busy = false
                    }
                },
                enabled = reason != null,
                loading = busy,
                icon = Icons.Rounded.Flag,
            )
        }
    }
}

// ------------------------------------------------------------------------------- dialogs

/** A yes / no question in the app's language (a stop that can't be undone, deleting a comment). */
@Composable
fun WeConfirmDialog(title: String, text: String, confirm: String, onConfirm: () -> Unit, onDismiss: () -> Unit, danger: Boolean = false) {
    LocaleAwareDialog(onDismissRequest = onDismiss) {
        Column(Modifier.clip(RoundedCornerShape(24.dp)).background(Wa.Surface).padding(22.dp)) {
            Text(title, color = Wa.Ink, fontSize = 17.sp, fontWeight = FontWeight.ExtraBold)
            Text(text, color = Wa.Mut, fontSize = 13.5.sp, lineHeight = 21.sp, modifier = Modifier.padding(top = 8.dp, bottom = 18.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                WaButton(stringResource(R.string.we_cancel), onDismiss, style = WaButtonStyle.Ghost, compact = true, modifier = Modifier.weight(1f))
                Box(Modifier.weight(1f)) {
                    if (danger) {
                        Box(
                            Modifier.fillMaxWidth().height(40.dp).clip(RoundedCornerShape(18.dp)).background(Wa.Danger).clickable(onClick = onConfirm),
                            contentAlignment = Alignment.Center,
                        ) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Icon(Icons.Rounded.Delete, null, tint = Color.White, modifier = Modifier.size(16.dp))
                                Spacer(Modifier.width(6.dp))
                                Text(confirm, color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
                            }
                        }
                    } else {
                        WaButton(confirm, onConfirm, compact = true)
                    }
                }
            }
        }
    }
}
