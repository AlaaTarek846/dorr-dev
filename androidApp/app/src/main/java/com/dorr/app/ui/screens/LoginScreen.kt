package com.dorr.app.ui.screens

import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.ClickableText
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.DeleteForever
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.KeyboardArrowDown
import androidx.compose.material.icons.rounded.Language
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Search
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextDirection
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.CountryDto
import com.dorr.app.network.CountryCache
import com.dorr.app.network.LanguageDto
import com.dorr.app.network.OtpRequest
import com.dorr.app.network.serverMessage
import com.dorr.app.ui.locale.LocalAppLanguage
import com.dorr.app.ui.screens.profile.PrivacyPolicyScreen
import com.dorr.app.ui.screens.profile.TermsConditionsScreen
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.PinkBackdrop
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.theme.AppColors
import com.dorr.app.ui.theme.LocalThemeState
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

@Composable
fun LoginScreen(
    onOtpRequested: (dialCode: String, phone: String, restoreMode: Boolean) -> Unit,
    sessionExpiredNotice: Boolean = false,
    onDismissSessionExpired: () -> Unit = {},
    accountDeletedNotice: Boolean = false,
    onDismissAccountDeleted: () -> Unit = {},
) {
    LaunchedEffect(sessionExpiredNotice) {
        if (sessionExpiredNotice) {
            delay(5500)
            onDismissSessionExpired()
        }
    }
    LaunchedEffect(accountDeletedNotice) {
        if (accountDeletedNotice) {
            delay(7000)
            onDismissAccountDeleted()
        }
    }
    var phone by remember { mutableStateOf("") }
    var showPrivacy by remember { mutableStateOf(false) }
    var showTerms by remember { mutableStateOf(false) }
    var isLoading by remember { mutableStateOf(false) }
    var errorMessage by remember { mutableStateOf<String?>(null) }
    // A server message is in the language it was asked in: switching language drops it (the next one comes in the new one).
    val languageCode = LocalAppLanguage.current.code
    val firstLanguage = remember { languageCode }
    LaunchedEffect(languageCode) { if (languageCode != firstLanguage) errorMessage = null }
    var countries by remember { mutableStateOf<List<CountryDto>>(emptyList()) }
    var menuExpanded by remember { mutableStateOf(false) }
    // The server said this phone belongs to a deleted (restorable) account — show the Restore card.

    // Seeded default country is Saudi Arabia until dropdown answer.
    var selectedCountryId by remember { mutableStateOf<Int?>(null) }
    var flagCode by remember { mutableStateOf("sa") }
    var dialCode by remember { mutableStateOf("+966") }
    var phoneLength by remember { mutableStateOf(9) }
    var phoneStartsWith by remember { mutableStateOf("") }

    val scope = rememberCoroutineScope()

    // The single place in the app that calls the countries dropdown: the
    // result is cached for every other screen (e.g. editing the phone in
    // the profile), so this endpoint is never hit outside the login flow.
    val context = LocalContext.current

    fun applyCountry(country: CountryDto) {
        selectedCountryId = country.id
        flagCode = country.flag?.code ?: country.code
        dialCode = formatDialCode(country.dialCode)
        val length = country.phoneLength ?: phoneLength
        phoneLength = length
        phoneStartsWith = country.phoneStartsWith.orEmpty()
        if (phone.length > length) phone = phone.take(length)
    }

    LaunchedEffect(Unit) {
        val listed = runCatching { ApiClient.countries.list().data.orEmpty() }.getOrDefault(emptyList())
        countries = listed
        if (listed.isNotEmpty()) CountryCache.save(context, listed)
        val default = listed.firstOrNull { it.isDefault } ?: listed.firstOrNull()
        default?.let(::applyCountry)
        // The country the phone is in: the server's guess by IP; when that only gives the
        // default, the mobile network's / SIM's country. Either one only if it's in the list.
        val byIp = runCatching { ApiClient.countries.detect().data }.getOrNull()
            ?.let { found -> listed.firstOrNull { it.id == found.id } }
        val byNetwork = deviceCountryIso(context)
            ?.let { iso -> listed.firstOrNull { it.code.equals(iso, ignoreCase = true) } }
        val chosen = byIp?.takeIf { !it.isDefault } ?: byNetwork ?: byIp
        // Don't override a country the user already picked by hand meanwhile.
        if (chosen != null && selectedCountryId == default?.id && phone.isEmpty()) applyCountry(chosen)
    }

    val isPhoneValid = phone.length == phoneLength && (phoneStartsWith.isEmpty() || phone.startsWith(phoneStartsWith))
    // No checkbox: «By continuing, you agree to …» sits under the button (agreeing by continuing).
    val isFormValid = isPhoneValid
    val canSubmit = !isLoading && isFormValid

    val genericError = stringResource(R.string.login_error_generic)

    val phoneError: String? = when {
        phone.isEmpty() -> null
        phone.length != phoneLength ->
            stringResource(R.string.login_error_phone_invalid_length, phoneLength)
        phoneStartsWith.isNotEmpty() && !phone.startsWith(phoneStartsWith) ->
            stringResource(R.string.login_error_phone_invalid_start, phoneStartsWith)
        else -> null
    }

    fun submit() {
        if (!canSubmit) return
        isLoading = true
        errorMessage = null
        scope.launch {
            runCatching {
                ApiClient.mobileAuth.requestOtp(
                    OtpRequest(dialCode = dialCode, phone = phone),
                ).data
            }.onSuccess { dto ->
                if (dto?.accountState == "deleted") {
                    // The account was deleted: send the restore code straight away and open the restore page (it says
                    // «Restore your account» itself) — no card to tap first. Back returns here.
                    onDismissAccountDeleted()
                    runCatching {
                        ApiClient.mobileAuth.requestRestoreOtp(OtpRequest(dialCode = dialCode, phone = phone)).data
                    }.onSuccess {
                        isLoading = false
                        onOtpRequested(dialCode, phone, true)
                    }.onFailure {
                        isLoading = false
                        errorMessage = it.serverMessage() ?: genericError
                    }
                } else {
                    isLoading = false
                    onOtpRequested(dialCode, phone, false)
                }
            }.onFailure {
                isLoading = false
                errorMessage = it.serverMessage() ?: genericError
            }
        }
    }

    Box(modifier = Modifier.fillMaxSize()) {
        // Fullscreen edge-to-edge background canvas
        PinkBackdrop(Modifier.fillMaxSize())

        // Safe area content with system insets
        LoginContent(
            phone = phone,
            onOpenPrivacy = { showPrivacy = true },
            onOpenTerms = { showTerms = true },
            isLoading = isLoading,
            isFormValid = isFormValid,
            canSubmit = canSubmit,
            phoneError = phoneError,
            errorMessage = errorMessage,
            countries = countries,
            selectedCountryId = selectedCountryId,
            flagCode = flagCode,
            dialCode = dialCode,
            phoneStartsWith = phoneStartsWith,
            menuExpanded = menuExpanded,
            onMenuExpandedChange = { menuExpanded = it },
            onCountrySelected = {
                applyCountry(it)
                menuExpanded = false
            },
            phoneLength = phoneLength,
            onPhoneChange = { newPhone ->
                if (newPhone.length <= phoneLength) {
                    phone = newPhone
                    errorMessage = null
                }
            },
            onSubmit = ::submit,
        modifier = Modifier
            .fillMaxSize()
                .statusBarsPadding()
                .navigationBarsPadding()
                .imePadding(),
        )

        // Animated Session Expired / Account Deleted Notification Banner
        AnimatedVisibility(
            visible = sessionExpiredNotice || accountDeletedNotice,
            enter = slideInVertically(
                initialOffsetY = { -it },
                animationSpec = tween(400, easing = FastOutSlowInEasing),
            ) + fadeIn(animationSpec = tween(300)),
            exit = slideOutVertically(
                targetOffsetY = { -it },
                animationSpec = tween(300, easing = FastOutSlowInEasing),
            ) + fadeOut(animationSpec = tween(250)),
            modifier = Modifier
                .align(Alignment.TopCenter)
                .statusBarsPadding()
                .padding(top = 16.dp, start = 16.dp, end = 16.dp)
                .widthIn(max = 440.dp),
        ) {
            if (sessionExpiredNotice) {
                SessionExpiredBanner(onDismiss = onDismissSessionExpired)
            } else {
                AccountDeletedBanner(onDismiss = onDismissAccountDeleted)
            }
        }

        if (showPrivacy) {
            BackHandler { showPrivacy = false }
            PrivacyPolicyScreen(onBack = { showPrivacy = false })
        }

        if (showTerms) {
            BackHandler { showTerms = false }
            TermsConditionsScreen(onBack = { showTerms = false })
        }
    }
}

@Composable
private fun SessionExpiredBanner(
    onDismiss: () -> Unit,
    modifier: Modifier = Modifier,
) {
    NoticeBanner(
        title = stringResource(R.string.session_expired_title),
        message = stringResource(R.string.session_expired_message),
        icon = Icons.Rounded.Lock,
        onDismiss = onDismiss,
        modifier = modifier,
    )
}

/** "Your account was deleted — sign in again to restore it." Same shape, calmer colours. */
@Composable
private fun AccountDeletedBanner(
    onDismiss: () -> Unit,
    modifier: Modifier = Modifier,
) {
    NoticeBanner(
        title = stringResource(R.string.account_deleted_title),
        message = stringResource(R.string.account_deleted_message),
        icon = Icons.Rounded.DeleteForever,
        onDismiss = onDismiss,
        modifier = modifier,
    )
}

@Composable
private fun NoticeBanner(
    title: String,
    message: String,
    icon: ImageVector,
    onDismiss: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val isDark = LocalThemeState.current.isDark ?: isSystemInDarkTheme()
    // Signed out / account deleted: the danger red (the admin's), on the app's card.
    val bad = AuthColors.danger(isDark)
    val bgColor = androidx.compose.ui.graphics.lerp(AuthColors.card(isDark), bad, if (isDark) 0.16f else 0.08f)
    val borderColor = AuthColors.statusFrame(bad)
    val iconBgColor = bad.copy(alpha = if (isDark) 0.22f else 0.14f)
    val titleColor = AuthColors.onStatus(bad, isDark)
    val messageColor = AuthColors.mut(isDark)

    Row(
        modifier = modifier
            .fillMaxWidth()
            .shadow(
                elevation = 10.dp,
                shape = RoundedCornerShape(16.dp),
                ambientColor = Color(0x33001B53),
                spotColor = Color(0x33001B53),
            )
            .clip(RoundedCornerShape(16.dp))
            .background(bgColor)
            .border(1.dp, borderColor, RoundedCornerShape(16.dp))
            .padding(horizontal = 14.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            modifier = Modifier
                .size(40.dp)
                .clip(CircleShape)
                .background(iconBgColor),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                imageVector = icon,
                contentDescription = null,
                tint = titleColor,
                modifier = Modifier.size(20.dp),
            )
        }

        Spacer(modifier = Modifier.width(12.dp))

        Column(modifier = Modifier.weight(1f)) {
            Text(
                text = title,
                style = MaterialTheme.typography.bodyMedium.copy(
                    fontWeight = FontWeight.Bold,
                    fontSize = 13.5.sp,
                ),
                color = titleColor,
            )
            Spacer(modifier = Modifier.height(2.dp))
            Text(
                text = message,
                style = MaterialTheme.typography.bodySmall.copy(
                    fontSize = 12.sp,
                    lineHeight = 16.sp,
                ),
                color = messageColor,
            )
        }

        Spacer(modifier = Modifier.width(8.dp))

        IconButton(
            onClick = onDismiss,
            modifier = Modifier.size(28.dp),
        ) {
            Icon(
                imageVector = Icons.Rounded.Close,
                contentDescription = "Dismiss",
                tint = messageColor.copy(alpha = 0.8f),
                modifier = Modifier.size(16.dp),
            )
        }
    }
}

@Composable
private fun LoginContent(
    phone: String,
    onOpenPrivacy: () -> Unit,
    onOpenTerms: () -> Unit,
    isLoading: Boolean,
    isFormValid: Boolean,
    canSubmit: Boolean,
    phoneError: String?,
    errorMessage: String?,
    countries: List<CountryDto>,
    selectedCountryId: Int?,
    flagCode: String,
    dialCode: String,
    phoneStartsWith: String,
    menuExpanded: Boolean,
    onMenuExpandedChange: (Boolean) -> Unit,
    onCountrySelected: (CountryDto) -> Unit,
    phoneLength: Int,
    onPhoneChange: (String) -> Unit,
    onSubmit: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val scrollState = rememberScrollState()
    val night = settingsNight()
    val accent = if (night) AccountDark.accent else settingsAccent()
    val mut = if (night) {
        AccountDark.mut
    } else {
        com.dorr.app.ui.theme.appearanceColor("authTextMuted", Color(0xFF6B7280), night = false)
    }

    Column(
        modifier = modifier
            .verticalScroll(scrollState)
            .padding(horizontal = 24.dp)
            .padding(top = 12.dp, bottom = 28.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .widthIn(max = 440.dp),
            horizontalArrangement = Arrangement.End,
        ) {
            LanguagePicker()
        }

        Spacer(Modifier.height(8.dp))

        Column(
            modifier = Modifier
                .fillMaxWidth()
                .widthIn(max = 440.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            // Over the phone field: the full Dorr logo (the mark over the name — the dark-letters one by day, the
            // white-letters one by night), «Welcome to Dorr» and what happens next.
            androidx.compose.foundation.Image(
                painter = androidx.compose.ui.res.painterResource(if (night) R.drawable.dorr_logo_full_dark else R.drawable.dorr_logo_full_light),
                contentDescription = stringResource(R.string.app_name),
                contentScale = androidx.compose.ui.layout.ContentScale.Fit,
                modifier = Modifier.padding(top = 4.dp).width(96.dp),
            )

            Spacer(Modifier.height(18.dp))

            Text(
                text = stringResource(R.string.login_title),
                color = accent,
                fontWeight = FontWeight.ExtraBold,
                fontSize = 22.sp,
                lineHeight = 30.sp,
                textAlign = TextAlign.Center,
            )

            Spacer(Modifier.height(4.dp))

            Text(
                text = stringResource(R.string.login_subtitle),
                fontSize = 14.sp,
                lineHeight = 22.sp,
                color = mut,
                textAlign = TextAlign.Center,
                modifier = Modifier.widthIn(max = 320.dp),
            )

            Spacer(Modifier.height(20.dp))

            // «Phone number», the field (the country's flag and code, then the number grouped as it's typed) and,
            // under it, what a number here looks like — or what's wrong with it.
            PhoneField(
                countries = countries,
                selectedCountryId = selectedCountryId,
                flagCode = flagCode,
                dialCode = dialCode,
                menuExpanded = menuExpanded,
                onMenuExpandedChange = onMenuExpandedChange,
                onCountrySelected = onCountrySelected,
                phoneStartsWith = phoneStartsWith,
                phoneLength = phoneLength,
                phone = phone,
                onPhoneChange = onPhoneChange,
                canSubmit = canSubmit,
                onSubmit = onSubmit,
                // The field's own check first, else what the server said («wait 60 s…») — both under the field.
                error = phoneError ?: errorMessage,
            )

            Spacer(Modifier.height(20.dp))

            // «Send verification code»: filled in the app's colour once the number is right; before that a soft grey
            // fill with grey words (no outline) — clearly not ready yet.
            val buttonShape = RoundedCornerShape(999.dp)
            val disabledFill = loginSoftFill(night, 0.6f)
            val disabledLabel = if (night) AccountDark.mut else com.dorr.app.ui.theme.appearanceColor("textMuted", Color(0xFF9CA3AF), night = false)
        Button(
                onClick = onSubmit,
                enabled = canSubmit,
                colors = ButtonDefaults.buttonColors(
                    containerColor = accent,
                    disabledContainerColor = if (isLoading) accent else disabledFill,
                    contentColor = Color.White,
                    disabledContentColor = if (isLoading) Color.White else disabledLabel,
                ),
                shape = buttonShape,
                modifier = Modifier
                    .fillMaxWidth()
                    .height(52.dp)
                    .then(
                        if (isFormValid && !isLoading) {
                            Modifier.shadow(
                                elevation = 8.dp,
                                shape = buttonShape,
                                ambientColor = accent.copy(alpha = 0.4f),
                                spotColor = accent.copy(alpha = 0.4f),
                            )
                        } else {
                            Modifier
                        },
                    ),
            ) {
                if (isLoading) {
                    CircularProgressIndicator(
                        modifier = Modifier.size(24.dp),
                        strokeWidth = 2.5.dp,
                        color = Color.White,
                    )
                } else {
                    Text(
                        text = stringResource(R.string.login_cta),
                        fontSize = 16.sp,
                        fontWeight = FontWeight.Bold,
                    )
                }
            }

            // «By continuing, you agree to the Terms of Use and the Privacy Policy» — each link in the app's colour,
            // underlined, opening its page. Continuing is the agreement (no checkbox).
            Spacer(Modifier.height(14.dp))
            val terms = buildAnnotatedString {
                val link = SpanStyle(
                    color = accent,
                    textDecoration = TextDecoration.Underline,
                    fontWeight = FontWeight.SemiBold,
                )
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
                style = MaterialTheme.typography.bodySmall.copy(
                    fontSize = 12.sp,
                    lineHeight = 19.sp,
                    color = mut,
                    textAlign = TextAlign.Center,
                ),
                modifier = Modifier.fillMaxWidth(),
                onClick = { offset ->
                    when (terms.getStringAnnotations("link", offset, offset).firstOrNull()?.item) {
                        "terms" -> onOpenTerms()
                        "privacy" -> onOpenPrivacy()
                    }
                },
            )
        }
    }
}

@Composable
private fun PhoneField(
    countries: List<CountryDto>,
    selectedCountryId: Int?,
    flagCode: String,
    dialCode: String,
    menuExpanded: Boolean,
    onMenuExpandedChange: (Boolean) -> Unit,
    onCountrySelected: (CountryDto) -> Unit,
    phoneStartsWith: String,
    phoneLength: Int,
    phone: String,
    onPhoneChange: (String) -> Unit,
    canSubmit: Boolean,
    onSubmit: () -> Unit,
    error: String?,
) {
    var countryQuery by remember { mutableStateOf("") }
    var isFocused by remember { mutableStateOf(false) }
    val night = settingsNight()

    LaunchedEffect(menuExpanded) {
        if (!menuExpanded) countryQuery = ""
    }

    val visibleCountries = countries.filter { country ->
        val query = countryQuery.trim()
        query.isEmpty()
            || country.name.contains(query, ignoreCase = true)
            || country.code.contains(query, ignoreCase = true)
            || country.dialCode.contains(query, ignoreCase = true)
    }

    val ink = AuthColors.ink(night)
    val mut = AuthColors.mut(night)
    val danger = AuthColors.danger(night)
    val line = AuthColors.line(night)
    // The frame: the app's colour while typing, red with an error, the soft line otherwise.
    val borderColor = when {
        error != null -> danger
        isFocused -> settingsAccent()
        else -> line
    }
    val borderWidth = if (isFocused || error != null) 1.5.dp else 1.dp
    val groups = phoneGroups(phoneLength)

    Column(Modifier.fillMaxWidth()) {
    Text(
        text = stringResource(R.string.login_phone_label),
        color = ink,
        fontSize = 13.5.sp,
        fontWeight = FontWeight.Bold,
        modifier = Modifier.padding(start = 2.dp, bottom = 8.dp),
    )
    // The number reads left-to-right in every language: the country on the left, the number after it.
    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
        Surface(
            modifier = Modifier
                .fillMaxWidth()
                .height(52.dp),
            shape = RoundedCornerShape(14.dp),
            color = AuthColors.card(night),
            border = androidx.compose.foundation.BorderStroke(borderWidth, borderColor),
        ) {
            Row(
                modifier = Modifier.fillMaxSize(),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                // The country: its flag, its code and a caret, on a soft background — it opens the list.
                Box(Modifier.fillMaxHeight()) {
                    Row(
                        modifier = Modifier
                            .fillMaxHeight()
                            .background(loginSoftFill(night, 0.4f))
                            .clickable(enabled = countries.isNotEmpty()) { onMenuExpandedChange(true) }
                            .padding(start = 12.dp, end = 8.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        FlagImage(flagCode)
                        Spacer(Modifier.width(6.dp))
                        Text(
                            text = dialCode,
                            style = TextStyle(
                                fontSize = 15.sp,
                                fontWeight = FontWeight.Bold,
                                color = ink,
                            ),
                        )
                        if (countries.isNotEmpty()) {
                            Spacer(Modifier.width(2.dp))
                            Icon(
                                Icons.Rounded.KeyboardArrowDown,
                                contentDescription = stringResource(R.string.login_country_code),
                                tint = AuthColors.mut(night),
                                modifier = Modifier.size(18.dp),
                            )
                        }
                    }

                    DropdownMenu(
                        expanded = menuExpanded && countries.isNotEmpty(),
                        onDismissRequest = { onMenuExpandedChange(false) },
                        containerColor = AuthColors.card(night),
                        shape = RoundedCornerShape(16.dp),
                        shadowElevation = 10.dp,
                        modifier = Modifier
                            .heightIn(max = 340.dp)
                            .widthIn(min = 260.dp),
                    ) {
                        // Search bar in dropdown
                        Row(
                            modifier = Modifier
                                .fillMaxWidth()
                                .padding(8.dp)
                                .background(loginSoftFill(night, 0.5f), RoundedCornerShape(10.dp))
                                .padding(horizontal = 10.dp, vertical = 8.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(
                                Icons.Rounded.Search,
                                contentDescription = null,
                                tint = AuthColors.soft(night),
                                modifier = Modifier.size(18.dp),
                            )
                            Spacer(Modifier.width(8.dp))
                            BasicTextField(
                                value = countryQuery,
                                onValueChange = { countryQuery = it },
                                singleLine = true,
                                textStyle = TextStyle(
                                    fontSize = 14.sp,
                                    color = AuthColors.ink(night),
                                ),
                                decorationBox = { inner ->
                                    if (countryQuery.isEmpty()) {
                                        Text(
                                            text = stringResource(R.string.login_country_search),
                                            color = AuthColors.soft(night),
                                            fontSize = 14.sp,
                                        )
                                    }
                                    inner()
                                },
                                modifier = Modifier.fillMaxWidth(),
                            )
                        }

                        visibleCountries.forEach { country ->
                            val selected = country.id == selectedCountryId
                            DropdownMenuItem(
                                text = {
                                    Row(
                                        modifier = Modifier.fillMaxWidth(),
                                        verticalAlignment = Alignment.CenterVertically,
                                    ) {
                                        FlagImage(country.flag?.code ?: country.code)
                                        Spacer(Modifier.width(10.dp))
                                        Text(
                                            text = country.name.ifBlank { country.code },
                                            modifier = Modifier.weight(1f),
                                            maxLines = 1,
                                            overflow = TextOverflow.Ellipsis,
                                            color = if (selected) (if (night) AccountDark.accent else settingsAccent()) else AuthColors.ink(night),
                                            fontWeight = if (selected) FontWeight.Bold else FontWeight.Normal,
                                            fontSize = 14.sp,
                                        )
                                        Spacer(Modifier.width(8.dp))
                                        Text(
                                            text = formatDialCode(country.dialCode),
                                            color = if (selected) (if (night) AccountDark.accent else settingsAccent()) else AuthColors.mut(night),
                                            fontWeight = FontWeight.SemiBold,
                                            fontSize = 13.sp,
                                        )
                                    }
                                },
                                onClick = { onCountrySelected(country) },
                                modifier = if (selected) Modifier.background(if (night) AccountDark.well else settingsAccent().copy(alpha = 0.14f)) else Modifier,
                            )
                        }
                    }
                }

                Box(Modifier.fillMaxHeight().width(1.dp).background(line))
                Spacer(Modifier.width(14.dp))

                // «1X XXXX XXXX»: the digits a number starts with, then X for the rest, grouped like the typed number.
                val placeholder = groupPhone(phoneStartsWith + "X".repeat((phoneLength - phoneStartsWith.length).coerceAtLeast(0)), groups)
                BasicTextField(
                    value = phone,
                    onValueChange = { input ->
                        val filtered = input.filter { it.isDigit() }
                        if (filtered.length <= phoneLength) {
                            onPhoneChange(filtered)
                        }
                    },
                    singleLine = true,
                    textStyle = TextStyle(
                        fontSize = 16.sp,
                        fontWeight = FontWeight.SemiBold,
                        color = ink,
                        textDirection = TextDirection.Ltr,
                    ),
                    // Shown in groups («10 1234 5678»); the number itself stays plain digits (sent as is).
                    visualTransformation = PhoneGroupsTransformation(groups),
                    keyboardOptions = KeyboardOptions(
                        keyboardType = KeyboardType.Phone,
                        imeAction = if (canSubmit) ImeAction.Done else ImeAction.Default,
                    ),
                    keyboardActions = KeyboardActions(
                        onDone = {
                            if (canSubmit) onSubmit()
                        },
                    ),
                    cursorBrush = SolidColor(settingsAccent()),
                    modifier = Modifier
                        .weight(1f)
                        .onFocusChanged { isFocused = it.isFocused },
                    decorationBox = { innerTextField ->
                        Box(
                            modifier = Modifier.fillMaxWidth(),
                            contentAlignment = Alignment.CenterStart,
                        ) {
                            if (phone.isEmpty()) {
                                Text(
                                    text = placeholder,
                                    color = AuthColors.soft(night),
                                    fontSize = 16.sp,
                                    fontWeight = FontWeight.SemiBold,
                                )
                            }
                            innerTextField()
                        }
                    },
                )

                // Quick Clear Button
                if (phone.isNotEmpty()) {
                    IconButton(
                        onClick = { onPhoneChange("") },
                        modifier = Modifier.size(28.dp),
                    ) {
                        Icon(
                            Icons.Rounded.Close,
                            contentDescription = "Clear",
                            tint = AuthColors.soft(night),
                            modifier = Modifier.size(16.dp),
                        )
                    }
                }
                Spacer(Modifier.width(8.dp))
            }
        }
    }
    // Under the field: what a number here looks like («10 digits starting with 1»), or what's wrong with it, in red.
    val hint = if (phoneLength <= 0) "" else
        pluralStringResource(R.plurals.login_phone_digits, phoneLength, phoneLength.toString()) +
            (if (phoneStartsWith.isNotEmpty()) " " + stringResource(R.string.login_phone_starts, phoneStartsWith) else "")
    if (error != null) {
        // What's wrong (the field's check or the server's answer): a red line with its icon, like the OTP page.
        val bad = AuthColors.onStatus(danger, night)
        Row(Modifier.padding(start = 2.dp, top = 6.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.ErrorOutline, contentDescription = null, tint = bad, modifier = Modifier.size(15.dp))
            Spacer(Modifier.width(5.dp))
            Text(text = error, color = bad, fontSize = 12.5.sp, fontWeight = FontWeight.Medium)
        }
    } else if (hint.isNotEmpty()) {
        Text(
            text = hint,
            color = mut,
            fontSize = 12.sp,
            modifier = Modifier.padding(start = 2.dp, top = 6.dp),
        )
    }
    }
}

/**
 * The sign-in's soft fill — the country part of the phone field ([amount] 0.4) and «Send» before the number is right
 * (0.6): the app's card colour mixed with its frame colour, so it follows the admin's colours by day and by night
 * (was a fixed grey by day and a fixed brown, `AccountDark.well`, by night). To go back: `if (night) AccountDark.well
 * else Color(0xFFF5F6F8)` / `Color(0xFFECEEF2)`.
 */
@Composable
internal fun loginSoftFill(night: Boolean, amount: Float): Color = if (night) {
    androidx.compose.ui.graphics.lerp(AccountDark.card, AccountDark.line, amount)
} else {
    androidx.compose.ui.graphics.lerp(
        com.dorr.app.ui.theme.appearanceColor("surface", Color.White, night = false),
        com.dorr.app.ui.theme.appearanceColor("border", Color(0xFFE5E7EB), night = false),
        amount,
    )
}

/** How a phone number of [length] digits is grouped: two digits, then fours (the first of those shorter if need be). */
internal fun phoneGroups(length: Int): List<Int> {
    if (length <= 4) return listOf(length.coerceAtLeast(1))
    val rest = length - 2
    val tail = generateSequence(rest) { it - 4 }.takeWhile { it > 0 }.map { minOf(it, 4) }.toList().reversed()
    return listOf(2) + tail
}

/** [digits] with a space between the [groups] («1012345678» → «10 1234 5678»). */
internal fun groupPhone(digits: String, groups: List<Int>): String {
    val out = StringBuilder()
    var i = 0
    for (size in groups) {
        if (i >= digits.length) break
        if (out.isNotEmpty()) out.append(' ')
        out.append(digits, i, minOf(i + size, digits.length))
        i += size
    }
    if (i < digits.length) out.append(digits, i, digits.length)
    return out.toString()
}

/** Shows the typed digits grouped with spaces; the value stays plain digits, and the cursor maps across the spaces. */
private class PhoneGroupsTransformation(private val groups: List<Int>) : androidx.compose.ui.text.input.VisualTransformation {
    override fun filter(text: androidx.compose.ui.text.AnnotatedString): androidx.compose.ui.text.input.TransformedText {
        val raw = text.text
        val shown = groupPhone(raw, groups)
        // Where each group ends in the raw digits: a space follows it in the shown text.
        val ends = groups.runningReduce { acc, g -> acc + g }
        val mapping = object : androidx.compose.ui.text.input.OffsetMapping {
            override fun originalToTransformed(offset: Int): Int = offset + ends.count { it < offset && it < raw.length }
            override fun transformedToOriginal(offset: Int): Int {
                var digits = 0
                for (i in 0 until minOf(offset, shown.length)) if (shown[i] != ' ') digits++
                return digits.coerceAtMost(raw.length)
            }
        }
        return androidx.compose.ui.text.input.TransformedText(androidx.compose.ui.text.AnnotatedString(shown), mapping)
    }
}

@Composable
private fun BrandName() {
    var name by remember { mutableStateOf("") }
    LaunchedEffect(Unit) {
        name = runCatching { ApiClient.branding.get().data?.appName.orEmpty() }.getOrDefault("")
    }
    if (name.isBlank()) return
    Spacer(Modifier.height(8.dp))
    Text(
        text = name,
        color = settingsAccent(),
        fontWeight = FontWeight.Bold,
        fontSize = 15.sp,
        textAlign = TextAlign.Center,
    )
}

/** A language's own name ("English", "العربية") — what the two-language switch shows; others keep the server's name. */
private fun nativeLanguageName(code: String, fallback: String?): String = when (code.lowercase()) {
    "ar" -> "العربية"
    "en" -> "English"
    else -> fallback ?: code.uppercase()
}

/**
 * The language button. With two languages (Arabic and English, or the list not loaded) it shows the *other* one in
 * its own name — «English» while the app is in Arabic — and one tap switches straight to it. With three or more it
 * shows the current one with a caret and opens the list to pick from.
 */
@Composable
internal fun LanguagePicker() {
    val appLanguage = LocalAppLanguage.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val downloadFailed = stringResource(R.string.language_download_failed)
    var languages by remember { mutableStateOf<List<LanguageDto>>(emptyList()) }
    var expanded by remember { mutableStateOf(false) }
    var selected by remember { mutableStateOf<LanguageDto?>(null) }

    LaunchedEffect(appLanguage.code) {
        val listed = runCatching { ApiClient.languages.list().data.orEmpty() }.getOrDefault(emptyList())
        languages = listed
        selected = listed.find { it.code.equals(appLanguage.code, ignoreCase = true) }
            ?: listed.find { it.code.equals("ar", ignoreCase = true) }
            ?: listed.firstOrNull()
    }

    val night = settingsNight()
    val caret = settingsAccent()
    // Two languages (or none listed yet — the app ships Arabic and English): a one-tap switch to the other.
    val toggle = languages.size <= 2
    val otherCode = languages.firstOrNull { !it.code.equals(appLanguage.code, ignoreCase = true) }?.code
        ?: if (appLanguage.code.equals("ar", ignoreCase = true)) "en" else "ar"
    val otherName = nativeLanguageName(otherCode, languages.firstOrNull { it.code.equals(otherCode, ignoreCase = true) }?.name)
    Box {
        Surface(
            shape = RoundedCornerShape(999.dp),
            color = Color.Transparent,
            border = androidx.compose.foundation.BorderStroke(1.dp, AuthColors.line(night)),
            modifier = Modifier.clickable {
                if (toggle) {
                    scope.launch {
                        if (!appLanguage.choose(context, otherCode)) {
                            android.widget.Toast.makeText(context, downloadFailed, android.widget.Toast.LENGTH_SHORT).show()
                        }
                    }
                } else if (languages.isNotEmpty()) {
                    expanded = true
                }
            },
        ) {
    Row(
        modifier = Modifier
                    .height(34.dp)
            .padding(horizontal = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
                Icon(
                    Icons.Rounded.Language,
                    contentDescription = null,
                    tint = caret,
                    modifier = Modifier.size(18.dp),
                )
                Spacer(Modifier.width(7.dp))
                Text(
                    text = if (toggle) otherName else selected?.name ?: stringResource(R.string.language_arabic),
                    color = if (night) AccountDark.ink else caret,
                    fontWeight = FontWeight.Bold,
                    fontSize = 13.sp,
                )
                if (!toggle) Spacer(Modifier.width(8.dp))
                if (!toggle) Canvas(Modifier.size(8.dp, 5.dp)) {
                    val path = androidx.compose.ui.graphics.Path().apply {
                        moveTo(0f, 0f)
                        lineTo(size.width, 0f)
                        lineTo(size.width / 2f, size.height)
                        close()
                    }
                    drawPath(path, caret)
                }
            }
        }
        DropdownMenu(
            expanded = expanded,
            onDismissRequest = { expanded = false },
            containerColor = AuthColors.card(night),
            shape = RoundedCornerShape(16.dp),
            shadowElevation = 8.dp,
        ) {
            languages.forEach { language ->
                val isSelected = language.code.equals(selected?.code, ignoreCase = true)
                DropdownMenuItem(
                    text = {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            FlagImage(languageFlagCode(language))
        Spacer(Modifier.width(8.dp))
                            Text(
                                language.name,
                                color = if (isSelected) (if (night) AccountDark.accent else settingsAccent()) else AuthColors.ink(night),
                                fontWeight = if (isSelected) FontWeight.Bold else FontWeight.Medium,
                                fontSize = 13.sp,
                            )
                        }
                    },
                    trailingIcon = if (isSelected) {
                        {
                            Icon(
                                Icons.Rounded.Check,
                                contentDescription = null,
                                tint = settingsAccent(),
                                modifier = Modifier.size(16.dp),
                            )
                        }
                    } else null,
                    onClick = {
                        expanded = false
                        scope.launch {
                            if (appLanguage.choose(context, language.code)) {
                                selected = language
                            } else {
                                android.widget.Toast.makeText(context, downloadFailed, android.widget.Toast.LENGTH_SHORT).show()
                            }
                        }
                    },
                    modifier = if (isSelected) Modifier.background(if (night) AccountDark.well else settingsAccent().copy(alpha = 0.14f)) else Modifier,
                )
            }
        }
    }
}

@Composable
private fun FlagImage(code: String?) {
    val iso = code?.trim()?.lowercase().orEmpty()
    if (iso.length != 2) return
    AsyncImage(
        model = "https://flagcdn.com/w40/$iso.png",
        contentDescription = null,
        contentScale = ContentScale.Crop,
        modifier = Modifier
            .size(width = 20.dp, height = 15.dp)
            .clip(RoundedCornerShape(2.dp)),
    )
}

private fun languageFlagCode(language: LanguageDto?): String {
    val fromFlag = language?.flag?.code?.trim()?.lowercase().orEmpty()
    if (fromFlag.length == 2) return fromFlag
    return when (language?.code?.lowercase()) {
        "ar" -> "sa"
        "en" -> "us"
        else -> language?.code?.lowercase()?.take(2).orEmpty()
    }
}

private fun formatDialCode(value: String): String {
    val digits = value.trim().removePrefix("+")
    return if (digits.isEmpty()) "" else "+$digits"
}

/** The country of the mobile network the phone is on (else its SIM's), as ISO alpha-2; null on Wi-Fi-only devices. */
private fun deviceCountryIso(context: android.content.Context): String? = runCatching {
    val tm = context.getSystemService(android.content.Context.TELEPHONY_SERVICE) as? android.telephony.TelephonyManager
    listOfNotNull(tm?.networkCountryIso, tm?.simCountryIso).firstOrNull { it.length == 2 }
}.getOrNull()
