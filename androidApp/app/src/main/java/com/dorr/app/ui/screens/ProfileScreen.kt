package com.dorr.app.ui.screens

import android.widget.Toast
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.slideInHorizontally
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Build
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.DarkMode
import androidx.compose.material.icons.rounded.DeleteForever
import androidx.compose.material.icons.rounded.Help
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.Language
import androidx.compose.material.icons.rounded.Logout
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import com.dorr.app.R
import com.dorr.app.network.AuthSession
import com.dorr.app.ui.locale.LocalAppLanguage
import com.dorr.app.ui.screens.profile.ContactUsSheet
import com.dorr.app.ui.screens.profile.FaqSheet
import com.dorr.app.ui.screens.profile.NotificationSettingsScreen
import com.dorr.app.ui.screens.profile.PersonalDataScreen
import com.dorr.app.ui.screens.profile.PrivacyPolicyScreen
import com.dorr.app.ui.theme.AppColors
import com.dorr.app.ui.theme.LocalThemeState

private enum class ProfileSub { NONE, PERSONAL_DATA, NOTIFICATIONS, PRIVACY }

private data class MenuEntry(
    val icon: ImageVector,
    val label: Int,
    val danger: Boolean = false,
    val trailing: (@Composable () -> Unit)? = null,
    val onClick: () -> Unit = {},
)

@Composable
fun ProfileScreen(onLogout: () -> Unit) {
    var subScreen by remember { mutableStateOf(ProfileSub.NONE) }

    when (subScreen) {
        ProfileSub.PERSONAL_DATA -> {
            val context = LocalContext.current
            PersonalDataScreen(
                onBack = { subScreen = ProfileSub.NONE },
                onSaved = { message -> Toast.makeText(context, message, Toast.LENGTH_SHORT).show() },
            )
        }
        ProfileSub.NOTIFICATIONS -> NotificationSettingsScreen(onBack = { subScreen = ProfileSub.NONE })
        ProfileSub.PRIVACY -> PrivacyPolicyScreen(onBack = { subScreen = ProfileSub.NONE })
        ProfileSub.NONE -> ProfileMenuScreen(
            onLogout = onLogout,
            onOpenPersonalData = { subScreen = ProfileSub.PERSONAL_DATA },
            onOpenNotifications = { subScreen = ProfileSub.NOTIFICATIONS },
            onOpenPrivacy = { subScreen = ProfileSub.PRIVACY },
        )
    }
}

@Composable
private fun ProfileMenuScreen(
    onLogout: () -> Unit,
    onOpenPersonalData: () -> Unit,
    onOpenNotifications: () -> Unit,
    onOpenPrivacy: () -> Unit,
) {
    val themeState = LocalThemeState.current
    val isDark = themeState.isDark ?: isSystemInDarkTheme()

    var showLogoutConfirm by remember { mutableStateOf(false) }
    var showDeleteConfirm by remember { mutableStateOf(false) }
    var showFaqSheet by remember { mutableStateOf(false) }
    var showContactSheet by remember { mutableStateOf(false) }
    var showAboutDialog by remember { mutableStateOf(false) }
    var showLanguageDialog by remember { mutableStateOf(false) }

    val menuItems = listOf(
        MenuEntry(Icons.Rounded.Person, R.string.account_personal_data, onClick = onOpenPersonalData),
        MenuEntry(Icons.Rounded.Notifications, R.string.account_notifications, onClick = onOpenNotifications),
        MenuEntry(Icons.Rounded.Language, R.string.account_language) { showLanguageDialog = true },
        MenuEntry(
            icon = Icons.Rounded.DarkMode,
            label = R.string.account_dark_mode,
            trailing = { Switch(checked = isDark, onCheckedChange = { themeState.isDark = it }) },
            onClick = { themeState.isDark = !isDark },
        ),
        MenuEntry(Icons.Rounded.Help, R.string.account_faqs) { showFaqSheet = true },
        MenuEntry(Icons.Rounded.Call, R.string.account_contact_us) { showContactSheet = true },
        MenuEntry(Icons.Rounded.Info, R.string.account_about) { showAboutDialog = true },
        MenuEntry(Icons.Rounded.Shield, R.string.account_privacy, onClick = onOpenPrivacy),
        MenuEntry(Icons.Rounded.Logout, R.string.account_logout, danger = true) { showLogoutConfirm = true },
        MenuEntry(Icons.Rounded.DeleteForever, R.string.account_delete, danger = true) { showDeleteConfirm = true },
    )

    LazyColumn(
        modifier = Modifier
            .fillMaxSize()
            .padding(20.dp),
    ) {
        item {
            Column(modifier = Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
                Box(
                    modifier = Modifier
                        .size(88.dp)
                        .clip(CircleShape)
                        .background(AppColors.primary.copy(alpha = 0.12f)),
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(Icons.Rounded.Person, contentDescription = null, tint = AppColors.primary, modifier = Modifier.size(48.dp))
                }
                Spacer(Modifier.height(12.dp))
                val user = AuthSession.user
                Text(
                    text = user?.takeIf { !it.name.isNullOrBlank() }?.name ?: stringResource(R.string.account_default_user),
                    style = MaterialTheme.typography.headlineMedium,
                )
                user?.phone?.let { phone ->
                    Spacer(Modifier.height(4.dp))
                    Text(
                        text = phone,
                        style = MaterialTheme.typography.bodyMedium,
                        color = AppColors.textSecondary,
                    )
                }
            }
            Spacer(Modifier.height(32.dp))
        }

        itemsIndexed(menuItems) { index, entry ->
            var visible by remember { mutableStateOf(false) }
            LaunchedEffect(Unit) { visible = true }
            AnimatedVisibility(
                visible = visible,
                enter = fadeIn(tween(350, delayMillis = index * 40)) +
                    slideInHorizontally(tween(350, delayMillis = index * 40)) { fullWidth -> -fullWidth / 3 },
            ) {
                MenuRow(entry)
            }
        }
    }

    if (showLogoutConfirm) {
        ConfirmDialog(
            title = stringResource(R.string.account_logout),
            message = stringResource(R.string.logout_confirm_message),
            onConfirm = { showLogoutConfirm = false; onLogout() },
            onDismiss = { showLogoutConfirm = false },
        )
    }
    if (showDeleteConfirm) {
        ConfirmDialog(
            title = stringResource(R.string.account_delete),
            message = stringResource(R.string.delete_confirm_message),
            onConfirm = { showDeleteConfirm = false; onLogout() },
            onDismiss = { showDeleteConfirm = false },
        )
    }
    if (showFaqSheet) FaqSheet(onDismiss = { showFaqSheet = false })
    if (showContactSheet) ContactUsSheet(onDismiss = { showContactSheet = false })
    if (showAboutDialog) AboutDialog(onDismiss = { showAboutDialog = false })
    if (showLanguageDialog) LanguageDialog(onDismiss = { showLanguageDialog = false })
}

@Composable
private fun MenuRow(entry: MenuEntry) {
    val tint = if (entry.danger) AppColors.danger else AppColors.textSecondary
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = entry.onClick)
            .padding(horizontal = 16.dp, vertical = 14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(entry.icon, contentDescription = null, tint = tint, modifier = Modifier.size(22.dp))
        Spacer(Modifier.width(14.dp))
        Text(
            stringResource(entry.label),
            style = MaterialTheme.typography.bodyLarge,
            color = if (entry.danger) AppColors.danger else MaterialTheme.colorScheme.onSurface,
            modifier = Modifier.weight(1f),
        )
        if (entry.trailing != null) {
            entry.trailing.invoke()
        } else {
            Icon(Icons.Rounded.ChevronRight, contentDescription = null, tint = tint, modifier = Modifier.size(20.dp))
        }
    }
}

@Composable
private fun ConfirmDialog(title: String, message: String, onConfirm: () -> Unit, onDismiss: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = { Text(message) },
        confirmButton = { TextButton(onClick = onConfirm) { Text(title) } },
        dismissButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.common_cancel)) } },
    )
}

@Composable
private fun AboutDialog(onDismiss: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        icon = {
            Box(
                modifier = Modifier
                    .size(56.dp)
                    .clip(RoundedCornerShape(12.dp))
                    .background(AppColors.primary.copy(alpha = 0.1f)),
                contentAlignment = Alignment.Center,
            ) {
                Icon(Icons.Rounded.Build, contentDescription = null, tint = AppColors.primary)
            }
        },
        title = { Text(stringResource(R.string.app_name)) },
        text = {
            Column {
                Text("v1.0.0", color = AppColors.textSecondary)
                Spacer(Modifier.height(8.dp))
                Text(stringResource(R.string.about_description))
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.common_close)) } },
    )
}

// Switches the app locale. LocalAppLanguage set() persists the choice and
// LocalizedApp (MainActivity) re-wraps the composition in a locale-aware
// Context, so every stringResource call and the RTL/LTR layout follow it
// immediately — no activity recreation needed.
@Composable
private fun LanguageDialog(onDismiss: () -> Unit) {
    val appLanguage = LocalAppLanguage.current
    var selectedArabic by remember(appLanguage.code) { mutableStateOf(appLanguage.isArabic) }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.language_title)) },
        text = {
            Column {
                LanguageOption(
                    label = stringResource(R.string.language_arabic),
                    selected = selectedArabic,
                    onClick = { selectedArabic = true; appLanguage.set("ar") },
                )
                LanguageOption(
                    label = stringResource(R.string.language_english),
                    selected = !selectedArabic,
                    onClick = { selectedArabic = false; appLanguage.set("en") },
                )
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.common_close)) } },
    )
}

@Composable
private fun LanguageOption(label: String, selected: Boolean, onClick: () -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick)
            .padding(vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        RadioButton(selected = selected, onClick = onClick)
        Spacer(Modifier.width(8.dp))
        Text(label)
    }
}
