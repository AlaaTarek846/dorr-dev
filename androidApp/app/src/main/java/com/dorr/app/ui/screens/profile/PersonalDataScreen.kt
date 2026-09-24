package com.dorr.app.ui.screens.profile

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material3.Button
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import com.dorr.app.R
import com.dorr.app.ui.components.SettingsScaffold
import com.dorr.app.ui.theme.AppColors

@Composable
fun PersonalDataScreen(onBack: () -> Unit, onSaved: (String) -> Unit) {
    var nameAr by remember { mutableStateOf("") }
    var nameEn by remember { mutableStateOf("") }
    var email by remember { mutableStateOf("") }
    val savedMessage = stringResource(R.string.personal_data_saved)

    SettingsScaffold(title = stringResource(R.string.personal_data_title), onBack = onBack) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(20.dp),
        ) {
            Box(
                modifier = Modifier
                    .align(Alignment.CenterHorizontally)
                    .size(88.dp)
                    .background(AppColors.primary.copy(alpha = 0.12f), CircleShape),
                contentAlignment = Alignment.Center,
            ) {
                Icon(Icons.Rounded.Person, contentDescription = null, tint = AppColors.primary, modifier = Modifier.size(48.dp))
            }
            Spacer(Modifier.height(24.dp))
            OutlinedTextField(
                value = nameAr,
                onValueChange = { nameAr = it },
                label = { Text(stringResource(R.string.personal_data_name_ar)) },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            Spacer(Modifier.height(12.dp))
            OutlinedTextField(
                value = nameEn,
                onValueChange = { nameEn = it },
                label = { Text(stringResource(R.string.personal_data_name_en)) },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            Spacer(Modifier.height(12.dp))
            OutlinedTextField(
                value = email,
                onValueChange = { email = it },
                label = { Text(stringResource(R.string.personal_data_email)) },
                singleLine = true,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
                modifier = Modifier.fillMaxWidth(),
            )
            Spacer(Modifier.height(28.dp))
            Button(
                onClick = { onSaved(savedMessage) },
                modifier = Modifier
                    .fillMaxWidth()
                    .height(50.dp),
            ) {
                Text(stringResource(R.string.common_save))
            }
        }
    }
}
