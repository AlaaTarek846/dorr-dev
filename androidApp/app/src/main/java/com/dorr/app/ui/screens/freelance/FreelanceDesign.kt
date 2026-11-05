package com.dorr.app.ui.screens.freelance

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.ui.screens.profile.SubHeader
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsSurface

/**
 * The look the redesigned freelance pages share (the client's side, see docs/modules/freelance/CLIENT-APP-REDESIGN-PLAN.md): the head of
 * a page, the head of a section, the tiles of the hub and a small count. The app's own colour (`settingsAccent`) and its light / dark
 * surfaces; nothing new in colour.
 */
internal object FlCorners {
    val card = RoundedCornerShape(20.dp)
    val tile = RoundedCornerShape(16.dp)
    val icon = RoundedCornerShape(14.dp)
}

/**
 * The head of every freelance page: the title (and a quiet line under it when there is one), an action of the page, and the way back —
 * the same button as the app's other pages.
 */
@Composable
internal fun FlPageHeader(title: String, onBack: () -> Unit, subtitle: String? = null, trailing: @Composable () -> Unit = {}) {
    if (subtitle.isNullOrBlank()) {
        SubHeader(title, onBack, trailing)
        return
    }
    Column {
        SubHeader(title, onBack, trailing)
        Text(
            subtitle, color = settingsMut(), fontSize = 13.sp, lineHeight = 18.sp,
            modifier = Modifier.padding(horizontal = 16.dp).padding(bottom = 6.dp).offset(y = (-6).dp),
        )
    }
}

/** A small filled action in a page's head (e.g. «New project»). */
@Composable
internal fun FlHeaderAction(text: String, icon: ImageVector, onClick: () -> Unit) {
    val accent = settingsAccent()
    Row(
        Modifier.padding(end = 8.dp).clip(RoundedCornerShape(999.dp)).background(accent)
            .clickable(role = Role.Button, onClick = onClick).heightIn(min = 34.dp).padding(horizontal = 12.dp, vertical = 7.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(5.dp),
    ) {
        Icon(icon, null, tint = Color.White, modifier = Modifier.size(16.dp))
        Text(text, color = Color.White, fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
    }
}

/** The head of a section on a page: its name, and «see all» (or another action) on the other side when there is one. */
@Composable
internal fun FlSectionHeader(title: String, modifier: Modifier = Modifier, action: String? = null, onAction: () -> Unit = {}) {
    Row(modifier.fillMaxWidth().semantics(mergeDescendants = true) {}, verticalAlignment = Alignment.CenterVertically) {
        Text(title, color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
        if (action != null) Text(
            action, color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
            modifier = Modifier.clip(RoundedCornerShape(999.dp)).clickable(role = Role.Button, onClick = onAction).padding(horizontal = 8.dp, vertical = 4.dp),
        )
    }
}

/** A count on a tile or a button (what waits there); nothing below 1. */
@Composable
internal fun FlCountBadge(count: Int, modifier: Modifier = Modifier) {
    if (count <= 0) return
    Text(
        if (count > 99) "99+" else count.toString(), color = Color.White, fontSize = 10.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1,
        modifier = modifier.clip(RoundedCornerShape(999.dp)).background(Color(0xFFDC2626)).padding(horizontal = 6.dp, vertical = 1.dp),
    )
}

/** A big tile of the hub: an icon on its own colour, the name of the section and what is in it. */
@Composable
internal fun FlHubTile(icon: ImageVector, title: String, line: String, tint: Color, modifier: Modifier = Modifier, badge: Int = 0, onClick: () -> Unit) {
    Column(
        modifier.settingsSurface(FlCorners.card).clickable(role = Role.Button, onClick = onClick).padding(14.dp)
            .semantics(mergeDescendants = true) {},
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(verticalAlignment = Alignment.Top) {
            Box(Modifier.size(44.dp).clip(FlCorners.icon).background(tint.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                Icon(icon, null, tint = tint, modifier = Modifier.size(23.dp))
            }
            Spacer(Modifier.weight(1f))
            FlCountBadge(badge)
        }
        Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(title, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(line, color = settingsMut(), fontSize = 12.sp, lineHeight = 16.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
        }
    }
}

/** A small tile of a tool: an icon over its name, a count on the corner when something waits. */
@Composable
internal fun FlToolTile(icon: ImageVector, title: String, tint: Color, modifier: Modifier = Modifier, badge: Int = 0, onClick: () -> Unit) {
    Column(
        modifier.settingsSurface(FlCorners.tile, elevation = 4.dp).clickable(role = Role.Button, onClick = onClick)
            .padding(vertical = 14.dp, horizontal = 6.dp).semantics(mergeDescendants = true) {},
        horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Box {
            Box(Modifier.size(40.dp).clip(FlCorners.icon).background(tint.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                Icon(icon, null, tint = tint, modifier = Modifier.size(21.dp))
            }
            FlCountBadge(badge, Modifier.align(Alignment.TopEnd).offset(x = 8.dp, y = (-6).dp))
        }
        Text(title, color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.Bold, maxLines = 2, lineHeight = 15.sp, overflow = TextOverflow.Ellipsis,
            textAlign = androidx.compose.ui.text.style.TextAlign.Center)
    }
}

/** A number with its name in a tile (the hub's numbers): an icon on its colour, the figure big, the name under it; opens its page. */
@Composable
internal fun FlStatTile(icon: ImageVector, value: String, label: String, tint: Color, onClick: () -> Unit) {
    Column(
        Modifier.width(118.dp).settingsSurface(FlCorners.tile, elevation = 4.dp).clickable(role = Role.Button, onClick = onClick)
            .padding(12.dp).semantics(mergeDescendants = true) {},
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Box(Modifier.size(32.dp).clip(RoundedCornerShape(10.dp)).background(tint.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = tint, modifier = Modifier.size(18.dp))
        }
        Text(value, color = settingsInk(), fontSize = 19.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
        Text(label, color = settingsMut(), fontSize = 11.5.sp, lineHeight = 15.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
    }
}

/** The parts of a list (its statuses): side by side as tabs on a page of its own, as a row of chips inside a hub page (one row of tabs is enough). */
@Composable
internal fun FlPartTabs(tabs: List<FlTab>, selected: Int, compact: Boolean, onSelect: (Int) -> Unit) {
    if (compact) Row(
        Modifier.horizontalScroll(androidx.compose.foundation.rememberScrollState()).padding(horizontal = 14.dp, vertical = 8.dp),
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        tabs.forEachIndexed { i, t -> Chip(t.label + if (t.badge > 0) "  ${t.badge}" else "", i == selected, t.icon) { onSelect(i) } }
    } else Box(Modifier.padding(horizontal = 16.dp, vertical = 4.dp)) { FlTabs(tabs, selected, onSelect) }
}

/** A person's round picture, or the first letter of their name on the app's colour (freelancers on cards, lists and pages). */
@Composable
internal fun FlAvatar(url: String?, name: String?, size: Int = 34) {
    Box(Modifier.size(size.dp).clip(androidx.compose.foundation.shape.CircleShape).background(settingsAccent().copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
        val reachable = com.dorr.app.network.ApiClient.mediaUrl(url)
        if (!reachable.isNullOrBlank()) coil.compose.AsyncImage(
            model = reachable, contentDescription = null, contentScale = androidx.compose.ui.layout.ContentScale.Crop, modifier = Modifier.matchParentSize(),
        )
        else Text(name?.trim()?.firstOrNull()?.uppercase() ?: "?", color = settingsAccent(), fontSize = (size / 2.4).sp, fontWeight = FontWeight.ExtraBold)
    }
}

/**
 * Where a service or a consultation comes from — a small flag and the country's name. Freelance work is open across countries (owner,
 * 2026-11-04), so the market mixes countries and this says which. Nothing when the server did not say.
 */
@Composable
internal fun FlCountryTag(country: com.dorr.app.network.FlCountryRefDto?, modifier: Modifier = Modifier) {
    val c = country ?: return
    Row(modifier, verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(5.dp)) {
        val iso = c.code?.trim()?.lowercase().orEmpty()
        if (iso.length == 2) coil.compose.AsyncImage(
            model = "https://flagcdn.com/w40/$iso.png", contentDescription = null, contentScale = androidx.compose.ui.layout.ContentScale.Crop,
            modifier = Modifier.size(width = 18.dp, height = 13.dp).clip(RoundedCornerShape(2.dp)),
        )
        Text(c.name ?: c.code.orEmpty(), color = settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

/** A status with a dot before it, on its colour (filled when [solid]): orders, consultations, subscriptions. */
@Composable
internal fun FlDotPill(text: String, color: Color, solid: Boolean = false, modifier: Modifier = Modifier) {
    Row(
        modifier.clip(RoundedCornerShape(999.dp)).background(if (solid) color else color.copy(alpha = if (com.dorr.app.ui.screens.profile.settingsNight()) 0.22f else 0.12f))
            .padding(horizontal = 10.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(6.dp).clip(androidx.compose.foundation.shape.CircleShape).background(if (solid) Color.White else color))
        Spacer(Modifier.width(6.dp))
        Text(text, color = if (solid) Color.White else color, fontSize = 11.5.sp, fontWeight = FontWeight.Bold, maxLines = 1)
    }
}

/** The tints the hub gives its sections (each section keeps its colour wherever it shows). */
internal object FlTints {
    val projects = Color(0xFF3B82F6)
    val services = Color(0xFF14B8A6)
    val consultations = Color(0xFF8B5CF6)
    val orders = Color(0xFFF59E0B)
    val special = Color(0xFFF43F5E)
    val loyalty = Color(0xFFD97706)
    val favorites = Color(0xFFEC4899)
    val mine = Color(0xFF0EA5E9)
}
