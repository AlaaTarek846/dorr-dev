package com.dorr.freelance.ui.kit

import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.animateDpAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.collectAsState
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.material.icons.rounded.LocalOffer
import androidx.compose.material.icons.rounded.Loyalty
import androidx.compose.material.icons.rounded.Storefront
import androidx.compose.material.icons.rounded.VideoChat
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.freelance.ui.components.CountBadge

/*
 * The redesign's shared pieces (one look for the tabs): three corner sizes, the section head, the parts switch of a hub tab, the hub
 * tab's frame, a number tile and a shortcut tile. Colours come from the appearance tokens through the Kit helpers.
 */

/** The three corners the app uses: small chips and tiles, cards, and the big hero cards. */
internal object Corners {
    val small = RoundedCornerShape(12.dp)
    val card = RoundedCornerShape(18.dp)
    val hero = RoundedCornerShape(24.dp)
}

/** A section's head: its title on one side and an optional «See all» on the other. */
@Composable
internal fun SectionHeader(title: String, modifier: Modifier = Modifier, action: String? = null, onAction: (() -> Unit)? = null) {
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    Row(modifier.fillMaxWidth().padding(top = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(title, color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
        if (action != null && onAction != null) Row(
            Modifier.clip(RoundedCornerShape(50)).clickable(onClick = onAction).padding(horizontal = 8.dp, vertical = 4.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(action, color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
            Icon(Icons.Rounded.ChevronRight, null, tint = settingsAccent(), modifier = Modifier.size(16.dp).graphicsLayer { if (rtl) scaleX = -1f })
        }
    }
}

/** One part of a hub tab: its key, its label, an icon and what waits in it. */
internal data class Segment(val key: String, val label: String, val icon: ImageVector? = null, val count: Int = 0)

/** The parts switch under a hub tab's title: a soft track with the chosen part on a raised pill; scrolls sideways when the parts are many. */
@Composable
internal fun SegmentTabs(segments: List<Segment>, selected: String, onSelect: (String) -> Unit, modifier: Modifier = Modifier) {
    val night = settingsNight()
    val accent = settingsAccent()
    Row(
        modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp))
            .background(if (night) AccountDark.card else accent.copy(alpha = 0.07f))
            .horizontalScroll(rememberScrollState()).padding(4.dp),
        horizontalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        segments.forEach { s ->
            val on = s.key == selected
            val fill by animateColorAsState(if (on) (if (night) AccountDark.accent else Color.White) else Color.Transparent, tween(200), label = "segFill")
            val ink = if (on) (if (night) Color.White else accent) else settingsMut()
            Row(
                Modifier
                    .then(if (on && !night) Modifier.shadow(4.dp, RoundedCornerShape(12.dp), spotColor = accent.copy(alpha = 0.18f)) else Modifier)
                    .clip(RoundedCornerShape(12.dp)).background(fill).clickable(role = androidx.compose.ui.semantics.Role.Tab) { onSelect(s.key) }
                    .heightIn(min = 40.dp).padding(horizontal = 14.dp, vertical = 9.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                s.icon?.let { Icon(it, null, tint = ink, modifier = Modifier.size(16.dp)); Spacer(Modifier.width(6.dp)) }
                Text(s.label, color = ink, fontSize = 13.sp, fontWeight = if (on) FontWeight.ExtraBold else FontWeight.SemiBold, maxLines = 1, softWrap = false)
                if (s.count > 0) {
                    Spacer(Modifier.width(6.dp))
                    CountBadge(s.count, background = accent, ink = Color.White)
                }
            }
        }
    }
}

/** A hub tab (Opportunities, My work): its title and line, the parts switch, then the open part filling the rest. */
@Composable
internal fun HubTabFrame(title: String, subtitle: String?, segments: List<Segment>, selected: String, onSelect: (String) -> Unit, onBack: (() -> Unit)? = null, content: @Composable () -> Unit) {
    Column(Modifier.fillMaxSize()) {
        Column(Modifier.padding(horizontal = 16.dp).padding(top = 14.dp, bottom = 10.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(title, color = settingsInk(), fontSize = 22.sp, fontWeight = FontWeight.Black, modifier = Modifier.weight(1f))
                if (onBack != null) com.dorr.freelance.ui.screens.RoundIcon(Icons.AutoMirrored.Rounded.ArrowBack, androidx.compose.ui.res.stringResource(com.dorr.freelance.R.string.common_back), mirrored = true, onClick = onBack)
            }
            subtitle?.let { Text(it, color = settingsMut(), fontSize = 12.sp, modifier = Modifier.padding(top = 2.dp)) }
            if (segments.size > 1) SegmentTabs(segments, selected, onSelect, Modifier.padding(top = 12.dp))
        }
        Box(Modifier.weight(1f).fillMaxWidth()) { content() }
    }
}

/** A card of the app: a soft shadow by day, a hairline at night. */
@Composable
internal fun Modifier.appCard(shape: RoundedCornerShape = Corners.card, elevation: Dp = 6.dp): Modifier = settingsSurface(shape, elevation)

/** A number tile: an icon on its tint, the value large and what it counts under it. */
@Composable
internal fun StatTile(icon: ImageVector, value: String, label: String, tint: Color, modifier: Modifier = Modifier, onClick: (() -> Unit)? = null) {
    val night = settingsNight()
    Column(
        modifier.appCard(Corners.card, 4.dp).then(if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier).padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Box(Modifier.size(36.dp).clip(Corners.small).background(tint.copy(alpha = if (night) 0.22f else 0.12f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = tint, modifier = Modifier.size(19.dp))
        }
        Column {
            Text(value, color = settingsInk(), fontSize = 19.sp, fontWeight = FontWeight.Black, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(label, color = settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
    }
}

/** A shortcut tile of a grid: an icon on a soft square and a short label under it; [count] adds a badge. */
@Composable
internal fun ShortcutTile(icon: ImageVector, label: String, modifier: Modifier = Modifier, tint: Color = settingsAccent(), count: Int = 0, onClick: () -> Unit) {
    val night = settingsNight()
    Column(
        modifier.appCard(Corners.card, 4.dp).clickable(onClick = onClick).padding(vertical = 14.dp, horizontal = 6.dp).heightIn(min = 84.dp),
        horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center,
    ) {
        Box {
            Box(Modifier.size(42.dp).clip(RoundedCornerShape(14.dp)).background(tint.copy(alpha = if (night) 0.22f else 0.12f)), contentAlignment = Alignment.Center) {
                Icon(icon, null, tint = tint, modifier = Modifier.size(21.dp))
            }
            if (count > 0) CountBadge(count, background = settingsAccent(), ink = Color.White, edge = settingsCard(), modifier = Modifier.align(Alignment.TopEnd).padding(start = 0.dp))
        }
        Spacer(Modifier.height(8.dp))
        Text(label, color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.Bold, maxLines = 2, overflow = TextOverflow.Ellipsis, textAlign = androidx.compose.ui.text.style.TextAlign.Center)
    }
}

/** A row of the «needs your answer» strip: a coloured dot icon, the line and a chevron. */
@Composable
internal fun AttentionChip(icon: ImageVector, text: String, tint: Color, onClick: () -> Unit) {
    val night = settingsNight()
    Row(
        Modifier.clip(RoundedCornerShape(50)).background(tint.copy(alpha = if (night) 0.20f else 0.10f))
            .border(1.dp, tint.copy(alpha = 0.25f), RoundedCornerShape(50))
            .clickable(onClick = onClick).padding(start = 6.dp, end = 12.dp, top = 6.dp, bottom = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(24.dp).clip(CircleShape).background(tint), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = Color.White, modifier = Modifier.size(14.dp))
        }
        Spacer(Modifier.width(8.dp))
        Text(text, color = settingsInk(), fontSize = 12.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, softWrap = false)
    }
}

/** The tints the tiles use besides the app colour (the same in both modes; tiles soften them with alpha). */
internal object Tints {
    val blue = Color(0xFF3B82F6)
    val green = Color(0xFF10B981)
    val amber = Color(0xFFF59E0B)
    val violet = Color(0xFF8B5CF6)
    val teal = Color(0xFF14B8A6)
    val rose = Color(0xFFF43F5E)
}

/** One choice of a filter row: its key, label, an optional icon, and a green dot when something there waits for me. */
internal data class FilterOption(val key: String, val label: String, val icon: ImageVector? = null, val dot: Boolean = false)

/**
 * The second-level filter of a list (under a hub tab's parts): pills that scroll sideways, the chosen one filled with the app colour and the
 * others outlined — one look for every list of «My work» and «Opportunities».
 */
@Composable
internal fun FilterChips(options: List<FilterOption>, selected: String, onSelect: (String) -> Unit, modifier: Modifier = Modifier) {
    Row(modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        options.forEach { o -> PillChip(o.label, o.key == selected, o.icon, o.dot) { onSelect(o.key) } }
    }
}

/** A pill: filled with the app colour when chosen, a soft outline otherwise. */
@Composable
internal fun PillChip(text: String, selected: Boolean, icon: ImageVector? = null, dot: Boolean = false, onClick: () -> Unit) {
    val isOn = selected
    val accent = settingsAccent()
    val ink = if (selected) Color.White else settingsInk()
    Row(
        Modifier.clip(RoundedCornerShape(999.dp))
            .background(if (selected) accent else settingsCard())
            .border(1.dp, if (selected) accent else settingsMut().copy(alpha = 0.22f), RoundedCornerShape(999.dp))
            .clickable(role = androidx.compose.ui.semantics.Role.Tab, onClick = onClick)
            .heightIn(min = 40.dp).padding(horizontal = 14.dp, vertical = 8.dp)
            .semantics { this.selected = isOn },
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (icon != null) {
            Icon(icon, contentDescription = null, tint = if (selected) ink else accent, modifier = Modifier.size(15.dp))
            Spacer(Modifier.width(6.dp))
        }
        Text(text, color = ink, fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 1, softWrap = false)
        if (dot) Box(Modifier.padding(start = 6.dp).size(7.dp).clip(CircleShape).background(if (selected) Color.White else Tints.green))
    }
}

/** A small fact: an icon and a short text (money in the ink colour, the rest muted). */
@Composable
internal fun FactLine(icon: ImageVector, text: String, strong: Boolean = false) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Icon(icon, null, tint = if (strong) settingsAccent() else settingsMut(), modifier = Modifier.size(15.dp))
        Spacer(Modifier.width(4.dp))
        Text(text, color = if (strong) settingsInk() else settingsMut(), fontSize = 12.5.sp, fontWeight = if (strong) FontWeight.ExtraBold else FontWeight.SemiBold, maxLines = 1)
    }
}

/** A status pill in its tone. */
@Composable
internal fun StatusPill(text: String, tint: Color, icon: ImageVector? = null) {
    Row(
        Modifier.clip(RoundedCornerShape(999.dp)).background(tint.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (icon != null) { Icon(icon, null, tint = tint, modifier = Modifier.size(12.dp)); Spacer(Modifier.width(4.dp)) }
        Text(text, color = tint, fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1)
    }
}

/** One number in a store page's head. */
internal data class HeroNumber(val value: String, val label: String, val weight: Float = 1f)

/**
 * The head of a store page (my services, consultations, special offers, loyalty) in the home slider's look: the app colour deepening across,
 * soft rings, an icon on a light tile with the title and a line (or [status]), the numbers on light tiles, a white main button and a small
 * line under it.
 */
@Composable
internal fun StoreHero(
    icon: ImageVector,
    title: String,
    body: String?,
    numbers: List<HeroNumber>,
    status: (@Composable () -> Unit)? = null,
    action: String? = null,
    actionIcon: ImageVector? = null,
    actionEnabled: Boolean = true,
    onAction: (() -> Unit)? = null,
    footnote: String? = null,
) {
    val accent = settingsAccent()
    val deep = Color(accent.red * 0.62f, accent.green * 0.62f, accent.blue * 0.62f)
    Box(Modifier.fillMaxWidth().clip(Corners.hero).background(androidx.compose.ui.graphics.Brush.linearGradient(listOf(accent, deep)))) {
        Box(Modifier.size(180.dp).align(Alignment.TopEnd).padding(start = 40.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.07f)))
        Box(Modifier.size(110.dp).align(Alignment.BottomStart).clip(CircleShape).background(Color.White.copy(alpha = 0.06f)))
        Column(Modifier.fillMaxWidth().padding(18.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                Box(Modifier.size(46.dp).clip(RoundedCornerShape(15.dp)).background(Color.White.copy(alpha = 0.18f)), contentAlignment = Alignment.Center) {
                    Icon(icon, contentDescription = null, tint = Color.White, modifier = Modifier.size(24.dp))
                }
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(title, color = Color.White, fontSize = 18.sp, fontWeight = FontWeight.Black, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    if (status != null) status()
                    else body?.let { Text(it, color = Color.White.copy(alpha = 0.88f), fontSize = 12.sp, lineHeight = 17.sp) }
                }
            }
            if (numbers.isNotEmpty()) Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                numbers.forEach { n ->
                    Column(
                        Modifier.weight(n.weight).clip(RoundedCornerShape(14.dp)).background(Color.White.copy(alpha = 0.14f)).padding(vertical = 10.dp, horizontal = 6.dp),
                        horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(2.dp),
                    ) {
                        Text(n.value, color = Color.White, fontSize = 18.sp, fontWeight = FontWeight.Black, maxLines = 1)
                        Text(n.label, color = Color.White.copy(alpha = 0.85f), fontSize = 10.5.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                }
            }
            if (action != null && onAction != null) Row(
                Modifier.fillMaxWidth().height(48.dp).clip(RoundedCornerShape(16.dp)).background(Color.White.copy(alpha = if (actionEnabled) 1f else 0.6f))
                    .clickable(enabled = actionEnabled, onClick = onAction),
                horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
            ) {
                actionIcon?.let { Icon(it, null, tint = accent, modifier = Modifier.size(18.dp)); Spacer(Modifier.width(8.dp)) }
                Text(action, color = accent, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
            }
            footnote?.let { Text(it, color = Color.White.copy(alpha = 0.9f), fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold) }
        }
    }
}

/** The store's pages, with the country feature each needs. */
internal val STORE_PAGES = listOf("services" to "services", "consultations" to "consultations", "special_offers" to "special_offers", "loyalty" to "loyalty")

/**
 * The switch at the top of a store page: one pill per page of my store (those on in my country), the open one filled; a tap swaps the page
 * in place.
 */
@Composable
internal fun StoreSwitcher(current: String, onOpen: (String) -> Unit) {
    val home by com.dorr.freelance.net.HomeHub.home.collectAsState()
    val pages = STORE_PAGES.filter { (_, feature) -> home?.on(feature) != false }.map { it.first }
    if (pages.size < 2) return
    Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        pages.forEach { key ->
            PillChip(
                androidx.compose.ui.res.stringResource(
                    when (key) {
                        "services" -> com.dorr.freelance.R.string.svc_home_card
                        "consultations" -> com.dorr.freelance.R.string.cns_home_card
                        "special_offers" -> com.dorr.freelance.R.string.spo_home_card
                        else -> com.dorr.freelance.R.string.loy_home_card
                    },
                ),
                selected = key == current,
                icon = when (key) {
                    "services" -> androidx.compose.material.icons.Icons.Rounded.Storefront
                    "consultations" -> androidx.compose.material.icons.Icons.Rounded.VideoChat
                    "special_offers" -> androidx.compose.material.icons.Icons.Rounded.LocalOffer
                    else -> androidx.compose.material.icons.Icons.Rounded.Loyalty
                },
            ) { if (key != current) onOpen(key) }
        }
    }
}
