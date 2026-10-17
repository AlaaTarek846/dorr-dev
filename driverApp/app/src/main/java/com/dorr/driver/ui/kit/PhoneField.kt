package com.dorr.driver.ui.kit

import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.KeyboardArrowDown
import androidx.compose.material.icons.rounded.Phone
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextDirection
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.driver.R
import com.dorr.driver.net.CountryDto
import com.dorr.driver.ui.theme.CairoFamily
import com.dorr.driver.ui.theme.Pal

/** "+966" from "966" or "+966". */
fun formatDialCode(value: String): String {
    val digits = value.trim().removePrefix("+")
    return if (digits.isEmpty()) "" else "+$digits"
}

/** A country's flag from flagcdn (the same images the DORR app shows); nothing for an unknown code. */
@Composable
fun FlagImage(code: String?, modifier: Modifier = Modifier) {
    val iso = code?.trim()?.lowercase().orEmpty()
    if (iso.length != 2) return
    AsyncImage(
        model = "https://flagcdn.com/w40/$iso.png",
        contentDescription = null,
        contentScale = ContentScale.Crop,
        modifier = modifier.size(width = 20.dp, height = 15.dp).clip(RoundedCornerShape(2.dp)),
    )
}

/**
 * The phone field of the DORR app's sign-in, piece for piece: a pill with the phone icon, the country (flag, dial
 * code, a searchable list of the countries open for sign-in), the number with a placeholder showing its length and
 * first digits, and a clear button. It always reads left to right, in Arabic too.
 */
@Composable
fun PhoneField(
    countries: List<CountryDto>,
    selected: CountryDto?,
    onCountry: (CountryDto) -> Unit,
    phone: String,
    onPhone: (String) -> Unit,
    error: Boolean,
    onDone: () -> Unit,
    modifier: Modifier = Modifier,
) {
    var open by remember { mutableStateOf(false) }
    var query by remember { mutableStateOf("") }
    var focused by remember { mutableStateOf(false) }
    LaunchedEffect(open) { if (!open) query = "" }

    val length = selected?.phoneLength ?: 12
    val startsWith = selected?.phoneStartsWith.orEmpty()
    val shape = RoundedCornerShape(999.dp)
    val edge by animateColorAsState(
        when {
            error -> Pal.danger
            focused -> Pal.accent
            else -> Color.Transparent
        },
        tween(200), label = "phone-edge",
    )
    val visible = countries.filter { c ->
        val q = query.trim()
        q.isEmpty() || c.name.contains(q, ignoreCase = true) || c.code.contains(q, ignoreCase = true) || c.dialCode.contains(q.removePrefix("+"))
    }

    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
        Row(
            modifier
                .fillMaxWidth()
                .height(52.dp)
                .then(if (Pal.night) Modifier else Modifier.shadow(3.dp, shape))
                .clip(shape)
                .background(Pal.card)
                .border(if (focused || error) 1.5.dp else 1.dp, if (focused || error) edge else Pal.line, shape)
                .padding(horizontal = 14.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.Phone, null, tint = Pal.faint, modifier = Modifier.size(18.dp))
            Separator()
            Box {
                Row(
                    Modifier
                        .clip(RoundedCornerShape(10.dp))
                        .clickable(enabled = countries.size > 1) { open = true }
                        .padding(horizontal = 6.dp, vertical = 6.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    FlagImage(selected?.flag?.code ?: selected?.code)
                    Spacer(Modifier.width(4.dp))
                    Text(formatDialCode(selected?.dialCode ?: "+966"), color = Pal.ink, fontSize = 14.sp, fontWeight = FontWeight.Bold)
                    if (countries.size > 1) {
                        Spacer(Modifier.width(2.dp))
                        Icon(Icons.Rounded.KeyboardArrowDown, stringResource(R.string.login_country), tint = Pal.mut, modifier = Modifier.size(18.dp))
                    }
                }
                DropdownMenu(
                    expanded = open,
                    onDismissRequest = { open = false },
                    containerColor = Pal.card,
                    shape = RoundedCornerShape(16.dp),
                    modifier = Modifier.heightIn(max = 340.dp).widthIn(min = 260.dp),
                ) {
                    Row(
                        Modifier.fillMaxWidth().padding(8.dp).clip(RoundedCornerShape(10.dp)).background(Pal.fieldFill).padding(horizontal = 10.dp, vertical = 8.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(Icons.Rounded.Search, null, tint = Pal.faint, modifier = Modifier.size(18.dp))
                        Spacer(Modifier.width(8.dp))
                        BasicTextField(
                            value = query, onValueChange = { query = it }, singleLine = true,
                            textStyle = TextStyle(fontSize = 14.sp, color = Pal.ink, fontFamily = CairoFamily),
                            cursorBrush = SolidColor(Pal.accent),
                            decorationBox = { inner ->
                                if (query.isEmpty()) Text(stringResource(R.string.login_country_search), color = Pal.faint, fontSize = 14.sp)
                                inner()
                            },
                            modifier = Modifier.fillMaxWidth(),
                        )
                    }
                    visible.forEach { country ->
                        val chosen = country.id == selected?.id
                        DropdownMenuItem(
                            text = {
                                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                                    FlagImage(country.flag?.code ?: country.code)
                                    Spacer(Modifier.width(10.dp))
                                    Text(
                                        country.name.ifBlank { country.code }, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis,
                                        color = if (chosen) Pal.accent else Pal.ink, fontWeight = if (chosen) FontWeight.Bold else FontWeight.Normal, fontSize = 14.sp,
                                    )
                                    Spacer(Modifier.width(8.dp))
                                    Text(formatDialCode(country.dialCode), color = if (chosen) Pal.accent else Pal.mut, fontWeight = FontWeight.SemiBold, fontSize = 13.sp)
                                }
                            },
                            onClick = {
                                onCountry(country)
                                open = false
                            },
                            modifier = if (chosen) Modifier.background(Pal.well) else Modifier,
                        )
                    }
                }
            }
            Separator()
            val placeholder = startsWith + "•".repeat((length - startsWith.length).coerceAtLeast(0))
            BasicTextField(
                value = phone,
                onValueChange = { input ->
                    val digits = input.filter(Char::isDigit)
                    if (digits.length <= length) onPhone(digits)
                },
                singleLine = true,
                textStyle = TextStyle(fontSize = 15.sp, fontWeight = FontWeight.SemiBold, color = Pal.ink, textDirection = TextDirection.Ltr, fontFamily = CairoFamily),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone, imeAction = ImeAction.Done),
                keyboardActions = KeyboardActions(onDone = { onDone() }),
                cursorBrush = SolidColor(Pal.accent),
                modifier = Modifier.weight(1f).onFocusChanged { focused = it.isFocused },
                decorationBox = { inner ->
                    Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.CenterStart) {
                        if (phone.isEmpty()) Text(placeholder, color = Pal.faint, fontSize = 15.sp, fontWeight = FontWeight.Medium, letterSpacing = 1.sp)
                        inner()
                    }
                },
            )
            if (phone.isNotEmpty()) {
                Box(Modifier.size(28.dp).clip(RoundedCornerShape(999.dp)).clickable { onPhone("") }, contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.Close, stringResource(R.string.login_clear), tint = Pal.faint, modifier = Modifier.size(16.dp))
                }
            }
        }
    }
}

@Composable
private fun Separator() {
    Box(Modifier.padding(horizontal = 8.dp).width(1.dp).height(18.dp).background(Pal.line))
}
