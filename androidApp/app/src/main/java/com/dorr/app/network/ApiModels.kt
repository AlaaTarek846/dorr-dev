package com.dorr.app.network

import com.google.gson.JsonParser
import com.google.gson.annotations.SerializedName

/** Mirrors App\Support\Api\ApiResponse's envelope shape on every endpoint. */
data class ApiEnvelope<T>(
    val success: Boolean,
    val status: String,
    val code: Int,
    val message: String,
    val data: T?,
    /** Present only on paginated lists. */
    val pagination: PaginationDto? = null,
)

data class PaginationDto(
    @SerializedName("current_page") val currentPage: Int,
    @SerializedName("has_more_pages") val hasMorePages: Boolean,
)

/** Pulls a human-readable message out of an API error body (422 and friends).
 *  Prefers the first field error, then the top-level `message`. Backend strings
 *  are already localized via the `X-Locale` header. */
fun Throwable.serverMessage(): String? {
    if (this !is retrofit2.HttpException) return null
    val raw = response()?.errorBody()?.string() ?: return null
    return runCatching {
        val root = JsonParser.parseString(raw).asJsonObject
        val errors = root.getAsJsonObject("errors")
        val firstError = errors?.entrySet()?.firstOrNull()?.value?.asJsonArray?.firstOrNull()?.asString
        firstError ?: root.get("message")?.takeIf { !it.isJsonNull }?.asString
    }.getOrNull()
}

/** A failed API call, parsed once (an OkHttp error body can only be read once,
 *  so [serverMessage] and the error code can't be read separately).
 *  `errorCode` is the machine-readable code of a domain error (e.g.
 *  `wallet_pin_invalid`) — lets the UI branch on the kind of failure without
 *  matching on localized text; null for plain validation/server errors.
 *  `message` is null for network failures (no HTTP response at all). */
data class ApiFailure(val message: String?, val errorCode: String?, val httpStatus: Int?)

fun Throwable.apiFailure(): ApiFailure {
    if (this !is retrofit2.HttpException) return ApiFailure(null, null, null)
    val raw = response()?.errorBody()?.string()
    val root = raw?.let { runCatching { JsonParser.parseString(it).asJsonObject }.getOrNull() }
    val firstError = root?.getAsJsonObject("errors")?.entrySet()?.firstOrNull()?.value?.asJsonArray?.firstOrNull()?.asString
    val message = firstError ?: root?.get("message")?.takeIf { !it.isJsonNull }?.asString
    val errorCode = root?.get("error_code")?.takeIf { !it.isJsonNull }?.asString
    return ApiFailure(message, errorCode, code())
}

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

data class OtpRequest(
    @SerializedName("dial_code") val dialCode: String,
    val phone: String,
)

data class OtpSentDto(
    @SerializedName("masked_phone") val maskedPhone: String?,
    @SerializedName("is_new_user") val isNewUser: Boolean?,
    @SerializedName("resend_cooldown_seconds") val resendCooldownSeconds: Int?,
)

data class VerifyOtpRequest(
    @SerializedName("dial_code") val dialCode: String,
    val phone: String,
    val code: String,
)

data class AuthResultDto(
    val user: UserDto?,
    val token: String?,
    @SerializedName("token_type") val tokenType: String?,
)

data class UserDto(
    val id: Int,
    val name: String?,
    val email: String?,
    val phone: String?,
    @SerializedName("phone_verified_at") val phoneVerifiedAt: String?,
)

data class ServiceDto(
    val id: Int,
    val name: String,
    @SerializedName("module_name") val moduleName: String?,
    val image: String?,
    @SerializedName("requires_provider") val requiresProvider: Boolean?,
    @SerializedName("has_children") val hasChildren: Boolean?,
    val children: List<ServiceChildDto>?,
)

data class ServiceChildDto(
    val id: Int,
    val name: String,
    @SerializedName("module_name") val moduleName: String?,
    val image: String?,
)
