package com.dorr.app.ui.screens.chat

import android.content.Context
import android.hardware.biometrics.BiometricManager
import android.hardware.biometrics.BiometricPrompt
import android.net.Uri
import android.os.Build
import android.os.CancellationSignal
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.spring
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
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
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AddAPhoto
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.CreateNewFolder
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Folder
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
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
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ConversationDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.theme.CairoFontFamily
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody

// =============================================================================== chat lock

/**
 * Locked chats open only after the phone's own lock (fingerprint, face or screen PIN). Unlocked
 * once per visit; going to the background locks them again. Uses the system prompt directly —
 * no extra library, and it works from any Activity.
 */
object ChatLock {
    var unlocked by mutableStateOf(false)
        private set

    fun lock() {
        unlocked = false
    }

    fun unlock(context: Context, onSuccess: () -> Unit) {
        if (unlocked) {
            onSuccess()
            return
        }
        // Below Android 9 there's no system prompt to show.
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.P) {
            unlocked = true
            onSuccess()
            return
        }
        val executor = context.mainExecutor
        val builder = BiometricPrompt.Builder(context)
            .setTitle(context.getString(R.string.ch_unlock_title))
            .setSubtitle(context.getString(R.string.ch_unlock_sub))
        when {
            Build.VERSION.SDK_INT >= Build.VERSION_CODES.R ->
                builder.setAllowedAuthenticators(BiometricManager.Authenticators.BIOMETRIC_WEAK or BiometricManager.Authenticators.DEVICE_CREDENTIAL)
            Build.VERSION.SDK_INT == Build.VERSION_CODES.Q -> @Suppress("DEPRECATION") builder.setDeviceCredentialAllowed(true)
            else -> builder.setNegativeButton(context.getString(R.string.ch_cancel), executor) { _, _ -> }
        }
        runCatching {
            builder.build().authenticate(CancellationSignal(), executor, object : BiometricPrompt.AuthenticationCallback() {
                override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult?) {
                    unlocked = true
                    onSuccess()
                }

                override fun onAuthenticationError(errorCode: Int, errString: CharSequence?) {
                    // A phone with no lock screen at all can't protect anything: don't lock the user out.
                    if (errorCode == BiometricPrompt.BIOMETRIC_ERROR_NO_DEVICE_CREDENTIAL || errorCode == BiometricPrompt.BIOMETRIC_ERROR_HW_NOT_PRESENT) {
                        unlocked = true
                        onSuccess()
                    }
                }
            })
        }.onFailure {
            unlocked = true
            onSuccess()
        }
    }
}

/** Shown instead of a locked conversation until the phone's lock is passed. */
@Composable
fun LockedGate(onUnlocked: () -> Unit) {
    val context = LocalContext.current
    LaunchedEffect(Unit) { ChatLock.unlock(context, onUnlocked) }
    Column(Modifier.fillMaxSize().background(Ch.Bg), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
        Box(Modifier.size(96.dp).shadow(20.dp, CircleShape, spotColor = Ch.Red.copy(alpha = 0.5f)).clip(CircleShape).background(Ch.HeaderBrush), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.Lock, null, tint = Color.White, modifier = Modifier.size(44.dp))
        }
        Spacer(Modifier.height(18.dp))
        Text(stringResource(R.string.ch_locked_note), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
        Spacer(Modifier.height(20.dp))
        ChPrimaryButton(stringResource(R.string.ch_unlock), icon = Icons.Rounded.Lock) { ChatLock.unlock(context, onUnlocked) }
    }
}

// =============================================================================== small sheets

/** One text field + a button (folder name, rename…). */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun TextInputSheet(title: String, initial: String = "", action: String, icon: ImageVector = Icons.Rounded.Edit, onDismiss: () -> Unit, onDone: (String) -> Unit) {
    var value by remember { mutableStateOf(initial) }
    val focus = remember { FocusRequester() }
    LaunchedEffect(Unit) { focus.requestFocus() }
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().imePadding().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Text(title, color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(14.dp))
            ChField(value, { value = it.take(50) }, title, icon = icon, fontSize = 15.sp, fieldModifier = Modifier.focusRequester(focus))
            Spacer(Modifier.height(16.dp))
            ChPrimaryButton(action, modifier = Modifier.fillMaxWidth(), enabled = value.isNotBlank()) { onDone(value.trim()); onDismiss() }
        }
    }
}

/** Tick the folders this chat belongs to — saved as you tap. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun FolderPickerSheet(conversation: ConversationDto, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var newFolder by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    LaunchedEffect(Unit) { host.refreshFolders() }

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 28.dp)) {
            Text(stringResource(R.string.ch_add_to_folder), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(conversation.title.orEmpty(), color = Ch.Mut, fontSize = 13.sp)
            Spacer(Modifier.height(12.dp))
            host.folders.forEachIndexed { i, folder ->
                val inside = conversation.id in folder.conversationIds
                Row(
                    Modifier.fillMaxWidth().chStagger(i).clip(RoundedCornerShape(16.dp)).clickable {
                        val ids = if (inside) folder.conversationIds - conversation.id else folder.conversationIds + conversation.id
                        scope.launch {
                            runCatching { ApiClient.chat.folderConversations(chatAuth(), folder.id, mapOf("conversations" to ids)).data }.getOrNull()?.let { updated ->
                                val index = host.folders.indexOfFirst { it.id == updated.id }
                                if (index >= 0) host.folders[index] = updated
                            }
                        }
                    }.padding(vertical = 12.dp, horizontal = 6.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Rounded.Folder, null, tint = Ch.Red, modifier = Modifier.size(22.dp))
                    Spacer(Modifier.width(12.dp))
                    Text(folder.name, color = Ch.Ink, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                    AnimatedContent(inside, label = "folderTick", transitionSpec = { scaleIn(spring(dampingRatio = 0.4f)) togetherWith scaleOut() }) { on ->
                        Icon(if (on) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (on) Ch.Red else Ch.Soft, modifier = Modifier.size(24.dp))
                    }
                }
            }
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).clickable { newFolder = true }.padding(vertical = 12.dp, horizontal = 6.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.CreateNewFolder, null, tint = Ch.Red, modifier = Modifier.size(22.dp))
                Spacer(Modifier.width(12.dp))
                Text(stringResource(R.string.ch_new_folder), color = Ch.Red, fontWeight = FontWeight.ExtraBold)
            }
        }
    }

    if (newFolder) TextInputSheet(stringResource(R.string.ch_new_folder), action = stringResource(R.string.ch_save), onDismiss = { newFolder = false }) { name ->
        host.scope.launch {
            try {
                val folder = ApiClient.chat.createFolder(chatAuth(), mapOf("name" to name)).data ?: return@launch
                val withChat = ApiClient.chat.folderConversations(chatAuth(), folder.id, mapOf("conversations" to listOf(conversation.id))).data ?: folder
                host.folders.add(withChat)
            } catch (e: Exception) {
                e.apiFailure().message?.let { host.showToast(it) }
            }
        }
    }
}

/** Group name, description and photo — for admins (or everyone, when the group allows it). */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun GroupEditSheet(conversation: ConversationDto, onDismiss: () -> Unit, onSaved: (ConversationDto) -> Unit) {
    val context = LocalContext.current
    val host = LocalChat.current
    var name by remember { mutableStateOf(conversation.group?.name.orEmpty()) }
    var description by remember { mutableStateOf(conversation.group?.description.orEmpty()) }
    var photo by remember { mutableStateOf<Uri?>(null) }
    var saving by remember { mutableStateOf(false) }
    val picker = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { photo = it ?: photo }

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().imePadding().padding(horizontal = 20.dp).padding(bottom = 26.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Text(stringResource(R.string.ch_edit_group), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(16.dp))
            Box(
                Modifier.size(100.dp).shadow(14.dp, CircleShape, spotColor = Ch.Red.copy(alpha = 0.4f)).clip(CircleShape).background(Ch.HeaderBrush)
                    .clickable { picker.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) },
                contentAlignment = Alignment.Center,
            ) {
                val model: Any? = photo ?: conversation.group?.avatar?.let { ApiClient.mediaUrl(it) }
                if (model != null) AsyncImage(model, null, imageLoader = chatImages(context), contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                Box(Modifier.fillMaxSize().background(Color.Black.copy(alpha = 0.25f)), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.AddAPhoto, null, tint = Color.White, modifier = Modifier.size(30.dp))
                }
            }
            Spacer(Modifier.height(16.dp))
            Field(name, stringResource(R.string.ch_group_name_hint), Icons.Rounded.Groups) { name = it.take(100) }
            Spacer(Modifier.height(10.dp))
            Field(description, stringResource(R.string.ch_group_desc_hint), Icons.Rounded.Notes, multiline = true) { description = it.take(2000) }
            Spacer(Modifier.height(16.dp))
            ChPrimaryButton(stringResource(R.string.ch_save), modifier = Modifier.fillMaxWidth(), enabled = name.isNotBlank() && !saving) {
                saving = true
                host.scope.launch {
                    try {
                        val fields = mapOf<String, RequestBody>(
                            "name" to name.trim().toRequestBody("text/plain".toMediaTypeOrNull()),
                            "description" to description.trim().toRequestBody("text/plain".toMediaTypeOrNull()),
                        )
                        val part = photo?.let { copyToCache(context, it, "avatar.jpg") }?.let { compressImage(context, it) }?.let { f ->
                            MultipartBody.Part.createFormData("avatar", f.name, f.file.asRequestBody(f.mime.toMediaTypeOrNull()))
                        }
                        ApiClient.chat.updateGroup(chatAuth(), conversation.id, fields, part).data?.let(onSaved)
                        onDismiss()
                    } catch (e: Exception) {
                        e.apiFailure().message?.let { host.showToast(it) }
                    }
                    saving = false
                }
            }
        }
    }
}

@Composable
private fun Field(value: String, hint: String, icon: ImageVector, multiline: Boolean = false, onChange: (String) -> Unit) {
    ChField(value, onChange, hint, icon = icon, singleLine = !multiline, fontSize = 15.sp)
}

/** Light / dark / same as the app. */
@Composable
fun ThemeSheet(onDismiss: () -> Unit) {
    val current = com.dorr.app.chat.ChatStore.themeMode
    ChoiceSheet(
        title = stringResource(R.string.ch_theme),
        options = listOf("system" to R.string.ch_theme_system, "light" to R.string.ch_theme_light, "dark" to R.string.ch_theme_dark).map { (mode, label) ->
            (if (mode == current) "✓  " else "") + stringResource(label) to { com.dorr.app.chat.ChatStore.setTheme(mode) }
        },
        onDismiss = onDismiss,
    )
}

