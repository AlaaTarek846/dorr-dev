package com.dorr.driver.ui

import androidx.compose.foundation.background
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
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowForward
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.R
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.PhoneBody
import com.dorr.driver.ui.kit.Banner
import com.dorr.driver.ui.kit.DorrLogo
import com.dorr.driver.ui.kit.DorrTextField
import com.dorr.driver.ui.kit.LanguagePill
import com.dorr.driver.ui.kit.PinkBackdrop
import com.dorr.driver.ui.kit.PrimaryButton
import com.dorr.driver.ui.theme.Pal
import kotlinx.coroutines.launch

/** Phase 0 signs in Saudi numbers (the launch country); the country picker comes with more countries. */
private const val DIAL_CODE = "+966"

/** Sign-in, in the DORR app's design: the glowing page, the logo, one rounded phone field and one button. */
@Composable
fun LoginScreen(onCodeSent: (dialCode: String, phone: String, maskedPhone: String, cooldown: Int) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var phone by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    fun submit() {
        if (busy || phone.length < 8) return
        busy = true
        error = null
        scope.launch {
            when (val result = apiCall { DriverBackend.api.requestOtp(PhoneBody(DIAL_CODE, phone)) }) {
                is ApiResult.Ok -> onCodeSent(DIAL_CODE, phone, result.data.maskedPhone ?: phone, result.data.resendCooldownSeconds)
                is ApiResult.Failed -> error = result.error.displayMessage
                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
            }
            busy = false
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
                .padding(top = 12.dp, bottom = 28.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Row(Modifier.fillMaxWidth().widthIn(max = 440.dp), horizontalArrangement = Arrangement.End) { LanguagePill() }
            Spacer(Modifier.height(8.dp))

            Column(Modifier.fillMaxWidth().widthIn(max = 440.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                Text(
                    stringResource(R.string.login_title), color = Pal.accent, fontWeight = FontWeight.ExtraBold,
                    fontSize = 30.sp, lineHeight = 38.sp, textAlign = TextAlign.Center,
                )
                Spacer(Modifier.height(8.dp))
                Text(
                    stringResource(R.string.login_subtitle), fontSize = 14.sp, lineHeight = 22.sp, color = Pal.mut,
                    textAlign = TextAlign.Center, modifier = Modifier.widthIn(max = 260.dp),
                )
                DorrLogo(width = 156.dp, modifier = Modifier.padding(top = 22.dp, bottom = 26.dp))

                // A number reads left to right in Arabic as well.
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    DorrTextField(
                        value = phone,
                        onValueChange = { value -> phone = value.filter(Char::isDigit).take(12); error = null },
                        placeholder = stringResource(R.string.login_phone_label),
                        leading = {
                            Text(DIAL_CODE, color = Pal.ink, fontWeight = FontWeight.Bold, fontSize = 15.sp)
                            Box(Modifier.width(1.dp).height(20.dp).background(Pal.fieldLine))
                        },
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
                        error = error != null,
                        minHeight = 56.dp,
                    )
                }

                Banner(error, Modifier.padding(top = 10.dp))

                Spacer(Modifier.height(16.dp))
                Text(
                    stringResource(R.string.login_terms), color = Pal.faint, fontSize = 12.sp, lineHeight = 18.sp,
                    textAlign = TextAlign.Center, modifier = Modifier.padding(horizontal = 8.dp),
                )
                Spacer(Modifier.height(16.dp))
                PrimaryButton(
                    stringResource(R.string.login_send_code), enabled = phone.length >= 8, busy = busy,
                    icon = Icons.AutoMirrored.Rounded.ArrowForward, onClick = { submit() },
                )
            }
        }
    }
}
