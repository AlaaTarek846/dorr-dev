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
import androidx.compose.material.icons.rounded.CheckBox
import androidx.compose.material.icons.rounded.CheckBoxOutlineBlank
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.DeleteForever
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.KeyboardArrowDown
import androidx.compose.material.icons.rounded.Language
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Phone
import androidx.compose.material.icons.rounded.Restore
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
import com.dorr.app.ui.components.DorrLogo
import com.dorr.app.ui.locale.LocalAppLanguage
import com.dorr.app.ui.screens.profile.PrivacyPolicyScreen
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
    var acceptedTerms by remember { mutableStateOf(false) }
    var showPrivacy by remember { mutableStateOf(false) }
    var isLoading by remember { mutableStateOf(false) }
    var errorMessage by remember { mutableStateOf<String?>(null) }
    var countries by remember { mutableStateOf<List<CountryDto>>(emptyList()) }
    var menuExpanded by remember { mutableStateOf(false) }
    // The server said this phone belongs to a deleted (restorable) account — show the Restore card.
    var showRestoreCard by remember { mutableStateOf(false) }
    var restoreRequesting by remember { mutableStateOf(false) }

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
    val isFormValid = acceptedTerms && isPhoneValid
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
        showRestoreCard = false
        scope.launch {
            runCatching {
                ApiClient.mobileAuth.requestOtp(
                    OtpRequest(dialCode = dialCode, phone = phone),
                ).data
            }.onSuccess { dto ->
                isLoading = false
                if (dto?.accountState == "deleted") {
                    // The account exists but was deleted — offer the restore step
                    // instead of the normal OTP flow.
                    onDismissAccountDeleted()
                    showRestoreCard = true
                } else {
                    onOtpRequested(dialCode, phone, false)
                }
            }.onFailure {
                isLoading = false
                errorMessage = it.serverMessage() ?: genericError
            }
        }
    }

    /** The user picked "Restore Account": send the restore OTP, then verify via the OTP screen. */
    fun restoreAccount() {
        if (restoreRequesting) return
        restoreRequesting = true
        errorMessage = null
        scope.launch {
            runCatching {
                ApiClient.mobileAuth.requestRestoreOtp(
                    OtpRequest(dialCode = dialCode, phone = phone),
                ).data
            }.onSuccess {
                restoreRequesting = false
                onOtpRequested(dialCode, phone, true)
            }.onFailure {
                restoreRequesting = false
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
            acceptedTerms = acceptedTerms,
            onAcceptedTermsChange = { acceptedTerms = it },
            onOpenPrivacy = { showPrivacy = true },
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
                    if (showRestoreCard) showRestoreCard = false
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

        // A deleted (but restorable) account — the user taps Restore to jump into
        // the OTP flow and bring the account back.
        AnimatedVisibility(
            visible = showRestoreCard,
            enter = fadeIn(animationSpec = tween(300)) + slideInVertically(
                initialOffsetY = { -it },
                animationSpec = tween(350, easing = FastOutSlowInEasing),
            ),
            exit = fadeOut(animationSpec = tween(200)) + slideOutVertically(
                targetOffsetY = { -it },
                animationSpec = tween(250, easing = FastOutSlowInEasing),
            ),
            modifier = Modifier
                .align(Alignment.TopCenter)
                .statusBarsPadding()
                .padding(top = 16.dp, start = 16.dp, end = 16.dp)
                .widthIn(max = 440.dp),
        ) {
            RestoreAccountCard(
                restoring = restoreRequesting,
                onRestore = ::restoreAccount,
                onDismiss = { showRestoreCard = false },
            )
        }

        if (showPrivacy) {
            BackHandler { showPrivacy = false }
            PrivacyPolicyScreen(onBack = { showPrivacy = false })
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

/** The login attempt hit a soft-deleted account — offer to restore it via OTP. */
@Composable
private fun RestoreAccountCard(
    restoring: Boolean,
    onRestore: () -> Unit,
    onDismiss: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val isDark = LocalThemeState.current.isDark ?: isSystemInDarkTheme()
    val bgColor = if (isDark) Color(0xFF1F2A24) else Color(0xFFF0FDF4)
    val borderColor = if (isDark) AppColors.success.copy(alpha = 0.45f) else Color(0xFF86EFAC)
    val titleColor = if (isDark) Color(0xFFDCFCE7) else Color(0xFF14532D)
    val messageColor = if (isDark) Color(0xFFA7C4B2) else Color(0xFF166534)
    val accent = settingsAccent()

    Row(
        modifier = modifier
            .fillMaxWidth()
            .shadow(
                elevation = 10.dp,
                shape = RoundedCornerShape(16.dp),
                ambientColor = Color(0x33E50914),
                spotColor = Color(0x33E50914),
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
                .background(if (isDark) Color(0xFF2F3D45) else Color(0xFFDCFCE7)),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                imageVector = Icons.Rounded.Restore,
                contentDescription = null,
                tint = accent,
                modifier = Modifier.size(20.dp),
            )
        }

        Spacer(modifier = Modifier.width(12.dp))

        Column(modifier = Modifier.weight(1f)) {
            Text(
                text = stringResource(R.string.account_deleted_title),
                style = MaterialTheme.typography.bodyMedium.copy(
                    fontWeight = FontWeight.Bold,
                    fontSize = 13.5.sp,
                ),
                color = titleColor,
            )
            Spacer(modifier = Modifier.height(2.dp))
            Text(
                text = stringResource(R.string.account_restore_message),
                style = MaterialTheme.typography.bodySmall.copy(
                    fontSize = 12.sp,
                    lineHeight = 16.sp,
                ),
                color = messageColor,
            )
            Spacer(modifier = Modifier.height(8.dp))
            Box(
                modifier = Modifier
                    .clip(RoundedCornerShape(10.dp))
                    .background(accent.copy(alpha = 0.14f))
                    .clickable(enabled = !restoring, onClick = onRestore)
                    .padding(horizontal = 12.dp, vertical = 7.dp),
            ) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    if (restoring) {
                        CircularProgressIndicator(
                            color = accent,
                            strokeWidth = 2.dp,
                            modifier = Modifier.size(14.dp),
                        )
                        Spacer(Modifier.width(6.dp))
                    }
                    Text(
                        text = stringResource(R.string.account_restore_action),
                        color = accent,
                        fontSize = 13.sp,
                        fontWeight = FontWeight.Bold,
                    )
                }
            }
        }

        Spacer(modifier = Modifier.width(8.dp))

        IconButton(
            onClick = onDismiss,
            modifier = Modifier.size(28.dp),
        ) {
            Icon(
                imageVector = Icons.Rounded.Close,
                contentDescription = stringResource(R.string.common_close),
                tint = messageColor.copy(alpha = 0.8f),
                modifier = Modifier.size(16.dp),
            )
        }
    }
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
    val bgColor = if (isDark) Color(0xFF2B191C) else Color(0xFFFFF5F5)
    val borderColor = if (isDark) settingsAccent().copy(alpha = 0.45f) else Color(0xFFFCA5A5)
    val iconBgColor = if (isDark) Color(0xFF4A1E24) else Color(0xFFFEE2E2)
    val titleColor = if (isDark) Color(0xFFFDE8E8) else Color(0xFF991B1B)
    val messageColor = if (isDark) Color(0xFFE5C0C4) else Color(0xFF7F1D1D)

    Row(
        modifier = modifier
            .fillMaxWidth()
            .shadow(
                elevation = 10.dp,
                shape = RoundedCornerShape(16.dp),
                ambientColor = Color(0x33E50914),
                spotColor = Color(0x33E50914),
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
                tint = settingsAccent(),
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
    acceptedTerms: Boolean,
    onAcceptedTermsChange: (Boolean) -> Unit,
    onOpenPrivacy: () -> Unit,
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
            Text(
                text = stringResource(R.string.login_title),
                color = accent,
                fontWeight = FontWeight.ExtraBold,
                fontSize = 30.sp,
                lineHeight = 38.sp,
                textAlign = TextAlign.Center,
            )

            Spacer(Modifier.height(8.dp))

            Text(
                text = stringResource(R.string.login_subtitle),
                fontSize = 14.sp,
                lineHeight = 22.sp,
                color = mut,
                textAlign = TextAlign.Center,
                modifier = Modifier.widthIn(max = 260.dp),
            )

            DorrLogo(
                width = 156.dp,
                onDark = night,
                modifier = Modifier.padding(top = 22.dp, bottom = 26.dp),
            )

            // Phone Input Field (56.dp height, perfectly centered, no text cutoff)
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
            )

            // Validation Error Message
            AnimatedVisibility(
                visible = phoneError != null,
                enter = fadeIn() + expandVertically(),
                exit = fadeOut() + shrinkVertically(),
            ) {
                phoneError?.let { message ->
                    val errorInk = if (night) Color(0xFFFFB4B4) else AppColors.danger
                    val errorBg = AppColors.danger.copy(alpha = if (night) 0.16f else 0.12f)
                    val errorBorder = AppColors.danger.copy(alpha = if (night) 0.38f else 0.28f)
                    Row(
                        modifier = Modifier
                            .fillMaxWidth()
                            .padding(top = 8.dp)
                            .clip(RoundedCornerShape(10.dp))
                            .background(errorBg)
                            .border(1.dp, errorBorder, RoundedCornerShape(10.dp))
                            .padding(horizontal = 12.dp, vertical = 8.dp),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.Start,
                    ) {
                        Icon(
                            Icons.Rounded.ErrorOutline,
                            contentDescription = null,
                            tint = errorInk,
                            modifier = Modifier.size(16.dp),
                        )
                        Spacer(Modifier.width(8.dp))
                        Text(
                            text = message,
                            color = errorInk,
                            style = MaterialTheme.typography.bodySmall,
                            fontWeight = FontWeight.Medium,
                            modifier = Modifier.weight(1f),
                        )
                    }
                }
            }

            Spacer(Modifier.height(12.dp))

            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(vertical = 4.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Box(
                    modifier = Modifier
                        .size(28.dp)
                        .clickable { onAcceptedTermsChange(!acceptedTerms) },
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(
                        imageVector = if (acceptedTerms) Icons.Rounded.CheckBox else Icons.Rounded.CheckBoxOutlineBlank,
                        contentDescription = null,
                        tint = if (acceptedTerms) accent else if (night) AccountDark.line else Color(0xFFD1D5DB),
                        modifier = Modifier.size(24.dp),
                    )
                }
                Spacer(Modifier.width(8.dp))
                val terms = buildAnnotatedString {
                    val link = SpanStyle(
                        color = accent,
                        textDecoration = TextDecoration.Underline,
                        fontWeight = FontWeight.SemiBold,
                    )
                    append(stringResource(R.string.login_terms_prefix))
                    pushStringAnnotation("link", "privacy")
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
                        fontSize = 13.sp,
                        lineHeight = 20.sp,
                        color = mut,
                    ),
                    modifier = Modifier.weight(1f),
                    onClick = { offset ->
                        if (terms.getStringAnnotations("link", offset, offset).isNotEmpty()) {
                            onOpenPrivacy()
                        }
                    },
                )
            }

            Spacer(Modifier.height(16.dp))

            val buttonShape = RoundedCornerShape(999.dp)
            val disabledOutline = accent.copy(alpha = if (night) 0.55f else 0.35f)
            val disabledLabel = if (night) accent.copy(alpha = 0.92f) else accent.copy(alpha = 0.55f)
            Button(
                onClick = onSubmit,
                enabled = canSubmit,
                colors = ButtonDefaults.buttonColors(
                    containerColor = accent,
                    disabledContainerColor = if (isLoading) accent else Color.Transparent,
                    contentColor = Color.White,
                    disabledContentColor = if (isLoading) Color.White else disabledLabel,
                ),
                shape = buttonShape,
                border = if (!canSubmit && !isLoading) {
                    androidx.compose.foundation.BorderStroke(1.dp, disabledOutline)
                } else {
                    null
                },
                modifier = Modifier
                    .fillMaxWidth()
                    .height(48.dp)
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
                        fontSize = 15.sp,
                        fontWeight = FontWeight.Bold,
                    )
                }
            }

            // Server / Network Error Message Banner
            AnimatedVisibility(
                visible = errorMessage != null,
                enter = fadeIn() + expandVertically(),
                exit = fadeOut() + shrinkVertically(),
            ) {
                errorMessage?.let { message ->
                    Row(
                        modifier = Modifier
                            .fillMaxWidth()
                            .padding(top = 16.dp)
                            .clip(RoundedCornerShape(12.dp))
                            .background(if (night) AccountDark.well else settingsAccent().copy(alpha = 0.14f))
                            .border(1.dp, if (night) AccountDark.accent else Color(0xFFF8B4C0), RoundedCornerShape(12.dp))
                            .padding(horizontal = 14.dp, vertical = 12.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(
                            Icons.Rounded.ErrorOutline,
                            contentDescription = null,
                            tint = settingsAccent(),
                            modifier = Modifier.size(20.dp),
                        )
                        Spacer(Modifier.width(10.dp))
                        Text(
                            text = message,
                            color = if (night) AccountDark.accent else Color(0xFF991B1B),
                            style = MaterialTheme.typography.bodySmall,
                            fontWeight = FontWeight.Medium,
                            modifier = Modifier.weight(1f),
                        )
                    }
                }
            }
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

    val borderColor = if (isFocused) settingsAccent() else if (night) AccountDark.line else Color(0xFFE5E7EB)
    val borderWidth = if (isFocused) 1.5.dp else 1.dp

    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
        Surface(
            modifier = Modifier
                .fillMaxWidth()
                .height(44.dp),
            shape = RoundedCornerShape(999.dp),
            color = if (night) AccountDark.card else Color.White,
            shadowElevation = if (night) 0.dp else 3.dp,
            border = if (isFocused) androidx.compose.foundation.BorderStroke(borderWidth, borderColor) else null,
        ) {
            Row(
                modifier = Modifier
                    .fillMaxSize()
                    .padding(horizontal = 14.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(
                    Icons.Rounded.Phone,
                    contentDescription = null,
                    tint = if (night) AccountDark.mut else Color(0xFF9CA3AF),
                    modifier = Modifier.size(18.dp),
                )
                PhoneSep()

                Box {
                    Row(
                        modifier = Modifier
                            .clip(RoundedCornerShape(10.dp))
                            .clickable(enabled = countries.isNotEmpty()) { onMenuExpandedChange(true) }
                            .padding(horizontal = 6.dp, vertical = 6.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        FlagImage(flagCode)
                        Spacer(Modifier.width(4.dp))
                        Text(
                            text = dialCode,
                            style = TextStyle(
                                fontSize = 14.sp,
                                fontWeight = FontWeight.Bold,
                                color = if (night) AccountDark.ink else AppColors.textPrimary,
                            ),
                        )
                        if (countries.isNotEmpty()) {
                            Spacer(Modifier.width(2.dp))
                            Icon(
                                Icons.Rounded.KeyboardArrowDown,
                                contentDescription = stringResource(R.string.login_country_code),
                                tint = if (night) AccountDark.mut else AppColors.textSecondary,
                                modifier = Modifier.size(18.dp),
                            )
                        }
                    }

                    DropdownMenu(
                        expanded = menuExpanded && countries.isNotEmpty(),
                        onDismissRequest = { onMenuExpandedChange(false) },
                        containerColor = if (night) AccountDark.card else Color.White,
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
                                .background(if (night) AccountDark.well else Color(0xFFF3F4F6), RoundedCornerShape(10.dp))
                                .padding(horizontal = 10.dp, vertical = 8.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(
                                Icons.Rounded.Search,
                                contentDescription = null,
                                tint = if (night) AccountDark.mut else AppColors.textMuted,
                                modifier = Modifier.size(18.dp),
                            )
                            Spacer(Modifier.width(8.dp))
                            BasicTextField(
                                value = countryQuery,
                                onValueChange = { countryQuery = it },
                                singleLine = true,
                                textStyle = TextStyle(
                                    fontSize = 14.sp,
                                    color = if (night) AccountDark.ink else AppColors.textPrimary,
                                ),
                                decorationBox = { inner ->
                                    if (countryQuery.isEmpty()) {
                                        Text(
                                            text = stringResource(R.string.login_country_search),
                                            color = if (night) AccountDark.mut else AppColors.textMuted,
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
                                            color = if (selected) (if (night) AccountDark.accent else settingsAccent()) else if (night) AccountDark.ink else AppColors.textPrimary,
                                            fontWeight = if (selected) FontWeight.Bold else FontWeight.Normal,
                                            fontSize = 14.sp,
                                        )
                                        Spacer(Modifier.width(8.dp))
                                        Text(
                                            text = formatDialCode(country.dialCode),
                                            color = if (selected) (if (night) AccountDark.accent else settingsAccent()) else if (night) AccountDark.mut else AppColors.textSecondary,
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

                PhoneSep()

                val placeholder = phoneStartsWith + "*".repeat(
                    if (phoneLength > phoneStartsWith.length) phoneLength - phoneStartsWith.length else 0,
                )
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
                        fontSize = 14.sp,
                        fontWeight = FontWeight.Medium,
                        color = if (night) AccountDark.ink else AppColors.textPrimary,
                        textDirection = TextDirection.Ltr,
                    ),
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
                                    color = if (night) AccountDark.mut else AppColors.textMuted,
                                    fontSize = 14.sp,
                                    fontWeight = FontWeight.Medium,
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
                            tint = if (night) AccountDark.mut else Color(0xFF9CA3AF),
                            modifier = Modifier.size(16.dp),
                        )
                    }
                }
            }
        }
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

@Composable
internal fun LanguagePicker() {
    val appLanguage = LocalAppLanguage.current
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
    Box {
        Surface(
            shape = RoundedCornerShape(999.dp),
            color = if (night) AccountDark.card else Color.White,
            shadowElevation = if (night) 0.dp else 6.dp,
            modifier = Modifier.clickable { if (languages.isNotEmpty()) expanded = true },
        ) {
            Row(
                modifier = Modifier
                    .height(36.dp)
                    .padding(horizontal = 12.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(
                    Icons.Rounded.Language,
                    contentDescription = null,
                    tint = caret,
                    modifier = Modifier.size(20.dp),
                )
                Spacer(Modifier.width(8.dp))
                Text(
                    text = selected?.name ?: stringResource(R.string.language_arabic),
                    color = if (night) AccountDark.ink else Color(0xFF374151),
                    fontWeight = FontWeight.SemiBold,
                    fontSize = 13.sp,
                )
                Spacer(Modifier.width(8.dp))
                Canvas(Modifier.size(8.dp, 5.dp)) {
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
            containerColor = if (night) AccountDark.card else Color.White,
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
                                color = if (isSelected) (if (night) AccountDark.accent else settingsAccent()) else if (night) AccountDark.ink else Color(0xFF374151),
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
                        selected = language
                        appLanguage.set(language.code.lowercase())
                        expanded = false
                    },
                    modifier = if (isSelected) Modifier.background(if (night) AccountDark.well else settingsAccent().copy(alpha = 0.14f)) else Modifier,
                )
            }
        }
    }
}

@Composable
private fun PhoneSep() {
    Box(
        modifier = Modifier
            .padding(horizontal = 8.dp)
            .width(1.dp)
            .height(18.dp)
            .background(if (settingsNight()) AccountDark.line else Color(0xFFE5E7EB)),
    )
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
