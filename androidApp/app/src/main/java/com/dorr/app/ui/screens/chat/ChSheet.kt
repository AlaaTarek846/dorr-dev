package com.dorr.app.ui.screens.chat

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material3.BottomSheetDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.SheetState
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Shape
import androidx.compose.ui.res.stringResource
import com.dorr.app.ui.locale.rememberAppLocaleFrame
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import com.dorr.app.R
import com.dorr.app.ui.components.DorrLogo
import kotlinx.coroutines.launch

/*
 * The header and navigation rule, shared with DORR AI (spec, part 1 · "قاعدة الهيدر والملاحة"):
 * the chat's main screen has no back arrow; every inner screen has back at the start (pointing
 * the right way in RTL) with the DORR logo beside it; bottom sheets close with ×.
 */

/** Back, with the DORR logo beside it — the start of every inner chat screen's header (on the brand gradient). */
@Composable
internal fun ChBack(onBack: () -> Unit, size: Dp = 40.dp, logoWidth: Dp = 58.dp) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        GlassIcon(Icons.AutoMirrored.Rounded.ArrowBack, size = size, onClick = onBack)
        Spacer(Modifier.width(8.dp))
        DorrLogo(logoWidth, onDark = true)
    }
}

/** Every chat bottom sheet: Material's sheet, with a × beside the drag handle that closes it. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun ChSheet(
    onDismissRequest: () -> Unit,
    modifier: Modifier = Modifier,
    sheetState: SheetState = rememberModalBottomSheetState(),
    shape: Shape = BottomSheetDefaults.ExpandedShape,
    containerColor: Color = BottomSheetDefaults.ContainerColor,
    content: @Composable ColumnScope.() -> Unit,
) {
    val scope = rememberCoroutineScope()
    val locale = rememberAppLocaleFrame()
    ModalBottomSheet(
        onDismissRequest = onDismissRequest,
        modifier = modifier,
        sheetState = sheetState,
        shape = shape,
        containerColor = containerColor,
        dragHandle = {
            locale.Provide {
                val closeLabel = stringResource(R.string.ch_close_sheet)
                Box(Modifier.fillMaxWidth().padding(start = 14.dp, end = 14.dp, top = 10.dp, bottom = 2.dp)) {
                    Box(Modifier.align(Alignment.Center).size(width = 36.dp, height = 4.dp).clip(CircleShape).background(Ch.Soft.copy(alpha = 0.45f)))
                    Box(
                        Modifier.align(Alignment.CenterEnd).size(32.dp).clip(CircleShape).background(Ch.SurfaceMuted)
                            .clickable { scope.launch { sheetState.hide() }.invokeOnCompletion { onDismissRequest() } },
                        contentAlignment = Alignment.Center,
                    ) {
                        Icon(Icons.Rounded.Close, closeLabel, tint = Ch.Mut, modifier = Modifier.size(18.dp))
                    }
                }
            }
        },
        // A sheet is a window of its own: re-apply the app language or stringResource falls back to English.
        content = {
            val sheet = this
            locale.Provide { sheet.content() }
        },
    )
}
