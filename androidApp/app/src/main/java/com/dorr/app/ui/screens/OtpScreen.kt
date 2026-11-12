package com.dorr.app.ui.screens

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
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
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.offset
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
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowLeft
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.History
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
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
import androidx.compose.ui.text.TextRange
import androidx.compose.ui.text.input.TextFieldValue
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.OtpRequest
import com.dorr.app.network.VerifyOtpRequest
import com.dorr.app.network.ReferralTracker
import com.dorr.app.network.serverMessage
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.theme.AppColors
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

private const val OTP_LENGTH = 4

@Composable
fun OtpScreen(
    dialCode: String,
    phoneNumber: String,
    onBack: () -> Unit,
    onVerified: () -> Unit,
    restoreMode: Boolean = false,
) {
    val digits = remember { mutableStateListOf(*Array(OTP_LENGTH) { "" }) }
    val focusRequesters = remember { List(OTP_LENGTH) { FocusRequester() } }
    val scope = rememberCoroutineScope()
    val genericOtpError = stringResource(R.string.otp_error_generic)

    var isVerifying by remember { mutableStateOf(false) }
    var hasError by remember { mutableStateOf(false) }
    var errorMessage by remember { mutableStateOf<String?>(null) }
    var isSuccess by remember { mutableStateOf(false) }
    val shakeOffset = remember { Animatable(0f) }

    // A server message is in the language it was asked in: switching language drops it (the next one comes in the new one).
    val languageCode = com.dorr.app.ui.locale.LocalAppLanguage.current.code
    val firstLanguage = remember { languageCode }
    LaunchedEffect(languageCode) {
        if (languageCode != firstLanguage) {
            errorMessage = null
            hasError = false
        }
    }

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
                ReferralTracker.submitPending()
                delay(300)
                onVerified()
            }.onFailure {
                hasError = true
                errorMessage = it.serverMessage() ?: genericOtpError
                triggerShake()
                // Then the boxes empty and the first one takes the cursor, ready for the code again (the message stays
                // until the next digit is typed).
                delay(700)
                for (i in digits.indices) digits[i] = ""
                hasError = false
                focusRequesters[0].requestFocus()
            }
        }
    }

    fun onDigitChanged(index: Int, value: String) {
        if (value.isNotEmpty()) {
            hasError = false
            errorMessage = null
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
        isSuccess -> AuthColors.success(settingsNight())
        hasError -> AuthColors.danger(settingsNight())
        else -> null
    }

    val scrollState = rememberScrollState()
    val night = settingsNight()
    val ink = if (night) AccountDark.ink else settingsAccent()
    val mut = AuthColors.mut(night)

    Box(modifier = Modifier.fillMaxSize()) {
        // Full bleed background canvas
        com.dorr.app.ui.screens.profile.PinkBackdrop(Modifier.fillMaxSize())

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
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .widthIn(max = 440.dp),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                // Back: the language button's look — a see-through circle, 34, with a soft frame — and a chevron in the
                // app's colour (› in Arabic, ‹ in English).
                Box(
                    modifier = Modifier
                        .size(34.dp)
                        .clip(CircleShape)
                        .border(1.dp, AuthColors.line(night), CircleShape)
                        .clickable(onClick = onBack),
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(
                        Icons.AutoMirrored.Rounded.KeyboardArrowLeft,
                        contentDescription = stringResource(R.string.common_back),
                        tint = if (night) AccountDark.ink else settingsAccent(),
                        modifier = Modifier.size(22.dp),
                    )
                }
                LanguagePicker()
            }

            Spacer(Modifier.height(8.dp))


            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .widthIn(max = 440.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                OtpShield(
                    modifier = Modifier.size(88.dp),
                    isError = hasError,
                    restore = restoreMode,
                )

                Spacer(Modifier.height(18.dp))

                Text(
                    text = stringResource(if (restoreMode) R.string.account_restore_title else R.string.otp_title),
                    color = settingsAccent(),
                    fontWeight = FontWeight.ExtraBold,
                    fontSize = 22.sp,
                    lineHeight = 30.sp,
                    textAlign = TextAlign.Center,
                )

                Spacer(Modifier.height(4.dp))

                // «Enter the 4-digit code we sent to», then the number on its own line, grouped like the sign-in shows it.
                Text(
                    text = stringResource(if (restoreMode) R.string.otp_restore_subtitle else R.string.otp_subtitle, OTP_LENGTH.toString()),
                    fontSize = 14.sp,
                    lineHeight = 22.sp,
                    color = mut,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.widthIn(max = 320.dp),
                )
                val formattedPhone = listOf(dialCode, groupPhone(phoneNumber, phoneGroups(phoneNumber.length)))
                    .filter { it.isNotBlank() }.joinToString(" ")
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    Text(
                        text = formattedPhone,
                        color = ink,
                        fontSize = 16.sp,
                        fontWeight = FontWeight.Bold,
                        modifier = Modifier.padding(top = 2.dp),
                    )
                }

                Spacer(Modifier.height(20.dp))

                // OTP Digits (always LTR, perfectly centered, no clipping)
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    Row(
                        modifier = Modifier
                            .fillMaxWidth()
                            .graphicsLayer { translationX = shakeOffset.value },
                        horizontalArrangement = Arrangement.spacedBy(12.dp, Alignment.CenterHorizontally),
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

                // What went wrong, right under the boxes: a red line with its icon (the danger red), until the next digit.
                AnimatedVisibility(
                    visible = errorMessage != null,
                    enter = fadeIn() + expandVertically(),
                    exit = fadeOut() + shrinkVertically(),
                ) {
                    val bad = AuthColors.onStatus(AuthColors.danger(night), night)
                    Row(
                        modifier = Modifier.padding(top = 10.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(Icons.Rounded.ErrorOutline, contentDescription = null, tint = bad, modifier = Modifier.size(16.dp))
                        Spacer(Modifier.width(6.dp))
                        Text(
                            text = errorMessage.orEmpty(),
                            color = bad,
                            fontSize = 12.5.sp,
                            fontWeight = FontWeight.Medium,
                            textAlign = TextAlign.Center,
                        )
                    }
                }

                // Until real SMS is wired: the code to use, small, under the boxes.
                Text(
                    text = stringResource(R.string.otp_dev_hint),
                    fontSize = 12.sp,
                    fontWeight = FontWeight.Bold,
                    color = mut,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.padding(top = 10.dp),
                )

                Spacer(Modifier.height(20.dp))

                // «Verify»: filled in the app's colour once the code is complete; before that the sign-in's soft fill
                // with grey words; while checking, the app's colour with a spinner.
                val buttonShape = RoundedCornerShape(999.dp)
                val accent = settingsAccent()
                Button(
                    onClick = ::verify,
                    enabled = canVerify,
                    colors = ButtonDefaults.buttonColors(
                        containerColor = accent,
                        disabledContainerColor = if (isVerifying) accent else loginSoftFill(night, 0.6f),
                        contentColor = Color.White,
                        disabledContentColor = if (isVerifying) Color.White else if (night) AccountDark.mut else com.dorr.app.ui.theme.appearanceColor("textMuted", Color(0xFF9CA3AF), night = false),
                    ),
                    shape = buttonShape,
                    modifier = Modifier
                        .fillMaxWidth()
                        .height(52.dp)
                        .then(
                            if (canVerify) {
                                Modifier.shadow(8.dp, buttonShape, ambientColor = accent.copy(alpha = 0.4f), spotColor = accent.copy(alpha = 0.4f))
                            } else {
                                Modifier
                            },
                        ),
                ) {
                    if (isVerifying) {
                        CircularProgressIndicator(
                            modifier = Modifier.size(22.dp),
                            strokeWidth = 2.5.dp,
                            color = Color.White,
                        )
                    } else {
                        Text(
                            text = stringResource(R.string.otp_verify),
                            fontSize = 16.sp,
                            fontWeight = FontWeight.Bold,
                        )
                    }
                }

                Spacer(Modifier.height(14.dp))

                // Countdown Timer / Resend Action
                TimerOrResend(
                    countdown = countdown,
                    canResend = canResend,
                    onResend = ::resend,
                )
            }
        }
    }

    LaunchedEffect(Unit) {
        focusRequesters[0].requestFocus()
    }
}

private const val OTP_MARKER = "​"

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

    val night = settingsNight()
    val boxBorderColor = overrideBorderColor ?: if (isFocused) (if (night) AccountDark.accent else settingsAccent()) else AuthColors.line(night)

    // «_» blinking under the focused empty box (the design's cursor), in the app's colour.
    val blink = androidx.compose.animation.core.rememberInfiniteTransition(label = "otpCursor")
    val cursorAlpha by blink.animateFloat(
        initialValue = 1f,
        targetValue = 0f,
        animationSpec = androidx.compose.animation.core.infiniteRepeatable(
            tween(500, easing = androidx.compose.animation.core.LinearEasing),
            androidx.compose.animation.core.RepeatMode.Reverse,
        ),
        label = "otpCursorAlpha",
    )
    Surface(
        modifier = Modifier
            .width(56.dp)
            .height(60.dp)
            .scale(scale.value),
        shape = RoundedCornerShape(16.dp),
        color = if (highlightFill && overrideBorderColor != null) {
            overrideBorderColor.copy(alpha = 0.05f)
        } else {
            AuthColors.card(night)
        },
        border = androidx.compose.foundation.BorderStroke(1.5.dp, boxBorderColor),
    ) {
        Box(
            modifier = Modifier.fillMaxSize(),
            contentAlignment = Alignment.Center,
        ) {
            if (isFocused && value.isEmpty()) {
                Box(
                    Modifier
                        .align(Alignment.BottomCenter)
                        .padding(bottom = 16.dp)
                        .size(width = 20.dp, height = 2.5.dp)
                        .clip(RoundedCornerShape(2.dp))
                        .background(settingsAccent().copy(alpha = cursorAlpha)),
                )
            }
            // A soft keyboard sends no key event for Backspace on an empty field, so the box always
            // holds an invisible marker: deleting it is how an "empty" box learns Backspace was pressed.
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
                    color = AuthColors.ink(night),
                    textAlign = TextAlign.Center,
                ),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
                // The system cursor is hidden: a short line lying at the bottom of the focused empty box stands in.
                cursorBrush = SolidColor(Color.Transparent),
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

/**
 * Under «Verify»: «Didn't get the code? You can resend in 0:17» (the time bold, in the app's colour, ticking on the
 * phone's clock), then «Didn't get the code? Resend» — «Resend» bold in the app's accent, tappable.
 */
@Composable
private fun TimerOrResend(countdown: Int, canResend: Boolean, onResend: () -> Unit) {
    val night = settingsNight()
    val mut = AuthColors.mut(night)
    Row(verticalAlignment = Alignment.CenterVertically) {
        Text(stringResource(R.string.otp_resend_question), color = mut, fontSize = 13.5.sp)
        Spacer(Modifier.width(6.dp))
        if (canResend) {
            Text(
                text = stringResource(R.string.otp_resend),
                color = settingsAccent(),
                fontSize = 13.5.sp,
                fontWeight = FontWeight.Bold,
                modifier = Modifier
                    .clip(RoundedCornerShape(8.dp))
                    .clickable(onClick = onResend)
                    .padding(horizontal = 4.dp, vertical = 2.dp),
            )
        } else {
            Text(stringResource(R.string.otp_resend_in), color = mut, fontSize = 13.5.sp)
            Spacer(Modifier.width(5.dp))
            Text(
                text = "%d:%02d".format(java.util.Locale.ROOT, countdown / 60, countdown % 60),
                color = if (night) AccountDark.ink else settingsAccent(),
                fontSize = 13.5.sp,
                fontWeight = FontWeight.Bold,
            )
        }
    }
}

/**
 * The OTP page's badge: the shield with a white lock (a white restore icon for a deleted account), on a rounded square of
 * the same colour, faint (no ring) — the app's main colour, or the danger red (badge and square together) on a wrong
 * code.
 */
@Composable
fun OtpShield(modifier: Modifier = Modifier, isError: Boolean = false, restore: Boolean = false) {
    val night = settingsNight()
    val badgeColor by animateColorAsState(
        targetValue = if (isError) AuthColors.danger(night) else settingsAccent(),
        animationSpec = tween(600), label = "otpShieldBadge",
    )
    Box(
        modifier = modifier
            .size(88.dp)
            .clip(RoundedCornerShape(26.dp))
            .background(badgeColor.copy(alpha = if (night) 0.16f else 0.10f)),
        contentAlignment = Alignment.Center,
    ) {
        Canvas(modifier = Modifier.size(76.dp)) {
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
            // A deleted account: the restore icon goes in the shield instead of the lock (drawn over the canvas below).
            if (restore) return@Canvas
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
        // A deleted account being restored: the restore icon (a clock with an arrow), white, inside the shield.
        if (restore) {
            Icon(
                Icons.Rounded.History,
                contentDescription = null,
                tint = Color.White,
                modifier = Modifier.offset(y = (-1).dp).size(26.dp),
            )
        }
    }
}
