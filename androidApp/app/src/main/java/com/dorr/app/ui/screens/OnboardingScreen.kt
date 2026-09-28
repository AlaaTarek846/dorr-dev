package com.dorr.app.ui.screens

import androidx.activity.compose.BackHandler
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.pager.HorizontalPager
import androidx.compose.foundation.pager.rememberPagerState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowForward
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.ui.components.DotIndicator
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.theme.AppColors
import kotlinx.coroutines.launch

private data class OnboardingPage(
    val title: Int,
    val body: Int,
)

private val pages = listOf(
    OnboardingPage(R.string.onboarding_services_title, R.string.onboarding_services_body),
    OnboardingPage(R.string.onboarding_wallet_title, R.string.onboarding_wallet_body),
    OnboardingPage(R.string.onboarding_account_title, R.string.onboarding_account_body),
)

@Composable
fun OnboardingScreen(onFinished: () -> Unit) {
    val pagerState = rememberPagerState(pageCount = { pages.size })
    val scope = rememberCoroutineScope()
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    val page = pages[pagerState.currentPage]
    val lastPage = pagerState.currentPage == pages.lastIndex
    val cardShape = RoundedCornerShape(28.dp)
    val buttonShape = RoundedCornerShape(999.dp)
    val night = settingsNight()
    val nightBg = AccountDark.bg
    val nightGlow = AccountDark.accent
    val brand = settingsAccent()

    BackHandler(enabled = pagerState.currentPage > 0) {
        scope.launch {
            pagerState.animateScrollToPage(
                pagerState.currentPage - 1,
                animationSpec = tween(320, easing = FastOutSlowInEasing),
            )
        }
    }

    Box(
        modifier = Modifier
            .fillMaxSize()
            .drawBehind {
                if (night) {
                    drawRect(nightBg)
                    drawCircle(
                        brush = Brush.radialGradient(
                            colors = listOf(nightGlow.copy(alpha = 0.35f), nightGlow.copy(alpha = 0.10f), Color.Transparent),
                            center = Offset(size.width * 0.5f, size.height * -0.08f),
                            radius = size.width * 0.85f,
                        ),
                        radius = size.width * 0.85f,
                        center = Offset(size.width * 0.5f, size.height * -0.08f),
                    )
                    return@drawBehind
                }
                drawRect(Color.White)
                drawRect(
                    Brush.radialGradient(
                        colors = listOf(brand.copy(alpha = 0.28f), brand.copy(alpha = 0.08f), Color.Transparent),
                        center = Offset(size.width * -0.08f, size.height * -0.12f),
                        radius = size.width * 1.3f,
                    ),
                )
                drawRect(
                    Brush.radialGradient(
                        colors = listOf(AppColors.otpPinkBorder, Color.Transparent),
                        center = Offset(size.width * 0.5f, size.height * -0.18f),
                        radius = size.width * 1.1f,
                    ),
                )
                drawRect(
                    Brush.radialGradient(
                        colors = listOf(AppColors.otpGlowMist, Color.Transparent),
                        center = Offset(size.width * 1.12f, size.height * -0.08f),
                        radius = size.width * 0.9f,
                    ),
                )
            },
    ) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .navigationBarsPadding()
                .padding(bottom = 20.dp),
        ) {
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(horizontal = 16.dp, vertical = 10.dp),
                horizontalArrangement = Arrangement.End,
            ) {
                Row(
                    modifier = Modifier
                        .then(if (night) Modifier else Modifier.shadow(4.dp, RoundedCornerShape(999.dp), spotColor = Color(0x14111928)))
                        .clip(RoundedCornerShape(999.dp))
                        .background(if (night) AccountDark.card else Color.White)
                        .clickable(onClick = onFinished)
                        .padding(horizontal = 14.dp, vertical = 8.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(
                        text = stringResource(R.string.onboarding_skip),
                        color = if (night) AccountDark.mut else AppColors.textSecondary,
                        fontSize = 14.sp,
                        fontWeight = FontWeight.SemiBold,
                    )
                    Icon(
                        Icons.AutoMirrored.Rounded.ArrowForward,
                        contentDescription = null,
                        tint = if (night) AccountDark.mut else AppColors.textSecondary,
                        modifier = Modifier
                            .padding(start = 2.dp)
                            .size(16.dp),
                    )
                }
            }
            HorizontalPager(
                state = pagerState,
                reverseLayout = rtl,
                modifier = Modifier
                    .weight(1f)
                    .fillMaxWidth(),
            ) {
                Box(
                    modifier = Modifier
                        .fillMaxSize()
                        .padding(horizontal = 28.dp, vertical = 8.dp),
                    contentAlignment = Alignment.Center,
                ) {
                    Image(
                        painter = painterResource(R.drawable.payment),
                        contentDescription = null,
                        contentScale = ContentScale.Fit,
                        modifier = Modifier.fillMaxWidth(0.78f),
                    )
                }
            }
            Column(
                modifier = Modifier
                    .padding(horizontal = 18.dp)
                    .fillMaxWidth()
                    .then(
                        if (night) Modifier
                        else Modifier.shadow(
                            elevation = 18.dp,
                            shape = cardShape,
                            ambientColor = Color(0x33111928),
                            spotColor = Color(0x40111928),
                        ),
                    )
                    .clip(cardShape)
                    .background(if (night) AccountDark.card else Color.White)
                    .then(if (night) Modifier.border(1.dp, AccountDark.line, cardShape) else Modifier)
                    .padding(horizontal = 20.dp, vertical = 18.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                DotIndicator(count = pages.size, activeIndex = pagerState.currentPage)
                Spacer(Modifier.height(14.dp))
                Text(
                    text = highlightedTitle(stringResource(page.title)),
                    color = if (night) AccountDark.ink else AppColors.textPrimary,
                    fontSize = 22.sp,
                    fontWeight = FontWeight.ExtraBold,
                    textAlign = TextAlign.Center,
                    lineHeight = 30.sp,
                )
                Spacer(Modifier.height(8.dp))
                Text(
                    text = "\'${stringResource(page.body)}\'",
                    color = if (night) AccountDark.mut else AppColors.textSecondary,
                    fontSize = 14.sp,
                    lineHeight = 22.sp,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.widthIn(max = 280.dp),
                )
                Spacer(Modifier.height(16.dp))
                Button(
                    onClick = {
                        if (lastPage) {
                            onFinished()
                        } else {
                            scope.launch {
                                pagerState.animateScrollToPage(
                                    pagerState.currentPage + 1,
                                    animationSpec = tween(320, easing = FastOutSlowInEasing),
                                )
                            }
                        }
                    },
                    colors = ButtonDefaults.buttonColors(
                        containerColor = settingsAccent(),
                        contentColor = Color.White,
                    ),
                    shape = buttonShape,
                    modifier = Modifier
                        .fillMaxWidth()
                        .height(52.dp)
                        .shadow(
                            elevation = 8.dp,
                            shape = buttonShape,
                            ambientColor = Color(0x66E50914),
                            spotColor = Color(0x66E50914),
                        ),
                ) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(
                            text = stringResource(if (lastPage) R.string.onboarding_start else R.string.onboarding_next),
                            fontSize = 16.sp,
                            fontWeight = FontWeight.Bold,
                        )
                        Icon(
                            Icons.AutoMirrored.Rounded.ArrowForward,
                            contentDescription = null,
                            modifier = Modifier
                                .padding(start = 6.dp)
                                .size(18.dp),
                        )
                    }
                }
            }
        }
    }
}

private val titleHighlights = listOf("محفظتك", "محفظة", "wallet", "Wallet")

@Composable
private fun highlightedTitle(text: String) = buildAnnotatedString {
    val match = titleHighlights
        .mapNotNull { word -> text.indexOf(word).takeIf { it >= 0 }?.let { it to word } }
        .minByOrNull { it.first }
    if (match == null) {
        append(text)
        return@buildAnnotatedString
    }
    val (start, word) = match
    append(text.substring(0, start))
    withStyle(SpanStyle(color = settingsAccent())) {
        append(text.substring(start, start + word.length))
    }
    append(text.substring(start + word.length))
}
