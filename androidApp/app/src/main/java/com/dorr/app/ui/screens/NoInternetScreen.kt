package com.dorr.app.ui.screens

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.isSystemInDarkTheme
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
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AirplanemodeInactive
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.SignalCellularAlt
import androidx.compose.material.icons.rounded.Wifi
import androidx.compose.material.icons.rounded.WifiOff
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
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
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.ui.locale.LocalAppLanguage
import com.dorr.app.ui.theme.AppColors
import com.dorr.app.ui.theme.LocalThemeState
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * Full-screen blocking view shown whenever there is no internet connection.
 * Consumes all pointer events to prevent interacting with the app behind it
 * until the network connection is restored.
 */
@Composable
fun NoInternetScreen(
    onRetry: suspend () -> Boolean,
    modifier: Modifier = Modifier,
) {
    val isArabic = LocalAppLanguage.current.isArabic
    val isDark = LocalThemeState.current.isDark ?: isSystemInDarkTheme()
    val scope = rememberCoroutineScope()
    var isChecking by remember { mutableStateOf(false) }
    var showStillOfflineMessage by remember { mutableStateOf(false) }

    val bgColor = if (isDark) AppColors.darkBackground else AppColors.background
    val cardBg = if (isDark) AppColors.darkSurface else AppColors.surface
    val cardBorder = if (isDark) AppColors.darkBorder.copy(alpha = 0.5f) else AppColors.border
    val textPrimaryColor = if (isDark) AppColors.darkTextPrimary else AppColors.textPrimary
    val textSecondaryColor = if (isDark) AppColors.darkTextSecondary else AppColors.textSecondary

    // Subtle pulsing animation on the offline halo
    val infiniteTransition = rememberInfiniteTransition(label = "pulse")
    val pulseScale by infiniteTransition.animateFloat(
        initialValue = 1f,
        targetValue = 1.12f,
        animationSpec = infiniteRepeatable(
            animation = tween(1400, easing = FastOutSlowInEasing),
            repeatMode = RepeatMode.Reverse,
        ),
        label = "pulseScale",
    )
    val haloAlpha by infiniteTransition.animateFloat(
        initialValue = 0.15f,
        targetValue = 0.35f,
        animationSpec = infiniteRepeatable(
            animation = tween(1400, easing = FastOutSlowInEasing),
            repeatMode = RepeatMode.Reverse,
        ),
        label = "haloAlpha",
    )

    Box(
        modifier = modifier
            .fillMaxSize()
            .background(bgColor)
            // Intercept all touches so nothing underneath receives clicks
            .pointerInput(Unit) {}
            .statusBarsPadding()
            .navigationBarsPadding(),
        contentAlignment = Alignment.Center,
    ) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 24.dp, vertical = 32.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.Center,
        ) {
            Box(
                modifier = Modifier
                    .widthIn(max = 440.dp)
                    .fillMaxWidth(),
                contentAlignment = Alignment.Center,
            ) {
                Column(
                    horizontalAlignment = Alignment.CenterHorizontally,
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    // Pulsing animated icon container
                    Box(
                        contentAlignment = Alignment.Center,
                        modifier = Modifier.size(140.dp),
                    ) {
                        // Outer pulsating ring
                        Box(
                            modifier = Modifier
                                .size(130.dp)
                                .scale(pulseScale)
                                .clip(CircleShape)
                                .background(
                                    Brush.radialGradient(
                                        colors = listOf(
                                            AppColors.waRed.copy(alpha = haloAlpha),
                                            AppColors.waRed.copy(alpha = 0f),
                                        ),
                                    ),
                                ),
                        )

                        // Middle soft ring
                        Box(
                            modifier = Modifier
                                .size(96.dp)
                                .clip(CircleShape)
                                .background(
                                    if (isDark) Color(0xFF3B1E22) else Color(0xFFFDE8E8)
                                )
                                .border(
                                    width = 2.dp,
                                    color = AppColors.waRed.copy(alpha = 0.3f),
                                    shape = CircleShape,
                                ),
                            contentAlignment = Alignment.Center,
                        ) {
                            Icon(
                                imageVector = Icons.Rounded.WifiOff,
                                contentDescription = if (isArabic) "لا يوجد إنترنت" else "No Internet",
                                tint = AppColors.waRed,
                                modifier = Modifier.size(46.dp),
                            )
                        }
                    }

                    Spacer(modifier = Modifier.height(24.dp))

                    // Title
                    Text(
                        text = if (isArabic) "لا يوجد اتصال بالإنترنت" else "No Internet Connection",
                        style = MaterialTheme.typography.headlineSmall.copy(
                            fontSize = 22.sp,
                            fontWeight = FontWeight.Bold,
                        ),
                        color = textPrimaryColor,
                        textAlign = TextAlign.Center,
                    )

                    Spacer(modifier = Modifier.height(10.dp))

                    // Subtitle description
                    Text(
                        text = if (isArabic) {
                            "يبدو أنك غير متصل بالإنترنت. يرجى التحقق من اتصال شبكة Wi-Fi أو بيانات الهاتف للمتابعة إلى التطبيق."
                        } else {
                            "You seem to be offline. Please check your Wi-Fi or cellular network connection to continue."
                        },
                        style = MaterialTheme.typography.bodyMedium.copy(
                            fontSize = 14.sp,
                            lineHeight = 22.sp,
                        ),
                        color = textSecondaryColor,
                        textAlign = TextAlign.Center,
                        modifier = Modifier.padding(horizontal = 12.dp),
                    )

                    Spacer(modifier = Modifier.height(28.dp))

                    // Helpful Tips Card
                    Column(
                        modifier = Modifier
                            .fillMaxWidth()
                            .clip(RoundedCornerShape(16.dp))
                            .background(cardBg)
                            .border(1.dp, cardBorder, RoundedCornerShape(16.dp))
                            .padding(16.dp),
                        verticalArrangement = Arrangement.spacedBy(14.dp),
                    ) {
                        TipItem(
                            icon = Icons.Rounded.Wifi,
                            title = if (isArabic) "شبكة Wi-Fi أو البيانات" else "Wi-Fi or Mobile Data",
                            desc = if (isArabic) "تأكد من تفعيل الاتصال بالإنترنت" else "Check that your network is active",
                            isDark = isDark,
                        )
                        TipItem(
                            icon = Icons.Rounded.AirplanemodeInactive,
                            title = if (isArabic) "وضع الطيران" else "Airplane Mode",
                            desc = if (isArabic) "تأكد من إيقاف تشغيل وضع الطيران" else "Ensure airplane mode is switched off",
                            isDark = isDark,
                        )
                    }

                    Spacer(modifier = Modifier.height(24.dp))

                    // Still offline notice
                    AnimatedVisibility(
                        visible = showStillOfflineMessage,
                        enter = fadeIn(animationSpec = tween(200)),
                        exit = fadeOut(animationSpec = tween(200)),
                    ) {
                        Box(
                            modifier = Modifier
                                .fillMaxWidth()
                                .padding(bottom = 12.dp)
                                .clip(RoundedCornerShape(12.dp))
                                .background(Color(0xFFFFEBEB))
                                .border(1.dp, Color(0xFFF87171), RoundedCornerShape(12.dp))
                                .padding(horizontal = 14.dp, vertical = 10.dp),
                            contentAlignment = Alignment.Center,
                        ) {
                            Text(
                                text = if (isArabic) "ما زال غير متصل بالإنترنت، يرجى المحاولة بعد قليل" else "Still offline. Please check connection and try again",
                                style = MaterialTheme.typography.bodySmall.copy(fontWeight = FontWeight.Medium),
                                color = Color(0xFFB91C1C),
                                textAlign = TextAlign.Center,
                            )
                        }
                    }

                    // Retry button
                    Button(
                        onClick = {
                            if (!isChecking) {
                                isChecking = true
                                showStillOfflineMessage = false
                                scope.launch {
                                    delay(400) // Brief touch feedback
                                    val isNowOnline = onRetry()
                                    isChecking = false
                                    if (!isNowOnline) {
                                        showStillOfflineMessage = true
                                    }
                                }
                            }
                        },
                        enabled = true,
                        modifier = Modifier
                            .fillMaxWidth()
                            .height(52.dp),
                        shape = RoundedCornerShape(14.dp),
                        colors = ButtonDefaults.buttonColors(
                            containerColor = AppColors.waRed,
                            contentColor = Color.White,
                            disabledContainerColor = AppColors.waRed.copy(alpha = 0.85f),
                            disabledContentColor = Color.White,
                        ),
                        elevation = ButtonDefaults.buttonElevation(defaultElevation = 2.dp),
                    ) {
                        if (isChecking) {
                            CircularProgressIndicator(
                                modifier = Modifier.size(22.dp),
                                color = Color.White,
                                strokeWidth = 2.5.dp,
                            )
                            Spacer(modifier = Modifier.width(10.dp))
                            Text(
                                text = if (isArabic) "جارٍ التحقق..." else "Checking...",
                                fontSize = 16.sp,
                                fontWeight = FontWeight.Bold,
                                color = Color.White,
                            )
                        } else {
                            Icon(
                                imageVector = Icons.Rounded.Refresh,
                                contentDescription = null,
                                tint = Color.White,
                                modifier = Modifier.size(20.dp),
                            )
                            Spacer(modifier = Modifier.width(8.dp))
                            Text(
                                text = if (isArabic) "إعادة المحاولة" else "Try Again",
                                fontSize = 16.sp,
                                fontWeight = FontWeight.Bold,
                                color = Color.White,
                            )
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun TipItem(
    icon: ImageVector,
    title: String,
    desc: String,
    isDark: Boolean,
) {
    val textPrimary = if (isDark) AppColors.darkTextPrimary else AppColors.textPrimary
    val textSecondary = if (isDark) AppColors.darkTextSecondary else AppColors.textSecondary
    val iconBg = if (isDark) Color(0xFF2C3440) else Color(0xFFF3F4F6)
    val iconTint = if (isDark) AppColors.primaryLight else AppColors.primary

    Row(
        modifier = Modifier.fillMaxWidth(),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            modifier = Modifier
                .size(40.dp)
                .clip(CircleShape)
                .background(iconBg),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                imageVector = icon,
                contentDescription = null,
                tint = iconTint,
                modifier = Modifier.size(20.dp),
            )
        }

        Spacer(modifier = Modifier.width(14.dp))

        Column(modifier = Modifier.weight(1f)) {
            Text(
                text = title,
                style = MaterialTheme.typography.bodyMedium.copy(fontWeight = FontWeight.SemiBold),
                color = textPrimary,
                fontSize = 14.sp,
            )
            Spacer(modifier = Modifier.height(2.dp))
            Text(
                text = desc,
                style = MaterialTheme.typography.bodySmall,
                color = textSecondary,
                fontSize = 12.sp,
            )
        }
    }
}
