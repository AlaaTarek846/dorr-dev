package com.dorr.app.network

import okhttp3.ResponseBody
import retrofit2.http.GET
import retrofit2.http.Path

interface LanguageApi {
    /**
     * GET /api/general/v1/translations/languages?platform=android — ar/en (bundled) plus active
     * languages with published Android strings, each with its `android_version`. Pre-login.
     */
    @GET("general/v1/translations/languages?platform=android")
    suspend fun list(): ApiEnvelope<List<LanguageDto>>

    /**
     * GET /api/general/v1/translations/{code}/android — `{code, direction, version, strings}`.
     * Raw body: the `strings` object is stored on disk as-is. 404 when not (or no longer) published.
     */
    @GET("general/v1/translations/{code}/android")
    suspend fun androidStrings(@Path("code") code: String): ResponseBody
}
