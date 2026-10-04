package com.dorr.app.ui.screens.wallet

import android.content.Context
import android.Manifest
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Matrix
import android.media.ExifInterface
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.core.content.ContextCompat
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CalendarToday
import androidx.compose.material.icons.rounded.LockReset
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.AddPhotoAlternate
import androidx.compose.material.icons.rounded.PhotoCamera
import androidx.compose.material.icons.rounded.Badge
import androidx.compose.material.icons.rounded.Cake
import androidx.compose.material.icons.rounded.Email
import androidx.compose.material.icons.rounded.Flight
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.Key
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material.icons.rounded.Warning
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
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
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ChangePinRequest
import com.dorr.app.network.CreatePinRequest
import com.dorr.app.network.PinStatusDto
import com.dorr.app.network.RecoverPinRequest
import com.dorr.app.network.RecoveryCodeRequest
import com.dorr.app.network.RecoverySetupRequest
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.components.DorrTextField
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.ByteArrayOutputStream
import java.time.LocalDate

/**
 * How a forgotten wallet PIN gets recovered (docs/wallet-tasks.md, "PIN recovery"). One of these is chosen
 * *before* the PIN is created; [wire] is the value the API uses.
 */
enum class RecoveryMethodUi(val wire: String, val icon: ImageVector, val tone: Tone, val title: Int, val desc: Int) {
    Password("password", Icons.Rounded.Key, Tone.Red, R.string.wa_rec_m_password, R.string.wa_rec_m_password_d),
    BirthDate("birth_date", Icons.Rounded.Cake, Tone.Pink, R.string.wa_rec_m_birth_date, R.string.wa_rec_m_birth_date_d),
    IdPhoto("id_photo", Icons.Rounded.Badge, Tone.Blue, R.string.wa_rec_m_id_photo, R.string.wa_rec_m_id_photo_d),
    PassportPhoto("passport_photo", Icons.Rounded.Flight, Tone.Amber, R.string.wa_rec_m_passport_photo, R.string.wa_rec_m_passport_photo_d),
    Email("email", Icons.Rounded.Email, Tone.Green, R.string.wa_rec_m_email, R.string.wa_rec_m_email_d),
    ;

    val isPhoto get() = this == IdPhoto || this == PassportPhoto

    companion object {
        fun of(wire: String?): RecoveryMethodUi? = entries.firstOrNull { it.wire == wire }
    }
}

// ------------------------------------------------------------------------------- photos

/** A picture chosen from the gallery, already shrunk and turned into a JPEG the server accepts (max 5 MB). */
class PickedPhoto(val bytes: ByteArray, val preview: ImageBitmap)

internal fun PickedPhoto.toPart(field: String = "document"): MultipartBody.Part =
    MultipartBody.Part.createFormData(field, "$field.jpg", bytes.toRequestBody("image/jpeg".toMediaType()))

/** Decodes at most ~2000px, honours the camera's rotation, re-encodes as JPEG. Null when the file is not a picture. */
internal suspend fun readPhoto(context: Context, uri: Uri): PickedPhoto? = withContext(Dispatchers.IO) {
    runCatching {
        val resolver = context.contentResolver
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        resolver.openInputStream(uri)?.use { BitmapFactory.decodeStream(it, null, bounds) }
        var sample = 1
        while (maxOf(bounds.outWidth, bounds.outHeight) / sample > 2000) sample *= 2

        val decoded = resolver.openInputStream(uri)?.use { BitmapFactory.decodeStream(it, null, BitmapFactory.Options().apply { inSampleSize = sample }) }
            ?: return@runCatching null
        val degrees = resolver.openInputStream(uri)?.use {
            when (ExifInterface(it).getAttributeInt(ExifInterface.TAG_ORIENTATION, ExifInterface.ORIENTATION_NORMAL)) {
                ExifInterface.ORIENTATION_ROTATE_90 -> 90f
                ExifInterface.ORIENTATION_ROTATE_180 -> 180f
                ExifInterface.ORIENTATION_ROTATE_270 -> 270f
                else -> 0f
            }
        } ?: 0f
        val bitmap = if (degrees == 0f) decoded else Bitmap.createBitmap(decoded, 0, 0, decoded.width, decoded.height, Matrix().apply { postRotate(degrees) }, true)

        val out = ByteArrayOutputStream()
        bitmap.compress(Bitmap.CompressFormat.JPEG, 88, out)
        PickedPhoto(out.toByteArray(), bitmap.asImageBitmap())
    }.getOrNull()
}

/** The "tap to choose a photo" card; shows the picture once one is chosen. */
@Composable
private fun WaPhotoPicker(photo: PickedPhoto?, onPicked: (PickedPhoto?) -> Unit, onUnreadable: () -> Unit, useCamera: Boolean = false) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val galleryLauncher = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        if (uri != null) scope.launch {
            val picked = readPhoto(context, uri)
            if (picked == null) onUnreadable() else onPicked(picked)
        }
    }
    // A selfie has to be taken live, not picked from the gallery — that's the whole point of asking for one.
    val cameraLauncher = rememberLauncherForActivityResult(ActivityResultContracts.TakePicturePreview()) { bitmap ->
        if (bitmap != null) scope.launch {
            val picked = withContext(Dispatchers.IO) { bitmapToPhoto(bitmap) }
            if (picked == null) onUnreadable() else onPicked(picked)
        }
    }
    val cameraPermission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        if (granted) cameraLauncher.launch(null)
    }
    fun launchPicker() {
        if (!useCamera) {
            galleryLauncher.launch("image/*")
            return
        }
        if (ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) {
            cameraLauncher.launch(null)
        } else {
            cameraPermission.launch(Manifest.permission.CAMERA)
        }
    }

    val shape = RoundedCornerShape(20.dp)
    Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.fillMaxWidth()) {
        Box(
            Modifier
                .fillMaxWidth()
                .heightIn(min = 190.dp)
                .clip(shape)
                .background(Color.White)
                .border(1.5.dp, if (photo == null) Color(0xFFFBD2C4) else Wa.Line, shape)
                .clickable { launchPicker() },
            contentAlignment = Alignment.Center,
        ) {
            if (photo == null) {
                Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.padding(24.dp)) {
                    WaIconWell(if (useCamera) Icons.Rounded.PhotoCamera else Icons.Rounded.AddPhotoAlternate, Tone.Red, size = 58.dp, iconSize = 28.dp)
                    Spacer(Modifier.height(10.dp))
                    Text(
                        stringResource(if (useCamera) R.string.wa_rec_photo_take else R.string.wa_rec_photo_pick),
                        fontWeight = FontWeight.ExtraBold, fontSize = 15.sp, color = Wa.Red,
                    )
                }
            } else {
                Image(photo.preview, null, contentScale = ContentScale.Fit, modifier = Modifier.fillMaxWidth().heightIn(max = 260.dp).padding(8.dp))
            }
        }
        if (photo != null) {
            WaButton(
                stringResource(if (useCamera) R.string.wa_rec_photo_retake else R.string.wa_rec_photo_change),
                { launchPicker() },
                style = WaButtonStyle.Quiet,
                modifier = Modifier.padding(top = 6.dp),
            )
        }
    }
}

/** JPEG-encodes a camera preview [Bitmap] the same way a gallery pick is re-encoded in [readPhoto]. */
private fun bitmapToPhoto(bitmap: Bitmap): PickedPhoto {
    val out = ByteArrayOutputStream()
    bitmap.compress(Bitmap.CompressFormat.JPEG, 88, out)
    return PickedPhoto(out.toByteArray(), bitmap.asImageBitmap())
}

// ------------------------------------------------------------------------------- shared pieces

@Composable
private fun WaStepHeader(icon: ImageVector, tone: Tone, title: String, sub: String) {
    Column(Modifier.fillMaxWidth().padding(top = 8.dp, bottom = 18.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        WaIconWell(icon, tone, size = 58.dp, iconSize = 28.dp)
        Spacer(Modifier.height(10.dp))
        Text(title, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp, color = Wa.Ink, textAlign = TextAlign.Center)
        if (sub.isNotEmpty()) {
            Spacer(Modifier.height(4.dp))
            Text(sub, color = Wa.Mut, fontSize = 13.sp, lineHeight = 21.sp, textAlign = TextAlign.Center)
        }
    }
}

/** A scrolling form with its button pinned underneath; lifts above the keyboard so nothing typed is hidden. */
@Composable
private fun ColumnScope.WaFormStep(cta: @Composable ColumnScope.() -> Unit, content: @Composable ColumnScope.() -> Unit) {
    Column(Modifier.weight(1f).fillMaxWidth().imePadding()) {
        Column(Modifier.weight(1f).fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 8.dp), content = content)
        Column(Modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 12.dp), content = cta)
    }
}

/** Day / month / year boxes → "yyyy-MM-dd", or null when it is not a real date in the past. */
@Composable
private fun WaBirthFields(day: String, month: String, year: String, onChange: (String, String, String) -> Unit, error: Boolean) {
    // Digits read left-to-right in every language.
    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            val keyboard = KeyboardOptions(keyboardType = KeyboardType.Number)
            DorrTextField(day, { onChange(it.filter(Char::isDigit).take(2), month, year) }, Modifier.weight(1f), label = stringResource(R.string.wa_rec_day), placeholder = "DD", icon = Icons.Rounded.CalendarToday, keyboardOptions = keyboard, error = error)
            DorrTextField(month, { onChange(day, it.filter(Char::isDigit).take(2), year) }, Modifier.weight(1f), label = stringResource(R.string.wa_rec_month), placeholder = "MM", keyboardOptions = keyboard, error = error)
            DorrTextField(year, { onChange(day, month, it.filter(Char::isDigit).take(4)) }, Modifier.weight(1.4f), label = stringResource(R.string.wa_rec_year), placeholder = "YYYY", keyboardOptions = keyboard, error = error)
        }
    }
}

internal fun birthDateOrNull(day: String, month: String, year: String): String? {
    val date = runCatching { LocalDate.of(year.toInt(), month.toInt(), day.toInt()) }.getOrNull() ?: return null
    if (year.length != 4 || !date.isBefore(LocalDate.now()) || date.year < 1900) return null
    return date.toString()
}

private val EMAIL_SHAPE = Regex("^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$")

@Composable
private fun WaCodeField(code: String, onChange: (String) -> Unit, error: Boolean) {
    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
        DorrTextField(
            value = code,
            onValueChange = { onChange(it.filter(Char::isDigit).take(4)) },
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
            placeholder = "••••",
            error = error,
            minHeight = 58.dp,
            shape = androidx.compose.foundation.shape.RoundedCornerShape(18.dp),
            textStyle = androidx.compose.ui.text.TextStyle(fontSize = 26.sp, fontWeight = FontWeight.ExtraBold, letterSpacing = 10.sp, textAlign = TextAlign.Center),
        )
    }
}

@Composable
private fun WaMethodRow(method: RecoveryMethodUi, onClick: () -> Unit, index: Int) {
    WaCard(Modifier.fillMaxWidth().waRise(index).clickable(onClick = onClick), padding = 14.dp) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            WaIconWell(method.icon, method.tone)
            Column(Modifier.weight(1f)) {
                Text(stringResource(method.title), fontWeight = FontWeight.ExtraBold, fontSize = 15.sp, color = Wa.Ink)
                Text(stringResource(method.desc), fontSize = 12.5.sp, color = Wa.Mut, lineHeight = 19.sp)
            }
            Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, null, tint = Wa.Soft)
        }
    }
}

@Composable
private fun photoName(method: RecoveryMethodUi) = stringResource(if (method == RecoveryMethodUi.PassportPhoto) R.string.wa_photo_passport else R.string.wa_photo_id)

// ------------------------------------------------------------------------------- create the PIN

/**
 * First-time PIN: choose a way to get the PIN back → fill in its details (an e-mail is confirmed with a
 * 4-digit code) → enter the PIN → confirm it. Draws its own [WaPage]. [onDone] runs after the PIN exists
 * (after a "saved" seal when [showSaved]).
 */
@Composable
fun WaPinSetupPage(
    onExit: () -> Unit,
    onDone: () -> Unit,
    title: String = stringResource(R.string.wa_pin_page_title),
    showSaved: Boolean = true,
    initialStep: String = "method",
    initialMethod: RecoveryMethodUi = RecoveryMethodUi.Password,
    existingPin: String? = null,
) {
    // existingPin != null: the person already has a PIN and is only switching how it can be recovered —
    // proven with that PIN, and it ends after the method (no PIN steps).
    val changing = existingPin != null
    val networkError = stringResource(R.string.wa_error_network)
    val mismatch = stringResource(R.string.wa_pin_mismatch)
    val scope = rememberCoroutineScope()

    var step by remember { mutableStateOf(initialStep) }
    var method by remember { mutableStateOf(initialMethod) }
    var password by remember { mutableStateOf("") }
    var passwordAgain by remember { mutableStateOf("") }
    var day by remember { mutableStateOf("") }
    var month by remember { mutableStateOf("") }
    var year by remember { mutableStateOf("") }
    var email by remember { mutableStateOf("") }
    var photo by remember { mutableStateOf<PickedPhoto?>(null) }
    var code by remember { mutableStateOf("") }
    var first by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }

    val shortPassword = stringResource(R.string.wa_rec_password_short)
    val passwordMismatch = stringResource(R.string.wa_rec_password_mismatch)
    val birthInvalid = stringResource(R.string.wa_rec_birth_invalid)
    val emailInvalid = stringResource(R.string.wa_rec_email_invalid)
    val photoRequired = stringResource(R.string.wa_rec_photo_required)
    val photoUnreadable = stringResource(R.string.wa_rec_photo_unreadable)
    val codeInvalid = stringResource(R.string.wa_rec_code_invalid)
    val codeSent = stringResource(R.string.wa_rec_code_sent)

    fun back() {
        error = null
        notice = null
        when (step) {
            "method", "saved" -> onExit()
            "detail" -> step = "method"
            "code" -> step = "detail"
            "pin" -> step = "method"
            "confirm" -> step = "pin"
        }
    }
    BackHandler { back() }

    fun submitDetail() {
        error = when (method) {
            RecoveryMethodUi.Password -> when {
                password.length < 6 -> shortPassword
                password != passwordAgain -> passwordMismatch
                else -> null
            }
            RecoveryMethodUi.BirthDate -> if (birthDateOrNull(day, month, year) == null) birthInvalid else null
            RecoveryMethodUi.Email -> if (!EMAIL_SHAPE.matches(email.trim())) emailInvalid else null
            else -> if (photo == null) photoRequired else null
        }
        if (error != null) return
        scope.launch {
            busy = true
            try {
                val auth = walletAuth()
                when (method) {
                    RecoveryMethodUi.Password -> ApiClient.wallet.setRecovery(auth, RecoverySetupRequest("password", password = password, passwordConfirmation = passwordAgain), existingPin)
                    RecoveryMethodUi.BirthDate -> ApiClient.wallet.setRecovery(auth, RecoverySetupRequest("birth_date", birthDate = birthDateOrNull(day, month, year)), existingPin)
                    RecoveryMethodUi.Email -> ApiClient.wallet.setRecovery(auth, RecoverySetupRequest("email", email = email.trim()), existingPin)
                    else -> ApiClient.wallet.setRecoveryDocument(auth, method.wire.toRequestBody("text/plain".toMediaType()), photo!!.toPart(), existingPin)
                }
                step = if (method == RecoveryMethodUi.Email) "code" else if (changing) "saved" else "pin"
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                error = e.apiFailure().message ?: networkError
            } finally {
                busy = false
            }
        }
    }

    fun confirmCode() {
        if (code.length != 4) {
            error = codeInvalid
            return
        }
        scope.launch {
            busy = true
            try {
                ApiClient.wallet.confirmRecoveryEmail(walletAuth(), RecoveryCodeRequest(code))
                error = null
                step = if (changing) "saved" else "pin"
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                error = e.apiFailure().message ?: networkError
            } finally {
                busy = false
            }
        }
    }

    fun resendCode() {
        scope.launch {
            try {
                ApiClient.wallet.sendRecoveryEmailCode(walletAuth(), if (changing) true else null)
                error = null
                notice = codeSent
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                error = e.apiFailure().message ?: networkError
            }
        }
    }

    WaPage(title = title, onBack = { back() }, scroll = false) {
        when (step) {
            "method" -> Column(Modifier.weight(1f).fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 8.dp)) {
                WaStepHeader(Icons.Rounded.Shield, Tone.Red, stringResource(R.string.wa_rec_choose_title), stringResource(R.string.wa_rec_choose_sub))
                RecoveryMethodUi.entries.forEachIndexed { index, item ->
                    WaMethodRow(item, index = index, onClick = {
                        method = item
                        error = null
                        step = "detail"
                    })
                    Spacer(Modifier.height(10.dp))
                }
            }

            "detail" -> WaFormStep(
                cta = {
                    WaError(error)
                    WaButton(stringResource(R.string.wa_continue), { submitDetail() }, loading = busy)
                },
            ) {
                WaStepHeader(method.icon, method.tone, stringResource(method.title), "")
                when (method) {
                    RecoveryMethodUi.Password -> {
                        DorrTextField(password, { password = it; error = null }, label = stringResource(R.string.wa_rec_password), icon = Icons.Rounded.Lock, password = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password))
                        Spacer(Modifier.height(12.dp))
                        DorrTextField(passwordAgain, { passwordAgain = it; error = null }, label = stringResource(R.string.wa_rec_password_confirm), icon = Icons.Rounded.LockReset, password = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password))
                        Spacer(Modifier.height(12.dp))
                        WaNote(stringResource(R.string.wa_rec_password_hint))
                    }
                    RecoveryMethodUi.BirthDate -> {
                        WaBirthFields(day, month, year, { d, m, y -> day = d; month = m; year = y; error = null }, error = error != null)
                        Spacer(Modifier.height(12.dp))
                        WaNote(stringResource(R.string.wa_rec_birth_hint))
                    }
                    RecoveryMethodUi.Email -> {
                        DorrTextField(email, { email = it; error = null }, label = stringResource(R.string.wa_rec_email), icon = Icons.Rounded.Email, placeholder = "name@example.com", keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email))
                        Spacer(Modifier.height(12.dp))
                        WaNote(stringResource(R.string.wa_rec_email_hint))
                    }
                    else -> {
                        WaPhotoPicker(photo, { photo = it; error = null }, onUnreadable = { error = photoUnreadable })
                        Spacer(Modifier.height(12.dp))
                        WaNote(stringResource(if (method == RecoveryMethodUi.PassportPhoto) R.string.wa_rec_photo_passport_hint else R.string.wa_rec_photo_id_hint))
                    }
                }
            }

            "code" -> WaFormStep(
                cta = {
                    WaError(error)
                    WaButton(stringResource(R.string.wa_continue), { confirmCode() }, loading = busy, enabled = code.length == 4)
                    WaButton(stringResource(R.string.wa_rec_code_resend), { resendCode() }, style = WaButtonStyle.Quiet)
                },
            ) {
                WaStepHeader(Icons.Rounded.Email, Tone.Green, stringResource(R.string.wa_rec_code_title), stringResource(R.string.wa_rec_code_sub, email.trim()))
                WaCodeField(code, { code = it; error = null }, error = error != null)
                if (notice != null) Text(notice.orEmpty(), color = Wa.Green, fontSize = 12.5.sp, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(top = 10.dp))
            }

            "saved" -> WaStatusColumn {
                WaSeal()
                WaStatusTitle(stringResource(if (changing) R.string.wa_rec_changed_title else R.string.wa_pin_saved_title))
                WaStatusText(stringResource(if (changing) R.string.wa_rec_changed_text else R.string.wa_pin_saved_text))
                Spacer(Modifier.height(12.dp))
                WaButton(stringResource(R.string.wa_done), { onDone() }, modifier = Modifier.padding(horizontal = 20.dp))
            }

            else -> Box(Modifier.weight(1f).fillMaxWidth().padding(horizontal = 8.dp)) {
                val confirming = step == "confirm"
                WaPinPad(
                    title = stringResource(if (confirming) R.string.wa_pin_confirm_title else R.string.wa_pin_create_title_page),
                    sub = stringResource(if (confirming) R.string.wa_pin_confirm_sub else R.string.wa_pin_digits_sub),
                    icon = Icons.Rounded.Shield,
                    modifier = Modifier.fillMaxSize().waRise(0),
                    onComplete = { pin ->
                        if (!confirming) {
                            first = pin
                            step = "confirm"
                            return@WaPinPad PadResult.Reset
                        }
                        if (pin != first) {
                            step = "pin"
                            return@WaPinPad PadResult.Error(mismatch)
                        }
                        try {
                            ApiClient.wallet.createPin(walletAuth(), CreatePinRequest(first, pin))
                        } catch (e: CancellationException) {
                            throw e
                        } catch (e: Exception) {
                            step = "pin"
                            val failure = e.apiFailure()
                            return@WaPinPad PadResult.Error(if (failure.httpStatus == null) networkError else failure.message ?: networkError)
                        }
                        if (showSaved) step = "saved" else onDone()
                        PadResult.Ok
                    },
                )
            }
        }
    }
}

// ------------------------------------------------------------------------------- forgot the PIN

/**
 * "I forgot my PIN" — by the method chosen at setup. Password / birth date / e-mail code: prove it, then a new
 * PIN (twice). Photo methods: upload the photo again and wait for a person to approve it (the PIN then
 * becomes 0000). Draws its own [WaPage].
 */
@Composable
fun WaForgotPinPage(status: PinStatusDto?, onExit: () -> Unit, onDone: () -> Unit, initialStep: String = "secret") {
    val networkError = stringResource(R.string.wa_error_network)
    val mismatch = stringResource(R.string.wa_pin_mismatch)
    val scope = rememberCoroutineScope()

    val info = status?.recovery
    val method = RecoveryMethodUi.of(info?.method)
    var request by remember { mutableStateOf(status?.request) }
    var step by remember { mutableStateOf(initialStep) }
    var password by remember { mutableStateOf("") }
    var day by remember { mutableStateOf("") }
    var month by remember { mutableStateOf("") }
    var year by remember { mutableStateOf("") }
    var code by remember { mutableStateOf("") }
    var photo by remember { mutableStateOf<PickedPhoto?>(null) }
    var first by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var sent by remember { mutableStateOf(false) }

    val birthInvalid = stringResource(R.string.wa_rec_birth_invalid)
    val photoRequired = stringResource(R.string.wa_rec_photo_required)
    val photoUnreadable = stringResource(R.string.wa_rec_photo_unreadable)
    val codeInvalid = stringResource(R.string.wa_rec_code_invalid)
    val passwordEmpty = stringResource(R.string.wa_rec_password_short)

    fun back() {
        error = null
        when (step) {
            "secret", "done" -> onExit()
            "pin" -> step = "secret"
            "confirm" -> step = "pin"
        }
    }
    BackHandler { back() }

    // E-mail: a fresh code goes out as soon as this screen opens.
    LaunchedEffect(method) {
        if (method == RecoveryMethodUi.Email && !sent) {
            sent = true
            runCatching { ApiClient.wallet.sendRecoveryEmailCode(walletAuth()) }
        }
    }

    fun continueFromSecret() {
        error = when (method) {
            RecoveryMethodUi.Password -> if (password.isEmpty()) passwordEmpty else null
            RecoveryMethodUi.BirthDate -> if (birthDateOrNull(day, month, year) == null) birthInvalid else null
            RecoveryMethodUi.Email -> if (code.length != 4) codeInvalid else null
            else -> null
        }
        if (error == null) step = "pin"
    }

    fun sendPhoto() {
        val picked = photo
        if (picked == null) {
            error = photoRequired
            return
        }
        scope.launch {
            busy = true
            try {
                val next = ApiClient.wallet.recoverPinWithDocument(walletAuth(), picked.toPart()).data?.request
                request = next ?: request
                error = null
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                error = e.apiFailure().message ?: networkError
            } finally {
                busy = false
            }
        }
    }

    WaPage(title = stringResource(R.string.wa_forgot_title), onBack = { back() }, scroll = false) {
        when {
            info == null || method == null -> Box(Modifier.weight(1f).fillMaxWidth(), contentAlignment = Alignment.Center) {
                WaEmpty(Icons.Rounded.Warning, Tone.Gray, stringResource(R.string.wa_forgot_title), stringResource(R.string.wa_forgot_no_method))
            }

            method.isPhoto && request?.status == "pending" -> WaStatusColumn {
                WaIconWellBig(Icons.Rounded.HourglassTop, Tone.Amber)
                WaStatusTitle(stringResource(R.string.wa_forgot_pending_title))
                WaStatusText(stringResource(R.string.wa_forgot_pending_text))
                Spacer(Modifier.height(12.dp))
                WaButton(stringResource(R.string.wa_done), { onExit() }, modifier = Modifier.padding(horizontal = 20.dp))
            }

            method.isPhoto -> WaFormStep(
                cta = {
                    WaError(error)
                    WaButton(stringResource(R.string.wa_forgot_send), { sendPhoto() }, loading = busy)
                },
            ) {
                WaStepHeader(method.icon, method.tone, stringResource(R.string.wa_forgot_photo_title, photoName(method)), stringResource(R.string.wa_forgot_photo_sub))
                when (request?.status) {
                    "rejected" -> {
                        WaNote(listOfNotNull(stringResource(R.string.wa_forgot_rejected_title), request?.rejectionReason?.takeIf { it.isNotBlank() }, stringResource(R.string.wa_forgot_rejected_text)).joinToString(" — "), icon = Icons.Rounded.Warning)
                        Spacer(Modifier.height(12.dp))
                    }
                    "approved" -> {
                        WaNote(stringResource(R.string.wa_forgot_approved_text))
                        Spacer(Modifier.height(12.dp))
                    }
                }
                WaPhotoPicker(photo, { photo = it; error = null }, onUnreadable = { error = photoUnreadable })
            }

            step == "secret" -> WaFormStep(
                cta = {
                    WaError(error)
                    WaButton(stringResource(R.string.wa_continue), { continueFromSecret() })
                },
            ) {
                val sub = when (method) {
                    RecoveryMethodUi.Password -> stringResource(R.string.wa_forgot_password_sub)
                    RecoveryMethodUi.BirthDate -> stringResource(R.string.wa_forgot_birth_sub)
                    else -> stringResource(R.string.wa_forgot_email_sub, info.email.orEmpty())
                }
                WaStepHeader(method.icon, method.tone, stringResource(method.title), sub)
                when (method) {
                    RecoveryMethodUi.Password -> DorrTextField(password, { password = it; error = null }, label = stringResource(R.string.wa_rec_password), icon = Icons.Rounded.Lock, password = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password), error = error != null)
                    RecoveryMethodUi.BirthDate -> WaBirthFields(day, month, year, { d, m, y -> day = d; month = m; year = y; error = null }, error = error != null)
                    else -> {
                        WaCodeField(code, { code = it; error = null }, error = error != null)
                        WaButton(stringResource(R.string.wa_rec_code_resend), { scope.launch { runCatching { ApiClient.wallet.sendRecoveryEmailCode(walletAuth()) } } }, style = WaButtonStyle.Quiet, modifier = Modifier.padding(top = 8.dp))
                    }
                }
            }

            step == "done" -> WaStatusColumn {
                WaSeal()
                WaStatusTitle(stringResource(R.string.wa_forgot_done_title))
                WaStatusText(stringResource(R.string.wa_forgot_done_text))
                Spacer(Modifier.height(12.dp))
                WaButton(stringResource(R.string.wa_done), { onDone() }, modifier = Modifier.padding(horizontal = 20.dp))
            }

            else -> Box(Modifier.weight(1f).fillMaxWidth().padding(horizontal = 8.dp)) {
                val confirming = step == "confirm"
                WaPinPad(
                    title = stringResource(if (confirming) R.string.wa_pin_confirm_title else R.string.wa_forgot_new_pin_title),
                    sub = stringResource(if (confirming) R.string.wa_pin_confirm_sub else R.string.wa_pin_digits_sub),
                    icon = Icons.Rounded.Shield,
                    modifier = Modifier.fillMaxSize().waRise(0),
                    onComplete = { pin ->
                        if (!confirming) {
                            first = pin
                            step = "confirm"
                            return@WaPinPad PadResult.Reset
                        }
                        if (pin != first) {
                            step = "pin"
                            return@WaPinPad PadResult.Error(mismatch)
                        }
                        val body = when (method) {
                            RecoveryMethodUi.Password -> RecoverPinRequest(password = password, pin = first, pinConfirmation = pin)
                            RecoveryMethodUi.BirthDate -> RecoverPinRequest(birthDate = birthDateOrNull(day, month, year), pin = first, pinConfirmation = pin)
                            else -> RecoverPinRequest(code = code, pin = first, pinConfirmation = pin)
                        }
                        try {
                            ApiClient.wallet.recoverPin(walletAuth(), body)
                        } catch (e: CancellationException) {
                            throw e
                        } catch (e: Exception) {
                            // A wrong password / date / code goes back to the form with the reason; the PIN was never the problem.
                            val failure = e.apiFailure()
                            step = "secret"
                            error = if (failure.httpStatus == null) networkError else failure.message ?: networkError
                            return@WaPinPad PadResult.Reset
                        }
                        step = "done"
                        PadResult.Ok
                    },
                )
            }
        }
    }
}

@Composable
private fun WaIconWellBig(icon: ImageVector, tone: Tone) {
    WaIconWell(icon, tone, size = 84.dp, iconSize = 38.dp)
}

// ------------------------------------------------------------------------------- change the recovery method

/**
 * Switch how a forgotten PIN is recovered. Proves the current PIN first (the server asks for it too), then the same
 * method wizard as at setup — which ends after the method, since the PIN itself stays.
 */
@Composable
fun WaChangeRecoveryPage(onExit: () -> Unit, onDone: () -> Unit) {
    val networkError = stringResource(R.string.wa_error_network)
    var pin by remember { mutableStateOf<String?>(null) }
    val proven = pin

    if (proven != null) {
        WaPinSetupPage(onExit = onExit, onDone = onDone, title = stringResource(R.string.wa_rec_change_title), existingPin = proven)
        return
    }

    WaPage(title = stringResource(R.string.wa_rec_change_title), onBack = onExit, scroll = false) {
        Box(Modifier.weight(1f).fillMaxWidth().padding(horizontal = 8.dp)) {
            WaPinPad(
                title = stringResource(R.string.wa_pin_enter_title),
                sub = stringResource(R.string.wa_rec_change_pin_sub),
                icon = Icons.Rounded.Shield,
                modifier = Modifier.fillMaxSize().waRise(0),
                onComplete = { entered ->
                    try {
                        ApiClient.wallet.verifyPin(walletAuth(), entered)
                    } catch (e: CancellationException) {
                        throw e
                    } catch (e: Exception) {
                        val failure = e.apiFailure()
                        return@WaPinPad PadResult.Error(if (failure.httpStatus == null) networkError else failure.message ?: networkError)
                    }
                    pin = entered
                    PadResult.Ok
                },
            )
        }
    }
}

// ------------------------------------------------------------------------------- a reset PIN must be replaced

/**
 * Shown right after the wallet was unlocked with the PIN 0000 an approved recovery request set: the person
 * picks a real one (twice) before anything else. [current] is the PIN they just typed; [onDone] opens the wallet.
 */
@Composable
fun WaForcePinChangePage(current: String, onExit: () -> Unit, onDone: () -> Unit, initialStep: String = "new") {
    val networkError = stringResource(R.string.wa_error_network)
    val mismatch = stringResource(R.string.wa_pin_mismatch)
    var step by remember { mutableStateOf(initialStep) }
    var first by remember { mutableStateOf("") }

    WaPage(title = stringResource(R.string.wa_force_page_title), onBack = onExit, scroll = false) {
        Box(Modifier.weight(1f).fillMaxWidth().padding(horizontal = 8.dp)) {
            val confirming = step == "confirm"
            WaPinPad(
                title = stringResource(if (confirming) R.string.wa_pin_confirm_title else R.string.wa_force_title),
                sub = stringResource(if (confirming) R.string.wa_pin_confirm_sub else R.string.wa_force_sub),
                icon = Icons.Rounded.Shield,
                modifier = Modifier.fillMaxSize().waRise(0),
                onComplete = { pin ->
                    if (!confirming) {
                        first = pin
                        step = "confirm"
                        return@WaPinPad PadResult.Reset
                    }
                    if (pin != first) {
                        step = "new"
                        return@WaPinPad PadResult.Error(mismatch)
                    }
                    try {
                        ApiClient.wallet.changePin(walletAuth(), ChangePinRequest(current, first, pin))
                    } catch (e: CancellationException) {
                        throw e
                    } catch (e: Exception) {
                        // e.g. "choose a PIN other than 0000": ask again from the first step
                        step = "new"
                        val failure = e.apiFailure()
                        return@WaPinPad PadResult.Error(if (failure.httpStatus == null) networkError else failure.message ?: networkError)
                    }
                    onDone()
                    PadResult.Ok
                },
            )
        }
    }
}

// ------------------------------------------------------------------------------- a device seen for the first time

/**
 * Shown after the right PIN comes from a device (`X-Device-Id`) this wallet has never opened from
 * before (wallet policy bend 3, docs/wallet-tasks.md §10.10). A code goes to the phone on file
 * automatically as this page opens; entering it proves the device, and `wallet/device/confirm`
 * remembers it so this page is skipped next time.
 */
@Composable
fun WaDeviceTrustPage(pin: String, onExit: () -> Unit, onDone: () -> Unit) {
    val networkError = stringResource(R.string.wa_error_network)
    val codeSent = stringResource(R.string.wa_rec_code_sent)
    val scope = rememberCoroutineScope()
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(Unit) {
        runCatching { ApiClient.wallet.sendDeviceTrustCode(walletAuth(), pin) }
    }

    WaPage(title = stringResource(R.string.wa_device_trust_title), onBack = onExit, scroll = false) {
        Box(Modifier.weight(1f).fillMaxWidth().padding(horizontal = 8.dp)) {
            WaPinPad(
                title = stringResource(R.string.wa_device_trust_title),
                sub = stringResource(R.string.wa_device_trust_sub),
                icon = Icons.Rounded.Shield,
                modifier = Modifier.fillMaxSize().waRise(0),
                onComplete = { code ->
                    notice = null
                    try {
                        ApiClient.wallet.confirmDeviceTrust(walletAuth(), pin, RecoveryCodeRequest(code))
                    } catch (e: CancellationException) {
                        throw e
                    } catch (e: Exception) {
                        val failure = e.apiFailure()
                        return@WaPinPad PadResult.Error(if (failure.httpStatus == null) networkError else failure.message ?: networkError)
                    }
                    onDone()
                    PadResult.Ok
                },
            )
        }
        WaButton(
            stringResource(R.string.wa_rec_code_resend),
            {
                scope.launch {
                    try {
                        ApiClient.wallet.sendDeviceTrustCode(walletAuth(), pin)
                        notice = codeSent
                        error = null
                    } catch (e: CancellationException) {
                        throw e
                    } catch (e: Exception) {
                        error = e.apiFailure().message ?: networkError
                    }
                }
            },
            style = WaButtonStyle.Quiet,
        )
        (notice ?: error)?.let {
            Text(it, color = if (error != null) Wa.Danger else Wa.Green, fontSize = 12.5.sp, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp))
        }
    }
}

// ------------------------------------------------------------------------------- a permanent freeze

/**
 * Shown instead of the PIN pad when the wallet is permanently frozen — a wrong attempt right after a
 * temporary lock (point 5, docs/wallet-tasks.md). The only way out: a selfie + an ID/passport photo,
 * reviewed by a person — whatever the owner's configured recovery method actually is.
 */
@Composable
fun WaFrozenPage(status: PinStatusDto?, onExit: () -> Unit, onLifted: () -> Unit) {
    val networkError = stringResource(R.string.wa_error_network)
    val photoRequired = stringResource(R.string.wa_rec_photo_required)
    val photoUnreadable = stringResource(R.string.wa_rec_photo_unreadable)
    val scope = rememberCoroutineScope()

    var request by remember { mutableStateOf(status?.request) }
    var idPhoto by remember { mutableStateOf<PickedPhoto?>(null) }
    var selfie by remember { mutableStateOf<PickedPhoto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }

    fun submit() {
        val id = idPhoto
        val self = selfie
        if (id == null || self == null) {
            error = photoRequired
            return
        }
        scope.launch {
            busy = true
            try {
                val next = ApiClient.wallet.unfreezePin(walletAuth(), id.toPart("id_document"), self.toPart("selfie")).data
                request = next?.request
                error = null
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                error = e.apiFailure().message ?: networkError
            } finally {
                busy = false
            }
        }
    }

    WaPage(title = stringResource(R.string.wa_frozen_title), onBack = onExit, scroll = false) {
        when (request?.status) {
            "pending" -> WaStatusColumn {
                WaIconWellBig(Icons.Rounded.HourglassTop, Tone.Amber)
                WaStatusTitle(stringResource(R.string.wa_frozen_pending_title))
                WaStatusText(stringResource(R.string.wa_frozen_pending_text))
                Spacer(Modifier.height(12.dp))
                WaButton(stringResource(R.string.wa_retry), onLifted, style = WaButtonStyle.Ghost, icon = Icons.Rounded.Refresh, modifier = Modifier.padding(horizontal = 40.dp))
                WaButton(stringResource(R.string.wa_done), onExit, modifier = Modifier.padding(horizontal = 20.dp, vertical = 8.dp))
            }

            else -> WaFormStep(
                cta = {
                    WaError(error)
                    WaButton(stringResource(R.string.wa_forgot_send), { submit() }, loading = busy)
                },
            ) {
                WaStepHeader(Icons.Rounded.Warning, Tone.Red, stringResource(R.string.wa_frozen_title), stringResource(R.string.wa_frozen_sub))
                if (request?.status == "rejected") {
                    WaNote(listOfNotNull(stringResource(R.string.wa_forgot_rejected_title), request?.rejectionReason?.takeIf { it.isNotBlank() }).joinToString(" — "), icon = Icons.Rounded.Warning)
                    Spacer(Modifier.height(12.dp))
                }
                Text(stringResource(R.string.wa_frozen_id_label), fontWeight = FontWeight.ExtraBold, fontSize = 14.sp, color = Wa.Ink, modifier = Modifier.padding(bottom = 8.dp))
                WaPhotoPicker(idPhoto, { idPhoto = it; error = null }, onUnreadable = { error = photoUnreadable })
                Spacer(Modifier.height(18.dp))
                Text(stringResource(R.string.wa_frozen_selfie_label), fontWeight = FontWeight.ExtraBold, fontSize = 14.sp, color = Wa.Ink, modifier = Modifier.padding(bottom = 8.dp))
                WaPhotoPicker(selfie, { selfie = it; error = null }, onUnreadable = { error = photoUnreadable }, useCamera = true)
            }
        }
    }
}

