package com.dorr.app.ui.screens.aichat

import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.RadioButton
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.AiWorldConsentRequest
import com.dorr.app.network.AiWorldConsentTextDto
import com.dorr.app.network.AiWorldProfileDto
import com.dorr.app.network.ApiClient
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.chat.LocalFile
import com.dorr.app.ui.screens.chat.chatAuth
import com.dorr.app.ui.screens.chat.copyToCache
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody

private fun text(value: String): RequestBody = value.toRequestBody("text/plain".toMediaTypeOrNull())

/**
 * My photos for DORR World: add up to 3 photos of one person, read and accept the consent text, and delete a
 * set at any time (which also stops any unfinished video that uses it). Photos are never shown back in the app.
 */
@Composable
internal fun WorldPhotosScreen() {
    val c = sitePalette()
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    var profiles by remember { mutableStateOf<List<AiWorldProfileDto>?>(null) }
    var consent by remember { mutableStateOf<AiWorldConsentTextDto?>(null) }
    var adding by remember { mutableStateOf(false) }
    var label by remember { mutableStateOf("") }
    var subject by remember { mutableStateOf("self") }
    var photos by remember { mutableStateOf<List<LocalFile>>(emptyList()) }
    var agreed by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    suspend fun reload() {
        profiles = runCatching { ApiClient.aiWorld.profiles(chatAuth()).data.orEmpty() }.getOrDefault(emptyList())
    }

    LaunchedEffect(Unit) {
        consent = runCatching { ApiClient.aiWorld.consentText(chatAuth()).data }.getOrNull()
        reload()
    }

    val picker = rememberLauncherForActivityResult(ActivityResultContracts.GetMultipleContents()) { uris ->
        photos = (photos + uris.mapNotNull { copyToCache(context, it, "face.jpg") }).take(3)
    }

    if (profiles == null) {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Ai.Red) }
        return
    }

    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        SiteSectionTitle(stringResource(R.string.ai_world_photos_title), stringResource(R.string.ai_world_photos_sub))

        profiles.orEmpty().forEach { p ->
            SiteCard(padding = 14.dp) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(p.label, color = c.ink, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp)
                        Text(stringResource(R.string.ai_world_photos_count, p.photos), color = c.mut, fontSize = 12.sp)
                    }
                    Text(
                        stringResource(if (p.status == "active") R.string.ai_world_photo_active else R.string.ai_world_photo_pending),
                        color = if (p.status == "active") c.success else c.warn, fontWeight = FontWeight.Bold, fontSize = 12.sp,
                    )
                }
                Spacer(Modifier.height(10.dp))
                SecondaryButton(stringResource(R.string.ai_world_photo_delete), Modifier.fillMaxWidth(), color = c.danger) {
                    scope.launch {
                        runCatching { ApiClient.aiWorld.deleteProfile(chatAuth(), p.id) }.onFailure { error = it.apiFailure().message }
                        reload()
                    }
                }
            }
        }

        if (!adding) {
            if ((profiles?.size ?: 0) < 5) PrimaryButton(stringResource(R.string.ai_world_photo_add)) { adding = true }
        } else {
            SiteCard {
                SiteField(stringResource(R.string.ai_world_photo_label), label, { label = it })
                Row(verticalAlignment = Alignment.CenterVertically) {
                    RadioButton(selected = subject == "self", onClick = { subject = "self" })
                    Text(stringResource(R.string.ai_world_subject_self), color = c.ink, fontSize = 13.sp)
                }
                Row(verticalAlignment = Alignment.CenterVertically) {
                    RadioButton(selected = subject == "other_with_permission", onClick = { subject = "other_with_permission" })
                    Text(stringResource(R.string.ai_world_subject_other), color = c.ink, fontSize = 13.sp)
                }
                Spacer(Modifier.height(6.dp))
                SecondaryButton(stringResource(R.string.ai_world_photo_pick, photos.size), Modifier.fillMaxWidth(), enabled = photos.size < 3) { picker.launch("image/*") }
                Spacer(Modifier.height(10.dp))
                Text(consent?.text.orEmpty(), color = c.mut, fontSize = 12.sp, lineHeight = 18.sp)
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Checkbox(checked = agreed, onCheckedChange = { agreed = it })
                    Text(consent?.attestations?.get(subject).orEmpty(), color = c.ink, fontSize = 12.5.sp, modifier = Modifier.weight(1f))
                }
                error?.let { Text(it, color = c.danger, fontSize = 13.sp) }
                Spacer(Modifier.height(8.dp))
                if (busy) Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Ai.Red) }
                else PrimaryButton(stringResource(R.string.ai_world_photo_save), enabled = label.isNotBlank() && photos.isNotEmpty() && agreed) {
                    error = null
                    busy = true
                    scope.launch {
                        runCatching {
                            val parts = photos.map { MultipartBody.Part.createFormData("photos[]", it.name, it.file.asRequestBody(it.mime.toMediaTypeOrNull())) }
                            val created = ApiClient.aiWorld.createProfile(chatAuth(), mapOf("label" to text(label.trim()), "subject_type" to text(subject)), parts).data
                            // The checkbox the customer ticked IS the attestation: it is only sent together with the upload.
                            if (created != null) ApiClient.aiWorld.consent(chatAuth(), created.id, AiWorldConsentRequest(if (subject == "self") "own_photo" else "has_permission"))
                        }.fold(
                            onSuccess = {
                                adding = false; label = ""; photos = emptyList(); agreed = false
                                reload()
                            },
                            onFailure = { error = it.apiFailure().message },
                        )
                        busy = false
                    }
                }
            }
        }
        if (!adding) error?.let { Text(it, color = c.danger, fontSize = 13.sp) }
    }
}
