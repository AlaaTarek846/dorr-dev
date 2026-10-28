package com.dorr.freelance.ui.screens

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.tween
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
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material3.Icon
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.input.key.Key
import androidx.compose.ui.input.key.KeyEventType
import androidx.compose.ui.input.key.key
import androidx.compose.ui.input.key.onPreviewKeyEvent
import androidx.compose.ui.input.key.type
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextRange
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.TextFieldValue
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.BuildConfig
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.OtpRequest
import com.dorr.freelance.net.Session
import com.dorr.freelance.net.VerifyOtpRequest
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.LanguageSwitch
import com.dorr.freelance.ui.kit.AccountDark
import com.dorr.freelance.ui.kit.PinkBackdrop
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsNight
import com.dorr.freelance.ui.theme.AppColors
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

private const val OTP_LENGTH = 4

/**
 * The code sent by SMS. The resend countdown starts from the server's cooldown and runs on the phone's clock — no
 * request is made to ask "can I resend yet". A correct code signs in and keeps the token in [Session].
 */
@Composable
fun OtpScreen(
    dialCode: String,
    phoneNumber: String,
    cooldownSeconds: Int,
    onBack: () -> Unit,
    onVerified: () -> Unit,
) {
    val digits = remember { mutableStateListOf(*Array(OTP_LENGTH) { "" }) }
    val focusRequesters = remember { List(OTP_LENGTH) { FocusRequester() } }
    val scope = rememberCoroutineScope()
    val genericError = stringResource(R.string.otp_error_generic)
    val offlineText = stringResource(R.string.login_error_offline)

    var isVerifying by remember { mutableStateOf(false) }
    var hasError by remember { mutableStateOf(false) }
    var errorMessage by remember { mutableStateOf<String?>(null) }
    var isSuccess by remember { mutableStateOf(false) }
    val shakeOffset = remember { Animatable(0f) }

    var cooldown by remember { mutableIntStateOf(cooldownSeconds) }
    var countdown by remember { mutableIntStateOf(cooldownSeconds) }
    var timerKey by remember { mutableIntStateOf(0) }

    LaunchedEffect(timerKey) {
        countdown = cooldown
        while (countdown > 0) {
            delay(1000)
            countdown--
        }
    }

    suspend fun triggerShake() {
        for (target in listOf(-10f, 10f, -10f, 0f)) shakeOffset.animateTo(target, tween(100))
    }

    fun verify() {
        val code = digits.joinToString("")
        if (code.length < OTP_LENGTH || isVerifying) return
        isVerifying = true
        hasError = false
        errorMessage = null
        scope.launch {
            val result = apiCall {
                ApiClient.auth.verifyOtp(
                    VerifyOtpRequest(dialCode = dialCode, phone = phoneNumber, code = code, deviceId = Session.deviceId, deviceName = ApiClient.deviceName),
                )
            }
            isVerifying = false
            when (result) {
                is ApiResult.Ok -> {
                    val token = result.data.token
                    if (token.isNullOrBlank()) {
                        hasError = true
                        errorMessage = genericError
                    } else {
                        Session.setMe(result.data.freelancer)
                        Session.signIn(token)
                        isSuccess = true
                        delay(300)
                        onVerified()
                    }
                }
                else -> {
                    hasError = true
                    errorMessage = result.errorText(offlineText) ?: genericError
                    triggerShake()
                }
            }
        }
    }

    fun resend() {
        for (i in digits.indices) digits[i] = ""
        hasError = false
        errorMessage = null
        isSuccess = false
        focusRequesters[0].requestFocus()
        scope.launch {
            val result = apiCall { ApiClient.auth.resendOtp(OtpRequest(dialCode = dialCode, phone = phoneNumber)) }
            if (result is ApiResult.Ok) {
                cooldown = result.data.resendCooldownSeconds ?: cooldown
                timerKey++
            } else {
                hasError = true
                errorMessage = result.errorText(offlineText) ?: genericError
            }
        }
    }

    val isComplete = digits.joinToString("").length == OTP_LENGTH
    val borderColor = when {
        isSuccess -> AppColors.success
        hasError -> AppColors.danger
        else -> null
    }
    val night = settingsNight()

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(
            modifier = Modifier
                .fillMaxSize()
                .statusBarsPadding()
                .navigationBarsPadding()
                .imePadding()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 24.dp)
                .padding(top = 10.dp, bottom = 28.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Row(
                modifier = Modifier.fillMaxWidth().widthIn(max = 440.dp),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Box(Modifier.size(36.dp).clip(CircleShape).clickable(onClick = onBack), contentAlignment = Alignment.Center) {
                    Icon(
                        Icons.AutoMirrored.Rounded.ArrowBack,
                        contentDescription = stringResource(R.string.common_back),
                        tint = if (night) AccountDark.accent else Color(0xFF111928),
                        modifier = Modifier.size(22.dp),
                    )
                }
                LanguageSwitch()
            }
            Spacer(Modifier.height(32.dp))

            Column(Modifier.fillMaxWidth().widthIn(max = 440.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                Text(
                    text = stringResource(R.string.otp_title),
                    color = settingsAccent(),
                    fontWeight = FontWeight.ExtraBold,
                    fontSize = 26.sp,
                    textAlign = TextAlign.Center,
                )
                Spacer(Modifier.height(8.dp))
                Text(
                    text = stringResource(R.string.otp_subtitle, listOf(dialCode, phoneNumber).filter { it.isNotBlank() }.joinToString(" ")),
                    fontSize = 14.sp,
                    lineHeight = 22.sp,
                    color = if (night) AccountDark.mut else Color(0xFF6B7280),
                    textAlign = TextAlign.Center,
                    modifier = Modifier.widthIn(max = 280.dp),
                )
                Spacer(Modifier.height(24.dp))

                // The digits are always left-to-right, even in Arabic.
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    Row(
                        modifier = Modifier.fillMaxWidth().graphicsLayer { translationX = shakeOffset.value },
                        horizontalArrangement = Arrangement.spacedBy(8.dp, Alignment.CenterHorizontally),
                    ) {
                        for (i in 0 until OTP_LENGTH) {
                            DigitBox(
                                value = digits[i],
                                onValueChange = { v ->
                                    digits[i] = v
                                    if (v.isNotEmpty()) {
                                        hasError = false
                                        if (i < OTP_LENGTH - 1) focusRequesters[i + 1].requestFocus() else verify()
                                    }
                                },
                                onBackspaceOnEmpty = {
                                    if (i > 0) {
                                        focusRequesters[i - 1].requestFocus()
                                        digits[i - 1] = ""
                                    }
                                },
                                focusRequester = focusRequesters[i],
                                overrideBorderColor = borderColor,
                            )
                        }
                    }
                }

                Spacer(Modifier.height(24.dp))
                SettingsPrimaryButton(text = stringResource(R.string.otp_verify), enabled = isComplete, loading = isVerifying, onClick = ::verify)
                Spacer(Modifier.height(14.dp))

                if (countdown <= 0) {
                    Text(
                        text = stringResource(R.string.otp_resend),
                        color = if (night) AccountDark.accent else settingsAccent(),
                        fontSize = 14.sp,
                        fontWeight = FontWeight.SemiBold,
                        textAlign = TextAlign.Center,
                        modifier = Modifier.clickable(onClick = ::resend),
                    )
                } else {
                    Text(
                        text = stringResource(R.string.otp_resend_in, countdown),
                        fontSize = 12.sp,
                        color = if (night) AccountDark.mut else Color(0xFF9CA3AF),
                        textAlign = TextAlign.Center,
                    )
                }

                ErrorBanner(if (hasError) errorMessage else null, Modifier.fillMaxWidth())

                // A test build accepts a fixed code (config/auth_flow.php); a release build never shows this.
                if (BuildConfig.DEBUG) {
                    Spacer(Modifier.height(16.dp))
                    Text(stringResource(R.string.otp_dev_hint), fontSize = 12.sp, color = Color(0xFFF59E0B), textAlign = TextAlign.Center)
                }
            }
        }
    }

    LaunchedEffect(Unit) { focusRequesters[0].requestFocus() }
}

private const val OTP_MARKER = "​"

/** One digit. A soft keyboard sends no key for Backspace on an empty field, so the box always holds an invisible marker. */
@Composable
private fun DigitBox(
    value: String,
    onValueChange: (String) -> Unit,
    onBackspaceOnEmpty: () -> Unit,
    focusRequester: FocusRequester,
    overrideBorderColor: Color?,
) {
    var isFocused by remember { mutableStateOf(false) }
    val scale = remember { Animatable(1f) }
    val scope = rememberCoroutineScope()
    val night = settingsNight()
    val boxBorderColor = overrideBorderColor ?: if (isFocused) settingsAccent() else if (night) AccountDark.line else Color(0xFFFBD2C4)

    Surface(
        modifier = Modifier.width(44.dp).height(52.dp).scale(scale.value),
        shape = RoundedCornerShape(14.dp),
        color = if (night) AccountDark.card else Color.White,
        border = androidx.compose.foundation.BorderStroke(1.5.dp, boxBorderColor),
    ) {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            val shown = OTP_MARKER + value
            BasicTextField(
                value = TextFieldValue(shown, TextRange(shown.length)),
                onValueChange = { typed ->
                    val new = typed.text
                    if (!new.startsWith(OTP_MARKER)) {
                        // Marker deleted → Backspace: clear this digit, or step back from an empty box.
                        if (value.isEmpty()) onBackspaceOnEmpty() else onValueChange("")
                        return@BasicTextField
                    }
                    val digit = new.filter(Char::isDigit).takeLast(1)
                    onValueChange(digit)
                    if (digit.isNotEmpty()) {
                        scope.launch {
                            scale.animateTo(1.15f, tween(100))
                            scale.animateTo(1f, tween(100))
                        }
                    }
                },
                singleLine = true,
                textStyle = TextStyle(
                    fontSize = 22.sp,
                    fontWeight = FontWeight.SemiBold,
                    color = if (night) AccountDark.ink else Color(0xFF111928),
                    textAlign = TextAlign.Center,
                ),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
                cursorBrush = SolidColor(settingsAccent()),
                modifier = Modifier
                    .fillMaxWidth()
                    .focusRequester(focusRequester)
                    .onFocusChanged { isFocused = it.isFocused }
                    .onPreviewKeyEvent { event ->
                        if (event.type == KeyEventType.KeyDown && event.key == Key.Backspace && value.isEmpty()) {
                            onBackspaceOnEmpty()
                            true
                        } else {
                            false
                        }
                    },
                decorationBox = { inner -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { inner() } },
            )
        }
    }
}
