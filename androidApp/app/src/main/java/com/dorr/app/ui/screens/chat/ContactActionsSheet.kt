package com.dorr.app.ui.screens.chat

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.provider.ContactsContract
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.spring
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.togetherWith
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
import androidx.compose.material.icons.automirrored.rounded.Chat
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.ContentCopy
import androidx.compose.material.icons.rounded.PersonAdd
import androidx.compose.material.icons.rounded.Send
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.ContactEntry
import com.dorr.app.network.ProfileDto
import com.dorr.app.network.apiFailure
import kotlinx.coroutines.launch

/** A phone number kept left-to-right inside Arabic text ("+966 50…", not "…50 966+"). */
internal fun ltrNumber(text: String?): String = text?.takeIf { it.isNotBlank() }?.let { "⁦$it⁩" }.orEmpty()

/**
 * Tapping a shared contact: call it with the phone's own dialer, save it to the phone's contacts,
 * message it on Dorr — or invite it when it isn't on Dorr yet — and copy the number. A card with
 * several numbers lets you pick one first.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun ContactActionsSheet(name: String, phones: List<String>, onDismiss: () -> Unit) {
    val host = LocalChat.current
    val context = LocalContext.current
    var phone by remember { mutableStateOf(phones.firstOrNull().orEmpty()) }
    // Is this number on Dorr? null = still checking.
    var onDorr by remember { mutableStateOf<ProfileDto?>(null) }
    var checked by remember { mutableStateOf(false) }
    var inviting by remember { mutableStateOf(false) }
    val copied = stringResource(R.string.ch_number_copied)

    LaunchedEffect(phone) {
        checked = false
        onDorr = runCatching { ApiClient.chat.lookup(chatAuth(), mapOf("phone" to phone)).data }.getOrNull()?.takeIf { it.isMe != true }
        checked = true
    }

    fun open(intent: Intent) = runCatching { context.startActivity(intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }

    if (inviting) {
        InviteSheet(ContactEntry(name, phone), contactCountry = AuthSession.user?.phone) { inviting = false; onDismiss() }
        return
    }

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(start = 20.dp, end = 20.dp, bottom = 34.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            ChAvatar(onDorr?.avatar, name, onDorr?.key ?: phone, size = 72.dp, ring = onDorr != null)
            Spacer(Modifier.height(10.dp))
            Text(name.ifBlank { ltrNumber(phone) }, color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp, textAlign = TextAlign.Center)
            Text(ltrNumber(phone), color = Ch.Mut, fontSize = 14.sp, fontWeight = FontWeight.SemiBold)
            // On Dorr or not, once known.
            AnimatedContent(targetState = checked to (onDorr != null), label = "onDorr", transitionSpec = { fadeIn() togetherWith fadeOut() }) { (done, there) ->
                Box(Modifier.height(28.dp).padding(top = 6.dp), contentAlignment = Alignment.Center) {
                    when {
                        !done -> CircularProgressIndicator(Modifier.size(16.dp), color = Ch.Red, strokeWidth = 2.dp)
                        there -> Text(stringResource(R.string.ch_contact_on_dorr), color = Ch.Success, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
                        else -> Text(stringResource(R.string.ch_contact_not_on_dorr), color = Ch.Soft, fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold)
                    }
                }
            }

            // Several numbers: pick which one the actions use.
            if (phones.size > 1) {
                Spacer(Modifier.height(8.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    phones.forEach { p ->
                        val on = p == phone
                        Text(
                            ltrNumber(p), color = if (on) Color.White else Ch.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold,
                            modifier = Modifier.clip(RoundedCornerShape(12.dp)).background(if (on) Ch.Red else Ch.SurfaceMuted).clickable { phone = p }.padding(horizontal = 10.dp, vertical = 6.dp),
                        )
                    }
                }
            }

            Spacer(Modifier.height(20.dp))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceEvenly) {
                // The phone's own call, not a Dorr call.
                ContactAction(Icons.Rounded.Call, stringResource(R.string.ch_contact_call), Color(0xFF10B981), 0) {
                    open(Intent(Intent.ACTION_DIAL, Uri.parse("tel:" + phone.filter { it.isDigit() || it == '+' })))
                    onDismiss()
                }
                if (onDorr != null) {
                    ContactAction(Icons.AutoMirrored.Rounded.Chat, stringResource(R.string.ch_message_contact), Ch.Red, 1) {
                        onDismiss()
                        host.openChatWith(onDorr!!)
                    }
                } else {
                    ContactAction(Icons.Rounded.Send, stringResource(R.string.ch_invite), Color(0xFF6366F1), 1, enabled = checked) { inviting = true }
                }
                ContactAction(Icons.Rounded.PersonAdd, stringResource(R.string.ch_contact_save), Color(0xFF0EA5E9), 2) {
                    open(
                        Intent(ContactsContract.Intents.Insert.ACTION).setType(ContactsContract.RawContacts.CONTENT_TYPE)
                            .putExtra(ContactsContract.Intents.Insert.NAME, name)
                            .putExtra(ContactsContract.Intents.Insert.PHONE, phone),
                    )
                    onDismiss()
                }
                ContactAction(Icons.Rounded.ContentCopy, stringResource(R.string.ch_contact_copy), Color(0xFFF59E0B), 3) {
                    (context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager).setPrimaryClip(ClipData.newPlainText("phone", phone))
                    host.showToast(copied)
                    onDismiss()
                }
            }
        }
    }
}

@Composable
private fun ContactAction(icon: ImageVector, label: String, color: Color, index: Int, enabled: Boolean = true, onClick: () -> Unit) {
    val pop = remember { Animatable(0f) }
    LaunchedEffect(Unit) {
        kotlinx.coroutines.delay(50L + index * 50L)
        pop.animateTo(1f, spring(dampingRatio = 0.5f, stiffness = 420f))
    }
    Column(
        Modifier.width(76.dp).graphicsLayer { scaleX = pop.value; scaleY = pop.value; alpha = if (enabled) pop.value else pop.value * 0.4f }
            .clip(RoundedCornerShape(16.dp)).clickable(enabled = enabled, onClick = onClick).padding(vertical = 6.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Box(
            Modifier.size(54.dp).clip(CircleShape).background(Brush.linearGradient(listOf(color, color.copy(alpha = 0.75f)))),
            contentAlignment = Alignment.Center,
        ) { Icon(icon, null, tint = Color.White, modifier = Modifier.size(25.dp)) }
        Spacer(Modifier.height(6.dp))
        Text(label, color = Ch.Ink, fontSize = 12.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center, maxLines = 1)
    }
}

/** Is this a one-to-one chat with someone I haven't saved? (Not a group, not my own notes.) */
internal fun ConversationDtoNeedsContact(c: com.dorr.app.network.ConversationDto?): Boolean =
    c != null && !c.isGroup && !c.isSelf && !c.isRequest && c.peer != null && !c.peer.isContact && !c.peer.isDeleted

/**
 * Save the other person of a one-to-one chat to my Dorr contacts — so they see my stories
 * (story privacy is "my contacts") and appear under "Contacts on Dorr". Returns the refreshed chat.
 */
internal suspend fun addPeerToContacts(c: com.dorr.app.network.ConversationDto): com.dorr.app.network.ConversationDto? {
    val peer = c.peer ?: return null
    val phone = peer.phone?.takeIf { it.isNotBlank() } ?: return null
    ApiClient.chat.addContact(chatAuth(), mapOf("name" to (peer.accountName ?: peer.name ?: phone), "phone" to phone))
    return ApiClient.chat.conversation(chatAuth(), c.id).data
}

/**
 * "Sara isn't in your contacts" — on top of a chat with someone I haven't saved, like WhatsApp,
 * with Add (and why it matters: they'll see my stories). Closable for this visit.
 */
@Composable
internal fun NotInContactsBanner(c: com.dorr.app.network.ConversationDto, onAdded: (com.dorr.app.network.ConversationDto) -> Unit) {
    val host = LocalChat.current
    val scope = androidx.compose.runtime.rememberCoroutineScope()
    var closed by remember(c.id) { mutableStateOf(false) }
    var saving by remember { mutableStateOf(false) }
    val added = stringResource(R.string.ch_contact_added)
    val networkError = stringResource(R.string.ch_error_network)
    androidx.compose.animation.AnimatedVisibility(
        !closed && ConversationDtoNeedsContact(c),
        enter = androidx.compose.animation.expandVertically() + fadeIn(),
        exit = androidx.compose.animation.shrinkVertically() + fadeOut(),
    ) {
        Row(
            Modifier.fillMaxWidth().padding(horizontal = 10.dp, vertical = 6.dp).clip(RoundedCornerShape(18.dp)).background(Ch.Surface).padding(start = 12.dp, end = 6.dp, top = 8.dp, bottom = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(Modifier.size(36.dp).clip(CircleShape).background(Ch.Red.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.PersonAdd, null, tint = Ch.Red, modifier = Modifier.size(19.dp))
            }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.ch_not_in_contacts, c.title.orEmpty()), color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 13.5.sp, maxLines = 1)
                Text(stringResource(R.string.ch_not_in_contacts_why), color = Ch.Mut, fontSize = 11.5.sp, maxLines = 2)
            }
            Spacer(Modifier.width(6.dp))
            Box(
                Modifier.clip(RoundedCornerShape(14.dp)).background(Ch.Red).clickable(enabled = !saving) {
                    saving = true
                    scope.launch {
                        runCatching { addPeerToContacts(c) }
                            .onSuccess { fresh -> fresh?.let(onAdded); host.showToast(added) }
                            .onFailure { e -> host.showToast(e.apiFailure().message ?: networkError) }
                        saving = false
                    }
                }.padding(horizontal = 14.dp, vertical = 8.dp),
                contentAlignment = Alignment.Center,
            ) {
                if (saving) CircularProgressIndicator(Modifier.size(16.dp), color = Color.White, strokeWidth = 2.dp)
                else Text(stringResource(R.string.ch_add), color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 13.sp)
            }
            Box(Modifier.size(32.dp).clip(CircleShape).clickable { closed = true }, contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Close, null, tint = Ch.Soft, modifier = Modifier.size(18.dp))
            }
        }
    }
}
