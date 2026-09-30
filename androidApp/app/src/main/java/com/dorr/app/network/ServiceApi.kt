package com.dorr.app.network

import retrofit2.http.GET
import retrofit2.http.Query

interface ServiceApi {
    /**
     * GET /api/general/v1/services — active categories for the given [audience]
     * (default `user`). Pass [home]=1 for the home dashboard subset only.
     */
    @GET("general/v1/services")
    suspend fun list(
        @Query("audience") audience: String = "user",
        @Query("home") home: Int? = null,
    ): ApiEnvelope<List<ServiceDto>>
}
