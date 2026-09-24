package com.dorr.app.ui.screens

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
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
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import androidx.compose.ui.draw.scale
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.input.key.Key
import androidx.compose.ui.input.key.KeyEventType
import androidx.compose.ui.input.key.key
import androidx.compose.ui.input.key.onPreviewKeyEvent
import androidx.compose.ui.input.key.type
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.OtpRequest
import com.dorr.app.network.VerifyOtpRequest
import com.dorr.app.network.serverMessage
import com.dorr.app.ui.theme.AppColors
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

private const val OTP_LENGTH = 6

@Composable
fun OtpScreen(dialCode: String, phoneNumber: String, onBack: () -> Unit, onVerified: () -> Unit) {
    val digits = remember { mutableStateListOf(*Array(OTP_LENGTH) { "" }) }
    val focusRequesters = remember { List(OTP_LENGTH) { FocusRequester() } }
    val scope = rememberCoroutineScope()
    val genericOtpError = stringResource(R.string.otp_error_generic)

    var isVerifying by remember { mutableStateOf(false) }
    var hasError by remember { mutableStateOf(false) }
    var errorMessage by remember { mutableStateOf<String?>(null) }
    var isSuccess by remember { mutableStateOf(false) }
    val shakeOffset = remember { Animatable(0f) }

    var countdown by remember { mutableIntStateOf(60) }
    var canResend by remember { mutableStateOf(false) }
    var timerKey by remember { mutableIntStateOf(0) }

    LaunchedEffect(timerKey) {
        countdown = 60
        canResend = false
        while (countdown > 0) {
            delay(1000)
            countdown--
        }
        canResend = true
    }

    suspend fun triggerShake() {
        for (target in listOf(-10f, 10f, -10f, 0f)) {
            shakeOffset.animateTo(target, tween(100))
        }
    }

    fun verify() {
        val code = digits.joinToString("")
        if (code.length < OTP_LENGTH || isVerifying) return
        isVerifying = true
        hasError = false
        errorMessage = null
        scope.launch {
            val result = runCatching {
                ApiClient.mobileAuth.verifyOtp(
                    VerifyOtpRequest(dialCode = dialCode, phone = phoneNumber, code = code),
                ).data
            }
            isVerifying = false
            result.onSuccess { data ->
                AuthSession.token = data?.token
                AuthSession.user = data?.user
                isSuccess = true
                delay(300)
                onVerified()
            }.onFailure {
                hasError = true
                errorMessage = it.serverMessage() ?: genericOtpError
                triggerShake()
            }
        }
    }

    fun onDigitChanged(index: Int, value: String) {
        if (value.isNotEmpty()) {
            hasError = false
            if (index < OTP_LENGTH - 1) {
                focusRequesters[index + 1].requestFocus()
            } else {
                verify()
            }
        }
    }

    fun resend() {
        for (i in digits.indices) digits[i] = ""
        hasError = false
        errorMessage = null
        isSuccess = false
        timerKey++
        focusRequesters[0].requestFocus()
        scope.launch {
            runCatching {
                ApiClient.mobileAuth.resendOtp(
                    OtpRequest(dialCode = dialCode, phone = phoneNumber),
                )
            }.onFailure {
                hasError = true
                errorMessage = it.serverMessage() ?: genericOtpError
            }
        }
    }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(24.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        IconButton(onClick = onBack, modifier = Modifier.align(Alignment.Start)) {
            Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = stringResource(R.string.common_back))
        }
        Spacer(Modifier.height(12.dp))

        Text(stringResource(R.string.otp_title), style = MaterialTheme.typography.headlineLarge, textAlign = TextAlign.Center)
        Spacer(Modifier.height(8.dp))
        Text(
            stringResource(R.string.otp_subtitle, listOf(dialCode, phoneNumber).filter { it.isNotBlank() }.joinToString(" ")),
            style = MaterialTheme.typography.bodyMedium,
            color = AppColors.textSecondary,
            textAlign = TextAlign.Center,
        )
        Spacer(Modifier.height(40.dp))

        val borderColor = when {
            isSuccess -> AppColors.success
            hasError -> AppColors.danger
            else -> null // per-box: primary when focused, border otherwise
        }

        // OTP digits always read left-to-right regardless of app language.
        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
            Row(
                modifier = Modifier.graphicsLayer { translationX = shakeOffset.value },
                horizontalArrangement = Arrangement.Center,
            ) {
                for (i in 0 until OTP_LENGTH) {
                    DigitBox(
                        value = digits[i],
                        onValueChange = { v ->
                            digits[i] = v
                            onDigitChanged(i, v)
                        },
                        onBackspaceOnEmpty = {
                            if (i > 0) {
                                focusRequesters[i - 1].requestFocus()
                                digits[i - 1] = ""
                            }
                        },
                        focusRequester = focusRequesters[i],
                        overrideBorderColor = borderColor,
                        highlightFill = isSuccess || hasError,
                    )
                }
            }
        }

        Spacer(Modifier.height(32.dp))
        TimerOrResend(countdown = countdown, canResend = canResend, onResend = ::resend)

        Spacer(Modifier.height(12.dp))
        val message = errorMessage
        if (hasError && message != null) {
            Text(
                message,
                style = MaterialTheme.typography.bodyMedium,
                color = AppColors.danger,
                textAlign = TextAlign.Center,
            )
        }

        Spacer(Modifier.weight(1f))

        Button(
            onClick = ::verify,
            enabled = !isVerifying && digits.joinToString("").length == OTP_LENGTH,
            modifier = Modifier
                .fillMaxWidth()
                .height(52.dp),
        ) {
            if (isVerifying) {
                CircularProgressIndicator(modifier = Modifier.size(20.dp), strokeWidth = 2.dp)
            } else {
                Text(stringResource(R.string.otp_verify))
            }
        }
        Spacer(Modifier.height(12.dp))
        Text(
            stringResource(R.string.otp_dev_hint),
            style = MaterialTheme.typography.bodySmall,
            color = AppColors.warning,
            textAlign = TextAlign.Center,
        )
    }

    LaunchedEffect(Unit) {
        focusRequesters[0].requestFocus()
    }
}

@Composable
private fun DigitBox(
    value: String,
    onValueChange: (String) -> Unit,
    onBackspaceOnEmpty: () -> Unit,
    focusRequester: FocusRequester,
    overrideBorderColor: Color?,
    highlightFill: Boolean,
) {
    val scale = remember { Animatable(1f) }
    val scope = rememberCoroutineScope()

    OutlinedTextField(
        value = value,
        onValueChange = { new ->
            val digit = new.filter(Char::isDigit).takeLast(1)
            onValueChange(digit)
            if (digit.isNotEmpty()) {
                scope.launch {
                    scale.animateTo(1.15f, tween(120))
                    scale.animateTo(1f, tween(120))
                }
            }
        },
        singleLine = true,
        textStyle = MaterialTheme.typography.headlineMedium.copy(textAlign = TextAlign.Center),
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
        shape = RoundedCornerShape(12.dp),
        colors = OutlinedTextFieldDefaults.colors(
            unfocusedBorderColor = overrideBorderColor ?: AppColors.border,
            focusedBorderColor = overrideBorderColor ?: AppColors.primary,
        ),
        modifier = Modifier
            .padding(horizontal = 4.dp)
            .width(48.dp)
            .height(56.dp)
            .scale(scale.value)
            .focusRequester(focusRequester)
            .onPreviewKeyEvent { event ->
                if (event.type == KeyEventType.KeyDown &&
                    event.key == Key.Backspace &&
                    value.isEmpty()
                ) {
                    onBackspaceOnEmpty()
                    true
                } else {
                    false
                }
            }
            .let {
                if (highlightFill) {
                    it.background(
                        (overrideBorderColor ?: AppColors.border).copy(alpha = 0.05f),
                        RoundedCornerShape(12.dp),
                    )
                } else {
                    it
                }
            },
    )
}

@Composable
private fun TimerOrResend(countdown: Int, canResend: Boolean, onResend: () -> Unit) {
    if (canResend) {
        TextButton(onClick = onResend) {
            Text(
                stringResource(R.string.otp_resend),
                style = MaterialTheme.typography.titleMedium,
                color = AppColors.primary,
            )
        }
        return
    }

    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        Box(modifier = Modifier.size(56.dp), contentAlignment = Alignment.Center) {
            CircularProgressIndicator(
                progress = { countdown / 60f },
                strokeWidth = 3.dp,
                color = AppColors.primary,
                trackColor = AppColors.border,
                modifier = Modifier.fillMaxSize(),
            )
            Text("$countdown", style = MaterialTheme.typography.titleMedium)
        }
        Spacer(Modifier.height(8.dp))
        Text(
            stringResource(R.string.otp_resend_in, countdown),
            style = MaterialTheme.typography.bodySmall,
            color = AppColors.textMuted,
        )
    }
}
