package com.dorr.driver.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
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
import kotlinx.coroutines.launch

/** Phase 0 signs in Saudi numbers (the launch country); the country picker comes with more countries. */
private const val DIAL_CODE = "+966"

@Composable
fun LoginScreen(onCodeSent: (dialCode: String, phone: String, maskedPhone: String, cooldown: Int) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var phone by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    AuthScaffold(stringResource(R.string.login_title), stringResource(R.string.login_subtitle)) {
        Column(verticalArrangement = Arrangement.spacedBy(16.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Surface(shape = RoundedCornerShape(14.dp), color = MaterialTheme.colorScheme.surfaceVariant) {
                    Text(DIAL_CODE, modifier = Modifier.padding(horizontal = 14.dp, vertical = 18.dp), fontWeight = FontWeight.SemiBold)
                }
                Spacer(Modifier.size(10.dp))
                OutlinedTextField(
                    value = phone,
                    onValueChange = { value -> phone = value.filter(Char::isDigit).take(12); error = null },
                    label = { Text(stringResource(R.string.login_phone_label)) },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
                    shape = RoundedCornerShape(14.dp),
                    isError = error != null,
                    modifier = Modifier.weight(1f),
                )
            }

            error?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyMedium) }

            Spacer(Modifier.height(4.dp))
            Button(
                onClick = {
                    busy = true
                    scope.launch {
                        when (val result = apiCall { DriverBackend.api.requestOtp(PhoneBody(DIAL_CODE, phone)) }) {
                            is ApiResult.Ok -> onCodeSent(DIAL_CODE, phone, result.data.maskedPhone ?: phone, result.data.resendCooldownSeconds)
                            is ApiResult.Failed -> error = result.error.displayMessage
                            is ApiResult.Offline -> error = context.getString(R.string.error_offline)
                        }
                        busy = false
                    }
                },
                enabled = phone.length >= 8 && !busy,
                shape = RoundedCornerShape(16.dp),
                modifier = Modifier
                    .fillMaxWidth()
                    .height(56.dp),
            ) {
                if (busy) {
                    CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp, color = MaterialTheme.colorScheme.onPrimary)
                } else {
                    Text(stringResource(R.string.login_send_code), style = MaterialTheme.typography.titleMedium)
                }
            }
        }
    }
}
