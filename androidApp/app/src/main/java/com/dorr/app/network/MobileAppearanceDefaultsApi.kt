package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import retrofit2.http.GET

/** GET /api/general/v1/mobile-appearance-defaults — platform colors, pre-login. */
interface MobileAppearanceDefaultsApi {
    @GET("general/v1/mobile-appearance-defaults")
    suspend fun show(): ApiEnvelope<PlatformAppearanceDefaultsDto>
}

data class PlatformAppearanceDefaultsDto(
    @SerializedName("light_tokens") val lightTokens: Map<String, String>? = null,
    @SerializedName("dark_tokens") val darkTokens: Map<String, String>? = null,
)
