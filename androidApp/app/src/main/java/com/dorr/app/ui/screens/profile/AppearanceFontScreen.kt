package com.dorr.app.ui.screens.profile

import android.widget.Toast
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.togetherWith
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
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AppearanceUpdateRequest
import com.dorr.app.network.AuthSession
import com.dorr.app.network.MobileAppFontDto
import com.dorr.app.network.serverMessage
import com.dorr.app.ui.screens.AccountDark
import com.dorr.app.ui.theme.LocalAppearance
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

private const val FONT_PAGE_SIZE = 15

@Composable
fun AppearanceFontScreen(onBack: () -> Unit) {
    val appearance = LocalAppearance.current
    val snap = appearance.snapshot
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val saved = stringResource(R.string.appearance_saved)
    val failed = stringResource(R.string.appearance_failed)

    var query by remember { mutableStateOf("") }
    var debouncedQuery by remember { mutableStateOf("") }
    var chunkPage by remember { mutableIntStateOf(1) }
    var loading by remember { mutableStateOf(true) }
    var savingId by remember { mutableStateOf<Int?>(null) }

    val selectedId = snap?.mobileAppFontId ?: snap?.font?.id

    LaunchedEffect(query) {
        delay(400)
        debouncedQuery = query
        chunkPage = 1
    }

    LaunchedEffect(Unit) {
        loading = true
        val token = AuthSession.token
        if (token.isNullOrBlank()) {
            loading = false
            return@LaunchedEffect
        }
        if (!snap?.availableFonts.isNullOrEmpty()) {
            loading = false
            return@LaunchedEffect
        }
        runCatching { ApiClient.appearance.show("Bearer $token").data }.onSuccess { dto ->
            if (dto != null && AuthSession.token == token) appearance.apply(dto)
        }
        loading = false
    }

    val allFonts = remember(snap) {
        val fromList = snap?.availableFonts?.filter { it.status }?.sortedBy { it.sortOrder }.orEmpty()
        if (fromList.isNotEmpty()) fromList
        else snap?.font?.let { listOf(it) }.orEmpty()
    }

    val filtered = remember(allFonts, debouncedQuery) {
        val q = debouncedQuery.trim()
        if (q.isBlank()) allFonts
        else allFonts.filter { font ->
            val name = font.name.orEmpty()
            val slug = font.slug.orEmpty()
            name.contains(q, ignoreCase = true) || slug.contains(q, ignoreCase = true)
        }
    }

    val visibleFonts = remember(filtered, chunkPage) {
        filtered.take(chunkPage * FONT_PAGE_SIZE)
    }
    val hasMore = filtered.size > visibleFonts.size
    val loadingMore = false

    fun selectFont(font: MobileAppFontDto) {
        if (font.id == selectedId || savingId != null) return
        val token = AuthSession.token
        if (token.isNullOrBlank()) return
        savingId = font.id
        scope.launch {
            runCatching {
                ApiClient.appearance.update(
                    "Bearer $token",
                    AppearanceUpdateRequest(mobileAppFontId = font.id),
                ).data
            }.onSuccess { dto ->
                if (dto != null && AuthSession.token == token) {
                    appearance.apply(dto)
                    Toast.makeText(context, saved, Toast.LENGTH_SHORT).show()
                    onBack()
                }
            }.onFailure { error ->
                Toast.makeText(context, error.serverMessage() ?: failed, Toast.LENGTH_SHORT).show()
            }
            savingId = null
        }
    }

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(horizontal = 20.dp),
        ) {
            Spacer(Modifier.height(16.dp))
            FontScreenHeader(
                title = stringResource(R.string.appearance_font_choose),
                onBack = onBack,
            )
            Spacer(Modifier.height(16.dp))
            FontSearchPill(
                query = query,
                onQueryChange = { query = it },
                placeholder = stringResource(R.string.appearance_font_search),
            )
            Spacer(Modifier.height(16.dp))

            val listState = when {
                loading && allFonts.isEmpty() -> FontListState.Loading
                visibleFonts.isEmpty() -> FontListState.Empty
                else -> FontListState.Content
            }

            AnimatedContent(
                targetState = listState,
                transitionSpec = {
                    fadeIn(tween(260)).togetherWith(fadeOut(tween(200)))
                },
                label = "font_list_transition",
                modifier = Modifier
                    .weight(1f)
                    .fillMaxWidth(),
            ) { state ->
                when (state) {
                    FontListState.Loading -> {
                        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                            CircularProgressIndicator(
                                color = settingsAccent(),
                                strokeWidth = 2.5.dp,
                                modifier = Modifier.size(32.dp),
                            )
                        }
                    }
                    FontListState.Empty -> {
                        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                                Text(
                                    stringResource(R.string.appearance_font_empty),
                                    color = settingsMut(),
                                    fontSize = 14.sp,
                                )
                                if (debouncedQuery.isNotBlank()) {
                                    Spacer(Modifier.height(12.dp))
                                    Text(
                                        stringResource(R.string.addr_clear_search),
                                        color = settingsAccent(),
                                        fontWeight = FontWeight.Bold,
                                        fontSize = 13.sp,
                                        modifier = Modifier.clickable { query = "" },
                                    )
                                }
                            }
                        }
                    }
                    FontListState.Content -> {
                        LazyColumn(
                            modifier = Modifier.fillMaxSize(),
                            contentPadding = PaddingValues(vertical = 4.dp),
                            verticalArrangement = Arrangement.spacedBy(10.dp),
                        ) {
                            items(visibleFonts, key = { it.id }) { font ->
                                FontOptionRow(
                                    font = font,
                                    selected = font.id == selectedId,
                                    busy = savingId == font.id,
                                    onSelect = { selectFont(font) },
                                )
                            }
                            if (hasMore) {
                                item(key = "show_more") {
                                    FontShowMoreButton(loading = loadingMore) { chunkPage++ }
                                }
                            }
                        }
                    }
                }
            }
            Spacer(Modifier.height(14.dp))
        }
    }
}

private enum class FontListState { Loading, Empty, Content }

@Composable
private fun FontScreenHeader(title: String, onBack: () -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = 4.dp),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        SettingsScreenTitle(title, Modifier.weight(1f))
        Box(
            modifier = Modifier
                .size(42.dp)
                .then(if (settingsNight()) Modifier else Modifier.shadow(6.dp, CircleShape, spotColor = Color(0x20000000)))
                .clip(CircleShape)
                .background(settingsCard())
                .then(if (settingsNight()) Modifier.border(1.dp, AccountDark.line, CircleShape) else Modifier)
                .clickable(onClick = onBack),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                Icons.AutoMirrored.Rounded.KeyboardArrowRight,
                contentDescription = null,
                tint = settingsAccent(),
                modifier = Modifier.size(24.dp),
            )
        }
    }
}

@Composable
private fun FontSearchPill(
    query: String,
    onQueryChange: (String) -> Unit,
    placeholder: String,
) {
    Box(
        modifier = Modifier
            .fillMaxWidth()
            .then(if (settingsNight()) Modifier else Modifier.shadow(6.dp, RoundedCornerShape(50), spotColor = Color(0x12000000)))
            .clip(RoundedCornerShape(50))
            .background(settingsCard())
            .then(if (settingsNight()) Modifier.border(1.dp, AccountDark.line, RoundedCornerShape(50)) else Modifier)
            .padding(horizontal = 16.dp, vertical = 12.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth()) {
            Icon(Icons.Rounded.Search, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(10.dp))
            Box(modifier = Modifier.weight(1f)) {
                if (query.isEmpty()) {
                    Text(placeholder, color = settingsMut(), fontSize = 14.sp)
                }
                BasicTextField(
                    value = query,
                    onValueChange = onQueryChange,
                    singleLine = true,
                    textStyle = TextStyle(fontSize = 14.sp, color = settingsInk(), fontWeight = FontWeight.Medium),
                    cursorBrush = SolidColor(settingsAccent()),
                    modifier = Modifier.fillMaxWidth(),
                )
            }
            if (query.isNotEmpty()) {
                Icon(
                    Icons.Rounded.Close,
                    contentDescription = null,
                    tint = Color(0xFF9CA3AF),
                    modifier = Modifier
                        .size(18.dp)
                        .clickable { onQueryChange("") },
                )
            }
        }
    }
}

@Composable
private fun FontOptionRow(
    font: MobileAppFontDto,
    selected: Boolean,
    busy: Boolean,
    onSelect: () -> Unit,
) {
    val shape = RoundedCornerShape(12.dp)
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clip(shape)
            .then(
                if (selected) Modifier.background(settingsAccent())
                else Modifier.settingsSurface(shape),
            )
            .clickable(enabled = !busy, onClick = onSelect)
            .padding(horizontal = 14.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f)) {
            Text(
                text = font.name?.takeIf { it.isNotBlank() } ?: font.slug.orEmpty(),
                color = if (selected) Color.White else settingsInk(),
                fontWeight = FontWeight.Bold,
                fontSize = 14.sp,
            )
            if (font.isDefault) {
                Text(
                    stringResource(R.string.appearance_font_default),
                    color = if (selected) Color.White.copy(alpha = 0.85f) else settingsMut(),
                    fontSize = 11.sp,
                )
            }
        }
        when {
            busy -> CircularProgressIndicator(
                modifier = Modifier.size(18.dp),
                strokeWidth = 2.dp,
                color = if (selected) Color.White else settingsAccent(),
            )
            selected -> Icon(Icons.Rounded.Check, contentDescription = null, tint = Color.White, modifier = Modifier.size(18.dp))
        }
    }
}

@Composable
private fun FontShowMoreButton(loading: Boolean, onClick: () -> Unit) {
    Box(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(50))
            .background(settingsCard())
            .border(
                1.5.dp,
                if (settingsNight()) AccountDark.line else settingsAccent().copy(alpha = 0.35f),
                RoundedCornerShape(50),
            )
            .clickable(enabled = !loading, onClick = onClick)
            .padding(vertical = 12.dp),
        contentAlignment = Alignment.Center,
    ) {
        if (loading) {
            CircularProgressIndicator(modifier = Modifier.size(20.dp), strokeWidth = 2.dp, color = settingsAccent())
        } else {
            Text(
                stringResource(R.string.wallet_history_more),
                color = settingsAccent(),
                fontSize = 13.sp,
                fontWeight = FontWeight.Bold,
            )
        }
    }
}
