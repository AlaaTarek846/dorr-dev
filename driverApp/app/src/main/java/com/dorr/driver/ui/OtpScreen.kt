package com.dorr.driver.ui

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.R
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.PhoneBody
import com.dorr.driver.net.VerifyBody
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

private const val CODE_LENGTH = 4

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
    val focus = remember { FocusRequester() }

    LaunchedEffect(cooldown) {
        if (cooldown > 0) {
            delay(1000)
            cooldown -= 1
        }
    }
    LaunchedEffect(Unit) { focus.requestFocus() }

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

    AuthScaffold(stringResource(R.string.otp_title), stringResource(R.string.otp_subtitle, maskedPhone)) {
        Column(verticalArrangement = Arrangement.spacedBy(20.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            // One hidden field drives the boxes, so paste and the SMS keyboard suggestion both work.
            BasicTextField(
                value = code,
                onValueChange = { value ->
                    code = value.filter(Char::isDigit).take(CODE_LENGTH)
                    error = null
                    if (code.length == CODE_LENGTH && !busy) verify()
                },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
                modifier = Modifier.focusRequester(focus),
                decorationBox = {
                    Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                        repeat(CODE_LENGTH) { index ->
                            val filled = index < code.length
                            val current = index == code.length
                            Surface(
                                shape = RoundedCornerShape(16.dp),
                                color = MaterialTheme.colorScheme.surfaceVariant,
                                border = BorderStroke(
                                    if (current) 2.dp else 1.dp,
                                    when {
                                        error != null -> MaterialTheme.colorScheme.error
                                        current || filled -> MaterialTheme.colorScheme.secondary
                                        else -> MaterialTheme.colorScheme.outline.copy(alpha = 0.4f)
                                    },
                                ),
                                modifier = Modifier.size(64.dp),
                            ) {
                                Box(contentAlignment = Alignment.Center) {
                                    Text(
                                        code.getOrNull(index)?.toString() ?: "",
                                        style = MaterialTheme.typography.headlineMedium,
                                        fontWeight = FontWeight.Bold,
                                    )
                                }
                            }
                        }
                    }
                },
            )

            error?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyMedium) }

            Button(
                onClick = { verify() },
                enabled = code.length == CODE_LENGTH && !busy,
                shape = RoundedCornerShape(16.dp),
                modifier = Modifier
                    .fillMaxWidth()
                    .height(56.dp),
            ) {
                if (busy) {
                    CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp, color = MaterialTheme.colorScheme.onPrimary)
                } else {
                    Text(stringResource(R.string.otp_verify), style = MaterialTheme.typography.titleMedium)
                }
            }

            Row(horizontalArrangement = Arrangement.SpaceBetween, modifier = Modifier.fillMaxWidth()) {
                TextButton(onClick = onBack) { Text(stringResource(R.string.otp_change_number)) }
                TextButton(
                    enabled = cooldown == 0,
                    onClick = {
                        scope.launch {
                            when (val result = apiCall { DriverBackend.api.resendOtp(PhoneBody(dialCode, phone)) }) {
                                is ApiResult.Ok -> cooldown = result.data.resendCooldownSeconds
                                is ApiResult.Failed -> error = result.error.displayMessage
                                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
                            }
                        }
                    },
                ) {
                    Text(if (cooldown > 0) stringResource(R.string.otp_resend_in, cooldown) else stringResource(R.string.otp_resend))
                }
            }
        }
    }
}
