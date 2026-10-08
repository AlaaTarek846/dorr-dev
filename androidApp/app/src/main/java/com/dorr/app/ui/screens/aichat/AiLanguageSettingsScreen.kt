package com.dorr.app.ui.screens.aichat

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.ArrowBackIosNew
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.RadioButtonChecked
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material3.CircularProgressIndicator
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
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.AiLanguageDto
import com.dorr.app.network.AiLanguagePreferenceShowDto
import com.dorr.app.network.AiLanguagePreferenceUpdateRequestDto
import com.dorr.app.network.AiLanguageVariantDto
import com.dorr.app.network.ApiClient
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.chat.chatAuth
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

private const val MODE_FOLLOW_INPUT = "follow_input"
private const val MODE_FIXED = "fixed"

/**
 * Business gap fix (2026-10-03): the only place in the mobile app a user
 * can actually control the AI Assistant's reply language/dialect.
 * AiChatLanguageResolver (backend) already reads this row on every chat
 * reply, every voice-message transcript and every realtime voice call -
 * this screen is simply the missing control for it, mirroring the same
 * admin/v1/ai-user-language-preferences screen but scoped to the user's
 * own row (user/v1/ai-language-preference, AiUserLanguagePreferenceSelfController).
 *
 * Two modes, matching the backend exactly:
 * - follow_input (default): the assistant matches whatever language/dialect
 *   the user's own message/voice note is in, message by message - no
 *   language pick needed here.
 * - fixed: the assistant always replies in one chosen language, optionally
 *   narrowed to one of that language's dialect/style variants (e.g.
 *   Egyptian/Saudi colloquial Arabic vs. formal Arabic).
 */
@Composable
internal fun AiLanguageSettingsScreen(night: Boolean, onExit: () -> Unit) {
    val scope = rememberCoroutineScope()
    val bg = if (night) Ai.bgDark else Ai.bgLight
    val ink = if (night) Ai.inkDark else Ai.inkLight
    val mut = if (night) Ai.mutDark else Ai.mutLight
    val surface = if (night) Ai.surfaceDark else Ai.surfaceLight

    var loading by remember { mutableStateOf(true) }
    var loadError by remember { mutableStateOf<String?>(null) }
    var languages by remember { mutableStateOf<List<AiLanguageDto>>(emptyList()) }
    var variants by remember { mutableStateOf<List<AiLanguageVariantDto>>(emptyList()) }

    var mode by remember { mutableStateOf(MODE_FOLLOW_INPUT) }
    var selectedLanguageId by remember { mutableStateOf<Int?>(null) }
    var selectedVariantId by remember { mutableStateOf<Int?>(null) }

    var saving by remember { mutableStateOf(false) }
    var saveError by remember { mutableStateOf<String?>(null) }
    var savedFlash by remember { mutableStateOf(false) }

    fun applyFromServer(data: AiLanguagePreferenceShowDto?) {
        if (data == null) return
        languages = data.languages
        variants = data.variants
        mode = data.preference.responseLanguageMode
        selectedLanguageId = data.preference.language?.id
        selectedVariantId = data.preference.variant?.id
    }

    suspend fun load() {
        loading = true
        loadError = null
        runCatching { ApiClient.aiChat.languagePreference(chatAuth()).data }
            .onSuccess { applyFromServer(it) }
            .onFailure { loadError = it.apiFailure().message }
        loading = false
    }

    LaunchedEffect(Unit) { load() }

    LaunchedEffect(savedFlash) {
        if (savedFlash) {
            delay(2000)
            savedFlash = false
        }
    }

    BackHandler { onExit() }

    // Hoisted out of save() below - stringResource() is a @Composable call
    // and save() is a plain local fun (not itself @Composable), the same
    // reason AiConversationPage.kt's own sendPayload() hoists
    // sendFailedLabel the same way just above its definition.
    val fixedRequiresLanguageLabel = stringResource(R.string.ai_language_fixed_requires_language)

    fun save() {
        if (saving) return
        if (mode == MODE_FIXED && selectedLanguageId == null) {
            saveError = fixedRequiresLanguageLabel
            return
        }
        saving = true
        saveError = null
        scope.launch {
            val result = runCatching {
                ApiClient.aiChat.updateLanguagePreference(
                    chatAuth(),
                    AiLanguagePreferenceUpdateRequestDto(
                        responseLanguageMode = mode,
                        languageId = if (mode == MODE_FIXED) selectedLanguageId else null,
                        variantId = if (mode == MODE_FIXED) selectedVariantId else null,
                    ),
                ).data
            }
            result.onSuccess {
                savedFlash = true
                // Re-pull so the screen reflects exactly what the server
                // persisted (e.g. a cleared variant when the language
                // changed), not just this screen's own optimistic guess.
                runCatching { ApiClient.aiChat.languagePreference(chatAuth()).data }.onSuccess { applyFromServer(it) }
            }.onFailure {
                saveError = it.apiFailure().message
            }
            saving = false
        }
    }

    Column(Modifier.fillMaxSize().background(bg)) {
        Row(
            Modifier.fillMaxWidth().statusBarsPadding().padding(horizontal = 8.dp, vertical = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(Modifier.size(38.dp).clip(CircleShape).clickable { onExit() }, contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.ArrowBackIosNew, contentDescription = null, tint = ink, modifier = Modifier.size(18.dp))
            }
            Spacer(Modifier.width(8.dp))
            Text(stringResource(R.string.ai_language_title), color = ink, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp)
        }

        when {
            loading -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                CircularProgressIndicator(color = Ai.Red)
            }
            loadError != null -> Box(Modifier.fillMaxSize().padding(32.dp), contentAlignment = Alignment.Center) {
                Text(loadError ?: "", color = mut, fontSize = 13.sp)
            }
            else -> LazyColumn(Modifier.fillMaxSize().padding(horizontal = 16.dp), contentPadding = PaddingValues(bottom = 32.dp)) {
                item {
                    Text(
                        stringResource(R.string.ai_language_subtitle),
                        color = mut,
                        fontSize = 13.sp,
                        modifier = Modifier.padding(top = 4.dp, bottom = 18.dp),
                    )
                }

                item {
                    ModeOption(
                        title = stringResource(R.string.ai_language_mode_follow_input),
                        description = stringResource(R.string.ai_language_mode_follow_input_desc),
                        selected = mode == MODE_FOLLOW_INPUT,
                        night = night, ink = ink, mut = mut, surface = surface,
                        onClick = { mode = MODE_FOLLOW_INPUT },
                    )
                    Spacer(Modifier.height(10.dp))
                    ModeOption(
                        title = stringResource(R.string.ai_language_mode_fixed),
                        description = stringResource(R.string.ai_language_mode_fixed_desc),
                        selected = mode == MODE_FIXED,
                        night = night, ink = ink, mut = mut, surface = surface,
                        onClick = { mode = MODE_FIXED },
                    )
                }

                if (mode == MODE_FIXED) {
                    item {
                        Spacer(Modifier.height(18.dp))
                        Text(
                            stringResource(R.string.ai_language_select_language),
                            color = ink, fontWeight = FontWeight.Bold, fontSize = 13.5.sp,
                            modifier = Modifier.padding(bottom = 8.dp),
                        )
                    }
                    items(languages, key = { "lang-${it.id}" }) { language ->
                        PickRow(
                            label = language.name,
                            selected = selectedLanguageId == language.id,
                            night = night, ink = ink, mut = mut, surface = surface,
                            onClick = {
                                if (selectedLanguageId != language.id) {
                                    selectedLanguageId = language.id
                                    selectedVariantId = null
                                }
                            },
                        )
                        Spacer(Modifier.height(8.dp))
                    }

                    val languageVariants = variants.filter { it.languageId == selectedLanguageId }
                    if (selectedLanguageId != null && languageVariants.isNotEmpty()) {
                        item {
                            Spacer(Modifier.height(10.dp))
                            Text(
                                stringResource(R.string.ai_language_select_dialect),
                                color = ink, fontWeight = FontWeight.Bold, fontSize = 13.5.sp,
                                modifier = Modifier.padding(bottom = 8.dp),
                            )
                        }
                        items(languageVariants, key = { "variant-${it.id}" }) { variant ->
                            PickRow(
                                label = variant.name,
                                selected = selectedVariantId == variant.id,
                                night = night, ink = ink, mut = mut, surface = surface,
                                onClick = { selectedVariantId = variant.id },
                            )
                            Spacer(Modifier.height(8.dp))
                        }
                    }
                }

                item {
                    Spacer(Modifier.height(20.dp))
                    saveError?.let {
                        Text(it, color = Color(0xFFB91C1C), fontSize = 12.5.sp, modifier = Modifier.padding(bottom = 10.dp))
                    }
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .clip(RoundedCornerShape(14.dp))
                            .background(Ai.Red)
                            .clickable(enabled = !saving) { save() }
                            .padding(vertical = 14.dp),
                        horizontalArrangement = Arrangement.Center,
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        if (saving) {
                            CircularProgressIndicator(color = Color.White, strokeWidth = 2.dp, modifier = Modifier.size(16.dp))
                        } else if (savedFlash) {
                            Icon(Icons.Rounded.Check, contentDescription = null, tint = Color.White, modifier = Modifier.size(16.dp))
                            Spacer(Modifier.width(6.dp))
                            Text(stringResource(R.string.ai_language_saved), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                        } else {
                            Text(stringResource(R.string.ai_language_save), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun ModeOption(
    title: String,
    description: String,
    selected: Boolean,
    night: Boolean,
    ink: Color,
    mut: Color,
    surface: Color,
    onClick: () -> Unit,
) {
    Row(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(14.dp))
            .background(surface)
            .border(1.dp, if (selected) Ai.Red else Color.Transparent, RoundedCornerShape(14.dp))
            .clickable(onClick = onClick)
            .padding(horizontal = 14.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(
            if (selected) Icons.Rounded.RadioButtonChecked else Icons.Rounded.RadioButtonUnchecked,
            contentDescription = null,
            tint = if (selected) Ai.Red else mut,
            modifier = Modifier.size(20.dp),
        )
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(title, color = ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
            Text(description, color = mut, fontSize = 12.sp, modifier = Modifier.padding(top = 2.dp))
        }
    }
}

@Composable
private fun PickRow(
    label: String,
    selected: Boolean,
    night: Boolean,
    ink: Color,
    mut: Color,
    surface: Color,
    onClick: () -> Unit,
) {
    Row(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(12.dp))
            .background(surface)
            .border(1.dp, if (selected) Ai.Red else Color.Transparent, RoundedCornerShape(12.dp))
            .clickable(onClick = onClick)
            .padding(horizontal = 14.dp, vertical = 11.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(label, color = ink, fontSize = 13.5.sp, modifier = Modifier.weight(1f))
        if (selected) {
            Box(Modifier.size(22.dp).clip(CircleShape).background(Ai.Red), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Check, contentDescription = null, tint = Color.White, modifier = Modifier.size(13.dp))
            }
        }
    }
}
