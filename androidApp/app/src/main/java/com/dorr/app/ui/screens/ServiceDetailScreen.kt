package com.dorr.app.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ServiceChildDto
import com.dorr.app.network.ServiceDto
import com.dorr.app.ui.components.HtmlText
import com.dorr.app.ui.components.ServiceAvatar
import com.dorr.app.ui.components.serviceIcon
import com.dorr.app.ui.components.serviceMediaWellColor
import com.dorr.app.ui.components.sharedServiceImageLoader
import com.dorr.app.ui.screens.profile.PinkBackdrop
import com.dorr.app.ui.screens.profile.SubHeader
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.screens.profile.settingsSurface

fun ServiceChildDto.toServiceDto(): ServiceDto = ServiceDto(
    id = id,
    name = name,
    moduleName = moduleName,
    image = image,
    description = description,
    sortOrder = sortOrder,
    requiresProvider = null,
    hasChildren = false,
    children = null,
)

@Composable
fun ServiceDetailScreen(
    service: ServiceDto,
    accentColor: Color,
    onBack: () -> Unit,
    onOpenChild: (ServiceChildDto) -> Unit = {},
) {
    val night = settingsNight()
    val context = LocalContext.current
    val imageLoader = sharedServiceImageLoader(context)
    val children = service.children.orEmpty()
    val description = service.description?.takeIf { it.isNotBlank() }
        ?: stringResource(R.string.services_detail_description_fallback)

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.matchParentSize())
        Column(Modifier.fillMaxSize()) {
            SubHeader(service.name, onBack)
            LazyColumn(
                modifier = Modifier.fillMaxSize(),
                contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 8.dp, bottom = 32.dp),
                verticalArrangement = Arrangement.spacedBy(16.dp),
            ) {
                item {
                    val heroShape = RoundedCornerShape(22.dp)
                    Box(
                        modifier = Modifier
                            .fillMaxWidth()
                            .then(
                                if (night) Modifier
                                else Modifier.shadow(8.dp, heroShape, ambientColor = Color(0x12000000), spotColor = Color(0x12000000)),
                            )
                            .clip(heroShape)
                            .settingsSurface(heroShape),
                        contentAlignment = Alignment.Center,
                    ) {
                        if (service.image.isNullOrBlank()) {
                            Box(Modifier.padding(vertical = 28.dp)) {
                                ServiceAvatar(
                                    image = null,
                                    icon = serviceIcon(service.moduleName),
                                    color = accentColor,
                                    size = 120,
                                )
                            }
                        } else {
                            Box(
                                modifier = Modifier
                                    .fillMaxWidth()
                                    .background(serviceMediaWellColor(), heroShape)
                                    .padding(vertical = 24.dp, horizontal = 20.dp),
                                contentAlignment = Alignment.Center,
                            ) {
                                AsyncImage(
                                    model = ApiClient.mediaUrl(service.image),
                                    imageLoader = imageLoader,
                                    contentDescription = null,
                                    contentScale = ContentScale.Fit,
                                    modifier = Modifier
                                        .fillMaxWidth()
                                        .height(200.dp),
                                )
                            }
                        }
                    }
                }
                item {
                    Column(
                        modifier = Modifier
                            .fillMaxWidth()
                            .clip(RoundedCornerShape(18.dp))
                            .settingsSurface(RoundedCornerShape(18.dp))
                            .padding(horizontal = 18.dp, vertical = 16.dp),
                    ) {
                        Text(
                            stringResource(R.string.services_detail_about),
                            color = settingsAccent(),
                            fontWeight = FontWeight.Bold,
                            fontSize = 15.sp,
                        )
                        Spacer(Modifier.height(10.dp))
                        HtmlText(html = description, modifier = Modifier.fillMaxWidth())
                    }
                }
                if (children.isNotEmpty()) {
                    item {
                        Text(
                            stringResource(R.string.services_detail_subservices),
                            color = settingsInk(),
                            fontWeight = FontWeight.Bold,
                            fontSize = 16.sp,
                            modifier = Modifier.padding(start = 4.dp, top = 4.dp),
                        )
                    }
                    items(children, key = { it.id }) { child ->
                        ServiceChildDetailRow(
                            child = child,
                            accentColor = accentColor,
                            onClick = { onOpenChild(child) },
                        )
                    }
                }
                item {
                    Spacer(Modifier.height(4.dp))
                    val accent = if (night) AccountDark.accent else settingsAccent()
                    val pillShape = RoundedCornerShape(999.dp)
                    Box(
                        modifier = Modifier
                            .fillMaxWidth()
                            .clip(pillShape)
                            .background(
                                if (night) accent.copy(alpha = 0.14f)
                                else accent.copy(alpha = 0.08f),
                            )
                            .border(1.dp, accent.copy(alpha = if (night) 0.32f else 0.22f), pillShape)
                            .padding(vertical = 14.dp),
                        contentAlignment = Alignment.Center,
                    ) {
                        Text(
                            stringResource(R.string.services_coming_soon),
                            color = if (night) settingsInk() else accent.copy(alpha = 0.88f),
                            fontWeight = FontWeight.SemiBold,
                            fontSize = 14.sp,
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun ServiceChildDetailRow(
    child: ServiceChildDto,
    accentColor: Color,
    onClick: () -> Unit,
) {
    val night = settingsNight()
    val shape = RoundedCornerShape(16.dp)
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .then(
                if (night) Modifier
                else Modifier.shadow(4.dp, shape, ambientColor = Color(0x10001B53), spotColor = Color(0x10001B53)),
            )
            .clip(shape)
            .background(settingsCard())
            .border(1.dp, if (night) AccountDark.line else Color.Transparent, shape)
            .clickable(onClick = onClick)
            .padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        ServiceAvatar(child.image, serviceIcon(child.moduleName), accentColor, size = 48)
        Spacer(Modifier.width(12.dp))
        Text(
            child.name,
            style = MaterialTheme.typography.bodyLarge,
            fontWeight = FontWeight.SemiBold,
            color = settingsInk(),
            modifier = Modifier.weight(1f),
        )
        Icon(
            Icons.Rounded.ChevronRight,
            contentDescription = null,
            tint = if (night) AccountDark.chevron else settingsMut(),
            modifier = Modifier.size(20.dp),
        )
    }
}
