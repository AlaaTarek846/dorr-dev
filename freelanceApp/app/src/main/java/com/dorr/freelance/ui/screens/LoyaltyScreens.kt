package com.dorr.freelance.ui.screens

import android.widget.Toast
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Block
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Loyalty
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material3.Icon
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.LoyaltyClientDto
import com.dorr.freelance.net.LoyaltyDashDto
import com.dorr.freelance.net.LoyaltyRequest
import com.dorr.freelance.net.LoyaltyStepRequest
import com.dorr.freelance.net.LoyaltyTierDto
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.AvatarImage
import com.dorr.freelance.ui.components.ChipRow
import com.dorr.freelance.ui.components.EmptyState
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.Page
import com.dorr.freelance.ui.components.SkeletonDetail
import com.dorr.freelance.ui.kit.AccountDark
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsNight
import com.dorr.freelance.ui.kit.settingsSurface
import kotlinx.coroutines.launch

/*
 * FL-L09 (part B): my loyalty program. A discount for clients who keep coming back, in steps by how many works we finished together —
 * I set the steps (within the platform's limits), the discount is taken by itself on a project offer, a service order and a consultation,
 * and it comes off my own price. The screen: how the program did, the on / off switch, the steps with ready-made ladders, how a client
 * sees it, and my clients on a step. It reloads on open, on a live notice and when the connection returns — never on a timer.
 */

private val LOY_GREEN = Color(0xFF16A34A)

/** 1500 → "15", 250 → "2.5", 125 → "1.25". */
internal fun loyPercent(bp: Int): String {
    val whole = bp / 100
    val rest = bp % 100
    return when {
        rest == 0 -> "$whole"
        rest % 10 == 0 -> "$whole.${rest / 10}"
        else -> "$whole.${rest.toString().padStart(2, '0')}"
    }
}

/** A price after a discount, to the nearest minor unit, as the server works it out (display only: the server decides). */
private fun loyAfter(minor: Long, bp: Int): Long = ((minor * (10000 - bp) + 5000) / 10000).coerceAtLeast(1)

/** What a client earned, on a price already worked out: "Loyalty 10% (− 100 SAR)". Nothing when there is none. */
@Composable
internal fun LoyaltyOfferLine(bp: Int?, savingMinor: Long?, currency: String?) {
    if (bp == null) return
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        Icon(Icons.Rounded.Loyalty, contentDescription = null, tint = LOY_GREEN, modifier = Modifier.size(15.dp))
        val saved = savingMinor?.let { " (− ‎${formatMinor(it, null)} ${currency.orEmpty()})" }.orEmpty()
        Text(stringResource(R.string.loy_offer_line, loyPercent(bp)) + saved, color = LOY_GREEN, fontSize = 12.sp, fontWeight = FontWeight.Bold)
    }
}

/** Said before I type a price: this project's client earned a step of my program, so it comes off what I ask. */
@Composable
internal fun LoyaltyNote(bp: Int, works: Int) {
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(LOY_GREEN.copy(alpha = 0.10f)).padding(12.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Icon(Icons.Rounded.Loyalty, contentDescription = null, tint = LOY_GREEN, modifier = Modifier.size(20.dp))
        Text(stringResource(R.string.loy_offer_note, works, loyPercent(bp)), color = settingsInk(), fontSize = 12.5.sp, lineHeight = 18.sp, modifier = Modifier.weight(1f))
    }
}

@Composable
fun LoyaltyScreen(onBack: () -> Unit, onSwitchStore: ((String) -> Unit)? = null) {
    val context = LocalContext.current
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var dash by remember { mutableStateOf<LoyaltyDashDto?>(null) }
    var closed by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    // What I am editing: copied from the saved program once, and again after each save (a live reload never overwrites it).
    val tiers = remember { mutableStateListOf<LoyaltyTierDto>() }
    var seeded by remember { mutableStateOf(false) }
    var enabled by remember { mutableStateOf(true) }

    fun seed(d: LoyaltyDashDto) {
        tiers.clear()
        tiers.addAll((d.program?.tiers ?: d.presets.firstOrNull { it.key == "balanced" }?.tiers ?: d.presets.firstOrNull()?.tiers ?: listOf(LoyaltyTierDto(2, 500))).map { LoyaltyTierDto(it.after, it.discountBp) })
        enabled = d.program?.enabled ?: true
        seeded = true
    }

    suspend fun load() {
        when (val r = apiCall { ApiClient.loyalty.show() }) {
            is ApiResult.Ok -> { dash = r.data; closed = false; error = null; if (!seeded) seed(r.data) }
            is ApiResult.Failed -> if (r.error.errorCode == "freelance_feature_off") closed = true else error = r.errorText(offlineText) ?: genericError
            else -> Unit
        }
    }
    LaunchedEffect(reconnect) { load() }
    LaunchedEffect(Unit) { Realtime.changed.collect { load() } }

    fun save() {
        busy = true
        error = null
        scope.launch {
            val r = apiCall { ApiClient.loyalty.save(LoyaltyRequest(enabled, tiers.map { LoyaltyStepRequest(it.after, it.discountBp) })) }
            busy = false
            if (r is ApiResult.Ok) {
                dash = r.data
                seed(r.data)
                Toast.makeText(context, context.getString(R.string.loy_saved), Toast.LENGTH_SHORT).show()
            } else error = r.errorText(offlineText) ?: genericError
        }
    }

    fun toggle(on: Boolean) {
        busy = true
        error = null
        scope.launch {
            val r = apiCall { ApiClient.loyalty.toggle(if (on) "enable" else "disable") }
            busy = false
            if (r is ApiResult.Ok) { dash = r.data; enabled = r.data.program?.enabled ?: on } else error = r.errorText(offlineText) ?: genericError
        }
    }

    Page(title = stringResource(R.string.loy_title), onBack = onBack) {
        val d = dash
        if (closed) {
            EmptyState(icon = Icons.Rounded.Block, title = stringResource(R.string.loy_closed_title), body = stringResource(R.string.loy_closed_body), modifier = Modifier.padding(top = 70.dp))
            return@Page
        }
        if (d == null) {
            if (error == null) SkeletonDetail()
            ErrorBanner(error, Modifier.fillMaxWidth())
            return@Page
        }
        val saved = d.program
        val max = d.rules.maxDiscountBp
        val rising = tiers.zipWithNext().all { (a, b) -> b.after > a.after && b.discountBp > a.discountBp }
        val problem = if (!rising) stringResource(R.string.loy_problem_order) else null
        val dirty = saved == null || enabled != saved.enabled || tiers.map { it.after to it.discountBp } != saved.tiers.map { it.after to it.discountBp }

        onSwitchStore?.let { com.dorr.freelance.ui.kit.StoreSwitcher("loyalty", it) }
        LoyHero(d)
        // On / off: instant once a program is saved.
        Row(
            Modifier.fillMaxWidth().widthIn(max = 520.dp).settingsSurface(RoundedCornerShape(20.dp)).padding(horizontal = 16.dp, vertical = 14.dp),
            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(stringResource(R.string.loy_switch_title), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                Text(
                    stringResource(if (saved == null) R.string.loy_switch_save_first else if (saved.enabled) R.string.loy_switch_on else R.string.loy_switch_off),
                    color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp,
                )
            }
            Switch(
                checked = saved?.enabled ?: false, enabled = saved != null && !busy, onCheckedChange = { toggle(it) },
                colors = SwitchDefaults.colors(checkedTrackColor = settingsAccent(), checkedThumbColor = Color.White),
            )
        }

        StepCard(1, stringResource(R.string.loy_steps_title), stringResource(R.string.loy_steps_sub)) {
            if (d.presets.isNotEmpty()) {
                Text(stringResource(R.string.loy_presets), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                ChipRow(d.presets.map { it.key to it.name }, emptySet(), { key ->
                    d.presets.firstOrNull { it.key == key }?.let { p -> tiers.clear(); tiers.addAll(p.tiers.map { LoyaltyTierDto(it.after, it.discountBp) }) }
                })
            }
            tiers.forEachIndexed { i, t ->
                LoyTierRow(i + 1, t, d, canRemove = tiers.size > 1, onChange = { tiers[i] = it }, onRemove = { tiers.removeAt(i) })
            }
            val last = tiers.lastOrNull()
            val canAdd = tiers.size < d.rules.maxTiers && last != null && last.after < d.rules.maxAfter && last.discountBp < max
            Row(
                Modifier.fillMaxWidth().height(46.dp).clip(RoundedCornerShape(14.dp)).border(1.dp, if (canAdd) settingsAccent() else settingsMut().copy(alpha = 0.3f), RoundedCornerShape(14.dp))
                    .clickable(enabled = canAdd) {
                        val l = tiers.last()
                        tiers.add(LoyaltyTierDto((l.after + 2).coerceAtMost(d.rules.maxAfter), (l.discountBp + 500).coerceAtMost(max)))
                    },
                horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
            ) {
                val tint = if (canAdd) settingsAccent() else settingsMut().copy(alpha = 0.5f)
                Icon(Icons.Rounded.Add, contentDescription = null, tint = tint, modifier = Modifier.size(18.dp))
                Spacer(Modifier.size(6.dp))
                Text(stringResource(R.string.loy_add), color = tint, fontSize = 14.sp, fontWeight = FontWeight.Bold)
            }
            Text(stringResource(R.string.loy_limit, d.rules.maxTiers, loyPercent(max)), color = settingsMut(), fontSize = 11.5.sp)
            problem?.let { Text(it, color = serviceTone("awaiting").ink, fontSize = 12.sp, fontWeight = FontWeight.SemiBold) }
        }

        LoyPreview(tiers, d)
        SettingsPrimaryButton(
            text = stringResource(R.string.loy_save), loading = busy, enabled = dirty && problem == null && tiers.isNotEmpty(),
            modifier = Modifier.widthIn(max = 520.dp), onClick = { save() },
        )
        ErrorBanner(error, Modifier.fillMaxWidth())

        if (saved != null) LoyClients(d)
        LoyHow()
    }
}

/** The head: what this is for and how it did. */
@Composable
private fun LoyHero(d: LoyaltyDashDto) {
    val st = d.stats
    com.dorr.freelance.ui.kit.StoreHero(
        Icons.Rounded.Loyalty, stringResource(R.string.loy_hero_title), stringResource(R.string.loy_hero_body),
        listOf(
            com.dorr.freelance.ui.kit.HeroNumber("${st.pastClients}", stringResource(R.string.loy_stat_clients)),
            com.dorr.freelance.ui.kit.HeroNumber("${st.inProgram}", stringResource(R.string.loy_stat_in_program)),
            com.dorr.freelance.ui.kit.HeroNumber("${st.uses}", stringResource(R.string.loy_stat_uses)),
        ),
        footnote = if (st.uses > 0) stringResource(R.string.loy_saved_line, svcMoney(st.savedMinor, d.decimals, d.currency)) else null,
    )
}

/** One step of the ladder: how many works, how much off. */
@Composable
private fun LoyTierRow(number: Int, tier: LoyaltyTierDto, d: LoyaltyDashDto, canRemove: Boolean, onChange: (LoyaltyTierDto) -> Unit, onRemove: () -> Unit) {
    val night = settingsNight()
    val minPct = ((d.rules.minDiscountBp + 99) / 100).coerceAtLeast(1)
    val maxPct = (d.rules.maxDiscountBp / 100).coerceAtLeast(minPct)
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(if (night) AccountDark.well else settingsAccent().copy(alpha = 0.05f)).padding(12.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(stringResource(R.string.loy_step_n, number), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
            Text("${loyPercent(tier.discountBp)}%", color = LOY_GREEN, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
            if (canRemove) {
                Box(Modifier.padding(start = 8.dp).size(30.dp).clip(CircleShape).clickable(onClick = onRemove), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.Delete, contentDescription = stringResource(R.string.loy_remove), tint = settingsMut(), modifier = Modifier.size(18.dp))
                }
            }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Stepper(stringResource(R.string.loy_after), tier.after, 1..d.rules.maxAfter, modifier = Modifier.weight(1f)) { onChange(tier.copy(after = it)) }
            Stepper(stringResource(R.string.loy_discount), (tier.discountBp / 100).coerceIn(minPct, maxPct), minPct..maxPct, modifier = Modifier.weight(1f)) { onChange(tier.copy(discountBp = it * 100)) }
        }
    }
}

/** How a client reads it, with one worked example at the first step. */
@Composable
private fun LoyPreview(tiers: List<LoyaltyTierDto>, d: LoyaltyDashDto) {
    InfoCard(title = stringResource(R.string.loy_preview_title), icon = Icons.Rounded.Visibility) {
        Text(stringResource(R.string.loy_preview_head), color = settingsInk(), fontSize = 13.5.sp, fontWeight = FontWeight.Bold, lineHeight = 19.sp)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            tiers.forEach { t ->
                Column(
                    Modifier.weight(1f).clip(RoundedCornerShape(14.dp)).background(LOY_GREEN.copy(alpha = 0.10f)).padding(vertical = 10.dp, horizontal = 4.dp),
                    horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(2.dp),
                ) {
                    Text("${loyPercent(t.discountBp)}%", color = LOY_GREEN, fontSize = 17.sp, fontWeight = FontWeight.ExtraBold)
                    Text(stringResource(R.string.loy_tier_after, t.after), color = settingsMut(), fontSize = 10.5.sp, textAlign = TextAlign.Center, lineHeight = 14.sp)
                }
            }
        }
        tiers.firstOrNull()?.let { t ->
            val example = 1000L * pow10(d.decimals)
            Text(
                stringResource(R.string.loy_example, svcMoney(example, d.decimals, d.currency), svcMoney(loyAfter(example, t.discountBp), d.decimals, d.currency)),
                color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp,
            )
        }
    }
}

private fun pow10(n: Int): Long {
    var v = 1L
    repeat(n.coerceIn(0, 6)) { v *= 10 }
    return v
}

/** My clients on a step, and how many stand on each. */
@Composable
private fun LoyClients(d: LoyaltyDashDto) {
    InfoCard(title = stringResource(R.string.loy_clients_title), icon = Icons.Rounded.Groups) {
        val tiers = d.stats.byTier
        if (tiers.isNotEmpty()) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                tiers.forEach { t ->
                    Column(
                        Modifier.weight(1f).clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.07f)).padding(vertical = 8.dp),
                        horizontalAlignment = Alignment.CenterHorizontally,
                    ) {
                        Text("${loyPercent(t.discountBp)}%", color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                        Text(stringResource(R.string.loy_tier_clients, t.clients), color = settingsMut(), fontSize = 10.5.sp)
                    }
                }
            }
        }
        if (d.clients.isEmpty()) {
            Text(stringResource(R.string.loy_clients_empty), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp)
        } else d.clients.forEach { LoyClientRow(it) }
    }
}

@Composable
private fun LoyClientRow(c: LoyaltyClientDto) {
    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        AvatarImage(c.avatarUrl, 36.dp)
        Column(Modifier.weight(1f)) {
            Text(c.name, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(
                c.next?.let { stringResource(R.string.loy_client_next, it.remaining, loyPercent(it.discountBp)) } ?: stringResource(R.string.loy_client_works, c.works),
                color = settingsMut(), fontSize = 11.5.sp,
            )
        }
        val now = c.current
        if (now != null) {
            Text(
                "${loyPercent(now.discountBp)}%", color = LOY_GREEN, fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold,
                modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(LOY_GREEN.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 4.dp),
            )
        } else {
            Text(stringResource(R.string.loy_client_none), color = settingsMut(), fontSize = 11.5.sp)
        }
    }
}

@Composable
private fun LoyHow() {
    InfoCard(title = stringResource(R.string.loy_how_title), icon = Icons.Rounded.CheckCircle) {
        listOf(R.string.loy_how_1, R.string.loy_how_2, R.string.loy_how_3, R.string.loy_how_4).forEach { id ->
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.Top) {
                Box(Modifier.padding(top = 6.dp).size(6.dp).clip(CircleShape).background(settingsAccent()))
                Text(stringResource(id), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp)
            }
        }
    }
}
