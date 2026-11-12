package com.dorr.app.ui.screens.moving

import android.content.Intent
import android.net.Uri
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.LocationOn
import androidx.compose.material.icons.rounded.Map
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.StarBorder
import androidx.compose.material.icons.rounded.Storefront
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.MovingCriterionDto
import com.dorr.app.network.MovingOfferBranchDto
import com.dorr.app.network.MovingOfferEstablishmentDto
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.theme.AppColors
import java.util.Locale

/*
 * Knowing the company before choosing it (owner, 2026-11-12): its rating by criteria — as the rider sees the captain's — and
 * where its branches are, the one that answered first, each with how far it is from the pickup point and a tap to open it on
 * the map.
 */

private fun one(v: Double): String = String.format(Locale.US, "%.1f", v)

/** The company's stars: the overall average big, then each criterion with its bar and number (as rides score the captain). */
@Composable
internal fun MvCompanyRatingCard(e: MovingOfferEstablishmentDto) {
    MvCard {
        MvCardTitle(stringResource(R.string.mv_company_rating))
        Spacer(Modifier.height(8.dp))
        if (e.count == 0 || e.average == null) {
            Text(stringResource(R.string.mv_company_rating_none), color = settingsMut(), fontSize = 13.sp)
            return@MvCard
        }
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(one(e.average), color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 30.sp)
            Spacer(Modifier.width(10.dp))
            Column {
                MvStarsRow(e.average, 18)
                Text(
                    stringResource(R.string.mv_ratings_n, e.count) + " · " + stringResource(R.string.mv_finished_moves, e.jobs),
                    color = settingsMut(), fontSize = 12.sp,
                )
            }
        }
        val scored = e.criteria.filter { it.average != null }
        if (scored.isNotEmpty()) {
            Spacer(Modifier.height(10.dp))
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                scored.forEach { c -> MvCriterionBar(c.label ?: c.key, c.average ?: 0.0) }
            }
        }
    }
}

/** Read-only stars for an average (a half star rounds to the nearest). */
@Composable
private fun MvStarsRow(average: Double, size: Int) {
    val full = Math.round(average).toInt()
    Row {
        (1..5).forEach { i ->
            Icon(if (i <= full) Icons.Rounded.Star else Icons.Rounded.StarBorder, null, tint = if (i <= full) AppColors.warning else settingsMut(), modifier = Modifier.size(size.dp))
        }
    }
}

/** One criterion: its name, a bar filling to its average out of 5, and the number. */
@Composable
private fun MvCriterionBar(label: String, average: Double) {
    val fill by animateFloatAsState((average / 5.0).toFloat().coerceIn(0f, 1f), tween(600), label = "mv-criterion")
    Row(verticalAlignment = Alignment.CenterVertically) {
        Text(label, color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1.2f))
        Spacer(Modifier.width(8.dp))
        Box(Modifier.weight(1f).height(7.dp).clip(CircleShape).background(mvInsetColor())) {
            Box(Modifier.fillMaxWidth(fill).fillMaxHeight().clip(CircleShape).background(AppColors.warning))
        }
        Spacer(Modifier.width(8.dp))
        Text(one(average), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Black)
    }
}

/** The company's branches: the one that answered first, then the nearest; the address, the distance and the map. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun MvCompanyBranchesCard(branches: List<MovingOfferBranchDto>) {
    if (branches.isEmpty()) return
    val context = LocalContext.current
    MvCard {
        MvCardTitle(stringResource(R.string.mv_company_branches))
        Spacer(Modifier.height(4.dp))
        branches.forEachIndexed { index, b ->
            if (index > 0) Box(Modifier.fillMaxWidth().padding(vertical = 8.dp).height(1.dp).background(mvInsetColor()))
            Row(verticalAlignment = Alignment.Top, modifier = Modifier.padding(top = if (index == 0) 6.dp else 0.dp)) {
                Box(Modifier.size(38.dp).clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                    Icon(if (b.answering) Icons.Rounded.Storefront else Icons.Rounded.LocationOn, null, tint = settingsAccent(), modifier = Modifier.size(20.dp))
                }
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(b.name ?: stringResource(R.string.mv_branch_unnamed), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 14.sp)
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        if (b.answering) MvChip(stringResource(R.string.mv_branch_answering), settingsAccent())
                        if (b.isMain) MvChip(stringResource(R.string.mv_branch_main), AppColors.info)
                        b.distanceKm?.let { MvChip(stringResource(R.string.mv_branch_distance, one(it)), settingsMut()) }
                    }
                    b.address?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 13.sp, lineHeight = 18.sp) }
                }
                if (b.lat != null && b.lng != null) {
                    Spacer(Modifier.width(8.dp))
                    Box(
                        Modifier.size(38.dp).clip(CircleShape).background(mvInsetColor()).clickable {
                            val label = Uri.encode(b.name.orEmpty())
                            runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("geo:${b.lat},${b.lng}?q=${b.lat},${b.lng}($label)"))) }
                        },
                        contentAlignment = Alignment.Center,
                    ) { Icon(Icons.Rounded.Map, stringResource(R.string.mv_branch_on_map), tint = settingsAccent(), modifier = Modifier.size(19.dp)) }
                }
            }
        }
    }
}

/**
 * The rating's criteria, as rides ask the rider: a row of stars per criterion, each starting at the overall stars and moved
 * on its own. [scores] keeps what was moved; an untouched criterion counts as the overall stars.
 */
@Composable
internal fun MvCriteriaStars(criteria: List<MovingCriterionDto>, overall: Int, scores: MutableMap<String, Int>) {
    if (criteria.isEmpty() || overall == 0) return
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(mvInsetColor()).padding(horizontal = 12.dp, vertical = 6.dp),
    ) {
        criteria.forEach { c ->
            val value = scores[c.key] ?: overall
            Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(c.label ?: c.key, color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                (1..5).forEach { i ->
                    Icon(
                        if (i <= value) Icons.Rounded.Star else Icons.Rounded.StarBorder, null,
                        tint = if (i <= value) AppColors.warning else settingsMut(),
                        modifier = Modifier.size(26.dp).clip(CircleShape).clickable { scores[c.key] = i },
                    )
                }
            }
        }
    }
}

/** A fresh map for [MvCriteriaStars]. */
@Composable
internal fun rememberCriteriaScores(key: String): MutableMap<String, Int> = androidx.compose.runtime.remember(key) { mutableStateMapOf() }
