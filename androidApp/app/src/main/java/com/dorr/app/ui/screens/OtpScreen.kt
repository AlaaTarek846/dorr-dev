package com.dorr.app.ui.screens

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
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
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
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
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.input.key.Key
import androidx.compose.ui.input.key.KeyEventType
import androidx.compose.ui.input.key.key
import androidx.compose.ui.input.key.onPreviewKeyEvent
import androidx.compose.ui.input.key.type
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
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

    val isComplete = digits.joinToString("").length == OTP_LENGTH
    val canVerify = !isVerifying && isComplete

    val borderColor = when {
        isSuccess -> AppColors.success
        hasError -> AppColors.danger
        else -> null
    }

    val scrollState = rememberScrollState()

    Box(modifier = Modifier.fillMaxSize()) {
        // Full bleed background canvas
        OtpBackdrop(Modifier.fillMaxSize())

        // Content with safe area insets
        Column(
            modifier = Modifier
                .fillMaxSize()
                .statusBarsPadding()
                .navigationBarsPadding()
                .imePadding()
                .verticalScroll(scrollState)
                .padding(horizontal = 24.dp)
                .padding(top = 10.dp, bottom = 28.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            // Top Bar: Back Button safely below status bar
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .widthIn(max = 440.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                IconButton(
                    onClick = onBack,
                    modifier = Modifier
                        .clip(CircleShape)
                        .background(Color.White.copy(alpha = 0.95f))
                        .border(1.dp, Color(0xFFE5E7EB), CircleShape)
                        .size(42.dp),
                ) {
                    Icon(
                        Icons.AutoMirrored.Rounded.ArrowBack,
                        contentDescription = stringResource(R.string.common_back),
                        tint = AppColors.textPrimary,
                        modifier = Modifier.size(20.dp),
                    )
                }
            }

            // Lower the content gracefully
            Spacer(Modifier.height(28.dp))

            // Main container restricted to max mobile width
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .widthIn(max = 440.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                // Shield graphic
                OtpShield(
                    modifier = Modifier.size(110.dp),
                    isError = hasError,
                )

                Spacer(Modifier.height(24.dp))

                // Title
                Text(
                    text = stringResource(R.string.otp_title),
                    style = MaterialTheme.typography.headlineLarge.copy(
                        fontSize = 26.sp,
                        fontWeight = FontWeight.ExtraBold,
                        color = Color(0xFF111928),
                    ),
                    textAlign = TextAlign.Center,
                )

                Spacer(Modifier.height(8.dp))

                // Subtitle with phone number
                val formattedPhone = listOf(dialCode, phoneNumber).filter { it.isNotBlank() }.joinToString(" ")
                Text(
                    text = stringResource(R.string.otp_subtitle, formattedPhone),
                    style = MaterialTheme.typography.bodyMedium.copy(
                        fontSize = 14.sp,
                        lineHeight = 22.sp,
                    ),
                    color = Color(0xFF6B7280),
                    textAlign = TextAlign.Center,
                    modifier = Modifier.padding(horizontal = 8.dp),
                )

                Spacer(Modifier.height(36.dp))

                // OTP Digits (always LTR, perfectly centered, no clipping)
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    Row(
                        modifier = Modifier
                            .fillMaxWidth()
                            .graphicsLayer { translationX = shakeOffset.value },
                        horizontalArrangement = Arrangement.spacedBy(8.dp, Alignment.CenterHorizontally),
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

                // Verify Button (Stays solid red on click with spinner, never disappears)
                Button(
                    onClick = ::verify,
                    enabled = canVerify,
                    colors = ButtonDefaults.buttonColors(
                        containerColor = Color(0xFFE50914),
                        disabledContainerColor = if (isVerifying) Color(0xFFE50914) else Color(0xFFE5E7EB),
                        contentColor = Color.White,
                        disabledContentColor = if (isVerifying) Color.White else Color(0xFF9CA3AF),
                    ),
                    shape = RoundedCornerShape(16.dp),
                    modifier = Modifier
                        .fillMaxWidth()
                        .height(54.dp)
                        .then(
                            if (isComplete && !isVerifying) {
                                Modifier.shadow(
                                    elevation = 10.dp,
                                    shape = RoundedCornerShape(16.dp),
                                    ambientColor = Color(0x33E50914),
                                    spotColor = Color(0x55E50914),
                                )
                            } else {
                                Modifier
                            },
                        ),
                ) {
                    if (isVerifying) {
                        CircularProgressIndicator(
                            modifier = Modifier.size(24.dp),
                            strokeWidth = 2.5.dp,
                            color = Color.White,
                        )
                    } else {
                        Text(
                            text = stringResource(R.string.otp_verify),
                            fontSize = 16.sp,
                            fontWeight = FontWeight.Bold,
                            color = if (isComplete) Color.White else Color(0xFF9CA3AF),
                        )
                    }
                }

                Spacer(Modifier.height(20.dp))

                // Countdown Timer / Resend Action
                TimerOrResend(
                    countdown = countdown,
                    canResend = canResend,
                    onResend = ::resend,
                )

                // Error message banner
                AnimatedVisibility(
                    visible = hasError && errorMessage != null,
                    enter = fadeIn() + expandVertically(),
                    exit = fadeOut() + shrinkVertically(),
                ) {
                    errorMessage?.let { message ->
                        Row(
                            modifier = Modifier
                                .fillMaxWidth()
                                .padding(top = 16.dp)
                                .clip(RoundedCornerShape(12.dp))
                                .background(Color(0xFFFDE8EC))
                                .border(1.dp, Color(0xFFF8B4C0), RoundedCornerShape(12.dp))
                                .padding(horizontal = 14.dp, vertical = 12.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(
                                Icons.Rounded.ErrorOutline,
                                contentDescription = null,
                                tint = Color(0xFFE50914),
                                modifier = Modifier.size(20.dp),
                            )
                            Spacer(Modifier.width(10.dp))
                            Text(
                                text = message,
                                color = Color(0xFF991B1B),
                                style = MaterialTheme.typography.bodySmall,
                                fontWeight = FontWeight.Medium,
                                modifier = Modifier.weight(1f),
                            )
                        }
                    }
                }

                Spacer(Modifier.height(16.dp))

                // Dev hint if present
                Text(
                    text = stringResource(R.string.otp_dev_hint),
                    style = MaterialTheme.typography.bodySmall,
                    color = AppColors.warning,
                    textAlign = TextAlign.Center,
                )
            }
        }
    }

    LaunchedEffect(Unit) {
        focusRequesters[0].requestFocus()
    }
}

@Composable
private fun OtpBackdrop(modifier: Modifier = Modifier) {
    Canvas(modifier) {
        drawRect(Color(0xFFFAFAFC))

        fun glow(center: Offset, radius: Float, color: Color) {
            drawCircle(
                brush = Brush.radialGradient(
                    colors = listOf(color, color.copy(alpha = 0.45f), Color.Transparent),
                    center = center,
                    radius = radius,
                ),
                radius = radius,
                center = center,
            )
        }

        glow(Offset(size.width * 0.15f, size.height * 0.05f), size.width * 0.95f, Color(0xFFFDE8EC))
        glow(Offset(size.width * 0.85f, size.height * 0.08f), size.width * 0.85f, Color(0xFFFDE2E6))
        glow(Offset(size.width * 0.5f, size.height * -0.05f), size.width * 1.1f, Color(0xFFFFF0F2))
        glow(Offset(size.width * 0.9f, size.height * 0.92f), size.width * 0.75f, Color(0xFFFDEBED))
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
    var isFocused by remember { mutableStateOf(false) }
    val scale = remember { Animatable(1f) }
    val scope = rememberCoroutineScope()

    val boxBorderColor = overrideBorderColor ?: if (isFocused) Color(0xFFE50914) else Color(0xFFE5E7EB)
    val borderWidth = if (isFocused || overrideBorderColor != null) 2.dp else 1.dp

    Surface(
        modifier = Modifier
            .width(48.dp)
            .height(56.dp)
            .scale(scale.value),
        shape = RoundedCornerShape(14.dp),
        color = if (highlightFill && overrideBorderColor != null) {
            overrideBorderColor.copy(alpha = 0.08f)
        } else if (isFocused) {
            Color.White
        } else {
            Color(0xFFFAFAFA)
        },
        shadowElevation = if (isFocused) 4.dp else 1.dp,
        border = androidx.compose.foundation.BorderStroke(borderWidth, boxBorderColor),
    ) {
        Box(
            modifier = Modifier.fillMaxSize(),
            contentAlignment = Alignment.Center,
        ) {
            BasicTextField(
                value = value,
                onValueChange = { new ->
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
                    fontSize = 24.sp,
                    fontWeight = FontWeight.ExtraBold,
                    color = AppColors.textPrimary,
                    textAlign = TextAlign.Center,
                ),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
                cursorBrush = SolidColor(Color(0xFFE50914)),
                modifier = Modifier
                    .fillMaxWidth()
                    .focusRequester(focusRequester)
                    .onFocusChanged { isFocused = it.isFocused }
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
                    },
                decorationBox = { innerTextField ->
                    Box(
                        modifier = Modifier.fillMaxSize(),
                        contentAlignment = Alignment.Center,
                    ) {
                        innerTextField()
                    }
                },
            )
        }
    }
}

@Composable
private fun TimerOrResend(countdown: Int, canResend: Boolean, onResend: () -> Unit) {
    if (canResend) {
        Button(
            onClick = onResend,
            colors = ButtonDefaults.outlinedButtonColors(
                contentColor = Color(0xFFE50914),
            ),
            shape = RoundedCornerShape(12.dp),
            border = androidx.compose.foundation.BorderStroke(1.dp, Color(0xFFE50914).copy(alpha = 0.4f)),
            modifier = Modifier.height(42.dp),
        ) {
            Icon(
                Icons.Rounded.Refresh,
                contentDescription = null,
                modifier = Modifier.size(18.dp),
            )
            Spacer(Modifier.width(6.dp))
            Text(
                text = stringResource(R.string.otp_resend),
                style = MaterialTheme.typography.bodyMedium,
                fontWeight = FontWeight.Bold,
            )
        }
        return
    }

    Row(
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.Center,
        modifier = Modifier
            .clip(RoundedCornerShape(999.dp))
            .background(Color.White.copy(alpha = 0.9f))
            .border(1.dp, Color(0xFFE5E7EB), RoundedCornerShape(999.dp))
            .padding(horizontal = 16.dp, vertical = 8.dp),
    ) {
        Box(modifier = Modifier.size(20.dp), contentAlignment = Alignment.Center) {
            CircularProgressIndicator(
                progress = { countdown / 60f },
                strokeWidth = 2.5.dp,
                color = Color(0xFFE50914),
                trackColor = Color(0xFFF3F4F6),
                modifier = Modifier.fillMaxSize(),
            )
        }
        Spacer(Modifier.width(8.dp))
        Text(
            text = stringResource(R.string.otp_resend_in, countdown),
            style = MaterialTheme.typography.bodySmall.copy(
                fontSize = 13.sp,
                fontWeight = FontWeight.Medium,
            ),
            color = AppColors.textSecondary,
        )
    }
}

// Decorative OTP shield
@Composable
fun OtpShield(modifier: Modifier = Modifier, isError: Boolean = false) {
    val badgeColor by animateColorAsState(
        targetValue = if (isError) AppColors.danger else Color(0xFFE50914),
        animationSpec = tween(600), label = "otpShieldBadge",
    )
    val ringColor = badgeColor.copy(alpha = 0.2f)
    Box(
        modifier = modifier.size(110.dp),
        contentAlignment = Alignment.Center,
    ) {
        Canvas(modifier = Modifier.fillMaxSize()) {
            drawCircle(color = ringColor, style = Stroke(width = 2.dp.toPx()))
        }
        Canvas(modifier = Modifier.size(86.dp)) {
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
            // White lock body
            drawRoundRect(
                color = Color.White,
                topLeft = Offset(23f * s, 31f * s),
                size = androidx.compose.ui.geometry.Size(18f * s, 13f * s),
                cornerRadius = androidx.compose.ui.geometry.CornerRadius(2.5f * s, 2.5f * s),
            )
            // White shackle
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
