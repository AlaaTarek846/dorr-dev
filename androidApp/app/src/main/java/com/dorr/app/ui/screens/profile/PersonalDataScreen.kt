package com.dorr.app.ui.screens.profile

import android.content.Context
import android.widget.Toast
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
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.Email
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Phone
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
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
import com.dorr.app.R
import com.dorr.app.network.AuthSession
import com.dorr.app.ui.components.SettingsScaffold
import com.dorr.app.ui.theme.AppColors
import kotlinx.coroutines.delay

private enum class PdSub { NONE, NAME, GENDER, PHONE, EMAIL }

private const val PD_PREFS = "dorr_profile"
private const val PD_GENDER = "gender"

private fun loadGender(context: Context): String =
    context.getSharedPreferences(PD_PREFS, Context.MODE_PRIVATE).getString(PD_GENDER, "").orEmpty()

private fun saveGender(context: Context, gender: String) {
    context.getSharedPreferences(PD_PREFS, Context.MODE_PRIVATE).edit().putString(PD_GENDER, gender).apply()
}

@Composable
fun PersonalDataScreen(onBack: () -> Unit, onSaved: (String) -> Unit) {
    var sub by remember { mutableStateOf(PdSub.NONE) }
    // Bump to refresh hub rows after a nested edit saves.
    var refresh by remember { mutableIntStateOf(0) }

    when (sub) {
        PdSub.NAME -> EditNameScreen(onBack = { sub = PdSub.NONE }, onSaved = { onSaved(it); refresh++ })
        PdSub.GENDER -> EditGenderScreen(onBack = { sub = PdSub.NONE }, onSaved = { onSaved(it); refresh++ })
        PdSub.PHONE -> EditPhoneScreen(onBack = { sub = PdSub.NONE }, onSaved = { onSaved(it); refresh++ })
        PdSub.EMAIL -> EditEmailScreen(onBack = { sub = PdSub.NONE }, onSaved = { onSaved(it); refresh++ })
        PdSub.NONE -> PdHub(onBack = onBack, refreshKey = refresh, onOpen = { sub = it })
    }
}

@Composable
private fun PdHub(onBack: () -> Unit, refreshKey: Int, onOpen: (PdSub) -> Unit) {
    val context = LocalContext.current
    val notAdded = stringResource(R.string.pd_not_added)
    // Read on every refresh so nested saves repaint immediately.
    val user = remember(refreshKey) { AuthSession.user }
    val gender = remember(refreshKey) { loadGender(context) }
    val genderLabel = when (gender) {
        "male" -> stringResource(R.string.gender_male)
        "female" -> stringResource(R.string.gender_female)
        else -> stringResource(R.string.gender_unspecified)
    }
    val emailVerified = !user?.email.isNullOrBlank()

    SettingsScaffold(title = stringResource(R.string.personal_data_title), onBack = onBack) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(horizontal = 20.dp),
        ) {
            Spacer(Modifier.height(12.dp))
            Box(
                modifier = Modifier
                    .align(Alignment.CenterHorizontally)
                    .size(88.dp)
                    .shadow(8.dp, CircleShape, spotColor = AppColors.waRed.copy(alpha = 0.1f))
                    .clip(CircleShape)
                    .background(Color.White),
                contentAlignment = Alignment.Center,
            ) {
                Icon(Icons.Rounded.Person, contentDescription = null, tint = AppColors.waRed, modifier = Modifier.size(44.dp))
            }
            Spacer(Modifier.height(16.dp))
            PdFieldRow(
                icon = Icons.Rounded.Person,
                label = stringResource(R.string.pd_name),
                value = user?.name?.takeIf { it.isNotBlank() } ?: notAdded,
                verified = false,
                onClick = { onOpen(PdSub.NAME) },
            )
            PdFieldRow(
                icon = Icons.Rounded.Person,
                label = stringResource(R.string.pd_gender),
                value = genderLabel,
                verified = false,
                onClick = { onOpen(PdSub.GENDER) },
            )
            PdFieldRow(
                icon = Icons.Rounded.Phone,
                label = stringResource(R.string.pd_phone),
                value = user?.phone?.takeIf { it.isNotBlank() } ?: notAdded,
                verified = user?.phoneVerifiedAt != null,
                ltr = true,
                onClick = { onOpen(PdSub.PHONE) },
            )
            PdFieldRow(
                icon = Icons.Rounded.Email,
                label = stringResource(R.string.pd_email),
                value = user?.email?.takeIf { it.isNotBlank() } ?: notAdded,
                verified = emailVerified,
                ltr = true,
                onClick = { onOpen(PdSub.EMAIL) },
            )
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
    onClick: () -> Unit,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = 4.dp)
            .shadow(6.dp, RoundedCornerShape(14.dp), spotColor = AppColors.waRed.copy(alpha = 0.07f))
            .clip(RoundedCornerShape(14.dp))
            .background(Color.White)
            .clickable(onClick = onClick)
            .padding(horizontal = 12.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            modifier = Modifier
                .size(34.dp)
                .clip(RoundedCornerShape(10.dp))
                .background(Color(0xFFFDE8EC)),
            contentAlignment = Alignment.Center,
        ) {
            Icon(icon, contentDescription = null, tint = AppColors.waRed, modifier = Modifier.size(18.dp))
        }
        Spacer(Modifier.width(10.dp))
        Column(modifier = Modifier.weight(1f)) {
            Text(label, fontSize = 12.sp, fontWeight = FontWeight.SemiBold, color = AppColors.textMuted)
            Row(verticalAlignment = Alignment.CenterVertically) {
                val valueModifier = Modifier.weight(1f, fill = false)
                if (ltr) {
                    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                        Text(
                            value,
                            fontSize = 14.sp,
                            fontWeight = FontWeight.Bold,
                            color = AppColors.textPrimary,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                            modifier = valueModifier,
                        )
                    }
                } else {
                    Text(
                        value,
                        fontSize = 14.sp,
                        fontWeight = FontWeight.Bold,
                        color = AppColors.textPrimary,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                        modifier = valueModifier,
                    )
                }
                if (verified) {
                    Spacer(Modifier.width(6.dp))
                    Box(
                        modifier = Modifier
                            .size(15.dp)
                            .clip(CircleShape)
                            .background(AppColors.waRed),
                        contentAlignment = Alignment.Center,
                    ) {
                        Icon(Icons.Rounded.Check, contentDescription = null, tint = Color.White, modifier = Modifier.size(10.dp))
                    }
                }
            }
        }
        Icon(
            Icons.Rounded.ChevronRight,
            contentDescription = null,
            tint = AppColors.otpGlowDeep,
            modifier = Modifier.size(16.dp),
        )
    }
}

@Composable
private fun PdFormCard(content: @Composable () -> Unit) {
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .shadow(8.dp, RoundedCornerShape(18.dp), spotColor = AppColors.waRed.copy(alpha = 0.07f))
            .clip(RoundedCornerShape(18.dp))
            .background(Color.White)
            .padding(16.dp),
    ) {
        content()
    }
}

@Composable
private fun PdSaveButton(label: String, enabled: Boolean = true, onClick: () -> Unit) {
    Button(
        onClick = onClick,
        enabled = enabled,
        colors = ButtonDefaults.buttonColors(
            containerColor = AppColors.waRed,
            disabledContainerColor = AppColors.waRed.copy(alpha = 0.4f),
            contentColor = Color.White,
            disabledContentColor = Color.White,
        ),
        shape = RoundedCornerShape(50),
        modifier = Modifier
            .fillMaxWidth()
            .height(50.dp)
            .shadow(8.dp, RoundedCornerShape(50), spotColor = AppColors.waRed.copy(alpha = 0.22f)),
    ) {
        Text(label, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
private fun EditNameScreen(onBack: () -> Unit, onSaved: (String) -> Unit) {
    val context = LocalContext.current
    var name by remember { mutableStateOf(AuthSession.user?.name.orEmpty()) }
    val enterName = stringResource(R.string.toast_enter_name)
    val updated = stringResource(R.string.toast_name_updated)

    SettingsScaffold(title = stringResource(R.string.edit_name_title), onBack = onBack) { padding ->
        Column(modifier = Modifier.fillMaxSize().padding(padding).padding(horizontal = 20.dp)) {
            Spacer(Modifier.height(12.dp))
            PdFormCard {
                Text(fontSize = 12.sp, fontWeight = FontWeight.SemiBold, color = AppColors.textMuted, text = stringResource(R.string.edit_name_label))
                Spacer(Modifier.height(8.dp))
                OutlinedTextField(
                    value = name,
                    onValueChange = { name = it },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                Spacer(Modifier.height(8.dp))
                Text(stringResource(R.string.edit_name_hint), fontSize = 12.sp, color = AppColors.textSecondary)
            }
            Spacer(Modifier.height(20.dp))
            PdSaveButton(label = stringResource(R.string.common_save)) {
                if (name.trim().length < 2) {
                    Toast.makeText(context, enterName, Toast.LENGTH_SHORT).show()
                    return@PdSaveButton
                }
                AuthSession.user = AuthSession.user?.copy(name = name.trim())
                onSaved(updated)
                onBack()
            }
            Spacer(Modifier.height(20.dp))
        }
    }
}

@Composable
private fun EditGenderScreen(onBack: () -> Unit, onSaved: (String) -> Unit) {
    val context = LocalContext.current
    var gender by remember { mutableStateOf(loadGender(context)) }
    val pickGender = stringResource(R.string.toast_pick_gender)
    val updated = stringResource(R.string.toast_gender_updated)

    SettingsScaffold(title = stringResource(R.string.edit_gender_title), onBack = onBack) { padding ->
        Column(modifier = Modifier.fillMaxSize().padding(padding).padding(horizontal = 20.dp)) {
            Spacer(Modifier.height(12.dp))
            PdFormCard {
                GenderOption(
                    label = stringResource(R.string.gender_male),
                    selected = gender == "male",
                    onClick = { gender = "male" },
                )
                GenderOption(
                    label = stringResource(R.string.gender_female),
                    selected = gender == "female",
                    onClick = { gender = "female" },
                )
                Spacer(Modifier.height(8.dp))
                Text(stringResource(R.string.edit_gender_hint), fontSize = 12.sp, color = AppColors.textSecondary)
            }
            Spacer(Modifier.height(20.dp))
            PdSaveButton(label = stringResource(R.string.common_save)) {
                if (gender != "male" && gender != "female") {
                    Toast.makeText(context, pickGender, Toast.LENGTH_SHORT).show()
                    return@PdSaveButton
                }
                saveGender(context, gender)
                onSaved(updated)
                onBack()
            }
            Spacer(Modifier.height(20.dp))
        }
    }
}

@Composable
private fun GenderOption(label: String, selected: Boolean, onClick: () -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick)
            .padding(vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        RadioButton(selected = selected, onClick = onClick)
        Spacer(Modifier.width(8.dp))
        Text(label, fontSize = 14.sp, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
private fun EditPhoneScreen(onBack: () -> Unit, onSaved: (String) -> Unit) {
    var dial by remember { mutableStateOf("+966") }
    var phone by remember { mutableStateOf("") }
    var stepOtp by remember { mutableStateOf(false) }
    val context = LocalContext.current
    val invalid = stringResource(R.string.toast_valid_phone)
    val updated = stringResource(R.string.toast_phone_updated)

    if (stepOtp) {
        PdOtpStep(
            title = stringResource(R.string.edit_phone_title),
            target = "$dial $phone",
            onBack = { stepOtp = false },
            onVerified = {
                AuthSession.user = AuthSession.user?.copy(phone = "$dial$phone")
                onSaved(updated)
                onBack()
            },
        )
        return
    }
    SettingsScaffold(title = stringResource(R.string.edit_phone_title), onBack = onBack) { padding ->
        Column(modifier = Modifier.fillMaxSize().padding(padding).padding(horizontal = 20.dp)) {
            Spacer(Modifier.height(12.dp))
            PdFormCard {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    OutlinedTextField(
                        value = dial,
                        onValueChange = { dial = it.filter { c -> c.isDigit() || c == '+' }.take(6) },
                        label = { Text("+") },
                        singleLine = true,
                        modifier = Modifier.width(110.dp),
                    )
                    Spacer(Modifier.width(12.dp))
                    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                        OutlinedTextField(
                            value = phone,
                            onValueChange = { phone = it.filter(Char::isDigit).take(12) },
                            label = { Text(stringResource(R.string.edit_phone_label)) },
                            singleLine = true,
                            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
                            modifier = Modifier.weight(1f),
                        )
                    }
                }
                Spacer(Modifier.height(8.dp))
                Text(stringResource(R.string.edit_phone_hint), fontSize = 12.sp, color = AppColors.textSecondary)
            }
            Spacer(Modifier.height(20.dp))
            PdSaveButton(label = stringResource(R.string.common_save)) {
                if (phone.length < 7) {
                    Toast.makeText(context, invalid, Toast.LENGTH_SHORT).show()
                    return@PdSaveButton
                }
                stepOtp = true
            }
            Spacer(Modifier.height(20.dp))
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
    SettingsScaffold(title = stringResource(R.string.edit_email_title), onBack = onBack) { padding ->
        Column(modifier = Modifier.fillMaxSize().padding(padding).padding(horizontal = 20.dp)) {
            Spacer(Modifier.height(12.dp))
            PdFormCard {
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    OutlinedTextField(
                        value = email,
                        onValueChange = { email = it },
                        label = { Text(stringResource(R.string.edit_email_label)) },
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
                        modifier = Modifier.fillMaxWidth(),
                    )
                }
                Spacer(Modifier.height(8.dp))
                Text(stringResource(R.string.edit_email_hint), fontSize = 12.sp, color = AppColors.textSecondary)
            }
            Spacer(Modifier.height(20.dp))
            PdSaveButton(label = stringResource(R.string.common_save)) {
                val value = email.trim()
                if (!value.contains("@") || !value.contains(".")) {
                    Toast.makeText(context, invalid, Toast.LENGTH_SHORT).show()
                    return@PdSaveButton
                }
                stepOtp = true
            }
            Spacer(Modifier.height(20.dp))
        }
    }
}

/**
 * Compact 6-box OTP check mirroring the preview's profile OTP step: the demo
 * code 123456 verifies locally (same as the preview's DEV_FIXED_OTP), with a
 * 60s countdown then resend.
 */
@Composable
private fun PdOtpStep(title: String, target: String, onBack: () -> Unit, onVerified: () -> Unit) {
    val context = LocalContext.current
    var digits by remember { mutableStateOf(List(6) { "" }) }
    var countdown by remember { mutableStateOf(60) }
    var hasError by remember { mutableStateOf(false) }
    val wrongCode = stringResource(R.string.toast_wrong_code)
    val codeSent = stringResource(R.string.toast_code_sent)
    val focusers = remember { List(6) { FocusRequester() } }

    LaunchedEffect(countdown) {
        if (countdown > 0) {
            delay(1000)
            countdown--
        }
    }

    fun verify(code: String) {
        if (code.length != 6) return
        if (code == "123456") {
            onVerified()
        } else {
            hasError = true
            Toast.makeText(context, wrongCode, Toast.LENGTH_SHORT).show()
        }
    }

    SettingsScaffold(title = title, onBack = onBack) { padding ->
        Column(
            modifier = Modifier.fillMaxSize().padding(padding).padding(horizontal = 20.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Spacer(Modifier.height(12.dp))
            PdFormCard {
                Text(
                    stringResource(R.string.profile_otp_hint, target),
                    fontSize = 13.sp,
                    color = AppColors.textSecondary,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth(),
                )
                Spacer(Modifier.height(16.dp))
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    Row(
                        horizontalArrangement = Arrangement.Center,
                        modifier = Modifier.fillMaxWidth(),
                    ) {
                        digits.forEachIndexed { index, digit ->
                            OutlinedTextField(
                                value = digit,
                                onValueChange = { v ->
                                    val d = v.filter(Char::isDigit).takeLast(1)
                                    digits = digits.toMutableList().also { it[index] = d }
                                    hasError = false
                                    if (d.isNotEmpty()) {
                                        if (index < 5) focusers[index + 1].requestFocus()
                                        val code = digits.joinToString("")
                                        if (code.length == 6) verify(code)
                                    }
                                },
                                singleLine = true,
                                textStyle = MaterialTheme.typography.titleMedium.copy(textAlign = TextAlign.Center),
                                shape = RoundedCornerShape(12.dp),
                                colors = OutlinedTextFieldDefaults.colors(
                                    unfocusedBorderColor = if (hasError) AppColors.danger else AppColors.border,
                                    focusedBorderColor = if (hasError) AppColors.danger else AppColors.waRed,
                                ),
                                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
                                modifier = Modifier
                                    .padding(horizontal = 3.dp)
                                    .width(44.dp)
                                    .focusRequester(focusers[index]),
                            )
                        }
                    }
                }
                Spacer(Modifier.height(12.dp))
                if (countdown > 0) {
                    Text(
                        stringResource(R.string.profile_otp_timer, countdown),
                        fontSize = 12.sp,
                        color = AppColors.textMuted,
                        textAlign = TextAlign.Center,
                        modifier = Modifier.fillMaxWidth(),
                    )
                } else {
                    Text(
                        stringResource(R.string.profile_otp_resend),
                        fontSize = 14.sp,
                        fontWeight = FontWeight.SemiBold,
                        color = AppColors.waRed,
                        textAlign = TextAlign.Center,
                        modifier = Modifier
                            .fillMaxWidth()
                            .clickable {
                                digits = List(6) { "" }
                                hasError = false
                                countdown = 60
                                Toast.makeText(context, codeSent, Toast.LENGTH_SHORT).show()
                                focusers[0].requestFocus()
                            },
                    )
                }
            }
            Spacer(Modifier.height(20.dp))
            val code = digits.joinToString("")
            PdSaveButton(
                label = stringResource(R.string.profile_otp_confirm),
                enabled = code.length == 6,
            ) { verify(code) }
            Spacer(Modifier.height(12.dp))
            Text(
                stringResource(R.string.profile_otp_dev_hint),
                fontSize = 12.sp,
                color = AppColors.warning,
                textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(20.dp))
        }
    }
    LaunchedEffect(Unit) { focusers[0].requestFocus() }
}
