package com.dorr.app.ui.screens.aichat

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import com.dorr.app.ui.screens.profile.settingsAccent

/**
 * Colour tokens for the AI Assistant screens — the same brand palette the rest of the app
 * uses (see [com.dorr.app.ui.screens.chat.Ch] for the chat module's identical light-mode
 * values), computed independently rather than through Ch's globally mutable dark/accent
 * state, since the AI screens can be the very first chat-like surface opened this session.
 */
internal object Ai {
    val bgLight = Color(0xFFF7F4F2)
    val bgDark = Color(0xFF0B1220)
    val surfaceLight = Color.White
    val surfaceDark = Color(0xFF172033)
    val inkLight = Color(0xFF111928)
    val inkDark = Color(0xFFF3F4F6)
    val mutLight = Color(0xFF6B7280)
    val mutDark = Color(0xFF9CA3AF)
    val lineLight = Color(0xFFEEF0F3)
    val lineDark = Color(0xFF273244)

    /** The app's chosen accent colour (appearance settings), same source as everywhere else. */
    val Red: Color @Composable get() = settingsAccent()
}

@Composable
internal fun AiConfirmDialog(
    night: Boolean,
    title: String,
    message: String,
    confirmLabel: String? = null,
    dismissLabel: String? = null,
    destructive: Boolean = true,
    onConfirm: () -> Unit,
    onDismiss: () -> Unit,
) {
    val surface = if (night) Ai.surfaceDark else Ai.surfaceLight
    val ink = if (night) Ai.inkDark else Ai.inkLight
    val mut = if (night) Ai.mutDark else Ai.mutLight
    val resolvedDismiss = dismissLabel ?: androidx.compose.ui.res.stringResource(com.dorr.app.R.string.ai_cancel)
    val resolvedConfirm = confirmLabel ?: androidx.compose.ui.res.stringResource(com.dorr.app.R.string.ai_delete)

    Dialog(onDismissRequest = onDismiss) {
        Column(
            Modifier.clip(RoundedCornerShape(20.dp)).background(surface).padding(20.dp),
        ) {
            Text(title, color = ink, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp)
            Spacer(Modifier.height(8.dp))
            Text(message, color = mut, fontSize = 13.5.sp)
            Spacer(Modifier.height(18.dp))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) {
                Box(
                    Modifier.clip(RoundedCornerShape(12.dp)).clickable(onClick = onDismiss).padding(horizontal = 14.dp, vertical = 8.dp),
                ) { Text(resolvedDismiss, color = mut, fontWeight = FontWeight.Bold, fontSize = 13.sp) }
                Spacer(Modifier.width(4.dp))
                Box(
                    Modifier.clip(RoundedCornerShape(12.dp))
                        .background(if (destructive) Color(0xFFDC2626) else Ai.Red)
                        .clickable(onClick = onConfirm)
                        .padding(horizontal = 14.dp, vertical = 8.dp),
                ) { Text(resolvedConfirm, color = Color.White, fontWeight = FontWeight.Bold, fontSize = 13.sp) }
            }
        }
    }
}
