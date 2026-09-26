package com.dorr.app.ui.screens.profile

import android.content.Context
import android.widget.Toast
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
import com.dorr.app.ui.theme.AppColors
import kotlinx.coroutines.delay

private enum class PdSub { NONE, NAME, GENDER, PHONE, EMAIL }

private const val PD_PREFS = "dorr_profile"
private const val PD_GENDER = "gender"
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

private fun formatDial(value: String): String {
    val digits = value.trim().removePrefix("+")
    return if (digits.isEmpty()) "" else "+$digits"
}

@Composable
fun PersonalDataScreen(onBack: () -> Unit, onSaved: (String) -> Unit) {
    var sub by remember { mutableStateOf(PdSub.NONE) }
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
    val user = remember(refreshKey) { AuthSession.user }
    val gender = remember(refreshKey) { loadGender(context) }
    val genderLabel = when (gender) {
        "male" -> stringResource(R.string.gender_male)
        "female" -> stringResource(R.string.gender_female)
        else -> stringResource(R.string.gender_unspecified)
    }
    val emailVerified = !user?.email.isNullOrBlank()

    PdScreen(title = stringResource(R.string.personal_data_title), onBack = onBack) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 14.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Box(
                modifier = Modifier
                    .padding(top = 6.dp, bottom = 14.dp)
                    .size(92.dp)
                    .align(Alignment.CenterHorizontally),
            ) {
                Box(
                    modifier = Modifier
                        .fillMaxSize()
                        .shadow(8.dp, CircleShape, ambientColor = Color(0x1FE50914), spotColor = Color(0x1FE50914))
                        .clip(CircleShape)
                        .background(Color.White),
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(Icons.Rounded.Person, contentDescription = null, tint = AppColors.waRed, modifier = Modifier.size(44.dp))
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
    val enterName = stringResource(R.string.toast_enter_name)
    val updated = stringResource(R.string.toast_name_updated)

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
            }
            PdSaveButton(label = stringResource(R.string.common_save)) {
                if (name.trim().length < 2) {
                    Toast.makeText(context, enterName, Toast.LENGTH_SHORT).show()
                    return@PdSaveButton
                }
                AuthSession.user = AuthSession.user?.copy(name = name.trim())
                onSaved(updated)
                onBack()
            }
        }
    }
}

@Composable
private fun EditGenderScreen(onBack: () -> Unit, onSaved: (String) -> Unit) {
    val context = LocalContext.current
    var gender by remember { mutableStateOf(loadGender(context)) }
    val pickGender = stringResource(R.string.toast_pick_gender)
    val updated = stringResource(R.string.toast_gender_updated)

    PdScreen(title = stringResource(R.string.edit_gender_title), onBack = onBack) {
        FormColumn {
            PdFormCard {
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
                Hint(stringResource(R.string.edit_gender_hint))
            }
            PdSaveButton(label = stringResource(R.string.common_save)) {
                if (gender != "male" && gender != "female") {
                    Toast.makeText(context, pickGender, Toast.LENGTH_SHORT).show()
                    return@PdSaveButton
                }
                saveGender(context, gender)
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

@Composable
private fun EditPhoneScreen(onBack: () -> Unit, onSaved: (String) -> Unit) {
    var dial by remember { mutableStateOf("+966") }
    var flag by remember { mutableStateOf("sa") }
    var phone by remember { mutableStateOf("") }
    var phoneLength by remember { mutableIntStateOf(9) }
    var stepOtp by remember { mutableStateOf(false) }
    var countries by remember { mutableStateOf<List<CountryDto>>(emptyList()) }
    var menuOpen by remember { mutableStateOf(false) }
    val context = LocalContext.current
    val invalid = stringResource(R.string.toast_valid_phone)
    val updated = stringResource(R.string.toast_phone_updated)

    LaunchedEffect(Unit) {
        val listed = runCatching { ApiClient.countries.list().data.orEmpty() }.getOrDefault(emptyList())
        countries = listed
        val picked = listed.find { it.isDefault } ?: listed.find { it.code.equals("sa", true) } ?: listed.firstOrNull()
        if (picked != null) {
            dial = formatDial(picked.dialCode).ifBlank { dial }
            flag = picked.flag?.code?.lowercase()?.takeIf { it.length == 2 } ?: picked.code.lowercase().take(2)
            phoneLength = picked.phoneLength ?: phoneLength
        }
    }

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
    PdScreen(title = stringResource(R.string.edit_phone_title), onBack = onBack) {
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
                    countries = countries,
                    menuOpen = menuOpen,
                    onMenuOpen = { menuOpen = it },
                    onCountry = { country ->
                        dial = formatDial(country.dialCode).ifBlank { dial }
                        flag = country.flag?.code?.lowercase()?.takeIf { it.length == 2 }
                            ?: country.code.lowercase().take(2)
                        phoneLength = country.phoneLength ?: phoneLength
                        phone = phone.take(phoneLength)
                        menuOpen = false
                    },
                    onPhone = { phone = it.filter(Char::isDigit).take(phoneLength) },
                )
                Hint(stringResource(R.string.edit_phone_hint))
            }
            PdSaveButton(label = stringResource(R.string.pd_send_code)) {
                if (phone.length < 7) {
                    Toast.makeText(context, invalid, Toast.LENGTH_SHORT).show()
                    return@PdSaveButton
                }
                stepOtp = true
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

@Composable
private fun PdOtpStep(title: String, target: String, onBack: () -> Unit, onVerified: () -> Unit) {
    val context = LocalContext.current
    var digits by remember { mutableStateOf(List(6) { "" }) }
    var countdown by remember { mutableIntStateOf(60) }
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
                                        if (index < 5) focusers[index + 1].requestFocus()
                                        val code = digits.toMutableList().also { it[index] = d }.joinToString("")
                                        if (code.length == 6) verify(code)
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
                                digits = List(6) { "" }
                                hasError = false
                                countdown = 60
                                Toast.makeText(context, codeSent, Toast.LENGTH_SHORT).show()
                                focusers[0].requestFocus()
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
                enabled = code.length == 6,
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
    countries: List<CountryDto>,
    menuOpen: Boolean,
    onMenuOpen: (Boolean) -> Unit,
    onCountry: (CountryDto) -> Unit,
    onPhone: (String) -> Unit,
) {
    var focused by remember { mutableStateOf(false) }
    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
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
            Icon(Icons.Rounded.Phone, contentDescription = null, tint = Color(0xFF9CA3AF), modifier = Modifier.size(18.dp))
            PhoneSep()
            Box {
                Row(
                    modifier = Modifier.clickable(enabled = countries.isNotEmpty()) { onMenuOpen(true) },
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    PdFlag(flag)
                    Spacer(Modifier.width(6.dp))
                    Text(dial, fontSize = 14.sp, fontWeight = FontWeight.Bold, color = AppColors.textPrimary)
                    Spacer(Modifier.width(2.dp))
                    Icon(Icons.Rounded.KeyboardArrowDown, contentDescription = null, tint = AppColors.waRed, modifier = Modifier.size(16.dp))
                }
                DropdownMenu(
                    expanded = menuOpen,
                    onDismissRequest = { onMenuOpen(false) },
                    containerColor = Color.White,
                ) {
                    countries.forEach { country ->
                        DropdownMenuItem(
                            text = {
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    PdFlag(country.flag?.code ?: country.code)
                                    Spacer(Modifier.width(8.dp))
                                    Text(country.name, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                                    Spacer(Modifier.width(8.dp))
                                    Text(formatDial(country.dialCode), fontWeight = FontWeight.Bold)
                                }
                            },
                            onClick = { onCountry(country) },
                        )
                    }
                }
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
                            Text("0".repeat(phoneLength.coerceAtLeast(1)), color = Color(0xFFC5CAD3), fontSize = 14.sp)
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
