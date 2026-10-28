package com.dorr.freelance.net

import com.dorr.core.network.ApiResult

/** What to show for a failed call: the server's own message (in the request's language), or [offline] with no connection. */
fun ApiResult<*>.errorText(offline: String): String? = when (this) {
    is ApiResult.Ok -> null
    is ApiResult.Failed -> error.displayMessage
    is ApiResult.Offline -> offline
}

/**
 * Runs a call whose success carries no data (a delete): null when it went through, else the text to show. [apiCall] can't
 * be used for these because it treats an empty `data` as a failure.
 */
suspend fun <T> apiDone(offline: String, generic: String, block: suspend () -> retrofit2.Response<T>): String? {
    val response = try {
        block()
    } catch (e: java.io.IOException) {
        return offline
    }
    if (response.isSuccessful) return null
    return com.dorr.core.network.parseError(response.code(), response.errorBody()?.string(), null).displayMessage.ifBlank { generic }
}
