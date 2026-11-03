package com.dorr.app.ui.screens.watchearn

import android.content.Context
import android.media.MediaMetadataRetriever
import android.net.Uri
import android.provider.OpenableColumns
import android.widget.VideoView
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideOutHorizontally
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowForward
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.CloudUpload
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Image
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.Link
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.Videocam
import androidx.compose.material.icons.rounded.Warning
import androidx.compose.material3.DatePicker
import androidx.compose.material3.DatePickerDefaults
import androidx.compose.material3.DatePickerDialog
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.SelectableDates
import androidx.compose.material3.Text
import androidx.compose.material3.rememberDatePickerState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.WeAdDto
import com.dorr.app.network.WeAudienceRequest
import com.dorr.app.network.WeOptionsDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.locale.rememberAppLocaleFrame
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaDivider
import com.dorr.app.ui.screens.wallet.WaEmpty
import com.dorr.app.ui.screens.wallet.WaKeyValue
import com.dorr.app.ui.screens.wallet.WaNote
import com.dorr.app.ui.screens.wallet.WaPage
import com.dorr.app.ui.screens.wallet.WaSkeleton
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.asRequestBody
import java.io.File
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneOffset
import kotlin.math.ceil

/**
 * What the wizard has gathered so far. It lives in the route ([WeRoute.Wizard]), so going to fund the ad wallet and
 * coming back finds every step as it was. [edit] = editing that ad: steps 1–3 only, and it goes back to the review.
 */
@Stable
class AdForm(val edit: WeAdDto? = null) {
    var step by mutableIntStateOf(0)
    /** +1 when the last move went forward, -1 back — the pages slide accordingly. */
    var direction by mutableIntStateOf(1)

    var mediaUri by mutableStateOf<Uri?>(null)
    var mediaFile by mutableStateOf<File?>(null)
    var mediaMime by mutableStateOf<String?>(null)
    var mediaType by mutableStateOf(edit?.media?.type)
    var mediaSeconds by mutableStateOf<Int?>(null)
    var mediaError by mutableStateOf<String?>(null)
    var inspecting by mutableStateOf(false)

    var title by mutableStateOf(edit?.title.orEmpty())
    var body by mutableStateOf(edit?.body.orEmpty())
    var link by mutableStateOf(edit?.linkUrl.orEmpty())

    val cities = mutableStateListOf<Int>().apply { edit?.audience?.cities?.let { addAll(it) } }
    /** null = everyone */
    var gender by mutableStateOf(edit?.audience?.gender)
    /** null = every language */
    var locale by mutableStateOf(edit?.audience?.locale)
    var people by mutableStateOf<Long?>(null)

    var budgetText by mutableStateOf("")
    var startDate by mutableStateOf<LocalDate>(LocalDate.now())
    var endDate by mutableStateOf<LocalDate>(LocalDate.now().plusDays(7))

    val isEdit: Boolean get() = edit != null
    val lastStep: Int get() = if (isEdit) 2 else 4

    /** What the preview shows: the picked file, or the ad's current one when editing. */
    val previewModel: Any? get() = mediaUri ?: edit?.media?.url?.let { ApiClient.mediaUrl(it) }
    val previewIsVideo: Boolean get() = mediaType == "video"
}

private fun isHttpsLink(text: String): Boolean {
    val t = text.trim()
    if (t.isEmpty()) return true
    return t.startsWith("https://", ignoreCase = true) && android.util.Patterns.WEB_URL.matcher(t).matches()
}

/**
 * Creating an ad (D16), five steps on a pager with a step indicator: the file, the text, the audience (with the live
 * «reaches about N people»), the budget and dates (with the free ad wallet balance, and a way to fund it), and the
 * review — then «send for review» and a success check. Editing reuses steps 1–3.
 */
@Composable
fun AdWizard(form: AdForm) {
    val host = LocalWe.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.we_error_generic)
    var options by remember { mutableStateOf<WeOptionsDto?>(null) }
    var optionsFailed by remember { mutableStateOf(false) }
    var reloadOptions by remember { mutableIntStateOf(0) }
    var sending by remember { mutableStateOf(false) }
    var sent by remember { mutableStateOf(false) }

    // The options (and the free balance) on every visit: coming back from funding shows the new balance.
    LaunchedEffect(reloadOptions) {
        optionsFailed = false
        options = runCatching { ApiClient.watchEarn.options(weAuth()).data }.getOrNull()
        if (options == null) optionsFailed = true
    }

    fun back() {
        if (form.step > 0) {
            form.direction = -1
            form.step--
        } else {
            host.pop()
        }
    }
    BackHandler(enabled = form.step > 0 && !sent) { back() }

    val opts = options
    val decimals = opts?.currency?.decimals ?: 2
    val budgetMinor = weParseAmount(form.budgetText, decimals)
    val needMinor = maxOf(budgetMinor ?: 0L, opts?.minAdWalletMinor ?: 0L)
    val walletShort = opts != null && budgetMinor != null && opts.freeMinor < needMinor

    val stepValid = when (form.step) {
        0 -> !form.inspecting && form.mediaError == null && (form.mediaFile != null || form.isEdit)
        1 -> form.title.trim().length in 2..120 && isHttpsLink(form.link)
        2 -> true
        3 -> opts != null && budgetMinor != null && budgetMinor >= opts.pricePerViewMinor.coerceAtLeast(1) &&
            !form.startDate.isBefore(LocalDate.now()) && form.endDate.isAfter(form.startDate) && !walletShort
        else -> true
    }

    suspend fun submit() {
        if (options == null) return
        sending = true
        try {
            val parts = buildParts(form, budgetMinor)
            if (form.isEdit) ApiClient.watchEarn.updateAd(weAuth(), form.edit!!.id, parts)
            else ApiClient.watchEarn.createAd(weAuth(), parts)
            sent = true
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            val failure = e.apiFailure()
            host.showToast(failure.message ?: generic, error = true)
            // Take the person to the step the problem is on.
            val goTo = when (failure.errorCode) {
                "watch_earn_bad_file", "watch_earn_file_too_big", "watch_earn_video_too_long", "watch_earn_video_length_unknown" -> 0
                "watch_earn_bad_cities" -> 2
                "watch_earn_insufficient_ad_wallet", "watch_earn_budget_too_small", "watch_earn_bad_dates" -> if (form.isEdit) null else 3
                else -> null
            }
            if (goTo != null && goTo != form.step) {
                form.direction = -1
                form.step = goTo
            }
            if (failure.errorCode == "watch_earn_insufficient_ad_wallet") reloadOptions++
        }
        sending = false
    }

    if (sent) {
        WaPage(title = stringResource(if (form.isEdit) R.string.we_edit_ad else R.string.we_create_ad), onBack = {}, showHeader = false, scroll = false) {
            Column(Modifier.fillMaxWidth().weight(1f), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
                WeSuccessCheck(onDone = {
                    scope.launch {
                        delay(900)
                        if (form.isEdit) host.pop() else host.replace(WeRoute.MyAds())
                    }
                })
                Spacer(Modifier.height(18.dp))
                Text(
                    stringResource(if (form.isEdit) R.string.we_edit_sent_title else R.string.we_sent_title),
                    color = Wa.Ink, fontSize = 20.sp, fontWeight = FontWeight.Black, textAlign = TextAlign.Center,
                )
                Text(
                    stringResource(R.string.we_sent_text), color = Wa.Mut, fontSize = 13.5.sp, textAlign = TextAlign.Center,
                    modifier = Modifier.padding(horizontal = 30.dp, vertical = 6.dp),
                )
            }
        }
        return
    }

    val stepTitles = if (form.isEdit) {
        listOf(R.string.we_step_file, R.string.we_step_text, R.string.we_step_audience)
    } else {
        listOf(R.string.we_step_file, R.string.we_step_text, R.string.we_step_audience, R.string.we_step_budget, R.string.we_step_review)
    }
    val last = form.step == form.lastStep

    WaPage(
        title = stringResource(if (form.isEdit) R.string.we_edit_ad else R.string.we_create_ad),
        onBack = { back() },
        scroll = false,
        ctaDock = true,
        ctaReserve = 0.dp,
        cta = {
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.CenterVertically) {
                if (form.step > 0) {
                    WaButton(stringResource(R.string.we_back), { back() }, style = WaButtonStyle.Ghost, modifier = Modifier.weight(0.4f))
                }
                WaButton(
                    text = stringResource(
                        when {
                            !last -> R.string.we_next
                            form.isEdit -> R.string.we_resubmit
                            else -> R.string.we_send_for_review
                        },
                    ),
                    onClick = {
                        if (!last) {
                            form.direction = 1
                            form.step++
                        } else {
                            scope.launch { submit() }
                        }
                    },
                    enabled = stepValid && opts != null,
                    loading = sending,
                    icon = if (last) Icons.AutoMirrored.Rounded.Send else Icons.AutoMirrored.Rounded.ArrowForward,
                    iconAfter = !last,
                    modifier = Modifier.weight(1f),
                )
            }
        },
    ) {
        StepIndicator(form.step, stepTitles)
        Spacer(Modifier.height(12.dp))
        when {
            opts == null && optionsFailed -> WaEmpty(
                Icons.Rounded.Warning, Tone.Red, stringResource(R.string.we_load_failed), stringResource(R.string.we_load_failed_hint),
                action = { WaButton(stringResource(R.string.we_retry), { reloadOptions++ }, modifier = Modifier.padding(horizontal = 40.dp)) },
            )
            opts == null -> Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                WaSkeleton(Modifier.fillMaxWidth().height(220.dp), RoundedCornerShape(22.dp))
                WaSkeleton(Modifier.fillMaxWidth().height(60.dp), RoundedCornerShape(16.dp))
            }
            !opts.enabled -> WaEmpty(Icons.Rounded.Videocam, Tone.Gray, stringResource(R.string.we_disabled_title), stringResource(R.string.we_disabled_text))
            else -> {
                val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
                AnimatedContent(
                    targetState = form.step,
                    transitionSpec = {
                        val sign = if ((form.direction > 0) != rtl) 1 else -1
                        (slideInHorizontally(tween(320, easing = FastOutSlowInEasing)) { it * sign } + fadeIn(tween(220))) togetherWith
                            (slideOutHorizontally(tween(280, easing = FastOutSlowInEasing)) { -it * sign } + fadeOut(tween(180)))
                    },
                    label = "wizardStep",
                    modifier = Modifier.weight(1f),
                ) { step ->
                    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(bottom = 120.dp)) {
                        when (step) {
                            0 -> FileStep(form, opts, context)
                            1 -> TextStep(form)
                            2 -> AudienceStep(form, opts)
                            3 -> BudgetStep(form, opts, budgetMinor, needMinor, walletShort) { host.push(WeRoute.AdWallet(fundMinor = (needMinor - opts.freeMinor).coerceAtLeast(1))) }
                            else -> ReviewStep(form, opts, budgetMinor)
                        }
                    }
                }
            }
        }
    }
}

/** The multipart body: the text, the audience, the dates and budget (creating only) and the file when one was picked. */
private fun buildParts(form: AdForm, budgetMinor: Long?): List<MultipartBody.Part> = buildList {
    add(MultipartBody.Part.createFormData("title", form.title.trim()))
    add(MultipartBody.Part.createFormData("body", form.body.trim()))
    add(MultipartBody.Part.createFormData("link_url", form.link.trim()))
    // No city = the whole country; an empty value says so on an edit too (it clears the old ones).
    if (form.cities.isEmpty()) add(MultipartBody.Part.createFormData("cities", ""))
    else form.cities.forEach { add(MultipartBody.Part.createFormData("cities[]", it.toString())) }
    add(MultipartBody.Part.createFormData("target_gender", form.gender.orEmpty()))
    add(MultipartBody.Part.createFormData("target_locale", form.locale.orEmpty()))
    if (!form.isEdit) {
        add(MultipartBody.Part.createFormData("starts_at", weServerDate(form.startDate)))
        add(MultipartBody.Part.createFormData("ends_at", weServerDate(form.endDate, endOfDay = true)))
        add(MultipartBody.Part.createFormData("budget_minor", (budgetMinor ?: 0L).toString()))
    }
    val file = form.mediaFile
    val mime = form.mediaMime
    if (file != null && mime != null) {
        add(MultipartBody.Part.createFormData("media", file.name, file.asRequestBody(mime.toMediaType())))
        form.mediaSeconds?.let { add(MultipartBody.Part.createFormData("media_seconds", it.toString())) }
    }
}

// ------------------------------------------------------------------------------- the step indicator

@Composable
private fun StepIndicator(step: Int, titles: List<Int>) {
    Column {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            titles.forEachIndexed { i, _ ->
                val done = i < step
                val now = i == step
                val bg by animateColorAsState(if (done || now) Wa.Red else Wa.Line, tween(300), label = "dot")
                val scale by animateFloatAsState(if (now) 1.15f else 1f, tween(300), label = "dotScale")
                Box(
                    Modifier.size((26 * scale).dp).clip(CircleShape).background(bg),
                    contentAlignment = Alignment.Center,
                ) {
                    if (done) Icon(Icons.Rounded.Check, null, tint = Color.White, modifier = Modifier.size(15.dp))
                    else Text((i + 1).toString(), color = if (now) Color.White else Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.ExtraBold)
                }
                if (i < titles.lastIndex) {
                    val fill by animateFloatAsState(if (i < step) 1f else 0f, tween(400), label = "line")
                    Box(Modifier.weight(1f).padding(horizontal = 4.dp).height(3.dp).clip(RoundedCornerShape(2.dp)).background(Wa.Line)) {
                        Box(Modifier.fillMaxWidth(fill).height(3.dp).background(Wa.Red))
                    }
                }
            }
        }
        AnimatedContent(step, transitionSpec = { fadeIn(tween(220)) togetherWith fadeOut(tween(150)) }, label = "stepTitle") { s ->
            Text(
                stringResource(R.string.we_step_of, s + 1, titles.size) + " · " + stringResource(titles[s.coerceIn(0, titles.lastIndex)]),
                color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 10.dp),
            )
        }
    }
}

// ------------------------------------------------------------------------------- 1. the file

@Composable
private fun FileStep(form: AdForm, opts: WeOptionsDto, context: Context) {
    val scope = rememberCoroutineScope()
    val badType = stringResource(R.string.we_file_bad_type)
    val tooBig = stringResource(R.string.we_file_too_big)
    val tooLong = stringResource(R.string.we_file_too_long, opts.media.videoMaxSeconds)
    val unreadable = stringResource(R.string.we_file_unreadable)
    val picker = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri ->
        if (uri == null) return@rememberLauncherForActivityResult
        form.inspecting = true
        scope.launch {
            // Checked on the phone first: the type, the size and a video's length (the server checks again).
            val result = withContext(Dispatchers.IO) { inspectMedia(context, uri, opts) }
            form.inspecting = false
            form.mediaError = when (result.problem) {
                MediaProblem.Type -> badType
                MediaProblem.Size -> tooBig.format(result.limitMb)
                MediaProblem.Length -> tooLong
                MediaProblem.Unreadable -> unreadable
                null -> null
            }
            if (result.problem == null) {
                form.mediaUri = uri
                form.mediaFile = result.file
                form.mediaMime = result.mime
                form.mediaType = if (result.video) "video" else "image"
                form.mediaSeconds = result.seconds
            }
        }
    }

    Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.fillMaxWidth()) {
        PhoneFrame(form, Modifier.width(210.dp)) {
            if (form.previewModel == null) {
                Column(
                    Modifier.fillMaxSize().clickable { picker.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageAndVideo)) },
                    horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center,
                ) {
                    Icon(Icons.Rounded.CloudUpload, null, tint = Color.White.copy(alpha = 0.85f), modifier = Modifier.size(40.dp))
                    Spacer(Modifier.height(8.dp))
                    Text(stringResource(R.string.we_pick_file), color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center)
                }
            }
        }
        Spacer(Modifier.height(14.dp))
        WaButton(
            stringResource(if (form.previewModel == null) R.string.we_pick_file else R.string.we_change_file),
            { picker.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageAndVideo)) },
            style = WaButtonStyle.Ghost,
            icon = Icons.Rounded.Image,
            loading = form.inspecting,
            compact = true,
            modifier = Modifier.padding(horizontal = 30.dp),
        )
        AnimatedVisibility(form.mediaError != null, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
            Text(form.mediaError.orEmpty(), color = Wa.Danger, fontSize = 12.5.sp, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 10.dp))
        }
        Spacer(Modifier.height(12.dp))
        WaNote(stringResource(R.string.we_file_rules, opts.media.videoMaxSeconds, opts.media.videoMaxMb), icon = Icons.Rounded.Info)
        if (form.isEdit) {
            Spacer(Modifier.height(8.dp))
            WaNote(stringResource(R.string.we_edit_note), icon = Icons.Rounded.Info)
        }
    }
}

private enum class MediaProblem { Type, Size, Length, Unreadable }

private class MediaCheck(
    val problem: MediaProblem? = null,
    val file: File? = null,
    val mime: String? = null,
    val video: Boolean = false,
    val seconds: Int? = null,
    val limitMb: Int = 0,
)

/** Type, size and length on the device, then a copy in the cache to upload. */
private fun inspectMedia(context: Context, uri: Uri, opts: WeOptionsDto): MediaCheck {
    val resolver = context.contentResolver
    val mime = resolver.getType(uri)?.lowercase() ?: return MediaCheck(MediaProblem.Type)
    val video = mime in opts.media.videoMimes
    if (!video && mime !in opts.media.imageMimes) return MediaCheck(MediaProblem.Type)
    val size = runCatching {
        resolver.query(uri, arrayOf(OpenableColumns.SIZE), null, null, null)?.use { c -> if (c.moveToFirst() && !c.isNull(0)) c.getLong(0) else null }
    }.getOrNull()
    val limitMb = if (video) opts.media.videoMaxMb else 10
    if (size != null && size > limitMb * 1024L * 1024L) return MediaCheck(MediaProblem.Size, limitMb = limitMb)
    var seconds: Int? = null
    if (video) {
        val ms = runCatching {
            MediaMetadataRetriever().run {
                try {
                    setDataSource(context, uri)
                    extractMetadata(MediaMetadataRetriever.METADATA_KEY_DURATION)?.toLongOrNull()
                } finally {
                    release()
                }
            }
        }.getOrNull() ?: return MediaCheck(MediaProblem.Unreadable)
        seconds = ceil(ms / 1000.0).toInt().coerceAtLeast(1)
        if (seconds > opts.media.videoMaxSeconds) return MediaCheck(MediaProblem.Length)
    }
    val ext = when (mime) {
        "image/png" -> "png"
        "image/webp" -> "webp"
        "video/quicktime" -> "mov"
        "video/webm" -> "webm"
        "video/mp4" -> "mp4"
        else -> "jpg"
    }
    val file = File(context.cacheDir, "we_ad_${System.currentTimeMillis()}.$ext")
    val copied = runCatching {
        resolver.openInputStream(uri)?.use { input -> file.outputStream().use { input.copyTo(it) } } != null
    }.getOrDefault(false)
    if (!copied) return MediaCheck(MediaProblem.Unreadable)
    if (file.length() > limitMb * 1024L * 1024L) {
        file.delete()
        return MediaCheck(MediaProblem.Size, limitMb = limitMb)
    }
    return MediaCheck(file = file, mime = mime, video = video, seconds = seconds)
}

/** A phone-shaped frame with the file (or the ad as the viewer will see it) inside. */
@Composable
internal fun PhoneFrame(form: AdForm, modifier: Modifier = Modifier, overlay: @Composable () -> Unit = {}) {
    val shape = RoundedCornerShape(30.dp)
    Box(
        modifier
            .aspectRatio(9f / 18.5f)
            .shadow(18.dp, shape, ambientColor = Color(0x33111928), spotColor = Color(0x55111928))
            .clip(shape)
            .background(Color(0xFF0B0F19))
            .border(6.dp, Color(0xFF1F2937), shape),
    ) {
        val model = form.previewModel
        if (model != null) {
            if (form.previewIsVideo) {
                LoopingPreview(model.toString())
            } else {
                AsyncImage(model, null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
            }
        }
        // The notch.
        Box(Modifier.align(Alignment.TopCenter).padding(top = 10.dp).size(width = 54.dp, height = 6.dp).clip(RoundedCornerShape(3.dp)).background(Color(0xFF1F2937)))
        overlay()
    }
}

/** The picked video, silent and looping, inside the frame. */
@Composable
private fun LoopingPreview(url: String) {
    AndroidView(
        factory = { ctx ->
            VideoView(ctx).apply {
                tag = url
                setVideoURI(Uri.parse(url))
                setOnPreparedListener { mp ->
                    mp.isLooping = true
                    runCatching { mp.setVolume(0f, 0f) }
                    start()
                }
            }
        },
        // Only a new file restarts it (an update runs on every recomposition).
        update = { view ->
            if (view.tag != url) {
                view.tag = url
                view.setVideoURI(Uri.parse(url))
            }
        },
        onRelease = { it.stopPlayback() },
        modifier = Modifier.fillMaxSize(),
    )
}

// ------------------------------------------------------------------------------- 2. the text

@Composable
private fun TextStep(form: AdForm) {
    val linkBad = form.link.isNotBlank() && !isHttpsLink(form.link)
    val titleShort = form.title.isNotEmpty() && form.title.trim().length < 2
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        DorrTextField(
            value = form.title,
            onValueChange = { if (it.length <= 120) form.title = it },
            label = stringResource(R.string.we_ad_title),
            placeholder = stringResource(R.string.we_ad_title_hint),
            error = titleShort,
        )
        Text("${form.title.length} / 120", color = Wa.Soft, fontSize = 11.sp, modifier = Modifier.fillMaxWidth(), textAlign = TextAlign.End)
        DorrTextField(
            value = form.body,
            onValueChange = { if (it.length <= 2000) form.body = it },
            label = stringResource(R.string.we_ad_body),
            placeholder = stringResource(R.string.we_ad_body_hint),
            singleLine = false,
            minLines = 3,
        )
        DorrTextField(
            value = form.link,
            onValueChange = { form.link = it.trim() },
            label = stringResource(R.string.we_ad_link),
            placeholder = "https://",
            icon = Icons.Rounded.Link,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri),
            error = linkBad,
        )
        AnimatedVisibility(linkBad, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
            Text(stringResource(R.string.we_ad_link_bad), color = Wa.Danger, fontSize = 12.sp)
        }
        if (form.isEdit) WaNote(stringResource(R.string.we_edit_note), icon = Icons.Rounded.Info)
    }
}

// ------------------------------------------------------------------------------- 3. the audience

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun AudienceStep(form: AdForm, opts: WeOptionsDto) {
    var query by remember { mutableStateOf("") }
    val names = remember(opts.cities) { opts.cities.associate { it.id to it.name.orEmpty() } }

    // «Reaches about N people», asked again a moment after the choice changes (not on a timer).
    val citiesKey = form.cities.toList()
    LaunchedEffect(citiesKey, form.gender, form.locale) {
        delay(450)
        runCatching {
            ApiClient.watchEarn.audience(weAuth(), WeAudienceRequest(citiesKey.ifEmpty { null }, form.gender, form.locale)).data?.people
        }.getOrNull()?.let { form.people = it }
    }

    Column(Modifier.fillMaxWidth()) {
        // The live estimate on top.
        WaCard(Modifier.fillMaxWidth(), padding = 16.dp) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(42.dp).clip(RoundedCornerShape(14.dp)).background(Wa.Red.copy(alpha = 0.13f)), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.Groups, null, tint = Wa.Red, modifier = Modifier.size(22.dp))
                }
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.we_reaches_about), color = Wa.Mut, fontSize = 12.sp)
                    val people = form.people
                    if (people == null) WaSkeleton(Modifier.padding(top = 4.dp).width(90.dp).height(20.dp))
                    else Row(verticalAlignment = Alignment.Bottom) {
                        WeCountUp(people, { "%,d".format(java.util.Locale.US, it) }, fontSize = 22.sp, durationMs = 600)
                        Spacer(Modifier.width(6.dp))
                        Text(stringResource(R.string.we_people), color = Wa.Mut, fontSize = 13.sp, modifier = Modifier.padding(bottom = 3.dp))
                    }
                }
            }
        }

        Text(stringResource(R.string.we_cities), color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 18.dp, bottom = 8.dp))
        // Picked cities as chips that pop in and shrink away.
        if (form.cities.isEmpty()) {
            Text(stringResource(R.string.we_whole_country), color = Wa.Mut, fontSize = 12.5.sp, modifier = Modifier.padding(bottom = 8.dp))
        } else {
            FlowRow(Modifier.fillMaxWidth()) {
                form.cities.toList().forEach { id ->
                    androidx.compose.runtime.key(id) {
                        WeRemovableChip(names[id] ?: id.toString()) { form.cities.remove(id) }
                    }
                }
            }
        }
        DorrTextField(
            value = query,
            onValueChange = { query = it },
            placeholder = stringResource(R.string.we_search_city),
            icon = Icons.Rounded.Search,
        )
        val q = query.trim()
        val matches = opts.cities.filter { c -> c.id !in form.cities && (q.isEmpty() || c.name.orEmpty().contains(q, ignoreCase = true)) }.take(8)
        AnimatedVisibility(matches.isNotEmpty(), enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
            WaCard(Modifier.fillMaxWidth().padding(top = 8.dp)) {
                matches.forEachIndexed { i, c ->
                    Row(
                        Modifier.fillMaxWidth().clickable { form.cities.add(c.id); query = "" }.padding(horizontal = 14.dp, vertical = 11.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(c.name.orEmpty(), color = Wa.Ink, fontSize = 13.5.sp, modifier = Modifier.weight(1f))
                        Text("+", color = Wa.Red, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
                    }
                    if (i < matches.lastIndex) WaDivider()
                }
            }
        }
        if (opts.cities.isEmpty()) Text(stringResource(R.string.we_no_cities), color = Wa.Mut, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp))

        Text(stringResource(R.string.we_gender), color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 18.dp, bottom = 8.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            WeChip(stringResource(R.string.we_all), form.gender == null, { form.gender = null })
            WeChip(stringResource(R.string.we_gender_male), form.gender == "male", { form.gender = "male" })
            WeChip(stringResource(R.string.we_gender_female), form.gender == "female", { form.gender = "female" })
        }
        Text(stringResource(R.string.we_language), color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 18.dp, bottom = 8.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            WeChip(stringResource(R.string.we_all), form.locale == null, { form.locale = null })
            WeChip(stringResource(R.string.we_lang_ar), form.locale == "ar", { form.locale = "ar" })
            WeChip(stringResource(R.string.we_lang_en), form.locale == "en", { form.locale = "en" })
        }
        if (form.isEdit) {
            Spacer(Modifier.height(14.dp))
            WaNote(stringResource(R.string.we_edit_note), icon = Icons.Rounded.Info)
        }
    }
}

// ------------------------------------------------------------------------------- 4. the budget and the dates

@Composable
private fun BudgetStep(form: AdForm, opts: WeOptionsDto, budgetMinor: Long?, needMinor: Long, walletShort: Boolean, onFund: () -> Unit) {
    var picking by remember { mutableStateOf<Int?>(null) }
    val currency = opts.currency
    val views = if (budgetMinor != null && opts.pricePerViewMinor > 0) budgetMinor / opts.pricePerViewMinor else null
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        DorrTextField(
            value = form.budgetText,
            onValueChange = { form.budgetText = it.filter { ch -> ch.isDigit() || ch == '.' || ch == ',' } },
            label = stringResource(R.string.we_budget),
            placeholder = "0",
            icon = Icons.Rounded.AccountBalanceWallet,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
            error = form.budgetText.isNotBlank() && budgetMinor == null,
            trailing = { Text(currency.code.orEmpty(), color = Wa.Mut, fontSize = 13.sp, fontWeight = FontWeight.Bold) },
        )
        WaCard(Modifier.fillMaxWidth(), padding = 14.dp) {
            WaKeyValue(stringResource(R.string.we_price_per_view), weMoney(opts.pricePerViewMinor, currency), ltr = true)
            WaDivider()
            Row(Modifier.fillMaxWidth().padding(vertical = 11.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(stringResource(R.string.we_enough_for), color = Wa.Mut, fontSize = 13.5.sp, modifier = Modifier.weight(1f))
                if (views != null) WeCountUp(views, { stringResourceFree(it) }, fontSize = 14.sp, durationMs = 500)
                else Text("—", color = Wa.Ink, fontSize = 13.5.sp)
            }
            WaDivider()
            WaKeyValue(stringResource(R.string.we_free_balance), weMoney(opts.freeMinor, currency), ltr = true, bold = true, valueColor = if (walletShort) Wa.Danger else We.Withdrawable)
        }
        AnimatedVisibility(walletShort, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                WaNote(stringResource(R.string.we_wallet_short, weMoney(needMinor, currency)), icon = Icons.Rounded.Warning)
                WaButton(stringResource(R.string.we_fund_ad_wallet), onFund, style = WaButtonStyle.Ghost, icon = Icons.Rounded.AccountBalanceWallet, compact = true)
            }
        }
        if (opts.minAdWalletMinor > 0) {
            Text(stringResource(R.string.we_min_ad_wallet, weMoney(opts.minAdWalletMinor, currency)), color = Wa.Soft, fontSize = 11.5.sp)
        }

        Text(stringResource(R.string.we_dates), color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 6.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            DateBox(stringResource(R.string.we_starts), form.startDate, Modifier.weight(1f)) { picking = 0 }
            DateBox(stringResource(R.string.we_ends), form.endDate, Modifier.weight(1f)) { picking = 1 }
        }
        if (!form.endDate.isAfter(form.startDate)) Text(stringResource(R.string.we_dates_bad), color = Wa.Danger, fontSize = 12.sp)
    }

    when (picking) {
        0 -> WeDatePicker(initial = form.startDate, min = LocalDate.now(), onDismiss = { picking = null }) {
            form.startDate = it
            if (!form.endDate.isAfter(it)) form.endDate = it.plusDays(7)
            picking = null
        }
        1 -> WeDatePicker(initial = form.endDate, min = form.startDate.plusDays(1), onDismiss = { picking = null }) {
            form.endDate = it
            picking = null
        }
    }
}

/** "1,234" — used where the count is shown with its own word after it. */
private fun stringResourceFree(n: Long): String = "%,d".format(java.util.Locale.US, n)

@Composable
private fun DateBox(label: String, day: LocalDate, modifier: Modifier, onClick: () -> Unit) {
    val shape = RoundedCornerShape(16.dp)
    Row(
        modifier.clip(shape).background(Wa.Surface).border(1.dp, Wa.Line, shape).clickable(onClick = onClick).padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Rounded.CalendarMonth, null, tint = Wa.Red, modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(8.dp))
        Column {
            Text(label, color = Wa.Mut, fontSize = 11.5.sp)
            Text(weDate(day), color = Wa.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
    }
}

/** A day on Material's date picker, from [min] on, in the app's language. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun WeDatePicker(initial: LocalDate, min: LocalDate, onDismiss: () -> Unit, onPick: (LocalDate) -> Unit) {
    val locale = rememberAppLocaleFrame()
    val state = rememberDatePickerState(
        initialSelectedDateMillis = (if (initial.isBefore(min)) min else initial).atStartOfDay(ZoneOffset.UTC).toInstant().toEpochMilli(),
        selectableDates = object : SelectableDates {
            override fun isSelectableDate(utcTimeMillis: Long): Boolean {
                val day = Instant.ofEpochMilli(utcTimeMillis).atZone(ZoneOffset.UTC).toLocalDate()
                return !day.isBefore(min) && !day.isAfter(LocalDate.now().plusYears(2))
            }
        },
    )
    DatePickerDialog(
        onDismissRequest = onDismiss,
        colors = DatePickerDefaults.colors(containerColor = Wa.Surface),
        confirmButton = {
            locale.Provide {
                Text(
                    stringResource(R.string.we_ok), color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 14.sp,
                    modifier = Modifier.padding(end = 6.dp, bottom = 4.dp).clip(RoundedCornerShape(12.dp)).background(Wa.Red)
                        .clickable { state.selectedDateMillis?.let { onPick(Instant.ofEpochMilli(it).atZone(ZoneOffset.UTC).toLocalDate()) } }
                        .padding(horizontal = 18.dp, vertical = 10.dp),
                )
            }
        },
        dismissButton = {
            locale.Provide {
                Text(
                    stringResource(R.string.we_cancel), color = Wa.Ink, fontWeight = FontWeight.Bold, fontSize = 14.sp,
                    modifier = Modifier.padding(end = 6.dp, bottom = 4.dp).clip(RoundedCornerShape(12.dp)).clickable(onClick = onDismiss)
                        .padding(horizontal = 14.dp, vertical = 10.dp),
                )
            }
        },
    ) {
        DatePicker(state = state, showModeToggle = false)
    }
}

// ------------------------------------------------------------------------------- 5. the review

@Composable
private fun ReviewStep(form: AdForm, opts: WeOptionsDto, budgetMinor: Long?) {
    val currency = opts.currency
    val names = remember(opts.cities) { opts.cities.associate { it.id to it.name.orEmpty() } }
    val me = AuthSession.user?.name.orEmpty()
    Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
        Text(stringResource(R.string.we_review_hint), color = Wa.Mut, fontSize = 12.5.sp, textAlign = TextAlign.Center, modifier = Modifier.padding(bottom = 12.dp))
        // The ad as a viewer will see it.
        PhoneFrame(form, Modifier.width(200.dp)) {
            Column(Modifier.fillMaxSize()) {
                Row(
                    Modifier.fillMaxWidth().background(Brush.verticalGradient(listOf(Color.Black.copy(alpha = 0.55f), Color.Transparent))).padding(start = 12.dp, end = 12.dp, top = 22.dp, bottom = 14.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Box(Modifier.size(22.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.3f)), contentAlignment = Alignment.Center) {
                        Text(me.firstOrNull()?.toString().orEmpty(), color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.Bold)
                    }
                    Spacer(Modifier.width(6.dp))
                    Text(me, color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
                Spacer(Modifier.weight(1f))
                Column(
                    Modifier.fillMaxWidth().background(Brush.verticalGradient(listOf(Color.Transparent, Color.Black.copy(alpha = 0.7f)))).padding(start = 12.dp, end = 12.dp, top = 20.dp, bottom = 16.dp),
                ) {
                    Text(form.title.trim(), color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                    if (form.body.isNotBlank()) Text(form.body.trim(), color = Color.White.copy(alpha = 0.88f), fontSize = 10.sp, maxLines = 3, overflow = TextOverflow.Ellipsis)
                    if (form.link.isNotBlank()) {
                        Text(
                            stringResource(R.string.we_open_link), color = Color.Black, fontSize = 9.5.sp, fontWeight = FontWeight.Bold,
                            modifier = Modifier.padding(top = 6.dp).clip(RoundedCornerShape(99.dp)).background(Color.White).padding(horizontal = 8.dp, vertical = 3.dp),
                        )
                    }
                }
            }
        }
        Spacer(Modifier.height(16.dp))
        WaCard(Modifier.fillMaxWidth(), padding = 14.dp) {
            val cities = if (form.cities.isEmpty()) stringResource(R.string.we_whole_country) else form.cities.joinToString("، ") { names[it] ?: it.toString() }
            WaKeyValue(stringResource(R.string.we_cities), cities)
            WaDivider()
            WaKeyValue(
                stringResource(R.string.we_gender),
                stringResource(
                    when (form.gender) {
                        "male" -> R.string.we_gender_male
                        "female" -> R.string.we_gender_female
                        else -> R.string.we_all
                    },
                ),
            )
            WaDivider()
            WaKeyValue(
                stringResource(R.string.we_language),
                stringResource(
                    when (form.locale) {
                        "ar" -> R.string.we_lang_ar
                        "en" -> R.string.we_lang_en
                        else -> R.string.we_all
                    },
                ),
            )
            WaDivider()
            form.people?.let {
                WaKeyValue(stringResource(R.string.we_reaches_about), "%,d".format(java.util.Locale.US, it) + " " + stringResource(R.string.we_people))
                WaDivider()
            }
            WaKeyValue(stringResource(R.string.we_budget), weMoney(budgetMinor ?: 0L, currency), ltr = true, bold = true)
            if (budgetMinor != null && opts.pricePerViewMinor > 0) {
                WaKeyValue(stringResource(R.string.we_enough_for), "%,d".format(java.util.Locale.US, budgetMinor / opts.pricePerViewMinor) + " " + stringResource(R.string.we_views_word))
            }
            WaDivider()
            WaKeyValue(stringResource(R.string.we_dates), weDate(form.startDate) + " – " + weDate(form.endDate))
        }
        Spacer(Modifier.height(12.dp))
        WaNote(stringResource(R.string.we_review_note), icon = Icons.Rounded.Info)
    }
}
