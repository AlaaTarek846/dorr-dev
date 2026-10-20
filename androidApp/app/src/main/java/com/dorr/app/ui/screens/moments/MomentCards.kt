package com.dorr.app.ui.screens.moments

import android.Manifest
import android.widget.Toast
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.shrinkVertically
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.defaultMinSize
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.Pause
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.Photo
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.Redeem
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Send
import androidx.compose.material.icons.rounded.Stop
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.rotate
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Shadow
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.chat.VoiceClip
import com.dorr.app.chat.VoicePlayer
import com.dorr.app.chat.VoiceRecorder
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AppLocale
import com.dorr.app.network.CardLookDto
import com.dorr.app.network.ContactDto
import com.dorr.app.network.MessageDto
import com.dorr.app.network.OpenDirectRequest
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.AccountDark
import com.dorr.app.ui.screens.chat.Ch
import com.dorr.app.ui.screens.chat.arr
import com.dorr.app.ui.screens.chat.int
import com.dorr.app.ui.screens.chat.long
import com.dorr.app.ui.screens.chat.obj
import com.dorr.app.ui.screens.chat.LocalFile
import com.dorr.app.ui.screens.chat.ScheduleSheet
import com.dorr.app.ui.screens.chat.chatAuth
import com.dorr.app.ui.screens.chat.compressImage
import com.dorr.app.ui.screens.chat.copyToCache
import com.dorr.app.ui.screens.profile.PinkIcon
import com.dorr.app.ui.screens.profile.RedToggle
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.screens.profile.settingsSurface
import com.dorr.app.ui.screens.wallet.PadResult
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaError
import com.dorr.app.ui.screens.wallet.WaPinPad
import com.dorr.app.ui.screens.wallet.formatMinor
import com.dorr.app.ui.screens.wallet.parseAmountToMinor
import com.google.gson.Gson
import com.google.gson.JsonElement
import com.google.gson.JsonObject
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.time.Duration
import java.time.OffsetDateTime
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import com.dorr.app.ui.screens.chat.ChSheet

/*
 * Greeting cards (DORR Moments, spec 161–167): the composer — occasion look, my words (or three
 * AI suggestions to start from), my own voice, photos, a surprise time, a time in the recipient's
 * day, a wallet gift — and the card as it shows in the chat.
 */

/** Where the composer starts: an occasion, one of my own kinds, a title. */
data class CardPreset(val momentId: Int? = null, val personalKind: String? = null, val title: String? = null)

/** My own kinds — the same looks the server gives them. */
internal val PersonalLooks = mapOf(
    "birthday" to CardLookDto(kind = "personal", theme = "birthday", primaryColor = "#EC4899", secondaryColor = "#8B5CF6", emoji = "🎂", animation = "balloons"),
    "anniversary" to CardLookDto(kind = "personal", theme = "anniversary", primaryColor = "#BE123C", secondaryColor = "#FDA4AF", emoji = "💍", animation = "hearts"),
    "graduation" to CardLookDto(kind = "personal", theme = "graduation", primaryColor = "#1E3A8A", secondaryColor = "#FBBF24", emoji = "🎓", animation = "confetti"),
    "wedding" to CardLookDto(kind = "personal", theme = "wedding", primaryColor = "#9D174D", secondaryColor = "#FBCFE8", emoji = "💒", animation = "petals"),
    "baby" to CardLookDto(kind = "personal", theme = "baby", primaryColor = "#0EA5E9", secondaryColor = "#FBCFE8", emoji = "👶", animation = "balloons"),
    "other" to CardLookDto(kind = "personal", theme = "other", primaryColor = "#001B53", secondaryColor = "#FA7552", emoji = "✨", animation = "confetti"),
)

/** One thing to make a card for: an occasion from the catalog, or one of my kinds. */
internal data class CardChoice(val momentId: Int?, val kind: String?, val name: String, val look: CardLookDto)

@Composable
internal fun rememberCardChoices(): List<CardChoice> {
    LaunchedEffect(Unit) { if (MomentsStore.center == null) MomentsStore.refresh() }
    val center = MomentsStore.center
    val personalNames = PersonalLooks.keys.associateWith { stringResource(personalKindLabel(it)) }
    return remember(center, personalNames) {
        val occasions = (center?.active.orEmpty() + center?.upcoming.orEmpty()).distinctBy { it.id }.map {
            CardChoice(it.id, null, it.name.orEmpty(), CardLookDto(momentId = it.id, kind = it.kind, theme = it.theme, title = it.name, primaryColor = it.primaryColor, secondaryColor = it.secondaryColor, emoji = it.emoji, animation = it.animation, cardImage = it.cardImage))
        }
        occasions + PersonalLooks.map { (k, look) -> CardChoice(null, k, personalNames[k].orEmpty(), look) }
    }
}

internal fun personalKindLabel(kind: String): Int = when (kind) {
    "birthday" -> R.string.mo_kind_birthday
    "anniversary" -> R.string.mo_kind_anniversary
    "graduation" -> R.string.mo_kind_graduation
    "wedding" -> R.string.mo_kind_wedding
    "baby" -> R.string.mo_kind_baby
    else -> R.string.mo_kind_other
}

private fun textPart(v: String): RequestBody = v.toRequestBody("text/plain".toMediaType())

private val sendAtFormat: DateTimeFormatter = DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm")

private fun whenText(at: ZonedDateTime): String = at.format(DateTimeFormatter.ofLocalizedDateTime(FormatStyle.MEDIUM, FormatStyle.SHORT).withLocale(AppLocale.java()))

// =============================================================================== the composer

/**
 * Make a card. With no [conversationId] it asks who for first (a saved contact → our chat).
 * [canGift]: a one-to-one chat, where the wallet can send money.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun MomentCardSheet(
    conversationId: String?,
    peerName: String?,
    canGift: Boolean,
    preset: CardPreset? = null,
    toUser: com.dorr.app.network.ProfileDto? = null,
    onDismiss: () -> Unit,
    onSent: (MessageDto?) -> Unit = {},
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var chatId by remember { mutableStateOf(conversationId) }
    var name by remember { mutableStateOf(peerName) }
    var direct by remember { mutableStateOf(canGift) }
    // From a date of mine tied to a person: straight to our chat.
    LaunchedEffect(toUser) {
        if (chatId == null && toUser != null) {
            runCatching { ApiClient.chat.openDirect(chatAuth(), OpenDirectRequest(toUser.id, toUser.type)).data }.getOrNull()?.let { chatId = it.id; name = toUser.name; direct = true }
        }
    }

    val choices = rememberCardChoices()
    var choice by remember { mutableStateOf<CardChoice?>(null) }
    LaunchedEffect(choices) {
        if (choice == null && choices.isNotEmpty()) {
            choice = choices.firstOrNull { preset?.momentId != null && it.momentId == preset.momentId }
                ?: choices.firstOrNull { preset?.personalKind != null && it.kind == preset.personalKind }
                ?: choices.first()
        }
    }
    var title by remember { mutableStateOf(preset?.title.orEmpty()) }
    var body by remember { mutableStateOf("") }
    var aiOpen by remember { mutableStateOf(false) }
    var voice by remember { mutableStateOf<VoiceClip?>(null) }
    val photos = remember { mutableStateListOf<LocalFile>() }
    var revealAt by remember { mutableStateOf<ZonedDateTime?>(null) }
    var sendAt by remember { mutableStateOf<ZonedDateTime?>(null) }
    var theirZone by remember { mutableStateOf(true) }
    var giftOn by remember { mutableStateOf(false) }
    var gift by remember { mutableStateOf("") }
    var picking by remember { mutableStateOf<String?>(null) } // reveal · send
    var step by remember { mutableStateOf(0) } // 0 compose · 1 PIN
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val giftMinor = if (giftOn && direct && sendAt == null) parseAmountToMinor(gift) else null

    val recorder = remember { VoiceRecorder(context) }
    DisposableEffect(Unit) { onDispose { if (recorder.recording) recorder.cancel() } }
    val mic = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted -> if (granted) recorder.start() }
    val gallery = rememberLauncherForActivityResult(ActivityResultContracts.PickMultipleVisualMedia(10)) { uris ->
        scope.launch {
            val picked = withContext(Dispatchers.IO) { uris.mapNotNull { copyToCache(context, it, "card")?.let { f -> compressImage(context, f) } } }
            photos.addAll(picked.take(10 - photos.size))
        }
    }

    suspend fun send(pin: String?): Boolean {
        val id = chatId ?: return false
        val c = choice ?: return false
        val fields = buildMap {
            c.momentId?.let { put("moment_id", textPart(it.toString())) }
            c.kind?.let { put("personal_kind", textPart(it)) }
            title.trim().takeIf { it.isNotEmpty() }?.let { put("title", textPart(it)) }
            body.trim().takeIf { it.isNotEmpty() }?.let { put("text", textPart(it)) }
            revealAt?.let { put("reveal_at", textPart(it.toOffsetDateTime().toString())) }
            sendAt?.let {
                put("send_at", textPart(it.toLocalDateTime().format(sendAtFormat)))
                put("schedule_zone", textPart(if (theirZone && direct) "recipient" else "mine"))
            }
            giftMinor?.let { put("gift_amount_minor", textPart(it.toString())) }
            put("uuid", textPart(java.util.UUID.randomUUID().toString()))
        }
        val files = buildList {
            voice?.let { add(MultipartBody.Part.createFormData("voice", it.file.name, it.file.asRequestBody("audio/mp4".toMediaType()))) }
            photos.forEach { add(MultipartBody.Part.createFormData("photos[]", it.name, it.file.asRequestBody(it.mime.toMediaType()))) }
        }
        val data: JsonElement? = ApiClient.moments.sendCard(chatAuth(), pin, id, fields, files).data
        val message = data?.takeIf { it.isJsonObject && it.asJsonObject.has("type") }?.let { runCatching { Gson().fromJson(it, MessageDto::class.java) }.getOrNull() }
        Toast.makeText(context, context.getString(if (message == null) R.string.mo_card_scheduled else R.string.mo_card_sent), Toast.LENGTH_SHORT).show()
        onSent(message)
        onDismiss()
        return true
    }

    ChSheet(
        onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true),
        containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp),
    ) {
        if (chatId == null && toUser != null) {
            Box(Modifier.fillMaxWidth().padding(40.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = settingsAccent()) }
            return@ChSheet
        }
        if (chatId == null) {
            WhoFor { c ->
                scope.launch {
                    runCatching { ApiClient.chat.openDirect(chatAuth(), OpenDirectRequest(c.profile!!.id, c.profile.type)).data }
                        .onSuccess { conv -> if (conv != null) { chatId = conv.id; name = c.name; direct = true } }
                        .onFailure { error = it.apiFailure().message }
                }
            }
            return@ChSheet
        }
        AnimatedContent(step, label = "cardStep", transitionSpec = { (fadeIn(tween(220)) + scaleIn(initialScale = 0.94f)) togetherWith fadeOut(tween(150)) }) { now ->
            if (now == 1) {
                Column(Modifier.fillMaxWidth().heightIn(min = 520.dp).padding(horizontal = 12.dp).padding(bottom = 16.dp)) {
                    WaPinPad(
                        title = stringResource(R.string.mo_card_gift_pin, formatMinor(giftMinor ?: 0, null)),
                        sub = name.orEmpty(),
                        icon = Icons.Rounded.Redeem,
                        modifier = Modifier.fillMaxWidth().height(500.dp),
                        onComplete = { pin ->
                            try {
                                send(pin)
                                PadResult.Ok
                            } catch (e: Exception) {
                                val failure = e.apiFailure()
                                if (failure.errorCode?.startsWith("wallet_pin") == true) throw e
                                error = failure.message
                                step = 0
                                PadResult.Ok
                            }
                        },
                    )
                }
                return@AnimatedContent
            }
            Column(
                Modifier
                    .fillMaxWidth()
                    .navigationBarsPadding()
                    .verticalScroll(rememberScrollState())
                    .imePadding()
                    .padding(horizontal = 20.dp)
                    .padding(bottom = 28.dp),
            ) {
                Text(
                    if (name.isNullOrBlank()) stringResource(R.string.mo_card_title) else stringResource(R.string.mo_card_to, name.orEmpty()),
                    color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold,
                )
                Spacer(Modifier.height(10.dp))
                choice?.let { c -> CardFace(c.look.copy(title = title.trim().ifEmpty { c.name }), Modifier.fillMaxWidth()) }
                Spacer(Modifier.height(10.dp))
                MoSheetSelect(
                    label = stringResource(R.string.mo_occasion),
                    value = choice?.let { "${it.look.emoji ?: "✨"}  ${it.name}" }.orEmpty(),
                    icon = Icons.Rounded.AutoAwesome,
                ) { close ->
                    choices.forEach { c ->
                        val on = choice == c
                        DropdownMenuItem(
                            text = { Text("${c.look.emoji ?: "✨"}  ${c.name}", color = if (on) settingsAccent() else settingsInk(), fontWeight = if (on) FontWeight.Bold else FontWeight.Medium, maxLines = 1, overflow = TextOverflow.Ellipsis) },
                            trailingIcon = { if (on) Icon(Icons.Rounded.CheckCircle, null, tint = settingsAccent(), modifier = Modifier.size(16.dp)) },
                            onClick = { choice = c; close() },
                        )
                    }
                }
                Spacer(Modifier.height(10.dp))
                DorrTextField(
                    title, { title = it.take(120) }, modifier = Modifier.fillMaxWidth(),
                    label = stringResource(R.string.mo_lbl_title), placeholder = stringResource(R.string.mo_card_title_hint), icon = Icons.Rounded.Edit,
                )
                Spacer(Modifier.height(10.dp))
                DorrTextField(
                    body, { body = it.take(2000) }, modifier = Modifier.fillMaxWidth(),
                    placeholder = stringResource(R.string.mo_card_text_hint), icon = Icons.Rounded.Edit,
                    singleLine = false, minLines = 3,
                )
                Spacer(Modifier.height(8.dp))
                Row(
                    Modifier.clip(RoundedCornerShape(50)).background(settingsAccent().copy(alpha = 0.1f)).clickable { aiOpen = !aiOpen }.padding(horizontal = 12.dp, vertical = 7.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Rounded.AutoAwesome, null, tint = settingsAccent(), modifier = Modifier.size(16.dp))
                    Spacer(Modifier.width(6.dp))
                    Text(stringResource(R.string.mo_card_ai), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
                }
                AnimatedVisibility(aiOpen, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
                    GreetingSuggestions(choice, name) { body = it; aiOpen = false }
                }
                Spacer(Modifier.height(10.dp))
                DorrTextField(
                    when {
                        recorder.recording -> stringResource(R.string.mo_card_voice_stop, durationLabel(recorder.elapsedMs))
                        voice != null -> stringResource(R.string.mo_card_voice_done, durationLabel(voice!!.durationMs))
                        else -> stringResource(R.string.mo_card_voice)
                    },
                    {},
                    modifier = Modifier.fillMaxWidth(),
                    icon = if (recorder.recording) Icons.Rounded.Stop else if (voice != null) Icons.Rounded.Delete else Icons.Rounded.Mic,
                    onClick = {
                        when {
                            recorder.recording -> voice = recorder.finish()
                            voice != null -> { voice!!.file.delete(); voice = null }
                            androidx.core.content.ContextCompat.checkSelfPermission(context, Manifest.permission.RECORD_AUDIO) == android.content.pm.PackageManager.PERMISSION_GRANTED -> recorder.start()
                            else -> mic.launch(Manifest.permission.RECORD_AUDIO)
                        }
                    },
                )
                Spacer(Modifier.height(10.dp))
                DorrTextField(
                    if (photos.isEmpty()) stringResource(R.string.mo_card_photos) else "${photos.size}/10",
                    {},
                    modifier = Modifier.fillMaxWidth(),
                    icon = Icons.Rounded.Photo,
                    onClick = { if (photos.size < 10) gallery.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) },
                )
                if (photos.isNotEmpty()) {
                    Spacer(Modifier.height(8.dp))
                    LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        items(photos.toList(), key = { it.file.path }) { f ->
                            Box {
                                AsyncImage(f.file, null, contentScale = ContentScale.Crop, modifier = Modifier.size(64.dp).clip(RoundedCornerShape(12.dp)))
                                Box(
                                    Modifier.align(Alignment.TopEnd).padding(3.dp).size(20.dp).clip(CircleShape).background(Color.Black.copy(alpha = 0.55f)).clickable { photos.remove(f) },
                                    contentAlignment = Alignment.Center,
                                ) { Icon(Icons.Rounded.Close, null, tint = Color.White, modifier = Modifier.size(13.dp)) }
                            }
                        }
                    }
                }
                Spacer(Modifier.height(14.dp))
                Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp), 8.dp).padding(horizontal = 8.dp, vertical = 4.dp)) {
                    ToggleLine(
                        Icons.Rounded.Lock, stringResource(R.string.mo_card_surprise),
                        revealAt?.let { stringResource(R.string.mo_card_surprise_at, whenText(it)) } ?: stringResource(R.string.mo_card_surprise_sub),
                        checked = revealAt != null, divider = true,
                    ) { if (it) picking = "reveal" else revealAt = null }
                    ToggleLine(
                        Icons.Rounded.Schedule, stringResource(R.string.mo_card_later),
                        sendAt?.let { stringResource(R.string.mo_card_later_at, it.toLocalDateTime().format(DateTimeFormatter.ofLocalizedDateTime(FormatStyle.MEDIUM, FormatStyle.SHORT).withLocale(AppLocale.java()))) } ?: stringResource(R.string.mo_card_later_sub),
                        checked = sendAt != null, divider = direct,
                    ) { if (it) picking = "send" else sendAt = null }
                    if (sendAt != null && direct) {
                        MoSheetSelect(
                            label = stringResource(R.string.mo_card_later),
                            value = if (theirZone) stringResource(R.string.mo_card_their_time, name.orEmpty()) else stringResource(R.string.mo_card_my_time),
                            icon = Icons.Rounded.Schedule,
                        ) { close ->
                            listOf(true to stringResource(R.string.mo_card_their_time, name.orEmpty()), false to stringResource(R.string.mo_card_my_time)).forEach { (theirs, label) ->
                                val on = theirZone == theirs
                                DropdownMenuItem(
                                    text = { Text(label, color = if (on) settingsAccent() else settingsInk(), fontWeight = if (on) FontWeight.Bold else FontWeight.Medium) },
                                    trailingIcon = { if (on) Icon(Icons.Rounded.CheckCircle, null, tint = settingsAccent(), modifier = Modifier.size(16.dp)) },
                                    onClick = { theirZone = theirs; close() },
                                )
                            }
                        }
                    }
                    if (direct) {
                        ToggleLine(
                            Icons.Rounded.Redeem, stringResource(R.string.mo_card_gift),
                            stringResource(if (sendAt != null) R.string.mo_card_gift_now_only else R.string.mo_card_gift_sub),
                            checked = giftOn && sendAt == null, enabled = sendAt == null, divider = false,
                        ) { giftOn = it }
                        AnimatedVisibility(giftOn && sendAt == null) {
                            DorrTextField(
                                gift, { v -> gift = v.filter { it.isDigit() || it == '.' }.take(12) },
                                modifier = Modifier.fillMaxWidth().padding(horizontal = 4.dp, vertical = 8.dp),
                                label = stringResource(R.string.mo_card_gift_amount), icon = Icons.Rounded.Payments,
                                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                            )
                        }
                    }
                }
                WaError(error)
                Spacer(Modifier.height(16.dp))
                WaButton(
                    stringResource(if (sendAt != null) R.string.mo_card_schedule else R.string.mo_card_send),
                    {
                        if (giftMinor != null) {
                            step = 1
                            return@WaButton
                        }
                        busy = true
                        error = null
                        scope.launch {
                            runCatching { send(null) }.onFailure { error = it.apiFailure().message }
                            busy = false
                        }
                    },
                    enabled = !busy && choice != null && !recorder.recording && (!giftOn || sendAt != null || giftMinor != null),
                    icon = Icons.Rounded.Send,
                    loading = busy,
                )
            }
        }
    }

    picking?.let { which ->
        ScheduleSheet(onDismiss = { picking = null }) { at ->
            if (which == "reveal") revealAt = at else { sendAt = at; giftOn = false }
            picking = null
        }
    }
}

/** No chat yet: pick the person (saved contacts on Dorr). */
@Composable
private fun WhoFor(onPick: (ContactDto) -> Unit) {
    var contacts by remember { mutableStateOf<List<ContactDto>?>(null) }
    var query by remember { mutableStateOf("") }
    LaunchedEffect(Unit) { contacts = runCatching { ApiClient.chat.contacts(chatAuth(), registered = 1).data }.getOrNull().orEmpty().filter { it.profile != null } }
    Column(Modifier.fillMaxWidth().navigationBarsPadding().heightIn(min = 420.dp).padding(horizontal = 20.dp).padding(bottom = 20.dp)) {
        Text(stringResource(R.string.mo_card_who), color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
        Spacer(Modifier.height(10.dp))
        DorrTextField(query, { query = it }, modifier = Modifier.fillMaxWidth(), placeholder = stringResource(R.string.ch_search_contacts), icon = Icons.Rounded.Search)
        Spacer(Modifier.height(10.dp))
        val list = contacts
        if (list == null) CircularProgressIndicator(color = settingsAccent(), modifier = Modifier.align(Alignment.CenterHorizontally).padding(20.dp))
        else Column(Modifier.verticalScroll(rememberScrollState()).heightIn(max = 460.dp).fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp), 8.dp).padding(horizontal = 6.dp, vertical = 2.dp)) {
            list.filter { query.isBlank() || it.name.contains(query.trim(), ignoreCase = true) || it.phone.contains(query.trim()) }.forEach { c ->
                Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).clickable { onPick(c) }.padding(vertical = 10.dp, horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(34.dp).clip(RoundedCornerShape(10.dp)).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                        c.profile?.avatar?.let { AsyncImage(ApiClient.mediaUrl(it), null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize()) }
                            ?: Text(c.name.take(1), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 13.5.sp)
                    }
                    Spacer(Modifier.width(10.dp))
                    Text(c.name, color = settingsInk(), fontSize = 13.5.sp, fontWeight = FontWeight.Bold)
                }
            }
        }
    }
}

/** "Write it for me": who they are to me and the tone → three greetings to start from. */
@Composable
private fun GreetingSuggestions(choice: CardChoice?, name: String?, onUse: (String) -> Unit) {
    val scope = rememberCoroutineScope()
    var relation by remember { mutableStateOf("friend") }
    var tone by remember { mutableStateOf("warm") }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var results by remember { mutableStateOf<List<String>>(emptyList()) }
    val relations = listOf("family" to R.string.mo_rel_family, "friend" to R.string.mo_rel_friend, "partner" to R.string.mo_rel_partner, "work" to R.string.mo_rel_work, "other" to R.string.mo_rel_other)
    val tones = listOf("warm" to R.string.mo_tone_warm, "formal" to R.string.mo_tone_formal, "funny" to R.string.mo_tone_funny, "short" to R.string.mo_tone_short, "poetic" to R.string.mo_tone_poetic)

    Column(Modifier.fillMaxWidth().padding(top = 8.dp).settingsSurface(RoundedCornerShape(18.dp), 8.dp).padding(12.dp)) {
        PillRow(relations, relation) { relation = it }
        Spacer(Modifier.height(6.dp))
        PillRow(tones, tone) { tone = it }
        Spacer(Modifier.height(8.dp))
        WaButton(stringResource(R.string.mo_card_ai_go), {
            loading = true
            error = null
            scope.launch {
                runCatching {
                    ApiClient.moments.greetings(chatAuth(), JsonObject().apply {
                        choice?.momentId?.let { addProperty("moment_id", it) }
                        choice?.kind?.let { addProperty("kind", it) }
                        addProperty("relation", relation)
                        addProperty("tone", tone)
                        name?.takeIf { it.isNotBlank() }?.let { addProperty("name", it.take(60)) }
                    }).data?.greetings.orEmpty()
                }.onSuccess { results = it }.onFailure { error = it.apiFailure().message }
                loading = false
            }
        }, icon = Icons.Rounded.AutoAwesome, enabled = !loading && choice != null, loading = loading)
        error?.let { Text(it, color = Color(0xFFDC2626), fontSize = 12.5.sp, modifier = Modifier.padding(top = 6.dp)) }
        results.forEach { g ->
            Text(
                g, color = settingsInk(), fontSize = 13.5.sp,
                modifier = Modifier.fillMaxWidth().padding(top = 8.dp).clip(RoundedCornerShape(14.dp)).background(if (settingsNight()) AccountDark.well else Color(0xFFF7F8FA)).clickable { onUse(g) }.padding(12.dp),
            )
        }
    }
}

@Composable
private fun PillRow(options: List<Pair<String, Int>>, selected: String, onPick: (String) -> Unit) {
    LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        items(options, key = { it.first }) { (key, label) ->
            val on = key == selected
            val accent = settingsAccent()
            val bg by animateColorAsState(if (on) accent else Color.Transparent, label = "pill")
            Text(
                stringResource(label), color = if (on) Color.White else settingsInk(), fontSize = 12.5.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(RoundedCornerShape(50)).border(1.dp, if (on) accent else Color(0xFFE6E8EC), RoundedCornerShape(50)).background(bg).clickable { onPick(key) }.padding(horizontal = 12.dp, vertical = 6.dp),
            )
        }
    }
}

@Composable
private fun ToggleLine(
    icon: ImageVector,
    title: String,
    sub: String,
    checked: Boolean,
    enabled: Boolean = true,
    divider: Boolean = false,
    onChange: (Boolean) -> Unit,
) {
    val dark = settingsNight()
    Row(
        Modifier.fillMaxWidth().alpha(if (enabled) 1f else 0.45f).padding(horizontal = 4.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        PinkIcon(icon)
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(title, fontSize = 13.5.sp, fontWeight = FontWeight.Bold, color = settingsInk())
            Text(sub, fontSize = 11.sp, color = settingsMut(), lineHeight = 15.sp, modifier = Modifier.padding(top = 2.dp), maxLines = 2)
        }
        RedToggle(checked, dark) { if (enabled) onChange(!checked) }
    }
    if (divider) Box(Modifier.fillMaxWidth().height(1.dp).background(if (dark) AccountDark.line else Color(0xFFF6E4E8)))
}

private fun durationLabel(ms: Long): String {
    val s = ms / 1000
    return "%d:%02d".format(s / 60, s % 60)
}

// =============================================================================== the card face

/** The occasion's face: its gradient (or the admin's card image), animation, emoji and title. */
@Composable
internal fun CardFace(look: CardLookDto, modifier: Modifier = Modifier, effects: String = MomentsStore.center?.effects ?: "full", content: @Composable () -> Unit = {}) {
    Box(modifier.defaultMinSize(minHeight = 156.dp).clip(RoundedCornerShape(22.dp)).background(momentBrush(look.primaryColor, look.secondaryColor))) {
        look.cardImage?.let { AsyncImage(ApiClient.mediaUrl(it), null, contentScale = ContentScale.Crop, modifier = Modifier.matchParentSize()) }
        MomentEffect(look.animation, look.emoji, look.primaryColor, look.secondaryColor, effects, Modifier.matchParentSize())
        Column(
            Modifier.fillMaxWidth().padding(horizontal = 18.dp, vertical = 16.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(look.emoji ?: "✨", fontSize = 40.sp)
            val heading = look.title.orEmpty()
            if (heading.isNotBlank()) {
                Spacer(Modifier.height(8.dp))
                Text(
                    heading,
                    color = Color.White,
                    fontSize = 18.sp,
                    fontWeight = FontWeight.ExtraBold,
                    textAlign = TextAlign.Center,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                    style = TextStyle(
                        shadow = Shadow(Color.Black.copy(alpha = 0.7f), offset = Offset(0f, 1f), blurRadius = 12f),
                    ),
                )
            }
            content()
        }
    }
}

// =============================================================================== in the chat

/**
 * A greeting card message. Sealed (someone's surprise, not time yet): a wrapped gift with a
 * countdown, nothing else — then "open it" fetches the real card. A group card lists everyone's
 * part with their voice / photo; a gift shows its amount.
 */
@Composable
fun MomentCardBubble(dto: MessageDto, mine: Boolean, onOpenSealed: () -> Unit, onOpenPhoto: (Int) -> Unit, footer: @Composable () -> Unit) {
    val card = dto.meta?.get("card")?.takeIf { it.isJsonObject }?.asJsonObject
    val look = remember(card) { card?.let { runCatching { Gson().fromJson(it, CardLookDto::class.java) }.getOrNull() } ?: CardLookDto() }
    val revealAt = card?.get("reveal_at")?.takeIf { it.isJsonPrimitive }?.asString?.let { runCatching { OffsetDateTime.parse(it) }.getOrNull() }

    Column(Modifier.width(270.dp)) {
        CardFace(look, Modifier.fillMaxWidth(), effects = if ((MomentsStore.center?.effects ?: "full") == "off") "off" else "light") {
            if (dto.sealed) {
                SealedCountdown(revealAt, onOpenSealed)
                return@CardFace
            }
            if (mine && revealAt != null && revealAt.isAfter(OffsetDateTime.now())) {
                Text(stringResource(R.string.mo_card_sealed_mine, whenText(revealAt.atZoneSameInstant(java.time.ZoneId.systemDefault()))), color = Color.White.copy(alpha = 0.9f), fontSize = 11.5.sp, textAlign = TextAlign.Center)
            }
            val pop = remember { Animatable(0.85f) }
            LaunchedEffect(Unit) { pop.animateTo(1f, spring(dampingRatio = 0.45f)) }
            Column(Modifier.scale(pop.value)) {
                dto.body?.takeIf { it.isNotBlank() }?.let {
                    Text(it, color = Color(0xFF111928), fontSize = 14.5.sp, modifier = Modifier.padding(top = 10.dp).fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Color.White.copy(alpha = 0.92f)).padding(12.dp))
                }
                val contributions = card?.arr("contributions")
                val used = mutableSetOf<Int>()
                contributions?.forEach { el ->
                    val c = el.takeIf { it.isJsonObject }?.asJsonObject ?: return@forEach
                    val files = c.arr("files")?.mapNotNull { f -> f.takeIf { it.isJsonObject }?.asJsonObject?.int("attachment") }.orEmpty()
                    used += files
                    Column(Modifier.padding(top = 8.dp).fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Color.White.copy(alpha = 0.92f)).padding(10.dp)) {
                        c.get("name")?.takeIf { it.isJsonPrimitive }?.asString?.let { Text(it, color = momentColor(look.primaryColor, Ch.Red), fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold) }
                        c.get("text")?.takeIf { it.isJsonPrimitive }?.asString?.let { Text(it, color = Color(0xFF111928), fontSize = 14.sp) }
                        Attachments(dto, files, onOpenPhoto)
                    }
                }
                Attachments(dto, dto.attachments.indices.filterNot { it in used }, onOpenPhoto)
                card?.obj("gift")?.let { g ->
                    val amount = g.long("amount_minor") ?: 0
                    val currency = g.get("currency_code")?.takeIf { it.isJsonPrimitive }?.asString
                    Row(
                        Modifier.padding(top = 10.dp).clip(RoundedCornerShape(50)).background(Color.White).padding(horizontal = 14.dp, vertical = 7.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text("🎁", fontSize = 16.sp)
                        Spacer(Modifier.width(6.dp))
                        androidx.compose.runtime.CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                            Text(formatMinor(amount, currency), color = Color(0xFF047857), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                        }
                    }
                }
            }
        }
        Box(Modifier.fillMaxWidth().padding(horizontal = 6.dp)) { footer() }
    }
}

@Composable
private fun SealedCountdown(revealAt: OffsetDateTime?, onOpen: () -> Unit) {
    var now by remember { mutableLongStateOf(System.currentTimeMillis()) }
    LaunchedEffect(revealAt) { while (true) { delay(1000); now = System.currentTimeMillis() } }
    val left = revealAt?.let { Duration.ofMillis(it.toInstant().toEpochMilli() - now) }
    val wiggle = remember { Animatable(0f) }
    LaunchedEffect(Unit) { while (true) { wiggle.animateTo(1f, tween(140)); wiggle.animateTo(-1f, tween(140)); wiggle.animateTo(0f, tween(140)); delay(2200) } }
    Text("🎁", fontSize = 54.sp, modifier = Modifier.padding(top = 6.dp).rotate(wiggle.value * 8f))
    Text(stringResource(R.string.mo_card_sealed), color = Color.White, fontSize = 14.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center)
    if (left == null || left.isNegative || left.isZero) {
        Text(
            stringResource(R.string.mo_card_open), color = Color(0xFF111928), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold,
            modifier = Modifier.padding(top = 10.dp).clip(RoundedCornerShape(50)).background(Color.White).clickable(onClick = onOpen).padding(horizontal = 22.dp, vertical = 9.dp),
        )
    } else {
        val d = left.toDays()
        val h = left.toHours() % 24
        val m = left.toMinutes() % 60
        val s = left.seconds % 60
        androidx.compose.runtime.CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
            Text(
                if (d > 0) "${d}d  %02d:%02d:%02d".format(h, m, s) else "%02d:%02d:%02d".format(h, m, s),
                color = Color.White, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 6.dp),
            )
        }
    }
}

/** The card's photos (a strip) and voices (play buttons), by their index in the message. */
@Composable
private fun Attachments(dto: MessageDto, indices: List<Int>, onOpenPhoto: (Int) -> Unit) {
    val files = indices.mapNotNull { i -> dto.attachments.getOrNull(i)?.let { i to it } }
    if (files.isEmpty()) return
    val photos = files.filter { it.second.mimeType?.startsWith("image/") == true }
    val voices = files.filterNot { it.second.mimeType?.startsWith("image/") == true }
    voices.forEach { (i, a) ->
        val key = "${dto.id}#$i"
        val playing = VoicePlayer.isPlaying(key)
        Row(
            Modifier.padding(top = 6.dp).clip(RoundedCornerShape(50)).background(Color.White).clickable { ApiClient.mediaUrl(a.url)?.let { VoicePlayer.toggle(key, it) } }.padding(horizontal = 12.dp, vertical = 6.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(if (playing) Icons.Rounded.Pause else Icons.Rounded.PlayArrow, null, tint = Color(0xFF111928), modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(4.dp))
            Text(stringResource(R.string.mo_card_voice_play) + (a.durationMs?.let { "  " + durationLabel(it) } ?: ""), color = Color(0xFF111928), fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
        }
    }
    if (photos.isNotEmpty()) {
        Row(Modifier.padding(top = 6.dp), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
            photos.take(3).forEachIndexed { n, (i, a) ->
                Box(Modifier.weight(1f).height(80.dp).clip(RoundedCornerShape(12.dp)).clickable { onOpenPhoto(i) }) {
                    AsyncImage(ApiClient.mediaUrl(a.thumbnail ?: a.url), null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                    if (n == 2 && photos.size > 3) Box(Modifier.fillMaxSize().background(Color.Black.copy(alpha = 0.45f)), contentAlignment = Alignment.Center) {
                        Text("+${photos.size - 3}", color = Color.White, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
                    }
                }
            }
        }
    }
}
