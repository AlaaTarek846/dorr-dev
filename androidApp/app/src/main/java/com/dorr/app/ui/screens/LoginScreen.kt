package com.dorr.app.ui.screens

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Build
import androidx.compose.material.icons.rounded.KeyboardArrowDown
import androidx.compose.material.icons.rounded.Language
import androidx.compose.material.icons.rounded.Phone
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CheckboxDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
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
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AppLocale
import com.dorr.app.network.CountryDto
import com.dorr.app.network.LanguageDto
import com.dorr.app.ui.theme.AppColors
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

@Composable
fun LoginScreen(onOtpRequested: (dialCode: String, phone: String) -> Unit) {
    var phone by remember { mutableStateOf("") }
    var acceptedTerms by remember { mutableStateOf(false) }
    var isLoading by remember { mutableStateOf(false) }
    var countries by remember { mutableStateOf<List<CountryDto>>(emptyList()) }
    var menuExpanded by remember { mutableStateOf(false) }

    // Seeded default country is Saudi Arabia until detect/dropdown answer.
    var selectedCountryId by remember { mutableStateOf<Int?>(null) }
    var flagCode by remember { mutableStateOf("sa") }
    var dialCode by remember { mutableStateOf("+966") }
    var phoneLength by remember { mutableStateOf(9) }

    val scope = rememberCoroutineScope()

    fun applyCountry(country: CountryDto) {
        selectedCountryId = country.id
        flagCode = country.flag?.code ?: country.code
        dialCode = formatDialCode(country.dialCode)
        val length = country.phoneLength ?: phoneLength
        phoneLength = length
        if (phone.length > length) phone = phone.take(length)
    }

    LaunchedEffect(Unit) {
        val listed = runCatching { ApiClient.countries.list().data.orEmpty() }.getOrDefault(emptyList())
        val detected = runCatching { ApiClient.countries.detect().data }.getOrNull()
        countries = listed
        val chosen = detected?.let { detectedCountry ->
            listed.find { it.id == detectedCountry.id } ?: detectedCountry
        } ?: listed.firstOrNull { it.isDefault } ?: listed.firstOrNull()
        chosen?.let(::applyCountry)
    }

    val canSubmit = !isLoading && acceptedTerms && phone.length == phoneLength

    Box(Modifier.fillMaxSize()) {
        LoginBackdrop(Modifier.fillMaxSize())
        LoginContent(
            phone = phone,
            acceptedTerms = acceptedTerms,
            onAcceptedTermsChange = { acceptedTerms = it },
            isLoading = isLoading,
            canSubmit = canSubmit,
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
            phoneLength = phoneLength,
            onPhoneChange = { if (it.length <= phoneLength) phone = it },
            onSubmit = {
                isLoading = true
                scope.launch {
                    delay(500)
                    isLoading = false
                    onOtpRequested(dialCode, phone)
                }
            },
        )
    }
}

@Composable
private fun LoginBackdrop(modifier: Modifier = Modifier) {
    Canvas(modifier) {
        drawRect(Color.White)
        fun glow(center: Offset, radius: Float, color: Color) {
            drawCircle(
                brush = Brush.radialGradient(
                    colors = listOf(color, color.copy(alpha = 0.55f), Color.Transparent),
                    center = center,
                    radius = radius,
                ),
                radius = radius,
                center = center,
            )
        }
        glow(Offset(0f, 0f), size.width * 1.15f, Color(0xFFEFA8B4))
        glow(Offset(size.width * 0.5f, 0f), size.width * 0.95f, Color(0xFFF3C4CC))
        glow(Offset(size.width, 0f), size.width * 0.72f, Color(0xFFF0B8C2))
        val wave = Path().apply {
            moveTo(0f, size.height * 0.18f)
            quadraticBezierTo(size.width * 0.22f, size.height * 0.02f, size.width * 0.5f, size.height * 0.14f)
            quadraticBezierTo(size.width * 0.78f, size.height * 0.26f, size.width, size.height * 0.06f)
            lineTo(size.width, 0f)
            lineTo(0f, 0f)
            close()
        }
        drawPath(
            wave,
            brush = Brush.verticalGradient(
                colors = listOf(Color(0x99F3C4CC), Color.Transparent),
                startY = 0f,
                endY = size.height * 0.4f,
            ),
        )
    }
}

@Composable
private fun LoginContent(
    phone: String,
    acceptedTerms: Boolean,
    onAcceptedTermsChange: (Boolean) -> Unit,
    isLoading: Boolean,
    canSubmit: Boolean,
    countries: List<CountryDto>,
    selectedCountryId: Int?,
    flagCode: String,
    dialCode: String,
    menuExpanded: Boolean,
    onMenuExpandedChange: (Boolean) -> Unit,
    onCountrySelected: (CountryDto) -> Unit,
    phoneLength: Int,
    onPhoneChange: (String) -> Unit,
    onSubmit: () -> Unit,
) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(horizontal = 22.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Spacer(Modifier.height(28.dp))
        LanguagePicker()
        Spacer(Modifier.height(18.dp))

        Text(
            text = stringResource(R.string.login_title),
            color = Color(0xFFE50914),
            fontWeight = FontWeight.ExtraBold,
            style = MaterialTheme.typography.headlineLarge,
            textAlign = TextAlign.Center,
        )
        Spacer(Modifier.height(8.dp))
        Text(
            text = stringResource(R.string.login_subtitle),
            style = MaterialTheme.typography.bodyMedium,
            color = Color(0xFF6B7280),
            textAlign = TextAlign.Center,
        )
        Spacer(Modifier.height(28.dp))

        Box(
            modifier = Modifier
                .size(96.dp)
                .background(Color.White, CircleShape),
            contentAlignment = Alignment.Center,
        ) {
            Icon(Icons.Rounded.Build, contentDescription = null, tint = AppColors.primary, modifier = Modifier.size(44.dp))
        }
        BrandName()
        Spacer(Modifier.height(20.dp))

        PhoneField(
            countries = countries,
            selectedCountryId = selectedCountryId,
            flagCode = flagCode,
            dialCode = dialCode,
            menuExpanded = menuExpanded,
            onMenuExpandedChange = onMenuExpandedChange,
            onCountrySelected = onCountrySelected,
            phoneLength = phoneLength,
            phone = phone,
            onPhoneChange = onPhoneChange,
        )

        Spacer(Modifier.height(12.dp))

        Row(
            modifier = Modifier
                .fillMaxWidth()
                .clickable { onAcceptedTermsChange(!acceptedTerms) },
            verticalAlignment = Alignment.Top,
        ) {
            Checkbox(
                checked = acceptedTerms,
                onCheckedChange = onAcceptedTermsChange,
                colors = CheckboxDefaults.colors(checkedColor = Color(0xFFE50914)),
            )
            Text(
                text = stringResource(R.string.login_terms),
                style = MaterialTheme.typography.bodySmall,
                color = Color(0xFF6B7280),
                modifier = Modifier.padding(top = 12.dp),
            )
        }

        Spacer(Modifier.height(16.dp))

        Box(
            modifier = Modifier
                .fillMaxWidth()
                .height(48.dp)
                .clip(RoundedCornerShape(999.dp))
                .background(Color(0xFFE50914))
                .clickable(enabled = canSubmit, onClick = onSubmit),
            contentAlignment = Alignment.Center,
        ) {
            if (isLoading) {
                CircularProgressIndicator(modifier = Modifier.size(20.dp), strokeWidth = 2.dp, color = Color.White)
            } else {
                Text(
                    stringResource(R.string.login_cta),
                    color = Color.White,
                    fontWeight = FontWeight.SemiBold,
                )
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
    phoneLength: Int,
    phone: String,
    onPhoneChange: (String) -> Unit,
) {
    var countryQuery by remember { mutableStateOf("") }
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

    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .height(48.dp)
                .background(Color.White, RoundedCornerShape(999.dp))
                .padding(horizontal = 14.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.Phone, contentDescription = null, tint = Color(0xFF9CA3AF), modifier = Modifier.size(22.dp))
            Spacer(Modifier.width(8.dp))
            Box(Modifier.width(1.dp).height(18.dp).background(AppColors.border))
            Spacer(Modifier.width(8.dp))
            Box {
                Row(
                    modifier = Modifier
                        .clickable(enabled = countries.isNotEmpty()) { onMenuExpandedChange(true) }
                        .padding(vertical = 12.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    FlagEmoji(flagCode)
                    Spacer(Modifier.width(6.dp))
                    Text(dialCode, style = MaterialTheme.typography.titleMedium, color = AppColors.textPrimary)
                    if (countries.isNotEmpty()) {
                        Icon(
                            Icons.Rounded.KeyboardArrowDown,
                            contentDescription = stringResource(R.string.login_country_code),
                            tint = AppColors.textSecondary,
                            modifier = Modifier.size(20.dp),
                        )
                    }
                }
                DropdownMenu(
                    expanded = menuExpanded && countries.isNotEmpty(),
                    onDismissRequest = { onMenuExpandedChange(false) },
                    modifier = Modifier
                        .heightIn(max = 320.dp)
                        .widthIn(min = 220.dp),
                ) {
                    OutlinedTextField(
                        value = countryQuery,
                        onValueChange = { countryQuery = it },
                        placeholder = { Text(stringResource(R.string.login_country_search)) },
                        singleLine = true,
                        modifier = Modifier.padding(horizontal = 8.dp, vertical = 4.dp),
                    )
                    visibleCountries.forEach { country ->
                        val selected = country.id == selectedCountryId
                        DropdownMenuItem(
                            text = {
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    FlagEmoji(country.flag?.code ?: country.code)
                                    Spacer(Modifier.width(8.dp))
                                    Text(
                                        text = country.name.ifBlank { country.code },
                                        modifier = Modifier.widthIn(max = 160.dp),
                                        maxLines = 1,
                                        overflow = TextOverflow.Ellipsis,
                                        color = if (selected) AppColors.primary else AppColors.textPrimary,
                                    )
                                    Spacer(Modifier.width(12.dp))
                                    Text(
                                        text = formatDialCode(country.dialCode),
                                        color = AppColors.textSecondary,
                                    )
                                }
                            },
                            onClick = { onCountrySelected(country) },
                        )
                    }
                }
            }
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
        color = Color(0xFFE50914),
        fontWeight = FontWeight.Bold,
        fontSize = 15.sp,
        textAlign = TextAlign.Center,
    )
}

@Composable
private fun LanguagePicker() {
    var languages by remember { mutableStateOf<List<LanguageDto>>(emptyList()) }
    var expanded by remember { mutableStateOf(false) }
    var selected by remember { mutableStateOf<LanguageDto?>(null) }

    LaunchedEffect(AppLocale.current) {
        val listed = runCatching { ApiClient.languages.list().data.orEmpty() }.getOrDefault(emptyList())
        languages = listed
        selected = listed.find { it.code.equals(AppLocale.current, ignoreCase = true) }
            ?: listed.find { it.code.equals("ar", ignoreCase = true) }
            ?: listed.firstOrNull()
    }

    Box {
        Row(
            modifier = Modifier
                .shadow(8.dp, RoundedCornerShape(999.dp), ambientColor = Color(0x14E50914), spotColor = Color(0x14E50914))
                .clip(RoundedCornerShape(999.dp))
                .background(Color.White)
                .clickable { if (languages.isNotEmpty()) expanded = true }
                .padding(horizontal = 12.dp, vertical = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.Language, contentDescription = null, tint = Color(0xFFE50914), modifier = Modifier.size(16.dp))
            Spacer(Modifier.width(8.dp))
            Text(
                text = selected?.name ?: "العربية",
                color = Color(0xFF374151),
                fontWeight = FontWeight.SemiBold,
                fontSize = 13.sp,
            )
            Spacer(Modifier.width(6.dp))
            Icon(Icons.Rounded.KeyboardArrowDown, contentDescription = null, tint = Color(0xFFE50914), modifier = Modifier.size(16.dp))
        }
        DropdownMenu(
            expanded = expanded,
            onDismissRequest = { expanded = false },
            containerColor = Color.White,
            shape = RoundedCornerShape(16.dp),
        ) {
            languages.forEach { language ->
                val isSelected = language.code.equals(selected?.code, ignoreCase = true)
                DropdownMenuItem(
                    text = {
                        Text(
                            language.name,
                            color = if (isSelected) Color(0xFFE50914) else Color(0xFF374151),
                            fontWeight = FontWeight.SemiBold,
                            fontSize = 13.sp,
                        )
                    },
                    onClick = {
                        selected = language
                        AppLocale.current = language.code.lowercase()
                        expanded = false
                    },
                    modifier = if (isSelected) Modifier.background(Color(0xFFFDE8EC)) else Modifier,
                )
            }
        }
    }
}

@Composable
private fun FlagEmoji(code: String?) {
    val emoji = flagEmoji(code)
    if (emoji.isEmpty()) return
    Text(
        text = emoji,
        fontFamily = FontFamily.Default,
        fontSize = 18.sp,
    )
}

/** ISO 3166-1 alpha-2 → regional-indicator emoji (e.g. "sa" → 🇸🇦). */
private fun flagEmoji(code: String?): String {
    val iso = code?.trim()?.uppercase() ?: return ""
    if (iso.length != 2 || iso.any { it !in 'A'..'Z' }) return ""
    val base = 0x1F1E6
    return buildString {
        appendCodePoint(base + (iso[0] - 'A'))
        appendCodePoint(base + (iso[1] - 'A'))
    }
}

private fun formatDialCode(value: String): String {
    val digits = value.trim().removePrefix("+")
    return if (digits.isEmpty()) "" else "+$digits"
}
