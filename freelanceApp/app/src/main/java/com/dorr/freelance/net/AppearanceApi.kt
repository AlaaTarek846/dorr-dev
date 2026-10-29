package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import okhttp3.ResponseBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.PUT
import retrofit2.http.Path

/** The freelancer's colours, dark mode and font under `/api/freelance/v1/appearance` — the same settings as the DORR app's, kept per freelancer. */
interface AppearanceApi {
    @GET("freelance/v1/appearance")
    suspend fun show(): Response<ApiEnvelope<AppearanceDto>>

    @PUT("freelance/v1/appearance")
    suspend fun update(@Body body: AppearanceUpdateRequest): Response<ApiEnvelope<AppearanceDto>>
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

data class MobileAppFontDto(
    val id: Int = 0,
    val name: String? = null,
    val slug: String? = null,
    val status: Boolean = true,
    @SerializedName("is_default") val isDefault: Boolean = false,
    @SerializedName("sort_order") val sortOrder: Int = 0,
    @SerializedName("font_files") val fontFiles: List<MobileAppFontFileDto>? = null,
)

data class MobileAppFontFileDto(
    val id: Int = 0,
    @SerializedName("file_name") val fileName: String? = null,
    val url: String? = null,
    val weight: String? = null,
)

/** The interface languages, as in the DORR app: ar / en are bundled, every other active language is downloaded. */
interface TranslationsApi {
    /** ar/en (bundled) plus the active languages with published Android strings, each with its `android_version`. Pre-login. */
    @GET("general/v1/translations/languages?platform=android")
    suspend fun list(): Response<ApiEnvelope<List<AppLanguageDto>>>

    /** `{code, direction, version, strings}` as a raw body: the `strings` object is stored on disk as it is. 404 when not (or no longer) published. */
    @GET("general/v1/translations/{code}/android")
    suspend fun androidStrings(@Path("code") code: String): ResponseBody
}

data class AppLanguageDto(
    val id: Int = 0,
    val code: String = "",
    val name: String = "",
    val direction: String = "ltr",
    val flag: FlagDto? = null,
    /** Version of the downloadable Android strings; null for the bundled ar/en. */
    @SerializedName("android_version") val androidVersion: String? = null,
)
