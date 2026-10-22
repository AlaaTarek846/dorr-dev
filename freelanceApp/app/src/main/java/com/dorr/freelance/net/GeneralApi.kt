package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.GET

/** The shared public endpoints every DORR app reads (no sign-in). */
interface GeneralApi {
    /** The platform colours the admin set for the apps (light and dark), read by the splash. */
    @GET("general/v1/mobile-appearance-defaults")
    suspend fun appearanceDefaults(): Response<ApiEnvelope<PlatformAppearanceDto>>

    /** The active countries (name, dial code, phone length / prefix, flag) — the sign-in's country picker. */
    @GET("general/v1/countries/dropdown")
    suspend fun countries(): Response<ApiEnvelope<List<CountryDto>>>

    /** The caller's country by IP, in the dropdown shape (the default country when it can't tell). */
    @GET("general/v1/countries/detect")
    suspend fun detectCountry(): Response<ApiEnvelope<CountryDto>>

    /** The active languages (code + name) — the contact languages the freelancer picks from. */
    @GET("general/v1/languages/dropdown")
    suspend fun languages(): Response<ApiEnvelope<List<LanguageDto>>>
}

data class LanguageDto(val code: String = "", val name: String = "")

data class PlatformAppearanceDto(
    @SerializedName("light_tokens") val lightTokens: Map<String, String>? = null,
    @SerializedName("dark_tokens") val darkTokens: Map<String, String>? = null,
)

data class CountryDto(
    val id: Int,
    val code: String,
    val name: String,
    @SerializedName("dial_code") val dialCode: String,
    @SerializedName("phone_length") val phoneLength: Int? = null,
    @SerializedName("phone_starts_with") val phoneStartsWith: String? = null,
    @SerializedName("is_default") val isDefault: Boolean = false,
    val flag: FlagDto? = null,
)

data class FlagDto(val id: Int = 0, val code: String = "")

/** "+966" whether or not the stored dial code carries the plus. */
fun CountryDto.formattedDial(): String {
    val digits = dialCode.trim().removePrefix("+")
    return if (digits.isEmpty()) "" else "+$digits"
}
