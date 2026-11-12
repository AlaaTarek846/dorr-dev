package com.dorr.freelance.ui.screens

import android.widget.Toast
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.Arrangement
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
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Language
import androidx.compose.material3.CircularProgressIndicator
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
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.DialogProperties
import coil.compose.AsyncImage
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.AppLanguageDto
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.ui.kit.AccountDark
import com.dorr.freelance.ui.kit.PinkBackdrop
import com.dorr.freelance.ui.kit.PinkIcon
import com.dorr.freelance.ui.kit.settingsBackground
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.locale.LocalAppLanguage
import com.dorr.freelance.ui.locale.LocaleAwareDialog
import com.dorr.freelance.ui.theme.LocalThemeState
import kotlinx.coroutines.launch

// Switches the app locale. LocalAppLanguage set() persists the choice and
// LocalizedApp (MainActivity) re-wraps the composition in a locale-aware
// Context, so every stringResource call and the RTL/LTR layout follow it
// immediately — no activity recreation needed.
@Composable
internal fun LanguageDialog(onDismiss: () -> Unit) {
    val dark = LocalThemeState.current.isDark ?: isSystemInDarkTheme()
    val appLanguage = LocalAppLanguage.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val downloadFailed = stringResource(R.string.language_download_failed)
    var languages by remember { mutableStateOf<List<AppLanguageDto>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    // Code of the language whose strings are being downloaded; the other choices wait for it.
    var downloading by remember { mutableStateOf<String?>(null) }
    val reconnectTick = collectReconnectTick()
    LaunchedEffect(reconnectTick) {
        languages = runCatching { ApiClient.translations.list().body()?.data.orEmpty() }.getOrDefault(emptyList())
        loading = false
        appLanguage.syncWith(context, languages)
    }
    LocaleAwareDialog(
        onDismissRequest = onDismiss,
        properties = DialogProperties(usePlatformDefaultWidth = false),
    ) {
        Box(
            modifier = Modifier
                .fillMaxSize()
                .background(Color.Black.copy(alpha = 0.45f))
                .clickable(onClick = onDismiss),
            contentAlignment = Alignment.Center,
        ) {
            Box(
                modifier = Modifier
                    .padding(horizontal = 24.dp)
                    .widthIn(max = 300.dp)
                    .fillMaxWidth()
                    .clip(RoundedCornerShape(20.dp))
                    .shadow(16.dp, RoundedCornerShape(20.dp), ambientColor = Color(0x24001B53), spotColor = Color(0x24001B53))
                    .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null, onClick = {}),
            ) {
                PinkBackdrop(Modifier.matchParentSize())
                Column(Modifier.padding(horizontal = 16.dp, vertical = 18.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(bottom = 12.dp)) {
                        PinkIcon(Icons.Rounded.Language)
                        Spacer(Modifier.width(10.dp))
                        Text(
                            stringResource(R.string.language_title),
                            color = if (dark) AccountDark.accent else settingsAccent(),
                            fontSize = 18.sp,
                            fontWeight = FontWeight.ExtraBold,
                        )
                    }
                    if (loading && languages.isEmpty()) {
                        Text(
                            stringResource(R.string.language_loading),
                            color = settingsMut(),
                            fontSize = 13.sp,
                            modifier = Modifier.padding(bottom = 14.dp),
                        )
                    } else {
                        val shown = languages.ifEmpty {
                            listOf(
                                AppLanguageDto(id = 0, code = "ar", name = stringResource(R.string.language_arabic), direction = "rtl"),
                                AppLanguageDto(id = 0, code = "en", name = stringResource(R.string.language_english), direction = "ltr"),
                            )
                        }
                        Column(verticalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.padding(bottom = 14.dp)) {
                            shown.forEach { language ->
                                val code = language.code.lowercase()
                                LanguageOption(
                                    name = language.name,
                                    flag = languageFlagCode(language),
                                    selected = code == appLanguage.code.lowercase(),
                                    onClick = {
                                        if (downloading == null && code != appLanguage.code.lowercase()) {
                                            scope.launch {
                                                downloading = code
                                                val switched = appLanguage.choose(context, code)
                                                downloading = null
                                                if (!switched) Toast.makeText(context, downloadFailed, Toast.LENGTH_SHORT).show()
                                            }
                                        }
                                    },
                                    dark = dark,
                                    loading = downloading == code,
                                )
                            }
                        }
                    }
                    Box(
                        modifier = Modifier
                            .fillMaxWidth()
                            .height(44.dp)
                            .shadow(8.dp, RoundedCornerShape(14.dp), ambientColor = Color(0x38001B53), spotColor = Color(0x38001B53))
                            .clip(RoundedCornerShape(14.dp))
                            .background(settingsAccent())
                            .clickable(onClick = onDismiss),
                        contentAlignment = Alignment.Center,
                    ) {
                        Text(stringResource(R.string.common_close), color = Color.White, fontSize = 15.sp, fontWeight = FontWeight.Bold)
                    }
                }
            }
        }
    }
}

@Composable
private fun LanguageOption(
    name: String,
    flag: String,
    selected: Boolean,
    onClick: () -> Unit,
    dark: Boolean = false,
    loading: Boolean = false,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(14.dp))
            .background(
                when {
                    dark && selected -> AccountDark.well
                    dark -> AccountDark.card
                    selected -> settingsAccent().copy(alpha = 0.14f)
                    else -> Color(0xFFFBF7F8)
                },
            )
            .border(1.5.dp, if (selected) settingsAccent() else if (dark) AccountDark.line else Color.Transparent, RoundedCornerShape(14.dp))
            .clickable(onClick = onClick)
            .padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            modifier = Modifier
                .size(34.dp)
                .clip(RoundedCornerShape(10.dp))
                .background(settingsBackground()),
            contentAlignment = Alignment.Center,
        ) {
            AsyncImage(
                model = "https://flagcdn.com/w40/$flag.png",
                contentDescription = null,
                contentScale = ContentScale.Crop,
                modifier = Modifier.size(width = 22.dp, height = 16.dp).clip(RoundedCornerShape(3.dp)),
            )
        }
        Spacer(Modifier.width(10.dp))
        Text(name, fontSize = 14.sp, fontWeight = FontWeight.Bold, color = settingsInk(), modifier = Modifier.weight(1f))
        if (loading) {
            CircularProgressIndicator(
                color = settingsAccent(),
                strokeWidth = 2.dp,
                modifier = Modifier.size(18.dp),
            )
        } else {
            Box(
                modifier = Modifier
                    .size(18.dp)
                    .border(2.dp, if (selected) settingsAccent() else if (dark) AccountDark.chevron else Color(0xFFF8BCA9), CircleShape),
                contentAlignment = Alignment.Center,
            ) {
                if (selected) {
                    Box(Modifier.size(10.dp).clip(CircleShape).background(settingsAccent()))
                }
            }
        }
    }
}

private fun languageFlagCode(language: AppLanguageDto): String {
    val fromFlag = language.flag?.code?.trim()?.lowercase().orEmpty()
    if (fromFlag.length == 2) return fromFlag
    return when (language.code.lowercase()) {
        "ar" -> "sa"
        "en" -> "us"
        else -> language.code.lowercase().take(2)
    }
}
