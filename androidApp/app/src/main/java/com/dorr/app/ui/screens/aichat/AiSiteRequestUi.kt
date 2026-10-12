package com.dorr.app.ui.screens.aichat

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.text.font.FontWeight
import java.time.Instant
import java.time.OffsetDateTime

/** The dashboard names a status colour the Bootstrap way; map it onto the app's palette. */
@Composable
internal fun requestColor(name: String?): Color {
    val c = sitePalette()
    return when (name) {
        "success" -> c.success
        "danger" -> c.danger
        "warning" -> c.warn
        "info" -> Color(0xFF0EA5E9)
        "primary" -> Ai.Red
        else -> c.mut
    }
}

@Composable
internal fun RequestStatusChip(label: String, colorName: String?, onDark: Boolean = false) {
    val color = requestColor(colorName)
    val fg = if (onDark) Color.White else color
    Row(
        Modifier.clip(RoundedCornerShape(50)).background(if (onDark) Color.White.copy(alpha = 0.2f) else color.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Box(Modifier.size(7.dp).clip(CircleShape).background(fg))
        Text(label, color = fg, fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold)
    }
}

/** Multi-select (or single-select) pills that wrap onto several lines. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun PillGroup(options: List<String>, selected: Set<String>, multi: Boolean = true, onChange: (Set<String>) -> Unit) {
    val c = sitePalette()
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        options.forEach { option ->
            val on = option in selected
            val shape = RoundedCornerShape(50)
            Row(
                Modifier.clip(shape)
                    .background(if (on) Ai.Red.copy(alpha = 0.12f) else c.surface)
                    .border(if (on) 1.5.dp else 1.dp, if (on) Ai.Red else c.line, shape)
                    .clickable {
                        onChange(
                            when {
                                !multi -> setOf(option)
                                on -> selected - option
                                else -> selected + option
                            },
                        )
                    }
                    .padding(horizontal = 14.dp, vertical = 9.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                if (on) {
                    Icon(Icons.Rounded.Check, contentDescription = null, tint = Ai.Red, modifier = Modifier.size(15.dp))
                    Spacer(Modifier.width(5.dp))
                }
                Text(option, color = if (on) Ai.Red else c.ink, fontSize = 12.5.sp, fontWeight = if (on) FontWeight.Bold else FontWeight.Medium)
            }
        }
    }
}

internal fun parseInstant(value: String?): Instant? = value?.let { runCatching { OffsetDateTime.parse(it).toInstant() }.getOrNull() }

/** "12d 4h" style remaining time, or null when nothing is running. */
internal fun remainingParts(dueAt: String?, now: Long = System.currentTimeMillis()): Triple<Long, Long, Boolean>? {
    val due = parseInstant(dueAt)?.toEpochMilli() ?: return null
    val diff = kotlin.math.abs(due - now) / 1000
    return Triple(diff / 86400, (diff % 86400) / 3600, due < now)
}
