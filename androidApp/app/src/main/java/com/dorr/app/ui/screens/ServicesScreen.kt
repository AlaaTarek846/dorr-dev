package com.dorr.app.ui.screens

import android.widget.Toast
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import com.dorr.app.R
import com.dorr.app.network.ServiceDto
import com.dorr.app.ui.components.ServiceAvatar
import com.dorr.app.ui.components.ServiceChildrenSheet
import com.dorr.app.ui.components.ServicesError
import com.dorr.app.ui.components.ServicesSkeleton
import com.dorr.app.ui.components.ServicesState
import com.dorr.app.ui.components.palette
import com.dorr.app.ui.components.rememberServicesLoader
import com.dorr.app.ui.components.serviceIcon
import com.dorr.app.ui.theme.AppColors

/** Every service the dashboard exposes to the app, searchable, one clean row each. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ServicesScreen(onBack: () -> Unit) {
    val loader = rememberServicesLoader()
    var query by remember { mutableStateOf("") }
    var opened by remember { mutableStateOf<Pair<ServiceDto, Color>?>(null) }
    val context = LocalContext.current
    val comingSoon = stringResource(R.string.services_coming_soon)

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(stringResource(R.string.services_title)) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = stringResource(R.string.common_back))
                    }
                },
            )
        },
    ) { padding ->
        when (val state = loader.state) {
            ServicesState.Loading -> Column(Modifier.padding(padding).padding(16.dp)) { ServicesSkeleton() }
            ServicesState.Error -> Box(Modifier.padding(padding).padding(16.dp)) { ServicesError(onRetry = loader.reload) }
            is ServicesState.Loaded -> {
                val services = state.services
                val filtered = services.filter { it.name.contains(query.trim(), ignoreCase = true) }

                LazyColumn(
                    modifier = Modifier
                        .fillMaxSize()
                        .padding(padding),
                    contentPadding = PaddingValues(16.dp),
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
                                unfocusedBorderColor = AppColors.border,
                                focusedBorderColor = AppColors.primary,
                            ),
                            modifier = Modifier.fillMaxWidth(),
                        )
                    }
                    item {
                        Text(
                            stringResource(R.string.services_count, filtered.size),
                            style = MaterialTheme.typography.bodySmall,
                            color = AppColors.textSecondary,
                            modifier = Modifier.padding(start = 4.dp, top = 2.dp),
                        )
                    }
                    if (filtered.isEmpty()) {
                        item {
                            Text(
                                stringResource(R.string.services_no_results),
                                style = MaterialTheme.typography.bodyMedium,
                                color = AppColors.textSecondary,
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
                        ServiceRow(service, color) {
                            if (service.hasChildren == true) {
                                opened = service to color
                            } else {
                                Toast.makeText(context, comingSoon, Toast.LENGTH_SHORT).show()
                            }
                        }
                    }
                }
            }
        }
    }

    opened?.let { (service, color) ->
        ServiceChildrenSheet(service = service, color = color, onDismiss = { opened = null })
    }
}

@Composable
private fun ServiceRow(service: ServiceDto, color: Color, onClick: () -> Unit) {
    val childCount = service.children?.size ?: 0

    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(18.dp))
            .background(color.copy(alpha = 0.06f))
            .border(1.dp, color.copy(alpha = 0.16f), RoundedCornerShape(18.dp))
            .clickable(onClick = onClick)
            .padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        ServiceAvatar(service.image, serviceIcon(service.moduleName), color, size = 52)
        Spacer(Modifier.width(14.dp))
        Column(modifier = Modifier.weight(1f)) {
            Text(service.name, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            if (childCount > 0) {
                Spacer(Modifier.height(2.dp))
                Text(
                    stringResource(R.string.services_sub_count, childCount),
                    style = MaterialTheme.typography.bodySmall,
                    color = color,
                    fontWeight = FontWeight.SemiBold,
                )
            }
        }
        Icon(Icons.Rounded.ChevronRight, contentDescription = null, tint = AppColors.textMuted, modifier = Modifier.size(22.dp))
    }
}
