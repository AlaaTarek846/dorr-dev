package com.dorr.app.ui.screens.moments

import android.Manifest
import android.widget.Toast
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Cancel
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Inventory2
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.Pause
import androidx.compose.material.icons.rounded.Photo
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.PersonAdd
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
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.chat.VoiceClip
import com.dorr.app.chat.VoicePlayer
import com.dorr.app.chat.VoiceRecorder
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AppLocale
import com.dorr.app.network.CapsuleDto
import com.dorr.app.network.CollabCardDto
import com.dorr.app.network.CollabFileDto
import com.dorr.app.network.ContactDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.chat.Ch
import com.dorr.app.ui.screens.chat.ChAvatar
import com.dorr.app.ui.screens.chat.ChField
import com.dorr.app.ui.screens.chat.LocalFile
import com.dorr.app.ui.screens.chat.PeoplePickerSheet
import com.dorr.app.ui.screens.chat.chatAuth
import com.dorr.app.ui.screens.chat.compressImage
import com.dorr.app.ui.screens.chat.copyToCache
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaEmpty
import com.dorr.app.ui.screens.wallet.WaError
import com.dorr.app.ui.screens.wallet.WaPage
import com.dorr.app.ui.screens.wallet.WaSectionTitle
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.wallet.waRise
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.time.OffsetDateTime
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import com.dorr.app.ui.screens.chat.ChSheet
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.AccountDark
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsNight

/*
 * DORR Moments together and kept: group cards everyone signs (spec 163) and capsules — albums
 * of the messages I chose to keep for an occasion (166).
 */

private fun dayText(iso: String?): String = runCatching {
    OffsetDateTime.parse(iso).toLocalDate().format(DateTimeFormatter.ofLocalizedDate(FormatStyle.MEDIUM).withLocale(AppLocale.java()))
}.getOrDefault("")

// =============================================================================== group cards

@Composable
internal fun CollabCardsPage(onBack: () -> Unit, onOpen: (String) -> Unit) {
    var cards by remember { mutableStateOf<List<CollabCardDto>?>(null) }
    var creating by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { cards = runCatching { ApiClient.moments.collabCards(chatAuth()).data }.getOrNull().orEmpty() }

    WaPage(
        title = stringResource(R.string.mo_collab_title), onBack = onBack,
        cta = { WaButton(stringResource(R.string.mo_collab_new), { creating = true }, icon = Icons.Rounded.Add) },
    ) {
        Text(stringResource(R.string.mo_collab_sub), color = Wa.Mut, fontSize = 13.sp, modifier = Modifier.padding(bottom = 10.dp))
        val list = cards
        when {
            list == null -> repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(84.dp).padding(bottom = 10.dp), RoundedCornerShape(22.dp)) }
            list.isEmpty() -> WaCard(Modifier.fillMaxWidth()) { WaEmpty(Icons.Rounded.Groups, Tone.Gray, stringResource(R.string.mo_collab_empty), stringResource(R.string.mo_collab_empty_sub)) }
            else -> list.forEachIndexed { i, c ->
                Row(
                    Modifier.fillMaxWidth().padding(bottom = 8.dp).waRise(i).clip(RoundedCornerShape(20.dp)).background(Wa.Surface).clickable { onOpen(c.id) }.padding(12.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Box(Modifier.size(50.dp).clip(RoundedCornerShape(14.dp)).background(momentBrush(c.look?.primaryColor, c.look?.secondaryColor)), contentAlignment = Alignment.Center) {
                        Text(c.look?.emoji ?: "✨", fontSize = 24.sp)
                    }
                    Spacer(Modifier.width(12.dp))
                    Column(Modifier.weight(1f)) {
                        Text(c.title, color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        Text(
                            stringResource(R.string.mo_collab_for, c.recipient?.name.orEmpty()) + "  ·  " + stringResource(R.string.mo_collab_signed, c.members.count { it.signed }, c.members.size),
                            color = Wa.Mut, fontSize = 12.5.sp, maxLines = 1,
                        )
                    }
                    if (c.status == "sent") Icon(Icons.Rounded.CheckCircle, null, tint = Color(0xFF10B981), modifier = Modifier.size(20.dp))
                }
            }
        }
    }

    if (creating) CreateCollabSheet(onDismiss = { creating = false }) { id -> creating = false; onOpen(id) }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun CreateCollabSheet(onDismiss: () -> Unit, onCreated: (String) -> Unit) {
    val scope = rememberCoroutineScope()
    val choices = rememberCardChoices()
    var choice by remember { mutableStateOf<CardChoice?>(null) }
    LaunchedEffect(choices) { if (choice == null) choice = choices.firstOrNull() }
    var recipient by remember { mutableStateOf<ContactDto?>(null) }
    var title by remember { mutableStateOf("") }
    var members by remember { mutableStateOf<List<Int>>(emptyList()) }
    var picking by remember { mutableStateOf(false) }
    var contacts by remember { mutableStateOf<List<ContactDto>>(emptyList()) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(Unit) { contacts = runCatching { ApiClient.chat.contacts(chatAuth(), registered = 1).data }.getOrNull().orEmpty().filter { it.profile != null } }

    ChSheet(
        onDismissRequest = onDismiss,
        sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true),
        containerColor = Wa.Surface,
        shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp),
    ) {
        Column(
            Modifier
                .fillMaxWidth()
                .navigationBarsPadding()
                .imePadding()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 20.dp)
                .padding(bottom = 28.dp),
        ) {
            Text(stringResource(R.string.mo_collab_new), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(10.dp))
            choice?.let { CardFace(it.look.copy(title = title.trim().ifEmpty { it.name }), Modifier.fillMaxWidth()) }
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
            MoSheetSelect(
                label = stringResource(R.string.mo_collab_recipient),
                value = recipient?.name.orEmpty(),
                placeholder = stringResource(R.string.mo_collab_pick_person),
                icon = Icons.Rounded.Person,
            ) { close ->
                contacts.forEach { c ->
                    val on = recipient?.id == c.id
                    DropdownMenuItem(
                        text = { Text(c.name, color = if (on) settingsAccent() else settingsInk(), fontWeight = if (on) FontWeight.Bold else FontWeight.Medium) },
                        trailingIcon = { if (on) Icon(Icons.Rounded.CheckCircle, null, tint = settingsAccent(), modifier = Modifier.size(16.dp)) },
                        onClick = {
                            recipient = c
                            c.profile?.id?.let { id -> members = members - id }
                            close()
                        },
                    )
                }
            }
            Spacer(Modifier.height(10.dp))
            DorrTextField(
                stringResource(R.string.mo_collab_members, members.size), {},
                modifier = Modifier.fillMaxWidth(),
                label = stringResource(R.string.mo_collab_invite),
                icon = Icons.Rounded.PersonAdd,
                onClick = { picking = true },
            )
            WaError(error)
            Spacer(Modifier.height(16.dp))
            WaButton(
                stringResource(R.string.mo_collab_start),
                {
                    busy = true
                    error = null
                    scope.launch {
                        runCatching {
                            ApiClient.moments.createCollab(chatAuth(), JsonObject().apply {
                                addProperty("recipient_id", recipient!!.profile!!.id)
                                choice?.momentId?.let { addProperty("moment_id", it) }
                                choice?.kind?.let { addProperty("personal_kind", it) }
                                title.trim().takeIf { it.isNotEmpty() }?.let { addProperty("title", it) }
                                add("members", JsonArray().apply { members.filter { it != recipient!!.profile!!.id }.forEach { add(it) } })
                            }).data
                        }.onSuccess { it?.let { c -> onCreated(c.id) } }.onFailure { error = it.apiFailure().message }
                        busy = false
                    }
                },
                icon = Icons.Rounded.Groups,
                enabled = !busy && recipient != null && choice != null,
                loading = busy,
            )
        }
    }
    if (picking) PeoplePickerSheet(members.toSet(), onDismiss = { picking = false }) { members = it }
}

@Composable
internal fun CollabCardPage(id: String, onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var card by remember { mutableStateOf<CollabCardDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var text by remember { mutableStateOf("") }
    var voice by remember { mutableStateOf<VoiceClip?>(null) }
    var photo by remember { mutableStateOf<LocalFile?>(null) }
    var busy by remember { mutableStateOf(false) }
    var inviting by remember { mutableStateOf(false) }

    fun take(c: CollabCardDto?) {
        card = c
        if (c != null && text.isEmpty()) text = c.mine?.text.orEmpty()
    }
    LaunchedEffect(id) { runCatching { ApiClient.moments.collabCard(chatAuth(), id).data }.onSuccess { take(it) }.onFailure { error = it.apiFailure().message } }
    fun act(block: suspend () -> CollabCardDto?) {
        busy = true
        error = null
        scope.launch {
            runCatching { block() }.onSuccess { it?.let(::take) }.onFailure { error = it.apiFailure().message }
            busy = false
        }
    }

    val recorder = remember { VoiceRecorder(context) }
    DisposableEffect(Unit) { onDispose { if (recorder.recording) recorder.cancel() } }
    val mic = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted -> if (granted) recorder.start() }
    val gallery = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri ->
        uri ?: return@rememberLauncherForActivityResult
        scope.launch { photo = withContext(Dispatchers.IO) { copyToCache(context, uri, "card")?.let { compressImage(context, it) } } }
    }

    val c = card
    WaPage(title = c?.title ?: stringResource(R.string.mo_collab_title), onBack = onBack) {
        if (c == null) {
            WaError(error)
            repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(90.dp).padding(bottom = 10.dp), RoundedCornerShape(22.dp)) }
            return@WaPage
        }
        CardFace((c.look ?: PersonalLooks.getValue("other")).copy(title = c.title), Modifier.fillMaxWidth().waRise(0)) {
            Text(stringResource(R.string.mo_collab_for, c.recipient?.name.orEmpty()), color = Color.White.copy(alpha = 0.9f), fontSize = 13.sp)
        }
        if (c.status != "collecting") {
            Text(
                stringResource(if (c.status == "sent") R.string.mo_collab_was_sent else R.string.mo_collab_was_cancelled),
                color = Wa.Mut, fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 10.dp),
            )
        }

        WaSectionTitle(stringResource(R.string.mo_collab_signed, c.members.count { it.signed }, c.members.size), Modifier.waRise(1))
        c.members.forEachIndexed { i, m ->
            Column(Modifier.fillMaxWidth().padding(bottom = 8.dp).waRise(2 + i).clip(RoundedCornerShape(18.dp)).background(Wa.Surface).padding(12.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    ChAvatar(m.profile?.avatar, m.profile?.name, m.profile?.key, size = 34.dp)
                    Spacer(Modifier.width(10.dp))
                    Text(m.profile?.name.orEmpty(), color = Wa.Ink, fontWeight = FontWeight.Bold, fontSize = 14.sp, modifier = Modifier.weight(1f))
                    if (m.signed) Icon(Icons.Rounded.CheckCircle, null, tint = Color(0xFF10B981), modifier = Modifier.size(18.dp))
                    else Text(stringResource(R.string.mo_collab_waiting), color = Wa.Mut, fontSize = 12.sp)
                    if (c.isOrganiser && c.status == "collecting" && m.profile?.isMe != true) {
                        Spacer(Modifier.width(6.dp))
                        Icon(Icons.Rounded.Close, null, tint = Wa.Mut, modifier = Modifier.size(18.dp).clickable { act { ApiClient.moments.removeCollabMember(chatAuth(), c.id, m.profile!!.id).data } })
                    }
                }
                m.text?.let { Text(it, color = Wa.Ink, fontSize = 13.5.sp, modifier = Modifier.padding(top = 6.dp)) }
                Files(m.files, "${c.id}-${m.profile?.key}")
            }
        }

        if (c.status == "collecting" && c.mine != null) {
            WaSectionTitle(stringResource(R.string.mo_collab_my_part), Modifier.waRise(3))
            ChField(text, { text = it.take(1000) }, stringResource(R.string.mo_card_text_hint), singleLine = false, minLines = 3)
            Spacer(Modifier.height(8.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                val clip = voice
                when {
                    recorder.recording -> SmallChip(Icons.Rounded.Stop, stringResource(R.string.mo_card_voice_stop, "%d:%02d".format(recorder.elapsedMs / 60000, recorder.elapsedMs / 1000 % 60)), true) { voice = recorder.finish() }
                    clip != null -> SmallChip(Icons.Rounded.Delete, stringResource(R.string.mo_card_voice_done, "%d:%02d".format(clip.durationMs / 60000, clip.durationMs / 1000 % 60)), true) { voice = null }
                    else -> SmallChip(Icons.Rounded.Mic, stringResource(R.string.mo_card_voice)) {
                        if (androidx.core.content.ContextCompat.checkSelfPermission(context, Manifest.permission.RECORD_AUDIO) == android.content.pm.PackageManager.PERMISSION_GRANTED) recorder.start()
                        else mic.launch(Manifest.permission.RECORD_AUDIO)
                    }
                }
                if (photo != null) SmallChip(Icons.Rounded.Delete, stringResource(R.string.mo_collab_photo_added), true) { photo = null }
                else SmallChip(Icons.Rounded.Photo, stringResource(R.string.mo_collab_photo)) { gallery.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) }
            }
            Spacer(Modifier.height(10.dp))
            WaButton(stringResource(if (c.mine.signed) R.string.mo_collab_update else R.string.mo_collab_sign), {
                act {
                    val fields = mapOf("text" to text.trim().toRequestBody("text/plain".toMediaType()))
                    val files = listOfNotNull(
                        voice?.let { MultipartBody.Part.createFormData("voice", it.file.name, it.file.asRequestBody("audio/mp4".toMediaType())) },
                        photo?.let { MultipartBody.Part.createFormData("photo", it.name, it.file.asRequestBody(it.mime.toMediaType())) },
                    )
                    ApiClient.moments.contribute(chatAuth(), c.id, fields, files).data.also { voice = null; photo = null }
                }
            }, enabled = !busy && !recorder.recording && (text.isNotBlank() || voice != null || photo != null), loading = busy, icon = Icons.Rounded.CheckCircle)
        }

        WaError(error)
        if (c.isOrganiser && c.status == "collecting") {
            Spacer(Modifier.height(14.dp))
            WaButton(stringResource(R.string.mo_collab_invite), { inviting = true }, style = WaButtonStyle.Ghost, icon = Icons.Rounded.PersonAdd)
            Spacer(Modifier.height(8.dp))
            WaButton(stringResource(R.string.mo_collab_send), {
                busy = true
                scope.launch {
                    runCatching { ApiClient.moments.sendCollab(chatAuth(), c.id) }
                        .onSuccess {
                            Toast.makeText(context, context.getString(R.string.mo_card_sent), Toast.LENGTH_SHORT).show()
                            take(runCatching { ApiClient.moments.collabCard(chatAuth(), c.id).data }.getOrNull())
                        }
                        .onFailure { error = it.apiFailure().message }
                    busy = false
                }
            }, enabled = !busy && c.members.any { it.signed }, icon = Icons.Rounded.Send)
            Spacer(Modifier.height(6.dp))
            WaButton(stringResource(R.string.mo_collab_cancel), {
                scope.launch { runCatching { ApiClient.moments.cancelCollab(chatAuth(), c.id) }.onSuccess { onBack() }.onFailure { error = it.apiFailure().message } }
            }, style = WaButtonStyle.Quiet, icon = Icons.Rounded.Cancel)
        }
    }

    if (inviting && c != null) {
        PeoplePickerSheet(emptySet(), onDismiss = { inviting = false }) { ids ->
            act { ApiClient.moments.inviteCollab(chatAuth(), c.id, JsonObject().apply { add("members", JsonArray().apply { ids.forEach { add(it) } }) }).data }
        }
    }
}

@Composable
private fun SmallChip(icon: androidx.compose.ui.graphics.vector.ImageVector, label: String, active: Boolean = false, onClick: () -> Unit) {
    Row(
        Modifier.clip(RoundedCornerShape(50)).background(if (active) Wa.Red else Wa.Surface).clickable(onClick = onClick).padding(horizontal = 14.dp, vertical = 9.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = if (active) Color.White else Wa.Red, modifier = Modifier.size(17.dp))
        Spacer(Modifier.width(6.dp))
        Text(label, color = if (active) Color.White else Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold)
    }
}

/** Kept files: photos as thumbnails, voices as play buttons. */
@Composable
private fun Files(files: List<CollabFileDto>, key: String) {
    if (files.isEmpty()) return
    Row(Modifier.padding(top = 6.dp), horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
        files.forEachIndexed { i, f ->
            if (f.mimeType?.startsWith("image/") == true) {
                AsyncImage(ApiClient.mediaUrl(f.url), null, contentScale = ContentScale.Crop, modifier = Modifier.size(72.dp).clip(RoundedCornerShape(12.dp)))
            } else {
                val id = "$key#$i"
                Box(
                    Modifier.size(40.dp).clip(CircleShape).background(Wa.Red).clickable { ApiClient.mediaUrl(f.url)?.let { VoicePlayer.toggle(id, it) } },
                    contentAlignment = Alignment.Center,
                ) { Icon(if (VoicePlayer.isPlaying(id)) Icons.Rounded.Pause else Icons.Rounded.PlayArrow, null, tint = Color.White, modifier = Modifier.size(22.dp)) }
            }
        }
    }
}

// =============================================================================== capsules

private val CapsuleEmojis = listOf("🌙", "🎂", "💍", "🎓", "🎉", "❤️", "🌸", "✈️", "👶", "⭐")

@Composable
private fun Modifier.capHairline(shape: RoundedCornerShape = RoundedCornerShape(16.dp)): Modifier {
    val dark = settingsNight()
    return clip(shape)
        .background(if (dark) AccountDark.card else Color.White)
        .border(1.dp, if (dark) AccountDark.line else Color(0xFFE6E8EC), shape)
}

@Composable
internal fun CapsulesPage(onBack: () -> Unit, onOpen: (String) -> Unit) {
    var capsules by remember { mutableStateOf<List<CapsuleDto>?>(null) }
    var creating by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { capsules = runCatching { ApiClient.moments.capsules(chatAuth()).data }.getOrNull().orEmpty() }

    WaPage(
        title = stringResource(R.string.mo_capsules), onBack = onBack,
        cta = { WaButton(stringResource(R.string.mo_capsule_new), { creating = true }, icon = Icons.Rounded.Add) },
    ) {
        Text(
            stringResource(R.string.mo_capsules_sub),
            color = settingsMut(),
            fontSize = 13.sp,
            modifier = Modifier.padding(bottom = 10.dp, start = 2.dp),
        )
        val list = capsules
        when {
            list == null -> repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(56.dp).padding(bottom = 8.dp), RoundedCornerShape(16.dp)) }
            list.isEmpty() -> Column(Modifier.fillMaxWidth().capHairline()) {
                WaEmpty(Icons.Rounded.Inventory2, Tone.Gray, stringResource(R.string.mo_capsules_empty), stringResource(R.string.mo_capsules_empty_sub))
            }
            else -> Column(Modifier.fillMaxWidth().capHairline().padding(horizontal = 6.dp, vertical = 2.dp)) {
                list.forEachIndexed { i, c ->
                    Row(
                        Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).clickable { onOpen(c.id) }.padding(horizontal = 4.dp, vertical = 10.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Box(
                            Modifier.size(34.dp).clip(RoundedCornerShape(10.dp)).background(settingsAccent().copy(alpha = 0.12f)),
                            contentAlignment = Alignment.Center,
                        ) { Text(c.emoji ?: "📦", fontSize = 16.sp) }
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text(c.title, color = settingsInk(), fontSize = 13.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            Text(stringResource(R.string.mo_capsule_count, c.itemsCount), color = settingsMut(), fontSize = 11.sp, modifier = Modifier.padding(top = 2.dp))
                        }
                    }
                    if (i != list.lastIndex) Box(Modifier.fillMaxWidth().padding(horizontal = 8.dp).height(1.dp).background(if (settingsNight()) AccountDark.line else Color(0xFFF0F1F4)))
                }
            }
        }
    }

    if (creating) NewCapsuleSheet(onDismiss = { creating = false }) { c -> creating = false; onOpen(c.id) }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun NewCapsuleSheet(onDismiss: () -> Unit, onCreated: (CapsuleDto) -> Unit) {
    val scope = rememberCoroutineScope()
    var title by remember { mutableStateOf("") }
    var emoji by remember { mutableStateOf(CapsuleEmojis.first()) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    ChSheet(
        onDismissRequest = onDismiss,
        sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true),
        containerColor = Wa.Surface,
        shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp),
    ) {
        Column(
            Modifier
                .fillMaxWidth()
                .navigationBarsPadding()
                .imePadding()
                .padding(horizontal = 20.dp)
                .padding(bottom = 28.dp),
        ) {
            Text(stringResource(R.string.mo_capsule_new), color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(10.dp))
            MoSheetSelect(
                label = stringResource(R.string.mo_emoji),
                value = emoji,
                icon = Icons.Rounded.AutoAwesome,
            ) { close ->
                CapsuleEmojis.forEach { e ->
                    val on = e == emoji
                    DropdownMenuItem(
                        text = { Text(e, fontSize = 20.sp) },
                        trailingIcon = { if (on) Icon(Icons.Rounded.CheckCircle, null, tint = settingsAccent(), modifier = Modifier.size(16.dp)) },
                        onClick = { emoji = e; close() },
                    )
                }
            }
            Spacer(Modifier.height(10.dp))
            DorrTextField(
                title, { title = it.take(120) }, modifier = Modifier.fillMaxWidth(),
                label = stringResource(R.string.mo_lbl_name), placeholder = stringResource(R.string.mo_capsule_name), icon = Icons.Rounded.Edit,
            )
            WaError(error)
            Spacer(Modifier.height(16.dp))
            WaButton(
                stringResource(R.string.mo_save),
                {
                    busy = true
                    error = null
                    scope.launch {
                        runCatching { ApiClient.moments.createCapsule(chatAuth(), JsonObject().apply { addProperty("title", title.trim()); addProperty("emoji", emoji) }).data }
                            .onSuccess { it?.let(onCreated) }.onFailure { error = it.apiFailure().message }
                        busy = false
                    }
                },
                enabled = !busy && title.isNotBlank(),
                loading = busy,
            )
        }
    }
}

@Composable
internal fun CapsulePage(id: String, onBack: () -> Unit) {
    val scope = rememberCoroutineScope()
    var capsule by remember { mutableStateOf<CapsuleDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(id) { runCatching { ApiClient.moments.capsule(chatAuth(), id).data }.onSuccess { capsule = it }.onFailure { error = it.apiFailure().message } }
    val c = capsule

    WaPage(title = c?.let { "${it.emoji ?: "📦"}  ${it.title}" } ?: stringResource(R.string.mo_capsules), onBack = onBack) {
        WaError(error)
        if (c == null) {
            repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(90.dp).padding(bottom = 10.dp), RoundedCornerShape(22.dp)) }
            return@WaPage
        }
        val items = c.items.orEmpty()
        if (items.isEmpty()) {
            WaCard(Modifier.fillMaxWidth()) { WaEmpty(Icons.Rounded.Inventory2, Tone.Gray, stringResource(R.string.mo_capsule_empty), stringResource(R.string.mo_capsule_empty_sub)) }
        }
        items.forEachIndexed { i, item ->
            Column(Modifier.fillMaxWidth().padding(bottom = 8.dp).waRise(i).capHairline().padding(12.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(listOfNotNull(item.senderName, dayText(item.originalAt).ifEmpty { null }).joinToString("  ·  "), color = settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                    Icon(Icons.Rounded.Delete, null, tint = settingsMut(), modifier = Modifier.size(18.dp).clickable {
                        scope.launch { runCatching { ApiClient.moments.removeFromCapsule(chatAuth(), c.id, item.id).data }.onSuccess { capsule = it } }
                    })
                }
                item.text?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsInk(), fontSize = 13.5.sp, modifier = Modifier.padding(top = 4.dp)) }
                Files(item.files, "cap-${item.id}")
                item.note?.let { Text("📝 $it", color = settingsAccent(), fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp)) }
            }
        }
        Spacer(Modifier.height(10.dp))
        WaButton(stringResource(R.string.mo_capsule_delete), {
            scope.launch { runCatching { ApiClient.moments.deleteCapsule(chatAuth(), c.id) }.onSuccess { onBack() } }
        }, style = WaButtonStyle.Quiet, icon = Icons.Rounded.Delete)
    }
}

/** "Keep in a capsule" on a message: pick one (or start one), with an optional note. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CapsulePickerSheet(messageId: String, onDismiss: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var capsules by remember { mutableStateOf<List<CapsuleDto>?>(null) }
    var note by remember { mutableStateOf("") }
    var creating by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(Unit) { capsules = runCatching { ApiClient.moments.capsules(chatAuth()).data }.getOrNull().orEmpty() }

    fun keep(capsuleId: String) {
        scope.launch {
            runCatching {
                ApiClient.moments.addToCapsule(chatAuth(), capsuleId, JsonObject().apply {
                    addProperty("message_id", messageId)
                    note.trim().takeIf { it.isNotEmpty() }?.let { addProperty("note", it) }
                })
            }.onSuccess {
                Toast.makeText(context, context.getString(R.string.mo_capsule_kept), Toast.LENGTH_SHORT).show()
                onDismiss()
            }.onFailure { error = it.apiFailure().message }
        }
    }

    if (creating) {
        NewCapsuleSheet(onDismiss = { creating = false }) { keep(it.id) }
        return
    }
    ChSheet(
        onDismissRequest = onDismiss,
        sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true),
        containerColor = Wa.Surface,
        shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp),
    ) {
        Column(
            Modifier
                .fillMaxWidth()
                .navigationBarsPadding()
                .imePadding()
                .padding(horizontal = 20.dp)
                .padding(bottom = 24.dp),
        ) {
            Text(stringResource(R.string.mo_capsule_keep), color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.mo_capsule_keep_sub), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.padding(top = 4.dp))
            Spacer(Modifier.height(10.dp))
            DorrTextField(
                note, { note = it.take(300) }, modifier = Modifier.fillMaxWidth(),
                label = stringResource(R.string.mo_capsule_note), icon = Icons.Rounded.Edit,
            )
            Spacer(Modifier.height(10.dp))
            val list = capsules
            if (list == null) CircularProgressIndicator(color = settingsAccent(), modifier = Modifier.align(Alignment.CenterHorizontally).padding(16.dp))
            else Column(Modifier.fillMaxWidth().capHairline().padding(horizontal = 6.dp, vertical = 2.dp).heightIn(max = 340.dp).verticalScroll(rememberScrollState())) {
                list.forEachIndexed { i, c ->
                    Row(
                        Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).clickable { keep(c.id) }.padding(horizontal = 4.dp, vertical = 10.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Box(
                            Modifier.size(34.dp).clip(RoundedCornerShape(10.dp)).background(settingsAccent().copy(alpha = 0.12f)),
                            contentAlignment = Alignment.Center,
                        ) { Text(c.emoji ?: "📦", fontSize = 16.sp) }
                        Spacer(Modifier.width(10.dp))
                        Text(c.title, color = settingsInk(), fontSize = 13.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                        Text(stringResource(R.string.mo_capsule_count, c.itemsCount), color = settingsMut(), fontSize = 11.sp)
                    }
                    if (i != list.lastIndex) Box(Modifier.fillMaxWidth().padding(horizontal = 8.dp).height(1.dp).background(if (settingsNight()) AccountDark.line else Color(0xFFF0F1F4)))
                }
                Row(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).clickable { creating = true }.padding(horizontal = 4.dp, vertical = 10.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Rounded.Add, null, tint = settingsAccent(), modifier = Modifier.size(20.dp))
                    Spacer(Modifier.width(10.dp))
                    Text(stringResource(R.string.mo_capsule_new), color = settingsAccent(), fontSize = 13.5.sp, fontWeight = FontWeight.Bold)
                }
            }
            WaError(error)
        }
    }
}
