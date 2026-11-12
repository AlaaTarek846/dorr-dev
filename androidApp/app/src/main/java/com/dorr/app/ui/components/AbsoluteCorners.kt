package com.dorr.app.ui.components

import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.LayoutDirection

/**
 * A rounded shape whose corners are named by the screen side (left/right), not by the reading side.
 * Chat bubbles sit on a fixed side (mine on the right, the other person on the left, in Arabic too),
 * so the sharp "tail" corner must follow the screen side as well.
 */
@Composable
fun absoluteCorners(topLeft: Dp, topRight: Dp, bottomRight: Dp, bottomLeft: Dp): RoundedCornerShape =
    if (LocalLayoutDirection.current == LayoutDirection.Rtl) {
        RoundedCornerShape(topStart = topRight, topEnd = topLeft, bottomEnd = bottomLeft, bottomStart = bottomRight)
    } else {
        RoundedCornerShape(topStart = topLeft, topEnd = topRight, bottomEnd = bottomRight, bottomStart = bottomLeft)
    }
