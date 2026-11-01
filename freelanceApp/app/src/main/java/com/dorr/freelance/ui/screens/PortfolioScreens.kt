package com.dorr.freelance.ui.screens

import androidx.compose.material.icons.rounded.Videocam
import androidx.compose.material.icons.rounded.Link
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.Work
import com.dorr.freelance.ui.components.SkeletonDetail
import com.dorr.freelance.ui.components.SkeletonList
import android.Manifest
import android.content.pm.PackageManager
import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.ArrowDownward
import androidx.compose.material.icons.rounded.ArrowUpward
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Image
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.listSaver
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshots.SnapshotStateList
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import coil.compose.AsyncImage
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.MySkillDto
import com.dorr.freelance.net.MySpecialtyDto
import com.dorr.freelance.net.PortfolioItemDto
import com.dorr.freelance.net.PortfolioOrderRequest
import com.dorr.freelance.net.apiDone
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.Card
import com.dorr.freelance.ui.components.ChipRow
import com.dorr.freelance.ui.components.DateField
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.FieldLabel
import com.dorr.freelance.ui.components.Page
import com.dorr.freelance.ui.kit.RedToggle
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.SubHeader
import com.dorr.freelance.ui.kit.SettingsScreenTitle
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsSurface
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.asRequestBody
import java.io.File

private const val MAX_WORKS = 30
private const val MAX_IMAGES = 10
private const val MAX_WORK_SKILLS = 10

/** My portfolio as a tab: my works (first image, title, who can see it), add one, and put them in order. Up to 30. */
@Composable
fun PortfolioTab(onBack: () -> Unit, onOpenItem: (Int) -> Unit) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val items = remember { mutableStateListOf<PortfolioItemDto>() }
    var loaded by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(reconnect) {
        val result = apiCall { ApiClient.portfolio.list() }
        if (result is ApiResult.Ok) {
            items.clear()
            items.addAll(result.data)
            loaded = true
            error = null
        } else {
            error = result.errorText(offlineText) ?: genericError
        }
    }

    fun move(index: Int, by: Int) {
        val to = index + by
        if (to !in items.indices) return
        val moved = items.removeAt(index)
        items.add(to, moved)
        scope.launch {
            val result = apiCall { ApiClient.portfolio.order(PortfolioOrderRequest(items.map { it.id })) }
            if (result !is ApiResult.Ok) error = result.errorText(offlineText) ?: genericError
        }
    }

    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(bottom = 16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        SubHeader(title = stringResource(R.string.portfolio_title), onBack = onBack)
        Column(Modifier.padding(horizontal = 14.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text(stringResource(R.string.portfolio_hint), color = settingsMut(), fontSize = 12.sp)
        if (!loaded && error == null) SkeletonList()
        if (loaded) {
            if (items.size < MAX_WORKS) SettingsPrimaryButton(text = stringResource(R.string.portfolio_add), onClick = { onOpenItem(0) })
            if (items.isEmpty()) Text(stringResource(R.string.portfolio_empty), color = settingsMut(), fontSize = 13.sp)
            items.forEachIndexed { index, item ->
                Row(
                    Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(16.dp)).clickable { onOpenItem(item.id) }.padding(10.dp),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(10.dp),
                ) {
                    val cover = item.images.firstOrNull()?.path
                    Box(Modifier.size(64.dp).clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                        if (cover != null) AsyncImage(model = ApiClient.url(cover), contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                        else Icon(Icons.Rounded.Image, contentDescription = null, tint = settingsAccent())
                    }
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Text(item.title, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
                        item.specialty?.name?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp, maxLines = 1) }
                        Text(visibilityLabel(item.visibility), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                        if (item.hidden) Text(stringResource(R.string.portfolio_hidden) + (item.hiddenReason?.let { ": $it" } ?: ""), color = settingsMut(), fontSize = 11.sp)
                    }
                    Column {
                        Icon(Icons.Rounded.ArrowUpward, contentDescription = null, tint = settingsMut(), modifier = Modifier.size(22.dp).clickable(enabled = index > 0) { move(index, -1) })
                        Spacer(Modifier.height(6.dp))
                        Icon(Icons.Rounded.ArrowDownward, contentDescription = null, tint = settingsMut(), modifier = Modifier.size(22.dp).clickable(enabled = index < items.lastIndex) { move(index, 1) })
                    }
                }
            }
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
        }
    }
}

@Composable
private fun visibilityLabel(visibility: String): String = when (visibility) {
    "public" -> stringResource(R.string.vis_public)
    "clients_only" -> stringResource(R.string.vis_clients)
    else -> stringResource(R.string.vis_private)
}

/**
 * One work: add (itemId 0) or edit. The text fields and who can see it are saved with one request; the images (up to 10, from the
 * gallery or the camera) go up one by one after that. A work shown beyond its owner needs the rights confirmation. Everything is
 * `rememberSaveable` because a phone can kill the app while the gallery or the camera is open.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun PortfolioFormScreen(itemId: Int, onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)

    var currentId by rememberSaveable { mutableStateOf(itemId) }
    var item by remember { mutableStateOf<PortfolioItemDto?>(null) }
    var specialties by remember { mutableStateOf<List<MySpecialtyDto>>(emptyList()) }
    var mySkills by remember { mutableStateOf<List<MySkillDto>>(emptyList()) }
    var loaded by rememberSaveable { mutableStateOf(itemId == 0) }

    var title by rememberSaveable { mutableStateOf("") }
    var description by rememberSaveable { mutableStateOf("") }
    var completed by rememberSaveable { mutableStateOf("") }
    var specialtyId by rememberSaveable { mutableStateOf<Int?>(null) }
    val skillIds = rememberSaveable(saver = intListSaver()) { mutableStateListOf<Int>() }
    var visibility by rememberSaveable { mutableStateOf("private") }
    var rights by rememberSaveable { mutableStateOf(false) }
    var externalUrl by rememberSaveable { mutableStateOf("") }
    var videoUrl by rememberSaveable { mutableStateOf("") }
    val pending = rememberSaveable(saver = stringListSaver()) { mutableStateListOf<String>() }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var confirmDelete by remember { mutableStateOf(false) }

    fun fill(dto: PortfolioItemDto) {
        item = dto
        title = dto.title
        description = dto.description.orEmpty()
        completed = dto.completedOn.orEmpty()
        specialtyId = dto.specialty?.id
        skillIds.clear()
        skillIds.addAll(dto.skills.map { it.id })
        visibility = dto.visibility
        rights = dto.rightsConfirmed
        externalUrl = dto.externalUrl.orEmpty()
        videoUrl = ""
    }

    // One read of each list when the page opens.
    LaunchedEffect(Unit) {
        (apiCall { ApiClient.lists.mySpecialties() } as? ApiResult.Ok)?.data?.let { specialties = it }
        (apiCall { ApiClient.lists.mySkills() } as? ApiResult.Ok)?.data?.let { mySkills = it }
        if (itemId != 0) {
            val result = apiCall { ApiClient.portfolio.list() }
            if (result is ApiResult.Ok) {
                val found = result.data.firstOrNull { it.id == currentId }
                if (found != null) {
                    // A form restored after the app was killed keeps what was typed; only a fresh one is filled from the server.
                    if (!loaded) fill(found) else item = found
                }
                loaded = true
            } else {
                error = result.errorText(offlineText) ?: genericError
            }
        }
    }

    // ---- images ----
    val existing = item?.images.orEmpty()
    val imageCount = existing.size + pending.size

    fun take(uri: Uri) {
        scope.launch {
            val file = withContext(Dispatchers.IO) { preparePortfolioImage(context, uri, "work_${System.currentTimeMillis()}") }
            if (file == null) error = genericError else if (imageCount < MAX_IMAGES) pending.add(file.absolutePath)
        }
    }

    val gallery = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri -> uri?.let(::take) }
    var cameraUriText by rememberSaveable { mutableStateOf<String?>(null) }
    val camera = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { taken -> if (taken) cameraUriText?.let { take(Uri.parse(it)) } }
    fun launchCamera() {
        val file = File(File(context.cacheDir, "camera").apply { mkdirs() }, "work_camera.jpg")
        val uri = FileProvider.getUriForFile(context, "${context.packageName}.files", file)
        cameraUriText = uri.toString()
        camera.launch(uri)
    }
    val cameraPermission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted -> if (granted) launchCamera() }
    fun openCamera() {
        if (ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) launchCamera()
        else cameraPermission.launch(Manifest.permission.CAMERA)
    }

    fun deleteExisting(media: Int) {
        busy = true
        scope.launch {
            val result = apiCall { ApiClient.portfolio.deleteImage(currentId, media) }
            busy = false
            if (result is ApiResult.Ok) item = result.data else error = result.errorText(offlineText) ?: genericError
        }
    }

    // ---- save / delete ----
    fun save() {
        busy = true
        error = null
        val body = mutableMapOf<String, Any?>(
            "title" to title.trim(),
            "description" to description.trim().ifEmpty { null },
            "completed_on" to completed.ifEmpty { null },
            "specialty_id" to specialtyId,
            "skill_ids" to skillIds.toList(),
            "visibility" to visibility,
            "rights_confirmed" to (rights && visibility != "private"),
            "external_url" to externalUrl.trim().ifEmpty { null },
            "video_url" to videoUrl.trim().ifEmpty { null },
        )
        scope.launch {
            val saved = if (currentId == 0) apiCall { ApiClient.portfolio.create(body) } else apiCall { ApiClient.portfolio.update(currentId, body) }
            if (saved !is ApiResult.Ok) {
                busy = false
                error = saved.errorText(offlineText) ?: genericError
                return@launch
            }
            // From here the work exists: a failed image no longer loses the rest.
            currentId = saved.data.id
            item = saved.data
            val toSend = pending.toList()
            for (path in toSend) {
                val file = File(path)
                if (!file.exists()) {
                    pending.remove(path)
                    continue
                }
                val part = MultipartBody.Part.createFormData("image", file.name, file.asRequestBody("image/jpeg".toMediaTypeOrNull()))
                val up = apiCall { ApiClient.portfolio.addImage(currentId, part) }
                if (up is ApiResult.Ok) {
                    item = up.data
                    pending.remove(path)
                    file.delete()
                } else {
                    busy = false
                    error = up.errorText(offlineText) ?: genericError
                    return@launch
                }
            }
            busy = false
            onBack()
        }
    }

    fun delete() {
        busy = true
        scope.launch {
            val failed = apiDone(offlineText, genericError) { ApiClient.portfolio.delete(currentId) }
            busy = false
            if (failed == null) onBack() else error = failed
        }
    }

    Page(title = stringResource(if (currentId == 0) R.string.portfolio_add else R.string.common_edit), onBack = onBack) {
        if (!loaded && error == null) SkeletonDetail()
        if (loaded) {
            com.dorr.freelance.ui.components.FormIntro(Icons.Rounded.Work, stringResource(R.string.pf_intro_title), stringResource(R.string.pf_intro))
            com.dorr.freelance.ui.components.FormGroup {
                DorrTextField(title, { title = it }, label = stringResource(R.string.pf_title), icon = Icons.Rounded.Work, modifier = Modifier.fillMaxWidth())
                DorrTextField(description, { description = it.take(1000) }, label = stringResource(R.string.pf_description), icon = Icons.Rounded.Notes, singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
                DateField(completed, { completed = it }, label = stringResource(R.string.pf_completed), modifier = Modifier.fillMaxWidth())
            }

            if (specialties.isNotEmpty()) {
                com.dorr.freelance.ui.components.FormGroup {
                    FieldLabel(stringResource(R.string.pf_specialty))
                    ChipRow(
                        options = specialties.map { it.id.toString() to it.name },
                        selected = setOfNotNull(specialtyId?.toString()),
                        onToggle = { value -> specialtyId = if (specialtyId?.toString() == value) null else value.toInt() },
                    )
                }
            }
            if (mySkills.isNotEmpty()) {
                com.dorr.freelance.ui.components.FormGroup {
                    FieldLabel(stringResource(R.string.pf_skills))
                    ChipRow(
                        options = mySkills.filter { !it.pending }.map { it.id.toString() to it.name },
                        selected = skillIds.map { it.toString() }.toSet(),
                        onToggle = { value ->
                            val id = value.toInt()
                            if (id in skillIds) skillIds.remove(id) else if (skillIds.size < MAX_WORK_SKILLS) skillIds.add(id)
                        },
                    )
                }
            }

            com.dorr.freelance.ui.components.FormGroup {
                FieldLabel(stringResource(R.string.pf_visibility))
                ChipRow(
                    options = listOf("private" to stringResource(R.string.vis_private), "clients_only" to stringResource(R.string.vis_clients), "public" to stringResource(R.string.vis_public)),
                    selected = setOf(visibility),
                    onToggle = { visibility = it },
                )
                if (visibility != "private") {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        RedToggle(on = rights, onClick = { rights = !rights })
                        Text(stringResource(R.string.pf_rights), color = settingsInk(), fontSize = 13.sp, modifier = Modifier.weight(1f))
                    }
                    Text(stringResource(R.string.portfolio_verified_hint), color = settingsMut(), fontSize = 11.sp)
                }
            }

            com.dorr.freelance.ui.components.FormGroup {
                DorrTextField(
                    externalUrl, { externalUrl = it }, label = stringResource(R.string.pf_external), icon = Icons.Rounded.Link, placeholder = "https://",
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri), modifier = Modifier.fillMaxWidth(),
                    textStyle = MaterialTheme.typography.bodyLarge.copy(textDirection = TextDirection.Ltr),
                )
                DorrTextField(
                    videoUrl, { videoUrl = it }, label = stringResource(R.string.pf_video), icon = Icons.Rounded.Videocam, placeholder = "https://youtu.be/...",
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri), modifier = Modifier.fillMaxWidth(),
                    textStyle = MaterialTheme.typography.bodyLarge.copy(textDirection = TextDirection.Ltr),
                )
                item?.video?.embedUrl?.let { Text(stringResource(R.string.pf_video_saved), color = settingsMut(), fontSize = 11.sp) }
                Text(stringResource(R.string.pf_video_hint), color = settingsMut(), fontSize = 11.sp)
            }

            com.dorr.freelance.ui.components.FormGroup {
                FieldLabel(stringResource(R.string.pf_images) + " ($imageCount/$MAX_IMAGES)")
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    existing.forEach { image ->
                        Thumb(model = image.path?.let { ApiClient.url(it) }, onRemove = { deleteExisting(image.id) }, enabled = !busy)
                    }
                    pending.toList().forEach { path ->
                        Thumb(model = File(path), onRemove = { pending.remove(path); File(path).delete() }, enabled = !busy)
                    }
                }
                if (imageCount < MAX_IMAGES) {
                    Row(horizontalArrangement = Arrangement.spacedBy(20.dp)) {
                        Text(stringResource(R.string.profile_photo_gallery), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable { gallery.launch("image/*") })
                        Text(stringResource(R.string.profile_photo_camera), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable { openCamera() })
                    }
                }
            }

            SettingsPrimaryButton(text = stringResource(R.string.profile_save), enabled = title.trim().length >= 2 && (visibility == "private" || rights), loading = busy, onClick = ::save)
            if (currentId != 0) {
                Text(stringResource(R.string.common_delete), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable { confirmDelete = true }.padding(8.dp))
            }
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }

    if (confirmDelete) {
        AlertDialog(
            onDismissRequest = { confirmDelete = false },
            text = { Text(stringResource(R.string.common_delete_confirm)) },
            confirmButton = { TextButton(onClick = { confirmDelete = false; delete() }) { Text(stringResource(R.string.common_delete), color = settingsAccent()) } },
            dismissButton = { TextButton(onClick = { confirmDelete = false }) { Text(stringResource(R.string.common_cancel), color = settingsMut()) } },
        )
    }
}

@Composable
private fun Thumb(model: Any?, onRemove: () -> Unit, enabled: Boolean) {
    Box(Modifier.size(78.dp).clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.12f))) {
        AsyncImage(model = model, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
        Box(
            Modifier.align(Alignment.TopEnd).padding(3.dp).size(22.dp).clip(RoundedCornerShape(11.dp)).background(androidx.compose.ui.graphics.Color.Black.copy(alpha = 0.55f)).clickable(enabled = enabled, onClick = onRemove),
            contentAlignment = Alignment.Center,
        ) { Icon(Icons.Rounded.Close, contentDescription = stringResource(R.string.common_remove), tint = androidx.compose.ui.graphics.Color.White, modifier = Modifier.size(14.dp)) }
    }
}

private fun intListSaver() = listSaver<SnapshotStateList<Int>, Int>(save = { it.toList() }, restore = { saved -> mutableStateListOf<Int>().apply { addAll(saved) } })

private fun stringListSaver() = listSaver<SnapshotStateList<String>, String>(save = { it.toList() }, restore = { saved -> mutableStateListOf<String>().apply { addAll(saved) } })
