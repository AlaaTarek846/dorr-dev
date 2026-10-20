package com.dorr.driver.ui

import androidx.activity.compose.BackHandler
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.ui.kit.DorrToggle
import com.dorr.driver.ui.kit.ScreenScaffold
import com.dorr.driver.ui.theme.DorrBrand
import com.dorr.driver.ui.kit.DorrField
import com.dorr.driver.R
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.PreferencesDto
import com.google.gson.JsonArray
import com.google.gson.JsonNull
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.math.BigDecimal

/** Pickup ranges offered as choices, in km (only those the country's search can reach). */
private val RANGES_KM = listOf(2, 3, 5, 10, 20)

/** How far Auto-Accept will go for a pickup, in km. */
private val AUTO_PICKUP_KM = listOf(1, 2, 3, 5)

/**
 * The captain's driving preferences (D-32..D-37): the categories they want to be asked for, how far
 * they go for a pickup, and Auto-Accept — requests that fit their own rules are accepted for them.
 * Every choice is saved as it is made; the server answers with the whole state and says what isn't
 * available where they are (Auto-Accept, its price and payment rules).
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun PreferencesScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var prefs by remember { mutableStateOf<PreferencesDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var saved by remember { mutableStateOf(false) }
    BackHandler(onBack = onBack)

    LaunchedEffect(Unit) { (apiCall { DriverBackend.api.preferences() } as? ApiResult.Ok)?.let { prefs = it.data } }

    fun save(body: JsonObject) {
        error = null
        saved = false
        scope.launch {
            when (val r = apiCall { DriverBackend.api.savePreferences(body) }) {
                is ApiResult.Ok -> { prefs = r.data; saved = true }
                is ApiResult.Failed -> error = r.error.displayMessage
                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
            }
        }
    }
    fun saveAuto(build: JsonObject.() -> Unit) = save(JsonObject().apply { add("auto_accept", JsonObject().apply(build)) })

    ScreenScaffold(stringResource(R.string.pf_title), onBack) {
        val p = prefs ?: return
        val maxKm = (p.pickupRadiusMaxM ?: 50000) / 1000
        val auto = p.autoAccept

        Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp).navigationBarsPadding(), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            if (error != null) Text(error.orEmpty(), color = DorrBrand.danger, fontSize = 13.sp)
            else if (saved) Text(stringResource(R.string.pf_saved), color = DorrBrand.success, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)

            // D-32: the categories.
            Card {
                Text(stringResource(R.string.pf_categories), fontWeight = FontWeight.Bold)
                Text(stringResource(R.string.pf_categories_hint), fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
                Spacer(Modifier.height(10.dp))
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    // O-27: only the categories this captain and car may take — the general ones and the special ones they
                    // qualify for (For Her for women, VIP for a luxury car, Accessible for an equipped one…).
                    p.categories.filter { it.eligible }.forEach { c ->
                        PrefPill(c.name ?: c.slug, c.selected) {
                            val now = p.categories.filter { it.selected }.map { it.slug }
                            val next = if (c.selected) now - c.slug else now + c.slug
                            if (next.isEmpty()) error = context.getString(R.string.pf_keep_one)
                            else save(JsonObject().apply { add("categories", JsonArray().apply { next.forEach { add(it) } }) })
                        }
                    }
                }

            }

            // D-33: how far for a pickup.
            Card {
                Text(stringResource(R.string.pf_range), fontWeight = FontWeight.Bold)
                Text(stringResource(R.string.pf_range_hint), fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
                Spacer(Modifier.height(10.dp))
                val current = p.pickupRadiusM
                val options = (RANGES_KM.filter { it <= maxKm } + listOfNotNull(current?.let { (it / 1000).takeIf { km -> km > 0 && km !in RANGES_KM } })).distinct().sorted()
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    PrefPill(stringResource(R.string.pf_no_limit), current == null) {
                        save(JsonObject().apply { add("pickup_radius_m", JsonNull.INSTANCE) })
                    }
                    options.forEach { km ->
                        PrefPill(stringResource(R.string.pf_km, km), current != null && current / 1000 == km) {
                            save(JsonObject().apply { addProperty("pickup_radius_m", km * 1000) })
                        }
                    }
                }
            }

            // D-34..D-37: Auto-Accept — only where the country has it on.
            if (auto.available) {
                Card {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(stringResource(R.string.pf_auto), fontWeight = FontWeight.Bold)
                            Text(stringResource(R.string.pf_auto_hint), fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
                        }
                        DorrToggle(auto.enabled) { on -> saveAuto { addProperty("enabled", on) } }
                    }
                    if (auto.enabled) {
                        Spacer(Modifier.height(12.dp))
                        Text(stringResource(R.string.pf_auto_pickup), fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
                        Spacer(Modifier.height(6.dp))
                        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                            PrefPill(stringResource(R.string.pf_any), auto.maxPickupM == null) { saveAuto { add("max_pickup_m", JsonNull.INSTANCE) } }
                            AUTO_PICKUP_KM.forEach { km ->
                                PrefPill(stringResource(R.string.pf_km, km), auto.maxPickupM == km * 1000) { saveAuto { addProperty("max_pickup_m", km * 1000) } }
                            }
                        }
                    }
                }

                if (auto.enabled && auto.priceFilter.allowed) PriceRules(p)
                if (auto.enabled && auto.paymentFilter.allowed) {
                    Card {
                        Text(stringResource(R.string.pf_payment), fontWeight = FontWeight.Bold)
                        Text(stringResource(R.string.pf_payment_hint), fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
                        Spacer(Modifier.height(10.dp))
                        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                            auto.paymentFilter.options.forEach { method ->
                                val on = method in auto.paymentFilter.methods
                                PrefPill(paymentLabel(method), on) {
                                    val next = if (on) auto.paymentFilter.methods - method else auto.paymentFilter.methods + method
                                    saveAuto { add("payment_methods", JsonArray().apply { next.forEach { add(it) } }) }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

/** D-36: the lowest earning Auto-Accept takes — per ride and per km — only where the country allows it. */
@Composable
private fun PriceRules(p: PreferencesDto) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val places = p.currency?.decimals ?: 2
    val symbol = p.currency?.symbol.orEmpty()
    val price = p.autoAccept.priceFilter
    var fare by remember(price.minFareMinor) { mutableStateOf(fromMinor(price.minFareMinor, places)) }
    var perKm by remember(price.minPerKmMinor) { mutableStateOf(fromMinor(price.minPerKmMinor, places)) }
    var message by remember { mutableStateOf<String?>(null) }

    Card {
        Text(stringResource(R.string.pf_price), fontWeight = FontWeight.Bold)
        Spacer(Modifier.height(8.dp))
        DorrField(
            value = fare, onValueChange = { fare = it; message = null }, singleLine = true,
            label = { Text(stringResource(R.string.pf_min_fare, symbol)) },
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.fillMaxWidth(),
        )
        Spacer(Modifier.height(8.dp))
        DorrField(
            value = perKm, onValueChange = { perKm = it; message = null }, singleLine = true,
            label = { Text(stringResource(R.string.pf_min_km, symbol)) },
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.fillMaxWidth(),
        )
        Spacer(Modifier.height(10.dp))
        BigButton(stringResource(R.string.pf_save)) {
            val body = JsonObject().apply {
                add("auto_accept", JsonObject().apply {
                    toMinor(fare, places)?.let { addProperty("min_fare_minor", it) } ?: add("min_fare_minor", JsonNull.INSTANCE)
                    toMinor(perKm, places)?.let { addProperty("min_per_km_minor", it) } ?: add("min_per_km_minor", JsonNull.INSTANCE)
                })
            }
            scope.launch {
                message = when (val r = apiCall { DriverBackend.api.savePreferences(body) }) {
                    is ApiResult.Ok -> context.getString(R.string.pf_saved)
                    is ApiResult.Failed -> r.error.displayMessage
                    is ApiResult.Offline -> context.getString(R.string.error_offline)
                }
            }
        }
        message?.let { Text(it, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp)) }
    }
}

@Composable
private fun PrefPill(label: String, selected: Boolean, enabled: Boolean = true, onClick: () -> Unit) {
    val bg by animateColorAsState(if (selected) DorrBrand.navy else DorrBrand.navy.copy(alpha = 0.07f), tween(200), label = "pref-pill")
    Text(
        label, color = if (selected) Color.White else DorrBrand.navy.copy(alpha = if (enabled) 1f else 0.4f), fontWeight = FontWeight.SemiBold, fontSize = 13.sp,
        modifier = Modifier.clip(RoundedCornerShape(50)).background(bg).clickable(enabled = enabled, onClick = onClick).padding(horizontal = 14.dp, vertical = 8.dp),
    )
}

@Composable
private fun paymentLabel(method: String): String = stringResource(
    when (method) { "cash" -> R.string.pf_pay_cash; "wallet" -> R.string.pf_pay_wallet; else -> R.string.pf_pay_card },
)

/** "12.5" → minor units (1250 with two places); null for nothing typed or not a number. */
private fun toMinor(text: String, places: Int): Long? =
    text.trim().takeIf { it.isNotEmpty() }?.toBigDecimalOrNull()?.movePointRight(places)?.toLong()

private fun fromMinor(minor: Long?, places: Int): String =
    minor?.let { BigDecimal(it).movePointLeft(places).stripTrailingZeros().toPlainString() }.orEmpty()
