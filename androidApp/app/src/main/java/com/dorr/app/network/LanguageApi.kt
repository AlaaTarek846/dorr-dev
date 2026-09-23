package com.dorr.app.network

import retrofit2.http.GET

interface LanguageApi {
    /** GET /api/general/v1/languages/dropdown — active storable languages, pre-login. */
    @GET("general/v1/languages/dropdown")
    suspend fun list(): ApiEnvelope<List<LanguageDto>>
}
