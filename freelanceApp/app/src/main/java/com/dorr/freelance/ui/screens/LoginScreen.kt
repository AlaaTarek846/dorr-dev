package com.dorr.freelance.ui.screens

import android.content.Context
import android.telephony.TelephonyManager
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
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.text.ClickableText
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckBox
import androidx.compose.material.icons.rounded.CheckBoxOutlineBlank
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.withStyle
import com.dorr.freelance.ui.components.InlineErrorBanner
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
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.CountryDto
import com.dorr.freelance.net.OtpRequest
import com.dorr.freelance.net.errorText
import com.dorr.freelance.net.formattedDial
import com.dorr.freelance.ui.components.DorrLogo
import com.dorr.freelance.ui.components.LanguageSwitch
import com.dorr.freelance.ui.components.PhoneField
import com.dorr.freelance.ui.kit.AccountDark
import com.dorr.freelance.ui.kit.PinkBackdrop
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsNight
import com.dorr.freelance.ui.theme.AppColors
import com.dorr.freelance.ui.theme.appearanceColor
import kotlinx.coroutines.launch

/**
 * Sign in with the phone number (FL-003): the country and number, then a code by SMS. There is no separate sign-up — the
 * first code creates the account. The countries are read once when the page opens (the DORR app's list).
 */
@Composable
fun LoginScreen(onOtpSent: (dialCode: String, phone: String, cooldownSeconds: Int) -> Unit) {
    var phone by remember { mutableStateOf("") }
    var isLoading by remember { mutableStateOf(false) }
    var errorMessage by remember { mutableStateOf<String?>(null) }
    var countries by remember { mutableStateOf<List<CountryDto>>(emptyList()) }
    var menuExpanded by remember { mutableStateOf(false) }
    // The terms must be agreed to before a code is asked for (the DORR app's sign-in page); the two links open the policies.
    var acceptedTerms by remember { mutableStateOf(false) }
    var showPrivacy by remember { mutableStateOf(false) }
    var showTerms by remember { mutableStateOf(false) }

    var selectedCountryId by remember { mutableStateOf<Int?>(null) }
    var flagCode by remember { mutableStateOf("sa") }
    var dialCode by remember { mutableStateOf("+966") }
    var phoneLength by remember { mutableStateOf(9) }
    var phoneStartsWith by remember { mutableStateOf("") }

    val scope = rememberCoroutineScope()
    val context = LocalContext.current

    fun applyCountry(country: CountryDto) {
        selectedCountryId = country.id
        flagCode = country.flag?.code?.takeIf { it.isNotBlank() } ?: country.code
        dialCode = country.formattedDial()
        val length = country.phoneLength ?: phoneLength
        phoneLength = length
        phoneStartsWith = country.phoneStartsWith.orEmpty()
        if (phone.length > length) phone = phone.take(length)
    }

    // One read when the page opens: the list, then the country the phone is in (by IP; the mobile network's own country when
    // that only gives the default). A country picked by hand meanwhile is not overridden.
    LaunchedEffect(Unit) {
        val listed = (apiCall { ApiClient.general.countries() } as? ApiResult.Ok)?.data.orEmpty()
        countries = listed
        val default = listed.firstOrNull { it.isDefault } ?: listed.firstOrNull()
        default?.let(::applyCountry)
        val byIp = (apiCall { ApiClient.general.detectCountry() } as? ApiResult.Ok)?.data
            ?.let { found -> listed.firstOrNull { it.id == found.id } }
        val byNetwork = deviceCountryIso(context)?.let { iso -> listed.firstOrNull { it.code.equals(iso, ignoreCase = true) } }
        val chosen = byIp?.takeIf { !it.isDefault } ?: byNetwork ?: byIp
        if (chosen != null && selectedCountryId == default?.id && phone.isEmpty()) applyCountry(chosen)
    }

    val isPhoneValid = phone.length == phoneLength && (phoneStartsWith.isEmpty() || phone.startsWith(phoneStartsWith))
    val isFormValid = acceptedTerms && isPhoneValid
    val canSubmit = !isLoading && isFormValid

    val phoneError: String? = when {
        phone.isEmpty() -> null
        phone.length != phoneLength -> stringResource(R.string.login_error_phone_invalid_length, phoneLength)
        phoneStartsWith.isNotEmpty() && !phone.startsWith(phoneStartsWith) -> stringResource(R.string.login_error_phone_invalid_start, phoneStartsWith)
        else -> null
    }
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)

    fun submit() {
        if (!canSubmit) return
        isLoading = true
        errorMessage = null
        scope.launch {
            val result = apiCall { ApiClient.auth.requestOtp(OtpRequest(dialCode = dialCode, phone = phone)) }
            isLoading = false
            when (result) {
                is ApiResult.Ok -> onOtpSent(dialCode, phone, result.data.resendCooldownSeconds ?: 60)
                else -> errorMessage = result.errorText(offlineText) ?: genericError
            }
        }
    }

    val night = settingsNight()
    val accent = if (night) AccountDark.accent else settingsAccent()
    val mut = if (night) AccountDark.mut else appearanceColor("authTextMuted", AppColors.textSecondary, night = false)

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(
            modifier = Modifier
                .fillMaxSize()
                .statusBarsPadding()
                .navigationBarsPadding()
                .imePadding()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 24.dp)
                .padding(top = 12.dp, bottom = 28.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Row(Modifier.fillMaxWidth().widthIn(max = 440.dp), horizontalArrangement = Arrangement.End) {
                LanguageSwitch()
            }
            Spacer(Modifier.height(8.dp))

            Column(Modifier.fillMaxWidth().widthIn(max = 440.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                Text(
                    text = stringResource(R.string.login_title),
                    color = accent,
                    fontWeight = FontWeight.ExtraBold,
                    fontSize = 28.sp,
                    lineHeight = 36.sp,
                    textAlign = TextAlign.Center,
                )
                Spacer(Modifier.height(8.dp))
                Text(
                    text = stringResource(R.string.login_subtitle),
                    fontSize = 14.sp,
                    lineHeight = 22.sp,
                    color = mut,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.widthIn(max = 280.dp),
                )
                DorrLogo(width = 140.dp, onDark = night, modifier = Modifier.padding(top = 22.dp, bottom = 26.dp))

                PhoneField(
                    countries = countries,
                    selectedCountryId = selectedCountryId,
                    flagCode = flagCode,
                    dialCode = dialCode,
                    menuExpanded = menuExpanded,
                    onMenuExpandedChange = { menuExpanded = it },
                    onCountrySelected = {
                        applyCountry(it)
                        menuExpanded = false
                    },
                    phoneStartsWith = phoneStartsWith,
                    phoneLength = phoneLength,
                    phone = phone,
                    onPhoneChange = { phone = it },
                    canSubmit = canSubmit,
                    onSubmit = ::submit,
                )
                InlineErrorBanner(phoneError, Modifier.fillMaxWidth())

                Spacer(Modifier.height(12.dp))
                Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(28.dp).clickable { acceptedTerms = !acceptedTerms }, contentAlignment = Alignment.Center) {
                        Icon(
                            imageVector = if (acceptedTerms) Icons.Rounded.CheckBox else Icons.Rounded.CheckBoxOutlineBlank,
                            contentDescription = null,
                            tint = if (acceptedTerms) accent else if (night) AccountDark.line else Color(0xFFD1D5DB),
                            modifier = Modifier.size(24.dp),
                        )
                    }
                    Spacer(Modifier.width(8.dp))
                    val terms = buildAnnotatedString {
                        val link = SpanStyle(color = accent, textDecoration = TextDecoration.Underline, fontWeight = FontWeight.SemiBold)
                        append(stringResource(R.string.login_terms_prefix))
                        pushStringAnnotation("link", "terms")
                        withStyle(link) { append(stringResource(R.string.login_terms_use)) }
                        pop()
                        append(stringResource(R.string.login_terms_mid))
                        pushStringAnnotation("link", "privacy")
                        withStyle(link) { append(stringResource(R.string.login_terms_privacy)) }
                        pop()
                    }
                    ClickableText(
                        text = terms,
                        style = MaterialTheme.typography.bodySmall.copy(fontSize = 13.sp, lineHeight = 20.sp, color = mut),
                        modifier = Modifier.weight(1f),
                        onClick = { offset ->
                            when (terms.getStringAnnotations("link", offset, offset).firstOrNull()?.item) {
                                "terms" -> showTerms = true
                                "privacy" -> showPrivacy = true
                            }
                        },
                    )
                }

                Spacer(Modifier.height(16.dp))
                SettingsPrimaryButton(
                    text = stringResource(R.string.login_cta),
                    enabled = isFormValid,
                    loading = isLoading,
                    onClick = ::submit,
                )
                InlineErrorBanner(errorMessage, Modifier.fillMaxWidth())
            }
        }
        if (showPrivacy) PrivacyPolicyScreen(onBack = { showPrivacy = false })
        if (showTerms) TermsConditionsScreen(onBack = { showTerms = false })
    }
}

/** The country of the mobile network the phone is on (else its SIM's), as ISO alpha-2; null on Wi-Fi-only devices. */
private fun deviceCountryIso(context: Context): String? = runCatching {
    val tm = context.getSystemService(Context.TELEPHONY_SERVICE) as? TelephonyManager
    listOfNotNull(tm?.networkCountryIso, tm?.simCountryIso).firstOrNull { it.length == 2 }
}.getOrNull()
