package com.dorr.app.ui.screens.profile

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.Help
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import com.dorr.app.R
import com.dorr.app.ui.theme.AppColors

private data class FaqItem(val question: Int, val answer: Int)

private val faqItems = listOf(
    FaqItem(R.string.faq_q1, R.string.faq_a1),
    FaqItem(R.string.faq_q2, R.string.faq_a2),
    FaqItem(R.string.faq_q3, R.string.faq_a3),
    FaqItem(R.string.faq_q4, R.string.faq_a4),
)

@Composable
fun FaqSheet(onDismiss: () -> Unit) {
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    Dialog(
        onDismissRequest = onDismiss,
        properties = DialogProperties(usePlatformDefaultWidth = false),
    ) {
        Box(
            modifier = Modifier
                .fillMaxSize()
                .background(Color.Black.copy(alpha = 0.45f))
                .clickable(onClick = onDismiss),
            contentAlignment = Alignment.BottomCenter,
        ) {
            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .heightIn(max = 560.dp)
                    .clip(RoundedCornerShape(topStart = 22.dp, topEnd = 22.dp))
                    .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null, onClick = {}),
            ) {
                PinkBackdrop(Modifier.matchParentSize())
                Column(
                    modifier = Modifier
                        .verticalScroll(rememberScrollState())
                        .padding(horizontal = 14.dp)
                        .padding(top = 18.dp, bottom = 24.dp),
                ) {
                Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(bottom = 12.dp)) {
                    PinkIcon(Icons.Rounded.Help)
                    Spacer(Modifier.width(10.dp))
                    Text(stringResource(R.string.faq_title), color = AppColors.waRed, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
                }
                faqItems.forEach { item ->
                    FaqRow(item, rtl)
                    Spacer(Modifier.size(8.dp))
                }
                }
            }
        }
    }
}

@Composable
private fun FaqRow(item: FaqItem, rtl: Boolean) {
    var open by remember { mutableStateOf(false) }
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .shadow(6.dp, RoundedCornerShape(14.dp), ambientColor = Color(0x12E50914), spotColor = Color(0x12E50914))
            .clip(RoundedCornerShape(14.dp))
            .background(Color.White)
            .clickable { open = !open }
            .padding(12.dp),
        verticalAlignment = Alignment.Top,
    ) {
        PinkIcon(Icons.Rounded.Help)
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(stringResource(item.question), fontSize = 13.5.sp, fontWeight = FontWeight.Bold, color = AppColors.textPrimary, lineHeight = 19.sp)
            if (open) {
                Text(
                    stringResource(item.answer),
                    fontSize = 12.sp,
                    color = AppColors.textSecondary,
                    lineHeight = 19.sp,
                    modifier = Modifier.padding(top = 6.dp),
                )
            }
        }
        Icon(
            Icons.Rounded.ChevronRight,
            contentDescription = null,
            tint = Color(0xFFEFA8B4),
            modifier = Modifier
                .padding(top = 2.dp)
                .size(16.dp)
                .graphicsLayer {
                    rotationZ = if (open) -90f else 0f
                    if (rtl) scaleX = -1f
                },
        )
    }
}
