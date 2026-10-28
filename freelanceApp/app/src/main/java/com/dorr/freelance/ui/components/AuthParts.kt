package com.dorr.freelance.ui.components

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
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
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.KeyboardArrowDown
import androidx.compose.material.icons.rounded.Language
import androidx.compose.material.icons.rounded.Phone
import androidx.compose.material.icons.rounded.Search
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
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextDirection
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.freelance.R
import com.dorr.freelance.net.CountryDto
import com.dorr.freelance.net.formattedDial
import com.dorr.freelance.ui.kit.AccountDark
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsCard
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsNight
import com.dorr.freelance.ui.locale.LocalAppLanguage
import com.dorr.freelance.ui.theme.AppColors

/** The ar ⇄ en switch, a small pill (the DORR app's language picker, with the two bundled languages). */
@Composable
fun LanguageSwitch(modifier: Modifier = Modifier) {
    val language = LocalAppLanguage.current
    val night = settingsNight()
    Row(
        modifier = modifier
            .clip(RoundedCornerShape(999.dp))
            .background(settingsCard())
            .border(1.dp, if (night) AccountDark.line else settingsAccent().copy(alpha = 0.18f), RoundedCornerShape(999.dp))
            .clickable { language.toggle() }
            .padding(horizontal = 12.dp, vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Icon(Icons.Rounded.Language, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(16.dp))
        Text(stringResource(R.string.language_switch), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
    }
}

/** A short error line on a tinted card (a refused sign-in, a wrong code). */
@Composable
fun ErrorBanner(message: String?, modifier: Modifier = Modifier) {
    val night = settingsNight()
    AnimatedVisibility(
        visible = message != null,
        enter = fadeIn() + expandVertically(),
        exit = fadeOut() + shrinkVertically(),
        modifier = modifier,
    ) {
        if (message != null) {
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(top = 16.dp)
                    .clip(RoundedCornerShape(12.dp))
                    .background(if (night) AccountDark.well else settingsAccent().copy(alpha = 0.14f))
                    .border(1.dp, if (night) AccountDark.accent else Color(0xFFFBC8B7), RoundedCornerShape(12.dp))
                    .padding(horizontal = 14.dp, vertical = 12.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.ErrorOutline, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(10.dp))
                Text(
                    text = message,
                    color = if (night) AccountDark.accent else Color(0xFF001B53),
                    style = MaterialTheme.typography.bodySmall,
                    fontWeight = FontWeight.Medium,
                    modifier = Modifier.weight(1f),
                )
            }
        }
    }
}

@Composable
fun FlagImage(code: String?) {
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

/**
 * The phone field of the DORR app's sign-in: a country picker (flag + dial code, with a search) and the number, always
 * left-to-right. The number's length and first digits come from the selected country.
 */
@Composable
fun PhoneField(
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
        query.isEmpty() ||
            country.name.contains(query, ignoreCase = true) ||
            country.code.contains(query, ignoreCase = true) ||
            country.dialCode.contains(query, ignoreCase = true)
    }

    val muted = if (night) AccountDark.mut else Color(0xFF9CA3AF)
    val ink = if (night) AccountDark.ink else AppColors.textPrimary

    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
        Surface(
            modifier = Modifier.fillMaxWidth().height(44.dp),
            shape = RoundedCornerShape(999.dp),
            color = if (night) AccountDark.card else Color.White,
            shadowElevation = if (night) 0.dp else 3.dp,
            border = if (isFocused) androidx.compose.foundation.BorderStroke(1.5.dp, settingsAccent()) else null,
        ) {
            Row(
                modifier = Modifier.fillMaxSize().padding(horizontal = 14.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.Phone, contentDescription = null, tint = muted, modifier = Modifier.size(18.dp))
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
                        Text(text = dialCode, style = TextStyle(fontSize = 14.sp, fontWeight = FontWeight.Bold, color = ink))
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
                        modifier = Modifier.heightIn(max = 340.dp).widthIn(min = 260.dp),
                    ) {
                        Row(
                            modifier = Modifier
                                .fillMaxWidth()
                                .padding(8.dp)
                                .background(if (night) AccountDark.well else Color(0xFFF3F4F6), RoundedCornerShape(10.dp))
                                .padding(horizontal = 10.dp, vertical = 8.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(Icons.Rounded.Search, contentDescription = null, tint = if (night) AccountDark.mut else AppColors.textMuted, modifier = Modifier.size(18.dp))
                            Spacer(Modifier.width(8.dp))
                            BasicTextField(
                                value = countryQuery,
                                onValueChange = { countryQuery = it },
                                singleLine = true,
                                textStyle = TextStyle(fontSize = 14.sp, color = ink),
                                cursorBrush = SolidColor(settingsAccent()),
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
                            val accent = settingsAccent()
                            DropdownMenuItem(
                                text = {
                                    Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                                        FlagImage(country.flag?.code ?: country.code)
                                        Spacer(Modifier.width(10.dp))
                                        Text(
                                            text = country.name.ifBlank { country.code },
                                            modifier = Modifier.weight(1f),
                                            maxLines = 1,
                                            overflow = TextOverflow.Ellipsis,
                                            color = if (selected) accent else ink,
                                            fontWeight = if (selected) FontWeight.Bold else FontWeight.Normal,
                                            fontSize = 14.sp,
                                        )
                                        Spacer(Modifier.width(8.dp))
                                        Text(
                                            text = country.formattedDial(),
                                            color = if (selected) accent else if (night) AccountDark.mut else AppColors.textSecondary,
                                            fontWeight = FontWeight.SemiBold,
                                            fontSize = 13.sp,
                                        )
                                    }
                                },
                                onClick = { onCountrySelected(country) },
                                modifier = if (selected) Modifier.background(if (night) AccountDark.well else accent.copy(alpha = 0.14f)) else Modifier,
                            )
                        }
                    }
                }

                PhoneSep()

                val placeholder = phoneStartsWith + "*".repeat((phoneLength - phoneStartsWith.length).coerceAtLeast(0))
                BasicTextField(
                    value = phone,
                    onValueChange = { input ->
                        val filtered = input.filter { it.isDigit() }
                        if (filtered.length <= phoneLength) onPhoneChange(filtered)
                    },
                    singleLine = true,
                    textStyle = TextStyle(fontSize = 14.sp, fontWeight = FontWeight.Medium, color = ink, textDirection = TextDirection.Ltr),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone, imeAction = if (canSubmit) ImeAction.Done else ImeAction.Default),
                    keyboardActions = KeyboardActions(onDone = { if (canSubmit) onSubmit() }),
                    cursorBrush = SolidColor(settingsAccent()),
                    modifier = Modifier.weight(1f).onFocusChanged { isFocused = it.isFocused },
                    decorationBox = { innerTextField ->
                        Box(modifier = Modifier.fillMaxWidth(), contentAlignment = Alignment.CenterStart) {
                            if (phone.isEmpty()) {
                                Text(text = placeholder, color = if (night) AccountDark.mut else AppColors.textMuted, fontSize = 14.sp, fontWeight = FontWeight.Medium)
                            }
                            innerTextField()
                        }
                    },
                )

                if (phone.isNotEmpty()) {
                    IconButton(onClick = { onPhoneChange("") }, modifier = Modifier.size(28.dp)) {
                        Icon(Icons.Rounded.Close, contentDescription = null, tint = muted, modifier = Modifier.size(16.dp))
                    }
                }
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
