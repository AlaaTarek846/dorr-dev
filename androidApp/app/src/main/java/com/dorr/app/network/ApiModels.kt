package com.dorr.app.network

import com.google.gson.annotations.SerializedName

/** Mirrors App\Support\Api\ApiResponse's envelope shape on every endpoint. */
data class ApiEnvelope<T>(
    val success: Boolean,
    val status: String,
    val code: Int,
    val message: String,
    val data: T?,
)

data class CountryDto(
    val id: Int,
    val code: String,
    val name: String,
    @SerializedName("dial_code") val dialCode: String,
    @SerializedName("phone_length") val phoneLength: Int?,
    @SerializedName("phone_starts_with") val phoneStartsWith: String?,
    @SerializedName("is_default") val isDefault: Boolean,
    val flag: FlagDto?,
)

data class FlagDto(
    val id: Int,
    val code: String,
)

data class BrandingDto(
    @SerializedName("app_name") val appName: String? = null,
    val logo: String? = null,
    @SerializedName("logo_dark") val logoDark: String? = null,
)

data class LanguageDto(
    val id: Int,
    val code: String,
    val name: String,
    val direction: String,
)
