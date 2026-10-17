package com.dorr.driver.ui

import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
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
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.scale
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.R
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.PhoneBody
import com.dorr.driver.net.VerifyBody
import com.dorr.driver.ui.kit.Banner
import com.dorr.driver.ui.kit.OtpBoxes
import com.dorr.driver.ui.kit.PinkBackdrop
import com.dorr.driver.ui.kit.PrimaryButton
import com.dorr.driver.ui.kit.RoundIconButton
import com.dorr.driver.ui.kit.bounceClick
import com.dorr.driver.ui.theme.Pal
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

private const val CODE_LENGTH = 4

/** The code step, in the DORR app's design: the shield, the boxes that pop and shake, the resend link. */
@Composable
fun OtpScreen(
    dialCode: String,
    phone: String,
    maskedPhone: String,
    initialCooldown: Int,
    onBack: () -> Unit,
    onSignedIn: () -> Unit,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var code by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var cooldown by remember { mutableIntStateOf(initialCooldown) }

    // The resend countdown runs on the phone's own clock — nothing is asked of the server meanwhile.
    LaunchedEffect(cooldown) {
        if (cooldown > 0) {
            delay(1000)
            cooldown -= 1
        }
    }

    fun verify() {
        busy = true
        scope.launch {
            when (val result = apiCall { DriverBackend.api.verify(VerifyBody(dialCode, phone, code)) }) {
                is ApiResult.Ok -> {
                    DriverBackend.session.token = result.data.token
                    onSignedIn()
                }
                is ApiResult.Failed -> {
                    error = result.error.displayMessage
                    code = ""
                }
                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
            }
            busy = false
        }
    }

    fun resend() {
        scope.launch {
            when (val result = apiCall { DriverBackend.api.resendOtp(PhoneBody(dialCode, phone)) }) {
                is ApiResult.Ok -> { cooldown = result.data.resendCooldownSeconds; error = null }
                is ApiResult.Failed -> error = result.error.displayMessage
                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
            }
        }
    }

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.matchParentSize())
        Column(
            Modifier
                .fillMaxSize()
                .statusBarsPadding()
                .navigationBarsPadding()
                .imePadding()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 24.dp)
                .padding(top = 10.dp, bottom = 28.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Row(Modifier.fillMaxWidth().widthIn(max = 440.dp), verticalAlignment = Alignment.CenterVertically) {
                RoundIconButton(Icons.AutoMirrored.Rounded.ArrowBack, stringResource(R.string.back), onBack)
            }
            Spacer(Modifier.height(8.dp))

            Column(Modifier.fillMaxWidth().widthIn(max = 440.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                OtpShield(Modifier.size(118.dp), isError = error != null)
                Spacer(Modifier.height(6.dp))
                Text(stringResource(R.string.otp_title), color = Pal.accent, fontWeight = FontWeight.ExtraBold, fontSize = 26.sp, textAlign = TextAlign.Center)
                Spacer(Modifier.height(8.dp))
                Text(
                    stringResource(R.string.otp_subtitle, maskedPhone), fontSize = 14.sp, lineHeight = 22.sp, color = Pal.mut,
                    textAlign = TextAlign.Center, modifier = Modifier.widthIn(max = 280.dp),
                )
                Spacer(Modifier.height(24.dp))

                OtpBoxes(
                    code = code,
                    length = CODE_LENGTH,
                    error = error != null,
                    onChange = { value ->
                        code = value
                        error = null
                        if (code.length == CODE_LENGTH && !busy) verify()
                    },
                )

                Banner(error, Modifier.padding(top = 14.dp))

                Spacer(Modifier.height(24.dp))
                PrimaryButton(stringResource(R.string.otp_verify), enabled = code.length == CODE_LENGTH, busy = busy, onClick = { verify() })

                Spacer(Modifier.height(18.dp))
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        stringResource(R.string.otp_change_number), color = Pal.accent, fontSize = 14.sp, fontWeight = FontWeight.SemiBold,
                        modifier = Modifier.bounceClick(onClick = onBack).padding(vertical = 6.dp),
                    )
                    if (cooldown > 0) {
                        Text(stringResource(R.string.otp_resend_in, cooldown), color = Pal.faint, fontSize = 12.sp)
                    } else {
                        Text(
                            stringResource(R.string.otp_resend), color = Pal.accent, fontSize = 14.sp, fontWeight = FontWeight.SemiBold,
                            modifier = Modifier.bounceClick { resend() }.padding(vertical = 6.dp),
                        )
                    }
                }
            }
        }
    }
}

/** The shield with a lock — turns red with a wrong code, and lands with a little spring when it first appears. */
@Composable
private fun OtpShield(modifier: Modifier = Modifier, isError: Boolean) {
    val badge by animateColorAsState(if (isError) Pal.danger else Pal.accent, tween(600), label = "shield")
    val ring = badge.copy(alpha = 0.28f)
    val land = remember { Animatable(0.7f) }
    LaunchedEffect(Unit) { land.animateTo(1f, spring(dampingRatio = Spring.DampingRatioMediumBouncy, stiffness = Spring.StiffnessLow)) }

    Box(modifier.scale(land.value), contentAlignment = Alignment.Center) {
        Canvas(Modifier.fillMaxSize()) { drawCircle(color = ring, style = Stroke(width = 2.dp.toPx())) }
        Canvas(Modifier.size(92.dp)) {
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
            drawPath(shield, color = badge)
            drawRoundRect(color = Color.White, topLeft = Offset(23f * s, 31f * s), size = Size(18f * s, 13f * s), cornerRadius = CornerRadius(2.5f * s, 2.5f * s))
            val shackle = Path().apply {
                moveTo(27f * s, 31.5f * s)
                lineTo(27f * s, 27.3f * s)
                cubicTo(27f * s, 24.5f * s, 29.2f * s, 22.3f * s, 32f * s, 22.3f * s)
                cubicTo(34.8f * s, 22.3f * s, 37f * s, 24.5f * s, 37f * s, 27.3f * s)
                lineTo(37f * s, 31.5f * s)
            }
            drawPath(shackle, color = Color.White, style = Stroke(width = 2.6f * s, cap = StrokeCap.Round))
        }
    }
}
