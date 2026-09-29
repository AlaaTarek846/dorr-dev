package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.PUT

/**
 * Logged-in appearance under /api/mobile/v1/appearance.
 * Font is intentionally not sent from this client yet.
 */
interface AppearanceApi {
    @GET("mobile/v1/appearance")
    suspend fun show(
        @Header("Authorization") authorization: String,
    ): ApiEnvelope<AppearanceDto>

    @PUT("mobile/v1/appearance")
    suspend fun update(
        @Header("Authorization") authorization: String,
        @Body body: AppearanceUpdateRequest,
    ): ApiEnvelope<AppearanceDto>
}

data class AppearanceDto(
    @SerializedName("uses_default_colors") val usesDefaultColors: Boolean = true,
    @SerializedName("custom_light_tokens") val customLightTokens: Map<String, String>? = null,
    @SerializedName("custom_dark_tokens") val customDarkTokens: Map<String, String>? = null,
    @SerializedName("dark_mode") val darkMode: String = "system",
    @SerializedName("customizable_token_keys") val customizableTokenKeys: List<String>? = null,
    val resolved: AppearanceTokenSetDto? = null,
    val default: AppearanceTokenSetDto? = null,
    /** The app font chosen by the admin / user (Settings → fonts). Null = the built-in Cairo. */
    val font: AppFontDto? = null,
)

data class AppFontDto(
    val id: Int,
    val slug: String? = null,
    @SerializedName("font_files") val fontFiles: List<AppFontFileDto> = emptyList(),
)

data class AppFontFileDto(
    val id: Int,
    val url: String,
    val weight: String? = "400",
)

data class AppearanceTokenSetDto(
    @SerializedName("light_tokens") val lightTokens: Map<String, String>? = null,
    @SerializedName("dark_tokens") val darkTokens: Map<String, String>? = null,
)

data class AppearanceUpdateRequest(
    @SerializedName("uses_default_colors") val usesDefaultColors: Boolean? = null,
    @SerializedName("custom_light_tokens") val customLightTokens: Map<String, String>? = null,
    @SerializedName("custom_dark_tokens") val customDarkTokens: Map<String, String>? = null,
    @SerializedName("dark_mode") val darkMode: String? = null,
)
