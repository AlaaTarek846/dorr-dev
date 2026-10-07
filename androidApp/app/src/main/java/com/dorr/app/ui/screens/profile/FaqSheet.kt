package com.dorr.app.ui.screens.profile

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Help
import androidx.compose.material.icons.rounded.KeyboardArrowDown
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.ui.locale.LocaleAwareDialog
import androidx.compose.ui.window.DialogProperties
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.FaqDto
import kotlinx.coroutines.launch

@Composable
fun FaqSheet(onDismiss: () -> Unit) {
    var faqs by remember { mutableStateOf<List<FaqDto>?>(null) }
    var isLoading by remember { mutableStateOf(true) }
    var errorMessage by remember { mutableStateOf<String?>(null) }
    var searchQuery by remember { mutableStateOf("") }
    val openIds = remember { mutableStateListOf<Int>() }
    val scope = rememberCoroutineScope()

    fun loadFaqs() {
        scope.launch {
            isLoading = true
            errorMessage = null
            try {
                val response = ApiClient.content.getFaqs()
                faqs = response.data.orEmpty()
            } catch (e: Exception) {
                errorMessage = e.message
            } finally {
                isLoading = false
            }
        }
    }

    LaunchedEffect(Unit) {
        loadFaqs()
    }

    val filteredFaqs = remember(faqs, searchQuery) {
        val list = faqs.orEmpty()
        if (searchQuery.isBlank()) list
        else {
            val q = searchQuery.trim().lowercase()
            list.filter {
                it.question.lowercase().contains(q) || it.answer.lowercase().contains(q)
            }
        }
    }

    LocaleAwareDialog(
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
                    .heightIn(max = 620.dp)
                    .clip(RoundedCornerShape(topStart = 22.dp, topEnd = 22.dp))
                    .clickable(
                        interactionSource = remember { MutableInteractionSource() },
                        indication = null,
                        onClick = {},
                    ),
            ) {
                PinkBackdrop(Modifier.matchParentSize())
                Column(
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(horizontal = 14.dp)
                        .padding(top = 18.dp, bottom = 24.dp),
                ) {
                    // Header
                    Row(
                        verticalAlignment = Alignment.CenterVertically,
                        modifier = Modifier
                            .fillMaxWidth()
                            .padding(bottom = 12.dp),
                    ) {
                        PinkIcon(Icons.Rounded.Help)
                        Spacer(Modifier.width(10.dp))
                        SettingsScreenTitle(
                            stringResource(R.string.faq_title),
                            Modifier.weight(1f),
                        )
                        Box(
                            modifier = Modifier
                                .size(28.dp)
                                .clip(CircleShape)
                                .background(settingsInk().copy(alpha = 0.06f))
                                .clickable(onClick = onDismiss),
                            contentAlignment = Alignment.Center,
                        ) {
                            Icon(
                                Icons.Rounded.Close,
                                contentDescription = null,
                                tint = settingsMut(),
                                modifier = Modifier.size(16.dp),
                            )
                        }
                    }

                    // Search bar (if not loading and not empty)
                    if (!isLoading && errorMessage == null && !faqs.isNullOrEmpty()) {
                        Row(
                            modifier = Modifier
                                .fillMaxWidth()
                                .padding(bottom = 12.dp)
                                .settingsSurface(RoundedCornerShape(12.dp))
                                .padding(horizontal = 12.dp, vertical = 10.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(
                                Icons.Rounded.Search,
                                contentDescription = null,
                                tint = settingsMut(),
                                modifier = Modifier.size(18.dp),
                            )
                            Spacer(Modifier.width(8.dp))
                            BasicTextField(
                                value = searchQuery,
                                onValueChange = { searchQuery = it },
                                singleLine = true,
                                textStyle = TextStyle(
                                    fontSize = 13.5.sp,
                                    color = settingsInk(),
                                ),
                                cursorBrush = SolidColor(settingsAccent()),
                                modifier = Modifier.weight(1f),
                                decorationBox = { innerTextField ->
                                    if (searchQuery.isEmpty()) {
                                        Text(
                                            stringResource(R.string.faq_search_hint),
                                            color = settingsMut().copy(alpha = 0.7f),
                                            fontSize = 13.5.sp,
                                        )
                                    }
                                    innerTextField()
                                },
                            )
                            if (searchQuery.isNotEmpty()) {
                                Icon(
                                    Icons.Rounded.Close,
                                    contentDescription = null,
                                    tint = settingsMut(),
                                    modifier = Modifier
                                        .size(16.dp)
                                        .clickable { searchQuery = "" },
                                )
                            }
                        }
                    }

                    // Content Area
                    Box(
                        modifier = Modifier
                            .fillMaxWidth()
                            .weight(1f, fill = false),
                    ) {
                        when {
                            isLoading -> {
                                Box(
                                    modifier = Modifier
                                        .fillMaxWidth()
                                        .height(180.dp),
                                    contentAlignment = Alignment.Center,
                                ) {
                                    Column(
                                        horizontalAlignment = Alignment.CenterHorizontally,
                                        verticalArrangement = Arrangement.Center,
                                    ) {
                                        CircularProgressIndicator(
                                            color = settingsAccent(),
                                            strokeWidth = 2.5.dp,
                                            modifier = Modifier.size(30.dp),
                                        )
                                        Spacer(Modifier.height(10.dp))
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
                                        .padding(vertical = 16.dp)
                                        .settingsSurface(RoundedCornerShape(14.dp))
                                        .padding(20.dp),
                                    horizontalAlignment = Alignment.CenterHorizontally,
                                ) {
                                    Text(
                                        stringResource(R.string.content_error),
                                        color = settingsInk(),
                                        fontSize = 14.sp,
                                        fontWeight = FontWeight.Bold,
                                    )
                                    Spacer(Modifier.height(10.dp))
                                    Row(
                                        modifier = Modifier
                                            .clip(RoundedCornerShape(10.dp))
                                            .clickable { loadFaqs() }
                                            .padding(horizontal = 14.dp, vertical = 6.dp),
                                        verticalAlignment = Alignment.CenterVertically,
                                    ) {
                                        Icon(
                                            Icons.Rounded.Refresh,
                                            contentDescription = null,
                                            tint = settingsAccent(),
                                            modifier = Modifier.size(16.dp),
                                        )
                                        Spacer(Modifier.width(6.dp))
                                        Text(
                                            stringResource(R.string.content_retry),
                                            color = settingsAccent(),
                                            fontWeight = FontWeight.Bold,
                                            fontSize = 13.sp,
                                        )
                                    }
                                }
                            }

                            filteredFaqs.isEmpty() -> {
                                Box(
                                    modifier = Modifier
                                        .fillMaxWidth()
                                        .height(140.dp),
                                    contentAlignment = Alignment.Center,
                                ) {
                                    Text(
                                        if (searchQuery.isNotEmpty()) stringResource(R.string.faq_empty_search)
                                        else stringResource(R.string.faq_empty),
                                        color = settingsMut(),
                                        fontSize = 13.5.sp,
                                    )
                                }
                            }

                            else -> {
                                Column(
                                    modifier = Modifier
                                        .verticalScroll(rememberScrollState()),
                                ) {
                                    filteredFaqs.forEach { faq ->
                                        val isOpen = openIds.contains(faq.id)
                                        FaqRow(
                                            item = faq,
                                            isOpen = isOpen,
                                            onToggle = {
                                                if (isOpen) openIds.remove(faq.id)
                                                else openIds.add(faq.id)
                                            },
                                        )
                                        Spacer(Modifier.size(8.dp))
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun FaqRow(
    item: FaqDto,
    isOpen: Boolean,
    onToggle: () -> Unit,
) {
    val rotation by animateFloatAsState(
        targetValue = if (isOpen) 180f else 0f,
        label = "faq_chevron_rotation",
    )

    Column(
        modifier = Modifier
            .fillMaxWidth()
            .settingsSurface(RoundedCornerShape(14.dp))
            .clickable(onClick = onToggle)
            .padding(14.dp),
    ) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            PinkIcon(Icons.Rounded.Help)
            Spacer(Modifier.width(10.dp))
            Text(
                item.question,
                fontSize = 13.5.sp,
                fontWeight = FontWeight.Bold,
                color = settingsInk(),
                lineHeight = 19.sp,
                modifier = Modifier.weight(1f),
            )
            Icon(
                Icons.Rounded.KeyboardArrowDown,
                contentDescription = null,
                tint = if (settingsNight()) com.dorr.app.ui.screens.AccountDark.chevron else Color(0xFFF8BCA9),
                modifier = Modifier
                    .size(20.dp)
                    .graphicsLayer { rotationZ = rotation },
            )
        }

        AnimatedVisibility(
            visible = isOpen,
            enter = fadeIn() + expandVertically(),
            exit = fadeOut() + shrinkVertically(),
        ) {
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(top = 10.dp, start = 44.dp),
            ) {
                Text(
                    item.answer,
                    fontSize = 12.5.sp,
                    color = settingsMut(),
                    lineHeight = 20.sp,
                )
            }
        }
    }
}
