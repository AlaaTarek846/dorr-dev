package com.dorr.freelance.ui.screens

import android.widget.Toast
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
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
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AddPhotoAlternate
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.EditNote
import androidx.compose.material.icons.rounded.ExpandMore
import androidx.compose.material.icons.rounded.Gavel
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.QuestionAnswer
import androidx.compose.material.icons.rounded.Sell
import androidx.compose.material.icons.rounded.VerifiedUser
import androidx.compose.material.icons.rounded.Work
import androidx.compose.material.icons.rounded.WorkOff
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.CommunityChange
import com.dorr.freelance.net.CommunityHomeDto
import com.dorr.freelance.net.CommunityHub
import com.dorr.freelance.net.CommunityPostDto
import com.dorr.freelance.net.CommunityPostUpdate
import com.dorr.freelance.net.PortfolioItemDto
import com.dorr.freelance.net.cursorCall
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.AvatarImage
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.EmptyState
import com.dorr.freelance.ui.components.SkeletonBlock
import com.dorr.freelance.ui.kit.AccountDark
import com.dorr.freelance.ui.kit.ConfirmDialog
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsCard
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsNight
import com.dorr.freelance.ui.kit.settingsSurface
import com.dorr.freelance.ui.locale.LocaleAwareBottomSheet
import java.io.File
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody

/*
 * FL-L12: writing a post (or editing mine). The kind (a post, a question, or a finished work from my portfolio), the text within the
 * server's limits, the specialty (my primary one first), up to 4 images from the gallery, and for a work the item from my portfolio. The
 * community rules show once. Can't write (not verified / muted) → a page saying why. Every field survives the system killing the app while
 * the gallery is open.
 */

private val pathsSaver = listSaver<SnapshotStateList<String>, String>(save = { it.toList() }, restore = { mutableStateListOf<String>().apply { addAll(it) } })
private val idsSaver = listSaver<SnapshotStateList<Int>, Int>(save = { it.toList() }, restore = { mutableStateListOf<Int>().apply { addAll(it) } })

/** [editUuid] null = a new post of [startKind]; else editing that post of mine (its text, specialty and images to remove). */
@Composable
fun CommunityComposeScreen(startKind: String, editUuid: String?, nav: CommunityNav, onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val home by CommunityHub.home.collectAsState()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val editing = editUuid != null

    var kind by rememberSaveable { mutableStateOf(startKind.takeIf { it in listOf("post", "question", "work") } ?: "post") }
    var body by rememberSaveable { mutableStateOf("") }
    var specialtyId by rememberSaveable { mutableStateOf<Int?>(null) }
    var specialtyTouched by rememberSaveable { mutableStateOf(false) }
    var workUuid by rememberSaveable { mutableStateOf<String?>(null) }
    val pending = rememberSaveable(saver = pathsSaver) { mutableStateListOf<String>() }
    val removed = rememberSaveable(saver = idsSaver) { mutableStateListOf<Int>() }
    var filled by rememberSaveable { mutableStateOf(false) }

    var original by remember { mutableStateOf<CommunityPostDto?>(null) }
    var loadFailed by remember { mutableStateOf<String?>(null) }
    var portfolio by remember { mutableStateOf<List<PortfolioItemDto>?>(null) }
    var busy by remember { mutableStateOf(false) }
    var inlineError by remember { mutableStateOf<String?>(null) }
    var showRules by remember { mutableStateOf(false) }
    var pickingSpecialty by remember { mutableStateOf(false) }
    var confirmLeave by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) { if (CommunityHub.home.value == null) CommunityHub.refresh() }
    // Editing: the post as it is now; its text fills the field once (not again after a rotation).
    LaunchedEffect(editUuid) {
        if (editUuid == null) return@LaunchedEffect
        when (val r = cursorCall { ApiClient.community.post(editUuid) }) {
            is ApiResult.Ok -> {
                original = r.data.data.post
                if (!filled) {
                    body = r.data.data.post.body
                    specialtyId = r.data.data.post.specialty?.id
                    kind = r.data.data.post.kind
                    filled = true
                }
            }
            else -> loadFailed = r.errorText(offlineText) ?: genericError
        }
    }
    // A new post starts on my primary specialty; the rules show the first time.
    LaunchedEffect(home) {
        val h = home ?: return@LaunchedEffect
        if (!editing && !specialtyTouched && specialtyId == null) {
            specialtyId = h.me.specialties.firstOrNull { it.primary }?.id ?: h.mySpecialties.firstOrNull()
        }
        if (h.canWrite && !editing && !CommunityHub.rulesSeen(context)) showRules = true
    }
    LaunchedEffect(kind) {
        if (kind == "work" && !editing && portfolio == null) {
            (apiCall { ApiClient.portfolio.list() } as? ApiResult.Ok)?.data?.let { list -> portfolio = list.filter { it.uuid != null && !it.hidden && !it.withdrawnByClient && it.visibility != "private" } }
                ?: run { portfolio = emptyList() }
        }
    }

    val limits = home?.limits
    val maxImages = limits?.maxImages ?: 4
    val existing = original?.images.orEmpty().filter { it.id !in removed }
    val imageCount = existing.size + pending.size
    val gallery = rememberLauncherForActivityResult(ActivityResultContracts.GetMultipleContents()) { uris ->
        val room = (maxImages - imageCount).coerceAtLeast(0)
        if (uris.size > room) Toast.makeText(context, context.getString(R.string.cm_images_max, maxImages), Toast.LENGTH_SHORT).show()
        uris.take(room).forEachIndexed { i, uri ->
            scope.launch {
                val file = withContext(Dispatchers.IO) { preparePortfolioImage(context, uri, "community_${System.currentTimeMillis()}_$i") }
                if (file == null) Toast.makeText(context, genericError, Toast.LENGTH_SHORT).show()
                else if (pending.size + existing.size < maxImages) pending.add(file.absolutePath)
            }
        }
    }

    val text = body.trim()
    val bodyMin = limits?.bodyMin ?: 10
    val bodyMax = limits?.bodyMax ?: 3000
    val postsLeft = limits?.let { (it.postsPerDay - it.postsToday).coerceAtLeast(0) }
    val dayFull = !editing && postsLeft == 0
    val ready = !busy && text.length in bodyMin..bodyMax && (editing || kind != "work" || workUuid != null) && !dayFull && (!editing || original != null)
    val dirty = text.isNotEmpty() || pending.isNotEmpty() || workUuid != null

    fun leave() {
        pending.forEach { File(it).delete() }
        onBack()
    }

    fun submit() {
        if (!ready) return
        busy = true
        inlineError = null
        scope.launch {
            val r = if (editUuid != null) {
                apiCall { ApiClient.community.update(editUuid, CommunityPostUpdate(body = text, specialtyId = specialtyId, removeImages = removed.toList().ifEmpty { null })) }
            } else {
                val plain = "text/plain".toMediaTypeOrNull()
                val fields = mutableMapOf<String, RequestBody>("kind" to kind.toRequestBody(plain), "body" to text.toRequestBody(plain))
                specialtyId?.let { fields["specialty_id"] = it.toString().toRequestBody(plain) }
                if (kind == "work") workUuid?.let { fields["portfolio_item"] = it.toRequestBody(plain) }
                val parts = pending.map(::File).filter { it.exists() }
                    .map { MultipartBody.Part.createFormData("images[]", it.name, it.asRequestBody("image/jpeg".toMediaTypeOrNull())) }
                apiCall { ApiClient.community.create(fields, parts) }
            }
            busy = false
            when (r) {
                is ApiResult.Ok -> {
                    pending.forEach { File(it).delete() }
                    pending.clear()
                    CommunityHub.emit(if (editing) CommunityChange.PostUpdated(r.data) else CommunityChange.PostCreated(r.data))
                    r.message?.takeIf { it.isNotBlank() }?.let { Toast.makeText(context, it, Toast.LENGTH_SHORT).show() }
                    launch { CommunityHub.refresh() }
                    onBack()
                }
                else -> {
                    val msg = r.errorText(offlineText) ?: genericError
                    // The server's refusals of what I wrote (contact details, too short, a bad work) show under the text; anything else as a toast.
                    if (r is ApiResult.Failed && r.error.httpStatus == 422) inlineError = msg else Toast.makeText(context, msg, Toast.LENGTH_SHORT).show()
                }
            }
        }
    }

    BackHandler(enabled = dirty && !busy && !editing) { confirmLeave = true }

    CommunityFrame(
        title = stringResource(if (editing) R.string.cm_edit_title else R.string.cm_new_title),
        onBack = { if (dirty && !editing) confirmLeave = true else onBack() },
        actions = {
            val h = home
            if (h == null || h.canWrite) {
                Box(
                    Modifier.height(36.dp).clip(RoundedCornerShape(999.dp)).background(if (ready) settingsAccent() else settingsAccent().copy(alpha = 0.35f))
                        .clickable(enabled = ready) { submit() }.padding(horizontal = 16.dp),
                    contentAlignment = Alignment.Center,
                ) {
                    if (busy) SmallSpinner(Color.White, 16.dp)
                    else Text(stringResource(if (editing) R.string.cm_save_changes else R.string.cm_publish), color = Color.White, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                }
            }
        },
    ) {
        val h = home
        when {
            h != null && !h.canWrite -> {
                CannotWrite(h, nav.openVerification)
                return@CommunityFrame
            }
            loadFailed != null && original == null -> {
                EmptyState(Icons.Rounded.Info, stringResource(R.string.cm_load_failed), loadFailed.orEmpty(), Modifier.padding(top = 80.dp))
                return@CommunityFrame
            }
            editing && original == null || h == null -> {
                Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    SkeletonBlock(Modifier.fillMaxWidth(), height = 64.dp, radius = 18.dp)
                    SkeletonBlock(Modifier.fillMaxWidth(), height = 80.dp, radius = 18.dp)
                    SkeletonBlock(Modifier.fillMaxWidth(), height = 200.dp, radius = 18.dp)
                }
                return@CommunityFrame
            }
        }
        val homeNow = h!!
        Column(
            Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 14.dp).padding(bottom = 28.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            // ---- me and the specialty
            Row(
                Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp), elevation = 3.dp).padding(12.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                AvatarImage(homeNow.me.avatarUrl, 46.dp)
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(5.dp)) {
                    NameWithBadge(homeNow.me.name, homeNow.me.badge)
                    val name = specialtyId?.let { id -> homeNow.specialties.firstOrNull { it.id == id }?.name ?: homeNow.me.specialties.firstOrNull { it.id == id }?.name }
                    Row(
                        Modifier.clip(RoundedCornerShape(999.dp)).border(1.dp, settingsAccent().copy(alpha = 0.5f), RoundedCornerShape(999.dp))
                            .clickable { pickingSpecialty = true }.padding(horizontal = 10.dp, vertical = 5.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(Icons.Rounded.Sell, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(14.dp))
                        Spacer(Modifier.width(5.dp))
                        Text(name ?: stringResource(R.string.cm_pick_specialty), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        Icon(Icons.Rounded.ExpandMore, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(16.dp))
                    }
                }
            }

            // ---- the kind
            if (editing) {
                original?.let { Row { KindPill(it.kind, it.kindLabel) } }
            } else {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    KindCard("post", Icons.Rounded.EditNote, settingsAccent(), stringResource(R.string.cm_kind_post), stringResource(R.string.cm_kind_post_hint), kind == "post", Modifier.weight(1f)) { kind = "post"; inlineError = null }
                    KindCard("question", Icons.Rounded.QuestionAnswer, QUESTION_TONE, stringResource(R.string.cm_kind_question), stringResource(R.string.cm_kind_question_hint), kind == "question", Modifier.weight(1f)) { kind = "question"; inlineError = null }
                    KindCard("work", Icons.Rounded.Work, WORK_TONE, stringResource(R.string.cm_kind_work), stringResource(R.string.cm_kind_work_hint), kind == "work", Modifier.weight(1f)) { kind = "work"; inlineError = null }
                }
            }

            // ---- the text
            Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                DorrTextField(
                    body, { body = it.take(bodyMax); inlineError = null },
                    placeholder = stringResource(
                        when (kind) {
                            "question" -> R.string.cm_body_hint_question
                            "work" -> R.string.cm_body_hint_work
                            else -> R.string.cm_body_hint_post
                        },
                    ),
                    singleLine = false, minLines = 7, error = inlineError != null,
                    modifier = Modifier.fillMaxWidth(),
                )
                Row(Modifier.fillMaxWidth().padding(horizontal = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                    if (text.length < bodyMin) Text(stringResource(R.string.cm_body_min, bodyMin), color = settingsMut(), fontSize = 11.5.sp, modifier = Modifier.weight(1f))
                    else Spacer(Modifier.weight(1f))
                    Text("\u200E${body.length} / $bodyMax", color = if (body.length >= bodyMax) Color(0xFFDC2626) else settingsMut(), fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold)
                }
                inlineError?.let { msg ->
                    Row(
                        Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(Color(0xFFDC2626).copy(alpha = 0.08f))
                            .border(1.dp, Color(0xFFDC2626).copy(alpha = 0.35f), RoundedCornerShape(14.dp)).padding(12.dp),
                        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp),
                    ) {
                        Icon(Icons.Rounded.Lock, contentDescription = null, tint = Color(0xFFDC2626), modifier = Modifier.size(18.dp))
                        Text(msg, color = Color(0xFFDC2626), fontSize = 13.sp, fontWeight = FontWeight.SemiBold, lineHeight = 19.sp)
                    }
                }
            }

            // ---- the work from my portfolio
            if (kind == "work") {
                if (editing) original?.work?.let { WorkCard(it) }
                else WorkPicker(portfolio, workUuid, onPick = { workUuid = it; inlineError = null }, onOpenPortfolio = nav.openPortfolio)
            }

            // ---- images
            Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp), elevation = 3.dp).padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Rounded.AddPhotoAlternate, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
                    Spacer(Modifier.width(6.dp))
                    Text(stringResource(R.string.cm_images_title), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                    Text("\u200E$imageCount / $maxImages", color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
                }
                ImageThumbs(
                    existing = existing.map { it }, pending = pending.toList(), canAdd = !editing && imageCount < maxImages, busy = busy,
                    onRemoveExisting = { removed.add(it) }, onRemovePending = { path -> pending.remove(path); File(path).delete() },
                    onAdd = { gallery.launch("image/*") },
                )
                if (editing) Text(stringResource(R.string.cm_images_edit_note), color = settingsMut(), fontSize = 11.5.sp)
            }

            // ---- the day's room and the rules
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                if (!editing && postsLeft != null) {
                    Text(
                        if (dayFull) stringResource(R.string.cm_day_full, limits.postsPerDay) else stringResource(R.string.cm_day_left, postsLeft),
                        color = if (dayFull) Color(0xFFDC2626) else settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f),
                    )
                } else Spacer(Modifier.weight(1f))
                Row(
                    Modifier.clip(RoundedCornerShape(999.dp)).clickable { showRules = true }.padding(horizontal = 8.dp, vertical = 6.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Rounded.Gavel, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(15.dp))
                    Spacer(Modifier.width(5.dp))
                    Text(stringResource(R.string.cm_rules_link), color = settingsAccent(), fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold)
                }
            }

            SettingsPrimaryButton(stringResource(if (editing) R.string.cm_save_changes else R.string.cm_publish), enabled = ready, loading = busy) { submit() }
        }
    }

    if (pickingSpecialty) {
        SpecialtySheet(home, specialtyId, allowAll = false, onPick = { specialtyId = it; specialtyTouched = true; pickingSpecialty = false }, onDismiss = { pickingSpecialty = false })
    }
    if (showRules) RulesSheet(home?.rules.orEmpty()) { CommunityHub.markRulesSeen(context); showRules = false }
    if (confirmLeave) {
        ConfirmDialog(
            icon = Icons.Rounded.Close, title = stringResource(R.string.cm_leave_title), message = stringResource(R.string.cm_leave_body),
            confirmLabel = stringResource(R.string.cm_leave_yes), onConfirm = { confirmLeave = false; leave() }, onDismiss = { confirmLeave = false },
        )
    }
}

@Composable
private fun KindCard(key: String, icon: ImageVector, tone: Color, title: String, hint: String, on: Boolean, modifier: Modifier, onClick: () -> Unit) {
    val night = settingsNight()
    Column(
        modifier.clip(RoundedCornerShape(16.dp))
            .background(if (on) tone.copy(alpha = if (night) 0.22f else 0.1f) else settingsCard())
            .border(if (on) 2.dp else 1.dp, if (on) tone else (if (night) AccountDark.line else Color(0xFFE5E7EB)), RoundedCornerShape(16.dp))
            .clickable(onClick = onClick).padding(horizontal = 8.dp, vertical = 12.dp),
        horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(5.dp),
    ) {
        Box(Modifier.size(36.dp).clip(CircleShape).background(tone.copy(alpha = if (on) 1f else 0.12f)), contentAlignment = Alignment.Center) {
            Icon(icon, contentDescription = key, tint = if (on) Color.White else tone, modifier = Modifier.size(19.dp))
        }
        Text(title, color = if (on) tone else settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
        Text(hint, color = settingsMut(), fontSize = 10.5.sp, lineHeight = 14.sp, textAlign = TextAlign.Center, maxLines = 2, overflow = TextOverflow.Ellipsis)
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun ImageThumbs(
    existing: List<com.dorr.freelance.net.CommunityImageDto>,
    pending: List<String>,
    canAdd: Boolean,
    busy: Boolean,
    onRemoveExisting: (Int) -> Unit,
    onRemovePending: (String) -> Unit,
    onAdd: () -> Unit,
) {
    val fill = if (settingsNight()) AccountDark.well else settingsAccent().copy(alpha = 0.06f)
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        existing.forEach { img ->
            Thumb(onRemove = if (busy) null else ({ onRemoveExisting(img.id) })) { CommunityPhoto(img, Modifier.fillMaxSize().background(fill)) }
        }
        pending.forEach { path ->
            Thumb(onRemove = if (busy) null else ({ onRemovePending(path) })) {
                AsyncImage(model = File(path), contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize().background(fill))
            }
        }
        if (canAdd) {
            Column(
                Modifier.size(92.dp).clip(RoundedCornerShape(14.dp)).background(fill)
                    .border(1.5.dp, settingsAccent().copy(alpha = 0.45f), RoundedCornerShape(14.dp)).clickable(enabled = !busy, onClick = onAdd),
                horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center,
            ) {
                Icon(Icons.Rounded.AddPhotoAlternate, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(26.dp))
                Spacer(Modifier.height(4.dp))
                Text(stringResource(R.string.cm_add_image), color = settingsAccent(), fontSize = 11.sp, fontWeight = FontWeight.Bold)
            }
        }
    }
}

@Composable
private fun Thumb(onRemove: (() -> Unit)?, content: @Composable () -> Unit) {
    Box(Modifier.size(92.dp).clip(RoundedCornerShape(14.dp))) {
        content()
        if (onRemove != null) {
            Box(
                Modifier.align(Alignment.TopEnd).padding(5.dp).size(24.dp).clip(CircleShape).background(Color.Black.copy(alpha = 0.6f)).clickable(onClick = onRemove),
                contentAlignment = Alignment.Center,
            ) { Icon(Icons.Rounded.Close, contentDescription = stringResource(R.string.common_remove), tint = Color.White, modifier = Modifier.size(15.dp)) }
        }
    }
}

/** For a «work» post: one item of my portfolio that others may see (not «only me», not hidden, not taken back by its client). */
@Composable
private fun WorkPicker(items: List<PortfolioItemDto>?, selected: String?, onPick: (String) -> Unit, onOpenPortfolio: () -> Unit) {
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp), elevation = 3.dp).padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.Work, contentDescription = null, tint = WORK_TONE, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(6.dp))
            Text(stringResource(R.string.cm_pick_work), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
        }
        when {
            items == null -> Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) { repeat(3) { SkeletonBlock(Modifier.size(width = 130.dp, height = 130.dp), height = 130.dp, radius = 14.dp) } }
            items.isEmpty() -> {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    Icon(Icons.Rounded.WorkOff, contentDescription = null, tint = settingsMut(), modifier = Modifier.size(28.dp))
                    Text(stringResource(R.string.cm_no_works), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp, modifier = Modifier.weight(1f))
                }
                PillButton(stringResource(R.string.cm_open_portfolio), Icons.Rounded.Work, filled = false, onClick = onOpenPortfolio)
            }
            else -> {
                Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    items.forEach { item ->
                        val on = item.uuid == selected
                        Column(
                            Modifier.width(130.dp).clip(RoundedCornerShape(14.dp))
                                .border(if (on) 2.dp else 1.dp, if (on) WORK_TONE else (if (settingsNight()) AccountDark.line else Color(0xFFE5E7EB)), RoundedCornerShape(14.dp))
                                .clickable { item.uuid?.let(onPick) },
                        ) {
                            Box(Modifier.fillMaxWidth().height(90.dp).background(WORK_TONE.copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
                                val cover = item.images.firstOrNull()?.path
                                if (cover != null) AsyncImage(model = ApiClient.url(cover), contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                                else Icon(Icons.Rounded.Work, contentDescription = null, tint = WORK_TONE, modifier = Modifier.size(28.dp))
                                if (on) {
                                    Box(Modifier.align(Alignment.TopEnd).padding(6.dp).size(22.dp).clip(CircleShape).background(Color.White), contentAlignment = Alignment.Center) {
                                        Icon(Icons.Rounded.CheckCircle, contentDescription = null, tint = WORK_TONE, modifier = Modifier.size(22.dp))
                                    }
                                }
                            }
                            Text(
                                item.title, color = settingsInk(), fontSize = 12.5.sp, fontWeight = FontWeight.Bold, maxLines = 2, overflow = TextOverflow.Ellipsis,
                                modifier = Modifier.padding(8.dp),
                            )
                        }
                    }
                }
                Text(stringResource(R.string.cm_works_note), color = settingsMut(), fontSize = 11.sp)
            }
        }
    }
}

/** Not verified, or muted by the staff until a date: reading stays open, writing waits. */
@Composable
private fun CannotWrite(home: CommunityHomeDto, onVerify: () -> Unit) {
    val muted = home.muted
    Column(Modifier.fillMaxSize().padding(horizontal = 24.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Spacer(Modifier.height(70.dp))
        EmptyState(
            if (muted != null) Icons.Rounded.Lock else Icons.Rounded.VerifiedUser,
            stringResource(R.string.cm_cannot_write_title),
            if (muted != null) stringResource(R.string.cm_muted_until, cmDate(muted.until)) + (muted.reason?.takeIf { it.isNotBlank() }?.let { "\n" + stringResource(R.string.cm_muted_reason, it) } ?: "")
            else stringResource(R.string.cm_need_verified_body),
        )
        if (muted == null) {
            Spacer(Modifier.height(18.dp))
            PillButton(stringResource(R.string.cm_verify_now), Icons.Rounded.VerifiedUser, filled = true, onClick = onVerify)
        }
    }
}

/** The community rules, from the server, shown once before the first post (and from «community rules» any time). */
@Composable
private fun RulesSheet(rules: List<String>, onDone: () -> Unit) {
    LocaleAwareBottomSheet(onDismissRequest = onDone, containerColor = settingsCard(), shape = RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 24.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                Box(Modifier.size(48.dp).clip(RoundedCornerShape(15.dp)).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.Gavel, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(24.dp))
                }
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.cm_rules_title), color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
                    Text(stringResource(R.string.cm_rules_sub), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp)
                }
            }
            rules.forEachIndexed { i, rule ->
                Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    Box(Modifier.size(24.dp).clip(CircleShape).background(settingsAccent()), contentAlignment = Alignment.Center) {
                        Text("${i + 1}", color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.ExtraBold)
                    }
                    Text(rule, color = settingsInk(), fontSize = 13.5.sp, lineHeight = 20.sp, modifier = Modifier.weight(1f))
                }
            }
            Spacer(Modifier.height(4.dp))
            SettingsPrimaryButton(stringResource(R.string.cm_rules_ok), onClick = onDone)
        }
    }
}
