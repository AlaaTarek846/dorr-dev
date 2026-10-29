package com.dorr.freelance.ui.screens

import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Public
import androidx.compose.material.icons.rounded.LocationCity
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.Badge
import androidx.compose.material.icons.rounded.Person
import android.Manifest
import android.content.pm.PackageManager
import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CameraAlt
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Email
import androidx.compose.material.icons.rounded.KeyboardArrowDown
import androidx.compose.material.icons.rounded.PhotoLibrary
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.runtime.saveable.listSaver
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.snapshots.SnapshotStateList
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.CodeRequest
import com.dorr.freelance.net.CountryDto
import com.dorr.freelance.net.EmailRequest
import com.dorr.freelance.net.LanguageDto
import com.dorr.freelance.net.Session
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.AvatarImage
import com.dorr.freelance.ui.components.AvatarVersion
import com.dorr.freelance.ui.components.ChipRow
import com.dorr.freelance.ui.components.DateField
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.MessageToast
import com.dorr.freelance.ui.kit.PinkBackdrop
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.SubHeader
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsCard
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsSurface
import com.dorr.freelance.ui.locale.LocalAppLanguage
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File

/**
 * Completing the profile (FL-103): photo (gallery or camera), name, headline, about, years, languages, country, city,
 * residency, gender, date of birth, and the email with its code. Everything but the name is optional here; the home shows
 * what is still missing. Nothing is asked on a timer: the lists load once when the page opens and the save is one request.
 * Nothing is required to leave the page; only saving needs a name of 2+ characters.
 */
@Composable
fun ProfileScreen(onBack: () -> Unit, onSaved: () -> Unit) {
    val context = LocalContext.current
    val savedText = stringResource(R.string.profile_saved)
    val scope = rememberCoroutineScope()
    val initial = remember { Session.me.value }
    val me by Session.me.collectAsState()
    val appLanguage = LocalAppLanguage.current
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val photoFailed = stringResource(R.string.profile_photo_failed)

    var name by rememberSaveable { mutableStateOf(initial?.name.orEmpty()) }
    var headline by rememberSaveable { mutableStateOf(initial?.headline.orEmpty()) }
    var bio by rememberSaveable { mutableStateOf(initial?.bio.orEmpty()) }
    var years by rememberSaveable { mutableStateOf(initial?.yearsOfExperience?.toString().orEmpty()) }
    val languages = rememberSaveable(saver = listSaver<SnapshotStateList<String>, String>(save = { it.toList() }, restore = { saved -> mutableStateListOf<String>().apply { addAll(saved) } })) {
        mutableStateListOf<String>().also { it.addAll(initial?.contactLanguages.orEmpty()) }
    }
    var countryId by rememberSaveable { mutableStateOf(initial?.countryId) }
    var city by rememberSaveable { mutableStateOf(initial?.city.orEmpty()) }
    var residency by rememberSaveable { mutableStateOf(initial?.residency) }
    var gender by rememberSaveable { mutableStateOf(initial?.gender) }
    var dob by rememberSaveable { mutableStateOf(initial?.dateOfBirth.orEmpty()) }

    var allCountries by remember { mutableStateOf<List<CountryDto>>(emptyList()) }
    var allLanguages by remember { mutableStateOf(listOf(LanguageDto("ar", "العربية"), LanguageDto("en", "English"))) }
    var countryMenu by remember { mutableStateOf(false) }

    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var photoBusy by remember { mutableStateOf(false) }
    var photoError by remember { mutableStateOf<String?>(null) }

    var email by rememberSaveable { mutableStateOf(initial?.email.orEmpty()) }
    var codeSent by remember { mutableStateOf(false) }
    var code by remember { mutableStateOf("") }
    var emailBusy by remember { mutableStateOf(false) }
    var emailMessage by remember { mutableStateOf<String?>(null) }
    var emailError by remember { mutableStateOf<String?>(null) }
    val emailSentText = stringResource(R.string.profile_email_sent)

    // One read each when the page opens.
    LaunchedEffect(Unit) {
        (apiCall { ApiClient.general.countries() } as? ApiResult.Ok)?.data?.let { allCountries = it }
        (apiCall { ApiClient.general.languages() } as? ApiResult.Ok)?.data?.takeIf { it.isNotEmpty() }?.let { allLanguages = it }
    }

    // ---- photo ----
    fun upload(uri: Uri) {
        photoBusy = true
        photoError = null
        scope.launch {
            val part = withContext(Dispatchers.IO) { avatarPart(context, uri) }
            if (part == null) {
                photoError = photoFailed
            } else {
                val result = apiCall { ApiClient.profile.avatar(part) }
                if (result is ApiResult.Ok) {
                    Session.setMe(result.data)
                    AvatarVersion.bump()
                } else {
                    photoError = result.errorText(offlineText) ?: photoFailed
                }
            }
            photoBusy = false
        }
    }

    val gallery = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri -> uri?.let(::upload) }
    // Kept across the app being killed while the camera is open, so the photo still gets uploaded.
    var cameraUriText by rememberSaveable { mutableStateOf<String?>(null) }
    val camera = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { taken -> if (taken) cameraUriText?.let { upload(Uri.parse(it)) } }
    fun launchCamera() {
        val file = File(context.cacheDir, "camera").apply { mkdirs() }.let { File(it, "photo.jpg") }
        val uri = FileProvider.getUriForFile(context, "${context.packageName}.files", file)
        cameraUriText = uri.toString()
        camera.launch(uri)
    }
    val cameraPermission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted -> if (granted) launchCamera() }
    fun openCamera() {
        if (ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) launchCamera()
        else cameraPermission.launch(Manifest.permission.CAMERA)
    }

    // ---- email ----
    fun sendCode() {
        emailBusy = true
        emailError = null
        emailMessage = null
        scope.launch {
            val result = apiCall { ApiClient.profile.sendEmailCode(EmailRequest(email.trim())) }
            emailBusy = false
            if (result is ApiResult.Ok) {
                codeSent = true
                emailMessage = emailSentText
            } else {
                emailError = result.errorText(offlineText) ?: genericError
            }
        }
    }

    fun confirmEmail() {
        emailBusy = true
        emailError = null
        scope.launch {
            val result = apiCall { ApiClient.profile.confirmEmail(CodeRequest(code.trim())) }
            emailBusy = false
            if (result is ApiResult.Ok) {
                Session.setMe(result.data)
                codeSent = false
                code = ""
                emailMessage = null
            } else {
                emailError = result.errorText(offlineText) ?: genericError
            }
        }
    }

    // ---- save ----
    fun save() {
        saving = true
        error = null
        val body = mutableMapOf<String, Any>(
            "name" to name.trim(),
            "headline" to headline.trim(),
            "bio" to bio.trim(),
            "city" to city.trim(),
            "contact_languages" to languages.toList(),
            "locale" to appLanguage.code,
        )
        years.trim().toIntOrNull()?.let { body["years_of_experience"] = it }
        countryId?.let { body["country_id"] = it }
        residency?.let { body["residency"] = it }
        gender?.let { body["gender"] = it }
        if (dob.isNotBlank()) body["date_of_birth"] = dob.trim()
        scope.launch {
            val result = apiCall { ApiClient.profile.update(body) }
            saving = false
            if (result is ApiResult.Ok) {
                Session.setMe(result.data)
                android.widget.Toast.makeText(context, savedText, android.widget.Toast.LENGTH_SHORT).show()
                onSaved()
            } else {
                error = result.errorText(offlineText) ?: genericError
            }
        }
    }

    val emailVerified = me?.emailVerified == true && me?.email.equals(email.trim(), ignoreCase = true)

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize().statusBarsPadding().navigationBarsPadding().imePadding()) {
            SubHeader(title = stringResource(R.string.profile_title), onBack = onBack)
            Column(
                modifier = Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 18.dp).padding(bottom = 28.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.spacedBy(14.dp),
            ) {
                com.dorr.freelance.ui.components.FormIntro(Icons.Rounded.Person, stringResource(R.string.profile_intro_title), stringResource(R.string.profile_intro))
                // Photo
                CardSection {
                    Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        AvatarImage(url = me?.avatarUrl, size = 96.dp)
                        Text(stringResource(R.string.profile_photo), color = settingsMut(), fontSize = 13.sp)
                        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                            PhotoButton(Icons.Rounded.PhotoLibrary, stringResource(R.string.profile_photo_gallery), !photoBusy) { gallery.launch("image/*") }
                            PhotoButton(Icons.Rounded.CameraAlt, stringResource(R.string.profile_photo_camera), !photoBusy) { openCamera() }
                        }
                        ErrorBanner(photoError, Modifier.fillMaxWidth())
                    }
                }

                // Basics
                Section {
                    DorrTextField(name, { name = it }, label = stringResource(R.string.profile_name), icon = Icons.Rounded.Person, modifier = Modifier.fillMaxWidth())
                    DorrTextField(headline, { headline = it }, label = stringResource(R.string.profile_headline), icon = Icons.Rounded.Badge, placeholder = stringResource(R.string.profile_headline_hint), modifier = Modifier.fillMaxWidth())
                    DorrTextField(bio, { bio = it.take(1500) }, label = stringResource(R.string.profile_bio), icon = Icons.Rounded.Notes, singleLine = false, minLines = 4, modifier = Modifier.fillMaxWidth())
                    DorrTextField(
                        years, { years = it.filter(Char::isDigit).take(2) }, label = stringResource(R.string.profile_years), icon = Icons.Rounded.Schedule,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth(),
                    )
                }

                // Languages and place
                Section {
                    Label(stringResource(R.string.profile_languages))
                    ChipRow(
                        options = allLanguages.map { it.code to it.name.ifBlank { it.code } },
                        selected = languages.toSet(),
                        onToggle = { code -> if (code in languages) languages.remove(code) else if (languages.size < 10) languages.add(code) },
                    )
                    Box {
                        DorrTextField(
                            value = allCountries.firstOrNull { it.id == countryId }?.name.orEmpty(),
                            onValueChange = {},
                            label = stringResource(R.string.profile_country), icon = Icons.Rounded.Public,
                            trailing = { Icon(Icons.Rounded.KeyboardArrowDown, contentDescription = null, tint = settingsMut()) },
                            onClick = { if (allCountries.isNotEmpty()) countryMenu = true },
                            modifier = Modifier.fillMaxWidth(),
                        )
                        DropdownMenu(expanded = countryMenu, onDismissRequest = { countryMenu = false }, containerColor = settingsCard()) {
                            allCountries.forEach { country ->
                                DropdownMenuItem(
                                    text = { Text(country.name.ifBlank { country.code }, color = if (country.id == countryId) settingsAccent() else settingsInk(), fontWeight = if (country.id == countryId) FontWeight.Bold else FontWeight.Normal) },
                                    onClick = {
                                        countryId = country.id
                                        countryMenu = false
                                    },
                                )
                            }
                        }
                    }
                    DorrTextField(city, { city = it }, label = stringResource(R.string.profile_city), icon = Icons.Rounded.LocationCity, modifier = Modifier.fillMaxWidth())
                    Label(stringResource(R.string.profile_residency))
                    ChipRow(
                        options = listOf("citizen" to stringResource(R.string.residency_citizen), "resident" to stringResource(R.string.residency_resident)),
                        selected = setOfNotNull(residency),
                        onToggle = { residency = if (residency == it) null else it },
                    )
                    Label(stringResource(R.string.profile_gender))
                    ChipRow(
                        options = listOf("male" to stringResource(R.string.gender_male), "female" to stringResource(R.string.gender_female)),
                        selected = setOfNotNull(gender),
                        onToggle = { gender = if (gender == it) null else it },
                    )
                    DateField(dob, { dob = it }, label = stringResource(R.string.profile_dob), modifier = Modifier.fillMaxWidth())
                }

                SettingsPrimaryButton(text = stringResource(R.string.profile_save), enabled = name.trim().length >= 2, loading = saving, onClick = ::save)
                ErrorBanner(error, Modifier.fillMaxWidth())

                // Email (its own code, apart from the save)
                CardSection {
                    DorrTextField(
                        email, { email = it; codeSent = false }, label = stringResource(R.string.profile_email), icon = Icons.Rounded.Email,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email), modifier = Modifier.fillMaxWidth(),
                    )
                    if (emailVerified) {
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            Icon(Icons.Rounded.CheckCircle, contentDescription = null, tint = settingsAccent(), modifier = Modifier.height(18.dp))
                            Text(stringResource(R.string.profile_email_verified), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
                        }
                    } else {
                        if (codeSent) {
                            DorrTextField(
                                code, { code = it.filter(Char::isDigit).take(8) }, label = stringResource(R.string.profile_email_code), icon = Icons.Rounded.Lock,
                                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth(),
                            )
                            SettingsPrimaryButton(text = stringResource(R.string.profile_email_confirm), enabled = code.length >= 4, loading = emailBusy, onClick = ::confirmEmail)
                        } else {
                            SettingsPrimaryButton(text = stringResource(R.string.profile_email_send), enabled = email.contains("@"), loading = emailBusy, onClick = ::sendCode)
                        }
                    }
                    MessageToast(emailMessage)
                    ErrorBanner(emailError, Modifier.fillMaxWidth())
                }
            }
        }
    }
}

@Composable
private fun CardSection(content: @Composable () -> Unit) {
    Column(
        modifier = Modifier.fillMaxWidth().widthIn(max = 440.dp).settingsSurface(RoundedCornerShape(20.dp)).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) { content() }
}

@Composable
private fun Label(text: String) {
    Text(text, fontSize = 13.sp, fontWeight = FontWeight.Bold, color = settingsInk())
}

@Composable
private fun PhotoButton(icon: androidx.compose.ui.graphics.vector.ImageVector, text: String, enabled: Boolean, onClick: () -> Unit) {
    val accent = settingsAccent()
    Row(
        modifier = Modifier
            .let { if (enabled) it.clickable(onClick = onClick) else it }
            .padding(horizontal = 14.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Icon(icon, contentDescription = null, tint = if (enabled) accent else accent.copy(alpha = 0.4f), modifier = Modifier.height(20.dp))
        Text(text, color = if (enabled) accent else accent.copy(alpha = 0.4f), fontSize = 14.sp, fontWeight = FontWeight.Bold)
    }
}

/** The fields of the profile straight on the page, like the other forms. */
@Composable
private fun Section(content: @Composable () -> Unit) {
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(14.dp)) { content() }
}
