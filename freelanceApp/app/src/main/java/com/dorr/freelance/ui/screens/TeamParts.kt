package com.dorr.freelance.ui.screens

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.AppLocale
import com.dorr.freelance.ui.kit.RedToggle
import com.dorr.freelance.ui.kit.StatusPill
import com.dorr.freelance.ui.kit.Tints
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut

/*
 * FS-209: the small pieces the team screens share — a team's logo, the role pill, a person's initials, a switch row, and the copy / share of
 * an invitation code.
 */

/** A team's logo in a rounded square, or a group icon when it has none (or it can't be reached). [version] makes a new upload show at once. */
@Composable
internal fun TeamLogo(url: String?, size: Dp, modifier: Modifier = Modifier, onBrand: Boolean = false, version: Long = 0L) {
    val shown = remember(url, version) {
        ApiClient.mediaUrl(url)?.let { if (version == 0L) it else "$it${if ('?' in it) "&" else "?"}v=$version" }
    }
    var failed by remember(shown) { mutableStateOf(false) }
    val tint = if (onBrand) Color.White else settingsAccent()
    Box(
        modifier.size(size).clip(RoundedCornerShape(size * 0.28f)).background(tint.copy(alpha = if (onBrand) 0.22f else 0.12f)),
        contentAlignment = Alignment.Center,
    ) {
        if (shown != null && !failed) {
            AsyncImage(model = shown, contentDescription = null, contentScale = ContentScale.Crop, onError = { failed = true }, modifier = Modifier.size(size))
        } else {
            Icon(Icons.Rounded.Groups, contentDescription = null, tint = tint, modifier = Modifier.size(size / 2))
        }
    }
}

@Composable
internal fun roleLabel(role: String): String = stringResource(
    when (role) {
        "owner" -> R.string.team_role_owner
        "pm" -> R.string.team_role_pm
        else -> R.string.team_role_member
    },
)

@Composable
internal fun roleHint(role: String): String = stringResource(
    when (role) {
        "owner" -> R.string.team_role_owner_hint
        "pm" -> R.string.team_role_pm_hint
        else -> R.string.team_role_member_hint
    },
)

internal fun roleTint(role: String): Color = when (role) {
    "owner" -> Tints.amber
    "pm" -> Tints.blue
    else -> Tints.teal
}

@Composable
internal fun RolePill(role: String) = StatusPill(roleLabel(role), roleTint(role))

/** A person without a photo: the first letter of the name on a soft disc. */
@Composable
internal fun InitialsBadge(name: String?, size: Dp, tint: Color = settingsAccent()) {
    val letter = name?.trim()?.firstOrNull()?.uppercase() ?: "?"
    Box(Modifier.size(size).clip(CircleShape).background(tint.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
        Text(letter, color = tint, fontSize = (size.value * 0.42f).sp, fontWeight = FontWeight.Black)
    }
}

/** A switch with its title and a line under it; the whole row is the tap target. */
@Composable
internal fun TeamSwitchRow(title: String, subtitle: String?, on: Boolean, enabled: Boolean = true, onToggle: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().clickable(enabled = enabled, onClick = onToggle).padding(vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f).padding(end = 12.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(title, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
            if (subtitle != null) Text(subtitle, color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp)
        }
        Box(Modifier.alpha(if (enabled) 1f else 0.5f)) { RedToggle(on = on, onClick = { if (enabled) onToggle() }) }
    }
}

/** An ISO date as a short date in the app's language (null when it can't be read). */
internal fun teamDate(iso: String?): String? = iso?.let {
    runCatching {
        java.time.OffsetDateTime.parse(it).toLocalDate()
            .format(java.time.format.DateTimeFormatter.ofLocalizedDate(java.time.format.FormatStyle.MEDIUM).withLocale(AppLocale.java()))
    }.getOrNull()
}

internal fun copyText(context: Context, text: String) {
    val clipboard = context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
    clipboard.setPrimaryClip(ClipData.newPlainText("code", text))
}

internal fun shareText(context: Context, text: String) {
    val send = Intent(Intent.ACTION_SEND).apply {
        type = "text/plain"
        putExtra(Intent.EXTRA_TEXT, text)
    }
    context.startActivity(Intent.createChooser(send, null))
}
