package com.dorr.provider.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.GET
import retrofit2.http.Query

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

    /**
     * The DORR legal pages (`privacy` / `term`), public: the sign-in page links them. The providers app reads the platform's
     * general pages (`mobile/v1/legal-pages`) until providers get pages of their own.
     */
    @GET("mobile/v1/legal-pages")
    suspend fun legalPage(@Query("type") type: String): Response<ApiEnvelope<LegalPageDto>>
}

data class LegalPageDto(
    val id: Int? = null,
    val type: String? = null,
    val content: String? = null,
    val status: Boolean? = null,
)

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
