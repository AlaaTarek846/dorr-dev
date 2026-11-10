package com.dorr.app.ui.screens

import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.animateDpAsState
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.pager.HorizontalPager
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.pager.rememberPagerState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.outlined.VerifiedUser
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.FilterQuality
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.res.imageResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.ui.screens.profile.PinkBackdrop
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.theme.appearanceColor
import kotlinx.coroutines.launch

/**
 * One onboarding page: its picture (res/drawable-nodpi, drawn from the SVGs in androidApp/design/onboarding/light),
 * the small label over the title, the title, its highlighted line (the accent colour) and the text.
 */
private data class OnboardingPage(
    val image: Int,
    val eyebrow: Int,
    val title: Int,
    val highlight: Int,
    val body: Int,
)

private val pages = listOf(
    OnboardingPage(R.drawable.onboarding_chat_ai, R.string.onb_chat_eyebrow, R.string.onb_chat_title, R.string.onb_chat_highlight, R.string.onb_chat_body),
    OnboardingPage(R.drawable.onboarding_rides_services, R.string.onb_rides_eyebrow, R.string.onb_rides_title, R.string.onb_rides_highlight, R.string.onb_rides_body),
    OnboardingPage(R.drawable.onboarding_wallet, R.string.onb_wallet_eyebrow, R.string.onb_wallet_title, R.string.onb_wallet_highlight, R.string.onb_wallet_body),
)

/**
 * Three swipeable pages: «‹ Skip» at the top, the picture large and sharp, then (no card) the small label, the title,
 * its highlighted line, the text, the «Next ‹» / «Start» button, the page dots and «A safe and secure experience».
 * The colours are the app's: the title and button in the primary colour (navy by day), the highlight and the active
 * dot in the accent (orange); by night the title is the light ink and the button the night primary.
 *
 * Every screen: the content is at most [ONBOARDING_MAX_WIDTH] wide, in the middle (a tablet or an open foldable looks
 * like a phone, the button isn't stretched); the picture takes about half the height (within 180–460dp), the skip row
 * stays at the top and the words / button at the bottom; when that doesn't fit (a phone on its side, a small phone
 * with a large font) the page scrolls instead of cutting anything.
 */
@Composable
fun OnboardingScreen(onFinished: () -> Unit) {
    val pagerState = rememberPagerState(pageCount = { pages.size })
    val scope = rememberCoroutineScope()
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    val lastPage = pagerState.currentPage == pages.lastIndex
    val buttonShape = RoundedCornerShape(999.dp)
    val night = settingsNight()
    val primary = settingsAccent()
    val accent = appearanceColor("accent", Color(0xFFFA7552), night = night)
    val ink = if (night) AccountDark.ink else primary
    val muted = AuthColors.mut(night)

    BackHandler(enabled = pagerState.currentPage > 0) {
        scope.launch {
            pagerState.animateScrollToPage(pagerState.currentPage - 1, animationSpec = tween(320, easing = FastOutSlowInEasing))
        }
    }

    BoxWithConstraints(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.matchParentSize())
        val screenHeight = maxHeight
        val pictureHeight = (screenHeight * 0.46f).coerceIn(180.dp, 460.dp)
        Column(
            modifier = Modifier
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .heightIn(min = screenHeight)
                .navigationBarsPadding()
                .padding(bottom = 14.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.SpaceBetween,
        ) {
            Row(
                modifier = Modifier
                    .widthIn(max = ONBOARDING_MAX_WIDTH)
                    .fillMaxWidth()
                    .padding(horizontal = 16.dp, vertical = 10.dp),
                // «Skip» at the start (the right in Arabic), small: the word, then a chevron on its right pointing
                // right — «تخطي ›» / «Skip ›» in both languages (the pill's inside is laid out left-to-right).
                horizontalArrangement = Arrangement.Start,
            ) {
                // The sign-in's language button's look: see-through on the backdrop, a soft frame, the word and the
                // chevron in the app's colour, bold.
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    val skipInk = if (night) AccountDark.ink else primary
                    Row(
                        modifier = Modifier
                            .height(34.dp)
                            .clip(RoundedCornerShape(999.dp))
                            .border(1.dp, AuthColors.line(night), RoundedCornerShape(999.dp))
                            .clickable(onClick = onFinished)
                            .padding(start = 12.dp, end = 8.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(stringResource(R.string.onboarding_skip), color = skipInk, fontSize = 13.sp, fontWeight = FontWeight.Bold)
                        Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, contentDescription = null, tint = skipInk, modifier = Modifier.size(18.dp))
                    }
                }
            }

            // The picture, large and sharp (drawn from the full-size PNG with high-quality filtering).
            HorizontalPager(
                state = pagerState,
                reverseLayout = rtl,
                modifier = Modifier
                    .widthIn(max = ONBOARDING_MAX_WIDTH)
                    .fillMaxWidth()
                    .height(pictureHeight),
            ) { index ->
                Box(Modifier.fillMaxSize().padding(horizontal = 20.dp), contentAlignment = Alignment.Center) {
                    Image(
                        bitmap = ImageBitmap.imageResource(pages[index].image),
                        contentDescription = null,
                        contentScale = ContentScale.Fit,
                        filterQuality = FilterQuality.High,
                        modifier = Modifier.fillMaxWidth(0.9f),
                    )
                }
            }

            Column(Modifier.widthIn(max = ONBOARDING_MAX_WIDTH).fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
            // The words under it — they fade to the next page's with the swipe.
            AnimatedContent(
                targetState = pagerState.currentPage,
                transitionSpec = { fadeIn(tween(260)) togetherWith fadeOut(tween(180)) },
                label = "onboardingText",
            ) { index ->
                val page = pages[index]
                Column(
                    Modifier.fillMaxWidth().padding(horizontal = 26.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                ) {
                    Text(stringResource(page.eyebrow), color = muted, fontSize = 13.5.sp, fontWeight = FontWeight.SemiBold, textAlign = TextAlign.Center)
                    Spacer(Modifier.height(6.dp))
                    Text(
                        stringResource(page.title), color = ink, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold,
                        textAlign = TextAlign.Center, lineHeight = 30.sp,
                    )
                    Text(
                        stringResource(page.highlight), color = accent, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold,
                        textAlign = TextAlign.Center, lineHeight = 30.sp,
                    )
                    Spacer(Modifier.height(10.dp))
                    Text(
                        stringResource(page.body), color = muted, fontSize = 14.sp, lineHeight = 22.sp,
                        textAlign = TextAlign.Center, modifier = Modifier.widthIn(max = 320.dp),
                    )
                }
            }

            Spacer(Modifier.height(20.dp))
            Button(
                onClick = {
                    if (lastPage) {
                        onFinished()
                    } else {
                        scope.launch {
                            pagerState.animateScrollToPage(pagerState.currentPage + 1, animationSpec = tween(320, easing = FastOutSlowInEasing))
                        }
                    }
                },
                colors = ButtonDefaults.buttonColors(containerColor = primary, contentColor = Color.White),
                shape = buttonShape,
                modifier = Modifier
                    .padding(horizontal = 20.dp)
                    .fillMaxWidth()
                    .height(52.dp)
                    .then(
                        if (night) Modifier
                        else Modifier.shadow(8.dp, buttonShape, ambientColor = primary.copy(alpha = 0.35f), spotColor = primary.copy(alpha = 0.35f)),
                    ),
            ) {
                // «التالي ›» / «Next ›»: the chevron on the word's right, pointing right — the way the next page comes in.
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(stringResource(if (lastPage) R.string.onb_start else R.string.onboarding_next), fontSize = 16.sp, fontWeight = FontWeight.Bold)
                        Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, contentDescription = null, modifier = Modifier.padding(start = 4.dp).size(20.dp))
                    }
                }
            }

            Spacer(Modifier.height(16.dp))
            OnboardingDots(count = pages.size, active = pagerState.currentPage, accent = accent, idle = if (night) AccountDark.line else AuthColors.soft(false).copy(alpha = 0.45f))

            Spacer(Modifier.height(14.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Outlined.VerifiedUser, contentDescription = null, tint = AuthColors.success(night), modifier = Modifier.size(15.dp))
                Spacer(Modifier.width(5.dp))
                Text(stringResource(R.string.onb_safe), color = muted, fontSize = 12.sp)
            }
            }
        }
    }
}

/** The onboarding content's widest: a phone's width, kept on tablets and open foldables. */
private val ONBOARDING_MAX_WIDTH = 480.dp

/**
 * The page dots: the current one a short pill in the accent colour, the others small grey dots. Laid out
 * left-to-right in both languages — the first page on the left — because the pages themselves come in from the right
 * (the pager is reversed under RTL), so the pill moves the same way as the pages.
 */
@Composable
private fun OnboardingDots(count: Int, active: Int, accent: Color, idle: Color) {
    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
            repeat(count) { i ->
                val on = i == active
                val width by animateDpAsState(if (on) 22.dp else 7.dp, tween(260), label = "dotWidth")
                val color by animateColorAsState(if (on) accent else idle, tween(260), label = "dotColor")
                Box(Modifier.size(width = width, height = 7.dp).clip(RoundedCornerShape(999.dp)).background(color))
            }
        }
    }
}
