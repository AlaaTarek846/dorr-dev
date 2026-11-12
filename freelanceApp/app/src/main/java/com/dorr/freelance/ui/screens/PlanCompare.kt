package com.dorr.freelance.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.freelance.R
import com.dorr.freelance.net.PlanFeatureDto
import com.dorr.freelance.ui.components.Card
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.google.gson.JsonElement
import java.math.BigDecimal

/** One plan as a column of the comparison: the free baseline first, [mine] marks the plan I (or my team) am on. */
internal data class PlanColumn(val slug: String, val name: String, val mine: Boolean, val perks: Map<String, JsonElement?>)

/** What a cell shows: a tick, a dash (nothing), or the number. */
private sealed interface Cell {
    data object Yes : Cell
    data object No : Cell
    data object NoCap : Cell
    data class Text(val text: String) : Cell
}

/** A value of the server's `perks` by the feature's type: a switch is on or off, a level counts from 0, a limit is a number or no cap, a fee is hundredths of a percent. */
private fun cellOf(f: PlanFeatureDto, value: JsonElement?): Cell {
    val v = value?.takeIf { !it.isJsonNull && it.isJsonPrimitive }?.asJsonPrimitive
    return when (f.type) {
        "switch" -> if (v != null && (if (v.isBoolean) v.asBoolean else v.asInt != 0)) Cell.Yes else Cell.No
        "level" -> v?.asInt?.takeIf { it > 0 }?.let { Cell.Text(if (f.max != null && f.max > 1) "$it/${f.max}" else "$it") } ?: Cell.No
        "fee_bp" -> v?.asInt?.let { Cell.Text(BigDecimal.valueOf(it.toLong(), 2).stripTrailingZeros().toPlainString() + "%") } ?: Cell.No
        else -> if (v == null) Cell.NoCap else Cell.Text(java.text.NumberFormat.getIntegerInstance(java.util.Locale.US).format(v.asLong))
    }
}

@Composable
private fun groupName(group: String): String = stringResource(
    when (group) {
        "money" -> R.string.plans_group_money
        "offers" -> R.string.plans_group_offers
        "visibility" -> R.string.plans_group_visibility
        "selling" -> R.string.plans_group_selling
        "tools" -> R.string.plans_group_tools
        "team" -> R.string.plans_group_team
        "support" -> R.string.plans_group_support
        else -> R.string.plans_group_other
    },
)

/**
 * FS-705c: the plans side by side — every feature the app checks today, in the server's order and groups, one column per plan, my plan marked.
 * Only what works is listed (the server leaves out what is "coming"), so a tick or a number here is always something the plan really gives.
 */
@Composable
internal fun PlanComparison(features: List<PlanFeatureDto>, plans: List<PlanColumn>) {
    if (features.isEmpty() || plans.size < 2) return
    Card {
        Text(stringResource(R.string.plans_compare_title), color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
        Row(verticalAlignment = Alignment.Bottom) {
            Spacer(Modifier.weight(1.5f))
            plans.forEach { p ->
                Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally) {
                    Text(p.name, color = if (p.mine) settingsAccent() else settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center, maxLines = 2)
                    if (p.mine) Text(stringResource(R.string.plans_compare_mine), color = settingsAccent(), fontSize = 10.sp, fontWeight = FontWeight.Bold)
                }
            }
        }
        features.groupBy { it.group }.forEach { (group, rows) ->
            Text(groupName(group), color = settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.Bold)
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                rows.forEach { f ->
                    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1.5f)) {
                            Text(f.name, color = settingsInk(), fontSize = 12.sp, lineHeight = 16.sp)
                            f.hint?.let { Text(it, color = settingsMut(), fontSize = 10.sp, lineHeight = 13.sp) }
                        }
                        plans.forEach { p ->
                            Box(Modifier.weight(1f), contentAlignment = Alignment.Center) {
                                when (val c = cellOf(f, p.perks[f.key])) {
                                    Cell.Yes -> Icon(Icons.Rounded.Check, null, tint = settingsAccent(), modifier = Modifier.size(16.dp))
                                    Cell.No -> Text("—", color = settingsMut(), fontSize = 12.sp)
                                    Cell.NoCap -> Text(stringResource(R.string.plans_compare_unlimited), color = settingsAccent(), fontSize = 11.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center)
                                    is Cell.Text -> Text(c.text, color = if (p.mine) settingsAccent() else settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center)
                                }
                            }
                        }
                    }
                }
            }
            Spacer(Modifier.height(2.dp))
        }
    }
}
