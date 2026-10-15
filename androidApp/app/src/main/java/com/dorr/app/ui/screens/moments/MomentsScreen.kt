package com.dorr.app.ui.screens.moments

import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.ContentTransform
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideOutHorizontally
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.systemBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Alarm
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.Cake
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.Celebration
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.KeyboardArrowDown
import androidx.compose.material.icons.rounded.LightMode
import androidx.compose.material.icons.rounded.Public
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Settings
import androidx.compose.material.icons.rounded.Tune
import androidx.compose.material.icons.rounded.VisibilityOff
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Shadow
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AppLocale
import com.dorr.app.network.AuthSession
import com.dorr.app.network.ContactDto
import com.dorr.app.network.CountryDto
import com.dorr.app.network.MomentCatalogItemDto
import com.dorr.app.network.MomentDto
import com.dorr.app.network.MomentsCenterDto
import com.dorr.app.network.PersonalMomentDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.components.DarkHeroButton
import com.dorr.app.ui.components.DarkHeroPanel
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.theme.CairoFontFamily
import com.dorr.app.ui.screens.AccountDark
import com.dorr.app.ui.screens.profile.PinkBackdrop
import com.dorr.app.ui.screens.profile.PinkIcon
import com.dorr.app.ui.screens.profile.RedToggle
import com.dorr.app.ui.screens.profile.SubHeader
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.screens.profile.settingsSurface
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaEmpty
import com.dorr.app.ui.screens.wallet.WaError
import com.dorr.app.ui.screens.wallet.WaPage
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.wallet.rememberPressScale
import com.dorr.app.ui.screens.wallet.waRise
import com.google.gson.JsonArray
import com.google.gson.JsonNull
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import com.dorr.app.ui.screens.chat.ChSheet

/*
 * DORR Moments in the app (spec 160, 168): the home page's banner, the Moments page (on now,
 * coming, my own dates) and its preferences. Occasions come from the server — nothing here knows
 * any occasion by name.
 */

private fun auth() = "Bearer ${AuthSession.token.orEmpty()}"

/** The Moments Center, shared by the home banner and the page (one load, kept fresh). */
@Stable
object MomentsStore {
    var center by mutableStateOf<MomentsCenterDto?>(null)

    suspend fun refresh() {
        runCatching { ApiClient.moments.center(auth()).data }.getOrNull()?.let { center = it }
    }
}

private fun dateLabel(iso: String?): String {
    if (iso.isNullOrBlank()) return ""
    val locale = java.util.Locale.forLanguageTag(AppLocale.current)
    val raw = iso.trim().take(10).replace('/', '-')
    val day = runCatching { LocalDate.parse(raw) }.getOrNull()
        ?: runCatching {
            val p = iso.trim().split('/', '-')
            LocalDate.of(p[0].toInt(), p[1].toInt(), p[2].take(2).toInt())
        }.getOrNull()
    return day?.format(DateTimeFormatter.ofLocalizedDate(FormatStyle.MEDIUM).withLocale(locale)) ?: iso
}

@Composable
private fun whenLabel(daysLeft: Int, isToday: Boolean): String = when {
    isToday || daysLeft == 0 -> stringResource(R.string.mo_today)
    daysLeft == 1 -> stringResource(R.string.mo_tomorrow)
    else -> stringResource(R.string.mo_in_days, daysLeft)
}

// =============================================================================== home banner

/**
 * On the home page: the occasion that's on (its colours, emoji and animation) — or the next one
 * soon, as a slim strip. Nothing when Moments are off.
 */
@Composable
fun MomentsBanner(onOpen: () -> Unit, modifier: Modifier = Modifier) {
    LaunchedEffect(Unit) { MomentsStore.refresh() }
    val center = MomentsStore.center ?: return
    if (!center.enabled) return
    val active = center.active.firstOrNull { it.isToday } ?: center.active.firstOrNull()
    val source = remember { MutableInteractionSource() }
    val press by rememberPressScale(source, 0.98f)

    if (active != null) {
        Box(
            modifier.fillMaxWidth().scale(press).height(132.dp).clip(RoundedCornerShape(24.dp)).background(momentBrush(active.primaryColor, active.secondaryColor))
                .clickable(interactionSource = source, indication = null, onClick = onOpen),
        ) {
            active.cardImage?.let { AsyncImage(it, null, contentScale = ContentScale.Crop, modifier = Modifier.matchParentSize()) }
            MomentEffect(active.animation, active.emoji, active.primaryColor, active.secondaryColor, center.effects, Modifier.matchParentSize())
            Row(Modifier.fillMaxSize().padding(18.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(active.emoji ?: "✨", fontSize = 44.sp)
                Spacer(Modifier.width(14.dp))
                Column(Modifier.weight(1f)) {
                    Text(
                        if (active.isToday) active.name.orEmpty() else stringResource(R.string.mo_soon_named, active.name.orEmpty(), whenLabel(active.daysLeft, false)),
                        color = Color.White, fontSize = 19.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis,
                    )
                    active.greeting?.let { Text(it, color = Color.White.copy(alpha = 0.9f), fontSize = 13.sp, maxLines = 2, overflow = TextOverflow.Ellipsis) }
                }
            }
        }
        return
    }

    // Nothing on now: the next one within two weeks, as a strip.
    val next = center.upcoming.firstOrNull { it.daysLeft <= 14 }
    val personal = center.personal.firstOrNull { it.daysLeft <= 7 }
    if (next == null && personal == null) return
    Row(
        modifier.fillMaxWidth().scale(press).clip(RoundedCornerShape(18.dp)).background(Wa.Surface)
            .border(1.dp, Wa.Line, RoundedCornerShape(18.dp)).clickable(interactionSource = source, indication = null, onClick = onOpen).padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        val emoji = next?.emoji ?: personal?.look?.emoji ?: "✨"
        Box(Modifier.size(40.dp).clip(CircleShape).background(momentBrush(next?.primaryColor ?: personal?.look?.primaryColor, next?.secondaryColor ?: personal?.look?.secondaryColor)), contentAlignment = Alignment.Center) {
            Text(emoji, fontSize = 20.sp)
        }
        Spacer(Modifier.width(10.dp))
        Text(
            if (next != null) stringResource(R.string.mo_soon_named, next.name.orEmpty(), whenLabel(next.daysLeft, next.isToday))
            else stringResource(R.string.mo_soon_named, personal!!.title, whenLabel(personal.daysLeft, false)),
            color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis,
        )
        Icon(Icons.Rounded.Event, null, tint = Wa.Red, modifier = Modifier.size(20.dp))
    }
}

// =============================================================================== the page

private sealed interface MomentsPage {
    data object Center : MomentsPage
    data object Settings : MomentsPage
    data object Collabs : MomentsPage
    data class Collab(val id: String) : MomentsPage
    data object Capsules : MomentsPage
    data class Capsule(val id: String) : MomentsPage
}

private fun MomentsPage.depth(): Int = when (this) {
    MomentsPage.Center -> 0
    MomentsPage.Settings, MomentsPage.Collabs, MomentsPage.Capsules -> 1
    is MomentsPage.Collab, is MomentsPage.Capsule -> 2
}

private fun MomentsPage.up(): MomentsPage? = when (this) {
    MomentsPage.Center -> null
    is MomentsPage.Collab -> MomentsPage.Collabs
    is MomentsPage.Capsule -> MomentsPage.Capsules
    else -> MomentsPage.Center
}

@Composable
fun MomentsScreen(onExit: () -> Unit) {
    var page by remember { mutableStateOf<MomentsPage>(MomentsPage.Center) }
    BackHandler { page.up()?.let { page = it } ?: onExit() }
    val sign = if (LocalLayoutDirection.current == LayoutDirection.Rtl) -1 else 1

    Box(Modifier.fillMaxSize().background(Wa.Bg).systemBarsPadding().imePadding()) {
        AnimatedContent(
            targetState = page,
            transitionSpec = {
                val forward = targetState.depth() > initialState.depth()
                ContentTransform(
                    slideInHorizontally(tween(320)) { (if (forward) sign else -sign) * it / 6 } + fadeIn(tween(280)),
                    slideOutHorizontally(tween(280)) { (if (forward) -sign else sign) * it / 10 } + fadeOut(tween(200)),
                )
            },
            label = "momentsPages",
        ) { p ->
            when (p) {
                MomentsPage.Center -> MomentsCenter(
                    onBack = onExit, onSettings = { page = MomentsPage.Settings },
                    onCollabs = { page = MomentsPage.Collabs }, onCapsules = { page = MomentsPage.Capsules },
                )
                MomentsPage.Settings -> MomentsSettings(onBack = { page = MomentsPage.Center })
                MomentsPage.Collabs -> CollabCardsPage(onBack = { page = MomentsPage.Center }, onOpen = { page = MomentsPage.Collab(it) })
                is MomentsPage.Collab -> CollabCardPage(p.id, onBack = { page = MomentsPage.Collabs })
                MomentsPage.Capsules -> CapsulesPage(onBack = { page = MomentsPage.Center }, onOpen = { page = MomentsPage.Capsule(it) })
                is MomentsPage.Capsule -> CapsulePage(p.id, onBack = { page = MomentsPage.Capsules })
            }
        }
    }
}

@Composable
private fun MomentsCenter(onBack: () -> Unit, onSettings: () -> Unit, onCollabs: () -> Unit, onCapsules: () -> Unit) {
    var editing by remember { mutableStateOf<PersonalMomentDto?>(null) }
    var adding by remember { mutableStateOf(false) }
    // A card to send: for an occasion, for a date of mine (to that person), or from scratch.
    var card by remember { mutableStateOf<Pair<CardPreset, com.dorr.app.network.ProfileDto?>?>(null) }
    LaunchedEffect(Unit) { MomentsStore.refresh() }
    val center = MomentsStore.center

    WaPage(
        title = stringResource(R.string.mo_title),
        onBack = onBack,
        showHeader = false,
        cta = { WaButton(stringResource(R.string.mo_add_personal), { adding = true }, icon = Icons.Rounded.Cake) },
    ) {
        DarkHeroPanel {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text(
                    stringResource(R.string.mo_title),
                    color = Color.White,
                    fontSize = 22.sp,
                    fontWeight = FontWeight.Black,
                    fontFamily = CairoFontFamily,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.weight(1f),
                )
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                    DarkHeroButton(Icons.Rounded.Settings, onSettings, stringResource(R.string.mo_settings))
                    DarkHeroButton(Icons.AutoMirrored.Rounded.ArrowBack, onBack, stringResource(R.string.common_back), flip = true)
                }
            }
        }
        Spacer(Modifier.height(12.dp))
        when {
            center == null -> {
                WaSkeleton(Modifier.fillMaxWidth().height(172.dp).padding(bottom = 12.dp), RoundedCornerShape(22.dp))
                repeat(2) { WaSkeleton(Modifier.fillMaxWidth().height(72.dp).padding(bottom = 10.dp), RoundedCornerShape(16.dp)) }
            }
            !center.enabled -> WaCard(Modifier.fillMaxWidth()) {
                WaEmpty(Icons.Rounded.Event, Tone.Gray, stringResource(R.string.mo_off_title), stringResource(R.string.mo_off_text), action = {
                    WaButton(stringResource(R.string.mo_settings), onSettings, style = WaButtonStyle.Ghost, icon = Icons.Rounded.Tune)
                })
            }
            else -> {
                if (center.active.isNotEmpty()) {
                    center.active.forEachIndexed { i, m ->
                        ActiveMoment(
                            m,
                            center.effects,
                            Modifier
                                .padding(bottom = 12.dp)
                                .waRise(i)
                                .clickable { card = CardPreset(momentId = m.id) to null },
                        )
                    }
                }
                Row(
                    Modifier
                        .fillMaxWidth()
                        .waRise(1)
                        .moHairline(RoundedCornerShape(16.dp))
                        .height(IntrinsicSize.Min),
                ) {
                    QuickTile("💌", stringResource(R.string.mo_card_title), Modifier.weight(1f)) { card = CardPreset() to null }
                    MoVLine()
                    QuickTile("✍️", stringResource(R.string.mo_collab_title), Modifier.weight(1f), onCollabs)
                    MoVLine()
                    QuickTile("📦", stringResource(R.string.mo_capsules), Modifier.weight(1f), onCapsules)
                }

                if (center.personal.isNotEmpty()) {
                    MoCenterLabel(stringResource(R.string.mo_mine), Modifier.waRise(2))
                    MoListCard(Modifier.waRise(3)) {
                        center.personal.forEachIndexed { i, p ->
                            PersonalRow(p, onCard = { card = CardPreset(personalKind = p.kind) to p.contact }) { editing = p }
                            if (i != center.personal.lastIndex) MoListDivider()
                        }
                    }
                }

                val countryName = center.country?.name?.trim().orEmpty()
                MoCenterLabel(
                    if (countryName.isEmpty()) stringResource(R.string.mo_upcoming_plain) else stringResource(R.string.mo_upcoming, countryName),
                    Modifier.waRise(4),
                )
                val coming = center.upcoming.filterNot { it.isVisible }
                if (coming.isEmpty()) {
                    Text(stringResource(R.string.mo_none_soon), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.padding(vertical = 8.dp, horizontal = 2.dp))
                } else {
                    MoListCard(Modifier.waRise(5)) {
                        coming.forEachIndexed { i, m ->
                            UpcomingRow(m)
                            if (i != coming.lastIndex) MoListDivider()
                        }
                    }
                }
            }
        }
    }

    if (adding) PersonalSheet(null) { adding = false }
    editing?.let { p -> PersonalSheet(p) { editing = null } }
    card?.let { (preset, to) -> MomentCardSheet(conversationId = null, peerName = to?.name, canGift = true, preset = preset, toUser = to, onDismiss = { card = null }) }
}

@Composable
private fun Modifier.moHairline(shape: RoundedCornerShape = RoundedCornerShape(16.dp)): Modifier {
    val dark = settingsNight()
    return clip(shape)
        .background(if (dark) AccountDark.card else Color.White)
        .border(1.dp, if (dark) AccountDark.line else Color(0xFFE6E8EC), shape)
}

@Composable
private fun MoVLine() {
    Box(Modifier.width(1.dp).fillMaxHeight().background(if (settingsNight()) AccountDark.line else Color(0xFFE6E8EC)))
}

@Composable
private fun QuickTile(emoji: String, label: String, modifier: Modifier = Modifier, onClick: () -> Unit) {
    val dark = settingsNight()
    Column(
        modifier
            .fillMaxHeight()
            .clickable(onClick = onClick)
            .padding(horizontal = 8.dp, vertical = 12.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        Box(
            Modifier
                .size(36.dp)
                .clip(RoundedCornerShape(10.dp))
                .background(if (dark) AccountDark.well else settingsAccent().copy(alpha = 0.12f)),
            contentAlignment = Alignment.Center,
        ) { Text(emoji, fontSize = 18.sp) }
        Spacer(Modifier.height(6.dp))
        Text(
            label,
            color = settingsInk(),
            fontSize = 12.sp,
            fontWeight = FontWeight.Bold,
            textAlign = TextAlign.Center,
            maxLines = 2,
            lineHeight = 15.sp,
            overflow = TextOverflow.Ellipsis,
        )
    }
}

@Composable
private fun MoCenterLabel(text: String, modifier: Modifier = Modifier) {
    Text(
        text,
        color = settingsInk(),
        fontSize = 13.sp,
        fontWeight = FontWeight.ExtraBold,
        fontFamily = CairoFontFamily,
        modifier = modifier.padding(top = 14.dp, bottom = 8.dp, start = 2.dp),
    )
}

@Composable
private fun MoListCard(modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    Column(modifier.fillMaxWidth().moHairline(RoundedCornerShape(16.dp)).padding(horizontal = 6.dp, vertical = 2.dp), content = content)
}

@Composable
private fun MoListDivider() {
    Box(Modifier.fillMaxWidth().padding(horizontal = 8.dp).height(1.dp).background(if (settingsNight()) AccountDark.line else Color(0xFFF0F1F4)))
}

@Composable
private fun MoWhenChip(daysLeft: Int, isToday: Boolean) {
    val soon = isToday || daysLeft <= 1
    val accent = settingsAccent()
    Text(
        whenLabel(daysLeft, isToday),
        color = if (soon) Color.White else accent,
        fontSize = 11.sp,
        fontWeight = FontWeight.Bold,
        maxLines = 1,
        modifier = Modifier
            .clip(RoundedCornerShape(8.dp))
            .background(if (soon) accent else accent.copy(alpha = 0.12f))
            .padding(horizontal = 8.dp, vertical = 4.dp),
    )
}

@Composable
private fun ActiveMoment(m: MomentDto, effects: String, modifier: Modifier = Modifier) {
    val line = if (m.isToday) m.greeting.orEmpty() else whenLabel(m.daysLeft, false) + " · " + dateLabel(m.start)
    val textShadow = Shadow(Color.Black.copy(alpha = 0.55f), Offset(0f, 1f), 10f)
    Box(modifier.fillMaxWidth().height(172.dp).clip(RoundedCornerShape(22.dp)).background(momentBrush(m.primaryColor, m.secondaryColor))) {
        m.cardImage?.let { AsyncImage(it, null, contentScale = ContentScale.Crop, modifier = Modifier.matchParentSize()) }
        MomentEffect(m.animation, m.emoji, m.primaryColor, m.secondaryColor, effects, Modifier.matchParentSize())
        Box(
            Modifier
                .matchParentSize()
                .background(
                    Brush.verticalGradient(
                        0f to Color.Black.copy(alpha = 0.12f),
                        0.4f to Color.Transparent,
                        1f to Color.Black.copy(alpha = 0.55f),
                    ),
                ),
        )
        Column(
            Modifier.fillMaxSize().padding(horizontal = 16.dp, vertical = 14.dp),
            verticalArrangement = Arrangement.Center,
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(m.emoji ?: "✨", fontSize = 36.sp, style = TextStyle(shadow = textShadow))
            Spacer(Modifier.height(6.dp))
            Text(
                m.name.orEmpty(),
                color = Color.White,
                fontSize = 20.sp,
                fontWeight = FontWeight.ExtraBold,
                textAlign = TextAlign.Center,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
                style = TextStyle(shadow = textShadow),
            )
            if (line.isNotBlank()) {
                Spacer(Modifier.height(4.dp))
                Text(
                    line,
                    color = Color.White.copy(alpha = 0.95f),
                    fontSize = 13.sp,
                    fontWeight = FontWeight.SemiBold,
                    textAlign = TextAlign.Center,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                    style = TextStyle(shadow = textShadow),
                )
            }
        }
    }
}

@Composable
private fun UpcomingRow(m: MomentDto) {
    Row(Modifier.fillMaxWidth().padding(horizontal = 4.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(
            Modifier.size(34.dp).clip(RoundedCornerShape(10.dp)).background(momentBrush(m.primaryColor, m.secondaryColor)),
            contentAlignment = Alignment.Center,
        ) { Text(m.emoji ?: "✨", fontSize = 16.sp) }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(m.name.orEmpty(), color = settingsInk(), fontSize = 13.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(dateLabel(m.start), color = settingsMut(), fontSize = 11.sp, modifier = Modifier.padding(top = 2.dp), maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        Spacer(Modifier.width(8.dp))
        MoWhenChip(m.daysLeft, m.isToday)
    }
}

@Composable
private fun PersonalRow(p: PersonalMomentDto, onCard: () -> Unit, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).clickable(onClick = onClick).padding(horizontal = 4.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            Modifier.size(34.dp).clip(RoundedCornerShape(10.dp)).background(momentBrush(p.look?.primaryColor, p.look?.secondaryColor)),
            contentAlignment = Alignment.Center,
        ) { Text(p.look?.emoji ?: "✨", fontSize = 16.sp) }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(p.title, color = settingsInk(), fontSize = 13.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(
                listOfNotNull(dateLabel(p.next), p.turns?.let { stringResource(R.string.mo_turns, it) }, p.contact?.name).joinToString("  ·  "),
                color = settingsMut(), fontSize = 11.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 2.dp),
            )
        }
        if (p.contact != null) {
            Box(
                Modifier.padding(end = 8.dp).size(32.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.12f)).clickable(onClick = onCard),
                contentAlignment = Alignment.Center,
            ) { Text("💌", fontSize = 14.sp) }
        }
        Spacer(Modifier.width(8.dp))
        MoWhenChip(p.daysLeft, false)
    }
}

// =============================================================================== my own dates

private val PersonalKinds = listOf("birthday" to "🎂", "anniversary" to "💍", "graduation" to "🎓", "wedding" to "💒", "baby" to "👶", "other" to "✨")

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun PersonalSheet(existing: PersonalMomentDto?, onDismiss: () -> Unit) {
    val scope = rememberCoroutineScope()
    var kind by remember { mutableStateOf(existing?.kind ?: "birthday") }
    var title by remember { mutableStateOf(existing?.title.orEmpty()) }
    var day by remember { mutableStateOf(existing?.day?.toString() ?: "") }
    var month by remember { mutableStateOf(existing?.month ?: LocalDate.now().monthValue) }
    var year by remember { mutableStateOf(existing?.year?.toString() ?: "") }
    var remind by remember { mutableStateOf(existing?.remindDaysBefore ?: 1) }
    var contactId by remember { mutableStateOf(existing?.contact?.id) }
    var contactName by remember { mutableStateOf(existing?.contact?.name) }
    var contacts by remember { mutableStateOf<List<ContactDto>>(emptyList()) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(Unit) { contacts = runCatching { ApiClient.chat.contacts(auth(), registered = 1).data }.getOrNull().orEmpty().filter { it.profile != null } }
    val locale = java.util.Locale.forLanguageTag(AppLocale.current)
    val monthName = java.time.Month.of(month).getDisplayName(java.time.format.TextStyle.FULL_STANDALONE, locale)
    val remindChoices = listOf(0 to R.string.mo_remind_day, 1 to R.string.mo_remind_1, 2 to R.string.mo_remind_2, 7 to R.string.mo_remind_7)
    val remindLabel = stringResource((remindChoices.firstOrNull { it.first == remind } ?: remindChoices[1]).second)

    ChSheet(
        onDismissRequest = onDismiss,
        sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true),
        containerColor = Wa.Surface,
        shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp),
    ) {
        Column(
            Modifier
                .fillMaxWidth()
                .navigationBarsPadding()
                .imePadding()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 20.dp)
                .padding(bottom = 28.dp),
        ) {
            Text(stringResource(if (existing == null) R.string.mo_add_personal else R.string.mo_edit_personal), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(12.dp))
            LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                items(PersonalKinds, key = { it.first }) { (k, emoji) ->
                    val on = kind == k
                    val bg by animateColorAsState(if (on) Wa.Red else Wa.Field, label = "kind")
                    Text(
                        "$emoji  " + stringResource(kindLabel(k)), color = if (on) Color.White else Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold,
                        modifier = Modifier.clip(RoundedCornerShape(50)).background(bg).clickable { kind = k }.padding(horizontal = 12.dp, vertical = 8.dp),
                    )
                }
            }
            Spacer(Modifier.height(12.dp))
            DorrTextField(
                title, { title = it.take(120) }, modifier = Modifier.fillMaxWidth(),
                label = stringResource(R.string.mo_lbl_title), placeholder = stringResource(R.string.mo_title_hint), icon = Icons.Rounded.Edit,
            )
            Spacer(Modifier.height(10.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.Bottom) {
                DorrTextField(
                    day, { day = it.filter(Char::isDigit).take(2) }, modifier = Modifier.weight(1f),
                    label = stringResource(R.string.mo_day), icon = Icons.Rounded.Event,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                )
                DorrTextField(
                    year, { year = it.filter(Char::isDigit).take(4) }, modifier = Modifier.weight(1.4f),
                    label = stringResource(R.string.mo_year), placeholder = stringResource(R.string.mo_year_optional),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                )
            }
            Spacer(Modifier.height(10.dp))
            MoSheetSelect(
                label = stringResource(R.string.mo_month),
                value = monthName,
                icon = Icons.Rounded.CalendarMonth,
            ) { close ->
                (1..12).forEach { m ->
                    val name = java.time.Month.of(m).getDisplayName(java.time.format.TextStyle.FULL_STANDALONE, locale)
                    DropdownMenuItem(
                        text = { Text(name, color = if (month == m) settingsAccent() else settingsInk(), fontWeight = if (month == m) FontWeight.Bold else FontWeight.Medium) },
                        trailingIcon = { if (month == m) Icon(Icons.Rounded.CheckCircle, null, tint = settingsAccent(), modifier = Modifier.size(16.dp)) },
                        onClick = { month = m; close() },
                    )
                }
            }
            if (contacts.isNotEmpty()) {
                Spacer(Modifier.height(10.dp))
                val none = stringResource(R.string.mo_person_none)
                MoSheetSelect(
                    label = stringResource(R.string.mo_person),
                    value = contactName ?: none,
                    icon = Icons.Rounded.Person,
                ) { close ->
                    DropdownMenuItem(
                        text = { Text(none, color = if (contactId == null) settingsAccent() else settingsInk(), fontWeight = if (contactId == null) FontWeight.Bold else FontWeight.Medium) },
                        trailingIcon = { if (contactId == null) Icon(Icons.Rounded.CheckCircle, null, tint = settingsAccent(), modifier = Modifier.size(16.dp)) },
                        onClick = { contactId = null; contactName = null; close() },
                    )
                    contacts.forEach { c ->
                        val on = contactId == c.profile?.id
                        DropdownMenuItem(
                            text = { Text(c.name, color = if (on) settingsAccent() else settingsInk(), fontWeight = if (on) FontWeight.Bold else FontWeight.Medium) },
                            trailingIcon = { if (on) Icon(Icons.Rounded.CheckCircle, null, tint = settingsAccent(), modifier = Modifier.size(16.dp)) },
                            onClick = {
                                contactId = c.profile?.id
                                contactName = c.name
                                if (title.isBlank()) title = c.name
                                close()
                            },
                        )
                    }
                }
            }
            Spacer(Modifier.height(10.dp))
            MoSheetSelect(
                label = stringResource(R.string.mo_remind),
                value = remindLabel,
                icon = Icons.Rounded.Alarm,
            ) { close ->
                remindChoices.forEach { (days, label) ->
                    val on = remind == days
                    DropdownMenuItem(
                        text = { Text(stringResource(label), color = if (on) settingsAccent() else settingsInk(), fontWeight = if (on) FontWeight.Bold else FontWeight.Medium) },
                        trailingIcon = { if (on) Icon(Icons.Rounded.CheckCircle, null, tint = settingsAccent(), modifier = Modifier.size(16.dp)) },
                        onClick = { remind = days; close() },
                    )
                }
            }
            WaError(error)
            Spacer(Modifier.height(16.dp))
            WaButton(
                stringResource(R.string.mo_save), enabled = title.isNotBlank() && (day.toIntOrNull() ?: 0) in 1..31 && !busy, loading = busy, icon = Icons.Rounded.CheckCircle,
                onClick = {
                    busy = true
                    error = null
                    val body = JsonObject().apply {
                        addProperty("kind", kind)
                        addProperty("title", title.trim())
                        addProperty("month", month)
                        addProperty("day", day.toInt())
                        addProperty("remind_days_before", remind)
                        year.toIntOrNull()?.let { addProperty("year", it) } ?: add("year", JsonNull.INSTANCE)
                        contactId?.let { addProperty("contact_id", it) } ?: add("contact_id", JsonNull.INSTANCE)
                    }
                    scope.launch {
                        runCatching { if (existing == null) ApiClient.moments.addPersonal(auth(), body) else ApiClient.moments.updatePersonal(auth(), existing.id, body) }
                            .onSuccess { MomentsStore.refresh(); onDismiss() }
                            .onFailure { error = it.apiFailure().message; busy = false }
                    }
                },
            )
            if (existing != null) {
                Spacer(Modifier.height(6.dp))
                WaButton(stringResource(R.string.mo_delete), style = WaButtonStyle.Quiet, icon = Icons.Rounded.Delete, onClick = {
                    scope.launch { runCatching { ApiClient.moments.deletePersonal(auth(), existing.id) }; MomentsStore.refresh(); onDismiss() }
                })
            }
        }
    }
}

@Composable
internal fun MoSheetSelect(
    label: String,
    value: String,
    icon: ImageVector,
    placeholder: String? = null,
    menu: @Composable ColumnScope.(close: () -> Unit) -> Unit,
) {
    val dark = settingsNight()
    val density = LocalDensity.current
    var open by remember { mutableStateOf(false) }
    var fieldWidth by remember { mutableStateOf(0.dp) }
    Box(Modifier.fillMaxWidth().onGloballyPositioned { fieldWidth = with(density) { it.size.width.toDp() } }) {
        DorrTextField(
            value, {}, modifier = Modifier.fillMaxWidth(),
            label = label, placeholder = placeholder, icon = icon, onClick = { open = true },
            trailing = { Icon(Icons.Rounded.KeyboardArrowDown, null, tint = settingsMut(), modifier = Modifier.size(22.dp)) },
        )
        DropdownMenu(
            expanded = open,
            onDismissRequest = { open = false },
            containerColor = if (dark) AccountDark.card else Color.White,
            shape = RoundedCornerShape(16.dp),
            shadowElevation = 10.dp,
            modifier = Modifier.then(if (fieldWidth > 0.dp) Modifier.width(fieldWidth) else Modifier).heightIn(max = 280.dp),
        ) {
            menu { open = false }
        }
    }
}

private fun kindLabel(kind: String): Int = when (kind) {
    "birthday" -> R.string.mo_kind_birthday
    "anniversary" -> R.string.mo_kind_anniversary
    "graduation" -> R.string.mo_kind_graduation
    "wedding" -> R.string.mo_kind_wedding
    "baby" -> R.string.mo_kind_baby
    else -> R.string.mo_kind_other
}

// =============================================================================== preferences

@Composable
private fun MomentsSettings(onBack: () -> Unit) {
    val scope = rememberCoroutineScope()
    val center = MomentsStore.center
    var catalog by remember { mutableStateOf<List<MomentCatalogItemDto>?>(null) }
    var countries by remember { mutableStateOf<List<CountryDto>>(emptyList()) }
    var error by remember { mutableStateOf<String?>(null) }
    val on = remember { mutableStateListOf<Int>() }
    val dark = settingsNight()
    var onEffects by remember { mutableStateOf(false) }
    var onCountry by remember { mutableStateOf(false) }
    val effects = center?.effects ?: "full"
    val effectChoices = listOf(
        Triple("full", R.string.mo_effects_full, Icons.Rounded.AutoAwesome),
        Triple("light", R.string.mo_effects_light, Icons.Rounded.LightMode),
        Triple("off", R.string.mo_effects_off, Icons.Rounded.VisibilityOff),
    )
    val effectsLabel = stringResource((effectChoices.firstOrNull { it.first == effects } ?: effectChoices.first()).second)
    val homeCode = AuthSession.user?.country?.code
    val countryChoices = remember(countries, homeCode) {
        countries.sortedWith(compareByDescending<CountryDto> { it.code.equals(homeCode, ignoreCase = true) }.thenBy { it.name })
    }
    val countryName = center?.country?.name ?: countryChoices.firstOrNull { it.id == center?.country?.id }?.name

    suspend fun loadCatalog(countryId: Int? = center?.country?.id) {
        catalog = null
        catalog = runCatching { ApiClient.moments.catalog(auth(), countryId).data }.getOrNull().orEmpty()
        on.clear()
        on.addAll(catalog.orEmpty().filter { it.on }.map { it.id })
    }
    LaunchedEffect(Unit) {
        loadCatalog()
        countries = runCatching { ApiClient.countries.list().data }.getOrNull().orEmpty()
    }
    fun save(body: JsonObject, reloadCatalog: Boolean = false, catalogCountry: Int? = null) {
        scope.launch {
            runCatching { ApiClient.moments.preferences(auth(), body).data }
                .onSuccess {
                    it?.let { c -> MomentsStore.center = c }
                    if (reloadCatalog) loadCatalog(catalogCountry ?: it?.country?.id)
                }
                .onFailure { error = it.apiFailure().message }
        }
    }

    BackHandler(enabled = onEffects || onCountry) {
        onEffects = false
        onCountry = false
    }

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize()) {
            SubHeader(
                stringResource(
                    when {
                        onEffects -> R.string.mo_effects
                        onCountry -> R.string.mo_country
                        else -> R.string.mo_settings
                    },
                ),
            ) {
                if (onEffects || onCountry) {
                    onEffects = false
                    onCountry = false
                } else {
                    onBack()
                }
            }
            Column(
                Modifier
                    .weight(1f)
                    .fillMaxWidth()
                    .verticalScroll(rememberScrollState())
                    .padding(horizontal = 14.dp)
                    .padding(top = 6.dp, bottom = 16.dp),
            ) {
                if (onEffects) {
                    MoSettingsCard {
                        effectChoices.forEachIndexed { index, (key, label, icon) ->
                            val selected = effects == key
                            Row(
                                Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).clickable { save(JsonObject().apply { addProperty("effects", key) }) }.padding(horizontal = 4.dp, vertical = 12.dp),
                                verticalAlignment = Alignment.CenterVertically,
                            ) {
                                PinkIcon(icon)
                                Spacer(Modifier.width(10.dp))
                                Text(stringResource(label), fontSize = 13.5.sp, fontWeight = FontWeight.Bold, color = settingsInk(), modifier = Modifier.weight(1f))
                                if (selected) Icon(Icons.Rounded.CheckCircle, null, tint = settingsAccent(), modifier = Modifier.size(20.dp))
                            }
                            if (index != effectChoices.lastIndex) MoSettingsDivider(dark)
                        }
                    }
                    return@Column
                }

                if (onCountry) {
                    MoCountryDropdown(
                        countries = countryChoices,
                        selectedId = center?.country?.id,
                        selectedName = countryName,
                    ) { c ->
                        if (c.id != center?.country?.id) {
                            save(JsonObject().apply { addProperty("country_id", c.id) }, reloadCatalog = true, catalogCountry = c.id)
                        }
                    }
                    MoSettingsLabel(stringResource(R.string.mo_which))
                    Text(stringResource(R.string.mo_which_sub), color = settingsMut(), fontSize = 11.sp, lineHeight = 15.sp, modifier = Modifier.padding(bottom = 8.dp, start = 2.dp))
                    WaError(error)
                    MoOccasionList(catalog, on, dark) { m, want ->
                        if (want) on.add(m.id) else on.remove(m.id)
                        save(JsonObject().apply { add(if (want) "on" else "off", JsonArray().apply { add(m.id) }) })
                    }
                    return@Column
                }

                MoSettingsCard {
                    MoToggleRow(
                        Icons.Rounded.Celebration,
                        stringResource(R.string.mo_enabled),
                        stringResource(R.string.mo_enabled_sub),
                        center?.enabled != false,
                        divider = true,
                    ) { save(JsonObject().apply { addProperty("enabled", it) }) }
                    MoSettingsNavRow(Icons.Rounded.AutoAwesome, stringResource(R.string.mo_effects), effectsLabel, divider = true) { onEffects = true }
                    MoSettingsNavRow(Icons.Rounded.Public, stringResource(R.string.mo_country), countryName, divider = false) { onCountry = true }
                }
            }
        }
    }
}

/** Same row as the toggle above: icon well, title (value), chevron, optional divider. */
@Composable
private fun MoSettingsNavRow(icon: ImageVector, title: String, value: String?, divider: Boolean, onClick: () -> Unit) {
    val dark = settingsNight()
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    Row(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(10.dp))
            .clickable(onClick = onClick)
            .padding(horizontal = 4.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        PinkIcon(icon)
        Spacer(Modifier.width(10.dp))
        Row(Modifier.weight(1f), verticalAlignment = Alignment.CenterVertically) {
            Text(title, fontSize = 13.5.sp, fontWeight = FontWeight.Bold, color = settingsInk(), maxLines = 1, overflow = TextOverflow.Ellipsis)
            if (!value.isNullOrBlank()) {
                Spacer(Modifier.width(6.dp))
                Text(
                    "($value)",
                    fontSize = 13.5.sp,
                    fontWeight = FontWeight.SemiBold,
                    color = settingsMut(),
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.weight(1f),
                )
            }
        }
        Spacer(Modifier.width(8.dp))
        Icon(
            Icons.Rounded.ChevronRight,
            null,
            tint = if (dark) AccountDark.chevron else settingsAccent().copy(alpha = 0.55f),
            modifier = Modifier.size(16.dp).graphicsLayer { if (rtl) scaleX = -1f },
        )
    }
    if (divider) MoSettingsDivider(dark)
}

@Composable
private fun MoCountryDropdown(
    countries: List<CountryDto>,
    selectedId: Int?,
    selectedName: String?,
    onPick: (CountryDto) -> Unit,
) {
    val dark = settingsNight()
    var open by remember { mutableStateOf(false) }
    var query by remember { mutableStateOf("") }
    var menuWidth by remember { mutableStateOf(0.dp) }
    val density = LocalDensity.current
    val shown = countries.filter { query.isBlank() || it.name.contains(query, ignoreCase = true) || it.code.contains(query, ignoreCase = true) }
    Box(
        Modifier
            .fillMaxWidth()
            .onGloballyPositioned { menuWidth = with(density) { it.size.width.toDp() } },
    ) {
        Row(
            Modifier
                .fillMaxWidth()
                .settingsSurface(RoundedCornerShape(18.dp), 8.dp)
                .clip(RoundedCornerShape(18.dp))
                .clickable(enabled = countries.isNotEmpty()) {
                    query = ""
                    open = true
                }
                .padding(horizontal = 14.dp, vertical = 14.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            PinkIcon(Icons.Rounded.Public)
            Spacer(Modifier.width(10.dp))
            Text(
                selectedName ?: stringResource(R.string.mo_country),
                fontSize = 13.5.sp,
                fontWeight = FontWeight.Bold,
                color = settingsInk(),
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
                modifier = Modifier.weight(1f),
            )
            Icon(Icons.Rounded.KeyboardArrowDown, null, tint = settingsMut(), modifier = Modifier.size(22.dp))
        }
        DropdownMenu(
            expanded = open && countries.isNotEmpty(),
            onDismissRequest = { open = false },
            containerColor = if (dark) AccountDark.card else Color.White,
            shape = RoundedCornerShape(16.dp),
            shadowElevation = 10.dp,
            modifier = Modifier.width(menuWidth).heightIn(max = 340.dp).widthIn(min = 220.dp),
        ) {
            Row(
                Modifier
                    .fillMaxWidth()
                    .padding(8.dp)
                    .background(if (dark) AccountDark.well else Color(0xFFF3F4F6), RoundedCornerShape(10.dp))
                    .padding(horizontal = 10.dp, vertical = 8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.Search, null, tint = settingsMut(), modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(8.dp))
                BasicTextField(
                    value = query,
                    onValueChange = { query = it },
                    singleLine = true,
                    textStyle = TextStyle(fontSize = 14.sp, color = settingsInk()),
                    decorationBox = { inner ->
                        if (query.isEmpty()) {
                            Text(stringResource(R.string.mo_search_country), color = settingsMut(), fontSize = 14.sp)
                        }
                        inner()
                    },
                    modifier = Modifier.fillMaxWidth(),
                )
            }
            shown.forEach { c ->
                val selected = c.id == selectedId
                DropdownMenuItem(
                    text = {
                        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                            Text(
                                c.name.ifBlank { c.code },
                                modifier = Modifier.weight(1f),
                                maxLines = 1,
                                overflow = TextOverflow.Ellipsis,
                                color = if (selected) settingsAccent() else settingsInk(),
                                fontWeight = if (selected) FontWeight.Bold else FontWeight.Normal,
                                fontSize = 14.sp,
                            )
                            if (selected) Icon(Icons.Rounded.CheckCircle, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
                        }
                    },
                    onClick = {
                        open = false
                        onPick(c)
                    },
                )
            }
        }
    }
}

@Composable
private fun MoOccasionList(
    list: List<MomentCatalogItemDto>?,
    on: List<Int>,
    dark: Boolean,
    onToggle: (MomentCatalogItemDto, Boolean) -> Unit,
) {
    if (list == null) {
        repeat(4) { WaSkeleton(Modifier.fillMaxWidth().height(56.dp).padding(bottom = 8.dp), RoundedCornerShape(18.dp)) }
        return
    }
    if (list.isEmpty()) return
    MoSettingsCard {
        list.forEachIndexed { index, m ->
            val isOn = m.id in on
            Row(Modifier.fillMaxWidth().padding(horizontal = 4.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                Box(
                    Modifier.size(34.dp).clip(RoundedCornerShape(10.dp)).background(if (dark) AccountDark.well else settingsAccent().copy(alpha = 0.14f)),
                    contentAlignment = Alignment.Center,
                ) { Text(m.emoji ?: "✨", fontSize = 16.sp) }
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(m.name.orEmpty(), fontSize = 13.5.sp, fontWeight = FontWeight.Bold, color = settingsInk(), maxLines = 1, overflow = TextOverflow.Ellipsis)
                    m.next?.let { Text(dateLabel(it), fontSize = 11.sp, color = settingsMut(), modifier = Modifier.padding(top = 2.dp)) }
                }
                RedToggle(isOn, dark) { onToggle(m, !isOn) }
            }
            if (index != list.lastIndex) MoSettingsDivider(dark)
        }
    }
}

@Composable
private fun MoSettingsLabel(text: String) {
    Text(text, color = settingsInk(), fontSize = 13.5.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 16.dp, bottom = 8.dp, start = 2.dp))
}

@Composable
private fun MoSettingsCard(content: @Composable ColumnScope.() -> Unit) {
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp), 8.dp).padding(horizontal = 8.dp, vertical = 4.dp), content = content)
}

@Composable
private fun MoSettingsDivider(dark: Boolean) {
    Box(Modifier.fillMaxWidth().height(1.dp).background(if (dark) AccountDark.line else Color(0xFFF6E4E8)))
}

@Composable
private fun MoToggleRow(icon: ImageVector, title: String, help: String, on: Boolean, divider: Boolean, onChange: (Boolean) -> Unit) {
    val dark = settingsNight()
    Row(Modifier.fillMaxWidth().padding(horizontal = 4.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
        PinkIcon(icon)
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(title, fontSize = 13.5.sp, fontWeight = FontWeight.Bold, color = settingsInk())
            Text(help, fontSize = 11.sp, color = settingsMut(), lineHeight = 15.sp, modifier = Modifier.padding(top = 2.dp))
        }
        RedToggle(on, dark) { onChange(!on) }
    }
    if (divider) MoSettingsDivider(dark)
}
