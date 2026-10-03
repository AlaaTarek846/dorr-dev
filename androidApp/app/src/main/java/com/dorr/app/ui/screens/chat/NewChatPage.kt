package com.dorr.app.ui.screens.chat

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.net.Uri
import android.provider.ContactsContract
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandHorizontally
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.animation.shrinkHorizontally
import androidx.compose.animation.shrinkVertically
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.automirrored.rounded.ArrowForward
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.Chat
import androidx.compose.material.icons.rounded.Share
import androidx.compose.material.icons.rounded.Sms
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.material.icons.rounded.AddAPhoto
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Contacts
import androidx.compose.material.icons.rounded.GroupAdd
import androidx.compose.material.icons.rounded.Phone
import androidx.compose.material.icons.rounded.Bookmark
import androidx.compose.material.icons.rounded.Campaign
import androidx.compose.material.icons.rounded.Dialpad
import androidx.compose.material.icons.rounded.QrCode2
import androidx.compose.material.icons.rounded.QrCodeScanner
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Sync
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.rotate
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ContactDto
import com.dorr.app.network.ContactEntry
import com.dorr.app.network.ContactSyncRequest
import com.dorr.app.network.OpenDirectRequest
import com.dorr.app.network.ProfileDto
import com.dorr.app.network.QrDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.theme.CairoFontFamily
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody

/** The frame of every secondary chat page: brand header with back + title, content below. */
@Composable
internal fun ChPage(title: String, onBack: () -> Unit, actions: @Composable () -> Unit = {}, content: @Composable () -> Unit) {
    Column(Modifier.fillMaxSize().background(Ch.Bg)) {
        Box(Modifier.fillMaxWidth().clip(RoundedCornerShape(bottomStart = 26.dp, bottomEnd = 26.dp)).background(Ch.HeaderBrush)) {
            Row(Modifier.fillMaxWidth().padding(start = 10.dp, end = 10.dp, top = 12.dp, bottom = 16.dp), verticalAlignment = Alignment.CenterVertically) {
                GlassIcon(Icons.AutoMirrored.Rounded.ArrowBack, onClick = onBack)
                Spacer(Modifier.width(12.dp))
                Text(title, color = Color.White, fontSize = 20.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                actions()
            }
        }
        Box(Modifier.weight(1f).fillMaxWidth()) { content() }
    }
}

/** Opens (or creates) the direct chat with someone and moves to it. */
internal fun ChatHost.openChatWith(profile: ProfileDto) {
    scope.launch {
        try {
            val conversation = ApiClient.chat.openDirect(chatAuth(), OpenDirectRequest(profile.id, profile.type)).data ?: return@launch
            replace(ChRoute.Conversation(conversation.id, conversation))
        } catch (e: Exception) {
            e.apiFailure().message?.let { showToast(it) }
        }
    }
}

/** Opens my "note to self" chat (created on first use). */
internal fun ChatHost.openNoteToSelf() {
    scope.launch {
        try {
            val conversation = ApiClient.chat.openSelf(chatAuth()).data ?: return@launch
            replace(ChRoute.Conversation(conversation.id, conversation))
        } catch (e: Exception) {
            e.apiFailure().message?.let { showToast(it) }
        }
    }
}

// =============================================================================== new chat

@Composable
fun NewChatPage() {
    val host = LocalChat.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var contacts by remember { mutableStateOf<List<ContactDto>?>(null) }
    var query by remember { mutableStateOf("") }
    var syncing by remember { mutableStateOf(false) }
    var synced by remember { mutableStateOf<Int?>(null) }
    var lookupError by remember { mutableStateOf<String?>(null) }
    // The "add by number" sheet; the text is what was typed in the search box, if anything.
    var byPhone by remember { mutableStateOf<String?>(null) }
    val notOnDorr = stringResource(R.string.ch_not_on_dorr)
    val invalidQr = stringResource(R.string.ch_error_network)

    // What's in the phone's own address book — shown even if the server can't be reached.
    var deviceBook by remember { mutableStateOf<List<ContactEntry>>(emptyList()) }
    var inviting by remember { mutableStateOf<ContactEntry?>(null) }
    val me = com.dorr.app.network.AuthSession.user
    var permissionDenied by remember { mutableStateOf(false) }
    val syncFailed = stringResource(R.string.ch_sync_failed)

    suspend fun loadContacts() {
        // All my saved numbers (registered or not): the ones on Dorr to chat, the rest to invite.
        contacts = runCatching { ApiClient.chat.contacts(chatAuth(), registered = 0).data }.getOrNull() ?: contacts.orEmpty()
    }

    fun sync() = scope.launch {
        syncing = true
        val book = withContext(Dispatchers.IO) { readAddressBook(context) }
        deviceBook = book
        var registered = 0
        var failed = false
        book.chunked(1500).forEachIndexed { i, chunk ->
            try {
                ApiClient.chat.syncContacts(chatAuth(), ContactSyncRequest(chunk, full = book.size <= 1500)).data?.let { registered = it.size }
            } catch (e: Exception) {
                failed = true
                if (i == 0) host.showToast(e.apiFailure().message ?: syncFailed)
            }
        }
        synced = if (failed && registered == 0) null else registered
        loadContacts()
        syncing = false
    }

    val contactsPermission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        permissionDenied = !granted
        if (granted) sync()
    }
    val scanner = rememberLauncherForActivityResult(ScanContract()) { result ->
        val text = result.contents ?: return@rememberLauncherForActivityResult
        // A group's invite QR: the join sheet (join / ask to join), not a person.
        if (text.startsWith("dorr://chat/join/")) {
            host.joinToken = text.removePrefix("dorr://chat/join/")
            return@rememberLauncherForActivityResult
        }
        scope.launch {
            runCatching { ApiClient.chat.resolveQr(chatAuth(), mapOf("payload" to text)).data }
                .onSuccess { it?.let { p -> host.openChatWith(p) } }
                .onFailure { host.showToast(it.apiFailure().message ?: invalidQr) }
        }
    }

    LaunchedEffect(Unit) {
        loadContacts()
        // Every visit: sync the address book (new friends on Dorr show up), or ask for access once.
        if (ContextCompat.checkSelfPermission(context, Manifest.permission.READ_CONTACTS) == PackageManager.PERMISSION_GRANTED) sync()
        else contactsPermission.launch(Manifest.permission.READ_CONTACTS)
    }

    val digits = query.filter { it.isDigit() || it == '+' }
    val looksLikeNumber = digits.length >= 8 && digits.length >= query.trim().length - 2
    fun matches(name: String, phone: String) = query.isBlank() || name.contains(query, true) || phone.filter { it.isDigit() }.contains(digits.filter { it.isDigit() }.ifEmpty { "~" })
    val all = contacts.orEmpty()
    val onDorr = all.filter { it.isRegistered && matches(it.name, it.phone) }
    // Not on Dorr yet: from the server when it answered, else straight from the phone.
    val toInvite: List<ContactEntry> = if (all.isNotEmpty()) all.filter { !it.isRegistered }.map { ContactEntry(it.name, it.phone) } else deviceBook
    val invitees = toInvite.filter { matches(it.name, it.phone) }

    ChPage(stringResource(R.string.ch_new_chat), onBack = { host.pop() }) {
        LazyColumn(contentPadding = PaddingValues(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxSize()) {
            item {
                ChField(query, { query = it; lookupError = null }, stringResource(R.string.ch_find_by_number), icon = Icons.Rounded.Search, clearable = !looksLikeNumber, trailing = {
                    AnimatedVisibility(looksLikeNumber, enter = scaleIn() + fadeIn(), exit = scaleOut() + fadeOut()) {
                        Text(
                            stringResource(R.string.ch_find), color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 13.sp,
                            modifier = Modifier.clip(RoundedCornerShape(12.dp)).background(Ch.Red).clickable { byPhone = query }.padding(horizontal = 12.dp, vertical = 6.dp),
                        )
                    }
                })
                AnimatedVisibility(lookupError != null) {
                    Text(lookupError.orEmpty(), color = Ch.Danger, fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 6.dp, start = 8.dp))
                }
            }
            item {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    ShortcutTile(Icons.Rounded.GroupAdd, stringResource(R.string.ch_new_group), 0, Modifier.weight(1f)) { host.replace(ChRoute.NewGroup()) }
                    ShortcutTile(Icons.Rounded.Dialpad, stringResource(R.string.ch_by_number), 1, Modifier.weight(1f)) { byPhone = "" }
                    ShortcutTile(Icons.Rounded.QrCodeScanner, stringResource(R.string.ch_scan_qr), 2, Modifier.weight(1f)) {
                        scanner.launch(ScanOptions().setPrompt(context.getString(R.string.ch_scan_prompt)).setBeepEnabled(false).setOrientationLocked(false))
                    }
                    ShortcutTile(Icons.Rounded.QrCode2, stringResource(R.string.ch_my_qr), 3, Modifier.weight(1f)) { host.push(ChRoute.MyQr) }
                }
            }
            // Channels: discover, follow, or start one.
            item {
                Row(
                    Modifier.fillMaxWidth().chStagger(4).shadow(4.dp, RoundedCornerShape(20.dp)).clip(RoundedCornerShape(20.dp)).background(Ch.Surface)
                        .clickable { host.push(ChRoute.Channels) }.padding(14.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Box(Modifier.size(44.dp).clip(CircleShape).background(Ch.HeaderBrush), contentAlignment = Alignment.Center) {
                        Icon(Icons.Rounded.Campaign, null, tint = Color.White, modifier = Modifier.size(24.dp))
                    }
                    Spacer(Modifier.width(12.dp))
                    Column(Modifier.weight(1f)) {
                        Text(stringResource(R.string.ch_channels), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp)
                        Text(stringResource(R.string.ch_channels_hero_sub), color = Ch.Mut, fontSize = 12.5.sp, maxLines = 1)
                    }
                    Icon(Icons.AutoMirrored.Rounded.ArrowForward, null, tint = Ch.Soft, modifier = Modifier.size(20.dp))
                }
            }
            // Note to self: notes, links and files I keep for myself, on all my devices.
            item {
                Row(
                    Modifier.fillMaxWidth().chStagger(5).shadow(4.dp, RoundedCornerShape(20.dp)).clip(RoundedCornerShape(20.dp)).background(Ch.Surface)
                        .clickable { host.openNoteToSelf() }.padding(14.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Box(Modifier.size(44.dp).clip(CircleShape).background(Ch.TintBrush), contentAlignment = Alignment.Center) {
                        Icon(Icons.Rounded.Bookmark, null, tint = Ch.Red, modifier = Modifier.size(22.dp))
                    }
                    Spacer(Modifier.width(12.dp))
                    Column(Modifier.weight(1f)) {
                        Text(stringResource(R.string.ch_note_to_self), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp)
                        Text(stringResource(R.string.ch_note_to_self_sub), color = Ch.Mut, fontSize = 12.5.sp, maxLines = 1)
                    }
                    Icon(Icons.AutoMirrored.Rounded.ArrowForward, null, tint = Ch.Soft, modifier = Modifier.size(20.dp))
                }
            }
            item { SyncCard(syncing, synced, permissionDenied) {
                if (ContextCompat.checkSelfPermission(context, Manifest.permission.READ_CONTACTS) == PackageManager.PERMISSION_GRANTED) sync()
                else if (permissionDenied) {
                    // Refused before (maybe "don't ask again"): the app settings are the way back.
                    context.startActivity(android.content.Intent(android.provider.Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.fromParts("package", context.packageName, null)))
                } else contactsPermission.launch(Manifest.permission.READ_CONTACTS)
            } }
            item { SectionTitle(stringResource(R.string.ch_contacts_on_dorr), onDorr.size) }
            when {
                contacts == null && syncing -> items(5) { com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().height(64.dp), RoundedCornerShape(18.dp)) }
                onDorr.isEmpty() -> item { Text(stringResource(R.string.ch_no_contacts), color = Ch.Soft, modifier = Modifier.fillMaxWidth().padding(20.dp), textAlign = TextAlign.Center) }
                else -> itemsIndexed(onDorr, key = { _, c -> "dorr-" + c.id }) { i, contact ->
                    ContactRow(contact, i) { contact.profile?.let { host.openChatWith(it) } }
                }
            }
            if (invitees.isNotEmpty()) {
                item { SectionTitle(stringResource(R.string.ch_invite_section), invitees.size) }
                itemsIndexed(invitees.take(300), key = { i, c -> "invite-$i-" + c.phone }) { i, entry ->
                    InviteRow(entry, i) { inviting = entry }
                }
            }
        }
    }

    inviting?.let { entry -> InviteSheet(entry, contactCountry = me?.phone) { inviting = null } }
    byPhone?.let { typed -> AddByPhoneSheet(typed, onDismiss = { byPhone = null }) }
}

/**
 * Invite someone who isn't on Dorr: WhatsApp (straight into a chat with *that* number, message
 * already typed), Telegram, SMS, or "More" — the phone's own share sheet (Instagram, Messenger,
 * Snapchat, e-mail…).
 */
@OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)
@Composable
internal fun InviteSheet(entry: ContactEntry, contactCountry: String?, onDismiss: () -> Unit) {
    val context = LocalContext.current
    val text = stringResource(R.string.ch_invite_text)
    val digits = entry.phone.filter { it.isDigit() || it == '+' }
    // wa.me wants the full international number without "+" or a trunk 0.
    val international = remember(digits, contactCountry) { internationalDigits(digits, contactCountry) }

    fun open(intent: android.content.Intent): Boolean = runCatching { context.startActivity(intent.addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK)); true }.getOrDefault(false)
    fun share(pkg: String?): Boolean = open(
        android.content.Intent(android.content.Intent.ACTION_SEND).setType("text/plain").putExtra(android.content.Intent.EXTRA_TEXT, text).apply { pkg?.let { setPackage(it) } },
    )
    fun chooser() = open(android.content.Intent.createChooser(android.content.Intent(android.content.Intent.ACTION_SEND).setType("text/plain").putExtra(android.content.Intent.EXTRA_TEXT, text), null))

    androidx.compose.material3.ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(start = 20.dp, end = 20.dp, bottom = 34.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                ChAvatar(null, entry.name, entry.phone, size = 46.dp)
                Spacer(Modifier.width(12.dp))
                Column {
                    Text(stringResource(R.string.ch_invite_title, entry.name.ifBlank { ltrNumber(entry.phone) }), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp)
                    Text(text, color = Ch.Mut, fontSize = 12.5.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
                }
            }
            Spacer(Modifier.height(20.dp))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceEvenly) {
                ShareTarget("WhatsApp", Color(0xFF25D366), Icons.Rounded.Chat, 0) {
                    onDismiss()
                    val direct = android.content.Intent(android.content.Intent.ACTION_VIEW, Uri.parse("https://wa.me/$international?text=" + Uri.encode(text)))
                    if (!open(direct.setPackage("com.whatsapp")) && !open(direct.setPackage("com.whatsapp.w4b")) && !open(direct.setPackage(null))) chooser()
                }
                ShareTarget("Telegram", Color(0xFF229ED9), Icons.AutoMirrored.Rounded.Send, 1) {
                    onDismiss()
                    if (!share("org.telegram.messenger") && !open(android.content.Intent(android.content.Intent.ACTION_VIEW, Uri.parse("https://t.me/share/url?url=" + Uri.encode(text))))) chooser()
                }
                ShareTarget("SMS", Color(0xFF6366F1), Icons.Rounded.Sms, 2) {
                    onDismiss()
                    if (!open(android.content.Intent(android.content.Intent.ACTION_SENDTO, Uri.parse("smsto:$digits")).putExtra("sms_body", text))) chooser()
                }
                ShareTarget(stringResource(R.string.ch_share_more), Color(0xFFDB2777), Icons.Rounded.Share, 3) {
                    onDismiss()
                    chooser()
                }
            }
        }
    }
}

@Composable
private fun ShareTarget(label: String, color: Color, icon: ImageVector, index: Int, onClick: () -> Unit) {
    val pop = remember { androidx.compose.animation.core.Animatable(0f) }
    LaunchedEffect(Unit) {
        kotlinx.coroutines.delay(60L + index * 55L)
        pop.animateTo(1f, androidx.compose.animation.core.spring(dampingRatio = 0.45f, stiffness = 420f))
    }
    Column(
        Modifier.width(76.dp).graphicsLayer { scaleX = pop.value; scaleY = pop.value; alpha = pop.value.coerceIn(0f, 1f) },
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Box(
            Modifier.size(58.dp).shadow(12.dp, CircleShape, spotColor = color.copy(alpha = 0.55f)).clip(CircleShape).background(color).clickable(onClick = onClick),
            contentAlignment = Alignment.Center,
        ) { Icon(icon, null, tint = Color.White, modifier = Modifier.size(26.dp)) }
        Spacer(Modifier.height(8.dp))
        Text(label, color = Ch.Ink, fontSize = 12.sp, fontWeight = FontWeight.SemiBold, maxLines = 1)
    }
}

/** "0100 000 0002" + my own "+20…" → "201000000002" (what wa.me expects). */
private fun internationalDigits(phone: String, myPhone: String?): String {
    if (phone.startsWith("+")) return phone.drop(1)
    if (phone.startsWith("00")) return phone.drop(2)
    // Borrow my own country code: first 1–3 digits of my number that aren't part of a local 0-number.
    val mine = myPhone?.takeIf { it.startsWith("+") }?.drop(1).orEmpty()
    val national = phone.removePrefix("0")
    val code = listOf(3, 2, 1).map { mine.take(it) }.firstOrNull { it.isNotEmpty() && mine.length - it.length in 8..11 } ?: ""
    return code + national
}

@Composable
private fun ShortcutTile(icon: ImageVector, label: String, index: Int, modifier: Modifier, onClick: () -> Unit) {
    Column(
        modifier.chStagger(index).shadow(4.dp, RoundedCornerShape(20.dp)).clip(RoundedCornerShape(20.dp)).background(Ch.Surface).clickable(onClick = onClick).padding(vertical = 14.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Box(Modifier.size(44.dp).clip(CircleShape).background(Ch.HeaderBrush), contentAlignment = Alignment.Center) { Icon(icon, null, tint = Color.White, modifier = Modifier.size(22.dp)) }
        Spacer(Modifier.height(8.dp))
        Text(label, color = Ch.Ink, fontSize = 12.sp, fontWeight = FontWeight.Bold, maxLines = 1, textAlign = TextAlign.Center)
    }
}

@Composable
private fun SectionTitle(title: String, count: Int) {
    Row(Modifier.fillMaxWidth().padding(start = 8.dp, end = 8.dp, top = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(title, color = Ch.Mut, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.weight(1f))
        if (count > 0) Text(count.toString(), color = Ch.Soft, fontSize = 12.sp)
    }
}

/** Someone in my phone who isn't on Dorr yet: their initials and an "Invite" pill (opens SMS). */
@Composable
private fun InviteRow(entry: ContactEntry, index: Int, onInvite: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().chStagger(index).clip(RoundedCornerShape(18.dp)).background(Ch.Surface).padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        ChAvatar(null, entry.name, entry.phone, size = 42.dp)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(entry.name.ifBlank { ltrNumber(entry.phone) }, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            androidx.compose.runtime.CompositionLocalProvider(androidx.compose.ui.platform.LocalLayoutDirection provides androidx.compose.ui.unit.LayoutDirection.Ltr) {
                Text(ltrNumber(entry.phone), color = Ch.Mut, fontSize = 12.sp)
            }
        }
        Text(
            stringResource(R.string.ch_invite), color = Ch.Red, fontWeight = FontWeight.ExtraBold, fontSize = 12.5.sp,
            modifier = Modifier.clip(RoundedCornerShape(12.dp)).background(Ch.Red.copy(alpha = 0.08f)).clickable(onClick = onInvite).padding(horizontal = 14.dp, vertical = 7.dp),
        )
    }
}

@Composable
private fun SyncCard(syncing: Boolean, synced: Int?, denied: Boolean = false, onSync: () -> Unit) {
    val spin = rememberInfiniteTransition(label = "sync")
    val angle by spin.animateFloat(0f, 360f, infiniteRepeatable(tween(1000, easing = LinearEasing)), label = "angle")
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(Ch.TintBrush).clickable(enabled = !syncing, onClick = onSync).padding(14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(46.dp).clip(CircleShape).background(Ch.Red), contentAlignment = Alignment.Center) {
            Icon(if (syncing) Icons.Rounded.Sync else Icons.Rounded.Contacts, null, tint = Color.White, modifier = Modifier.size(24.dp).rotate(if (syncing) -angle else 0f))
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            AnimatedContent(targetState = Triple(syncing, synced, 0), label = "syncText", transitionSpec = { fadeIn() togetherWith fadeOut() }) { (busy, count, _) ->
                Column {
                    Text(stringResource(if (busy) R.string.ch_syncing else R.string.ch_sync_contacts), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 14.5.sp)
                    Text(
                        when {
                            denied && !busy -> stringResource(R.string.ch_contacts_denied)
                            count != null && !busy -> stringResource(R.string.ch_synced, count)
                            else -> stringResource(R.string.ch_contacts_permission)
                        },
                        color = if (denied && !busy) Ch.Danger else Ch.Mut, fontSize = 12.sp,
                    )
                }
            }
        }
        if (!syncing) Icon(Icons.Rounded.Refresh, null, tint = Ch.Red, modifier = Modifier.size(22.dp))
    }
}

@Composable
private fun ContactRow(contact: ContactDto, index: Int, trailing: (@Composable () -> Unit)? = null, onClick: () -> Unit) {
    val host = LocalChat.current
    Row(
        Modifier.fillMaxWidth().chStagger(index).clip(RoundedCornerShape(18.dp)).background(Ch.Surface).clickable(onClick = onClick).padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        ChAvatar(contact.profile?.avatar, contact.name, contact.profile?.key ?: contact.phone, size = 46.dp, online = host.presence[contact.profile?.key]?.online == true)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(contact.name, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            androidx.compose.runtime.CompositionLocalProvider(androidx.compose.ui.platform.LocalLayoutDirection provides androidx.compose.ui.unit.LayoutDirection.Ltr) {
                Text(ltrNumber(contact.phone), color = Ch.Mut, fontSize = 12.5.sp)
            }
        }
        trailing?.invoke()
    }
}

/** Every phone number in the address book, with its contact's name. */
private fun readAddressBook(context: Context): List<ContactEntry> {
    val out = LinkedHashMap<String, ContactEntry>()
    runCatching {
        context.contentResolver.query(
            ContactsContract.CommonDataKinds.Phone.CONTENT_URI,
            arrayOf(ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME, ContactsContract.CommonDataKinds.Phone.NUMBER),
            null, null, null,
        )?.use { c ->
            while (c.moveToNext()) {
                val name = c.getString(0).orEmpty()
                val number = c.getString(1)?.trim().orEmpty()
                if (number.isNotEmpty()) out.putIfAbsent(number.filter { it.isDigit() || it == '+' }, ContactEntry(name, number))
            }
        }
    }
    return out.values.toList()
}

// =============================================================================== new group / add members

@Composable
fun NewGroupPage(addTo: String?) {
    val host = LocalChat.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    // null = still loading (skeletons), [] = none on Dorr (the empty state).
    var contacts by remember { mutableStateOf<List<ContactDto>?>(null) }
    val selected = remember { mutableStateListOf<ContactDto>() }
    var query by remember { mutableStateOf("") }
    var byPhone by remember { mutableStateOf(false) }
    var step by remember { mutableStateOf(0) }
    var name by remember { mutableStateOf("") }
    var description by remember { mutableStateOf("") }
    var avatar by remember { mutableStateOf<Uri?>(null) }
    var busy by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) { contacts = runCatching { ApiClient.chat.contacts(chatAuth(), registered = 1).data }.getOrNull().orEmpty() }
    // People found by number join the list as if they were contacts (negative ids never clash).
    fun addFound(profile: ProfileDto) {
        val entry = ContactDto(-profile.id, profile.name ?: profile.phone.orEmpty(), profile.phone.orEmpty(), false, "lookup", true, profile)
        if (contacts.orEmpty().none { it.profile?.id == profile.id }) contacts = listOf(entry) + contacts.orEmpty()
        val existing = contacts.orEmpty().first { it.profile?.id == profile.id }
        if (selected.none { it.id == existing.id }) selected.add(existing)
    }
    val shown = contacts.orEmpty().filter { query.isBlank() || it.name.contains(query, true) || it.phone.filter(Char::isDigit).contains(query.filter(Char::isDigit).ifEmpty { "~" }) }
    val photo = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { avatar = it }

    fun toggle(c: ContactDto) {
        if (selected.any { it.id == c.id }) selected.removeAll { it.id == c.id } else selected.add(c)
    }

    fun submit() = scope.launch {
        busy = true
        try {
            val ids = selected.mapNotNull { it.profile?.id }
            if (addTo != null) {
                ApiClient.chat.addMembers(chatAuth(), addTo, mapOf("members" to ids))
                host.pop()
            } else {
                val fields = mutableMapOf<String, RequestBody>(
                    "name" to name.trim().toRequestBody("text/plain".toMediaTypeOrNull()),
                    "description" to description.trim().toRequestBody("text/plain".toMediaTypeOrNull()),
                )
                ids.forEachIndexed { i, id -> fields["members[$i]"] = id.toString().toRequestBody("text/plain".toMediaTypeOrNull()) }
                val part = avatar?.let { copyToCache(context, it, "avatar.jpg") }?.let { f ->
                    MultipartBody.Part.createFormData("avatar", f.name, f.file.asRequestBody(f.mime.toMediaTypeOrNull()))
                }
                val created = ApiClient.chat.createGroup(chatAuth(), fields, part).data
                if (created != null) {
                    host.upsert(created.conversation)
                    if (created.notAdded.isNotEmpty()) host.showToast(context.getString(R.string.ch_not_added, created.notAdded.size))
                    host.replace(ChRoute.Conversation(created.conversation.id, created.conversation))
                }
            }
        } catch (e: Exception) {
            e.apiFailure().message?.let { host.showToast(it) }
        }
        busy = false
    }

    ChPage(
        title = stringResource(if (addTo != null) R.string.ch_add_members else R.string.ch_new_group),
        onBack = { if (step == 1) step = 0 else host.pop() },
    ) {
        AnimatedContent(targetState = step, label = "groupStep", transitionSpec = {
            (fadeIn(tween(250)) + expandHorizontally()) togetherWith (fadeOut(tween(200)) + shrinkHorizontally())
        }) { s ->
            if (s == 0) {
                Box(Modifier.fillMaxSize()) {
                    Column(Modifier.fillMaxSize()) {
                        // Chosen people as chips that jump in and out.
                        AnimatedVisibility(selected.isNotEmpty(), enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
                            LazyRow(contentPadding = PaddingValues(horizontal = 14.dp, vertical = 10.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                                items(selected, key = { it.id }) { c ->
                                    Column(Modifier.animateItem().width(62.dp).clickable { toggle(c) }, horizontalAlignment = Alignment.CenterHorizontally) {
                                        Box {
                                            ChAvatar(c.profile?.avatar, c.name, c.profile?.key, size = 52.dp, modifier = Modifier.chPopIn(true))
                                            Box(Modifier.align(Alignment.BottomEnd).size(20.dp).clip(CircleShape).background(Ch.Mut).border(2.dp, Color.White, CircleShape), contentAlignment = Alignment.Center) {
                                                Icon(Icons.Rounded.Close, null, tint = Color.White, modifier = Modifier.size(12.dp))
                                            }
                                        }
                                        Text(c.name, fontSize = 11.sp, color = Ch.Ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                    }
                                }
                            }
                        }
                        // Search + "add by number": always there, even with an empty address book.
                        Row(Modifier.fillMaxWidth().padding(horizontal = 14.dp, vertical = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                            ChField(query, { query = it }, stringResource(R.string.ch_search_contacts), modifier = Modifier.weight(1f), icon = Icons.Rounded.Search, clearable = true)
                            Box(
                                Modifier.size(46.dp).shadow(8.dp, RoundedCornerShape(16.dp), spotColor = Ch.Red.copy(alpha = 0.4f)).clip(RoundedCornerShape(16.dp)).background(Ch.HeaderBrush).clickable { byPhone = true },
                                contentAlignment = Alignment.Center,
                            ) { Icon(Icons.Rounded.Dialpad, null, tint = Color.White, modifier = Modifier.size(22.dp)) }
                        }
                        if (contacts?.isNotEmpty() == true) {
                            Text(stringResource(R.string.ch_selected, selected.size), color = Ch.Mut, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.padding(start = 22.dp, top = 4.dp, bottom = 4.dp))
                        }
                        when {
                            contacts == null -> Column(Modifier.padding(horizontal = 14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                                repeat(6) { com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().height(66.dp), RoundedCornerShape(18.dp)) }
                            }
                            contacts!!.isEmpty() -> NoContactsForGroup(onByNumber = { byPhone = true }, onInvite = {
                                runCatching {
                                    context.startActivity(
                                        android.content.Intent.createChooser(
                                            android.content.Intent(android.content.Intent.ACTION_SEND).setType("text/plain").putExtra(android.content.Intent.EXTRA_TEXT, context.getString(R.string.ch_invite_text)),
                                            null,
                                        ).addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK),
                                    )
                                }
                            })
                            shown.isEmpty() -> Text(stringResource(R.string.ch_no_results), color = Ch.Soft, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(30.dp))
                            else -> LazyColumn(contentPadding = PaddingValues(start = 14.dp, end = 14.dp, bottom = 110.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                            itemsIndexed(shown, key = { _, c -> c.id }) { i, c ->
                                val on = selected.any { it.id == c.id }
                                ContactRow(c, i, trailing = {
                                    AnimatedContent(on, label = "pick", transitionSpec = { scaleIn(spring(dampingRatio = 0.4f)) togetherWith scaleOut() }) { checked ->
                                        Icon(if (checked) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (checked) Ch.Red else Ch.Soft, modifier = Modifier.size(26.dp))
                                    }
                                }) { toggle(c) }
                            }
                        }
                        }
                    }
                    AnimatedVisibility(selected.isNotEmpty(), enter = scaleIn(spring(dampingRatio = 0.5f)) + fadeIn(), exit = scaleOut() + fadeOut(), modifier = Modifier.align(Alignment.BottomEnd).padding(22.dp)) {
                        Box(
                            Modifier.size(62.dp).shadow(18.dp, CircleShape, spotColor = Ch.Red.copy(alpha = 0.6f)).clip(CircleShape).background(Ch.HeaderBrush)
                                .clickable(enabled = !busy) { if (addTo != null) submit() else step = 1 },
                            contentAlignment = Alignment.Center,
                        ) { Icon(Icons.AutoMirrored.Rounded.ArrowForward, null, tint = Color.White, modifier = Modifier.size(28.dp)) }
                    }
                }
            } else {
                Column(Modifier.fillMaxSize().padding(20.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                    Box(
                        Modifier.size(112.dp).shadow(16.dp, CircleShape, spotColor = Ch.Red.copy(alpha = 0.4f)).clip(CircleShape).background(Ch.HeaderBrush)
                            .clickable { photo.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) },
                        contentAlignment = Alignment.Center,
                    ) {
                        if (avatar != null) AsyncImage(avatar, null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                        else Icon(Icons.Rounded.AddAPhoto, null, tint = Color.White, modifier = Modifier.size(38.dp))
                    }
                    Spacer(Modifier.height(22.dp))
                    GroupField(name, stringResource(R.string.ch_group_name_hint), Icons.Rounded.Groups) { name = it.take(100) }
                    Spacer(Modifier.height(10.dp))
                    GroupField(description, stringResource(R.string.ch_group_desc_hint), Icons.Rounded.Notes, multiline = true) { description = it.take(500) }
                    Spacer(Modifier.height(18.dp))
                    Text(stringResource(R.string.ch_selected, selected.size), color = Ch.Mut, fontSize = 13.sp)
                    Spacer(Modifier.weight(1f))
                    ChPrimaryButton(stringResource(R.string.ch_create_group), modifier = Modifier.fillMaxWidth(), icon = Icons.Rounded.GroupAdd, enabled = name.isNotBlank() && !busy) { submit() }
                }
            }
        }
    }

    if (byPhone) AddByPhoneSheet(onDismiss = { byPhone = false }, onPicked = { addFound(it) })
}

/**
 * Nobody from the address book is on Dorr yet: a friendly card instead of a blank page — floating
 * chat bubbles around a group icon, and the two ways forward: add someone by number, or invite.
 */
@Composable
private fun NoContactsForGroup(onByNumber: () -> Unit, onInvite: () -> Unit) {
    val t = androidx.compose.animation.core.rememberInfiniteTransition(label = "emptyGroup")
    val float by t.animateFloat(0f, 1f, androidx.compose.animation.core.infiniteRepeatable(tween(2600, easing = androidx.compose.animation.core.FastOutSlowInEasing), androidx.compose.animation.core.RepeatMode.Reverse), label = "float")
    Column(Modifier.fillMaxSize().padding(horizontal = 28.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
        Box(Modifier.size(190.dp), contentAlignment = Alignment.Center) {
            Box(Modifier.size(170.dp).clip(CircleShape).background(Ch.Red.copy(alpha = 0.06f)))
            Box(Modifier.size(128.dp).clip(CircleShape).background(Ch.Red.copy(alpha = 0.09f)))
            Box(
                Modifier.size(88.dp).graphicsLayer { translationY = -8.dp.toPx() * float }.shadow(20.dp, CircleShape, spotColor = Ch.Red.copy(alpha = 0.5f)).clip(CircleShape).background(Ch.HeaderBrush),
                contentAlignment = Alignment.Center,
            ) { Icon(Icons.Rounded.Groups, null, tint = Color.White, modifier = Modifier.size(44.dp)) }
            Text("💬", fontSize = 26.sp, modifier = Modifier.align(Alignment.TopStart).padding(start = 14.dp, top = 22.dp).graphicsLayer { translationY = 10.dp.toPx() * float; rotationZ = -12f })
            Text("👋", fontSize = 24.sp, modifier = Modifier.align(Alignment.TopEnd).padding(end = 10.dp, top = 34.dp).graphicsLayer { translationY = -12.dp.toPx() * float })
            Text("🎉", fontSize = 22.sp, modifier = Modifier.align(Alignment.BottomStart).padding(start = 26.dp, bottom = 18.dp).graphicsLayer { translationX = 8.dp.toPx() * float })
        }
        Spacer(Modifier.height(10.dp))
        Text(stringResource(R.string.ch_group_empty_title), color = Ch.Ink, fontSize = 19.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center)
        Spacer(Modifier.height(6.dp))
        Text(stringResource(R.string.ch_group_empty_sub), color = Ch.Mut, fontSize = 13.5.sp, textAlign = TextAlign.Center)
        Spacer(Modifier.height(22.dp))
        ChPrimaryButton(stringResource(R.string.ch_add_by_phone), icon = Icons.Rounded.Dialpad, modifier = Modifier.fillMaxWidth(), onClick = onByNumber)
        Spacer(Modifier.height(10.dp))
        Box(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).border(1.5.dp, Ch.Red.copy(alpha = 0.35f), RoundedCornerShape(18.dp)).clickable(onClick = onInvite).padding(vertical = 14.dp),
            contentAlignment = Alignment.Center,
        ) { Text(stringResource(R.string.ch_invite_friends), color = Ch.Red, fontWeight = FontWeight.ExtraBold, fontSize = 14.5.sp) }
    }
}

@Composable
private fun GroupField(value: String, hint: String, icon: ImageVector, multiline: Boolean = false, onChange: (String) -> Unit) {
    ChField(value, onChange, hint, icon = icon, singleLine = !multiline, fontSize = 15.sp)
}

// =============================================================================== my QR

@Composable
fun MyQrPage() {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var qr by remember { mutableStateOf<QrDto?>(null) }
    val resetDone = stringResource(R.string.ch_qr_reset_done)
    LaunchedEffect(Unit) { qr = runCatching { ApiClient.chat.myQr(chatAuth()).data }.getOrNull() }
    val spin = rememberInfiniteTransition(label = "qrRing")
    val angle by spin.animateFloat(0f, 360f, infiniteRepeatable(tween(6000, easing = LinearEasing)), label = "ring")
    val me = com.dorr.app.network.AuthSession.user

    ChPage(stringResource(R.string.ch_my_qr_title), onBack = { host.pop() }) {
        Column(Modifier.fillMaxSize().padding(24.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
            Box(contentAlignment = Alignment.Center) {
                // A slowly turning brand ring behind the card.
                Box(Modifier.size(300.dp).rotate(angle).clip(RoundedCornerShape(44.dp)).background(Brush.sweepGradient(listOf(Ch.Red, Color(0xFFFF8A4C), Color(0xFFDB2777), Ch.Red))))
                Column(
                    Modifier.size(290.dp).clip(RoundedCornerShape(40.dp)).background(Ch.Surface).padding(18.dp),
                    horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center,
                ) {
                    val bmp = remember(qr?.payload) { qr?.payload?.let { runCatching { qrImage(it) }.getOrNull() } }
                    if (bmp != null) {
                        Box(contentAlignment = Alignment.Center) {
                            Image(bmp.asImageBitmap(), null, modifier = Modifier.size(210.dp))
                            ChAvatar(null, me?.name, myKey(), size = 46.dp, modifier = Modifier.border(3.dp, Color.White, CircleShape))
                        }
                    } else com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.size(210.dp), RoundedCornerShape(20.dp))
                    Spacer(Modifier.height(8.dp))
                    Text(me?.name.orEmpty(), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp)
                }
            }
            Spacer(Modifier.height(26.dp))
            Text(stringResource(R.string.ch_my_qr_sub), color = Ch.Mut, fontSize = 14.sp, textAlign = TextAlign.Center)
            Spacer(Modifier.height(20.dp))
            Row(
                Modifier.clip(RoundedCornerShape(16.dp)).background(Ch.Surface).clickable {
                    scope.launch {
                        qr = runCatching { ApiClient.chat.resetQr(chatAuth()).data }.getOrNull() ?: qr
                        host.showToast(resetDone)
                    }
                }.padding(horizontal = 18.dp, vertical = 12.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.Refresh, null, tint = Ch.Red, modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(8.dp))
                Text(stringResource(R.string.ch_reset_qr), color = Ch.Red, fontWeight = FontWeight.ExtraBold)
            }
        }
    }
}

