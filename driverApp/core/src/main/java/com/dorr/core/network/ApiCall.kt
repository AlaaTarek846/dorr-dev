package com.dorr.core.network

import com.google.gson.Gson
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import java.io.IOException
import retrofit2.Response

/** The result of one API call: the data, or what went wrong (never an exception for the UI to catch). */
sealed interface ApiResult<out T> {
    data class Ok<T>(val data: T, val message: String?) : ApiResult<T>
    data class Failed(val error: ApiError) : ApiResult<Nothing>
    /** No connection, timeout, unreachable host. */
    data class Offline(val cause: IOException) : ApiResult<Nothing>
}

private val gson = Gson()

/**
 * Runs a Retrofit call returning [ApiEnvelope] and turns every outcome into an [ApiResult]:
 * `success` with data, a 4xx/5xx with the server's message and `error_code`, or offline.
 */
suspend fun <T> apiCall(block: suspend () -> Response<ApiEnvelope<T>>): ApiResult<T> {
    val response = try {
        block()
    } catch (e: IOException) {
        return ApiResult.Offline(e)
    }

    val body = response.body()
    if (response.isSuccessful && body != null && body.success && body.data != null) {
        return ApiResult.Ok(body.data, body.message)
    }

    return ApiResult.Failed(parseError(response.code(), response.errorBody()?.string(), body?.message))
}

fun parseError(status: Int, raw: String?, fallback: String?): ApiError {
    val json: JsonObject? = runCatching { JsonParser.parseString(raw ?: "").asJsonObject }.getOrNull()
    val message = json?.get("message")?.takeIf { it.isJsonPrimitive }?.asString ?: fallback ?: "HTTP $status"
    val errorCode = json?.get("error_code")?.takeIf { it.isJsonPrimitive }?.asString
    val fields = json?.get("errors")?.takeIf { it.isJsonObject }?.asJsonObject?.entrySet()?.associate { (key, value) ->
        key to (if (value.isJsonArray) value.asJsonArray.mapNotNull { e -> e.takeIf { it.isJsonPrimitive }?.asString } else emptyList())
    } ?: emptyMap()

    return ApiError(status, message, errorCode, fields, json?.get("data"))
}

/** Gson shared by the clients (field names as the server sends them). */
fun coreGson(): Gson = gson
