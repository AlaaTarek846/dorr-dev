package com.dorr.app.ui.screens.chat

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Chat
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.KeyboardArrowDown
import androidx.compose.material.icons.rounded.PersonAdd
import androidx.compose.material.icons.rounded.PersonSearch
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Send
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.ContactEntry
import com.dorr.app.network.CountryDto
import com.dorr.app.network.ProfileDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.theme.CairoFontFamily
import kotlinx.coroutines.launch

/**
 * A number as typed, read for one country. Accepts it with or without the country code
 * (`+966…`, `00966…`, `966…`), with the trunk 0 (`05…`) or without it, with spaces, dashes and
 * Arabic digits. `national` is the part after the country code; `complete` only when it has the
 * country's exact length and a valid first digit — nothing is searched before that.
 */
internal data class PhoneInput(val country: CountryDto?, val national: String, val complete: Boolean, val wrongStart: Boolean) {
    val e164: String get() = "+" + dial(country) + national
}

private fun dial(country: CountryDto?): String = country?.dialCode.orEmpty().filter { it.isDigit() }

private fun prefixes(country: CountryDto?): List<String> = country?.phoneStartsWith.orEmpty().split(',').map { it.trim() }.filter { it.isNotEmpty() }

internal fun readPhone(raw: String, selected: CountryDto?, countries: List<CountryDto>): PhoneInput {
    val western = raw.map { c -> if (c in '٠'..'٩') '0' + (c - '٠') else c }.joinToString("")
    val digits = western.filter { it.isDigit() }
    val international = western.trimStart().startsWith("+") || digits.startsWith("00")

    var country = selected
    var national: String
    if (international) {
        // The number names its own country: follow it (longest dial code wins).
        val full = if (digits.startsWith("00")) digits.drop(2) else digits
        country = countries.filter { dial(it).isNotEmpty() && full.startsWith(dial(it)) }.maxByOrNull { dial(it).length } ?: selected
        national = full.removePrefix(dial(country))
    } else {
        val d = dial(selected)
        val length = selected?.phoneLength
        national = when {
            d.isNotEmpty() && length != null && digits.length == d.length + length && digits.startsWith(d) -> digits.drop(d.length)
            else -> digits.removePrefix("0")
        }
    }

    val length = country?.phoneLength
    val starts = prefixes(country)
    val wrongStart = national.isNotEmpty() && starts.isNotEmpty() && starts.none { national.startsWith(it) || it.startsWith(national) }
    val complete = country != null && (length == null || national.length == length) && national.length >= 6 && !wrongStart &&
        (starts.isEmpty() || starts.any { national.startsWith(it) })
    return PhoneInput(country, national, complete, wrongStart)
}

/** 🇸🇦 from "SA". */
internal fun flagEmoji(code: String?): String = code?.uppercase()?.takeIf { it.length == 2 }
    ?.map { Character.toChars(0x1F1E6 + (it - 'A')).concatToString() }?.joinToString("") ?: "🌐"

private sealed interface LookupState {
    data object Idle : LookupState
    data object Searching : LookupState
    data class Found(val profile: ProfileDto) : LookupState
    data class NotOnDorr(val e164: String) : LookupState
    data class Failed(val message: String) : LookupState
}

/**
 * Start a chat with a phone number: pick the country (defaults to mine), type the number any
 * common way, and "Check" lights up only once it's a whole valid number. Then: their card with
 * "Message" and "Save to contacts" — or, not on Dorr, an invite.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AddByPhoneSheet(initial: String = "", onDismiss: () -> Unit, onPicked: ((ProfileDto) -> Unit)? = null) {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var countries by remember { mutableStateOf<List<CountryDto>>(emptyList()) }
    var selected by remember { mutableStateOf<CountryDto?>(null) }
    var picking by remember { mutableStateOf(false) }
    var raw by remember { mutableStateOf(initial) }
    var state by remember { mutableStateOf<LookupState>(LookupState.Idle) }
    var inviting by remember { mutableStateOf<ContactEntry?>(null) }
    var saved by remember { mutableStateOf(false) }
    val focus = remember { FocusRequester() }
    val notOnDorr = stringResource(R.string.ch_not_on_dorr)
    val savedText = stringResource(R.string.ch_contact_saved)

    LaunchedEffect(Unit) {
        val list = runCatching { ApiClient.countries.list().data.orEmpty() }.getOrDefault(emptyList())
        countries = list
        // My own country: the one my phone number starts with, else the default.
        val mine = AuthSession.user?.phone.orEmpty().filter { it.isDigit() }
        selected = list.filter { dial(it).isNotEmpty() && mine.startsWith(dial(it)) }.maxByOrNull { dial(it).length }
            ?: list.firstOrNull { it.isDefault } ?: list.firstOrNull()
        runCatching { focus.requestFocus() }
    }

    val input = readPhone(raw, selected, countries)
    val length = input.country?.phoneLength
    val progress by animateFloatAsState(
        if (length == null || length == 0) (if (input.complete) 1f else 0f) else (input.national.length.toFloat() / length).coerceIn(0f, 1f),
        spring(dampingRatio = 0.7f), label = "phoneProgress",
    )
    val ringColor by animateColorAsState(
        when {
            input.wrongStart || (length != null && input.national.length > length) -> Ch.Danger
            input.complete -> Ch.Success
            else -> Ch.Red
        }, tween(250), label = "phoneRing",
    )

    fun check() {
        if (!input.complete) return
        val e164 = input.e164
        state = LookupState.Searching
        scope.launch {
            state = try {
                val profile = ApiClient.chat.lookup(chatAuth(), mapOf("phone" to e164, "country_code" to input.country?.code.orEmpty())).data
                if (profile != null) LookupState.Found(profile) else LookupState.NotOnDorr(e164)
            } catch (e: Exception) {
                val failure = e.apiFailure()
                if (failure.httpStatus == 404) LookupState.NotOnDorr(e164) else LookupState.Failed(failure.message ?: notOnDorr)
            }
        }
    }

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().imePadding().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(42.dp).clip(CircleShape).background(Ch.Red.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.PersonSearch, null, tint = Ch.Red, modifier = Modifier.size(22.dp))
                }
                Spacer(Modifier.width(12.dp))
                Column {
                    Text(stringResource(R.string.ch_add_by_phone), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
                    Text(stringResource(R.string.ch_add_by_phone_sub), color = Ch.Mut, fontSize = 12.5.sp)
                }
            }
            Spacer(Modifier.height(16.dp))

            // ------------------------------------------------------------ country + number (always LTR: numbers read left to right)
            androidx.compose.runtime.CompositionLocalProvider(androidx.compose.ui.platform.LocalLayoutDirection provides LayoutDirection.Ltr) {
                Row(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Ch.FieldFill)
                        .border(if (raw.isEmpty()) 1.dp else 1.5.dp, if (raw.isEmpty()) Ch.FieldLine else ringColor.copy(alpha = 0.8f), RoundedCornerShape(18.dp)),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Row(
                        Modifier.clip(RoundedCornerShape(topStart = 18.dp, bottomStart = 18.dp)).clickable { picking = true }.padding(start = 14.dp, end = 8.dp, top = 16.dp, bottom = 16.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(flagEmoji(input.country?.flag?.code ?: input.country?.code), fontSize = 20.sp)
                        Spacer(Modifier.width(6.dp))
                        Text("+" + dial(input.country), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp)
                        Icon(Icons.Rounded.KeyboardArrowDown, null, tint = Ch.Mut, modifier = Modifier.size(18.dp))
                    }
                    Box(Modifier.width(1.dp).height(28.dp).background(Ch.Line))
                    Box(Modifier.weight(1f).padding(horizontal = 12.dp)) {
                        if (raw.isEmpty()) Text(examplePhone(input.country), color = Ch.Soft, fontSize = 16.sp)
                        BasicTextField(
                            raw, { raw = it.take(24); state = LookupState.Idle; saved = false },
                            singleLine = true,
                            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
                            textStyle = TextStyle(color = Ch.Ink, fontSize = 16.sp, fontWeight = FontWeight.Bold, fontFamily = CairoFontFamily, letterSpacing = 0.6.sp),
                            cursorBrush = SolidColor(Ch.Red),
                            modifier = Modifier.fillMaxWidth().focusRequester(focus),
                        )
                    }
                    // Digits so far, as a filling ring; a tick once the number is whole.
                    Box(Modifier.padding(end = 12.dp).size(30.dp), contentAlignment = Alignment.Center) {
                        androidx.compose.foundation.Canvas(Modifier.size(28.dp)) {
                            drawArc(ringColor.copy(alpha = 0.18f), 0f, 360f, false, style = androidx.compose.ui.graphics.drawscope.Stroke(3.dp.toPx()))
                            drawArc(ringColor, -90f, 360f * progress, false, style = androidx.compose.ui.graphics.drawscope.Stroke(3.dp.toPx(), cap = androidx.compose.ui.graphics.StrokeCap.Round))
                        }
                        AnimatedContent(input.complete, label = "phoneTick", transitionSpec = { scaleIn(spring(dampingRatio = 0.45f)) togetherWith fadeOut() }) { ok ->
                            if (ok) Icon(Icons.Rounded.CheckCircle, null, tint = ringColor, modifier = Modifier.size(18.dp))
                            else Text("${input.national.length}", color = Ch.Mut, fontSize = 10.sp, fontWeight = FontWeight.Bold)
                        }
                    }
                }
            }

            // ------------------------------------------------------------ what's missing
            val hint = when {
                input.wrongStart -> stringResource(R.string.ch_phone_wrong_start, prefixes(input.country).joinToString(" / "))
                length != null && input.national.length > length -> stringResource(R.string.ch_phone_too_long, length)
                length != null && raw.isNotEmpty() && !input.complete -> stringResource(R.string.ch_phone_digits_left, length - input.national.length)
                else -> stringResource(R.string.ch_phone_any_format)
            }
            Text(hint, color = if (input.wrongStart || (length != null && input.national.length > length)) Ch.Danger else Ch.Mut, fontSize = 12.sp, modifier = Modifier.padding(start = 6.dp, top = 6.dp))

            Spacer(Modifier.height(14.dp))
            ChPrimaryButton(stringResource(R.string.ch_check_number), icon = Icons.Rounded.Search, modifier = Modifier.fillMaxWidth(), enabled = input.complete && state !is LookupState.Searching) { check() }

            // ------------------------------------------------------------ the result
            AnimatedContent(state, label = "lookup", transitionSpec = { (fadeIn(tween(220)) + slideInVertically { it / 4 }) togetherWith fadeOut(tween(120)) }) { s ->
                when (s) {
                    LookupState.Idle -> Spacer(Modifier.height(4.dp))
                    LookupState.Searching -> Box(Modifier.fillMaxWidth().padding(top = 22.dp), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator(Modifier.size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp)
                    }
                    is LookupState.Found -> Column(
                        Modifier.fillMaxWidth().padding(top = 16.dp).clip(RoundedCornerShape(22.dp)).background(Ch.SurfaceMuted).padding(16.dp),
                        horizontalAlignment = Alignment.CenterHorizontally,
                    ) {
                        ChAvatar(s.profile.avatar, s.profile.name, s.profile.key, size = 72.dp)
                        Spacer(Modifier.height(10.dp))
                        Text(s.profile.name.orEmpty(), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 17.sp)
                        Text(ltrNumber(s.profile.phone), color = Ch.Mut, fontSize = 13.sp)
                        Spacer(Modifier.height(14.dp))
                        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                            if (!s.profile.isContact && !saved) {
                                Row(
                                    Modifier.weight(1f).clip(RoundedCornerShape(16.dp)).background(Ch.Surface).clickable {
                                        host.scope.launch {
                                            try {
                                                ApiClient.chat.addContact(chatAuth(), mapOf("name" to s.profile.name.orEmpty().ifBlank { s.profile.phone.orEmpty() }, "phone" to s.profile.phone.orEmpty()))
                                                saved = true
                                                host.showToast(savedText)
                                            } catch (e: Exception) {
                                                e.apiFailure().message?.let { host.showToast(it) }
                                            }
                                        }
                                    }.padding(vertical = 13.dp),
                                    horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
                                ) {
                                    Icon(Icons.Rounded.PersonAdd, null, tint = Ch.Red, modifier = Modifier.size(18.dp))
                                    Spacer(Modifier.width(6.dp))
                                    Text(stringResource(R.string.ch_save_contact), color = Ch.Red, fontWeight = FontWeight.ExtraBold, fontSize = 13.5.sp)
                                }
                            }
                            // Picking people for a group: add them to the selection instead of opening a chat.
                            if (onPicked != null) ChPrimaryButton(stringResource(R.string.ch_add_to_group), icon = Icons.Rounded.PersonAdd, modifier = Modifier.weight(1f)) {
                                onPicked(s.profile)
                                onDismiss()
                            } else ChPrimaryButton(stringResource(R.string.ch_message), icon = Icons.Rounded.Chat, modifier = Modifier.weight(1f)) {
                                onDismiss()
                                host.openChatWith(s.profile)
                            }
                        }
                    }
                    is LookupState.NotOnDorr -> Column(
                        Modifier.fillMaxWidth().padding(top = 16.dp).clip(RoundedCornerShape(22.dp)).background(Ch.SurfaceMuted).padding(16.dp),
                        horizontalAlignment = Alignment.CenterHorizontally,
                    ) {
                        Text("🙈", fontSize = 34.sp)
                        Text(stringResource(R.string.ch_number_not_on_dorr, s.e164), color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.sp, modifier = Modifier.padding(top = 6.dp))
                        Spacer(Modifier.height(12.dp))
                        ChPrimaryButton(stringResource(R.string.ch_invite), icon = Icons.Rounded.Send, modifier = Modifier.fillMaxWidth()) { inviting = ContactEntry("", s.e164) }
                    }
                    is LookupState.Failed -> Row(Modifier.fillMaxWidth().padding(top = 14.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Rounded.ErrorOutline, null, tint = Ch.Danger, modifier = Modifier.size(18.dp))
                        Spacer(Modifier.width(6.dp))
                        Text(s.message, color = Ch.Danger, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
                    }
                }
            }
        }
    }

    if (picking) CountryPickerSheet(countries, onDismiss = { picking = false }) { c ->
        selected = c
        // The typed number keeps its national part; drop a code written for the old country.
        if (raw.trimStart().startsWith("+") || raw.filter { it.isDigit() }.startsWith("00")) raw = ""
        state = LookupState.Idle
    }
    inviting?.let { entry -> InviteSheet(entry, contactCountry = AuthSession.user?.phone) { inviting = null } }
}

/** "5X XXX XXXX" — how long a number is and what it starts with, for the placeholder. */
private fun examplePhone(country: CountryDto?): String {
    val length = country?.phoneLength ?: return "5X XXX XXXX"
    val start = prefixes(country).firstOrNull().orEmpty()
    return (start + "X".repeat((length - start.length).coerceAtLeast(0))).chunked(3).joinToString(" ")
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun CountryPickerSheet(countries: List<CountryDto>, onDismiss: () -> Unit, onPick: (CountryDto) -> Unit) {
    var query by remember { mutableStateOf("") }
    val shown = countries.filter { query.isBlank() || it.name.contains(query, true) || it.dialCode.contains(query.filter { c -> c.isDigit() }.ifEmpty { "~" }) || it.code.equals(query, true) }
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 18.dp).padding(bottom = 20.dp)) {
            ChField(query, { query = it }, stringResource(R.string.ch_search_country), icon = Icons.Rounded.Search, clearable = true)
            Spacer(Modifier.height(8.dp))
            LazyColumn(Modifier.heightIn(max = 420.dp)) {
                items(shown, key = { it.id }) { c ->
                    Row(
                        Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable { onPick(c); onDismiss() }.padding(horizontal = 8.dp, vertical = 12.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(flagEmoji(c.flag?.code ?: c.code), fontSize = 22.sp)
                        Spacer(Modifier.width(12.dp))
                        Text(c.name, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.5.sp, modifier = Modifier.weight(1f))
                        Text("+" + dial(c), color = Ch.Mut, fontWeight = FontWeight.Bold, fontSize = 13.5.sp)
                    }
                }
            }
        }
    }
}
