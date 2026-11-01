package com.dorr.app.ui.screens.freelance

import androidx.compose.material.icons.rounded.*
import android.content.Context
import android.content.Intent
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.Favorite
import androidx.compose.material.icons.rounded.FavoriteBorder
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.DialogProperties
import androidx.core.content.FileProvider
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.FreelanceAttachmentDto
import com.dorr.app.network.FreelanceCompareDto
import com.dorr.app.network.FreelanceDecisionRequest
import com.dorr.app.network.FreelanceOfferDto
import com.dorr.app.network.FreelanceOfferFreelancerDto
import com.dorr.app.network.FreelancerProfileDto
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.locale.LocaleAwareDialog
import com.dorr.app.ui.screens.profile.PinkBackdrop
import com.dorr.app.ui.screens.profile.SettingsPrimaryButton
import com.dorr.app.ui.screens.profile.SubHeader
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsSurface
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File

/*
 * The client compares the offers on a project and chooses one (FL-305 / FL-309): the list (sorted, the shortlisted only), two or
 * three side by side, one offer with its files and what can be done with it (shortlist, turn down with an optional reason, choose
 * after seeing what it costs), and the profile of the freelancer who sent it. Each page reads once when it opens and when the
 * connection comes back — nothing on a timer.
 */

private val SORTS = listOf("newest", "cheapest", "fastest", "completeness")

private const val COMPARE_MAX = 3

private val Green = Color(0xFF16A34A)
private val Red = Color(0xFFDC2626)

@Composable
private fun sortLabel(sort: String): String = stringResource(
    when (sort) {
        "cheapest" -> R.string.fl_sort_cheapest
        "fastest" -> R.string.fl_sort_fastest
        "completeness" -> R.string.fl_sort_completeness
        else -> R.string.fl_sort_newest
    },
)

@Composable
internal fun offerStatusLabel(status: String): String = stringResource(
    when (status) {
        "viewed" -> R.string.fl_offer_st_viewed
        "shortlisted" -> R.string.fl_offer_st_shortlisted
        "rejected" -> R.string.fl_offer_st_rejected
        "accepted" -> R.string.fl_offer_st_accepted
        else -> R.string.fl_offer_st_new
    },
)

private fun offerStatusColor(status: String): Color = when (status) {
    "accepted" -> Green
    "shortlisted" -> Color(0xFF2563EB)
    "rejected" -> Red
    "viewed" -> Color(0xFF6B7280)
    else -> Color(0xFFD97706)
}

@Composable
private fun OfferStatusPill(status: String) {
    val color = offerStatusColor(status)
    Text(
        offerStatusLabel(status), color = color, fontSize = 11.sp, fontWeight = FontWeight.Bold,
        modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(color.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 3.dp),
    )
}

@Composable
private fun Avatar(f: FreelanceOfferFreelancerDto?, size: Int = 42) {
    Box(Modifier.size(size.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
        if (!f?.avatarUrl.isNullOrBlank()) AsyncImage(model = f?.avatarUrl, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
        else Text(f?.name?.take(1).orEmpty(), color = settingsAccent(), fontSize = (size / 2.4).sp, fontWeight = FontWeight.ExtraBold)
    }
}

/** «New on DORR» until ratings exist (FL-701). */
@Composable
private fun ratingText(f: FreelanceOfferFreelancerDto?): String =
    f?.rating?.let { stringResource(R.string.fl_rating, it, f.reviewsCount ?: 0) }
        ?: f?.reviewsCount?.takeIf { it > 0 }?.let { stringResource(R.string.fl_rating_few, it) }
        ?: stringResource(R.string.fl_rating_new)

@Composable
private fun skillsMatch(f: FreelanceOfferFreelancerDto?): String? =
    f?.takeIf { it.projectSkillsCount > 0 }?.let { stringResource(R.string.fl_skills_match, it.matchingSkills.size, it.projectSkillsCount) }

// ---------------------------------------------------------------- the list

private fun sortIcon(sort: String): androidx.compose.ui.graphics.vector.ImageVector = when (sort) {
    "cheapest" -> Icons.Rounded.Payments
    "fastest" -> Icons.Rounded.Bolt
    "completeness" -> Icons.Rounded.Verified
    else -> Icons.Rounded.Schedule
}

@Composable
internal fun OffersPage(projectUuid: String, onBack: () -> Unit, onOpen: (String) -> Unit, onCompare: (List<String>) -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val reconnect = collectReconnectTick()
    var sort by rememberSaveable { mutableStateOf("newest") }
    var onlyShortlisted by rememberSaveable { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val listState = rememberLazyListState()
    // A new sort or filter is a new list (it starts from the first page); the next fifteen come while I scroll.
    val paged = remember(projectUuid, sort, onlyShortlisted) {
        PagedRows<FreelanceOfferDto>({ it.uuid }, { page -> ApiClient.freelance.offers(auth(), projectUuid, sort, if (onlyShortlisted) "shortlisted" else null, page = page) })
    }
    val offers = if (paged.loaded && (paged.error == null || paged.rows.isNotEmpty())) paged.rows.toList() else null
    val error = paged.error
    val picked = remember { mutableStateListOf<String>() }

    LaunchedEffect(paged, reconnect) { paged.reload(generic) }
    listState.NearEnd(paged.rows.size) { scope.launch { paged.loadMore(generic) } }

    Column(Modifier.fillMaxSize()) {
        SubHeader(stringResource(R.string.fl_offers), onBack)
        Row(Modifier.horizontalScroll(rememberScrollState()).padding(horizontal = 14.dp, vertical = 4.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Chip(stringResource(R.string.fl_filter_all), selected = !onlyShortlisted, icon = Icons.Rounded.Apps) { onlyShortlisted = false }
            Chip(stringResource(R.string.fl_offer_st_shortlisted), selected = onlyShortlisted, icon = Icons.Rounded.Star) { onlyShortlisted = true }
            SORTS.forEach { s -> Chip(sortLabel(s), selected = sort == s, icon = sortIcon(s)) { sort = s } }
        }
        LazyColumn(Modifier.weight(1f).fillMaxWidth(), state = listState, contentPadding = PaddingValues(horizontal = 16.dp, vertical = 10.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            val list = offers
            when {
                list == null && error == null -> items(4) { com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().height(150.dp), RoundedCornerShape(18.dp)) }
                list != null && list.isEmpty() -> item {
                    com.dorr.app.ui.screens.wallet.WaEmpty(
                        Icons.Rounded.RequestQuote, com.dorr.app.ui.screens.wallet.Tone.Blue,
                        stringResource(if (onlyShortlisted) R.string.fl_no_shortlisted else R.string.fl_no_offers), "",
                    )
                }
                list != null -> {
                    if (list.size >= 2) item { FlNotice(stringResource(R.string.fl_compare_hint, COMPARE_MAX), Icons.Rounded.CompareArrows, settingsAccent()) }
                    items(list, key = { it.uuid }) { o ->
                        val isPicked = o.uuid in picked
                        OfferCard(
                            o, picked = isPicked, canPick = list.size >= 2 && (isPicked || picked.size < COMPARE_MAX),
                            onPick = { if (isPicked) picked.remove(o.uuid) else picked.add(o.uuid) }, onClick = { onOpen(o.uuid) },
                        )
                    }
                }
            }
            item { PagedSpinner(paged) }
            error?.let { item { Text(it, color = Red, fontSize = 13.sp, modifier = Modifier.clickable { paged.clearError(); scope.launch { paged.loadMore(generic) } }.padding(8.dp)) } }
        }
        if (picked.size >= 2) SettingsPrimaryButton(text = stringResource(R.string.fl_compare_n, picked.size), modifier = Modifier.padding(16.dp), onClick = { onCompare(picked.toList()) })
    }
}

@Composable
private fun OfferCard(o: FreelanceOfferDto, picked: Boolean, canPick: Boolean, onPick: () -> Unit, onClick: () -> Unit) {
    val f = o.freelancer
    Column(
        Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp))
            .then(if (picked) Modifier.border(2.dp, settingsAccent(), RoundedCornerShape(20.dp)) else Modifier)
            .clickable(onClick = onClick).padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Avatar(f, 46)
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(f?.name ?: "—", color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false))
                    if (f?.verified == true) Icon(Icons.Rounded.Verified, stringResource(R.string.fl_verified), tint = Green, modifier = Modifier.padding(start = 4.dp).size(16.dp))
                    if (f?.isFavorite == true) Icon(Icons.Rounded.Favorite, stringResource(R.string.fl_favorite), tint = Red, modifier = Modifier.padding(start = 4.dp).size(15.dp))
                }
                f?.headline?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis) }
            }
            OfferStatusPill(o.status)
        }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            FlStat(Icons.Rounded.Payments, stringResource(R.string.fl_price), "‎${money(o.priceMinor)} ${o.currency}", Modifier.weight(1.5f))
            FlStat(Icons.Rounded.Schedule, stringResource(R.string.fl_duration), stringResource(R.string.fl_days, o.durationDays), Modifier.weight(1f))
        }
        // FL-210: dearer than the most I said I'd pay.
        if (o.overCap) FlNotice(stringResource(R.string.fl_over_cap), Icons.Rounded.Warning, Red)
        Text(
            listOfNotNull(
                f?.let { stringResource(R.string.fl_completeness, it.completeness) },
                f?.let { stringResource(R.string.fl_portfolio_n, it.portfolioCount) },
                skillsMatch(f),
                ratingText(f),
            ).joinToString("  ·  "),
            color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp,
        )
        Text(o.details, color = settingsInk(), fontSize = 13.sp, lineHeight = 20.sp, maxLines = 3, overflow = TextOverflow.Ellipsis)
        Row(verticalAlignment = Alignment.CenterVertically) {
            if (o.edited) Text(stringResource(R.string.fl_offer_edited), color = settingsMut(), fontSize = 11.sp, modifier = Modifier.padding(end = 10.dp))
            if (o.attachments.isNotEmpty()) {
                Icon(Icons.Rounded.AttachFile, null, tint = settingsMut(), modifier = Modifier.size(14.dp))
                Text(stringResource(R.string.fl_offer_files, o.attachments.size), color = settingsMut(), fontSize = 11.sp)
            }
            Spacer(Modifier.weight(1f))
            if (canPick) Row(
                Modifier.clip(RoundedCornerShape(999.dp)).background(settingsAccent().copy(alpha = if (picked) 0.16f else 0.08f)).clickable(onClick = onPick).padding(horizontal = 10.dp, vertical = 5.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(if (picked) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
                Text(stringResource(R.string.fl_compare), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(start = 4.dp))
            }
        }
    }
}

// ---------------------------------------------------------------- side by side

@Composable
internal fun ComparePage(projectUuid: String, ids: List<String>, onBack: () -> Unit, onOpen: (String) -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val reconnect = collectReconnectTick()
    var data by remember { mutableStateOf<FreelanceCompareDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(projectUuid, ids, reconnect) {
        try {
            data = ApiClient.freelance.compareOffers(auth(), projectUuid, ids.joinToString(",")).data
            error = null
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message(generic)
        }
    }

    Column(Modifier.fillMaxSize()) {
        SubHeader(stringResource(R.string.fl_compare_title), onBack)
        val d = data
        if (d == null) {
            FlLoading(error)
            return@Column
        }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = 12.dp, vertical = 8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            FlIntro(Icons.Rounded.CompareArrows, stringResource(R.string.fl_compare_title), stringResource(R.string.fl_compare_best_hint))
            // The heads: who, tap to open the offer.
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                d.offers.forEach { o ->
                    Column(
                        Modifier.weight(1f).settingsSurface(RoundedCornerShape(16.dp)).clickable { onOpen(o.uuid) }.padding(10.dp),
                        horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(4.dp),
                    ) {
                        Avatar(o.freelancer, 48)
                        Text(o.freelancer?.name ?: "—", color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                        OfferStatusPill(o.status)
                    }
                }
            }
            CompareRow(stringResource(R.string.fl_price), d) { o -> Cell("‎${money(o.priceMinor)} ${o.currency}", best = d.best.price == o.uuid) }
            CompareRow(stringResource(R.string.fl_you_pay), d) { o -> Cell(o.clientCost?.let { "‎${money(it.totalMinor)} ${it.currency}" } ?: "—") }
            CompareRow(stringResource(R.string.fl_duration), d) { o -> Cell(stringResource(R.string.fl_days, o.durationDays), best = d.best.duration == o.uuid) }
            CompareRow(stringResource(R.string.fl_profile_complete), d) { o -> Cell("${o.freelancer?.completeness ?: 0}%", best = d.best.completeness == o.uuid) }
            CompareRow(stringResource(R.string.fl_skills_fit), d) { o -> Cell(skillsMatch(o.freelancer) ?: "—", best = d.best.skills == o.uuid && (o.freelancer?.matchingSkills?.isNotEmpty() == true)) }
            CompareRow(stringResource(R.string.fl_verified), d) { o -> Cell(stringResource(if (o.freelancer?.verified == true) R.string.fl_yes else R.string.fl_no)) }
            CompareRow(stringResource(R.string.fl_experience_years), d) { o -> Cell(o.freelancer?.yearsOfExperience?.let { stringResource(R.string.fl_years, it) } ?: "—") }
            CompareRow(stringResource(R.string.fl_portfolio), d) { o -> Cell("${o.freelancer?.portfolioCount ?: 0}") }
            // FL-703: what they finished, and on time.
            CompareRow(stringResource(R.string.fl_st_completed), d) { o -> Cell("${o.freelancer?.completedProjects ?: 0}" + (o.freelancer?.similarProjects?.takeIf { it > 0 }?.let { " · " + stringResource(R.string.fl_st_similar, it) } ?: "")) }
            CompareRow(stringResource(R.string.fl_st_on_time), d) { o -> Cell(o.freelancer?.onTimeRate?.let { "$it%" } ?: "—") }
            CompareRow(stringResource(R.string.fl_rating_label), d) { o -> Cell(ratingText(o.freelancer)) }
            CompareRow(stringResource(R.string.fl_offer_details), d) { o -> Cell(o.details, small = true) }
            // FL-307: the same question, each one's answer.
            d.offers.firstOrNull()?.answers?.forEachIndexed { i, a ->
                CompareRow(a.question, d) { o -> Cell(o.answers.getOrNull(i)?.answer ?: "—", small = true) }
            }
            Spacer(Modifier.height(16.dp))
        }
    }
}

@Composable
private fun CompareRow(label: String, d: FreelanceCompareDto, cell: @Composable (FreelanceOfferDto) -> Unit) {
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(14.dp)).padding(10.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(label, color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            d.offers.forEach { o -> Box(Modifier.weight(1f)) { cell(o) } }
        }
    }
}

@Composable
private fun Cell(text: String, best: Boolean = false, small: Boolean = false) {
    Text(
        text, color = if (best) Green else settingsInk(), fontSize = if (small) 12.sp else 14.sp,
        fontWeight = if (best) FontWeight.ExtraBold else FontWeight.SemiBold, maxLines = if (small) 6 else 2, overflow = TextOverflow.Ellipsis,
    )
}

// ---------------------------------------------------------------- one offer

@Composable
internal fun OfferPage(projectUuid: String, offerUuid: String, onBack: () -> Unit, onProfile: () -> Unit, onChat: (String) -> Unit, onChosen: () -> Unit) {
    val context = LocalContext.current
    val generic = stringResource(R.string.fl_error_generic)
    val fileError = stringResource(R.string.fl_file_open_failed)
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    var offer by remember { mutableStateOf<FreelanceOfferDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    // "reject" or "choose"
    var sheet by remember { mutableStateOf<String?>(null) }
    var opening by remember { mutableStateOf<Int?>(null) }

    LaunchedEffect(projectUuid, offerUuid, reconnect) {
        try {
            offer = ApiClient.freelance.offer(auth(), projectUuid, offerUuid).data
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message(generic)
        }
    }

    fun decide(action: String, reason: String? = null) {
        busy = true
        error = null
        notice = null
        scope.launch {
            try {
                val envelope = ApiClient.freelance.decideOffer(auth(), projectUuid, offerUuid, action, FreelanceDecisionRequest(reason))
                offer = envelope.data
                notice = envelope.message
                sheet = null
                if (action == "choose") onChosen()
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                error = e.message(generic)
            }
            busy = false
        }
    }

    Column(Modifier.fillMaxSize()) {
        SubHeader(stringResource(R.string.fl_offer), onBack)
        val o = offer
        if (o == null) {
            FlLoading(error)
            return@Column
        }
        val f = o.freelancer
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            // Who sent it — tap for the profile.
            Row(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp)).clickable(onClick = onProfile).padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                Avatar(f, 52)
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(f?.name ?: "—", color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
                        if (f?.verified == true) Icon(Icons.Rounded.Verified, stringResource(R.string.fl_verified), tint = Green, modifier = Modifier.padding(start = 4.dp).size(18.dp))
                    }
                    f?.headline?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
                    Text(ratingText(f), color = settingsMut(), fontSize = 12.sp)
                    Text(stringResource(R.string.fl_see_profile), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
                }
                OfferStatusPill(o.status)
            }
            Card {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    FlStat(Icons.Rounded.Payments, stringResource(R.string.fl_price), "‎${money(o.priceMinor)} ${o.currency}", Modifier.weight(1.5f))
                    FlStat(Icons.Rounded.Schedule, stringResource(R.string.fl_duration), stringResource(R.string.fl_days, o.durationDays), Modifier.weight(1f))
                }
                Field(stringResource(R.string.fl_offer_details), o.details)
                o.answers.forEach { a -> Field(a.question, a.answer) }
                if (o.edited) Text(stringResource(R.string.fl_offer_edited), color = settingsMut(), fontSize = 11.sp)
            }
            if (f != null) Card(Icons.Rounded.Person, stringResource(R.string.fl_freelancer_profile)) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    FlStat(Icons.Rounded.Verified, stringResource(R.string.fl_profile_complete), "${f.completeness}%", Modifier.weight(1f))
                    FlStat(Icons.Rounded.Collections, stringResource(R.string.fl_portfolio), "${f.portfolioCount}", Modifier.weight(1f))
                }
                Field(stringResource(R.string.fl_skills_fit), (skillsMatch(f)?.let { "$it — " } ?: "") + f.matchingSkills.joinToString("، ") { it.name }.ifBlank { "—" })
            }
            if (o.attachments.isNotEmpty()) Card(Icons.Rounded.AttachFile, stringResource(R.string.fl_files)) {
                o.attachments.forEach { a ->
                    Row(
                        Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.07f)).clickable(enabled = opening == null && a.path != null) {
                            opening = a.id
                            scope.launch {
                                if (!openFile(context, a)) error = fileError
                                opening = null
                            }
                        }.padding(horizontal = 12.dp, vertical = 10.dp),
                        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp),
                    ) {
                        if (opening == a.id) CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(18.dp))
                        else Icon(Icons.Rounded.Description, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
                        Text(a.name, color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                }
            }
            notice?.let { FlNotice(it, Icons.Rounded.CheckCircle, Green) }
            error?.let { FlNotice(it, Icons.Rounded.ErrorOutline, Red) }

            // FL-308: talk to them before choosing (phone numbers and links are not allowed until the agreement).
            if (o.can.chat || o.chat != null) FlAction(
                stringResource(R.string.fl_chat_open) + if ((o.chat?.unread ?: 0) > 0) " (${o.chat?.unread})" else "", Icons.Rounded.Chat,
                loading = busy && sheet == "chat",
                onClick = {
                    val existing = o.chat?.uuid
                    if (existing != null) onChat(existing) else {
                        busy = true
                        sheet = "chat"
                        error = null
                        scope.launch {
                            try {
                                ApiClient.freelance.openChat(auth(), projectUuid, offerUuid).data?.uuid?.let(onChat) ?: run { error = generic }
                            } catch (e: CancellationException) {
                                throw e
                            } catch (e: Exception) {
                                error = e.message(generic)
                            }
                            busy = false
                            sheet = null
                        }
                    }
                },
            )
            if (o.can.choose && o.overCap) FlNotice(stringResource(R.string.fl_over_cap_note), Icons.Rounded.Warning, Red)
            if (o.can.choose) FlAction(stringResource(R.string.fl_choose_offer), Icons.Rounded.Handshake, FlTone.Primary, enabled = !o.overCap, loading = busy && sheet == null) { sheet = "choose" }
            if (o.can.shortlist && o.status != "shortlisted") FlAction(stringResource(R.string.fl_shortlist), Icons.Rounded.StarBorder) { decide("shortlist") }
            if (o.can.unshortlist) FlAction(stringResource(R.string.fl_unshortlist), Icons.Rounded.Star) { decide("unshortlist") }
            if (o.can.reject) FlAction(stringResource(R.string.fl_reject_offer), Icons.Rounded.Cancel, FlTone.Danger) { sheet = "reject" }
            Spacer(Modifier.height(20.dp))
        }
    }

    val current = offer
    when {
        sheet == "choose" && current != null -> ChooseSheet(current, busy = busy, onDismiss = { if (!busy) sheet = null }, onConfirm = { decide("choose") })
        sheet == "reject" -> RejectSheet(busy = busy, onDismiss = { if (!busy) sheet = null }, onConfirm = { decide("reject", it) })
    }
}

/** A panel from the bottom over a dimmed page. */
@Composable
internal fun BottomPanel(onDismiss: () -> Unit, content: @Composable () -> Unit) {
    LocaleAwareDialog(onDismissRequest = onDismiss, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        Box(Modifier.fillMaxSize().background(Color.Black.copy(alpha = 0.45f)).clickable(onClick = onDismiss), contentAlignment = Alignment.BottomCenter) {
            Box(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(topStart = 24.dp, topEnd = 24.dp))
                    .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null, onClick = {}),
            ) {
                PinkBackdrop(Modifier.matchParentSize())
                Column(Modifier.navigationBarsPadding().imePadding().padding(18.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) { content() }
            }
        }
    }
}

/** What choosing costs, said before it is done (FL-309). */
@Composable
private fun ChooseSheet(o: FreelanceOfferDto, busy: Boolean, onDismiss: () -> Unit, onConfirm: () -> Unit) {
    val cost = o.clientCost
    FlSheet(Icons.Rounded.Handshake, stringResource(R.string.fl_choose_title, o.freelancer?.name ?: "—"), stringResource(R.string.fl_choose_note), onDismiss) {
        Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(settingsAccent().copy(alpha = 0.07f)).padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            SumLine(stringResource(R.string.fl_price), "‎${money(cost?.priceMinor ?: o.priceMinor)} ${o.currency}")
            SumLine(stringResource(R.string.fl_duration), stringResource(R.string.fl_days, o.durationDays))
            if ((cost?.clientFeeMinor ?: 0L) > 0L) SumLine(stringResource(R.string.fl_client_fee), "‎${money(cost?.clientFeeMinor)} ${o.currency}")
            SumLine(stringResource(R.string.fl_total), "‎${money(cost?.totalMinor ?: o.priceMinor)} ${o.currency}", bold = true)
        }
        FlAction(stringResource(R.string.fl_choose_confirm), Icons.Rounded.Handshake, FlTone.Primary, loading = busy, onClick = onConfirm)
        FlAction(stringResource(R.string.fl_not_now), null, onClick = onDismiss)
    }
}

@Composable
private fun SumLine(label: String, value: String, bold: Boolean = false) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Text(label, color = settingsMut(), fontSize = 14.sp, modifier = Modifier.weight(1f))
        Text(value, color = settingsInk(), fontSize = if (bold) 16.sp else 14.sp, fontWeight = if (bold) FontWeight.ExtraBold else FontWeight.SemiBold)
    }
}

@Composable
private fun RejectSheet(busy: Boolean, onDismiss: () -> Unit, onConfirm: (String?) -> Unit) {
    var reason by rememberSaveable { mutableStateOf("") }
    FlSheet(Icons.Rounded.Cancel, stringResource(R.string.fl_reject_offer), stringResource(R.string.fl_reject_hint), onDismiss) {
        DorrTextField(value = reason, onValueChange = { if (it.length <= 500) reason = it }, label = stringResource(R.string.fl_reject_reason), icon = Icons.Rounded.Notes, singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
        FlAction(stringResource(R.string.fl_reject_confirm), Icons.Rounded.Cancel, FlTone.Danger, loading = busy) { onConfirm(reason.trim().ifBlank { null }) }
        FlAction(stringResource(R.string.fl_not_now), null, onClick = onDismiss)
    }
}

/** Downloads one of the offer's files with the client's own sign-in (it is never a public link) and opens it. */
private suspend fun openFile(context: Context, a: FreelanceAttachmentDto): Boolean {
    val path = a.path ?: return false
    val file = withContext(Dispatchers.IO) {
        runCatching {
            val response = ApiClient.freelance.file(auth(), "mobile/v1/freelance/$path")
            val body = response.body()
            if (!response.isSuccessful || body == null) return@runCatching null
            val dir = File(context.cacheDir, "exports").apply { mkdirs() }
            val safe = a.name.filter { it.isLetterOrDigit() || it in "._- " }.ifBlank { "file" }
            File(dir, safe).also { out -> body.byteStream().use { input -> out.outputStream().use { input.copyTo(it) } } }
        }.getOrNull()
    } ?: return false
    val uri = FileProvider.getUriForFile(context, context.packageName + ".files", file)
    val view = Intent(Intent.ACTION_VIEW).setDataAndType(uri, a.mime ?: "*/*").addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
    return runCatching { context.startActivity(Intent.createChooser(view, a.name).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }.isSuccess
}

// ---------------------------------------------------------------- the freelancer

@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun FreelancerProfilePage(projectUuid: String, offerUuid: String, onBack: () -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val reconnect = collectReconnectTick()
    var profile by remember { mutableStateOf<FreelancerProfileDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()

    LaunchedEffect(projectUuid, offerUuid, reconnect) {
        try {
            profile = ApiClient.freelance.offerFreelancer(auth(), projectUuid, offerUuid).data
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message(generic)
        }
    }

    Column(Modifier.fillMaxSize()) {
        SubHeader(stringResource(R.string.fl_freelancer_profile), onBack)
        val p = profile
        if (p == null) {
            FlLoading(error)
            return@Column
        }
        val head = FreelanceOfferFreelancerDto(uuid = p.uuid, name = p.name, avatarUrl = p.avatarUrl, verified = p.verified, rating = p.rating, reviewsCount = p.reviewsCount)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp)).padding(16.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Avatar(head, 76)
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(p.name ?: "—", color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
                    if (p.verified) Icon(Icons.Rounded.Verified, stringResource(R.string.fl_verified), tint = Green, modifier = Modifier.padding(start = 4.dp).size(18.dp))
                }
                // FL-306: keep this freelancer to find them again.
                Row(
                    Modifier.clip(RoundedCornerShape(999.dp)).clickable {
                        val uuid = p.uuid ?: return@clickable
                        val keep = !p.isFavorite
                        profile = p.copy(isFavorite = keep)
                        scope.launch {
                            val ok = runCatching { if (keep) ApiClient.freelance.addFavorite(auth(), uuid) else ApiClient.freelance.removeFavorite(auth(), uuid) }.isSuccess
                            if (!ok) profile = profile?.copy(isFavorite = !keep)
                        }
                    }.padding(horizontal = 10.dp, vertical = 4.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(if (p.isFavorite) Icons.Rounded.Favorite else Icons.Rounded.FavoriteBorder, null, tint = Red, modifier = Modifier.size(18.dp))
                    Text(stringResource(if (p.isFavorite) R.string.fl_favorite_on else R.string.fl_favorite_add), color = Red, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(start = 4.dp))
                }
                p.headline?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 13.sp) }
                Text(
                    listOfNotNull(ratingText(head), stringResource(R.string.fl_completeness, p.completeness), p.yearsOfExperience?.let { stringResource(R.string.fl_years_exp, it) }).joinToString("  ·  "),
                    color = settingsMut(), fontSize = 12.sp,
                )
                Text(stringResource(R.string.fl_contact_in_dorr), color = settingsMut(), fontSize = 11.sp)
            }
            p.bio?.takeIf { it.isNotBlank() }?.let { Card(Icons.Rounded.Info, stringResource(R.string.fl_about)) { Text(it, color = settingsInk(), fontSize = 13.sp, lineHeight = 20.sp) } }
            if (p.specialties.isNotEmpty() || p.skills.isNotEmpty()) Card(Icons.Rounded.Bolt, stringResource(R.string.fl_skills)) {
                if (p.specialties.isNotEmpty()) Field(stringResource(R.string.fl_specialty), p.specialties.joinToString("، ") { it.name })
                if (p.skills.isNotEmpty()) {
                    val matching = p.matchingSkills.map { it.id }.toSet()
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        p.skills.forEach { s ->
                            val hit = s.id in matching
                            Text(
                                s.name, color = if (hit) Color.White else settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold,
                                modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(if (hit) Green else settingsAccent().copy(alpha = 0.08f)).padding(horizontal = 10.dp, vertical = 4.dp),
                            )
                        }
                    }
                    if (matching.isNotEmpty()) Text(stringResource(R.string.fl_skills_green), color = settingsMut(), fontSize = 11.sp)
                }
            }
            if (p.languages.isNotEmpty() || !p.city.isNullOrBlank()) Card(Icons.Rounded.Language, stringResource(R.string.fl_languages)) {
                if (p.languages.isNotEmpty()) Field(stringResource(R.string.fl_languages), p.languages.joinToString("، "))
                if (!p.city.isNullOrBlank()) Field(stringResource(R.string.fl_city), p.city)
                p.memberSince?.let { Field(stringResource(R.string.fl_member_since), "‎" + it.take(7)) }
            }
            if (p.experiences.isNotEmpty()) Card(Icons.Rounded.Work, stringResource(R.string.fl_work_history)) {
                p.experiences.forEach { e ->
                    Column(Modifier.padding(vertical = 4.dp)) {
                        Text(listOfNotNull(e.title, e.company).joinToString(" — "), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                        Text("‎${e.startedOn.orEmpty()} – " + (if (e.isCurrent) stringResource(R.string.fl_now) else e.endedOn.orEmpty()), color = settingsMut(), fontSize = 12.sp)
                        e.description?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsInk(), fontSize = 12.sp, maxLines = 4, overflow = TextOverflow.Ellipsis) }
                    }
                }
            }
            if (p.portfolio.isNotEmpty()) Card(Icons.Rounded.Collections, stringResource(R.string.fl_portfolio) + " (${p.portfolio.size})") {
                p.portfolio.forEach { w ->
                    Row(Modifier.padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        val cover = w.images.firstOrNull()?.url
                        Box(Modifier.size(56.dp).clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.08f))) {
                            if (cover != null) AsyncImage(model = cover, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                        }
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text(w.title.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            w.description?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp, maxLines = 2, overflow = TextOverflow.Ellipsis) }
                        }
                    }
                }
            }
            // FL-701 / FL-703: what clients said, and their numbers.
            if (p.reviews.isNotEmpty() || (p.completedProjects ?: 0) > 0) Card(Icons.Rounded.Star, stringResource(R.string.fl_rv_about)) {
                Text(listOfNotNull(p.completedProjects?.let { stringResource(R.string.fl_st_completed_n, it) }, p.onTimeRate?.let { stringResource(R.string.fl_st_on_time_n, it) }).joinToString("  ·  "), color = settingsMut(), fontSize = 12.sp)
                p.reviews.forEach { r ->
                    Column(Modifier.padding(vertical = 4.dp)) {
                        ReviewStars(r.stars)
                        r.comment?.let { Text(it, color = settingsInk(), fontSize = 13.sp) }
                        Text(listOfNotNull(r.project, r.at?.take(10)?.let { "\u200E$it" }).joinToString(" · "), color = settingsMut(), fontSize = 11.sp)
                    }
                }
            }
            if (p.certificates.isNotEmpty()) Card(Icons.Rounded.School, stringResource(R.string.fl_certificates)) {
                p.certificates.forEach { c -> Text(listOfNotNull(c.title, c.issuer, c.issuedOn?.let { "‎$it" }).joinToString(" · "), color = settingsInk(), fontSize = 13.sp) }
            }
            Spacer(Modifier.height(20.dp))
        }
    }
}

// ---------------------------------------------------------------- the freelancers I keep (FL-306)

@Composable
internal fun FavoritesPage(onBack: () -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val listState = rememberLazyListState()
    val paged = remember { PagedRows<com.dorr.app.network.FreelanceFavoriteDto>({ it.uuid }, { page -> ApiClient.freelance.favorites(auth(), page = page) }) }
    val list = if (paged.loaded && (paged.error == null || paged.rows.isNotEmpty())) paged.rows.toList() else null
    val error = paged.error

    LaunchedEffect(reconnect) { paged.reload(generic) }
    listState.NearEnd(paged.rows.size) { scope.launch { paged.loadMore(generic) } }

    Column(Modifier.fillMaxSize()) {
        SubHeader(stringResource(R.string.fl_favorites), onBack)
        LazyColumn(Modifier.weight(1f).fillMaxWidth(), state = listState, contentPadding = PaddingValues(horizontal = 16.dp, vertical = 10.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            val rows = list
            when {
                rows == null && error == null -> items(4) { com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().height(80.dp), RoundedCornerShape(18.dp)) }
                rows != null && rows.isEmpty() -> item { com.dorr.app.ui.screens.wallet.WaEmpty(Icons.Rounded.Favorite, com.dorr.app.ui.screens.wallet.Tone.Pink, stringResource(R.string.fl_favorites), stringResource(R.string.fl_favorites_empty)) }
                rows != null -> items(rows, key = { it.uuid }) { f ->
                    Row(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp)).padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                        Avatar(FreelanceOfferFreelancerDto(uuid = f.uuid, name = f.name, avatarUrl = f.avatarUrl))
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Text(f.name ?: "—", color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.Bold)
                                if (f.verified) Icon(Icons.Rounded.Verified, stringResource(R.string.fl_verified), tint = Green, modifier = Modifier.padding(start = 4.dp).size(16.dp))
                            }
                            f.headline?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis) }
                            Text(stringResource(R.string.fl_completeness, f.completeness), color = settingsMut(), fontSize = 12.sp)
                        }
                        Icon(Icons.Rounded.Favorite, stringResource(R.string.fl_favorite_remove), tint = Red, modifier = Modifier.size(34.dp).clip(CircleShape).clickable {
                            scope.launch {
                                if (runCatching { ApiClient.freelance.removeFavorite(auth(), f.uuid) }.isSuccess) paged.rows.removeAll { it.uuid == f.uuid }
                            }
                        }.padding(7.dp))
                    }
                }
            }
            item { PagedSpinner(paged) }
            error?.let { item { Text(it, color = Red, fontSize = 13.sp, modifier = Modifier.clickable { paged.clearError(); scope.launch { paged.loadMore(generic) } }.padding(8.dp)) } }
        }
    }
}
