package com.dorr.app.ui.screens

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.tween
import androidx.compose.animation.animateColorAsState
import androidx.compose.foundation.border
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.foundation.Canvas
import androidx.compose.material3.ButtonDefaults
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.unit.sp
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
            .drawBehind {
                // 1:1 port of #screen-otp's pink radial-gradient background.
                drawRect(Color.White)
                drawRect(
                    Brush.radialGradient(
                        colors = listOf(AppColors.otpGlowDeep, AppColors.otpGlowSoft, Color.Transparent),
                        center = Offset(size.width * -0.08f, size.height * -0.12f),
                        radius = size.width * 1.3f,
                    ),
                )
                drawRect(
                    Brush.radialGradient(
                        colors = listOf(AppColors.otpPinkBorder, Color.Transparent),
                        center = Offset(size.width * 0.5f, size.height * -0.18f),
                        radius = size.width * 1.1f,
                    ),
                )
                drawRect(
                    Brush.radialGradient(
                        colors = listOf(AppColors.otpGlowMist, Color.Transparent),
                        center = Offset(size.width * 1.12f, size.height * -0.08f),
                        radius = size.width * 0.9f,
                    ),
                )
            }
            .padding(horizontal = 22.dp, vertical = 18.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        IconButton(onClick = onBack, modifier = Modifier.align(Alignment.Start)) {
            Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = stringResource(R.string.common_back))
        }
        Spacer(Modifier.height(16.dp))

        // Decorative OTP shield (matches the preview's otp-shield).
        OtpShield(modifier = Modifier.align(Alignment.CenterHorizontally), isError = hasError)

        Spacer(Modifier.height(20.dp))
        Text(
            stringResource(R.string.otp_title),
            style = MaterialTheme.typography.headlineLarge.copy(
                fontSize = 26.sp,
                fontWeight = FontWeight.ExtraBold,
                color = AppColors.textPrimary,
            ),
            textAlign = TextAlign.Center,
        )
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

        Spacer(Modifier.height(22.dp))
        Button(
            onClick = ::verify,
            enabled = !isVerifying && digits.joinToString("").length == OTP_LENGTH,
            colors = ButtonDefaults.buttonColors(
                containerColor = AppColors.waRed,
                disabledContainerColor = AppColors.waRed,
                contentColor = Color.White,
                disabledContentColor = Color.White,
            ),
            shape = RoundedCornerShape(50),
            modifier = Modifier
                .fillMaxWidth()
                .height(48.dp)
                .shadow(8.dp, RoundedCornerShape(50), spotColor = AppColors.waRed),
        ) {
            if (isVerifying) {
                CircularProgressIndicator(modifier = Modifier.size(20.dp), strokeWidth = 2.dp, color = Color.White)
            } else {
                Text(
                    stringResource(R.string.otp_verify),
                    fontSize = 15.sp,
                    fontWeight = FontWeight.SemiBold,
                )
            }
        }

        Spacer(Modifier.height(14.dp))
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

        Spacer(Modifier.height(16.dp))
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
        textStyle = MaterialTheme.typography.headlineMedium.copy(
            textAlign = TextAlign.Center,
            fontSize = 22.sp,
            fontWeight = FontWeight.SemiBold,
            color = AppColors.textPrimary,
        ),
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
        shape = RoundedCornerShape(14.dp),
        colors = OutlinedTextFieldDefaults.colors(
            unfocusedBorderColor = overrideBorderColor ?: AppColors.otpPinkBorder,
            focusedBorderColor = overrideBorderColor ?: AppColors.waRed,
        ),
        modifier = Modifier
            .padding(horizontal = 4.dp)
            .width(44.dp)
            .height(52.dp)
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
                        RoundedCornerShape(14.dp),
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
                style = MaterialTheme.typography.bodyMedium,
                fontWeight = FontWeight.SemiBold,
                color = AppColors.waRed,
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

// Decorative OTP shield — 1:1 port of the preview's .otp-shield block:
// 118dp circle ring (red 28%) + 92dp red shield with a white lock.
@Composable
fun OtpShield(modifier: Modifier = Modifier, isError: Boolean = false) {
    val badgeColor by animateColorAsState(
        targetValue = if (isError) AppColors.danger else AppColors.waRed,
        animationSpec = tween(600), label = "otpShieldBadge",
    )
    val ringColor = badgeColor.copy(alpha = 0.28f)
    Box(
        modifier = modifier.size(118.dp),
        contentAlignment = Alignment.Center,
    ) {
        Canvas(modifier = Modifier.fillMaxSize()) {
            drawCircle(color = ringColor, style = Stroke(width = 2.dp.toPx()))
        }
        Canvas(modifier = Modifier.size(92.dp)) {
            val s = size.width / 64f
            val shield = Path().apply {
                moveTo(32f * s, 6f * s)
                lineTo(54f * s, 14.2f * s)
                lineTo(54f * s, 32.8f * s)
                cubicTo(54f * s, 46f * s, 45.4f * s, 55.6f * s, 32f * s, 60.4f * s)
                cubicTo(18.6f * s, 55.6f * s, 10f * s, 46f * s, 10f * s, 32.8f * s)
                lineTo(10f * s, 14.2f * s)
                close()
            }
            drawPath(shield, color = badgeColor)
            // White lock body.
            drawRoundRect(
                color = Color.White,
                topLeft = Offset(23f * s, 31f * s),
                size = androidx.compose.ui.geometry.Size(18f * s, 13f * s),
                cornerRadius = androidx.compose.ui.geometry.CornerRadius(2.5f * s, 2.5f * s),
            )
            // White shackle.
            val shackle = Path().apply {
                moveTo(27f * s, 31.5f * s)
                lineTo(27f * s, 27.3f * s)
                cubicTo(27f * s, 24.5f * s, 29.2f * s, 22.3f * s, 32f * s, 22.3f * s)
                cubicTo(34.8f * s, 22.3f * s, 37f * s, 24.5f * s, 37f * s, 27.3f * s)
                lineTo(37f * s, 31.5f * s)
            }
            drawPath(
                shackle, color = Color.White,
                style = Stroke(width = 2.6f * s, cap = androidx.compose.ui.graphics.StrokeCap.Round),
            )
        }
    }
}
