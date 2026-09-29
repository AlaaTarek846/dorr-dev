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
    /** The font this account picked, resolved for the app. */
    @SerializedName("mobile_app_font_id") val mobileAppFontId: Int? = null,
    val font: MobileAppFontDto? = null,
    /** Every font on offer, so the picker can list them. */
    @SerializedName("available_fonts") val availableFonts: List<MobileAppFontDto> = emptyList(),
    val resolved: AppearanceTokenSetDto? = null,
    val default: AppearanceTokenSetDto? = null,
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
    @SerializedName("mobile_app_font_id") val mobileAppFontId: Int? = null,
)
