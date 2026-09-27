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
import androidx.compose.material.icons.rounded.AddAPhoto
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Contacts
import androidx.compose.material.icons.rounded.GroupAdd
import androidx.compose.material.icons.rounded.Phone
import androidx.compose.material.icons.rounded.QrCode2
import androidx.compose.material.icons.rounded.QrCodeScanner
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.Refresh
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
    val notOnDorr = stringResource(R.string.ch_not_on_dorr)
    val invalidQr = stringResource(R.string.ch_error_network)

    suspend fun loadContacts() {
        contacts = runCatching { ApiClient.chat.contacts(chatAuth(), registered = 1).data }.getOrNull() ?: contacts.orEmpty()
    }

    fun sync() = scope.launch {
        syncing = true
        val book = withContext(Dispatchers.IO) { readAddressBook(context) }
        var registered = 0
        book.chunked(1500).forEach { chunk ->
            val result = runCatching { ApiClient.chat.syncContacts(chatAuth(), ContactSyncRequest(chunk, full = book.size <= 1500)).data }.getOrNull()
            if (result != null) registered = result.size
        }
        synced = registered
        loadContacts()
        syncing = false
    }

    val contactsPermission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted -> if (granted) sync() }
    val scanner = rememberLauncherForActivityResult(ScanContract()) { result ->
        val text = result.contents ?: return@rememberLauncherForActivityResult
        scope.launch {
            runCatching { ApiClient.chat.resolveQr(chatAuth(), mapOf("payload" to text)).data }
                .onSuccess { it?.let { p -> host.openChatWith(p) } }
                .onFailure { host.showToast(it.apiFailure().message ?: invalidQr) }
        }
    }

    LaunchedEffect(Unit) {
        loadContacts()
        // First visit with permission already granted: refresh quietly.
        if (ContextCompat.checkSelfPermission(context, Manifest.permission.READ_CONTACTS) == PackageManager.PERMISSION_GRANTED && contacts.isNullOrEmpty()) sync()
    }

    val digits = query.filter { it.isDigit() || it == '+' }
    val looksLikeNumber = digits.length >= 8 && digits.length >= query.trim().length - 2
    val filtered = contacts.orEmpty().filter { query.isBlank() || it.name.contains(query, true) || it.phone.contains(digits.ifEmpty { "~" }) }

    ChPage(stringResource(R.string.ch_new_chat), onBack = { host.pop() }) {
        LazyColumn(contentPadding = PaddingValues(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxSize()) {
            item {
                Row(
                    Modifier.fillMaxWidth().shadow(4.dp, RoundedCornerShape(18.dp)).clip(RoundedCornerShape(18.dp)).background(Color.White).padding(horizontal = 14.dp, vertical = 12.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Rounded.Search, null, tint = Ch.Red, modifier = Modifier.size(20.dp))
                    Spacer(Modifier.width(10.dp))
                    Box(Modifier.weight(1f)) {
                        if (query.isEmpty()) Text(stringResource(R.string.ch_find_by_number), color = Ch.Soft, fontSize = 14.5.sp)
                        BasicTextField(query, { query = it; lookupError = null }, singleLine = true, textStyle = TextStyle(color = Ch.Ink, fontSize = 14.5.sp, fontFamily = CairoFontFamily), cursorBrush = SolidColor(Ch.Red), modifier = Modifier.fillMaxWidth())
                    }
                    AnimatedVisibility(looksLikeNumber, enter = scaleIn() + fadeIn(), exit = scaleOut() + fadeOut()) {
                        Text(
                            stringResource(R.string.ch_find), color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 13.sp,
                            modifier = Modifier.clip(RoundedCornerShape(12.dp)).background(Ch.Red).clickable {
                                scope.launch {
                                    runCatching { ApiClient.chat.lookup(chatAuth(), mapOf("phone" to digits)).data }
                                        .onSuccess { p -> p?.let { host.openChatWith(it) } }
                                        .onFailure { lookupError = if (it.apiFailure().httpStatus == 404) notOnDorr else it.apiFailure().message ?: notOnDorr }
                                }
                            }.padding(horizontal = 12.dp, vertical = 6.dp),
                        )
                    }
                }
                AnimatedVisibility(lookupError != null) {
                    Text(lookupError.orEmpty(), color = Color(0xFFDC2626), fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 6.dp, start = 8.dp))
                }
            }
            item {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    ShortcutTile(Icons.Rounded.GroupAdd, stringResource(R.string.ch_new_group), 0, Modifier.weight(1f)) { host.replace(ChRoute.NewGroup()) }
                    ShortcutTile(Icons.Rounded.QrCodeScanner, stringResource(R.string.ch_scan_qr), 1, Modifier.weight(1f)) {
                        scanner.launch(ScanOptions().setPrompt(context.getString(R.string.ch_scan_prompt)).setBeepEnabled(false).setOrientationLocked(false))
                    }
                    ShortcutTile(Icons.Rounded.QrCode2, stringResource(R.string.ch_my_qr), 2, Modifier.weight(1f)) { host.push(ChRoute.MyQr) }
                }
            }
            item { SyncCard(syncing, synced) {
                if (ContextCompat.checkSelfPermission(context, Manifest.permission.READ_CONTACTS) == PackageManager.PERMISSION_GRANTED) sync()
                else contactsPermission.launch(Manifest.permission.READ_CONTACTS)
            } }
            item {
                Text(stringResource(R.string.ch_contacts_on_dorr), color = Ch.Mut, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.padding(start = 8.dp, top = 8.dp))
            }
            val list = contacts
            if (list == null) {
                items(5) { com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().height(64.dp), RoundedCornerShape(18.dp)) }
            } else if (filtered.isEmpty()) {
                item { Text(stringResource(R.string.ch_no_contacts), color = Ch.Soft, modifier = Modifier.fillMaxWidth().padding(24.dp), textAlign = TextAlign.Center) }
            } else {
                itemsIndexed(filtered, key = { _, c -> c.id }) { i, contact ->
                    ContactRow(contact, i) { contact.profile?.let { host.openChatWith(it) } }
                }
            }
        }
    }
}

@Composable
private fun ShortcutTile(icon: ImageVector, label: String, index: Int, modifier: Modifier, onClick: () -> Unit) {
    Column(
        modifier.chStagger(index).shadow(4.dp, RoundedCornerShape(20.dp)).clip(RoundedCornerShape(20.dp)).background(Color.White).clickable(onClick = onClick).padding(vertical = 14.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Box(Modifier.size(44.dp).clip(CircleShape).background(Ch.HeaderBrush), contentAlignment = Alignment.Center) { Icon(icon, null, tint = Color.White, modifier = Modifier.size(22.dp)) }
        Spacer(Modifier.height(8.dp))
        Text(label, color = Ch.Ink, fontSize = 12.sp, fontWeight = FontWeight.Bold, maxLines = 1, textAlign = TextAlign.Center)
    }
}

@Composable
private fun SyncCard(syncing: Boolean, synced: Int?, onSync: () -> Unit) {
    val spin = rememberInfiniteTransition(label = "sync")
    val angle by spin.animateFloat(0f, 360f, infiniteRepeatable(tween(1000, easing = LinearEasing)), label = "angle")
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(Brush.horizontalGradient(listOf(Color(0xFFFFF1F2), Color(0xFFFFE4E6)))).clickable(enabled = !syncing, onClick = onSync).padding(14.dp),
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
                    Text(if (count != null && !busy) stringResource(R.string.ch_synced, count) else stringResource(R.string.ch_contacts_permission), color = Ch.Mut, fontSize = 12.sp)
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
        Modifier.fillMaxWidth().chStagger(index).clip(RoundedCornerShape(18.dp)).background(Color.White).clickable(onClick = onClick).padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        ChAvatar(contact.profile?.avatar, contact.name, contact.profile?.key ?: contact.phone, size = 46.dp, online = host.presence[contact.profile?.key]?.online == true)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(contact.name, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            androidx.compose.runtime.CompositionLocalProvider(androidx.compose.ui.platform.LocalLayoutDirection provides androidx.compose.ui.unit.LayoutDirection.Ltr) {
                Text(contact.phone, color = Ch.Mut, fontSize = 12.5.sp)
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
    var contacts by remember { mutableStateOf<List<ContactDto>>(emptyList()) }
    val selected = remember { mutableStateListOf<ContactDto>() }
    var step by remember { mutableStateOf(0) }
    var name by remember { mutableStateOf("") }
    var description by remember { mutableStateOf("") }
    var avatar by remember { mutableStateOf<Uri?>(null) }
    var busy by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) { contacts = runCatching { ApiClient.chat.contacts(chatAuth(), registered = 1).data }.getOrNull().orEmpty() }
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
                        Text(stringResource(R.string.ch_selected, selected.size), color = Ch.Mut, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.padding(start = 22.dp, top = 8.dp, bottom = 4.dp))
                        LazyColumn(contentPadding = PaddingValues(start = 14.dp, end = 14.dp, bottom = 110.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                            itemsIndexed(contacts, key = { _, c -> c.id }) { i, c ->
                                val on = selected.any { it.id == c.id }
                                ContactRow(c, i, trailing = {
                                    AnimatedContent(on, label = "pick", transitionSpec = { scaleIn(spring(dampingRatio = 0.4f)) togetherWith scaleOut() }) { checked ->
                                        Icon(if (checked) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (checked) Ch.Red else Ch.Soft, modifier = Modifier.size(26.dp))
                                    }
                                }) { toggle(c) }
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
                    GroupField(name, stringResource(R.string.ch_group_name_hint)) { name = it.take(100) }
                    Spacer(Modifier.height(10.dp))
                    GroupField(description, stringResource(R.string.ch_group_desc_hint)) { description = it.take(500) }
                    Spacer(Modifier.height(18.dp))
                    Text(stringResource(R.string.ch_selected, selected.size), color = Ch.Mut, fontSize = 13.sp)
                    Spacer(Modifier.weight(1f))
                    ChPrimaryButton(stringResource(R.string.ch_create_group), modifier = Modifier.fillMaxWidth(), icon = Icons.Rounded.GroupAdd, enabled = name.isNotBlank() && !busy) { submit() }
                }
            }
        }
    }
}

@Composable
private fun GroupField(value: String, hint: String, onChange: (String) -> Unit) {
    Box(Modifier.fillMaxWidth().shadow(4.dp, RoundedCornerShape(18.dp)).clip(RoundedCornerShape(18.dp)).background(Color.White).padding(16.dp)) {
        if (value.isEmpty()) Text(hint, color = Ch.Soft, fontSize = 15.sp)
        BasicTextField(value, onChange, textStyle = TextStyle(color = Ch.Ink, fontSize = 15.sp, fontFamily = CairoFontFamily), cursorBrush = SolidColor(Ch.Red), modifier = Modifier.fillMaxWidth())
    }
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
                    Modifier.size(290.dp).clip(RoundedCornerShape(40.dp)).background(Color.White).padding(18.dp),
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
                Modifier.clip(RoundedCornerShape(16.dp)).background(Color.White).clickable {
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

