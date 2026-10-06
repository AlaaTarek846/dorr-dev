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
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.systemBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Cake
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material.icons.rounded.Public
import androidx.compose.material.icons.rounded.Tune
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
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
import com.dorr.app.network.AuthSession
import com.dorr.app.network.ContactDto
import com.dorr.app.network.CountryDto
import com.dorr.app.network.MomentCatalogItemDto
import com.dorr.app.network.MomentDto
import com.dorr.app.network.MomentsCenterDto
import com.dorr.app.network.PersonalMomentDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaCircleButton
import com.dorr.app.ui.screens.wallet.WaEmpty
import com.dorr.app.ui.screens.wallet.WaError
import com.dorr.app.ui.screens.wallet.WaPage
import com.dorr.app.ui.screens.wallet.WaSectionTitle
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

private fun dateLabel(iso: String?): String = runCatching {
    LocalDate.parse(iso).format(DateTimeFormatter.ofLocalizedDate(FormatStyle.MEDIUM))
}.getOrDefault(iso.orEmpty())

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
        actions = { WaCircleButton(Icons.Rounded.Tune, onSettings, contentDescription = stringResource(R.string.mo_settings)) },
        cta = { WaButton(stringResource(R.string.mo_add_personal), { adding = true }, icon = Icons.Rounded.Cake) },
    ) {
        when {
            center == null -> repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(90.dp).padding(bottom = 10.dp), RoundedCornerShape(22.dp)) }
            !center.enabled -> WaCard(Modifier.fillMaxWidth()) {
                WaEmpty(Icons.Rounded.Event, Tone.Gray, stringResource(R.string.mo_off_title), stringResource(R.string.mo_off_text), action = {
                    WaButton(stringResource(R.string.mo_settings), onSettings, style = WaButtonStyle.Ghost, icon = Icons.Rounded.Tune)
                })
            }
            else -> {
                // On now: big, with its animation — tap to send a card for it.
                center.active.forEachIndexed { i, m -> ActiveMoment(m, center.effects, Modifier.padding(bottom = 12.dp).waRise(i).clickable { card = CardPreset(momentId = m.id) to null }) }

                Row(Modifier.fillMaxWidth().padding(bottom = 6.dp).waRise(1), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    QuickTile("💌", stringResource(R.string.mo_card_title), Modifier.weight(1f)) { card = CardPreset() to null }
                    QuickTile("✍️", stringResource(R.string.mo_collab_title), Modifier.weight(1f), onCollabs)
                    QuickTile("📦", stringResource(R.string.mo_capsules), Modifier.weight(1f), onCapsules)
                }

                if (center.personal.isNotEmpty()) {
                    WaSectionTitle(stringResource(R.string.mo_mine), Modifier.waRise(2))
                    center.personal.forEachIndexed { i, p ->
                        PersonalRow(p, Modifier.padding(bottom = 8.dp).waRise(3 + i), onCard = { card = CardPreset(personalKind = p.kind) to p.contact }) { editing = p }
                    }
                }

                WaSectionTitle(stringResource(R.string.mo_upcoming, center.country?.name ?: ""), Modifier.waRise(4))
                val coming = center.upcoming.filterNot { it.isVisible }
                if (coming.isEmpty()) {
                    Text(stringResource(R.string.mo_none_soon), color = Wa.Mut, fontSize = 13.sp, modifier = Modifier.padding(vertical = 10.dp))
                } else {
                    coming.forEachIndexed { i, m -> UpcomingRow(m, Modifier.padding(bottom = 8.dp).waRise(5 + i)) }
                }
            }
        }
    }

    if (adding) PersonalSheet(null) { adding = false }
    editing?.let { p -> PersonalSheet(p) { editing = null } }
    card?.let { (preset, to) -> MomentCardSheet(conversationId = null, peerName = to?.name, canGift = true, preset = preset, toUser = to, onDismiss = { card = null }) }
}

@Composable
private fun QuickTile(emoji: String, label: String, modifier: Modifier = Modifier, onClick: () -> Unit) {
    Column(
        modifier.clip(RoundedCornerShape(20.dp)).background(Wa.Surface).clickable(onClick = onClick).padding(vertical = 14.dp, horizontal = 6.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(emoji, fontSize = 26.sp)
        Spacer(Modifier.height(4.dp))
        Text(label, color = Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

@Composable
private fun ActiveMoment(m: MomentDto, effects: String, modifier: Modifier = Modifier) {
    Box(modifier.fillMaxWidth().height(190.dp).clip(RoundedCornerShape(26.dp)).background(momentBrush(m.primaryColor, m.secondaryColor))) {
        m.cardImage?.let { AsyncImage(it, null, contentScale = ContentScale.Crop, modifier = Modifier.matchParentSize()) }
        MomentEffect(m.animation, m.emoji, m.primaryColor, m.secondaryColor, effects, Modifier.matchParentSize())
        Column(Modifier.fillMaxSize().padding(20.dp), verticalArrangement = Arrangement.Center, horizontalAlignment = Alignment.CenterHorizontally) {
            Text(m.emoji ?: "✨", fontSize = 52.sp)
            Text(m.name.orEmpty(), color = Color.White, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center)
            Text(
                if (m.isToday) m.greeting.orEmpty() else whenLabel(m.daysLeft, false) + " · " + dateLabel(m.start),
                color = Color.White.copy(alpha = 0.9f), fontSize = 14.sp, textAlign = TextAlign.Center, maxLines = 2,
            )
        }
    }
}

@Composable
private fun UpcomingRow(m: MomentDto, modifier: Modifier = Modifier) {
    Row(modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(Wa.Surface).padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(46.dp).clip(RoundedCornerShape(14.dp)).background(momentBrush(m.primaryColor, m.secondaryColor)), contentAlignment = Alignment.Center) {
            Text(m.emoji ?: "✨", fontSize = 22.sp)
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(m.name.orEmpty(), color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(dateLabel(m.start), color = Wa.Mut, fontSize = 12.5.sp)
        }
        Text(
            whenLabel(m.daysLeft, m.isToday), color = Wa.Red, fontSize = 12.sp, fontWeight = FontWeight.Bold,
            modifier = Modifier.clip(RoundedCornerShape(10.dp)).background(Wa.Red.copy(alpha = 0.1f)).padding(horizontal = 8.dp, vertical = 4.dp),
        )
    }
}

@Composable
private fun PersonalRow(p: PersonalMomentDto, modifier: Modifier = Modifier, onCard: () -> Unit, onClick: () -> Unit) {
    Row(modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(Wa.Surface).clickable(onClick = onClick).padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(46.dp).clip(CircleShape).background(momentBrush(p.look?.primaryColor, p.look?.secondaryColor)), contentAlignment = Alignment.Center) {
            Text(p.look?.emoji ?: "✨", fontSize = 22.sp)
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(p.title, color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(
                listOfNotNull(dateLabel(p.next), p.turns?.let { stringResource(R.string.mo_turns, it) }, p.contact?.name).joinToString("  ·  "),
                color = Wa.Mut, fontSize = 12.5.sp, maxLines = 1,
            )
        }
        if (p.contact != null) {
            Box(Modifier.padding(end = 8.dp).size(34.dp).clip(CircleShape).background(Wa.Red.copy(alpha = 0.1f)).clickable(onClick = onCard), contentAlignment = Alignment.Center) {
                Text("💌", fontSize = 16.sp)
            }
        }
        Text(
            whenLabel(p.daysLeft, false), color = if (p.daysLeft <= 1) Color.White else Wa.Red, fontSize = 12.sp, fontWeight = FontWeight.Bold,
            modifier = Modifier.clip(RoundedCornerShape(10.dp)).background(if (p.daysLeft <= 1) Wa.Red else Wa.Red.copy(alpha = 0.1f)).padding(horizontal = 8.dp, vertical = 4.dp),
        )
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

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).imePadding().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
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
            DorrTextField(title, { title = it.take(120) }, placeholder = stringResource(R.string.mo_title_hint), modifier = Modifier.fillMaxWidth())
            Spacer(Modifier.height(10.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                DorrTextField(day, { day = it.filter(Char::isDigit).take(2) }, placeholder = stringResource(R.string.mo_day), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.weight(1f))
                DorrTextField(year, { year = it.filter(Char::isDigit).take(4) }, placeholder = stringResource(R.string.mo_year_optional), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.weight(1.4f))
            }
            Spacer(Modifier.height(8.dp))
            LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                items((1..12).toList()) { m ->
                    val on = month == m
                    Text(
                        java.time.Month.of(m).getDisplayName(java.time.format.TextStyle.SHORT, java.util.Locale.getDefault()),
                        color = if (on) Color.White else Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold,
                        modifier = Modifier.clip(RoundedCornerShape(12.dp)).background(if (on) Wa.Red else Wa.Field).clickable { month = m }.padding(horizontal = 12.dp, vertical = 8.dp),
                    )
                }
            }
            if (contacts.isNotEmpty()) {
                Spacer(Modifier.height(12.dp))
                Text(stringResource(R.string.mo_person), color = Wa.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
                Spacer(Modifier.height(6.dp))
                LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    items(contacts, key = { it.id }) { c ->
                        val on = contactId == c.profile?.id
                        Text(
                            c.name, color = if (on) Color.White else Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold,
                            modifier = Modifier.clip(RoundedCornerShape(50)).background(if (on) Wa.Red else Wa.Field)
                                .clickable { if (on) { contactId = null; contactName = null } else { contactId = c.profile?.id; contactName = c.name; if (title.isBlank()) title = c.name } }
                                .padding(horizontal = 12.dp, vertical = 8.dp),
                        )
                    }
                }
            }
            // When to remind me (9 in my morning): on the day only, or also some days before.
            Spacer(Modifier.height(12.dp))
            Text(stringResource(R.string.mo_remind), color = Wa.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(6.dp))
            LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                items(listOf(0 to R.string.mo_remind_day, 1 to R.string.mo_remind_1, 2 to R.string.mo_remind_2, 7 to R.string.mo_remind_7), key = { it.first }) { (days, label) ->
                    val on = remind == days
                    Text(
                        stringResource(label), color = if (on) Color.White else Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold,
                        modifier = Modifier.clip(RoundedCornerShape(50)).background(if (on) Wa.Red else Wa.Field).clickable { remind = days }.padding(horizontal = 12.dp, vertical = 8.dp),
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

    suspend fun loadCatalog() {
        catalog = runCatching { ApiClient.moments.catalog(auth()).data }.getOrNull().orEmpty()
        on.clear()
        on.addAll(catalog.orEmpty().filter { it.on }.map { it.id })
    }
    LaunchedEffect(Unit) {
        loadCatalog()
        countries = runCatching { ApiClient.countries.list().data }.getOrNull().orEmpty()
    }
    fun save(body: JsonObject, reloadCatalog: Boolean = false) {
        scope.launch {
            runCatching { ApiClient.moments.preferences(auth(), body).data }
                .onSuccess { it?.let { c -> MomentsStore.center = c }; if (reloadCatalog) loadCatalog() }
                .onFailure { error = it.apiFailure().message }
        }
    }

    WaPage(title = stringResource(R.string.mo_settings), onBack = onBack) {
        WaCard(Modifier.fillMaxWidth().waRise(0), padding = 14.dp) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.mo_enabled), color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.Bold)
                    Text(stringResource(R.string.mo_enabled_sub), color = Wa.Mut, fontSize = 12.sp)
                }
                Switch(
                    checked = center?.enabled != false, onCheckedChange = { save(JsonObject().apply { addProperty("enabled", it) }) },
                    colors = SwitchDefaults.colors(checkedTrackColor = Wa.Red),
                )
            }
        }

        WaSectionTitle(stringResource(R.string.mo_effects), Modifier.waRise(1))
        Row(Modifier.waRise(1), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf("full" to R.string.mo_effects_full, "light" to R.string.mo_effects_light, "off" to R.string.mo_effects_off).forEach { (key, label) ->
                val selected = (center?.effects ?: "full") == key
                Text(
                    stringResource(label), color = if (selected) Color.White else Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center,
                    modifier = Modifier.weight(1f).clip(RoundedCornerShape(14.dp)).background(if (selected) Wa.Red else Wa.Surface)
                        .clickable { save(JsonObject().apply { addProperty("effects", key) }) }.padding(vertical = 11.dp),
                )
            }
        }

        WaSectionTitle(stringResource(R.string.mo_country), Modifier.waRise(2))
        Text(stringResource(R.string.mo_country_sub), color = Wa.Mut, fontSize = 12.sp, modifier = Modifier.waRise(2))
        Spacer(Modifier.height(6.dp))
        LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.waRise(2)) {
            items(countries, key = { it.id }) { c ->
                val selected = center?.country?.id == c.id
                Row(
                    Modifier.clip(RoundedCornerShape(50)).background(if (selected) Wa.Red else Wa.Surface).border(1.dp, if (selected) Color.Transparent else Wa.Line, RoundedCornerShape(50))
                        .clickable { save(JsonObject().apply { addProperty("country_id", c.id) }, reloadCatalog = true) }.padding(horizontal = 12.dp, vertical = 8.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Rounded.Public, null, tint = if (selected) Color.White else Wa.Mut, modifier = Modifier.size(15.dp))
                    Spacer(Modifier.width(5.dp))
                    Text(c.name, color = if (selected) Color.White else Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold)
                }
            }
        }

        WaSectionTitle(stringResource(R.string.mo_which), Modifier.waRise(3))
        Text(stringResource(R.string.mo_which_sub), color = Wa.Mut, fontSize = 12.sp)
        WaError(error)
        Spacer(Modifier.height(6.dp))
        val list = catalog
        if (list == null) {
            repeat(4) { WaSkeleton(Modifier.fillMaxWidth().height(56.dp).padding(bottom = 8.dp), RoundedCornerShape(16.dp)) }
        } else {
            list.forEach { m ->
                val isOn = m.id in on
                Row(Modifier.fillMaxWidth().padding(bottom = 6.dp).clip(RoundedCornerShape(16.dp)).background(Wa.Surface).padding(horizontal = 12.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text(m.emoji ?: "✨", fontSize = 20.sp)
                    Spacer(Modifier.width(10.dp))
                    Column(Modifier.weight(1f)) {
                        Text(m.name.orEmpty(), color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        m.next?.let { Text(dateLabel(it), color = Wa.Mut, fontSize = 11.5.sp) }
                    }
                    Switch(
                        checked = isOn,
                        onCheckedChange = { want ->
                            if (want) on.add(m.id) else on.remove(m.id)
                            save(JsonObject().apply { add(if (want) "on" else "off", JsonArray().apply { add(m.id) }) })
                        },
                        colors = SwitchDefaults.colors(checkedTrackColor = Wa.Red),
                    )
                }
            }
        }
    }
}
