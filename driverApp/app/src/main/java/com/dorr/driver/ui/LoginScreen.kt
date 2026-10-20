package com.dorr.driver.ui

import android.content.Context
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
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowForward
import androidx.compose.material.icons.rounded.CheckBox
import androidx.compose.material.icons.rounded.CheckBoxOutlineBlank
import androidx.compose.material3.Icon
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.R
import com.dorr.driver.net.CountryDto
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.PhoneBody
import com.dorr.driver.ui.kit.Banner
import com.dorr.driver.ui.kit.DorrLogo
import com.dorr.driver.ui.kit.LanguagePill
import com.dorr.driver.ui.kit.PhoneField
import com.dorr.driver.ui.kit.PinkBackdrop
import com.dorr.driver.ui.kit.PrimaryButton
import com.dorr.driver.ui.kit.bounceClick
import com.dorr.driver.ui.kit.formatDialCode
import com.dorr.driver.ui.kit.staggerIn
import com.dorr.driver.ui.theme.Pal
import kotlinx.coroutines.launch

/** Used until the list answers (and if it never does): the launch country. */
private val FALLBACK_COUNTRY = CountryDto(id = 0, code = "SA", name = "", dialCode = "+966", phoneLength = 9, phoneStartsWith = "5", isDefault = true)

/**
 * Sign-in, the DORR app's way: the countries open for sign-in (with flags and a search), the country the phone is in
 * picked for you (the server's guess by IP, else the SIM's), the number checked against that country's length and
 * first digits as it is typed, the terms, then the code.
 */
@Composable
fun LoginScreen(onCodeSent: (dialCode: String, phone: String, maskedPhone: String, cooldown: Int) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var countries by remember { mutableStateOf<List<CountryDto>>(emptyList()) }
    var country by remember { mutableStateOf(FALLBACK_COUNTRY) }
    var phone by remember { mutableStateOf("") }
    var accepted by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(Unit) {
        val listed = (apiCall { DriverBackend.api.countries() } as? ApiResult.Ok)?.data.orEmpty()
        countries = listed
        val default = listed.firstOrNull { it.isDefault } ?: listed.firstOrNull()
        default?.let { country = it }
        // The country the phone is in: the server's guess by IP; when that only gives the default, the SIM's /
        // mobile network's. Either one only if it is in the list.
        val byIp = (apiCall { DriverBackend.api.detectCountry() } as? ApiResult.Ok)?.data?.let { found -> listed.firstOrNull { it.id == found.id } }
        val byNetwork = deviceCountryIso(context)?.let { iso -> listed.firstOrNull { it.code.equals(iso, ignoreCase = true) } }
        val chosen = byIp?.takeIf { !it.isDefault } ?: byNetwork ?: byIp
        // Never over a country picked by hand meanwhile.
        if (chosen != null && country.id == default?.id && phone.isEmpty()) country = chosen
    }

    val length = country.phoneLength ?: 12
    val startsWith = country.phoneStartsWith.orEmpty()
    val phoneProblem: String? = when {
        phone.isEmpty() -> null
        startsWith.isNotEmpty() && !phone.startsWith(startsWith) -> stringResource(R.string.login_error_start, startsWith)
        phone.length != length -> stringResource(R.string.login_error_length, length)
        else -> null
    }
    val valid = phone.length == length && (startsWith.isEmpty() || phone.startsWith(startsWith))
    val canSubmit = valid && accepted && !busy

    fun submit() {
        if (!canSubmit) return
        busy = true
        error = null
        val dial = formatDialCode(country.dialCode)
        scope.launch {
            when (val result = apiCall { DriverBackend.api.requestOtp(PhoneBody(dial, phone)) }) {
                is ApiResult.Ok -> onCodeSent(dial, phone, result.data.maskedPhone ?: "$dial $phone", result.data.resendCooldownSeconds)
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
                    fontSize = 30.sp, lineHeight = 38.sp, textAlign = TextAlign.Center, modifier = Modifier.staggerIn(0),
                )
                Spacer(Modifier.height(8.dp))
                Text(
                    stringResource(R.string.login_subtitle), fontSize = 14.sp, lineHeight = 22.sp, color = Pal.mut,
                    textAlign = TextAlign.Center, modifier = Modifier.widthIn(max = 260.dp).staggerIn(1),
                )
                DorrLogo(width = 156.dp, modifier = Modifier.padding(top = 22.dp, bottom = 26.dp).staggerIn(2))

                PhoneField(
                    countries = countries,
                    selected = country,
                    onCountry = { country = it; if (phone.length > (it.phoneLength ?: 12)) phone = phone.take(it.phoneLength ?: 12); error = null },
                    phone = phone,
                    onPhone = { phone = it; error = null },
                    error = phoneProblem != null,
                    onDone = { submit() },
                    modifier = Modifier.staggerIn(3),
                )
                // What is wrong with the number, as it is typed (the country's length and first digits).
                Banner(phoneProblem, Modifier.padding(top = 8.dp))

                Spacer(Modifier.height(12.dp))
                Row(Modifier.fillMaxWidth().padding(vertical = 4.dp).staggerIn(4), verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(28.dp).bounceClick { accepted = !accepted }, contentAlignment = Alignment.Center) {
                        Icon(
                            if (accepted) Icons.Rounded.CheckBox else Icons.Rounded.CheckBoxOutlineBlank, null,
                            tint = if (accepted) Pal.accent else Pal.line, modifier = Modifier.size(24.dp),
                        )
                    }
                    Spacer(Modifier.width(8.dp))
                    Text(
                        stringResource(R.string.login_terms), color = Pal.mut, fontSize = 13.sp, lineHeight = 20.sp,
                        modifier = Modifier.weight(1f).bounceClick { accepted = !accepted },
                    )
                }

                Spacer(Modifier.height(16.dp))
                PrimaryButton(
                    stringResource(R.string.login_send_code), enabled = valid && accepted, busy = busy,
                    icon = Icons.AutoMirrored.Rounded.ArrowForward, onClick = { submit() }, modifier = Modifier.staggerIn(5),
                )
                Banner(error, Modifier.padding(top = 14.dp))
            }
        }
    }
}

/** The country of the mobile network the phone is on (else its SIM's), as ISO alpha-2; null on Wi-Fi-only devices. */
private fun deviceCountryIso(context: Context): String? = runCatching {
    val tm = context.getSystemService(Context.TELEPHONY_SERVICE) as? android.telephony.TelephonyManager
    listOfNotNull(tm?.networkCountryIso, tm?.simCountryIso).firstOrNull { it.length == 2 }
}.getOrNull()
