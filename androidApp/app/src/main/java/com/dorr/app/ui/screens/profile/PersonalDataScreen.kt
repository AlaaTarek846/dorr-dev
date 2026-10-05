package com.dorr.app.ui.screens.profile

import android.content.Context
import android.net.Uri
import android.widget.Toast
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.ContentTransform
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutHorizontally
import androidx.compose.animation.slideOutVertically
import androidx.compose.foundation.Canvas
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
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Email
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.Female
import androidx.compose.material.icons.rounded.KeyboardArrowDown
import androidx.compose.material.icons.rounded.Male
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Phone
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.TextRange
import androidx.compose.ui.text.input.TextFieldValue
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.ui.screens.AccountDark
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ApiEnvelope
import com.dorr.app.network.AuthSession
import com.dorr.app.network.ChannelOtpDto
import com.dorr.app.network.ConfirmCodeBody
import com.dorr.app.network.CountryCache
import com.dorr.app.network.CountryDto
import com.dorr.app.network.EmailChangeRequest
import com.dorr.app.network.FlagDto
import com.dorr.app.network.OtpRequest
import com.dorr.app.network.PhoneChangeCodeRequest
import com.dorr.app.network.UpdateIdentityBody
import com.dorr.app.network.UserCountryDto
import com.dorr.app.network.UserDto
import com.dorr.app.network.apiFailure
import com.dorr.app.network.resolveForPhone
import com.dorr.app.network.serverMessage
import com.dorr.app.ui.screens.wallet.PIN_ERROR_CODES
import com.dorr.app.ui.screens.wallet.PadResult
import com.dorr.app.ui.screens.wallet.WaPinPad
import com.dorr.app.ui.theme.AppColors
import java.io.File
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.asRequestBody

private enum class PdSub { NONE, NAME, PHONE, EMAIL }

private const val PD_GENDER = "gender"
private const val PD_PHOTO = "photo"
// Matches the server's auth_flow.otp_length (phone and e-mail change codes alike).
private const val PD_OTP_LENGTH = 4
private const val PD_OTP_MARKER = "​"
private val Pink = Color(0xFFFFEEE8)
private val FieldFill = Color(0xFFFBF7F8)
private val FieldBorder = Color(0xFFF3D5DB)
private val CardShadow = Color(0x12001B53)
private val VerifiedGreen = Color(0xFF16A34A)

private fun profilePrefs(context: Context, userId: Int) =
    context.getSharedPreferences("dorr_profile_$userId", Context.MODE_PRIVATE)

private fun loadGender(context: Context): String {
    val userId = AuthSession.user?.id ?: return ""
    return profilePrefs(context, userId).getString(PD_GENDER, "").orEmpty()
}

private fun saveGender(context: Context, gender: String) {
    val userId = AuthSession.user?.id ?: return
    profilePrefs(context, userId).edit().putString(PD_GENDER, gender).apply()
}

/**
 * The cached photo file is namespaced per account. An earlier build stored every
 * account in one shared `profile_photo.jpg`, so logging into a second account
 * left the first account's preference pointing at the second account's image.
 */
private fun photoFile(context: Context, userId: Int) =
    File(context.filesDir, "profile_photo_$userId.jpg")

private fun loadPhoto(context: Context, userId: Int): String? {
    // Drop the shared file left behind by the old build so it can never be
    // served again, and free the space it holds.
    runCatching { File(context.filesDir, "profile_photo.jpg").delete() }

    val file = photoFile(context, userId)
    if (!file.exists()) return null

    return runCatching {
        profilePrefs(context, userId)
            .getString(PD_PHOTO, null)
            ?.takeIf { it == file.absolutePath }
    }.getOrNull()
}

private fun saveProfilePhoto(context: Context, uri: Uri, userId: Int): String? = runCatching {
    val dest = photoFile(context, userId)
    context.contentResolver.openInputStream(uri)?.use { input ->
        dest.outputStream().use { output -> input.copyTo(output) }
    } ?: return null
    profilePrefs(context, userId)
        .edit()
        .putString(PD_PHOTO, dest.absolutePath)
        .apply()
    dest.absolutePath
}.getOrNull()

private fun deleteProfilePhoto(context: Context, userId: Int) {
    runCatching { photoFile(context, userId).delete() }
    runCatching { profilePrefs(context, userId).edit().remove(PD_PHOTO).apply() }
}

private data class FieldRow(
    val icon: ImageVector,
    val label: String,
    val value: String,
    val verified: Boolean,
    val ltr: Boolean,
    val sub: PdSub,
    val note: String?,
)

private fun formatDial(value: String): String {
    val digits = value.trim().removePrefix("+")
    return if (digits.isEmpty()) "" else "+$digits"
}

private fun pdAuthHeader(): String = "Bearer ${AuthSession.token.orEmpty()}"

@Composable
fun PersonalDataScreen(onBack: () -> Unit, onSaved: (String) -> Unit) {
    var sub by remember { mutableStateOf(PdSub.NONE) }
    var refresh by remember { mutableIntStateOf(0) }

    // Edit name / phone / email → back to the personal data list; on the list, ProfileScreen handles it.
    BackHandler(enabled = sub != PdSub.NONE) { sub = PdSub.NONE }

    AnimatedContent(
        targetState = sub,
        label = "pdSub",
        transitionSpec = {
            if (targetState == PdSub.NONE) {
                val enter = slideInHorizontally(tween(340, easing = FastOutSlowInEasing)) { -it / 5 } +
                    fadeIn(tween(280))
                val exit = slideOutHorizontally(tween(300, easing = FastOutSlowInEasing)) { it / 5 } +
                    fadeOut(tween(220))
                ContentTransform(enter, exit, sizeTransform = null)
            } else {
                val enter = slideInHorizontally(tween(340, easing = FastOutSlowInEasing)) { it / 5 } +
                    fadeIn(tween(280))
                val exit = slideOutHorizontally(tween(300, easing = FastOutSlowInEasing)) { -it / 5 } +
                    fadeOut(tween(220))
                ContentTransform(enter, exit, sizeTransform = null)
            }
        },
    ) { currentSub ->
        when (currentSub) {
            PdSub.NAME -> EditNameScreen(onBack = { sub = PdSub.NONE }, onSaved = { onSaved(it); refresh++ })
            PdSub.PHONE -> EditPhoneScreen(onBack = { sub = PdSub.NONE }, onSaved = { onSaved(it); refresh++ })
            PdSub.EMAIL -> EditEmailScreen(onBack = { sub = PdSub.NONE }, onSaved = { onSaved(it); refresh++ })
            PdSub.NONE -> PdHub(onBack = onBack, onOpen = { sub = it })
        }
    }
}

@Composable
private fun PdHub(onBack: () -> Unit, onOpen: (PdSub) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val notAdded = stringResource(R.string.pd_not_added)
    val genericError = stringResource(R.string.wallet_error_generic)
    val avatarSaved = stringResource(R.string.toast_profile_updated)
    var uploadingAvatar by remember { mutableStateOf(false) }
    // Keyed on the session so a logout/login switch re-reads AuthSession.user
    // instead of keeping the previous account captured in remember().
    val sessionVersion by AuthSession.sessionVersion.collectAsState()
    val user = remember(sessionVersion) { AuthSession.user }
    val emailVerified = user?.emailVerifiedAt != null
    var photoPath by remember(sessionVersion, user?.id) {
        mutableStateOf(user?.id?.let { loadPhoto(context, it) })
    }

    fun uploadAvatar(file: File) {
        scope.launch {
            uploadingAvatar = true
            runCatching {
                val body = file.asRequestBody("image/*".toMediaType())
                ApiClient.profile.updateAvatar(
                    pdAuthHeader(),
                    MultipartBody.Part.createFormData("avatar", file.name, body),
                )
            }.onSuccess { envelope ->
                envelope.data?.let { AuthSession.user = it }
                Toast.makeText(
                    context,
                    envelope.message.ifBlank { avatarSaved },
                    Toast.LENGTH_SHORT,
                ).show()
            }.onFailure {
                Toast.makeText(
                    context,
                    it.serverMessage() ?: genericError,
                    Toast.LENGTH_SHORT,
                ).show()
            }
            uploadingAvatar = false
        }
    }

    var deletingAvatar by remember { mutableStateOf(false) }
    var confirmDelete by remember { mutableStateOf(false) }

    fun deleteAvatar() {
        scope.launch {
            deletingAvatar = true
            runCatching {
                ApiClient.profile.deleteAvatar(pdAuthHeader())
            }.onSuccess { envelope ->
                envelope.data?.let { AuthSession.user = it }
                user?.id?.let { deleteProfilePhoto(context, it) }
                photoPath = null
                Toast.makeText(
                    context,
                    envelope.message.ifBlank { avatarSaved },
                    Toast.LENGTH_SHORT,
                ).show()
            }.onFailure {
                Toast.makeText(
                    context,
                    it.serverMessage() ?: genericError,
                    Toast.LENGTH_SHORT,
                ).show()
            }
            deletingAvatar = false
        }
    }

    val photoPicker = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri ->
        if (uri == null) return@rememberLauncherForActivityResult
        val userId = user?.id ?: return@rememberLauncherForActivityResult
        scope.launch {
            val saved = withContext(Dispatchers.IO) { saveProfilePhoto(context, uri, userId) }
            if (saved != null) {
                photoPath = saved
                uploadAvatar(File(saved))
            }
        }
    }

    if (confirmDelete) {
        // Same confirmation card as deleting an address.
        DeleteAddressDialog(
            title = stringResource(R.string.pd_delete_photo_ask),
            name = stringResource(R.string.pd_delete_photo_confirm),
            onDismiss = { confirmDelete = false },
            onConfirm = { confirmDelete = false; deleteAvatar() },
        )
    }

    PdScreen(title = stringResource(R.string.personal_data_title), onBack = onBack) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 14.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            // Avatar — slides in from the top
            AnimatedVisibility(
                visible = true,
                modifier = Modifier.fillMaxWidth(),
                enter = slideInVertically(tween(400, delayMillis = 60, easing = FastOutSlowInEasing)) { -it / 3 } +
                    fadeIn(tween(360, delayMillis = 60)),
            ) {
                Box(
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(top = 6.dp, bottom = 14.dp),
                    contentAlignment = Alignment.Center,
                ) {
                    Box(
                        modifier = Modifier
                            .size(92.dp)
                            .clickable {
                                photoPicker.launch(
                                    PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly),
                                )
                            },
                    ) {
                        Box(
                            modifier = Modifier
                                .fillMaxSize()
                                .then(if (settingsNight()) Modifier else Modifier.shadow(8.dp, CircleShape, ambientColor = Color(0x1F001B53), spotColor = Color(0x1F001B53)))
                                .clip(CircleShape)
                                .background(com.dorr.app.ui.screens.profile.settingsCard()),
                            contentAlignment = Alignment.Center,
                        ) {
                            // Server avatar first (rewritten to a host the device
                            // can reach), then the locally cached photo, then
                            // the placeholder icon. If the server image fails
                            // to load, fall back to the local photo instead of
                            // a blank circle.
                            //
                            // The server stores the avatar under a fixed name
                            // (…/{userId}/avatar.jpg), so a re-upload keeps the
                            // same URL. updated_at is appended as a cache key so
                            // Coil doesn't keep serving the previous image.
                            val serverAvatar = ApiClient.mediaUrl(user?.avatar)
                                ?.plus("?v=" + (user?.updatedAt ?: "0"))
                            var serverAvatarFailed by remember(serverAvatar) { mutableStateOf(false) }
                            val avatarModel = (if (serverAvatarFailed) null else serverAvatar)
                                ?: photoPath?.let(::File)
                            if (avatarModel != null) {
                                AsyncImage(
                                    model = avatarModel,
                                    contentDescription = stringResource(R.string.pd_change_photo),
                                    contentScale = ContentScale.Crop,
                                    onError = { serverAvatarFailed = true },
                                    modifier = Modifier.fillMaxSize(),
                                )
                            } else {
                                Icon(Icons.Rounded.Person, contentDescription = stringResource(R.string.pd_change_photo), tint = settingsAccent(), modifier = Modifier.size(44.dp))
                            }
                            if (uploadingAvatar || deletingAvatar) {
                                Box(
                                    modifier = Modifier
                                        .fillMaxSize()
                                        .clip(CircleShape)
                                        .background(Color.Black.copy(alpha = 0.35f)),
                                    contentAlignment = Alignment.Center,
                                ) {
                                    CircularProgressIndicator(
                                        color = Color.White,
                                        strokeWidth = 2.dp,
                                        modifier = Modifier.size(28.dp),
                                    )
                                }
                            }
                        }
                        Box(
                            modifier = Modifier
                                .align(Alignment.BottomEnd)
                                .size(28.dp)
                                .shadow(4.dp, CircleShape, ambientColor = Color(0x40001B53), spotColor = Color(0x40001B53))
                                .clip(CircleShape)
                                .background(settingsAccent()),
                            contentAlignment = Alignment.Center,
                        ) {
                            Icon(Icons.Rounded.Edit, contentDescription = null, tint = Color.White, modifier = Modifier.size(14.dp))
                        }
                        // Only when there is a photo to remove (server avatar or the local copy).
                        if ((user?.avatar != null || photoPath != null) && !uploadingAvatar && !deletingAvatar) {
                            Box(
                                modifier = Modifier
                                    .align(Alignment.BottomStart)
                                    .size(28.dp)
                                    .shadow(4.dp, CircleShape)
                                    .clip(CircleShape)
                                    .background(settingsCard())
                                    .clickable { confirmDelete = true },
                                contentAlignment = Alignment.Center,
                            ) {
                                Icon(
                                    Icons.Rounded.Delete,
                                    contentDescription = stringResource(R.string.pd_delete_photo),
                                    tint = settingsAccent(),
                                    modifier = Modifier.size(16.dp),
                                )
                            }
                        }
                    }
                }
            }
            // Staggered field rows
            val fields = listOf(
                FieldRow(Icons.Rounded.Person, stringResource(R.string.pd_name),
                    user?.name?.takeIf { it.isNotBlank() } ?: notAdded, false, false, PdSub.NAME,
                    stringResource(R.string.pd_name_more)),
                FieldRow(Icons.Rounded.Phone, stringResource(R.string.pd_phone),
                    user?.phone?.takeIf { it.isNotBlank() } ?: notAdded, user?.phoneVerifiedAt != null, true, PdSub.PHONE, null),
                FieldRow(Icons.Rounded.Email, stringResource(R.string.pd_email),
                    user?.email?.takeIf { it.isNotBlank() } ?: notAdded, emailVerified, true, PdSub.EMAIL, null),
            )
            fields.forEachIndexed { index, field ->
                AnimatedVisibility(
                    visible = true,
                    enter = slideInVertically(
                        tween(380, delayMillis = 120 + index * 60, easing = FastOutSlowInEasing)
                    ) { it / 3 } + fadeIn(tween(320, delayMillis = 120 + index * 60)),
                ) {
                    PdFieldRow(
                        icon = field.icon,
                        label = field.label,
                        value = field.value,
                        verified = field.verified,
                        ltr = field.ltr,
                        note = field.note,
                        onClick = { onOpen(field.sub) },
                    )
                }
            }
            Spacer(Modifier.height(12.dp))
        }
    }
}

@Composable
private fun PdFieldRow(
    icon: ImageVector,
    label: String,
    value: String,
    verified: Boolean,
    ltr: Boolean = false,
    note: String? = null,
    onClick: () -> Unit,
) {
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .settingsSurface(RoundedCornerShape(14.dp))
            .clickable(onClick = onClick)
            .padding(horizontal = 14.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            modifier = Modifier
                .size(34.dp)
                .clip(RoundedCornerShape(10.dp))
                .background(if (settingsNight()) AccountDark.well else settingsAccent().copy(alpha = 0.14f)),
            contentAlignment = Alignment.Center,
        ) {
            Icon(icon, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
        }
        Spacer(Modifier.width(10.dp))
        Column(modifier = Modifier.weight(1f)) {
            Text(label, fontSize = 12.sp, fontWeight = FontWeight.SemiBold, color = settingsMut())
            Row(verticalAlignment = Alignment.CenterVertically) {
                val valueText: @Composable () -> Unit = {
                    Text(
                        value,
                        fontSize = 14.sp,
                        fontWeight = FontWeight.Bold,
                        color = settingsInk(),
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                }
                if (ltr) {
                    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) { valueText() }
                } else {
                    valueText()
                }
                if (verified) {
                    Spacer(Modifier.width(6.dp))
                    Box(
                        modifier = Modifier
                            .size(16.dp)
                            .clip(CircleShape)
                            .background(VerifiedGreen),
                        contentAlignment = Alignment.Center,
                    ) {
                        Icon(Icons.Rounded.Check, contentDescription = null, tint = Color.White, modifier = Modifier.size(10.dp))
                    }
                }
            }
            if (!note.isNullOrBlank()) {
                Text(
                    note,
                    fontSize = 11.sp,
                    color = settingsMut(),
                    modifier = Modifier.padding(top = 2.dp),
                )
            }
        }
        Icon(
            Icons.Rounded.ChevronRight,
            contentDescription = null,
            tint = if (settingsNight()) AccountDark.chevron else Color(0xFFF8BCA9),
            modifier = Modifier
                .size(16.dp)
                .graphicsLayer { if (rtl) scaleX = -1f },
        )
    }
}

@Composable
private fun EditNameScreen(onBack: () -> Unit, onSaved: (String) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var name by remember { mutableStateOf(AuthSession.user?.name.orEmpty()) }
    // Server value first, locally picked gender as fallback; the API requires
    // one of male/female, so an empty choice blocks the save below.
    var gender by remember {
        mutableStateOf(
            AuthSession.user?.gender?.takeIf { it == "male" || it == "female" }
                ?: loadGender(context),
        )
    }
    var saving by remember { mutableStateOf(false) }
    val enterName = stringResource(R.string.toast_enter_name)
    val pickGender = stringResource(R.string.toast_pick_gender)
    val updated = stringResource(R.string.toast_profile_updated)
    val genericError = stringResource(R.string.wallet_error_generic)

    PdScreen(title = stringResource(R.string.edit_name_title), onBack = onBack) {
        FormColumn {
            PdFormCard {
                IconField(
                    value = name,
                    onValueChange = { name = it },
                    label = stringResource(R.string.edit_name_label),
                    icon = Icons.Rounded.Person,
                )
                Hint(stringResource(R.string.edit_name_hint))
                Spacer(Modifier.height(14.dp))
                Text(
                    stringResource(R.string.pd_gender),
                    fontSize = 13.sp,
                    fontWeight = FontWeight.Bold,
                    color = settingsInk(),
                )
                Spacer(Modifier.height(8.dp))
                GenderOption(stringResource(R.string.gender_male), Icons.Rounded.Male, gender == "male") { gender = "male" }
                Spacer(Modifier.height(8.dp))
                GenderOption(stringResource(R.string.gender_female), Icons.Rounded.Female, gender == "female") { gender = "female" }
            }
            PdSaveButton(label = stringResource(R.string.common_save), enabled = !saving) {
                val cleanName = name.trim()
                if (cleanName.length < 2) {
                    Toast.makeText(context, enterName, Toast.LENGTH_SHORT).show()
                    return@PdSaveButton
                }
                if (gender != "male" && gender != "female") {
                    Toast.makeText(context, pickGender, Toast.LENGTH_SHORT).show()
                    return@PdSaveButton
                }
                scope.launch {
                    saving = true
                    runCatching {
                        ApiClient.profile.updateIdentity(
                            pdAuthHeader(),
                            UpdateIdentityBody(name = cleanName, gender = gender),
                        )
                    }.onSuccess { envelope ->
                        envelope.data?.let { AuthSession.user = it }
                        if (gender == "male" || gender == "female") saveGender(context, gender)
                        onSaved(envelope.message.ifBlank { updated })
                        onBack()
                    }.onFailure {
                        Toast.makeText(
                            context,
                            it.serverMessage() ?: genericError,
                            Toast.LENGTH_SHORT,
                        ).show()
                    }
                    saving = false
                }
            }
        }
    }
}

@Composable
private fun GenderOption(label: String, icon: ImageVector, selected: Boolean, onClick: () -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(14.dp))
            .background(if (selected) (if (settingsNight()) AccountDark.well else settingsAccent().copy(alpha = 0.14f)) else if (settingsNight()) AccountDark.bg else FieldFill)
            .border(1.5.dp, if (selected) settingsAccent() else Color.Transparent, RoundedCornerShape(14.dp))
            .clickable(onClick = onClick)
            .padding(horizontal = 16.dp, vertical = 14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, contentDescription = null, tint = if (selected) settingsAccent() else settingsMut(), modifier = Modifier.size(22.dp))
        Spacer(Modifier.width(10.dp))
        Text(
            label,
            fontSize = 15.sp,
            fontWeight = FontWeight.Bold,
            color = if (selected) settingsAccent() else settingsInk(),
            modifier = Modifier.weight(1f),
        )
        Box(
            modifier = Modifier
                .size(18.dp)
                .border(2.dp, if (selected) settingsAccent() else if (settingsNight()) AccountDark.chevron else Color(0xFFF8BCA9), CircleShape),
            contentAlignment = Alignment.Center,
        ) {
            if (selected) {
                Box(
                    Modifier
                        .size(10.dp)
                        .clip(CircleShape)
                        .background(settingsAccent()),
                )
            }
        }
    }
}

/**
 * Changing the phone number goes through the real, guarded flow (PhoneChangeService on the server):
 * a code to the *new* number, behind the wallet PIN once one exists. "form" → ("pin" only if the
 * account has a PIN) → "otp" → done. Every other profile field here is still a local-only mock; this
 * one talks to the server because it is also the wallet's login identity and transfer address.
 */
@Composable
private fun EditPhoneScreen(onBack: () -> Unit, onSaved: (String) -> Unit) {
    var dial by remember { mutableStateOf("+966") }
    var flag by remember { mutableStateOf("sa") }
    var phone by remember { mutableStateOf("") }
    var phoneLength by remember { mutableIntStateOf(9) }
    var phoneStartsWith by remember { mutableStateOf("5") }
    var step by remember { mutableStateOf("form") }
    var serverError by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    // The PIN that let the change start — resending the code has to pass it again.
    var confirmedPin by remember { mutableStateOf<String?>(null) }
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val updated = stringResource(R.string.toast_phone_updated)
    val genericError = stringResource(R.string.wa_error_generic)
    val networkError = stringResource(R.string.wa_error_network)

    // The countries dropdown is fetched once in LoginScreen and cached —
    // AuthSession.user.country (from auth/me) is the primary source for
    // the user's country; CountryCache resolves by phone number as fallback.
    LaunchedEffect(Unit) {
        CountryCache.load(context) // ensure cache is restored from disk
        val uc = AuthSession.user?.country
        if (uc != null) {
            dial = formatDial(uc.dialCode ?: "").ifBlank { dial }
            val candidate = uc.flag?.code?.lowercase()?.takeIf { it.length == 2 }
                ?: uc.code?.lowercase()?.take(2)
            flag = candidate?.takeIf { it.length == 2 } ?: flag
            phoneLength = uc.phoneLength ?: phoneLength
            phoneStartsWith = uc.phoneStartsWith.orEmpty()
        } else {
            val picked = CountryCache.resolveForPhone(context, AuthSession.user?.phone)
                ?: CountryCache.pickDefault(context)
            if (picked != null) {
                dial = formatDial(picked.dialCode).ifBlank { dial }
                flag = picked.flag?.code?.lowercase()?.takeIf { it.length == 2 }
                    ?: picked.code.lowercase().take(2) ?: flag
                phoneLength = picked.phoneLength ?: phoneLength
                phoneStartsWith = picked.phoneStartsWith.orEmpty()
            }
        }
    }

    val isPhoneValid = phone.length == phoneLength && (phoneStartsWith.isEmpty() || phone.startsWith(phoneStartsWith))

    val phoneError: String? = when {
        phone.isEmpty() -> null
        phone.length != phoneLength ->
            stringResource(R.string.login_error_phone_invalid_length, phoneLength)
        phoneStartsWith.isNotEmpty() && !phone.startsWith(phoneStartsWith) ->
            stringResource(R.string.login_error_phone_invalid_start, phoneStartsWith)
        else -> null
    }

    // The server decides whether a PIN is needed (only once the wallet has one).
    fun start() {
        scope.launch {
            busy = true
            serverError = null
            try {
                ApiClient.mobileAuth.startPhoneChange(pdAuthHeader(), OtpRequest(dial, phone), null)
                step = "otp"
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                val failure = e.apiFailure()
                if (failure.errorCode == "wallet_pin_required") {
                    step = "pin"
                } else {
                    step = "form"
                    serverError = if (failure.httpStatus == null) networkError else failure.message ?: genericError
                }
            } finally {
                busy = false
            }
        }
    }

    when (step) {
        "pin" -> PdScreen(title = stringResource(R.string.edit_phone_title), onBack = { step = "form" }) {
            WaPinPad(
                title = stringResource(R.string.wa_pin_enter_title),
                sub = stringResource(R.string.edit_phone_title),
                dark = settingsNight(),
                modifier = Modifier.fillMaxSize(),
                onComplete = { pin ->
                    try {
                        ApiClient.mobileAuth.startPhoneChange(pdAuthHeader(), OtpRequest(dial, phone), pin)
                        confirmedPin = pin
                        step = "otp"
                        PadResult.Ok
                    } catch (e: CancellationException) {
                        throw e
                    } catch (e: Exception) {
                        val failure = e.apiFailure()
                        val lockedUntil = failure.lockedUntil?.let { runCatching { java.time.Instant.parse(it).toEpochMilli() }.getOrNull() }
                        when {
                            failure.errorCode == "wallet_pin_locked" && lockedUntil != null -> PadResult.Locked(lockedUntil)
                            failure.errorCode in PIN_ERROR_CODES -> PadResult.Error(failure.message ?: genericError)
                            else -> {
                                step = "form"
                                serverError = if (failure.httpStatus == null) networkError else failure.message ?: genericError
                                PadResult.Ok
                            }
                        }
                    }
                },
            )
        }
        "otp" -> PdOtpStep(
            title = stringResource(R.string.edit_phone_title),
            target = "$dial $phone",
            initialCooldown = 60,
            onVerify = { code ->
                ApiClient.mobileAuth.confirmPhoneChange(pdAuthHeader(), PhoneChangeCodeRequest(code))
                ApiClient.mobileAuth.me(pdAuthHeader())
            },
            onResend = {
                ApiClient.mobileAuth.startPhoneChange(pdAuthHeader(), OtpRequest(dial, phone), confirmedPin)
                null
            },
            onVerified = { user, _ ->
                AuthSession.user = user
                onSaved(updated)
                onBack()
            },
            onBack = { step = "form" },
        )
        else -> PdScreen(title = stringResource(R.string.edit_phone_title), onBack = onBack) {
            FormColumn {
                PdFormCard {
                    Text(
                        stringResource(R.string.edit_phone_label),
                        fontSize = 13.sp,
                        fontWeight = FontWeight.Bold,
                        color = settingsInk(),
                        modifier = Modifier.padding(bottom = 8.dp),
                    )
                    ProfilePhoneField(
                        flag = flag,
                        dial = dial,
                        phone = phone,
                        phoneLength = phoneLength,
                        phoneStartsWith = phoneStartsWith,
                        isError = phoneError != null || serverError != null,
                        onPhone = { phone = it.filter(Char::isDigit).take(phoneLength); serverError = null },
                    )
                    AnimatedVisibility(
                        visible = phoneError != null || serverError != null,
                        enter = fadeIn() + expandVertically(),
                        exit = fadeOut() + shrinkVertically(),
                    ) {
                        (phoneError ?: serverError)?.let { message ->
                            Row(
                                modifier = Modifier
                                    .fillMaxWidth()
                                    .padding(top = 8.dp)
                                    .clip(RoundedCornerShape(10.dp))
                                    .background(Color(0xFFFEE2E2).copy(alpha = 0.85f))
                                    .padding(horizontal = 12.dp, vertical = 7.dp),
                                verticalAlignment = Alignment.CenterVertically,
                                horizontalArrangement = Arrangement.Center,
                            ) {
                                Icon(
                                    Icons.Rounded.ErrorOutline,
                                    contentDescription = null,
                                    tint = AppColors.danger,
                                    modifier = Modifier.size(16.dp),
                                )
                                Spacer(Modifier.width(6.dp))
                                Text(
                                    text = message,
                                    color = AppColors.danger,
                                    fontSize = 12.sp,
                                    fontWeight = FontWeight.Medium,
                                    textAlign = TextAlign.Center,
                                )
                            }
                        }
                    }
                    Hint(stringResource(R.string.edit_phone_hint))
                }
                PdSaveButton(
                    label = stringResource(R.string.pd_send_code),
                    enabled = isPhoneValid && !busy,
                ) {
                    if (isPhoneValid && !busy) start()
                }
            }
        }
    }
}

@Composable
private fun EditEmailScreen(onBack: () -> Unit, onSaved: (String) -> Unit) {
    var email by remember { mutableStateOf("") }
    var stepOtp by remember { mutableStateOf(false) }
    var requesting by remember { mutableStateOf(false) }
    var maskedEmail by remember { mutableStateOf<String?>(null) }
    var cooldownSeconds by remember { mutableIntStateOf(60) }
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val invalid = stringResource(R.string.toast_valid_email)
    val updated = stringResource(R.string.toast_email_updated)
    val genericError = stringResource(R.string.wallet_error_generic)

    suspend fun requestCode(): ChannelOtpDto? {
        val envelope = ApiClient.profile.requestEmailChange(
            pdAuthHeader(),
            EmailChangeRequest(email = email.trim()),
        )
        return envelope.data
    }

    if (stepOtp) {
        PdOtpStep(
            title = stringResource(R.string.edit_email_title),
            target = maskedEmail ?: email.trim(),
            initialCooldown = cooldownSeconds,
            onVerify = { code ->
                ApiClient.profile.confirmEmailChange(pdAuthHeader(), ConfirmCodeBody(code))
            },
            onResend = { requestCode() },
            onVerified = { user, message ->
                AuthSession.user = user
                onSaved(message.ifBlank { updated })
                onBack()
            },
            onBack = { stepOtp = false },
        )
        return
    }
    PdScreen(title = stringResource(R.string.edit_email_title), onBack = onBack) {
        FormColumn {
            PdFormCard {
                IconField(
                    value = email,
                    onValueChange = { email = it },
                    label = stringResource(R.string.edit_email_label),
                    icon = Icons.Rounded.Email,
                    placeholder = "name@example.com",
                    ltr = true,
                    keyboardType = KeyboardType.Email,
                )
                Hint(stringResource(R.string.edit_email_hint))
            }
            PdSaveButton(label = stringResource(R.string.pd_send_code), enabled = !requesting) {
                val value = email.trim()
                if (!value.contains("@") || !value.contains(".")) {
                    Toast.makeText(context, invalid, Toast.LENGTH_SHORT).show()
                    return@PdSaveButton
                }
                if (requesting) return@PdSaveButton
                scope.launch {
                    requesting = true
                    runCatching { requestCode() }
                        .onSuccess { dto ->
                            maskedEmail = dto?.maskedEmail ?: value
                            cooldownSeconds = dto?.resendCooldownSeconds ?: 60
                            stepOtp = true
                        }
                        .onFailure {
                            Toast.makeText(
                                context,
                                it.serverMessage() ?: genericError,
                                Toast.LENGTH_SHORT,
                            ).show()
                        }
                    requesting = false
                }
            }
        }
    }
}

/**
 * The shared OTP step for phone and email changes. Both go through the same
 * shape — `onVerify(code)` confirms, `onResend()` requests a fresh code and
 * reports the new cooldown — so the screen owns no gateway knowledge at all.
 * Failures surface the server's own message; the raw value never leaves here.
 */
@Composable
private fun PdOtpStep(
    title: String,
    target: String,
    initialCooldown: Int,
    onVerify: suspend (String) -> ApiEnvelope<UserDto>,
    onResend: suspend () -> ChannelOtpDto?,
    onVerified: (UserDto, String) -> Unit,
    onBack: () -> Unit,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var digits by remember { mutableStateOf(List(PD_OTP_LENGTH) { "" }) }
    var countdown by remember { mutableIntStateOf(initialCooldown.coerceAtLeast(0)) }
    var hasError by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    val wrongCode = stringResource(R.string.toast_wrong_code)
    val codeSent = stringResource(R.string.toast_code_sent)
    val genericError = stringResource(R.string.wallet_error_generic)
    val focusers = remember { List(PD_OTP_LENGTH) { FocusRequester() } }

    LaunchedEffect(countdown) {
        if (countdown > 0) {
            delay(1000)
            countdown--
        }
    }

    fun verify(code: String) {
        if (code.length != PD_OTP_LENGTH || busy) return
        scope.launch {
            busy = true
            runCatching { onVerify(code) }
                .onSuccess { envelope ->
                    val user = envelope.data
                    if (user != null) {
                        onVerified(user, envelope.message)
                    } else {
                        hasError = true
                        Toast.makeText(
                            context,
                            envelope.message.ifBlank { wrongCode },
                            Toast.LENGTH_SHORT,
                        ).show()
                    }
                }
                .onFailure {
                    if (it is CancellationException) throw it
                    hasError = true
                    digits = List(PD_OTP_LENGTH) { "" }
                    focusers[0].requestFocus()
                    Toast.makeText(
                        context,
                        it.serverMessage() ?: wrongCode,
                        Toast.LENGTH_SHORT,
                    ).show()
                }
            busy = false
        }
    }

    fun resend() {
        if (busy) return
        scope.launch {
            busy = true
            runCatching { onResend() }
                .onSuccess { dto ->
                    digits = List(PD_OTP_LENGTH) { "" }
                    hasError = false
                    countdown = dto?.resendCooldownSeconds ?: 60
                    Toast.makeText(context, codeSent, Toast.LENGTH_SHORT).show()
                    focusers[0].requestFocus()
                }
                .onFailure {
                    Toast.makeText(
                        context,
                        it.serverMessage() ?: genericError,
                        Toast.LENGTH_SHORT,
                    ).show()
                }
            busy = false
        }
    }

    PdScreen(title = title, onBack = onBack) {
        FormColumn {
            PdFormCard {
                Text(
                    stringResource(R.string.profile_otp_hint, target),
                    fontSize = 12.sp,
                    lineHeight = 19.sp,
                    color = if (settingsNight()) settingsMut() else AppColors.textSecondary,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth(),
                )
                Spacer(Modifier.height(14.dp))
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.spacedBy(8.dp, Alignment.CenterHorizontally),
                    ) {
                        digits.forEachIndexed { index, digit ->
                            OtpDigit(
                                value = digit,
                                hasError = hasError,
                                focusRequester = focusers[index],
                                onValue = { d ->
                                    digits = digits.toMutableList().also { it[index] = d }
                                    hasError = false
                                    if (d.isNotEmpty()) {
                                        if (index < digits.lastIndex) focusers[index + 1].requestFocus()
                                        val code = digits.toMutableList().also { it[index] = d }.joinToString("")
                                        if (code.length == PD_OTP_LENGTH) verify(code)
                                    }
                                },
                                onBackspaceOnEmpty = {
                                    if (index > 0) {
                                        focusers[index - 1].requestFocus()
                                        digits = digits.toMutableList().also { it[index - 1] = "" }
                                    }
                                },
                            )
                        }
                    }
                }
                Spacer(Modifier.height(14.dp))
                if (countdown > 0 || busy) {
                    Text(
                        stringResource(R.string.profile_otp_timer, countdown.coerceAtLeast(0)),
                        fontSize = 13.sp,
                        color = if (settingsNight()) settingsMut() else AppColors.textSecondary,
                        textAlign = TextAlign.Center,
                        modifier = Modifier.fillMaxWidth(),
                    )
                } else {
                    Text(
                        stringResource(R.string.profile_otp_resend),
                        fontSize = 13.sp,
                        fontWeight = FontWeight.Bold,
                        color = settingsAccent(),
                        textAlign = TextAlign.Center,
                        modifier = Modifier
                            .fillMaxWidth()
                            .clickable { resend() },
                    )
                }
            }
            val code = digits.joinToString("")
            PdSaveButton(
                label = stringResource(R.string.profile_otp_confirm),
                enabled = code.length == PD_OTP_LENGTH && !busy,
            ) { verify(code) }
        }
    }
    LaunchedEffect(Unit) { focusers[0].requestFocus() }
}

@Composable
private fun OtpDigit(
    value: String,
    hasError: Boolean,
    focusRequester: FocusRequester,
    onValue: (String) -> Unit,
    onBackspaceOnEmpty: () -> Unit,
) {
    var focused by remember { mutableStateOf(false) }
    val border = when {
        hasError -> AppColors.danger
        focused -> settingsAccent()
        else -> Color(0xFFFBD2C4)
    }
    Box(
        modifier = Modifier
            .width(40.dp)
            .height(48.dp)
            .clip(RoundedCornerShape(14.dp))
            .background(com.dorr.app.ui.screens.profile.settingsCard())
            .border(1.5.dp, border, RoundedCornerShape(14.dp)),
        contentAlignment = Alignment.Center,
    ) {
        // A soft keyboard sends no key event for Backspace on an empty field, so the box always holds
        // an invisible marker: deleting it is how an "empty" box learns Backspace was pressed.
        val shown = PD_OTP_MARKER + value
        BasicTextField(
            value = TextFieldValue(shown, TextRange(shown.length)),
            onValueChange = { typed ->
                if (!typed.text.startsWith(PD_OTP_MARKER)) {
                    if (value.isEmpty()) onBackspaceOnEmpty() else onValue("")
                } else {
                    onValue(typed.text.filter(Char::isDigit).takeLast(1))
                }
            },
            singleLine = true,
            textStyle = TextStyle(
                fontSize = 20.sp,
                fontWeight = FontWeight.SemiBold,
                color = settingsInk(),
                textAlign = TextAlign.Center,
            ),
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
            cursorBrush = SolidColor(settingsAccent()),
            modifier = Modifier
                .fillMaxWidth()
                .focusRequester(focusRequester)
                .onFocusChanged { focused = it.isFocused },
        )
    }
}

@Composable
private fun ProfilePhoneField(
    flag: String,
    dial: String,
    phone: String,
    phoneLength: Int,
    phoneStartsWith: String = "",
    isError: Boolean = false,
    onPhone: (String) -> Unit,
) {
    var focused by remember { mutableStateOf(false) }
    val borderColor = when {
        isError -> AppColors.danger
        focused -> settingsAccent()
        else -> FieldBorder
    }
    val placeholder = if (phoneStartsWith.isNotEmpty()) {
        phoneStartsWith + "*".repeat(
            if (phoneLength > phoneStartsWith.length) phoneLength - phoneStartsWith.length else 0,
        )
    } else {
        "0".repeat(phoneLength.coerceAtLeast(1))
    }
    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .height(44.dp)
                .clip(RoundedCornerShape(999.dp))
                .background(if (settingsNight()) AccountDark.bg else if (focused) Color.White else FieldFill)
                .border(1.dp, borderColor, RoundedCornerShape(999.dp))
                .padding(horizontal = 14.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.Phone, contentDescription = null, tint = Color(0xFF9CA3AF), modifier = Modifier.size(18.dp))
            PhoneSep()
            Row(
                verticalAlignment = Alignment.CenterVertically,
            ) {
                PdFlag(flag)
                Spacer(Modifier.width(6.dp))
                Text(dial, fontSize = 14.sp, fontWeight = FontWeight.Bold, color = settingsInk())
            }
            PhoneSep()
            BasicTextField(
                value = phone,
                onValueChange = onPhone,
                singleLine = true,
                textStyle = TextStyle(fontSize = 14.sp, fontWeight = FontWeight.Medium, color = settingsInk(), letterSpacing = 0.5.sp),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
                cursorBrush = SolidColor(settingsAccent()),
                modifier = Modifier
                    .weight(1f)
                    .onFocusChanged { focused = it.isFocused },
                decorationBox = { inner ->
                    Box(contentAlignment = Alignment.CenterStart) {
                        if (phone.isEmpty()) {
                            Text(placeholder, color = Color(0xFFC5CAD3), fontSize = 14.sp, fontWeight = FontWeight.Medium, letterSpacing = 0.5.sp)
                        }
                        inner()
                    }
                },
            )
        }
    }
}

@Composable
private fun PdFlag(code: String?) {
    val iso = code?.trim()?.lowercase().orEmpty()
    if (iso.length != 2) return
    AsyncImage(
        model = "https://flagcdn.com/w40/$iso.png",
        contentDescription = null,
        contentScale = ContentScale.Crop,
        modifier = Modifier
            .size(width = 20.dp, height = 15.dp)
            .clip(RoundedCornerShape(2.dp)),
    )
}

@Composable
private fun PhoneSep() {
    Box(
        Modifier
            .padding(horizontal = 8.dp)
            .width(1.dp)
            .height(18.dp)
            .background(if (settingsNight()) AccountDark.line else Color(0xFFE5E7EB)),
    )
}

@Composable
private fun FormColumn(content: @Composable () -> Unit) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(horizontal = 14.dp)
            .padding(top = 6.dp, bottom = 16.dp),
    ) {
        content()
    }
}

@Composable
private fun PdFormCard(content: @Composable () -> Unit) {
    AnimatedVisibility(
        visible = true,
        enter = slideInVertically(tween(400, delayMillis = 80, easing = FastOutSlowInEasing)) { it / 4 } +
            fadeIn(tween(340, delayMillis = 80)),
    ) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .settingsSurface(RoundedCornerShape(18.dp), 8.dp)
                .padding(16.dp),
        ) {
            content()
        }
    }
}

@Composable
private fun IconField(
    value: String,
    onValueChange: (String) -> Unit,
    label: String,
    icon: ImageVector,
    placeholder: String = "",
    ltr: Boolean = false,
    keyboardType: KeyboardType = KeyboardType.Text,
) {
    var focused by remember { mutableStateOf(false) }
    Text(label, fontSize = 13.sp, fontWeight = FontWeight.Bold, color = settingsInk(), modifier = Modifier.padding(bottom = 8.dp))
    val field = @Composable {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .height(44.dp)
                .clip(RoundedCornerShape(999.dp))
                .background(if (settingsNight()) AccountDark.bg else if (focused) Color.White else FieldFill)
                .border(1.dp, if (focused) settingsAccent() else if (settingsNight()) AccountDark.line else FieldBorder, RoundedCornerShape(999.dp))
                .padding(horizontal = 14.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(icon, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(8.dp))
            BasicTextField(
                value = value,
                onValueChange = onValueChange,
                singleLine = true,
                textStyle = TextStyle(fontSize = 15.sp, color = settingsInk()),
                keyboardOptions = KeyboardOptions(keyboardType = keyboardType),
                cursorBrush = SolidColor(settingsAccent()),
                modifier = Modifier
                    .weight(1f)
                    .onFocusChanged { focused = it.isFocused },
                decorationBox = { inner ->
                    Box(contentAlignment = Alignment.CenterStart) {
                        if (value.isEmpty() && placeholder.isNotEmpty()) {
                            Text(placeholder, color = Color(0xFFC5CAD3), fontSize = 15.sp, fontWeight = FontWeight.Medium)
                        }
                        inner()
                    }
                },
            )
        }
    }
    if (ltr) {
        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) { field() }
    } else {
        field()
    }
}

@Composable
private fun Hint(text: String) {
    Text(
        text,
        fontSize = 12.sp,
        lineHeight = 19.sp,
        color = if (settingsNight()) settingsMut() else AppColors.textSecondary,
        modifier = Modifier.padding(top = 10.dp, start = 2.dp, end = 2.dp),
    )
}

@Composable
private fun PdSaveButton(label: String, enabled: Boolean = true, onClick: () -> Unit) {
    val shape = RoundedCornerShape(14.dp)
    AnimatedVisibility(
        visible = true,
        enter = slideInVertically(tween(420, delayMillis = 160, easing = FastOutSlowInEasing)) { it / 3 } +
            fadeIn(tween(360, delayMillis = 160)),
    ) {
        Box(
            modifier = Modifier
                .padding(top = 16.dp)
                .fillMaxWidth()
                .height(48.dp)
                .alpha(if (enabled) 1f else 0.5f)
                .shadow(8.dp, shape, ambientColor = Color(0x38001B53), spotColor = Color(0x38001B53))
                .clip(shape)
                .background(settingsAccent())
                .clickable(enabled = enabled, onClick = onClick),
            contentAlignment = Alignment.Center,
        ) {
            Text(label, color = Color.White, fontSize = 16.sp, fontWeight = FontWeight.Bold)
        }
    }
}

@Composable
private fun PdScreen(title: String, onBack: () -> Unit, content: @Composable () -> Unit) {
    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize()) {
            SubHeader(title, onBack)
            content()
        }
    }
}

@Composable
private fun PdHeader(title: String, onBack: () -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 14.dp)
            .padding(top = 14.dp, bottom = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(
            title,
            color = settingsAccent(),
            fontSize = 22.sp,
            fontWeight = FontWeight.ExtraBold,
            modifier = Modifier.weight(1f),
            textAlign = TextAlign.Start,
        )
        Box(
            modifier = Modifier
                .size(34.dp)
                .shadow(6.dp, CircleShape, ambientColor = Color(0x14001B53), spotColor = Color(0x14001B53))
                .clip(CircleShape)
                .background(Color.White)
                .clickable(onClick = onBack),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                Icons.AutoMirrored.Rounded.ArrowBack,
                contentDescription = stringResource(R.string.common_back),
                tint = settingsAccent(),
                modifier = Modifier
                    .size(16.dp)
                    .graphicsLayer { scaleX = -1f },
            )
        }
    }
}

@Composable
private fun PdBackdrop(modifier: Modifier = Modifier) {
    Canvas(modifier) {
        drawRect(Color.White)
        fun glow(center: Offset, radius: Float, color: Color) {
            drawCircle(
                brush = Brush.radialGradient(
                    colors = listOf(color, color.copy(alpha = 0.55f), Color.Transparent),
                    center = center,
                    radius = radius,
                ),
                radius = radius,
                center = center,
            )
        }
        glow(Offset(size.width * -0.08f, size.height * -0.12f), size.width * 1.3f, Color(0xFFF8BCA9))
        glow(Offset(size.width * 0.50f, size.height * -0.18f), size.width * 1.1f, Color(0xFFFBD2C4))
        glow(Offset(size.width * 1.12f, size.height * -0.08f), size.width * 0.9f, Color(0xFFF9C4B4))
    }
}
