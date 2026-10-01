package com.dorr.app.ui.screens

import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.ui.unit.sp
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.activity.compose.BackHandler
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
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
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
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import com.dorr.app.R
import com.dorr.app.network.ServiceDto
import com.dorr.app.ui.components.ServiceAvatar
import com.dorr.app.ui.components.ServicesError
import com.dorr.app.ui.components.ServicesSkeleton
import com.dorr.app.ui.components.ServicesState
import com.dorr.app.ui.components.palette
import com.dorr.app.ui.components.rememberServicesLoader
import com.dorr.app.ui.components.serviceIcon
import com.dorr.app.ui.screens.profile.PinkBackdrop
import com.dorr.app.ui.screens.profile.SubHeader
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.theme.AppColors

/** Every service the dashboard exposes to the app, searchable, one clean row each. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ServicesScreen(
    onBack: () -> Unit,
    onOpenService: ((ServiceDto, Color) -> Unit)? = null,
) {
    val loader = rememberServicesLoader()
    var query by remember { mutableStateOf("") }
    var localDetail by remember { mutableStateOf<Pair<ServiceDto, Color>?>(null) }
    val night = settingsNight()

    val openDetail: (ServiceDto, Color) -> Unit = { service, color ->
        if (onOpenService != null) onOpenService(service, color)
        else localDetail = service to color
    }

    BackHandler(enabled = localDetail != null) { localDetail = null }

    localDetail?.let { (service, color) ->
        ServiceDetailScreen(
            service = service,
            accentColor = color,
            onBack = { localDetail = null },
            onOpenChild = { child -> localDetail = child.toServiceDto() to color },
        )
        return
    }

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.matchParentSize())
        Column(Modifier.fillMaxSize()) {
        SubHeader(stringResource(R.string.services_title), onBack)
        when (val state = loader.state) {
            ServicesState.Loading -> Column(Modifier.padding(16.dp)) { ServicesSkeleton() }
            ServicesState.Error -> Box(Modifier.padding(16.dp)) { ServicesError(onRetry = loader.reload) }
            is ServicesState.Loaded -> {
                val services = state.services
                val filtered = services.filter { it.name.contains(query.trim(), ignoreCase = true) }

                LazyColumn(
                    modifier = Modifier
                        .fillMaxSize()
                        .weight(1f),
                    contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 16.dp, bottom = 100.dp),
                    verticalArrangement = Arrangement.spacedBy(10.dp),
                ) {
                    item {
                        OutlinedTextField(
                            value = query,
                            onValueChange = { query = it },
                            singleLine = true,
                            placeholder = { Text(stringResource(R.string.services_search_hint), color = AppColors.textMuted) },
                            leadingIcon = { Icon(Icons.Rounded.Search, contentDescription = null, tint = AppColors.textMuted) },
                            shape = RoundedCornerShape(16.dp),
                            colors = OutlinedTextFieldDefaults.colors(
                                unfocusedBorderColor = if (night) AccountDark.line else AppColors.border,
                                focusedBorderColor = if (night) AccountDark.accent else settingsAccent(),
                                unfocusedContainerColor = settingsCard(),
                                focusedContainerColor = settingsCard(),
                                unfocusedTextColor = settingsInk(),
                                focusedTextColor = settingsInk(),
                                cursorColor = if (night) AccountDark.accent else settingsAccent(),
                            ),
                            modifier = Modifier.fillMaxWidth(),
                        )
                    }
                    item {
                        Text(
                            stringResource(R.string.services_count, filtered.size),
                            style = MaterialTheme.typography.bodySmall,
                            color = if (night) AccountDark.mut else AppColors.textSecondary,
                            modifier = Modifier.padding(start = 4.dp, top = 2.dp),
                        )
                    }
                    if (filtered.isEmpty()) {
                        item {
                            Text(
                                stringResource(R.string.services_no_results),
                                style = MaterialTheme.typography.bodyMedium,
                                color = if (night) AccountDark.mut else AppColors.textSecondary,
                                textAlign = TextAlign.Center,
                                modifier = Modifier
                                    .fillMaxWidth()
                                    .padding(vertical = 40.dp),
                            )
                        }
                    }
                    items(filtered, key = { it.id }) { service ->
                        // Same colour as on Home: keyed to the position in the full list.
                        val color = palette[services.indexOf(service) % palette.size]
                        ServiceRow(service, color) { openDetail(service, color) }
                    }
                }
            }
        }
        }
    }

}

@Composable
private fun ServiceRow(service: ServiceDto, color: Color, onClick: () -> Unit) {
    val childCount = service.children?.size ?: 0

    Row(
        modifier = Modifier
            .fillMaxWidth()
            .then(
                if (settingsNight()) Modifier
                else Modifier.shadow(6.dp, RoundedCornerShape(18.dp), ambientColor = Color(0x12E50914), spotColor = Color(0x12E50914)),
            )
            .clip(RoundedCornerShape(18.dp))
            .background(settingsCard())
            .border(1.dp, if (settingsNight()) AccountDark.line else Color.Transparent, RoundedCornerShape(18.dp))
            .clickable(onClick = onClick)
            .padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        ServiceAvatar(service.image, serviceIcon(service.moduleName), color, size = 52)
        Spacer(Modifier.width(14.dp))
        Column(modifier = Modifier.weight(1f)) {
            Text(
                service.name,
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.Bold,
                color = settingsInk(),
            )
            if (childCount > 0) {
                Spacer(Modifier.height(2.dp))
                Text(
                    stringResource(R.string.services_sub_count, childCount),
                    style = MaterialTheme.typography.bodySmall,
                    color = if (settingsNight()) AccountDark.mut else AppColors.textMuted,
                    fontWeight = FontWeight.SemiBold,
                )
            }
        }
        Icon(Icons.Rounded.ChevronRight, contentDescription = null, tint = if (settingsNight()) AccountDark.chevron else AppColors.otpGlowDeep, modifier = Modifier.size(22.dp))
    }
}
