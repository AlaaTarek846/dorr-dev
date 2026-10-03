package com.dorr.app.network

import com.google.gson.JsonElement
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
data class ApiFailure(
    val message: String?,
    val errorCode: String?,
    val httpStatus: Int?,
    /** `data.locked_until` on a `wallet_pin_locked` (423) answer — an ISO-8601 instant. */
    val lockedUntil: String? = null,
)

/**
 * Reads an error answer without ever throwing: the envelope's `data` is `[]` on most errors (an
 * empty PHP array), `errors` may be missing or not a map — reading those as objects used to crash
 * the app on every refused request (e.g. "delete for everyone" after the time limit).
 */
fun Throwable.apiFailure(): ApiFailure {
    if (this !is retrofit2.HttpException) return ApiFailure(null, null, null)
    val raw = runCatching { response()?.errorBody()?.string() }.getOrNull()
    val root = raw?.let { runCatching { JsonParser.parseString(it) }.getOrNull() }?.takeIf { it.isJsonObject }?.asJsonObject
    fun JsonElement?.text(): String? = this?.takeIf { it.isJsonPrimitive }?.asString
    val firstError = root?.get("errors")?.takeIf { it.isJsonObject }?.asJsonObject?.entrySet()?.firstOrNull()?.value
        ?.let { if (it.isJsonArray) it.asJsonArray.firstOrNull() else it }.text()
    val message = firstError ?: root?.get("message").text()
    val errorCode = root?.get("error_code").text()
    val lockedUntil = root?.get("data")?.takeIf { it.isJsonObject }?.asJsonObject?.get("locked_until").text()
    return ApiFailure(message, errorCode, code(), lockedUntil)
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
    val flag: FlagDto? = null,
    /** Version of the downloadable Android strings; null for the bundled ar/en. */
    @SerializedName("android_version") val androidVersion: String? = null,
)

data class OtpRequest(
    @SerializedName("dial_code") val dialCode: String,
    val phone: String,
)

data class OtpSentDto(
    @SerializedName("masked_phone") val maskedPhone: String?,
    @SerializedName("is_new_user") val isNewUser: Boolean?,
    /** "active" (normal login), "deleted" (soft-deleted, offer restore), "restore" (restore OTP sent). */
    @SerializedName("account_state") val accountState: String?,
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
    /** True when this verify restored a previously deleted account (deleted_at → null). */
    @SerializedName("is_restored") val isRestored: Boolean?,
)

data class UserDto(
    val id: Int,
    val name: String?,
    val email: String?,
    val phone: String?,
    @SerializedName("phone_verified_at") val phoneVerifiedAt: String?,
    /** `male` / `female`, from Modules\User UserResource. Null when never set. */
    val gender: String? = null,
    /** Absolute media URL of the avatar, null when none. */
    val avatar: String? = null,
    @SerializedName("email_verified_at") val emailVerifiedAt: String? = null,
    /** Bumps on every profile write; used to bust the image cache for the avatar. */
    @SerializedName("updated_at") val updatedAt: String? = null,
    /** Country data from auth/me — the primary source for country code, flag, and phone validation. */
    val country: UserCountryDto? = null,
)

data class UserCountryDto(
    val code: String?,
    @SerializedName("dial_code") val dialCode: String?,
    @SerializedName("phone_starts_with") val phoneStartsWith: String?,
    @SerializedName("phone_length") val phoneLength: Int?,
    val flag: UserFlagDto?,
)

data class UserFlagDto(
    val code: String?,
)

data class ServiceDto(
    val id: Int,
    val name: String,
    @SerializedName("module_name") val moduleName: String?,
    val image: String?,
    val description: String? = null,
    @SerializedName("sort_order") val sortOrder: Int = 0,
    @SerializedName("requires_provider") val requiresProvider: Boolean?,
    @SerializedName("has_children") val hasChildren: Boolean?,
    val children: List<ServiceChildDto>?,
)

data class ServiceChildDto(
    val id: Int,
    val name: String,
    @SerializedName("module_name") val moduleName: String?,
    val image: String?,
    val description: String? = null,
    @SerializedName("sort_order") val sortOrder: Int = 0,
)
