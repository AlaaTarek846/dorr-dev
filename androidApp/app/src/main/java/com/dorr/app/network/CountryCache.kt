package com.dorr.app.network

import android.content.Context
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken

/**
 * The countries dropdown, fetched once in LoginScreen and reused everywhere
 * else from memory/disk — so `general/v1/countries/dropdown` is never called
 * outside the login flow.
 *
 * Fresh installs reach the profile screens only after logging in, which is
 * exactly when this cache gets written; a cold start with a live session
 * restores it from disk. When nothing was ever cached, callers fall back to
 * their own hardcoded defaults (SA: +966 / 9 digits / starts with 5).
 */
object CountryCache {
    private const val PREFS_NAME = "dorr_countries"
    private const val KEY_DROPDOWN = "dropdown"

    private val gson = Gson()

    @Volatile
    private var memory: List<CountryDto>? = null

    fun save(context: Context, countries: List<CountryDto>) {
        memory = countries
        runCatching {
            context.applicationContext
                .getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
                .edit()
                .putString(KEY_DROPDOWN, gson.toJson(countries))
                .apply()
        }
    }

    fun load(context: Context): List<CountryDto> {
        memory?.let { return it }
        val restored = runCatching {
            context.applicationContext
                .getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
                .getString(KEY_DROPDOWN, null)
                ?.let { raw ->
                    gson.fromJson<List<CountryDto>>(
                        raw,
                        TypeToken.getParameterized(List::class.java, CountryDto::class.java).type,
                    )
                }
        }.getOrNull().orEmpty()
        memory = restored
        return restored
    }

    /** Default → SA → first, mirroring the login screen's own picking order. */
    fun pickDefault(context: Context): CountryDto? {
        val listed = load(context)
        return listed.find { it.isDefault }
            ?: listed.find { it.code.equals("sa", ignoreCase = true) }
            ?: listed.firstOrNull()
    }
    fun clear(context: Context) {
        memory = null
        runCatching {
            context.applicationContext
                .getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
                .edit()
                .remove(KEY_DROPDOWN)
                .apply()
        }
    }
}

/** "+966" regardless of whether the stored dial code carries the plus. */
fun CountryDto.formattedDial(): String {
    val digits = dialCode.trim().removePrefix("+")
    return if (digits.isEmpty()) "" else "+$digits"
}

/** Flag iso ("sa"): the flag row first, else the country code. */
fun CountryDto.flagIso(): String =
    flag?.code?.lowercase()?.takeIf { it.length == 2 } ?: code.lowercase().take(2)

/**
 * The cached country a full international number belongs to, for seeding
 * dial code, flag and validation from the user's own stored profile instead
 * of hardcoded defaults. Longest matching dial code wins ("+9665..." → SA
 * even if a shorter prefix also matches). Null when nothing is cached or
 * nothing matches — callers fall back to pickDefault() or their defaults.
 */
fun CountryCache.resolveForPhone(context: Context, fullPhone: String?): CountryDto? {
    val digits = fullPhone.orEmpty().filter(Char::isDigit)
    if (digits.isEmpty()) return null
    return load(context)
        .filter { country ->
            val dial = country.dialCode.filter(Char::isDigit)
            dial.isNotEmpty() && digits.startsWith(dial)
        }
        .maxByOrNull { it.dialCode.filter(Char::isDigit).length }
}
