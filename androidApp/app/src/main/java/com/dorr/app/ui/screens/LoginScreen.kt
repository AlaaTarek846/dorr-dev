package com.dorr.app.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.border
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
import androidx.compose.material.icons.rounded.Build
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.ui.theme.AppColors
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

@Composable
fun LoginScreen(onOtpRequested: (dialCode: String, phone: String) -> Unit) {
    var phone by remember { mutableStateOf("") }
    var isLoading by remember { mutableStateOf(false) }

    // Defaults match the backend's fallback (seeded default country) — only
    // used until /user/v1/countries/detect answers, or if it fails.
    var dialCode by remember { mutableStateOf("+20") }
    var phoneLength by remember { mutableStateOf(10) }

    val scope = rememberCoroutineScope()

    LaunchedEffect(Unit) {
        try {
            val response = ApiClient.countries.detect()
            response.data?.let { country ->
                dialCode = country.dialCode
                phoneLength = country.phoneLength ?: phoneLength
            }
        } catch (_: Exception) {
            // Offline or backend unreachable — keep the defaults above.
        }
    }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(24.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Spacer(Modifier.height(40.dp))

        Box(
            modifier = Modifier
                .size(80.dp)
                .background(AppColors.primary.copy(alpha = 0.1f), CircleShape),
            contentAlignment = Alignment.Center,
        ) {
            Icon(Icons.Rounded.Build, contentDescription = null, tint = AppColors.primary, modifier = Modifier.size(40.dp))
        }
        Spacer(Modifier.height(24.dp))

        Text(
            text = stringResource(R.string.login_title),
            style = MaterialTheme.typography.headlineLarge,
            textAlign = TextAlign.Center,
        )
        Spacer(Modifier.height(8.dp))
        Text(
            text = stringResource(R.string.login_subtitle),
            style = MaterialTheme.typography.bodyMedium,
            color = AppColors.textSecondary,
            textAlign = TextAlign.Center,
        )
        Spacer(Modifier.height(40.dp))

        PhoneField(
            dialCode = dialCode,
            phoneLength = phoneLength,
            phone = phone,
            onPhoneChange = { if (it.length <= phoneLength) phone = it },
        )

        Spacer(Modifier.height(32.dp))

        Button(
            onClick = {
                isLoading = true
                scope.launch {
                    // No real send-otp endpoint yet — this delay just keeps the
                    // loading-state UX consistent with what a real call will feel like.
                    delay(500)
                    isLoading = false
                    onOtpRequested(dialCode, phone)
                }
            },
            enabled = !isLoading && phone.length == phoneLength,
            modifier = Modifier
                .fillMaxWidth()
                .height(52.dp),
        ) {
            if (isLoading) {
                CircularProgressIndicator(modifier = Modifier.size(20.dp), strokeWidth = 2.dp)
            } else {
                Text(stringResource(R.string.login_cta))
            }
        }

        Spacer(Modifier.height(24.dp))
        Text(
            text = stringResource(R.string.login_terms),
            style = MaterialTheme.typography.bodySmall,
            textAlign = TextAlign.Center,
        )
    }
}

@Composable
private fun PhoneField(dialCode: String, phoneLength: Int, phone: String, onPhoneChange: (String) -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .background(AppColors.background, RoundedCornerShape(12.dp))
            .border(1.dp, AppColors.border, RoundedCornerShape(12.dp))
            .padding(horizontal = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(dialCode, style = MaterialTheme.typography.titleMedium, color = AppColors.textPrimary)
        Spacer(Modifier.width(8.dp))
        Box(
            modifier = Modifier
                .width(1.dp)
                .height(28.dp)
                .background(AppColors.border),
        )
        Spacer(Modifier.width(8.dp))
        OutlinedTextField(
            value = phone,
            onValueChange = onPhoneChange,
            placeholder = { Text("0".repeat(phoneLength), color = AppColors.textMuted) },
            singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
            colors = OutlinedTextFieldDefaults.colors(
                unfocusedBorderColor = androidx.compose.ui.graphics.Color.Transparent,
                focusedBorderColor = androidx.compose.ui.graphics.Color.Transparent,
            ),
            modifier = Modifier.fillMaxWidth(),
        )
    }
}
