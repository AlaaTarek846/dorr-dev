package com.dorr.app.ui.screens.aichat

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
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
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.Language
import androidx.compose.material.icons.rounded.Web
import androidx.compose.material.icons.rounded.WorkspacePremium
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R

private data class AiSettingsEntry(
    val icon: ImageVector,
    val title: String,
    val subtitle: String,
    val onClick: () -> Unit,
)

/**
 * Professional settings hub for the AI Assistant section (2026-10-04):
 * previously "Manage subscription" and "Language & dialect" were two
 * separate icons scattered across the conversation top bar and the
 * history list's top bar - functional, but not what a polished product
 * looks like. This consolidates every AI Assistant setting into one
 * proper settings screen (grouped cards, icon-in-a-circle + title +
 * subtitle + chevron, exactly the visual language ProfileScreen.kt's own
 * MenuRow already established elsewhere in the app), with room to add
 * more rows later without inventing a new top-bar icon each time.
 */
@Composable
internal fun AiSettingsScreen(
    night: Boolean,
    onExit: () -> Unit,
    onOpenSubscription: () -> Unit,
    onOpenLanguage: () -> Unit,
    onOpenSites: () -> Unit,
) {
    val bg = if (night) Ai.bgDark else Ai.bgLight
    val ink = if (night) Ai.inkDark else Ai.inkLight
    val mut = if (night) Ai.mutDark else Ai.mutLight
    val surface = if (night) Ai.surfaceDark else Ai.surfaceLight
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl

    BackHandler { onExit() }

    val entries = listOf(
        AiSettingsEntry(
            icon = Icons.Rounded.WorkspacePremium,
            title = stringResource(R.string.ai_subscription_manage),
            subtitle = stringResource(R.string.ai_settings_subscription_sub),
            onClick = onOpenSubscription,
        ),
        AiSettingsEntry(
            icon = Icons.Rounded.Language,
            title = stringResource(R.string.ai_language_title),
            subtitle = stringResource(R.string.ai_settings_language_sub),
            onClick = onOpenLanguage,
        ),
        AiSettingsEntry(
            icon = Icons.Rounded.Web,
            title = stringResource(R.string.ai_sites_title),
            subtitle = stringResource(R.string.ai_sites_settings_sub),
            onClick = onOpenSites,
        ),
    )

    Column(Modifier.fillMaxSize().background(bg)) {
        Row(
            Modifier.fillMaxWidth().statusBarsPadding().padding(horizontal = 8.dp, vertical = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(Modifier.size(38.dp).clip(CircleShape).clickable { onExit() }, contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.ArrowBackIosNew, contentDescription = null, tint = ink, modifier = Modifier.size(18.dp))
            }
            Spacer(Modifier.width(8.dp))
            Text(stringResource(R.string.ai_settings_title), color = ink, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp)
        }

        LazyColumn(
            Modifier.fillMaxSize().padding(horizontal = 16.dp),
            contentPadding = PaddingValues(top = 6.dp, bottom = 32.dp),
        ) {
            items(entries, key = { it.title }) { entry ->
                AiSettingsRow(entry = entry, surface = surface, ink = ink, mut = mut, rtl = rtl)
                Spacer(Modifier.height(4.dp))
            }
        }
    }
}

@Composable
private fun AiSettingsRow(entry: AiSettingsEntry, surface: Color, ink: Color, mut: Color, rtl: Boolean) {
    val shape = RoundedCornerShape(16.dp)
    Row(
        Modifier
            .fillMaxWidth()
            .padding(vertical = 4.dp)
            .shadow(6.dp, shape, spotColor = Ai.Red.copy(alpha = 0.08f))
            .clip(shape)
            .background(surface)
            .clickable(onClick = entry.onClick)
            .padding(horizontal = 14.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            Modifier.size(40.dp).clip(RoundedCornerShape(12.dp)).background(Ai.Red.copy(alpha = 0.12f)),
            contentAlignment = Alignment.Center,
        ) {
            Icon(entry.icon, contentDescription = null, tint = Ai.Red, modifier = Modifier.size(20.dp))
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(entry.title, color = ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
            Text(entry.subtitle, color = mut, fontSize = 11.5.sp, modifier = Modifier.padding(top = 2.dp))
        }
        Icon(
            Icons.Rounded.ChevronRight,
            contentDescription = null,
            tint = mut,
            modifier = Modifier.size(18.dp).graphicsLayer { if (rtl) scaleX = -1f },
        )
    }
}
