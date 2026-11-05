package com.dorr.freelance.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Gavel
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.RadioButtonChecked
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.Saver
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.AgreementDto
import com.dorr.freelance.net.AgreementRightsDto
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.DeliveryRightsDto
import com.dorr.freelance.net.RightsInputDto
import com.dorr.freelance.net.RightsOptionsDto
import com.dorr.freelance.net.RightsSummaryDto
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.ChipRow
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.FieldLabel
import com.dorr.freelance.ui.kit.RedToggle
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsSurface
import com.google.gson.Gson
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.toRequestBody

/**
 * FL-L13, ownership and licences — the pieces the offer form, the service form, the delivery sheet, the agreement and the portfolio share.
 * Where the feature is off in my country the server sends `rights: null` and `GET rights` says `enabled: false`: nothing here shows.
 */

/** The terms as typed on the offer and service forms (transfer · license · retained; a licence may be limited, a kept ownership never is). */
internal data class RightsForm(
    val model: String = "transfer",
    val commercial: Boolean = true,
    val exclusive: Boolean = false,
    /** Months; empty = no time limit. */
    val term: String = "",
    val territory: String = "worldwide",
    /** My wish to show the finished work in my portfolio (the client decides). */
    val portfolio: Boolean = false,
)

internal val rightsFormSaver = Saver<RightsForm, String>(save = { Gson().toJson(it) }, restore = { runCatching { Gson().fromJson(it, RightsForm::class.java) }.getOrNull() })

internal fun RightsForm.toInput() = RightsInputDto(model, commercial, exclusive, term.toIntOrNull(), territory, portfolio)

internal fun RightsInputDto.toForm() = RightsForm(model, commercial, exclusive, termMonths?.toString().orEmpty(), territory, portfolio)

/** The terms an offer or a service already carries, back in the form (an edit). */
internal fun RightsSummaryDto.toForm() = RightsForm(model, details.commercial, details.exclusive, details.termMonths?.toString().orEmpty(), details.territory, portfolio)

/** The terms as the fields of a multipart request (`rights[model]`…), the way the offer is sent. */
internal fun RightsForm.toFields(): Map<String, RequestBody> {
    val text = "text/plain".toMediaTypeOrNull()
    val fields = mutableMapOf(
        "rights[model]" to model.toRequestBody(text),
        "rights[commercial]" to (if (commercial) "1" else "0").toRequestBody(text),
        "rights[exclusive]" to (if (exclusive) "1" else "0").toRequestBody(text),
        "rights[territory]" to territory.toRequestBody(text),
        "rights[portfolio]" to (if (portfolio) "1" else "0").toRequestBody(text),
    )
    term.toIntOrNull()?.let { fields["rights[term_months]"] = it.toString().toRequestBody(text) }
    return fields
}

/** What the forms need, read once (and again when the connection comes back); null while it loads and where the feature is off. */
@Composable
internal fun rememberRightsOptions(): RightsOptionsDto? {
    var options by remember { mutableStateOf<RightsOptionsDto?>(null) }
    val reconnect = collectReconnectTick()
    LaunchedEffect(reconnect) { (apiCall { ApiClient.rights.options() } as? ApiResult.Ok)?.data?.let { options = it } }
    return options?.takeIf { it.enabled }
}

private val Green = Color(0xFF16A34A)
private val Amber = Color(0xFFD97706)

/** The three ways to hand over the work, then the limits that go with the one picked, my portfolio wish, and the standard text. */
@Composable
internal fun RightsEditor(form: RightsForm, options: RightsOptionsDto, readOnly: Boolean = false, onChange: (RightsForm) -> Unit) {
    var showText by remember { mutableStateOf(false) }
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp)).padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Box(Modifier.size(38.dp).clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Gavel, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(20.dp))
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(stringResource(R.string.rights_title), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                Text(stringResource(R.string.rights_editor_sub), color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp)
            }
        }
        options.models.forEach { m ->
            val on = form.model == m.model
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp))
                    .background(settingsAccent().copy(alpha = if (on) 0.08f else 0.0f))
                    .border(1.dp, if (on) settingsAccent() else settingsMut().copy(alpha = 0.25f), RoundedCornerShape(14.dp))
                    .clickable(enabled = !readOnly) { onChange(form.copy(model = m.model)) }.padding(12.dp),
                verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                Icon(if (on) Icons.Rounded.RadioButtonChecked else Icons.Rounded.RadioButtonUnchecked, null, tint = if (on) settingsAccent() else settingsMut(), modifier = Modifier.size(22.dp))
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text(m.label, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                        if (m.model == options.default.model) Text(
                            stringResource(R.string.rights_default), color = settingsAccent(), fontSize = 10.sp, fontWeight = FontWeight.Bold,
                            modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(settingsAccent().copy(alpha = 0.12f)).padding(horizontal = 7.dp, vertical = 2.dp),
                        )
                    }
                    Text(m.summary, color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp)
                }
            }
        }

        // A transfer is the whole thing; a licence or a kept ownership says what the client may do.
        if (form.model != "transfer") {
            RightsSwitch(stringResource(R.string.rights_commercial), form.commercial, readOnly) { onChange(form.copy(commercial = it)) }
            if (form.model == "license") {
                RightsSwitch(stringResource(R.string.rights_exclusive), form.exclusive, readOnly) { onChange(form.copy(exclusive = it)) }
                DorrTextField(
                    form.term, { v -> onChange(form.copy(term = v.filter(Char::isDigit).take(3).let { d -> if ((d.toIntOrNull() ?: 0) > options.limits.maxTermMonths) options.limits.maxTermMonths.toString() else d })) },
                    label = stringResource(R.string.rights_term), placeholder = stringResource(R.string.rights_term_hint), icon = Icons.Rounded.Schedule,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth(),
                )
            }
            FieldLabel(stringResource(R.string.rights_territory))
            ChipRow(
                options = listOf("worldwide" to stringResource(R.string.rights_territory_worldwide), "country" to stringResource(R.string.rights_territory_country)),
                selected = setOf(form.territory), onToggle = { if (!readOnly) onChange(form.copy(territory = it)) },
            )
        }

        RightsSwitch(stringResource(R.string.rights_portfolio_wish), form.portfolio, readOnly) { onChange(form.copy(portfolio = it)) }

        options.models.firstOrNull { it.model == form.model }?.terms?.takeIf { it.body.isNotBlank() }?.let { terms ->
            Text(
                stringResource(if (showText) R.string.rights_hide_terms else R.string.rights_read_terms), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(RoundedCornerShape(10.dp)).clickable { showText = !showText }.padding(vertical = 4.dp),
            )
            if (showText) TermsText(terms.title, terms.body, terms.version)
        }
    }
}

@Composable
private fun RightsSwitch(label: String, on: Boolean, readOnly: Boolean, onChange: (Boolean) -> Unit) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        RedToggle(on = on, onClick = { if (!readOnly) onChange(!on) })
        Text(label, color = settingsInk(), fontSize = 13.sp, lineHeight = 18.sp, modifier = Modifier.weight(1f))
    }
}

@Composable
private fun TermsText(title: String, body: String, version: Int) {
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsMut().copy(alpha = 0.08f)).padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(title, color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
        Text(body, color = settingsInk(), fontSize = 12.5.sp, lineHeight = 19.sp)
        if (version > 0) Text(stringResource(R.string.rights_terms_version, version), color = settingsMut(), fontSize = 11.sp)
    }
}

/** The clauses as lines with a tick. */
@Composable
private fun Clauses(lines: List<String>) {
    lines.forEach { line ->
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Icon(Icons.Rounded.CheckCircle, null, tint = Green, modifier = Modifier.size(16.dp).padding(top = 2.dp))
            Text(line, color = settingsInk(), fontSize = 12.5.sp, lineHeight = 18.sp, modifier = Modifier.weight(1f))
        }
    }
}

/** The terms of one of my offers, as the client sees them. */
@Composable
internal fun RightsSummaryCard(s: RightsSummaryDto, modifier: Modifier = Modifier) {
    Column(modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp)).padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Icon(Icons.Rounded.Gavel, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
            Text(stringResource(R.string.rights_title), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
            Text(
                s.modelLabel, color = settingsAccent(), fontSize = 11.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(settingsAccent().copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 3.dp),
            )
        }
        Text(s.summary, color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp)
        Clauses(s.clauses)
    }
}

/** The agreement's terms, where the passing of the rights stands, and the client's leave to show the finished work. */
@Composable
internal fun AgreementRightsCard(r: AgreementRightsDto, projectUuid: String, onAgreement: (AgreementDto) -> Unit) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var showText by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var note by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val sent = stringResource(R.string.rights_portfolio_ask_done)
    val tint = when (r.state) { "granted" -> Green; "partial" -> settingsAccent(); else -> Amber }

    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp)).padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Icon(Icons.Rounded.Gavel, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.rights_title), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                Text(r.modelLabel, color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
            }
            Text(
                stringResource(when (r.state) { "granted" -> R.string.rights_state_granted; "partial" -> R.string.rights_state_partial; else -> R.string.rights_state_pending }),
                color = tint, fontSize = 11.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(tint.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 3.dp),
            )
        }
        Text(r.summary, color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp)
        Text(stringResource(R.string.rights_parts, r.parts.granted, r.parts.total), color = tint, fontSize = 12.sp, fontWeight = FontWeight.Bold)
        Clauses(r.clauses)
        // Before the first part is paid the client only looks.
        if (r.state != "granted") Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(Amber.copy(alpha = 0.08f)).padding(10.dp),
            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Icon(Icons.Rounded.HourglassTop, null, tint = Amber, modifier = Modifier.size(16.dp))
            Text(stringResource(R.string.rights_preview_only), color = settingsInk(), fontSize = 12.sp, lineHeight = 17.sp, modifier = Modifier.weight(1f))
        }

        // The portfolio: mine to show only with the client's leave.
        Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(
                stringResource(if (r.portfolio.allowed) R.string.rights_portfolio_allowed else R.string.rights_portfolio_not_allowed),
                color = if (r.portfolio.allowed) Green else settingsMut(), fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold, lineHeight = 18.sp,
            )
            if (!r.portfolio.allowed) r.portfolio.askedAt?.let { Text(stringResource(R.string.rights_portfolio_asked, "‎" + it.take(10)), color = settingsMut(), fontSize = 11.5.sp) }
            if (r.can.portfolioRequest) ActionButton(stringResource(R.string.rights_portfolio_ask), Icons.Rounded.Gavel, Tone.Soft, enabled = !busy, loading = busy) {
                busy = true
                error = null
                note = null
                scope.launch {
                    val result = apiCall { ApiClient.rights.askPortfolio(projectUuid) }
                    busy = false
                    if (result is ApiResult.Ok) { onAgreement(result.data); note = sent } else error = result.errorText(offlineText) ?: genericError
                }
            }
            note?.let { Text(it, color = Green, fontSize = 12.sp, fontWeight = FontWeight.Bold) }
            error?.let { Text(it, color = Color(0xFFDC2626), fontSize = 12.sp, fontWeight = FontWeight.Bold) }
        }

        if (r.terms.body.isNotBlank()) {
            Text(
                stringResource(if (showText) R.string.rights_hide_terms else R.string.rights_read_terms), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(RoundedCornerShape(10.dp)).clickable { showText = !showText }.padding(vertical = 4.dp),
            )
            if (showText) TermsText(r.terms.title, r.terms.body, r.terms.version)
        }
    }
}

/** One delivery's rights line: my word and the outside material I listed. */
@Composable
internal fun DeliveryRightsView(d: DeliveryRightsDto?) {
    if (d == null || d.confirmedAt == null) return
    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.CheckCircle, null, tint = Green, modifier = Modifier.size(14.dp))
            Spacer(Modifier.width(6.dp))
            Text(stringResource(R.string.rights_delivery_confirmed), color = Green, fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold)
        }
        if (d.materials.isNotEmpty()) {
            Text(stringResource(R.string.rights_materials_used), color = settingsMut(), fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
            d.materials.forEach { m ->
                Text("•  " + listOfNotNull(m.name, m.source, m.license).joinToString(" · "), color = settingsMut(), fontSize = 11.5.sp, lineHeight = 16.sp)
            }
        }
    }
}
