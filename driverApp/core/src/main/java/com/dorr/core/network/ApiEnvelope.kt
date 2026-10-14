package com.dorr.core.network

import com.google.gson.JsonElement
import com.google.gson.annotations.SerializedName

/**
 * Every DORR API answer (App\Support\Api\ApiResponse): `success`, `code`, `message`, `data`, and on
 * a rule's refusal an `error_code` such as `rides_feature_disabled` the app can act on.
 */
data class ApiEnvelope<T>(
    val success: Boolean = false,
    val code: Int = 0,
    val message: String? = null,
    val data: T? = null,
    @SerializedName("error_code") val errorCode: String? = null,
    val errors: Map<String, List<String>>? = null,
)

/** A refused or failed call, read from the error body: the message to show and the code to branch on. */
data class ApiError(
    val httpStatus: Int,
    val message: String,
    val errorCode: String? = null,
    val fieldErrors: Map<String, List<String>> = emptyMap(),
    val data: JsonElement? = null,
) {
    /** The first validation message when there is one (e.g. a wrong OTP), else the general message. */
    val displayMessage: String
        get() = fieldErrors.values.firstOrNull()?.firstOrNull() ?: message
}
