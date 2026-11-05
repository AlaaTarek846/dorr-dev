package com.dorr.provider.net

import com.google.gson.GsonBuilder
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.toRequestBody

private val nullsKept = GsonBuilder().serializeNulls().create()

/**
 * A JSON body that keeps its `null`s. Retrofit's Gson leaves out null values, which is right when creating something but wrong when
 * editing it: the server reads a missing key as "no change" and only an explicit null as "clear it" (all branches, no offer limit,
 * an emptied address or note).
 */
fun jsonBody(value: Any): RequestBody = nullsKept.toJson(value).toRequestBody("application/json".toMediaType())
