package com.dorr.app.ui.screens.profile

import android.text.method.LinkMovementMethod
import android.widget.TextView
import androidx.activity.compose.BackHandler
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.clickable
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
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
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
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.text.HtmlCompat
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.LegalPageDto
import com.dorr.app.ui.theme.AppColors
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

private const val LEGAL_EXIT_ANIM_MS = 260

@Composable
fun PrivacyPolicyScreen(onBack: () -> Unit) {
    LegalContentScreen(
        legalType = "privacy",
        headerRes = R.string.account_privacy,
        titleRes = R.string.privacy_head,
        emptyRes = R.string.privacy_empty,
        icon = Icons.Rounded.Shield,
        onBack = onBack,
    )
}

@Composable
fun TermsConditionsScreen(onBack: () -> Unit) {
    LegalContentScreen(
        legalType = "term",
        headerRes = R.string.account_terms,
        titleRes = R.string.terms_head,
        emptyRes = R.string.terms_empty,
        icon = Icons.Rounded.Description,
        onBack = onBack,
    )
}

@Composable
private fun LegalContentScreen(
    legalType: String,
    headerRes: Int,
    titleRes: Int,
    emptyRes: Int,
    icon: ImageVector,
    onBack: () -> Unit,
) {
    var policy by remember { mutableStateOf<LegalPageDto?>(null) }
    var isLoading by remember { mutableStateOf(true) }
    var errorMessage by remember { mutableStateOf<String?>(null) }
    var isExiting by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()

    val exitProgress by animateFloatAsState(
        targetValue = if (isExiting) 1f else 0f,
        animationSpec = tween(LEGAL_EXIT_ANIM_MS, easing = FastOutSlowInEasing),
        label = "legalContentExit",
    )

    fun handleBack() {
        if (isExiting) return
        isExiting = true
        scope.launch {
            delay(LEGAL_EXIT_ANIM_MS.toLong())
            onBack()
        }
    }

    BackHandler { handleBack() }

    fun loadPolicy() {
        scope.launch {
            isLoading = true
            errorMessage = null
            try {
                val response = ApiClient.content.getLegalPage(type = legalType)
                policy = response.data
            } catch (e: Exception) {
                errorMessage = e.message
            } finally {
                isLoading = false
            }
        }
    }

    LaunchedEffect(Unit) {
        loadPolicy()
    }

    Box(
        Modifier
            .fillMaxSize()
            .graphicsLayer {
                alpha = 1f - exitProgress
                translationX = -exitProgress * size.width * 0.18f
            },
    ) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize()) {
            SubHeader(stringResource(headerRes)) { handleBack() }

            Box(
                modifier = Modifier
                    .weight(1f)
                    .fillMaxWidth(),
            ) {
                when {
                    isLoading -> {
                        Box(
                            modifier = Modifier
                                .fillMaxSize()
                                .padding(16.dp),
                            contentAlignment = Alignment.Center,
                        ) {
                            Column(
                                horizontalAlignment = Alignment.CenterHorizontally,
                                verticalArrangement = Arrangement.Center,
                            ) {
                                CircularProgressIndicator(
                                    color = settingsAccent(),
                                    strokeWidth = 2.5.dp,
                                    modifier = Modifier.size(32.dp),
                                )
                                Spacer(Modifier.height(12.dp))
                                Text(
                                    stringResource(R.string.content_loading),
                                    color = settingsMut(),
                                    fontSize = 13.sp,
                                )
                            }
                        }
                    }

                    errorMessage != null -> {
                        Column(
                            modifier = Modifier
                                .fillMaxWidth()
                                .padding(horizontal = 14.dp, vertical = 16.dp)
                                .settingsSurface(RoundedCornerShape(18.dp), 8.dp)
                                .padding(24.dp),
                            horizontalAlignment = Alignment.CenterHorizontally,
                        ) {
                            Text(
                                stringResource(R.string.content_error),
                                color = settingsInk(),
                                fontSize = 15.sp,
                                fontWeight = FontWeight.Bold,
                            )
                            Spacer(Modifier.height(12.dp))
                            Row(
                                modifier = Modifier
                                    .clip(RoundedCornerShape(12.dp))
                                    .clickable { loadPolicy() }
                                    .padding(horizontal = 16.dp, vertical = 8.dp),
                                verticalAlignment = Alignment.CenterVertically,
                            ) {
                                Icon(
                                    Icons.Rounded.Refresh,
                                    contentDescription = null,
                                    tint = settingsAccent(),
                                    modifier = Modifier.size(18.dp),
                                )
                                Spacer(Modifier.width(6.dp))
                                Text(
                                    stringResource(R.string.content_retry),
                                    color = settingsAccent(),
                                    fontWeight = FontWeight.Bold,
                                    fontSize = 13.5.sp,
                                )
                            }
                        }
                    }

                    else -> {
                        val rawContent = policy?.content?.trim()
                        Column(
                            modifier = Modifier
                                .verticalScroll(rememberScrollState())
                                .padding(horizontal = 14.dp)
                                .padding(top = 6.dp, bottom = 24.dp)
                                .fillMaxWidth()
                                .settingsSurface(RoundedCornerShape(18.dp), 8.dp)
                                .padding(16.dp),
                        ) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                PinkIcon(icon)
                                Spacer(Modifier.width(10.dp))
                                Text(
                                    stringResource(titleRes),
                                    color = settingsAccent(),
                                    fontSize = 18.sp,
                                    fontWeight = FontWeight.ExtraBold,
                                )
                            }

                            Spacer(Modifier.height(12.dp))

                            if (rawContent.isNullOrBlank()) {
                                Text(
                                    stringResource(emptyRes),
                                    fontSize = 13.sp,
                                    lineHeight = 24.sp,
                                    color = if (settingsNight()) settingsMut() else AppColors.textSecondary,
                                )
                            } else {
                                HtmlContent(
                                    html = rawContent,
                                    textColor = if (settingsNight()) settingsMut() else AppColors.textSecondary,
                                    accentColor = settingsAccent(),
                                )
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun HtmlContent(
    html: String,
    textColor: Color,
    accentColor: Color,
    modifier: Modifier = Modifier,
) {
    AndroidView(
        modifier = modifier.fillMaxWidth(),
        factory = { context ->
            TextView(context).apply {
                movementMethod = LinkMovementMethod.getInstance()
                setLineSpacing(0f, 1.45f)
                textSize = 13.5f
                linksClickable = true
                isClickable = true
            }
        },
        update = { textView ->
            textView.setTextColor(textColor.toArgb())
            textView.setLinkTextColor(accentColor.toArgb())
            textView.text = HtmlCompat.fromHtml(
                html,
                HtmlCompat.FROM_HTML_MODE_LEGACY,
            )
        },
    )
}
