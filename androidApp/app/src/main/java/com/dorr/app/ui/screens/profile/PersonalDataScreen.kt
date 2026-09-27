package com.dorr.app.ui.screens.profile

import android.content.Context
import android.net.Uri
import android.widget.Toast
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
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Email
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.Female
import androidx.compose.material.icons.rounded.KeyboardArrowDown
import androidx.compose.material.icons.rounded.Male
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Phone
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
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
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.CountryDto
import com.dorr.app.network.OtpRequest
import com.dorr.app.network.PhoneChangeCodeRequest
import com.dorr.app.network.apiFailure
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

private enum class PdSub { NONE, NAME, PHONE, EMAIL }

private const val PD_PREFS = "dorr_profile"
private const val PD_GENDER = "gender"
private const val PD_PHOTO = "photo"
private val Pink = Color(0xFFFDE8EC)
private val FieldFill = Color(0xFFFBF7F8)
private val FieldBorder = Color(0xFFF3D5DB)
private val CardShadow = Color(0x12E50914)
private val VerifiedGreen = Color(0xFF16A34A)

private fun loadGender(context: Context): String =
    context.getSharedPreferences(PD_PREFS, Context.MODE_PRIVATE).getString(PD_GENDER, "").orEmpty()

private fun saveGender(context: Context, gender: String) {
    context.getSharedPreferences(PD_PREFS, Context.MODE_PRIVATE).edit().putString(PD_GENDER, gender).apply()
}

private fun loadPhoto(context: Context): String? =
    context.getSharedPreferences(PD_PREFS, Context.MODE_PRIVATE)
        .getString(PD_PHOTO, null)
        ?.takeIf { File(it).exists() }

private fun saveProfilePhoto(context: Context, uri: Uri): String? = runCatching {
    val dest = File(context.filesDir, "profile_photo.jpg")
    context.contentResolver.openInputStream(uri)?.use { input ->
        dest.outputStream().use { output -> input.copyTo(output) }
    } ?: return null
    context.getSharedPreferences(PD_PREFS, Context.MODE_PRIVATE)
        .edit()
        .putString(PD_PHOTO, dest.absolutePath)
        .apply()
    dest.absolutePath
}.getOrNull()

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

@Composable
fun PersonalDataScreen(onBack: () -> Unit, onSaved: (String) -> Unit) {
    var sub by remember { mutableStateOf(PdSub.NONE) }
    var refresh by remember { mutableIntStateOf(0) }

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
            PdSub.NONE -> PdHub(onBack = onBack, refreshKey = refresh, onOpen = { sub = it })
        }
    }
}

@Composable
private fun PdHub(onBack: () -> Unit, refreshKey: Int, onOpen: (PdSub) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val notAdded = stringResource(R.string.pd_not_added)
    val user = remember(refreshKey) { AuthSession.user }
    val emailVerified = !user?.email.isNullOrBlank()
    var photoPath by remember { mutableStateOf(loadPhoto(context)) }
    val photoPicker = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri ->
        if (uri == null) return@rememberLauncherForActivityResult
        scope.launch {
            val saved = withContext(Dispatchers.IO) { saveProfilePhoto(context, uri) }
            if (saved != null) photoPath = saved
        }
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
                                .shadow(8.dp, CircleShape, ambientColor = Color(0x1FE50914), spotColor = Color(0x1FE50914))
                                .clip(CircleShape)
                                .background(Color.White),
                            contentAlignment = Alignment.Center,
                        ) {
                            if (photoPath != null) {
                                AsyncImage(
                                    model = File(photoPath!!),
                                    contentDescription = stringResource(R.string.pd_change_photo),
                                    contentScale = ContentScale.Crop,
                                    modifier = Modifier.fillMaxSize(),
                                )
                            } else {
                                Icon(Icons.Rounded.Person, contentDescription = stringResource(R.string.pd_change_photo), tint = AppColors.waRed, modifier = Modifier.size(44.dp))
                            }
                        }
                        Box(
                            modifier = Modifier
                                .align(Alignment.BottomEnd)
                                .size(28.dp)
                                .shadow(4.dp, CircleShape, ambientColor = Color(0x40E50914), spotColor = Color(0x40E50914))
                                .clip(CircleShape)
                                .background(AppColors.waRed),
                            contentAlignment = Alignment.Center,
                        ) {
                            Icon(Icons.Rounded.Edit, contentDescription = null, tint = Color.White, modifier = Modifier.size(14.dp))
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
            .shadow(6.dp, RoundedCornerShape(14.dp), ambientColor = CardShadow, spotColor = CardShadow)
            .clip(RoundedCornerShape(14.dp))
            .background(Color.White)
            .clickable(onClick = onClick)
            .padding(horizontal = 14.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            modifier = Modifier
                .size(34.dp)
                .clip(RoundedCornerShape(10.dp))
                .background(Pink),
            contentAlignment = Alignment.Center,
        ) {
            Icon(icon, contentDescription = null, tint = AppColors.waRed, modifier = Modifier.size(18.dp))
        }
        Spacer(Modifier.width(10.dp))
        Column(modifier = Modifier.weight(1f)) {
            Text(label, fontSize = 12.sp, fontWeight = FontWeight.SemiBold, color = AppColors.textMuted)
            Row(verticalAlignment = Alignment.CenterVertically) {
                val valueText: @Composable () -> Unit = {
                    Text(
                        value,
                        fontSize = 14.sp,
                        fontWeight = FontWeight.Bold,
                        color = AppColors.textPrimary,
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
                    color = AppColors.textMuted,
                    modifier = Modifier.padding(top = 2.dp),
                )
            }
        }
        Icon(
            Icons.Rounded.ChevronRight,
            contentDescription = null,
            tint = Color(0xFFEFA8B4),
            modifier = Modifier
                .size(16.dp)
                .graphicsLayer { if (rtl) scaleX = -1f },
        )
    }
}

@Composable
private fun EditNameScreen(onBack: () -> Unit, onSaved: (String) -> Unit) {
    val context = LocalContext.current
    var name by remember { mutableStateOf(AuthSession.user?.name.orEmpty()) }
    var gender by remember { mutableStateOf(loadGender(context)) }
    val enterName = stringResource(R.string.toast_enter_name)
    val updated = stringResource(R.string.toast_profile_updated)

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
                    color = AppColors.textPrimary,
                )
                Spacer(Modifier.height(8.dp))
                GenderOption(stringResource(R.string.gender_male), Icons.Rounded.Male, gender == "male") { gender = "male" }
                Spacer(Modifier.height(8.dp))
                GenderOption(stringResource(R.string.gender_female), Icons.Rounded.Female, gender == "female") { gender = "female" }
            }
            PdSaveButton(label = stringResource(R.string.common_save)) {
                if (name.trim().length < 2) {
                    Toast.makeText(context, enterName, Toast.LENGTH_SHORT).show()
                    return@PdSaveButton
                }
                AuthSession.user = AuthSession.user?.copy(name = name.trim())
                if (gender == "male" || gender == "female") saveGender(context, gender)
                onSaved(updated)
                onBack()
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
            .background(if (selected) Pink else FieldFill)
            .border(1.5.dp, if (selected) AppColors.waRed else Color.Transparent, RoundedCornerShape(14.dp))
            .clickable(onClick = onClick)
            .padding(horizontal = 16.dp, vertical = 14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, contentDescription = null, tint = if (selected) AppColors.waRed else AppColors.textMuted, modifier = Modifier.size(22.dp))
        Spacer(Modifier.width(10.dp))
        Text(
            label,
            fontSize = 15.sp,
            fontWeight = FontWeight.Bold,
            color = if (selected) AppColors.waRed else AppColors.textPrimary,
            modifier = Modifier.weight(1f),
        )
        Box(
            modifier = Modifier
                .size(18.dp)
                .border(2.dp, if (selected) AppColors.waRed else Color(0xFFEFA8B4), CircleShape),
            contentAlignment = Alignment.Center,
        ) {
            if (selected) {
                Box(
                    Modifier
                        .size(10.dp)
                        .clip(CircleShape)
                        .background(AppColors.waRed),
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
    var pinError by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val updated = stringResource(R.string.toast_phone_updated)
    val genericError = stringResource(R.string.wa_error_generic)
    val networkError = stringResource(R.string.wa_error_network)

    LaunchedEffect(Unit) {
        val listed = runCatching { ApiClient.countries.list().data.orEmpty() }.getOrDefault(emptyList())
        val picked = listed.find { it.isDefault } ?: listed.find { it.code.equals("sa", true) } ?: listed.firstOrNull()
        if (picked != null) {
            dial = formatDial(picked.dialCode).ifBlank { dial }
            flag = picked.flag?.code?.lowercase()?.takeIf { it.length == 2 } ?: picked.code.lowercase().take(2)
            phoneLength = picked.phoneLength ?: phoneLength
            phoneStartsWith = picked.phoneStartsWith.orEmpty()
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

    // Starts (or retries, once a PIN is known) the change; the server decides whether a PIN is needed.
    fun start(pin: String?) {
        scope.launch {
            busy = true
            serverError = null
            try {
                ApiClient.mobileAuth.startPhoneChange(
                    "Bearer ${AuthSession.token.orEmpty()}",
                    OtpRequest(dial, phone),
                    pin,
                )
                step = "otp"
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                val failure = e.apiFailure()
                when (failure.errorCode) {
                    "wallet_pin_not_set", "wallet_pin_required" -> step = "pin"
                    "wallet_pin_invalid" -> {
                        step = "pin"
                        pinError = failure.message
                    }
                    else -> {
                        step = "form"
                        serverError = if (failure.httpStatus == null) networkError else failure.message ?: genericError
                    }
                }
            } finally {
                busy = false
            }
        }
    }

    when (step) {
        "pin" -> Box(Modifier.fillMaxSize()) {
            WaPinPad(
                title = stringResource(R.string.wa_pin_enter_title),
                sub = stringResource(R.string.edit_phone_title),
                onComplete = { pin ->
                    pinError = null
                    var result: PadResult = PadResult.Ok
                    try {
                        ApiClient.mobileAuth.startPhoneChange(
                            "Bearer ${AuthSession.token.orEmpty()}",
                            OtpRequest(dial, phone),
                            pin,
                        )
                        step = "otp"
                    } catch (e: CancellationException) {
                        throw e
                    } catch (e: Exception) {
                        val failure = e.apiFailure()
                        result = if (failure.errorCode in PIN_ERROR_CODES) {
                            PadResult.Error(failure.message ?: genericError)
                        } else {
                            step = "form"
                            serverError = if (failure.httpStatus == null) networkError else failure.message ?: genericError
                            PadResult.Ok
                        }
                    }
                    result
                },
            )
        }
        "otp" -> PdOtpStep(
            title = stringResource(R.string.edit_phone_title),
            target = "$dial $phone",
            onBack = { step = "form" },
            onVerify = { code ->
                try {
                    val result = ApiClient.mobileAuth.confirmPhoneChange(
                        "Bearer ${AuthSession.token.orEmpty()}",
                        PhoneChangeCodeRequest(code),
                    ).data
                    val newPhone = result?.phone ?: "$dial$phone"
                    AuthSession.user = AuthSession.user?.copy(phone = newPhone)
                    null
                } catch (e: CancellationException) {
                    throw e
                } catch (e: Exception) {
                    val failure = e.apiFailure()
                    if (failure.httpStatus == null) networkError else failure.message ?: genericError
                }
            },
            onResend = {
                try {
                    ApiClient.mobileAuth.startPhoneChange("Bearer ${AuthSession.token.orEmpty()}", OtpRequest(dial, phone), null)
                    null
                } catch (e: CancellationException) {
                    throw e
                } catch (e: Exception) {
                    e.apiFailure().message ?: genericError
                }
            },
            onVerified = {
                onSaved(updated)
                onBack()
            },
        )
        else -> PdScreen(title = stringResource(R.string.edit_phone_title), onBack = onBack) {
            FormColumn {
                PdFormCard {
                    Text(
                        stringResource(R.string.edit_phone_label),
                        fontSize = 13.sp,
                        fontWeight = FontWeight.Bold,
                        color = AppColors.textPrimary,
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
                    if (isPhoneValid) {
                        start(null)
                    }
                }
            }
        }
    }
}

@Composable
private fun EditEmailScreen(onBack: () -> Unit, onSaved: (String) -> Unit) {
    var email by remember { mutableStateOf(AuthSession.user?.email.orEmpty()) }
    var stepOtp by remember { mutableStateOf(false) }
    val context = LocalContext.current
    val invalid = stringResource(R.string.toast_valid_email)
    val updated = stringResource(R.string.toast_email_updated)

    if (stepOtp) {
        PdOtpStep(
            title = stringResource(R.string.edit_email_title),
            target = email.trim(),
            onBack = { stepOtp = false },
            onVerified = {
                AuthSession.user = AuthSession.user?.copy(email = email.trim())
                onSaved(updated)
                onBack()
            },
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
            PdSaveButton(label = stringResource(R.string.pd_send_code)) {
                val value = email.trim()
                if (!value.contains("@") || !value.contains(".")) {
                    Toast.makeText(context, invalid, Toast.LENGTH_SHORT).show()
                    return@PdSaveButton
                }
                stepOtp = true
            }
        }
    }
}

/**
 * [onVerify]/[onResend] are the real API calls when given (return an error message, or null on
 * success) — used by the phone-change flow. Left null (the default), this keeps the original
 * local-only mock (checks for the fixed demo code "1234") that every other profile field still uses.
 */
@Composable
private fun PdOtpStep(
    title: String,
    target: String,
    onBack: () -> Unit,
    onVerified: () -> Unit,
    onVerify: (suspend (String) -> String?)? = null,
    onResend: (suspend () -> String?)? = null,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var digits by remember { mutableStateOf(List(4) { "" }) }
    var countdown by remember { mutableIntStateOf(60) }
    var hasError by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    val wrongCode = stringResource(R.string.toast_wrong_code)
    val codeSent = stringResource(R.string.toast_code_sent)
    val focusers = remember { List(4) { FocusRequester() } }

    LaunchedEffect(countdown) {
        if (countdown > 0) {
            delay(1000)
            countdown--
        }
    }

    fun verify(code: String) {
        if (code.length != 4 || busy) return

        if (onVerify == null) {
            if (code == "1234") {
                onVerified()
            } else {
                hasError = true
                Toast.makeText(context, wrongCode, Toast.LENGTH_SHORT).show()
            }
            return
        }

        scope.launch {
            busy = true
            val error = onVerify(code)
            busy = false
            if (error == null) {
                onVerified()
            } else {
                hasError = true
                digits = List(4) { "" }
                Toast.makeText(context, error, Toast.LENGTH_SHORT).show()
                focusers[0].requestFocus()
            }
        }
    }

    PdScreen(title = title, onBack = onBack) {
        FormColumn {
            PdFormCard {
                Text(
                    stringResource(R.string.profile_otp_hint, target),
                    fontSize = 12.sp,
                    lineHeight = 19.sp,
                    color = AppColors.textSecondary,
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
                                        if (code.length == 4) verify(code)
                                    }
                                },
                            )
                        }
                    }
                }
                Spacer(Modifier.height(14.dp))
                if (countdown > 0) {
                    Text(
                        stringResource(R.string.profile_otp_timer, countdown),
                        fontSize = 13.sp,
                        color = AppColors.textSecondary,
                        textAlign = TextAlign.Center,
                        modifier = Modifier.fillMaxWidth(),
                    )
                } else {
                    Text(
                        stringResource(R.string.profile_otp_resend),
                        fontSize = 13.sp,
                        fontWeight = FontWeight.Bold,
                        color = AppColors.waRed,
                        textAlign = TextAlign.Center,
                        modifier = Modifier
                            .fillMaxWidth()
                            .clickable {
                                digits = List(4) { "" }
                                hasError = false
                                countdown = 60
                                focusers[0].requestFocus()
                                if (onResend == null) {
                                    Toast.makeText(context, codeSent, Toast.LENGTH_SHORT).show()
                                } else {
                                    scope.launch {
                                        val error = onResend()
                                        Toast.makeText(context, error ?: codeSent, Toast.LENGTH_SHORT).show()
                                    }
                                }
                            },
                    )
                }
                Spacer(Modifier.height(12.dp))
                Text(
                    stringResource(R.string.profile_otp_dev_hint),
                    fontSize = 12.sp,
                    color = AppColors.textMuted,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth(),
                )
            }
            val code = digits.joinToString("")
            PdSaveButton(
                label = stringResource(R.string.profile_otp_confirm),
                enabled = code.length == 4,
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
) {
    var focused by remember { mutableStateOf(false) }
    val border = when {
        hasError -> AppColors.danger
        focused -> AppColors.waRed
        else -> Color(0xFFF3C4CC)
    }
    Box(
        modifier = Modifier
            .width(40.dp)
            .height(48.dp)
            .clip(RoundedCornerShape(14.dp))
            .background(Color.White)
            .border(1.5.dp, border, RoundedCornerShape(14.dp)),
        contentAlignment = Alignment.Center,
    ) {
        BasicTextField(
            value = value,
            onValueChange = { onValue(it.filter(Char::isDigit).takeLast(1)) },
            singleLine = true,
            textStyle = TextStyle(
                fontSize = 20.sp,
                fontWeight = FontWeight.SemiBold,
                color = AppColors.textPrimary,
                textAlign = TextAlign.Center,
            ),
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
            cursorBrush = SolidColor(AppColors.waRed),
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
        focused -> AppColors.waRed
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
                .background(if (focused) Color.White else FieldFill)
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
                Text(dial, fontSize = 14.sp, fontWeight = FontWeight.Bold, color = AppColors.textPrimary)
            }
            PhoneSep()
            BasicTextField(
                value = phone,
                onValueChange = onPhone,
                singleLine = true,
                textStyle = TextStyle(fontSize = 14.sp, fontWeight = FontWeight.Medium, color = AppColors.textPrimary, letterSpacing = 0.5.sp),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
                cursorBrush = SolidColor(AppColors.waRed),
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
            .background(Color(0xFFE5E7EB)),
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
                .shadow(8.dp, RoundedCornerShape(18.dp), ambientColor = CardShadow, spotColor = CardShadow)
                .clip(RoundedCornerShape(18.dp))
                .background(Color.White)
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
    Text(label, fontSize = 13.sp, fontWeight = FontWeight.Bold, color = AppColors.textPrimary, modifier = Modifier.padding(bottom = 8.dp))
    val field = @Composable {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .height(44.dp)
                .clip(RoundedCornerShape(999.dp))
                .background(if (focused) Color.White else FieldFill)
                .border(1.dp, if (focused) AppColors.waRed else FieldBorder, RoundedCornerShape(999.dp))
                .padding(horizontal = 14.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(icon, contentDescription = null, tint = AppColors.waRed, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(8.dp))
            BasicTextField(
                value = value,
                onValueChange = onValueChange,
                singleLine = true,
                textStyle = TextStyle(fontSize = 15.sp, color = AppColors.textPrimary),
                keyboardOptions = KeyboardOptions(keyboardType = keyboardType),
                cursorBrush = SolidColor(AppColors.waRed),
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
        color = AppColors.textSecondary,
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
                .shadow(8.dp, shape, ambientColor = Color(0x38E50914), spotColor = Color(0x38E50914))
                .clip(shape)
                .background(AppColors.waRed)
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
        PdBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize()) {
            PdHeader(title, onBack)
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
            color = AppColors.waRed,
            fontSize = 22.sp,
            fontWeight = FontWeight.ExtraBold,
            modifier = Modifier.weight(1f),
            textAlign = TextAlign.Start,
        )
        Box(
            modifier = Modifier
                .size(34.dp)
                .shadow(6.dp, CircleShape, ambientColor = Color(0x14E50914), spotColor = Color(0x14E50914))
                .clip(CircleShape)
                .background(Color.White)
                .clickable(onClick = onBack),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                Icons.AutoMirrored.Rounded.ArrowBack,
                contentDescription = stringResource(R.string.common_back),
                tint = AppColors.waRed,
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
        glow(Offset(size.width * -0.08f, size.height * -0.12f), size.width * 1.3f, Color(0xFFEFA8B4))
        glow(Offset(size.width * 0.50f, size.height * -0.18f), size.width * 1.1f, Color(0xFFF3C4CC))
        glow(Offset(size.width * 1.12f, size.height * -0.08f), size.width * 0.9f, Color(0xFFF0B8C2))
    }
}
