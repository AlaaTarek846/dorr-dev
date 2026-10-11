package com.dorr.app.ui.screens.profile

import android.app.Activity
import android.content.Context
import android.content.ContextWrapper
import android.widget.Toast
import androidx.compose.foundation.gestures.detectHorizontalDragGestures
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Star
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.drawWithContent
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.clipRect
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalSoftwareKeyboardController
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.SubmitRatingRequest
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.components.DorrTextField
import kotlin.math.ceil
import com.google.android.play.core.ktx.launchReview
import com.google.android.play.core.ktx.requestReview
import com.google.android.play.core.review.ReviewManagerFactory
import kotlinx.coroutines.launch

@Composable
fun RateAppScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val keyboard = LocalSoftwareKeyboardController.current
    val scope = rememberCoroutineScope()
    // Quarter-star steps: 1, 1.25, 1.5 ... 5 (0 = not chosen yet).
    var stars by remember { mutableFloatStateOf(0f) }
    var comment by remember { mutableStateOf("") }
    var alreadyRated by remember { mutableStateOf(false) }
    var offeredStoreReview by remember { mutableStateOf(false) }
    var sending by remember { mutableStateOf(false) }
    val failed = stringResource(R.string.rate_failed)

    LaunchedEffect(Unit) {
        runCatching { ApiClient.ratings.mine("Bearer ${AuthSession.token.orEmpty()}") }
            .onSuccess { envelope ->
                envelope.data?.rating?.takeIf { envelope.data.rated }?.let {
                    stars = it.stars.toFloat()
                    comment = it.comment.orEmpty()
                    alreadyRated = true
                    if (it.stars >= 4.0) offeredStoreReview = true
                }
            }
    }
    val accent = settingsAccent()
    val pickStars = stringResource(R.string.rate_pick_stars)
    val thanks = stringResource(R.string.rate_thanks)
    val updated = stringResource(R.string.rate_updated)
    val commentLength = stringResource(R.string.rate_comment_length)

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize()) {
            SubHeader(stringResource(R.string.rate_title), onBack)
            Column(
                modifier = Modifier
                    .weight(1f)
                    .verticalScroll(rememberScrollState())
                    .padding(horizontal = 14.dp)
                    .padding(bottom = 24.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                Text(
                    stringResource(R.string.rate_headline),
                    color = settingsInk(),
                    fontSize = 22.sp,
                    lineHeight = 28.sp,
                    fontWeight = FontWeight.ExtraBold,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth().padding(top = 8.dp, bottom = 6.dp),
                )
                Text(
                    stringResource(R.string.rate_body),
                    color = settingsMut(),
                    fontSize = 14.sp,
                    textAlign = TextAlign.Center,
                    lineHeight = 20.sp,
                    modifier = Modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 4.dp),
                )
                Spacer(Modifier.height(18.dp))
                StarPicker(
                    value = stars,
                    enabled = true,
                    activeColor = accent,
                    idleColor = settingsMut().copy(alpha = 0.45f),
                    onChange = { stars = it },
                )
                Text(
                    when {
                        stars <= 0f -> stringResource(R.string.rate_hint)
                        stars < 1.5f -> stringResource(R.string.rate_label_1)
                        stars < 2.5f -> stringResource(R.string.rate_label_2)
                        stars < 3.5f -> stringResource(R.string.rate_label_3)
                        stars < 4.5f -> stringResource(R.string.rate_label_4)
                        else -> stringResource(R.string.rate_label_5)
                    } + if (stars > 0f) "  ·  ${formatStars(stars)}" else "",
                    color = settingsMut(),
                    fontSize = 13.sp,
                    modifier = Modifier.padding(top = 10.dp, bottom = 18.dp),
                )
                if (alreadyRated) {
                    Text(
                        stringResource(R.string.rate_already),
                        color = accent,
                        fontSize = 14.sp,
                        fontWeight = FontWeight.SemiBold,
                        textAlign = TextAlign.Center,
                        modifier = Modifier.fillMaxWidth().padding(bottom = 10.dp),
                    )
                }
                DorrTextField(
                    value = comment,
                    onValueChange = { if (it.length <= 300) comment = it },
                    label = stringResource(R.string.rate_comment_label),
                    placeholder = stringResource(R.string.rate_comment_hint),
                    icon = Icons.Rounded.Notes,
                    singleLine = false,
                    minLines = 4,
                    minHeight = 120.dp,
                )
                Text(
                    "${comment.trim().length} / 300",
                    color = settingsMut(),
                    fontSize = 12.sp,
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(top = 6.dp, bottom = 4.dp),
                )
                Spacer(Modifier.height(14.dp))
                SettingsPrimaryButton(
                    text = stringResource(if (alreadyRated) R.string.rate_update else R.string.rate_submit),
                    loading = sending,
                ) {
                    if (sending) return@SettingsPrimaryButton
                    if (stars < 1f) {
                        Toast.makeText(context, pickStars, Toast.LENGTH_SHORT).show()
                        return@SettingsPrimaryButton
                    }
                    val trimmed = comment.trim()
                    if (trimmed.isNotEmpty() && trimmed.length !in 5..300) {
                        Toast.makeText(context, commentLength, Toast.LENGTH_SHORT).show()
                        return@SettingsPrimaryButton
                    }
                    keyboard?.hide()
                    sending = true
                    val wasRated = alreadyRated
                    scope.launch {
                        runCatching {
                            ApiClient.ratings.submit(
                                "Bearer ${AuthSession.token.orEmpty()}",
                                SubmitRatingRequest(stars, trimmed),
                            )
                        }.onSuccess { envelope ->
                            alreadyRated = true
                            comment = envelope.data?.comment.orEmpty()
                            Toast.makeText(context, if (wasRated) updated else thanks, Toast.LENGTH_SHORT).show()
                            // Play only the first time this rating becomes 4–5 stars.
                            if (envelope.data?.promptStoreReview == true && !offeredStoreReview) {
                                offeredStoreReview = true
                                context.findActivity()?.let { launchStoreReview(it) }
                            }
                        }.onFailure {
                            Toast.makeText(context, it.apiFailure().message ?: failed, Toast.LENGTH_SHORT).show()
                        }
                        sending = false
                    }
                }
            }
        }
    }
}

private fun formatStars(value: Float): String =
    if (value % 1f == 0f) value.toInt().toString() else value.toString().trimEnd('0')

private val StarSize = 40.dp
private val StarGap = 6.dp

/**
 * Five stars that can be tapped or dragged to any quarter (1, 1.25, 1.5 ... 5). Each star is drawn
 * partly filled, and the fill follows the reading direction (right-to-left in Arabic).
 */
@Composable
private fun StarPicker(
    value: Float,
    enabled: Boolean,
    activeColor: Color,
    idleColor: Color,
    onChange: (Float) -> Unit,
) {
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    val density = LocalDensity.current
    val starPx = with(density) { StarSize.toPx() }
    val stridePx = with(density) { (StarSize + StarGap).toPx() }
    val totalPx = starPx + stridePx * 4

    fun valueAt(x: Float): Float {
        val pos = (if (rtl) totalPx - x else x).coerceIn(0f, totalPx)
        val index = (pos / stridePx).toInt().coerceAtMost(4)
        val within = ((pos - index * stridePx) / starPx).coerceIn(0f, 1f)
        return (index + ceil(within * 4f) / 4f).coerceIn(1f, 5f)
    }

    Row(
        horizontalArrangement = Arrangement.spacedBy(StarGap),
        modifier = Modifier
            .pointerInput(enabled, rtl) {
                if (enabled) detectTapGestures { onChange(valueAt(it.x)) }
            }
            .pointerInput(enabled, rtl) {
                if (enabled) detectHorizontalDragGestures { change, _ -> onChange(valueAt(change.position.x)) }
            },
    ) {
        (1..5).forEach { n ->
            val fill = (value - (n - 1)).coerceIn(0f, 1f)
            Box(Modifier.size(StarSize)) {
                Icon(Icons.Outlined.Star, contentDescription = null, tint = idleColor, modifier = Modifier.fillMaxSize())
                if (fill > 0f) {
                    Icon(
                        imageVector = Icons.Rounded.Star,
                        contentDescription = null,
                        tint = activeColor,
                        modifier = Modifier
                            .fillMaxSize()
                            .drawWithContent {
                                val w = size.width
                                clipRect(
                                    left = if (rtl) w * (1f - fill) else 0f,
                                    right = if (rtl) w else w * fill,
                                ) { this@drawWithContent.drawContent() }
                            },
                    )
                }
            }
        }
    }
}

private tailrec fun Context.findActivity(): Activity? = when (this) {
    is Activity -> this
    is ContextWrapper -> baseContext.findActivity()
    else -> null
}

/**
 * Google Play In-App Review. Play decides whether the sheet actually shows (quota, app not yet
 * published, sideloaded builds), so this is safe to call and silently does nothing when it can't.
 */
private suspend fun launchStoreReview(activity: Activity) {
    runCatching {
        val manager = ReviewManagerFactory.create(activity)
        manager.launchReview(activity, manager.requestReview())
    }
}
