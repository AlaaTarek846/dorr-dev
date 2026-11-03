package com.dorr.app.ui.screens.freelance

import androidx.compose.animation.animateContentSize
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AttachFile
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.foundation.gestures.detectTransformGestures
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.material.icons.rounded.Flag
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.Send
import androidx.compose.material.icons.rounded.VerifiedUser
import androidx.compose.material3.Icon
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.FreelanceAgreementRightsDto
import com.dorr.app.network.FreelanceDeliveryRightsDto
import com.dorr.app.network.FreelanceRightsClaimRequest
import com.dorr.app.network.FreelanceRightsSummaryDto
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

/**
 * FL-L13, ownership and licences as the client sees them: the terms on an offer and a ready-made service (what the freelancer says), the
 * terms kept in the agreement with how far the rights have passed (a part is theirs once approved and paid; before that the client only
 * previews and reviews) and the leave to show the finished work in the portfolio, the freelancer's word with each delivery, and reporting
 * a work shown on Dorr that is not the freelancer's own. Everything here is null-safe: the feature off in a country sends no `rights`.
 */

internal fun rightsModelColor(model: String): Color = when (model) {
    "transfer" -> Color(0xFF16A34A)
    "license" -> Color(0xFF2563EB)
    else -> Color(0xFFD97706)
}

/** The model as a small pill; [label] comes from the server in my language. */
@Composable
internal fun RightsPill(label: String, model: String, modifier: Modifier = Modifier) {
    val c = rightsModelColor(model)
    Text(
        label, color = c, fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1,
        modifier = modifier.clip(RoundedCornerShape(999.dp)).background(c.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 3.dp),
    )
}

/** The lines of the terms, each with a small tick. */
@Composable
private fun RightsClauses(clauses: List<String>) {
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        clauses.forEach { line ->
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.Top) {
                Icon(Icons.Rounded.CheckCircle, null, tint = settingsAccent(), modifier = Modifier.padding(top = 2.dp).size(15.dp))
                Text(line, color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp, modifier = Modifier.weight(1f))
            }
        }
    }
}

/** On an offer or a service: who owns the work once it is paid, in the freelancer's words. Nothing when the feature is off. */
@Composable
internal fun RightsCard(rights: FreelanceRightsSummaryDto?) {
    if (rights == null) return
    Card(Icons.Rounded.VerifiedUser, stringResource(R.string.rights_title)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            RightsPill(rights.modelLabel, rights.model)
        }
        if (rights.summary.isNotBlank()) Text(rights.summary, color = settingsMut(), fontSize = 12.5.sp, lineHeight = 19.sp)
        RightsClauses(rights.clauses)
        if (rights.portfolio) FlNotice(stringResource(R.string.rights_portfolio_wish), Icons.Rounded.Info, settingsAccent())
    }
}

/**
 * In the agreement: the model and how far the rights have passed, the lines, the standard text kept with it (opens in place), the note that
 * before a part is paid the client only looks, and the switch that lets the freelancer show the finished work in their portfolio.
 * [onPortfolio] sends the new answer and the screen takes the agreement that comes back.
 */
@Composable
internal fun AgreementRightsCard(r: FreelanceAgreementRightsDto, busy: Boolean, onPortfolio: (Boolean) -> Unit) {
    var text by remember { mutableStateOf(false) }
    val stateColor = when (r.state) { "granted" -> Color(0xFF16A34A); "partial" -> Color(0xFF2563EB); else -> Color(0xFFD97706) }
    Card(Icons.Rounded.VerifiedUser, stringResource(R.string.rights_title)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            RightsPill(r.modelLabel, r.model)
            Text(
                stringResource(when (r.state) { "granted" -> R.string.rights_state_granted; "partial" -> R.string.rights_state_partial; else -> R.string.rights_state_pending }),
                color = stateColor, fontSize = 11.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(stateColor.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 3.dp),
            )
        }
        if (r.summary.isNotBlank()) Text(r.summary, color = settingsMut(), fontSize = 12.5.sp, lineHeight = 19.sp)
        if (r.parts.total > 0) Text(stringResource(R.string.rights_parts_n, r.parts.granted, r.parts.total), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
        RightsClauses(r.clauses)
        // Before the first part is approved and paid the client only previews and reviews.
        if (r.state != "granted") FlNotice(stringResource(R.string.rights_preview_only), Icons.Rounded.HourglassTop, Color(0xFFD97706))
        if (r.terms.body.isNotBlank()) {
            Column(Modifier.fillMaxWidth().animateContentSize(), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Text(
                    stringResource(if (text) R.string.rights_terms_hide else R.string.rights_terms_show), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                    modifier = Modifier.clickable { text = !text }.padding(vertical = 4.dp),
                )
                if (text) {
                    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.06f)).padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        if (r.terms.title.isNotBlank()) Text(r.terms.title, color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
                        Text(r.terms.body, color = settingsInk(), fontSize = 12.5.sp, lineHeight = 20.sp)
                        if (r.terms.version > 0) Text(stringResource(R.string.rights_terms_version, r.terms.version), color = settingsMut(), fontSize = 11.sp)
                    }
                }
            }
        }
        // The client's leave to show the finished work in the freelancer's portfolio: off until they say so, and taken back at once.
        if (r.can.portfolioToggle) {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(stringResource(R.string.rights_portfolio_title), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                    Text(stringResource(R.string.rights_portfolio_hint), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp)
                }
                Switch(checked = r.portfolio.allowed, onCheckedChange = onPortfolio, enabled = !busy, colors = SwitchDefaults.colors(checkedTrackColor = settingsAccent()))
            }
            if (r.portfolio.askedAt != null && !r.portfolio.allowed) FlNotice(stringResource(R.string.rights_portfolio_asked), Icons.Rounded.HourglassTop, settingsAccent())
        }
    }
}

/** With a delivered version: the freelancer's word that the work is theirs and the outside material they listed (name · source · licence). */
@Composable
internal fun DeliveryRightsLines(r: FreelanceDeliveryRightsDto?) {
    if (r == null || r.confirmedAt == null) return
    Column(Modifier.fillMaxWidth().padding(top = 2.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.VerifiedUser, null, tint = Color(0xFF16A34A), modifier = Modifier.size(14.dp))
            Text(stringResource(R.string.rights_delivery_confirmed, "‎" + r.confirmedAt.take(10)), color = Color(0xFF16A34A), fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold)
        }
        if (r.materials.isNotEmpty()) {
            Text(stringResource(R.string.rights_materials_title), color = settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.Bold)
            r.materials.forEach { m ->
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.Top) {
                    Icon(Icons.Rounded.AttachFile, null, tint = settingsMut(), modifier = Modifier.padding(top = 2.dp).size(13.dp))
                    Text(listOfNotNull(m.name, m.source?.takeIf { it.isNotBlank() }, m.license?.takeIf { it.isNotBlank() }).joinToString(" · "), color = settingsInk(), fontSize = 12.sp, lineHeight = 18.sp)
                }
            }
        }
    }
}

/**
 * "Report this work": a work shown on Dorr (a portfolio item or a ready-made service) that is not the freelancer's own. The server refuses a
 * repeat by the same person (409) and a work that is not shown (404); its words are shown as they are.
 */
@Composable
internal fun ReportWorkSheet(subjectType: String, subject: String, onDismiss: () -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    var details by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var done by remember { mutableStateOf<String?>(null) }
    FlSheet(Icons.Rounded.Flag, stringResource(R.string.rights_report_title), stringResource(R.string.rights_report_body), onDismiss = { if (!busy) onDismiss() }) {
        if (done == null) {
            DorrTextField(details, { details = it.take(2000) }, placeholder = stringResource(R.string.rights_report_hint), icon = Icons.Rounded.Flag, singleLine = false, minLines = 4, modifier = Modifier.fillMaxWidth())
            error?.let { FlNotice(it, Icons.Rounded.Info, Color(0xFFDC2626)) }
            FlAction(stringResource(R.string.rights_report_send), Icons.Rounded.Send, FlTone.Primary, enabled = details.trim().length >= 10, loading = busy) {
                busy = true
                error = null
                scope.launch {
                    try {
                        done = ApiClient.freelanceRights.claim(auth(), FreelanceRightsClaimRequest(subjectType, subject, details.trim())).message
                    } catch (e: CancellationException) {
                        throw e
                    } catch (e: Exception) {
                        error = e.message(generic)
                    }
                    busy = false
                }
            }
        } else {
            FlNotice(done.orEmpty().ifBlank { stringResource(R.string.rights_report_title) }, Icons.Rounded.CheckCircle, Color(0xFF16A34A))
            FlAction(stringResource(R.string.common_cancel), null, onClick = onDismiss)
        }
    }
}

/** FL-L13: FLAG_SECURE on the activity while any freelance screen is open (the same switch the chat uses), cleared on leaving. */
@Composable
internal fun FreelanceScreenShield() {
    val activity = androidx.compose.ui.platform.LocalContext.current as? android.app.Activity
    androidx.compose.runtime.DisposableEffect(activity) {
        activity?.window?.addFlags(android.view.WindowManager.LayoutParams.FLAG_SECURE)
        onDispose { activity?.window?.clearFlags(android.view.WindowManager.LayoutParams.FLAG_SECURE) }
    }
}

/** A file that is only a preview for now: its name and size and the lock line; nothing to tap, nothing to download. */
@Composable
internal fun PreviewOnlyFile(f: com.dorr.app.network.FreelanceDeliveryFileDto) {
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(settingsMut().copy(alpha = 0.08f)).padding(10.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
        Text("📎 ${f.name}" + if (f.size > 0) " · ${f.size / 1024} KB" else "", color = settingsInk(), fontSize = 12.sp)
        Text("🔒 " + stringResource(R.string.rights_preview_locked), color = Color(0xFFD97706), fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold)
    }
}

/** An in-app viewer for a preview image: pinch to zoom, drag to move, no share and no save. */
@Composable
internal fun RightsImageViewer(image: androidx.compose.ui.graphics.ImageBitmap, name: String, onClose: () -> Unit) {
    var scale by remember { mutableStateOf(1f) }
    var offset by remember { mutableStateOf(androidx.compose.ui.geometry.Offset.Zero) }
    androidx.compose.ui.window.Dialog(onDismissRequest = onClose, properties = androidx.compose.ui.window.DialogProperties(usePlatformDefaultWidth = false, securePolicy = androidx.compose.ui.window.SecureFlagPolicy.SecureOn)) {
        androidx.compose.foundation.layout.Box(
            Modifier.fillMaxWidth().fillMaxSize().background(Color.Black)
                .pointerInput(Unit) {
                    detectTransformGestures { _, pan, zoom, _ ->
                        scale = (scale * zoom).coerceIn(1f, 5f)
                        offset = if (scale == 1f) androidx.compose.ui.geometry.Offset.Zero else offset + pan
                    }
                },
            contentAlignment = Alignment.Center,
        ) {
            androidx.compose.foundation.Image(
                image, name, modifier = Modifier.fillMaxWidth().graphicsLayer(scaleX = scale, scaleY = scale, translationX = offset.x, translationY = offset.y),
                contentScale = androidx.compose.ui.layout.ContentScale.Fit,
            )
            Icon(Icons.Rounded.Close, null, tint = Color.White, modifier = Modifier.align(Alignment.TopEnd).padding(16.dp).size(36.dp).clip(RoundedCornerShape(50)).background(Color.Black.copy(alpha = 0.5f)).clickable(onClick = onClose).padding(6.dp))
        }
    }
}
