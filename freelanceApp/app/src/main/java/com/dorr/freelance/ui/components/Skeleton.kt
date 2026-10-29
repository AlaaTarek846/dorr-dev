package com.dorr.freelance.ui.components

import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsSurface

/** A grey block that pulses softly: the placeholder of something still being read (instead of a spinner). */
@Composable
fun SkeletonBlock(modifier: Modifier = Modifier, height: Dp = 12.dp, radius: Dp = 8.dp) {
    val pulse by rememberInfiniteTransition(label = "skeleton").animateFloat(
        initialValue = 0.45f, targetValue = 1f,
        animationSpec = infiniteRepeatable(tween(800), RepeatMode.Reverse), label = "skeleton-alpha",
    )
    Spacer(modifier.height(height).graphicsLayer { alpha = pulse }.clip(RoundedCornerShape(radius)).background(settingsMut().copy(alpha = 0.18f)))
}

/** One list row while loading: a tile and three lines on a card. */
@Composable
fun SkeletonCard(modifier: Modifier = Modifier) {
    Row(modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp)).padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
        SkeletonBlock(Modifier.size(44.dp), height = 44.dp, radius = 14.dp)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            SkeletonBlock(Modifier.fillMaxWidth(0.7f), height = 14.dp)
            SkeletonBlock(Modifier.fillMaxWidth(0.45f), height = 11.dp)
            SkeletonBlock(Modifier.fillMaxWidth(0.9f), height = 11.dp)
        }
    }
}

/** A list page while its first page is read. */
@Composable
fun SkeletonList(count: Int = 4, modifier: Modifier = Modifier) {
    Column(modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(10.dp)) { repeat(count) { SkeletonCard() } }
}

/** A detail page while it is read: the headline card, two number tiles and two text cards. */
@Composable
fun SkeletonDetail(modifier: Modifier = Modifier) {
    Column(modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).padding(16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                SkeletonBlock(Modifier.size(44.dp), height = 44.dp, radius = 14.dp)
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    SkeletonBlock(Modifier.fillMaxWidth(0.8f), height = 16.dp)
                    SkeletonBlock(Modifier.fillMaxWidth(0.4f), height = 11.dp)
                }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                repeat(3) { SkeletonBlock(Modifier.weight(1f), height = 52.dp, radius = 14.dp) }
            }
        }
        repeat(2) {
            Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp)).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                SkeletonBlock(Modifier.fillMaxWidth(0.35f), height = 14.dp)
                SkeletonBlock(Modifier.fillMaxWidth(), height = 11.dp)
                SkeletonBlock(Modifier.fillMaxWidth(0.92f), height = 11.dp)
                SkeletonBlock(Modifier.fillMaxWidth(0.6f), height = 11.dp)
            }
        }
    }
}

/** A block of text lines while an article or a list of questions is read. */
@Composable
fun SkeletonLines(count: Int = 6, modifier: Modifier = Modifier) {
    Column(modifier.fillMaxWidth().padding(vertical = 4.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        SkeletonBlock(Modifier.fillMaxWidth(0.5f), height = 16.dp)
        repeat(count) { i -> SkeletonBlock(Modifier.fillMaxWidth(if (i == count - 1) 0.6f else 1f), height = 11.dp) }
    }
}
